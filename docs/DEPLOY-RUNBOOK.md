# OSMHelper deploy runbook

Server: CloudPanel on `79.72.90.90`. App: `/home/osmhelper/app`. Docroot: `/home/osmhelper/htdocs/osmhelper.co.uk`.
Deploy user `bungle` (member of group `osmhelper`, no sudo). PHP-FPM runs as `osmhelper`.

## Full deploy
`./deploy/deploy.sh` backs up the live app code and database, uploads the repo (never `.env`, `vendor/` or `storage/`;
the `storage/` directory itself is left out of the tarball so unpacking can't reset it from `2770` to `2755`),
unpacks under `umask 002` with `--no-overwrite-dir`, runs Composer, installs route stubs, then fixes permissions.
`storage/` is excluded from the blanket `chmod 750/640` and handled in its own step (`2770`, group `osmhelper`,
files `660`, run with `umask 002`).

## Permissions at a glance
| Path | Owner:group | Mode | Why |
|---|---|---|---|
| `app/` dirs / files (except `storage/`) | `bungle:osmhelper` | `750` / `640` | PHP-FPM (group) reads code, can't change it |
| `app/.env` | `bungle:osmhelper` | `640` | secrets, group-readable only |
| `app/storage/` | `bungle:osmhelper` | `2770` | setgid so new files get group `osmhelper`; PHP-FPM writes here |
| files in `app/storage/` (`osmhelper.sqlite`, `-wal`, `-shm`, `settings.json`, `osm-errors.jsonl`) | `osmhelper:osmhelper` (or `bungle`) | `660` | both PHP-FPM and CLI must write |
| `/home/osmhelper/backups/` | `osmhelper:osmhelper` | `770` | CloudPanel-owned; `bungle` can't chmod it |
| `/home/osmhelper/backups/deploy/` | `bungle:osmhelper` | `750` | deploy backups; files `640` (umask 027) |

## Backups (made by deploy.sh)
Stored in `/home/osmhelper/backups/deploy/`. The newest 10 of each kind are kept; older ones are deleted by deploy.sh.
- `app-predeploy-<UTC TS>.tgz`: the live `app/` tree **without** `vendor/` and `storage/`, but **with** `.env`.
- `db-<UTC TS>.sqlite`: an online `sqlite3 .backup` copy of `storage/osmhelper.sqlite` (safe while the site is
  running; includes anything still in the WAL). It holds group settings (age cut-offs, capacity, kit locations,
  waiting-list field choices), not member data.
- Not backed up on purpose: `storage/settings.json`, `storage/osm-errors.jsonl`, and any Top awards cache
  (member names, deleted after 2 hours).

The sqlite3 step runs under `umask 007` rather than `027`, because if it has to create `osmhelper.sqlite-wal`/`-shm`
they must stay group-writable or PHP-FPM can no longer write. The backup file is then `chmod 640`.

## Restore
Code (rollback to the previous release):

    ssh bungle@79.72.90.90
    cd /home/osmhelper && B=/home/osmhelper/backups/deploy && ls -1t $B/app-predeploy-*.tgz | head
    umask 002; tar -xzf $B/app-predeploy-<TS>.tgz --no-overwrite-dir     # restores app/ (and .env) over the current code
    cd app && composer install --no-dev --optimize-autoloader --no-interaction
    # then re-run the "Permissions" and "storage writable" steps from deploy.sh (or a full deploy of the old commit)

Database (only if the DB itself is damaged; stops nothing, but do it at a quiet time):

    cd /home/osmhelper/app/storage && B=/home/osmhelper/backups/deploy
    umask 007
    sqlite3 osmhelper.sqlite ".backup '/home/osmhelper/tmp/db-before-restore.sqlite'"   # safety copy of the current DB
    sqlite3 osmhelper.sqlite ".restore '$B/db-<TS>.sqlite'"                                 # WAL-safe, in place
    chmod 660 osmhelper.sqlite* 2>/dev/null; chgrp osmhelper osmhelper.sqlite* 2>/dev/null
    # delete /home/osmhelper/tmp/db-before-restore.sqlite once the site checks out

Use `.restore` rather than copying the file over the live DB: a plain `cp` while PHP has it open (or with a stale
`-wal` next to it) can corrupt it.

## Admins (`ADMIN_OSM_USER_IDS`)
`.env` key, comma-separated OSM user IDs (e.g. `ADMIN_OSM_USER_IDS=96377`). Those users see the most recent OSM API
errors for **every** group on the signed-in home page. Everyone else sees only errors for the sections their own OSM
login can see (or no panel). Unset or empty: nobody sees other groups' errors. A user's OSM ID is shown in waiting-list
field settings as "Name (OSM user N)". Users signed in before this change see their own errors after signing in again.

## Debug files and member data
- Top awards caches (`storage/top-awards-cache-<section>.json`, member names) are deleted once older than 2 hours on
  every Top awards cache read/write, and a user's sections are cleared when they log out.
- `storage/osm-debug.json` and `storage/top-awards-dryrun.json` are only written when `OSM_DEBUG_FILES=true` is set
  in `.env`. Leave it off in production (it is off by default; `APP_DEBUG` alone doesn't turn it on).
- `storage/osm-errors.jsonl` keeps the last ~200 OSM API errors, with tokens, names, emails, phone numbers and notes
  stripped from the text fields. Numeric OSM IDs (member/section/badge) are kept for diagnosis.

## Partial (file-by-file) deploy
1. Back up the live copies of the files you'll replace to `/home/osmhelper/backups/deploy/predeploy-<name>-<UTC timestamp>/`
   (`cp -p`, then `chmod -R o-rwx`). **Never put backups in `app/storage/`.** `storage/` holds live data
   (settings, `osmhelper.sqlite` and its `-wal`/`-shm` files) and should contain nothing else.
2. `install -m 644 -g osmhelper <file> /home/osmhelper/app/<path>` for each file. New route stubs are directories in the
   docroot (`755`, group `osmhelper`) holding an `index.php` (`644`).
3. `php8.5 -l` each PHP file on the server, then smoke-test `/`, `/help/`, `/roadmap/` and the changed pages, and check
   `/home/osmhelper/logs/php/error.log` and `/home/osmhelper/logs/nginx/error.log` for new lines.
4. Leave `.env` and `storage/` alone.

Older backups made before 28 Sep 2026 are still under `app/storage/predeploy-*` (code, two `.env` copies and one copy of
`osmhelper.sqlite` with its `-wal`/`-shm` in `predeploy-hotfix-*`; no Top awards caches or debug dumps, checked 28 Sep). Move them to `/home/osmhelper/backups/deploy/` when convenient; nothing reads them.

## Database and storage from the command line
- The SQLite database (`storage/osmhelper.sqlite`) must stay writable by group `osmhelper`, including the `-wal` and
  `-shm` files SQLite creates next to it.
- **Any CLI script that opens the database or writes into `storage/` must run as `osmhelper`, or with `umask 002`**
  (for example `umask 002; php8.5 some-script.php`). Otherwise a new `-wal`/`-shm` or settings file can be created
  without group write, and the web app will then fail to save.
- Migrations run automatically on first DB use from the web app, so you rarely need to touch the DB by hand.

## No temporary check scripts in the docroot
Don't put one-off PHP check or debug scripts anywhere web-reachable (the docroot or `app/public/`), not even briefly.
Run checks from the CLI over SSH instead (for example `php8.5 -r '...'` or a script in `/home/osmhelper/tmp/` that you
delete afterwards).

## Known server config fix (Jon to apply in CloudPanel)
URLs without a trailing slash, such as `/waiting-list`, `/help` or `/roadmap`, get
`301 Location: http://osmhelper.co.uk/waiting-list/` (plain `http://`). The app isn't involved: these paths are
route-stub **directories** in the docroot, so the backend nginx vhost (the `listen 8080` server block that Varnish
talks to over plain HTTP) adds the trailing slash itself and builds an absolute URL with its own `http` scheme.
Cloudflare/port 80 then bounces the visitor back to https, so it works, but with an extra hop over http.

One-line fix, in the osmhelper.co.uk vhost (CloudPanel, Sites, osmhelper.co.uk, Vhost), inside the
`server { listen 8080; ... }` block:

    absolute_redirect off;

nginx then sends a relative `Location: /waiting-list/`, which the browser keeps on https. Reload nginx after saving.
