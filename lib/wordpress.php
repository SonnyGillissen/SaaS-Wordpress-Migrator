<?php
declare(strict_types=1);

function migration_php_constants(string $php): array
{
    $tokens = token_get_all($php);
    $clean = [];
    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $clean[] = $token;
    }
    $out = [];
    for ($i = 0, $n = count($clean); $i < $n - 5; $i++) {
        $token = $clean[$i];
        if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'define' || $clean[$i + 1] !== '('
            || !is_array($clean[$i + 2]) || $clean[$i + 2][0] !== T_CONSTANT_ENCAPSED_STRING || $clean[$i + 3] !== ',') {
            continue;
        }
        $name = migration_php_literal($clean[$i + 2]);
        $value = migration_php_literal($clean[$i + 4]);
        if (is_string($name) && $clean[$i + 5] === ')' && $value !== null) {
            $out[$name] = $value;
        }
    }
    return $out;
}

function migration_php_literal(mixed $token): mixed
{
    if (!is_array($token)) {
        return null;
    }
    if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
        $value = substr($token[1], 1, -1);
        if ($token[1][0] === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
        }
        // Double-quoted PHP strings with interpolation or exotic escapes are not evaluated.
        if (preg_match('/(?<!\\\\)\$|\\\\[^\\\\\"nrtvfe$0-7x]/', $value)) {
            return null;
        }
        return stripcslashes($value);
    }
    if ($token[0] === T_LNUMBER) {
        return ctype_digit($token[1]) ? (int) $token[1] : null;
    }
    if ($token[0] === T_STRING) {
        return match (strtolower($token[1])) {'true' => true, 'false' => false, default => null};
    }
    return null;
}

function migration_check_constants(array $constants): void
{
    if (!empty($constants['MULTISITE']) || !empty($constants['SUBDOMAIN_INSTALL'])
        || isset($constants['CUSTOM_USER_TABLE'], $constants['CUSTOM_USER_META_TABLE'])
        || isset($constants['CUSTOM_USER_TABLE']) || isset($constants['CUSTOM_USER_META_TABLE'])) {
        throw new RuntimeException('Multisite and custom/shared user tables are unsupported.');
    }
    foreach (['WP_CONTENT_DIR', 'WP_CONTENT_URL', 'UPLOADS'] as $name) {
        if (isset($constants[$name])) {
            throw new RuntimeException('Custom content/upload directory constants are unsupported. Use standard wp-content/uploads layout.');
        }
    }
}

function migration_wp_config(array $config, array $constants): string
{
    $db = $config['destination']['database'];
    $host = $db['host'];
    if (str_contains($host, ':') && $host[0] !== '[') {
        $host = '[' . $host . ']';
    }
    $values = ['DB_NAME' => $db['name'], 'DB_USER' => $db['username'], 'DB_PASSWORD' => $db['password'],
        'DB_HOST' => $host . ':' . $db['port'], 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '',
        'WP_SITEURL' => $config['migration']['siteUrl'], 'WP_HOME' => $config['migration']['homeUrl'],
        'WP_DEBUG' => false, 'DISALLOW_FILE_EDIT' => true];
    foreach (['WPLANG', 'WP_DEFAULT_THEME', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_POST_REVISIONS',
        'AUTOSAVE_INTERVAL', 'EMPTY_TRASH_DAYS'] as $name) {
        if (isset($constants[$name])) {
            $values[$name] = $constants[$name];
        }
    }
    foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) {
        $values[$name] = bin2hex(random_bytes(48));
    }
    $php = "<?php\n// Generated without executing the source configuration.\n";
    foreach ($values as $name => $value) {
        $php .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
    }
    return $php . '$table_prefix = ' . var_export($db['prefix'], true) . ";\n"
        . "if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\n"
        . "require_once ABSPATH . 'wp-settings.php';\n";
}

function migration_password_hash(string $password): string
{
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new RuntimeException('Administrator password must contain 12–72 bytes and no null bytes.');
    }
    return password_hash($password, PASSWORD_BCRYPT);
}

