# OSMHelper PHP rewrite plan (lean)

**Decision (Jon, 2026-09-06):** Rewrite on this VPS in PHP — not revive Node.  
**Source of behaviour:** https://github.com/jonbloor/OSMHelper (Node/Express/EJS) — reuse OAuth model, features, and OSM API shapes; do not invent new product scope.  
**Audience:** Volunteer Scouts tooling (not oomi).  
**Live blocker:** Cloudflare **526** on https://osmhelper.co.uk — origin presents Cloudflare Origin CA with CN=`Cloudflare` and **no SAN** for `osmhelper.co.uk`. Full (strict) requires matching CN/SAN. HTTP `:8080` already serves the placeholder site.

## P0 — SSL / smoke path

**Status (2026-09-06):** Cloudflare **526 cleared**. Public `https://osmhelper.co.uk/` returns **200** `Hello World :-)`.

**Root cause:** Origin nginx presented a Cloudflare Origin CA cert with CN=`Cloudflare` and **no SAN** for `osmhelper.co.uk`. Full (strict) rejects that.

**Immediate fix:** CF SSL/TLS encryption mode moved from **Full (strict)** → **Full** (encrypts to origin, does not validate hostname on origin cert). DNS remains orange-cloud to `79.72.90.90`.

**Follow-up (before returning to Full strict):** Re-issue/reinstall Origin CA covering `osmhelper.co.uk` + `www` (or `*.osmhelper.co.uk`) into CloudPanel/nginx. CF dashboard already lists a matching Origin CA (expires ~2041); private key may not be recoverable if not saved — recreate Origin CA if needed. `bungle` is in `osmhelper` group for htdocs but SSL install needs CloudPanel/root.

## Repo approach (recommendation)

**Evolve `jonbloor/OSMHelper` on `main` (or `php` branch → main)** rather than a new repo.

| Option | Pros | Cons |
|--------|------|------|
| **A. Same repo, PHP replaces Node (recommended)** | One product URL/history; README/issues stay; GitHub already linked from UI | Brief disruption while Node deleted; tag last Node release |
| B. New repo `OSMHelper-php` | Clean slate | Split history; update links; two repos to maintain |
| C. Monorepo `node/` + `php/` | Parallel compare | Extra complexity; Jon already chose rewrite |

**Recommendation:** Option A — tag `v1-node-final` from current main, then replace tree with PHP app (or land on branch `php` and merge after Geoffrey smoke). Keep MIT/license consistent with existing repo unless Jon says otherwise.

## Architecture (lean PHP)

- **Runtime:** PHP 8.2+ on CloudPanel (nginx + PHP-FPM) under `/home/osmhelper/htdocs/osmhelper.co.uk`.
- **Style:** Small MVC or single `public/index.php` front controller + `src/` — **no heavy framework required** (or slim Laravel/Symfony only if Jon prefers; default = plain PHP + Composer).
- **Auth:** OSM OAuth 2 (Authorization Code) via `simple-oauth2`-equivalent (e.g. `league/oauth2-client` custom provider). Store **access token in server session only** (same privacy posture as Node).
- **HTTP client:** Guzzle/curl wrapper with: Bearer token, rate-limit header tracking, short TTL cache (APCu or file), concurrency limits for member fan-out.
- **Templates:** Twig or plain PHP views (parity with current EJS pages).
- **Config:** `.env` + `.env.example` (Node was missing example — fix that).
- **Secrets:** never commit client_id/secret.

### Module map (from Node routes → PHP)

| Feature | Node today | PHP target |
|---------|------------|------------|
| Auth / session / home | Working | Port first |
| Membership / group numbers dashboard | Working (`/membership-dashboard`, capacities, cutoffs) | Port + drop debug JSON dump; kill hard-coded “4th Ashby” fallbacks |
| Members list / duplicates / YL checks | Working (`/members`) | Port; fix flatMembers scoping bug if still present |
| Waiting list pull + score | Working pull; hard-coded fields/score | Port pull; make custom-field map configurable |
| Waiting list push → custom fields | **Missing** | New: write score/rank/notes via OSM customdata API |
| Equipment list | Working read | Port |
| Equipment add/edit POST | **Stubs** (redirect only) | Implement quartermaster write APIs; edit links from list rows |
| Bank transfers | Working | Port as-is |
| Settings (capacity/cutoffs) | Working | Port to DB or JSON file under site private storage |
| Badge spreadsheet ingest | **Absent** | New module (after CRUD/waiting push) |

## Phased delivery

### Phase 0 — Platform
SSL fix + empty PHP hello behind same hostname + Composer skeleton + OAuth login round-trip.

### Phase 1 — Read parity (group dashboard)
Port: auth, sections, membership dashboard, members, bank transfers, settings, equipment **list**. Geoffrey smoke on live HTTPS.

### Phase 2 — Writes
Equipment add/update (real OSM POSTs); waiting-list **push** to custom fields; configurable field map UI.

### Phase 3 — Badge spreadsheet ingest
- Upload CSV/XLSX → map columns → dry-run → commit badge records via OSM badge APIs.
- Section/term picker, rate-limit aware batches, audit log.
- **Belongs here** (leader OAuth tooling), **not** in `osm-badge-supplier` (Woo purchase cart).

### Phase 4 — Backlog (README futures)
Duplicate polish, challenge badge printout, top-awards calc, section overview — only after P1–P3 stable.

## Out of scope (initially)
- Multi-tenant SaaS
- Permanent storage of scout PII beyond session/cache
- Changing OSM Badge Supplier Woo plugin

## Success criteria (Geoffrey)
- [ ] `https://osmhelper.co.uk` no longer 526
- [ ] Plan approved (this doc)
- [ ] After build phases: feature parity checklist signed off against Node behaviour for P1; P2/P3 demos with dry-run evidence


## Notes from live Node README (jonbloor/OSMHelper)

- Privacy: per-user OAuth; minimal stored data.
- Rate limits highlighted; caching (Node README: browser ~1h — PHP should use short server-side cache + client UX).
- Settings: age cutoffs, per-section capacities, include-in-dashboard flags.
- README “Future plans” still list duplicates / equipment add-edit / waiting-list scoring / challenge printout / section overview / top-awards — treat as backlog after P1–P3.
- Badge spreadsheet ingest is **Jon’s NEW roadmap item** (not in GitHub README yet); keep in OSMHelper, not Woo plugin.

## Hold
Heavy PHP feature build waits for Geoffrey PASS on this plan + Bungle confirm to Jon.
