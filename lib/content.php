<?php
declare(strict_types=1);

function content_warnings(): array
{
    return [
        'Content only: no FTP, database access, themes, plugins, users/account changes, settings, comments, media binaries, featured images, unsupported taxonomies, meta or plugin fields are copied. This is not a full clone.',
        'Authors are mapped to the authenticated destination administrator. Parent links are not copied; existing destination parents are retained. Category hierarchy and term descriptions are not copied.',
        'Existing destination content is retained. Matching type and slug may be overwritten; back up first. A slug collision is not proof of shared identity.',
        'Pause source writes until completion: ID-ordered pagination is not a snapshot. Keep the same configuration when resuming within 24 hours.',
        'Native REST has no portable idempotency marker. Slug lookup protects ordinary replays, but draft/pending creates with lost responses cannot guarantee exactly-once delivery. Do not run concurrent migrations or edit destination slugs during migration.',
        'WP URL must be the PUBLIC home URL, not a different WordPress installation URL. HTTPS certificate verification is required; redirects are refused. Application-password use may update authentication audit metadata.',
        'Only types exposing raw editable title/content can be copied. Excerpts are optional and copied only when present and supported by the destination. Custom types must support title, editor and author; internal template/navigation/style/media types are excluded. WordPress may sanitize HTML according to destination permissions/plugins.',
        'Embedded media, upload links and media/style attributes retain source URLs because files are not copied. The source must stay available for source-hosted media. Featured images and media/block ID references are not migrated or remapped; review blocks and plugin-generated content.',
        'Newer-only copies source posts strictly modified_gmt > the supplied UTC since timestamp, then updates a matching destination only when the source modified_gmt is strictly newer than the destination; equal or newer destination content is skipped.',
        'Publication instants are copied in UTC; local date display follows the destination timezone. Drafts/pending posts without a UTC date retain their source-local date instead.',
        'Bounds: at most 24 discovered REST types, 32 selected types, eight category/tag references per post, 2 MiB per REST response, and one post per tick. Other taxonomies, term hierarchy/metadata, custom fields and plugin tables are unsupported. Failed ticks may leave already-created terms/content; there is no rollback.',
        'Password-protected posts are rejected rather than risk publishing protected content without its protection.',
    ];
}

function content_url(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
        throw new RuntimeException('Content WP URL must be a valid HTTPS public home URL.');
    }
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
        throw new RuntimeException('Content WP URL must be HTTPS without credentials, query or fragment.');
    }
    $path = $parts['path'] ?? '';
    // Reject alternate encodings and dot segments rather than compare ambiguous paths.
    if (str_contains($path, '%') || str_contains($path, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
        throw new RuntimeException('Content WP URL contains an ambiguous path.');
    }
    return 'https://' . strtolower($parts['host'])
        . (isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '') . rtrim($path, '/');
}

function content_connection(mixed $value): array
{
    if (!is_array($value) || !is_array($value['wordpress'] ?? null)) {
        throw new RuntimeException('Content mode requires WordPress URL, username and application password.');
    }
    $wp = $value['wordpress'];
    foreach (['username', 'password'] as $field) {
        if (!is_string($wp[$field] ?? null) || trim($wp[$field]) === '' || strlen($wp[$field]) > 1024
            || preg_match('/[\x00-\x1f\x7f]/', $wp[$field])) {
            throw new RuntimeException('Invalid WordPress username or application password.');
        }
    }
    if (str_contains($wp['username'], ':')) {
        throw new RuntimeException('WordPress authentication username cannot contain a colon.');
    }
    return ['url' => content_url($wp['url'] ?? null), 'username' => $wp['username'], 'password' => $wp['password']];
}

