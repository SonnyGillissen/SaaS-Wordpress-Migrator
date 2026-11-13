# SaaS WordPress Migrator

A self-hosted, dependency-free WordPress migration wizard. Upload the files to a
PHP-enabled web directory, open the wizard, and move a single-site WordPress
installation between hosts. The source is read-only. There is no migrator
database, package manager, build step, or third-party service.

## Deploy

1. Upload `index.html`, `assets/`, `api.php`, and `lib/` to a **separate, private
   HTTPS web directory**, not inside either WordPress installation being moved.
2. Copy `config.example.php` to `config.php`, and set `access_key` to a unique,
   randomly generated value of at least 32 characters. Alternatively set the
   server environment variable `MIGRATOR_ACCESS_KEY`. Do not reuse a hosting or
   WordPress password. Never commit your configuration.
3. Open `index.html` over HTTPS. Enter the access key and connection details.
4. Back up the destination, pause source edits/orders/uploads, and keep source
   data unchanged until migration and verification finish.
5. After verification, revoke temporary credentials and **delete the migrator
   and its configuration** from the server.

Restrict this tool to your IP or an authenticated hosting control panel whenever
possible. It can connect to private services reachable from its server, so give
the access key only to trusted hosting administrators. Do not deploy it publicly
without configuring the key. PHP must execute `.php` files, never serve them as
downloads or plain text.

### Hosting requirements

“Upload and run” does not mean every host has the necessary capabilities:

- PHP 8.1+ with sessions, JSON, OpenSSL, PDO MySQL, cURL, and a working HTTPS
  certificate.
- Outbound connections to both file servers, both MySQL servers, and the source
  WordPress HTTPS endpoint. MySQL hosts must permit access from the migrator host.
- FTP/FTPS needs PHP's FTP extension and a server with MLSD support. SFTP needs
  the optional SSH2 extension and an independently verified 40-hexadecimal-character
  SHA-1 server host-key fingerprint (colons are also accepted). No libraries are
  downloaded.
- Sufficient PHP memory, request duration, and host quotas for the largest
  database row/directory listing. Work is divided into batches, but host limits
  still apply. Remote directories are limited to 4,000 entries each and transfer
  paths to 24 levels. Files are limited to 10 GiB; database rows to 4 MiB, and
  databases to 1,000 tables. Each table needs a non-binary primary key for safe
  resume. Symlinks, views, and special files are rejected, not followed.
  The destination needs space for the migrated files and tables.
- Dedicated writable destination directories and a destination database with
  permissions to create, drop, insert, and update tables.

Source and destination databases must be distinct. On a shared file-server host,
both connections must use the same account, port, and service namespace, with
disjoint directories. Ambiguous cross-account/chroot or FTP-to-SFTP combinations
are rejected conservatively because their physical paths cannot be proven
separate. Use distinct hosts rather than bypassing these source-safety checks.

Unsupported capabilities must be enabled by the hosting provider; the wizard
cannot bypass hosting firewalls, missing extensions, or filesystem permissions.
FTP is unencrypted: use it only on a trusted network. Native PHP FTPS does not
verify server certificates; prefer SFTP with a verified host key on untrusted
networks. Protect MySQL connections with a trusted
private network or an administrator-managed secure tunnel.

## Wizard

1. **Source:** enter (S)FTP details, the absolute WordPress directory, MySQL
   credentials and table prefix, plus an administrator's WordPress application
   password. Generate the application password under Users → Profile. The source
   WordPress REST API must be reachable over HTTPS. The migrator does not write
   source files or issue source SQL writes; WordPress itself may record normal
   authentication metadata when checking the application password.
2. **Content:** migrate all content, select individual post types, or migrate
   files/settings/users without standard WordPress content. Select all/none is
   available. Optionally change the authenticated administrator's username,
   password, and email **on the destination only**.
3. **Destination:** enter transfer and database details, the public home URL, and,
   if different, the WordPress site URL. Confirm that overwriting the destination
   is authorized. The transfer path points to the **WordPress directory**, not
   necessarily the public home directory.
4. **Migration:** follow the batch-by-batch activity log. Pause safely between
   requests or retry after fixing a connection failure.
