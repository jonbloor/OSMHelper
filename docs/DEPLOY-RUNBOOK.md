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
- `app-predeploy-<UTC TS>.tgz`: the live `app/` tree **without** `vendor/`, `storage/`, `.env` or any `.env.bak*`
  (from 22 Oct 2026). A deploy never touches the live `.env`, so a rollback keeps using it. Archives made before
  that date still contain `.env`: treat them as secret until they age out (only the newest 10 are kept).
- `db-<UTC TS>.sqlite`: an online `sqlite3 .backup` copy of `storage/osmhelper.sqlite` (safe while the site is
  running; includes anything still in the WAL). It holds group settings (age cut-offs, capacity, kit locations,
  waiting-list field choices) and WordPress joining form site keys with the leader's OSM tokens, not member data.
  Since 22 Oct 2026 those tokens are encrypted with `OSMHELPER_TOKEN_KEY`, which is only in `.env` (never in a
  backup). Still treat it as secret.
- Not backed up on purpose: `storage/settings.json`, `storage/osm-errors.jsonl`, `storage/sessions/`, and any
  Top awards cache (member names, deleted after 2 hours).
- **`.env` and `OSMHELPER_TOKEN_KEY` are intentionally excluded from every backup** (so a backup on its own can't
  decrypt the stored tokens). Keep one copy of `.env` (or at least the key) **off the server**, e.g. in Jon's
  password manager, never in git, email or chat. If the key is lost, the stored WordPress form tokens can't be
  read: set a new key (`php deploy/ensure-token-key.php` after removing the old line) and each leader using the
  WordPress joining form signs in and presses Save on **Members → Joining form** again (the site key on their
  WordPress site can stay the same unless they regenerate it).

Only the `sqlite3` command runs under `umask 007` (the rest of the backup step uses `027`). Opening the live DB can make
sqlite3 create `storage/osmhelper.sqlite-wal`/`-shm`; under `027` those would be `640` and PHP-FPM (group `osmhelper`)
couldn't write to them, so the site would fail to save. With `007` they are `660`, and they get group `osmhelper` from
the setgid `storage/` dir. The backup file sqlite3 writes is then `chmod 640` (owner `bungle`, group `bungle`; the
`backups/deploy/` dir has no setgid), so PHP-FPM can't read backups.

## Restore
Code (rollback to the previous release):

    ssh bungle@79.72.90.90
    cd /home/osmhelper && B=/home/osmhelper/backups/deploy && ls -1t $B/app-predeploy-*.tgz | head
    umask 002; tar -xzf $B/app-predeploy-<TS>.tgz --no-overwrite-dir     # restores app/ code (the live .env is left as is)
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
`.env` key, comma-separated OSM user IDs. Live value: `ADMIN_OSM_USER_IDS=96377` (Jon). Only those users see the
"Recent OSM API errors" panel (last 8 errors, all groups) on the signed-in home page; nobody else sees it at all.
Unset or empty: nobody sees it. A user's OSM ID appears in waiting-list field settings as "Name (OSM user N)".

## Debug files and member data
- Top awards caches (`storage/top-awards-cache-<section>.json`, member names) are deleted once older than 2 hours on
  every request (swept in `App::run()`), and a user's sections are cleared when they log out.
- There are no debug dump files any more: `OsmDebug` (`storage/osm-debug.json`) and the Top awards dry-run dump
  (`storage/top-awards-dryrun.json`) were removed from the code on 22 Oct 2026, and `OSM_DEBUG_FILES` does nothing.
  Old copies of those files on the server can be deleted.
- `storage/osm-errors.jsonl` keeps the last ~200 OSM API errors, at most 30 days old (older entries are pruned on
  every request), with tokens, names, emails, phone numbers, postcodes and notes stripped from the text fields
  (`App\Support\LogSafe::redact()`). Only section and badge IDs are kept; member IDs (scoutid) are never written.
- PHP `error_log` lines from OSMHelper carry a reason code (`LogSafe::reason()`: exception class, code and a fixed
  reason such as `osm_http_429`) plus a section ID at most, never member IDs, OSM reply text or search terms.
- Find a member searches are POSTed (`/find-member/search/`, CSRF-checked) and kept in the session, so search
  terms never appear in URLs or the nginx access log.
