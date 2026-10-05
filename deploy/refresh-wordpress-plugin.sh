#!/usr/bin/env bash
# Refresh the WordPress plugin zip that OSM Helper offers on /wordpress-form/ (Download plugin).
#
# Usage (from the OSMHelper repo root):
#   deploy/refresh-wordpress-plugin.sh [PATH_TO_osm-for-wordpress_REPO]
#   ZIP=/path/to/already-built.zip deploy/refresh-wordpress-plugin.sh [PATH_TO_REPO]
#
# Builds downloads/osm-for-wordpress.zip from <repo>/src as a top-level osm-for-wordpress/ folder
# (no node_modules, tests, .git or .DS_Store), or copies ZIP if given, then writes
# downloads/osm-for-wordpress.json (version from the plugin header, commit, branch, build time, size, sha256).
# Then commit both files and run deploy/deploy.sh. The download is served only to signed-in leaders.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_REPO="${1:-/workspace/osm-for-wordpress}"
OUT_DIR="$ROOT/downloads"
OUT_ZIP="$OUT_DIR/osm-for-wordpress.zip"
OUT_JSON="$OUT_DIR/osm-for-wordpress.json"

[ -f "$PLUGIN_REPO/src/osm-for-wordpress.php" ] || { echo "Plugin repo not found at $PLUGIN_REPO (expected src/osm-for-wordpress.php)" >&2; exit 1; }
if [ -n "$(git -C "$PLUGIN_REPO" status --porcelain 2>/dev/null)" ]; then
  echo "Warning: $PLUGIN_REPO has uncommitted changes; the zip will not match a commit." >&2
fi
mkdir -p "$OUT_DIR"

PLUGIN_REPO="$PLUGIN_REPO" OUT_ZIP="$OUT_ZIP" OUT_JSON="$OUT_JSON" SRC_ZIP="${ZIP:-}" python3 - <<'PY'
import hashlib, json, os, re, shutil, subprocess, tempfile, zipfile, datetime
repo = os.environ['PLUGIN_REPO']; out_zip = os.environ['OUT_ZIP']; out_json = os.environ['OUT_JSON']
src_zip = os.environ.get('SRC_ZIP') or ''

def git(*args):
    try:
        return subprocess.check_output(['git', '-C', repo, *args], text=True, stderr=subprocess.DEVNULL).strip()
    except Exception:
        return ''

if src_zip:
    shutil.copyfile(src_zip, out_zip)
else:
    stage = tempfile.mkdtemp()
    try:
        shutil.copytree(os.path.join(repo, 'src'), os.path.join(stage, 'osm-for-wordpress'),
                        ignore=shutil.ignore_patterns('node_modules', '.git', 'tests', '.DS_Store'))
        if os.path.exists(out_zip):
            os.remove(out_zip)
        shutil.make_archive(out_zip[:-4], 'zip', root_dir=stage, base_dir='osm-for-wordpress')
    finally:
        shutil.rmtree(stage, ignore_errors=True)

z = zipfile.ZipFile(out_zip)
names = z.namelist()
assert 'osm-for-wordpress/osm-for-wordpress.php' in names, 'zip must contain osm-for-wordpress/osm-for-wordpress.php'
bad = [n for n in names if 'node_modules' in n or '/.git' in n or '/tests/' in n]
assert not bad, 'zip contains excluded paths: %r' % bad[:5]
header = z.read('osm-for-wordpress/osm-for-wordpress.php').decode('utf-8', 'replace')
m = re.search(r'^\s*\*\s*Version:\s*(\S+)', header, re.M)
data = open(out_zip, 'rb').read()
manifest = {
    'name': 'OSM for WordPress (with OSM Helper waiting-list form)',
    'filename': 'osm-for-wordpress.zip',
    'version': m.group(1) if m else '',
    'commit': git('rev-parse', '--short', 'HEAD'),
    'branch': git('rev-parse', '--abbrev-ref', 'HEAD'),
    'built_at': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
    'size': len(data),
    'sha256': hashlib.sha256(data).hexdigest(),
    'files': len(names),
}
with open(out_json, 'w') as f:
    json.dump(manifest, f, indent=2)
    f.write('\n')
print(json.dumps(manifest, indent=2))
PY
echo "Updated $OUT_ZIP and $OUT_JSON. Commit both, then run deploy/deploy.sh."
