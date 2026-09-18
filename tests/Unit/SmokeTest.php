<?php
/**
 * Smoke test proving the PHPUnit + Brain Monkey harness is wired up.
 *
 * @package OpenCodeConnector
 */

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Harness smoke test.
 */
final class SmokeTest extends MonkeyTestCase {

	/**
	 * Trivially-passing assertion: the harness itself is alive.
	 */
	public function test_harness_boots(): void {
		self::assertTrue( true );
	}

	/**
	 * Brain Monkey stubs a WordPress function without WP core loaded.
	 */
	public function test_brain_monkey_stubs_wp_functions(): void {
		Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( 'stubbed-user' );

		self::assertSame( 'stubbed-user', wp_get_current_user() );
	}

	/**
	 * Brain Monkey expects an action to be added.
	 */
	public function test_brain_monkey_expects_action_added(): void {
		Monkey\Actions\expectAdded( 'init' )->once();

		add_action( 'init', '__return_null' );

		self::assertTrue( has_action( 'init' ) );
	}
}
