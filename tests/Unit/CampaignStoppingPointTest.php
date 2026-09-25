<?php
/**
 * Contract for the final campaign stopping point.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

final class CampaignStoppingPointTest extends MonkeyTestCase {

	/**
	 * The campaign queue records exactly one active Model Radar item.
	 */
	public function test_queue_records_active_model_radar_item(): void {
		$queue = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/architecture/refactor-queue.yaml' );
		$scope = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/architecture/PRODUCT-SCOPE.md' );

		self::assertMatchesRegularExpression( '/source_commit: [0-9a-f]{40}/', $queue );
		self::assertStringContainsString( 'active_item: MODEL-001', $queue );
		self::assertStringContainsString( '  - id: MODEL-001', $queue );
		self::assertStringContainsString( '    status: in_progress', $queue );
		self::assertStringContainsString( '    github_issue: 92', $queue );
		self::assertStringContainsString( '    github_pr: 93', $queue );
		self::assertStringContainsString( 'PF-005', $queue );
		self::assertStringContainsString( 'PF-007', $scope );
		self::assertStringNotContainsString( 'connectors_ai_', $queue );
	}
}
