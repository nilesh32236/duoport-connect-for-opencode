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
	 * Server and transport failures are safe and unusable.
	 */
	public function test_server_and_transport_failures_are_unusable(): void {
		$diagnostics = new ConnectionDiagnostics();
		$server      = $diagnostics->classify( 503 );
		$network     = $diagnostics->classify( 0, null, new \RuntimeException( 'transport failed' ) );

		self::assertSame( 'server_error', $server['state'] );
		self::assertFalse( $server['usable'] );
		self::assertSame( 'network_error', $network['state'] );
		self::assertFalse( $network['usable'] );
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
