<?php
/**
 * Evidence boundary for the future image-generation adapter.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use OpenCodeConnector\Metadata\ModelAllowlist;
use OpenCodeConnector\Metadata\ModelRegistry;

final class ImageGenerationEvidenceTest extends MonkeyTestCase {

	/**
	 * The image allowlist remains empty for both catalogs.
	 */
	public function test_image_allowlist_remains_default_deny(): void {
		foreach ( array( 'go', 'zen' ) as $catalog ) {
			self::assertSame( array(), array_values( array_filter(
				ModelAllowlist::allowedIds( $catalog ),
				static fn( string $id ): bool => ModelAllowlist::isImageCapable( $id, $catalog )
			) ) );
			foreach ( ModelRegistry::records( $catalog ) as $record ) {
				self::assertFalse( $record['capabilities']['image'] );
			}
		}
	}

	/**
	 * The image policy remains credential-blind and evidence-gated.
	 */
	public function test_image_policy_remains_evidence_gated_and_credential_blind(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Metadata/ModelAllowlist.php' );

		self::assertStringContainsString( 'Image capability is advertised only for IDs in this set', $source );
		self::assertStringContainsString( "'go'  => array()", $source );
		self::assertStringContainsString( "'zen' => array()", $source );
		self::assertStringNotContainsString( 'connectors_ai_', $source );
	}
}
