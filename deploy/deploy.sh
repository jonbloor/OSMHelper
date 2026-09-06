#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SSH_KEY="${SSH_KEY:-/home/box/.ssh/id_ed25519}"
REMOTE="${REMOTE:-bungle@79.72.90.90}"
SSH=(ssh -i "$SSH_KEY" -o IdentitiesOnly=yes -o ConnectTimeout=15 "$REMOTE")

echo "==> Pack + upload app"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/app"
tar -C "$ROOT/app" --exclude=.env --exclude=vendor --exclude=.git -cf - . | tar -C "$TMP/app" -xf -
tar -C "$TMP" -czf "$TMP/app.tgz" app
"${SSH[@]}" 'mkdir -p /home/osmhelper/app /home/osmhelper/htdocs/osmhelper.co.uk /home/osmhelper/tmp'
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$TMP/app.tgz" "${REMOTE}:/home/osmhelper/tmp/osmhelper-app.tgz"
"${SSH[@]}" 'cd /home/osmhelper && tar -xzf tmp/osmhelper-app.tgz && rm -f tmp/osmhelper-app.tgz'

echo "==> Composer install"
"${SSH[@]}" 'cd /home/osmhelper/app && composer install --no-dev --optimize-autoloader --no-interaction'

echo "==> htdocs index + route stubs"
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$ROOT/deploy/htdocs-index.php" "${REMOTE}:/home/osmhelper/htdocs/osmhelper.co.uk/index.php"
tar -C "$ROOT/deploy/route-stubs" -czf "$TMP/stubs.tgz" .
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$TMP/stubs.tgz" "${REMOTE}:/home/osmhelper/tmp/osmhelper-stubs.tgz"
"${SSH[@]}" 'tar -xzf /home/osmhelper/tmp/osmhelper-stubs.tgz -C /home/osmhelper/htdocs/osmhelper.co.uk || true; rm -f /home/osmhelper/tmp/osmhelper-stubs.tgz; rm -rf /home/osmhelper/htdocs/osmhelper.co.uk/update-cutoffs /home/osmhelper/htdocs/osmhelper.co.uk/update-sections; cd /home/osmhelper/htdocs/osmhelper.co.uk && ln -sfn /home/osmhelper/app/public/assets assets'

echo "==> .env + SESSION_SECRET"
"${SSH[@]}" 'cd /home/osmhelper/app && if [ ! -f .env ]; then cp .env.example .env; fi'
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$ROOT/deploy/ensure-session-secret.php" "${REMOTE}:/home/osmhelper/app/ensure-session-secret.php"
"${SSH[@]}" 'cd /home/osmhelper/app && php ensure-session-secret.php && rm -f ensure-session-secret.php'


echo "==> Permissions"
"${SSH[@]}" 'chgrp -R osmhelper /home/osmhelper/app; find /home/osmhelper/app -type d -exec chmod 750 {} \; ; find /home/osmhelper/app -type f -exec chmod 640 {} \; ; chmod 640 /home/osmhelper/app/.env; chgrp -R osmhelper /home/osmhelper/htdocs/osmhelper.co.uk/auth /home/osmhelper/htdocs/osmhelper.co.uk/callback /home/osmhelper/htdocs/osmhelper.co.uk/dashboard /home/osmhelper/htdocs/osmhelper.co.uk/logout /home/osmhelper/htdocs/osmhelper.co.uk/membership-dashboard /home/osmhelper/htdocs/osmhelper.co.uk/members /home/osmhelper/htdocs/osmhelper.co.uk/member-checks /home/osmhelper/htdocs/osmhelper.co.uk/waiting-list /home/osmhelper/htdocs/osmhelper.co.uk/equipment /home/osmhelper/htdocs/osmhelper.co.uk/bank-transfers /home/osmhelper/htdocs/osmhelper.co.uk/settings /home/osmhelper/htdocs/osmhelper.co.uk/settings/update-cutoffs /home/osmhelper/htdocs/osmhelper.co.uk/settings/update-sections /home/osmhelper/htdocs/osmhelper.co.uk/settings/update-tool-sections /home/osmhelper/htdocs/osmhelper.co.uk/bank-transfers 2>/dev/null || true'

echo "==> storage writable (after general perms)"
"${SSH[@]}" 'mkdir -p /home/osmhelper/app/storage; chgrp osmhelper /home/osmhelper/app/storage; chmod 770 /home/osmhelper/app/storage; if [ -f /home/osmhelper/app/storage/settings.json ]; then chgrp osmhelper /home/osmhelper/app/storage/settings.json 2>/dev/null || true; chmod 660 /home/osmhelper/app/storage/settings.json 2>/dev/null || true; fi'

echo "==> Done"
