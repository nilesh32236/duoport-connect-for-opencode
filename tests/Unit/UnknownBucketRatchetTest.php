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
			// be consulted by production code.
			foreach ( array( 'KEYED_STATES', 'COULD_NOT_BE_CHECKED_STATES' ) as $constant ) {
				self::assertGreaterThan(
					0,
					substr_count( $source, 'ConnectionDiagnostics::' . $constant ),
					$constant . ' must be read by the probe, not merely declared.'
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
			$store   = array( $flag => 1 );
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
			self::assertSame(
				1,
				$store[ $flag ] ?? null,
				'The flag armed by a previous keyed success must survive a state in no bucket.'
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
	}
}
