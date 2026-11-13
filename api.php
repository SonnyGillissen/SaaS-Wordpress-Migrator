<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/transfer.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/serialization.php';
require_once __DIR__ . '/lib/database.php';
require_once __DIR__ . '/lib/wordpress.php';
require_once __DIR__ . '/lib/migration.php';

ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_time_limit(45);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');

function api_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    migrator_session();
    migrator_same_origin();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        api_reply(['ok' => true, 'csrf' => $_SESSION['csrf'], 'authenticated' => migrator_authenticated(),
            'setupRequired' => strlen(migrator_access_key()) < 32,
            'capabilities' => [
                'ftp' => extension_loaded('ftp'), 'ftps' => function_exists('ftp_ssl_connect'),
                'sftp' => extension_loaded('ssh2'), 'mysql' => extension_loaded('pdo_mysql'),
                'wordpressRest' => extension_loaded('curl'), 'ftpsCertificateVerification' => false,
                'checkpointEncryption' => extension_loaded('openssl'),
                'sftpFingerprint' => 'SHA1 hexadecimal (40 characters)', 'chunkBytes' => Transfer::CHUNK,
                'accessKeyEnvironment' => 'MIGRATOR_ACCESS_KEY', 'minimumAccessKeyLength' => 32,
            ]]);
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        api_reply(['ok' => false, 'error' => 'Use GET or POST.'], 405);
    }
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        api_reply(['ok' => false, 'error' => 'Invalid CSRF token. Refresh the application.'], 403);
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!$secure && !migrator_local_http_request()
        && getenv('MIGRATOR_ALLOW_HTTP') !== '1') {
        api_reply(['ok' => false, 'error' => 'Serve the migrator over HTTPS before transmitting credentials.'], 403);
    }
    if (strlen(migrator_access_key()) < 32) {
        api_reply(['ok' => false, 'error' => 'Operator setup required: set MIGRATOR_ACCESS_KEY or config.php access_key to a random secret of at least 32 characters.'], 503);
    }
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        api_reply(['ok' => false, 'error' => 'Content-Type must be application/json.'], 415);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2097152) {
        api_reply(['ok' => false, 'error' => 'Request exceeds 2 MiB.'], 413);
    }
    $stream = fopen('php://input', 'rb');
    $body = stream_get_contents($stream, 2097153);
    fclose($stream);
    if (strlen($body) > 2097152) {
        api_reply(['ok' => false, 'error' => 'Request exceeds 2 MiB.'], 413);
    }
    $request = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_string($request['action'] ?? null)) {
        api_reply(['ok' => false, 'error' => 'An action is required.'], 400);
    }
    $action = $request['action'];
    $headerKey = $_SERVER['HTTP_X_MIGRATOR_KEY'] ?? null;
    if ($action === 'login' || (is_string($headerKey) && $headerKey !== '')) {
        if (($_SESSION['loginBlockedUntil'] ?? 0) > time()) {
            api_reply(['ok' => false, 'error' => 'Too many login attempts. Wait five minutes.'], 429);
        }
        $key = $action === 'login' ? ($request['accessKey'] ?? null) : $headerKey;
        if (!is_string($key) || !hash_equals(migrator_access_key(), $key)) {
            $_SESSION['loginFailures'] = ($_SESSION['loginFailures'] ?? 0) + 1;
            if ($_SESSION['loginFailures'] >= 5) {
                $_SESSION['loginBlockedUntil'] = time() + 300;
            }
            api_reply(['ok' => false, 'error' => 'Invalid access key.'], 401);
        }
        if (!migrator_authenticated()) {
            session_regenerate_id(true);
        }
        $_SESSION['access'] = hash('sha256', migrator_access_key());
        $_SESSION['expires'] = time() + 86400;
        $_SESSION['loginFailures'] = 0;
        if ($action === 'login') {
            api_reply(['ok' => true, 'authenticated' => true, 'csrf' => $_SESSION['csrf']]);
        }
    }
    if (!migrator_authenticated()) {
        api_reply(['ok' => false, 'error' => 'Authenticate with the operator access key first.'], 401);
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        api_reply(['ok' => true]);
    }
    if (!in_array($action, ['inspect', 'validate', 'tick'], true)) {
        api_reply(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
    $raw = $request['config'] ?? null;
    if ($action === 'inspect' && is_array($raw)) {
        // Inspection requires only the source connection form.
        $raw['destination'] = $raw['source'] ?? [];
        $raw['migration'] = ['siteUrl' => 'https://inspection.invalid', 'homeUrl' => 'https://inspection.invalid'];
    }
    $config = migration_config($raw);
    if ($action === 'inspect') {
        $probe = migration_source_probe($config);
        api_reply(['ok' => true, 'source' => $probe['source'], 'postTypes' => $probe['postTypes'], 'contentTypes' => $probe['postTypes'],
            'warnings' => ['Source is read-only. Standard single-site WordPress with a local wp-config.php is required.']]);
    }
    if ($action === 'validate') {
        $probe = migration_preflight($config);
        api_reply(['ok' => true, 'source' => $probe['source'], 'postTypes' => $probe['postTypes'], 'contentTypes' => $probe['postTypes'],
            'warnings' => migration_warnings($config)]);
    }
    api_reply(migration_tick($config, $request['state'] ?? null));
} catch (JsonException) {
    api_reply(['ok' => false, 'error' => 'Malformed JSON request or checkpoint.'], 400);
} catch (PDOException) {
    api_reply(['ok' => false, 'error' => 'Database operation failed. Check privileges, schema compatibility, and server limits. No credentials or SQL are logged.'], 400);
} catch (RuntimeException $error) {
    api_reply(['ok' => false, 'error' => $error->getMessage()], 400);
} catch (Throwable) {
    api_reply(['ok' => false, 'error' => 'Operation failed safely. Verify PHP extensions, remote permissions, and configuration.'], 500);
}
