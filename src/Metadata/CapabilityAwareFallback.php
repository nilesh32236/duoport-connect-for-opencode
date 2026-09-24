<?php
/**
 * Bounded capability-aware model fallback selection.
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
 * Selects only reviewed records with the same endpoint and capability.
 */
final class CapabilityAwareFallback {

	/**
	 * Maximum number of candidates considered for one request.
	 */
	private const MAX_CANDIDATES = 8;

	/**
	 * Verification states accepted for fallback.
	 *
	 * @var list<string>
	 */
	private const VERIFIED_STATUSES = array( 'legacy-verified', 'verified' );

	/**
	 * Optional record resolver used only for isolated contract tests.
	 *
	 * @var callable(string, string): (array<string, mixed>|null)|null
	 */
	private $record_resolver;

	/**
	 * Construct a selector with the canonical registry or an isolated resolver.
	 *
	 * @param callable(string, string): (array<string, mixed>|null)|null $record_resolver Optional resolver.
	 */
	public function __construct( ?callable $record_resolver = null ) {
		$this->record_resolver = $record_resolver;
	}

	/**
	 * Select a primary model or a same-contract fallback.
	 *
	 * @param string             $catalog      Catalog slug.
	 * @param string             $primary_id   Primary model ID.
	 * @param string             $capability   Required capability key.
	 * @param array<int, string> $fallback_ids Ordered fallback IDs.
	 * @return array<string, mixed>
	 */
	public function select( string $catalog, string $primary_id, string $capability, array $fallback_ids = array() ): array {
		if ( ! in_array( $catalog, array( 'go', 'zen' ), true ) ) {
			return $this->empty_result( 'unknown_catalog' );
		}

		$primary = $this->record( $primary_id, $catalog );
		if ( null === $primary ) {
			return $this->empty_result( 'unknown_primary' );
		}
		if ( ( $primary['id'] ?? null ) !== $primary_id || ( $primary['catalog'] ?? null ) !== $catalog ) {
			return $this->empty_result( 'primary_record_mismatch' );
		}
		if ( 'unsupported' === ( $primary['endpoint_family'] ?? '' ) || ! $this->is_verified( $primary ) ) {
			return $this->empty_result( 'unsupported_primary_endpoint' );
		}

		$endpoint   = (string) ( $primary['endpoint_family'] ?? '' );
		$seen       = array();
		$rejected   = array();
		$candidates = array_merge( array( $primary_id ), $fallback_ids );
		foreach ( array_slice( $candidates, 0, self::MAX_CANDIDATES ) as $candidate_id ) {
			if ( ! is_string( $candidate_id ) || '' === $candidate_id ) {
				$rejected[] = array(
					'id'     => '',
					'reason' => 'invalid_candidate',
				);
				continue;
			}
			if ( isset( $seen[ $candidate_id ] ) ) {
				$rejected[] = array(
					'id'     => $candidate_id,
					'reason' => 'cycle',
				);
				continue;
			}
			$seen[ $candidate_id ] = true;
			$record                = $this->record( $candidate_id, $catalog );
			if ( null === $record ) {
				$rejected[] = array(
					'id'     => $candidate_id,
					'reason' => 'unknown_model',
				);
				continue;
			}
			if ( ( $record['id'] ?? null ) !== $candidate_id || ( $record['catalog'] ?? null ) !== $catalog ) {
				$rejected[] = array(
					'id'     => $candidate_id,
					'reason' => 'record_mismatch',
				);
				continue;
			}
			if ( ( $record['endpoint_family'] ?? '' ) !== $endpoint ) {
				$rejected[] = array(
					'id'     => $candidate_id,
					'reason' => 'endpoint_mismatch',
				);
				continue;
			}
			if ( 'unsupported' === ( $record['endpoint_family'] ?? '' ) || ! $this->is_verified( $record ) ) {
				$rejected[] = array(
					'id'     => $candidate_id,
					'reason' => 'unsupported_endpoint',
				);
				continue;
			}
			if ( true !== ( $record['capabilities'][ $capability ] ?? false ) ) {
				$rejected[] = array(
					'id'     => $candidate_id,
					'reason' => 'capability_mismatch',
				);
				continue;
			}
			return array(
				'selected'    => $record,
				'selected_id' => $candidate_id,
				'rejected'    => $rejected,
				'exhausted'   => false,
			);
		}

		return array(
			'selected'    => null,
			'selected_id' => null,
			'rejected'    => $rejected,
			'exhausted'   => true,
		);
	}

	/**
	 * Whether a record has a known verification state.
	 *
	 * @param array<string, mixed> $record Model record.
	 * @return bool
	 */
	private function is_verified( array $record ): bool {
		return in_array( $record['verification_status'] ?? '', self::VERIFIED_STATUSES, true );
	}

	/**
	 * Resolve one curated record through the canonical or test seam.
	 *
	 * @param string $id      Model ID.
	 * @param string $catalog Catalog slug.
	 * @return array<string, mixed>|null
	 */
	private function record( string $id, string $catalog ): ?array {
		if ( null === $this->record_resolver ) {
			return ModelRegistry::record( $id, $catalog );
		}
		$record = call_user_func( $this->record_resolver, $id, $catalog );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Build a safe empty result.
	 *
	 * @param string $reason Failure reason.
	 * @return array<string, mixed>
	 */
	private function empty_result( string $reason ): array {
		return array(
			'selected'    => null,
			'selected_id' => null,
			'rejected'    => array(
				array(
					'id'     => '',
					'reason' => $reason,
				),
			),
			'exhausted'   => true,
		);
	}
}
