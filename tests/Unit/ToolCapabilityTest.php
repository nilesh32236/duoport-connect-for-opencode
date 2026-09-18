<?php
/**
 * Tool-capability gating specs for the OpenCode connector.
 *
 * Paid, allowlisted, non-DeepSeek models must advertise function-calling so
 * the Abilities API stops filtering this connector out of tool tasks, while
 * free/DeepSeek/unknown models stay text-only and web search stays
 * fail-closed until its chat/completions payload is gateway-verified.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\ModelAllowlist;

/**
 * Tool-capability gate specs.
 *
 * @package OpenCodeConnector
 */
final class ToolCapabilityTest extends MonkeyTestCase {

	/**
	 * Load the plugin PSR-4 autoloader (SDK-free: ModelAllowlist has no SDK deps).
	 */
	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
	}

	/**
	 * Paid allowlisted models are tool-capable in their own catalog.
	 */
	public function test_paid_allowlisted_models_are_tool_capable(): void {
		self::assertTrue( ModelAllowlist::isToolCapable( 'glm-5', 'go' ) );
		self::assertTrue( ModelAllowlist::isToolCapable( 'kimi-k3', 'go' ) );
		self::assertTrue( ModelAllowlist::isToolCapable( 'mimo-v2.5', 'go' ) );
		self::assertTrue( ModelAllowlist::isToolCapable( 'glm-5', 'zen' ) );
		self::assertTrue( ModelAllowlist::isToolCapable( 'minimax-m3', 'zen' ) );
	}

	/**
	 * Tool capability is per-catalog: a go-only model is not tool-capable via zen.
	 */
	public function test_tool_capability_is_per_catalog(): void {
		self::assertTrue( ModelAllowlist::isToolCapable( 'hy3', 'go' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( 'hy3', 'zen' ) );
	}

	/**
	 * Free models never advertise tools (spend/injection surface stays bounded).
	 */
	public function test_free_models_are_not_tool_capable(): void {
		self::assertFalse( ModelAllowlist::isToolCapable( 'deepseek-v4-flash-free', 'zen' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( 'mimo-v2.5-free', 'zen' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( 'big-pickle', 'zen' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( 'nemotron-3-ultra-free', 'zen' ) );
	}

	/**
	 * DeepSeek models are excluded: malformed strict JSON means tool-call args untrusted.
	 */
	public function test_deepseek_models_are_not_tool_capable(): void {
		self::assertFalse( ModelAllowlist::isToolCapable( 'deepseek-v4-pro', 'go' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( 'deepseek-v4-flash', 'go' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( 'deepseek-v4-pro', 'zen' ) );
	}

	/**
	 * Unknown models stay text-only even though show_all can list them.
	 */
	public function test_unknown_models_are_not_tool_capable(): void {
		self::assertFalse( ModelAllowlist::isToolCapable( 'gpt-4', 'go' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( '', 'go' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( 'glm-5', 'unknown-catalog' ) );
	}

	/**
	 * Web search stays fail-closed for every model until gateway-verified.
	 */
	public function test_web_search_is_fail_closed(): void {
		self::assertFalse( ModelAllowlist::isWebSearchCapable( 'glm-5', 'go' ) );
		self::assertFalse( ModelAllowlist::isWebSearchCapable( 'kimi-k3', 'zen' ) );
		self::assertFalse( ModelAllowlist::isWebSearchCapable( 'deepseek-v4-flash-free', 'zen' ) );
	}

	/**
	 * Metadata directory wires both options through the gates (tools live,
	 * web search gated) and stays credential-blind per the hard rules.
	 */
	public function test_metadata_directory_wiring_is_gated_and_credential_blind(): void {
		$root     = dirname( __DIR__, 2 );
		$dir_src  = (string) file_get_contents( $root . '/src/Metadata/AbstractOpenCodeModelMetadataDirectory.php' );
		$allow_src = (string) file_get_contents( $root . '/src/Metadata/ModelAllowlist.php' );

		self::assertStringContainsString( 'OptionEnum::functionDeclarations()', $dir_src );
		self::assertStringContainsString( 'ModelAllowlist::isToolCapable(', $dir_src );
		self::assertStringContainsString( 'OptionEnum::webSearch()', $dir_src );
		self::assertStringContainsString( 'ModelAllowlist::isWebSearchCapable(', $dir_src );

		self::assertStringContainsString( 'function isToolCapable', $allow_src );
		self::assertStringContainsString( 'function isWebSearchCapable', $allow_src );

		self::assertStringNotContainsString( 'connectors_ai_', $dir_src, 'Metadata must stay credential-blind.' );
		self::assertStringNotContainsString( 'connectors_ai_', $allow_src, 'Allowlist must stay credential-blind.' );
	}
}
