<?php
/**
 * Tests for bounded capability-aware fallback selection.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\CapabilityAwareFallback;

final class CapabilityAwareFallbackTest extends MonkeyTestCase {

	/**
	 * A valid primary model is selected without fallback.
	 */
	public function test_valid_primary_is_selected(): void {
		$result = ( new CapabilityAwareFallback() )->select( 'go', 'glm-5.3', 'text' );

		self::assertSame( 'glm-5.3', $result['selected_id'] );
		self::assertFalse( $result['exhausted'] );
		self::assertSame( 'chat', $result['selected']['endpoint_family'] );
	}

	/**
	 * A same-endpoint capability fallback is selected after a capability mismatch.
	 */
	public function test_same_endpoint_capability_fallback_is_selected(): void {
		$result = ( new CapabilityAwareFallback() )->select( 'go', 'deepseek-v4-pro', 'tools', array( 'glm-5.3' ) );

		self::assertSame( 'glm-5.3', $result['selected_id'] );
		self::assertSame( 'capability_mismatch', $result['rejected'][0]['reason'] );
	}

	/**
	 * Unsupported and unknown primary records never fall back silently.
	 */
	public function test_unsupported_or_unknown_primary_is_denied(): void {
		$fallback    = new CapabilityAwareFallback();
		$unsupported = $fallback->select( 'zen', 'minimax-m3', 'text', array( 'glm-5.2' ) );
		$unknown     = $fallback->select( 'go', 'not-a-model', 'text', array( 'glm-5.3' ) );

		self::assertNull( $unsupported['selected'] );
		self::assertSame( 'unsupported_primary_endpoint', $unsupported['rejected'][0]['reason'] );
		self::assertNull( $unknown['selected'] );
		self::assertSame( 'unknown_primary', $unknown['rejected'][0]['reason'] );
	}

	/**
	 * A candidate with a different endpoint family is rejected.
	 */
	public function test_endpoint_mismatch_candidate_is_rejected(): void {
		$records = array(
			'primary' => array(
				'id'                  => 'primary',
				'catalog'             => 'go',
				'endpoint_family'     => 'chat',
				'verification_status' => 'legacy-verified',
				'capabilities'        => array( 'text' => false ),
			),
			'other'   => array(
				'id'                  => 'other',
				'catalog'             => 'go',
				'endpoint_family'     => 'responses',
				'verification_status' => 'legacy-verified',
				'capabilities'        => array( 'text' => true ),
			),
		);
		$fallback = new CapabilityAwareFallback(
			static fn( string $id, string $catalog ): ?array => $records[ $id ] ?? null
		);
		$result = $fallback->select( 'go', 'primary', 'text', array( 'other' ) );

		self::assertTrue( $result['exhausted'] );
		self::assertNull( $result['selected'] );
		self::assertSame( 'endpoint_mismatch', $result['rejected'][1]['reason'] );
	}

	/**
	 * A record whose identity fields disagree with its candidate is rejected.
	 */
	public function test_record_identity_mismatch_is_rejected(): void {
		$records = array(
			'primary' => array(
				'id'                  => 'primary',
				'catalog'             => 'go',
				'endpoint_family'     => 'chat',
				'verification_status' => 'legacy-verified',
				'capabilities'        => array( 'text' => false ),
			),
			'wrong'   => array(
				'id'                  => 'different',
				'catalog'             => 'go',
				'endpoint_family'     => 'chat',
				'verification_status' => 'legacy-verified',
				'capabilities'        => array( 'text' => true ),
			),
		);
		$fallback = new CapabilityAwareFallback(
			static fn( string $id, string $catalog ): ?array => $records[ $id ] ?? null
		);
		$result = $fallback->select( 'go', 'primary', 'text', array( 'wrong' ) );

		self::assertTrue( $result['exhausted'] );
		self::assertSame( 'record_mismatch', $result['rejected'][1]['reason'] );
	}

	/**
	 * Duplicate candidates are treated as a cycle and do not loop.
	 */
	public function test_duplicate_candidates_are_bounded(): void {
		$result = ( new CapabilityAwareFallback() )->select( 'go', 'deepseek-v4-pro', 'tools', array( 'not-a-model', 'not-a-model', 'glm-5.3' ) );

		self::assertSame( 'glm-5.3', $result['selected_id'] );
		self::assertSame( 'cycle', $result['rejected'][2]['reason'] );
	}

	/**
	 * The production text model invokes the bounded selector before routing.
	 */
	public function test_text_model_wires_bounded_selector_before_routing(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Models/AbstractOpenCodeTextGenerationModel.php' );

		self::assertStringContainsString( 'CapabilityAwareFallback', $source );
		self::assertStringContainsString( "->select(", $source );
		self::assertStringContainsString( 'fallback_model_ids()', $source );
		self::assertLessThan(
			strpos( $source, 'EndpointRoute::pathForModel' ),
			strpos( $source, '->select(' )
		);
	}

	/**
	 * Exhausted and cross-catalog candidates fail safely.
	 */
	public function test_exhausted_and_cross_catalog_candidates_are_denied(): void {
		$fallback = new CapabilityAwareFallback();
		$exhausted = $fallback->select( 'go', 'deepseek-v4-pro', 'tools', array( 'not-a-model' ) );
		$cross     = $fallback->select( 'go', 'deepseek-v4-pro', 'tools', array( 'minimax-m3' ) );

		self::assertTrue( $exhausted['exhausted'] );
		self::assertNull( $exhausted['selected'] );
		self::assertTrue( $cross['exhausted'] );
		self::assertSame( 'unknown_model', $cross['rejected'][1]['reason'] );
	}
}
