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

  # Agent/review tooling output is development-only. It can carry merged
  # conflict markers or prompt content, and nothing at runtime reads it, so
  # a stray *.jsonl must fail the build even if .distignore is bypassed.
  if grep -Eqi '\.jsonl$' "$zip_list"; then
    echo "ERROR: *.jsonl agent artifacts must not ship in the ZIP" >&2
    return 1
  fi

  # Belt-and-braces: no shipped file may carry unresolved conflict markers,
  # which would mean a botched merge reached the distributable. All three
  # markers are matched, because a resolved-looking conflict can leave only
  # the separator behind.
  #
  # Extract to a file first instead of piping `unzip -p` into grep. Under
  # `set -o pipefail`, grep -q closes the pipe as soon as it matches, so
  # unzip takes SIGPIPE and the script exits 141 for any ZIP larger than the
  # pipe buffer. That is the same failure class as issue #44, and it would
  # fail the build for the wrong reason.
  local extracted="${work_dir}/extracted"
  rm -rf "$extracted"
  mkdir -p "$extracted"
  if ! unzip -q -o "$zip_path" -d "$extracted"; then
    echo "ERROR: ZIP could not be extracted for content verification" >&2
    rm -rf "$extracted"
    return 1
  fi
  # Always clean up the extraction, including on the rejection path, so a
  # rejected build does not leave a multi-megabyte tree behind.
  # grep exits 0 when it finds a marker, 1 when it does not, so the flag is
  # 0 for "clean" and 1 for "marker present"; only a present marker rejects.
  local marker_found=0
  if grep -rIq -e '^<<<<<<< ' -e '^>>>>>>> ' -e '^=======$' "$extracted" 2>/dev/null; then
    marker_found=1
  fi
  rm -rf "$extracted"
  if [ "$marker_found" -ne 0 ]; then
    echo "ERROR: a shipped file contains an unresolved conflict marker" >&2
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
