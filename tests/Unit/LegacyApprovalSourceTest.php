<?php
/**
 * RED spec (plan todo 5d): no self-approval / Approvals_Store usage in source.
 *
 * The plugin must not grant itself connector approvals by poking WordPress'
 * AI plugin internals (Approvals_Store, wpai_connector_approval_pending,
 * set_approval). Until the legacy block is deleted from the main file, this
 * static source scan fails listing every offending location.
 *
 * @package OpenCodeConnector
 */

namespace OpenCodeConnector\Tests\Unit;

/**
 * Static source assertion against self-approval internals.
 */
final class LegacyApprovalSourceTest extends MonkeyTestCase {

	/**
	 * Main file + src/** must not reference approval-store internals.
	 */
	public function test_plugin_source_contains_no_approval_store_usage(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/duoport-connect-for-opencode.php' );

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file_info ) {
			if ( $file_info->isFile() && 'php' === $file_info->getExtension() ) {
				$files[] = $file_info->getPathname();
			}
		}

		$forbidden  = array( 'Approvals_Store', 'wpai_connector_approval_pending', 'set_approval' );
		$violations = array();

		foreach ( $files as $file ) {
			$source = file_get_contents( $file );
			foreach ( $forbidden as $needle ) {
				if ( str_contains( $source, $needle ) ) {
					$violations[] = basename( $file ) . ' contains "' . $needle . '"';
				}
			}
		}

		self::assertSame(
			array(),
			$violations,
			"Self-approval / Approvals_Store usage must be removed:\n" . implode( "\n", $violations )
		);
	}
}
