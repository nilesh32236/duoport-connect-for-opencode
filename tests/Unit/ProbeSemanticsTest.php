<?php
/**
 * Availability probe semantics matrix specs.
 *
 * Locks in the probe contract: 2xx true, 401 plus CreditsError true (valid
 * key, empty balance), 429 true (throttled: must not lock out valid users),
 * other 4xx false, 5xx plus transport exceptions fail open on
 * last-known-good (uncheckable verdict, never fatal).
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Availability\OpenCodeProviderAvailability;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use WordPress\AiClient\Providers\Http\DTO\Response;

	/**
	 * Transporter double replaying one queued response or throwable.
	 *
	 * @since 0.1.4
	 */
	final class FakeProbeTransporter {
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
			if ( $this->next instanceof \Throwable ) {
				throw $this->next;
			}
			return $this->next;
		}
	}

	/**
	 * Authentication double passing requests through untouched.
	 *
	 * @since 0.1.4
	 */
	final class FakeProbeAuthentication {
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
	 * Transporter double replaying a queue of outcomes and recording requests.
	 *
	 * @since 0.1.8
	 */
	final class QueueingProbeTransporter {
		/**
		 * Queued outcomes, consumed in order.
		 *
		 * @var list<mixed>
		 */
		private array $queue;

		/**
		 * Requests seen so far.
		 *
		 * @var list<mixed>
		 */
		public array $seen = array();

		/**
		 * Constructor.
		 *
		 * @param list<mixed> $queue Outcomes to replay in order.
		 */
		public function __construct( array $queue ) {
			$this->queue = $queue;
		}

		/**
		 * Replay the next queued outcome.
		 *
		 * @param mixed $request Request to record.
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			$this->seen[] = $request;
			$next         = array_shift( $this->queue );
			if ( $next instanceof \Throwable ) {
				throw $next;
			}
			return $next;
		}
	}

	/**
	 * Probe semantics matrix specs.
	 *
	 * @package OpenCodeConnector
	 * @since 0.1.4
	 */
	final class ProbeSemanticsTest extends MonkeyTestCase {

		/**
		 * Probe matrix without last-known-good: 2xx/CreditsError/429 true, everything else false.
		 *
		 * @since 0.1.4
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_probe_semantics_matrix(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ): int {
					unset( $min, $max );
					return 0;
				}
			);

			$cases = array(
				'2xx is connected'                 => array( 200, null, null, true ),
				'201 is connected'                 => array( 201, null, null, true ),
				'429 throttled stays connected'    => array( 429, null, null, true ),
				'429 FreeUsageLimitError stays connected' => array( 429, array( 'error' => array( 'type' => 'FreeUsageLimitError' ) ), null, true ),
				'401 CreditsError stays connected' => array( 401, array( 'error' => array( 'type' => 'CreditsError' ) ), null, true ),
				'401 other type is not connected'  => array( 401, array( 'error' => array( 'type' => 'InvalidApiKey' ) ), null, false ),
				'401 empty body is not connected'  => array( 401, null, null, false ),
				'400 is not connected'             => array( 400, null, null, false ),
				'403 is not connected'             => array( 403, null, null, false ),
				'500 is not connected'             => array( 500, null, null, false ),
				'transport exception degrades'     => array( 0, null, new \RuntimeException( 'network down' ), false ),
			);

			foreach ( $cases as $label => $case ) {
				list( $code, $data, $throw, $expected ) = $case;

				$availability = new OpenCodeProviderAvailability( 'go' );
				$outcome      = $throw ?? new Response( $code, $data );
				$availability->setHttpTransporter( new FakeProbeTransporter( $outcome ) );
				$availability->setRequestAuthentication( new FakeProbeAuthentication() );

				self::assertSame( $expected, $availability->isConfigured(), $label );
			}
		}

		/**
		 * The Zen catalog follows the same probe contract.
		 *
		 * @since 0.1.4
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_zen_catalog_follows_same_probe_contract(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ): int {
					unset( $min, $max );
					return 0;
				}
			);

			$ok = new OpenCodeProviderAvailability( 'zen' );
			$ok->setHttpTransporter(
				new FakeProbeTransporter( new Response( 401, array( 'error' => array( 'type' => 'CreditsError' ) ) ) )
			);
			$ok->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertTrue( $ok->isConfigured(), 'Zen 401 CreditsError stays connected.' );

			$bad = new OpenCodeProviderAvailability( 'zen' );
			$bad->setHttpTransporter( new FakeProbeTransporter( new Response( 500, null ) ) );
			$bad->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertFalse( $bad->isConfigured(), 'Zen 500 is not connected.' );
		}

		/**
		 * Fail-open: 5xx and transport failures preserve last-known-good state.
		 *
		 * @since 0.1.6
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_uncheckable_preserves_last_known_good(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ): mixed {
					return 'opencode_connector_avail_go_last_good' === $key ? 1 : false;
				}
			);
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->alias(
				static function ( int $min = 0, int $max = 0 ): int {
					unset( $min, $max );
					return 0;
				}
			);

			$server = new OpenCodeProviderAvailability( 'go' );
			$server->setHttpTransporter( new FakeProbeTransporter( new Response( 500, null ) ) );
			$server->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertTrue( $server->isConfigured(), '500 with last-known-good stays connected.' );
			self::assertSame( 'could_not_be_checked', $server->diagnose()['code'] );

			$transport = new OpenCodeProviderAvailability( 'go' );
			$transport->setHttpTransporter( new FakeProbeTransporter( new \RuntimeException( 'network down' ) ) );
			$transport->setRequestAuthentication( new FakeProbeAuthentication() );
			self::assertTrue( $transport->isConfigured(), 'Transport failure with last-known-good stays connected.' );

			// Unkeyed installs still report not configured even with a stale flag.
			$unkeyed = new OpenCodeProviderAvailability( 'go' );
			self::assertFalse( $unkeyed->isConfigured(), 'Without authentication the provider is not configured.' );
		}

		/**
		 * A could-not-be-checked verdict is cached briefly but never cached as
		 * not-connected, so a persistent outage costs one probe per window.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_uncheckable_is_cached_briefly_without_clearing_last_good(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$store = array();
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ) {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store ): bool {
					$store[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter( new FakeProbeTransporter( new Response( 500, null ) ) );
			$availability->setRequestAuthentication( new FakeProbeAuthentication() );

			// Public entry point: diagnose() runs the same probe path.
			$availability->diagnose();

			self::assertArrayHasKey( 'opencode_connector_avail_go', $store, 'Uncheckable verdict is cached.' );
			self::assertSame( 'uncheckable', $store['opencode_connector_avail_go']['state'] );
			self::assertArrayNotHasKey(
				'opencode_connector_avail_go_last_good',
				$store,
				'Uncheckable never writes last-known-good.'
			);
		}

		/**
		 * A Zen free-tier quota stop proves the key is valid and refreshes
		 * last-known-good instead of being treated as a definitive failure.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_free_tier_limit_stays_connected_and_sets_last_good(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$store = array();
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ) {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store ): bool {
					$store[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'zen' );
			$availability->setHttpTransporter(
				new FakeProbeTransporter(
					new Response( 429, array( 'error' => array( 'type' => 'FreeUsageLimitError' ) ) )
				)
			);
			$availability->setRequestAuthentication( new FakeProbeAuthentication() );

			self::assertTrue( $availability->isConfigured(), 'Free-tier limit keeps a valid key connected.' );
			self::assertSame( 1, $store['opencode_connector_avail_zen_last_good'] ?? null, 'Free-tier limit refreshes last-known-good.' );
		}

		/**
		 * Detailed state survives the transient cache and legacy projection.
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_detailed_result_survives_cache(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$cache = false;
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$cache ) {
					return 'opencode_connector_avail_go' === $key ? $cache : false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$cache ): bool {
					if ( 'opencode_connector_avail_go' === $key ) {
						$cache = $value;
					}
					return true;
				}
			);
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter(
				new FakeProbeTransporter(
					new Response( 401, array( 'error' => array( 'type' => 'CreditsError' ) ) )
				)
			);
			$availability->setRequestAuthentication( new FakeProbeAuthentication() );

			self::assertTrue( $availability->isConfigured() );
			$detailed = $availability->getLastResult();
			self::assertSame( 'no_credits', $detailed['state'] );
			self::assertFalse( $detailed['usable'] );
			self::assertIsArray( $cache );

			$availability->setHttpTransporter( new FakeProbeTransporter( new \RuntimeException( 'must not run' ) ) );
			self::assertSame( $detailed, $availability->diagnose() );
		}

		/**
		 * Missing authentication degrades to not-connected, never fatal.
		 *
		 * @since 0.1.4
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_missing_authentication_is_not_connected(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			Functions\when( 'get_transient' )->justReturn( false );

			$availability = new OpenCodeProviderAvailability( 'go' );

			self::assertFalse( $availability->isConfigured(), 'Without authentication the provider is not configured.' );
		}

		/**
		 * No unexpected upstream answer clears the last-known-good flag.
		 *
		 * A 401 ModelError (a model-side refusal), an unrecognised response,
		 * and a non-auth 4xx such as the documented Go 400 MissingSessionID or
		 * a 402/403 account limit all say nothing about the key, so they must
		 * preserve last-known-good instead of locking a valid key out.
		 *
		 * @since 0.1.8
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unexpected_upstream_states_preserve_last_known_good(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$cases = array(
				'model-side 401'   => array( 401, array( 'error' => array( 'type' => 'ModelError' ) ), 'probe_model_unavailable' ),
				'body-less 401'    => array( 401, null, 'probe_model_unavailable' ),
				'400 missing sid'  => array( 400, array( 'error' => array( 'type' => 'MissingSessionID' ) ), 'unknown' ),
				'402 account'     => array( 402, null, 'unknown' ),
				'403 account'     => array( 403, null, 'unknown' ),
			);

			foreach ( $cases as $label => $case ) {
				list( $code, $data, $expected_state ) = $case;
				$store = array( 'opencode_connector_avail_go_last_good' => 1 );

				Functions\when( 'get_transient' )->alias(
					static function ( string $key ) use ( &$store ): mixed {
						return $store[ $key ] ?? false;
					}
				);
				Functions\when( 'set_transient' )->alias(
					static function ( string $key, mixed $value, int $ttl ) use ( &$store ): bool {
						$store[ $key ] = $value;
						return true;
					}
				);
				$deleted = array();
				Functions\when( 'delete_transient' )->alias(
					static function ( string $key ) use ( &$deleted ): bool {
						$deleted[] = $key;
						return true;
					}
				);
				Functions\when( 'wp_rand' )->justReturn( 0 );

				$availability = new OpenCodeProviderAvailability( 'go' );
				$availability->setHttpTransporter( new FakeProbeTransporter( new Response( $code, $data ) ) );
				$availability->setRequestAuthentication( new FakeProbeAuthentication() );

				self::assertTrue( $availability->isConfigured(), $label . ' must stay connected through last-known-good.' );
				self::assertSame( $expected_state, $availability->getLastResult()['state'], $label );
				self::assertNotContains( 'opencode_connector_avail_go_last_good', $deleted, $label . ' must not clear last-known-good.' );
				self::assertSame( 1, $store['opencode_connector_avail_go_last_good'] ?? null, $label );
			}
		}

		/**
		 * A rejected credential is still the only definitive negative.
		 *
		 * @since 0.1.8
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_rejected_credential_clears_last_known_good(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			$store   = array( 'opencode_connector_avail_go_last_good' => 1 );
			$deleted = array();
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ): mixed {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store ): bool {
					$store[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				static function ( string $key ) use ( &$deleted ): bool {
					$deleted[] = $key;
					return true;
				}
			);
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter(
				new FakeProbeTransporter( new Response( 401, array( 'error' => array( 'type' => 'AuthError' ) ) ) )
			);
			$availability->setRequestAuthentication( new FakeProbeAuthentication() );

			self::assertFalse( $availability->isConfigured() );
			self::assertSame( 'invalid_key', $availability->getLastResult()['state'] );
			self::assertContains( 'opencode_connector_avail_go_last_good', $deleted, 'A rejected credential clears last-known-good.' );
		}

		/**
		 * Probe-model drift is retried with another reviewed, paid model.
		 *
		 * @since 0.1.8
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_probe_model_drift_retries_with_another_paid_model(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$transporter = new QueueingProbeTransporter(
				array(
					new Response( 401, array( 'error' => array( 'type' => 'ModelError' ) ) ),
					new Response( 401, array( 'error' => array( 'type' => 'CreditsError' ) ) ),
				)
			);
			$availability = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter( $transporter );
			$availability->setRequestAuthentication( new FakeProbeAuthentication() );

			self::assertTrue( $availability->isConfigured(), 'A retired probe model must not read as an invalid key.' );
			self::assertSame( 'no_credits', $availability->getLastResult()['state'] );
			self::assertCount( 2, $transporter->seen );
			self::assertSame( OpenCodeProviderAvailability::PROBE_MODEL, $transporter->seen[0]->getData()['model'] );
			self::assertNotSame(
				OpenCodeProviderAvailability::PROBE_MODEL,
				$transporter->seen[1]->getData()['model'],
				'The retry must use a different reviewed, paid allowlisted model.'
			);
		}

		/**
		 * When every candidate model is refused, the verdict is the distinct
		 * probe-model-unavailable state, not an invalid key.
		 *
		 * @since 0.1.8
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_persistent_model_drift_reports_probe_model_unavailable(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$refusal = new Response( 401, array( 'error' => array( 'type' => 'ModelError' ) ) );
			$availability = new OpenCodeProviderAvailability( 'zen' );
			$availability->setHttpTransporter( new FakeProbeTransporter( $refusal ) );
			$availability->setRequestAuthentication( new FakeProbeAuthentication() );

			self::assertFalse( $availability->isConfigured(), 'With no last-known-good there is nothing to fall back to.' );
			self::assertSame( 'probe_model_unavailable', $availability->getLastResult()['state'] );
		}
	}
}