function content_config(array $raw, bool $inspect): array
{
    $config = ['source' => content_connection($raw['source'] ?? null)];
    if ($inspect) {
        return $config;
    }
    $config['destination'] = content_connection($raw['destination'] ?? null);
    if ($config['source']['url'] === $config['destination']['url']) {
        throw new RuntimeException('Source and destination public home URLs must be different.');
    }
    $m = $raw['migration'];
    if (($m['includeContent'] ?? null) !== true || ($m['admin']['mode'] ?? null) !== 'keep'
        || !is_bool($m['newerOnly'] ?? null) || !is_bool($m['confirmOverwrite'] ?? null)
        || !is_array($m['postTypes'] ?? null) || !array_is_list($m['postTypes'])
        || count($m['postTypes']) > 32) {
        throw new RuntimeException('Content mode requires includeContent=true, admin.mode=keep, boolean newerOnly/confirmOverwrite and a postTypes list.');
    }
    $types = $m['postTypes'];
    foreach ($types as $type) {
        if (!is_string($type) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $type) || $type === 'attachment') {
            throw new RuntimeException('Invalid or unsupported content type; attachments are not copied.');
        }
    }
    $types = array_values(array_unique($types));
    sort($types);
    $since = $m['since'] ?? '';
    if (!is_string($since) || strlen($since) > 20) {
        throw new RuntimeException('since must be an ISO UTC timestamp.');
    }
    if ($m['newerOnly']) {
        content_time($since, true);
    } else {
        $since = '';
    }
    $config['migration'] = ['postTypes' => $types, 'newerOnly' => $m['newerOnly'],
        'since' => $since, 'confirmOverwrite' => $m['confirmOverwrite']];
    return $config;
}

function content_time(mixed $value, bool $utc = false): int
{
    $format = $utc ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s';
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC')) : false;
    if ($date === false || $date->format($format) !== $value) {
        throw new RuntimeException('A valid UTC timestamp is required (since: YYYY-MM-DDTHH:MM:SSZ).');
    }
    return $date->getTimestamp();
}

function content_request(array $wp, string $route, array $query = [], ?array $payload = null): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP cURL is required for content migration.');
    }
    if (!preg_match('~^/(?:wp/v2/[a-zA-Z0-9_/-]+)?$~D', $route)) {
        throw new RuntimeException('Unsupported content REST route.');
    }
    $remaining = ($GLOBALS['content_deadline'] ?? (microtime(true) + 35)) - microtime(true);
    if ($remaining < 1 || ++$GLOBALS['content_requests'] > 40) {
        throw new RuntimeException('Content tick request/time bound reached. Retry the checkpoint.');
    }
    $url = $wp['url'] . '/?' . http_build_query(['rest_route' => $route] + $query, '', '&', PHP_QUERY_RFC3986);
    $body = '';
    $headers = [];
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT_MS => (int) (min(12, $remaining) * 1000),
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => $wp['username'] . ':' . $wp['password'],
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            if (stripos($line, 'X-WP-Total:') === 0) {
                $headers['total'] = (int) trim(substr($line, 11));
            }
            return strlen($line);
        },
    ]);
    if ($payload !== null) {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 1572864) {
            throw new RuntimeException('Content payload exceeds the bounded REST limit.');
        }
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json]);
    }
    $success = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($success === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Content REST request failed (HTTP ' . $status . '). Check HTTPS, application-password permissions and REST availability; no remote error details are exposed.');
    }
    try {
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Content REST returned invalid JSON.');
    }
    if (!is_array($data)) {
        throw new RuntimeException('Content REST returned an invalid response.');
    }
    return ['data' => $data, 'total' => $headers['total'] ?? null];
}

function content_type_supported(string $name, array $definition): bool
{
    if (in_array($name, ['attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'custom_css',
        'customize_changeset', 'user_request'], true)) {
        return false;
    }
    if (in_array($name, ['post', 'page'], true)) {
        return true;
    }
    foreach (['title', 'editor', 'author'] as $feature) {
        if (empty($definition['supports'][$feature])) {
            return false;
        }
    }
    return true;
}

