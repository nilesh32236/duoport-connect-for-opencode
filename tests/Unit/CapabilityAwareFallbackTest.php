<?php
/**
 * Tests for bounded capability-aware fallback selection.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\CapabilityAwareFallback;
use OpenCodeConnector\Models\AbstractOpenCodeTextGenerationModel;
use OpenCodeConnector\Providers\OpenCodeGoProvider;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

require_once __DIR__ . '/Fixtures/SdkStubs.php';
require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

final class FallbackPayloadModel extends AbstractOpenCodeTextGenerationModel {
	/**
	 * Provider class.
	 *
	 * @return string
	 */
	protected function providerClass(): string {
		return OpenCodeGoProvider::class;
	}

	/**
	 * Primary model.
	 *
	 * @return string
	 */
	protected function route_model_id(): string {
		return 'deepseek-v4-pro';
	}

	/**
	 * Reviewed fallback.
	 *
	 * @return array<int, string>
	 */
	protected function fallback_model_ids(): array {
		return array( 'glm-5.3' );
	}

	/**
	 * Metadata accessor used by the tool preparation resolver.
	 *
	 * @return FallbackMetadata
	 */
	public function getModel(): FallbackMetadata {
		return new FallbackMetadata( 'deepseek-v4-pro' );
	}

	/**
	 * Build a request for the test.
	 *
	 * @param array $data Request data.
	 * @return Request
	 */
	public function make_request( array $data ): Request {
		return $this->createRequest( HttpMethodEnum::POST(), 'chat/completions', array(), $data );
	}

	/**
	 * Prepare tools for the test.
	 *
	 * @param array $declarations Function declarations.
	 * @return array
	 */
	public function prepare_tools( array $declarations ): array {
		return $this->prepareToolsParam( $declarations ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	}
}

final class FallbackMetadata {
	/**
	 * Model ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Construct metadata.
	 *
	 * @param string $id Model ID.
	 */
	public function __construct( string $id ) {
		$this->id = $id;
	}

	/**
	 * Return the model ID.
	 *
	 * @return string
	 */
	public function getId(): string {
		return $this->id;
	}
}

final class FallbackToolDeclaration {
	/**
	 * Return a wire-shaped declaration.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array( 'name' => 'lookup', 'parameters' => array( 'type' => 'object' ) );
	}
}

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
	 * A tool request rewrites the payload model to the reviewed fallback.
	 */
	public function test_tool_request_rewrites_payload_to_fallback(): void {
		$request = ( new FallbackPayloadModel() )->make_request(
			array(
				'model' => 'deepseek-v4-pro',
				'tools' => array( array( 'type' => 'function' ) ),
			)
		);
		$data = $request->getData();

		self::assertIsArray( $data );
		self::assertSame( 'glm-5.3', $data['model'] );
	}

	/**
	 * Tool preparation selects the fallback before SDK request construction.
	 */
	public function test_tool_preparation_selects_fallback_before_request(): void {
		$model = new FallbackPayloadModel();
		$tools = $model->prepare_tools( array( new FallbackToolDeclaration() ) );
		$request = $model->make_request(
			array(
				'model' => 'deepseek-v4-pro',
				'tools' => $tools,
			)
		);
		$data = $request->getData();

		self::assertCount( 1, $tools );
		self::assertIsArray( $data );
		self::assertSame( 'glm-5.3', $data['model'] );
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
