<?php
/**
 * Compares live OpenCode model catalogs with the reviewed registry.
 *
 * Usage: php check-catalog-drift.php [--json|--markdown]
 * Exit 0 = no drift (or API unreachable — prints a warning, stays green so
 * a short API outage doesn't file issues). Exit 2 = drift detected.
 * With --json, prints the Model Radar coverage report. With --markdown,
 * prints a report body suitable for one aggregated GitHub issue.
 */

declare(strict_types=1);

$repo_root = dirname( __DIR__, 2 );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $repo_root . '/' );
}
require_once $repo_root . '/src/autoload.php';

$catalogs = array(
	'go'  => 'https://opencode.ai/zen/go/v1/models',
	'zen' => 'https://opencode.ai/zen/v1/models',
);

/**
 * Fetch safe discovery rows without credentials or response bodies.
 *
 * @param string $url Catalog URL.
 * @return array<int, array<string, mixed>>|null
 */
function decode_discovery( mixed $data ): ?array {
	if ( ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
		return null;
	}
	$rows = array();
	foreach ( $data['data'] as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || '' === $row['id'] ) {
			$rows[] = array( 'id' => '' );
			continue;
		}
		$safe = array( 'id' => $row['id'] );
		foreach ( array( 'endpoint_family', 'display_name', 'free' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$safe[ $field ] = $row[ $field ];
			}
		}
		if ( array_key_exists( 'capabilities', $row ) ) {
			$safe['capabilities'] = $row['capabilities'];
		}
		$rows[] = $safe;
	}
	return $rows;
}

function fetch_discovery( string $url ): ?array {
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
	return decode_discovery( json_decode( $raw, true ) );
}

$markdown     = in_array( '--markdown', $argv, true );
$json         = in_array( '--json', $argv, true );
$fixture_path = '';
foreach ( $argv as $argument ) {
	if ( str_starts_with( $argument, '--fixture=' ) ) {
		$fixture_path = substr( $argument, strlen( '--fixture=' ) );
	}
}
$watch      = new \OpenCodeConnector\Metadata\CatalogWatch();
$drift           = array();
$live_by_catalog = array();
$unreachable     = array();
$fixture    = null !== $fixture_path && is_file( $fixture_path ) ? json_decode( (string) file_get_contents( $fixture_path ), true ) : null;

foreach ( $catalogs as $catalog => $url ) {
	$live = is_array( $fixture ) ? decode_discovery( $fixture[ $catalog ] ?? null ) : fetch_discovery( $url );
	$live_by_catalog[ $catalog ] = $live;
	if ( null === $live ) {
		$unreachable[] = $catalog;
		continue;
	}
	$drift[ $catalog ] = $watch->compare( $catalog, $live );
}

$has_drift = false;
foreach ( $drift as $results ) {
	foreach ( $results as $result ) {
		if ( 'allowlisted' !== $result['status'] || in_array( 'input_invalid', $result['states'], true ) ) {
			$has_drift = true;
			break 2;
		}
	}
}

$radar       = new \OpenCodeConnector\Metadata\ModelRadar();
$radar_report = $radar->report( $live_by_catalog );
if ( $json ) {
	echo json_encode( $radar_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
	exit( $has_drift ? 2 : 0 );
}
if ( $markdown ) {
	echo $radar->markdown( $radar_report ), "\n";
	exit( $has_drift ? 2 : 0 );
}

foreach ( $drift as $catalog => $results ) {
	foreach ( $results as $result ) {
		echo strtoupper( $catalog ) . ': ' . $result['id'] . '=' . $result['status'] . '[' . implode( ',', $result['states'] ) . "]\n";
	}
}
foreach ( $unreachable as $catalog ) {
	echo strtoupper( $catalog ) . ": API unreachable, skipped\n";
}
exit( $has_drift ? 2 : 0 );
