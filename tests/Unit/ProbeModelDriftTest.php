<?php
/**
 * Probe-model drift specs.
 *
 * `PROBE_MODEL` is a hard-coded constant. The probe sends exactly that one
 * model, so if the model is retired, renamed or withdrawn upstream, EVERY
 * probe on EVERY site fails at once — and a failure that reads as a
 * credential verdict deletes the last-known-good flag that `isConfigured()`
 * falls back to. That is a mass false-invalidation of keys that were fine
 * when they were entered.
 *
 * A response that names the probe model as the thing that is wrong is
 * therefore evidence about the PROBE, not about the credential, and has to
 * classify as indeterminate. These specs pin that by behaviour — what the
 * flag does, what `isConfigured()` reports, what the cache window is — not by
 * asserting on the contents of a constant.
 *
 * @package OpenCodeConnector
 * @since 0.1.7
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
	 * Transporter double replaying one queued response.
	 */
	final class ProbeModelDriftTransporter {

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
	 * Pass-through authentication double.
	 */
	final class ProbeModelDriftAuthentication {

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
	 * Probe-model drift specs.
	 *
	 * @package OpenCodeConnector
	 * @since 0.1.7
	 */
	final class ProbeModelDriftTest extends MonkeyTestCase {

		/**
		 * Boot the autoloader and the time constants the probe reads.
		 *
		 * @return void
		 */
		private function boot(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}
			if ( ! defined( 'DAY_IN_SECONDS' ) ) {
				define( 'DAY_IN_SECONDS', 86400 );
			}
		}

		/**
		 * Every drift shape a gateway plausibly answers with.
		 *
		 * The 401 rows are the load-bearing ones. On the unfixed base a 401 with
		 * no CreditsError classifies to `invalid_key`, which IS a definitive
		 * negative — so `applyLastGood()` clears the flag and the site is
		 * disconnected. A gateway is entitled to answer 401 for a model the key
		 * may not reach, and when the model is retired it may answer it for every
		 * key at once. The 400/404 rows are the shapes issue #127 already saw,
		 * and they are included so a rename that only fixed the 401 case still
		 * shows up as a distinct, named state rather than a generic `unknown`.
		 *
		 * @return array<string, array{0: int, 1: array<string, mixed>}>
		 */
		private function drift_responses(): array {
			$model = OpenCodeProviderAvailability::PROBE_MODEL;

			return array(
				'404 model_not_found (OpenAI shape)'      => array(
					404,
					array(
						'error' => array(
							'message' => sprintf( 'The model `%s` does not exist.', $model ),
							'type'    => 'invalid_request_error',
							'param'   => 'model',
							'code'    => 'model_not_found',
						),
					),
				),
				'400 invalid model, param=model'         => array(
					400,
					array(
						'error' => array(
							'message' => sprintf( 'Invalid model: %s.', $model ),
							'type'    => 'invalid_request_error',
							'param'   => 'model',
						),
					),
				),
				'401 model withdrawn, key is fine'        => array(
					401,
					array(
						'error' => array(
							'message' => sprintf( 'Model %s has been retired and is not available for this key.', $model ),
							'type'    => 'model_unavailable',
						),
					),
				),
				'401 gateway says the key lacks the model' => array(
					401,
					array(
						'error' => array(
							'message' => sprintf( 'Your API key does not have access to model %s.', $model ),
							'type'    => 'insufficient_permissions',
							'code'    => 'model_not_found',
						),
					),
				),
				'404 bare NotFoundError naming the model' => array(
					404,
					array(
						'error' => array(
							'type'    => 'NotFoundError',
							'message' => sprintf( 'unknown model %s', $model ),
						),
					),
				),
				// Only the message rule can reach this one: no `param`, no code,
				// and a type that names no model. It is here because every other
				// row is caught by the code or param rule, which left the message
				// rule unexercised — and it was broken while unexercised. Model
				// ids contain the characters the identifier normaliser strips, so
				// matching a normalised model name against a raw message never
				// matches, and the rule silently did nothing for
				// `deepseek-v4-flash`, the very model this plugin probes with.
				'404 message-only, hyphenated model id' => array(
					404,
					array(
						'error' => array(
							'type'    => 'some_gateway_error',
							'message' => sprintf( 'Model %s has been retired.', $model ),
						),
					),
				),
			);
		}

		/**
		 * A retired probe model is indeterminate and never clears the flag.
		 *
		 * This is the whole of issue #127's mass-invalidation hazard. The flag is
		 * the only thing that keeps `isConfigured()` reporting a working key
		 * while the probe cannot reach a verdict, and it is the thing a
		 * definitive negative destroys. So the assertion is on the flag itself,
		 * captured through `delete_transient`, rather than on the state name: a
		 * verdict that merely renames itself while still clearing the flag would
		 * pass every state-name assertion and still disconnect every site.
		 *
		 * The armed flag is seeded on purpose. On a cold flag an indeterminate
		 * outcome and a definitive one both read "not configured", so the test
		 * could not tell the two apart — that is the same mistake the cold-cache
		 * half of ProbeSemanticsTest makes by construction, and why the
		 * control assertion at the end of this method is load-bearing.
		 *
		 * The state assertion is load-bearing in the other direction, and was
		 * added because this spec passed for the WRONG reason before it was
		 * there. Three of the assertions below hold just as well for a verdict
		 * that fell through to the generic `unknown` bucket, which also never
		 * clears the flag — so a classifier whose attribution rules were
		 * entirely dead still showed a green row here. Requiring the attributed
		 * state is what makes "the rule fired" observable, rather than only
		 * "the outcome was safe". Which is not redundant with
		 * test_responses_without_model_evidence_are_not_attributed_to_drift: that
		 * one pins the rows that must NOT be attributed, and this one pins the
		 * rows that must.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_a_retired_probe_model_never_clears_last_known_good(): void {
			$this->boot();

			$flag    = Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX;
			$deleted = array();

			foreach ( $this->drift_responses() as $label => $case ) {
				list( $status, $body ) = $case;

				$store = array( $flag => 1 );
				$seen  = array();
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
				$deleted = array();
				Functions\when( 'delete_transient' )->alias(
					static function ( string $key ) use ( &$store, &$deleted ): bool {
						$deleted[] = $key;
						unset( $store[ $key ] );
						return true;
					}
				);
				Functions\when( 'wp_rand' )->justReturn( 0 );

				$availability = new OpenCodeProviderAvailability( 'go' );
				$availability->setHttpTransporter( new ProbeModelDriftTransporter( new Response( $status, $body ) ) );
				$availability->setRequestAuthentication( new ProbeModelDriftAuthentication() );

				// diagnose() runs the probe, and the probe is what would clear the
				// flag, so it has to come first: isConfigured() then reads the
				// cached verdict rather than probing again, and a second probe
				// would hide the delete behind a cache hit.
				$state      = $availability->diagnose()['state'];
				$configured = $availability->isConfigured();

				self::assertNotContains(
					$flag,
					$deleted,
					$label . ': probe-model drift must never delete last-known-good. That flag is the fail-open fallback; clearing it disconnects every site whose probe model was retired at once.'
				);
				self::assertSame(
					ConnectionDiagnostics::PROBE_MODEL_UNAVAILABLE_STATE,
					$state,
					$label . ': the response must be attributed to the probe model, not fall through to the generic bucket — an unattributed row is also safe, which is why the flag assertion alone cannot see a dead rule.'
				);
				self::assertNotSame(
					'invalid_key',
					$state,
					$label . ': a failure the response attributes to the probe model is not a credential verdict.'
				);
				self::assertTrue(
					$configured,
					$label . ': the armed last-known-good flag must carry a working key through probe-model drift.'
				);
				self::assertSame(
					1,
					$store[ $flag ] ?? null,
					$label . ': the flag must still be armed after a drift response.'
				);
			}
		}

		/**
		 * Control: a proven bad key still disconnects immediately.
		 *
		 * Without this, every assertion above also holds for an `applyLastGood()`
		 * that never touches the flag at all. "The flag survived" is only evidence
		 * that the flag can be destroyed when something else does destroy it.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_a_genuinely_invalid_key_still_clears_last_known_good(): void {
			$this->boot();

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
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter(
				new ProbeModelDriftTransporter( new Response( 401, array( 'error' => array( 'type' => 'InvalidApiKey' ) ) ) )
			);
			$availability->setRequestAuthentication( new ProbeModelDriftAuthentication() );

			self::assertFalse(
				$availability->isConfigured(),
				'A proven bad credential must still report not-configured.'
			);
			self::assertContains(
				$flag,
				$deleted,
				'A proven bad credential must still clear the flag immediately, or the fail-open above proves nothing.'
			);
		}

		/**
		 * Drift gets its own state rather than being folded into `unknown`.
		 *
		 * `unknown` is the verdict for a response this plugin has no rule for.
		 * A response that NAMES the probe model as the unavailable thing is a
		 * different failure with a different cause, and collapsing it into
			`unknown` means the mass-invalidation case is indistinguishable from the
		 * unrelated one — which is how a real drift would be debugged as
		 * "something 400s us" instead of "our probe model was retired".
		 *
		 * Asserted on behaviour, not on the constant: a state name asserted
		 * against itself proves nothing, so the assertions below are that the
		 * verdict is bucketed to fail open, is not a credential verdict, and is
		 * not the generic bucket.
		 *
		 * @return void
		 */
		public function test_drift_is_bucketed_indeterminate_and_not_as_a_credential_verdict(): void {
			self::assertContains(
				'probe_model_unavailable',
				ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES,
				'Probe-model drift adjudicates nothing about the credential; it must fall back to last-known-good.'
			);
			self::assertNotContains(
				'probe_model_unavailable',
				ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES,
				'Only a proven bad or missing credential may clear last-known-good.'
			);
			self::assertNotContains(
				'probe_model_unavailable',
				ConnectionDiagnostics::KEYED_STATES,
				'The gateway never accepted the key — it rejected the model. This is not evidence the credential works.'
			);
			self::assertNotSame(
				ConnectionDiagnostics::UNKNOWN_STATE,
				'probe_model_unavailable',
				'Drift must not be reported as the generic catch-all: it has a known cause.'
			);
		}

		/**
		 * A response that carries no model evidence is not drift.
		 *
		 * The attribution has to stay specific. A bare 404, or the Go gateway's
		 * MissingSessionID 400, say nothing about any model — and guessing that
		 * they are drift would make every unrecognised response indistinguishable
		 * from the retired-model case, which is the exact over-reach that would
		 * let a real credential problem hide behind a plausible label.
		 *
		 * These shapes are already indeterminate under the generic bucket; this
		 * asserts that recognising drift did not steal them.
		 *
		 * @return void
		 */
		public function test_responses_without_model_evidence_are_not_attributed_to_drift(): void {
			$diagnostics = new ConnectionDiagnostics();
			$model       = OpenCodeProviderAvailability::PROBE_MODEL;

			$bare_404 = $diagnostics->classify( 404, null, null, $model );
			self::assertSame(
				'unknown',
				$bare_404['state'],
				'A 404 with no body names no model. It stays the generic bucket rather than being attributed.'
			);
			self::assertFalse(
				$bare_404['configured'],
				'An indeterminate verdict must never assert a credential is configured.'
			);

			$missing_session = $diagnostics->classify(
				400,
				array( 'error' => array( 'type' => 'MissingSessionID' ) ),
				null,
				$model
			);
			self::assertSame(
				'unknown',
				$missing_session['state'],
				'A MissingSessionID 400 is about the request shape, not the model.'
			);

			$invalid_key = $diagnostics->classify(
				401,
				array( 'error' => array( 'type' => 'InvalidApiKey' ) ),
				null,
				$model
			);
			self::assertSame(
				'invalid_key',
				$invalid_key['state'],
				'A 401 that names no model is the shape the plugin depends on to revoke a dead key.'
			);
		}

		/**
		 * A revoked key is never rescued by a model-scoped string in its response.
		 *
		 * The exemption this file asks for is permissive on purpose: it accepts
		 * weak evidence so a retired model cannot disconnect a working key. The
		 * obvious cost of that is the mirror image — a dead key whose 401 happens
		 * to mention the model staying reported as connected, off the very flag
		 * that keeps it reported as connected, for the full 30 days. These are the
		 * shapes that would do it.
		 *
		 * This is also the spec that says the exemption is not simply "any 401 is
		 * indeterminate", which is what a change could otherwise quietly make it.
		 *
		 * @return void
		 */
		public function test_a_revoked_key_is_not_rescued_by_a_model_scoped_response(): void {
			$diagnostics = new ConnectionDiagnostics();
			$model       = OpenCodeProviderAvailability::PROBE_MODEL;

			$invalid_api_key = $diagnostics->classify(
				401,
				array(
					'error' => array(
						'message' => sprintf( 'Incorrect API key provided. Model %s is billed separately.', $model ),
						'type'    => 'invalid_request_error',
						'code'    => 'invalid_api_key',
					),
				),
				null,
				$model
			);
			self::assertSame(
				'invalid_key',
				$invalid_api_key['state'],
				'The response names the credential. Model-scoped wording elsewhere in it must not resurrect a dead key.'
			);

			// Even `param: "model"` loses to an explicit credential code: that is
			// the strongest evidence available that the model was not the subject.
			$credential_wins = $diagnostics->classify(
				401,
				array(
					'error' => array(
						'message' => 'Invalid API key provided.',
						'param'   => 'model',
						'code'    => 'invalid_api_key',
					),
				),
				null,
				$model
			);
			self::assertSame(
				'invalid_key',
				$credential_wins['state'],
				'An explicit credential code must outrank `param: model`, or a revoked key is held open for 30 days.'
			);

			// And the same body with no probe context is classified identically —
			// the exemption is a property of the probe, not of the response.
			$without_context = $diagnostics->classify(
				401,
				array(
					'error' => array(
						'message' => sprintf( 'Model %s has been retired.', $model ),
						'type'    => 'model_unavailable',
					),
				)
			);
			self::assertSame(
				'invalid_key',
				$without_context['state'],
				'Without a probe model there is nothing to attribute the failure to, so classification must be unchanged.'
			);
		}

		/**
		 * A keyed outcome that happens to mention the model is still keyed.
		 *
		 * Order matters here: a 401 CreditsError is proof the gateway READ the
		 * key, and a 429 is proof it accepted it. Neither may be downgraded to
			indeterminate by a model-scoped string in the body, or a healthy key
		 * stops refreshing the flag that keeps it healthy.
		 *
		 * @return void
		 */
		public function test_a_keyed_outcome_is_never_downgraded_to_drift(): void {
			$diagnostics = new ConnectionDiagnostics();
			$model       = OpenCodeProviderAvailability::PROBE_MODEL;

			$credits = $diagnostics->classify(
				401,
				array(
					'error' => array(
						'type'    => 'CreditsError',
						'message' => sprintf( 'Out of credits; %s was not run.', $model ),
					),
				),
				null,
				$model
			);
			self::assertSame(
				'no_credits',
				$credits['state'],
				'401 CreditsError proves the key was accepted and must keep winning over any model-scoped text.'
			);

			$throttled = $diagnostics->classify(
				429,
				array( 'error' => array( 'code' => 'model_not_found' ) ),
				null,
				$model
			);
			self::assertSame(
				'rate_limited',
				$throttled['state'],
				'429 is proof the key was accepted; a model-scoped code in the body must not downgrade it.'
			);
		}

		/**
		 * Drift takes the persistent window, not the sixty-second one.
		 *
		 * A retired model does not resolve itself in a minute. The one-minute
		 * window is for transport failures and 5xx, which do — and it carries no
		 * jitter, so every site whose probe model drifted would re-probe in
		 * lockstep on the same 60-second boundary. This is a regression guard on
		 * the window routing rather than a red/green case: it holds on the
		 * unfixed base too, because the generic `unknown` window is the same
		 * window. It is here so that recognising drift as its own state cannot
		 * quietly move it onto the short branch.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_drift_takes_the_persistent_window(): void {
			$this->boot();

			$writes     = array();
			$rand_calls = 0;

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$writes ): bool {
					unset( $value );
					$writes[ $key ] = $ttl;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ) use ( &$rand_calls ): int {
					unset( $min, $max );
					++$rand_calls;
					return 0;
				}
			);

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter(
				new ProbeModelDriftTransporter(
					new Response(
						404,
						array(
							'error' => array(
								'type'    => 'invalid_request_error',
								'param'   => 'model',
								'code'    => 'model_not_found',
								'message' => 'The model does not exist.',
							),
						)
					)
				)
			);
			$availability->setRequestAuthentication( new ProbeModelDriftAuthentication() );

			$result = $availability->diagnose();
			self::assertSame(
				'probe_model_unavailable',
				$result['state'],
				'The response names the model, so it must not collapse into the generic bucket.'
			);
			self::assertSame(
				5 * 60,
				$writes[ Catalog::AVAIL_PREFIX . 'go' ] ?? null,
				'A retired model does not resolve itself in a minute; it takes the full jittered window.'
			);
			self::assertGreaterThan(
				0,
				$rand_calls,
				'The long window must stay jittered, or every affected site re-probes on the same boundary.'
			);
			self::assertArrayNotHasKey(
				Catalog::AVAIL_PREFIX . 'go' . Catalog::LAST_GOOD_SUFFIX,
				$writes,
				'Drift must not ARM the flag either: the gateway rejected the model, so nothing proved the key works.'
			);
		}

		/**
		 * The verification probe agrees.
		 *
		 * `verify()` is the opt-in one-token check the settings page reads, and it
		 * runs the same classifier against the same constant. If it kept reporting
		 * `invalid_key` while `isConfigured()` failed open, the settings screen
		 * would tell a working key's owner that their key is invalid.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_verify_reports_drift_as_could_not_be_checked(): void {
			$this->boot();

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$model = OpenCodeProviderAvailability::PROBE_MODEL;

			$drift = new OpenCodeProviderAvailability( 'go' );
			$drift->setHttpTransporter(
				new ProbeModelDriftTransporter(
					new Response(
						401,
						array( 'error' => array( 'type' => 'model_unavailable', 'message' => sprintf( 'Model %s is retired.', $model ) ) )
					)
				)
			);
			$drift->setRequestAuthentication( new ProbeModelDriftAuthentication() );

			self::assertSame(
				'could-not-be-checked',
				$drift->verify()['state'],
				'The verification probe must not report a working key as invalid because the probe model was retired.'
			);

			$revoked = new OpenCodeProviderAvailability( 'go' );
			$revoked->setHttpTransporter(
				new ProbeModelDriftTransporter(
					new Response( 401, array( 'error' => array( 'type' => 'InvalidApiKey' ) ) )
				)
			);
			$revoked->setRequestAuthentication( new ProbeModelDriftAuthentication() );

			self::assertSame(
				'invalid_key',
				$revoked->verify()['state'],
				'Control: a proven bad credential still verifies as invalid.'
			);
		}
	}
}