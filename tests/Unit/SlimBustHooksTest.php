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

		// WP core passes the option name as the FIRST argument on all three:
		// update_option_{$option} fires (option, old, new, option), add_option_
		// fires (option, value), delete_option_ fires (option) alone. The bust is
		// scoped by that first argument, so these stubs must pass the real option
		// name — a placeholder is silently ignored by a callback that reads it,
		// which would make the update/delete paths look covered while busting
		// nothing at all.
		foreach ( $captured as $hook => $callback ) {
			if ( str_starts_with( $hook, 'update_option_' ) ) {
				$setting = substr( $hook, strlen( 'update_option_' ) );
				$callback( $setting, 'previous-key', 'fresh-key', $setting );
			} elseif ( str_starts_with( $hook, 'add_option_' ) ) {
				$callback( substr( $hook, strlen( 'add_option_' ) ), 'fresh-key' );
			} else {
				$callback( substr( $hook, strlen( 'delete_option_' ) ) );
			}
		}

		self::assertContains(
			'opencode_connector_avail_go',
			$deleted,
			'The go availability transient must be deleted when the go key changes.'
		);
		self::assertContains(
			'opencode_connector_avail_zen',
			$deleted,
			'The zen availability transient must be deleted when the zen key changes.'
		);
		self::assertContains(
			'opencode_connector_avail_go_lock',
			$deleted,
			'The go stampede-lock transient must be deleted when the go key changes.'
		);
		self::assertContains(
			'opencode_connector_avail_zen_lock',
			$deleted,
			'The zen stampede-lock transient must be deleted when the zen key changes.'
		);
		// The last-known-good flag is what the unrecognised-response fallback
		// reads. If it survives the key's own deletion it reports a working
		// credential for the full 30-day TTL after the key is gone. Note the
		// scope: THIS key's flag, not both catalogs' — see
		// test_deleting_one_connector_key_preserves_the_other_catalogs_fallback.
		self::assertContains(
			'opencode_connector_avail_go_last_good',
			$deleted,
			'The go last-known-good flag must be deleted when the go key changes, including on key deletion.'
		);
		self::assertContains(
			'opencode_connector_avail_zen_last_good',
			$deleted,
			'The zen last-known-good flag must be deleted when the zen key changes, including on key deletion.'
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

	/**
	 * Deleting one connector's key must not touch the other connector's fallback.
	 *
	 * The two last-known-good flags are independent. Each is a 30-day fail-open
	 * fallback for its own catalog, and `unknown` never re-arms one — so a flag
	 * destroyed by a sibling's key rotation cannot come back until a *keyed*
	 * response arrives. Busting both catalogs on every hook meant rotating the
	 * Go key silently destroyed Zen's fallback, and a Zen gateway already
	 * answering 400/402/403/404 then reported not-configured for as long as the
	 * condition lasted. That is this PR's defect reached by a different route.
	 *
	 * Asserted on the delete hook because that is the trigger this PR added and
	 * the one that fires on a key genuinely going away.
	 *
	 * @since 0.1.2
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_deleting_one_connector_key_preserves_the_other_catalogs_fallback(): void {
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

		foreach ( array( 'get_option', 'add_option', 'update_option', 'delete_option', 'get_site_option', 'add_site_option', 'update_site_option', 'delete_site_option' ) as $api ) {
			Functions\when( $api )->alias(
				static function ( ...$args ): mixed {
					unset( $args );
					return false;
				}
			);
		}

		$deleted = array();
		Functions\when( 'delete_transient' )->alias(
			static function ( ...$args ) use ( &$deleted ): bool {
				$deleted[] = $args[0];
				return true;
			}
		);
		Functions\when( 'delete_site_transient' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php';

		// WP fires delete_option_{$option} with the option name alone.
		( $captured['delete_option_connectors_ai_opencode_go_api_key'] )(
			'connectors_ai_opencode_go_api_key'
		);

		self::assertContains(
			'opencode_connector_avail_go_last_good',
			$deleted,
			'The deleted key’s own last-known-good flag must go: it describes a credential that no longer exists.'
		);
		self::assertNotContains(
			'opencode_connector_avail_zen_last_good',
			$deleted,
			'Rotating the Go key must not destroy Zen’s independent 30-day fail-open fallback.'
		);
		self::assertNotContains(
			'opencode_connector_avail_zen',
			$deleted,
			'Zen’s own cached verdict describes Zen, not the Go key that was deleted.'
		);

		// The verification verdict is per-catalog too, and it is the one family
		// this suite never asserted on the entry-file bust at all. Before the
		// per-catalog refactor a source scan for the literal verify keys was
		// doing that job, and when the refactor derived the keys from the catalog
		// slug that scan was rewritten to assert the deriving property instead.
		// That rewrite is satisfiable by a comment: swapping allKeys() for
		// availabilityKeys() drops both catalogs’ verification verdicts from the
		// delete list and leaves the whole suite green, because the comment above
		// the loop still contains the string the rewritten guard looks for. This
		// is the behavioural half of that guard — the delete list is exercised,
		// not read.
		self::assertContains(
			'opencode_connector_verify_go',
			$deleted,
			'The go verification verdict describes the go credential, so deleting the go key must invalidate it.'
		);
		self::assertContains(
			'opencode_connector_verify_go_lock',
			$deleted,
			'The go verification stampede lock must not outlive the go key it serialises.'
		);
		self::assertNotContains(
			'opencode_connector_verify_zen',
			$deleted,
			'Zen’s verification verdict is unrelated to the Go key.'
		);
		self::assertNotContains(
			'opencode_connector_verify_zen_lock',
			$deleted,
			'Zen’s verification stampede lock is unrelated to the Go key.'
		);

		// Symmetric: the zen hook must not reach into the go catalog.
		$deleted = array();
		( $captured['delete_option_connectors_ai_opencode_zen_api_key'] )(
			'connectors_ai_opencode_zen_api_key'
		);

		self::assertContains(
			'opencode_connector_avail_zen_last_good',
			$deleted,
			'Zen’s own flag must go when the Zen key is deleted.'
		);
		self::assertNotContains(
			'opencode_connector_avail_go_last_good',
			$deleted,
			'Deleting the Zen key must not destroy Go’s independent 30-day fail-open fallback.'
		);
		self::assertContains(
			'opencode_connector_verify_zen',
			$deleted,
			'The zen verification verdict must be invalidated by the zen key.'
		);
		self::assertNotContains(
			'opencode_connector_verify_go',
			$deleted,
			'Deleting the Zen key must not invalidate Go’s verification verdict.'
		);
	}
}
