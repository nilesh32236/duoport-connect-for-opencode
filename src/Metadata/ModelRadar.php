<?php
/**
 * Credential-free OpenCode model coverage radar.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces an evidence-only coverage report from public catalog snapshots.
 *
 * Discovery is never a promotion path. Reviewed support, explicit free
 * evidence, and unverified free-name candidates remain separate.
 */
final class ModelRadar {

	/**
	 * Catalog source URLs used by the shipped radar report.
	 *
	 * Derived from the Catalog base URLs so host changes stay in one place.
	 *
	 * @var array<string, string>
	 */
	private const SOURCES = array(
		Catalog::GO  => Catalog::GO_BASE_URL . '/models',
		Catalog::ZEN => Catalog::ZEN_BASE_URL . '/models',
	);

	/**
	 * Watch collaborator (test seam; defaults to canonical).
	 *
	 * @var CatalogWatch|null
	 */
	private ?CatalogWatch $watch_override = null;

	/**
	 * Build a radar with the canonical watch or an isolated double.
	 *
	 * @since 0.1.6
	 *
	 * @param CatalogWatch|null $watch Optional watch double for tests.
	 */
	public function __construct( ?CatalogWatch $watch = null ) {
		$this->watch_override = $watch;
	}

	/**
	 * Verification states that can support a reviewed route.
	 *
	 * @var list<string>
	 */
	private const SUPPORTED_STATUSES = array( 'legacy-verified', 'verified' );

	/**
	 * Build a safe, machine-readable report from public discovery rows.
	 *
	 * @param array<string, list<array<string, mixed>>|null> $catalogs    Public rows or null for unreachable.
	 * @param string|null                                    $checked_at Optional deterministic check time.
	 * @return array<string, mixed>
	 */
	public function report( array $catalogs, ?string $checked_at = null ): array {
		$checked_at = null !== $checked_at && '' !== $checked_at ? $checked_at : gmdate( 'c' );
		$watch      = $this->watch_override ?? new CatalogWatch();
		$report     = array(
			'schema_version' => 1,
			'checked_at'     => $checked_at,
			'sources'        => self::SOURCES,
			'metrics'        => array(
				'measurement_started_at'     => $checked_at,
				'detection_to_verification'  => null,
				'verification_to_merge'      => null,
				'merge_to_release'           => null,
				'total_detection_to_release' => null,
			),
			'catalogs'       => array(),
		);

		foreach ( Catalog::ALL as $catalog ) {
			$rows = $catalogs[ $catalog ] ?? null;
			if ( ! is_array( $rows ) ) {
				$report['catalogs'][ $catalog ] = $this->unreachable_report();
				continue;
			}

			$results                        = $watch->compare( $catalog, $rows );
			$report['catalogs'][ $catalog ] = $this->catalog_report( $catalog, $rows, $results );
		}

		return $report;
	}

	/**
	 * Render a stable Markdown view of a report.
	 *
	 * @param array<string, mixed> $report Report from report().
	 * @return string
	 */
	public function markdown( array $report ): string {
		$lines = array(
			'# OpenCode Model Radar',
			'',
			'Checked at: `' . $this->safe_text( $this->text_value( $report['checked_at'] ?? '' ) ) . '`',
			'',
			'Public catalog evidence is non-promotable. Unknown models, endpoint families, and capabilities remain default-deny.',
			'',
		);

		foreach ( Catalog::ALL as $catalog ) {
			$data = is_array( $report['catalogs'][ $catalog ] ?? null ) ? $report['catalogs'][ $catalog ] : array();
			foreach ( $this->catalogSection( $catalog, $data ) as $line ) {
				$lines[] = $line;
			}
		}

		foreach ( $this->measurementSection( $report ) as $line ) {
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render the Markdown section for one catalog.
	 *
	 * @since 0.1.6
	 *
	 * @param string               $catalog Catalog slug.
	 * @param array<string, mixed> $data Per-catalog report data.
	 * @return list<string>
	 */
	private function catalogSection( string $catalog, array $data ): array {
		$lines   = array();
		$lines[] = '## ' . strtoupper( $catalog );
		$lines[] = '';
		if ( ! empty( $data['unreachable'] ) ) {
			$lines[] = '_API unreachable; no retirement or promotion decision was made._';
			$lines[] = '';
			return $lines;
		}
		$summary = is_array( $data['summary'] ?? null ) ? $data['summary'] : array();
		$lines[] = '| Discovered | Supported | Free supported | Unsupported | Verification required | Free candidates |';
		$lines[] = '| ---: | ---: | ---: | ---: | ---: | ---: |';
		$lines[] = sprintf(
			'| %d | %d | %d | %d | %d | %d |',
			(int) ( $summary['discovered'] ?? 0 ),
			(int) ( $summary['supported'] ?? 0 ),
			(int) ( $summary['free_supported'] ?? 0 ),
			(int) ( $summary['unsupported'] ?? 0 ),
			(int) ( $summary['verification_required'] ?? 0 ),
			(int) ( $summary['free_candidates'] ?? 0 )
		);
		$lines[] = '';
		$changes = is_array( $data['changes'] ?? null ) ? $data['changes'] : array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || empty( $change['id'] ) ) {
				continue;
			}
			$lines[] = '- `' . $this->safe_text( $this->text_value( $change['id'] ) ) . '` — **' . $this->safe_text( $this->text_value( $change['status'] ?? '' ) ) . '** (' . $this->safe_text( $this->changeStatesText( $change ) ) . '); ' . $this->changeLabel( $change );
		}
		if ( array() === $changes ) {
			$lines[] = '_No comparable rows._';
		}
		$lines[] = '';
		return $lines;
	}

