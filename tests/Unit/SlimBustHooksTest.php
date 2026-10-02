<?php
/**
 * Slim transient-bust hook specs for both connector key settings.
 *
 * The plugin must keep busting its availability transients when either
 * `connectors_ai_opencode_go_api_key` or `connectors_ai_opencode_zen_api_key`
 * is added, updated, or deleted, but the hook callbacks must NEVER read or
 * write any `connectors_ai_*` option (option mirroring is what the
 * wordpress.org review flagged).
 *
 * Deletion is in that list on purpose. The last-known-good flag now decides
 * whether an unrecognised response fails open, and that flag lives for 30
 * days. A key removed by `delete_option()` — an uninstalled Connectors
 * feature, a migration, WP-CLI, another plugin — fires neither `update_option_`
 * nor `add_option_`, so without the delete hook the flag outlives the
 * credential it describes and the plugin reports connected for a month.
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
	 * All six key hooks delete both avail transients and touch no connectors_ai_* option.
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
			foreach ( array( 'update_option_', 'add_option_', 'delete_option_' ) as $prefix ) {
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
		// The guard is stated as "never read or write any connectors_ai_* option",
		// so it has to cover every option API that could do that, not just the
		// two that were instrumented when the hook was first written. Mirroring
		// a key through add_option(), delete_option(), or the *_site_option()
		// variants would all be an AGENTS.md hard-rule-2 violation, and each
		// would otherwise pass this test green.
		foreach ( array( 'get_option', 'add_option', 'update_option', 'delete_option', 'get_site_option', 'add_site_option', 'update_site_option', 'delete_site_option' ) as $reader ) {
			Functions\when( $reader )->alias(
				static function ( ...$args ) use ( &$get_calls ): mixed {
					$get_calls[] = $args;
					return 0 === strpos( (string) ( $args[0] ?? '' ), 'connectors_ai_' ) ? '' : false;
				}
			);
		}
		Functions\when( 'delete_transient' )->alias(
			static function ( ...$args ) use ( &$deleted ): bool {
				$deleted[] = $args[0];
				return true;
			}
		);
		Functions\when( 'delete_site_transient' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php';

		self::assertCount(
			6,
			$captured,
			'Plugin must register update/add/delete hooks for both the go and zen key settings.'
		);

		// Hook args: update_option_{option} fires (old, new); add_option_ and
		// delete_option_{option} both fire (option, value).
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
		// The last-known-good flag is what the unrecognised-response fallback
		// reads. If it survives the key's own deletion it reports a working
		// credential for the full 30-day TTL after the key is gone.
		self::assertContains(
			'opencode_connector_avail_go_last_good',
			$deleted,
			'The go last-known-good flag must be deleted when either key changes, including on key deletion.'
		);
		self::assertContains(
			'opencode_connector_avail_zen_last_good',
			$deleted,
			'The zen last-known-good flag must be deleted when either key changes, including on key deletion.'
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
