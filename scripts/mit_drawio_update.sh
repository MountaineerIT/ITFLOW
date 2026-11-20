#!/bin/sh
# Mountaineer IT - fetch a draw.io release into mit_drawio/ (the editor functions/mit_diagrams.php opens - served from this server).
#   sh scripts/mit_drawio_update.sh 32.7.4
# Takes the files draw.io's own offline service worker lists (its precache manifest) plus PreConfig.js / PostConfig.js and the LICENSE, from
# the release's tag on GitHub, then leaves out what we don't use: the other languages (we open it in English), PlantUML, OneDrive and the
# service worker itself. Then set MIT_DRAWIO_VERSION in functions/mit_diagrams.php to the same version, run smoke 58-diagrams.spec.js and
# scenario-diagrams.php, and commit mit_drawio/ with the change.
set -eu
VERSION="${1:-}"
[ -n "$VERSION" ] || { echo "Usage: sh scripts/mit_drawio_update.sh <version, e.g. 32.7.4>"; exit 1; }
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="https://raw.githubusercontent.com/jgraph/drawio/v$VERSION/src/main/webapp"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
curl -sSf -o "$TMP/sw.js" "$BASE/service-worker.js"
php -r '$s = file_get_contents($argv[1]); preg_match_all("/\"url\":\"([^\"]+)\"/", $s, $m); echo implode("\n", array_unique($m[1])), "\n";' "$TMP/sw.js" > "$TMP/list.txt"
printf 'js/PreConfig.js\njs/PostConfig.js\n' >> "$TMP/list.txt"
count=0
while read -r f; do
    case "$f" in
        resources/dia_*.txt|js/plantuml/*|js/onedrive/*|service-worker.js|"") continue ;;
        *..*|/*) echo "Skipping odd path $f"; continue ;;
    esac
    mkdir -p "$TMP/webapp/$(dirname "$f")"
    curl -sSf -o "$TMP/webapp/$f" "$BASE/$f"
    count=$((count + 1))
done < "$TMP/list.txt"
curl -sSf -o "$TMP/webapp/LICENSE" "https://raw.githubusercontent.com/jgraph/drawio/v$VERSION/LICENSE"
[ -f "$TMP/webapp/index.html" ] && [ -f "$TMP/webapp/js/app.min.js" ] || { echo "The release is missing index.html / js/app.min.js - nothing changed."; exit 1; }
rm -rf "$ROOT/mit_drawio"
mv "$TMP/webapp" "$ROOT/mit_drawio"
echo "draw.io $VERSION: $count files in mit_drawio/ ($(du -sh "$ROOT/mit_drawio" | cut -f1)). Now set MIT_DRAWIO_VERSION = '$VERSION' in functions/mit_diagrams.php and run the tests."
