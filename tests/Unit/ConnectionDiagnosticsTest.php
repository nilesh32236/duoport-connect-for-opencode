<?php
/**
 * Tests for credential-blind connection classification.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey;
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

	/**
	 * Design A is the default: unrecognised, non-definitive responses fail open.
	 *
	 * This is the shipped posture. Two buckets are credential-blind yet still
	 * `configured`, so isConfigured() falls back to last-known-good: a
	 * model-side 401, and any other unrecognised 4xx (402/403 included).
	 */
	public function test_default_posture_fails_open_for_unrecognized_responses(): void {
		$diagnostics = new ConnectionDiagnostics();

		$model_side = $diagnostics->classify( 401, array( 'error' => array( 'type' => 'ModelError' ) ) );
		self::assertSame( 'probe_model_unavailable', $model_side['state'], 'A model-side 401 is not a credential verdict by default.' );
		self::assertTrue( $model_side['configured'], 'Design A keeps a model-side 401 fail-open.' );

		foreach ( array( 402, 403, 404 ) as $status ) {
			$unknown = $diagnostics->classify( $status );
			self::assertSame( 'unknown', $unknown['state'], $status . ' is unrecognised by default.' );
			self::assertTrue( $unknown['configured'], $status . ' stays configured under Design A, which is the 30-day exposure.' );
		}
	}

	/**
	 * The deny filter flips the `unknown` bucket.
	 *
	 * Without this the shipped Design A code could be wrong in a way nothing
	 * would catch, which is the same defect class as the bug being flagged.
	 * Scope is the unrecognised 4xx fallthrough only: 400/404 are excluded as
	 * drift-recovery signals (see the next test), and 1xx/3xx are excluded as
	 * outside the status class the flag is reasoned about.
	 */
	public function test_deny_filter_downgrades_the_unknown_bucket_to_invalid_key(): void {
		Monkey\Filters\expectApplied( 'duoport_probe_deny_unrecognized' )
			->atLeast()->once()
			->andReturn( true );

		$diagnostics = new ConnectionDiagnostics();

		foreach ( array( 402, 403, 405, 407, 418, 422 ) as $status ) {
			$denied = $diagnostics->classify( $status );
			self::assertSame( 'invalid_key', $denied['state'], $status . ' is denied under Design B.' );
			self::assertFalse( $denied['configured'], $status . ' clears last-known-good under Design B.' );
		}
	}

	/**
	 * Design B must NOT reach the drift statuses: 400/404 are the retry path.
	 *
	 * Second instance of the model-side defect class, and the same shape as
	 * it. isProbeModelDrift() treats an `unknown` verdict at 400/404 as
	 * recoverable probe-model drift and retries with a second reviewed paid
	 * model. If Design B denied those statuses it would return `invalid_key`,
	 * which is not a settled state: last-known-good would be destroyed and the
	 * probe would have no path back. Same traded failure as the model-side 401
	 * above, so it gets the same permanent guard rather than a doc note.
	 *
	 * The filter is asserted `->never()` as well as the state, so a future edit
	 * cannot consult the filter for these statuses and then decline to act:
	 * only an unreachable filter can keep this bucket out of scope.
	 */
	public function test_deny_filter_never_reaches_the_drift_statuses(): void {
		Monkey\Filters\expectApplied( 'duoport_probe_deny_unrecognized' )
			->never();

		$diagnostics = new ConnectionDiagnostics();

		// Driven by the published list, so a status added to it is covered here
		// automatically rather than silently becoming denyable.
		foreach ( ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES as $status ) {
			$result = $diagnostics->classify( $status, array( 'error' => array( 'type' => 'model_not_found' ) ) );

			self::assertSame(
				'unknown',
				$result['state'],
				$status . ' must stay the drift-retry signal even with Design B enabled.'
			);
			self::assertSame(
				$status,
				$result['status'],
				$status . ' must keep its status, which is what isProbeModelDrift() matches on.'
			);
			self::assertTrue(
				$result['configured'],
				$status . ' must not clear last-known-good; a renamed probe model is not a revoked credential.'
			);
			self::assertSame(
				'could-not-be-checked',
				$diagnostics->verify_state( $result ),
				$status . ' must remain unverifiable rather than definitively invalid.'
			);
		}

		self::assertSame(
			array( 400, 404 ),
			array_values( ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES ),
			'The exemption list must stay the two statuses the probe actually recovers from.'
		);
	}

	/**
	 * Design B's scope is 4xx only: a 1xx or 3xx is not an authorization signal.
	 *
	 * The guard originally fired for any status that reached the `unknown`
	 * fallthrough, which meant a redirect surfaced by a gateway (302/307) or an
	 * informational 1xx would be denied and clear last-known-good. Neither
	 * says anything about the credential, so denying on it is exactly the
	 * false disconnect the posture change exists to avoid — a site behind a
	 * redirecting proxy would flap between Connected and Not connected.
	 */
	public function test_deny_filter_never_reaches_1xx_or_3xx(): void {
		Monkey\Filters\expectApplied( 'duoport_probe_deny_unrecognized' )
			->never();

		$diagnostics = new ConnectionDiagnostics();

		foreach ( array( 100, 101, 301, 302, 303, 304, 307, 308 ) as $status ) {
			$result = $diagnostics->classify( $status );

			self::assertSame(
				'unknown',
				$result['state'],
				$status . ' is outside the flag scope, so Design B must not deny it.'
			);
			self::assertTrue(
				$result['configured'],
				$status . ' must not clear last-known-good; a redirect is not a revoked key.'
			);
			self::assertSame(
				$status,
				$result['status'],
				$status . ' keeps its status for diagnostics.'
			);
		}
	}

	/**
	 * Design B must NOT reach the model-side 401: that is the drift signal.
	 *
	 * Regression guard for a defect found in review. `probe_model_unavailable`
	 * is the sole member of OpenCodeProviderAvailability::SETTLED_STATES, so
	 * it is what `isProbeModelDrift()` matches to retry with a second reviewed
	 * paid model. An earlier draft of this flag also denied that bucket;
	 * because `invalid_key` is not a settled state, a site that opted in could
	 * never recover from a retired probe model - last-known-good destroyed with
	 * no path back. That is a worse failure than the one the flag fixes, so the
	 * exclusion is permanent and this test is its guard.
	 */
	public function test_deny_filter_never_reaches_the_model_side_401(): void {
		Monkey\Filters\expectApplied( 'duoport_probe_deny_unrecognized' )
			->never();

		$diagnostics = new ConnectionDiagnostics();

		foreach ( array( 'ModelError', 'model_error', 'MODEL-ERROR', 'model_not_found', 'UnsupportedModel' ) as $type ) {
			$result = $diagnostics->classify( 401, array( 'error' => array( 'type' => $type ) ) );
			self::assertSame(
				'probe_model_unavailable',
				$result['state'],
				$type . ' must stay fail-open even with Design B enabled, or drift recovery is lost.'
			);
			self::assertTrue(
				$result['configured'],
				$type . ' must not clear last-known-good; a retired probe model is not a revoked credential.'
			);
		}
	}

	/**
	 * The model-side verdict stays in SETTLED_STATES so drift still retries.
	 *
	 * Pins the coupling the exclusion above depends on. SETTLED_STATES is
	 * private, so this is a source-level ratchet in the style already used by
	 * tests/Unit/MetadataRowIdTypeTest.php: if a refactor ever drops the state
	 * from that set, the retry silently stops, the `->never()` guard above
	 * would still pass, and the recovery path would already be broken.
	 */
	public function test_model_side_verdict_remains_a_settled_drift_state(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php'
		);

		self::assertMatchesRegularExpression(
			"/const SETTLED_STATES = array\( 'probe_model_unavailable' \);/",
			$source,
			'probe_model_unavailable must remain the settled drift state; dropping it removes probe-model retry and therefore the recovery path the deny filter is scoped to protect.'
		);
	}

	/**
	 * isProbeModelDrift() reads the shared status list, not a copy of it.
	 *
	 * Closes the loop on the two guards above. They are only equivalent while
	 * the retry predicate and the deny exemption name the same statuses: a
	 * second copy of the list would let the exemption cover 400/404 while the
	 * retry matched something else, which fails open for the worse reason — a
	 * denied bucket with no recovery path. Source-level ratchet in the style
	 * of the SETTLED_STATES check above.
	 */
	public function test_drift_retry_and_deny_exemption_share_one_status_list(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php'
		);

		self::assertMatchesRegularExpression(
			'/ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES/',
			$source,
			'isProbeModelDrift() must read the published list; a hardcoded copy lets the deny exemption and the retry diverge.'
		);
		self::assertDoesNotMatchRegularExpression(
			'/isProbeModelDrift\(\s*array[^}]*array\(\s*400,\s*404/',
			$source,
			'The literal 400/404 list must not reappear inside the drift predicate.'
		);
	}

	/**
	 * The filter is consulted with the facts a human needs to decide.
	 *
	 * The flag is a security control, so an operator switching it on without
	 * knowing which bucket is being denied would be flying blind.
	 */
	public function test_deny_filter_receives_status_type_and_state(): void {
		Monkey\Filters\expectApplied( 'duoport_probe_deny_unrecognized' )
			->once()
			->with( false, 403, 'someunreadtype', 'unknown' )
			->andReturn( false );

		$diagnostics = new ConnectionDiagnostics();
		$diagnostics->classify( 403, array( 'error' => array( 'type' => 'Some_Unread-Type' ) ) );
	}

	/**
	 * Neither design may swallow a definitive verdict or a quota signal.
	 *
	 * The flag only governs buckets that are currently credential-blind. A
	 * real bad key, an exhausted balance, and a throttle are already
	 * classified correctly and must be identical under both postures.
	 */
	public function test_flag_does_not_disturb_definitive_or_quota_verdicts(): void {
		// Every case below returns before the deny guard is reached, so the
		// meaningful assertion is that the filter is never consulted at all.
		// An earlier draft re-registered expectApplied mid-loop and compared a
		// pair computed under one posture, which proved nothing.
		Monkey\Filters\expectApplied( 'duoport_probe_deny_unrecognized' )->never();

		$diagnostics = new ConnectionDiagnostics();

		$definitions = array(
			array( 401, array( 'error' => array( 'type' => 'InvalidAPIKey' ) ), 'invalid_key' ),
			array( 401, array( 'error' => array( 'type' => 'AuthError' ) ), 'invalid_key' ),
			array( 401, array( 'error' => array( 'type' => 'CreditsError' ) ), 'no_credits' ),
			array( 429, array( 'error' => array( 'type' => 'FreeUsageLimitError' ) ), 'free_tier_limit' ),
			array( 429, array( 'error' => array( 'type' => 'RateLimitError' ) ), 'rate_limited' ),
			array( 500, null, 'uncheckable' ),
		);

		foreach ( $definitions as $case ) {
			list( $status, $data, $expected ) = $case;

			$result = $diagnostics->classify( $status, $data );

			self::assertSame( $expected, $result['state'], $status . ' must classify identically with the guard present.' );
		}
	}

	/**
	 * The 5xx and transport paths are outside the flag's scope, by design.
	 *
	 * Design B makes no claim about them in either direction: 5xx returns
	 * `uncheckable` before the guard is reached, so it cannot produce the
	 * documented "other 4xx/5xx -> false" and must not claim to.
	 */
	public function test_flag_is_not_consulted_for_5xx_or_transport_failures(): void {
		Monkey\Filters\expectApplied( 'duoport_probe_deny_unrecognized' )->never();

		$diagnostics = new ConnectionDiagnostics();

		foreach ( array( 500, 502, 503 ) as $status ) {
			$result = $diagnostics->classify( $status );
			self::assertSame( 'uncheckable', $result['state'], $status . ' stays uncheckable regardless of the flag.' );
		}

		$transport = $diagnostics->classify( 0, null, new \RuntimeException( 'no route' ) );
		self::assertSame( 'uncheckable', $transport['state'], 'A transport failure is outside the flag scope.' );
	}
}
