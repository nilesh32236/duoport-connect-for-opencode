<?php
/**
 * Credential-blind connection report for the Settings page.
 *
 * @package OpenCodeConnector
 * @since 0.1.10
 */

declare(strict_types=1);

namespace OpenCodeConnector\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Availability\ConnectionDiagnostics;
use OpenCodeConnector\Availability\TransientCache;
use OpenCodeConnector\Metadata\Catalog;

/**
 * Reads the classified availability verdict and describes it in plain language.
 *
 * Credential-blind by design: it reads only the plugin-owned availability
 * transient, which holds a classified state and a timestamp, never any
 * `connectors_ai_*` option value or response body.
 *
 * @package OpenCodeConnector
 * @since 0.1.10
 */
final class ConnectionReport {
	/**
	 * Transient key prefix for the last-checked timestamp.
	 *
	 * Kept outside Catalog::allTransientKeys() deliberately: this is
	 * presentation metadata, not an availability verdict a key rotation has to
	 * invalidate. The handler expires it on its own TTL.
	 *
	 * @since 0.1.10
	 */
	const LAST_CHECKED_PREFIX = 'opencode_connector_last_checked_';

	/**
	 * How long a last-checked timestamp remains displayable, in seconds.
	 *
	 * Literal rather than `30 * DAY_IN_SECONDS` so the value is available
	 * before WordPress defines its time constants.
	 *
	 * @since 0.1.10
	 */
	const LAST_CHECKED_TTL = 2592000;

	/**
	 * Map a classified state to a distinct, credential-blind sentence.
	 *
	 * The state name is appended so the line stays diagnosable even when the
	 * translated prose is unfamiliar. Unknown or unbucketed states resolve to
	 * could-not-be-checked through the same wording the fail-open policy uses;
	 * none of them is ever rendered as a bare "not connected".
	 *
	 * @since 0.1.10
	 *
	 * @param string $state Classified state name.
	 * @return string
	 */
	public static function describe( string $state ): string {
		switch ( $state ) {
			case 'verified':
				$label = self::translate( 'connected - verified (the API key was verified)' );
				break;
			case 'no_credits':
				$label = self::translate( 'connected - no credits (the API key is valid but the account has no credits)' );
				break;
			case 'rate_limited':
				$label = self::translate( 'connected - rate limited (rate-limited: the API key is valid but requests are rate limited)' );
				break;
			case 'free_tier_limit':
				$label = self::translate( 'connected - free tier limit reached (free-tier limit: the free-tier usage limit has been reached)' );
				break;
			case 'invalid_key':
				$label = self::translate( 'not connected - invalid API key (invalid key: the API key is invalid)' );
				break;
			case 'not_configured':
				$label = self::translate( 'not connected - not configured (no API key is configured)' );
				break;
			case 'network_error':
				$label = self::translate( 'could not be checked - network error stopped the check' );
				break;
			case 'server_error':
				$label = self::translate( 'could not be checked - server error' );
				break;
			case 'probe_model_unavailable':
				$label = self::translate( 'could not be checked - probe model unavailable' );
				break;
			case 'unknown':
				$label = self::translate( 'could not be checked - unknown response' );
				break;
			case 'uncheckable':
				$label = self::translate( 'could not be checked - the connection could not be verified' );
				break;
			default:
				$label = self::translate( 'could not be checked - unrecognised connection state' );
				break;
		}
		if ( '' === $state ) {
			return $label;
		}
		return $label . ' (' . $state . ')';
	}

	/**
	 * Alias kept for callers that name the value a reason rather than a sentence.
	 *
	 * @param string $state Classified state name.
	 * @return string
	 */
	public static function sentence( string $state ): string {
		return self::describe( $state );
	}

	/**
	 * Alias for the same mapping, matching the wording used by the issue.
	 *
	 * @param string $state Classified state name.
	 * @return string
	 */
	public static function reason( string $state ): string {
		return self::describe( $state );
	}

