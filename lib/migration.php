<?php
declare(strict_types=1);

function migration_warnings(array $config): array
{
    $warnings = [
        'The destination WordPress tables and matching files will be overwritten. Back up the destination first; there is no rollback.',
        'Pause source writes for a consistent migration. Files and tables are copied in separate bounded requests, not one database snapshot.',
        'Unrelated destination files and tables are not deleted. Use an empty, dedicated destination to avoid stale content.',
        'Plugins with custom content tables are copied in full, even when standard WordPress content is excluded.',
        'When content types are selected, all taxonomy definitions and term metadata are retained to preserve empty terms and hierarchy; relationships are filtered by selected posts. Selective migrations may require a WordPress term-count recount.',
        'GUIDs are preserved. Opaque custom-serialized payloads and malformed serialized values are not rewritten.',
        'Apache rewrite files are generated; Nginx/IIS routing must be configured by the server operator.',
        'Database triggers, events, stored routines and custom PHP configuration code are not copied. Failed migrations may leave the destination in maintenance mode.',
    ];
    foreach (['source', 'destination'] as $side) {
        $protocol = $config[$side]['transfer']['protocol'];
        if ($protocol === 'ftp') {
            $warnings[] = ucfirst($side) . ' FTP sends credentials and files without encryption. Prefer pinned SFTP.';
        } elseif ($protocol === 'ftps') {
            $warnings[] = ucfirst($side) . ' native PHP FTPS encrypts traffic but cannot verify the server certificate. Use pinned SFTP on untrusted networks.';
        }
    }
    if ($config['migration']['admin']['mode'] === 'replace') {
        $warnings[] = 'Administrator replacement changes only the authenticated source administrator; other accounts are retained. The replaced account’s sessions and application passwords are revoked.';
    }
    if ($config['source']['wordpress']['username'] !== '') {
        $warnings[] = 'HTTPS REST authentication can update WordPress application-password last-used/audit metadata; migration does not write source files or content tables.';
    }
    if ($config['migration']['includeContent']) {
        $warnings[] = 'Uploads are copied only when the attachment post type is selected. If selected, the entire uploads directory is copied, including unreferenced media.';
    }
    return $warnings;
}

function migration_source_probe(array $config): array
{
    $source = new Transfer($config['source']['transfer']);
    $root = $config['source']['transfer']['path'];
    $entries = $source->listing($root);
    $names = [];
    foreach ($entries as $entry) {
        $names[$entry['name']] = $entry;
    }
    foreach (['wp-config.php', 'wp-settings.php', 'wp-blog-header.php', 'wp-load.php'] as $name) {
        if (($names[$name]['type'] ?? '') !== 'file') {
            throw new RuntimeException('Source path must be the WordPress installation directory with a local wp-config.php.');
        }
    }
    if ($names['wp-config.php']['size'] > 65536) {
        throw new RuntimeException('Source wp-config.php exceeds the 64 KiB safe parser limit.');
    }
    $php = $source->read(rtrim($root, '/') . '/wp-config.php', 0, 65537);
    $constants = migration_php_constants($php);
    migration_check_constants($constants);
    // Non-literal directory/multisite definitions cannot safely be interpreted.
    foreach (['MULTISITE', 'SUBDOMAIN_INSTALL', 'WP_CONTENT_DIR', 'WP_CONTENT_URL', 'UPLOADS', 'CUSTOM_USER_TABLE', 'CUSTOM_USER_META_TABLE'] as $name) {
        if (preg_match('/\bdefine\s*\(\s*([\'"])' . preg_quote($name, '/') . '\1\s*,/i', $php) && !array_key_exists($name, $constants)) {
            throw new RuntimeException('Dynamic source layout constants are unsupported.');
        }
    }
    $db = migration_database($config['source']['database'], true);
    $prefix = $config['source']['database']['prefix'];
    $tables = migration_tables($db, $prefix);
    $info = migration_source_info($db, $prefix);
    if (isset($constants['WP_SITEURL']) && migration_url((string) $constants['WP_SITEURL']) !== $info['siteUrl']) {
        throw new RuntimeException('Source WP_SITEURL differs from the database; normalize it before migration.');
    }
    if (isset($constants['WP_HOME']) && migration_url((string) $constants['WP_HOME']) !== $info['homeUrl']) {
        throw new RuntimeException('Source WP_HOME differs from the database; normalize it before migration.');
    }
    if ($config['source']['wordpress']['url'] !== $info['siteUrl']) {
        throw new RuntimeException('Source WordPress authentication URL must match the database siteurl.');
    }
    $authenticatedIdentity = migration_rest_check($config['source']['wordpress'], $info['homeUrl']);
    $authenticatedUserId = migration_bind_administrator($db, $prefix, $authenticatedIdentity);
    return ['source' => $info, 'postTypes' => migration_post_types($db, $prefix),
        'tables' => $tables, 'authenticatedUserId' => $authenticatedUserId, 'constants' => array_intersect_key($constants, array_flip([
            'WPLANG', 'WP_DEFAULT_THEME', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT',
            'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL', 'EMPTY_TRASH_DAYS',
        ]))];
}

