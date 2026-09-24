#!/usr/bin/env bash
set -euo pipefail

fixture=$(mktemp)
trap 'rm -f "$fixture"' EXIT
cat >"$fixture" <<'JSON'
{
  "go": {"data": [{"id": "glm-5.3"}, {"id": "glm-5.3"}, {"endpoint_family": "chat"}]},
  "zen": {"data": [{"id": "minimax-m3"}]}
}
JSON
set +e
output=$(php .github/scripts/check-catalog-drift.php --fixture="$fixture" --markdown 2>&1)
code=$?
set -e
[ "$code" -eq 2 ]
printf '%s' "$output" | grep -q 'input_invalid'
printf '%s' "$output" | grep -q 'verification_required'
echo 'catalog drift malformed-input contract passed'
