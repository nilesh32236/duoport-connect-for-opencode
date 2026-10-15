<?php
/**
 * Shared Go request headers (session + User-Agent).
 *
 * Single source of truth for the Go header pair so the text and image
 * request paths cannot drift apart on the next header change.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */

declare(strict_types=1);

namespace OpenCodeConnector\Http;

use OpenCodeConnector\Metadata\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared Go request headers helper.
 *
 * Applies the stable `x-opencode-session` header plus the shared client
 * User-Agent. Credential-blind and option-blind: delegates to
 * SessionHeader and ClientUserAgent, which never read options, users, or
 * globals. Never throws; header failures fall back to a headerless send
 * (fail-open, behavior unchanged).
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */
final class GoRequestHeaders {
	/**
	 * Apply the Go header pair to a headers array.
	 *
	 * Adds the session header (when chat or image context exists) and the
	 * client User-Agent (when not explicitly provided, compared
	 * case-insensitively). Returns the headers unchanged when derivation
	 * fails. Never throws.
	 *
	 * @since 0.1.5
	 *
	 * @param array $headers Request headers.
	 * @param mixed $data    Request data.
	 * @return array Headers with the Go pair added when applicable.
	 */
	public static function for_go( array $headers, $data ): array {
		try {
			$with_session = SessionHeader::inject_into_headers( $headers, $data );
		} catch ( \Throwable ) {
			// Fail-open: leave headers unchanged when session injection fails.
			$with_session = $headers;
		}
		try {
			return ClientUserAgent::inject_into_headers( $with_session );
		} catch ( \Throwable ) {
			// Fail-open: leave headers unchanged when User-Agent injection fails.
			return $with_session;
		}
	}

	/**
	 * Apply the catalog-appropriate headers to a headers array.
	 *
	 * Single home for the catalog-branch header choice the availability
	 * probe and the shared request trait each derived inline (a
	 * class_exists/method_exists guard around for_go() plus the
	 * session-only fallback): the next header change lands here once
	 * instead of in both places. Never throws; a missing helper degrades
	 * to fewer headers, never a fatal.
	 *
	 * @since 0.1.9
	 *
	 * @param string $catalog Catalog slug.
	 * @param array  $headers Request headers.
	 * @param mixed  $data    Request data the session is derived from.
	 * @return array Headers with the catalog pair added when applicable.
	 */
	public static function forCatalog( string $catalog, array $headers, $data ): array {
		$base_headers = $headers;
		if ( Catalog::GO === $catalog ) {
			try {
				if ( method_exists( self::class, 'for_go' ) ) {
					return self::for_go( $base_headers, $data );
				}
			} catch ( \Throwable $go_header_exception ) {
				unset( $go_header_exception );
			}
		}
		try {
			if ( class_exists( SessionHeader::class ) && method_exists( SessionHeader::class, 'inject_into_headers' ) ) {
				return SessionHeader::inject_into_headers( $base_headers, $data );
			}
		} catch ( \Throwable $session_header_exception ) {
			unset( $session_header_exception );
		}
		return $base_headers;
	}
}
