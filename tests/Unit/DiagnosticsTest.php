<?php
/**
 * Diagnostics specs for the Saved vs Verified settings status.
 *
 * Covers the cause-class diagnostics added for the verify-but-not-persist
 * confusion: validCauses() allowlist, causeMessage() mapping, diagnosisFor()
 * override logic (no forced ok), isSaveReverted() hints, key/cause transient
 * names, and the keyless dry-run preview.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

use Brain\Monkey\Functions;
use OpenCodeConnector\Settings\Settings;

/**
 * Diagnostics specs.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */
final class DiagnosticsTest extends MonkeyTestCase {

	/**
	 * Boot the plugin autoloader and translation stubs.
	 *
	 * @return void
	 */
	private function boot(): void {
		require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		Functions\when( '__' )->alias(
			static fn ( ...$args ): string => (string) ( $args[0] ?? '' )
		);
	}

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param object|string $target Target object or class.
	 * @param string        $method Method name.
	 * @param array         $args   Call args.
	 * @return mixed
	 */
	private static function call_private( $target, string $method, array $args = array() ): mixed {
		$ref = new \ReflectionMethod( $target, $method );
		$ref->setAccessible( true );
		return $ref->isStatic() ? $ref->invokeArgs( null, $args ) : $ref->invokeArgs( $target, $args );
	}

	/**
	 * validCauses() must return the full cause allowlist.
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	public function test_valid_causes_returns_allowlist(): void {
		$this->boot();

		$causes = Settings::validCauses();
		foreach ( array( 'ok', 'valid-no-credits', 'throttled', 'bad-key', 'network-failure', 'server-error', 'unconfigured', 'unknown' ) as $expected ) {
			self::assertContains( $expected, $causes );
		}
	}

	/**
	 * Each cause class must map to a distinct, non-empty message.
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	public function test_cause_message_maps_each_cause_distinctly(): void {
		$this->boot();

		$messages = array();
		foreach ( Settings::validCauses() as $cause ) {
			$text = Settings::causeMessage( $cause );
			self::assertNotSame( '', $text, "causeMessage({$cause}) must not be empty." );
			$messages[ $cause ] = $text;
		}
		self::assertCount(
			count( array_unique( $messages ) ),
			$messages,
			'Each cause class must map to a distinct message.'
		);
		self::assertSame(
			Settings::causeMessage( 'unknown' ),
			Settings::causeMessage( 'bogus-cause' ),
			'Unknown cause classes must fall back to the unknown message.'
		);
	}

	/**
	 * Transient key helpers must stay per-catalog and plugin-owned.
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	public function test_transient_key_helpers_are_per_catalog(): void {
		$this->boot();

		self::assertSame( 'opencode_connector_key_seen_go', Settings::saveMarkerKey( 'go' ) );
		self::assertSame( 'opencode_connector_key_seen_zen', Settings::saveMarkerKey( 'zen' ) );
		self::assertSame( 'opencode_connector_avail_go_cause', Settings::causeKey( 'go' ) );
		self::assertSame( 'opencode_connector_avail_zen_cause', Settings::causeKey( 'zen' ) );
		foreach ( array( Settings::saveMarkerKey( 'go' ), Settings::saveMarkerKey( 'zen' ), Settings::causeKey( 'go' ), Settings::causeKey( 'zen' ) ) as $key ) {
			self::assertStringNotContainsString( 'connectors_ai_', $key );
		}
	}

	/**
	 * A live-configured verdict with no cached cause must not be forced to ok.
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	public function test_diagnosis_defaults_to_unknown_without_cached_cause(): void {
		$this->boot();
		Functions\when( 'get_transient' )->justReturn( false );

		$settings = new Settings();
		$result   = self::call_private( $settings, 'diagnosisFor', array( 'go', true ) );

		self::assertTrue( $result['configured'], 'The live registry verdict must be preserved.' );
		self::assertSame( 'unknown', $result['cause'], 'Without a cached cause the diagnosis must stay unknown, not ok.' );

		$cold = self::call_private( $settings, 'diagnosisFor', array( 'zen', false ) );
		self::assertFalse( $cold['configured'] );
		self::assertSame( 'unknown', $cold['cause'] );
	}

	/**
	 * A cached throttled cause must override a stale live verdict precisely.
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	public function test_diagnosis_prefers_cached_cause_precision(): void {
		$this->boot();
		Functions\when( 'get_transient' )->alias(
			static fn ( ...$args ): mixed => in_array( $args[0], array( 'opencode_connector_avail_go_cause', 'opencode_connector_avail_zen_cause' ), true ) ? 'throttled' : false
		);

		$settings = new Settings();
		$result   = self::call_private( $settings, 'diagnosisFor', array( 'go', false ) );

		self::assertSame( 'throttled', $result['cause'] );
		self::assertTrue( $result['configured'], 'throttled is a configured cause and must override the stale verdict.' );
	}

	/**
	 * isSaveReverted() must only fire for persisted-but-unverified states.
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	public function test_is_save_reverted_logic(): void {
		$this->boot();

		self::assertTrue( self::call_private( Settings::class, 'isSaveReverted', array( 'yes', array( 'configured' => false, 'cause' => 'bad-key' ) ) ) );
		self::assertTrue( self::call_private( Settings::class, 'isSaveReverted', array( 'yes', array( 'configured' => false, 'cause' => 'unknown' ) ) ) );
		self::assertFalse( self::call_private( Settings::class, 'isSaveReverted', array( 'yes', array( 'configured' => true, 'cause' => 'ok' ) ) ), 'Verified keys are not reverted.' );
		self::assertFalse( self::call_private( Settings::class, 'isSaveReverted', array( 'unknown', array( 'configured' => false, 'cause' => 'bad-key' ) ) ), 'No save marker means no reverted hint.' );
		self::assertFalse( self::call_private( Settings::class, 'isSaveReverted', array( 'yes', array( 'configured' => false, 'cause' => 'server-error' ) ) ), 'Server errors are not save reverts.' );
	}

	/**
	 * The dry-run preview needs no key and performs no external call.
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	public function test_dry_run_preview_is_keyless(): void {
		$this->boot();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '7.0' );

		$settings = new Settings();
		$preview  = $settings->dryRunPreview();

		self::assertTrue( $preview['supported'] );
		self::assertNotSame( '', $preview['message'] );
	}
}
