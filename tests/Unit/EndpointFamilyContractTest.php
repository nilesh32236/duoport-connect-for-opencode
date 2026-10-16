<?php
/**
 * Per-family route, parser, and auth contract specs.
 *
 * Each verified non-chat family (responses, messages, gemini, systemone)
 * needs three evidenced pieces: a route, a payload parser, and an
 * authentication contract. This file pins all three together per family,
 * so a family cannot be routed while its parser or auth contract is
 * missing. Unverified families stay fail-closed, and the availability
 * probe stays on chat completions.
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
	use OpenCodeConnector\Metadata\CapabilityAwareFallback;
	use OpenCodeConnector\Models\AbstractOpenCodeTextGenerationModel;
	use OpenCodeConnector\Providers\OpenCodeGoProvider;
	use OpenCodeConnector\Transport\EndpointAuth;
	use OpenCodeConnector\Transport\EndpointRoute;
	use OpenCodeConnector\Transport\GeminiPayloadParser;
	use OpenCodeConnector\Transport\MessagesPayloadParser;
	use OpenCodeConnector\Transport\ResponsesPayloadParser;
	use OpenCodeConnector\Transport\SystemOnePayloadParser;
	use OpenCodeConnector\Transport\UnsupportedEndpointFamilyException;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use WordPress\AiClient\Providers\Http\DTO\Request;
	use WordPress\AiClient\Providers\Http\DTO\Response;
	use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

	require_once __DIR__ . '/Fixtures/SdkStubs.php';
	require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

	/**
	 * Text model double bound to a verified chat model.
	 */
	final class FamilyContractTestModel extends AbstractOpenCodeTextGenerationModel {
		/**
		 * Provider class.
		 *
		 * @return string
		 */
		protected function providerClass(): string {
			return OpenCodeGoProvider::class;
		}

		/**
		 * Primary model.
		 *
		 * @return string
		 */
		protected function route_model_id(): string {
			return 'glm-5.3';
		}

		/**
		 * Build a request for the test.
		 *
		 * @param array $data Request data.
		 * @return Request
		 */
		public function make_request( array $data ): Request {
			return $this->createRequest( HttpMethodEnum::POST(), 'chat/completions', array(), $data );
		}

		/**
		 * Build the wire payload for a family (test seam).
		 *
		 * @param string $kind     Endpoint family name.
		 * @param string $model_id Model ID.
		 * @param array  $data     Chat-style request data.
		 * @return array
		 */
		public function payload_for( string $kind, string $model_id, array $data ): array {
			$method = new \ReflectionMethod( $this, 'payload_for_family' );
			$method->setAccessible( true );
			return $method->invoke( $this, $kind, $model_id, $data );
		}

		/**
		 * Extract assistant text for a family (test seam).
		 *
		 * @param string $kind Endpoint family name.
		 * @param mixed  $data Decoded response data.
		 * @return string
		 */
		public function text_for( string $kind, mixed $data ): string {
			$method = new \ReflectionMethod( $this, 'text_for_family' );
			$method->setAccessible( true );
			return $method->invoke( $this, $kind, $data );
		}
	}

	/**
	 * Capturing transporter double for the probe-path spec.
	 */
	final class FamilyContractCapturingTransporter {
		/**
		 * Queued outcome.
		 *
		 * @var mixed
		 */
		private mixed $next;

		/**
		 * Captured request.
		 *
		 * @var mixed
		 */
		public mixed $seen = null;

		/**
		 * Constructor.
		 *
		 * @param mixed $next Response to return or throwable to throw.
		 */
		public function __construct( mixed $next ) {
			$this->next = $next;
		}

		/**
		 * Capture the request then replay the queued outcome.
		 *
		 * @param mixed $request Request.
		 * @return mixed
		 */
		public function send( mixed $request ): mixed {
			$this->seen = $request;
			if ( $this->next instanceof \Throwable ) {
				throw $this->next;
			}
			return $this->next;
		}
	}

	/**
	 * Pass-through authentication double.
	 */
	final class FamilyContractPassthroughAuthentication {
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
	 * Per-family contract specs.
	 */
	final class EndpointFamilyContractTest extends MonkeyTestCase {

		/**
		 * Each verified family resolves a route and names its credential header.
		 */
		public function test_each_verified_family_has_a_route_and_auth_scheme(): void {
			$contracts = array(
				'chat'      => array( 'chat/completions', 'Authorization: Bearer', 'authorization' ),
				'responses' => array( 'responses', 'Authorization: Bearer', 'authorization' ),
				'messages'  => array( 'messages', 'x-api-key', 'x-api-key' ),
				'gemini'    => array( 'models/{model}:generateContent', 'x-goog-api-key', 'x-goog-api-key' ),
				'systemone' => array( 'systemone', 'Authorization: Bearer', 'authorization' ),
			);
			foreach ( $contracts as $family => $expected ) {
				list( $path, $scheme, $header ) = $expected;
				self::assertTrue( EndpointRoute::isVerifiedFamily( $family ), $family . ' must be routable.' );
				self::assertSame( $path, EndpointRoute::pathForEndpointKind( $family ), $family . ' must resolve its reviewed path.' );
				self::assertSame( $scheme, EndpointAuth::schemeForFamily( $family ), $family . ' must name its credential header.' );
				self::assertSame( $header, EndpointAuth::credential_header_name( $family ), $family . ' must expose its header name for send-time branching.' );
			}
		}

		/**
		 * Unverified families have no route and no auth contract.
		 *
		 * Every entry throws before any HTTP is sent, so an unverified
		 * family is never misrouted to a verified path.
		 */
		public function test_unverified_families_stay_fail_closed(): void {
			foreach ( array( 'provider-specific', 'some-future-family', 'unsupported', '' ) as $family ) {
				try {
					EndpointRoute::pathForEndpointKind( $family );
					self::fail( 'Expected an unsupported endpoint kind exception for ' . $family . '.' );
				} catch ( UnsupportedEndpointFamilyException ) {
					self::assertTrue( true );
				}
				try {
					EndpointRoute::pathForVerifiedKind( $family, 'glm-5.3' );
					self::fail( 'Expected an unsupported verified-kind exception for ' . $family . '.' );
				} catch ( UnsupportedEndpointFamilyException ) {
					self::assertTrue( true );
				}
				try {
					EndpointAuth::schemeForFamily( $family );
					self::fail( 'Expected an unsupported auth contract exception for ' . $family . '.' );
				} catch ( UnsupportedEndpointFamilyException ) {
					self::assertTrue( true );
				}
				try {
					EndpointAuth::credential_header_name( $family );
					self::fail( 'Expected an unsupported credential header exception for ' . $family . '.' );
				} catch ( UnsupportedEndpointFamilyException ) {
					self::assertTrue( true );
				}
				try {
					EndpointAuth::applyFamilyHeaders( array(), $family );
					self::fail( 'Expected an unsupported family header exception for ' . $family . '.' );
				} catch ( UnsupportedEndpointFamilyException ) {
					self::assertTrue( true );
				}
			}
		}

		/**
		 * The verified-kind path derives from the same map as the model path.
		 *
		 * createRequest() resolves the kind once and derives the path here,
		 * so this pins the derivation: fixed families report their path and
		 * Gemini substitutes the model ID.
		 */
		public function test_verified_kind_path_matches_model_path(): void {
			self::assertSame( 'chat/completions', EndpointRoute::pathForVerifiedKind( 'chat', 'glm-5.3' ) );
			self::assertSame( 'responses', EndpointRoute::pathForVerifiedKind( 'responses', 'glm-5.3' ) );
			self::assertSame( 'messages', EndpointRoute::pathForVerifiedKind( 'messages', 'kimi-k3' ) );
			self::assertSame( 'systemone', EndpointRoute::pathForVerifiedKind( 'systemone', 'jev-1' ) );
			self::assertSame( 'models/gemini-1:generateContent', EndpointRoute::pathForVerifiedKind( 'gemini', 'gemini-1' ) );
		}

		/**
		 * The denial text lists the enforced families, never a stale copy.
		 */
		public function test_denial_lists_the_enforced_families(): void {
			try {
				EndpointRoute::pathForEndpointKind( 'provider-specific' );
				self::fail( 'Expected an unsupported endpoint kind exception.' );
			} catch ( UnsupportedEndpointFamilyException $exception ) {
				foreach ( array( 'chat', 'responses', 'messages', 'gemini', 'systemone' ) as $family ) {
					self::assertStringContainsString( $family, $exception->getMessage(), 'Denial must list the enforced family ' . $family . '.' );
				}
			}
		}

		/**
		 * The Messages family carries the Anthropic version; others are untouched.
		 */
		public function test_messages_family_headers_carry_the_anthropic_version(): void {
			$messages = EndpointAuth::applyFamilyHeaders( array(), 'messages' );
			self::assertSame( EndpointAuth::ANTHROPIC_VERSION, $messages['anthropic-version'] );

			foreach ( array( 'chat', 'responses', 'gemini', 'systemone' ) as $family ) {
				self::assertSame( array(), EndpointAuth::applyFamilyHeaders( array(), $family ), $family . ' must not gain headers.' );
			}

			// A caller-supplied version (any casing) is never duplicated.
			$existing = EndpointAuth::applyFamilyHeaders( array( 'Anthropic-Version' => '2023-06-01' ), 'messages' );
			self::assertCount( 1, $existing );
		}

		/**
		 * The Responses parser round-trips its envelope and degrades safely.
		 */
		public function test_responses_parser_builds_input_and_reads_output(): void {
			$payload = ResponsesPayloadParser::buildPayload(
				'glm-5.3',
				array(
					'model'    => 'glm-5.3',
					'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ),
					'tools'    => array( array( 'type' => 'function' ) ),
				)
			);
			self::assertSame( 'glm-5.3', $payload['model'] );
			self::assertSame( array( array( 'role' => 'user', 'content' => 'hi' ) ), $payload['input'] );
			self::assertArrayNotHasKey( 'messages', $payload );
			self::assertCount( 1, $payload['tools'] );

			$text = ResponsesPayloadParser::extractText(
				array(
					'output' => array(
						array(
							'type'    => 'message',
							'content' => array(
								array(
									'type' => 'output_text',
									'text' => 'hello',
								),
							),
						),
					),
				)
			);
			self::assertSame( 'hello', $text );
			self::assertSame( 'flat', ResponsesPayloadParser::extractText( array( 'output_text' => 'flat' ) ) );

			self::assertSame( '', ResponsesPayloadParser::extractText( null ) );
			self::assertSame( '', ResponsesPayloadParser::extractText( array( 'unexpected' => true ) ) );
			$garbage = ResponsesPayloadParser::buildPayload( 'glm-5.3', array( 'messages' => 'not-an-array' ) );
			self::assertSame( 'glm-5.3', $garbage['model'], 'A malformed request still carries the model for a typed gateway error.' );
		}

		/**
		 * The Messages parser lifts system text and reads content blocks.
		 */
		public function test_messages_parser_lifts_system_and_reads_content(): void {
			$payload = MessagesPayloadParser::buildPayload(
				'kimi-k3',
				array(
					'model'      => 'kimi-k3',
					'messages'   => array(
						array(
							'role'    => 'system',
							'content' => 'be brief',
						),
						array(
							'role'    => 'user',
							'content' => 'hi',
						),
					),
					'max_tokens' => 4,
				)
			);
			self::assertSame( 'kimi-k3', $payload['model'] );
			self::assertSame( 'be brief', $payload['system'] );
			self::assertSame( array( array( 'role' => 'user', 'content' => 'hi' ) ), $payload['messages'] );
			self::assertSame( 4, $payload['max_tokens'] );

			$text = MessagesPayloadParser::extractText(
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'hello',
						),
						array( 'type' => 'tool_use' ),
					),
				)
			);
			self::assertSame( 'hello', $text );

			self::assertSame( '', MessagesPayloadParser::extractText( null ) );
			self::assertSame( '', MessagesPayloadParser::extractText( array( 'content' => 'not-an-array' ) ) );
		}

		/**
		 * The Gemini parser maps roles and reads candidates.
		 */
		public function test_gemini_parser_maps_roles_and_reads_candidates(): void {
			$payload = GeminiPayloadParser::buildPayload(
				'gemini-1',
				array(
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => 'be brief',
						),
						array(
							'role'    => 'assistant',
							'content' => 'prior',
						),
						array(
							'role'    => 'user',
							'content' => 'hi',
						),
					),
					'temperature' => 0.5,
				)
			);
			self::assertArrayNotHasKey( 'model', $payload, 'The Gemini path carries the model in the URL, not the body.' );
			self::assertSame( 'be brief', $payload['systemInstruction']['parts'][0]['text'] );
			self::assertSame( 'model', $payload['contents'][0]['role'] );
			self::assertSame( 'prior', $payload['contents'][0]['parts'][0]['text'] );
			self::assertSame( 'user', $payload['contents'][1]['role'] );
			self::assertSame( 0.5, $payload['generationConfig']['temperature'] );

			$text = GeminiPayloadParser::extractText(
				array(
					'candidates' => array(
						array(
							'content' => array(
								'parts' => array(
									array( 'text' => 'hel' ),
									array( 'text' => 'lo' ),
								),
							),
						),
					),
				)
			);
			self::assertSame( 'hello', $text );

			self::assertSame( '', GeminiPayloadParser::extractText( null ) );
			self::assertSame( '', GeminiPayloadParser::extractText( array( 'candidates' => 'not-an-array' ) ) );
		}

		/**
		 * The SystemOne parser wraps input and reads decision text.
		 */
		public function test_systemone_parser_wraps_input_and_reads_decision(): void {
			$payload = SystemOnePayloadParser::buildPayload(
				'jev-1',
				array(
					'model'    => 'jev-1',
					'messages' => array( array( 'role' => 'user', 'content' => 'review this' ) ),
				)
			);
			self::assertSame( 'jev-1', $payload['model'] );
			self::assertSame( array( array( 'role' => 'user', 'content' => 'review this' ) ), $payload['input'] );

			self::assertSame( 'ship it', SystemOnePayloadParser::extractText( array( 'decision' => array( 'text' => 'ship it' ) ) ) );
			self::assertSame( 'flat', SystemOnePayloadParser::extractText( array( 'output_text' => 'flat' ) ) );
			self::assertSame( '', SystemOnePayloadParser::extractText( null ) );
			self::assertSame( '', SystemOnePayloadParser::extractText( array( 'unexpected' => true ) ) );
		}

		/**
		 * Request construction branches per family and stays fail-closed.
		 */
		public function test_request_payload_branches_per_family(): void {
			$model = new FamilyContractTestModel();
			$data  = array(
				'model'    => 'glm-5.3',
				'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ),
			);

			$chat = $model->payload_for( 'chat', 'glm-5.3', $data );
			self::assertSame( $data, $chat, 'Chat sends the OpenAI-compatible shape unchanged.' );

			$responses = $model->payload_for( 'responses', 'glm-5.3', $data );
			self::assertArrayHasKey( 'input', $responses );
			self::assertArrayNotHasKey( 'messages', $responses );

			$messages = $model->payload_for( 'messages', 'kimi-k3', $data );
			self::assertSame( array( array( 'role' => 'user', 'content' => 'hi' ) ), $messages['messages'] );

			$gemini = $model->payload_for( 'gemini', 'gemini-1', $data );
			self::assertArrayHasKey( 'contents', $gemini );

			$systemone = $model->payload_for( 'systemone', 'jev-1', $data );
			self::assertArrayHasKey( 'input', $systemone );

			try {
				$model->payload_for( 'provider-specific', 'glm-5.3', $data );
				self::fail( 'Expected an unsupported endpoint family exception.' );
			} catch ( UnsupportedEndpointFamilyException ) {
				self::assertTrue( true );
			}
		}

		/**
		 * Degraded payloads stay minimal-valid, never an empty body.
		 *
		 * A malformed request still carries its required field so the
		 * gateway answers with a typed error; no family ever sends an
		 * empty body or a model-only payload missing required fields.
		 */
		public function test_degraded_payloads_stay_minimal_valid(): void {
			$garbage = array( 'messages' => 'not-an-array' );

			$responses = ResponsesPayloadParser::buildPayload( 'glm-5.3', $garbage );
			self::assertSame( 'glm-5.3', $responses['model'] );
			self::assertArrayHasKey( 'input', $responses );

			$messages = MessagesPayloadParser::buildPayload( 'kimi-k3', $garbage );
			self::assertSame( 'kimi-k3', $messages['model'] );
			self::assertNotEmpty( $messages['messages'] );

			$no_messages = MessagesPayloadParser::buildPayload( 'kimi-k3', array() );
			self::assertNotEmpty( $no_messages['messages'] );

			$gemini = GeminiPayloadParser::buildPayload( 'gemini-1', $garbage );
			self::assertNotEmpty( $gemini, 'Gemini must never degrade to an empty body.' );
			self::assertArrayHasKey( 'contents', $gemini );
			self::assertArrayNotHasKey( 'model', $gemini, 'The Gemini path carries the model in the URL, not the body.' );

			$systemone = SystemOnePayloadParser::buildPayload( 'jev-1', $garbage );
			self::assertSame( 'jev-1', $systemone['model'] );
			self::assertArrayHasKey( 'input', $systemone );
		}

		/**
		 * Response extraction is family-aware end to end.
		 *
		 * The request half shapes what is sent; this half shapes how the
		 * answer is read. Chat reads the OpenAI-compatible choices
		 * envelope, every other verified family reads its own envelope,
		 * and unverified families throw instead of guessing.
		 */
		public function test_response_extraction_branches_per_family(): void {
			$model = new FamilyContractTestModel();

			self::assertSame(
				'hello',
				$model->text_for( 'chat', array( 'choices' => array( array( 'message' => array( 'content' => 'hello' ) ) ) ) ),
				'Chat must read the OpenAI-compatible choices envelope.'
			);
			self::assertSame( 'legacy', $model->text_for( 'chat', array( 'choices' => array( array( 'text' => 'legacy' ) ) ) ) );
			self::assertSame( '', $model->text_for( 'chat', array( 'unexpected' => true ) ) );
			self::assertSame( '', $model->text_for( 'chat', null ) );

			self::assertSame( 'hello', $model->text_for( 'responses', array( 'output_text' => 'hello' ) ) );
			self::assertSame(
				'hello',
				$model->text_for( 'messages', array( 'content' => array( array( 'type' => 'text', 'text' => 'hello' ) ) ) )
			);
			self::assertSame(
				'hello',
				$model->text_for( 'gemini', array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'hello' ) ) ) ) ) ) )
			);
			self::assertSame( 'ship it', $model->text_for( 'systemone', array( 'decision' => array( 'text' => 'ship it' ) ) ) );

			try {
				$model->text_for( 'provider-specific', array() );
				self::fail( 'Expected an unsupported endpoint family exception.' );
			} catch ( UnsupportedEndpointFamilyException ) {
				self::assertTrue( true );
			}
		}

		/**
		 * A chat request still sends chat/completions without extra family headers.
		 */
		public function test_chat_request_still_sends_chat_completions(): void {
			$request = ( new FamilyContractTestModel() )->make_request(
				array(
					'model'    => 'glm-5.3',
					'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ),
				)
			);
			$data = $request->getData();

			self::assertStringEndsWith( '/chat/completions', $request->getUrl() );
			self::assertIsArray( $data );
			self::assertSame( 'glm-5.3', $data['model'] );
			self::assertSame( array( array( 'role' => 'user', 'content' => 'hi' ) ), $data['messages'] );
			foreach ( array_keys( $request->getHeaders() ) as $name ) {
				self::assertNotSame( 'anthropic-version', strtolower( (string) $name ), 'Chat must not carry the Messages version header.' );
			}
		}

		/**
		 * Fallback never crosses families, including verified non-chat ones.
		 */
		public function test_fallback_never_crosses_families(): void {
			$records  = array(
				'chat-primary'      => array(
					'id'                  => 'chat-primary',
					'catalog'             => 'go',
					'endpoint_family'     => 'chat',
					'verification_status' => 'legacy-verified',
					'capabilities'        => array( 'text' => false ),
				),
				'responses-primary' => array(
					'id'                  => 'responses-primary',
					'catalog'             => 'go',
					'endpoint_family'     => 'responses',
					'verification_status' => 'legacy-verified',
					'capabilities'        => array( 'text' => false ),
				),
				'responses-alt'     => array(
					'id'                  => 'responses-alt',
					'catalog'             => 'go',
					'endpoint_family'     => 'responses',
					'verification_status' => 'legacy-verified',
					'capabilities'        => array( 'text' => true ),
				),
			);
			$fallback = new CapabilityAwareFallback(
				static fn( string $id, string $catalog ): ?array => $records[ $id ] ?? null
			);

			$cross = $fallback->select( 'go', 'chat-primary', 'text', array( 'responses-alt' ) );
			self::assertTrue( $cross['exhausted'] );
			self::assertSame( 'endpoint_mismatch', $cross['rejected'][1]['reason'] );

			$same = $fallback->select( 'go', 'responses-primary', 'text', array( 'responses-alt' ) );
			self::assertSame( 'responses-alt', $same['selected_id'] );

			$unverified = $fallback->select( 'go', 'chat-primary', 'text', array( 'provider-specific-model' ) );
			self::assertTrue( $unverified['exhausted'] );
		}

		/**
		 * The availability probe stays on chat completions.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_availability_probe_stays_on_chat_completions(): void {
			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}
			if ( ! defined( 'DAY_IN_SECONDS' ) ) {
				define( 'DAY_IN_SECONDS', 86400 );
			}
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new OpenCodeProviderAvailability( 'go' );
			$transporter  = new FamilyContractCapturingTransporter( new Response( 200, array( 'choices' => array() ) ) );
			$availability->setHttpTransporter( $transporter );
			$availability->setRequestAuthentication( new FamilyContractPassthroughAuthentication() );

			$availability->diagnose();

			self::assertStringContainsString( 'chat/completions', $transporter->seen->getUrl() );
			$data = $transporter->seen->getData();
			self::assertSame( OpenCodeProviderAvailability::PROBE_MODEL, $data['model'] );
			self::assertArrayHasKey( 'messages', $data );
		}
	}
}