	/**
	 * Read the cached classified state for one catalog.
	 *
	 * Returns an empty string when no diagnosis is cached, so callers can fall
	 * back to the legacy boolean status instead of inventing a verdict.
	 *
	 * @param string $catalog Catalog slug.
	 * @return string
	 */
	public static function stateFor( string $catalog ): string {
		$diagnosis = self::diagnosisFor( $catalog );
		return isset( $diagnosis['state'] ) && is_string( $diagnosis['state'] ) ? $diagnosis['state'] : '';
	}

	/**
	 * Read the cached diagnosis for one catalog.
	 *
	 * @param string $catalog Catalog slug.
	 * @return array<string, mixed>
	 */
	public static function diagnosisFor( string $catalog ): array {
		if ( ! Catalog::isValid( $catalog ) ) {
			return array();
		}
		$cached = TransientCache::get( Catalog::AVAIL_PREFIX . $catalog );
		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * Read the timestamp of the last completed check for one catalog.
	 *
	 * Prefers the timestamp written with the cached diagnosis, then the
	 * dedicated last-checked transient. Zero means no check is known.
	 *
	 * @param string $catalog Catalog slug.
	 * @return int Unix timestamp, or zero when no check is known.
	 */
	public static function lastCheckedFor( string $catalog ): int {
		if ( ! Catalog::isValid( $catalog ) ) {
			return 0;
		}
		$diagnosis = self::diagnosisFor( $catalog );
		if ( isset( $diagnosis['checked_at'] ) && is_numeric( $diagnosis['checked_at'] ) ) {
			return max( 0, (int) $diagnosis['checked_at'] );
		}
		$stored = TransientCache::get( self::LAST_CHECKED_PREFIX . $catalog );
		if ( is_array( $stored ) && isset( $stored['ts'] ) && is_numeric( $stored['ts'] ) ) {
			return max( 0, (int) $stored['ts'] );
		}
		return is_numeric( $stored ) ? max( 0, (int) $stored ) : 0;
	}

	/**
	 * Format a last-checked timestamp without reading any WordPress option.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Empty when no timestamp is known.
	 */
	public static function formatLastChecked( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC';
	}

	/**
	 * Gather the display record for one catalog.
	 *
	 * `checked` distinguishes a real cached diagnosis from the fail-open
	 * fallback the render path uses when nothing has been probed yet.
	 *
	 * @param string $catalog Catalog slug.
	 * @return array{state: string, checked: bool, checked_at: int, sentence: string}
	 */
	public static function forCatalog( string $catalog ): array {
		$state = self::stateFor( $catalog );
		return array(
			'state'      => $state,
			'checked'    => '' !== $state,
			'checked_at' => self::lastCheckedFor( $catalog ),
			'sentence'   => '' === $state ? '' : self::describe( $state ),
		);
	}

	/**
	 * Whether a state is a definitive negative for the credential.
	 *
	 * Delegates to the one owner of the state lists rather than restating them
	 * here; call sites that need the same question must do the same.
	 *
	 * @param string $state Classified state name.
	 * @return bool
	 */
	public static function isDefinitiveNegative( string $state ): bool {
		return ConnectionDiagnostics::isDefinitiveNegativeState( $state );
	}

	/**
	 * Render a translated sentence, degrading to English when i18n is absent.
	 *
	 * @param string $literal English sentence from the fixed catalogue above.
	 * @return string
	 */
	private static function translate( string $literal ): string {
		if ( ! function_exists( '__' ) ) {
			return $literal;
		}
		try {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- $literal is one of the fixed catalogue entries above.
			return __( $literal, 'duoport-connect-for-opencode' );
		} catch ( \Throwable $translation_exception ) {
			unset( $translation_exception );
			// Degrade to the fixed English catalogue when i18n is unavailable.
			return $literal;
		}
	}
}