	/**
	 * States text for one change row.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, mixed> $change Change row.
	 * @return string
	 */
	private function changeStatesText( array $change ): string {
		return is_array( $change['states'] ?? null ) ? implode( ', ', $change['states'] ) : '';
	}

	/**
	 * Human label for one change row.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, mixed> $change Change row.
	 * @return string
	 */
	private function changeLabel( array $change ): string {
		$label = 'retired' === ( $change['status'] ?? '' ) ? 'retired' : ( 'verification_required' === ( $change['status'] ?? '' ) ? 'verification-required' : ( ! empty( $change['supported'] ) ? 'reviewed' : 'registry-candidate' ) );
		if ( ! empty( $change['free_candidate'] ) ) {
			$label .= '; free-candidate';
		}
		return $label;
	}

	/**
	 * Render the Markdown measurement section.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, mixed> $report Report from report().
	 * @return list<string>
	 */
	private function measurementSection( array $report ): array {
		return array(
			'## Measurement',
			'',
			'Measurement starts at this implementation. Historical detection, verification, merge, and release timestamps remain null until observed.',
			'',
			'- `measurement_started_at`: `' . $this->safe_text( $this->text_value( $report['metrics']['measurement_started_at'] ?? '' ) ) . '`',
			'- `detection_to_verification`: ' . $this->metric_text( $report, 'detection_to_verification' ),
			'- `verification_to_merge`: ' . $this->metric_text( $report, 'verification_to_merge' ),
			'- `merge_to_release`: ' . $this->metric_text( $report, 'merge_to_release' ),
			'- `total_detection_to_release`: ' . $this->metric_text( $report, 'total_detection_to_release' ),
			'',
			'Free-name candidates are observations only; they require endpoint, request, response, capability, and WordPress compatibility verification before promotion.',
		);
	}

	/**
	 * Build a safe report for an unreachable catalog.
	 *
	 * The summary shape comes from emptySummary() so the unreachable and the
	 * reachable branches cannot drift apart: a consumer that branches on key
	 * presence sees the same keys either way.
	 *
	 * @return array<string, mixed>
	 */
	private function unreachable_report(): array {
		return array(
			'unreachable' => true,
			'summary'     => $this->emptySummary(),
			'changes'     => array(),
		);
	}

	/**
	 * Build a report for one reachable catalog.
	 *
	 * @param string                     $catalog Catalog key.
	 * @param list<array<string, mixed>> $rows Public discovery rows.
	 * @param list<array<string, mixed>> $results CatalogWatch results.
	 * @return array<string, mixed>
	 */
	private function catalog_report( string $catalog, array $rows, array $results ): array {
		$scanned         = $this->scanExplicitFree( $rows );
		$explicit_free   = $scanned['explicit_free'];
		$endpoint_counts = $scanned['endpoint_counts'];

		$summary = $this->emptySummary();
		$changes = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$id = $this->text_value( $result['id'] ?? '' );
			if ( '' === $id ) {
				++$summary['malformed'];
				continue;
			}
			$built = $this->buildChangeRow( $catalog, $result, $id, $explicit_free );
			$this->summarizeResult( $summary, $built['counters'], $built['row'] );
			$changes[] = $built['row'];
		}

