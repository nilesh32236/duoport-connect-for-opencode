<?php
/**
 * Shared client User-Agent for Go requests.
 *
 * Identifies this plugin on the Go text and image request paths so both
 * surfaces share one fingerprint for gateway observability. Zen requests
 * never carry it.
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
 * Client User-Agent helper.
 *
 * Credential-blind and option-blind: the value is derived from the plugin
 * `VERSION` constant (with a literal fallback) and never reads options,
 * users, or globals. Never throws.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */
final class ClientUserAgent {
	/**
	 * Header name.
	 *
	 * @since 0.1.5
	 */
	const HEADER_NAME = 'User-Agent';

	/**
	 * Client User-Agent prefix identifying this plugin.
	 *
	 * @since 0.1.5
	 */
	const PREFIX = 'duoport-connect-for-opencode/';

	/**
	 * Fallback plugin version when the VERSION constant is unavailable.
	 *
	 * Mirrors the plugin header; used only when `OpenCodeConnector\VERSION`
	 * is not defined (e.g. partial bootstrap). Never read from options.
	 *
	 * Release checklist: bump this together with the plugin header `Version:`,
	 * the `OpenCodeConnector\VERSION` const, the readme.txt Stable tag, and
	 * the readme.txt Changelog entry, or the User-Agent reports a stale
	 * version on partial bootstraps.
	 *
	 * @since 0.1.5
	 */
	const FALLBACK_VERSION = '0.1.6';

	/**
	 * Build the client User-Agent value.
	 *
	 * Tracks the plugin VERSION constant with a literal fallback so the
	 * value stays correct across version bumps. Never throws; never reads
	 * options.
	 *
	 * @since 0.1.5
	 *
	 * @return string Client User-Agent value.
	 */
	public static function value(): string {
		try {
			$version = self::FALLBACK_VERSION;
			if ( defined( 'OpenCodeConnector\\VERSION' ) ) {
				$defined = constant( 'OpenCodeConnector\\VERSION' );
				if ( is_string( $defined ) && '' !== $defined ) {
					$version = $defined;
				}
			}
			return self::PREFIX . $version;
		} catch ( \Throwable ) {
			return self::PREFIX . self::FALLBACK_VERSION;
		}
	}

	/**
	 * Inject the client User-Agent into a headers array.
	 *
	 * Adds the header only when no value was explicitly provided (compared
	 * case-insensitively). Never throws; never reads options.
	 *
	 * @since 0.1.5
	 *
	 * @param array $headers Request headers.
	 * @return array Headers with the client User-Agent added when applicable.
	 */
	public static function inject_into_headers( array $headers ): array {
		try {
			foreach ( $headers as $name => $existing ) {
				if ( is_string( $name ) && 0 === strcasecmp( $name, self::HEADER_NAME ) ) {
					return $headers;
				}
			}
			$headers[ self::HEADER_NAME ] = self::value();
			return $headers;
		} catch ( \Throwable ) {
			return $headers;
		}
	}
}
