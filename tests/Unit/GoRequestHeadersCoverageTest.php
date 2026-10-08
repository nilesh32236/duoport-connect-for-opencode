<?php
/**
 * Go header-pair coverage: verify probe and /models listing.
 *
 * Locks the two branches added for the Go header gaps: the verify() probe
 * must carry both the stable x-opencode-session and the plugin User-Agent
 * on Go while Zen stays session-only, and the Go /models listing must carry
 * the plugin User-Agent with no session header (a headerless GET has no
 * chat or image context to derive one from) while Zen stays header-free.
 *
 * @package OpenCodeConnector
 * @since 0.1.9
 */

declare(strict_types=1);

namespace WordPress\AiClient\Providers\OpenAiCompatibleImplementation {
	if ( ! class_exists( \WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory::class ) ) {
		/**
		 * Minimal OpenAI-compatible metadata directory base stub.
		 */
		abstract class AbstractOpenAiCompatibleModelMetadataDirectory {
		}
	}
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Availability\OpenCodeProviderAvailability;
	use OpenCodeConnector\Http\ClientUserAgent;
	use OpenCodeConnector\Http\SessionHeader;
	use OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory;
	use OpenCodeConnector\Metadata\OpenCodeZenModelMetadataDirectory;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use WordPress\AiClient\Providers\Http\DTO\Request;
	use WordPress\AiClient\Providers\Http\DTO\Response;
	use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

	require_once __DIR__ . '/Fixtures/SdkStubs.php';
	require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

	/**
	 * Capturing transporter double for verify() header specs.
	 *
	 * @since 0.1.9
	 */
	final class GoHeadersCoverageCapturingTransporter {
		/**
		 * Captured request.
		 *
		 * @var mixed
		 */
		public mixed $seen = null;

		/**
		 * Queued response.
		 *
		 * @var mixed
		 */
		private mixed $next;

		/**
		 * Constructor.
		 *
		 * @param mixed $next Response to return.
		 */
		public function __construct( mixed $next ) {
			$this->next = $next;
		}

		/**
		 * Capture the request then replay the queued response.
		 *
		 * @param mixed $request Request.
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			$this->seen = $request;
			return $this->next;
		}
	}

	/**
	 * Pass-through authentication double.
	 *
	 * @since 0.1.9
	 */
	final class GoHeadersCoveragePassthroughAuthentication {
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
	 * Go header-pair coverage specs.
	 *
	 * @package OpenCodeConnector
	 * @since 0.1.9
	 */
	final class GoRequestHeadersCoverageTest extends MonkeyTestCase {

		/**
		 * Build a GET /models request through a directory's createRequest entry point.
		 *
		 * The directories are final, so the protected entry point is reached
		 * through reflection — the same seam MetadataRowIdTypeTest uses for its
		 * protected parse entry point.
		 *
		 * @param object $directory Directory instance.
		 * @param array  $headers   Request headers.
		 * @param mixed  $data      Request data.
		 * @return Request
		 */
		private static function make_directory_request( object $directory, array $headers, $data ): Request {
			$method = new \ReflectionMethod( $directory, 'createRequest' );
			return $method->invoke( $directory, HttpMethodEnum::GET(), 'models', $headers, $data );
		}

		/**
		 * Boot the minute constant.
		 *
		 * @return void
		 */
		private function boot(): void {
			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}
		}

		/**
		 * Stub transient functions with no persisted state.
		 *
		 * @return void
		 */
		private function stub_transients_stateless(): void {
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );
		}

		/**
		 * Build a verify-ready availability instance.
		 *
		 * @param mixed  $outcome Queued transporter outcome.
		 * @param string $catalog Catalog slug.
		 * @return array{0:OpenCodeProviderAvailability,1:GoHeadersCoverageCapturingTransporter}
		 */
		private function make_availability( mixed $outcome, string $catalog ): array {
			$availability = new OpenCodeProviderAvailability( $catalog );
			$transporter  = new GoHeadersCoverageCapturingTransporter( $outcome );
			$availability->setHttpTransporter( $transporter );
			$availability->setRequestAuthentication( new GoHeadersCoveragePassthroughAuthentication() );
			return array( $availability, $transporter );
		}