function migration_preflight(array $config): array
{
    migration_assert_distinct($config);
    migration_rewrite_files($config);
    $probe = migration_source_probe($config);
    $db = migration_database($config['source']['database'], true);
    foreach ($probe['tables'] as $table) {
        if (strlen($config['destination']['database']['prefix'] . substr($table, strlen($config['source']['database']['prefix']))) > 64) {
            throw new RuntimeException('Destination prefix makes a table name exceed 64 bytes.');
        }
        $keys = db_rows($db, 'SHOW INDEX FROM ' . db_identifier($table) . " WHERE Key_name='PRIMARY'");
        if ($keys === []) {
            throw new RuntimeException('A source table lacks a primary key; safe resumable migration requires primary keys on every table.');
        }
    }
    $destinationDb = migration_database($config['destination']['database']);
    // Compare the actual server identity when aliases point at the same database.
    $sourceIdentity = db_rows($db, 'SELECT @@hostname AS host, @@port AS port, DATABASE() AS db')[0];
    $destinationIdentity = db_rows($destinationDb, 'SELECT @@hostname AS host, @@port AS port, DATABASE() AS db')[0];
    if ($sourceIdentity === $destinationIdentity) {
        throw new RuntimeException('Source and destination resolve to the same database.');
    }
    $probeTable = db_identifier('__migrator_probe_' . bin2hex(random_bytes(10)));
    $created = false;
    try {
        $destinationDb->exec('CREATE TABLE ' . $probeTable . ' (id INT PRIMARY KEY) ENGINE=InnoDB');
        $created = true;
        $destinationDb->exec('INSERT INTO ' . $probeTable . ' VALUES (1)');
        $destinationDb->exec('UPDATE ' . $probeTable . ' SET id=2 WHERE id=1');
        $destinationDb->exec('DELETE FROM ' . $probeTable . ' WHERE id=2');
    } finally {
        if ($created) {
            $destinationDb->exec('DROP TABLE ' . $probeTable);
        }
    }
    $destination = new Transfer($config['destination']['transfer']);
    $destination->assertDirectory(migration_home_root($config));
    $test = rtrim($config['destination']['transfer']['path'], '/') . '/.migrator-probe-' . bin2hex(random_bytes(12));
    try {
        $destination->write($test, 'WordPress migrator write probe.');
    } finally {
        $destination->remove($test);
    }
    return $probe;
}

function migration_skip(string $relative, array $config): bool
{
    $base = basename($relative);
    if (preg_match('/^(?:\.git|\.svn|\.env(?:\..*)?|wp-config\.php(?:[.~].*)?|\.maintenance|\.htaccess|\.user\.ini|php\.ini|web\.config)$/i', $base)) {
        return true;
    }
    if (in_array($relative, ['wp-content/cache', 'wp-content/object-cache.php', 'wp-content/advanced-cache.php',
        'wp-content/db.php', 'wp-content/sunrise.php'], true)) {
        return true;
    }
    return $relative === 'wp-content/uploads' && (!$config['migration']['includeContent']
        || !in_array('attachment', $config['migration']['postTypes'], true));
}

function migration_state_response(array $state, string $message): array
{
    $done = $state['phase'] === 'done';
    return ['ok' => true, 'state' => migrator_sign($state),
        'progress' => ['phase' => $state['phase'], 'message' => $message,
            'completed' => $state['filesCopied'] + $state['rowsCopied'], 'current' => $state['filesCopied'] + $state['rowsCopied'],
            'total' => null, 'files' => $state['filesCopied'], 'bytes' => $state['bytesCopied'],
            'rows' => $state['rowsCopied'], 'tables' => $state['tableIndex'],
            'totalTables' => count($state['tables'])], 'done' => $done];
}