		return array(
			'unreachable'                 => false,
			'summary'                     => $summary,
			'endpoint_evidence'           => $endpoint_counts,
			'endpoint_evidence_available' => array() !== $endpoint_counts,
			'changes'                     => $changes,
		);
	}

	/**
	 * Scan discovery rows for explicit free evidence and endpoint families.
	 *
	 * @since 0.1.6
	 *
	 * @param list<array<string, mixed>> $rows Public discovery rows.
	 * @return array{explicit_free: array<string, bool>, endpoint_counts: array<string, int>}
	 */
	private function scanExplicitFree( array $rows ): array {
		$explicit_free   = array();
		$endpoint_counts = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || '' === $row['id'] ) {
				continue;
			}
			if ( array_key_exists( 'free', $row ) && true === $row['free'] ) {
				$explicit_free[ $row['id'] ] = true;
			}
			if ( isset( $row['endpoint_family'] ) && is_string( $row['endpoint_family'] ) ) {
				$family                     = $row['endpoint_family'];
				$endpoint_counts[ $family ] = ( $endpoint_counts[ $family ] ?? 0 ) + 1;
			}
		}
		return array(
			'explicit_free'   => $explicit_free,
			'endpoint_counts' => $endpoint_counts,
		);
	}

	/**
	 * Empty per-catalog summary counters.
	 *
	 * @since 0.1.6
	 *
	 * @return array<string, int>
	 */
	private function emptySummary(): array {
		return array(
			'discovered'              => 0,
			'supported'               => 0,
			'free_supported'          => 0,
			'registry_candidates'     => 0,
			'unsupported'             => 0,
			'verification_required'   => 0,
			'new'                     => 0,
			'retired'                 => 0,
			'free_candidates'         => 0,
			'retired_free_candidates' => 0,
			'free_name_candidates'    => 0,
			'explicit_free_evidence'  => 0,
			'endpoint_change'         => 0,
			'capability_change'       => 0,
			'metadata_change'         => 0,
			'free_change'             => 0,
			'malformed'               => 0,
		);
	}

	/**
	 * Build one change row for a watch result.
	 *
	 * The published row and the summary scaffolding are returned as separate
	 * structures so a row is valid on its own: the summary needs facts the
	 * report does not publish (retirement, free evidence), but they must never
	 * ride along on a public key. `status` and `states` are already published
	 * on the row, so the counters refer to the row for those.
	 *
	 * @since 0.1.6
	 *
	 * @param string               $catalog Catalog key.
	 * @param array<string, mixed> $result Watch result.
	 * @param string               $id Model ID.
	 * @param array<string, bool>  $explicit_free Explicit free evidence keyed by ID.
	 * @return array{row: array<string, mixed>, counters: array<string, mixed>}
	 */
	private function buildChangeRow( string $catalog, array $result, string $id, array $explicit_free ): array {
		$states         = is_array( $result['states'] ?? null ) ? $result['states'] : array();
		$record         = ModelRegistry::record( $id, $catalog );
		$supported      = $this->is_supported( $record );
		$free_reviewed  = null !== $record && ModelAllowlist::isFree( $id );
		$free_name      = $this->looks_free_name( $id );
		$free_explicit  = isset( $explicit_free[ $id ] );
		$free_candidate = $free_name || $free_explicit;
		$is_retired     = 'retired' === ( $result['status'] ?? '' );
		$priority       = $free_candidate || 'allowlisted' !== ( $result['status'] ?? '' ) ? 'high' : 'normal';

		return array(
			'row'      => array(
				'id'                   => $id,
				'status'               => $this->text_value( $result['status'] ?? '' ),
				'states'               => array_values( array_unique( $states ) ),
				'allowlisted'          => (bool) ( $result['allowlisted'] ?? false ),
				'registry_candidate'   => null !== $record,
				'supported'            => $supported,
				'free_registry'        => $free_reviewed,
				'free_candidate'       => $free_candidate,
				'free_candidate_basis' => $free_explicit ? 'explicit_public_evidence' : ( $free_name ? 'unverified_name' : 'none' ),
				'priority'             => $priority,
				'promotable'           => false,
			),
			'counters' => array(
				'is_retired'    => $is_retired,
				'free_name'     => $free_name,
				'free_explicit' => $free_explicit,
				'unsupported'   => null !== $record && ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED === ( $record['endpoint_family'] ?? '' ),
			),
		);
	}

	/**
	 * Fold one change row into the summary counters.
	 *
	 * Reads the summary-only facts returned alongside the row by
	 * buildChangeRow(); the published row itself is never mutated, so a caller
	 * that never summarizes still publishes a clean row.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, int>   $summary Summary counters (updated in place).
	 * @param array<string, mixed> $counters Summary-only facts for one row.
	 * @param array<string, mixed> $row Published change row (read only).
	 * @return void
	 */
	private function summarizeResult( array &$summary, array $counters, array $row ): void {
		$is_retired    = (bool) ( $counters['is_retired'] ?? false );
		$free_name     = (bool) ( $counters['free_name'] ?? false );
		$free_explicit = (bool) ( $counters['free_explicit'] ?? false );
		$unsupported   = (bool) ( $counters['unsupported'] ?? false );
		$status        = $this->text_value( $row['status'] ?? '' );
		$states        = is_array( $row['states'] ?? null ) ? $row['states'] : array();

		if ( $is_retired ) {
			++$summary['retired'];
		} else {
			++$summary['discovered'];
		}
		if ( ! $is_retired && (bool) ( $row['registry_candidate'] ?? false ) ) {
			++$summary['registry_candidates'];
		}
		if ( ! $is_retired && (bool) ( $row['supported'] ?? false ) ) {
			++$summary['supported'];
		}
		if ( ! $is_retired && (bool) ( $row['supported'] ?? false ) && (bool) ( $row['free_registry'] ?? false ) ) {
			++$summary['free_supported'];
		}
		if ( ! $is_retired && $unsupported ) {
			++$summary['unsupported'];
		}
		if ( ! $is_retired && 'verification_required' === $status ) {
			++$summary['verification_required'];
		}
		foreach ( array(
			'new'                => 'new',
			'endpoint_changed'   => 'endpoint_change',
			'capability_changed' => 'capability_change',
			'metadata_changed'   => 'metadata_change',
			'free_changed'       => 'free_change',
		) as $state => $counter ) {
			if ( ! $is_retired && in_array( $state, $states, true ) ) {
				++$summary[ $counter ];
			}
		}
		if ( ! $is_retired && $free_name && ! $free_explicit ) {
			++$summary['free_name_candidates'];
		}
		if ( ! $is_retired && $free_explicit ) {
			++$summary['explicit_free_evidence'];
		}
		if ( (bool) ( $row['free_candidate'] ?? false ) ) {
			if ( $is_retired ) {
				++$summary['retired_free_candidates'];
			} else {
				++$summary['free_candidates'];
			}
		}
	}

	/**
	 * Whether a reviewed record has a supported endpoint and verification.
	 *
	 * @param array<string, mixed>|null $record Registry record.
	 * @return bool
	 */
	private function is_supported( ?array $record ): bool {
		return null !== $record
			&& ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED !== ( $record['endpoint_family'] ?? '' )
			&& in_array( $record['verification_status'] ?? '', self::SUPPORTED_STATUSES, true );
	}

	/**
	 * Identify a free-looking name without treating it as proof.
	 *
	 * @param string $id Model ID.
	 * @return bool
	 */
	private function looks_free_name( string $id ): bool {
		return 1 === preg_match( '/(?:^|[-_.])free(?:$|[-_.])/i', $id );
	}

	/**
	 * Render one nullable measurement value.
	 *
	 * @param array<string, mixed> $report Report data.
	 * @param string               $key     Measurement key.
	 * @return string
	 */
	private function metric_text( array $report, string $key ): string {
		$value = $report['metrics'][ $key ] ?? null;
		return null === $value ? 'null' : $this->safe_text( $this->text_value( $value ) );
	}

	/**
	 * Escape report text used in Markdown output.
	 *
	 * @param string $text Text value.
	 * @return string
	 */
	private function safe_text( string $text ): string {
		return str_replace( array( '`', "\r", "\n" ), array( '', '', ' ' ), $text );
	}

	/**
	 * Read an untrusted value as text without blind-casting it.
	 *
	 * Discovery rows are decoded JSON, so any value here can be an array or an
	 * object; casting those to string emits a warning or throws. Non-scalars
	 * are described by type instead, matching the rest of src/.
	 *
	 * @since 0.1.7
	 *
	 * @param mixed $value Untrusted value.
	 * @return string
	 */
	private function text_value( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return is_object( $value ) ? get_class( $value ) : get_debug_type( $value );
	}
}
