<?php
/**
 * Availability probe semantics matrix specs.
 *
 * Locks in the probe contract: 2xx true, 401 plus CreditsError true (valid
 * key, empty balance), 429 true (throttled: must not lock out valid users),
 * 5xx plus transport exceptions and any unrecognised response (400/402/403/404)
 * fail open on last-known-good, and a definitive invalid key still fails
 * closed. Every row is stated against an explicit cache state: the cold-cache
 * and warm-cache matrices are separate because a single stub cannot express
 * both, and a row labelled only "not connected" is true of neither.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Availability\OpenCodeProviderAvailability;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use WordPress\AiClient\Providers\Http\DTO\Response;

	/**
	 * Transporter double replaying one queued response or throwable.
	 *
	 * @since 0.1.4
	 */
	final class FakeProbeTransporter {
		/**
		 * Queued outcome.
		 *
		 * @var mixed
		 */
		private mixed $next;

		/**
		 * Requests seen, so a spec can assert probe frequency.
		 *
		 * @var int
		 */
		public int $seen = 0;

		/**
		 * Constructor.
		 *
		 * @param mixed $next Response to return or throwable to throw.
		 */
		public function __construct( mixed $next ) {
			$this->next = $next;
		}

		/**
		 * Replay the queued outcome.
		 *
		 * @param mixed $request Request (ignored).
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			++$this->seen;
			if ( $this->next instanceof \Throwable ) {
				throw $this->next;
			}
			return $this->next;
		}
	}

	/**
	 * Authentication double passing requests through untouched.
	 *
	 * @since 0.1.4
	 */
	final class FakeProbeAuthentication {
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
	 * Probe semantics matrix specs.
	 *
	 * @package OpenCodeConnector
	 * @since 0.1.4
	 */
	final class ProbeSemanticsTest extends MonkeyTestCase {

		/**
		 * Probe matrix without last-known-good: 2xx/CreditsError/429 true, everything else false.
		 *
		 * @since 0.1.4
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_probe_semantics_matrix(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ): int {
					unset( $min, $max );
					return 0;
				}
			);

			$cases = array(
				'2xx is connected'                 => array( 200, null, null, true ),
				'201 is connected'                 => array( 201, null, null, true ),
				'429 throttled stays connected'    => array( 429, null, null, true ),
				'429 FreeUsageLimitError stays connected' => array( 429, array( 'error' => array( 'type' => 'FreeUsageLimitError' ) ), null, true ),
				'401 CreditsError stays connected' => array( 401, array( 'error' => array( 'type' => 'CreditsError' ) ), null, true ),
				'401 other type is not connected'  => array( 401, array( 'error' => array( 'type' => 'InvalidApiKey' ) ), null, false ),
				'401 empty body is not connected'  => array( 401, null, null, false ),
				'400 with no last-known-good is not connected' => array( 400, null, null, false ),
				'403 with no last-known-good is not connected' => array( 403, null, null, false ),
				'500 is not connected'             => array( 500, null, null, false ),
				'transport exception degrades'     => array( 0, null, new \RuntimeException( 'network down' ), false ),
			);

			foreach ( $cases as $label => $case ) {
				list( $code, $data, $throw, $expected ) = $case;

				$availability = new OpenCodeProviderAvailability( 'go' );
				$outcome      = $throw ?? new Response( $code, $data );
				$availability->setHttpTransporter( new FakeProbeTransporter( $outcome ) );
				$availability->setRequestAuthentication( new FakeProbeAuthentication() );

				self::assertSame( $expected, $availability->isConfigured(), $label );
			}
		}

		/**
		 * Probe matrix WITH last-known-good: an unrecognised 4xx fails open.
		 *
		 * The cold-cache matrix above stubs `get_transient` to always return
		 * false, so on its own it can only ever prove the cold-cache half of the
		 * contract. It cannot distinguish a probe that fails open from one that
		 * fails closed, because both agree with `false`. This matrix is the
		 * other half: with last-known-good set, the very statuses the cold-cache
		 * rows call "not connected" must come back connected, because an
		 * unrecognised response proves nothing about the key.
		 *
		 * Against main, where `unknown` is absent from the fallback bucket,
		 * every row here fails. That is the point: without this matrix the
		 * cold-cache labels state the opposite of shipped behaviour and both
		 * directions regress silently.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_probe_semantics_matrix_with_last_known_good(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ): mixed {
					return str_ends_with( $key, '_last_good' ) ? 1 : false;
				}
			);
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$cases = array(
				// Unrecognised: reaches the gateway, says nothing about the key.
				'400 with last-known-good stays connected' => array( 400, null, null, true ),
				'402 with last-known-good stays connected' => array( 402, null, null, true ),
				'403 with last-known-good stays connected' => array( 403, null, null, true ),
				'404 with last-known-good stays connected' => array( 404, null, null, true ),
				// 5xx and transport failures keep failing open on the same flag.
				'500 with last-known-good stays connected' => array( 500, null, null, true ),
				'transport exception with last-known-good stays connected' => array( 0, null, new \RuntimeException( 'network down' ), true ),
				// A definitive negative still wins: fail-open must not fail up.
				'401 invalid key still disconnects despite last-known-good' => array( 401, array( 'error' => array( 'type' => 'InvalidApiKey' ) ), null, false ),
			);

			foreach ( $cases as $label => $case ) {
				list( $code, $data, $throw, $expected ) = $case;

				$availability = new OpenCodeProviderAvailability( 'go' );
				$outcome      = $throw ?? new Response( $code, $data );
				$availability->setHttpTransporter( new FakeProbeTransporter( $outcome ) );
				$availability->setRequestAuthentication( new FakeProbeAuthentication() );

				self::assertSame( $expected, $availability->isConfigured(), $label );
			}
		}

		/**
		 * The Zen catalog follows the same probe contract.
		 *
		 * @since 0.1.4
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_zen_catalog_follows_same_probe_contract(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ): int {
					unset( $min, $max );
					return 0;
				}
			);

			$ok = new OpenCodeProviderAvailability( 'zen' );
			$ok->setHttpTransporter(
				new FakeProbeTransporter( new Response( 401, array( 'error' => array( 'type' => 'CreditsError' ) ) ) )
			);
			$ok->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertTrue( $ok->isConfigured(), 'Zen 401 CreditsError stays connected.' );

			$bad = new OpenCodeProviderAvailability( 'zen' );
			$bad->setHttpTransporter( new FakeProbeTransporter( new Response( 500, null ) ) );
			$bad->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertFalse( $bad->isConfigured(), 'Zen 500 is not connected.' );
		}

		/**
		 * An unrecognised response takes the jittered window, not the transient one.
		 *
		 * Two separate defects, one cause. Routing `unknown` onto the
		 * could-not-be-checked branch put it on a sixty-second window that is
		 * meant for failures which resolve on their own — five times the probe
		 * frequency of every other cached verdict. Worse, that branch has no
		 * jitter, so every site whose upstream answers 400/404 would re-probe
		 * in lockstep on the same boundary: the synchronized stampede the
		 * `wp_rand(-60, 60)` below the branch exists to prevent, introduced by
		 * the change meant to quieten the probe.
		 *
		 * The other half matters just as much: the longer window must not be
		 * bought by letting `unknown` reach the last-known-good writes. If it
		 * did, it would clear the flag and reinstate the production bug.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unrecognized_response_uses_the_jittered_window(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$result_key = 'opencode_connector_avail_go';
			$rand_calls = 0;

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$writes ): bool {
					$writes[ $key ] = $ttl;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ) use ( &$rand_calls ): int {
					++$rand_calls;
					return 0;
				}
			);

			// A persistent 404 — the shape this PR exists for — must be cached
			// on the full jittered window, and must consult the jitter source.
			$writes      = array();
			$rand_calls  = 0;
			$persistent  = new OpenCodeProviderAvailability( 'go' );
			$persistent->setHttpTransporter( new FakeProbeTransporter( new Response( 404, null ) ) );
			$persistent->setRequestAuthentication( new FakeProbeAuthentication() );
			$persistent->diagnose();

			self::assertArrayHasKey(
				$result_key,
				$writes,
				'An unrecognised response must still be cached, just for longer.'
			);
			self::assertSame(
				5 * 60,
				$writes[ $result_key ],
				'An unrecognised response is not transient; it takes the full five-minute window.'
			);
			self::assertGreaterThan(
				0,
				$rand_calls,
				'The long window must be jittered, or every affected site re-probes in lockstep.'
			);

			// A transport failure genuinely is transient and keeps the short
			// window, so a recovered gateway is noticed quickly.
			$writes     = array();
			$rand_calls = 0;
			$transient  = new OpenCodeProviderAvailability( 'go' );
			$transient->setHttpTransporter( new FakeProbeTransporter( new \RuntimeException( 'network down' ) ) );
			$transient->setRequestAuthentication( new FakeProbeAuthentication() );
			$transient->diagnose();

			self::assertSame(
				60,
				$writes[ $result_key ],
				'A transport failure resolves on its own and keeps the one-minute window.'
			);

			// And the longer window must not have cost the fail-open: the
			// last-known-good flag is never written on this path.
			$writes = array();
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( $result_key ): mixed {
					return $key === $result_key ? false : false;
				}
			);
			$guarded = new OpenCodeProviderAvailability( 'go' );
			$guarded->setHttpTransporter( new FakeProbeTransporter( new Response( 404, null ) ) );
			$guarded->setRequestAuthentication( new FakeProbeAuthentication() );
			$guarded->diagnose();

			self::assertArrayNotHasKey(
				'opencode_connector_avail_go_last_good',
				$writes,
				'An unrecognised response proves nothing about the key and must never arm last-known-good.'
			);
		}

		/**
		 * A persistent 404 stops re-probing; a transient failure retries sooner.
		 *
		 * The window split is only worth anything if it changes when the next
		 * outbound probe happens, so this drives a virtual clock and a
		 * time-aware transient stub rather than asserting on the TTL constant.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_probe_frequency_follows_the_window_split(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$now   = 1_000_000;
			$store = array();

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store, &$now ): mixed {
					if ( ! isset( $store[ $key ] ) ) {
						return false;
					}
					list( $expiry, $value ) = $store[ $key ];
					return $now < $expiry ? $value : false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store, &$now ): bool {
					$store[ $key ] = array( $now + $ttl, $value );
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			// Persistent 404: after a minute the cached verdict still stands, so
			// no second probe goes out.
			$persistent_transporter = new FakeProbeTransporter( new Response( 404, null ) );
			$persistent             = new OpenCodeProviderAvailability( 'go' );
			$persistent->setHttpTransporter( $persistent_transporter );
			$persistent->setRequestAuthentication( new FakeProbeAuthentication() );
			$persistent->diagnose();
			self::assertSame( 1, $persistent_transporter->seen, 'First probe goes out.' );

			$now += 61;
			$persistent->diagnose();
			self::assertSame(
				1,
				$persistent_transporter->seen,
				'A persistent 404 must not re-probe on the one-minute boundary that used to drive it.'
			);

			// A transport failure is transient, so it is still retried after a
			// minute — a recovered gateway must be noticed quickly.
			$store = array();
			$now   = 1_000_000;

			$transient_transporter = new FakeProbeTransporter( new \RuntimeException( 'network down' ) );
			$transient             = new OpenCodeProviderAvailability( 'go' );
			$transient->setHttpTransporter( $transient_transporter );
			$transient->setRequestAuthentication( new FakeProbeAuthentication() );
			$transient->diagnose();
			self::assertSame( 1, $transient_transporter->seen, 'First probe goes out.' );

			$now += 61;
			$transient->diagnose();
			self::assertSame(
				2,
				$transient_transporter->seen,
				'A transient failure must still be retried once its short window expires.'
			);
		}

		/**
		 * An already-set last-known-good flag is not rewritten on every probe.
		 *
		 * `set_transient()` has no equality short-circuit in WP core, so writing
		 * an unchanged flag is an unconditional options-row UPDATE. A keyed
		 * probe runs about every five minutes per catalog for the life of the
		 * install, so the previous code issued that UPDATE forever to store a
		 * value that could not have changed.
		 *
		 * Both directions matter: the flag is still armed on the first keyed
		 * success, and it is still cleared by the first definitive negative,
		 * which is what lets the next keyed probe re-arm it.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_last_known_good_is_not_rewritten_when_already_set(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}
			if ( ! defined( 'DAY_IN_SECONDS' ) ) {
				define( 'DAY_IN_SECONDS', 86400 );
			}

			$last_good = 'opencode_connector_avail_go_last_good';

			// Cold flag: the first keyed success must arm it.
			$cold_writes = array();
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$last_good ): mixed {
					unset( $key );
					return false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$cold_writes ): bool {
					$cold_writes[] = array( $key, $value, $ttl );
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$cold = new OpenCodeProviderAvailability( 'go' );
			$cold->setHttpTransporter( new FakeProbeTransporter( new Response( 200, null ) ) );
			$cold->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertTrue( $cold->isConfigured(), 'A keyed probe is connected.' );
			self::assertContains(
				$last_good,
				array_column( $cold_writes, 0 ),
				'The first keyed success must arm the last-known-good flag.'
			);

			// Warm flag: an unchanged value must not be written again.
			$warm_writes = array();
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( $last_good ): mixed {
					return $last_good === $key ? 1 : false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$warm_writes ): bool {
					$warm_writes[] = array( $key, $value, $ttl );
					return true;
				}
			);

			$warm = new OpenCodeProviderAvailability( 'go' );
			$warm->setHttpTransporter( new FakeProbeTransporter( new Response( 200, null ) ) );
			$warm->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertTrue( $warm->isConfigured(), 'A keyed probe is still connected.' );
			self::assertNotContains(
				$last_good,
				array_column( $warm_writes, 0 ),
				'An already-true flag must not be rewritten; set_transient() has no equality short-circuit.'
			);
		}

		/**
		 * Fail-open: 5xx and transport failures preserve last-known-good state.
		 *
		 * @since 0.1.6
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_uncheckable_preserves_last_known_good(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ): mixed {
					return 'opencode_connector_avail_go_last_good' === $key ? 1 : false;
				}
			);
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ): int {
					unset( $min, $max );
					return 0;
				}
			);

			$server = new OpenCodeProviderAvailability( 'go' );
			$server->setHttpTransporter( new FakeProbeTransporter( new Response( 500, null ) ) );
			$server->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertTrue( $server->isConfigured(), '500 with last-known-good stays connected.' );
			self::assertSame( 'could_not_be_checked', $server->diagnose()['code'] );

			$transport = new OpenCodeProviderAvailability( 'go' );
			$transport->setHttpTransporter( new FakeProbeTransporter( new \RuntimeException( 'network down' ) ) );
			$transport->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertTrue( $transport->isConfigured(), 'Transport failure with last-known-good stays connected.' );

			// Unkeyed installs still report not configured even with a stale flag.
			$unkeyed = new OpenCodeProviderAvailability( 'go' );
			self::assertFalse( $unkeyed->isConfigured(), 'Without authentication the provider is not configured.' );
		}

		/**
		 * A could-not-be-checked verdict is cached briefly but never cached as
		 * not-connected, so a persistent outage costs one probe per window.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_uncheckable_is_cached_briefly_without_clearing_last_good(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$store = array();
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ) {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store ): bool {
					$store[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter( new FakeProbeTransporter( new Response( 500, null ) ) );
			$availability->setRequestAuthentication( new FakeProbeAuthentication() );

			// Public entry point: diagnose() runs the same probe path.
			$availability->diagnose();

			self::assertArrayHasKey( 'opencode_connector_avail_go', $store, 'Uncheckable verdict is cached.' );
			self::assertSame( 'uncheckable', $store['opencode_connector_avail_go']['state'] );
			self::assertArrayNotHasKey(
				'opencode_connector_avail_go_last_good',
				$store,
				'Uncheckable never writes last-known-good.'
			);
		}

		/**
		 * A Zen free-tier quota stop proves the key is valid and refreshes
		 * last-known-good instead of being treated as a definitive failure.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_free_tier_limit_stays_connected_and_sets_last_good(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$store = array();
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ) {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store ): bool {
					$store[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'zen' );
			$availability->setHttpTransporter(
				new FakeProbeTransporter(
					new Response( 429, array( 'error' => array( 'type' => 'FreeUsageLimitError' ) ) )
				)
			);
			$availability->setRequestAuthentication( new FakeProbeAuthentication() );

			self::assertTrue( $availability->isConfigured(), 'Free-tier limit keeps a valid key connected.' );
			self::assertSame( 1, $store['opencode_connector_avail_zen_last_good'] ?? null, 'Free-tier limit refreshes last-known-good.' );
		}

		/**
		 * Detailed state survives the transient cache and legacy projection.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_detailed_result_survives_cache(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$cache = false;
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$cache ) {
					return 'opencode_connector_avail_go' === $key ? $cache : false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$cache ): bool {
					if ( 'opencode_connector_avail_go' === $key ) {
						$cache = $value;
					}
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter(
				new FakeProbeTransporter(
					new Response( 401, array( 'error' => array( 'type' => 'CreditsError' ) ) )
				)
			);
			$availability->setRequestAuthentication( new FakeProbeAuthentication() );

			self::assertTrue( $availability->isConfigured() );
			$detailed = $availability->getLastResult();
			self::assertSame( 'no_credits', $detailed['state'] );
			self::assertFalse( $detailed['usable'] );
			self::assertIsArray( $cache );

			$availability->setHttpTransporter( new FakeProbeTransporter( new \RuntimeException( 'must not run' ) ) );
			self::assertSame( $detailed, $availability->diagnose() );
		}

		/**
		 * Missing authentication degrades to not-connected, never fatal.
		 *
		 * @since 0.1.4
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_missing_authentication_is_not_connected(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			Functions\when( 'get_transient' )->justReturn( false );

			$availability = new OpenCodeProviderAvailability( 'go' );

			self::assertFalse( $availability->isConfigured(), 'Without authentication the provider is not configured.' );
		}
	}
}
