<?php
/**
 * Session header specs: derivation, clamping, omission, Go-only injection.
 *
 * Covers the x-opencode-session header used for routing and prompt-cache
 * affinity on Go chat completions requests.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey\Functions;
use OpenCodeConnector\Http\ClientUserAgent;
use OpenCodeConnector\Http\SessionHeader;
use OpenCodeConnector\Models\AbstractOpenCodeTextGenerationModel;
use OpenCodeConnector\Providers\OpenCodeGoProvider;
use OpenCodeConnector\Providers\OpenCodeZenProvider;
use OpenCodeConnector\Transport\UnsupportedEndpointFamilyException;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

require_once __DIR__ . '/Fixtures/SdkStubs.php';
require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

/**
 * Test double routing through the Go provider.
 */
final class SessionHeaderGoModel extends AbstractOpenCodeTextGenerationModel {
	/**
	 * Provider class FQCN.
	 *
	 * @return string
	 */
	protected function providerClass(): string {
		return OpenCodeGoProvider::class;
	}

	/**
	 * Expose request creation for tests.
	 *
	 * @param array $headers Request headers.
	 * @param mixed $data    Request data.
	 * @return Request
	 */
	public function make_request( array $headers, $data ): Request {
		return $this->createRequest( HttpMethodEnum::POST(), 'chat/completions', $headers, $data );
	}

	/**
	 * Verified model ID for route tests.
	 *
	 * @return string
	 */
	protected function route_model_id(): string {
		return 'glm-5.3';
	}
}

/**
 * Test double routing through the Zen provider.
 */
final class SessionHeaderZenModel extends AbstractOpenCodeTextGenerationModel {
	/**
	 * Provider class FQCN.
	 *
	 * @return string
	 */
	protected function providerClass(): string {
		return OpenCodeZenProvider::class;
	}

	/**
	 * Expose request creation for tests.
	 *
	 * @param array $headers Request headers.
	 * @param mixed $data    Request data.
	 * @return Request
	 */
	public function make_request( array $headers, $data ): Request {
		return $this->createRequest( HttpMethodEnum::POST(), 'chat/completions', $headers, $data );
	}

	/**
	 * Verified model ID for route tests.
	 *
	 * @return string
	 */
	protected function route_model_id(): string {
		return 'deepseek-v4-pro';
	}
}

/**
 * Test double with unresolved route metadata.
 */
final class UnresolvedRouteModel extends AbstractOpenCodeTextGenerationModel {
	/**
	 * Provider class FQCN.
	 *
	 * @return string
	 */
	protected function providerClass(): string {
		return OpenCodeGoProvider::class;
	}

	/**
	 * Expose request creation for tests.
	 *
	 * @param array $headers Request headers.
	 * @param mixed $data    Request data.
	 * @return Request
	 */
	public function make_request( array $headers, $data ): Request {
		return $this->createRequest( HttpMethodEnum::POST(), 'chat/completions', $headers, $data );
	}
}

/**
 * Session header specs.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */
final class SessionHeaderTest extends MonkeyTestCase {

