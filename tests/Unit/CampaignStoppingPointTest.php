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
	 * The campaign queue records one active listing item and the completed Model Radar.
	 */
	public function test_queue_records_active_listing_item(): void {
		$queue = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/architecture/refactor-queue.yaml' );
		$scope = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/architecture/PRODUCT-SCOPE.md' );

		self::assertMatchesRegularExpression( '/source_commit: [0-9a-f]{40}/', $queue );
		self::assertStringContainsString( 'active_item: GROWTH-001', $queue );
		self::assertStringContainsString( '  - id: GROWTH-001', $queue );
		self::assertStringContainsString( '    status: in_progress', $queue );
		self::assertStringContainsString( '    github_issue: 95', $queue );
		self::assertStringContainsString( '    github_pr: pending', $queue );
		self::assertStringContainsString( '  - id: MODEL-001', $queue );
		self::assertStringContainsString( '    github_issue: 92', $queue );
		self::assertStringContainsString( '    github_pr: 94', $queue );
		self::assertStringContainsString( 'GROWTH-002', $queue );
		self::assertStringContainsString( 'PF-007', $scope );
		self::assertStringNotContainsString( 'connectors_ai_', $queue );
	}
}
