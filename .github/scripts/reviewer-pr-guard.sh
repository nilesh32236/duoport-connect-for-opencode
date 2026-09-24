#!/usr/bin/env bash
# List same-repository non-automation pull requests for the campaign invariant.
#
# Usage: reviewer-pr-guard.sh <repository> <repository-full-name>
# A non-zero status means the GitHub API query failed; callers must fail closed.

set -euo pipefail

REPOSITORY="${1:?repository is required}"
EXPECTED_REPOSITORY="${2:?repository full name is required}"
GH_BIN="${GH_BIN:-gh}"
AUTOMATION_BRANCH="automation/opencode-ai-reviewer"
if [[ "$REPOSITORY" != "$EXPECTED_REPOSITORY" ]]; then
  echo "Repository identity arguments do not match." >&2
  exit 1
fi

if ! PR_LIST=$("$GH_BIN" api --paginate "repos/${REPOSITORY}/pulls?state=open&per_page=100" --jq '.[] | [.head.ref, (.head.repo.full_name // "")] | @tsv'); then
  echo "Unable to inspect open pull requests; refusing to update dependencies." >&2
  exit 1
fi

# Only the exact same-repository automation PR is exempt. Every other open PR,
# including a fork, remains a competing signal and must defer the updater.
printf '%s\n' "$PR_LIST" | awk -F '\t' -v expected="$EXPECTED_REPOSITORY" -v automation="$AUTOMATION_BRANCH" '!($1 == automation && $2 == expected) { print $1 }'
