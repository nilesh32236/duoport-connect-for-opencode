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
}
