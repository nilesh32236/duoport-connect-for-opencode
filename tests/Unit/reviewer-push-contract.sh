#!/usr/bin/env bash
# Behavioral contract for exact existing/empty reviewer branch leases.

set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
HELPER="$ROOT/.github/scripts/push-reviewer-branch.sh"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
REAL_GIT=$(command -v git)
REMOTE="$TMP/remote.git"
WORKTREE="$TMP/work"
FAKEBIN="$TMP/bin"
BRANCH="automation/opencode-ai-reviewer"

mkdir -p "$WORKTREE" "$FAKEBIN"
git init --bare -q "$REMOTE"
git init -q "$WORKTREE"
git -C "$WORKTREE" config user.name contract
git -C "$WORKTREE" config user.email contract@example.invalid
printf '%s\n' base >"$WORKTREE/file.txt"
git -C "$WORKTREE" add file.txt
git -C "$WORKTREE" commit -qm base
git -C "$WORKTREE" remote add origin "$REMOTE"
git -C "$WORKTREE" push -q origin HEAD:refs/heads/main

# The wrapper races the remote ref after ls-remote and before the helper's push.
cat >"$FAKEBIN/git" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == "push" && ! -e "${RACE_MARKER:?}" ]]; then
  touch "$RACE_MARKER"
  "$REAL_GIT" --git-dir="$RACE_REMOTE" update-ref "${RACE_REF}" "$RACE_SHA"
fi
exec "$REAL_GIT" "$@"
EOF
chmod +x "$FAKEBIN/git"

# Use a real competing commit so the object is reachable to the bare remote.
printf '%s\n' competitor >"$WORKTREE/competitor.txt"
git -C "$WORKTREE" add competitor.txt
git -C "$WORKTREE" commit -qm competitor
COMPETING_SHA=$(git -C "$WORKTREE" rev-parse HEAD)
git -C "$WORKTREE" push -q origin HEAD:refs/heads/competitor
printf '%s\n' local >"$WORKTREE/local.txt"
git -C "$WORKTREE" add local.txt
git -C "$WORKTREE" commit -qm local

# Empty-ref race: the branch is absent, then appears after the helper's lease read.
git -C "$WORKTREE" push -q origin :refs/heads/$BRANCH || true
export PATH="$FAKEBIN:$PATH" REAL_GIT RACE_REMOTE="$REMOTE" RACE_REF="refs/heads/$BRANCH" RACE_SHA="$COMPETING_SHA" RACE_MARKER="$TMP/raced"
if (cd "$WORKTREE" && "$HELPER" origin "$BRANCH" HEAD >/dev/null 2>&1); then
  echo "empty lease unexpectedly accepted a concurrent branch" >&2
  exit 1
fi
[ "$(git --git-dir="$REMOTE" rev-parse "refs/heads/$BRANCH")" = "$COMPETING_SHA" ]

# Reuse the now-existing branch and verify the normal lease accepts a clean push.
rm -f "$RACE_MARKER"
(cd "$WORKTREE" && "$HELPER" origin "$BRANCH" HEAD)
[ "$(git --git-dir="$REMOTE" rev-parse "refs/heads/$BRANCH")" = "$(git -C "$WORKTREE" rev-parse HEAD)" ]

# Existing-ref race: mutate the remote after the helper reads its old SHA.
printf '%s\n' second >"$WORKTREE/second.txt"
git -C "$WORKTREE" add second.txt
git -C "$WORKTREE" commit -qm second
NEW_SHA=$(git -C "$WORKTREE" rev-parse HEAD)
export RACE_MARKER="$TMP/raced-existing"
if (cd "$WORKTREE" && "$HELPER" origin "$BRANCH" HEAD >/dev/null 2>&1); then
  echo "existing lease unexpectedly accepted a concurrent ref mutation" >&2
  exit 1
fi
[ "$(git --git-dir="$REMOTE" rev-parse "refs/heads/$BRANCH")" = "$COMPETING_SHA" ]
[ "$NEW_SHA" != "$COMPETING_SHA" ]

echo "reviewer branch lease contract passed"
