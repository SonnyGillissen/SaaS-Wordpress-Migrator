<?php
declare(strict_types=1);

final class Transfer
{
    private mixed $connection;
    private mixed $sftp = null;
    private array $config;
    public const CHUNK = 262144;

    public function __construct(array $config, bool $createRoot = false)
    {
        $this->config = $config;
        if ($config['protocol'] === 'sftp') {
            if (!function_exists('ssh2_connect')) {
                throw new RuntimeException('SFTP requires the optional PHP ssh2 extension.');
            }
            $this->connection = @ssh2_connect($config['host'], $config['port']);
            if (!$this->connection) {
                throw new RuntimeException('SFTP connection failed.');
            }
            $actual = @ssh2_fingerprint($this->connection, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
            $expected = strtolower(str_replace(':', '', $config['fingerprint']));
            if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
                throw new RuntimeException('SFTP host key fingerprint mismatch (40-character SHA-1 fingerprint required).');
            }
            if (!@ssh2_auth_password($this->connection, $config['username'], $config['password'])) {
                throw new RuntimeException('SFTP authentication failed.');
            }
            $this->sftp = @ssh2_sftp($this->connection);
            if (!$this->sftp) {
                throw new RuntimeException('SFTP subsystem unavailable.');
            }
        } else {
            if (!extension_loaded('ftp')) {
                throw new RuntimeException('PHP FTP extension is unavailable.');
            }
            $this->connection = $config['protocol'] === 'ftps'
                ? @ftp_ssl_connect($config['host'], $config['port'], 12)
                : @ftp_connect($config['host'], $config['port'], 12);
            if (!$this->connection || !@ftp_login($this->connection, $config['username'], $config['password'])) {
                throw new RuntimeException('FTP connection or authentication failed.');
            }
            ftp_set_option($this->connection, FTP_TIMEOUT_SEC, 12);
            ftp_set_option($this->connection, FTP_AUTOSEEK, false);
            if (defined('FTP_USEPASVADDRESS')) {
                ftp_set_option($this->connection, FTP_USEPASVADDRESS, false);
            }
            if (!@ftp_pasv($this->connection, true)) {
                throw new RuntimeException('FTP passive mode unavailable.');
            }
        }
        if ($createRoot) {
            $this->ensureDirectory($config['path']);
        } else {
            $this->assertDirectory($config['path']);
        }
    }

    public function __destruct()
    {
        if (isset($this->connection) && $this->config['protocol'] !== 'sftp') {
            @ftp_close($this->connection);
        }
    }