function migration_finalize_database(PDO $db, array $config, int $authenticatedUserId): void
{
    $prefix = $config['destination']['database']['prefix'];
    $options = db_identifier($prefix . 'options');
    $q = $db->prepare('UPDATE ' . $options . ' SET option_value=? WHERE option_name=?');
    $q->execute([$config['migration']['siteUrl'], 'siteurl']);
    $q->execute([$config['migration']['homeUrl'], 'home']);
    $frontOptions = [];
    foreach (db_rows($db, 'SELECT option_name,option_value FROM ' . $options
        . " WHERE option_name IN ('page_on_front','page_for_posts','show_on_front')") as $option) {
        $frontOptions[$option['option_name']] = $option['option_value'];
    }
    $frontExists = false;
    foreach (['page_on_front', 'page_for_posts'] as $name) {
        $id = (int) ($frontOptions[$name] ?? 0);
        $exists = $id > 0 && db_scalar($db, 'SELECT ID FROM ' . db_identifier($prefix . 'posts')
            . " WHERE ID=? AND post_type='page' LIMIT 1", [$id]) !== false;
        if ($name === 'page_on_front') {
            $frontExists = $exists;
        }
        if (!$exists && isset($frontOptions[$name])) {
            $q->execute(['0', $name]);
        }
    }
    if (!$frontExists && ($frontOptions['show_on_front'] ?? '') === 'page') {
        $q->execute(['posts', 'show_on_front']);
    }
    $admin = $config['migration']['admin'];
    if ($admin['mode'] !== 'replace') {
        return;
    }
    $users = db_identifier($prefix . 'users');
    $meta = db_identifier($prefix . 'usermeta');
    $id = $authenticatedUserId;
    if ($id < 1 || db_scalar($db, 'SELECT ID FROM ' . $users . ' WHERE ID=? LIMIT 1', [$id]) === false) {
        throw new RuntimeException('The authenticated source administrator was not found in the copied destination users.');
    }
    $collision = db_scalar($db, 'SELECT ID FROM ' . $users . ' WHERE user_login=? AND ID<>? LIMIT 1', [$admin['username'], $id]);
    if ($collision !== false) {
        throw new RuntimeException('The requested administrator username already belongs to another account.');
    }
    $q = $db->prepare('UPDATE ' . $users . ' SET user_login=?,user_pass=?,user_email=?,user_nicename=?,display_name=?,user_activation_key=? WHERE ID=?');
    $q->execute([$admin['username'], migration_password_hash($admin['password']), $admin['email'],
        substr($admin['username'], 0, 50), $admin['username'], '', $id]);
    $q = $db->prepare('DELETE FROM ' . $meta . ' WHERE user_id=? AND meta_key IN (?,?)');
    $q->execute([$id, 'session_tokens', '_application_passwords']);
}

function migration_rewrite_files(array $config): array
{
    $sitePath = rtrim(parse_url($config['migration']['siteUrl'], PHP_URL_PATH) ?? '', '/');
    $homePath = rtrim(parse_url($config['migration']['homeUrl'], PHP_URL_PATH) ?? '', '/');
    $relative = trim(substr($sitePath, strlen($homePath)), '/');
    $index = "<?php\n// WordPress front controller.\ndefine('WP_USE_THEMES', true);\nrequire __DIR__ . "
        . var_export('/' . ($relative !== '' ? $relative . '/' : '') . 'wp-blog-header.php', true) . ";\n";
    $base = $homePath . '/';
    if (!preg_match('~^/[a-zA-Z0-9_/\-]*$~D', $base)) {
        throw new RuntimeException('URL paths must use ASCII letters, numbers, underscores, hyphens and slashes for safe Apache rewrite generation.');
    }
    $htaccess = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase " . $base
        . "\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\n"
        . "RewriteRule . " . $base . "index.php [L]\n</IfModule>\n# END WordPress\n"
        . "<FilesMatch \"^(wp-config\\.php|\\.env|\\.user\\.ini)$\">\nRequire all denied\n</FilesMatch>\n";
    return [$index, $htaccess];
}

