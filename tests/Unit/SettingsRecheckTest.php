<?php
/**
 * Per-catalog connection reason and recheck specs.
 *
 * The Settings page must show the reason the classifier already produced and
 * let an administrator force a fresh check for one catalog without clearing
 * the other catalog's verdict or either catalog's last-known-good fallback.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Availability\ConnectionDiagnostics;
	use OpenCodeConnector\Availability\OpenCodeProviderAvailability;
	use OpenCodeConnector\Metadata\Catalog;
	use OpenCodeConnector\Settings\ConnectionReport;
	use OpenCodeConnector\Settings\Settings;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;

	/**
	 * Availability double yielding one classified state.
	 */
	final class RecheckAvailabilityDouble {
		/**
		 * State returned by diagnose().
		 *
		 * @var string
		 */
		private string $state;

		/**
		 * Constructor.
		 *
		 * @param string $state Classified state.
		 */
		public function __construct( string $state ) {
			$this->state = $state;
		}

		/**
		 * Return the queued classified state.
		 *
		 * @return array<string, mixed>
		 */
		public function diagnose(): array {
			return array( 'state' => $this->state );
		}
	}

	/**
	 * Transporter double that always throws.
	 */
	final class RecheckTransporter {
		/**
		 * Throw on send().
		 *
		 * @param mixed $request Request (ignored).
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			unset( $request );
			throw new \RuntimeException( 'network down' );
		}
	}

	/**
	 * Authentication double passing requests through untouched.
	 */
	final class RecheckAuthentication {
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
	 * Recheck and connection-reason specs.
	 */
	final class SettingsRecheckTest extends MonkeyTestCase {

		/**
		 * Load the autoloader and constants the render/probe paths need.
		 *
		 * @return void
		 */
		private function boot(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
			if ( ! defined( 'OpenCodeConnector\\OPTION_NAME' ) ) {
				define( 'OpenCodeConnector\\OPTION_NAME', 'opencode_connector_settings' );
			}
			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}
		}

		/**
		 * Route transient operations into one in-memory store.
		 *
		 * @param array<string, mixed> $store   Store by reference.
		 * @param list<string>         $deleted Deleted keys by reference.
		 * @param array<string, mixed> $written Written values by reference.
		 * @return void
		 */
		private function stub_shared_transients( array &$store, array &$deleted, array &$written ): void {
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ): mixed {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store, &$written ): bool {
					unset( $ttl );
					$store[ $key ]   = $value;
					$written[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				static function ( string $key ) use ( &$store, &$deleted ): bool {
					$deleted[] = $key;
					unset( $store[ $key ] );
					return true;
				}
			);
			Functions\when( 'delete_site_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );
		}

		/**
		 * Stub render helpers and allow the handler to redirect in tests.
		 *
		 * @return void
		 */
		private function stub_admin_helpers(): void {
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_admin_referer' )->justReturn( 1 );
			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'esc_html_e' )->justReturn( null );
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_attr' )->returnArg();
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
			Functions\when( 'wp_safe_redirect' )->alias(
				static function (): void {
					throw new \RuntimeException( 'redirect' );
				}
			);
		}

		/**
		 * Invoke the handler and swallow its redirect exit.
		 *
		 * @return void
		 */
		private function call_handler(): void {
			try {
				( new Settings() )->handleRecheck();
				self::fail( 'The accepted handler path must redirect.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'redirect', $exception->getMessage() );
			}
		}

		/**
		 * An unprivileged caller is rejected before any probe or delete.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unprivileged_caller_is_rejected_before_any_probe(): void {
			$this->boot();
			$GLOBALS['__oc_availability_calls'] = 0;
			$GLOBALS['__oc_availability']       = new RecheckAvailabilityDouble( 'verified' );
			$_SERVER['REQUEST_METHOD']          = 'POST';
			$_POST['catalog']                   = Catalog::GO;

			$deleted = array();
			Functions\when( 'current_user_can' )->justReturn( false );
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'wp_die' )->alias(
				static function (): void {
					throw new \RuntimeException( 'wp_die' );
				}
			);
			Functions\when( 'delete_transient' )->alias(
				static function ( string $key ) use ( &$deleted ): bool {
					$deleted[] = $key;
					return true;
				}
			);

			try {
				( new Settings() )->handleRecheck();
				self::fail( 'An unprivileged caller must be stopped.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'wp_die', $exception->getMessage() );
			}

			self::assertSame( 0, $GLOBALS['__oc_availability_calls'] );
			self::assertSame( array(), $deleted );
		}

		/**
		 * A GET cannot trigger a probe even with a valid nonce and capability.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_get_request_is_rejected_before_any_probe(): void {
			$this->boot();
			$GLOBALS['__oc_availability_calls'] = 0;
			$GLOBALS['__oc_availability']       = new RecheckAvailabilityDouble( 'verified' );
			$_SERVER['REQUEST_METHOD']          = 'GET';
			$_POST['catalog']                   = Catalog::GO;

			$deleted = array();
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_admin_referer' )->justReturn( 1 );
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'wp_die' )->alias(
				static function (): void {
					throw new \RuntimeException( 'wp_die' );
				}
			);
			Functions\when( 'delete_transient' )->alias(
				static function ( string $key ) use ( &$deleted ): bool {
					$deleted[] = $key;
					return true;
				}
			);

			try {
				( new Settings() )->handleRecheck();
				self::fail( 'A GET request must not force a probe.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'wp_die', $exception->getMessage() );
			}

			self::assertSame( 0, $GLOBALS['__oc_availability_calls'] );
			self::assertSame( array(), $deleted );
		}

		/**
		 * An unknown catalog is rejected before any probe or delete.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unknown_catalog_is_rejected_before_any_probe(): void {
			$this->boot();
			$GLOBALS['__oc_availability_calls'] = 0;
			$_SERVER['REQUEST_METHOD']          = 'POST';
			$_POST['catalog']                   = 'not-a-catalog';

			$deleted = array();
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_admin_referer' )->justReturn( 1 );
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'wp_die' )->alias(
				static function (): void {
					throw new \RuntimeException( 'wp_die' );
				}
			);
			Functions\when( 'delete_transient' )->alias(
				static function ( string $key ) use ( &$deleted ): bool {
					$deleted[] = $key;
					return true;
				}
			);

			try {
				( new Settings() )->handleRecheck();
				self::fail( 'An unknown catalog must be stopped.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'wp_die', $exception->getMessage() );
			}

			self::assertSame( 0, $GLOBALS['__oc_availability_calls'] );
			self::assertSame( array(), $deleted );
		}

		/**
		 * An accepted recheck clears only the selected catalog's transients.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_accepted_recheck_clears_only_the_selected_catalog(): void {
			$this->boot();
			$GLOBALS['__oc_availability_calls'] = 0;
			$GLOBALS['__oc_availability']       = new RecheckAvailabilityDouble( 'invalid_key' );
			$_SERVER['REQUEST_METHOD']          = 'POST';
			$_POST['catalog']                   = Catalog::GO;

			$store   = array(
				'opencode_connector_avail_zen'            => array( 'state' => 'verified' ),
				'opencode_connector_avail_zen_last_good'  => 1,
				'opencode_connector_avail_go_last_good'   => 1,
			);
			$deleted = array();
			$written = array();
			$this->stub_shared_transients( $store, $deleted, $written );
			$this->stub_admin_helpers();

			$this->call_handler();

			self::assertSame( 1, $GLOBALS['__oc_availability_calls'] );
			foreach ( Catalog::availabilityResultKeys( Catalog::GO ) as $key ) {
				self::assertContains( $key, $deleted, 'The selected catalog verdict must be busted.' );
			}
			foreach ( Catalog::verifyKeys( Catalog::GO ) as $key ) {
				self::assertContains( $key, $deleted, 'The selected catalog verification verdict must be busted.' );
			}
			foreach ( Catalog::availabilityResultKeys( Catalog::ZEN ) as $key ) {
				self::assertNotContains( $key, $deleted, 'The other catalog must be untouched.' );
			}
			foreach ( Catalog::verifyKeys( Catalog::ZEN ) as $key ) {
				self::assertNotContains( $key, $deleted, 'The other catalog must be untouched.' );
			}
			self::assertNotContains( 'opencode_connector_avail_go_last_good', $deleted );
			self::assertArrayHasKey( 'opencode_connector_avail_go_last_good', $store );

			self::assertSame( 'invalid_key', $written[ Catalog::AVAIL_PREFIX . Catalog::GO ]['state'] ?? null );
			self::assertArrayHasKey( 'checked_at', $written[ Catalog::AVAIL_PREFIX . Catalog::GO ] );
			self::assertArrayHasKey( Settings::RECHECK_COOLDOWN_PREFIX . Catalog::GO, $written );
			self::assertArrayHasKey( Settings::LAST_CHECKED_PREFIX . Catalog::GO, $written );
			self::assertArrayNotHasKey( Settings::RECHECK_COOLDOWN_PREFIX . Catalog::ZEN, $written );
		}

		/**
		 * A second recheck inside the cooldown is a no-op.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_recheck_cooldown_is_enforced_per_catalog(): void {
			$this->boot();
			$GLOBALS['__oc_availability_calls'] = 0;
			$GLOBALS['__oc_availability']       = new RecheckAvailabilityDouble( 'verified' );
			$_SERVER['REQUEST_METHOD']          = 'POST';
			$_POST['catalog']                   = Catalog::GO;

			$store   = array();
			$deleted = array();
			$written = array();
			$this->stub_shared_transients( $store, $deleted, $written );
			$this->stub_admin_helpers();

			$this->call_handler();
			$first_deletes = count( $deleted );
			$first_calls   = $GLOBALS['__oc_availability_calls'];

			$this->call_handler();

			self::assertSame( $first_deletes, count( $deleted ), 'A recheck inside the cooldown must not delete caches again.' );
			self::assertSame( $first_calls, $GLOBALS['__oc_availability_calls'], 'A recheck inside the cooldown must not probe again.' );
		}

		/**
		 * Every classifier state renders a distinct plain-language sentence.
		 *
		 * @return void
		 */
		public function test_every_state_has_a_distinct_sentence(): void {
			$this->boot();
			$states    = array(
				'invalid_key',
				'no_credits',
				'rate_limited',
				'free_tier_limit',
				'network_error',
				'server_error',
				'unknown',
				'probe_model_unavailable',
				'verified',
				'not_configured',
			);
			$sentences = array();
			foreach ( $states as $state ) {
				$sentence = ConnectionReport::describe( $state );
				self::assertStringContainsString( $state, $sentence );
				$sentences[ $state ] = $sentence;
			}
			self::assertSame( $states, array_keys( array_unique( $sentences ) ) );
		}

		/**
		 * A failed recheck preserves last-known-good and reports could-not-be-checked.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_failed_recheck_preserves_last_known_good(): void {
			$this->boot();
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST['catalog']          = Catalog::GO;

			$store = array(
				'opencode_connector_avail_go_last_good' => array(
					'v'  => '1',
					'ts' => time(),
				),
			);
			$deleted = array();
			$written = array();
			$this->stub_shared_transients( $store, $deleted, $written );
			$this->stub_admin_helpers();

			$availability = new OpenCodeProviderAvailability( Catalog::GO );
			$availability->setHttpTransporter( new RecheckTransporter() );
			$availability->setRequestAuthentication( new RecheckAuthentication() );
			$GLOBALS['__oc_availability'] = $availability;

			$this->call_handler();

			self::assertSame(
				'uncheckable',
				$written[ Catalog::AVAIL_PREFIX . Catalog::GO ]['state'] ?? null,
				'A transport failure is could-not-be-checked, never a credential verdict.'
			);
			self::assertArrayHasKey(
				'opencode_connector_avail_go_last_good',
				$store,
				'A failed recheck must not clear the 30-day fallback.'
			);
			self::assertNotContains( 'opencode_connector_avail_go_last_good', $deleted );
			self::assertStringContainsString(
				'could not be checked',
				ConnectionReport::describe( 'uncheckable' )
			);
		}

		/**
		 * The rendered report never includes a response body or option value.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_render_does_not_echo_cached_payload_values(): void {
			$this->boot();
			$store = array(
				'opencode_connector_avail_go'  => array(
					'state'      => 'invalid_key',
					'checked_at' => 1700000000,
					'body'       => 'SECRET-RESPONSE-BODY',
				),
				'opencode_connector_avail_zen' => array(
					'state'      => 'verified',
					'checked_at' => 1700000000,
				),
			);

			$deleted = array();
			$written = array();
			$this->stub_shared_transients( $store, $deleted, $written );
			$this->stub_admin_helpers();
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => false ) );
			Functions\when( 'wp_kses_post' )->returnArg();
			Functions\when( 'settings_fields' )->justReturn( null );
			Functions\when( 'wp_nonce_field' )->justReturn( null );
			Functions\when( 'submit_button' )->justReturn( null );
			Functions\when( 'checked' )->justReturn( null );

			ob_start();
			( new Settings() )->render();
			$markup = (string) ob_get_clean();

			self::assertStringContainsString( 'invalid key', $markup );
			self::assertStringContainsString( 'verified', $markup );
			self::assertStringNotContainsString( 'SECRET-RESPONSE-BODY', $markup );
			self::assertStringContainsString( 'Last checked', $markup );
			self::assertStringContainsString( Settings::RECHECK_ACTION, $markup );
		}

		/**
		 * Unknown states are never rendered as a bare not-connected verdict.
		 *
		 * @return void
		 */
		public function test_unknown_and_indeterminate_states_are_could_not_be_checked(): void {
			$this->boot();
			$diagnostics = new ConnectionDiagnostics();
			foreach ( array( 'unknown', 'probe_model_unavailable', 'uncheckable', 'network_error', 'server_error' ) as $state ) {
				self::assertSame(
					'could-not-be-checked',
					$diagnostics->verify_state( array( 'state' => $state ) ),
					$state . ' must resolve through verify_state() to could-not-be-checked.'
				);
				self::assertStringContainsString( 'could not be checked', ConnectionReport::describe( $state ) );
			}
		}
	}
}
