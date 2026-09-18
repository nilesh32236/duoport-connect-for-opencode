#!/usr/bin/env bash
set -e
set -o pipefail

PLUGIN_SLUG="duoport-connect-for-opencode"
MAIN_FILE="${PLUGIN_SLUG}.php"
VERSION=$(grep "Version:" "$MAIN_FILE" | awk '{print $NF}' | tr -d '\r')
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
BUILD_DIR="/tmp/${PLUGIN_SLUG}-pkg"
ORIG_PWD="$(pwd)"

echo "==> Plugin version: ${VERSION}"

echo "==> Installing production dependencies (dev-only package: nothing ships)..."
composer install --no-dev --optimize-autoloader --no-progress --prefer-dist || true

echo "==> Staging files using .distignore..."
rm -rf "$BUILD_DIR" && mkdir -p "$BUILD_DIR/${PLUGIN_SLUG}"
rsync -a --exclude-from=".distignore" \
  --exclude=".git" \
  --exclude=".github" \
  --exclude="scripts/" \
  ./ "$BUILD_DIR/${PLUGIN_SLUG}/"

echo "==> Creating release ZIP: ${ZIP_NAME}..."
rm -f "${ORIG_PWD}/${ZIP_NAME}"
( cd "$BUILD_DIR" && zip -qr "${ORIG_PWD}/${ZIP_NAME}" "${PLUGIN_SLUG}" )
test -f "${ORIG_PWD}/${ZIP_NAME}" || { echo "ERROR: ZIP not created at ${ORIG_PWD}/${ZIP_NAME}" >&2; exit 1; }

echo "==> Verifying ZIP contents..."
unzip -l "${ORIG_PWD}/${ZIP_NAME}" | head -n 40
for required in "${PLUGIN_SLUG}/${MAIN_FILE}" "${PLUGIN_SLUG}/readme.txt" "${PLUGIN_SLUG}/uninstall.php" "${PLUGIN_SLUG}/src/autoload.php" "${PLUGIN_SLUG}/assets/images/opencode.svg"; do
  unzip -l "${ORIG_PWD}/${ZIP_NAME}" | grep -q "$required" || { echo "ERROR: $required missing in ZIP" >&2; exit 1; }
done
if unzip -l "${ORIG_PWD}/${ZIP_NAME}" | grep -q "${PLUGIN_SLUG}/vendor/"; then
  echo "ERROR: vendor/ must not ship in the ZIP" >&2; exit 1;
fi
if unzip -l "${ORIG_PWD}/${ZIP_NAME}" | grep -q "${PLUGIN_SLUG}/tests/"; then
  echo "ERROR: tests/ must not ship in the ZIP" >&2; exit 1;
fi

echo "==> Cleanup..."
rm -rf "$BUILD_DIR"

echo "==> Done! Release zip created at: ${ORIG_PWD}/${ZIP_NAME}"