function content_probe(array $wp): array
{
    $user = content_request($wp, '/wp/v2/users/me', ['context' => 'edit'])['data'];
    if (!is_int($user['id'] ?? null) || $user['id'] < 1
        || !is_string($user['username'] ?? null) || strcasecmp($wp['username'], $user['username']) !== 0
        || ($user['capabilities']['manage_options'] ?? false) !== true) {
        throw new RuntimeException('Content REST must authenticate the exact configured username as an administrator with manage_options.');
    }
    $root = content_request($wp, '/')['data'];
    $home = content_url($root['home'] ?? null);
    $site = content_url($root['url'] ?? null);
    if ($home !== $wp['url']) {
        throw new RuntimeException('WP URL must match the REST index PUBLIC home URL exactly.');
    }
    $definitions = content_request($wp, '/wp/v2/types', ['context' => 'edit'])['data'];
    if (count($definitions) > 128) {
        throw new RuntimeException('Too many registered content types for a bounded migration.');
    }
    $statuses = content_request($wp, '/wp/v2/statuses', ['context' => 'edit'])['data'];
    $statusNames = array_keys($statuses);
    foreach ($statusNames as $status) {
        if (!is_string($status) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $status)) {
            throw new RuntimeException('Unsupported REST post status.');
        }
    }
    if (count($statusNames) > 32 || !in_array('publish', $statusNames, true)) {
        throw new RuntimeException('Unsupported REST post statuses.');
    }
    $types = [];
    foreach ($definitions as $name => $definition) {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)
            || !is_array($definition) || ($definition['rest_namespace'] ?? 'wp/v2') !== 'wp/v2'
            || !is_string($definition['rest_base'] ?? null)
            || !preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $definition['rest_base'])
            || !content_type_supported($name, $definition)) {
            continue;
        }
        $types[$name] = ['name' => $name, 'label' => is_string($definition['name'] ?? null) ? $definition['name'] : $name,
            'route' => '/wp/v2/' . $definition['rest_base'],
            'supports' => is_array($definition['supports'] ?? null) ? $definition['supports'] : [],
            'taxonomies' => is_array($definition['taxonomies'] ?? null) ? $definition['taxonomies'] : []];
    }
    return ['source' => ['homeUrl' => $home, 'siteUrl' => $site], 'user' => $user['id'],
        'types' => $types, 'statuses' => array_values(array_diff($statusNames, ['trash', 'auto-draft', 'inherit']))];
}

function content_list(array $wp, array $type, array $statuses, int $page = 1): array
{
    return content_request($wp, $type['route'], ['context' => 'edit', 'status' => implode(',', $statuses),
        'per_page' => 1, 'page' => $page, 'orderby' => 'id', 'order' => 'asc']);
}

function content_catalog(array $wp, array $probe): array
{
    if (count($probe['types']) > 24) {
        throw new RuntimeException('More than 24 REST content types require a smaller REST surface for inspection.');
    }
    $types = [];
    foreach ($probe['types'] as $type) {
        $result = content_list($wp, $type, $probe['statuses']);
        if (!is_int($result['total']) || $result['total'] < 0) {
            throw new RuntimeException('WordPress REST pagination totals are required.');
        }
        $types[] = ['name' => $type['name'], 'label' => $type['label'], 'count' => $result['total']];
    }
    return $types;
}

function content_match(array $wp, string $route, string $slug, array $statuses): ?array
{
    $result = content_request($wp, $route, ['context' => 'edit', 'slug' => $slug, 'per_page' => 2,
        'status' => implode(',', array_values(array_unique(array_merge($statuses, ['trash', 'auto-draft', 'inherit']))))]);
    if (!array_is_list($result['data']) || count($result['data']) > 1 || ($result['total'] ?? 0) > 1) {
        throw new RuntimeException('Ambiguous destination slug. Resolve duplicate content before retrying.');
    }
    $post = $result['data'][0] ?? null;
    if ($post !== null && (!is_array($post) || ($post['slug'] ?? null) !== $slug
        || !is_int($post['id'] ?? null) || $post['id'] < 1
        || in_array($post['status'] ?? '', ['trash', 'auto-draft', 'inherit'], true))) {
        throw new RuntimeException('Destination slug/status conflict; no content was overwritten.');
    }
    return $post;
}

