#!/usr/bin/env bash
# Push the reviewer updater branch with an exact existing-ref lease or an
# atomic, non-forced create when the ref is absent.

set -euo pipefail

REMOTE="${1:?remote is required}"
BRANCH="${2:?branch is required}"
HEAD_REF="${3:?head ref is required}"
REF="refs/heads/${BRANCH}"

if [[ ! "$BRANCH" =~ ^[A-Za-z0-9._/-]+$ ]] || [[ "$BRANCH" == /* || "$BRANCH" == *..* ]]; then
  echo "Invalid reviewer branch name: ${BRANCH}" >&2
  exit 1
fi

EXISTING_SHA=$(git ls-remote "$REMOTE" "$REF" | awk '{print $1}')
if [ -n "$EXISTING_SHA" ]; then
  git push --force-with-lease="${REF}:${EXISTING_SHA}" "$REMOTE" "${HEAD_REF}:${BRANCH}"
else
  # A normal atomic create succeeds only when the ref is absent or is a
  # fast-forward. It never force-overwrites a ref created after ls-remote.
  git push --atomic "$REMOTE" "${HEAD_REF}:${BRANCH}"
fi
