<?php
/**
 * Explicit one-token verification probe specs.
 *
 * Locks the verify() contract: minimal one-token chat/completions request,
 * five-minute verdict caching with invalidation on key change, three
 * distinct states (valid, invalid_key, could-not-be-checked), and no key
 * material in transients or logs. Credential-blind: never reads or writes
 * any connectors_ai_* option value.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Availability\ConnectionDiagnostics;
	use OpenCodeConnector\Availability\OpenCodeProviderAvailability;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use WordPress\AiClient\Providers\Http\DTO\Response;

	/**
	 * Capturing transporter double for verify() request-shape specs.
	 *
	 * @since 0.1.6
	 */
	final class VerifyCapturingTransporter {
		/**
		 * Queued outcome.
		 *
		 * @var mixed
		 */
		private mixed $next;

		/**
		 * Captured request.
		 *
		 * @var mixed
		 */
		public mixed $seen = null;

		/**
		 * Send count.
		 *
		 * @var int
		 */
		public int $calls = 0;

		/**
		 * Constructor.
		 *
		 * @param mixed $next Response to return or throwable to throw.
		 */
		public function __construct( mixed $next ) {
			$this->next = $next;
		}

		/**
		 * Capture the request then replay the queued outcome.
		 *
		 * @param mixed $request Request.
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			$this->seen = $request;
			++$this->calls;
			if ( $this->next instanceof \Throwable ) {
				throw $this->next;
			}
			return $this->next;
		}
	}

	/**
	 * Pass-through authentication double.
	 *
	 * @since 0.1.6
	 */
	final class VerifyPassthroughAuthentication {
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
	 * One-token verification probe specs.
	 *
	 * @package OpenCodeConnector
	 * @since 0.1.6
	 */
	final class VerifyProbeTest extends MonkeyTestCase {

		/**
		 * Boot the plugin autoloader and the minute constant.
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
		 * Stub transient functions with no persisted state.
		 *
		 * @param array $ttls Captured TTLs by key.
		 * @return void
		 */
		private function stub_transients_stateless( array &$ttls ): void {
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$ttls ): bool {
					$ttls[ $key ] = $ttl;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ): int {
					unset( $min, $max );
					return 0;
				}
			);
		}

		/**
		 * Build a verify-ready availability instance.
		 *
		 * @param mixed  $outcome Queued transporter outcome.
		 * @param string $catalog Catalog slug.
		 * @return array{0:OpenCodeProviderAvailability,1:VerifyCapturingTransporter}
		 */
		private function make_availability( mixed $outcome, string $catalog = 'go' ): array {
			$availability = new OpenCodeProviderAvailability( $catalog );
			$transporter  = new VerifyCapturingTransporter( $outcome );
			$availability->setHttpTransporter( $transporter );
			$availability->setRequestAuthentication( new VerifyPassthroughAuthentication() );
			return array( $availability, $transporter );
		}

		/**
		 * Verification sends a minimal one-token generation request.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_verify_sends_one_token_generation_request(): void {
			$this->boot();
			$ttls = array();
			$this->stub_transients_stateless( $ttls );

			list( $availability, $transporter ) = $this->make_availability( new Response( 200, array( 'choices' => array() ) ) );
			$verdict = $availability->verify();

			self::assertSame( 'valid', $verdict['state'] );
			self::assertSame( 1, $transporter->calls );
			$data = $transporter->seen->getData();
			self::assertSame( 'deepseek-v4-flash', $data['model'] );
			self::assertSame( 1, $data['max_tokens'] );
			self::assertSame( 'ping', $data['messages'][0]['content'] );
			self::assertStringContainsString( 'chat/completions', $transporter->seen->getUrl() );
			self::assertSame( 300, $ttls['opencode_connector_verify_go'] );
		}

		/**
		 * Three distinct states: valid, invalid_key, could-not-be-checked.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_verify_maps_three_distinct_states(): void {
			$this->boot();
			$ttls = array();
			$this->stub_transients_stateless( $ttls );

			$cases = array(
				'2xx is valid'                => array( new Response( 200, null ), 'valid' ),
				'CreditsError is valid'       => array( new Response( 401, array( 'error' => array( 'type' => 'CreditsError' ) ) ), 'valid' ),
				'rate limited is valid'       => array( new Response( 429, null ), 'valid' ),
				'bad key is invalid'          => array( new Response( 401, array( 'error' => array( 'type' => 'InvalidApiKey' ) ) ), 'invalid_key' ),
				'server error is unchecked'   => array( new Response( 500, null ), 'could-not-be-checked' ),
				'transport failure unchecked' => array( new \RuntimeException( 'network down' ), 'could-not-be-checked' ),
			);

			foreach ( $cases as $label => $case ) {
				list( $outcome, $expected ) = $case;
				list( $availability ) = $this->make_availability( $outcome );
				$verdict = $availability->verify();
				self::assertSame( $expected, $verdict['state'], $label );
				self::assertArrayHasKey( 'diagnosis', $verdict );
			}

			// Missing authentication degrades to invalid_key, never fatal.
			$unkeyed = new OpenCodeProviderAvailability( 'go' );
			$unkeyed->setHttpTransporter( new VerifyCapturingTransporter( new Response( 200, null ) ) );
			self::assertSame( 'invalid_key', $unkeyed->verify()['state'] );

			// verify_state() mapper agrees with the probe outcomes.
			$diagnostics = new ConnectionDiagnostics();
			self::assertSame( 'valid', $diagnostics->verify_state( array( 'state' => 'verified' ) ) );
			self::assertSame( 'invalid_key', $diagnostics->verify_state( array( 'state' => 'invalid_key' ) ) );
			self::assertSame( 'could-not-be-checked', $diagnostics->verify_state( array( 'state' => 'server_error' ) ) );
			self::assertSame( 'could-not-be-checked', $diagnostics->verify_state( array( 'state' => 'unknown' ) ) );
		}

		/**
		 * Cached verdicts are reused within the TTL without a new request.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_verify_caches_verdict_for_five_minutes(): void {
			$this->boot();

			$cache = false;
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$cache ) {
					return 'opencode_connector_verify_go' === $key ? $cache : false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$cache ): bool {
					if ( 'opencode_connector_verify_go' === $key ) {
						$cache = $value;
					}
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			list( $availability, $transporter ) = $this->make_availability( new Response( 200, null ) );
			$first = $availability->verify();
			self::assertSame( 'valid', $first['state'] );
			self::assertIsArray( $cache );

			$availability->setHttpTransporter( new VerifyCapturingTransporter( new \RuntimeException( 'must not run' ) ) );
			$second = $availability->verify();
			self::assertSame( $first, $second );
		}

		/**
		 * Key-change busting clears verify verdicts and stays credential-blind.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_key_change_invalidates_verify_verdicts(): void {
			$this->boot();

			$deleted      = array();
			$get_calls    = array();
			$update_calls = array();
			Functions\when( 'delete_transient' )->alias(
				static function ( ...$args ) use ( &$deleted ): bool {
					$deleted[] = $args[0];
					return true;
				}
			);
			Functions\when( 'delete_site_transient' )->alias( static fn(): bool => true );
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

			$settings = new \OpenCodeConnector\Settings\Settings();
			$settings->bustCachesAdd( 'opencode_connector_settings', array( 'show_all_models' => true ) );

			self::assertContains( 'opencode_connector_verify_go', $deleted );
			self::assertContains( 'opencode_connector_verify_zen', $deleted );
			self::assertContains( 'opencode_connector_verify_go_lock', $deleted );
			self::assertContains( 'opencode_connector_verify_zen_lock', $deleted );

			foreach ( array_merge( $get_calls, $update_calls ) as $call ) {
				self::assertStringNotContainsString( 'connectors_ai_', (string) ( $call[0] ?? '' ) );
			}

			// The plugin file's per-catalog delete list derives each catalog's
			// verify keys from the catalog slug rather than hard-coding them, so
			// "both catalogs are covered" is now a property of Catalog::allKeys()
			// instead of two literals in the source. Assert the property, which
			// is what the old literal check was standing in for — a guard that
			// can only be satisfied by one spelling is a guard on the spelling.
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php' );
			self::assertStringContainsString( 'Catalog::allKeys(', $source );

			foreach ( array( 'go', 'zen' ) as $catalog ) {
				$catalog_keys = \OpenCodeConnector\Metadata\Catalog::allKeys( $catalog );
				self::assertContains( 'opencode_connector_verify_' . $catalog, $catalog_keys );
				self::assertContains( 'opencode_connector_verify_' . $catalog . '_lock', $catalog_keys );
			}
		}

		/**
		 * No key material lands in transients or logs.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_verify_caches_no_key_material(): void {
			$this->boot();

			$stored = array();
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$stored ): bool {
					$stored[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			list( $availability ) = $this->make_availability( new Response( 200, array( 'sensitive' => 'sk-live-secret' ) ) );
			$availability->verify();

			$blob = (string) json_encode( $stored );
			self::assertStringNotContainsString( 'sk-live-secret', $blob );
			self::assertArrayHasKey( 'opencode_connector_verify_go', $stored );
			$verdict = $stored['opencode_connector_verify_go'];
			self::assertSame( array( 'state', 'diagnosis', 'catalog' ), array_keys( $verdict ) );

			$probe_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php' );
			self::assertStringNotContainsString( 'get_option( \'connectors_ai_', $probe_source );
			self::assertStringNotContainsString( 'get_option( "connectors_ai_', $probe_source );
			self::assertStringNotContainsString( 'update_option( \'connectors_ai_', $probe_source );
		}

		/**
		 * A probe that reached nothing must not report verified.
		 *
		 * `verified` in a diagnosis means the backend was reached and
		 * identified — not that the key is good, which is what the top-level
		 * `state` adjudicates. A concurrent probe holding the stampede lock
		 * means this call sends nothing at all, so substituting the `unknown`
		 * verdict for a missing diagnosis asserted that a request had reached
		 * OpenCode and been understood. It had not been sent.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_a_locked_probe_does_not_report_verified(): void {
			$this->boot();
			$ttls = array();
			$this->stub_transients_stateless( $ttls );

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ): mixed {
					return 'opencode_connector_verify_go_lock' === $key ? 1 : false;
				}
			);

			list( $availability, $transporter ) = $this->make_availability( new Response( 200, null ) );
			$verdict = $availability->verify();

			self::assertSame( 0, $transporter->calls, 'The locked path must not send a request.' );
			self::assertSame( 'could-not-be-checked', $verdict['state'] );
			self::assertArrayHasKey( 'verified', $verdict['diagnosis'] );
			self::assertFalse(
				(bool) $verdict['diagnosis']['verified'],
				'No request was sent, so no gateway was reached or identified. "We reached the backend and identified it" must never be asserted about a probe that never left the process.'
			);
		}

		/**
		 * The unrecognised-response verdict keeps saying it reached the backend.
		 *
		 * The counterpart to the assertion above, so the fix cannot over-correct
		 * into claiming that no verdict ever reached a gateway. A 404 came back
		 * *from* OpenCode: the request arrived and the answer was recognised as
		 * something the plugin has no rule for, which is exactly what `unknown`
		 * means. The response says nothing about the credential either way.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_an_unrecognized_response_still_reports_reached(): void {
			$this->boot();
			$ttls = array();
			$this->stub_transients_stateless( $ttls );

			list( $availability ) = $this->make_availability( new Response( 404, null ) );
			$verdict = $availability->verify();

			self::assertSame( 'could-not-be-checked', $verdict['state'], 'An unrecognised status proves nothing about the credential.' );
			self::assertSame( 'unknown', $verdict['diagnosis']['state'] );
			self::assertTrue(
				(bool) $verdict['diagnosis']['verified'],
				'The gateway answered, so it was reached and identified. Fail-open here is about the credential, not about whether the request arrived.'
			);
		}

		/**
		 * Reading the last result before any probe has run must not report verified.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_get_last_result_before_any_probe_does_not_report_verified(): void {
			$this->boot();

			$availability = new OpenCodeProviderAvailability( 'zen' );
			$last         = $availability->getLastResult();

			self::assertSame(
				'uncheckable',
				$last['state'],
				'No probe has run, so nothing was reached and nothing was learned.'
			);
			self::assertFalse(
				(bool) $last['verified'],
				'getLastResult() must not default to a verdict that asserts the backend was reached before a request has been sent.'
			);
		}
	}
}
