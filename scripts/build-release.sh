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

  # Reject path-traversal entries before unzip writes anything, so a
  # malicious or malformed archive cannot escape the extraction directory.
  if grep -Eq '(^|/)\.\.(/|$)|^/' "$zip_list"; then
    echo "ERROR: ZIP contains a path-traversal or absolute entry" >&2
    return 1
  fi

  # Reject symlink entries. A published plugin needs none, and a stored
  # symlink is how a ZIP-based install escapes the plugin directory or
  # re-points a file at content from outside the archive. zipinfo marks
  # them with a leading "l" in the permissions column; when zipinfo is
  # unavailable this degrades to the staged-tree check in build_release.
  if command -v zipinfo >/dev/null 2>&1; then
    if zipinfo "$zip_path" 2>/dev/null | grep -q '^l'; then
      echo "ERROR: ZIP contains a symlink entry" >&2
      zipinfo "$zip_path" 2>/dev/null | grep '^l' | sed 's/^/  /' >&2
      return 1
    fi
  fi

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
  # ZIP listing entries for directories end in "/", so a bare "\.jsonl$" would
  # miss a directory named like an artifact. Allow an optional trailing slash.
  if grep -Eqi '\.jsonl/?$' "$zip_list"; then
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
  #
  # grep exits 0 on a match, 1 on no match, and 2 on an I/O error. Treating 2
  # as "no match" would let an unreadable file pass silently, so the status is
  # captured explicitly and anything other than a clean 1 is a rejection.
  #
  # The marker patterns tolerate CRLF: a checkout with core.autocrlf can leave
  # the separator as "=======\r", which an end-anchored pattern would miss.
  # -I skips binary payloads, where a marker match is not merge debris.
  local marker_status=0 marker_found=0
  grep -rIq -e '^<<<<<<< ' -e '^>>>>>>> ' -e '^=======[[:space:]]*$' "$extracted" 2>/dev/null || marker_status=$?
  if [ "$marker_status" -eq 0 ]; then
    marker_found=1
  elif [ "$marker_status" -ne 1 ]; then
    echo "ERROR: could not scan the archive contents (grep exit ${marker_status})" >&2
    rm -rf "$extracted"
    return 1
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

  # Reject symlinks in the staged tree BEFORE zipping.
  #
  # `rsync -a` preserves symlinks, and `zip -r` (without -y) dereferences
  # them: it writes the *contents of the link target* into the archive. A
  # symlink committed anywhere in the plugin tree would therefore silently
  # copy its target into a public WordPress.org release. A published plugin
  # has no legitimate use for symlinks, so refuse to build one.
  local staged_symlinks
  staged_symlinks="$(find "${BUILD_DIR}/${PLUGIN_SLUG}" -type l -print 2>/dev/null)"
  if [ -n "$staged_symlinks" ]; then
    echo "ERROR: staged release tree contains symlinks, which zip would dereference:" >&2
    printf '%s\n' "$staged_symlinks" | sed 's/^/  /' >&2
    rm -rf "$BUILD_DIR"
    return 1
  fi

  echo "==> Creating release ZIP: ${zip_name}..."
  rm -f "$zip_path"
  ( cd "$BUILD_DIR" && zip -qry "$zip_path" "$PLUGIN_SLUG" )
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
