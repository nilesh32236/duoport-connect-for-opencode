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

use OpenCodeConnector\Providers\Endpoints;

/**
 * Produces an evidence-only coverage report from public catalog snapshots.
 *
 * Discovery is never a promotion path. Reviewed support, explicit free
 * evidence, and unverified free-name candidates remain separate.
 */
final class ModelRadar {

	/**
	 * Watch used to diff discovery rows against the reviewed registry.
	 *
	 * @since 0.1.6
	 *
	 * @var CatalogWatch
	 */
	private CatalogWatch $watch;

	/**
	 * Constructor.
	 *
	 * @since 0.1.6
	 *
	 * @param CatalogWatch|null $watch Optional watch double for tests.
	 */
	public function __construct( ?CatalogWatch $watch = null ) {
		$this->watch = $watch ?? new CatalogWatch();
	}

	/**
	 * Build a safe, machine-readable report from public discovery rows.
	 *
	 * @param array<string, list<array<string, mixed>>|null> $catalogs    Public rows or null for unreachable.
	 * @param string|null                                    $checked_at Optional deterministic check time.
	 * @return array<string, mixed>
	 */
	public function report( array $catalogs, ?string $checked_at = null ): array {
		$checked_at = null !== $checked_at && '' !== $checked_at ? $checked_at : gmdate( 'c' );
		$report     = array(
			'schema_version' => 1,
			'checked_at'     => $checked_at,
			'sources'        => Endpoints::sources(),
			'metrics'        => array(
				'measurement_started_at'     => $checked_at,
				'detection_to_verification'  => null,
				'verification_to_merge'      => null,
				'merge_to_release'           => null,
				'total_detection_to_release' => null,
			),
			'catalogs'       => array(),
		);

		foreach ( Catalog::all() as $catalog ) {
			$rows = $catalogs[ $catalog ] ?? null;
			if ( ! is_array( $rows ) ) {
				$report['catalogs'][ $catalog ] = $this->unreachable_report();
				continue;
			}

			$results                        = $this->watch->compare( $catalog, $rows );
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
			'Checked at: `' . $this->safe_text( (string) ( $report['checked_at'] ?? '' ) ) . '`',
			'',
			'Public catalog evidence is non-promotable. Unknown models, endpoint families, and capabilities remain default-deny.',
			'',
		);

		foreach ( Catalog::all() as $catalog ) {
			$data = is_array( $report['catalogs'][ $catalog ] ?? null ) ? $report['catalogs'][ $catalog ] : array();
			foreach ( $this->renderCatalogSection( $catalog, $data ) as $line ) {
				$lines[] = $line;
			}
		}

		foreach ( $this->renderMeasurement( $report ) as $line ) {
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render the Markdown section for one catalog.
	 *
	 * @since 0.1.6
	 *
	 * @param string               $catalog Catalog key.
	 * @param array<string, mixed> $data    Catalog report data.
	 * @return list<string>
	 */
	private function renderCatalogSection( string $catalog, array $data ): array {
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
			$states = is_array( $change['states'] ?? null ) ? implode( ', ', $change['states'] ) : '';
			$label  = 'retired' === ( $change['status'] ?? '' ) ? 'retired' : ( 'verification_required' === ( $change['status'] ?? '' ) ? 'verification-required' : ( ! empty( $change['supported'] ) ? 'reviewed' : 'registry-candidate' ) );
			if ( ! empty( $change['free_candidate'] ) ) {
				$label .= '; free-candidate';
			}
			$lines[] = '- `' . $this->safe_text( (string) $change['id'] ) . '` — **' . $this->safe_text( (string) ( $change['status'] ?? '' ) ) . '** (' . $this->safe_text( $states ) . '); ' . $label;
		}
		if ( array() === $changes ) {
			$lines[] = '_No comparable rows._';
		}
		$lines[] = '';
		return $lines;
	}

	/**
	 * Render the Markdown measurement section.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, mixed> $report Report from report().
	 * @return list<string>
	 */
	private function renderMeasurement( array $report ): array {
		return array(
			'## Measurement',
			'',
			'Measurement starts at this implementation. Historical detection, verification, merge, and release timestamps remain null until observed.',
			'',
			'- `measurement_started_at`: `' . $this->safe_text( (string) ( $report['metrics']['measurement_started_at'] ?? '' ) ) . '`',
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
	 * @return array<string, mixed>
	 */
	private function unreachable_report(): array {
		return array(
			'unreachable' => true,
			'summary'     => array(
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
			),
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
		list( $explicit_free, $endpoint_counts ) = $this->scanExplicitFree( $rows );

		$summary = $this->emptySummary();
		$changes = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			list( $summary, $change ) = $this->summarizeResult( $summary, $catalog, $explicit_free, $result );
			if ( null !== $change ) {
				$changes[] = $change;
			}
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
	 * @return array{0: array<string, true>, 1: array<string, int>}
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
		return array( $explicit_free, $endpoint_counts );
	}

	/**
	 * Fresh zeroed summary accumulator.
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
	 * Fold one watch result into the summary accumulator.
	 *
	 * Returns the updated summary plus the change row, or null when the
	 * result is malformed (counted only).
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, int>   $summary Summary accumulator.
	 * @param string               $catalog Catalog key.
	 * @param array<string, true>  $explicit_free Explicit free evidence by ID.
	 * @param array<string, mixed> $result Watch result row.
	 * @return array{0: array<string, int>, 1: array<string, mixed>|null}
	 */
	private function summarizeResult( array $summary, string $catalog, array $explicit_free, array $result ): array {
		$id     = (string) ( $result['id'] ?? '' );
		$states = is_array( $result['states'] ?? null ) ? $result['states'] : array();
		if ( '' === $id ) {
			++$summary['malformed'];
			return array( $summary, null );
		}
		$is_retired = 'retired' === ( $result['status'] ?? '' );
		if ( $is_retired ) {
			++$summary['retired'];
		} else {
			++$summary['discovered'];
		}
		$record         = ModelRegistry::record( $id, $catalog );
		$supported      = $this->is_supported( $record );
		$free_reviewed  = null !== $record && ModelAllowlist::isFree( $id );
		$free_name      = $this->looks_free_name( $id );
		$free_explicit  = isset( $explicit_free[ $id ] );
		$free_candidate = $free_name || $free_explicit;
		if ( ! $is_retired && null !== $record ) {
			++$summary['registry_candidates'];
		}
		if ( ! $is_retired && $supported ) {
			++$summary['supported'];
		}
		if ( ! $is_retired && $supported && $free_reviewed ) {
			++$summary['free_supported'];
		}
		if ( ! $is_retired && null !== $record && ModelRegistry::UNSUPPORTED_FAMILY === ( $record['endpoint_family'] ?? '' ) ) {
			++$summary['unsupported'];
		}
		if ( ! $is_retired && 'verification_required' === ( $result['status'] ?? '' ) ) {
			++$summary['verification_required'];
		}
		if ( ! $is_retired && in_array( 'new', $states, true ) ) {
			++$summary['new'];
		}
		if ( ! $is_retired && in_array( 'endpoint_changed', $states, true ) ) {
			++$summary['endpoint_change'];
		}
		if ( ! $is_retired && in_array( 'capability_changed', $states, true ) ) {
			++$summary['capability_change'];
		}
		if ( ! $is_retired && in_array( 'metadata_changed', $states, true ) ) {
			++$summary['metadata_change'];
		}
		if ( ! $is_retired && in_array( 'free_changed', $states, true ) ) {
			++$summary['free_change'];
		}
		if ( ! $is_retired && $free_name && ! $free_explicit ) {
			++$summary['free_name_candidates'];
		}
		if ( ! $is_retired && $free_explicit ) {
			++$summary['explicit_free_evidence'];
		}
		if ( $free_candidate ) {
			if ( $is_retired ) {
				++$summary['retired_free_candidates'];
			} else {
				++$summary['free_candidates'];
			}
		}

		return array( $summary, $this->buildChangeRow( $result, $record, $states, $supported, $free_reviewed, $free_candidate, $free_explicit ) );
	}

	/**
	 * Build one non-promotable change row.
	 *
	 * @since 0.1.6
	 *
	 * @param array<string, mixed>      $result Watch result row.
	 * @param array<string, mixed>|null $record Reviewed record, if any.
	 * @param array<int, string>        $states Result states.
	 * @param bool                      $supported Whether the record is supported.
	 * @param bool                      $free_reviewed Whether the record is reviewed as free.
	 * @param bool                      $free_candidate Whether free evidence exists.
	 * @param bool                      $free_explicit Whether free evidence is explicit.
	 * @return array<string, mixed>
	 */
	private function buildChangeRow( array $result, ?array $record, array $states, bool $supported, bool $free_reviewed, bool $free_candidate, bool $free_explicit ): array {
		$priority = $free_candidate || 'allowlisted' !== ( $result['status'] ?? '' ) ? 'high' : 'normal';
		return array(
			'id'                   => (string) ( $result['id'] ?? '' ),
			'status'               => (string) ( $result['status'] ?? '' ),
			'states'               => array_values( array_unique( $states ) ),
			'allowlisted'          => (bool) ( $result['allowlisted'] ?? false ),
			'registry_candidate'   => null !== $record,
			'supported'            => $supported,
			'free_registry'        => $free_reviewed,
			'free_candidate'       => $free_candidate,
			'free_candidate_basis' => $free_explicit ? 'explicit_public_evidence' : ( $this->looks_free_name( (string) ( $result['id'] ?? '' ) ) ? 'unverified_name' : 'none' ),
			'priority'             => $priority,
			'promotable'           => false,
		);
	}

	/**
	 * Whether a reviewed record has a supported endpoint and verification.
	 *
	 * Delegates to the canonical ModelRegistry vocabulary.
	 *
	 * @param array<string, mixed>|null $record Registry record.
	 * @return bool
	 */
	private function is_supported( ?array $record ): bool {
		return ModelRegistry::isSupported( $record );
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
		return null === $value ? 'null' : $this->safe_text( (string) $value );
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
}
