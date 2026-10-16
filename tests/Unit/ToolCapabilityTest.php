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
	 * Always reset the web-search test seam so the default-deny gate is the
	 * starting point for every spec.
	 */
	protected function tearDown(): void {
		ModelAllowlist::setWebSearchCapableForTests( null );
		parent::tearDown();
	}

	/**
	 * Paid allowlisted models are tool-capable in their own catalog.
	 */
	public function test_paid_allowlisted_models_are_tool_capable(): void {
		self::assertTrue( ModelAllowlist::isToolCapable( 'glm-5', 'go' ) );
		self::assertTrue( ModelAllowlist::isToolCapable( 'kimi-k3', 'go' ) );
		self::assertTrue( ModelAllowlist::isToolCapable( 'mimo-v2.5', 'go' ) );
		self::assertTrue( ModelAllowlist::isToolCapable( 'glm-5', 'zen' ) );
	}

	/**
	 * Verification-required endpoint records are not tool-capable.
	 *
	 * The Zen pending set is currently empty (its only entry was
	 * retired from the live catalog), so this covers the six Go IDs;
	 * retired Zen IDs are not tool-capable either because they are no
	 * longer allowlisted at all.
	 */
	public function test_pending_verification_routes_are_not_tool_capable(): void {
		foreach ( array( 'glm-5.1', 'glm-5', 'kimi-k2.5', 'mimo-v2-pro', 'mimo-v2-omni', 'hy3-preview' ) as $id ) {
			self::assertFalse( \OpenCodeConnector\Metadata\ModelRegistry::supports( $id, 'go', 'tools' ) );
		}
		self::assertFalse( \OpenCodeConnector\Metadata\ModelRegistry::supports( 'mimo-v2.5-free', 'zen', 'tools' ) );
		self::assertFalse( \OpenCodeConnector\Metadata\ModelRegistry::supports( 'deepseek-v4-flash-free', 'zen', 'tools' ) );
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
		self::assertFalse( ModelAllowlist::isToolCapable( 'nemotron-3-ultra-free', 'zen' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( 'nemotron-3.5-lightning-free', 'zen' ) );
		self::assertFalse( ModelAllowlist::isToolCapable( 'big-pickle', 'zen' ) );
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
	 * Web search is default-deny for every model until gateway-verified.
	 *
	 * The shipped allowlist is empty per catalog, so allowlisted, free, and
	 * unknown-catalog models are all explicitly unsupported and denied.
	 */
	public function test_web_search_is_fail_closed(): void {
		self::assertFalse( ModelAllowlist::isWebSearchCapable( 'glm-5', 'go' ) );
		self::assertFalse( ModelAllowlist::isWebSearchCapable( 'kimi-k3', 'zen' ) );
		self::assertFalse( ModelAllowlist::isWebSearchCapable( 'nemotron-3-ultra-free', 'zen' ) );
		self::assertFalse( ModelAllowlist::isWebSearchCapable( 'glm-5', 'unknown-catalog' ) );
		self::assertFalse( ModelAllowlist::isWebSearchCapable( '', 'go' ) );
	}

	/**
	 * The web-search allowlist is per-catalog default-deny, never shared.
	 *
	 * Mirrors the IMAGE/FREE shape: each catalog denies independently, so
	 * verifying an ID in one catalog can never advertise it in the other.
	 */
	public function test_web_search_allowlist_is_per_catalog_default_deny(): void {
		$shipped = ( new \ReflectionClass( ModelAllowlist::class ) )->getConstant( 'WEB_SEARCH_CAPABLE' );

		self::assertSame( array( 'go' => array(), 'zen' => array() ), $shipped );
	}

	/**
	 * A contract-verified model advertises web search in its own catalog only.
	 *
	 * Gate-on path, via the test seam (no live gateway probe): the verified
	 * ID advertises, while the same ID in the other catalog and every
	 * unverified ID stay denied.
	 */
	public function test_verified_model_advertises_web_search(): void {
		ModelAllowlist::setWebSearchCapableForTests( array( 'go' => array( 'glm-5.3' ), 'zen' => array() ) );
		try {
			self::assertTrue( ModelAllowlist::isWebSearchCapable( 'glm-5.3', 'go' ) );
			self::assertFalse( ModelAllowlist::isWebSearchCapable( 'glm-5.3', 'zen' ) );
			self::assertFalse( ModelAllowlist::isWebSearchCapable( 'kimi-k3', 'go' ) );
			self::assertFalse( ModelAllowlist::isWebSearchCapable( 'kimi-k3', 'zen' ) );
			self::assertFalse( ModelAllowlist::isWebSearchCapable( 'glm-5.3', 'unknown-catalog' ) );
		} finally {
			ModelAllowlist::setWebSearchCapableForTests( null );
		}
	}

	/**
	 * A mis-declared pending-verification model never grounds.
	 *
	 * Even with its ID injected into the web-search list, the unsupported
	 * endpoint family vetoes the capability at the registry, so grounded
	 * generation cannot be attempted on it.
	 */
	public function test_mis_declared_pending_model_never_advertises_web_search(): void {
		ModelAllowlist::setWebSearchCapableForTests( array( 'go' => array( 'glm-5' ), 'zen' => array() ) );
		try {
			self::assertFalse( \OpenCodeConnector\Metadata\ModelRegistry::supports( 'glm-5', 'go', 'web_search' ) );
		} finally {
			ModelAllowlist::setWebSearchCapableForTests( null );
		}
	}

	/**
	 * Metadata directory wires both options through the gates (tools live,
	 * web search gated) and stays credential-blind per the hard rules.
	 */
	public function test_metadata_directory_wiring_is_gated_and_credential_blind(): void {
		$root     = dirname( __DIR__, 2 );
		$dir_src  = (string) file_get_contents( $root . '/src/Metadata/AbstractOpenCodeModelMetadataDirectory.php' );
		$allow_src  = (string) file_get_contents( $root . '/src/Metadata/ModelAllowlist.php' );
		$registry_src = (string) file_get_contents( $root . '/src/Metadata/ModelRegistry.php' );

		self::assertStringContainsString( 'OptionEnum::functionDeclarations()', $dir_src );
		self::assertStringContainsString( "ModelRegistry::supports( \$id, \$this->catalogKey(), 'tools' )", $dir_src );
		self::assertStringContainsString( 'OptionEnum::webSearch()', $dir_src );
		self::assertStringContainsString( "ModelRegistry::supports( \$id, \$this->catalogKey(), 'web_search' )", $dir_src );
		self::assertStringContainsString( 'function supports', $registry_src );

		// The web-search option degrades gracefully on older SDK builds: the
		// enum factories are magic (__callStatic, invisible to
		// method_exists()), so the guard must probe is_callable() and wrap
		// the factory call in try/catch instead of fataling.
		self::assertStringContainsString( 'webSearchOption()', $dir_src );
		self::assertStringContainsString( 'is_callable(', $dir_src );
		self::assertStringNotContainsString( 'method_exists( OptionEnum::class', $dir_src );

		self::assertStringContainsString( 'function isToolCapable', $allow_src );
		self::assertStringContainsString( 'function isWebSearchCapable', $allow_src );

		self::assertStringNotContainsString( 'connectors_ai_', $dir_src, 'Metadata must stay credential-blind.' );
		self::assertStringNotContainsString( 'connectors_ai_', $allow_src, 'Allowlist must stay credential-blind.' );
	}
}