    public static function path(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || preg_match('/[\x00-\x1f\x7f\\\\?#%]/', $path)) {
            throw new RuntimeException('Transfer paths must be absolute POSIX paths.');
        }
        $parts = explode('/', trim($path, '/'));
        foreach ($parts as $part) {
            if ($part === '.' || $part === '..') {
                throw new RuntimeException('Dot segments are forbidden in transfer paths.');
            }
        }
        return '/' . implode('/', array_filter($parts, static fn($v) => $v !== ''));
    }

    private function uri(string $path): string
    {
        return 'ssh2.sftp://' . intval($this->sftp) . $path;
    }

    public function listing(string $path): array
    {
        $out = [];
        if ($this->sftp !== null) {
            $dir = @opendir($this->uri($path));
            if (!$dir) {
                throw new RuntimeException('Cannot list remote directory.');
            }
            try {
                while (($name = readdir($dir)) !== false) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    $stat = @ssh2_sftp_lstat($this->sftp, rtrim($path, '/') . '/' . $name);
                    if (!$stat) {
                        throw new RuntimeException('Cannot inspect remote entry.');
                    }
                    $type = $stat['mode'] & 0170000;
                    $out[] = ['name' => $name, 'type' => $type === 0040000 ? 'dir' : ($type === 0100000 ? 'file' : 'unsafe'),
                        'size' => (int) $stat['size'], 'modify' => (string) $stat['mtime']];
                    if (count($out) > 4000) {
                        throw new RuntimeException('Directory exceeds the 4,000-entry safety limit.');
                    }
                }
            } finally {
                closedir($dir);
            }
        } else {
            $list = @ftp_mlsd($this->connection, $path);
            if ($list === false) {
                throw new RuntimeException('FTP server must support MLSD for safe file type detection.');
            }
            if (count($list) > 4002) {
                throw new RuntimeException('Directory exceeds the 4,000-entry safety limit.');
            }
            foreach ($list as $entry) {
                if (in_array($entry['type'] ?? '', ['cdir', 'pdir'], true)) {
                    continue;
                }
                $out[] = ['name' => $entry['name'], 'type' => in_array($entry['type'] ?? '', ['dir', 'file'], true) ? $entry['type'] : 'unsafe',
                    'size' => (int) ($entry['size'] ?? 0), 'modify' => (string) ($entry['modify'] ?? '')];
            }
        }
        foreach ($out as $entry) {
            if ($entry['name'] === '' || strlen($entry['name']) > 255 || in_array($entry['name'], ['.', '..'], true)
                || str_contains($entry['name'], '/') || preg_match('/[\x00-\x1f\x7f\\\\?#%]/', $entry['name'])) {
                throw new RuntimeException('Unsafe remote filename.');
            }
        }
        usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public function assertDirectory(string $path): void
    {
        $path = self::path($path);
        if ($path === '/') {
            return;
        }
        $parent = dirname($path);
        $this->assertDirectory($parent);
        foreach ($this->listing($parent) as $entry) {
            if ($entry['name'] === basename($path)) {
                if ($entry['type'] !== 'dir') {
                    throw new RuntimeException('Remote path is not a real directory; symlinks are forbidden.');
                }
                return;
            }
        }
        throw new RuntimeException('Remote directory does not exist.');
    }

    public function ensureDirectory(string $path): void
    {
        $path = self::path($path);
        if ($path === '/') {
            return;
        }
        if (substr_count($path, '/') > 64) {
            throw new RuntimeException('Destination directory is too deeply nested.');
        }
        $this->ensureDirectory(dirname($path));
        foreach ($this->listing(dirname($path)) as $entry) {
            if ($entry['name'] === basename($path)) {
                if ($entry['type'] !== 'dir') {
                    throw new RuntimeException('Destination directory is a symlink or non-directory.');
                }
                return;
            }
        }
        $ok = $this->sftp !== null ? @ssh2_sftp_mkdir($this->sftp, $path, 0755) : @ftp_mkdir($this->connection, $path);
        if (!$ok) {
            throw new RuntimeException('Cannot create destination directory.');
        }
        $this->assertDirectory($path);
    }

    private function fileEntry(string $path, bool $mustExist): ?array
    {
        $this->assertDirectory(dirname($path));
        foreach ($this->listing(dirname($path)) as $entry) {
            if ($entry['name'] === basename($path)) {
                if ($entry['type'] !== 'file') {
                    throw new RuntimeException('Refusing to access a symlink or non-regular file.');
                }
                return $entry;
            }
        }
        if ($mustExist) {
            throw new RuntimeException('Source file is missing.');
        }
        return null;
    }

    public function read(string $path, int $offset, int $length = self::CHUNK): string
    {
        $this->fileEntry($path, true);
        if ($this->sftp !== null) {
            $stream = @fopen($this->uri($path), 'rb');
            if (!$stream || fseek($stream, $offset) !== 0) {
                throw new RuntimeException('Cannot seek source SFTP file.');
            }
            $bytes = stream_get_contents($stream, $length);
            fclose($stream);
        } else {
            $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
            $status = @ftp_nb_fget($this->connection, $stream, $path, FTP_BINARY, $offset);
            $deadline = microtime(true) + 10;
            while ($status === FTP_MOREDATA && ftell($stream) < $length && microtime(true) < $deadline) {
                $status = @ftp_nb_continue($this->connection);
            }
            rewind($stream);
            $bytes = stream_get_contents($stream, $length);
            fclose($stream);
            // Closing a connection with an outstanding transfer bounds each download.
            if ($status === FTP_MOREDATA) {
                @ftp_close($this->connection);
                unset($this->connection);
            } elseif ($status !== FTP_FINISHED) {
                throw new RuntimeException('FTP download failed.');
            }
        }
        if (!is_string($bytes)) {
            throw new RuntimeException('Remote read failed.');
        }
        return $bytes;
    }

    public function write(string $path, string $bytes, int $offset = 0): void
    {
        $this->fileEntry($path, false);
        if ($this->sftp !== null) {
            $stream = @fopen($this->uri($path), $offset === 0 ? 'wb' : 'c+b');
            if (!$stream || fseek($stream, $offset) !== 0) {
                throw new RuntimeException('Cannot open destination SFTP file.');
            }
            $written = 0;
            while ($written < strlen($bytes)) {
                $n = fwrite($stream, substr($bytes, $written));
                if ($n === false || $n === 0) {
                    fclose($stream);
                    throw new RuntimeException('SFTP upload failed.');
                }
                $written += $n;
            }
            fclose($stream);
        } else {
            $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
            fwrite($stream, $bytes);
            rewind($stream);
            $ok = @ftp_fput($this->connection, $path, $stream, FTP_BINARY, $offset);
            fclose($stream);
            if (!$ok) {
                throw new RuntimeException('FTP upload failed; server must support REST uploads for large files.');
            }
        }
    }

    public function remove(string $path): void
    {
        if ($this->fileEntry($path, false) === null) {
            return;
        }
        $ok = $this->sftp !== null ? @ssh2_sftp_unlink($this->sftp, $path) : @ftp_delete($this->connection, $path);
        if (!$ok) {
            throw new RuntimeException('Cannot remove destination maintenance file.');
        }
    }

    public function protect(string $path): void
    {
        if ($this->sftp !== null) {
            if (!@ssh2_sftp_chmod($this->sftp, $path, 0600)) {
                throw new RuntimeException('Cannot restrict wp-config.php permissions.');
            }
        } elseif (@ftp_chmod($this->connection, 0600, $path) === false) {
            throw new RuntimeException('FTP server must support CHMOD 0600 for wp-config.php.');
        }
    }
}
