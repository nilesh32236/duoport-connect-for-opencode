<?php
/**
 * Boundary-hardening specs for the PSR-4 autoloader and the settings callback.
 *
 * Two defence-in-depth gaps, both of which are only reachable by a caller that
 * already controls a dynamically-constructed class name or bypasses the
 * registered admin menu. Neither is a live bypass, and each is worth three
 * lines to close:
 *
 * 1. `src/autoload.php` turned the namespace remainder into a filesystem path
 *    with no check on its segments, so a name containing `..` resolved OUTSIDE
 *    `src/` and was `require`d from there. This is the one place in the plugin
 *    where untrusted-shaped input reaches a `require`.
 * 2. `Settings::render()` was reachable only because `add_options_page()` was
 *    given `manage_options`. Enforcing the capability at the callback also puts
 *    the outbound provider probe it triggers behind that check.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Settings\Settings;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;

	/**
	 * Boundary-hardening specs.
	 *
	 * @package OpenCodeConnector
	 */
	final class HardeningBoundaryTest extends MonkeyTestCase {

		/**
		 * Name of the planted probe file, one level above `src`.
		 *
		 * @var string
		 */
		private const PROBE_BASENAME = 'opencode-autoload-guard-probe.php';

		/**
		 * Plant a file one level above src/ that records having been included.
		 *
		 * Without this the traversal spec below would be vacuous: an absent
		 * target file is rejected by `file_exists()` just as happily as by a
		 * segment check, so a green test would prove nothing about the guard.
		 * The file records a global on include, which is the only observable
		 * that distinguishes "refused" from "included something harmless".
		 *
		 * @return string Absolute path to the planted file.
		 */
		private function plant_probe_outside_src(): string {
			$path = dirname( __DIR__, 2 ) . '/' . self::PROBE_BASENAME;
			file_put_contents(
				$path,
				"<?php\n\$GLOBALS['opencode_autoload_escape_probe'] = true;\n"
			);
			return $path;
		}

		/**
		 * Ordinary class names still autoload.
		 *
		 * The regression guard that matters most: the hardening must reject only
		 * malformed remainders. If it ever stopped resolving real classes, every
		 * other spec in the suite would fail noisily, but only an explicit
		 * assertion says so at the seam the change was made.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_real_class_names_still_autoload(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			self::assertTrue(
				class_exists( \OpenCodeConnector\Media\ImageMime::class ),
				'A legitimate PSR-4 name must still resolve.'
			);
			self::assertTrue(
				class_exists( \OpenCodeConnector\Metadata\Catalog::class ),
				'A nested PSR-4 name must still resolve.'
			);
		}

		/**
		 * A traversal remainder never reaches a file outside src/.
		 *
		 * `OpenCodeConnector\..\opencode-autoload-guard-probe` maps, under the
		 * unguarded version of the autoloader, to
		 * `<plugin>/src/../opencode-autoload-guard-probe.php` — a real file,
		 * outside `src/`, `require`d because `file_exists()` said yes.
		 *
		 * The autoloaders are invoked directly rather than through
		 * `class_exists()` for a reason worth stating plainly: PHP itself
		 * refuses to pass a name containing `.` or a NUL byte to an autoloader,
		 * so the `..` form never gets there through the language. That makes
		 * this exact defence in depth — which is also how the finding described
		 * it — and a test that could only reach it by the language would be
		 * testing PHP rather than this plugin's guard. Calling the registered
		 * closures is what actually exercises the code that was changed.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_a_traversal_remainder_is_not_included(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			$planted = $this->plant_probe_outside_src();
			$loaders = array_values( array_filter( spl_autoload_functions(), 'is_callable' ) );
			unset( $GLOBALS['opencode_autoload_escape_probe'] );

			try {
				self::assertNotEmpty( $loaders, 'The plugin autoloader must be registered.' );

				foreach ( $loaders as $loader ) {
					$loader( 'OpenCodeConnector\\..\\' . str_replace( '.php', '', self::PROBE_BASENAME ) );
				}

				self::assertArrayNotHasKey(
					'opencode_autoload_escape_probe',
					$GLOBALS,
					'The file one level above src/ must never be included by the autoloader.'
				);
			} finally {
				if ( is_file( $planted ) ) {
					unlink( $planted );
				}
			}
		}

		/**
		 * Remainders that are not plain identifiers are refused outright.
		 *
		 * Every class this plugin ships matches `^[A-Za-z_][A-Za-z0-9_]*$` per
		 * segment, so requiring it costs nothing and rules out `.`, `..`, empty
		 * segments, embedded NUL bytes, absolute paths and stream wrappers in
		 * one rule.
		 *
		 * The doubled- and trailing-backslash forms are included here because
		 * those two ARE handed to an autoloader by PHP, so they exercise the
		 * segment rule rather than PHP's own name validation.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_malformed_remainder_segments_are_refused(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';

			$planted = $this->plant_probe_outside_src();
			$loaders = array_values( array_filter( spl_autoload_functions(), 'is_callable' ) );
			unset( $GLOBALS['opencode_autoload_escape_probe'] );

			$names = array(
				'OpenCodeConnector\\Media\\.\\ImageMime',
				'OpenCodeConnector\\\\Media',
				'OpenCodeConnector\\\\Media\\\\',
				'OpenCodeConnector\\php://filter/resource=ImageMime',
				'OpenCodeConnector\\..',
				'OpenCodeConnector\\.',
			);

			try {
				foreach ( $names as $name ) {
					foreach ( $loaders as $loader ) {
						$loader( $name );
					}
					self::assertFalse( class_exists( $name, true ), 'Malformed remainder must not autoload: ' . $name );
				}
				self::assertArrayNotHasKey(
					'opencode_autoload_escape_probe',
					$GLOBALS,
					'No malformed remainder may reach outside src/.'
				);

				// Sanity: the class those names aimed at does exist, so the loop
				// above is testing the guard and not an absent class.
				self::assertTrue( class_exists( \OpenCodeConnector\Media\ImageMime::class ) );
			} finally {
				if ( is_file( $planted ) ) {
					unlink( $planted );
				}
			}
		}

		/**
		 * render() refuses an unprivileged caller before any probe can run.
		 *
		 * `render()` calls `fetchProviderStatus()`, which drives the registry
		 * into `isProviderConfigured()` and can trigger a real outbound HTTP
		 * probe to the OpenCode gateway. That effect belongs behind the
		 * capability check rather than behind the `add_options_page()`
		 * registration some future caller might not go through — which is the
		 * same pattern the plugin entry file already uses for its admin output.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_settings_render_refuses_an_unprivileged_caller(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
			if ( ! defined( 'OpenCodeConnector\\OPTION_NAME' ) ) {
				define( 'OpenCodeConnector\\OPTION_NAME', 'opencode_connector_settings' );
			}

			$died       = false;
			$read_probe = false;

			Functions\when( 'current_user_can' )->justReturn( false );
			Functions\when( 'wp_die' )->alias(
				static function ( $message = '' ) use ( &$died ): void {
					unset( $message );
					$died = true;
					throw new \RuntimeException( 'wp_die' );
				}
			);
			Functions\when( 'get_option' )->alias(
				static function ( ...$args ) use ( &$read_probe ) {
					$read_probe = true;
					unset( $args );
					return array();
				}
			);
			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();

			$settings = new Settings();

			try {
				$settings->render();
				self::fail( 'render() must not reach its output for an unprivileged caller.' );
			} catch ( \RuntimeException $exception ) {
				self::assertSame( 'wp_die', $exception->getMessage() );
			}

			self::assertTrue( $died, 'The capability check must terminate the request.' );
			self::assertFalse( $read_probe, 'Nothing may be read — and therefore no probe triggered — before the capability check.' );
		}

		/**
		 * render() still renders for a capable caller.
		 *
		 * The counterweight to the spec above: a guard that stops the legitimate
		 * path is not a fix. Only the tail of the markup is asserted, because
		 * the method emits a whole admin page.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_settings_render_still_renders_for_a_capable_caller(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
			if ( ! defined( 'OpenCodeConnector\\OPTION_NAME' ) ) {
				define( 'OpenCodeConnector\\OPTION_NAME', 'opencode_connector_settings' );
			}

			Functions\when( 'current_user_can' )->justReturn( true );
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => false ) );
			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'esc_html_e' )->justReturn( null );
			Functions\when( 'esc_attr' )->returnArg();
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/options-connectors.php' );
			Functions\when( 'wp_kses_post' )->returnArg();
			Functions\when( 'settings_fields' )->justReturn( null );
			Functions\when( 'submit_button' )->justReturn( null );
			Functions\when( 'checked' )->justReturn( null );

			$settings = new Settings();
			ob_start();
			$settings->render();
			$markup = (string) ob_get_clean();

			self::assertStringContainsString( '<div class="wrap">', $markup );
			self::assertStringContainsString( 'opencode_connector_settings', $markup );
		}
	}
}
