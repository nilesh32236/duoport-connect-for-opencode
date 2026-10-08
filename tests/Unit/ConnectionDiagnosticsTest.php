<?php
/**
 * Tests for credential-blind connection classification.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Availability\ConnectionDiagnostics;

final class ConnectionDiagnosticsTest extends MonkeyTestCase {

	/**
	 * Successful and throttled backend responses remain usable.
	 */
	public function test_success_and_rate_limit_are_usable(): void {
		$diagnostics = new ConnectionDiagnostics();

		$success = $diagnostics->classify( 200 );
		$limited = $diagnostics->classify( 429 );

		self::assertTrue( $success['configured'] );
		self::assertTrue( $success['verified'] );
		self::assertTrue( $success['usable'] );
		self::assertFalse( $limited['usable'] );
		self::assertSame( 'rate_limited', $limited['state'] );
	}

	/**
	 * Free-tier usage-limit 429s are distinct from generic rate limiting.
	 */
	public function test_free_tier_limit_is_distinct_from_rate_limit(): void {
		$diagnostics = new ConnectionDiagnostics();

		$free = $diagnostics->classify( 429, array( 'error' => array( 'type' => 'FreeUsageLimitError' ) ) );

		self::assertSame( 'free_tier_limit', $free['state'] );
		self::assertSame( 'free_usage_limit', $free['code'] );
		self::assertTrue( $free['configured'] );
		self::assertTrue( $free['verified'] );
		self::assertFalse( $free['usable'] );

		$plain = $diagnostics->classify( 429 );

		self::assertSame( 'rate_limited', $plain['state'] );
		self::assertTrue( $plain['configured'] );
		self::assertFalse( $plain['usable'] );

		$other = $diagnostics->classify( 429, array( 'error' => array( 'type' => 'RateLimitError' ) ) );

		self::assertSame( 'rate_limited', $other['state'] );
		self::assertTrue( $other['configured'] );
		self::assertFalse( $other['usable'] );
	}

	/**
	 * Authorization and credit outcomes remain distinct.
	 */
	public function test_auth_and_credit_outcomes_are_distinct(): void {
		$diagnostics = new ConnectionDiagnostics();
		$invalid     = $diagnostics->classify( 401, array( 'error' => array( 'type' => 'InvalidAPIKey' ) ) );
		$credits     = $diagnostics->classify( 401, array( 'error' => array( 'type' => 'CreditsError' ) ) );

		self::assertFalse( $invalid['configured'] );
		self::assertTrue( $invalid['verified'] );
		self::assertFalse( $invalid['usable'] );
		self::assertTrue( $credits['configured'] );
		self::assertTrue( $credits['verified'] );
		self::assertFalse( $credits['usable'] );
		self::assertSame( 'no_credits', $credits['state'] );
	}

	/**
	 * Server and transport failures are uncheckable, never invalid-key.
	 */
	public function test_server_and_transport_failures_are_uncheckable(): void {
		$diagnostics = new ConnectionDiagnostics();
		$server      = $diagnostics->classify( 503 );
		$network     = $diagnostics->classify( 0, null, new \RuntimeException( 'transport failed' ) );

		self::assertSame( 'uncheckable', $server['state'] );
		self::assertSame( 'could_not_be_checked', $server['code'] );
		self::assertFalse( $server['usable'] );
		self::assertSame( 503, $server['status'] );
		self::assertSame( 'uncheckable', $network['state'] );
		self::assertSame( 'could_not_be_checked', $network['code'] );
		self::assertFalse( $network['usable'] );
		// Distinct from invalid-key: the backend was never identified.
		self::assertFalse( $server['verified'] );
		self::assertFalse( $network['verified'] );
	}

	/**
	 * Verified results persist the probed 2xx status; the default stays 200.
	 */
	public function test_verified_persists_probed_2xx_status(): void {
		$diagnostics = new ConnectionDiagnostics();

		self::assertSame( 201, $diagnostics->classify( 201 )['status'] );
		self::assertSame( 204, $diagnostics->classify( 204 )['status'] );
		self::assertSame( 200, $diagnostics->verified()['status'] );
	}

	/**
	 * Classification never returns response body content.
	 */
	public function test_response_data_is_not_returned(): void {
		$result = ( new ConnectionDiagnostics() )->classify( 401, array( 'error' => array( 'message' => 'sensitive response' ) ) );

		self::assertSame(
			array( 'state', 'configured', 'verified', 'usable', 'status', 'code' ),
			array_keys( $result )
		);
		self::assertStringNotContainsString( 'sensitive response', (string) json_encode( $result ) );
	}

	/**
	 * An unrecognised response must not assert the credential is configured.
	 *
	 * `unknown()` is a per-probe verdict with no memory: it cannot know whether
	 * a last-known-good flag exists, so it cannot claim the credential is set
	 * up. `isConfigured()` is the surface that resolves the same probe through
	 * that flag and returns false on a cold cache. If `configured` also read
	 * true, the two public surfaces would disagree and every consumer of this
	 * array would get an unconditional fail-open that the boolean projection
	 * deliberately withholds.
	 *
	 * `verified` stays true: the gateway was reached and identified, which is
	 * exactly what separates this verdict from `uncheckable()`.
	 *
	 * @return void
	 */
	public function test_unknown_does_not_assert_configured(): void {
		$diagnostics = new ConnectionDiagnostics();
		$unknown     = $diagnostics->classify( 404 );

		self::assertSame( 'unknown', $unknown['state'] );
		self::assertFalse(
			$unknown['configured'],
			'An unrecognised response never adjudicated the credential, so it must not report configured = true.'
		);
		self::assertTrue(
			$unknown['verified'],
			'The gateway was reached and identified; that is what separates `unknown` from `uncheckable`.'
		);
		self::assertFalse( $unknown['usable'] );

		// A definitive negative keeps the same shape: reached, but not configured.
		$invalid = $diagnostics->classify( 401, array( 'error' => array( 'type' => 'InvalidApiKey' ) ) );
		self::assertFalse( $invalid['configured'] );
		self::assertTrue( $invalid['verified'] );

		// An unreachable gateway is the one state that is not verified.
		self::assertFalse( $diagnostics->classify( 0, null, new \RuntimeException( 'down' ) )['verified'] );
	}

	/**
	 * `verify_state()` reads the published buckets instead of restating them.
	 *
	 * The defect these buckets document was one missing entry in one of two
	 * hand-maintained lists. `verify_state()` is the remaining second copy: it
	 * spelled both lists out inline, so a state added to the vocabulary would
	 * still be silently forgotten there and every probe outcome would be
	 * bucketed by hand.
	 *
	 * The two lists are equal by value, so no behavioural assertion can tell
	 * the copies apart — the duplicate literals themselves are the defect, so
	 * the assertion is that they are gone from that method body.
	 *
	 * @return void
	 */
	public function test_verify_state_has_no_hand_maintained_state_lists(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../src/Availability/ConnectionDiagnostics.php' );

		$start = strpos( $source, 'public function verify_state' );
		self::assertNotFalse( $start, 'verify_state() must exist.' );

		// Slice just this method body: from its signature to whichever member
		// comes next, so a literal elsewhere in the class cannot fail this.
		$next_public  = strpos( $source, "\n\tpublic function ", $start );
		$next_private = strpos( $source, "\n\tprivate function ", $start );
		$ends         = array_filter(
			array( $next_public, $next_private ),
			static fn( $offset ): bool => false !== $offset
		);
		$end = $ends ? min( $ends ) : strlen( $source );
		$body = substr( $source, $start, $end - $start );

		// The defect was a second copy of each list spelled out inline. The
		// method still legitimately *returns* 'valid' and 'invalid_key', so the
		// assertion targets the list literals, not the return values.
		self::assertDoesNotMatchRegularExpression(
			'/in_array\(\s*\$state,\s*array\(/',
			$body,
			'verify_state() must read the published buckets, not a hand-maintained array.'
		);
		foreach ( array( 'verified', 'no_credits', 'rate_limited', 'free_tier_limit', 'invalid_key', 'not_configured' ) as $state ) {
			self::assertStringNotContainsString(
				"array( '" . $state . "'",
				$body,
				"verify_state() must not restate the state list containing '{$state}'."
			);
		}

		self::assertStringContainsString(
			'self::isConfiguredState',
			$body,
			'verify_state() must read the keyed bucket through the state helper.'
		);
		self::assertStringContainsString(
			'self::isDefinitiveNegativeState',
			$body,
			'verify_state() must read the definitive-negative bucket through the state helper.'
		);
	}
}
