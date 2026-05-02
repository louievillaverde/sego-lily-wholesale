#!/usr/bin/env bash
# Build a clean plugin zip for a GitHub Release.
#
# The plugin's in-product updater (class-updater.php) requires a .zip asset
# attached to the GitHub Release — without it the updater returns null and
# WordPress shows nothing on the Updates page. This script produces a zip
# whose top-level directory matches the plugin slug so WP can install it.
#
# Usage:
#   bin/build-release.sh             # writes dist/sego-lily-wholesale.zip
#   bin/build-release.sh /tmp/foo    # writes /tmp/foo/sego-lily-wholesale.zip

set -euo pipefail

SLUG="sego-lily-wholesale"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="${1:-$REPO_ROOT/dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$OUT_DIR"
mkdir -p "$STAGE/$SLUG"

# Copy source. Excludes: VCS, CI, build artifacts, internal handoff docs.
rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='.DS_Store' \
  --exclude='HOLLY-TODOS.md' \
  --exclude='dist' \
  --exclude='bin' \
  --exclude='*.zip' \
  "$REPO_ROOT/" "$STAGE/$SLUG/"

# Confirm the plugin header version so the operator can sanity-check.
VERSION="$(grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$STAGE/$SLUG/$SLUG.php" | head -1 | awk -F: '{print $2}' | tr -d ' ')"

OUT_ZIP="$OUT_DIR/$SLUG.zip"
rm -f "$OUT_ZIP"
( cd "$STAGE" && zip -qr "$OUT_ZIP" "$SLUG" )

echo "Built: $OUT_ZIP"
echo "Plugin header version: $VERSION"
echo
echo "Next: attach to the GitHub Release. From repo root:"
echo "  gh release create v$VERSION \"$OUT_ZIP\" --title \"v$VERSION -- short description\" --notes \"...\""
echo "  # or, if the release already exists:"
echo "  gh release upload v$VERSION \"$OUT_ZIP\" --clobber"
