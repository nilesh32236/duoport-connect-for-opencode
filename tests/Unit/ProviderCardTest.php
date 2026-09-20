<?php
/**
 * Distinct Connectors card registration specs for Go and Zen.
 *
 * Each connector must appear as its own Connectors card with an independent
 * save and mask round trip. Core derives `connectors_ai_{providerId}_api_key`
 * from `providerId()`, so the IDs (`opencode-go` / `opencode-zen`) must stay
 * frozen and distinct, and no code may read or write any `connectors_ai_*`
 * option value (cache-bust hooks are transient deletes only).
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Providers\OpenCodeGoProvider;
	use OpenCodeConnector\Providers\OpenCodeZenProvider;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;

	/**
	 * Stand-in for AiClient::defaultRegistry() in card-registration specs.
	 *
	 * @since 0.1.4
	 */
	final class FakeCardRegistry {
		/**
		 * Classes passed to hasProvider(), in order.
		 *
		 * @var string[]
		 */
		public array $checked = array();

		/**
		 * Classes passed to registerProvider(), in order.
		 *
		 * @var string[]
		 */
		public array $registered = array();

		/**
		 * Record a hasProvider() probe (always miss so registration runs).
		 *
		 * @param string $class Provider class name.
		 * @return bool
		 */
		public function hasProvider( string $class ): bool {
			$this->checked[] = $class;
			return false;
		}

		/**
		 * Record a registerProvider() call.
		 *
		 * @param string $class Provider class name.
		 * @return void
		 */
		public function registerProvider( string $class ): void {
			$this->registered[] = $class;
		}
	}

	/**
	 * Distinct-card registration specs.
	 *
	 * @package OpenCodeConnector
	 * @since 0.1.4
	 */
	final class ProviderCardTest extends MonkeyTestCase {

		/**
		 * Plugin root directory.
		 *
		 * @return string
		 */
		private static function plugin_root(): string {
			return dirname( __DIR__, 2 );
		}

		/**
		 * Read a protected static string accessor via reflection.
		 *
		 * @param string $class  Provider class name.
		 * @param string $method Method name.
		 * @return string
		 */
		private static function provider_string( string $class, string $method ): string {
			$ref = new \ReflectionMethod( $class, $method );
			$ref->setAccessible( true );
			$value = $ref->invoke( null );
			self::assertIsString( $value );
			return $value;
		}

		/**
		 * Both providers must be registered on init as distinct cards.
		 *
		 * Captures the `init` callbacks the plugin file registers, invokes the
		 * provider-registration one (priority 5) against a fake registry, and
		 * asserts both classes go through hasProvider() then registerProvider().
		 *
		 * @since 0.1.4
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_go_and_zen_register_as_distinct_cards(): void {
			$callbacks  = array();
			$priorities = array();

			Monkey\Actions\expectAdded( 'init' )
				->twice()
				->with(
					\Mockery::on(
						static function ( $callback ) use ( &$callbacks ): bool {
							$callbacks[] = $callback;
							return true;
						}
					),
					\Mockery::on(
						static function ( $priority ) use ( &$priorities ): bool {
							$priorities[] = $priority;
							return true;
						}
					)
				);

			Functions\when( 'plugin_basename' )->justReturn( 'duoport-connect-for-opencode/duoport-connect-for-opencode.php' );

			require_once self::plugin_root() . '/duoport-connect-for-opencode.php';

			self::assertCount( 2, $callbacks, 'Plugin must register exactly two init callbacks.' );

			$index = array_search( 5, $priorities, true );
			self::assertNotFalse( $index, 'Provider registration must run on init at priority 5.' );
			$register = $callbacks[ $index ];
			self::assertIsCallable( $register );

			if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
				eval( 'namespace WordPress\\AiClient; class AiClient { public static function defaultRegistry(): object { return $GLOBALS["__oc_card_registry"]; } }' );
			}

			$registry                          = new FakeCardRegistry();
			$GLOBALS['__oc_card_registry']     = $registry;
			try {
				$register();
			} finally {
				unset( $GLOBALS['__oc_card_registry'] );
			}

			$expected = array( OpenCodeGoProvider::class, OpenCodeZenProvider::class );
			self::assertSame( $expected, $registry->checked, 'Both providers must be probed via hasProvider().' );
			self::assertSame( $expected, $registry->registered, 'Both providers must be registered as distinct cards.' );
		}

		/**
		 * Provider IDs and derived setting keys must be frozen and distinct.
		 *
		 * @since 0.1.4
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_provider_ids_and_setting_keys_are_distinct(): void {
			require_once self::plugin_root() . '/src/autoload.php';

			$go_id  = self::provider_string( OpenCodeGoProvider::class, 'providerId' );
			$zen_id = self::provider_string( OpenCodeZenProvider::class, 'providerId' );

			self::assertSame( 'opencode-go', $go_id, 'Go provider ID is frozen (core derives its setting key from it).' );
			self::assertSame( 'opencode-zen', $zen_id, 'Zen provider ID is frozen (core derives its setting key from it).' );
			self::assertNotSame( $go_id, $zen_id, 'Distinct cards require distinct provider IDs.' );

			$key_for = static fn( string $id ): string => 'connectors_ai_' . str_replace( '-', '_', $id ) . '_api_key';

			self::assertSame( 'connectors_ai_opencode_go_api_key', $key_for( $go_id ) );
			self::assertSame( 'connectors_ai_opencode_zen_api_key', $key_for( $zen_id ) );
			self::assertNotSame( $key_for( $go_id ), $key_for( $zen_id ), 'Each card keeps its own key setting; never shared or aliased.' );

			$go_name  = self::provider_string( OpenCodeGoProvider::class, 'displayName' );
			$zen_name = self::provider_string( OpenCodeZenProvider::class, 'displayName' );
			self::assertNotSame( '', $go_name );
			self::assertNotSame( '', $zen_name );
			self::assertNotSame( $go_name, $zen_name );

			$go_desc  = self::provider_string( OpenCodeGoProvider::class, 'description' );
			$zen_desc = self::provider_string( OpenCodeZenProvider::class, 'description' );
			self::assertNotSame( '', $go_desc );
			self::assertNotSame( '', $zen_desc );
		}

		/**
		 * No code may read or write any connectors_ai_* option value.
		 *
		 * Static scan over src/ plus the main plugin file: bust hooks must stay
		 * credential-blind (transient deletes only).
		 *
		 * @since 0.1.4
		 *
		 * @return void
		 */
		public function test_no_code_reads_or_writes_connectors_ai_options(): void {
			$root  = self::plugin_root();
			$files = array( $root . '/duoport-connect-for-opencode.php' );

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}

			self::assertNotEmpty( $files );

			$pattern = '/\b(get_option|update_option|add_option|delete_option)\s*\(\s*[\'"]connectors_ai_/';
			$hits    = array();
			foreach ( $files as $file ) {
				$source = (string) file_get_contents( $file );
				if ( preg_match( $pattern, $source ) ) {
					$hits[] = basename( $file );
				}
			}

			self::assertSame(
				array(),
				$hits,
				'Credential-blindness violated: these files read/write connectors_ai_* options: ' . implode( ', ', $hits )
			);
		}
	}
}
