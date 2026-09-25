#!/usr/bin/env bash
set -euo pipefail

fixture=$(mktemp)
trap 'rm -f "$fixture" "$fixture.json"' EXIT
php -r '
define( "ABSPATH", getcwd() . "/" );
require "src/autoload.php";
file_put_contents( $argv[1], json_encode( array(
	"go" => array( "data" => array(
		array( "id" => "new-free-model", "free" => true ),
		array( "id" => "name-free-model" ),
	) ),
	"zen" => null,
) ) );
' "$fixture"
set +e
output=$(php .github/scripts/check-catalog-drift.php --fixture="$fixture" --json 2>&1)
code=$?
set -e
[ "$code" -eq 2 ]
printf '%s' "$output" | jq -e '.catalogs.go.summary.free_candidates == 2 and .catalogs.zen.unreachable == true' >/dev/null
if printf '%s' "$output" | grep -Eq 'connectors_ai_|Authorization|Bearer'; then
	exit 1
fi
printf '%s' "$output" > "$fixture.json"
php .github/scripts/model-radar-markdown.php < "$fixture.json" | grep -q '# OpenCode Model Radar'
rm -f "$fixture.json"
echo 'model radar JSON and Markdown contract passed'
