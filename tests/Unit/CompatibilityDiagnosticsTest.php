<?php
/**
 * Tests for credential-blind compatibility diagnostics.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
		$registry = $this->healthy_registry();
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

	/**
	 * Gate fields fall back to null on WordPress under 7.0.
	 */
	public function test_gate_fields_are_null_on_legacy_wordpress(): void {
		$registry = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '6.9' );

		self::assertNull( $status['ai_supported'] );
		self::assertNull( $status['prompt_prevented'] );
		self::assertFalse( $status['wordpress_supported'] );
		self::assertContains( 'unsupported-wordpress-version', $status['issues'] );
	}

	/**
	 * Ungated installs report supported AI with no prevent filter.
	 */
	public function test_ungated_path_reports_supported_and_unprevented(): void {
		Functions\when( 'wp_supports_ai' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		$registry = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertTrue( $status['ai_supported'] );
		self::assertFalse( $status['prompt_prevented'] );
		self::assertTrue( $status['providers'][ CompatibilityFakeProvider::class ]['registered'] );
		self::assertTrue( $status['ok'] );
		self::assertSame( array(), $status['issues'] );
	}

	/**
	 * A prevented gate is reported without flipping the diagnostic verdict.
	 *
	 * A `wp_supports_ai() === false` / registered `wp_ai_client_prevent_prompt`
	 * filter must not read as not-configured: the gate fields record it while
	 * `ok`/`issues` stay driven by the registry/provider surface alone.
	 */
	public function test_gated_path_reports_prevented_without_changing_verdict(): void {
		Functions\when( 'wp_supports_ai' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( 10 );
		$registry = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertFalse( $status['ai_supported'] );
		self::assertTrue( $status['prompt_prevented'] );
		self::assertTrue( $status['providers'][ CompatibilityFakeProvider::class ]['registered'] );
		self::assertTrue( $status['ok'] );
		self::assertSame( array(), $status['issues'] );
	}

	/**
	 * The WP_AI_SUPPORT hard-disable reads as unsupported without WP calls.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_ai_support_constant_hard_disable_reads_unsupported(): void {
		require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		define( 'WP_AI_SUPPORT', false );
		// The constant hard-disable must short-circuit before any WP call.
		Functions\expect( 'wp_supports_ai' )->never();
		Functions\when( 'has_filter' )->justReturn( false );
		$registry = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertFalse( $status['ai_supported'] );
		self::assertFalse( $status['prompt_prevented'] );
		self::assertTrue( $status['ok'] );
	}

	/**
	 * An unavailable wp_supports_ai() leaves ai_supported null at >=7.0.
	 *
	 * `has_filter` is always provided by Brain Monkey's hook shims (returning
	 * false with no filters registered), so only the `wp_supports_ai()` leg of
	 * the legacy fallback is exercised here; the unreadable `has_filter()`
	 * contract is covered by the throwing case below.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_ai_support_is_null_when_function_unavailable(): void {
		require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		$registry    = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertNull( $status['ai_supported'] );
	}

	/**
	 * A falsy-but-not-false WP_AI_SUPPORT mirrors core truthiness.
	 *
	 * Core evaluates `defined( 'WP_AI_SUPPORT' ) && ! WP_AI_SUPPORT`, so a
	 * constant defined to a falsy non-false value must read as unsupported
	 * even when `wp_supports_ai()` is unavailable on a partial bootstrap.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_falsy_ai_support_constant_reports_unsupported(): void {
		require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		define( 'WP_AI_SUPPORT', 0 );
		Functions\when( 'has_filter' )->justReturn( false );
		$registry    = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertFalse( $status['ai_supported'] );
	}

	/**
	 * WP_AI_SUPPORT=true must not short-circuit to false.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_ai_support_constant_true_does_not_short_circuit(): void {
		require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		define( 'WP_AI_SUPPORT', true );
		Functions\when( 'wp_supports_ai' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		$registry    = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertTrue( $status['ai_supported'] );
	}

	/**
	 * A throwing wp_supports_ai() leaves ai_supported null, not false.
	 */
	public function test_unreadable_ai_support_is_null(): void {
		Functions\when( 'wp_supports_ai' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'gate unreadable' );
			}
		);
		Functions\when( 'has_filter' )->justReturn( false );
		$registry    = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertNull( $status['ai_supported'] );
		self::assertFalse( $status['prompt_prevented'] );
	}

	/**
	 * A throwing has_filter() leaves prompt_prevented null, not false.
	 */
	public function test_unreadable_prevent_filter_is_null(): void {
		Functions\when( 'wp_supports_ai' )->justReturn( true );
		Functions\when( 'has_filter' )->alias(
			static function (): int {
				throw new \RuntimeException( 'gate unreadable' );
			}
		);
		$registry    = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertTrue( $status['ai_supported'] );
		self::assertNull( $status['prompt_prevented'] );
	}

	/**
	 * A priority-0 prevent filter still counts as present.
	 *
	 * has_filter() returns the priority as an int; priority 0 is falsy but a
	 * registered filter, so it must read as prevented, not absent.
	 */
	public function test_priority_zero_prevent_filter_is_reported_present(): void {
		Functions\when( 'wp_supports_ai' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( 0 );
		$registry    = $this->healthy_registry();
		$diagnostics = new \OpenCodeConnector\Compatibility\CompatibilityDiagnostics( array( CompatibilityFakeProvider::class ) );
		$status      = $diagnostics->inspect( $registry, '7.1.2' );

		self::assertTrue( $status['ai_supported'] );
		self::assertTrue( $status['prompt_prevented'] );
	}

	/**
	 * Build a registry double with the required method surface.
	 *
	 * @return object
	 */
	private function healthy_registry(): object {
		return new class() {
			public function hasProvider( string $class_name ): bool {
				return true;
			}

			public function registerProvider( string $class_name ): void {
			}
		};
	}
}
