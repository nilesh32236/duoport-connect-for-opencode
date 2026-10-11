<?php
/**
 * Contract specs for the shared provider-request transport options.
 *
 * Pins `Request::__construct()`'s fifth argument to `?RequestOptions`
 * (the real SDK signature since 0.3.0) and asserts
 * `BuildsProviderRequest::buildFromSpec()` forwards a `RequestOptions`
 * instance — or null — but never a plain array, which TypeErrors under
 * `strict_types` against the real SDK.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Transport\BuildsProviderRequest;
use OpenCodeConnector\Transport\RequestSpec;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

require_once __DIR__ . '/Fixtures/SdkStubs.php';
require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

/**
 * Harness forwarding a RequestOptions instance.
 */
final class OptionsForwardingHarness {
	use BuildsProviderRequest;

	/**
	 * Configured options instance.
	 *
	 * @var RequestOptions|null
	 */
	private ?RequestOptions $configured;

	/**
	 * Constructor.
	 *
	 * @param RequestOptions|null $configured Options to offer.
	 */
	public function __construct( ?RequestOptions $configured = null ) {
		$this->configured = $configured ?? new RequestOptions();
	}

	/**
	 * Transport options.
	 *
	 * @return RequestOptions|null
	 */
	protected function getRequestOptions(): ?RequestOptions {
		return $this->configured;
	}

	/**
	 * Expose the shared builder for tests.
	 *
	 * @param RequestSpec $spec Request specification.
	 * @return Request
	 */
	public function make_request( RequestSpec $spec ): Request {
		return $this->buildFromSpec( $spec );
	}
}

/**
 * Harness without any getRequestOptions() method (directory shape).
 */
final class OptionsMissingHarness {
	use BuildsProviderRequest;

	/**
	 * Expose the shared builder for tests.
	 *
	 * @param RequestSpec $spec Request specification.
	 * @return Request
	 */
	public function make_request( RequestSpec $spec ): Request {
		return $this->buildFromSpec( $spec );
	}
}

/**
 * Harness offering a legacy plain-array option set.
 */
final class LegacyArrayOptionsHarness {
	use BuildsProviderRequest;

	/**
	 * Legacy transport options (must degrade to null, never be forwarded).
	 *
	 * @return array
	 */
	protected function getRequestOptions(): array {
		return array( 'timeout' => 5 );
	}

	/**
	 * Expose the shared builder for tests.
	 *
	 * @param RequestSpec $spec Request specification.
	 * @return Request
	 */
	public function make_request( RequestSpec $spec ): Request {
		return $this->buildFromSpec( $spec );
	}
}

/**
 * Request-options transport contract.
 */
final class ProviderRequestOptionsContractTest extends MonkeyTestCase {

	/**
	 * Build a spec routing through a stub provider.
	 *
	 * @return RequestSpec
	 */
	private function spec(): RequestSpec {
		$provider = new class() extends AbstractApiProvider {
		};
		return new RequestSpec( $provider::class, HttpMethodEnum::POST(), 'chat/completions', array(), null );
	}

	/**
	 * The stub Request constructor matches the real SDK fifth-argument type.
	 *
	 * The real SDK declares `__construct( HttpMethodEnum, string, array,
	 * string|array|null, ?RequestOptions )`; the stub must declare the
	 * same nullable RequestOptions fifth parameter or it masks a
	 * production TypeError from CI.
	 */
	public function test_request_fifth_argument_is_nullable_request_options(): void {
		$constructor = new \ReflectionMethod( Request::class, '__construct' );
		$params      = $constructor->getParameters();
		self::assertGreaterThanOrEqual( 5, count( $params ) );
		$type = $params[4]->getType();
		self::assertInstanceOf( \ReflectionNamedType::class, $type );
		self::assertSame( RequestOptions::class, $type->getName() );
		self::assertTrue( $type->allowsNull() );
	}

	/**
	 * A RequestOptions instance is forwarded by identity.
	 */
	public function test_request_options_object_is_forwarded(): void {
		$options = new RequestOptions();
		$request = ( new OptionsForwardingHarness( $options ) )->make_request( $this->spec() );

		self::assertSame( $options, $request->getOptions() );
	}

	/**
	 * A caller without getRequestOptions() sends null instead of fataling.
	 */
	public function test_missing_request_options_sends_null(): void {
		$request = ( new OptionsMissingHarness() )->make_request( $this->spec() );

		self::assertNull( $request->getOptions() );
	}

	/**
	 * A legacy array option set degrades to null instead of TypeErroring.
	 */
	public function test_legacy_array_options_degrade_to_null(): void {
		$request = ( new LegacyArrayOptionsHarness() )->make_request( $this->spec() );

		self::assertNull( $request->getOptions() );
	}
}
