#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SSH_KEY="${SSH_KEY:-/home/box/.ssh/id_ed25519}"
REMOTE="${REMOTE:-bungle@79.72.90.90}"
SSH=(ssh -i "$SSH_KEY" -o IdentitiesOnly=yes -o ConnectTimeout=15 "$REMOTE")

echo "==> Backup current live app + database (outside the app tree)"
# Backups go to /home/osmhelper/backups/deploy/ (bungle:osmhelper, 750), never app/storage/.
# The parent /home/osmhelper/backups is osmhelper-owned 770, so bungle can't chmod it; hence the subdir.
#  - app-predeploy-<TS>.tgz: code only, 640. vendor/, storage/ (settings, database, sessions) and every
#    .env* secrets file (.env, .env.bak*) are left out: the live .env is never touched by a deploy, so a
#    rollback keeps using it, and secrets don't pile up in backup archives.
#  - db-<TS>.sqlite: sqlite3 .backup of storage/osmhelper.sqlite (WAL-safe online copy), 640.
# The sqlite3 step runs under umask 007, not 027: if it has to create osmhelper.sqlite-wal/-shm they must
# stay group-writable (660) or PHP-FPM (group osmhelper) can no longer write. The backup file is then
# chmod 640. Only the newest 10 of each kind are kept. No member data from storage/ is copied
# (Top awards caches and sessions are not backed up). The WordPress form's OSM tokens inside the
# database are encrypted with OSMHELPER_TOKEN_KEY (kept only in .env, never in these backups).
BACKUP_TS=$(date -u +%Y%m%d-%H%M%S)
"${SSH[@]}" "set -e; B=/home/osmhelper/backups/deploy; umask 027; mkdir -p \$B; chgrp osmhelper \$B; chmod 750 \$B
if [ -d /home/osmhelper/app/src ]; then
  tar -C /home/osmhelper -czf \$B/app-predeploy-$BACKUP_TS.tgz --exclude=app/vendor --exclude=app/storage --exclude=app/.env --exclude='app/.env.bak*' --exclude='app/.env.*.bak' app || exit 1
  echo \"backup: \$B/app-predeploy-$BACKUP_TS.tgz\"
