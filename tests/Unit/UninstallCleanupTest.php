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
		// A full first page, then a short one, so the sweep has to advance the
		// offset instead of re-cleaning the same lowest-ID prefix.
		$site_args = array();
		Functions\when( 'get_sites' )->alias(
			static function ( array $args = array() ) use ( &$site_args ): array {
				$site_args[] = $args;
				$offset       = (int) ( $args['offset'] ?? 0 );
				if ( 0 === $offset ) {
					return range( 2, 1 + (int) $args['number'] );
				}
				return array( 4, 5 );
			}
		);
		Functions\when( 'wp_suspend_cache_invalidation' )->alias(
			static function ( bool $suspend = true ) use ( &$calls ): bool {
				$calls['wp_suspend_cache_invalidation'][] = $suspend;
				return true;
			}
		);

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$first_page = (int) ( $site_args[0]['number'] ?? 0 );
		$expected   = array_merge( range( 2, 1 + $first_page ), array( 4, 5 ) );
		// Every site is switched to exactly once, and each switch is unwound.
		self::assertSame( $expected, $calls['switch_to_blog'] );
		self::assertCount( count( $expected ), $calls['restore_current_blog'] );
		self::assertSame( array( true, false ), $calls['wp_suspend_cache_invalidation'] );
		// The paging contract, asserted here rather than inside the get_sites
		// double: an assertion in the stub is never checked when the stub is
		// not reached, and its argument order is easy to invert.
		self::assertSame( 'ids', $site_args[0]['fields'] ?? '' );
		self::assertGreaterThan( 0, $first_page, 'A zero page size would never advance.' );
		self::assertSame( 0, (int) ( $site_args[0]['offset'] ?? -1 ) );
		self::assertSame( $first_page, (int) ( $site_args[1]['offset'] ?? 0 ), 'Each batch must advance by one page.' );
		// 1 current site + one pass per site in the network.
		self::assertCount( 1 + count( $expected ), $calls['delete_option'] );
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
		// Current site plus one pass per site in the network, for every key:
		// the plugin's own transients plus the two AI Client model caches.
		self::assertCount(
			( 1 + count( $expected ) ) * ( count( $expected_transients ) + 2 ),
			$calls['delete_transient']
		);
	}

	/**
	 * The model-cache delete stays scoped to this plugin's own directories.
	 *
	 * The AI Client cache key is provider-agnostic apart from the per-directory
	 * md5, so a wildcard `ai_client_%_models` delete would purge another
	 * component's live model cache across the whole network.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_model_cache_cleanup_is_scoped_to_plugin_owned_keys(): void {
		$this->boot();
		$calls = array();
		$this->stub( $calls );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( array() );
		Functions\when( 'wp_suspend_cache_invalidation' )->justReturn( true );

		$prepared = array();
		$queries  = array();
		$wpdb     = new class( $prepared, $queries ) {
			/**
			 * Options table name.
			 *
			 * @var string
			 */
			public string $options = 'wp_options';

			/**
			 * Sitemeta table name.
			 *
			 * @var string
			 */
			public string $sitemeta = 'wp_sitemeta';

			/**
			 * Prepared statement arguments by call.
			 *
			 * @var array<int, mixed>
			 */
			private array $prepared;

			/**
			 * Executed queries.
			 *
			 * @var array<int, string>
			 */
			public array $queries;

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $prepared Prepared args sink.
			 * @param array<int, string> $queries  Query sink.
			 */
			public function __construct( array &$prepared, array &$queries ) {
				$this->prepared = &$prepared;
				$this->queries  = &$queries;
			}

			/**
			 * Record a prepare() call.
			 *
			 * @param string $query Query with placeholders.
			 * @param mixed  ...$args Bound values.
			 * @return string
			 */
			public function prepare( string $query, ...$args ): string {
				$this->prepared[] = $args;
				return $query;
			}

			/**
			 * Record a query() call.
			 *
			 * @param string $sql SQL.
			 * @return int
			 */
			public function query( string $sql ): int {
				$this->queries[] = $sql;
				return 1;
			}
		};

		$previous  = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $wpdb;
		try {
			require dirname( __DIR__, 2 ) . '/uninstall.php';
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $previous;
			}
		}

		$patterns = array();
		foreach ( $prepared as $args ) {
			$patterns[] = (string) ( $args[0] ?? '' );
		}
		self::assertNotEmpty( $patterns, 'The model-cache rows are deleted with a bound pattern.' );
		foreach ( $patterns as $pattern ) {
			self::assertStringNotContainsString( '_models\'', $pattern, 'No provider-agnostic wildcard tail.' );
			self::assertStringNotContainsString( '_', str_replace( '\\_', '', substr( $pattern, 0, 1 ) ), 'Leading underscore must be escaped.' );
		}
		// Both directories are represented, so neither leaves its cache behind.
		$joined = implode( "\n", $patterns );
		self::assertStringContainsString( md5( 'OpenCodeConnector\\Metadata\\OpenCodeGoModelMetadataDirectory' ), $joined );
		self::assertStringContainsString( md5( 'OpenCodeConnector\\Metadata\\OpenCodeZenModelMetadataDirectory' ), $joined );
		foreach ( $wpdb->queries as $query ) {
			self::assertStringNotContainsString( 'ai_client\_%\_models', $query, 'A provider-agnostic delete must never ship.' );
		}
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
