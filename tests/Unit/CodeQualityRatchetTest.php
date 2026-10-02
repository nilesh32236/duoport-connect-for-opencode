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

		self::assertStringContainsString( 'Version:           0.1.7', $main );
		self::assertStringContainsString( "const VERSION     = '0.1.7';", $main );
		self::assertStringContainsString( 'Stable tag: 0.1.7', $readme );
		self::assertStringContainsString( 'Tested up to: 7.1.2', $readme );
		$changelog_heading = '== Changelog ==';
		$notice_heading    = '== Upgrade Notice ==';
		$changelog_at      = strpos( $readme, $changelog_heading );
		$notice_at         = strpos( $readme, $notice_heading );
		self::assertNotFalse( $changelog_at, 'Changelog heading must exist.' );
		self::assertNotFalse( $notice_at, 'Upgrade Notice heading must exist.' );
		if ( false === $changelog_at || false === $notice_at || $changelog_at >= $notice_at ) {
			self::fail( 'Changelog must precede Upgrade Notice.' );
		}
		$changelog = substr( $readme, $changelog_at + strlen( $changelog_heading ), $notice_at - ( $changelog_at + strlen( $changelog_heading ) ) );
		$notice    = substr( $readme, $notice_at + strlen( $notice_heading ) );
		self::assertStringContainsString( '= 0.1.7 =', $changelog );
		self::assertStringContainsString( '= 0.1.7 =', $notice );
		self::assertStringContainsString( "const FALLBACK_VERSION = '0.1.7';", (string) file_get_contents( $root . '/src/Http/ClientUserAgent.php' ) );

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
	 * Product code must not carry WordPress-present guards around __().
	 *
	 * The providers wrapped every translated string in
	 * `function_exists( '__' )` with an untranslated English fallback. In a
	 * WordPress plugin __() always exists, so the guard only existed for
	 * SDK-free tests (Brain Monkey provides __()), and a genuinely missing
	 * translation silently returned English with no signal.
	 *
	 * @since 0.1.8
	 */
	public function test_provider_strings_call_translation_directly(): void {
		$root = dirname( __DIR__, 2 );

		foreach ( array( 'OpenCodeGoProvider.php', 'OpenCodeZenProvider.php' ) as $file ) {
			$source = (string) file_get_contents( $root . '/src/Providers/' . $file );

			self::assertStringNotContainsString(
				"function_exists( '__' )",
				$source,
				$file . ' must call __() directly; the guard ships test scaffolding as product code.'
			);
			self::assertStringContainsString(
				"__( 'OpenCode",
				$source,
				$file . ' must keep its user-facing strings translated.'
			);
		}
	}

	/**
	 * The availability class must use one cache-bust implementation.
	 *
	 * verify() re-implemented raw transient access with inline try/catch while
	 * probe() used the guarded helpers, so the fail-open rule existed twice in
	 * one class and could diverge. Every transient write in this class must go
	 * through the helpers.
	 *
	 * @since 0.1.8
	 */
	public function test_availability_transients_go_through_the_guarded_helpers(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php' );

		foreach ( array( 'get_transient', 'set_transient', 'delete_transient' ) as $call ) {
			self::assertSame(
				1,
				substr_count( $source, "function_exists( '" . $call . "' )" ),
				$call . ' must be reached through exactly one guarded helper.'
			);
		}
		self::assertStringContainsString( '$this->read_cached(', $source );
		self::assertStringContainsString( '$this->write_cached(', $source );
		self::assertStringContainsString( '$this->delete_cached(', $source );
		self::assertStringNotContainsString(
			'unset( $',
			$source,
			'The unset( $exception ) idiom silences nothing: PHP does not warn on unused catch bindings.'
		);
	}

	/**
	 * The PSR-4 loader must confine resolution to the plugin directory.
	 *
	 * It is the one place in src/ that turns a class name into a filesystem
	 * path, so the resolved path is checked for containment.
	 *
	 * @since 0.1.8
	 */
	public function test_autoloader_confines_resolution_to_the_plugin_directory(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/autoload.php' );

		self::assertStringContainsString( 'realpath(', $source, 'The loader must resolve the candidate path before requiring it.' );
		self::assertStringContainsString(
			'str_starts_with( $file, $base . DIRECTORY_SEPARATOR )',
			$source,
			'The loader must reject a resolved path outside the plugin directory.'
		);
		self::assertStringNotContainsString( "if ( file_exists( \$file ) ) {", $source );
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
