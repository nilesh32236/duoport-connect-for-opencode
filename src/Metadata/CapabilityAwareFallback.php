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
	 * Only the complete transport family currently implemented.
	 */
	private const IMPLEMENTED_ENDPOINT = 'chat';

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
		if ( ! Catalog::isValid( $catalog ) ) {
			return $this->empty_result( 'unknown_catalog' );
		}

		$primary = $this->validatePrimary( $primary_id, $catalog );
		if ( null === $primary['record'] ) {
			return $this->empty_result( (string) $primary['error'] );
		}

		$endpoint   = (string) ( $primary['record']['endpoint_family'] ?? '' );
		$context    = new CandidateContext( $catalog, $endpoint, $capability );
		$rejected   = array();
		$candidates = array_merge( array( $primary_id ), $fallback_ids );
		foreach ( array_slice( $candidates, 0, self::MAX_CANDIDATES ) as $candidate_id ) {
			$verdict = $this->evaluateCandidate( $candidate_id, $context );
			if ( isset( $verdict['record'] ) ) {
				return array(
					'selected'    => $verdict['record'],
					'selected_id' => $candidate_id,
					'rejected'    => $rejected,
					'exhausted'   => false,
				);
			}
			$rejected[] = $verdict['rejection'];
		}

		return array(
			'selected'    => null,
			'selected_id' => null,
			'rejected'    => $rejected,
			'exhausted'   => true,
		);
	}

	/**
	 * Validate the primary record (catalog match, endpoint, verification).
	 *
	 * @since 0.1.6
	 *
	 * @param string $primary_id Primary model ID.
	 * @param string $catalog Catalog slug.
	 * @return array{record: array<string, mixed>|null, error: string}
	 */
	private function validatePrimary( string $primary_id, string $catalog ): array {
		$primary = $this->record( $primary_id, $catalog );
		if ( null === $primary ) {
			return array(
				'record' => null,
				'error'  => 'unknown_primary',
			);
		}
		if ( ( $primary['id'] ?? null ) !== $primary_id || ( $primary['catalog'] ?? null ) !== $catalog ) {
			return array(
				'record' => null,
				'error'  => 'primary_record_mismatch',
			);
		}
		if ( self::IMPLEMENTED_ENDPOINT !== ( $primary['endpoint_family'] ?? '' ) || ! $this->is_verified( $primary ) ) {
			return array(
				'record' => null,
				'error'  => 'unsupported_primary_endpoint',
			);
		}
		return array(
			'record' => $primary,
			'error'  => '',
		);
	}

	/**
	 * Evaluate one candidate ID against the primary contract.
	 *
	 * Cycle detection lives in the context object rather than a
	 * by-reference array parameter, so the "updated in place" mechanism is
	 * visible in the signature's type instead of a docblock note, and a
	 * future rule cannot silently shift argument order. Returns either
	 * `array('record' => ...)` on acceptance or `array('rejection' => ...)`
	 * on rejection — never throws.
	 *
	 * @since 0.1.6
	 *
	 * @param mixed            $candidate_id Candidate model ID.
	 * @param CandidateContext $context      Per-select evaluation context.
	 * @return array<string, mixed>
	 */
	private function evaluateCandidate( $candidate_id, CandidateContext $context ): array {
		$catalog    = $context->catalog();
		$endpoint   = $context->endpoint();
		$capability = $context->capability();
		if ( ! is_string( $candidate_id ) || '' === $candidate_id ) {
			return array(
				'rejection' => array(
					'id'     => '',
					'reason' => 'invalid_candidate',
				),
			);
		}
		if ( $context->isSeen( $candidate_id ) ) {
			return array(
				'rejection' => array(
					'id'     => $candidate_id,
					'reason' => 'cycle',
				),
			);
		}
		$context->markSeen( $candidate_id );
		$record = $this->record( $candidate_id, $catalog );
		if ( null === $record ) {
			return array(
				'rejection' => array(
					'id'     => $candidate_id,
					'reason' => 'unknown_model',
				),
			);
		}
		if ( ( $record['id'] ?? null ) !== $candidate_id || ( $record['catalog'] ?? null ) !== $catalog ) {
			return array(
				'rejection' => array(
					'id'     => $candidate_id,
					'reason' => 'record_mismatch',
				),
			);
		}
		// Single endpoint check: the candidate must match the primary
		// endpoint AND the implemented transport family.
		if ( ( $record['endpoint_family'] ?? '' ) !== $endpoint || self::IMPLEMENTED_ENDPOINT !== ( $record['endpoint_family'] ?? '' ) ) {
			return array(
				'rejection' => array(
					'id'     => $candidate_id,
					'reason' => 'endpoint_mismatch',
				),
			);
		}
		if ( ! $this->is_verified( $record ) ) {
			return array(
				'rejection' => array(
					'id'     => $candidate_id,
					'reason' => 'unsupported_endpoint',
				),
			);
		}
		if ( true !== ( $record['capabilities'][ $capability ] ?? false ) ) {
			return array(
				'rejection' => array(
					'id'     => $candidate_id,
					'reason' => 'capability_mismatch',
				),
			);
		}
		return array( 'record' => $record );
	}

	/**
	 * Whether a record has a known verification state.
	 *
	 * Delegates to ModelRegistry, which owns the reviewed vocabulary, so
	 * the selector cannot disagree with the registry about what "reviewed"
	 * means.
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
