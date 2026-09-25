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
		if ( ! Catalog::is_valid( $catalog ) ) {
			return $this->empty_result( 'unknown_catalog' );
		}

		$primary = $this->record( $primary_id, $catalog );
		if ( null === $primary ) {
			return $this->empty_result( 'unknown_primary' );
		}
		if ( ( $primary['id'] ?? null ) !== $primary_id || ( $primary['catalog'] ?? null ) !== $catalog ) {
			return $this->empty_result( 'primary_record_mismatch' );
		}
		if ( ModelRegistry::IMPLEMENTED_ENDPOINT !== ( $primary['endpoint_family'] ?? '' ) || ! ModelRegistry::isVerified( $primary ) ) {
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
			$reason                = $this->validateCandidate( $candidate_id, $catalog, $endpoint, $capability );
			if ( null !== $reason ) {
				$rejected[] = array(
					'id'     => $candidate_id,
					'reason' => $reason,
				);
				continue;
			}
			$record = $this->record( $candidate_id, $catalog );
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
	 * Validate one fallback candidate against the primary contract.
	 *
	 * Returns a rejection reason, or null when the candidate is selectable.
	 *
	 * @param string $candidate_id Candidate model ID.
	 * @param string $catalog      Catalog slug.
	 * @param string $endpoint     Required endpoint family.
	 * @param string $capability   Required capability key.
	 * @return string|null Rejection reason, or null when valid.
	 */
	private function validateCandidate( string $candidate_id, string $catalog, string $endpoint, string $capability ): ?string {
		$record = $this->record( $candidate_id, $catalog );
		if ( null === $record ) {
			return 'unknown_model';
		}
		if ( ( $record['id'] ?? null ) !== $candidate_id || ( $record['catalog'] ?? null ) !== $catalog ) {
			return 'record_mismatch';
		}
		if ( ( $record['endpoint_family'] ?? '' ) !== $endpoint ) {
			return 'endpoint_mismatch';
		}
		if ( ModelRegistry::IMPLEMENTED_ENDPOINT !== ( $record['endpoint_family'] ?? '' ) ) {
			return 'endpoint_mismatch';
		}
		if ( ! ModelRegistry::isVerified( $record ) ) {
			return 'unsupported_endpoint';
		}
		if ( true !== ( $record['capabilities'][ $capability ] ?? false ) ) {
			return 'capability_mismatch';
		}
		return null;
	}

	/**
	 * Whether a record has a known verification state.
	 *
	 * Delegates to the canonical ModelRegistry vocabulary.
	 *
	 * @param array<string, mixed> $record Model record.
	 * @return bool
	 */
	private function is_verified( array $record ): bool {
		return ModelRegistry::isVerified( $record );
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