function migration_tick(array $config, mixed $checkpoint): array
{
    if (!$config['migration']['confirmOverwrite']) {
        throw new RuntimeException('Explicit destination overwrite confirmation is required.');
    }
    $identity = $config;
    foreach (['source', 'destination'] as $side) {
        unset($identity[$side]['transfer']['password'], $identity[$side]['database']['password'], $identity[$side]['wordpress']['password']);
    }
    unset($identity['migration']['admin']['password']);
    $configHash = hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), migrator_checkpoint_key('configuration-identity'));
    if ($checkpoint === null) {
        $probe = migration_preflight($config);
        $job = bin2hex(random_bytes(16));
        $_SESSION['job'] = $job;
        $_SESSION['configHash'] = $configHash;
        $state = ['job' => $job, 'configHash' => $configHash, 'expires' => time() + 86400,
            'seq' => 0, 'phase' => 'prepare', 'info' => $probe['source'], 'constants' => $probe['constants'],
            'authenticatedUserId' => $probe['authenticatedUserId'],
            'tables' => $probe['tables'], 'tableIndex' => 0, 'tableMeta' => null,
            'directories' => [''], 'entries' => [], 'currentDirectory' => '', 'currentFile' => null,
            'filesCopied' => 0, 'bytesCopied' => 0, 'rowsCopied' => 0];
        $response = migration_state_response($state, 'Connections verified. Ready to prepare the destination.');
        $_SESSION['latest'] = $response;
        $_SESSION['seq'] = 0;
        return $response;
    }
    $state = migrator_verify($checkpoint, $configHash);
    if (!is_int($state['authenticatedUserId'] ?? null) || $state['authenticatedUserId'] < 1) {
        throw new RuntimeException('Checkpoint lacks verified source administrator identity. Start a new migration.');
    }
    $hasReceipt = isset($_SESSION['latest'], $_SESSION['job'], $_SESSION['configHash'])
        && hash_equals($_SESSION['job'], $state['job']) && hash_equals($_SESSION['configHash'], $configHash);
    if ($hasReceipt && $state['seq'] < ($_SESSION['seq'] ?? 0)) {
        return $_SESSION['latest'];
    }
    $_SESSION['job'] = $state['job'];
    $_SESSION['configHash'] = $configHash;
    migration_assert_distinct($config);
    $sourceRoot = rtrim($config['source']['transfer']['path'], '/');
    $destinationRoot = rtrim($config['destination']['transfer']['path'], '/');
    $message = '';
    switch ($state['phase']) {
        case 'prepare':
            $destination = new Transfer($config['destination']['transfer']);
            $destination->write($destinationRoot . '/.maintenance', "<?php \$upgrading = time();\n");
            $state['phase'] = 'files';
            $message = 'Copying WordPress files. Keep the source unchanged.';
            break;
        case 'files':
            $source = new Transfer($config['source']['transfer']);
            $destination = new Transfer($config['destination']['transfer']);
            if ($state['currentFile'] !== null) {
                $file = $state['currentFile'];
                $path = $sourceRoot . '/' . $file['relative'];
                $found = null;
                foreach ($source->listing(dirname($path)) as $entry) {
                    if ($entry['name'] === basename($path)) {
                        $found = $entry;
                        break;
                    }
                }
                if ($found === null || $found['type'] !== 'file' || $found['size'] !== $file['size'] || $found['modify'] !== $file['modify']) {
                    throw new RuntimeException('A source file changed during migration. Freeze source writes and restart.');
                }
                $length = min(Transfer::CHUNK, $file['size'] - $file['offset']);
                $bytes = $length > 0 ? $source->read($path, $file['offset'], $length) : '';
                if ($length > 0 && $bytes === '') {
                    throw new RuntimeException('Source download made no progress.');
                }
                $destination->write($destinationRoot . '/' . $file['relative'], $bytes, $file['offset']);
                $file['offset'] += strlen($bytes);
                $state['bytesCopied'] += strlen($bytes);
                if ($file['offset'] >= $file['size']) {
                    $state['currentFile'] = null;
                    $state['filesCopied']++;
                } else {
                    $state['currentFile'] = $file;
                }
                $message = 'Copying file: ' . $file['relative'];
                break;
            }
            if ($state['entries'] !== []) {
                $entry = array_shift($state['entries']);
                $relative = ltrim($state['currentDirectory'] . '/' . $entry['name'], '/');
                if (migration_skip($relative, $config)) {
                    $message = 'Skipping non-portable or excluded file.';
                    break;
                }
                if ($entry['type'] === 'unsafe') {
                    throw new RuntimeException('Source contains a symlink or special file. Remove it before migration.');
                }
                if ($entry['type'] === 'dir') {
                    if (substr_count($relative, '/') > 24 || count($state['directories']) >= 4000) {
                        throw new RuntimeException('Directory traversal safety limit exceeded.');
                    }
                    $destination->ensureDirectory($destinationRoot . '/' . $relative);
                    $state['directories'][] = $relative;
                } else {
                    if ($entry['size'] < 0 || $entry['size'] > 10737418240) {
                        throw new RuntimeException('Files larger than 10 GiB are unsupported.');
                    }
                    $state['currentFile'] = $entry + ['relative' => $relative, 'offset' => 0];
                }
                $message = 'Preparing next file or directory.';
                break;
            }
            if ($state['directories'] !== []) {
                $state['currentDirectory'] = array_shift($state['directories']);
                $state['entries'] = $source->listing($sourceRoot . '/' . $state['currentDirectory']);
                $message = 'Reading directory inventory.';
            } else {
                $state['phase'] = 'database';
                $message = 'Files copied. Starting database tables.';
            }
            break;
        case 'database':
            if ($state['tableIndex'] >= count($state['tables'])) {
                $state['phase'] = 'finalize';
                $message = 'Database copied. Applying destination settings.';
                break;
            }
            $sourceDb = migration_database($config['source']['database'], true);
            $destinationDb = migration_database($config['destination']['database']);
            $table = $state['tables'][$state['tableIndex']];
            if ($state['tableMeta'] === null) {
                $state['tableMeta'] = migration_create_table($sourceDb, $destinationDb, $table,
                    $config['source']['database']['prefix'], $config['destination']['database']['prefix'], $state['tables']);
                $message = 'Created table: ' . $table;
            } else {
                [$meta, $done, $rows] = migration_copy_rows($sourceDb, $destinationDb, $table, $state['tableMeta'], $config, $state['info']);
                $state['rowsCopied'] += $rows;
                $state['tableMeta'] = $done ? null : $meta;
                if ($done) {
                    $state['tableIndex']++;
                }
                $message = 'Copying table: ' . $table;
            }
            break;
        case 'finalize':
            migration_finalize_database(migration_database($config['destination']['database']), $config, $state['authenticatedUserId']);
            $destination = new Transfer($config['destination']['transfer']);
            // Create an empty, private file before writing any database credentials.
            $wpConfig = $destinationRoot . '/wp-config.php';
            $destination->write($wpConfig, '');
            $destination->protect($wpConfig);
            $destination->write($wpConfig, migration_wp_config($config, $state['constants']));
            $destination->protect($wpConfig);
            [$index, $htaccess] = migration_rewrite_files($config);
            $homeRoot = rtrim(migration_home_root($config), '/');
            $destination->write($homeRoot . '/index.php', $index);
            $destination->write($homeRoot . '/.htaccess', $htaccess);
            if ($homeRoot !== $destinationRoot) {
                $destination->write($destinationRoot . '/.htaccess',
                    "<FilesMatch \"^(wp-config\\.php|\\.env|\\.user\\.ini)$\">\nRequire all denied\n</FilesMatch>\n");
            }
            $destination->remove($destinationRoot . '/.maintenance');
            $state['phase'] = 'done';
            $state['constants'] = [];
            $message = 'Migration complete. Verify the destination and configure non-Apache routing if needed.';
            break;
        case 'done':
            return migration_state_response($state, 'Migration complete. Verify the destination.');
        default:
            throw new RuntimeException('Invalid migration phase.');
    }
    $state['seq']++;
    $response = migration_state_response($state, $message);
    if (strlen($response['state']['payload']) > 1500000) {
        throw new RuntimeException('Checkpoint exceeds the safety limit. Directory inventory is too large.');
    }
    $_SESSION['seq'] = $state['seq'];
    $_SESSION['latest'] = $response;
    return $response;
}
