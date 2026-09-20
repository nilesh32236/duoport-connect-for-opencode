<?php
/**
 * Plugin Name:       DuoPort Connector for OpenCode
 * Description:       Connect OpenCode Go and Zen (including free models) to WordPress AI.
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Version:           0.1.4
 * Author:            Nilesh Kanzariya
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       duoport-connect-for-opencode
 * Domain Path:       /languages
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '0.1.4';
const OPTION_NAME = 'opencode_connector_settings';

require_once __DIR__ . '/src/autoload.php';

// Guard: WP < 6.9 or AI client missing — admin notice, bail.
//
// Dual-stack: WordPress 7.0 ships a core-bundled AI client (preferred); on
// WP 6.9 the Composer-bundled SDK is loaded as a fallback. The loader below
// never loads the bundled SDK when core classes are present and never
// requires it unconditionally — a missing file simply means "not connected".
add_action(
	'admin_notices',
	static function (): void {
		$wp_ok  = Compat\AiClientLoader::is_supported_wp_version();
		$sdk_ok = Compat\AiClientLoader::ensure_ai_client_loaded();
		if ( $wp_ok && $sdk_ok ) {
			return;
		}
		$msg = ! $wp_ok
			? __( 'DuoPort Connector for OpenCode requires WordPress 6.9+. On WP 6.9 the bundled AI Client SDK is used; on WP 7.0+ the core-bundled AI client is used.', 'duoport-connect-for-opencode' )
			: __( 'DuoPort Connector for OpenCode requires the WordPress AI Client (core-bundled on WordPress 7.0+, bundled SDK on WP 6.9).', 'duoport-connect-for-opencode' );
		echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
	}
);

add_action(
	'init',
	static function (): void {
		if ( ! Compat\AiClientLoader::is_supported_wp_version() ) {
			return;
		}
		if ( ! Compat\AiClientLoader::ensure_ai_client_loaded() ) {
			return;
		}
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			return;
		}
		if ( ! method_exists( \WordPress\AiClient\AiClient::class, 'defaultRegistry' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated.
				error_log( '[duoport-connect-for-opencode] AiClient::defaultRegistry() unavailable; skipping provider registration.' );
			}
			return;
		}
		try {
			$r = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! is_object( $r ) || ! method_exists( $r, 'hasProvider' ) || ! method_exists( $r, 'registerProvider' ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated.
					error_log( '[duoport-connect-for-opencode] AiClient registry has unexpected shape; skipping provider registration.' );
				}
				return;
			}
			foreach ( array( Providers\OpenCodeGoProvider::class, Providers\OpenCodeZenProvider::class ) as $cls ) {
				if ( ! $r->hasProvider( $cls ) ) {
					try {
						$r->registerProvider( $cls );
					} catch ( \Throwable $e ) {
						// Log but do not fatal: provider registration failed (e.g. corrupted core SDK).
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated.
							error_log( sprintf( '[duoport-connect-for-opencode] Failed to register %s: %s: %s', $cls, get_class( $e ), $e->getMessage() ) );
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated.
				error_log( '[duoport-connect-for-opencode] AiClient registry error: ' . get_class( $e ) . ': ' . $e->getMessage() );
			}
		}
	},
	5
);

// Bust availability probes when either connector key changes.
//
// Each catalog keeps its own API key setting (core default
// `connectors_ai_opencode_{go,zen}_api_key`). The callbacks below MUST stay
// credential-blind: they only clear transients and never read or write any
// `connectors_ai_*` option.
//
// NOTE: an earlier release aliased both connectors to one shared setting via
// `wp_connectors_init`. That override was removed in 0.1.2 because core's
// `/wp/v2/settings` dispatch validates and masks EACH connector's setting
// independently: the second connector ended up validating the first one's
// already-masked placeholder, the save was reverted to an empty string, and
// valid keys were rejected with "It was not possible to connect to the
// provider using this key." Separate settings keep every validation against
// the real submitted key.
$opencode_connector_bust = static function (): void {
	delete_transient( 'opencode_connector_avail_go' );
	delete_transient( 'opencode_connector_avail_zen' );
};
foreach ( array( 'connectors_ai_opencode_go_api_key', 'connectors_ai_opencode_zen_api_key' ) as $opencode_connector_setting ) {
	add_action( 'update_option_' . $opencode_connector_setting, $opencode_connector_bust );
	add_action( 'add_option_' . $opencode_connector_setting, $opencode_connector_bust );
}
unset( $opencode_connector_bust, $opencode_connector_setting );

// Settings bootstrap.
add_action(
	'init',
	static function (): void {
		if ( ! class_exists( Settings\Settings::class ) ) {
			return;
		}
		try {
			( new Settings\Settings() )->register();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated.
				error_log( '[duoport-connect-for-opencode] Settings bootstrap failed: ' . get_class( $e ) . ': ' . $e->getMessage() );
			}
		}
	},
	20
);

// Plugin action link to Connectors screen.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		$url     = admin_url( 'options-connectors.php' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Connect', 'duoport-connect-for-opencode' ) . '</a>';
		return $links;
	}
);
