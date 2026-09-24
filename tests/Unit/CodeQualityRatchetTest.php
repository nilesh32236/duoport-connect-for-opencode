<?php
/**
 * Focused ratchet tests for the issue #41 cleanup.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

/**
 * Guards the small maintainability invariants changed by issue #41.
 */
final class CodeQualityRatchetTest extends MonkeyTestCase {

	/**
	 * Product source must not document an unreleased version.
	 */
	public function test_product_source_has_no_future_since_tags(): void {
		$root = dirname( __DIR__, 2 );
		$files = array( $root . '/src/Http/ClientUserAgent.php', $root . '/src/Http/SessionHeader.php', $root . '/src/Models/AbstractOpenCodeTextGenerationModel.php', $root . '/src/Models/AbstractOpenCodeImageGenerationModel.php' );

		foreach ( $files as $file ) {
			self::assertStringNotContainsString(
				'@since 0.1.5',
				(string) file_get_contents( $file ),
				basename( $file ) . ' must not document a version before release preparation.'
			);
		}
	}

	/**
	 * Tool-capability catalog resolution must use exact provider class identity.
	 */
	public function test_tool_gate_uses_exact_provider_classes(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Models/AbstractOpenCodeTextGenerationModel.php' );

		self::assertStringContainsString( 'OpenCodeGoProvider::class === $cls', $source );
		self::assertStringContainsString( 'OpenCodeZenProvider::class === $cls', $source );
		self::assertStringNotContainsString( 'stripos( $cls, \'zen\' )', $source );
		self::assertStringNotContainsString( 'stripos( $cls, \'go\' )', $source );
	}

	/**
	 * PHP 8.2+ no longer needs a setAccessible compatibility probe.
	 */
	public function test_tool_gate_drops_dead_set_accessible_probe(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Models/AbstractOpenCodeTextGenerationModel.php' );

		self::assertStringNotContainsString( '->setAccessible(', $source );
		self::assertStringNotContainsString( 'method_exists( $prop, \'setAccessible\' )', $source );
	}

	/**
	 * The shared Go header helper is the single text/image header seam.
	 */
	public function test_text_and_image_models_share_go_header_helper(): void {
		$root = dirname( __DIR__, 2 );

		foreach ( array( 'AbstractOpenCodeTextGenerationModel.php', 'AbstractOpenCodeImageGenerationModel.php' ) as $name ) {
			$source = (string) file_get_contents( $root . '/src/Models/' . $name );
			self::assertStringContainsString( 'GoRequestHeaders::for_go', $source, $name . ' must use the shared helper.' );
		}
	}
}
