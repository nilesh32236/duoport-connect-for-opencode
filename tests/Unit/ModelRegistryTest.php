<?php
/**
 * Tests for the curated capability-aware model registry.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\ModelRegistry;

final class ModelRegistryTest extends MonkeyTestCase {

	/**
	 * Curated records expose provenance and conservative capabilities.
	 */
	public function test_record_contains_reviewed_registry_fields(): void {
		$record = ModelRegistry::record( 'glm-5.3', 'go' );

		self::assertIsArray( $record );
		self::assertSame( 'glm-5.3', $record['id'] );
		self::assertSame( 'go', $record['catalog'] );
		self::assertSame( 'chat', $record['endpoint_family'] );
		self::assertSame( 'legacy-verified', $record['verification_status'] );
		self::assertSame( '2026-09-24', $record['last_verified'] );
		self::assertTrue( $record['capabilities']['text'] );
		self::assertFalse( $record['capabilities']['image'] );
		self::assertFalse( $record['capabilities']['web_search'] );
	}

	/**
	 * Unimplemented Zen endpoint families are not mislabeled as chat.
	 */
	public function test_unimplemented_zen_routes_are_marked_unsupported(): void {
		foreach ( array( 'minimax-m3', 'minimax-m2.7', 'minimax-m2.5' ) as $id ) {
			$record = ModelRegistry::record( $id, 'zen' );
			self::assertIsArray( $record );
			self::assertSame( 'unsupported', $record['endpoint_family'] );
			self::assertSame( 'needs-adapter', $record['verification_status'] );
			self::assertFalse( $record['capabilities']['text'] );
			self::assertFalse( $record['capabilities']['tools'] );
		}
	}

	/**
	 * Unknown IDs and unsupported capabilities are default-deny.
	 */
	public function test_unknown_models_and_capabilities_are_denied(): void {
		self::assertNull( ModelRegistry::record( 'unreviewed-model', 'go' ) );
		self::assertFalse( ModelRegistry::supports( 'unreviewed-model', 'go', 'tools' ) );
		self::assertFalse( ModelRegistry::supports( 'deepseek-v4-pro', 'go', 'tools' ) );
		self::assertFalse( ModelRegistry::supports( 'glm-5.3', 'go', 'unknown-capability' ) );
	}

	/**
	 * Catalog records are separated and free labels are explicit.
	 */
	public function test_catalogs_and_free_labels_are_explicit(): void {
		$go_records   = ModelRegistry::records( 'go' );
		$zen_records  = ModelRegistry::records( 'zen' );
		$zen_free     = ModelRegistry::record( 'deepseek-v4-flash-free', 'zen' );

		self::assertNotEmpty( $go_records );
		self::assertNotEmpty( $zen_records );
		self::assertIsArray( $zen_free );
		self::assertTrue( $zen_free['free'] );
		self::assertSame( 'zen', $zen_free['catalog'] );
		self::assertFalse( \OpenCodeConnector\Metadata\ModelAllowlist::isFree( 'hy3-free' ) );
		self::assertFalse( \OpenCodeConnector\Metadata\ModelAllowlist::isFree( 'laguna-s-2.1-free' ) );
	}

	/**
	 * The published deny table names exactly the known non-chat families.
	 *
	 * @param string $family   Endpoint family under test.
	 * @param bool   $expected Whether the family should be reported denied.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unsupportedFamilyProvider' )]
	public function test_is_unsupported_family_covers_the_published_table( string $family, bool $expected ): void {
		self::assertSame( $expected, ModelRegistry::isUnsupportedFamily( $family ) );
	}

	/**
	 * Deny-table fixtures.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function unsupportedFamilyProvider(): array {
		return array(
			'responses'         => array( 'responses', true ),
			'messages'          => array( 'messages', true ),
			'systemone'         => array( 'systemone', true ),
			'provider-specific' => array( 'provider-specific', true ),
			'chat'              => array( 'chat', false ),
			'unsupported'       => array( 'unsupported', false ),
			'unknown'           => array( 'some-future-family', false ),
			'empty'             => array( '', false ),
		);
	}

	/**
	 * A model id that merely looks like a family name is still chat-routed.
	 *
	 * Guards against comparing a model id against the family deny table.
	 */
	public function test_model_id_matching_a_family_name_is_not_treated_as_a_family(): void {
		$record = ModelRegistry::record( 'responses', 'go' );

		self::assertNull( $record, 'A family name is not an allowlisted model id.' );
		self::assertFalse( ModelRegistry::isUnsupportedFamily( 'responses' ) === false );
	}

	/**
	 * The implemented endpoint family has exactly one owner.
	 *
	 * Three classes used to encode `chat` independently: the registry assigned
	 * it, the fallback selector admitted only it, and the router mapped it to a
	 * path. They are private constants, so a change to one could drift from the
	 * others with no test failing — and the divergence would either route a
	 * model the selector rejects or admit a family with no transport.
	 *
	 * @since 0.1.8
	 */
	public function test_implemented_endpoint_family_is_owned_in_one_place(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array(
			'src/Metadata/ModelRegistry.php'          => array( 'use' => "const IMPLEMENTED_FAMILY = 'chat';", 'banned' => array( "const ENDPOINT_FAMILY = 'chat'" ) ),
			'src/Metadata/CapabilityAwareFallback.php' => array( 'use' => 'ModelRegistry::isImplementedFamily(', 'banned' => array( "const IMPLEMENTED_ENDPOINT = 'chat'" ) ),
			'src/Transport/EndpointRoute.php'          => array( 'use' => 'ModelRegistry::IMPLEMENTED_FAMILY =>', 'banned' => array( "'chat' => 'chat/completions'" ) ),
		);

		self::assertSame( 'chat', ModelRegistry::IMPLEMENTED_FAMILY, 'The registry owns the implemented family name.' );
		self::assertTrue( ModelRegistry::isImplementedFamily( 'chat' ) );
		self::assertFalse( ModelRegistry::isImplementedFamily( 'responses' ) );
		self::assertFalse( ModelRegistry::isImplementedFamily( ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED ) );

		foreach ( $files as $file => $expect ) {
			$source = (string) file_get_contents( $root . '/' . $file );

			self::assertStringContainsString(
				$expect['use'],
				$source,
				$file . ' must derive the implemented family from ModelRegistry.'
			);
			foreach ( $expect['banned'] as $literal ) {
				self::assertStringNotContainsString(
					$literal,
					$source,
					$file . ' must not encode the implemented family a second time.'
				);
			}
		}
	}

	/**
	 * The Zen deny list must key off the shared catalog slug.
	 *
	 * The literal bypasses Catalog, so a slug rename would leave these models
	 * routed through the implemented chat family instead of being denied.
	 *
	 * @since 0.1.8
	 */
	public function test_unsupported_zen_list_is_keyed_by_the_catalog_constant(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Metadata/ModelRegistry.php' );

		self::assertStringContainsString(
			'Catalog::ZEN === $catalog && in_array( $id, self::UNSUPPORTED_ZEN_MODELS, true )',
			$source,
			'The Zen deny list must compare against Catalog::ZEN.'
		);
		self::assertStringNotContainsString(
			"'zen' === \$catalog",
			$source,
			"A bare 'zen' literal bypasses the single catalog slug owner."
		);
	}

	/**
	 * The route and verification guards must read the shared constant.
	 *
	 * These two comparisons decide whether a model is routed or denied, and
	 * EndpointRoute.php already compares against
	 * ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED. A bare 'unsupported' literal
	 * here would keep matching the old value if the constant ever changed, so
	 * a model whose family is not implemented would be routed anyway and the
	 * fail-closed guarantee this class documents would silently disappear.
	 */
	public function test_unsupported_family_guards_use_the_shared_constant(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Metadata/ModelRegistry.php' );

		self::assertStringContainsString(
			'self::ENDPOINT_FAMILY_UNSUPPORTED !== $endpoint_family',
			$source,
			'The route guard must compare against ENDPOINT_FAMILY_UNSUPPORTED.'
		);
		self::assertStringContainsString(
			'self::ENDPOINT_FAMILY_UNSUPPORTED === $endpoint_family',
			$source,
			'The verification-status guard must compare against ENDPOINT_FAMILY_UNSUPPORTED.'
		);
		self::assertStringNotContainsString(
			"'unsupported' !== \$endpoint_family",
			$source,
			'A bare literal in the route guard goes stale the moment the constant changes.'
		);
		self::assertStringNotContainsString(
			"'unsupported' === \$endpoint_family",
			$source,
			'A bare literal in the verification guard goes stale the moment the constant changes.'
		);
	}
}
