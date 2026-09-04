#!/usr/bin/env bash
#
# Assembles a distributable plugin ZIP, excluding dev dependencies, tests,
# and repository tooling. Run via `composer package`.
#
# Foundation only (M0): this proves the release-build mechanism works: it
# does not publish anything, tag anything, or touch GitHub. Tag-driven
# GitHub release publishing (docs/ARCHITECTURE.md, "Foundation scope") is
# separate, later work.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

SLUG="universal-commerce-bundles"
VERSION="$(php -r '
    $contents = file_get_contents("universal-commerce-bundles.php");
    preg_match("/^\s*\*\s*Version:\s*(.+)$/mi", $contents, $m);
    echo trim($m[1] ?? "0.0.0");
' 2>/dev/null || echo "0.0.0")"

BUILD_DIR="$ROOT_DIR/build"
STAGE_DIR="$BUILD_DIR/$SLUG"
ZIP_PATH="$BUILD_DIR/${SLUG}-${VERSION}.zip"

echo "Building ${SLUG} ${VERSION}..."

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR"

# Production-only autoloader/dependencies. Requires composer on PATH (or to
# be run inside the composer:2 Docker image, as CI does).
composer install --no-dev --optimize-autoloader --no-interaction --quiet

rsync -a \
    --exclude-from="$ROOT_DIR/.distignore" \
    "$ROOT_DIR/" "$STAGE_DIR/"

(cd "$BUILD_DIR" && zip -rq "$ZIP_PATH" "$SLUG")

# Restore dev dependencies for local/CI use after packaging.
composer install --no-interaction --quiet

echo "Built: $ZIP_PATH"
