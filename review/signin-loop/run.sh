#!/usr/bin/env bash
# Local review of the sign-in loop guard pages. Never contacts OSM.
#   review/signin-loop/run.sh   then open:
#     http://127.0.0.1:8097/callback?code=x&state=y          retry page (sign-in started elsewhere)
#     http://127.0.0.1:8097/callback?error=access_denied     retry page (cancelled or refused)
#     http://127.0.0.1:8097/auth/?retry=1                    "Continue to OSM" page
# Do NOT press "Continue to OSM" expecting OSM: it points at a placeholder on 127.0.0.1:8099.
set -euo pipefail
cd "$(dirname "$0")/../.."
export SIGNIN_REVIEW_TMP="${TMPDIR:-/tmp}/osmhelper-signin-review"
rm -rf "$SIGNIN_REVIEW_TMP"; mkdir -p "$SIGNIN_REVIEW_TMP"
php -S 127.0.0.1:8097 -t public review/signin-loop/app-router.php
