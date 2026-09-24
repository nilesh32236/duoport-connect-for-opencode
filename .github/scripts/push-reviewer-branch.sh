#!/usr/bin/env bash
# Push the reviewer updater branch with an exact existing-or-empty ref lease.

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
  # Git treats an all-zero expected SHA like an unconstrained lease; use a
  # non-existent sentinel so a ref appearing after ls-remote is rejected.
  git push --force-with-lease="${REF}:1111111111111111111111111111111111111111" "$REMOTE" "${HEAD_REF}:${BRANCH}"
fi
