<?php
/**
 * Regression specs: no shared connector setting override.
 *
 * 0.1.1 aliased the opencode-zen connector onto the Go key setting via
 * `wp_connectors_init`. Core's `/wp/v2/settings` dispatch validates and
 * masks EACH connector's setting independently, so the second connector
 * ended up validating the first one's already-masked placeholder, the save
 * was reverted to an empty string, and valid keys were rejected with "It
 * was not possible to connect to the provider using this key."
 *
 * Each catalog keeps its own key setting
 * (`connectors_ai_opencode_{go,zen}_api_key`) so every validation runs
 * against the real submitted key. This test locks that in: the plugin must
 * register no `wp_connectors_init` callback at all.
 *
 * @package OpenCodeConnector
 * @since 0.1.2
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Shared-setting regression guard.
 *
 * @package OpenCodeConnector
 * @since 0.1.2
 */
final class NoSharedSettingTest extends MonkeyTestCase {

	/**
	 * The plugin must not hook wp_connectors_init (no setting aliasing).
	 *
	 * @since 0.1.2
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_plugin_registers_no_wp_connectors_init_override(): void {
		Monkey\Actions\expectAdded( 'wp_connectors_init' )->never();

		Functions\when( 'plugin_basename' )->justReturn( 'duoport-connect-for-opencode/duoport-connect-for-opencode.php' );

		require_once dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php';

		// The never() expectation is verified by Mockery on tearDown.
		self::assertTrue( true );
	}
}
