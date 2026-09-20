<?php
/**
 * Dual-stack loader for the WordPress AI client.
 *
 * Prefers the WordPress 7.0 core-bundled AI client when present and falls
 * back to the Composer-bundled SDK (WP < 7.0) only when core classes are
 * absent. Never loads the bundled SDK unconditionally.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */

declare(strict_types=1);

namespace OpenCodeConnector\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\AiClient;

/**
 * Ensure the AI client classes are available.
 *
 * Core wins: when the core-bundled client is already registered (class or
 * helper function present) nothing else is loaded. The Composer-bundled SDK
 * autoloader is required only when core classes are absent, and only when
 * its autoload file exists (the release ZIP ships no vendor directory).
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */
final class AiClientLoader {
	/**
	 * Minimum WordPress version with a usable fallback stack.
	 *
	 * @since 0.1.5
	 *
	 * @var string
	 */
	const MIN_WP_VERSION = '6.9';

	/**
	 * Ensure the AI client is loadable.
	 *
	 * Never fatal: missing files, missing functions, and load errors all
	 * degrade to false so callers can fail open (not-connected).
	 *
	 * @since 0.1.5
	 *
	 * @return bool True when AiClient resolves (core or bundled), false otherwise.
	 */
	public static function ensure_ai_client_loaded(): bool {
		// Core-bundled client wins; no bundled autoload when core is present.
		if ( class_exists( AiClient::class ) ) {
			return true;
		}
		// Core helper-function probe: some core builds expose the client via
		// functions before classes autoload. Presence alone never fatals; the
		// class check below still decides.
		if ( self::is_core_helper_present() ) {
			if ( class_exists( AiClient::class ) ) {
				return true;
			}
		}
		// Bundled fallback for WP < 7.0: load only when core is absent and
		// only when the bundled autoload file actually exists on disk.
		foreach ( self::bundled_autoload_candidates() as $autoload_file ) {
			if ( ! is_string( $autoload_file ) || '' === $autoload_file ) {
				continue;
			}
			if ( ! file_exists( $autoload_file ) ) {
				continue;
			}
			try {
				require_once $autoload_file;
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( class_exists( AiClient::class ) ) {
				return true;
			}
		}
		return class_exists( AiClient::class );
	}

	/**
	 * Whether the running WordPress meets the minimum version.
	 *
	 * Guards every WP API touch: function_exists() around get_bloginfo(),
	 * defined() before reading version constants, version_compare() for the
	 * floor check, with a legacy $wp_version global fallback.
	 *
	 * @since 0.1.5
	 *
	 * @return bool True on WP >= MIN_WP_VERSION, false otherwise.
	 */
	public static function is_supported_wp_version(): bool {
		$wp_version = null;
		if ( function_exists( 'get_bloginfo' ) ) {
			try {
				$wp_version = get_bloginfo( 'version' );
			} catch ( \Throwable $e ) {
				$wp_version = null;
			}
		}
		if ( ! is_string( $wp_version ) || '' === $wp_version ) {
			if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
				$wp_version = $GLOBALS['wp_version'];
			} elseif ( defined( 'WP_VERSION' ) ) {
				$wp_version = (string) constant( 'WP_VERSION' );
			} else {
				return false;
			}
		}
		return version_compare( $wp_version, self::MIN_WP_VERSION, '>=' );
	}

	/**
	 * Whether the connected-AI filter stack is present.
	 *
	 * Optional signal only: guarded by function_exists() with a false
	 * fallback so older WP without the filter API still works.
	 *
	 * @since 0.1.5
	 *
	 * @return bool True when the AI provider filter is registered.
	 */
	public static function has_core_ai_filter(): bool {
		if ( ! function_exists( 'has_filter' ) ) {
			return false;
		}
		try {
			return false !== has_filter( 'ai_client_providers' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Probe for core AI-client helper functions.
	 *
	 * The class check in ensure_ai_client_loaded() is authoritative; this
	 * function_exists() probe only records core intent so future core builds
	 * that lazily register classes via helpers are still preferred over the
	 * bundled SDK. Unknown helpers are never called.
	 *
	 * @since 0.1.5
	 *
	 * @return bool True when any known core helper exists.
	 */
	private static function is_core_helper_present(): bool {
		foreach ( array( 'wp_ai_client', 'wp_get_ai_client', 'wordpress_ai_client' ) as $helper ) {
			if ( function_exists( $helper ) ) {
				return true;
			}
		}
		return self::has_core_ai_filter();
	}

	/**
	 * Bundled SDK autoload candidates (load only when core is absent).
	 *
	 * Relative to this file so the plugin stays relocatable; the release ZIP
	 * ships no vendor directory, so these usually miss and the loader simply
	 * returns false (fail open).
	 *
	 * @since 0.1.5
	 *
	 * @return string[]
	 */
	private static function bundled_autoload_candidates(): array {
		$root = dirname( __DIR__, 2 );
		return array(
			$root . '/vendor/wordpress/ai-client/autoload.php',
			$root . '/vendor/wordpress/ai-client/vendor/autoload.php',
		);
	}
}
