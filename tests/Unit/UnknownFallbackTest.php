<?php
/**
 * Characterisation spec for the unrecognised-response fallback.
 *
 * @package OpenCodeConnector
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
	 * A transporter double replaying one response.
	 */
	final class UnknownFallbackTransporter {
		/**
		 * Queued outcome.
		 *
		 * @var mixed
		 */
		private mixed $next;

		/**
		 * Requests seen.
		 *
		 * @var int
		 */
		public int $seen = 0;

		/**
		 * Constructor.
		 *
		 * @param mixed $next Response.
		 */
		public function __construct( mixed $next ) {
			$this->next = $next;
		}

		/**
		 * Replay the queued outcome.
		 *
		 * @param mixed $request Request.
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			++$this->seen;
			return $this->next;
		}
	}

	/**
	 * An authentication double passing requests through untouched.
	 */
	final class UnknownFallbackAuthentication {
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
	 * An unrecognised response must fail open, not disconnect a working key.
	 */
	final class UnknownFallbackTest extends MonkeyTestCase {

		/**
		 * An unrecognised 4xx falls back to last-known-good instead of false.
		 *
		 * `unknown` is what an unrecognised response classifies to. It proves
		 * nothing about the credential, so it belongs in the same fallback bucket
		 * as `uncheckable`. With no last-known-good it still reports not
		 * configured, which is the whole point of the bucket: it fails open, not
		 * up.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unrecognized_response_falls_back_to_last_known_good(): void {
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
			Functions\when( 'wp_rand' )->justReturn( 0 );

			foreach ( array( 400, 402, 403, 404 ) as $status ) {
				$availability = new OpenCodeProviderAvailability( 'go' );
				$availability->setHttpTransporter( new UnknownFallbackTransporter( new Response( $status, null ) ) );
				$availability->setRequestAuthentication( new UnknownFallbackAuthentication() );

				self::assertSame(
					'unknown',
					$availability->diagnose()['state'],
					$status . ' is unrecognised.'
				);
				self::assertTrue(
					$availability->isConfigured(),
					$status . ' with last-known-good must stay connected; it proved nothing about the key.'
				);
			}
		}

		/**
		 * The same unrecognised response still reports not-configured with no
		 * last-known-good. Failing open must not become failing positive.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unrecognized_response_without_last_known_good_is_not_configured(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}

			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			foreach ( array( 400, 402, 403, 404 ) as $status ) {
				$availability = new OpenCodeProviderAvailability( 'go' );
				$availability->setHttpTransporter( new UnknownFallbackTransporter( new Response( $status, null ) ) );
				$availability->setRequestAuthentication( new UnknownFallbackAuthentication() );

				self::assertFalse(
					$availability->isConfigured(),
					$status . ' with no last-known-good has nothing to fall back to.'
				);
			}
		}
	}
}
