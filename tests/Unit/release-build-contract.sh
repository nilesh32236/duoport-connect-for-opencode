#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=/dev/null
source "${ROOT_DIR}/scripts/build-release.sh"

FIXTURE_DIR="$(mktemp -d)"
trap 'rm -rf "$FIXTURE_DIR"' EXIT

make_fixture() {
  local name="$1"
  local plugin_dir="${FIXTURE_DIR}/${name}/${PLUGIN_SLUG}"
  mkdir -p "${plugin_dir}/src" "${plugin_dir}/assets/images"
  : > "${plugin_dir}/${MAIN_FILE}"
  : > "${plugin_dir}/readme.txt"
  : > "${plugin_dir}/uninstall.php"
  : > "${plugin_dir}/src/autoload.php"
  : > "${plugin_dir}/assets/images/opencode.svg"
  ( cd "${FIXTURE_DIR}/${name}" && zip -qr "../${name}.zip" "$PLUGIN_SLUG" )
}

make_fixture good
verify_zip "${FIXTURE_DIR}/good.zip" "${FIXTURE_DIR}/work-good" >/dev/null

make_fixture missing
mv "${FIXTURE_DIR}/missing/${PLUGIN_SLUG}/readme.txt" "${FIXTURE_DIR}/missing/${PLUGIN_SLUG}/readme.txt.bak"
rm -f "${FIXTURE_DIR}/missing.zip"
( cd "${FIXTURE_DIR}/missing" && zip -qr missing.zip "$PLUGIN_SLUG" )
if verify_zip "${FIXTURE_DIR}/missing.zip" "${FIXTURE_DIR}/work-missing" >/dev/null 2>&1; then
  echo "missing required file was accepted" >&2
  exit 1
fi

make_fixture forbidden
mkdir -p "${FIXTURE_DIR}/forbidden/${PLUGIN_SLUG}/src/.firecrawl"
: > "${FIXTURE_DIR}/forbidden/${PLUGIN_SLUG}/src/.firecrawl/secret.txt"
rm -f "${FIXTURE_DIR}/forbidden.zip"
( cd "${FIXTURE_DIR}/forbidden" && zip -qr forbidden.zip "$PLUGIN_SLUG" )
if verify_zip "${FIXTURE_DIR}/forbidden.zip" "${FIXTURE_DIR}/work-forbidden" >/dev/null 2>&1; then
  echo "nested .firecrawl artifact was accepted" >&2
  exit 1
fi

make_fixture forbidden-roots
: > "${FIXTURE_DIR}/forbidden-roots/${PLUGIN_SLUG}/vendor"
: > "${FIXTURE_DIR}/forbidden-roots/${PLUGIN_SLUG}/tests"
: > "${FIXTURE_DIR}/forbidden-roots/${PLUGIN_SLUG}/.firecrawl"
rm -f "${FIXTURE_DIR}/forbidden-roots.zip"
( cd "${FIXTURE_DIR}/forbidden-roots" && zip -qr forbidden-roots.zip "$PLUGIN_SLUG" )
if verify_zip "${FIXTURE_DIR}/forbidden-roots.zip" "${FIXTURE_DIR}/work-forbidden-roots" >/dev/null 2>&1; then
  echo "root forbidden artifacts were accepted" >&2
  exit 1
fi

echo "release ZIP contract passed"
