#!/usr/bin/env bash
# List same-repository non-automation pull requests for the campaign invariant.
#
# Usage: reviewer-pr-guard.sh <repository> <owner>
# A non-zero status means the GitHub API query failed; callers must fail closed.

set -euo pipefail

REPOSITORY="${1:?repository is required}"
OWNER="${2:?repository owner is required}"
GH_BIN="${GH_BIN:-gh}"
AUTOMATION_BRANCH="automation/opencode-ai-reviewer"

if ! PR_LIST=$("$GH_BIN" pr list -R "$REPOSITORY" --state open --json headRefName,headRepositoryOwner --jq '.[] | [.headRefName, (.headRepositoryOwner.login // "")] | @tsv'); then
  echo "Unable to inspect open pull requests; refusing to update dependencies." >&2
  exit 1
fi

# Only a same-repository PR is exempt. A fork using the automation branch name
# is not trusted to represent this repository's dependency PR.
printf '%s\n' "$PR_LIST" | awk -F '\t' -v owner="$OWNER" '$2 == owner && $1 != "'"$AUTOMATION_BRANCH"'" { print $1 }'
