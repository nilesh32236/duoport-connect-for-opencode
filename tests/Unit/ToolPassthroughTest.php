<?php
/**
 * Tool-use passthrough specs for the OpenCode text models.
 *
 * Covers prepareToolsParam() gating: tool-capable models map function
 * declarations to the OpenAI-compatible tools wire shape, while free,
 * DeepSeek, unknown, or unresolvable models strip tool params and degrade
 * to plain-text completion (fail-open, never fatal).
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Models\AbstractOpenCodeTextGenerationModel;
use OpenCodeConnector\Providers\OpenCodeGoProvider;
use OpenCodeConnector\Providers\OpenCodeZenProvider;

require_once __DIR__ . '/Fixtures/SdkStubs.php';
require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

/**
 * Minimal metadata stand-in exposing getId().
 */
final class ToolPassthroughFakeMetadata {
	private string $id;
	public function __construct( string $id ) {
		$this->id = $id;
	}
	public function getId(): string {
		return $this->id;
	}
}

/**
 * Minimal function declaration stand-in exposing toArray().
 */
final class ToolPassthroughFakeDeclaration {
	private array $data;
	public function __construct( array $data ) {
		$this->data = $data;
	}
	public function toArray(): array {
		return $this->data;
	}
}

/**
 * Declaration whose toArray() throws (must be skipped, never fatal).
 */
final class ToolPassthroughThrowingDeclaration {
	public function toArray(): array {
		throw new \RuntimeException( 'boom' );
	}
}

/**
 * Test double routing through a chosen catalog with a fixed model ID.
 */
final class ToolPassthroughTestModel extends AbstractOpenCodeTextGenerationModel {
	private string $model_id;
	private string $catalog;
	public function __construct( string $model_id, string $catalog ) {
		$this->model_id = $model_id;
		$this->catalog  = $catalog;
	}
	protected function providerClass(): string {
		return 'zen' === $this->catalog ? OpenCodeZenProvider::class : OpenCodeGoProvider::class;
	}
	public function metadata(): object {
		return new ToolPassthroughFakeMetadata( $this->model_id );
	}
	public function expose_prepare_tools( array $declarations ): array {
		return $this->prepareToolsParam( $declarations );
	}
}

/**
 * Tool-use passthrough specs.
 */
final class ToolPassthroughTest extends MonkeyTestCase {

	/**
	 * Tool-capable models map declarations to the tools wire shape.
	 */
	public function test_tool_capable_model_passes_through(): void {
		$model = new ToolPassthroughTestModel( 'glm-5', 'go' );
		$decls = array(
			new ToolPassthroughFakeDeclaration(
				array(
					'name'        => 'get_weather',
					'description' => 'Get weather.',
					'parameters'  => array( 'type' => 'object' ),
				)
			),
		);

		$result = $model->expose_prepare_tools( $decls );

		self::assertCount( 1, $result );
		self::assertSame( 'function', $result[0]['type'] );
		self::assertSame( 'get_weather', $result[0]['function']['name'] );
	}

	/**
	 * Zen tool-capable models pass through as well (per-catalog gate).
	 */
	public function test_zen_tool_capable_model_passes_through(): void {
		$model  = new ToolPassthroughTestModel( 'minimax-m3', 'zen' );
		$decls  = array( new ToolPassthroughFakeDeclaration( array( 'name' => 'do_thing' ) ) );
		$result = $model->expose_prepare_tools( $decls );

		self::assertCount( 1, $result );
		self::assertSame( 'function', $result[0]['type'] );
	}

	/**
	 * Unsupported models strip tool params (plain-text fallback).
	 */
	public function test_unsupported_models_strip_tools(): void {
		$decls = array( new ToolPassthroughFakeDeclaration( array( 'name' => 'do_thing' ) ) );

		self::assertSame( array(), ( new ToolPassthroughTestModel( 'deepseek-v4-pro', 'go' ) )->expose_prepare_tools( $decls ) );
		self::assertSame( array(), ( new ToolPassthroughTestModel( 'deepseek-v4-flash-free', 'zen' ) )->expose_prepare_tools( $decls ) );
		self::assertSame( array(), ( new ToolPassthroughTestModel( 'no-such-model', 'go' ) )->expose_prepare_tools( $decls ) );
		self::assertSame( array(), ( new ToolPassthroughTestModel( '', 'go' ) )->expose_prepare_tools( $decls ) );
		self::assertSame( array(), ( new ToolPassthroughTestModel( 'gpt-4', 'zen' ) )->expose_prepare_tools( $decls ) );
	}

	/**
	 * Cross-catalog IDs stay gated (go-only model via zen strips tools).
	 */
	public function test_cross_catalog_strips_tools(): void {
		$decls  = array( new ToolPassthroughFakeDeclaration( array( 'name' => 'do_thing' ) ) );
		$result = ( new ToolPassthroughTestModel( 'hy3', 'zen' ) )->expose_prepare_tools( $decls );

		self::assertSame( array(), $result );
	}

	/**
	 * Unmappable items are skipped, never fatal.
	 */
	public function test_unmappable_declarations_are_skipped(): void {
		$model = new ToolPassthroughTestModel( 'glm-5', 'go' );
		$decls = array(
			new ToolPassthroughFakeDeclaration( array( 'name' => 'ok' ) ),
			'not-an-object',
			new \stdClass(),
			new ToolPassthroughThrowingDeclaration(),
		);

		$result = $model->expose_prepare_tools( $decls );

		self::assertCount( 1, $result );
		self::assertSame( 'ok', $result[0]['function']['name'] );
	}

	/**
	 * Empty declarations stay empty (plain-text completion).
	 */
	public function test_empty_declarations_return_empty(): void {
		$model = new ToolPassthroughTestModel( 'glm-5', 'go' );

		self::assertSame( array(), $model->expose_prepare_tools( array() ) );
	}
}