- Nights away selections (the picker and the “Nights” buttons on Members / Find a member) are POSTed to
  `/nights-away/select/` and kept in the session, so member IDs stay out of URLs and the access log. Old
  `/nights-away/?scoutid=` bookmarks still work: the IDs move into the session and the page redirects to a clean URL
  (that one old-style request is still in the access log).
- `osm-errors.jsonl` never stores OSM message text: reason/action, HTTP status, a code-like OSM error code and
  section/badge IDs only. Pruning (30 days) and rotation rewrite the file in place under an exclusive `flock`, so
  concurrent requests can't lose or duplicate lines. deploy.sh runs `bin/scrub-error-log.php` (prints counts only)
  to strip `osm_message`/`scoutid` and OSM text from lines written before this change.

## OSM rate limit: cache, backoff and the X-Blocked stop
Two storage dirs are created by PHP-FPM itself (owner `osmhelper`, dir `0700`, files `0600`). deploy.sh **prunes
both from its storage chmod** (next to `storage/sessions/`), so a deploy never loosens them; `bungle` can't read them.
Don't back them up.

| Path | Holds | Lifetime |
|---|---|---|
| `storage/osm-cache/` | **Personal data.** Per OSM user and section: waiting-list applicants' names, dates of birth, dates joined, every waiting-list custom field and the leaders' notes; the user's section list. Owner-only (keyed to the OSM user who fetched it). Plus `inv-*.mark` invalidation markers (a timestamp only). | Each entry is deleted 5 minutes after it was fetched by `OsmCache::sweep()` on every request (`App::sweepPrivateCaches()`), at sign-out, and for a waiting list whenever OSMHelper writes to it. Markers: 1 hour. |
| `storage/yl-limits/<hash>.json` (+ `.swept`, and `.salt` if no real `SESSION_SECRET`) | Young Leaders limits per OSM user (file name = HMAC of the OSM user id with `SESSION_SECRET`, or with the `.salt` file): last call time, Refresh cooldown and progress per section, the count of a section over 150. No names. | Each entry is dropped 24 hours after it was written; a file with nothing left is deleted by `YoungLeaderLimits::sweep()` (at most hourly, on any request). Survives sign-out on purpose. If the dir can't be written, Young Leaders refuses to contact OSM. |
| `storage/osm-rate/<hash>.json` (+ `.lock`) | Per-OSM-user backoff: numbers and a reason code (`osm_backoff_429`, `osm_backoff_exhausted`, `osm_rate_low`). File name = first 40 hex chars of `sha256("osm-user:<id>")`. | Until the backoff ends (then ignored). |
| `storage/osm-rate/app-blocked.json` | App-wide stop, written when OSM sends `X-Blocked`. While it exists **every** OSM call is refused for every user: page loads, saves, sign-in, token refresh and the WordPress joining form. | **Until cleared by hand** (below). Never expires on its own. |
| `storage/osm-rate/intake-<hash>.json` | Joining-form per-IP throttle (20 submits / 15 min; keyed hash of the IP, timestamps only). | Swept 15 minutes after the last submit. |

- Changing `SESSION_SECRET` resets everyone's saved Young Leaders limits once (the files get new names, so the
  1-second pause, Refresh wait and too-large markers start afresh); the old files are swept within about a day.
- Fail closed: if `storage/osm-rate/` can't be created or written, the app refuses all OSM calls (sign-in included),
  shows "OSMHelper is not contacting OSM right now", and logs `osm_rate_state_unwritable`. Fix ownership
  (`chown -R osmhelper:osmhelper storage/osm-rate; chmod 700 storage/osm-rate`) and it recovers on the next request.
- If `storage/osm-cache/` can't be written the app logs `osm_cache_unwritable` and just doesn't cache (more OSM calls).

### Clearing the X-Blocked stop by hand
Carrying on after `X-Blocked` can make OSM's block permanent. Only clear it once OSM support has confirmed the block
is lifted and the cause is fixed. Log `kind: blocked` lines in `storage/osm-errors.jsonl` show when it happened.

    cd /home/osmhelper/app
    sudo -u osmhelper php8.5 bin/osm-clear-block.php            # show status (BLOCKED since ... UTC / Not blocked)
    sudo -u osmhelper php8.5 bin/osm-clear-block.php --clear    # lift it

