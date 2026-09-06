#!/usr/bin/env bash
# Deploy OSMHelper PHP app to CloudPanel VPS.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SSH_KEY="${SSH_KEY:-/home/box/.ssh/id_ed25519}"
REMOTE="${REMOTE:-bungle@79.72.90.90}"
SSH=(ssh -i "$SSH_KEY" -o IdentitiesOnly=yes -o ConnectTimeout=15 "$REMOTE")

echo "==> Pack + upload app (exclude .env vendor)"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/app"
# copy app without vendor/.env
tar -C "$ROOT/app" --exclude=.env --exclude=vendor --exclude=.git -cf - . | tar -C "$TMP/app" -xf -
tar -C "$TMP" -czf "$TMP/app.tgz" app

"${SSH[@]}" 'mkdir -p /home/osmhelper/app /home/osmhelper/htdocs/osmhelper.co.uk /home/osmhelper/tmp'
scp -i "$SSH_KEY" -o IdentitiesOnly=yes "$TMP/app.tgz" "${REMOTE}:/home/osmhelper/tmp/osmhelper-app.tgz"
"${SSH[@]}" 'cd /home/osmhelper && tar -xzf tmp/osmhelper-app.tgz && rm -f tmp/osmhelper-app.tgz'

echo "==> Composer install --no-dev on server"
"${SSH[@]}" 'cd /home/osmhelper/app && composer install --no-dev --optimize-autoloader --no-interaction'

echo "==> Install htdocs index.php + route stubs + assets link"
scp -i "$SSH_KEY" -o IdentitiesOnly=yes \
  "$ROOT/deploy/htdocs-index.php" \
  "${REMOTE}:/home/osmhelper/htdocs/osmhelper.co.uk/index.php"
for route in auth callback dashboard logout; do
  "${SSH[@]}" "mkdir -p /home/osmhelper/htdocs/osmhelper.co.uk/$route"
  scp -i "$SSH_KEY" -o IdentitiesOnly=yes \
    "$ROOT/deploy/route-stubs/$route/index.php" \
    "${REMOTE}:/home/osmhelper/htdocs/osmhelper.co.uk/$route/index.php"
done
"${SSH[@]}" 'cd /home/osmhelper/htdocs/osmhelper.co.uk && if [ ! -e assets ]; then ln -sfn /home/osmhelper/app/public/assets assets; fi'

echo "==> Ensure .env + SESSION_SECRET"
"${SSH[@]}" 'cd /home/osmhelper/app && if [ ! -f .env ]; then cp .env.example .env; fi'
scp -i "$SSH_KEY" -o IdentitiesOnly=yes \
  "$ROOT/deploy/ensure-session-secret.php" \
  "${REMOTE}:/home/osmhelper/app/ensure-session-secret.php"
"${SSH[@]}" 'cd /home/osmhelper/app && php ensure-session-secret.php && rm -f ensure-session-secret.php'

echo "==> Permissions (PHP-FPM runs as osmhelper)"
"${SSH[@]}" 'chgrp -R osmhelper /home/osmhelper/app; find /home/osmhelper/app -type d -exec chmod 750 {} \;; find /home/osmhelper/app -type f -exec chmod 640 {} \;; chmod 640 /home/osmhelper/app/.env; chgrp -R osmhelper /home/osmhelper/htdocs/osmhelper.co.uk/auth /home/osmhelper/htdocs/osmhelper.co.uk/callback /home/osmhelper/htdocs/osmhelper.co.uk/dashboard /home/osmhelper/htdocs/osmhelper.co.uk/logout 2>/dev/null || true'

echo "==> Done. Fill CLIENT_ID/CLIENT_SECRET in /home/osmhelper/app/.env if needed."
