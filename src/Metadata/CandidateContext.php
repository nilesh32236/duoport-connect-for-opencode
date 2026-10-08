<?php
/**
 * Per-select candidate evaluation context.
 *
 * Carries the immutable select() inputs (catalog, endpoint, capability)
 * together with the mutable seen-set used for cycle detection, so
 * CapabilityAwareFallback::evaluateCandidate() takes two arguments instead
 * of five with a by-reference array.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluation context for one fallback select() call.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class CandidateContext {
	/**
	 * Catalog slug the candidates are evaluated in.
	 *
	 * @var string
	 */
	private readonly string $catalog;

	/**
	 * Primary endpoint family candidates must match.
	 *
	 * @var string
	 */
	private readonly string $endpoint;

	/**
	 * Required capability key.
	 *
	 * @var string
	 */
	private readonly string $capability;

	/**
	 * Candidate IDs already evaluated (cycle detection).
	 *
	 * @var array<string, bool>
	 */
	private array $seen = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.8
	 *
	 * @param string $catalog    Catalog slug.
	 * @param string $endpoint   Primary endpoint family.
	 * @param string $capability Required capability key.
	 */
	public function __construct( string $catalog, string $endpoint, string $capability ) {
		$this->catalog    = $catalog;
		$this->endpoint   = $endpoint;
		$this->capability = $capability;
	}

	/**
	 * Catalog slug.
	 *
	 * @since 0.1.8
	 *
	 * @return string
	 */
	public function catalog(): string {
		return $this->catalog;
	}

	/**
	 * Primary endpoint family.
	 *
	 * @since 0.1.8
	 *
	 * @return string
	 */
	public function endpoint(): string {
		return $this->endpoint;
	}

	/**
	 * Required capability key.
	 *
	 * @since 0.1.8
	 *
	 * @return string
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Whether a candidate was already evaluated.
	 *
	 * @since 0.1.8
	 *
	 * @param string $candidate_id Candidate model ID.
	 * @return bool
	 */
	public function isSeen( string $candidate_id ): bool {
		return isset( $this->seen[ $candidate_id ] );
	}

	/**
	 * Record a candidate as evaluated.
	 *
	 * @since 0.1.8
	 *
	 * @param string $candidate_id Candidate model ID.
	 * @return void
	 */
	public function markSeen( string $candidate_id ): void {
		$this->seen[ $candidate_id ] = true;
	}
}
