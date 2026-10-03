<?php
/**
 * Transient-bust hook specs for both connector key settings.
 *
 * The plugin must bust its availability transients when either
 * `connectors_ai_opencode_go_api_key` or `connectors_ai_opencode_zen_api_key`
 * is added, updated, or deleted, but the hook callbacks must NEVER read or
 * write any `connectors_ai_*` option (option mirroring is what the
 * wordpress.org review flagged).
 *
 * HOOK SIGNATURES — read from WP core, not from memory.
 *
 * These three hooks do NOT agree on where the option name sits, and getting
 * that wrong is silent: the callback receives an argument that is simply not
 * the option name, fails its own guard, and busts nothing. No error, no
 * notice, no failing test — the plugin just stops invalidating anything.
 * Verified against WordPress 7.1.2, `wp-includes/option.php`:
 *
 *   :1019  do_action( "update_option_{$option}", $old_value, $value, $option );
 *           -- option name is THIRD, and argument one is the OLD CREDENTIAL.
 *   :1176  do_action( "add_option_{$option}",     $option, $value );
 *           -- option name is FIRST.
 *   :1264  do_action( "delete_option_{$option}",  $option );
 *           -- option name is FIRST.
 *
 * An earlier version of this file asserted the opposite of the first of those
 * — a comment claiming WP passed the option name first on all three — and
 * invoked `update_option_` in that fictional order. Both were wrong in the same
 * direction, so they cancelled out: the suite stayed green while the rotation
 * path deleted nothing, and the plugin's bust closure had the same blind spot
 * for the same reason.
 *
 * Two rules keep that from recurring:
 *
 * 1. Each hook is fired into its OWN recorder. A single shared array lets the
 *    `delete_option_` hook satisfy an assertion that was nominally about
 *    `update_option_`, which is precisely how the rotation path stayed green.
 * 2. `update_option_` is invoked in WP's real order, with a credential-shaped
 *    first argument, so a callback that reads argument one is handed the old
 *    key string and fails.
 *
 * The plugin reads connector keys with `get_option()`, never
 * `get_site_option()` — WP core's `_wp_connectors_get_api_key_source()` at
 * `wp-includes/connectors.php:462` is the authority. So these keys are site
 * options on multisite too, and the `*_site_option_` hook trio is deliberately
 * NOT registered: it could never fire for these names.
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
	 * Capture the plugin's six key hooks and stub every option API.
	 *
	 * Returns each hook's registration as `array( $callback, $accepted_args )`.
	 * `$accepted_args` is captured rather than assumed: `add_action()` defaults
	 * it to 1, so a registration that forgets to widen it would hand the
	 * callback only the old credential on `update_option_` and silently bust
	 * nothing. Invoking the callback directly would skip that entirely, which
	 * is the same blind spot this file exists to close.
	 *
	 * `delete_transient` records into `$deleted`, which every caller resets
	 * before each individual hook fires, so one hook's bust can never answer
	 * for another's.
	 *
	 * @param list<string> $deleted Recorder for deleted transient names.
	 * @return array<string, array{0: callable, 1: int}>
	 */
	private function capture_hooks( array &$deleted ): array {
		$captured = array();

		foreach ( array( 'connectors_ai_opencode_go_api_key', 'connectors_ai_opencode_zen_api_key' ) as $setting ) {
			foreach ( array( 'update_option_', 'add_option_', 'delete_option_' ) as $prefix ) {
				$hook = $prefix . $setting;
				// Brain Monkey intercepts by defining a namespaced
				// `add_action_{$hook}( $callback, $priority, $accepted_args )`, so
				// the hook name lives in the FUNCTION name and exactly three
				// argument matchers apply. Over-specifying with a fourth fails to
				// match at all and captures nothing.
				Monkey\Actions\expectAdded( $hook )
					->once()
					->with(
						\Mockery::on(
							static function ( $callback ) use ( &$captured, $hook ): bool {
								$captured[ $hook ] = array( $callback, 1 );
								return true;
							}
						),
						\Mockery::any(),
						\Mockery::on(
							static function ( $accepted_args ) use ( &$captured, $hook ): bool {
								$captured[ $hook ][1] = (int) $accepted_args;
								return true;
							}
						)
					);
			}
		}

		// The guard is stated as "never read or write any connectors_ai_* option",
		// so it has to cover every option API that could do that, not just the two
		// that were instrumented when the hook was first written. Mirroring a key
		// through add_option(), delete_option(), or the *_site_option() variants
		// would all be an AGENTS.md hard-rule-2 violation, and each would otherwise
		// pass this test green.
		Functions\when( 'delete_transient' )->alias(
			static function ( ...$args ) use ( &$deleted ): bool {
				$deleted[] = $args[0];
				return true;
			}
		);
		Functions\when( 'delete_site_transient' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( 'duoport-connect-for-opencode/duoport-connect-for-opencode.php' );

		require_once dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php';

		return $captured;
	}

	/**
	 * Fire one hook with the arguments WP core actually passes it.
	 *
	 * @param string $hook     Full hook name.
	 * @param array{0: callable, 1: int} $entry Captured registration.
	 * @return void
	 */
	private function fire_as_core_does( string $hook, array $entry ): void {
		$callback = $entry[0];
		self::assertIsCallable( $callback, $hook . ' must have been registered with a callable.' );
		$setting = substr( $hook, strpos( $hook, 'connectors_ai_' ) );

		if ( str_starts_with( $hook, 'update_option_' ) ) {
			// option.php:1019 -- ( $old_value, $value, $option ). The first argument
			// is the PREVIOUS KEY, given a shape that contains neither '_go_' nor
			// '_zen_' so a callback reading argument one fails visibly instead of
			// quietly busting the wrong catalog or nothing at all.
			$args = array( 'sk-live-previous-key-value', 'sk-live-rotated-key-value', $setting );
		} elseif ( str_starts_with( $hook, 'add_option_' ) ) {
			// option.php:1176 -- ( $option, $value ).
			$args = array( $setting, 'sk-live-fresh-key-value' );
		} else {
			// option.php:1264 -- ( $option ).
			$args = array( $setting );
		}

		// TRUNCATE to the registered $accepted_args, because that truncation is
		// what actually happens at runtime and it is the mechanism by which a
		// too-narrow registration turns a working hook into a silent no-op.
		// Passing all three regardless would let a dropped `$accepted_args` pass
		// this suite green, which is how the original regression survived.
		$args = array_slice( $args, 0, max( 0, $entry[1] ) );
		$callback( ...$args );
	}

	/**
	 * Every hook is registered, and each busts its own catalog's transients.
	 *
	 * Each hook is fired into a freshly reset recorder, so every assertion below
	 * is about that hook alone. A credential-blindness guard runs afterwards.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_key_hooks_bust_transients_without_touching_connectors_ai_options(): void {
		$deleted  = array();
		$captured = $this->capture_hooks( $deleted );

		self::assertCount(
			6,
			$captured,
			'Plugin must register update/add/delete hooks for both the go and zen key settings.'
		);

		foreach ( $captured as $hook => $callback ) {
			$deleted = array();
			$this->fire_as_core_does( $hook, $callback );

			$catalog = str_contains( $hook, '_zen_' ) ? 'zen' : 'go';

			// The last-known-good flag is what the unrecognised-response fallback
			// reads, and a rotated or removed key describes a credential this flag
			// no longer describes.
			self::assertContains(
				'opencode_connector_avail_' . $catalog . '_last_good',
				$deleted,
				$hook . ' must delete its own catalog\'s last-known-good flag. Fired in isolation, so a sibling hook\'s bust cannot answer this assertion.'
			);
			self::assertContains(
				'opencode_connector_avail_' . $catalog,
				$deleted,
				$hook . ' must delete the cached availability verdict.'
			);
			self::assertContains(
				'opencode_connector_avail_' . $catalog . '_lock',
				$deleted,
				$hook . ' must delete the stampede lock.'
			);
			self::assertContains(
				'opencode_connector_verify_' . $catalog,
				$deleted,
				$hook . ' must delete the cached verification verdict.'
			);
		}

		$option_reads = array();
		foreach ( array( 'get_option', 'add_option', 'update_option', 'delete_option', 'get_site_option', 'add_site_option', 'update_site_option', 'delete_site_option' ) as $api ) {
			Functions\when( $api )->alias(
				static function ( ...$args ) use ( &$option_reads ): mixed {
					$option_reads[] = $args;
					return false;
				}
			);
		}

		// Re-fire now that the option APIs record, so the credential-blindness
		// guard observes the real hooks rather than a re-read of the file.
		foreach ( $captured as $hook => $callback ) {
			$this->fire_as_core_does( $hook, $callback );
		}

		foreach ( $option_reads as $call ) {
			self::assertStringNotContainsString(
				'connectors_ai_',
				(string) ( $call[0] ?? '' ),
				'Bust hooks must never read or write connectors_ai_* options (no mirroring).'
			);
		}
	}

	/**
	 * Rotating a key busts. Regression spec for a silent no-op.
	 *
	 * This is the spec that was missing. The bust closure read the option name
	 * out of argument one, which is correct for `add_option_` and
	 * `delete_option_` and wrong for `update_option_`, where WP core passes the
	 * previous credential first and the option name third. Every rotation
	 * therefore handed the callback an API key string, failed its
	 * `str_contains( ..., '_go_' )` guard, and returned before deleting
	 * anything — so a rotated key kept the PREVIOUS key's last-known-good flag
	 * for its full 30-day window, and `isConfigured()` kept reporting the old
	 * credential's verdict about the new one.
	 *
	 * Sibling-hook isolation is what makes this spec mean something: the
	 * `delete_option_` hook busts the same transients from the same closure, so
	 * any test that pools the two stays green whether or not the update path
	 * works at all.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_rotating_a_key_busts_its_own_catalog(): void {
		$deleted  = array();
		$captured = $this->capture_hooks( $deleted );

		foreach ( array( 'go' => 'connectors_ai_opencode_go_api_key', 'zen' => 'connectors_ai_opencode_zen_api_key' ) as $catalog => $setting ) {
			$hook = 'update_option_' . $setting;
			self::assertArrayHasKey( $hook, $captured, $hook . ' must be registered.' );

			$deleted = array();
			$this->fire_as_core_does( $hook, $captured[ $hook ] );

			self::assertContains(
				'opencode_connector_avail_' . $catalog . '_last_good',
				$deleted,
				'Rotating the ' . $catalog . ' key must invalidate its last-known-good flag. WP passes ( $old_value, $value, $option ) — a callback reading argument one is holding the old credential, not the option name.'
			);
			self::assertNotContains(
				'opencode_connector_avail_' . ( 'go' === $catalog ? 'zen' : 'go' ) . '_last_good',
				$deleted,
				'Rotating one connector key must not destroy the other catalog\'s independent 30-day fail-open fallback.'
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
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_deleting_one_connector_key_preserves_the_other_catalogs_fallback(): void {
		$deleted  = array();
		$captured = $this->capture_hooks( $deleted );

		$hook    = 'delete_option_connectors_ai_opencode_go_api_key';
		$deleted = array();
		$this->fire_as_core_does( $hook, $captured[ $hook ] );

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
		$hook    = 'delete_option_connectors_ai_opencode_zen_api_key';
		$deleted = array();
		$this->fire_as_core_does( $hook, $captured[ $hook ] );

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
			'The Go verification verdict is unrelated to the Zen key.'
		);
	}
}