<?php
declare(strict_types=1);

function required_string(array $input, string $key, int $max = 1024, bool $empty = false): string
{
    $value = $input[$key] ?? null;
    if (!is_string($value) || (!$empty && $value === '') || strlen($value) > $max || str_contains($value, "\0")) {
        throw new RuntimeException('Invalid or missing setting: ' . $key . '.');
    }
    return $value;
}

function migration_url(string $url): string
{
    $p = parse_url($url);
    if (!$p || !in_array($p['scheme'] ?? '', ['https', 'http'], true) || empty($p['host'])
        || isset($p['user'], $p['pass']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])
        || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url)
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $p['path'] ?? '')
        || preg_match('/%(?:2f|5c|2e|00)/i', $p['path'] ?? '')) {
        throw new RuntimeException('Site URLs must be absolute HTTP(S) URLs without credentials, dot segments, query strings, or fragments.');
    }
    return rtrim($url, '/');
}

function migration_config(mixed $raw): array
{
    if (!is_array($raw)) {
        throw new RuntimeException('Configuration is required.');
    }
    $out = [];
    foreach (['source', 'destination'] as $side) {
        $input = $raw[$side] ?? null;
        if (!is_array($input)) {
            throw new RuntimeException('Source and destination configuration are required.');
        }
        $transfer = $input['transfer'] ?? [];
        $database = $input['database'] ?? [];
        if (!is_array($transfer) || !is_array($database)) {
            throw new RuntimeException('Invalid connection settings.');
        }
        $protocol = required_string($transfer, 'protocol', 8);
        if (!in_array($protocol, ['ftp', 'ftps', 'sftp'], true)) {
            throw new RuntimeException('Supported protocols are ftp, ftps, and sftp.');
        }
        $host = required_string($transfer, 'host', 253);
        $dbHost = required_string($database, 'host', 253);
        foreach ([$host, $dbHost] as $h) {
            if (!preg_match('/^[a-zA-Z0-9._:\-\[\]]+$/D', $h)) {
                throw new RuntimeException('Hosts must be DNS names or IP addresses, not URLs or socket paths.');
            }
        }
        $port = filter_var($transfer['port'] ?? ($protocol === 'sftp' ? 22 : 21), FILTER_VALIDATE_INT);
        $dbPort = filter_var($database['port'] ?? 3306, FILTER_VALIDATE_INT);
        if (!$port || $port > 65535 || $port < 1 || !$dbPort || $dbPort > 65535 || $dbPort < 1) {
            throw new RuntimeException('Ports must be between 1 and 65535.');
        }
        $prefix = required_string($database, 'prefix', 48);
        $name = required_string($database, 'name', 64);
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix) || !preg_match('/^[a-zA-Z0-9_$-]+$/D', $name)) {
            throw new RuntimeException('Invalid database name or table prefix.');
        }
        $path = Transfer::path(required_string($transfer, 'path', 1024));
        if (substr_count($path, '/') > 24) {
            throw new RuntimeException('Transfer path is too deeply nested.');
        }
        $fingerprint = (string) ($transfer['fingerprint'] ?? '');
        if ($protocol === 'sftp' && !preg_match('/^(?:[a-fA-F0-9]{40}|(?:[a-fA-F0-9]{2}:){19}[a-fA-F0-9]{2})$/D', $fingerprint)) {
            throw new RuntimeException('SFTP requires an independently verified 40-character SHA-1 host-key fingerprint.');
        }
        $out[$side] = ['transfer' => [
            'protocol' => $protocol, 'host' => $host, 'port' => $port,
            'username' => required_string($transfer, 'username', 255),
            'password' => required_string($transfer, 'password', 4096, true),
            'path' => $path, 'fingerprint' => $fingerprint,
        ], 'database' => [
            'host' => $dbHost, 'port' => $dbPort, 'name' => $name, 'prefix' => $prefix,
            'username' => required_string($database, 'username', 255),
            'password' => required_string($database, 'password', 4096, true),
        ]];
        if (preg_match('/[\r\n]/', $out[$side]['transfer']['username'] . $out[$side]['transfer']['password'])) {
            throw new RuntimeException('Transfer credentials cannot contain line breaks.');
        }
        $wp = $input['wordpress'] ?? [];
        if (!is_array($wp)) {
            throw new RuntimeException('Invalid WordPress authentication settings.');
        }
        $out[$side]['wordpress'] = [
            'url' => !empty($wp['url']) ? migration_url(required_string($wp, 'url', 2048)) : '',
            'username' => required_string($wp + ['username' => ''], 'username', 255, true),
            'password' => required_string($wp + ['password' => ''], 'password', 4096, true),
        ];
        if (preg_match('/[:\r\n]/', $out[$side]['wordpress']['username'])) {
            throw new RuntimeException('WordPress username cannot contain colons or line breaks.');
        }
        if ($side === 'source' && (!str_starts_with($out[$side]['wordpress']['url'], 'https://')
            || $out[$side]['wordpress']['username'] === '' || $out[$side]['wordpress']['password'] === '')) {
            throw new RuntimeException('Source WordPress HTTPS URL, administrator username, and application password are required.');
        }
    }
    $migration = $raw['migration'] ?? [];
    if (!is_array($migration)) {
        throw new RuntimeException('Migration settings are required.');
    }
    $admin = $migration['admin'] ?? ['mode' => 'keep'];
    if (!is_array($admin) || !in_array($admin['mode'] ?? 'keep', ['keep', 'replace'], true)) {
        throw new RuntimeException('Invalid administrator mode.');
    }
    $out['migration'] = [
        'includeContent' => ($migration['includeContent'] ?? true) === true,
        'postTypes' => $migration['postTypes'] ?? [],
        'admin' => ['mode' => $admin['mode'] ?? 'keep'],
        'siteUrl' => migration_url(required_string($migration, 'siteUrl', 2048)),
        'homeUrl' => migration_url(required_string($migration, 'homeUrl', 2048)),
        'confirmOverwrite' => ($migration['confirmOverwrite'] ?? false) === true,
    ];
    if (!is_array($out['migration']['postTypes']) || count($out['migration']['postTypes']) > 100) {
        throw new RuntimeException('Choose at most 100 content types.');
    }
    foreach ($out['migration']['postTypes'] as $type) {
        if (!is_string($type) || !preg_match('/^[a-zA-Z0-9_\-]{1,32}$/D', $type)) {
            throw new RuntimeException('Invalid post type.');
        }
    }
    $out['migration']['postTypes'] = array_values(array_unique($out['migration']['postTypes']));
    if ($out['migration']['admin']['mode'] === 'replace') {
        $username = required_string($admin, 'username', 60);
        $password = required_string($admin, 'password', 4096);
        $email = required_string($admin, 'email', 100);
        if (!preg_match('/^[a-zA-Z0-9_.@\-]{1,60}$/D', $username) || strlen($password) < 12
            || strlen($password) > 72 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Administrator needs a valid username, email, and a password of 12–72 bytes.');
        }
        $out['migration']['admin'] += compact('username', 'password', 'email');
    }
    migration_home_root($out);
    return $out;
}

