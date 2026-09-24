<?php
/**
 * Compares live OpenCode model catalogs with the reviewed registry.
 *
 * Usage: php check-catalog-drift.php [--markdown]
 * Exit 0 = no drift (or API unreachable — prints a warning, stays green so
 * a short API outage doesn't file issues). Exit 2 = drift detected.
 * With --markdown, prints a report body suitable for a GitHub issue.
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
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
		return null;
	}
	$rows = array();
	foreach ( $data['data'] as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || '' === $row['id'] ) {
			continue;
		}
		$safe = array( 'id' => $row['id'] );
		foreach ( array( 'endpoint_family', 'display_name', 'free' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$safe[ $field ] = $row[ $field ];
			}
		}
		if ( isset( $row['capabilities'] ) && is_array( $row['capabilities'] ) ) {
			$safe['capabilities'] = $row['capabilities'];
		}
		$rows[] = $safe;
	}
	return $rows;
}

$markdown   = in_array( '--markdown', $argv, true );
$watch      = new \OpenCodeConnector\Metadata\CatalogWatch();
$drift      = array();
$unreachable = array();

foreach ( $catalogs as $catalog => $url ) {
	$live = fetch_discovery( $url );
	if ( null === $live ) {
		$unreachable[] = $catalog;
		continue;
	}
	$drift[ $catalog ] = $watch->compare( $catalog, $live );
}

$has_drift = false;
foreach ( $drift as $results ) {
	foreach ( $results as $result ) {
		if ( 'allowlisted' !== $result['status'] ) {
			$has_drift = true;
			break 2;
		}
	}
}

if ( $markdown ) {
	echo "## OpenCode catalog drift — " . gmdate( 'Y-m-d' ) . "\n\n";
	echo "Live `/models` evidence is compared with the reviewed `ModelRegistry`; no discovery ID is promoted automatically.\n\n";
	foreach ( $drift as $catalog => $results ) {
		echo "### `$catalog`\n\n";
		if ( array() === $results ) {
			echo "_No usable rows._\n\n";
			continue;
		}
		foreach ( $results as $result ) {
			$states = implode( ', ', $result['states'] );
			echo '- `' . $result['id'] . '` — **' . $result['status'] . '** (' . $states . ")\n";
		}
		echo "\n";
	}
	if ( $unreachable ) {
		echo '_Note: could not reach the API for: ' . implode( ', ', $unreachable ) . " — those catalogs were skipped._\n\n";
	}
	echo "Suggested action: review the registry record, endpoint family, capabilities, and verification date before any promotion.\n";
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
