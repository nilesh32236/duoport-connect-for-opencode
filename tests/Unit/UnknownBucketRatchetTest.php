<?php
/**
 * Ratchet on the could-not-be-checked bucket.
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
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use WordPress\AiClient\Providers\Http\DTO\Response;

	/**
	 * `unknown` must stay in the bucket that decides fail-open.
	 */
	final class UnknownBucketRatchetTest extends MonkeyTestCase {

		/**
		 * `unknown` is in the could-not-be-checked bucket and stays there.
		 *
		 * This is the guard on the live defect this change fixes. Written FIRST and
		 * run against unfixed main, the behavioural characterisation in
		 * UnknownFallbackTest.php failed there with "proved nothing about the key".
		 * That test proves the consequence; this one names the cause, so a future
		 * edit that drops the state fails on the bucket itself rather than only on a
		 * status matrix that happens to exercise it.
		 *
		 * @return void
		 */
		public function test_unknown_is_in_the_could_not_be_checked_bucket(): void {
			self::assertContains(
				'unknown',
				ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES,
				'An unrecognised response reached the gateway and says nothing about the key; it must fail open, or a working key is disconnected.'
			);
			self::assertNotContains(
				'unknown',
				ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES,
				'`unknown` is not a credential verdict and must never clear last-known-good.'
			);
			self::assertNotContains(
				'unknown',
				ConnectionDiagnostics::KEYED_STATES,
				'`unknown` proves nothing about the credential; it is not evidence the gateway accepted the key.'
			);
		}

		/**
		 * The buckets stay disjoint.
		 *
		 * A state in two buckets would make the call ORDER decide the credential
		 * outcome rather than the state, which is the kind of silent coupling this
		 * file exists to rule out.
		 *
		 * @return void
		 */
		public function test_state_buckets_stay_disjoint(): void {
			self::assertSame(
				array(),
				array_intersect(
					ConnectionDiagnostics::KEYED_STATES,
					ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES
				),
				'Keyed and definitively negative must never overlap.'
			);
			self::assertSame(
				array(),
				array_intersect(
					ConnectionDiagnostics::KEYED_STATES,
					ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES
				),
				'Keyed and could-not-be-checked must never overlap.'
			);
			self::assertSame(
				array(),
				array_intersect(
					ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES,
					ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES
				),
				'Definitely negative and could-not-be-checked must never overlap.'
			);
		}

		/**
	 * The probe reads the published bucket, not its own copy of the list.
	 *
	 * The bug was one missing entry in one of two hand-maintained lists. Reading
	 * the published bucket removes the second copy, so the next state added to
	 * the vocabulary cannot be forgotten at a call site.
	 *
	 * The assertion is on the SHAPE OF THE DEFECT — a hand-maintained array at
	 * a decision point — not on how many times the constant appears. An
	 * earlier version of this test asserted `substr_count === 2`, which meant
	 * the test would have failed the first time anyone added a legitimate third
	 * decision point: it would have blocked the correct fix rather than the
	 * regression it was written for.
	 *
	 * Call sites consult the buckets through the isConfiguredState() /
	 * isUncheckableState() helpers (which read the constants), so either
	 * spelling counts as reading the published bucket.
	 *
	 * @return void
	 */
	public function test_probe_reads_the_published_bucket(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php'
		);

		self::assertDoesNotMatchRegularExpression(
			'/in_array\(\s*\$state,\s*array\(/',
			$source,
			'A hand-maintained copy of the bucket list at a decision point is what let `unknown` go missing.'
		);

		// A floor, not a count: each bucket the probe branches on must actually
		// be consulted by production code, either as the constant or through
		// the helper that reads it.
		foreach ( array( 'KEYED_STATES' => 'isConfiguredState', 'COULD_NOT_BE_CHECKED_STATES' => 'isUncheckableState' ) as $constant => $helper ) {
			self::assertTrue(
				substr_count( $source, 'ConnectionDiagnostics::' . $constant ) + substr_count( $source, 'ConnectionDiagnostics::' . $helper ) > 0,
				$constant . ' (or ' . $helper . ') must be read by the probe, not merely declared.'
			);
		}
	}

		/**
		 * A state in NO bucket leaves last-known-good untouched.
		 *
		 * The three buckets are exhaustive over today's vocabulary, and the buckets
		 * being disjoint is not the same claim as being complete. A state added to
		 * the vocabulary WITHOUT being given a bucket would land on whatever the
		 * decision point's fallthrough does — and the fallthrough this replaces
		 * cleared the flag. That is the same hazard as the live bug, one branch
		 * later: the state had not adjudicated the credential, yet a working key
		 * would have been disconnected.
		 *
		 * The state here is synthetic and deliberately in no bucket, because no real
		 * one is. That is the point — the test is about the shape of the decision,
		 * not about a status code that happens to reach it today.
		 *
		 * The control assertion at the end is load-bearing. Without it this test
		 * would also pass against an `applyLastGood()` that never touched the flag
		 * at all, which is the same reason an assertion has to be able to tell the
		 * correct implementation from the defect: "the flag survived" is only
		 * evidence if something else can make it not survive.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_a_state_in_no_bucket_leaves_last_known_good_untouched(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			$flag    = Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX;
			$seeded  = array(
				'v'  => '1',
				'ts' => time(),
			);
			$store   = array( $flag => $seeded );
			$deleted = array();

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
				static function ( string $key ) use ( &$store, &$deleted ): bool {
					$deleted[] = $key;
					unset( $store[ $key ] );
					return true;
				}
			);

			// Prove the state really is unbucketed, so a future rename cannot quietly
			// make this test exercise a real bucket and pass for the wrong reason.
			$unbucketed = 'synthetic_state_in_no_bucket';
			foreach (
				array(
					'KEYED_STATES'                 => ConnectionDiagnostics::KEYED_STATES,
					'DEFINITIVE_NEGATIVE_STATES'   => ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES,
					'COULD_NOT_BE_CHECKED_STATES'  => ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES,
				) as $name => $bucket
			) {
				self::assertNotContains(
					$unbucketed,
					$bucket,
					'This test is only meaningful while the state is in no bucket.'
				);
			}

			$apply = new \ReflectionMethod( OpenCodeProviderAvailability::class, 'applyLastGood' );
			$apply->setAccessible( true );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$apply->invoke( $availability, $unbucketed );

			self::assertNotContains(
				$flag,
				$deleted,
				'An unbucketed state must not delete last-known-good: it has adjudicated nothing.'
			);
			$survived = $store[ $flag ] ?? null;
			self::assertIsArray(
				$survived,
				'The flag armed by a previous keyed success must survive a state in no bucket.'
			);
			self::assertSame(
				'1',
				$survived['v'] ?? null,
				'The surviving flag keeps its string sentinel.'
			);

			// Control: a proven negative must still clear it immediately, or the
			// assertion above proves nothing.
			$apply->invoke( $availability, 'invalid_key' );

			self::assertContains(
				$flag,
				$deleted,
				'A proven invalid key must still clear the flag — that is what lets a revoked key go at once.'
			);
		}

		/**
		 * isConfigured() fails OPEN on a state in no bucket.
		 *
		 * This is the last fail-closed decision point, and it was left standing
		 * while its sibling in applyLastGood() was hardened. Both ask the same
		 * question — what should a state that adjudicated nothing mean? — and
		 * the answers were `true`/`false` respectively. `applyLastGood()`'s own
		 * docblock argues that a state outside every bucket must never be read
		 * as evidence against the credential; `isConfigured()` was doing
		 * precisely that, one call away.
		 *
		 * The state here is synthetic and deliberately unbucketed, because no
		 * real one is — the buckets are exhaustive over today's vocabulary, and
		 * that is exactly what made the bare `return false` read as harmless
		 * rather than as a landmine for the next state someone adds.
		 *
		 * The control assertion is load-bearing for the same reason as the one
		 * on the applyLastGood() test: without it, "an unbucketed state returns
		 * true" would also be satisfied by an isConfigured() that always returns
		 * true, which is not the contract and would never report a revoked key.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_is_configured_fails_open_on_a_state_in_no_bucket(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			$result_key = Catalog::AVAIL_PREFIX . 'go';
			$flag_key   = Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX;

			$unbucketed = 'synthetic_state_in_no_bucket';
			foreach (
				array(
					'KEYED_STATES'                => ConnectionDiagnostics::KEYED_STATES,
					'DEFINITIVE_NEGATIVE_STATES'  => ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES,
					'COULD_NOT_BE_CHECKED_STATES' => ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES,
				) as $name => $bucket
			) {
				self::assertNotContains(
					$unbucketed,
					$bucket,
					'This test is only meaningful while the state is in no bucket.'
				);
			}

			// Seed the cached probe result directly: probe() returns a cached array
			// verbatim, which is how a synthetic state can be fed through the
			// real public path without a classifier that cannot produce it.
			$serve = static function ( array $result, bool $flag ) use ( $result_key, $flag_key ): callable {
				return static function ( string $key ) use ( $result, $flag, $result_key, $flag_key ): mixed {
					if ( $key === $result_key ) {
						return $result;
					}
					return $flag_key === $key && $flag ? 1 : false;
				};
			};

			Functions\when( 'get_transient' )->alias(
				$serve( array( 'state' => $unbucketed ), true )
			);
			$armed = new OpenCodeProviderAvailability( 'go' );

			self::assertTrue(
				$armed->isConfigured(),
				'A state in no bucket has adjudicated nothing, so it must not silently mean "not configured".'
			);

			// Control: a proven negative still reports false, or the assertion
			// above proves nothing.
			Functions\when( 'get_transient' )->alias(
				$serve( array( 'state' => 'invalid_key' ), true )
			);
			$revoked = new OpenCodeProviderAvailability( 'go' );

			self::assertFalse(
				$revoked->isConfigured(),
				'A proven invalid key must still report not-configured even with an armed flag.'
			);
		}

		/**
		 * Transient failures re-probe on a jittered 45–75s window, not in lockstep.
		 *
		 * A fixed 60s window makes every site whose upstream fails the same way
		 * re-probe on the same boundary. The short branch therefore consults
		 * `wp_rand( -15, 15 )`, so a -15 draw caches 45s and a +15 draw caches
		 * 75s, with a 30s floor guarding filtered time constants.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_transient_failures_reprobe_on_a_jittered_window(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$result_key = Catalog::AVAIL_PREFIX . 'go';
			$writes     = array();
			$rand_args  = array();
			$rand_value = 0;

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$writes ): bool {
					$writes[ $key ] = $ttl;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ) use ( &$rand_args, &$rand_value ): int {
					$rand_args[] = array( $min, $max );
					return $rand_value;
				}
			);

			foreach ( array( -15 => 45, 0 => 60, 15 => 75 ) as $spread => $expected_ttl ) {
				$rand_value = $spread;
				$writes     = array();

				$availability = new OpenCodeProviderAvailability( 'go' );
				$availability->setHttpTransporter( new RatchetHardeningTransporter( new \RuntimeException( 'network down' ) ) );
				$availability->setRequestAuthentication( new RatchetHardeningAuthentication() );
				$availability->diagnose();

				self::assertSame(
					$expected_ttl,
					$writes[ $result_key ] ?? null,
					'A transport failure with jitter ' . $spread . 's must cache for ' . $expected_ttl . 's.'
				);
			}

			self::assertContains(
				array( -15, 15 ),
				$rand_args,
				'The short window must consult wp_rand(-15, 15), or installs re-probe in lockstep.'
			);
		}


		/**
		 * A throwing `wp_rand()` costs the jitter, never the probe.
		 *
		 * Every probe window consults the pluggable rand, which can throw;
		 * the short branch already guarded it but the persistent and keyed
		 * branches called it with a `function_exists` check only, so the
		 * throw escaped `probe()` and skipped the stampede-lock release. All
		 * branches now share one guarded source: a throw degrades to zero
		 * spread and the verdict is still cached.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_a_throwing_wp_rand_costs_the_jitter_never_the_probe(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$result_key = Catalog::AVAIL_PREFIX . 'go';
			$writes     = array();

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$writes ): bool {
					$writes[ $key ] = $ttl;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ): int {
					unset( $min, $max );
					throw new \RuntimeException( 'rand is down' );
				}
			);

			$transient = new OpenCodeProviderAvailability( 'go' );
			$transient->setHttpTransporter( new RatchetHardeningTransporter( new \RuntimeException( 'network down' ) ) );
			$transient->setRequestAuthentication( new RatchetHardeningAuthentication() );
			$transient->diagnose();
			self::assertSame( 60, $writes[ $result_key ] ?? null, 'Short branch without jitter keeps the 60s window.' );

			$writes     = array();
			$persistent = new OpenCodeProviderAvailability( 'go' );
			$persistent->setHttpTransporter( new RatchetHardeningTransporter( new Response( 404, null ) ) );
			$persistent->setRequestAuthentication( new RatchetHardeningAuthentication() );
			$persistent->diagnose();
			self::assertSame( 300, $writes[ $result_key ] ?? null, 'Persistent branch without jitter keeps the five-minute window.' );

			$writes = array();
			$keyed  = new OpenCodeProviderAvailability( 'go' );
			$keyed->setHttpTransporter( new RatchetHardeningTransporter( new Response( 200, null ) ) );
			$keyed->setRequestAuthentication( new RatchetHardeningAuthentication() );
			$keyed->diagnose();
			self::assertSame( 300, $writes[ $result_key ] ?? null, 'Keyed branch without jitter keeps the five-minute window.' );
		}

		/**
		 * A keyed success arms the flag as a timestamped string sentinel.
		 *
		 * The sentinel is the string `'1'`, not int `1`, so the stored type
		 * stays stable across the database round-trip. It does not skip the
		 * value-row write — `ts` changes on every keyed success, so each
		 * rewrite moves both rows, and that rewrite is what keeps the
		 * rolling 30-day TTL rolling. The timestamp is what bounds the
		 * fallback by age (timestamped shapes only).
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_keyed_success_writes_a_timestamped_string_sentinel(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}
			if ( ! defined( 'DAY_IN_SECONDS' ) ) {
				define( 'DAY_IN_SECONDS', 86400 );
			}

			$flag  = Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX;
			$store = array();
			$ttls  = array();

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ): mixed {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store, &$ttls ): bool {
					$store[ $key ] = $value;
					$ttls[ $key ]  = $ttl;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$before = time();

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter( new RatchetHardeningTransporter( new Response( 200, null ) ) );
			$availability->setRequestAuthentication( new RatchetHardeningAuthentication() );
			self::assertTrue( $availability->isConfigured(), 'A 200 proves the key works.' );

			$after   = time();
			$written = $store[ $flag ] ?? null;
			self::assertIsArray( $written, 'The flag is a timestamped array, not a bare int.' );
			self::assertSame( '1', $written['v'] ?? null, 'The sentinel is the string the database round-trips unchanged.' );
			self::assertGreaterThanOrEqual( $before, $written['ts'] ?? 0, 'The confirmation timestamp cannot predate the probe.' );
			self::assertLessThanOrEqual( $after, $written['ts'] ?? PHP_INT_MAX, 'The confirmation timestamp cannot be from the future.' );
			self::assertSame( 30 * 86400, $ttls[ $flag ] ?? null, 'The rolling 30-day TTL is unchanged.' );
		}

		/**
		 * Flag reads are memoised per instance, and writes arm the memo.
		 *
		 * `isConfigured()` reads the flag on every could-not-be-checked
		 * verdict, so without the memo each call costs a transient read on top
		 * of the verdict read. The memo is per-instance only — a new instance
		 * re-reads, so there is no cross-request staleness.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_last_good_reads_are_memoised_per_instance(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			$flag  = Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX;
			$store = array(
				$flag => array(
					'v'  => '1',
					'ts' => time(),
				),
			);
			$reads = 0;

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store, &$reads, $flag ): mixed {
					if ( $key === $flag ) {
						++$reads;
					}
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );

			$read = new \ReflectionMethod( OpenCodeProviderAvailability::class, 'readLastGood' );
			$read->setAccessible( true );
			$write = new \ReflectionMethod( OpenCodeProviderAvailability::class, 'writeLastGood' );
			$write->setAccessible( true );

			$availability = new OpenCodeProviderAvailability( 'go' );
			self::assertTrue( $read->invoke( $availability ) );
			self::assertTrue( $read->invoke( $availability ) );
			self::assertSame( 1, $reads, 'The second read in one request must come from the memo, not storage.' );

			// A write arms the memo: storage going away afterwards must not disarm it.
			$writer = new OpenCodeProviderAvailability( 'go' );
			$write->invoke( $writer, true );
			unset( $store[ $flag ] );
			self::assertTrue( $read->invoke( $writer ), 'writeLastGood() must set the memo, so a write-then-read never re-reads.' );
			self::assertSame( 1, $reads, 'The memoised read must not touch storage at all.' );
		}

		/**
		 * A flag unconfirmed past its age cap stops failing open.
		 *
		 * Rotations delivered outside the options table — a `wp-config.php`
		 * constant or an environment variable — fire none of the bust hooks,
		 * so without an age bound the 30-day flag could outlive the rotation
		 * it describes. Past `LAST_GOOD_MAX_AGE` without a fresh keyed
		 * confirmation, a could-not-be-checked verdict reports not-configured.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_an_unconfirmed_flag_past_its_age_cap_reports_not_configured(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$max_age = (int) ( new \ReflectionClass( OpenCodeProviderAvailability::class ) )->getConstant( 'LAST_GOOD_MAX_AGE' );
			self::assertGreaterThan( 0, $max_age, 'The fallback age cap must exist and be positive.' );

			$flag  = Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX;
			$store = array(
				$flag => array(
					'v'  => '1',
					'ts' => time() - $max_age - 60,
				),
			);

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ): mixed {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter( new RatchetHardeningTransporter( new Response( 500, null ) ) );
			$availability->setRequestAuthentication( new RatchetHardeningAuthentication() );

			self::assertSame( 'uncheckable', $availability->diagnose()['state'], 'Sanity: a 500 classifies could-not-be-checked.' );
			self::assertFalse(
				$availability->isConfigured(),
				'A flag unconfirmed past its age cap has nothing to fall back to.'
			);
		}

		/**
		 * A recently confirmed flag still fails open (control for the age cap).
		 *
		 * The cap must only end fallbacks that have gone unconfirmed, never
		 * the normal outage path: a flag confirmed within `LAST_GOOD_MAX_AGE`
		 * still carries a could-not-be-checked verdict.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_a_recently_confirmed_flag_still_fails_open(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$max_age = (int) ( new \ReflectionClass( OpenCodeProviderAvailability::class ) )->getConstant( 'LAST_GOOD_MAX_AGE' );
			self::assertGreaterThan( 0, $max_age, 'The fallback age cap must exist and be positive.' );

			$flag  = Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX;
			$store = array(
				$flag => array(
					'v'  => '1',
					'ts' => time() - $max_age + 3600,
				),
			);

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ): mixed {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter( new RatchetHardeningTransporter( new Response( 500, null ) ) );
			$availability->setRequestAuthentication( new RatchetHardeningAuthentication() );

			self::assertTrue(
				$availability->isConfigured(),
				'A flag confirmed within its age cap must still carry a 500 through the outage.'
			);
		}

		/**
		 * Pre-timestamp flag shapes still read as armed.
		 *
		 * Flags armed before the timestamped shape shipped carry no `ts` to
		 * bound; they fail open exactly as they always did, and the next keyed
		 * success upgrades them. Treating them as expired would disconnect
		 * working keys on upgrade.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_legacy_flag_shapes_still_read_as_armed(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			foreach ( array( 1, '1' ) as $legacy ) {
				$flag  = Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX;
				$store = array( $flag => $legacy );

				Functions\when( 'get_transient' )->alias(
					static function ( string $key ) use ( &$store ): mixed {
						return $store[ $key ] ?? false;
					}
				);
				Functions\when( 'set_transient' )->justReturn( true );
				Functions\when( 'delete_transient' )->justReturn( true );
				Functions\when( 'wp_rand' )->justReturn( 0 );

				$availability = new OpenCodeProviderAvailability( 'go' );
				$availability->setHttpTransporter( new RatchetHardeningTransporter( new Response( 500, null ) ) );
				$availability->setRequestAuthentication( new RatchetHardeningAuthentication() );

				self::assertTrue(
					$availability->isConfigured(),
					'A legacy ' . gettype( $legacy ) . ' flag must still fail open until a keyed success upgrades it.'
				);
			}
		}
	}

	/**
	 * Transporter double replaying one queued response or throwable.
	 */
	final class RatchetHardeningTransporter {
		/**
		 * Queued outcome.
		 *
		 * @var mixed
		 */
		private mixed $next;

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
			unset( $request );
			if ( $this->next instanceof \Throwable ) {
				throw $this->next;
			}
			return $this->next;
		}
	}

	/**
	 * Authentication double passing requests through untouched.
	 */
	final class RatchetHardeningAuthentication {
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
}
