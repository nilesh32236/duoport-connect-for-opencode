<?php
/**
 * Markdown renderer for the model radar report.
 *
 * Display half of the ModelRadar split: owns markdown() and all
 * section/label/text helpers so a schema change and a display change never
 * land in the same file and review.
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
 * Renders a radar report as stable Markdown.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class ModelRadarRenderer {
	/**
	 * Render a stable Markdown view of a report.
	 *
	 * @since 0.1.8
	 *
	 * @param array<string, mixed> $report Report from ModelRadar::report().
	 * @return string
	 */
	public function markdown( array $report ): string {
		$lines = array(
			'# OpenCode Model Radar',
			'',
			'Checked at: `' . $this->safeText( (string) ( $report['checked_at'] ?? '' ) ) . '`',
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
	 * @since 0.1.8
	 *
	 * @param string               $catalog Catalog slug.
	 * @param array<string, mixed> $data Per-catalog report data.
	 * @return list<string>
	 */
	public function catalogSection( string $catalog, array $data ): array {
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
			$lines[] = '- `' . $this->safeText( (string) $change['id'] ) . '` — **' . $this->safeText( (string) ( $change['status'] ?? '' ) ) . '** (' . $this->safeText( $this->changeStatesText( $change ) ) . '); ' . $this->changeLabel( $change );
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
	 * @since 0.1.8
	 *
	 * @param array<string, mixed> $change Change row.
	 * @return string
	 */
	public function changeStatesText( array $change ): string {
		return is_array( $change['states'] ?? null ) ? implode( ', ', $change['states'] ) : '';
	}

	/**
	 * Human label for one change row.
	 *
	 * @since 0.1.8
	 *
	 * @param array<string, mixed> $change Change row.
	 * @return string
	 */
	public function changeLabel( array $change ): string {
		$label = 'retired' === ( $change['status'] ?? '' ) ? 'retired' : ( 'verification_required' === ( $change['status'] ?? '' ) ? 'verification-required' : ( ! empty( $change['supported'] ) ? 'reviewed' : 'registry-candidate' ) );
		if ( ! empty( $change['free_candidate'] ) ) {
			$label .= '; free-candidate';
		}
		return $label;
	}

	/**
	 * Render the Markdown measurement section.
	 *
	 * @since 0.1.8
	 *
	 * @param array<string, mixed> $report Report from ModelRadar::report().
	 * @return list<string>
	 */
	public function measurementSection( array $report ): array {
		return array(
			'## Measurement',
			'',
			'Measurement starts at this implementation. Historical detection, verification, merge, and release timestamps remain null until observed.',
			'',
			'- `measurement_started_at`: `' . $this->safeText( (string) ( $report['metrics']['measurement_started_at'] ?? '' ) ) . '`',
			'- `detection_to_verification`: ' . $this->metricText( $report, 'detection_to_verification' ),
			'- `verification_to_merge`: ' . $this->metricText( $report, 'verification_to_merge' ),
			'- `merge_to_release`: ' . $this->metricText( $report, 'merge_to_release' ),
			'- `total_detection_to_release`: ' . $this->metricText( $report, 'total_detection_to_release' ),
			'',
			'Free-name candidates are observations only; they require endpoint, request, response, capability, and WordPress compatibility verification before promotion.',
		);
	}

	/**
	 * Render one nullable measurement value.
	 *
	 * @since 0.1.8
	 *
	 * @param array<string, mixed> $report Report data.
	 * @param string               $key     Measurement key.
	 * @return string
	 */
	public function metricText( array $report, string $key ): string {
		$value = $report['metrics'][ $key ] ?? null;
		return null === $value ? 'null' : $this->safeText( (string) $value );
	}

	/**
	 * Escape report text used in Markdown output.
	 *
	 * @since 0.1.8
	 *
	 * @param string $text Text value.
	 * @return string
	 */
	public function safeText( string $text ): string {
		return str_replace( array( '`', "\r", "\n" ), array( '', '', ' ' ), $text );
	}
}
