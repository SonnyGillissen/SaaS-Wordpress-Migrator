<?php
declare(strict_types=1);

function migrator_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('wp_migrator');
    session_start(['use_strict_mode' => 1, 'cookie_httponly' => true,
        'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'cookie_samesite' => 'Strict']);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function migrator_access_key(): string
{
    $key = getenv('MIGRATOR_ACCESS_KEY');
    if (is_string($key) && $key !== '') {
        return $key;
    }
    $file = dirname(__DIR__) . '/config.php';
    if (is_file($file) && !is_link($file)) {
        $settings = require $file;
        if (is_array($settings) && is_string($settings['access_key'] ?? null)) {
            return $settings['access_key'];
        }
    }
    return '';
}

function migrator_authenticated(): bool
{
    $key = migrator_access_key();
    return strlen($key) >= 32 && isset($_SESSION['access'])
        && hash_equals(hash('sha256', $key), (string) $_SESSION['access'])
        && (int) ($_SESSION['expires'] ?? 0) > time();
}

function migrator_local_http_request(): bool
{
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        || !preg_match('/\A(?:localhost|127\.0\.0\.1|\[::1\])(?::([0-9]{1,5}))?\z/i',
            $_SERVER['HTTP_HOST'] ?? '', $match)) {
        return false;
    }
    return !isset($match[1]) || ((int) $match[1] >= 1 && (int) $match[1] <= 65535);
}

function migrator_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $fetch = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($fetch !== '' && !in_array($fetch, ['same-origin', 'none'], true)) {
        throw new RuntimeException('Cross-origin requests are forbidden.');
    }
    if ($origin !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!hash_equals($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? ''), $origin)) {
            throw new RuntimeException('Cross-origin requests are forbidden.');
        }
    }
}

function migrator_checkpoint_key(string $purpose): string
{
    $accessKey = migrator_access_key();
    if (strlen($accessKey) < 32) {
        throw new RuntimeException('A configured operator access key of at least 32 characters is required.');
    }
    return hash_hmac('sha256', 'wp-migrator:v1:' . $purpose, $accessKey, true);
}

function migrator_sign(array $state): array
{
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('PHP OpenSSL is required to encrypt resumable checkpoints.');
    }
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $nonce = random_bytes(12);
    $tag = '';
    $key = migrator_checkpoint_key('checkpoint-encryption');
    $ciphertext = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'wp-migrator-checkpoint-v1');
    if ($ciphertext === false) {
        throw new RuntimeException('Checkpoint encryption failed.');
    }
    return ['payload' => base64_encode($ciphertext), 'nonce' => base64_encode($nonce), 'tag' => base64_encode($tag),
        'signature' => hash_hmac('sha256', $nonce . $tag . $ciphertext, migrator_checkpoint_key('checkpoint-signature'))];
}

function migrator_verify(mixed $value, string $configHash): array
{
    if (!is_array($value) || !is_string($value['payload'] ?? null)
        || !is_string($value['signature'] ?? null) || !is_string($value['nonce'] ?? null)
        || !is_string($value['tag'] ?? null) || strlen($value['nonce']) > 24 || strlen($value['tag']) > 32
        || strlen($value['payload']) > 1500000) {
        throw new RuntimeException('Invalid checkpoint. Start a new migration.');
    }
    $ciphertext = base64_decode($value['payload'], true);
    $nonce = base64_decode($value['nonce'], true);
    $tag = base64_decode($value['tag'], true);
    if ($ciphertext === false || $nonce === false || strlen($nonce) !== 12 || $tag === false || strlen($tag) !== 16
        || !hash_equals(hash_hmac('sha256', $nonce . $tag . $ciphertext, migrator_checkpoint_key('checkpoint-signature')), $value['signature'])) {
        throw new RuntimeException('Checkpoint signature is invalid.');
    }
    $key = migrator_checkpoint_key('checkpoint-encryption');
    $json = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'wp-migrator-checkpoint-v1');
    if ($json === false) {
        throw new RuntimeException('Checkpoint authentication failed.');
    }
    $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($state) || !hash_equals($configHash, (string) ($state['configHash'] ?? ''))
        || !is_string($state['job'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $state['job'])) {
        throw new RuntimeException('Checkpoint does not match this migration configuration.');
    }
    if (($state['expires'] ?? 0) < time()) {
        throw new RuntimeException('Checkpoint expired. Start a new migration.');
    }
    return $state;
}
