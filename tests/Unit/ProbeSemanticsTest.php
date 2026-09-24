<?php
/**
 * Availability probe semantics matrix specs.
 *
 * Locks in the probe contract: 2xx true, 401 plus CreditsError true (valid
 * key, empty balance), 429 true (throttled: must not lock out valid users),
 * other 4xx/5xx plus transport exceptions false (fail-open to not-connected,
 * never fatal).
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
	 * Probe semantics matrix specs.
	 *
	 * @package OpenCodeConnector
	 * @since 0.1.4
	 */
	final class ProbeSemanticsTest extends MonkeyTestCase {

		/**
		 * Probe matrix: 2xx/CreditsError/429 true, everything else false.
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
	}
}
