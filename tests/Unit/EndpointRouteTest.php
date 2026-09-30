<?php
/**
 * Tests for explicit endpoint-family routing.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Transport\EndpointRoute;
use OpenCodeConnector\Transport\UnsupportedEndpointFamilyException;

final class EndpointRouteTest extends MonkeyTestCase {

	/**
	 * Verified models resolve their reviewed path.
	 */
	public function test_verified_model_resolves_chat_path(): void {
		self::assertSame( 'chat', EndpointRoute::endpointKindForModel( 'glm-5.3', 'go' ) );
		self::assertSame( 'chat/completions', EndpointRoute::pathForModel( 'glm-5.3', 'go' ) );
	}

	/**
	 * The registry sentinel family is denied before transport.
	 *
	 * No allowlisted model carries it today, so the kind is exercised directly:
	 * the chat-only path table must still fail closed for it, which is what
	 * keeps a future non-chat record unroutable.
	 */
	public function test_unimplemented_model_routes_are_denied(): void {
		$this->expectException( UnsupportedEndpointFamilyException::class );
		EndpointRoute::pathForEndpointKind( \OpenCodeConnector\Metadata\ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED );
	}

	/**
	 * A model with a recorded pending family still resolves its chat path.
	 *
	 * An undocumented published-table entry is not evidence of a different
	 * family, so the model stays routable instead of disappearing.
	 */
	public function test_pending_family_model_resolves_chat_path(): void {
		self::assertSame( 'chat', EndpointRoute::endpointKindForModel( 'glm-5', 'go' ) );
		self::assertSame( 'chat/completions', EndpointRoute::pathForModel( 'mimo-v2-omni', 'go' ) );
	}

	/**
	 * Unimplemented endpoint kinds are explicitly denied.
	 */
	public function test_unimplemented_family_paths_are_denied(): void {
		foreach ( array( 'responses', 'messages', 'systemone', 'provider-specific' ) as $kind ) {
			try {
				EndpointRoute::pathForEndpointKind( $kind );
				self::fail( 'Expected an unsupported endpoint kind exception.' );
			} catch ( UnsupportedEndpointFamilyException ) {
				self::assertTrue( true );
			}
		}
	}

	/**
	 * A named non-chat family is named in the rejection message.
	 */
	public function test_named_family_is_reported_in_the_message(): void {
		try {
			EndpointRoute::pathForEndpointKind( 'systemone' );
			self::fail( 'Expected an unsupported endpoint kind exception.' );
		} catch ( UnsupportedEndpointFamilyException $exception ) {
			self::assertStringContainsString( 'systemone', $exception->getMessage() );
			self::assertStringContainsString( 'chat/completions only', $exception->getMessage() );
		}
	}

	/**
	 * The registry sentinel explains itself instead of quoting the sentinel.
	 */
	public function test_registry_sentinel_is_not_echoed_back(): void {
		try {
			EndpointRoute::pathForEndpointKind( \OpenCodeConnector\Metadata\ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED );
			self::fail( 'Expected an unsupported endpoint kind exception.' );
		} catch ( UnsupportedEndpointFamilyException $exception ) {
			self::assertStringNotContainsString(
				'"unsupported"',
				$exception->getMessage(),
				'The sentinel must not be reported as if it were a real family name.'
			);
			self::assertStringContainsString( 'not implemented by this adapter', $exception->getMessage() );
		}
	}

	/**
	 * An unrecognised family still fails closed without echoing its name.
	 */
	public function test_unknown_family_fails_closed_without_echoing_name(): void {
		try {
			EndpointRoute::pathForEndpointKind( 'some-future-family' );
			self::fail( 'Expected an unsupported endpoint kind exception.' );
		} catch ( UnsupportedEndpointFamilyException $exception ) {
			self::assertStringNotContainsString( 'some-future-family', $exception->getMessage() );
			self::assertStringContainsString( 'chat/completions only', $exception->getMessage() );
		}
	}

	/**
	 * Unknown models and families fail before transport.
	 */
	public function test_unknown_model_and_family_are_denied(): void {
		$this->expectException( UnsupportedEndpointFamilyException::class );
		EndpointRoute::pathForModel( 'not-verified', 'go' );
	}

	/**
	 * Unsupported family names are never silently sent to chat.
	 */
	public function test_unsupported_family_is_denied(): void {
		$this->expectException( UnsupportedEndpointFamilyException::class );
		EndpointRoute::pathForEndpointKind( 'provider-specific' );
	}
}
