<?php
/**
 * Plugin Name:       DuoPort Connector for OpenCode
 * Description:       Connect OpenCode Go and Zen (including free models) to WordPress 7.0 AI.
 * Requires at least: 7.0
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

// Guard: WP < 7.0 or SDK missing — admin notice, bail.
add_action(
	'admin_notices',
	static function (): void {
		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '0.0.0';
		$wp_ok      = version_compare( $wp_version, '7.0', '>=' );
		$sdk_ok     = class_exists( \WordPress\AiClient\AiClient::class );
		if ( $wp_ok && $sdk_ok ) {
			return;
		}
		if ( ! function_exists( '__' ) || ! function_exists( 'esc_html' ) ) {
			return;
		}
		$msg = ! $wp_ok
			? __( 'DuoPort Connector for OpenCode requires WordPress 7.0+.', 'duoport-connect-for-opencode' )
			: __( 'DuoPort Connector for OpenCode requires the WordPress AI Client (WordPress 7.0+ AI).', 'duoport-connect-for-opencode' );
		echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
	}
);

// Unkeyed installs: not-connected prompt, never a fatal.
//
// Credential-blind by design: status comes only from the boolean
// `isProviderConfigured()` probe (the same pattern Settings::render()
// uses). This callback never calls get_option()/update_option() for any
// `connectors_ai_*` value.
add_action(
	'admin_notices',
	static function (): void {
		if ( function_exists( 'is_admin' ) && ! is_admin() ) {
			return;
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			return;
		}
		if ( ! method_exists( \WordPress\AiClient\AiClient::class, 'defaultRegistry' ) ) {
			return;
		}
		if ( ! function_exists( 'admin_url' ) || ! function_exists( 'esc_url' ) || ! function_exists( '__' ) || ! function_exists( 'esc_html' ) ) {
			return;
		}
		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '0.0.0';
		if ( version_compare( $wp_version, '7.0', '<' ) ) {
			return;
		}
		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			if ( ! is_object( $registry ) || ! method_exists( $registry, 'isProviderConfigured' ) ) {
				return;
			}
			try {
				$go_configured = (bool) $registry->isProviderConfigured( 'opencode-go' );
			} catch ( \Throwable $e ) {
				$go_configured = false;
			}
			try {
				$zen_configured = (bool) $registry->isProviderConfigured( 'opencode-zen' );
			} catch ( \Throwable $e ) {
				$zen_configured = false;
			}
			if ( $go_configured || $zen_configured ) {
				return;
			}
			$url = admin_url( 'options-connectors.php' );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'DuoPort Connector for OpenCode is not connected.', 'duoport-connect-for-opencode' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Add your API key under Settings → Connectors.', 'duoport-connect-for-opencode' ) . '</a></p></div>';
		} catch ( \Throwable $e ) {
			return;
		}
	}
);

add_action(
	'init',
	static function (): void {
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
				try {
					// Skip (don't fatal) when the provider class cannot autoload,
					// e.g. its AbstractApiProvider parent is missing from the SDK.
					if ( ! class_exists( $cls ) ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated.
							error_log( sprintf( '[duoport-connect-for-opencode] Provider class %s unavailable; skipping registration.', $cls ) );
						}
						continue;
					}
					if ( ! $r->hasProvider( $cls ) ) {
						$r->registerProvider( $cls );
					}
				} catch ( \Throwable $e ) {
					// Log but do not fatal: provider registration failed (e.g. corrupted core SDK).
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated.
						error_log( sprintf( '[duoport-connect-for-opencode] Failed to register %s: %s: %s', $cls, get_class( $e ), $e->getMessage() ) );
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
if ( function_exists( 'plugin_basename' ) ) {
	add_filter(
		'plugin_action_links_' . plugin_basename( __FILE__ ),
		static function ( array $links ): array {
			if ( ! function_exists( 'admin_url' ) || ! function_exists( 'esc_url' ) || ! function_exists( 'esc_html__' ) ) {
				return $links;
			}
			$url     = admin_url( 'options-connectors.php' );
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Connect', 'duoport-connect-for-opencode' ) . '</a>';
			return $links;
		}
	);
}