function content_terms(array $config, array $post, string $field): array
{
    $ids = $post[$field] ?? [];
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > 8) {
        throw new RuntimeException('A post exceeds the eight related-term bound.');
    }
    $mapped = [];
    foreach (array_unique($ids) as $id) {
        if (!is_int($id) || $id < 1) {
            throw new RuntimeException('Invalid source taxonomy ID.');
        }
        $term = content_request($config['source'], '/wp/v2/' . $field . '/' . $id, ['context' => 'edit'])['data'];
        if (!is_string($term['slug'] ?? null) || $term['slug'] === '' || strlen($term['slug']) > 200
            || !is_string($term['name'] ?? null) || strlen($term['name']) > 1024) {
            throw new RuntimeException('Invalid source taxonomy fields.');
        }
        $result = content_request($config['destination'], '/wp/v2/' . $field,
            ['context' => 'edit', 'slug' => $term['slug'], 'per_page' => 2]);
        if (!array_is_list($result['data']) || count($result['data']) > 1 || ($result['total'] ?? 0) > 1) {
            throw new RuntimeException('Ambiguous destination term slug.');
        }
        $dest = $result['data'][0] ?? content_request($config['destination'], '/wp/v2/' . $field, [],
            ['name' => $term['name'], 'slug' => $term['slug']])['data'];
        if (($dest['slug'] ?? null) !== $term['slug'] || !is_int($dest['id'] ?? null) || $dest['id'] < 1) {
            throw new RuntimeException('Destination term was renamed or returned an invalid identity.');
        }
        $mapped[] = $dest['id'];
    }
    return $mapped;
}