	/**
	 * Sample conversation payload.
	 *
	 * @return array
	 */
	private static function conversation(): array {
		return array(
			'model'    => 'opencode-go-model',
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => 'Hello, OpenCode!',
				),
			),
		);
	}

	/**
	 * Unresolved route metadata fails before a Request can be constructed.
	 */
	public function test_unresolved_route_fails_closed(): void {
		$this->expectException( UnsupportedEndpointFamilyException::class );
		( new UnresolvedRouteModel() )->make_request( array(), self::conversation() );
	}

	/**
	 * Derivation is stable, hex, and clamped.
	 *
	 * @return void
	 */
	public function test_derive_returns_stable_clamped_hex_value(): void {
		$first  = SessionHeader::derive_from_data( self::conversation() );
		$second = SessionHeader::derive_from_data( self::conversation() );

		self::assertIsString( $first );
		self::assertSame( $first, $second );
		self::assertMatchesRegularExpression( '/^[a-f0-9]+$/', $first );
		self::assertLessThanOrEqual( SessionHeader::VALUE_MAX_LENGTH, strlen( $first ) );
	}

	/**
	 * Distinct conversations get distinct values (never one global constant).
	 *
	 * @return void
	 */
	public function test_derive_differs_per_conversation(): void {
		$one = SessionHeader::derive_from_data( self::conversation() );

		$other            = self::conversation();
		$other['messages'] = array(
			array(
				'role'    => 'user',
				'content' => 'Something completely different.',
			),
		);
		$two = SessionHeader::derive_from_data( $other );

		self::assertIsString( $one );
		self::assertIsString( $two );
		self::assertNotSame( $one, $two );
	}

	/**
	 * The model is part of the derivation input.
	 *
	 * @return void
	 */
	public function test_derive_differs_per_model(): void {
		$one = SessionHeader::derive_from_data( self::conversation() );

		$other          = self::conversation();
		$other['model'] = 'another-model';
		$two            = SessionHeader::derive_from_data( $other );

		self::assertNotSame( $one, $two );
	}

	/**
	 * Non-conversation keys (user ids, keys) never leak into the value.
	 *
	 * @return void
	 */
	public function test_derive_ignores_non_conversation_keys(): void {
		$plain = SessionHeader::derive_from_data( self::conversation() );

		$with_extras          = self::conversation();
		$with_extras['user']    = 'user_12345';
		$with_extras['api_key'] = 'sk-secret';
		$with_user_id           = SessionHeader::derive_from_data( $with_extras );

		self::assertSame( $plain, $with_user_id );
		self::assertStringNotContainsString( 'user_12345', (string) $with_user_id );
		self::assertStringNotContainsString( 'sk-secret', (string) $with_user_id );
	}

	/**
	 * No conversation context means omission (fail-open).
	 *
	 * @return void
	 */
	public function test_derive_returns_null_without_conversation_context(): void {
		self::assertNull( SessionHeader::derive_from_data( null ) );
		self::assertNull( SessionHeader::derive_from_data( 'ping' ) );
		self::assertNull( SessionHeader::derive_from_data( array() ) );
		self::assertNull( SessionHeader::derive_from_data( array( 'model' => 'x' ) ) );
		self::assertNull( SessionHeader::derive_from_data( array( 'messages' => array() ) ) );
		self::assertNull( SessionHeader::derive_from_data( array( 'messages' => 'ping' ) ) );
		self::assertNull(
			SessionHeader::derive_from_data(
				array(
					'messages' => array( 'not-an-array', array() ),
				)
			)
		);
	}

	/**
	 * Derivation works when wp_json_encode is available.
	 *
	 * @return void
	 */
	public function test_derive_uses_wp_json_encode_when_available(): void {
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ): string {
				$encoded = json_encode( $data );
				return is_string( $encoded ) ? $encoded : '';
			}
		);

		$first  = SessionHeader::derive_from_data( self::conversation() );
		$second = SessionHeader::derive_from_data( self::conversation() );

		self::assertIsString( $first );
		self::assertSame( $first, $second );
		self::assertMatchesRegularExpression( '/^[a-f0-9]+$/', $first );
	}

	/**
	 * Go requests carry a stable session header.
	 *
	 * @return void
	 */
	public function test_go_request_carries_stable_session_header(): void {
		$model = new SessionHeaderGoModel();

		$first  = $model->make_request( array(), self::conversation() );
		$second = $model->make_request( array(), self::conversation() );

		$headers = $first->getHeaders();
		self::assertArrayHasKey( SessionHeader::HEADER_NAME, $headers );
		self::assertSame( $headers[ SessionHeader::HEADER_NAME ], $second->getHeaders()[ SessionHeader::HEADER_NAME ] );
		self::assertSame( SessionHeader::derive_from_data( self::conversation() ), $headers[ SessionHeader::HEADER_NAME ] );
	}

	/**
	 * Zen requests must not receive the session header.
	 *
	 * @return void
	 */
	public function test_zen_request_has_no_session_header(): void {
		$model   = new SessionHeaderZenModel();
		$request = $model->make_request( array(), self::conversation() );

		foreach ( $request->getHeaders() as $name => $value ) {
			self::assertNotSame( 0, is_string( $name ) ? strcasecmp( $name, SessionHeader::HEADER_NAME ) : 1 );
		}
		self::assertArrayNotHasKey( SessionHeader::HEADER_NAME, $request->getHeaders() );
	}

	/**
	 * Go requests without conversation context stay header-free (fail-open).
	 *
	 * @return void
	 */
	public function test_go_request_omits_header_without_conversation_context(): void {
		$model   = new SessionHeaderGoModel();
		$request = $model->make_request( array(), null );

		self::assertArrayNotHasKey( SessionHeader::HEADER_NAME, $request->getHeaders() );
	}

	/**
	 * An explicitly provided header value is never overwritten.
	 *
	 * @return void
	 */
	public function test_explicit_header_value_is_preserved(): void {
		$model   = new SessionHeaderGoModel();
		$request = $model->make_request(
			array( 'X-OpenCode-Session' => 'caller-value' ),
			self::conversation()
		);

		$headers = $request->getHeaders();
		self::assertSame( 'caller-value', $headers['X-OpenCode-Session'] );
		self::assertArrayNotHasKey( SessionHeader::HEADER_NAME, $headers );
	}

	/**
	 * Image prompt payloads derive a stable 32-hex session via the same scheme.
	 *
	 * @return void
	 */
	public function test_derive_from_prompt_returns_stable_32hex_value(): void {
		$payload = array(
			'model'  => 'opencode-go-image-model',
			'prompt' => 'A lighthouse at dusk, watercolor.',
		);

		$first  = SessionHeader::derive_from_data( $payload );
		$second = SessionHeader::derive_from_data( $payload );

		self::assertIsString( $first );
		self::assertSame( $first, $second );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $first );
	}

	/**
	 * Prompt derivation varies with the prompt and the model.
	 *
	 * @return void
	 */
	public function test_derive_from_prompt_differs_per_prompt_and_model(): void {
		$base = array(
			'model'  => 'opencode-go-image-model',
			'prompt' => 'A lighthouse at dusk, watercolor.',
		);

		$other_prompt           = $base;
		$other_prompt['prompt'] = 'A lighthouse at dawn, oil painting.';
		self::assertNotSame(
			SessionHeader::derive_from_data( $base ),
			SessionHeader::derive_from_data( $other_prompt )
		);

		$other_model          = $base;
		$other_model['model'] = 'another-model';
		self::assertNotSame(
			SessionHeader::derive_from_data( $base ),
			SessionHeader::derive_from_data( $other_model )
		);
	}

	/**
	 * Non-prompt keys (user ids, keys) never leak into the image value.
	 *
	 * @return void
	 */
	public function test_derive_from_prompt_ignores_non_prompt_keys(): void {
		$plain = array(
			'model'  => 'opencode-go-image-model',
			'prompt' => 'A lighthouse at dusk, watercolor.',
		);

		$with_extras            = $plain;
		$with_extras['user']    = 'user_12345';
		$with_extras['api_key'] = 'sk-secret';
		$with_size              = $plain;
		$with_size['size']      = '1024x1024';

		self::assertSame( SessionHeader::derive_from_data( $plain ), SessionHeader::derive_from_data( $with_extras ) );
		self::assertSame( SessionHeader::derive_from_data( $plain ), SessionHeader::derive_from_data( $with_size ) );
	}

	/**
	 * Empty/missing prompts omit the session (fail-open).
	 *
	 * @return void
	 */
	public function test_derive_returns_null_for_empty_prompt(): void {
		self::assertNull( SessionHeader::derive_from_data( array( 'model' => 'x', 'prompt' => '' ) ) );
		self::assertNull( SessionHeader::derive_from_data( array( 'model' => 'x', 'prompt' => null ) ) );
		self::assertNull( SessionHeader::derive_from_data( array( 'model' => 'x' ) ) );
	}

	/**
	 * Go text requests share the same client User-Agent as the Go image path.
	 *
	 * @return void
	 */
	public function test_go_text_request_sets_shared_user_agent(): void {
		$model   = new SessionHeaderGoModel();
		$request = $model->make_request( array(), self::conversation() );
		$headers = $request->getHeaders();

		self::assertArrayHasKey( SessionHeader::HEADER_NAME, $headers );
		self::assertArrayHasKey( ClientUserAgent::HEADER_NAME, $headers );
		self::assertSame( ClientUserAgent::value(), $headers[ ClientUserAgent::HEADER_NAME ] );
		self::assertStringStartsWith( 'duoport-connect-for-opencode/', $headers[ ClientUserAgent::HEADER_NAME ] );
	}

	/**
	 * Go text requests preserve an explicitly provided User-Agent (any case).
	 *
	 * @return void
	 */
	public function test_go_text_request_preserves_explicit_user_agent(): void {
		$model   = new SessionHeaderGoModel();
		$request = $model->make_request(
			array( 'user-agent' => 'Custom/1.0' ),
			self::conversation()
		);

		$headers = $request->getHeaders();
		self::assertSame( 'Custom/1.0', $headers['user-agent'] );
		self::assertArrayNotHasKey( ClientUserAgent::HEADER_NAME, $headers );
	}

	/**
	 * Zen text requests carry neither the session header nor the User-Agent.
	 *
	 * @return void
	 */
	public function test_zen_text_request_has_no_user_agent(): void {
		$model   = new SessionHeaderZenModel();
		$request = $model->make_request( array(), self::conversation() );

		foreach ( $request->getHeaders() as $name => $value ) {
			self::assertNotSame( 0, is_string( $name ) ? strcasecmp( $name, ClientUserAgent::HEADER_NAME ) : 1 );
		}
		self::assertArrayNotHasKey( ClientUserAgent::HEADER_NAME, $request->getHeaders() );
	}

	/**
	 * The availability probe carries a derived session header: the Go
	 * catalog rejects headerless requests (400 MissingSessionID), so a
	 * header-free probe can never validate a Go key.
	 *
	 * @return void
	 */
	public function test_probe_carries_derived_session_header(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php' );

		self::assertStringContainsString( 'SessionHeader::inject_into_headers', $source );
	}
}
