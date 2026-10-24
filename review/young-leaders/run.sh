#!/usr/bin/env bash
# Local review of /young-leaders/ against a FAKE OSM. Never contacts OSM.
#   review/young-leaders/run.sh            normal
#   FAKE_OSM_429_AT=206 review/young-leaders/run.sh     fake OSM answers 429 for member 206
#   FAKE_OSM_ERROR_AT=205 review/young-leaders/run.sh   fake OSM answers 500 for member 205
#   FAKE_OSM_LIMIT=300 FAKE_OSM_REMAINING=66 review/young-leaders/run.sh   low allowance (stops at 60 left)
#   REVIEW_YL_LIMITS=unwritable review/young-leaders/run.sh   limits can't be saved (Young Leaders refuses to call OSM)
# Sessions, the OSM cache, OSM rate state and Young Leaders limits all live under $TMP, never storage/.
# Then open http://127.0.0.1:8098/__review/sign-in  (Ctrl+C stops both servers)
set -euo pipefail
cd "$(dirname "$0")/../.."
TMP="${TMPDIR:-/tmp}/osmhelper-yl-review"
if [ "${KEEP:-0}" != "1" ]; then rm -rf "$TMP"; fi
mkdir -p "$TMP"
php -S 127.0.0.1:8099 review/young-leaders/fake-osm.php > "$TMP/fake-osm.log" 2>&1 &
FAKE=$!
trap 'kill $FAKE 2>/dev/null || true' EXIT
echo "Fake OSM on 127.0.0.1:8099 (log: $TMP/fake-osm.log)"
echo "Open http://127.0.0.1:8098/__review/sign-in"
php -S 127.0.0.1:8098 -t public review/young-leaders/app-router.php
