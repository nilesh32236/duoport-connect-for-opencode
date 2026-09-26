<?php
/**
 * Credential-free catalog freshness and verification comparison.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares discovery evidence with the reviewed registry without promoting it.
 */
final class CatalogWatch {

	/**
	 * Compare a discovery snapshot with the reviewed catalog registry.
	 *
	 * Discovery is evidence only: every output row is non-promotable until a
	 * human updates the registry and its verification date.
	 *
	 * @param string            $catalog   Catalog slug.
	 * @param array<int, mixed> $discovered Raw discovery rows.
	 * @return list<array<string, mixed>>
	 */
	public function compare( string $catalog, array $discovered ): array {
		if ( ! Catalog::isValid( $catalog ) ) {
			return array();
		}

		$baseline = array();
		foreach ( ModelRegistry::records( $catalog ) as $record ) {
			$baseline[ 'id:' . $record['id'] ] = $record;
		}

		$validated     = $this->validateDiscoveryRows( $discovered );
		$current       = $validated['current'];
		$has_malformed = $validated['has_malformed'];
		$saw_valid     = $validated['saw_valid'];
		if ( $has_malformed && array() === $current ) {
			return array( $this->result( '', 'input_invalid', array( 'input_invalid' ), false ) );
		}
		if ( count( $discovered ) > 0 && ! $saw_valid ) {
			return array( $this->result( '', 'input_invalid', array( 'input_invalid' ), false ) );
		}

		$results = $this->diffAgainstBaseline( $baseline, $current, $has_malformed );
		$results = $this->appendRetirements( $results, $baseline, $current, $has_malformed );

		usort(
			$results,
			static function ( array $left, array $right ): int {
				return strcmp( $left['id'], $right['id'] );
			}
		);
		return $results;
	}

