<?php
/**
 * Transient-only last-known-good flag store.
 *
 * Flag half of the OpenCodeProviderAvailability split: read/freshness/
 * write/apply for the credential-blind fallback live here so probe
 * orchestration never touches flag semantics inline.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

use OpenCodeConnector\Metadata\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the transient-only last-known-good availability flag.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */
final class LastGoodStore {
	/**
	 * Maximum age of the last-known-good fallback, in seconds (48h).
	 *
	 * @since 0.1.8
	 *
	 * @var int
	 */
	public const MAX_AGE = 172800;

	/**
	 * Catalog slug this store is bound to.
	 *
	 * @var string
	 */
	private string $catalog;

	/**
	 * Per-instance memo of the flag.
	 *
	 * @var bool|null
	 */
	private ?bool $memo = null;

	/**
	 * Build a store for one catalog.
	 *
	 * @since 0.1.8
	 *
	 * @param string $catalog Catalog slug.
	 */
	public function __construct( string $catalog ) {
		$this->catalog = $catalog;
	}

	/**
	 * Transient key for the flag.
	 *
	 * @since 0.1.8
	 *
	 * @return string
	 */
	public function key(): string {
		return Catalog::AVAIL_PREFIX . $this->catalog . Catalog::LAST_GOOD_SUFFIX;
	}

	/**
	 * Read the flag, memoised per instance.
	 *
	 * @since 0.1.8
	 *
	 * @return bool
	 */
	public function read(): bool {
		if ( null !== $this->memo ) {
			return $this->memo;
		}
		$this->memo = self::isFresh( TransientCache::get( $this->key() ) );
		return $this->memo;
	}

	/**
	 * Whether a stored value still arms the fallback.
	 *
	 * @since 0.1.8
	 *
	 * @param mixed $value Raw transient value.
	 * @return bool True while the fallback may carry a could-not-be-checked verdict.
	 */
	public static function isFresh( mixed $value ): bool {
		if ( is_array( $value ) ) {
			if ( empty( $value['v'] ) ) {
				return false;
			}
			if ( isset( $value['ts'] ) && is_numeric( $value['ts'] ) ) {
				return ( time() - (int) $value['ts'] ) <= self::MAX_AGE;
			}
			return true;
		}
		return ! empty( $value );
	}

	/**
	 * Record or clear the flag.
	 *
	 * @since 0.1.8
	 *
	 * @param bool $good Whether a keyed probe just succeeded.
	 * @return void
	 */
	public function write( bool $good ): void {
		if ( ! $good ) {
			$this->memo = false;
			TransientCache::delete( $this->key() );
			return;
		}
		$this->memo = true;
		$day        = defined( 'DAY_IN_SECONDS' ) ? (int) DAY_IN_SECONDS : 86400;
		TransientCache::set(
			$this->key(),
			array(
				'v'  => '1',
				'ts' => time(),
			),
			30 * $day
		);
	}

	/**
	 * Apply a classified verdict to the flag (arm, clear, or leave alone).
	 *
	 * Only definitive negatives may clear; everything unbucketed fails open
	 * on whatever the flag already says.
	 *
	 * @since 0.1.8
	 *
	 * @param string $state Classified state name.
	 * @return void
	 */
	public function apply( string $state ): void {
		if ( ConnectionDiagnostics::isConfiguredState( $state ) ) {
			$this->write( true );
			return;
		}
		if ( ConnectionDiagnostics::isDefinitiveNegativeState( $state ) ) {
			$this->write( false );
		}
	}
}
