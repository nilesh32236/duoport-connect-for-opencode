<?php
/**
 * Ratchet on the could-not-be-checked bucket.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Availability\ConnectionDiagnostics;

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
}
