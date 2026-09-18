<?php
/**
 * Compares the live OpenCode model catalogs against ModelAllowlist.php.
 *
 * Usage: php check-catalog-drift.php [--markdown]
 * Exit 0 = no drift (or API unreachable — prints a warning, stays green so
 * a short API outage doesn't file issues). Exit 2 = drift detected.
 * With --markdown, prints a report body suitable for a GitHub issue.
 */

declare(strict_types=1);

$catalogs = array(
	'go'  => 'https://opencode.ai/zen/go/v1/models',
	'zen' => 'https://opencode.ai/zen/v1/models',
);

function fetch_ids( string $url ): ?array {
	$ctx = stream_context_create(
		array(
			'http' => array(
				'timeout' => 25,
				'header'  => "User-Agent: duoport-connect-for-opencode drift-check\r\n",
			),
		)
	);
	$raw = @file_get_contents( $url, false, $ctx );
	if ( false === $raw ) {
		return null;
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
		return null;
	}
	$ids = array();
	foreach ( $data['data'] as $row ) {
		if ( ! empty( $row['id'] ) && is_string( $row['id'] ) ) {
			$ids[] = $row['id'];
		}
	}
	sort( $ids );
	return $ids;
}

function allowlist_ids( string $file, string $catalog ): array {
	$src = file_get_contents( $file );
	if ( false === $src ) {
		fwrite( STDERR, "Cannot read $file\n" );
		exit( 3 );
	}
	// Isolate the catalog's array block inside the ALLOW const.
	if ( ! preg_match( "/'" . $catalog . "'\s*=>\s*array\((.*?)\)\s*,/s", $src, $m ) ) {
		fwrite( STDERR, "Catalog '$catalog' block not found in allowlist\n" );
		exit( 3 );
	}
	preg_match_all( "/'([a-z0-9][a-z0-9._-]*)'/i", $m[1], $mm );
	$ids = array_unique( $mm[1] );
	sort( $ids );
	return $ids;
}

$allow_file = dirname( __DIR__, 2 ) . '/src/Metadata/ModelAllowlist.php';
$markdown   = in_array( '--markdown', $argv, true );
$drift      = array();
$unreachable = array();

foreach ( $catalogs as $catalog => $url ) {
	$live = fetch_ids( $url );
	if ( null === $live ) {
		$unreachable[] = $catalog;
		continue;
	}
	$allowed = allowlist_ids( $allow_file, $catalog );
	$drift[ $catalog ] = array(
		// Allowlisted but gone from the API (stale entries).
		'removed' => array_values( array_diff( $allowed, $live ) ),
		// In the API but not allowlisted (candidates to add).
		'added'   => array_values( array_diff( $live, $allowed ) ),
	);
}

$has_drift = false;
foreach ( $drift as $d ) {
	if ( $d['removed'] || $d['added'] ) {
		$has_drift = true;
		break;
	}
}

if ( $markdown ) {
	echo "## OpenCode catalog drift — " . gmdate( 'Y-m-d' ) . "\n\n";
	echo "Live `/models` catalogs differ from `src/Metadata/ModelAllowlist.php`.\n\n";
	foreach ( $drift as $catalog => $d ) {
		echo "### `$catalog`\n\n";
		echo "Removed from API (stale allowlist entries): " . ( $d['removed'] ? '`' . implode( '`, `', $d['removed'] ) . '`' : '_none_' ) . "\n\n";
		echo "New in API (not allowlisted): " . ( $d['added'] ? '`' . implode( '`, `', $d['added'] ) . '`' : '_none_' ) . "\n\n";
	}
	if ( $unreachable ) {
		echo '_Note: could not reach the API for: ' . implode( ', ', $unreachable ) . " — those catalogs were skipped._\n\n";
	}
	echo "Suggested action: verify each model supports chat/completions, update the allowlist + readme model counts, add a changelog entry, tag a release.\n";
	exit( $has_drift ? 2 : 0 );
}

foreach ( $drift as $catalog => $d ) {
	echo strtoupper( $catalog ) . ': removed=[' . implode( ',', $d['removed'] ) . '] added=[' . implode( ',', $d['added'] ) . "]\n";
}
foreach ( $unreachable as $catalog ) {
	echo strtoupper( $catalog ) . ": API unreachable, skipped\n";
}
exit( $has_drift ? 2 : 0 );
