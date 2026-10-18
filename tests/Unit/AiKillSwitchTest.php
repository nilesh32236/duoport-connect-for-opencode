<?php
/**
 * WordPress 7.0 wp_supports_ai() kill-switch specs.
 *
 * When a site disables AI, the plugin must not register its AI providers on
 * init and must not render the not-connected admin nag. When the helper is
 * absent (WordPress below 7.0 or a partial bootstrap) the plugin fails open
 * and behaves exactly as before.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

require_once __DIR__ . '/Fixtures/SdkStubs.php';

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Registry stand-in recording hasProvider()/registerProvider().
 */
final class AiKillSwitchFakeRegistry {

	/**
	 * Classes passed to hasProvider().
	 *
	 * @var string[]
	 */
	public array $checked = array();

	/**
	 * Classes passed to registerProvider().
	 *
	 * @var string[]
	 */
	public array $registered = array();

	/**
	 * Record a hasProvider() probe (always miss so registration would run).
	 *
	 * @param string $class Provider class name.
	 * @return bool
	 */
	public function hasProvider( string $class ): bool {
		$this->checked[] = $class;
		return false;
	}

	/**
	 * Record a registerProvider() call.
	 *
	 * @param string $class Provider class name.
	 * @return void
	 */
	public function registerProvider( string $class ): void {
		$this->registered[] = $class;
	}
}

/**
 * Kill-switch specs (separate processes so the stub never leaks).
 */
final class AiKillSwitchTest extends MonkeyTestCase {

	/**
	 * Plugin root directory.
	 *
	 * @return string
	 */
	private static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Define the global wp_supports_ai() stub with the given verdict.
	 *
	 * @param bool $supported Verdict the stub returns.
	 * @return void
	 */
	private static function boot_kill_switch( bool $supported ): void {
		if ( ! function_exists( 'wp_supports_ai' ) ) {
			eval(
				sprintf(
					'function wp_supports_ai(): bool { return %s; }',
					$supported ? 'true' : 'false'
				)
			);
		}
	}

	/**
	 * Absent helper: the guard fails open (AI treated as supported).
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_ai_supported_fails_open_when_helper_absent(): void {
		require_once self::plugin_root() . '/src/autoload.php';

		self::assertFalse( function_exists( 'wp_supports_ai' ) );
		self::assertTrue( \OpenCodeConnector\Compatibility\CompatibilityDiagnostics::ai_supported() );
	}

	/**
	 * Disabled helper: the guard reports unsupported.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_ai_supported_is_false_when_helper_disables_ai(): void {
		require_once self::plugin_root() . '/src/autoload.php';
		self::boot_kill_switch( false );

		self::assertFalse( \OpenCodeConnector\Compatibility\CompatibilityDiagnostics::ai_supported() );
	}

	/**
	 * Disabled kill switch: providers are never registered on init.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_provider_registration_is_skipped_when_ai_disabled(): void {
		self::boot_kill_switch( false );

		$callbacks  = array();
		$priorities = array();

		Monkey\Actions\expectAdded( 'init' )
			->twice()
			->with(
				\Mockery::on(
					static function ( $callback ) use ( &$callbacks ): bool {
						$callbacks[] = $callback;
						return true;
					}
				),
				\Mockery::on(
					static function ( $priority ) use ( &$priorities ): bool {
						$priorities[] = $priority;
						return true;
					}
				)
			);

		Functions\when( 'plugin_basename' )->justReturn( 'duoport-connect-for-opencode/duoport-connect-for-opencode.php' );

		require_once self::plugin_root() . '/duoport-connect-for-opencode.php';

		$index = array_search( 5, $priorities, true );
		self::assertNotFalse( $index, 'Provider registration must run on init at priority 5.' );

		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			eval( 'namespace WordPress\\AiClient; class AiClient { public static function defaultRegistry(): object { return $GLOBALS["__oc_kill_registry"]; } }' );
		}

		$registry                      = new AiKillSwitchFakeRegistry();
		$GLOBALS['__oc_kill_registry'] = $registry;
		try {
			$callbacks[ $index ]();
		} finally {
			unset( $GLOBALS['__oc_kill_registry'] );
		}

		self::assertSame( array(), $registry->checked, 'No provider should be probed when AI is disabled.' );
		self::assertSame( array(), $registry->registered, 'No provider should be registered when AI is disabled.' );
	}

	/**
	 * Disabled kill switch: the not-connected nag is never rendered.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_not_connected_notice_is_skipped_when_ai_disabled(): void {
		self::boot_kill_switch( false );

		if ( ! class_exists( \AiKillSwitchNoticeRegistry::class ) ) {
			eval( 'class AiKillSwitchNoticeRegistry { public function isProviderConfigured( string $id ): bool { return false; } }' );
		}
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			eval( 'namespace WordPress\\AiClient; class AiClient { public static function defaultRegistry(): object { return new \\AiKillSwitchNoticeRegistry(); } }' );
		}

		$captured = array();
		Monkey\Actions\expectAdded( 'admin_notices' )
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

		require_once self::plugin_root() . '/duoport-connect-for-opencode.php';

		self::assertCount( 2, $captured );

		ob_start();
		foreach ( $captured as $callback ) {
			$callback();
		}
		$output = (string) ob_get_clean();

		self::assertStringNotContainsString( 'is not connected', $output );
	}

	/**
	 * Enabled kill switch: registration still runs (byte-identical behavior).
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_provider_registration_runs_when_ai_enabled(): void {
		self::boot_kill_switch( true );

		$callbacks  = array();
		$priorities = array();

		Monkey\Actions\expectAdded( 'init' )
			->twice()
			->with(
				\Mockery::on(
					static function ( $callback ) use ( &$callbacks ): bool {
						$callbacks[] = $callback;
						return true;
					}
				),
				\Mockery::on(
					static function ( $priority ) use ( &$priorities ): bool {
						$priorities[] = $priority;
						return true;
					}
				)
			);

		Functions\when( 'plugin_basename' )->justReturn( 'duoport-connect-for-opencode/duoport-connect-for-opencode.php' );

		require_once self::plugin_root() . '/duoport-connect-for-opencode.php';

		$index = array_search( 5, $priorities, true );
		self::assertNotFalse( $index );

		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			eval( 'namespace WordPress\\AiClient; class AiClient { public static function defaultRegistry(): object { return $GLOBALS["__oc_kill_registry"]; } }' );
		}

		$registry                      = new AiKillSwitchFakeRegistry();
		$GLOBALS['__oc_kill_registry'] = $registry;
		try {
			$callbacks[ $index ]();
		} finally {
			unset( $GLOBALS['__oc_kill_registry'] );
		}

		self::assertCount( 2, $registry->registered, 'Both providers must register when AI is enabled.' );
	}
}
