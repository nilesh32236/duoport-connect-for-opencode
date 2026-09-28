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
( cd "${FIXTURE_DIR}/forbidden" && zip -qr ../forbidden.zip "$PLUGIN_SLUG" )
if verify_zip "${FIXTURE_DIR}/forbidden.zip" "${FIXTURE_DIR}/work-forbidden" >/dev/null 2>&1; then
  echo "nested .firecrawl artifact was accepted" >&2
  exit 1
fi

make_fixture forbidden-roots
: > "${FIXTURE_DIR}/forbidden-roots/${PLUGIN_SLUG}/vendor"
: > "${FIXTURE_DIR}/forbidden-roots/${PLUGIN_SLUG}/tests"
: > "${FIXTURE_DIR}/forbidden-roots/${PLUGIN_SLUG}/.firecrawl"
rm -f "${FIXTURE_DIR}/forbidden-roots.zip"
( cd "${FIXTURE_DIR}/forbidden-roots" && zip -qr ../forbidden-roots.zip "$PLUGIN_SLUG" )
if verify_zip "${FIXTURE_DIR}/forbidden-roots.zip" "${FIXTURE_DIR}/work-forbidden-roots" >/dev/null 2>&1; then
  echo "root forbidden artifacts were accepted" >&2
  exit 1
fi

# An agent review artifact must never reach the distributable. This regressed
# once when a review .jsonl was committed to the plugin root.
make_fixture jsonl
printf '%s\n' '{"type":"executive_summary","riskLevel":"low"}' \
  > "${FIXTURE_DIR}/jsonl/${PLUGIN_SLUG}/review-output.jsonl"
rm -f "${FIXTURE_DIR}/jsonl.zip"
( cd "${FIXTURE_DIR}/jsonl" && zip -qr ../jsonl.zip "$PLUGIN_SLUG" )
if verify_zip "${FIXTURE_DIR}/jsonl.zip" "${FIXTURE_DIR}/work-jsonl" >/dev/null 2>&1; then
  echo "agent .jsonl artifact was accepted" >&2
  exit 1
fi

# A file carrying unresolved conflict markers must fail the build too.
make_fixture conflict-markers
printf '%s\n' '<<<<<<< HEAD' 'x' '=======' 'y' '>>>>>>> other' \
  > "${FIXTURE_DIR}/conflict-markers/${PLUGIN_SLUG}/readme.txt"
rm -f "${FIXTURE_DIR}/conflict-markers.zip"
( cd "${FIXTURE_DIR}/conflict-markers" && zip -qr ../conflict-markers.zip "$PLUGIN_SLUG" )
if verify_zip "${FIXTURE_DIR}/conflict-markers.zip" "${FIXTURE_DIR}/work-conflict-markers" >/dev/null 2>&1; then
  echo "file with conflict markers was accepted" >&2
  exit 1
fi

# A large ZIP must still verify. Piping unzip into `grep -q` takes SIGPIPE
# under `set -o pipefail` once the payload exceeds the pipe buffer, which is
# the issue #44 failure class; this fixture keeps the scan pipe-free.
# Random bytes are required: an all-zero file compresses to almost nothing and
# would not reach the size this regression depends on.
make_fixture large
dd if=/dev/urandom of="${FIXTURE_DIR}/large/${PLUGIN_SLUG}/bulk.bin" bs=1024 count=8192 >/dev/null 2>&1
rm -f "${FIXTURE_DIR}/large.zip"
( cd "${FIXTURE_DIR}/large" && zip -qr ../large.zip "$PLUGIN_SLUG" )
verify_zip "${FIXTURE_DIR}/large.zip" "${FIXTURE_DIR}/work-large" >/dev/null

# ...and the same large ZIP must still be rejected when it carries a marker.
make_fixture large-marker
dd if=/dev/urandom of="${FIXTURE_DIR}/large-marker/${PLUGIN_SLUG}/bulk.bin" bs=1024 count=8192 >/dev/null 2>&1
printf '%s\n' '<<<<<<< HEAD' 'x' '>>>>>>> other' \
  > "${FIXTURE_DIR}/large-marker/${PLUGIN_SLUG}/marker.txt"
rm -f "${FIXTURE_DIR}/large-marker.zip"
( cd "${FIXTURE_DIR}/large-marker" && zip -qr ../large-marker.zip "$PLUGIN_SLUG" )
if verify_zip "${FIXTURE_DIR}/large-marker.zip" "${FIXTURE_DIR}/work-large-marker" >/dev/null 2>&1; then
  echo "large ZIP with a conflict marker was accepted" >&2
  exit 1
fi

echo "release ZIP contract passed"
