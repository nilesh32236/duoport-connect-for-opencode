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
	 * Safe catalog keys.
	 *
	 * @var list<string>
	 */
	private const CATALOGS = array( 'go', 'zen' );

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
		if ( ! in_array( $catalog, self::CATALOGS, true ) ) {
			return array();
		}

		$baseline = array();
		foreach ( ModelRegistry::records( $catalog ) as $record ) {
			$baseline[ $record['id'] ] = $record;
		}

		$current   = array();
		$saw_valid = false;
		foreach ( $discovered as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || '' === $row['id'] ) {
				continue;
			}
			$saw_valid             = true;
			$current[ $row['id'] ] = $this->normalize( $row );
		}
		if ( count( $discovered ) > 0 && ! $saw_valid ) {
			return array();
		}

		$results = array();
		foreach ( $current as $id => $snapshot ) {
			$states = array();
			if ( ! isset( $baseline[ $id ] ) ) {
				$states[] = 'new';
				$states[] = 'verification_required';
				$status   = 'verification_required';
			} else {
				$states[] = 'allowlisted';
				$status   = 'allowlisted';
				$record   = $baseline[ $id ];
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
				if ( $this->metadata_changed( $snapshot, $record ) ) {
					$states[] = 'metadata_changed';
					$states[] = 'verification_required';
					$status   = 'verification_required';
				}
			}
			$results[] = $this->result( $id, $status, $states, isset( $baseline[ $id ] ) );
		}

		foreach ( $baseline as $id => $record ) {
			if ( isset( $current[ $id ] ) ) {
				continue;
			}
			$results[] = $this->result(
				$id,
				'retired',
				array( 'retired', 'verification_required' ),
				true
			);
		}

		usort(
			$results,
			static function ( array $left, array $right ): int {
				return strcmp( $left['id'], $right['id'] );
			}
		);
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
			if ( array_key_exists( $capability, $record['capabilities'] ) && $value !== $record['capabilities'][ $capability ] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compare only explicitly supplied safe metadata fields.
	 *
	 * @param array<string, mixed> $snapshot Normalized discovery row.
	 * @param array<string, mixed> $record   Reviewed record.
	 * @return bool
	 */
	private function metadata_changed( array $snapshot, array $record ): bool {
		foreach ( array( 'display_name', 'free' ) as $field ) {
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
