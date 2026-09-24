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
	 * Known family names have explicit paths.
	 */
	public function test_supported_family_paths_are_explicit(): void {
		self::assertSame( 'responses', EndpointRoute::pathForEndpointKind( 'responses' ) );
		self::assertSame( 'messages', EndpointRoute::pathForEndpointKind( 'messages' ) );
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
