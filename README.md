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

Content-only mode needs PHP sessions, JSON, OpenSSL, and cURL, plus outbound HTTPS
access to both WordPress sites. It does **not** require PDO MySQL, FTP, or SSH2.
Encrypted configuration packages additionally need a browser with Web Crypto
on HTTPS (localhost is allowed for development).

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

Connection details are grouped into keyboard-accessible **(S)FTP → Database →
WordPress** tabs (the destination's final tab contains its WordPress URLs).
Use the tab buttons or Next buttons to switch. Missing required fields reveal the
appropriate tab. Previously reached Source, Content, and Destination steps can be
revisited from the step bar. While a request or migration is running, navigation
is locked; pause safely first. Going back is not a rollback, and resuming a
checkpoint still requires its original locations and choices.

### Encrypted configuration packages

Expand **Import / export encrypted configuration** on Source or Destination.
Enter a unique passphrase of at least 12 characters, then export a package or
choose a previously exported JSON file and import it. Source and destination
packages are separate and cannot be imported into the wrong side. Partial
connection forms can be exported; review all required fields after importing.
Choose the migration mode before importing. Packages without the connections
needed for that mode are rejected, and omitted fields are cleared rather than
silently retaining credentials from a previously entered configuration.

Packages include connection passwords. Destination site/home URL settings are
included for full-site mode; content-only packages contain only WordPress
connection details. The migrator access key, job checkpoint, replacement account,
and overwrite consent are **never** exported. Imports never start a migration
or authorize overwrites. Import is blocked once a migration has a checkpoint.

Encryption and decryption happen entirely in the browser with AES-256-GCM and a
PBKDF2-SHA-256 key derived using 600,000 iterations, a fresh 16-byte salt, and a
fresh 12-byte nonce per export. Hostnames, usernames, URLs, and passwords are
inside the encrypted payload; only version/cryptographic metadata is readable.
Packages larger than 128 KiB, altered data, and unsupported formats are rejected.
There is no plaintext fallback or passphrase recovery.

Share the passphrase separately from the package. Protect downloaded files from
offline password guessing with a long, unique passphrase; encryption does not
protect an unlocked page or compromised computer. Passphrase fields are cleared
after each operation, and imported passwords remain in page memory only. Browser
resume state continues to exclude passwords.

### Content-only migration

Choose **Content only (WordPress connections)** on Source. Only the WordPress tab
is shown on each connection step: enter the site's **public home URL**, an
administrator username, and a WordPress application password. Both existing sites
must expose authenticated WordPress REST endpoints over HTTPS; the destination
must already have the required post types registered. No file server or database
details are requested or used, and this mode never changes site/home settings or
administrator accounts.

Select the supported post types. Optionally select **Only content modified after
a date** and supply a timestamp explicitly interpreted as **UTC**. Source items
must be modified strictly after that timestamp; matching destination items are
updated only if the source modification time is newer. Without this option,
selected source content is imported regardless of modification time.

Items are matched by post type and slug, not numeric IDs. Back up the destination
and check slug conflicts before running; unrelated destination content is not
deleted. Source content must remain unchanged during pagination/resume. Importing
through WordPress invokes normal plugin hooks, notifications, and other save
side effects. A retry can repeat those hooks even when it does not duplicate a
post.

This REST transfer is not a full database clone: themes, plugins, users, settings,
and data not exposed through supported REST fields are not migrated. Review
media links, custom blocks, plugin metadata, menus, comments, and relationships
before retiring the source. The validation/activity log describes supported
content and any transfer limitations.

Media binaries and featured images are not imported. Embedded media URLs remain
source-hosted, so **keep the source available** or migrate those assets separately.
Normal content links are rewritten to the destination; media/style attributes and
block metadata are preserved conservatively. Authors map to the authenticated
destination administrator. Categories/tags are mapped by slug, but parent-post
links, category hierarchy, term descriptions, custom taxonomies, and plugin fields
are not reconstructed. Password-protected posts are rejected rather than risk
publishing them without protection.

Each REST tick processes at most one post and eight related terms, with a 2 MiB
response limit, 40-request bound, and 35-second processing budget. Large or
unsupported content fails with an explicit error and retains its checkpoint.
WordPress can sanitize HTML according to destination permissions and plugins.

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