5. **Finished:** test the site before changing DNS or reopening source writes.

### Different WordPress and home URLs

For example:

```text
Public home URL:       https://new.example
WordPress site URL:    https://new.example/wordpress
Transfer directory:   /public_html/wordpress
Supporting home root: /public_html
```

The home URL must be a parent path of the WordPress URL on the same origin. The
destination's corresponding parent directory must be writable. The migrator
generates the home bootstrap and Apache permalink support files, and sets
`home` and `siteurl` independently. Nginx/IIS routing and virtual-host document
roots remain hosting-administrator tasks; PHP cannot configure these servers.

### URL rewriting and content boundaries

The migration rewrites source home/site addresses to their respective destination
addresses in database text, including PHP serialized values without instantiating
objects. The two URL mappings must remain distinct when WordPress lives in a
subdirectory. Database credentials are written only into the destination
WordPress configuration. A fresh configuration is generated without executing
the source's PHP. Custom configuration code, custom content directories,
cache/database drop-ins, server configuration files, `.env`, and version-control
directories are not copied; review host-specific settings manually. A local
`wp-config.php` in the source WordPress directory is required.

Selection applies to standard WordPress post types and their related content.
An attachment is its own type; include it to retain attachment records. When any
content is included, taxonomy definitions (including empty and parent categories)
are retained to preserve hierarchies; only selected posts' relationships remain.
Content stored by plugins in custom tables or arbitrary files cannot be reliably
classified by post type. Review those tables/files and privacy implications
before using “no content” as a clean template. Selecting attachments copies the
entire uploads directory, including unreferenced media; excluding attachments
skips uploads. Unrelated files/tables already on the destination are **not**
deleted. GUIDs are intentionally preserved, and malformed or opaque custom
serialization is left unchanged rather than risking corruption.

This is not a DNS/email/domain migration, a multisite migrator, or an automatic
repair tool for incompatible PHP, themes, or plugins. URLs assembled in code,
hard-coded theme/plugin files, external/CDN domains, encrypted data, filesystem
paths in plugin settings, and external services need manual verification.
Do not assume source `.htaccess` rules suit a different hosting stack.

## Resume and browser storage

The wizard stores connection settings, choices, and the last confirmed checkpoint
in **this browser's localStorage**. It does **not** store the access key, transfer
passwords, database passwords, WordPress application password, or replacement
administrator password. Credentials remain in page memory and are sent to the
PHP backend for each request. Clear the saved migration when finished, especially
on shared computers. Browser storage still contains hostnames, usernames, paths,
and site addresses.
The backend checkpoint is encrypted and authenticated; the surrounding connection
settings are not encrypted. Browser storage is not a substitute for protecting
your computer or server.

To resume after closing the page, use **Restore migration**, re-enter all
passwords and the access key, reconnect, retain the original choices, and start
again. Failed batches can be replayed. Keep the same access key, source data,
destination, and settings. Checkpoints expire 24 hours after the job starts;
changing the server access key also invalidates them. Reauthenticate after a PHP
session expires; the checkpoint itself is independent of that session.
Do not run two tabs or migration tools against the
same destination. Resume is not a rollback: partly migrated files/tables remain
on failure. Restore your backup if abandoning the migration, including any
destination `.maintenance` file left by the job. Restrict public access to the
destination until verification finishes; WordPress maintenance mode does not
protect static files or every direct entry point. Browser storage clearing/private-mode
restrictions can lose checkpoints.

The tool has no durable job scheduler: closing the tab stops subsequent batches.
PHP may finish an in-flight batch; the browser retries from its last acknowledged
checkpoint. Server-side session storage is used for CSRF protection, not as a
migrator database or credential store. Detailed activity is kept in page memory,
not sent to an external logging service.

## Verification

There are no dependencies or existing automated test suite. PHP files can be
checked with `php -l`, and the frontend with `node --check assets/app.js` if Node
is available for development. Node is **not** needed on the deployment host.
Use disposable source/destination fixtures before migrating production data.
Always verify serialized settings, both URL mappings, selective content,
administrator login, uploads, interrupted requests, and a restored checkpoint.