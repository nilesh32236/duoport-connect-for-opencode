#!/usr/bin/env bash
set -euo pipefail

fixture=$(mktemp)
trap 'rm -f "$fixture"' EXIT
php -r '
define( "ABSPATH", getcwd() . "/" );
require "src/autoload.php";
$rows = array();
foreach ( \OpenCodeConnector\Metadata\ModelRegistry::records( "go" ) as $record ) {
	$rows[] = array( "id" => $record["id"] );
}
$rows[] = array( "id" => "glm-5.3" );
file_put_contents( $argv[1], json_encode( array( "go" => array( "data" => $rows ) ) ) );
' "$fixture"
set +e
output=$(php .github/scripts/check-catalog-drift.php --fixture="$fixture" --markdown 2>&1)
code=$?
set -e
[ "$code" -eq 2 ]
printf '%s' "$output" | grep -q 'input_invalid'
if printf '%s' "$output" | grep -q 'retired'; then
	exit 1
fi
echo 'catalog drift malformed-input contract passed'
