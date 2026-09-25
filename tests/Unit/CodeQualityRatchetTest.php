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
	 * Release metadata and post-0.1.4 API annotations are synchronized.
	 */
	public function test_prepared_release_metadata_is_synchronized(): void {
		$root = dirname( __DIR__, 2 );
		$main = (string) file_get_contents( $root . '/duoport-connect-for-opencode.php' );
		$readme = (string) file_get_contents( $root . '/readme.txt' );

		self::assertStringContainsString( 'Version:           0.1.6', $main );
		self::assertStringContainsString( "const VERSION     = '0.1.6';", $main );
		self::assertStringContainsString( 'Stable tag: 0.1.6', $readme );
		self::assertStringContainsString( '= 0.1.6 =', $readme );
		self::assertStringContainsString( "const FALLBACK_VERSION = '0.1.6';", (string) file_get_contents( $root . '/src/Http/ClientUserAgent.php' ) );

		$post_release_files = array(
			$root . '/src/Http/ClientUserAgent.php',
			$root . '/src/Http/GoRequestHeaders.php',
			$root . '/src/Http/SessionHeader.php',
			$root . '/src/Models/AbstractOpenCodeTextGenerationModel.php',
			$root . '/src/Models/AbstractOpenCodeImageGenerationModel.php',
		);
		foreach ( $post_release_files as $file ) {
			self::assertStringContainsString(
				'@since 0.1.5',
				(string) file_get_contents( $file ),
				basename( $file ) . ' must document the post-0.1.4 API in the prepared release.'
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
