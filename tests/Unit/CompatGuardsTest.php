<?php
/**
 * Fail-open compat-guard specs for older-SDK installs.
 *
 * Locks in the core-parity hardening: SDK entry points
 * (AiClient::getCache, AiClient::VERSION, enum factories,
 * capability probes, registry shape) must degrade gracefully
 * instead of fataling with "Call to undefined method".
 *
 * Runtime tests define a minimal AiClient stub (separate
 * processes, so the stub never leaks into other suites) and
 * assert cache busting survives a throwing getCache() and that
 * cache keys fall back to the 0.0.0 version. Source-scan tests
 * lock the provider/metadata guard patterns that cannot run
 * without the full core SDK installed.
 *
 * @package OpenCodeConnector
 * @since 0.1.3
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Compat-guard specs.
 *
 * @package OpenCodeConnector
 * @since 0.1.3
 */
final class CompatGuardsTest extends MonkeyTestCase {

	/**
	 * Plugin autoloader + throwing-AiClient stub for runtime tests.
	 *
	 * Defines a WordPress\AiClient\AiClient stub whose getCache()
	 * throws (corrupted cache / signature mismatch) and which
	 * declares no VERSION constant (oldest SDK shape).
	 *
	 * @return void
	 */
	private function boot_throwing_ai_client_stub(): void {
		require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			eval( 'namespace WordPress\\AiClient; class AiClient { public static function getCache(): object { throw new \\RuntimeException( "corrupted cache" ); } }' );
		}
	}

	/**
	 * Cache busting must survive a throwing AiClient::getCache().
	 *
	 * bustCachesAdd runs on add_option hooks; an exception there
	 * would break settings saves. It must fall back to
	 * transient-only busting.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_bust_caches_survives_throwing_get_cache(): void {
		$this->boot_throwing_ai_client_stub();

		$deleted = array();
		Functions\when( 'delete_transient' )->alias(
			static function ( ...$args ) use ( &$deleted ): bool {
				$deleted[] = $args[0];
				return true;
			}
		);
		Functions\when( 'delete_site_transient' )->alias( static fn(): bool => true );

		$settings = new \OpenCodeConnector\Settings\Settings();
		$settings->bustCachesAdd( 'opencode_connector_settings', array( 'show_all_models' => true ) );

		self::assertContains( 'opencode_connector_avail_go', $deleted );
		self::assertContains( 'opencode_connector_avail_zen', $deleted );
	}

	/**
	 * Cache keys must fall back to 0.0.0 when AiClient::VERSION is undefined.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_model_cache_key_falls_back_without_ai_client_version(): void {
		$this->boot_throwing_ai_client_stub();

		$settings = new \OpenCodeConnector\Settings\Settings();
		$method   = new \ReflectionMethod( $settings, 'modelCacheKey' );

		$key = $method->invoke( $settings, 'Some\\Directory\\Class' );

		self::assertSame( 'ai_client_0.0.0_' . md5( 'Some\\Directory\\Class' ) . '_models', $key );
	}

	/**
	 * clearModelCaches must probe getCache defensively inside try/catch.
	 *
	 * @return void
	 */
	public function test_clear_model_caches_wraps_get_cache_in_try_catch(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Settings/Settings.php' );

		self::assertMatchesRegularExpression(
			'/class_exists\(\s*AiClient::class\s*\)\s*&&\s*method_exists\(\s*AiClient::class,\s*\'getCache\'\s*\)/',
			$source,
			'clearModelCaches must probe AiClient::getCache() with class_exists + method_exists.'
		);
		self::assertMatchesRegularExpression(
			'/try\s*\{\s*\$cache\s*=\s*AiClient::getCache\(\);?\s*\}\s*catch/',
			$source,
			'AiClient::getCache() must be wrapped in try/catch with a null fallback.'
		);
	}

	/**
	 * Capability-name builder must not blind-cast or unguarded-probe.
	 *
	 * @return void
	 */
	public function test_capability_name_builder_is_object_guarded(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Providers/AbstractOpenCodeProvider.php' );

		self::assertStringContainsString(
			'is_object( $capability ) && method_exists( $capability, \'getValue\' )',
			$source,
			'The exception-message builder must guard method_exists() with is_object().'
		);
		self::assertStringContainsString( 'get_class( $capability )', $source );
		self::assertStringContainsString( 'get_debug_type( $capability )', $source );
		self::assertStringNotContainsString(
			': (string) $capability,',
			$source,
			'The builder must not blind-cast non-stringable values to string.'
		);
	}

	/**
	 * Provider metadata must probe enum backing constants and VERSION defensively.
	 *
	 * The factories (ProviderTypeEnum::cloud(), RequestAuthenticationMethod::apiKey())
	 * are magic (__callStatic on AbstractEnum), so method_exists() probing cannot
	 * see them — the guards must probe the backing class constants instead.
	 *
	 * @return void
	 */
	public function test_provider_metadata_guards_enums_and_version(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Providers/AbstractOpenCodeProvider.php' );

		self::assertStringContainsString( 'method_exists( $model, \'getSupportedCapabilities\' )', $source );
		self::assertStringContainsString( 'defined( ProviderTypeEnum::class . \'::CLOUD\' )', $source );
		self::assertStringContainsString( 'defined( RequestAuthenticationMethod::class . \'::API_KEY\' )', $source );
		self::assertStringNotContainsString(
			'method_exists( ProviderTypeEnum::class',
			$source,
			'Enum factories are magic methods invisible to method_exists(); probe the backing constants.'
		);
		self::assertStringContainsString( 'throw new \\RuntimeException(', $source );
		self::assertStringContainsString( 'defined( AiClient::class . \'::VERSION\' )', $source );
		self::assertStringContainsString( ': \'1.0.0\'', $source );
	}

	/**
	 * Silent early returns must log under WP_DEBUG like the catch blocks.
	 *
	 * @return void
	 */
	public function test_registry_early_returns_log_under_wp_debug(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php' );

		self::assertStringContainsString(
			'AiClient::defaultRegistry() unavailable; skipping provider registration.',
			$source
		);
		self::assertStringContainsString(
			'AiClient registry has unexpected shape; skipping provider registration.',
			$source
		);
	}

	/**
	 * Missing ProviderMetadata DTO must fail open via RuntimeException.
	 *
	 * @return void
	 */
	public function test_provider_metadata_guards_missing_dto(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Providers/AbstractOpenCodeProvider.php' );

		self::assertStringContainsString( 'class_exists( ProviderMetadata::class )', $source );
	}

	/**
	 * Registration bootstrap must skip provider classes that cannot autoload.
	 *
	 * @return void
	 */
	public function test_register_bootstrap_skips_missing_provider_class(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php' );

		self::assertStringContainsString( 'class_exists( $cls )', $source );
		self::assertStringContainsString( 'version_compare(', $source );
		self::assertStringContainsString( "function_exists( 'get_bloginfo' )", $source );
		self::assertStringContainsString( "function_exists( 'admin_url' )", $source );
		self::assertStringContainsString( "function_exists( 'plugin_basename' )", $source );
	}

	/**
	 * Unkeyed installs must surface a credential-blind not-connected notice.
	 *
	 * @return void
	 */
	public function test_unkeyed_notice_is_credential_blind_and_guarded(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php' );

		self::assertStringContainsString( 'isProviderConfigured', $source );
		self::assertStringContainsString( 'is-dismissible', $source );
		self::assertStringContainsString( 'is not connected', $source );
		self::assertStringContainsString( "current_user_can( 'manage_options' )", $source );
		self::assertStringNotContainsString( "get_option( 'connectors_ai_", $source );
		self::assertStringNotContainsString( 'get_option( "connectors_ai_', $source );
		self::assertStringNotContainsString( "update_option( 'connectors_ai_", $source );
	}

	/**
	 * Unkeyed install renders a not-connected notice instead of fataling.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_unkeyed_install_renders_not_connected_notice_without_fatal(): void {
		require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		if ( ! class_exists( \UnkeyedNoticeFakeRegistry::class ) ) {
			eval( 'class UnkeyedNoticeFakeRegistry { public function isProviderConfigured( string $id ): bool { return false; } }' );
		}
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			eval( 'namespace WordPress\\AiClient; class AiClient { public static function defaultRegistry(): object { return new \\UnkeyedNoticeFakeRegistry(); } }' );
		}

		$captured = array();
		\Brain\Monkey\Actions\expectAdded( 'admin_notices' )
			->twice()
			->with(
				\Mockery::on(
					static function ( $callback ) use ( &$captured ): bool {
						$captured[] = $callback;
						return true;
					}
				)
			);

		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_bloginfo' )->justReturn( '7.0' );
		Functions\when( 'admin_url' )->alias( static fn( ...$args ): string => 'http://example.test/wp-admin/' . (string) ( $args[0] ?? '' ) );
		Functions\when( 'esc_url' )->alias( static fn( ...$args ): string => (string) ( $args[0] ?? '' ) );
		Functions\when( 'esc_html' )->alias( static fn( ...$args ): string => (string) ( $args[0] ?? '' ) );
		Functions\when( 'esc_html__' )->alias( static fn( ...$args ): string => (string) ( $args[0] ?? '' ) );
		Functions\when( '__' )->alias( static fn( ...$args ): string => (string) ( $args[0] ?? '' ) );
		Functions\when( 'plugin_basename' )->justReturn( 'duoport-connect-for-opencode/duoport-connect-for-opencode.php' );

		require_once dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php';

		self::assertCount( 2, $captured, 'Plugin must register the SDK-missing guard plus the unkeyed not-connected notice.' );

		ob_start();
		foreach ( $captured as $callback ) {
			$callback();
		}
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'is not connected', $output );
	}
}
