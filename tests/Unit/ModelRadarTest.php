<?php
/**
 * Tests for the credential-free OpenCode Model Radar.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\CatalogWatch;
use OpenCodeConnector\Metadata\ModelRadar;

final class ModelRadarTest extends MonkeyTestCase {

	/**
	 * The report separates reviewed support from unverified free candidates.
	 */
	public function test_report_separates_reviewed_support_and_free_candidates(): void {
		$report = ( new ModelRadar() )->report(
			array(
				'go' => array(
					array( 'id' => 'glm-5.3' ),
					array( 'id' => 'new-free-model', 'free' => true ),
					array( 'id' => 'name-free-model' ),
				),
				'zen' => array( array( 'id' => 'deepseek-v4-flash' ) ),
			),
			'2026-09-25T00:00:00+00:00'
		);

		self::assertSame( 1, $report['schema_version'] );
		self::assertSame( '2026-09-25T00:00:00+00:00', $report['checked_at'] );
		self::assertSame( 3, $report['catalogs']['go']['summary']['discovered'] );
		self::assertSame( 1, $report['catalogs']['go']['summary']['supported'] );
		self::assertSame( 2, $report['catalogs']['go']['summary']['free_candidates'] );
		self::assertSame( 1, $report['catalogs']['go']['summary']['explicit_free_evidence'] );
		self::assertSame( 1, $report['catalogs']['go']['summary']['free_name_candidates'] );
		self::assertSame( 0, $report['catalogs']['go']['summary']['free_supported'] );
		$by_id = array();
		foreach ( $report['catalogs']['go']['changes'] as $change ) {
			$by_id[ $change['id'] ] = $change;
			self::assertFalse( $change['promotable'] );
		}
		self::assertSame( 'explicit_public_evidence', $by_id['new-free-model']['free_candidate_basis'] );
		self::assertSame( 'unverified_name', $by_id['name-free-model']['free_candidate_basis'] );
	}

	/**
	 * Unsupported reviewed records remain visible without becoming routable.
	 */
	public function test_report_preserves_unsupported_registry_records(): void {
		$report = ( new ModelRadar() )->report(
			array(
				'go'  => array( array( 'id' => 'glm-5.3' ) ),
				'zen' => array( array( 'id' => 'minimax-m3' ) ),
			),
			'2026-09-25T00:00:00+00:00'
		);
		$change = null;
		foreach ( $report['catalogs']['zen']['changes'] as $candidate ) {
			if ( 'minimax-m3' === $candidate['id'] ) {
				$change = $candidate;
				break;
			}
		}

		self::assertIsArray( $change );
		self::assertTrue( $change['registry_candidate'] );
		self::assertFalse( $change['supported'] );
		self::assertSame( 1, $report['catalogs']['zen']['summary']['unsupported'] );
		self::assertSame( 1, $report['catalogs']['zen']['summary']['verification_required'] );
		self::assertFalse( $change['promotable'] );
	}

	/**
	 * Unreachable catalogs are not interpreted as mass retirements.
	 */
	public function test_report_distinguishes_unreachable_catalogs(): void {
		$report = ( new ModelRadar() )->report( array( 'go' => null, 'zen' => array() ) );

		self::assertTrue( $report['catalogs']['go']['unreachable'] );
		self::assertSame( 0, $report['catalogs']['go']['summary']['retired'] );
		self::assertGreaterThan( 0, $report['catalogs']['zen']['summary']['retired'] );
		self::assertSame( 4, $report['catalogs']['zen']['summary']['retired_free_candidates'] );
	}

	/**
	 * Explicit free-status changes are reported as a distinct transition.
	 */
	public function test_free_status_change_is_distinct(): void {
		$results = ( new CatalogWatch() )->compare(
			'go',
			array( array( 'id' => 'glm-5.3', 'free' => true ) )
		);

		$result = null;
		foreach ( $results as $candidate ) {
			if ( 'glm-5.3' === $candidate['id'] ) {
				$result = $candidate;
				break;
			}
		}

		self::assertIsArray( $result );
		self::assertContains( 'free_changed', $result['states'] );
		self::assertContains( 'verification_required', $result['states'] );
		self::assertNotContains( 'metadata_changed', $result['states'] );
	}

	/**
	 * Markdown output is useful for one aggregated issue and contains no credentials.
	 */
	public function test_markdown_is_safe_for_an_aggregated_issue(): void {
		$radar = new ModelRadar();
		$report = $radar->report(
			array(
				'go'  => array( array( 'id' => 'new-free-model', 'free' => true ) ),
				'zen' => null,
			),
			'2026-09-25T00:00:00+00:00'
		);
		$report['metrics']['detection_to_verification'] = '1h';
		$markdown = $radar->markdown( $report );

		self::assertStringContainsString( '# OpenCode Model Radar', $markdown );
		self::assertStringContainsString( 'Free candidates', $markdown );
		self::assertStringContainsString( 'detection_to_verification', $markdown );
		self::assertStringContainsString( 'detection_to_verification`: 1h', $markdown );
		self::assertStringNotContainsString( 'connectors_ai_', $markdown );
		self::assertStringNotContainsString( 'Authorization', $markdown );
	}

	/**
	 * The shipped script exposes both machine-readable and issue-safe modes.
	 */
	public function test_shipped_script_exposes_radar_modes(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/scripts/check-catalog-drift.php' );

		self::assertStringContainsString( 'ModelRadar', $script );
		self::assertStringContainsString( "--json", $script );
		self::assertStringContainsString( "--markdown", $script );
		self::assertStringNotContainsString( 'connectors_ai_', $script );
	}

	/**
	 * The catalog workflow aggregates free-lane findings without competing issues.
	 */
	public function test_workflow_aggregates_radar_findings(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__, 2 ) . '/.github/workflows/catalog-watch.yml' );

		self::assertStringContainsString( '--json', $workflow );
		self::assertStringContainsString( 'model-radar-markdown.php', $workflow );
		self::assertStringContainsString( 'model-radar', $workflow );
		self::assertStringContainsString( 'free_candidates', $workflow );
		self::assertStringContainsString( 'An active campaign issue exists', $workflow );
		self::assertStringContainsString( 'No model is promoted automatically', $workflow );
	}
}
