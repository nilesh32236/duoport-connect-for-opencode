<?php
/**
 * Plugin Name:       DuoPort Connector for OpenCode
 * Description:       Connect OpenCode Go and Zen (including free models) to WordPress 7.0 AI.
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Version:           0.1.8
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

const VERSION     = '0.1.8';
const OPTION_NAME = 'opencode_connector_settings';

require_once __DIR__ . '/src/autoload.php';

// Guard: report compatibility without reading connector settings.
add_action(
	'admin_notices',
	static function (): void {
		$diagnostics = new Compatibility\CompatibilityDiagnostics();
		$status      = $diagnostics->inspect();
		if ( $status['ok'] ) {
			return;
		}
		if ( ! function_exists( '__' ) || ! function_exists( 'esc_html' ) ) {
			return;
		}
		$msg = ! $status['wordpress_supported']
			? __( 'DuoPort Connector for OpenCode requires WordPress 7.0+.', 'duoport-connect-for-opencode' )
			: __( 'DuoPort Connector for OpenCode requires a compatible WordPress AI Client registry and provider surface.', 'duoport-connect-for-opencode' );
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
			// Provider IDs resolve through Catalog so the entry file cannot
			// drift from the providers' own IDs (see PROVIDER_ID constants).
			try {
				$go_configured = (bool) $registry->isProviderConfigured( Metadata\Catalog::providerId( Metadata\Catalog::GO ) );
			} catch ( \Throwable $e ) {
				$go_configured = false;
			}
			try {
				$zen_configured = (bool) $registry->isProviderConfigured( Metadata\Catalog::providerId( Metadata\Catalog::ZEN ) );
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
// Busts ONLY the transients for the connector whose option fired the hook.
//
// Scoped per catalog on purpose. The delete list used to be
// Catalog::allTransientKeys(), which is every key for both catalogs — correct
// for uninstall.php, wrong here. Both connectors' last-known-good flags are
// independent 30-day fail-open fallbacks, so deleting or rotating the Go key
// also destroyed Zen's. Since `unknown` never re-arms the flag, a Zen gateway
// currently answering 400/402/403/404 lost its fallback permanently and Zen
// reported not-configured until a *keyed* response arrived — this PR's exact
// defect, reached through a sibling's key rotation.
//
// WP core does NOT pass the option name in the same position on all three of
// these hooks, and reading the wrong argument is completely silent. Verified
// against WordPress 7.1.2, wp-includes/option.php:
//
// option.php:1019 do_action( "update_option_{$option}", $old_value, $value, $option );
// option name THIRD; argument one is the PREVIOUS CREDENTIAL.
// option.php:1176 do_action( "add_option_{$option}", $option, $value );
// option name FIRST.
// option.php:1264 do_action( "delete_option_{$option}", $option );
// option name FIRST.
//
// This closure used to read argument one for all three, which is correct twice
// out of three. On `update_option_` it was therefore handed an API key string,
// failed the `_go_`/`_zen_` guard below, and returned before deleting anything.
// No error, no notice: every key ROTATION silently deleted nothing, so the
// last-known-good flag describing the OLD key survived its full 30-day window
// and `isConfigured()` kept reporting the old credential's verdict about the
// new one. add and delete were unaffected, which is why the suite stayed green.
//
// So each hook gets its own closure that pulls the option name from the
// position core actually uses for THAT hook, registered with an
// `$accepted_args` wide enough for it to arrive. `update_option_` therefore
// takes 3. Tests/Unit/SlimBustHooksTest.php fires all three in core's real
// order, truncated to the registered `$accepted_args`, and gives each hook its
// own recorder.
$opencode_connector_bust_catalog = static function ( string $opencode_connector_catalog ): void {
	// Single source of truth: Metadata\Catalog::allKeys() (a dependency-free
	// class safe to load without SDK traits). Fallback literals below run only
	// when the class cannot autoload.
	if ( class_exists( Metadata\Catalog::class ) && method_exists( Metadata\Catalog::class, 'allKeys' ) ) {
		foreach ( Metadata\Catalog::allKeys( $opencode_connector_catalog ) as $opencode_connector_key ) {
			delete_transient( $opencode_connector_key );
			if ( function_exists( 'delete_site_transient' ) ) {
				delete_site_transient( $opencode_connector_key );
			}
		}
		unset( $opencode_connector_key );
		return;
	}
	$opencode_connector_base = 'opencode_connector_avail_' . $opencode_connector_catalog;
	foreach (
		array(
			$opencode_connector_base,
			$opencode_connector_base . '_lock',
			$opencode_connector_base . '_last_good',
			'opencode_connector_verify_' . $opencode_connector_catalog,
			'opencode_connector_verify_' . $opencode_connector_catalog . '_lock',
		) as $opencode_connector_key
	) {
		delete_transient( $opencode_connector_key );
		if ( function_exists( 'delete_site_transient' ) ) {
			delete_site_transient( $opencode_connector_key );
		}
	}
	unset( $opencode_connector_key, $opencode_connector_base );
};

// Resolve the catalog from the option name core passed, or null when this is
// not a connector key the closures below are registered for. Deleting both
// catalogs there would reintroduce the over-bust the per-catalog scoping
// removes.
$opencode_connector_catalog_of = static function ( string $opencode_connector_option ): ?string {
	if ( str_contains( $opencode_connector_option, '_zen_' ) ) {
		return 'zen';
	}
	if ( str_contains( $opencode_connector_option, '_go_' ) ) {
		return 'go';
	}
	return null;
};

foreach ( array( 'connectors_ai_opencode_go_api_key', 'connectors_ai_opencode_zen_api_key' ) as $opencode_connector_setting ) {
	// add_option_{$option} -- ( $option, $value ). Option name first.
	add_action(
		'add_option_' . $opencode_connector_setting,
		static function ( $opencode_connector_option = '', $opencode_connector_value = null ) use ( $opencode_connector_catalog_of, $opencode_connector_bust_catalog ): void {
			// The value is a credential. Accept it only so the signature matches
			// core's arity; it is never read (AGENTS.md hard rule 2).
			unset( $opencode_connector_value );
			$opencode_connector_catalog = $opencode_connector_catalog_of( (string) $opencode_connector_option );
			if ( null !== $opencode_connector_catalog ) {
				$opencode_connector_bust_catalog( $opencode_connector_catalog );
			}
		},
		10,
		1
	);

	// update_option_{$option} -- ( $old_value, $value, $option ). Option name
	// THIRD. The first two arguments are credentials and are never read; the
	// third is only reachable because `$accepted_args` is 3.
	add_action(
		'update_option_' . $opencode_connector_setting,
		static function ( $opencode_connector_old_value = null, $opencode_connector_value = null, $opencode_connector_option = '' ) use ( $opencode_connector_catalog_of, $opencode_connector_bust_catalog ): void {
			unset( $opencode_connector_old_value, $opencode_connector_value );
			$opencode_connector_catalog = $opencode_connector_catalog_of( (string) $opencode_connector_option );
			if ( null !== $opencode_connector_catalog ) {
				$opencode_connector_bust_catalog( $opencode_connector_catalog );
			}
		},
		10,
		3
	);

	// delete_option_{$option} -- ( $option ). A key can also leave by deletion
	// rather than update: uninstalling the Connectors feature, a migration,
	// WP-CLI, or another plugin calling delete_option(). Neither of the hooks
	// above fires then, so the last-known-good flag would outlive the key it
	// describes by its full 30-day TTL. That flag is what the
	// unrecognised-response fallback reads, so a site that deleted its key
	// would keep reporting connected for a month off a verdict about a
	// credential that no longer exists. WP's REST settings controller deletes
	// the option outright when the field is cleared
	// (class-wp-rest-settings-controller.php:201), so this is the hook the
	// "clear my key in the UI" path actually takes.
	add_action(
		'delete_option_' . $opencode_connector_setting,
		static function ( $opencode_connector_option = '' ) use ( $opencode_connector_catalog_of, $opencode_connector_bust_catalog ): void {
			$opencode_connector_catalog = $opencode_connector_catalog_of( (string) $opencode_connector_option );
			if ( null !== $opencode_connector_catalog ) {
				$opencode_connector_bust_catalog( $opencode_connector_catalog );
			}
		},
		10,
		1
	);
}
unset( $opencode_connector_bust_catalog, $opencode_connector_catalog_of, $opencode_connector_setting );

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
