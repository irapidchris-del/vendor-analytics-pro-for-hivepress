#!/usr/bin/env bash
#
# Builds the distributable plugin zips.
#
# Produces (in ./dist):
#   vendor-analytics-pro-for-hivepress.zip            <- clean; attach THIS to the
#                                                        GitHub release as the asset
#                                                        (also what the updater fetches)
#   vendor-analytics-pro-for-hivepress-<version>.zip  <- identical contents, version in
#                                                        the file name for local tracking
#
# BOTH zips contain a single top-level folder named exactly
# "vendor-analytics-pro-for-hivepress/", so WordPress always installs/updates
# the plugin into the correct folder with no "destination folder already
# exists" or version-mismatch warnings, regardless of the zip file's name.
#
# Usage: ./build.sh
#
set -euo pipefail

SLUG="vendor-analytics-pro-for-hivepress"
MAIN="hivepress-vendor-analytics.php"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

# The plugin header is the single source of truth for the version.
VERSION="$(grep -m1 -oiE '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[0-9][0-9.]*' "$MAIN" | grep -oE '[0-9][0-9.]*' || true)"
if [ -z "$VERSION" ]; then
	echo "ERROR: could not read the Version header from $MAIN" >&2
	exit 1
fi

# Guard against version drift: the header, the HPVA_VERSION constant and the
# readme "Stable tag" must all agree, or WordPress/the updater will misbehave.
CONST_VER="$(grep -oE "HPVA_VERSION', '[0-9][0-9.]*'" "$MAIN" | grep -oE '[0-9][0-9.]*' || true)"
README_VER="$(grep -m1 -oiE 'Stable tag:[[:space:]]*[0-9][0-9.]*' readme.txt | grep -oE '[0-9][0-9.]*' || true)"
if [ "$VERSION" != "$CONST_VER" ] || [ "$VERSION" != "$README_VER" ]; then
	echo "ERROR: version mismatch - header=$VERSION, HPVA_VERSION=$CONST_VER, readme=$README_VER" >&2
	echo "       Update all three (and the changelog) before building." >&2
	exit 1
fi

STAGE="$ROOT/build/$SLUG"
rm -rf "$ROOT/build" "$ROOT/dist"
mkdir -p "$STAGE" "$ROOT/dist"

# Explicit allow-list of everything that ships to users. Anything not listed
# here (build.sh, RELEASING.md, .git, dist/, etc.) is intentionally excluded.
INCLUDE=( "$MAIN" "readme.txt" "uninstall.php" "assets" "includes" "languages" )
for item in "${INCLUDE[@]}"; do
	if [ -e "$item" ]; then
		cp -R "$item" "$STAGE/"
	fi
done

# Belt and braces: never ship VCS metadata or OS cruft that may hide in a
# copied directory.
find "$STAGE" \( -name '.git' -o -name '.DS_Store' -o -name 'Thumbs.db' -o -name '.editorconfig' \) -exec rm -rf {} + 2>/dev/null || true

CLEAN="$ROOT/dist/$SLUG.zip"
( cd "$ROOT/build" && zip -rqX "$CLEAN" "$SLUG" )
cp "$CLEAN" "$ROOT/dist/$SLUG-$VERSION.zip"

echo "Built Vendor Analytics Pro $VERSION"
echo "  dist/$SLUG.zip            (attach this to the GitHub release)"
echo "  dist/$SLUG-$VERSION.zip   (versioned copy, for your own tracking)"
echo
echo "Top-level folder inside the zip:"
unzip -Z1 "$CLEAN" | sed -n '1p' | sed 's/^/  /'
echo
echo "Stable 'always latest' download link (works once the asset is attached):"
echo "  https://github.com/irapidchris-del/vendor-analytics-pro-for-hivepress/releases/latest/download/$SLUG.zip"