fi
if [ -f /home/osmhelper/app/storage/osmhelper.sqlite ]; then
  (umask 007; sqlite3 /home/osmhelper/app/storage/osmhelper.sqlite \".backup '\$B/db-$BACKUP_TS.sqlite'\")
  chmod 640 \$B/db-$BACKUP_TS.sqlite; echo \"backup: \$B/db-$BACKUP_TS.sqlite\"
fi
cd \$B; for k in 'app-predeploy-*.tgz' 'db-*.sqlite'; do ls -1t \$k 2>/dev/null | tail -n +11 | xargs -r rm -f --; done"

echo "==> OSMHELPER_TOKEN_KEY in the live .env (before the new code goes in)"
# The new code refuses to save WordPress form tokens in production without a key, so the key must be in
# .env before the code is unpacked. Uses the live app's existing vendor/ (Dotenv); skipped on a first
# install (no .env or vendor yet) and run again after composer install below. Only appends a key when
# no definition exists; exits 1 (stopping the deploy) if one exists but is empty/invalid. Never prints it.
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$ROOT/deploy/ensure-token-key.php" "${REMOTE}:/home/osmhelper/tmp/ensure-token-key.php"
"${SSH[@]}" 'cd /home/osmhelper/app 2>/dev/null || exit 0; rc=0; if [ -f .env ] && [ -f vendor/autoload.php ]; then php /home/osmhelper/tmp/ensure-token-key.php || rc=$?; fi; rm -f /home/osmhelper/tmp/ensure-token-key.php; exit $rc'

echo "==> Pack + upload app"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/app"
# Repo root is the app (older layouts had it under app/). Never ship .env or storage/ at all:
# the server .env (secrets, WAITING_RANK_TEST_MEMBER etc.) and storage/ (settings, osmhelper.sqlite) must survive.
# storage/ itself is excluded too (not just its contents): unpacking a storage/ dir entry would reset the
# live dir from 2770 to 2755. The storage step below creates it if missing.
# review/ (local fake-OSM review harness), tests/ and fixtures/ (fake data for tests) are never shipped.
APP_SRC="$ROOT"; [ -d "$ROOT/app" ] && APP_SRC="$ROOT/app"
tar -C "$APP_SRC" --exclude=./.env --exclude=.env --exclude=./vendor --exclude=./.git --exclude=./storage --exclude=./review --exclude=./tests --exclude=./fixtures -cf - . | tar -C "$TMP/app" -xf -
tar -C "$TMP" -czf "$TMP/app.tgz" app
"${SSH[@]}" 'mkdir -p /home/osmhelper/app /home/osmhelper/htdocs/osmhelper.co.uk /home/osmhelper/tmp'
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$TMP/app.tgz" "${REMOTE}:/home/osmhelper/tmp/osmhelper-app.tgz"
"${SSH[@]}" 'umask 002; cd /home/osmhelper && tar -xzf tmp/osmhelper-app.tgz --no-overwrite-dir && rm -f tmp/osmhelper-app.tgz'

echo "==> Composer install"
"${SSH[@]}" 'cd /home/osmhelper/app && composer install --no-dev --optimize-autoloader --no-interaction'

echo "==> htdocs index + route stubs"
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$ROOT/deploy/htdocs-index.php" "${REMOTE}:/home/osmhelper/htdocs/osmhelper.co.uk/index.php"
tar -C "$ROOT/deploy/route-stubs" -czf "$TMP/stubs.tgz" .
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$TMP/stubs.tgz" "${REMOTE}:/home/osmhelper/tmp/osmhelper-stubs.tgz"
"${SSH[@]}" 'tar -xzf /home/osmhelper/tmp/osmhelper-stubs.tgz -C /home/osmhelper/htdocs/osmhelper.co.uk || true; rm -f /home/osmhelper/tmp/osmhelper-stubs.tgz; rm -rf /home/osmhelper/htdocs/osmhelper.co.uk/update-cutoffs /home/osmhelper/htdocs/osmhelper.co.uk/update-sections; cd /home/osmhelper/htdocs/osmhelper.co.uk && ln -sfn /home/osmhelper/app/public/assets assets'
# Remove auth/callback dirs so OSM redirect_uri /callback (no slash) hits index.php.
# Directory stubs caused nginx 301 → http://…/callback/ which dropped the Secure session cookie.
echo "==> Remove auth/callback dirs (OAuth cookie fix)"
"${SSH[@]}" 'rm -rf /home/osmhelper/htdocs/osmhelper.co.uk/auth /home/osmhelper/htdocs/osmhelper.co.uk/callback'

echo "==> .env + SESSION_SECRET"
# Only creates .env when missing; never overwrites it. ensure-session-secret.php only fills an empty
# SESSION_SECRET and keeps every other line (e.g. WAITING_RANK_TEST_MEMBER) as is.
"${SSH[@]}" 'cd /home/osmhelper/app && if [ ! -f .env ]; then cp .env.example .env; fi'
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$ROOT/deploy/ensure-session-secret.php" "${REMOTE}:/home/osmhelper/app/ensure-session-secret.php"
"${SSH[@]}" 'cd /home/osmhelper/app && php ensure-session-secret.php && rm -f ensure-session-secret.php'
# OSMHELPER_TOKEN_KEY again (no-op if the step before the upload set it; covers a first install).
# Only added when no definition exists, never replaced, never printed.
"${SSH[@]}" 'cd /home/osmhelper/app && php deploy/ensure-token-key.php'


echo "==> Permissions (app code only; storage/ is pruned and handled in the next step)"
# storage/ is excluded from the blanket chmod: 640 on osmhelper.sqlite-wal/-shm would briefly drop
# group write and break PHP-FPM (group osmhelper) writes until the storage step below restores it.
"${SSH[@]}" 'chgrp -R osmhelper /home/osmhelper/app; find /home/osmhelper/app -path /home/osmhelper/app/storage -prune -o -type d -exec chmod 750 {} + ; find /home/osmhelper/app -path /home/osmhelper/app/storage -prune -o -type f -exec chmod 640 {} + ; chmod 640 /home/osmhelper/app/.env; chgrp -R osmhelper /home/osmhelper/htdocs/osmhelper.co.uk/auth /home/osmhelper/htdocs/osmhelper.co.uk/callback /home/osmhelper/htdocs/osmhelper.co.uk/dashboard /home/osmhelper/htdocs/osmhelper.co.uk/logout /home/osmhelper/htdocs/osmhelper.co.uk/membership-dashboard /home/osmhelper/htdocs/osmhelper.co.uk/members /home/osmhelper/htdocs/osmhelper.co.uk/member-checks /home/osmhelper/htdocs/osmhelper.co.uk/find-member /home/osmhelper/htdocs/osmhelper.co.uk/find-member/refresh /home/osmhelper/htdocs/osmhelper.co.uk/find-member/search /home/osmhelper/htdocs/osmhelper.co.uk/young-leaders /home/osmhelper/htdocs/osmhelper.co.uk/young-leaders/load /home/osmhelper/htdocs/osmhelper.co.uk/young-leaders/refresh /home/osmhelper/htdocs/osmhelper.co.uk/events /home/osmhelper/htdocs/osmhelper.co.uk/events/refresh /home/osmhelper/htdocs/osmhelper.co.uk/events/csv /home/osmhelper/htdocs/osmhelper.co.uk/nights-away /home/osmhelper/htdocs/osmhelper.co.uk/nights-away/select /home/osmhelper/htdocs/osmhelper.co.uk/top-awards /home/osmhelper/htdocs/osmhelper.co.uk/top-awards/select /home/osmhelper/htdocs/osmhelper.co.uk/top-awards/review /home/osmhelper/htdocs/osmhelper.co.uk/top-awards/apply /home/osmhelper/htdocs/osmhelper.co.uk/roadmap /home/osmhelper/htdocs/osmhelper.co.uk/changelog /home/osmhelper/htdocs/osmhelper.co.uk/help /home/osmhelper/htdocs/osmhelper.co.uk/waiting-list /home/osmhelper/htdocs/osmhelper.co.uk/waiting-list/fields /home/osmhelper/htdocs/osmhelper.co.uk/waiting-list/rank /home/osmhelper/htdocs/osmhelper.co.uk/waiting-list/review /home/osmhelper/htdocs/osmhelper.co.uk/waiting-list/apply /home/osmhelper/htdocs/osmhelper.co.uk/equipment /home/osmhelper/htdocs/osmhelper.co.uk/equipment/move /home/osmhelper/htdocs/osmhelper.co.uk/bank-transfers /home/osmhelper/htdocs/osmhelper.co.uk/settings /home/osmhelper/htdocs/osmhelper.co.uk/settings/update-cutoffs /home/osmhelper/htdocs/osmhelper.co.uk/settings/update-sections /home/osmhelper/htdocs/osmhelper.co.uk/settings/update-tool-sections /home/osmhelper/htdocs/osmhelper.co.uk/settings/update-equipment-locations /home/osmhelper/htdocs/osmhelper.co.uk/settings/update-wordpress-waiting-list /home/osmhelper/htdocs/osmhelper.co.uk/wordpress-form /home/osmhelper/htdocs/osmhelper.co.uk/wordpress-form/download /home/osmhelper/htdocs/osmhelper.co.uk/wordpress-form/save /home/osmhelper/htdocs/osmhelper.co.uk/api /home/osmhelper/htdocs/osmhelper.co.uk/api/waiting-list /home/osmhelper/htdocs/osmhelper.co.uk/api/waiting-list/submit /home/osmhelper/htdocs/osmhelper.co.uk/bank-transfers 2>/dev/null || true'

echo "==> storage writable (after general perms)"
# umask 002 so anything created here (dirs, sqlite sidecar files) stays group-writable for PHP-FPM.
# storage/sessions is created by PHP itself (owner osmhelper, 0700) and is deliberately left alone here.
# So are storage/osm-cache (cached waiting lists, 0700/0600) and storage/osm-rate (OSM allowance backoff, 0700/0600) and storage/yl-limits (Young Leaders limits, 0700/0600).
"${SSH[@]}" 'umask 002; mkdir -p /home/osmhelper/app/storage; chgrp osmhelper /home/osmhelper/app/storage; chmod 2770 /home/osmhelper/app/storage; find /home/osmhelper/app/storage \( -path /home/osmhelper/app/storage/sessions -o -path /home/osmhelper/app/storage/osm-cache -o -path /home/osmhelper/app/storage/osm-rate -o -path /home/osmhelper/app/storage/yl-limits \) -prune -o -exec chmod g+rwX {} + 2>/dev/null || true; for f in /home/osmhelper/app/storage/osmhelper.sqlite /home/osmhelper/app/storage/osmhelper.sqlite-wal /home/osmhelper/app/storage/osmhelper.sqlite-shm; do if [ -f "$f" ]; then chgrp osmhelper "$f" 2>/dev/null || true; chmod 660 "$f" 2>/dev/null || true; fi; done; if [ -f /home/osmhelper/app/storage/settings.json ]; then chgrp osmhelper /home/osmhelper/app/storage/settings.json 2>/dev/null || true; chmod 660 /home/osmhelper/app/storage/settings.json 2>/dev/null || true; fi'

echo "==> Privacy migrations (prints counts only, never tokens or the key)"
# Encrypt any WordPress form tokens still stored as plaintext (needs OSMHELPER_TOKEN_KEY, added above),
# and strip OSM message text / scoutid from older error-log entries. Both are safe to re-run.
# umask 007 so any sqlite sidecar files bungle creates stay group-writable for PHP-FPM.
"${SSH[@]}" 'cd /home/osmhelper/app && (umask 007; php bin/encrypt-site-tokens.php) && (umask 007; php bin/scrub-error-log.php)'

echo "==> Done"
