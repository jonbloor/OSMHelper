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

Optional: `OSM_API_BASE` (default `https://www.onlinescoutmanager.co.uk`), `APP_DEBUG=true`.

### REDIRECT_URI (production)

1. In the OSM developer / OAuth app settings, set the redirect URI to:
   **`https://osmhelper.co.uk/callback`**
2. Set the same value in `/home/osmhelper/app/.env` as `REDIRECT_URI=https://osmhelper.co.uk/callback`
3. Mismatch causes OSM to reject the authorize redirect or token exchange.

Scopes requested: `section:member:read section:quartermaster:write section:finance:read`  
Also sends `access_type=offline` (same as Node) if OSM issues refresh tokens.

### Session privacy

Server session stores only: `accessToken`, `email`, `groupName`, `fullName`. No long-term scout PII.

## Routes (P0)

| Method | Path | Behaviour |
|--------|------|-----------|
| GET | `/` | Home / Connect / setup if env missing |
| GET | `/auth` | Redirect to OSM authorize |
| GET | `/callback` | Token exchange → `/dashboard` |
| GET | `/dashboard` | Auth required; profile + “P0 OAuth OK” |
| GET | `/logout` | Destroy session |

## CloudPanel deploy

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