function migration_home_root(array $config): string
{
    $site = parse_url($config['migration']['siteUrl']);
    $home = parse_url($config['migration']['homeUrl']);
    foreach (['scheme', 'host', 'port'] as $key) {
        if (strtolower((string) ($site[$key] ?? '')) !== strtolower((string) ($home[$key] ?? ''))) {
            throw new RuntimeException('Site and home URLs must share the same origin.');
        }
    }
    $sp = rtrim($site['path'] ?? '', '/');
    $hp = rtrim($home['path'] ?? '', '/');
    if ($sp !== $hp && !str_starts_with($sp, $hp . '/')) {
        throw new RuntimeException('The home URL path must equal or be a parent of the WordPress site URL path.');
    }
    $relative = trim(substr($sp, strlen($hp)), '/');
    $root = $config['destination']['transfer']['path'];
    if ($relative !== '') {
        foreach (array_reverse(explode('/', $relative)) as $segment) {
            if ($root === '/' || basename($root) !== $segment) {
                throw new RuntimeException('Destination directory must end with the site-to-home URL relative path.');
            }
            $root = dirname($root);
        }
    }
    return $root;
}

function migration_assert_distinct(array $config): void
{
    $a = $config['source']['database'];
    $b = $config['destination']['database'];
    if (strtolower($a['host']) === strtolower($b['host']) && $a['port'] === $b['port'] && $a['name'] === $b['name']) {
        throw new RuntimeException('Source and destination must use different databases, not just different prefixes.');
    }
    $a = $config['source']['transfer'];
    $b = $config['destination']['transfer'];
    $aAddresses = migration_host_addresses($a['host']);
    $bAddresses = migration_host_addresses($b['host']);
    if (strtolower($a['host']) === strtolower($b['host']) || array_intersect($aAddresses, $bAddresses) !== []) {
        $sourceNamespace = [$a['protocol'] === 'sftp' ? 'sftp' : 'ftp', $a['port'], $a['username']];
        $destinationNamespace = [$b['protocol'] === 'sftp' ? 'sftp' : 'ftp', $b['port'], $b['username']];
        if ($sourceNamespace !== $destinationNamespace) {
            throw new RuntimeException('Source and destination share a host but use different accounts, ports, or FTP/SFTP namespaces. Physical root separation cannot be proven; use separate hosts or the same account and service with disjoint directories.');
        }
        $source = rtrim($a['path'], '/') . '/';
        $destination = rtrim(migration_home_root($config), '/') . '/';
        if (str_starts_with($source, $destination) || str_starts_with($destination, $source)) {
            throw new RuntimeException('Source and destination filesystem roots must not overlap.');
        }
    }
}

function migration_host_addresses(string $host): array
{
    $host = strtolower(trim($host, '[]'));
    $addresses = [$host];
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $addresses = @gethostbynamel($host) ?: [$host];
        if ($host === 'localhost') {
            $addresses[] = '::1';
        } elseif (function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
    }
    $normalized = [];
    foreach ($addresses as $address) {
        $packed = @inet_pton($address);
        if ($packed === false) {
            $normalized[] = $address;
            continue;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        $normalized[] = bin2hex($packed);
        if ((strlen($packed) === 4 && ord($packed[0]) === 127) || $packed === inet_pton('::1')) {
            $normalized[] = 'loopback';
        }
    }
    return array_values(array_unique($normalized));
}
