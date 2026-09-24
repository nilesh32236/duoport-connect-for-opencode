#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="duoport-connect-for-opencode"
MAIN_FILE="${PLUGIN_SLUG}.php"
BUILD_DIR="${DUOPORT_BUILD_DIR:-/tmp/${PLUGIN_SLUG}-pkg}"
REQUIRED_ENTRIES=(
  "${PLUGIN_SLUG}/${MAIN_FILE}"
  "${PLUGIN_SLUG}/readme.txt"
  "${PLUGIN_SLUG}/uninstall.php"
  "${PLUGIN_SLUG}/src/autoload.php"
  "${PLUGIN_SLUG}/assets/images/opencode.svg"
)

verify_zip() {
  local zip_path="${1:?ZIP path is required}"
  local work_dir="${2:?work directory is required}"
  local zip_list="${work_dir}/zip-list.txt"
  local required

  mkdir -p "$work_dir"
  unzip -Z1 "$zip_path" > "$zip_list"
  sed -n '1,40p' "$zip_list"

  for required in "${REQUIRED_ENTRIES[@]}"; do
    grep -Fxq -- "$required" "$zip_list" || {
      echo "ERROR: $required missing in ZIP" >&2
      return 1
    }
  done

  if grep -Eq '(^|/)(vendor|tests|\.firecrawl)(/|$)' "$zip_list"; then
    echo "ERROR: vendor/, tests/, or .firecrawl/ must not ship in the ZIP" >&2
    return 1
  fi
}

build_release() {
  local root_dir="${1:-$(pwd)}"
  root_dir="$(cd "$root_dir" && pwd)"
  local main_path="${root_dir}/${MAIN_FILE}"
  local version zip_name zip_path

  for tool in composer rsync zip unzip sed grep; do
    command -v "$tool" >/dev/null 2>&1 || {
      echo "ERROR: required release tool is unavailable: $tool" >&2
      return 1
    }
  done

  version=$(grep "Version:" "$main_path" | awk '{print $NF}' | tr -d '\r')
  zip_name="${PLUGIN_SLUG}-${version}.zip"
  zip_path="${root_dir}/${zip_name}"

  echo "==> Plugin version: ${version}"

  echo "==> Installing production dependencies (dev-only package: nothing ships)..."
  composer --working-dir="$root_dir" install --no-dev --optimize-autoloader --no-progress --prefer-dist

  echo "==> Staging files using .distignore..."
  rm -rf "$BUILD_DIR"
  mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}"
  rsync -a --exclude-from="${root_dir}/.distignore" \
    --exclude=".git" \
    --exclude=".github" \
    --exclude="scripts/" \
    "${root_dir}/" "${BUILD_DIR}/${PLUGIN_SLUG}/"

  echo "==> Creating release ZIP: ${zip_name}..."
  rm -f "$zip_path"
  ( cd "$BUILD_DIR" && zip -qr "$zip_path" "$PLUGIN_SLUG" )
  test -f "$zip_path" || { echo "ERROR: ZIP not created at $zip_path" >&2; return 1; }

  echo "==> Verifying ZIP contents..."
  verify_zip "$zip_path" "$BUILD_DIR"

  echo "==> Cleanup..."
  rm -rf "$BUILD_DIR"

  echo "==> Done! Release zip created at: ${zip_path}"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  build_release "$@"
fi
