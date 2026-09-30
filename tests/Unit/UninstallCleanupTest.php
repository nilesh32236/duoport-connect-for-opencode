<?php
/**
 * Uninstall cleanup specs.
 *
 * The settings row is registered per blog and the transients are per-site, so
 * a network uninstall has to sweep every site or a reinstall inherits a stale
 * `show_all_models` value and re-triggers a full /models sweep.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class UninstallCleanupTest extends MonkeyTestCase {

	/**
	 * Boot the WordPress and Brain Monkey surface uninstall.php expects.
	 *
	 * @return void
	 */
	private function boot(): void {
		require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'duoport-connect-for-opencode/duoport-connect-for-opencode.php' );
		}
	}

	/**
	 * Stub the uninstall-time WordPress functions and record their calls.
	 *
	 * @param array $calls Recorded calls by function name.
	 * @return void
	 */
	private function stub( array &$calls ): void {
		$record = static function ( string $name ) use ( &$calls ): \Closure {
			return static function ( ...$args ) use ( &$calls, $name ): bool {
				$calls[ $name ][] = $args[0] ?? null;
				return true;
			};
		};
		foreach ( array( 'delete_option', 'delete_site_option', 'delete_transient', 'delete_site_transient', 'restore_current_blog' ) as $name ) {
			Functions\when( $name )->alias( $record( $name ) );
		}
		Functions\when( 'switch_to_blog' )->alias(
			static function ( int $blog_id ) use ( &$calls ): bool {
				$calls['switch_to_blog'][] = $blog_id;
				return true;
			}
		);
	}

	/**
	 * A network uninstall clears every site's option and transients.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_multisite_uninstall_sweeps_every_site(): void {
		$this->boot();
		$calls = array();
		$this->stub( $calls );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->alias(
			static function ( array $args = array() ): array {
				// The bounded cap keeps a large network from timing the request out.
				self::assertLessThanOrEqual( 500, (int) ( $args['number'] ?? 0 ) );
				self::assertSame( 'ids', $args['fields'] ?? '' );
				return array( 2, 3 );
			}
		);

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		// Current site first, then one pass per site in the network.
		self::assertSame( array( 2, 3 ), $calls['switch_to_blog'] );
		self::assertCount( 2, $calls['restore_current_blog'] );
		// 1 current site + 2 switched sites.
		self::assertCount( 3, $calls['delete_option'] );
		// Network-level rows are cleared once, not per site.
		self::assertCount( 1, $calls['delete_site_option'] );
		foreach ( $calls['delete_option'] as $option ) {
			self::assertSame( 'opencode_connector_settings', $option );
		}
		$expected_transients = \OpenCodeConnector\Metadata\Catalog::allTransientKeys();
		foreach ( $expected_transients as $key ) {
			self::assertContains( $key, $calls['delete_transient'] );
			self::assertContains( $key, $calls['delete_site_transient'] );
		}
		// Current site plus one delete per switched site, for every key.
		self::assertCount( 3 * count( $expected_transients ), $calls['delete_transient'] );
	}

	/**
	 * A single-site uninstall does not touch the site switch API.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_single_site_uninstall_stays_on_the_current_site(): void {
		$this->boot();
		$calls = array();
		$this->stub( $calls );
		Functions\when( 'is_multisite' )->justReturn( false );

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertArrayNotHasKey( 'switch_to_blog', $calls );
		self::assertArrayNotHasKey( 'restore_current_blog', $calls );
		self::assertCount( 1, $calls['delete_option'] );
		self::assertCount( 1, $calls['delete_site_option'] );
	}
}