	/**
	 * Validate raw discovery rows into a deduplicated snapshot map.
	 *
	 * @since 0.1.6
	 *
	 * @param array<int, mixed> $discovered Raw discovery rows.
	 * @return array{current: array<string, array<string, mixed>>, has_malformed: bool, saw_valid: bool}
	 */
	private function validateDiscoveryRows( array $discovered ): array {
		$current       = array();
		$invalid_ids   = array();
		$saw_valid     = false;
		$has_malformed = false;
		foreach ( $discovered as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || '' === $row['id'] ) {
				$has_malformed = true;
				continue;
			}
			$id = (string) $row['id'];
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id ) ) {
				$has_malformed = true;
				continue;
			}
			if ( ( array_key_exists( 'endpoint_family', $row ) && ! is_string( $row['endpoint_family'] ) )
				|| ( array_key_exists( 'display_name', $row ) && ! is_string( $row['display_name'] ) )
				|| ( array_key_exists( 'free', $row ) && ! is_bool( $row['free'] ) )
				|| ( array_key_exists( 'capabilities', $row ) && ! is_array( $row['capabilities'] ) ) ) {
				$has_malformed = true;
				continue;
			}
			$key = 'id:' . $id;
			if ( isset( $invalid_ids[ $key ] ) ) {
				$has_malformed = true;
				continue;
			}
			if ( isset( $current[ $key ] ) ) {
				$has_malformed       = true;
				$invalid_ids[ $key ] = true;
				unset( $current[ $key ] );
				continue;
			}
			$saw_valid       = true;
			$current[ $key ] = array(
				'id'       => $id,
				'snapshot' => $this->normalize( $row ),
			);
		}
		return array(
			'current'       => $current,
			'has_malformed' => $has_malformed,
			'saw_valid'     => $saw_valid,
		);
	}

	/**
	 * Diff validated discovery snapshots against the reviewed baseline.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, array<string, mixed>> $baseline Baseline records keyed by `id:<id>`.
	 * @param array<string, array<string, mixed>> $current Validated snapshots keyed by `id:<id>`.
	 * @param bool                                $has_malformed Whether malformed rows were seen.
	 * @return list<array<string, mixed>>
	 */
	private function diffAgainstBaseline( array $baseline, array $current, bool $has_malformed ): array {
		$results = array();
		foreach ( $current as $entry ) {
			$id       = $entry['id'];
			$snapshot = $entry['snapshot'];
			$key      = 'id:' . $id;
			$states   = array();
			if ( ! isset( $baseline[ $key ] ) ) {
				$states[] = 'new';
				$states[] = 'verification_required';
				$status   = 'verification_required';
			} else {
				$states[] = 'allowlisted';
				$status   = 'allowlisted';
				$record   = $baseline[ $key ];
				if ( 'needs-adapter' === ( $record['verification_status'] ?? '' ) ) {
					$states[] = 'verification_required';
					$status   = 'verification_required';
				}
				if ( array_key_exists( 'endpoint_family', $snapshot ) && $snapshot['endpoint_family'] !== $record['endpoint_family'] ) {
					$states[] = 'endpoint_changed';
					$states[] = 'verification_required';
					$status   = 'verification_required';
				}
				if ( $this->capabilities_changed( $snapshot, $record ) ) {
					$states[] = 'capability_changed';
					$states[] = 'verification_required';
					$status   = 'verification_required';
				}
				if ( $this->free_changed( $snapshot, $record ) ) {
					$states[] = 'free_changed';
					$states[] = 'verification_required';
					$status   = 'verification_required';
				}
				if ( $this->metadata_changed( $snapshot, $record ) ) {
					$states[] = 'metadata_changed';
					$states[] = 'verification_required';
					$status   = 'verification_required';
				}
			}
			if ( $has_malformed ) {
				$states[] = 'input_invalid';
			}
			$results[] = $this->result( $id, $status, $states, isset( $baseline[ $key ] ) );
		}
		return $results;
	}

	/**
	 * Append retirement rows for baseline IDs missing from discovery.
	 *
	 * Retirements are suppressed when any malformed input was seen so a
	 * corrupt snapshot can never retire the reviewed registry.
	 *
	 * @since 0.1.6
	 *
	 * @param list<array<string, mixed>>          $results Existing results.
	 * @param array<string, array<string, mixed>> $baseline Baseline records keyed by `id:<id>`.
	 * @param array<string, array<string, mixed>> $current Validated snapshots keyed by `id:<id>`.
	 * @param bool                                $has_malformed Whether malformed rows were seen.
	 * @return list<array<string, mixed>>
	 */
	private function appendRetirements( array $results, array $baseline, array $current, bool $has_malformed ): array {
		if ( $has_malformed ) {
			return $results;
		}
		foreach ( $baseline as $entry ) {
			$key = 'id:' . $entry['id'];
			if ( isset( $current[ $key ] ) ) {
				continue;
			}
			$results[] = $this->result(
				$entry['id'],
				'retired',
				array( 'retired', 'verification_required' ),
				true
			);
		}
		return $results;
	}

	/**
	 * Normalize only safe discovery fields.
	 *
	 * @param array<string, mixed> $row Raw discovery row.
	 * @return array<string, mixed>
	 */
	private function normalize( array $row ): array {
		$snapshot = array();
		foreach ( array( 'endpoint_family', 'display_name', 'free' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$snapshot[ $field ] = $row[ $field ];
			}
		}
		if ( isset( $row['capabilities'] ) && is_array( $row['capabilities'] ) ) {
			$snapshot['capabilities'] = $row['capabilities'];
		}
		return $snapshot;
	}

	/**
	 * Compare only explicitly supplied capability keys.
	 *
	 * @param array<string, mixed> $snapshot Normalized discovery row.
	 * @param array<string, mixed> $record   Reviewed record.
	 * @return bool
	 */
	private function capabilities_changed( array $snapshot, array $record ): bool {
		if ( ! isset( $snapshot['capabilities'] ) || ! is_array( $snapshot['capabilities'] ) ) {
			return false;
		}
		foreach ( $snapshot['capabilities'] as $capability => $value ) {
			if ( ! array_key_exists( $capability, $record['capabilities'] ) || $value !== $record['capabilities'][ $capability ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compare an explicitly supplied free-status field.
	 *
	 * @param array<string, mixed> $snapshot Normalized discovery row.
	 * @param array<string, mixed> $record   Reviewed record.
	 * @return bool
	 */
	private function free_changed( array $snapshot, array $record ): bool {
		return array_key_exists( 'free', $snapshot ) && $snapshot['free'] !== $record['free'];
	}

	/**
	 * Compare only explicitly supplied safe metadata fields.
	 *
	 * @param array<string, mixed> $snapshot Normalized discovery row.
	 * @param array<string, mixed> $record   Reviewed record.
	 * @return bool
	 */
	private function metadata_changed( array $snapshot, array $record ): bool {
		foreach ( array( 'display_name' ) as $field ) {
			if ( array_key_exists( $field, $snapshot ) && $snapshot[ $field ] !== $record[ $field ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build a non-promotable watch result.
	 *
	 * @param string $id          Model ID.
	 * @param string $status      Primary status.
	 * @param array  $states      All applicable states.
	 * @param bool   $allowlisted Whether a reviewed record exists.
	 * @return array<string, mixed>
	 */
	private function result( string $id, string $status, array $states, bool $allowlisted ): array {
		return array(
			'id'          => $id,
			'status'      => $status,
			'states'      => array_values( array_unique( $states ) ),
			'allowlisted' => $allowlisted,
			'promotable'  => false,
		);
	}
}