function migration_administrator_capability(string $serialized): bool
{
    if (!preg_match('/^a:([0-9]+):\{/', $serialized, $header) || (int) $header[1] > 1000) {
        return false;
    }
    $position = strlen($header[0]);
    $administrator = false;
    for ($i = 0; $i < (int) $header[1]; $i++) {
        if (!preg_match('/\Gs:([0-9]+):"/', $serialized, $keyHeader, 0, $position)) {
            return false;
        }
        $position += strlen($keyHeader[0]);
        $length = (int) $keyHeader[1];
        if ($length > strlen($serialized) - $position) {
            return false;
        }
        $name = substr($serialized, $position, $length);
        $position += $length;
        if (substr($serialized, $position, 2) !== '";') {
            return false;
        }
        $position += 2;
        if (!preg_match('/\Gb:([01]);/', $serialized, $value, 0, $position)) {
            return false;
        }
        $position += strlen($value[0]);
        if ($name === 'administrator' && $value[1] === '1') {
            $administrator = true;
        }
    }
    return $administrator && substr($serialized, $position) === '}';
}

function migration_bind_administrator(PDO $database, string $prefix, array $identity): int
{
    $id = $identity['id'];
    if (db_scalar($database, 'SELECT ID FROM ' . db_identifier($prefix . 'users') . ' WHERE ID=? AND user_login=? LIMIT 1',
        [$id, $identity['username']]) === false) {
        throw new RuntimeException('The authenticated WordPress account does not match the supplied source database.');
    }
    $capabilities = db_scalar($database, 'SELECT IF(OCTET_LENGTH(meta_value)<=65536,meta_value,NULL) FROM '
        . db_identifier($prefix . 'usermeta') . ' WHERE user_id=? AND meta_key=? LIMIT 1',
        [$id, $prefix . 'capabilities']);
    if (!is_string($capabilities) || !migration_administrator_capability($capabilities)) {
        throw new RuntimeException('The authenticated WordPress user must also have the administrator role in the supplied source database.');
    }
    return $id;
}

function migration_rest_check(array $wordpress, string $homeUrl): array
{
    if ($wordpress['username'] === '' && $wordpress['password'] === '') {
        throw new RuntimeException('Source WordPress administrator username and application password are required.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('WordPress application-password verification requires PHP cURL.');
    }
    if (!str_starts_with($wordpress['url'], 'https://') || $wordpress['username'] === '' || $wordpress['password'] === '') {
        throw new RuntimeException('WordPress authentication requires an HTTPS URL, username, and application password.');
    }
    $homeUrl = migration_url($homeUrl);
    if (!str_starts_with($homeUrl, 'https://')) {
        throw new RuntimeException('The discovered source home URL must use HTTPS for application-password authentication.');
    }
    $curl = curl_init($homeUrl . '/?rest_route=/wp/v2/users/me&context=edit');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERPWD => $wordpress['username'] . ':' . $wordpress['password'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    // Use a bounded response buffer rather than exposing upstream error bodies.
    $body = '';
    curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$body): int {
        if (strlen($body) + strlen($chunk) > 262144) {
            return 0;
        }
        $body .= $chunk;
        return strlen($chunk);
    });
    $result = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $user = json_decode($body, true);
    if ($result === false || $status !== 200 || !is_array($user) || !is_int($user['id'] ?? null) || $user['id'] < 1
        || !is_string($user['username'] ?? null) || strlen($user['username']) > 60
        || strcasecmp($user['username'], $wordpress['username']) !== 0
        || empty($user['capabilities']['manage_options'])) {
        throw new RuntimeException('WordPress HTTPS application-password verification failed or account is not an administrator.');
    }
    return ['id' => $user['id'], 'username' => $user['username']];
}
