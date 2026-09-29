<?php
/**
 * Enum-surface probe regression specs.
 *
 * The shipped WordPress AI Client SDK serves `HttpMethodEnum::POST()` from
 * `AbstractEnum::__callStatic()` behind an `@method static` annotation, so
 * `method_exists( HttpMethodEnum::class, 'POST' )` is permanently false while
 * the factory itself resolves. `OpenCodeProviderAvailability::verify()` used
 * to gate its whole probe on that method_exists() probe, which made
 * `$surface_ok` permanently false in production: verify() short-circuited
 * into classify(0, ...) and reported `could-not-be-checked` for every user
 * with no error surfaced. The SDK stub used to declare a real static POST(),
 * so CI stayed green throughout.
 *
 * These specs pin the SDK shape the stub must model, the corrected
 * backing-constant probe, and the observable verify() behaviour that the bug
 * broke.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
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
	use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

	/**
	 * Counting transporter double proving the probe actually reached transport.
	 *
	 * @since 0.1.6
	 */
	final class EnumProbeCountingTransporter {
		/**
		 * Response to return.
		 *
		 * @var mixed
		 */
		private mixed $next;

		/**
		 * Number of send() calls.
		 *
		 * @var int
		 */
		public int $calls = 0;

		/**
		 * Constructor.
		 *
		 * @param mixed $next Response to return.
		 */
		public function __construct( mixed $next ) {
			$this->next = $next;
		}

		/**
		 * Record the call and return the queued response.
		 *
		 * @param mixed $request Request.
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			unset( $request );
			++$this->calls;
			return $this->next;
		}
	}

	/**
	 * Pass-through authentication double.
	 *
	 * @since 0.1.6
	 */
	final class EnumProbePassthroughAuthentication {
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
	 * Enum-surface probe regression specs.
	 *
	 * @package OpenCodeConnector
	 * @since 0.1.6
	 */
	final class AvailabilityEnumSurfaceProbeTest extends MonkeyTestCase {

		/**
		 * Boot the plugin autoloader and the minute constant.
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
		 * Stub transient and randomness helpers with no persisted state.
		 *
		 * @return void
		 */
		private function stub_transients_stateless(): void {
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->alias( static fn( ...$args ): bool => true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );
		}

		/**
		 * The SDK stub must model the real SDK's magic enum factories.
		 *
		 * A stub that declared a real `public static function POST()` would make
		 * `method_exists()` true here and false in production, hiding a
		 * production-only breakage from CI. This spec is what stops that
		 * regression from returning.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_stub_models_magic_enum_factory_shape(): void {
			self::assertFalse(
				method_exists( HttpMethodEnum::class, 'POST' ),
				'HttpMethodEnum::POST() must be a magic __callStatic factory, so method_exists() stays false as it is in the real SDK.'
			);
			self::assertTrue(
				defined( HttpMethodEnum::class . '::POST' ),
				'The real SDK backs POST() with a constant; the stub must too, so defined() can probe it.'
			);
			self::assertInstanceOf( HttpMethodEnum::class, HttpMethodEnum::POST() );
			self::assertSame( 'POST', HttpMethodEnum::POST()->getValue() );
		}

		/**
		 * verify() reaches transport and reports a valid key.
		 *
		 * This is the behavioural regression. When $surface_ok is wrongly
		 * computed with method_exists(), the transporter is never called and the
		 * verdict degrades to could-not-be-checked for every user.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_verify_reaches_transport_for_a_valid_key(): void {
			$this->boot();
			$this->stub_transients_stateless();

			$transporter   = new EnumProbeCountingTransporter( new Response( 200, array( 'choices' => array() ) ) );
			$availability  = new OpenCodeProviderAvailability( 'go' );
			$availability->setHttpTransporter( $transporter );
			$availability->setRequestAuthentication( new EnumProbePassthroughAuthentication() );

			$verdict = $availability->verify();

			self::assertSame(
				1,
				$transporter->calls,
				'verify() must send the probe request; a zero call count means the surface probe failed open and short-circuited.'
			);
			self::assertSame( 'valid', $verdict['state'] );
		}

		/**
		 * The surface probe must not use method_exists() on an SDK enum factory.
		 *
		 * This locks the rule the project already documents twice in
		 * AbstractOpenCodeProvider::createProviderMetadata(): magic
		 * __callStatic factories are invisible to method_exists(), so backings
		 * constants are probed with defined() instead.
		 *
		 * @return void
		 */
		public function test_surface_probe_does_not_method_exists_an_sdk_enum(): void {
			$source = (string) file_get_contents(
				dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php'
			);

			self::assertStringNotContainsString(
				"method_exists( HttpMethodEnum::class, 'POST' )",
				$source,
				'HttpMethodEnum::POST() is a magic factory; method_exists() is permanently false against the real SDK.'
			);
			self::assertStringContainsString(
				"defined( HttpMethodEnum::class . '::POST' )",
				$source,
				'The surface probe must test the backing constant instead.'
			);
		}
	}
}
