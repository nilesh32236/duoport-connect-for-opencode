#!/usr/bin/env bash
# Behavioral contract for current/absent/stale/error branch inspection.

set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
INSPECT="$ROOT/.github/scripts/inspect-reviewer-branch.sh"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
REMOTE="$TMP/remote.git"
WORKTREE="$TMP/work"
FAKEBIN="$TMP/bin"
BRANCH="automation/opencode-ai-reviewer"
EXPECTED_REPOSITORY="nilesh32236/duoport-connect-for-opencode"
REPO="$EXPECTED_REPOSITORY"

mkdir -p "$WORKTREE" "$FAKEBIN"
git init --bare -q "$REMOTE"
git init -q "$WORKTREE"
git -C "$WORKTREE" config user.name contract
git -C "$WORKTREE" config user.email contract@example.invalid
cp -a "$ROOT/.github" "$WORKTREE/"
git -C "$WORKTREE" add .
git -C "$WORKTREE" commit -qm automation
git -C "$WORKTREE" remote add origin "$REMOTE"
git -C "$WORKTREE" push -q origin HEAD:refs/heads/main
git -C "$WORKTREE" push -q origin HEAD:refs/heads/$BRANCH

cat >"$FAKEBIN/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
case "${GH_MODE:-}" in
  none) exit 0 ;;
  pr) printf '%s\n' 'automation/opencode-ai-reviewer	nilesh32236/duoport-connect-for-opencode	99' ;;
  fail) exit 42 ;;
  *) exit 64 ;;
esac
EOF
chmod +x "$FAKEBIN/gh"
export GH_BIN="$FAKEBIN/gh" GH_MODE=none
TAG=v1.22.0
COMMIT=103082c963f64cb2cf979ae14b729ec41d40866e

CURRENT_SHA=$(git -C "$WORKTREE" rev-parse HEAD)
# Current branch with no same-repository PR is recoverable without a rewrite.
[ "$(cd "$WORKTREE" && "$INSPECT" "$TAG" "$COMMIT" "$BRANCH" "$REPO" "$EXPECTED_REPOSITORY")" = ensure_pr ]
# A same-repository PR is recognized, while a fork is not trusted.
GH_MODE=pr
[ "$(cd "$WORKTREE" && "$INSPECT" "$TAG" "$COMMIT" "$BRANCH" "$REPO" "$EXPECTED_REPOSITORY")" = 'has_pr:99' ]
GH_MODE=fail
if (cd "$WORKTREE" && "$INSPECT" "$TAG" "$COMMIT" "$BRANCH" "$REPO" "$EXPECTED_REPOSITORY" >/dev/null 2>&1); then
  echo "branch inspector accepted a GitHub API failure" >&2
  exit 1
fi
GH_MODE=none

# A syntactically valid but incomplete manifest is an inspection failure.
printf '{}\n' >"$WORKTREE/.github/reviewer-dependency.json"
git -C "$WORKTREE" add .github/reviewer-dependency.json
git -C "$WORKTREE" commit -qm incomplete-manifest
git -C "$WORKTREE" push -q origin HEAD:refs/heads/$BRANCH
if (cd "$WORKTREE" && "$INSPECT" "$TAG" "$COMMIT" "$BRANCH" "$REPO" "$EXPECTED_REPOSITORY" >/dev/null 2>&1); then
  echo "branch inspector accepted an incomplete manifest" >&2
  exit 1
fi
git -C "$WORKTREE" push -q --force origin "$CURRENT_SHA:refs/heads/$BRANCH"
git -C "$WORKTREE" reset -q --hard "$CURRENT_SHA"

# A release mismatch is stale and may be regenerated from main.
git -C "$WORKTREE" checkout -q main
jq '.release_tag = "v0.0.0"' "$WORKTREE/.github/reviewer-dependency.json" >"$WORKTREE/manifest.tmp"
mv "$WORKTREE/manifest.tmp" "$WORKTREE/.github/reviewer-dependency.json"
git -C "$WORKTREE" add .github/reviewer-dependency.json
git -C "$WORKTREE" commit -qm stale
git -C "$WORKTREE" push -q origin HEAD:refs/heads/$BRANCH
[ "$(cd "$WORKTREE" && "$INSPECT" "$TAG" "$COMMIT" "$BRANCH" "$REPO" "$EXPECTED_REPOSITORY")" = stale ]

# Missing manifest is an operational inspection failure, never a rewrite signal.
git -C "$WORKTREE" rm -q .github/reviewer-dependency.json
git -C "$WORKTREE" commit -qm missing-manifest
git -C "$WORKTREE" push -q origin HEAD:refs/heads/$BRANCH
if (cd "$WORKTREE" && "$INSPECT" "$TAG" "$COMMIT" "$BRANCH" "$REPO" "$EXPECTED_REPOSITORY" >/dev/null 2>&1); then
  echo "branch inspector accepted a missing manifest" >&2
  exit 1
fi

echo "reviewer branch inspection contract passed"
