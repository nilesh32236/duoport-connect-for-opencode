<?php
/**
 * Plugin-settings cache-bust scope specs.
 *
 * The plugin's own option holds `show_all_models` and nothing else, so saving,
 * adding or deleting it says nothing about either connector's credential. Its
 * bust hook may therefore refresh stale verdicts, but it must not touch the
 * last-known-good flags: those are per-catalog 30-day fail-open fallbacks, and
 * `unknown` never re-arms one.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Availability\OpenCodeProviderAvailability;
	use OpenCodeConnector\Settings\Settings;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use WordPress\AiClient\Providers\Http\DTO\Response;

	/**
	 * Transporter double replaying one response.
	 *
	 * @package OpenCodeConnector
	 */
	final class SettingsBustTransporter {
		/**
		 * Queued outcome.
		 *
		 * @var mixed
		 */
		private mixed $next;

		/**
		 * Constructor.
		 *
		 * @param mixed $next Response to return.
		 */
		public function __construct( mixed $next ) {
			$this->next = $next;
		}

		/**
		 * Replay the queued response.
		 *
		 * @param mixed $request Request (ignored).
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			unset( $request );
			return $this->next;
		}
	}

	/**
	 * Authentication double passing requests through untouched.
	 *
	 * @package OpenCodeConnector
	 */
	final class SettingsBustAuthentication {
		/**
		 * Return the request unchanged.
		 *
		 * @param mixed $request Request.
		 * @return mixed
		 */
		public function authenticateRequest( mixed $request ): mixed {
			return $request;
		}
	}

	/**
	 * Settings-bust scope specs.
	 *
	 * @package OpenCodeConnector
	 */
	final class SettingsCacheBustTest extends MonkeyTestCase {

		/**
		 * Load the plugin autoloader and the minute constant.
		 *
		 * @return void
		 */
		private function boot(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}
		}

		/**
		 * Route every transient call into one in-memory store.
		 *
		 * A name-level assertion ("this key was deleted") cannot tell the
		 * difference between a bust that respects the fallback and one that
		 * removes it, because the probe reads the flag through the same API.
		 * Sharing the store between the bust and the probe is what makes the
		 * behavioural assertion below possible at all.
		 *
		 * @param array<string, mixed> $store Transient store, by reference.
		 * @return void
		 */
		private function stub_shared_transients( array &$store ): void {
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ): mixed {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store ): bool {
					unset( $ttl );
					$store[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				static function ( string $key ) use ( &$store ): bool {
					$existed = array_key_exists( $key, $store );
					unset( $store[ $key ] );
					return $existed;
				}
			);
			Functions\when( 'delete_site_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );
		}

		/**
		 * A settings save must not delete either catalog's last-known-good flag.
		 *
		 * The delete list used to be Catalog::allTransientKeys(), which is every
		 * key for both catalogs — correct for uninstall.php, and the same
		 * cross-catalog shape the entry-file key bust had. This option describes
		 * no credential, so it invalidates no fallback.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_settings_save_preserves_every_catalogs_last_known_good(): void {
			$this->boot();

			$deleted = array();
			Functions\when( 'delete_transient' )->alias(
				static function ( ...$args ) use ( &$deleted ): bool {
					$deleted[] = $args[0];
					return true;
				}
			);
			Functions\when( 'delete_site_transient' )->justReturn( true );

			$settings = new Settings();

			// All three registration paths, because each one reaches the same
			// helper from a different hook and a fix applied to only one of them
			// would leave the other two destroying the flag.
			$settings->bustCaches( array( 'show_all_models' => false ), array( 'show_all_models' => true ) );
			$settings->bustCachesAdd( 'opencode_connector_settings', array( 'show_all_models' => true ) );
			$settings->bustCachesDelete();

			// Stale verdicts still go: the model list and the opt-in verification
			// verdict are the things a settings change can actually invalidate,
			// and leaving them behind is the stale-verdict bug this helper exists
			// for.
			foreach ( array( 'go', 'zen' ) as $catalog ) {
				self::assertContains(
					'opencode_connector_avail_' . $catalog,
					$deleted,
					"The {$catalog} cached availability verdict must be cleared by a settings change."
				);
				self::assertContains(
					'opencode_connector_avail_' . $catalog . '_lock',
					$deleted,
					"The {$catalog} stampede lock must be cleared by a settings change."
				);
				self::assertContains(
					'opencode_connector_verify_' . $catalog,
					$deleted,
					"The {$catalog} verification verdict must be cleared by a settings change."
				);
				self::assertContains(
					'opencode_connector_verify_' . $catalog . '_lock',
					$deleted,
					"The {$catalog} verification lock must be cleared by a settings change."
				);
			}

			foreach ( array( 'go', 'zen' ) as $catalog ) {
				self::assertNotContains(
					'opencode_connector_avail_' . $catalog . '_last_good',
					$deleted,
					"A settings save describes no credential, so the {$catalog} 30-day fail-open fallback must survive it. Its catalog's key is untouched, and \`unknown\` never re-arms the flag."
				);
			}
		}

		/**
		 * End to end: a settings save must leave an unrecognised response failing open.
		 *
		 * The name-level assertion above says which keys the bust does not
		 * delete. This one says what that means for a site: arm both fallbacks,
		 * save a display setting, then meet a gateway answering something the
		 * plugin has no rule for. Both catalogs must still read as connected.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_settings_save_leaves_an_unrecognised_response_failing_open(): void {
			$this->boot();

			$store = array(
				'opencode_connector_avail_go_last_good'  => 1,
				'opencode_connector_avail_zen_last_good' => 1,
			);
			$this->stub_shared_transients( $store );

			( new Settings() )->bustCaches( array( 'show_all_models' => false ), array( 'show_all_models' => true ) );

			self::assertArrayHasKey(
				'opencode_connector_avail_go_last_good',
				$store,
				'Toggling a display setting must not delete the go fallback the unrecognised-response path reads.'
			);
			self::assertArrayHasKey(
				'opencode_connector_avail_zen_last_good',
				$store,
				'Toggling a display setting must not delete the zen fallback the unrecognised-response path reads.'
			);

			// The verdicts it did clear are gone, so the probe actually re-runs
			// rather than reading a cache the bust left behind.
			self::assertArrayNotHasKey( 'opencode_connector_avail_go', $store );
			self::assertArrayNotHasKey( 'opencode_connector_avail_zen', $store );

			foreach ( array( 'go' => 404, 'zen' => 400 ) as $catalog => $status ) {
				$availability = new OpenCodeProviderAvailability( $catalog );
				$availability->setHttpTransporter( new SettingsBustTransporter( new Response( $status, null ) ) );
				$availability->setRequestAuthentication( new SettingsBustAuthentication() );

				self::assertSame( 'unknown', $availability->diagnose()['state'], $status . ' is unrecognised.' );
				self::assertTrue(
					$availability->isConfigured(),
					"After a settings save, a {$catalog} gateway answering {$status} must still read as connected. The fallback describes a credential the settings screen never touched."
				);
			}
		}

		/**
		 * uninstall.php still wants every key gone, fallbacks included.
		 *
		 * The scoping fix above is the reason Catalog::allTransientKeys() must
		 * keep existing with its current shape. Uninstall removes the plugin and
		 * every credential verdict it left behind, so its delete list must stay
		 * the full set — a fallback surviving uninstall would outlive the plugin
		 * by its full 30 days.
		 *
		 * @return void
		 */
		public function test_uninstall_delete_set_still_covers_every_key(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

			self::assertStringContainsString(
				'allTransientKeys()',
				$source,
				'uninstall must keep deriving its delete list from Catalog::allTransientKeys(), which includes the last-known-good flags.'
			);

			$transients = \OpenCodeConnector\Metadata\Catalog::allTransientKeys();
			foreach ( array( 'go', 'zen' ) as $catalog ) {
				self::assertContains(
					'opencode_connector_avail_' . $catalog . '_last_good',
					$transients,
					'Uninstall must still remove the ' . $catalog . ' fallback.'
				);
			}
		}
	}
}