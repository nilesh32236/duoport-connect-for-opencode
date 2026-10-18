<?php
/**
 * On-demand "Test connection" button specs for the Settings page.
 *
 * The button lets an administrator force a fresh, nonce- and
 * capability-gated probe and see the classified verdict immediately. These
 * specs pin the security boundaries (reject before any probe), the
 * credential-blind payload, and the rule that a transport error never clears
 * the 30-day last-known-good fallback.
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
	use OpenCodeConnector\Metadata\Catalog;
	use OpenCodeConnector\Settings\Settings;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;

	/**
	 * Availability double yielding a queue of classified states.
	 *
	 * The stub provider's `availability()` delegates to
	 * `$GLOBALS['__oc_availability']`, so one instance serves both catalogs and
	 * the queue differentiates Go's verdict from Zen's.
	 */
	final class TestConnectionAvailabilityDouble {
		/**
		 * Remaining states to yield, in order.
		 *
		 * @var list<string>
		 */
		private array $states;

		/**
		 * Constructor.
		 *
		 * @param string ...$states States to yield.
		 */
		public function __construct( string ...$states ) {
			$this->states = array_values( $states );
		}

		/**
		 * Yield the next classified state.
		 *
		 * @return array<string, mixed>
		 */
		public function diagnose(): array {
			$state = array_shift( $this->states );
			return array( 'state' => $state ?? Settings::UNCHECKED_STATE );
		}
	}

	/**
	 * Transport double replaying one throwable.
	 */
	final class TestConnectionTransporter {
		/**
		 * Constructor.
		 *
		 * @param \Throwable $throwable Throwable to throw on send().
		 */
		public function __construct( private \Throwable $throwable ) {
		}

		/**
		 * Replay the queued throwable.
		 *
		 * @param mixed $request Request (ignored).
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			unset( $request );
			throw $this->throwable;
		}
	}

	/**
	 * Authentication double passing requests through untouched.
	 */
	final class TestConnectionAuthentication {
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
	 * On-demand connection test specs.
	 */
	final class SettingsTestConnectionTest extends MonkeyTestCase {

		/**
		 * Load the autoloader and the constants the probe needs.
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
		 * Stub the translation and admin-output helpers render() touches.
		 *
		 * `submit_button` echoes its first argument so a label reaches the
		 * captured markup; the plain settings save button passes null and emits
		 * nothing.
		 *
		 * @return void
		 */
		private function stub_render_helpers(): void {
			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => false ) );
			Functions\when( 'get_current_user_id' )->justReturn( 7 );
			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'esc_html_e' )->justReturn( null );
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_attr' )->returnArg();
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
			Functions\when( 'wp_kses_post' )->returnArg();
			Functions\when( 'settings_fields' )->justReturn( null );
			Functions\when( 'checked' )->justReturn( null );
			Functions\when( 'submit_button' )->alias(
				static function ( $text = '' ): void {
					echo is_string( $text ) ? $text : '';
				}
			);
		}

		/**
		 * The Settings page renders a Test connection button that is nonce-gated.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_test_button_is_present_and_nonce_gated(): void {
			$this->boot();
			$this->stub_render_helpers();
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\expect( 'wp_nonce_field' )->once()->with( Settings::TEST_ACTION );

			ob_start();
			( new Settings() )->render();
			$markup = (string) ob_get_clean();

			self::assertStringContainsString( 'Test connection', $markup );
			self::assertStringContainsString( 'name="action"', $markup );
			self::assertStringContainsString( Settings::TEST_ACTION, $markup );
			self::assertStringContainsString( 'admin-post.php', $markup );
		}

		/**
		 * An unprivileged caller is rejected before any probe can run.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unprivileged_caller_is_rejected_before_any_probe(): void {
			$this->boot();
			$redirected                          = false;
			$GLOBALS['__oc_availability_calls']  = 0;
			$GLOBALS['__oc_availability']        = new TestConnectionAvailabilityDouble( 'verified', 'verified' );

			Functions\when( 'current_user_can' )->justReturn( false );
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'wp_die' )->alias(
				static function (): void {
					throw new \RuntimeException( 'wp_die' );
				}
			);
			Functions\when( 'check_admin_referer' )->justReturn( 1 );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'wp_safe_redirect' )->alias(
				static function () use ( &$redirected ): void {
					$redirected = true;
				}
			);

			try {
				( new Settings() )->handleTestConnection();
				self::fail( 'wp_die must terminate the request for an unprivileged caller.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'wp_die', $exception->getMessage() );
			}

			self::assertSame( 0, $GLOBALS['__oc_availability_calls'], 'No probe may run for an unprivileged caller.' );
			self::assertFalse( $redirected, 'A rejected request must not redirect.' );
		}

		/**
		 * A missing or invalid nonce is rejected before any probe can run.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_invalid_nonce_is_rejected_before_any_probe(): void {
			$this->boot();
			$redirected                         = false;
			$GLOBALS['__oc_availability_calls'] = 0;
			$GLOBALS['__oc_availability']       = new TestConnectionAvailabilityDouble( 'verified', 'verified' );

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_admin_referer' )->alias(
				static function (): void {
					throw new \RuntimeException( 'nonce' );
				}
			);
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'wp_safe_redirect' )->alias(
				static function () use ( &$redirected ): void {
					$redirected = true;
				}
			);

			try {
				( new Settings() )->handleTestConnection();
				self::fail( 'A failed nonce check must terminate the request.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'nonce', $exception->getMessage() );
			}

			self::assertSame( 0, $GLOBALS['__oc_availability_calls'], 'No probe may run without a valid nonce.' );
			self::assertFalse( $redirected, 'A rejected request must not redirect.' );
		}

		/**
		 * A valid request probes both catalogs and stores only the states.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_valid_request_probes_and_stores_the_classified_verdict(): void {
			$this->boot();
			$GLOBALS['__oc_availability_calls'] = 0;
			$GLOBALS['__oc_availability']       = new TestConnectionAvailabilityDouble( 'verified', 'invalid_key' );

			$stored = array();
			$url    = null;

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_admin_referer' )->justReturn( 1 );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'delete_site_transient' )->justReturn( true );
			Functions\when( 'get_current_user_id' )->justReturn( 7 );
			Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$stored ): bool {
					unset( $ttl );
					$stored[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'wp_safe_redirect' )->alias(
				static function ( $location ) use ( &$url ): void {
					$url = $location;
					throw new \RuntimeException( 'redirect' );
				}
			);

			try {
				( new Settings() )->handleTestConnection();
				self::fail( 'The handler must redirect back to the Settings page.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'redirect', $exception->getMessage() );
			}

			self::assertSame( 2, $GLOBALS['__oc_availability_calls'], 'Both catalogs must be probed.' );

			$key = Settings::TEST_TRANSIENT_PREFIX . '7';
			self::assertArrayHasKey( $key, $stored );
			self::assertSame(
				array(
					'go'  => 'verified',
					'zen' => 'invalid_key',
				),
				$stored[ $key ],
				'The stored payload must be classified states only.'
			);
			self::assertStringContainsString( 'duoport-connect-for-opencode', (string) $url );
		}

		/**
		 * render() surfaces the classified verdict stored by the handler.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_render_shows_the_classified_verdict(): void {
			$this->boot();
			$this->stub_render_helpers();
			Functions\when( 'wp_nonce_field' )->justReturn( null );

			$key     = Settings::TEST_TRANSIENT_PREFIX . '7';
			$deleted = array();
			Functions\when( 'get_transient' )->alias(
				static function ( string $transient ) use ( $key ): mixed {
					return $transient === $key
						? array(
							'go'  => 'verified',
							'zen' => 'invalid_key',
						)
						: false;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				static function ( string $transient ) use ( &$deleted ): bool {
					$deleted[] = $transient;
					return true;
				}
			);

			ob_start();
			( new Settings() )->render();
			$markup = (string) ob_get_clean();

			self::assertStringContainsString( 'Test result', $markup );
			self::assertStringContainsString( 'connected (verified)', $markup );
			self::assertStringContainsString( 'not connected (invalid_key)', $markup );
			self::assertContains( $key, $deleted, 'The verdict is consumed once so it is never stale.' );
		}

		/**
		 * A transport error reports could-not-be-checked and keeps the fallback.
		 *
		 * Exercises the REAL classified path: a throwing transporter routes
		 * through probe() and persistProbeResult(), which must never clear the
		 * last-known-good flag.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_a_transport_error_never_clears_last_known_good(): void {
			$this->boot();

			$flag    = Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX;
			$store   = array(
				$flag => array(
					'v'  => '1',
					'ts' => time(),
				),
			);
			$deleted = array();
			$stored  = array();

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'check_admin_referer' )->justReturn( 1 );
			Functions\when( 'get_current_user_id' )->justReturn( 7 );
			Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
			Functions\when( 'wp_rand' )->justReturn( 0 );
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ): mixed {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store, &$stored ): bool {
					unset( $ttl );
					$store[ $key ]  = $value;
					$stored[ $key ] = $value;
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
			Functions\when( 'wp_safe_redirect' )->alias(
				static function (): void {
					throw new \RuntimeException( 'redirect' );
				}
			);

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter( new TestConnectionTransporter( new \RuntimeException( 'network down' ) ) );
			$availability->setRequestAuthentication( new TestConnectionAuthentication() );

			$GLOBALS['__oc_availability'] = $availability;

			try {
				( new Settings() )->handleTestConnection();
				self::fail( 'The handler must redirect back to the Settings page.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'redirect', $exception->getMessage() );
			}

			$key = Settings::TEST_TRANSIENT_PREFIX . '7';
			self::assertSame(
				'uncheckable',
				$stored[ $key ]['go'] ?? null,
				'A transport error is could-not-be-checked, never a credential verdict.'
			);
			self::assertNotContains(
				$flag,
				$deleted,
				'A transport error must never clear the last-known-good fallback.'
			);
			self::assertArrayHasKey( $flag, $store, 'The fallback survives the on-demand probe.' );
		}

		/**
		 * The handler is credential-blind: no `connectors_ai_*` option read.
		 *
		 * @return void
		 */
		public function test_handler_never_reads_a_connectors_ai_option(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Settings/Settings.php' );

			self::assertStringNotContainsString( "get_option( 'connectors_ai_", $source );
			self::assertStringNotContainsString( 'get_option( "connectors_ai_', $source );
			self::assertStringNotContainsString( "update_option( 'connectors_ai_", $source );
			self::assertStringNotContainsString( "add_option( 'connectors_ai_", $source );
		}
	}
}