(Or, as `osmhelper`: `rm storage/osm-rate/app-blocked.json`.) WordPress site keys marked blocked by the same event
stay blocked until the leader clears them on Members → Joining form.

### Clearing a stuck per-user backoff by hand
Backoffs end on their own (at most about 25 hours). Only clear one early if you are sure OSM's limit has reset:

    sudo -u osmhelper php8.5 bin/osm-clear-block.php --user=<OSM user id>           # show
    sudo -u osmhelper php8.5 bin/osm-clear-block.php --user=<OSM user id> --clear   # clear

(Or delete `storage/osm-rate/<first 40 hex of sha256("osm-user:<id>")>.json` as `osmhelper`.)
Removing the whole `storage/osm-rate/` dir clears every backoff **and the X-Blocked stop**, so don't.

## Sessions (2 hours, even while working)
- Limits come from `.env` (optional, seconds, clamped to 300..86400): `SESSION_ABSOLUTE_SECONDS` (default 7200:
  every session ends 2 hours after sign-in, even while in use) and `SESSION_IDLE_SECONDS` (default 7200).
- PHP sessions are stored in `app/storage/sessions/` (created by PHP-FPM as `osmhelper`, `0700`, outside the
  docroot, not backed up), not the system `/var/lib/php/sessions`. `session.gc_maxlifetime` and the cookie
  lifetime are the longer of the two limits (7200 s by default), with a 1-in-50 GC chance per request.
- The app enforces the limit itself (`App::enforceSessionMaxAge()`): when it is reached the session is emptied
  (Top awards caches cleared) and given a new ID, so the leader signs in again. Logout calls `session_destroy()`.
- Sign-in (`/callback`) starts a clean session with a new ID (`App::beginAuthenticatedSession()`,
  `session_regenerate_id(true)`): no session fixation, and the cookie is re-sent so its lifetime counts from
  sign-in. After sign-in the leader goes back to the GET page that needed it (`App::safeReturnPath()`:
  same-site paths only, never `/auth`, `/callback`, `/logout`).
- Every signed-in page shows “Signed in until HH:MM (UK time)” (plain text, no script).
- Switching the save path signs everyone out once when it first goes live.
- If `storage/sessions/` can't be created or written, the app logs `session_dir_create_failed` /
  `session_dir_not_writable` and falls back to PHP's default path (the in-app 2-hour limit still applies).
