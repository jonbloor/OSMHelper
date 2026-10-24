# OSMHelper (PHP)

Lean PHP 8.2+ rewrite of [jonbloor/OSMHelper](https://github.com/jonbloor/OSMHelper) (Node tagged `v1-node-final`).

**Phase 0:** OAuth login round-trip with Online Scout Manager, session-only tokens, Twig UI.

## Stack

- Plain PHP front controller (`public/index.php`)
- Composer, Twig, Guzzle, `league/oauth2-client` (GenericProvider)
- No framework

## Requirements

- PHP 8.2+ (VPS has 8.4)
- Composer 2
- OSM OAuth app credentials

## Local setup

```bash
cd app
cp .env.example .env
# edit .env — set SESSION_SECRET, CLIENT_ID, CLIENT_SECRET, REDIRECT_URI
composer install
php -S localhost:8080 -t public
```

Open http://localhost:8080 — if `.env` keys are missing you will see the setup page.

### Environment

| Variable | Purpose |
|----------|---------|
| `SESSION_SECRET` | Session signing / binding secret |
| `CLIENT_ID` | OSM OAuth client id |
| `CLIENT_SECRET` | OSM OAuth client secret |
| `REDIRECT_URI` | Must match OSM app registration **exactly** |
| `OSMHELPER_TOKEN_KEY` | 32 random bytes, base64: encrypts the WordPress form's saved OSM tokens. Generate with `php deploy/ensure-token-key.php` (writes it into `.env` without printing it). See `docs/DEPLOY-RUNBOOK.md`. |

Optional: `OSM_API_BASE` (default `https://www.onlinescoutmanager.co.uk`), `APP_DEBUG=true`.

### REDIRECT_URI (production)

1. In the OSM developer / OAuth app settings, set the redirect URI to:
   **`https://osmhelper.co.uk/callback`**
2. Set the same value in `/home/osmhelper/app/.env` as `REDIRECT_URI=https://osmhelper.co.uk/callback`
3. Mismatch causes OSM to reject the authorize redirect or token exchange.

Scopes requested: `section:member:write section:quartermaster:write section:finance:read section:badge:write section:event:read`  
Also sends `access_type=offline` (same as Node) if OSM issues refresh tokens.

### Session privacy

The server session stores your OSM access token (and refresh token when OSM sends one), your name, email, OSM user number, group, and the section list from sign-in. Anything a page loads for you (member list, group numbers, events, Find a member contacts, the Nights away selection) stays in that session only.

- **Session length:** every session ends `SESSION_ABSOLUTE_SECONDS` after sign-in (default 7200 = 2 hours), even while in use, or at sign-out (`session_destroy()`). `SESSION_IDLE_SECONDS` (default 7200) is an extra idle limit. Both are optional `.env` values (300 to 86400). Cookie lifetime and `session.gc_maxlifetime` are never shorter than the limit. Session files live in `storage/sessions/` (0700) and PHP's session GC removes them shortly after they expire. Sign-in gets a fresh session ID (`session_regenerate_id(true)`), and after re-signing in you go back to the GET page you were on (same-site paths only). Each page shows “Signed in until HH:MM (UK time)”.
- **No member data in URLs:** Find a member search terms and Nights away member selections are POSTed and kept in the session, never in URLs or the access log.
- **Stored on the server:** group settings, with the OSM user number of the leader who saved them (`waiting_field_maps.updated_by`, `wordpress_site_keys.osm_user_id`); and, for the WordPress joining form, that leader's OSM tokens, encrypted with `OSMHELPER_TOKEN_KEY` until the form is turned off. With `APP_ENV=production` (or on the live host) the app refuses to save those tokens if the key is missing. Deploy runs `bin/encrypt-site-tokens.php` for older rows.
- **5-minute OSM read cache** (`storage/osm-cache/`, `src/Osm/OsmCache.php`): to save OSM requests the waiting list pages keep, per OSM user and section, the applicants' names, dates of birth, dates joined, every waiting-list custom field (group 5) value and the leaders' notes (`cf_notes`), plus the user's section list. No tokens and no parent contact groups. Owner-only: every entry is keyed to the OSM user who fetched it and only read back for that user. Each entry is deleted 5 minutes after it was fetched: `OsmCache::sweep()` runs on every request (`App::sweepPrivateCaches()`, next to the Top awards sweep), as well as at sign-out (`forgetUser`) and, for a waiting list, whenever OSMHelper writes to it (`WaitingListService::invalidateCache`, for every user). Dir 0700, files 0600, owned by the PHP-FPM user. "Refresh from OSM" on the waiting list pages bypasses it.
- **Young Leaders copy, up to 24 hours** (same cache, kinds `ylsection` / `ylmember`, `OsmCache::KIND_TTLS`): per OSM user, section and member, only names, age text and the names of the other sections each member is in (`YoungLeaders::slimMember()` strips everything else; no dates of birth, contact or medical details). Owner-only, deleted by the sweep 24 hours after it was fetched, and deleted at sign-out (`OsmCache::forgetUser`). An expired session doesn't delete it by itself: it is deleted when that browser makes its next request (`App::enforceSessionMaxAge`), otherwise the 24-hour sweep removes it. A new sign-in clears only the previous user in that same browser (`App::beginAuthenticatedSession`). The page's Refresh button replaces it early.
- **Young Leaders limits** (`storage/yl-limits/`, `src/Members/YoungLeaderLimits.php`): per OSM user (file named by a keyed hash of the OSM user id: HMAC with `SESSION_SECRET`, or with a random per-install salt in `storage/yl-limits/.salt` if no real secret is set), timestamps, section ids and counts only: the last Young Leaders OSM call (the 1-second pause), when Refresh was last pressed per section (the 5-minute cooldown) and a reload in progress, and the member count of a section over the 150 limit. Kept across sign-out on purpose so signing in again doesn't reset the limits; each entry drops 24 hours after it was written, and a user's file is deleted once nothing in it is left, by a sweep that runs at most hourly on any request (`App::sweepPrivateCaches()`). Dir 0700, files created 0600, reads and writes locked. If the directory can't be written, Young Leaders refuses to contact OSM and says so on the page (fails closed).
- **OSM rate state** (`storage/osm-rate/`, `src/Osm/OsmRateGuard.php`): per-OSM-user backoff JSON (numbers and a reason code only), the app-wide `app-blocked.json` written when OSM sends `X-Blocked`, and the joining-form per-IP throttle counts (keyed hash of the IP, timestamps only). See `docs/DEPLOY-RUNBOOK.md` for clearing them by hand.
- **Error log** (`storage/osm-errors.jsonl`): reason codes, HTTP status, code-like OSM error codes and section/badge IDs only, never OSM message text or member IDs; entries older than 30 days are removed (rewritten in place under an exclusive file lock, so concurrent writes aren't lost). Deploy runs `bin/scrub-error-log.php` to clean lines written before this change. PHP `error_log` lines carry reason codes only.

Scout names and dates of birth are not stored long-term: the only copies outside the session are the 5-minute waiting list cache and the Young Leaders copy (names and ages, no dates of birth, up to 24 hours) above.

## Routes (P0)

| Method | Path | Behaviour |
|--------|------|-----------|
| GET | `/` | Home / Connect / setup if env missing |
| GET | `/auth` | Redirect to OSM authorize |
| GET | `/callback` | Token exchange → `/dashboard` |
| GET | `/dashboard` | Auth required; profile + “P0 OAuth OK” |
| GET | `/logout` | Destroy session |
| GET | `/members` | Auth required; tick sections from the sign-in list, then load |
| POST | `/members` | Auth + CSRF; fetch members for the ticked sections only |
| GET | `/membership-dashboard` | Auth required; tick sections from the sign-in list, then load |
| POST | `/membership-dashboard` | Auth + CSRF; fetch counts for the ticked sections, plus the waiting list |
| GET | `/equipment` | Auth required; quartermaster lists for the Equipment section, with a form to add an item |
| POST | `/equipment/move` | Auth + CSRF; review, then update Location for the ticked items |
| POST | `/equipment/add` | Auth + CSRF; review, then create one quartermaster item |
| GET | `/wordpress-form` | Auth required; WordPress joining form: plugin download, site key, setup steps |
| GET | `/wordpress-form/download` | Auth required; streams `downloads/osm-for-wordpress.zip` (refresh with `deploy/refresh-wordpress-plugin.sh`) |
| POST | `/wordpress-form/save` | Auth + CSRF; save waiting list, create/regenerate/remove site key, clear OSM block (old `/settings/update-wordpress-waiting-list` still works) |
| POST | `/api/waiting-list/submit` | WordPress plugin intake; `X-Osmhelper-Site-Key` header required (401 without) |

## CloudPanel deploy

See [docs/DEPLOY-RUNBOOK.md](docs/DEPLOY-RUNBOOK.md) for backups (outside `app/storage/`), storage permissions and `umask 002` for CLI scripts, and the nginx `absolute_redirect off;` fix.

Docroot stays: `/home/osmhelper/htdocs/osmhelper.co.uk`

App lives at: `/home/osmhelper/app/`

1. Place `deploy/htdocs-index.php` as `htdocs/osmhelper.co.uk/index.php` (requires the app public index).
2. Point nginx static assets: either symlink `htdocs/.../assets` → `/home/osmhelper/app/public/assets`, or add an nginx alias for `/assets/` to the app public assets dir.
3. Ensure PHP-FPM can read `/home/osmhelper/app`.
4. Copy `.env.example` → `.env` on the server and fill secrets (**do not invent secrets in deploy scripts**).
5. On server: `composer install --no-dev -o` inside `/home/osmhelper/app`.

Helper script (from repo root, run when ready — does **not** run automatically):

```bash
./deploy/deploy.sh
```

SSH key expected: `ssh -i /home/box/.ssh/id_ed25519 bungle@79.72.90.90`

## Layout

```
app/
  composer.json
  .env.example
  public/index.php
  public/assets/app.css
  src/Config.php
  src/App.php
  src/Osm/OsmOAuth.php
  src/Osm/OsmApi.php
  src/Http/Router.php
  src/Http/Controllers/...
  templates/...
```

## License

MIT (align with existing OSMHelper repo).
