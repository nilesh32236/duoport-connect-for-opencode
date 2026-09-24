<?php
/**
 * Tests for credential-blind compatibility diagnostics.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

/**
 * Fake provider class used to exercise the isolated diagnostics seam.
 */
final class CompatibilityFakeProvider {
}

final class CompatibilityDiagnosticsTest extends MonkeyTestCase {

	/**
	 * Missing WordPress/SDK surfaces are reported without fatal calls.
	 */
	public function test_missing_sdk_and_wordpress_are_reported(): void {
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array() );
		$status      = $diagnostics->inspect( null, '6.9' );

		self::assertFalse( $status['wordpress_supported'] );
		self::assertFalse( $status['sdk_available'] );
		self::assertContains( 'unsupported-wordpress-version', $status['issues'] );
		self::assertContains( 'missing-ai-client-sdk', $status['issues'] );
	}

	/**
	 * A registry without the required methods fails closed diagnostically.
	 */
	public function test_malformed_registry_is_reported(): void {
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array() );
		$status      = $diagnostics->inspect( new \stdClass(), '7.1.2' );

		self::assertTrue( $status['sdk_available'] );
		self::assertTrue( $status['registry_available'] );
		self::assertContains( 'malformed-ai-client-registry', $status['issues'] );
		self::assertFalse( $status['ok'] );
	}

	/**
	 * A compatible registry and registered providers produce a healthy result.
	 */
	public function test_registered_provider_surface_is_healthy(): void {
		$registry = new class() {
			public function hasProvider( string $class_name ): bool {
				return true;
			}

			public function registerProvider( string $class_name ): void {
			}
		};
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertTrue( $status['ok'] );
		self::assertSame( array( 'hasProvider', 'registerProvider' ), $status['registry_methods'] );
		self::assertTrue( $status['providers'][ CompatibilityFakeProvider::class ]['registered'] );
	}

	/**
	 * The diagnostic source contains no connector-option or credential API.
	 */
	public function test_diagnostics_are_credential_blind(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Compatibility/CompatibilityDiagnostics.php' );
		self::assertStringNotContainsString( 'get_option', $source );
		self::assertStringNotContainsString( 'isProviderConfigured', $source );
		self::assertStringNotContainsString( 'connectors_ai_', $source );
	}
}
