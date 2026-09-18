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
use OpenCodeConnector\Http\SessionHeader;
use OpenCodeConnector\Models\AbstractOpenCodeTextGenerationModel;
use OpenCodeConnector\Providers\OpenCodeGoProvider;
use OpenCodeConnector\Providers\OpenCodeZenProvider;
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
	 * The availability probe builds its request directly and stays header-free.
	 *
	 * @return void
	 */
	public function test_probe_stays_header_free(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php' );

		self::assertStringNotContainsString( 'SessionHeader', $source );
		self::assertStringNotContainsString( 'x-opencode-session', $source );
	}
}
