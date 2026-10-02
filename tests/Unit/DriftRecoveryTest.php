<?php
/**
 * Probe-model drift recovery and the drift-status ratchet.
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
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use WordPress\AiClient\Providers\Http\DTO\Response;

	/**
	 * Transporter replaying a queue and recording requests.
	 */
	final class DriftQueueingTransporter {
		/**
		 * Queued outcomes.
		 *
		 * @var list<mixed>
		 */
		private array $queue;

		/**
		 * Requests seen.
		 *
		 * @var list<mixed>
		 */
		public array $seen = array();

		/**
		 * Constructor.
		 *
		 * @param list<mixed> $queue Outcomes.
		 */
		public function __construct( array $queue ) {
			$this->queue = $queue;
		}

		/**
		 * Replay the next outcome.
		 *
		 * @param mixed $request Request to record.
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			$this->seen[] = $request;
			$next         = array_shift( $this->queue );
			return $next;
		}
	}

	/**
	 * Transporter replaying one response for every request.
	 */
	final class UnknownDriftTransporter {
		/**
		 * Outcome.
		 *
		 * @var mixed
		 */
		private mixed $next;

		/**
		 * Constructor.
		 *
		 * @param mixed $next Response.
		 */
		public function __construct( mixed $next ) {
			$this->next = $next;
		}

		/**
		 * Replay the outcome.
		 *
		 * @param mixed $request Request.
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			return $this->next;
		}
	}

	/**
	 * Authentication double passing requests through untouched.
	 */
	final class DriftAuthentication {
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
	 * A retired probe model must not present itself as a credential failure.
	 */
	final class DriftRecoveryTest extends MonkeyTestCase {

		/**
		 * Boot the probe surface.
		 *
		 * @return void
		 */
		private function boot(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}
		}

		/**
		 * Each drift shape retries with a second model and ends on that verdict.
		 *
		 * One test over every shape rather than one per shape, so a shape added to
		 * the drift list is covered by adding a row here and cannot silently go
		 * untested.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_every_drift_shape_retries_and_never_reports_invalid_key(): void {
			$this->boot();
			$shapes = array(
				'401 ModelError'   => array( 401, array( 'error' => array( 'type' => 'ModelError' ) ) ),
				'401 model_error'  => array( 401, array( 'error' => array( 'type' => 'model_error' ) ) ),
				'400 model_not_found' => array( 400, array( 'error' => array( 'type' => 'model_not_found' ) ) ),
				'404 model_not_found' => array( 404, array( 'error' => array( 'type' => 'model_not_found' ) ) ),
				'404 empty body'   => array( 404, null ),
			);

			foreach ( $shapes as $label => $shape ) {
				list( $status, $data ) = $shape;

				Functions\when( 'get_transient' )->justReturn( false );
				Functions\when( 'set_transient' )->justReturn( true );
				Functions\when( 'delete_transient' )->justReturn( true );
				Functions\when( 'wp_rand' )->justReturn( 0 );

				$transporter  = new DriftQueueingTransporter(
					array(
						new Response( $status, $data ),
						new Response( 401, array( 'error' => array( 'type' => 'CreditsError' ) ) ),
					)
				);
				$availability = new OpenCodeProviderAvailability( 'go' );
				$availability->setHttpTransporter( $transporter );
				$availability->setRequestAuthentication( new DriftAuthentication() );

				self::assertTrue( $availability->isConfigured(), $label . ' must not read as a failed connection.' );
				self::assertSame(
					'no_credits',
					$availability->getLastResult()['state'],
					$label . ': the retried probe decides the verdict.'
				);
				self::assertCount( 2, $transporter->seen, $label . ' must trigger the second candidate probe.' );
				self::assertNotSame(
					OpenCodeProviderAvailability::PROBE_MODEL,
					$transporter->seen[1]->getData()['model'],
					$label . ': the retry must use a different reviewed, paid allowlisted model.'
				);
			}
		}

		/**
		 * A model-side 401 classifies as its own verdict, never as invalid_key.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_model_side_401_is_not_an_invalid_key(): void {
			$this->boot();
			$diagnostics = new ConnectionDiagnostics();

			foreach ( array( 'ModelError', 'model_error', 'model-error', 'MODELERROR', 'model_not_found', 'UnsupportedModel' ) as $type ) {
				$result = $diagnostics->classify( 401, array( 'error' => array( 'type' => $type ) ) );
				self::assertSame( 'probe_model_unavailable', $result['state'], $type );
				self::assertTrue( $result['configured'], $type );
				self::assertContains( $result['state'], ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES, $type );
				self::assertNotContains( $result['state'], ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES, $type );
			}
		}

		/**
		 * An unlisted 401 stays a definitive credential rejection.
		 *
		 * The allowlist is on the model-side types, so an upstream rename cannot
		 * degrade a definitive rejection into an unverifiable one.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unlisted_401_is_a_rejected_credential(): void {
			$this->boot();
			$diagnostics = new ConnectionDiagnostics();

			foreach ( array( 'AuthError', 'InvalidApiKey', 'permission_denied_error', '' ) as $type ) {
				$data   = '' === $type ? null : array( 'error' => array( 'type' => $type ) );
				$result = $diagnostics->classify( 401, $data );
				$label  = '' === $type ? 'body-less 401' : $type;
				self::assertSame( 'invalid_key', $result['state'], $label );
				self::assertContains( $result['state'], ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES, $label );
			}
		}

		/**
		 * RATCHET: both drift-status consumers read the one published list.
		 *
		 * Two copies of one fact is what produced three separate defects in a
		 * single review session, so the two consumers are pinned to the same
		 * constant and the literals are banned from the file that holds them.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_both_drift_consumers_read_the_published_constant(): void {
			$this->boot();
			$source = (string) file_get_contents(
				dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php'
			);

			// Side 1: the predicate's OWN body references the constant. The
			// body is extracted up to its own closing brace, because a regex
			// that runs on to the end of the file would also match the constant
			// in a LATER method, and would therefore pass even if this predicate
			// had been changed to a literal.
			self::assertSame(
				1,
				preg_match(
					'/private function isProbeModelDrift\\( array \\$result \\): bool \\{(?<body>.*?)\\n\\t\\}/s',
					$source,
					$matches
				),
				'isProbeModelDrift() body must be locatable, or this ratchet proves nothing.'
			);
			self::assertStringContainsString(
				'ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES',
				$matches['body'] ?? '',
				'isProbeModelDrift() must read the published constant. A literal there is a second copy of the list, and the second copy is the defect this ratchet prevents.'
			);

			// The literals must not reappear anywhere in the consumer file.
			self::assertDoesNotMatchRegularExpression(
				'/array\\(\\s*400\\s*,\\s*404\\s*\\)/',
				$source,
				'A literal 400/404 list in this file is a second definition of the drift set.'
			);

			// There is exactly ONE drift predicate. The retry and the cache
			// window are the same fact; a second predicate encoding it separately
			// is the divergence that let a 401 ModelError drift take the
			// one-minute window while the 400/404 drift took the long one.
			self::assertSame(
				0,
				preg_match( '/private function is[A-Za-z]*Drift[A-Za-z]*\\(/', $source, $unused ) - 1,
				'Only isProbeModelDrift() may decide what drift means; a second drift predicate is the defect this ratchet prevents.'
			);

			self::assertSame(
				array( 400, 404 ),
				array_values( ConnectionDiagnostics::PROBE_MODEL_DRIFT_STATUSES ),
				'The drift set is the two statuses a renamed model actually answers.'
			);
		}

		/**
		 * RATCHET: the drift cache window is the long one, for BOTH drift shapes.
		 *
		 * Side 2 of the drift ratchet, and behavioural rather than source-level:
		 * the same predicate that triggers the retry must also decide that the
		 * verdict is cached long. When these two disagreed, a retired model
		 * answering 401 ModelError took the one-minute window and cost two paid
		 * probes a minute indefinitely, while 400/404 took the long window.
		 *
		 * A 5xx is the control: it is could-not-be-checked and NOT drift, so it
		 * must still take the short window. Without the control this test would
		 * pass if every could-not-be-checked verdict were cached long.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_drift_shapes_take_the_long_cache_window_and_others_do_not(): void {
			$this->boot();
			$shapes = array(
				'401 ModelError'      => array( 401, array( 'error' => array( 'type' => 'ModelError' ) ), true ),
				'400 model_not_found' => array( 400, array( 'error' => array( 'type' => 'model_not_found' ) ), true ),
				'404 model_not_found' => array( 404, array( 'error' => array( 'type' => 'model_not_found' ) ), true ),
				'500 server error'    => array( 500, null, false ),
				// Also could-not-be-checked, but NOT drift: the control that
				// proves the window is chosen from the drift status list and not
				// merely from bucket membership.
				'403 forbidden'       => array( 403, null, false ),
			);

			foreach ( $shapes as $label => $shape ) {
				list( $status, $data, $is_drift ) = $shape;

				$ttls = array();
				Functions\when( 'get_transient' )->justReturn( false );
				Functions\when( 'set_transient' )->alias(
					static function ( string $key, mixed $value, int $ttl ) use ( &$ttls ): bool {
						$ttls[ $key ] = $ttl;
						return true;
					}
				);
				Functions\when( 'delete_transient' )->justReturn( true );
				Functions\when( 'wp_rand' )->justReturn( 0 );

				$availability = new OpenCodeProviderAvailability( 'go' );
				$availability->setHttpTransporter( new UnknownDriftTransporter( new Response( $status, $data ) ) );
				$availability->setRequestAuthentication( new DriftAuthentication() );
				$availability->diagnose();

				$ttl = (int) ( $ttls['opencode_connector_avail_go'] ?? 0 );
				self::assertGreaterThan( 0, $ttl, $label . ': the verdict must be cached, or every call re-probes.' );
				if ( $is_drift ) {
					self::assertGreaterThanOrEqual(
						5 * MINUTE_IN_SECONDS,
						$ttl,
						$label . ' is drift and must not be re-probed every minute; the retry doubles the cost of each one.'
					);
				} else {
					self::assertSame(
						MINUTE_IN_SECONDS,
						$ttl,
						$label . ' is not drift and keeps the short window.'
					);
				}
			}
		}

		/**
		 * RATCHET: the state buckets stay disjoint and `unknown` fails open.
		 *
		 * `unknown` in the could-not-be-checked bucket is the bug fix in this PR.
		 * If it is ever removed, an unrecognised upstream status disconnects
		 * working keys again.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unknown_stays_in_the_could_not_be_checked_bucket(): void {
			$this->boot();

			self::assertContains(
				'unknown',
				ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES,
				'An unrecognised response is not evidence about the credential; it must fail open.'
			);
			self::assertContains( 'probe_model_unavailable', ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES );
			self::assertSame(
				array(),
				array_intersect( ConnectionDiagnostics::KEYED_STATES, ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES ),
				'The buckets must stay disjoint, or the verdict order decides the outcome instead of the state.'
			);
			self::assertSame(
				array(),
				array_intersect( ConnectionDiagnostics::KEYED_STATES, ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES )
			);
		}
	}
}