function content_rewrite(string $html, string $source, string $destination): string
{
    // Keep media-bearing markup and CSS intact, including extensionless media URLs.
    $pattern = '~<!--[\s\S]*?-->|<a\b(?=[^>]*\bdownload(?:\s|=|>))[^>]*>'
        . '|<(?:img|source|video|audio|track|iframe|embed|object|style)\b[^>]*>(?:[^<]*</style\s*>)?'
        . '|\b(?:src|srcset|poster|data|style)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)'
        . '|' . preg_quote($source, '~') . '(?=$|[/\s"\'<>?#)])[^\s"\'<>)]*~iu';
    $rewritten = preg_replace_callback($pattern, static function (array $match) use ($source, $destination): string {
        $url = $match[0];
        if (!str_starts_with($url, $source)) {
            return $url;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : null;
        if ($path === null || preg_match('~/(?:wp-content|wp-includes|uploads|files)(?:/|$)~i', $path)
            || preg_match('/[?&](?:amp;)?(?:attachment_id|download)=/i', $url)
            || preg_match('/\.(?:avif|bmp|gif|heic|ico|jpe?g|png|svg|tiff?|webp|mp[34]|m4[av]|mov|ogg|ogv|oga|wav|webm|flac|aac|pdf|docx?|xlsx?|pptx?|zip|gz|woff2?|ttf|eot)$/i', $path)) {
            return $url;
        }
        return $destination . substr($url, strlen($source));
    }, $html);
    if ($rewritten === null) {
        throw new RuntimeException('Source editable HTML must be valid UTF-8 within parsing bounds.');
    }
    return $rewritten;
}

function content_editable_fields(array $post, array $config, array $destinationType): array
{
    $fields = ['title', 'content'];
    if (array_key_exists('excerpt', $post) && !empty($destinationType['supports']['excerpt'])) {
        $fields[] = 'excerpt';
    }
    $payload = [];
    foreach ($fields as $field) {
        if (!is_string($post[$field]['raw'] ?? null)) {
            throw new RuntimeException('Raw editable title/content and any copied excerpt are required; rendered HTML is never used.');
        }
        $payload[$field] = content_rewrite($post[$field]['raw'], $config['source']['url'], $config['destination']['url']);
    }
    return $payload;
}

function content_date_fields(array $post): array
{
    if (array_key_exists('date_gmt', $post) && $post['date_gmt'] === null
        && in_array($post['status'] ?? null, ['draft', 'pending'], true)) {
        content_time($post['date'] ?? null);
        return ['date' => $post['date'], 'date_gmt' => null];
    }
    content_time($post['date_gmt'] ?? null);
    // Core prioritizes local date over date_gmt; never send both for dated posts.
    return ['date_gmt' => $post['date_gmt']];
}

function content_copy(array $config, array $sourceProbe, array $destinationProbe, string $type, array $post): string
{
    if (!is_int($post['id'] ?? null) || $post['id'] < 1 || !is_string($post['slug'] ?? null)
        || $post['slug'] === '' || strlen($post['slug']) > 200
        || !in_array($post['status'] ?? null, $sourceProbe['statuses'], true)
        || !in_array($post['status'], $destinationProbe['statuses'], true)) {
        throw new RuntimeException('Source content needs a nonempty stable slug and supported status.');
    }
    $modified = content_time($post['modified_gmt'] ?? null);
    if ($config['migration']['newerOnly'] && $modified <= content_time($config['migration']['since'], true)) {
        return 'Skipped source content at or before the UTC cutoff.';
    }
    $route = $destinationProbe['types'][$type]['route'];
    $existing = content_match($config['destination'], $route, $post['slug'], $destinationProbe['statuses']);
    if ($existing !== null && $config['migration']['newerOnly']
        && $modified <= content_time($existing['modified_gmt'] ?? null)) {
        return 'Skipped destination content that is equally new or newer.';
    }
    if (($post['password'] ?? '') !== '') {
        throw new RuntimeException('Password-protected source content is not supported; no content was published.');
    }
    $payload = ['slug' => $post['slug'], 'status' => $post['status'], 'author' => $destinationProbe['user']];
    $payload += content_editable_fields($post, $config, $destinationProbe['types'][$type]);
    $payload += content_date_fields($post);
    foreach (['comment_status', 'ping_status'] as $field) {
        if (isset($post[$field])) {
            if (!in_array($post[$field], ['open', 'closed'], true)) {
                throw new RuntimeException('Invalid source discussion status.');
            }
            $payload[$field] = $post[$field];
        }
    }
    if (isset($post['menu_order'])) {
        if (!is_int($post['menu_order'])) {
            throw new RuntimeException('Invalid source menu order.');
        }
        $payload['menu_order'] = $post['menu_order'];
    }
    if (count($post['categories'] ?? []) + count($post['tags'] ?? []) > 8) {
        throw new RuntimeException('A post exceeds the eight related-term bound.');
    }
    foreach (['categories', 'tags'] as $field) {
        if (array_key_exists($field, $post)) {
            $taxonomy = $field === 'categories' ? 'category' : 'post_tag';
            if (!in_array($taxonomy, $destinationProbe['types'][$type]['taxonomies'], true)) {
                throw new RuntimeException('Destination type does not support a source category/tag taxonomy.');
            }
            $payload[$field] = content_terms($config, $post, $field);
        }
    }
    $written = content_request($config['destination'], $route . ($existing === null ? '' : '/' . $existing['id']), [], $payload)['data'];
    if (($written['slug'] ?? null) !== $post['slug'] || !is_int($written['id'] ?? null) || $written['id'] < 1
        || ($existing !== null && $written['id'] !== $existing['id']) || ($written['status'] ?? null) !== $post['status']
        || ($written['author'] ?? null) !== $destinationProbe['user']) {
        throw new RuntimeException('Destination content identity/status changed unexpectedly. Inspect the destination before retrying.');
    }
    return $existing === null ? 'Created one content item.' : 'Updated one matching content item.';
}

function content_response(array $state, string $message): array
{
    return ['ok' => true, 'contentTypes' => $state['contentTypes'], 'source' => $state['source'],
        'state' => migrator_sign($state), 'progress' => ['phase' => $state['phase'], 'message' => $message,
            'current' => $state['current'], 'total' => $state['total']],
        'done' => $state['phase'] === 'done', 'warnings' => content_warnings()];
}

function content_dispatch(string $action, array $raw, mixed $checkpoint): array
{
    $GLOBALS['content_deadline'] = microtime(true) + 35;
    $GLOBALS['content_requests'] = 0;
    if (!in_array($action, ['inspect', 'validate', 'tick'], true)) {
        throw new RuntimeException('Unknown content migration action.');
    }
    $config = content_config($raw, $action === 'inspect');
    $identity = $config;
    foreach (['source', 'destination'] as $side) {
        unset($identity[$side]['password']);
    }
    $hash = hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), migrator_checkpoint_key('configuration-identity'));
    $state = null;
    if ($action === 'tick' && $checkpoint !== null) {
        $state = migrator_verify($checkpoint, $hash);
        if (($state['kind'] ?? '') !== 'content' || !in_array($state['phase'] ?? '', ['content', 'done'], true)) {
            throw new RuntimeException('Invalid content checkpoint.');
        }
    }
    if ($action === 'tick' && !$config['migration']['confirmOverwrite']) {
        throw new RuntimeException('Explicit destination overwrite confirmation is required for content ticks.');
    }
    $source = content_probe($config['source']);
    if ($action === 'inspect') {
        return ['ok' => true, 'contentTypes' => content_catalog($config['source'], $source),
            'source' => $source['source'], 'state' => null, 'progress' => ['phase' => 'inspect',
                'message' => 'Source REST content discovered (unfiltered counts).', 'current' => 0, 'total' => null],
            'done' => false, 'warnings' => content_warnings()];
    }
    $destination = content_probe($config['destination']);
    if ($source['source']['siteUrl'] === $destination['source']['siteUrl']
        || $source['source']['homeUrl'] === $destination['source']['homeUrl']) {
        throw new RuntimeException('Source and destination identify the same WordPress installation.');
    }
    $selected = $config['migration']['postTypes'];
    if ($selected === []) {
        throw new RuntimeException('Select at least one discovered content type.');
    }
    foreach ($selected as $type) {
        if (!isset($source['types'][$type], $destination['types'][$type])) {
            throw new RuntimeException('A selected content type is not REST-supported on both sites.');
        }
    }
    if ($action === 'validate') {
        return ['ok' => true, 'contentTypes' => content_catalog($config['source'], $source),
            'source' => $source['source'], 'state' => null, 'progress' => ['phase' => 'validate',
                'message' => 'Read-only content preflight passed. No destination writes performed.', 'current' => 0, 'total' => null],
            'done' => false, 'warnings' => content_warnings()];
    }
    if ($state === null) {
        $catalog = content_catalog($config['source'], $source);
        $counts = [];
        foreach ($catalog as $type) {
            if (in_array($type['name'], $selected, true)) {
                $counts[$type['name']] = $type['count'];
            }
        }
        $state = ['kind' => 'content', 'job' => bin2hex(random_bytes(16)), 'configHash' => $hash,
            'expires' => time() + 86400, 'phase' => array_sum($counts) === 0 ? 'done' : 'content',
            'source' => $source['source'], 'contentTypes' => $catalog, 'counts' => $counts,
            'index' => 0, 'page' => 1, 'lastId' => 0, 'current' => 0, 'total' => array_sum($counts)];
        return content_response($state, 'Read-only preflight passed. Ready to copy content.');
    }
    if ($state['phase'] === 'done') {
        return content_response($state, 'Content migration complete.');
    }
    while (isset($selected[$state['index']]) && $state['counts'][$selected[$state['index']]] === 0) {
        ++$state['index'];
    }
    $type = $selected[$state['index']] ?? null;
    if ($type === null) {
        $state['phase'] = 'done';
        return content_response($state, 'Content migration complete.');
    }
    $result = content_list($config['source'], $source['types'][$type], $source['statuses'], $state['page']);
    if ($result['total'] !== $state['counts'][$type] || !array_is_list($result['data'])
        || count($result['data']) !== 1 || !is_array($result['data'][0])
        || !is_int($result['data'][0]['id'] ?? null) || $result['data'][0]['id'] <= $state['lastId']) {
        throw new RuntimeException('Source content changed or pagination is inconsistent. Freeze source writes and start again.');
    }
    $message = content_copy($config, $source, $destination, $type, $result['data'][0]);
    $state['lastId'] = $result['data'][0]['id'];
    ++$state['current'];
    if ($state['page'] >= $state['counts'][$type]) {
        ++$state['index'];
        $state['page'] = 1;
        $state['lastId'] = 0;
    } else {
        ++$state['page'];
    }
    if ($state['current'] >= $state['total']) {
        $state['phase'] = 'done';
    }
    return content_response($state, $message);
}
