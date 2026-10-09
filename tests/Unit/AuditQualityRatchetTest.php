<?php
/**
 * Ratchet tests for the issue #117 code-quality residuals.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit;

/**
 * Guards the structural invariants landed for issue #117.
 */
final class AuditQualityRatchetTest extends MonkeyTestCase {

	/**
	 * The implemented endpoint family has exactly one owner.
	 *
	 * @return void
	 */
	public function test_implemented_family_has_single_owner(): void {
		$root = dirname( __DIR__, 2 );

		$route = (string) file_get_contents( $root . '/src/Transport/EndpointRoute.php' );
		self::assertStringContainsString( "IMPLEMENTED_FAMILY = 'chat'", $route, 'EndpointRoute must own the implemented family value.' );
		self::assertStringContainsString( 'function implementedFamilies', $route, 'EndpointRoute must expose the implemented families.' );

		foreach ( array( 'ModelRegistry.php', 'CapabilityAwareFallback.php' ) as $name ) {
			$source = (string) file_get_contents( $root . '/src/Metadata/' . $name );
			self::assertStringContainsString( 'EndpointRoute::IMPLEMENTED_FAMILY', $source, $name . ' must derive the family from EndpointRoute.' );
			self::assertStringNotContainsString( "'chat'", $source, $name . ' must not carry its own copy of the family literal.' );
		}
	}

	/**
	 * Radar change rows carry no underscore-prefixed scaffolding keys.
	 *
	 * @return void
	 */
	public function test_radar_rows_carry_no_scaffolding_keys(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Metadata/ModelRadar.php' );

		self::assertStringNotContainsString( 'SCAFFOLDING_KEYS', $source, 'Scaffolding keys must not ride on the published row.' );
		foreach ( array( "'_is_retired'", "'_free_name'", "'_free_explicit'", "'_unsupported'", "'_status'", "'_states'" ) as $key ) {
			self::assertStringNotContainsString( $key, $source, 'Scaffolding key ' . $key . ' must not exist on the row.' );
		}
		self::assertStringContainsString( "'counters'", $source, 'Fold inputs must travel beside the row, not on it.' );
	}

	/**
	 * Both availability probes share the guarded transient helpers.
	 *
	 * @return void
	 */
	public function test_verify_probe_shares_transient_helpers(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php' );

		// Exactly one raw call each, all inside the get/set/deleteCached helpers
		// (matched with the argument list so the function_exists() guards do not count).
		self::assertSame( 1, substr_count( $source, 'get_transient( $key )' ), 'Raw get_transient() must live only in getCached().' );
		self::assertSame( 1, substr_count( $source, 'set_transient( $key' ), 'Raw set_transient() must live only in setCached().' );
		self::assertSame( 1, substr_count( $source, 'delete_transient( $key )' ), 'Raw delete_transient() must live only in deleteCached().' );
		self::assertStringContainsString( '$this->getCached( $tkey )', $source, 'The verify cache read must go through getCached().' );
		self::assertStringContainsString( '$this->setCached( $tkey, $verdict', $source, 'The verify cache write must go through setCached().' );
	}
}
