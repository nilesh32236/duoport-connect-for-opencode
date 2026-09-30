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
		$auth        = $diagnostics->classify( 401, array( 'error' => array( 'type' => 'AuthError' ) ) );

		self::assertFalse( $invalid['configured'] );
		self::assertTrue( $invalid['verified'] );
		self::assertFalse( $invalid['usable'] );
		self::assertTrue( $credits['configured'] );
		self::assertTrue( $credits['verified'] );
		self::assertFalse( $credits['usable'] );
		self::assertSame( 'no_credits', $credits['state'] );
		self::assertSame( 'invalid_key', $auth['state'], 'The live gateway names a bad key AuthError.' );
	}

	/**
	 * A model-side 401 is not a credential verdict.
	 *
	 * Verified live: an unsupported model ID answers 401 ModelError with the
	 * same status as a bad key, so keying the verdict on the status alone
	 * reported a valid key as invalid whenever the probe model drifted.
	 */
	public function test_model_side_401_is_not_an_invalid_key(): void {
		$diagnostics = new ConnectionDiagnostics();
		$model_error = $diagnostics->classify( 401, array( 'error' => array( 'type' => 'ModelError' ) ) );
		$empty       = $diagnostics->classify( 401 );
		$future      = $diagnostics->classify( 401, array( 'error' => array( 'type' => 'SomeFutureError' ) ) );

		foreach ( array( 'model_error' => $model_error, 'empty' => $empty, 'future' => $future ) as $label => $result ) {
			self::assertSame( 'probe_model_unavailable', $result['state'], $label );
			self::assertTrue( $result['configured'], $label );
			self::assertFalse( $result['usable'], $label );
			self::assertSame( 401, $result['status'], $label );
			// Nothing about the credential can be concluded, so verification
			// reports could-not-be-checked and keeps last-known-good state.
			self::assertSame( 'could-not-be-checked', $diagnostics->verify_state( $result ), $label );
		}
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
}
