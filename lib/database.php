<?php
declare(strict_types=1);

function db_identifier(string $name): string
{
    if ($name === '' || strlen($name) > 64 || str_contains($name, "\0")) {
        throw new RuntimeException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $name) . '`';
}

function migration_database(array $config, bool $readOnly = false): PDO
{
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('PHP pdo_mysql extension is required.');
    }
    try {
        $pdo = new PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['name'] . ';charset=utf8mb4',
            $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 12,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        if ($readOnly) {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } else {
            $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO,STRICT_ALL_TABLES'");
        }
        // MySQL and MariaDB expose different bounded SELECT timeout settings.
        try {
            $pdo->exec('SET SESSION max_execution_time = 10000');
        } catch (PDOException) {
            try {
                $pdo->exec('SET SESSION max_statement_time = 10');
            } catch (PDOException) {
                throw new RuntimeException('Database must support a per-statement execution timeout.');
            }
        }
        return $pdo;
    } catch (PDOException) {
        throw new RuntimeException('Database connection failed. Check host, port, database, credentials, and server access.');
    }
}

function db_rows(PDO $pdo, string $sql, array $params = []): array
{
    $q = $pdo->prepare($sql);
    $q->execute($params);
    try {
        return $q->fetchAll(PDO::FETCH_ASSOC);
    } finally {
        $q->closeCursor();
    }
}

function db_scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $q = $pdo->prepare($sql);
    $q->execute($params);
    try {
        return $q->fetchColumn();
    } finally {
        $q->closeCursor();
    }
}