		/**
		 * The probe payload verify() sends.
		 *
		 * @return array
		 */
		private static function probe_data(): array {
			return array(
				'model'      => OpenCodeProviderAvailability::PROBE_MODEL,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => 'ping',
					),
				),
				'max_tokens' => 1,
			);
		}

		/**
		 * The Go verify probe carries both the session header and the plugin User-Agent.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_go_verify_probe_carries_session_and_user_agent(): void {
			$this->boot();
			$this->stub_transients_stateless();

			list( $availability, $transporter ) = $this->make_availability( new Response( 200, array( 'choices' => array() ) ), 'go' );
			$verdict = $availability->verify();

			self::assertSame( 'valid', $verdict['state'] );
			self::assertStringEndsWith( '/chat/completions', $transporter->seen->getUrl() );
			$headers = $transporter->seen->getHeaders();
			self::assertArrayHasKey( SessionHeader::HEADER_NAME, $headers );
			self::assertSame( SessionHeader::derive_from_data( self::probe_data() ), $headers[ SessionHeader::HEADER_NAME ] );
			self::assertArrayHasKey( ClientUserAgent::HEADER_NAME, $headers );
			self::assertSame( ClientUserAgent::value(), $headers[ ClientUserAgent::HEADER_NAME ] );
			self::assertStringStartsWith( 'duoport-connect-for-opencode/', $headers[ ClientUserAgent::HEADER_NAME ] );
		}

		/**
		 * The Zen verify probe stays session-only with no plugin User-Agent.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_zen_verify_probe_stays_session_only(): void {
			$this->boot();
			$this->stub_transients_stateless();

			list( $availability, $transporter ) = $this->make_availability( new Response( 200, array( 'choices' => array() ) ), 'zen' );
			$verdict = $availability->verify();

			self::assertSame( 'valid', $verdict['state'] );
			$headers = $transporter->seen->getHeaders();
			self::assertArrayHasKey( SessionHeader::HEADER_NAME, $headers );
			self::assertSame( SessionHeader::derive_from_data( self::probe_data() ), $headers[ SessionHeader::HEADER_NAME ] );
			foreach ( $headers as $name => $value ) {
				self::assertNotSame( 0, is_string( $name ) ? strcasecmp( $name, ClientUserAgent::HEADER_NAME ) : 1 );
			}
			self::assertArrayNotHasKey( ClientUserAgent::HEADER_NAME, $headers );
		}

		/**
		 * The Go /models listing carries the User-Agent and no session header.
		 *
		 * The listing is a headerless GET with null data, so there is no chat
		 * or image context to derive a session from; only the UA is fixed.
		 *
		 * @return void
		 */
		public function test_go_models_listing_carries_user_agent_without_session(): void {
			$request = self::make_directory_request( new OpenCodeGoModelMetadataDirectory(), array(), null );

			self::assertStringEndsWith( '/models', $request->getUrl() );
			self::assertNull( $request->getData() );
			$headers = $request->getHeaders();
			self::assertArrayHasKey( ClientUserAgent::HEADER_NAME, $headers );
			self::assertSame( ClientUserAgent::value(), $headers[ ClientUserAgent::HEADER_NAME ] );
			self::assertStringStartsWith( 'duoport-connect-for-opencode/', $headers[ ClientUserAgent::HEADER_NAME ] );
			foreach ( $headers as $name => $value ) {
				self::assertNotSame( 0, is_string( $name ) ? strcasecmp( $name, SessionHeader::HEADER_NAME ) : 1 );
			}
			self::assertArrayNotHasKey( SessionHeader::HEADER_NAME, $headers );
		}

		/**
		 * The Zen /models listing stays header-free.
		 *
		 * @return void
		 */
		public function test_zen_models_listing_stays_header_free(): void {
			$request = self::make_directory_request( new OpenCodeZenModelMetadataDirectory(), array(), null );

			self::assertStringEndsWith( '/models', $request->getUrl() );
			$headers = $request->getHeaders();
			self::assertArrayNotHasKey( SessionHeader::HEADER_NAME, $headers );
			self::assertArrayNotHasKey( ClientUserAgent::HEADER_NAME, $headers );
			foreach ( $headers as $name => $value ) {
				self::assertNotSame( 0, is_string( $name ) ? strcasecmp( $name, SessionHeader::HEADER_NAME ) : 1 );
				self::assertNotSame( 0, is_string( $name ) ? strcasecmp( $name, ClientUserAgent::HEADER_NAME ) : 1 );
			}
		}
	}
}
