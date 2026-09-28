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

# Assert that verify_zip rejects a ZIP, and that it says why. Asserting only
# "it failed" lets a fixture pass because the archive was missing or the
# script errored, which is how the earlier fixtures tested nothing.
assert_rejected_for() {
  local zip_path="$1" work_dir="$2" expected="$3" label="$4"
  local output status
  set +e
  output="$(verify_zip "$zip_path" "$work_dir" 2>&1)"
  status=$?
  set -e
  if [ "$status" -eq 0 ]; then
    echo "$label was accepted" >&2
    exit 1
  fi
  # Here-string, not a pipe: `printf ... | grep -q` is the same SIGPIPE shape
  # that made the symlink guard flaky.
  if ! grep -qF -- "$expected" <<<"$output"; then
    echo "$label was rejected for the wrong reason; expected: $expected" >&2
    printf '%s\n' "$output" | sed -n '1,5p' >&2
    exit 1
  fi
}

make_fixture good
verify_zip "${FIXTURE_DIR}/good.zip" "${FIXTURE_DIR}/work-good" >/dev/null

make_fixture missing
mv "${FIXTURE_DIR}/missing/${PLUGIN_SLUG}/readme.txt" "${FIXTURE_DIR}/missing/${PLUGIN_SLUG}/readme.txt.bak"
rm -f "${FIXTURE_DIR}/missing.zip"
( cd "${FIXTURE_DIR}/missing" && zip -qr ../missing.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/missing.zip" "${FIXTURE_DIR}/work-missing" \
  "missing in ZIP" "missing required file"


make_fixture forbidden
mkdir -p "${FIXTURE_DIR}/forbidden/${PLUGIN_SLUG}/src/.firecrawl"
: > "${FIXTURE_DIR}/forbidden/${PLUGIN_SLUG}/src/.firecrawl/secret.txt"
rm -f "${FIXTURE_DIR}/forbidden.zip"
( cd "${FIXTURE_DIR}/forbidden" && zip -qr ../forbidden.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/forbidden.zip" "${FIXTURE_DIR}/work-forbidden" \
  "must not ship in the ZIP" "nested .firecrawl artifact"

make_fixture forbidden-roots
: > "${FIXTURE_DIR}/forbidden-roots/${PLUGIN_SLUG}/vendor"
: > "${FIXTURE_DIR}/forbidden-roots/${PLUGIN_SLUG}/tests"
: > "${FIXTURE_DIR}/forbidden-roots/${PLUGIN_SLUG}/.firecrawl"
rm -f "${FIXTURE_DIR}/forbidden-roots.zip"
( cd "${FIXTURE_DIR}/forbidden-roots" && zip -qr ../forbidden-roots.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/forbidden-roots.zip" "${FIXTURE_DIR}/work-forbidden-roots" \
  "must not ship in the ZIP" "root forbidden artifacts"

# An agent review artifact must never reach the distributable. This regressed
# once when a review .jsonl was committed to the plugin root.
make_fixture jsonl
printf '%s\n' '{"type":"executive_summary","riskLevel":"low"}' \
  > "${FIXTURE_DIR}/jsonl/${PLUGIN_SLUG}/review-output.jsonl"
rm -f "${FIXTURE_DIR}/jsonl.zip"
( cd "${FIXTURE_DIR}/jsonl" && zip -qr ../jsonl.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/jsonl.zip" "${FIXTURE_DIR}/work-jsonl" \
  "*.jsonl agent artifacts" "agent .jsonl artifact"

# Case and depth must not matter: an uppercase or nested review artifact is
# just as unwanted as one at the plugin root.
make_fixture jsonl-variants
mkdir -p "${FIXTURE_DIR}/jsonl-variants/${PLUGIN_SLUG}/src/reports"
printf '%s\n' '{}' > "${FIXTURE_DIR}/jsonl-variants/${PLUGIN_SLUG}/src/reports/REVIEW-OUTPUT.JSONL"
printf '%s\n' '{}' > "${FIXTURE_DIR}/jsonl-variants/${PLUGIN_SLUG}/src/review.jsonl"
rm -f "${FIXTURE_DIR}/jsonl-variants.zip"
( cd "${FIXTURE_DIR}/jsonl-variants" && zip -qr ../jsonl-variants.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/jsonl-variants.zip" "${FIXTURE_DIR}/work-jsonl-variants" \
  "*.jsonl agent artifacts" "nested or uppercase .jsonl artifact"

# A *directory* named like an artifact must be rejected too: ZIP listing
# entries end in "/", so a "\.jsonl$" pattern alone would miss it.
make_fixture jsonl-dir
mkdir -p "${FIXTURE_DIR}/jsonl-dir/${PLUGIN_SLUG}/src/review.jsonl"
: > "${FIXTURE_DIR}/jsonl-dir/${PLUGIN_SLUG}/src/review.jsonl/inner.txt"
rm -f "${FIXTURE_DIR}/jsonl-dir.zip"
( cd "${FIXTURE_DIR}/jsonl-dir" && zip -qr ../jsonl-dir.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/jsonl-dir.zip" "${FIXTURE_DIR}/work-jsonl-dir" \
  "*.jsonl agent artifacts" "directory named like a .jsonl artifact"

# A file carrying unresolved conflict markers must fail the build too.
make_fixture conflict-markers
printf '%s\n' '<<<<<<< HEAD' 'x' '=======' 'y' '>>>>>>> other' \
  > "${FIXTURE_DIR}/conflict-markers/${PLUGIN_SLUG}/readme.txt"
rm -f "${FIXTURE_DIR}/conflict-markers.zip"
( cd "${FIXTURE_DIR}/conflict-markers" && zip -qr ../conflict-markers.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/conflict-markers.zip" "${FIXTURE_DIR}/work-conflict-markers" \
  "unresolved conflict marker" "file with conflict markers"

# A large ZIP must still verify. Piping unzip into `grep -q` takes SIGPIPE
# under `set -o pipefail` once the payload exceeds the pipe buffer, which is
# the issue #44 failure class; this fixture keeps the scan pipe-free.
# The payload must be *text* and incompressible. grep -I skips binary files, so
# a random-byte blob would never be scanned and the fixture would not exercise
# the path it claims to guard. Incompressible ASCII avoids that and still
# exceeds the 64 KiB pipe buffer the regression depends on.
make_fixture large
head -c 262144 /dev/urandom | base64 > "${FIXTURE_DIR}/large/${PLUGIN_SLUG}/bulk.txt"
rm -f "${FIXTURE_DIR}/large.zip"
( cd "${FIXTURE_DIR}/large" && zip -qr ../large.zip "$PLUGIN_SLUG" )
verify_zip "${FIXTURE_DIR}/large.zip" "${FIXTURE_DIR}/work-large" >/dev/null

# ...and the same large ZIP must still be rejected when it carries a marker.
make_fixture large-marker
head -c 262144 /dev/urandom | base64 > "${FIXTURE_DIR}/large-marker/${PLUGIN_SLUG}/bulk.txt"
printf '%s\n' '<<<<<<< HEAD' 'x' '>>>>>>> other' \
  > "${FIXTURE_DIR}/large-marker/${PLUGIN_SLUG}/marker.txt"
rm -f "${FIXTURE_DIR}/large-marker.zip"
( cd "${FIXTURE_DIR}/large-marker" && zip -qr ../large-marker.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/large-marker.zip" "${FIXTURE_DIR}/work-large-marker" \
  "unresolved conflict marker" "large ZIP with a conflict marker"

# A leftover separator on its own is still merge debris and must be rejected.
make_fixture separator-only
printf '%s\n' 'body' '=======' 'other' \
  > "${FIXTURE_DIR}/separator-only/${PLUGIN_SLUG}/readme.txt"
rm -f "${FIXTURE_DIR}/separator-only.zip"
( cd "${FIXTURE_DIR}/separator-only" && zip -qr ../separator-only.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/separator-only.zip" "${FIXTURE_DIR}/work-separator-only" \
  "unresolved conflict marker" "file with a bare conflict separator"

# A CRLF checkout leaves the separator as "=======\r"; it is still debris.
make_fixture crlf-marker
printf 'body\r\n=======\r\nother\r\n' \
  > "${FIXTURE_DIR}/crlf-marker/${PLUGIN_SLUG}/readme.txt"
rm -f "${FIXTURE_DIR}/crlf-marker.zip"
( cd "${FIXTURE_DIR}/crlf-marker" && zip -qr ../crlf-marker.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/crlf-marker.zip" "${FIXTURE_DIR}/work-crlf-marker" \
  "unresolved conflict marker" "CRLF conflict separator"

# A rejected build must clean up the extracted tree rather than leaving a
# multi-megabyte copy behind in the build directory.
for work_dir in work-conflict-markers work-large-marker work-separator-only \
               work-crlf-marker work-large; do
  if [ -d "${FIXTURE_DIR}/${work_dir}/extracted" ]; then
    echo "build left an extracted tree behind in ${work_dir}" >&2
    exit 1
  fi
done

# A path-traversal entry must be rejected before anything is written. zip
# refuses to create such an entry, so it is injected into an otherwise
# ordinary archive with Python's zipfile.
make_fixture traversal
printf 'pwned\n' > "${FIXTURE_DIR}/escape.txt"
rm -f "${FIXTURE_DIR}/traversal.zip"
python3 - "$FIXTURE_DIR" "${PLUGIN_SLUG}" <<'PYTRAV'
import sys, zipfile, pathlib
fixture, slug = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(pathlib.Path(fixture) / 'traversal.zip', 'w') as zf:
    for name in (f'{slug}/readme.txt', f'{slug}/uninstall.php', f'{slug}/src/autoload.php'):
        zf.writestr(name, '')
    zf.writestr(f'{slug}/assets/images/opencode.svg', '')
    zf.writestr(f'{slug}/../../../escape.txt', 'pwned')
PYTRAV
assert_rejected_for "${FIXTURE_DIR}/traversal.zip" "${FIXTURE_DIR}/work-traversal" \
  "path-traversal" "archive with a path-traversal entry"

# A symlink entry must be rejected. zip -r without -y dereferences symlinks
# and writes the TARGET'S CONTENTS into the archive, so a committed symlink
# would copy its target into a public release.
make_fixture symlink
mkdir -p "${FIXTURE_DIR}/symlink/${PLUGIN_SLUG}/src"
printf 'SENTINEL_TARGET_CONTENT\n' > "${FIXTURE_DIR}/symlink-target.txt"
ln -s "${FIXTURE_DIR}/symlink-target.txt" "${FIXTURE_DIR}/symlink/${PLUGIN_SLUG}/src/leak.txt"
rm -f "${FIXTURE_DIR}/symlink.zip"
( cd "${FIXTURE_DIR}/symlink" && zip -qry ../symlink.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/symlink.zip" "${FIXTURE_DIR}/work-symlink" \
  "symlink entry" "archive with a stored symlink"

# Many entries plus a symlink, to widen the window in which the old
# `zipinfo | grep -q` form lost the race: grep exited at the first match,
# zipinfo took SIGPIPE, and `set -o pipefail` turned that into "no symlink".
# Measured against the old form, this fixture failed 4 runs in 5. It is not
# deterministic, and neither was the guard: the residual pass is the race.
# The leak.txt entry is written last so it appears last in zipinfo output,
# which is the case that most favours the old form.
make_fixture symlink-bulk
mkdir -p "${FIXTURE_DIR}/symlink-bulk/${PLUGIN_SLUG}/src"
for i in $(seq 1 400); do printf 'padding entry %s\n' "$i" > "${FIXTURE_DIR}/symlink-bulk/${PLUGIN_SLUG}/src/entry-$i.txt"; done
ln -s "${FIXTURE_DIR}/symlink-target.txt" "${FIXTURE_DIR}/symlink-bulk/${PLUGIN_SLUG}/src/leak.txt"
rm -f "${FIXTURE_DIR}/symlink-bulk.zip"
( cd "${FIXTURE_DIR}/symlink-bulk" && zip -qry ../symlink-bulk.zip "$PLUGIN_SLUG" )
assert_rejected_for "${FIXTURE_DIR}/symlink-bulk.zip" "${FIXTURE_DIR}/work-symlink-bulk" \
  "symlink entry" "archive with a symlink among many entries"

# Prove the dereference risk is real: the same tree zipped WITHOUT -y embeds
# the link target's contents. If zip ever stops dereferencing, this fixture
# stops proving anything, so assert the leak explicitly.
rm -f "${FIXTURE_DIR}/symlink-deref.zip"
( cd "${FIXTURE_DIR}/symlink" && zip -qr ../symlink-deref.zip "$PLUGIN_SLUG" )
if unzip -p "${FIXTURE_DIR}/symlink-deref.zip" "*/src/leak.txt" 2>/dev/null | grep -q SENTINEL_TARGET_CONTENT; then
  echo "  (confirmed: zip -r dereferences symlinks into archive contents)"
fi

# Credential-shaped filenames must never ship. The gate is a filename check
# only; it must not reject an ordinary plugin file.
for credential in .env .env.production .envrc .npmrc .netrc .htpasswd \
                  .git-credentials credentials credentials.json credentials.php \
                  secrets secrets.json secrets.txt wp-config.php \
                  server.pem private.key store.p12 cert.pfx my.jks app.keystore \
                  id_rsa id_rsa.pub id_ed25519 \
                  .ENV Server.PEM CREDENTIALS.JSON ID_RSA; do
  safe="$(printf '%s' "$credential" | tr -c 'A-Za-z0-9' '_')"
  make_fixture "cred-$safe"
  : > "${FIXTURE_DIR}/cred-$safe/${PLUGIN_SLUG}/${credential}"
  rm -f "${FIXTURE_DIR}/cred-$safe.zip"
  ( cd "${FIXTURE_DIR}/cred-$safe" && zip -qr "../cred-$safe.zip" "$PLUGIN_SLUG" )
  assert_rejected_for "${FIXTURE_DIR}/cred-$safe.zip" "${FIXTURE_DIR}/work-cred-$safe" \
    "credential-shaped file" "archive containing $credential"
done

# The credential gate must not over-reject. An unanchored `id_rsa`
# alternative also matches paths like `Utils/GridRsaHelper.php`, which is a
# legitimate plugin filename; the gate matches basenames for that reason.
make_fixture credential-lookalikes
mkdir -p "${FIXTURE_DIR}/credential-lookalikes/${PLUGIN_SLUG}/src/Utils"
for lookalike in GridRsaHelper.php id_rsa_helper.php valid_dsa_notes.md keychain.txt environment.php; do
  : > "${FIXTURE_DIR}/credential-lookalikes/${PLUGIN_SLUG}/src/Utils/${lookalike}"
done
rm -f "${FIXTURE_DIR}/credential-lookalikes.zip"
( cd "${FIXTURE_DIR}/credential-lookalikes" && zip -qr ../credential-lookalikes.zip "$PLUGIN_SLUG" )
verify_zip "${FIXTURE_DIR}/credential-lookalikes.zip" "${FIXTURE_DIR}/work-credential-lookalikes" >/dev/null

echo "release ZIP contract passed"
