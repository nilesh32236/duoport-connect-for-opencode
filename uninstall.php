<?php
/**
 * Uninstall OpenCode Connector.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The settings option name. The plugin main file is not loaded during
// uninstall, so fall back to the shipped literal when the constant is absent.
$opencode_connector_option = defined( 'OpenCodeConnector\\OPTION_NAME' ) ? OpenCodeConnector\OPTION_NAME : 'opencode_connector_settings';
// Plugin-owned transients: availability results, stampede locks, the
// last-known-good flags, and the opt-in verification verdicts. The single
// source of truth is OpenCodeConnector\Metadata\Catalog::allTransientKeys()
// (dependency-free); literals below are a fail-open fallback when the
// autoloader is unavailable at uninstall time.
$opencode_connector_avail_keys = array(
	'opencode_connector_avail_go',
	'opencode_connector_avail_zen',
	'opencode_connector_avail_go_lock',
	'opencode_connector_avail_zen_lock',
	'opencode_connector_avail_go_last_good',
	'opencode_connector_avail_zen_last_good',
	'opencode_connector_verify_go',
	'opencode_connector_verify_zen',
	'opencode_connector_verify_go_lock',
	'opencode_connector_verify_zen_lock',
);
if ( defined( 'ABSPATH' ) ) {
	$opencode_connector_autoload = __DIR__ . '/src/autoload.php';
	if ( file_exists( $opencode_connector_autoload ) ) {
		require_once $opencode_connector_autoload;
	}
	if ( class_exists( 'OpenCodeConnector\\Metadata\\Catalog' ) && method_exists( 'OpenCodeConnector\\Metadata\\Catalog', 'allTransientKeys' ) ) {
		$opencode_connector_avail_keys = OpenCodeConnector\Metadata\Catalog::allTransientKeys();
	}
}

// Clean one site's rows: the settings option, every plugin transient, and the
// AI Client model caches. Safe to run on a site that never used the plugin:
// every call is a targeted delete.
$opencode_connector_clean_site = static function () use ( $opencode_connector_option, $opencode_connector_avail_keys ): void {
	delete_option( $opencode_connector_option );
	foreach ( $opencode_connector_avail_keys as $opencode_connector_avail_key ) {
		delete_transient( $opencode_connector_avail_key );
	}
	// AI Client model caches (ai_client_<VERSION>_<md5>_models) for this site.
	global $wpdb;
	if ( isset( $wpdb ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_ai_client_' ) . '%_models', $wpdb->esc_like( '_transient_timeout_ai_client_' ) . '%_models' ) );
	}
};

// Current site first: covers single-site installs and the current site of a
// network.
$opencode_connector_clean_site();

// Network-level rows.
delete_site_option( $opencode_connector_option );
foreach ( $opencode_connector_avail_keys as $opencode_connector_avail_key ) {
	delete_site_transient( $opencode_connector_avail_key );
}
global $wpdb;
if ( isset( $wpdb ) && is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( '_site_transient_ai_client_' ) . '%_models', $wpdb->esc_like( '_site_transient_timeout_ai_client_' ) . '%_models' ) );
}

// Multisite: the settings row is registered per blog (Settings::register()
// calls register_setting for the current site only) and the transients are
// per-site, so every other subsite keeps its own rows after a network
// uninstall. Activation scope cannot be read here — core clears
// active_sitewide_plugins before uninstall runs — so sweep the network with a
// bounded cap: a very large network keeps its oldest rows rather than timing
// out the uninstall request.
$opencode_connector_site_cap = 500;
if ( is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) ) {
	$opencode_connector_sites = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => $opencode_connector_site_cap,
			'update_site_meta_cache' => false,
		)
	);
	if ( is_array( $opencode_connector_sites ) ) {
		foreach ( $opencode_connector_sites as $opencode_connector_site_id ) {
			if ( ! is_scalar( $opencode_connector_site_id ) ) {
				continue;
			}
			switch_to_blog( (int) $opencode_connector_site_id );
			$opencode_connector_clean_site();
			restore_current_blog();
		}
	}
	unset( $opencode_connector_sites );
}
unset( $opencode_connector_clean_site, $opencode_connector_avail_keys, $opencode_connector_avail_key, $opencode_connector_autoload, $opencode_connector_option, $opencode_connector_site_cap, $opencode_connector_site_id );
