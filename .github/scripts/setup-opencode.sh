#!/usr/bin/env bash
# setup-opencode.sh — installs the OpenCode CLI for GitHub Actions runs.
#
# Usage:
#   .github/scripts/setup-opencode.sh
#   opencode run --auto --agent <agent> --model <model> < prompt.txt
#
# Environment:
#   GITHUB_TOKEN    — used for the GitHub release API lookup
#   OPENCODE_VERSION — optional reviewed pin (default: v1.18.31, checksum-verified)

set -euo pipefail

OPENCODE_VERSION="${OPENCODE_VERSION:-v1.18.31}"

if [ "$OPENCODE_VERSION" != "v1.18.31" ]; then
  echo "Error: OpenCode ${OPENCODE_VERSION} is not pinned to a reviewed checksum in this repository" >&2
  exit 1
fi

echo "::group::Setting up OpenCode ${OPENCODE_VERSION}"

ARCH="linux-x64"
EXPECTED_SHA256="e9312be75ed803b7415fc2aeabda1f4fe938912a39673762dc0c38c0e11ebde4"
case "$(uname -m)" in
  aarch64|arm64)
    ARCH="linux-arm64"
    EXPECTED_SHA256="d4e332f46b227448582c0d9fc75f6f826dfe95c9f751bc2011fc4d937a042be6"
    ;;
  x86_64|amd64)
    ARCH="linux-x64"
    ;;
  *)
    echo "Error: Unsupported architecture: $(uname -m)" >&2
    exit 1
    ;;
esac

RELEASE_URL="https://api.github.com/repos/anomalyco/opencode/releases/tags/${OPENCODE_VERSION}"
RELEASE_JSON=$(curl -fsSL -H "Authorization: Bearer ${GITHUB_TOKEN:-}" "$RELEASE_URL" 2>/dev/null || echo '{"message":"API error"}')
if echo "$RELEASE_JSON" | jq -e '.message' >/dev/null 2>&1; then
  echo "Error: GitHub API returned: $(echo "$RELEASE_JSON" | jq -r '.message')" >&2
  exit 1
fi
DOWNLOAD_URL=$(echo "$RELEASE_JSON" | jq -r '.assets[] | select(.name == "opencode-'"${ARCH}"'.tar.gz") | .browser_download_url')

if [ -z "$DOWNLOAD_URL" ] || [ "$DOWNLOAD_URL" = "null" ]; then
  echo "Error: Could not find opencode binary for ${ARCH}" >&2
  exit 1
fi

echo "Downloading from: ${DOWNLOAD_URL}"
curl -fsSL "$DOWNLOAD_URL" -o /tmp/opencode.tar.gz
printf '%s  %s\n' "$EXPECTED_SHA256" /tmp/opencode.tar.gz | sha256sum -c -
sudo tar -xzf /tmp/opencode.tar.gz -C /usr/local/bin/
sudo chmod +x /usr/local/bin/opencode
rm -f /tmp/opencode.tar.gz

opencode --version 2>&1 || true
echo "OpenCode installed at: $(which opencode)"

git config user.name "${GIT_USER_NAME:-duoport-connector[bot]}"
git config user.email "${GIT_USER_EMAIL:-duoport-connector[bot]@users.noreply.github.com}"

mkdir -p .opencode

echo "::endgroup::"
echo "OpenCode setup complete."