function migration_tables(PDO $pdo, string $prefix): array
{
    $rows = db_rows($pdo, 'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LEFT(TABLE_NAME, ?) = ? LIMIT 1001', [strlen($prefix), $prefix]);
    $tables = [];
    foreach ($rows as $row) {
        $values = array_values($row);
        $name = (string) $values[0];
        if (!str_starts_with($name, $prefix)) {
            continue;
        }
        if (($values[1] ?? '') !== 'BASE TABLE' || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
            throw new RuntimeException('Only ordinary WordPress tables with alphanumeric/underscore names are supported; views are unsupported.');
        }
        $tables[] = $name;
    }
    if (count($tables) > 1000) {
        throw new RuntimeException('Database exceeds the 1,000-table safety limit.');
    }
    foreach (['options', 'posts', 'postmeta', 'users', 'usermeta', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships'] as $required) {
        if (!in_array($prefix . $required, $tables, true)) {
            throw new RuntimeException('Source database does not contain the required WordPress tables.');
        }
    }
    if (in_array($prefix . 'blogs', $tables, true) || in_array($prefix . 'site', $tables, true)) {
        throw new RuntimeException('WordPress multisite is not supported.');
    }
    sort($tables, SORT_STRING);
    return $tables;
}

function migration_source_info(PDO $pdo, string $prefix): array
{
    $options = db_rows($pdo, 'SELECT option_name, option_value FROM ' . db_identifier($prefix . 'options')
        . " WHERE option_name IN ('siteurl','home')");
    $out = [];
    foreach ($options as $option) {
        $out[$option['option_name'] === 'siteurl' ? 'siteUrl' : 'homeUrl'] = migration_url($option['option_value']);
    }
    if (!isset($out['siteUrl'], $out['homeUrl'])) {
        throw new RuntimeException('Source siteurl and home options are required.');
    }
    return $out;
}

function migration_post_types(PDO $pdo, string $prefix): array
{
    $rows = db_rows($pdo, 'SELECT post_type AS name, COUNT(*) AS count FROM ' . db_identifier($prefix . 'posts')
        . ' GROUP BY post_type ORDER BY post_type LIMIT 101');
    if (count($rows) > 100) {
        throw new RuntimeException('More than 100 post types are unsupported.');
    }
    return array_map(static fn($r) => ['name' => $r['name'], 'label' => $r['name'], 'count' => (int) $r['count']], $rows);
}

function migration_filter(string $suffix, string $prefix, array $migration): array
{
    if ($suffix === 'options') {
        return [" WHERE LEFT(option_name,11)<>'_transient_' AND LEFT(option_name,16)<>'_site_transient_' AND option_name<>'rewrite_rules'", []];
    }
    $content = ['posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships'];
    if (!in_array($suffix, $content, true)) {
        return ['', []];
    }
    if (!$migration['includeContent'] || $migration['postTypes'] === []) {
        return [' WHERE 1=0', []];
    }
    if (in_array($suffix, ['terms', 'termmeta', 'term_taxonomy'], true)) {
        return ['', []];
    }
    $params = $migration['postTypes'];
    $types = implode(',', array_fill(0, count($params), '?'));
    $posts = 'SELECT ID FROM ' . db_identifier($prefix . 'posts') . ' WHERE post_type IN (' . $types . ')';
    return match ($suffix) {
        'posts' => [' WHERE post_type IN (' . $types . ')', $params],
        'postmeta' => [' WHERE post_id IN (' . $posts . ')', $params],
        'comments' => [' WHERE comment_post_ID IN (' . $posts . ')', $params],
        'commentmeta' => [' WHERE comment_id IN (SELECT comment_ID FROM ' . db_identifier($prefix . 'comments') . ' WHERE comment_post_ID IN (' . $posts . '))', $params],
        'term_relationships' => [' WHERE object_id IN (' . $posts . ')', $params],
    };
}

function migration_replacements(array $info, array $config): array
{
    $oldSite = $info['siteUrl'];
    $oldHome = $info['homeUrl'];
    $newSite = $config['migration']['siteUrl'];
    $newHome = $config['migration']['homeUrl'];
    $map = [];
    foreach (['/wp-content', '/wp-includes', '/wp-admin', '/wp-login.php'] as $asset) {
        $map[$oldSite . $asset] = $newSite . $asset;
    }
    $map[$oldHome . '/wp-json'] = $newHome . '/wp-json';
    $map[$oldSite] = $newSite;
    $map[$oldHome] = $newHome;
    foreach ($map as $from => $to) {
        $map[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to);
    }
    uksort($map, static fn($a, $b) => strlen($b) <=> strlen($a));
    return $map;
}

function migration_create_table(PDO $source, PDO $destination, string $table, string $sourcePrefix, string $destinationPrefix, array $tables): array
{
    $ddl = array_values(db_rows($source, 'SHOW CREATE TABLE ' . db_identifier($table))[0])[1];
    if (preg_match('/\b(?:DATA\s+DIRECTORY|INDEX\s+DIRECTORY|TABLESPACE|CONNECTION\s*=|FEDERATED)\b/i', $ddl)
        || !preg_match('/\bENGINE=(InnoDB|MyISAM|Aria|MEMORY)\b/i', $ddl)) {
        throw new RuntimeException('Unsupported or unsafe source table storage options.');
    }
    $mapping = [];
    foreach ($tables as $sourceTable) {
        $mapping[db_identifier($sourceTable)] = db_identifier($destinationPrefix . substr($sourceTable, strlen($sourcePrefix)));
    }
    $ddl = strtr($ddl, $mapping);
    $target = $destinationPrefix . substr($table, strlen($sourcePrefix));
    $destination->exec('SET FOREIGN_KEY_CHECKS=0');
    $destination->exec('DROP TABLE IF EXISTS ' . db_identifier($target));
    $destination->exec($ddl);
    $columns = db_rows($source, 'SHOW FULL COLUMNS FROM ' . db_identifier($table));
    $normal = [];
    $binary = [];
    foreach ($columns as $column) {
        if (preg_match('/\b(?:VIRTUAL|STORED)\s+GENERATED\b/i', $column['Extra'])) {
            continue;
        }
        if (count($normal) > 256) {
            throw new RuntimeException('Tables with more than 256 stored columns are unsupported.');
        }
        $normal[] = $column['Field'];
        if (preg_match('/(?:binary|blob|bit)/i', $column['Type'])) {
            $binary[] = $column['Field'];
        }
    }
    $keys = db_rows($source, 'SHOW INDEX FROM ' . db_identifier($table) . " WHERE Key_name = 'PRIMARY'");
    usort($keys, static fn($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
    $primary = array_column($keys, 'Column_name');
    if ($primary === [] || array_intersect($primary, $binary) !== []) {
        throw new RuntimeException('Each migrated table must have a non-binary primary key for safe resumable copying.');
    }
    return ['columns' => $normal, 'binary' => $binary, 'primary' => $primary, 'last' => null, 'offset' => 0];
}

function migration_copy_rows(PDO $source, PDO $destination, string $table, array $meta, array $config, array $info): array
{
    $sp = $config['source']['database']['prefix'];
    $dp = $config['destination']['database']['prefix'];
    $suffix = substr($table, strlen($sp));
    [$filter, $params] = migration_filter($suffix, $sp, $config['migration']);
    $where = $filter;
    $pk = $meta['primary'];
    if ($pk !== [] && $meta['last'] !== null) {
        $where .= $where === '' ? ' WHERE ' : ' AND ';
        // Lexicographic disjunction works on MySQL versions without optimized tuple comparisons.
        $clauses = [];
        foreach ($pk as $index => $key) {
            $bits = [];
            for ($i = 0; $i < $index; $i++) {
                $bits[] = db_identifier($pk[$i]) . ' = ?';
                $params[] = $meta['last'][$i];
            }
            $bits[] = db_identifier($key) . ' > ?';
            $params[] = $meta['last'][$index];
            $clauses[] = '(' . implode(' AND ', $bits) . ')';
        }
        $where .= '(' . implode(' OR ', $clauses) . ')';
    }
    $order = $pk !== [] ? ' ORDER BY ' . implode(',', array_map('db_identifier', $pk)) : '';
    $limit = ' LIMIT 100' . ($pk === [] ? ' OFFSET ' . (int) $meta['offset'] : '');
    $sizeExpression = '(' . implode('+', array_map(static fn($column) => 'COALESCE(OCTET_LENGTH(' . db_identifier($column) . '),0)', $meta['columns'])) . ')';
    $sizeAlias = '__migrator_row_bytes';
    while (in_array($sizeAlias, $meta['columns'], true)) {
        $sizeAlias .= '_';
    }
    $selectedTypes = implode(',', array_map(static fn($type) => $source->quote($type), $config['migration']['postTypes']));
    $selectedTypes = $selectedTypes !== '' ? $selectedTypes : "'__no_selected_content__'";
    $selectedPosts = 'SELECT ID FROM ' . db_identifier($sp . 'posts') . ' WHERE post_type IN (' . $selectedTypes . ')';
    $select = [];
    foreach ($meta['columns'] as $column) {
        $expression = db_identifier($column);
        if ($suffix === 'posts' && $column === 'post_parent') {
            $expression = 'IF(post_parent IN (' . $selectedPosts . '),post_parent,0)';
        } elseif ($suffix === 'comments' && $column === 'comment_parent') {
            $expression = 'IF(comment_parent IN (SELECT comment_ID FROM ' . db_identifier($sp . 'comments')
                . ' WHERE comment_post_ID IN (' . $selectedPosts . ')),comment_parent,0)';
        }
        $select[] = 'IF(' . $sizeExpression . '<=4194304,' . $expression . ',NULL) AS ' . db_identifier($column);
    }
    $select[] = $sizeExpression . ' AS ' . db_identifier($sizeAlias);
    $query = $source->prepare('SELECT ' . implode(',', $select)
        . ' FROM ' . db_identifier($table) . $where . $order . $limit);
    $query->execute($params);
    $target = db_identifier($dp . $suffix);
    $columns = implode(',', array_map('db_identifier', $meta['columns']));
    $values = implode(',', array_fill(0, count($meta['columns']), '?'));
    $updates = implode(',', array_map(static fn($column) => db_identifier($column) . '=VALUES(' . db_identifier($column) . ')', $meta['columns']));
    $insert = $destination->prepare('INSERT INTO ' . $target . ' (' . $columns . ') VALUES (' . $values . ') ON DUPLICATE KEY UPDATE ' . $updates);
    $map = migration_replacements($info, $config);
    $copied = 0;
    $bytes = 0;
    $exhausted = false;
    $deadline = microtime(true) + 8;
    $destination->exec('SET FOREIGN_KEY_CHECKS=0');
    $destination->beginTransaction();
    try {
        // Primary-key upserts make retries safe even after a commit with a lost response.
        while ($copied < 100) {
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $exhausted = true;
                break;
            }
            if ((int) $row[$sizeAlias] > 4194304) {
                throw new RuntimeException('A database row exceeds the 4 MiB safety limit.');
            }
            unset($row[$sizeAlias]);
            $size = 0;
            foreach ($row as $value) {
                $size += is_string($value) ? strlen($value) : 8;
            }
            if ($size > 4194304) {
                throw new RuntimeException('A database row exceeds the 4 MiB safety limit.');
            }
            if ($copied > 0 && ($bytes + $size > 1048576 || microtime(true) > $deadline)) {
                break;
            }
            $last = array_map(static fn($key) => $row[$key], $pk);
            foreach ($row as $column => &$value) {
                if (!is_string($value) || in_array($column, $meta['binary'], true) || ($suffix === 'posts' && $column === 'guid')) {
                    continue;
                }
                $value = SerializedRewriter::rewrite($value, $map);
                if (($suffix === 'usermeta' && $column === 'meta_key') || ($suffix === 'options' && $column === 'option_name')) {
                    if (str_starts_with($value, $sp)) {
                        $value = $dp . substr($value, strlen($sp));
                    }
                }
            }
            unset($value);
            $insert->execute(array_values($row));
            $meta['last'] = $last;
            $meta['offset']++;
            $copied++;
            $bytes += $size;
        }
        $query->closeCursor();
        $destination->commit();
    } catch (Throwable $e) {
        $query->closeCursor();
        if ($destination->inTransaction()) {
            $destination->rollBack();
        }
        throw $e;
    }
    return [$meta, $exhausted, $copied];
}
