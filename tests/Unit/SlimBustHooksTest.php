<?php
/**
 * Slim transient-bust hook specs for both connector key settings.
 *
 * The plugin must keep busting its availability transients when either
 * `connectors_ai_opencode_go_api_key` or `connectors_ai_opencode_zen_api_key`
 * is added or updated, but the hook callbacks must NEVER read or write any
 * `connectors_ai_*` option (option mirroring is what the wordpress.org
 * review flagged).
 *
 * @package OpenCodeConnector
 * @since 0.1.2
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Slim bust-hook specs.
 *
 * @package OpenCodeConnector
 * @since 0.1.2
 */
final class SlimBustHooksTest extends MonkeyTestCase {

	/**
	 * All four key hooks delete both avail transients and touch no connectors_ai_* option.
	 *
	 * @since 0.1.2
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_key_hooks_bust_transients_without_touching_connectors_ai_options(): void {
		$captured = array();

		foreach ( array( 'connectors_ai_opencode_go_api_key', 'connectors_ai_opencode_zen_api_key' ) as $setting ) {
			foreach ( array( 'update_option_', 'add_option_' ) as $prefix ) {
				$hook = $prefix . $setting;
				Monkey\Actions\expectAdded( $hook )
					->once()
					->with(
						\Mockery::on(
							static function ( $callback ) use ( &$captured, $hook ): bool {
								$captured[ $hook ] = $callback;
								return true;
							}
						)
					);
			}
		}

		$get_calls    = array();
		$update_calls = array();
		$deleted      = array();

		Functions\when( 'plugin_basename' )->justReturn( 'duoport-connect-for-opencode/duoport-connect-for-opencode.php' );
		Functions\when( 'get_option' )->alias(
			static function ( ...$args ) use ( &$get_calls ): string {
				$get_calls[] = $args;
				return '';
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( ...$args ) use ( &$update_calls ): bool {
				$update_calls[] = $args;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( ...$args ) use ( &$deleted ): bool {
				$deleted[] = $args[0];
				return true;
			}
		);

		require_once dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php';

		self::assertCount(
			4,
			$captured,
			'Plugin must register update/add hooks for both the go and zen key settings.'
		);

		// Hook args: update_option_{option} fires (old, new); add_option_{option} fires (option, value).
		foreach ( $captured as $hook => $callback ) {
			if ( str_starts_with( $hook, 'update_option_' ) ) {
				$callback( 'previous-key', 'fresh-key' );
			} else {
				$callback( substr( $hook, strlen( 'add_option_' ) ), 'fresh-key' );
			}
		}

		self::assertContains(
			'opencode_connector_avail_go',
			$deleted,
			'The go availability transient must be deleted when either key changes.'
		);
		self::assertContains(
			'opencode_connector_avail_zen',
			$deleted,
			'The zen availability transient must be deleted when either key changes.'
		);
		self::assertContains(
			'opencode_connector_avail_go_lock',
			$deleted,
			'The go stampede-lock transient must be deleted when either key changes.'
		);
		self::assertContains(
			'opencode_connector_avail_zen_lock',
			$deleted,
			'The zen stampede-lock transient must be deleted when either key changes.'
		);

		foreach ( array_merge( $get_calls, $update_calls ) as $call ) {
			$option_name = (string) ( $call[0] ?? '' );
			self::assertStringNotContainsString(
				'connectors_ai_',
				$option_name,
				'Bust hooks must never read or write connectors_ai_* options (no mirroring).'
			);
		}
	}
}
