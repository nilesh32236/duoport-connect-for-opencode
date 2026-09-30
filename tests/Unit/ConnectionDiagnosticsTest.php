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
	 * reported a valid key as invalid whenever the probe model drifted. The
	 * type is matched normalized, because gateway spelling and casing are not
	 * contractual.
	 */
	public function test_model_side_401_is_not_an_invalid_key(): void {
		$diagnostics = new ConnectionDiagnostics();

		foreach ( array( 'ModelError', 'model_error', 'model-error', 'MODELERROR' ) as $type ) {
			$result = $diagnostics->classify( 401, array( 'error' => array( 'type' => $type ) ) );
			self::assertSame( 'probe_model_unavailable', $result['state'], $type );
			self::assertTrue( $result['configured'], $type );
			self::assertFalse( $result['usable'], $type );
			self::assertSame( 401, $result['status'], $type );
			// Nothing about the credential can be concluded, so verification
			// reports could-not-be-checked and keeps last-known-good state.
			self::assertSame( 'could-not-be-checked', $diagnostics->verify_state( $result ), $type );
		}
	}

	/**
	 * A 401 that is not a model-side refusal is a rejected credential.
	 *
	 * Fail-closed on purpose: an unlisted type (including a body-less 401) must
	 * not degrade into an unverifiable verdict, which would keep a revoked key
	 * displayed as connected for the whole last-known-good window after a
	 * one-word upstream rename. The canonical OpenAI-compatible spellings are
	 * all invalid keys, and so is an unrecognised type.
	 */
	public function test_unlisted_401_is_a_rejected_credential(): void {
		$diagnostics = new ConnectionDiagnostics();

		$types = array( 'AuthError', 'InvalidApiKey', 'invalid_api_key', 'authentication_error', 'permission_denied_error', 'SomeFutureError', '' );
		foreach ( $types as $type ) {
			$data     = '' === $type ? null : array( 'error' => array( 'type' => $type ) );
			$result   = $diagnostics->classify( 401, $data );
			$label    = '' === $type ? 'body-less 401' : $type;
			self::assertSame( 'invalid_key', $result['state'], $label );
			self::assertFalse( $result['configured'], $label );
			self::assertContains( $result['state'], ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES, $label );
			self::assertSame( 'invalid_key', $diagnostics->verify_state( $result ), $label );
		}
	}

	/**
	 * The credit and free-tier error types are matched normalized too.
	 *
	 * Gateway casing is not contractual, so a rename of CreditsError must not
	 * silently degrade into invalid_key (or of FreeUsageLimitError into
	 * rate_limited).
	 */
	public function test_quota_error_types_match_any_casing(): void {
		$diagnostics = new ConnectionDiagnostics();

		$credits = $diagnostics->classify( 401, array( 'error' => array( 'type' => 'credits_error' ) ) );
		self::assertSame( 'no_credits', $credits['state'] );
		self::assertTrue( $credits['configured'] );

		$free = $diagnostics->classify( 429, array( 'error' => array( 'type' => 'free_usage_limit_error' ) ) );
		self::assertSame( 'free_tier_limit', $free['state'] );
		self::assertTrue( $free['configured'] );
	}

	/**
	 * The state buckets have one home, shared with the availability probe.
	 */
	public function test_state_buckets_are_single_sourced(): void {
		self::assertSame(
			array( 'verified', 'no_credits', 'rate_limited', 'free_tier_limit' ),
			ConnectionDiagnostics::KEYED_STATES
		);
		self::assertSame( array( 'not_configured', 'invalid_key' ), ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES );
		self::assertContains( 'probe_model_unavailable', ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES );
		// The buckets must stay disjoint: a state in two buckets would make the
		// probe's verdict order, not the state, decide the credential outcome.
		self::assertSame(
			array(),
			array_intersect( ConnectionDiagnostics::KEYED_STATES, ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES )
		);
		self::assertSame(
			array(),
			array_intersect( ConnectionDiagnostics::KEYED_STATES, ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES )
		);
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
