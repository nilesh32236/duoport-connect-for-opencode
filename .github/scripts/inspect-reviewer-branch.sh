#!/usr/bin/env bash
# Inspect the reviewer dependency branch without rewriting it on errors.
#
# Output states: absent, stale, ensure_pr, or has_pr:<number>.
# Any Git object or GitHub API inspection failure exits non-zero.

set -euo pipefail

TAG="${1:?release tag is required}"
COMMIT="${2:?release commit is required}"
BRANCH="${3:?branch is required}"
REPOSITORY="${4:?repository is required}"
OWNER="${5:?repository owner is required}"
GH_BIN="${GH_BIN:-gh}"
REF="refs/heads/${BRANCH}"

if ! REMOTE_OUTPUT=$(git ls-remote origin "$REF"); then
  echo "Unable to inspect reviewer branch ref; refusing to continue." >&2
  exit 1
fi
EXISTING_SHA=$(printf '%s\n' "$REMOTE_OUTPUT" | awk 'NF { print $1; exit }')
if [ -z "$EXISTING_SHA" ]; then
  echo absent
  exit 0
fi
if [[ ! "$EXISTING_SHA" =~ ^[a-f0-9]{40}$ ]]; then
  echo "Invalid reviewer branch SHA; refusing to continue." >&2
  exit 1
fi

if ! git fetch origin "$BRANCH"; then
  echo "Unable to fetch reviewer dependency branch; refusing to continue." >&2
  exit 1
fi
EXISTING_SHA=$(git rev-parse FETCH_HEAD)
if ! MANIFEST=$(git show "${EXISTING_SHA}:.github/reviewer-dependency.json" 2>/dev/null); then
  echo "Unable to inspect existing dependency manifest; refusing to continue." >&2
  exit 1
fi
if ! jq -e 'type == "object"' >/dev/null <<<"$MANIFEST"; then
  echo "Existing dependency manifest is invalid JSON; refusing to continue." >&2
  exit 1
fi
if ! jq -e --arg tag "$TAG" --arg commit "$COMMIT" '.release_tag == $tag and .release_commit == $commit' >/dev/null <<<"$MANIFEST"; then
  echo stale
  exit 0
fi

set +e
REF_OUTPUT=$(git grep -h -F -e "uses: nilesh32236/opencode-ai-reviewer@${COMMIT} # ${TAG}" "$EXISTING_SHA" -- '.github/workflows/*.yml' '.github/workflows/*.yaml')
GREP_STATUS=$?
set -e
if [ "$GREP_STATUS" -gt 1 ]; then
  echo "Unable to inspect existing workflow references; refusing to continue." >&2
  exit 1
fi
REF_COUNT=$(printf '%s\n' "$REF_OUTPUT" | awk 'NF { count++ } END { print count + 0 }')
if [ "$REF_COUNT" -lt 4 ]; then
  echo stale
  exit 0
fi

if ! PR_TSV=$("$GH_BIN" api --paginate "repos/${REPOSITORY}/pulls?state=open&per_page=100" --jq '.[] | [.head.ref, (.head.repo.owner.login // ""), .number] | @tsv'); then
  echo "Unable to inspect existing dependency PR; refusing to continue." >&2
  exit 1
fi
PR_NUMBER=$(printf '%s\n' "$PR_TSV" | awk -F '\t' -v branch="$BRANCH" -v owner="$OWNER" '$1 == branch && $2 == owner { print $3 }')
if [ -n "$PR_NUMBER" ]; then
  echo "has_pr:${PR_NUMBER}"
else
  echo ensure_pr
fi
