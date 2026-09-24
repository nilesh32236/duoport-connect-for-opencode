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
	 * Model-level unimplemented Zen routes are denied before transport.
	 */
	public function test_unimplemented_model_routes_are_denied(): void {
		foreach ( array( 'minimax-m3', 'minimax-m2.7', 'minimax-m2.5' ) as $id ) {
			try {
				EndpointRoute::pathForModel( $id, 'zen' );
				self::fail( 'Expected an unsupported model route exception.' );
			} catch ( UnsupportedEndpointFamilyException ) {
				self::assertTrue( true );
			}
		}
	}

	/**
	 * Unimplemented endpoint kinds are explicitly denied.
	 */
	public function test_unimplemented_family_paths_are_denied(): void {
		foreach ( array( 'responses', 'messages', 'provider-specific' ) as $kind ) {
			try {
				EndpointRoute::pathForEndpointKind( $kind );
				self::fail( 'Expected an unsupported endpoint kind exception.' );
			} catch ( UnsupportedEndpointFamilyException ) {
				self::assertTrue( true );
			}
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