- deploy.sh leaves `storage/sessions/` alone (it is owned by `osmhelper`, so `bungle` couldn't chmod it anyway).

## WordPress form token encryption (`OSMHELPER_TOKEN_KEY`)
`wordpress_site_keys.access_token` / `refresh_token` are encrypted at rest with libsodium secretbox
(`App\Store\TokenCrypto`, stored as `sb1:<base64 nonce+ciphertext>`). The key is 32 random bytes, base64, in
`.env` as `OSMHELPER_TOKEN_KEY`. Never print it, never commit it, and never change it once tokens are saved
(encrypted tokens would become unreadable; leaders would have to sign in and press Save on the Joining form again).

Adding the key (deploy.sh does this automatically via `deploy/ensure-token-key.php`, which only fills a missing or
empty value and never prints it). By hand, on the server:

    cd /home/osmhelper/app
    # prints only "set" or "missing" (never the value). Reads the value the app sees (Dotenv::parse,
    # last definition wins, trimmed); "set" only for a 32-byte base64 key:
    php -r 'require "vendor/autoload.php"; try { $v = trim((string) (Dotenv\Dotenv::parse((string) file_get_contents(".env"))["OSMHELPER_TOKEN_KEY"] ?? "")); } catch (Throwable $e) { $v = ""; } $k = base64_decode($v, true); echo (is_string($k) && strlen($k) === 32) ? "set\n" : "missing\n";'
    # if it printed "missing":
    php deploy/ensure-token-key.php             # prints "OSMHELPER_TOKEN_KEY set" (or "already set")

ensure-token-key.php appends a key only when `.env` has no `OSMHELPER_TOKEN_KEY` line at all (it writes a temp
file and renames it, keeping the owner, group and mode). If a line exists (including `export`, leading spaces or
`KEY = value`) but the value is empty or not a valid key, it changes nothing and exits 1 with a message: fix
`.env` by hand (restore the original key from the off-server copy if tokens were saved with it).

deploy.sh runs ensure-token-key against the live `.env` *before* unpacking the new code (so production never
runs without a key), and again after `composer install` for a first install. After the code is in place it runs
`bin/encrypt-site-tokens.php` and then `bin/scrub-error-log.php`, both under `umask 007`.
Encrypting rows saved before the key existed by hand (safe to re-run; prints counts only):

    cd /home/osmhelper/app && (umask 007; php bin/encrypt-site-tokens.php)
    # e.g. "wordpress_site_keys rows: 1, encrypted now: 1, already encrypted: 0"

`bin/` is outside the docroot and the script refuses to run outside the CLI. Without the migration, plaintext rows
are still read normally and are encrypted the first time they are read (e.g. the next form submission) once the key
is set. If the key is missing or invalid the PHP log shows `OSMHelper token encryption: token_key_missing` (or
`token_key_invalid`, `token_sodium_missing`), and:
- **In production** (any `APP_ENV` except `dev`, `local`, `development`, `test` or `testing`; if `APP_ENV` is
  unset or empty, the `osmhelper.co.uk` host counts as production) the app **fails closed**: it never saves a token unencrypted (`token_write_refused_no_key`). Saving
  on the Joining form page shows an error, the page shows a warning (admins in `ADMIN_OSM_USER_IDS` also see the
  reason code; it says a form already set up keeps sending for now but may stop once its saved OSM access needs
  renewing), and a token refresh during a form submission isn't saved, so the form may stop working once the
  stored token can no longer be refreshed. Fix the key promptly. Existing plaintext rows can still be read.
- Elsewhere (development) new tokens are saved unencrypted.
- An encrypted token that can't be opened logs `token_decrypt_failed` and the form asks the leader to save again.
- Live `.env` should have `APP_ENV=production` (add it by hand; deploy.sh doesn't edit `.env` apart from the key).
Database backups taken before the migration still hold plaintext tokens.

## WordPress plugin download (/wordpress-form/)
Signed-in leaders download the plugin from `/wordpress-form/download/`, which streams
`downloads/osm-for-wordpress.zip` (outside `public/`, so it's never served as a static file). The page shows the
version, build commit and date from `downloads/osm-for-wordpress.json`.

To publish a new plugin build:
1. Commit and push the plugin change in `osm-for-wordpress` (branch `feature/waiting-list-form` until it's merged).
2. From the OSMHelper repo root: `deploy/refresh-wordpress-plugin.sh /path/to/osm-for-wordpress`
   (builds the zip from `src/` as an `osm-for-wordpress/` folder without `node_modules`, `tests` or `.git`, and writes
   the manifest). To ship a zip you've already built, use `ZIP=/path/to/zip deploy/refresh-wordpress-plugin.sh /path/to/repo`.
   Bump `Version:` in the plugin header first if you want the version number on the page to change.
3. Commit `downloads/osm-for-wordpress.zip` and `downloads/osm-for-wordpress.json`, push, run `./deploy/deploy.sh`.

## Partial (file-by-file) deploy
1. Back up the live copies of the files you'll replace to `/home/osmhelper/backups/deploy/predeploy-<name>-<UTC timestamp>/`
   (`cp -p`, then `chmod -R o-rwx`). **Never put backups in `app/storage/`.** `storage/` holds live data
   (settings, `osmhelper.sqlite` and its `-wal`/`-shm` files) and should contain nothing else.
2. `install -m 644 -g osmhelper <file> /home/osmhelper/app/<path>` for each file. New route stubs are directories in the
   docroot (`755`, group `osmhelper`) holding an `index.php` (`644`).
3. `php8.5 -l` each PHP file on the server, then smoke-test `/`, `/help/`, `/roadmap/` and the changed pages, and check
   `/home/osmhelper/logs/php/error.log` and `/home/osmhelper/logs/nginx/error.log` for new lines.
4. Leave `.env` and `storage/` alone.

Older ad-hoc backups (`app/storage/predeploy-*`, and `env.bak.*`, `settings.bak.*`, `storage.bak.*` in
`/home/osmhelper/tmp/`) were deleted on 28 Sep 2026 after the first `backups/deploy/` backup was verified.
Keep all backups in `/home/osmhelper/backups/deploy/` from now on.

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
