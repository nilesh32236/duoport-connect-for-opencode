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
	 * The queue records a completed justified set and no active work.
	 */
	public function test_queue_records_defensible_stopping_point(): void {
		$queue = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/architecture/refactor-queue.yaml' );
		$scope = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/architecture/PRODUCT-SCOPE.md' );

		self::assertStringContainsString( 'active_item: none', $queue );
		self::assertStringContainsString( 'status: completed', $queue );
		self::assertStringContainsString( 'PF-005', $queue );
		self::assertStringContainsString( 'PF-007', $scope );
		self::assertStringNotContainsString( 'connectors_ai_', $queue );
	}
}
