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
// Credential-blind by design: every branch below only DELETES the settings
// row, the plugin transients, and the AI Client model caches. No option
// value (in particular no connectors_ai_* secret) is ever read.
if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
	// Network uninstall: the settings row and the transients are per-site,
	// so every subsite is swept. The loop is capped so a very large network
	// cannot time the uninstall request out; remaining sites expire on
	// their own transient TTLs.
	$opencode_connector_site_ids = array();
	try {
		$opencode_connector_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 500,
			)
		);
	} catch ( \Throwable $opencode_connector_sites_exception ) {
		unset( $opencode_connector_sites_exception );
		$opencode_connector_site_ids = array();
	}
	if ( is_array( $opencode_connector_site_ids ) ) {
		foreach ( $opencode_connector_site_ids as $opencode_connector_site_id ) {
			switch_to_blog( (int) $opencode_connector_site_id );
			delete_option( 'opencode_connector_settings' );
			foreach ( $opencode_connector_avail_keys as $opencode_connector_avail_key ) {
				delete_transient( $opencode_connector_avail_key );
			}
			unset( $opencode_connector_avail_key );
			opencode_connector_delete_ai_client_caches();
			restore_current_blog();
		}
	}
	unset( $opencode_connector_site_ids, $opencode_connector_site_id );
	delete_site_option( 'opencode_connector_settings' );
	foreach ( $opencode_connector_avail_keys as $opencode_connector_avail_key ) {
		delete_site_transient( $opencode_connector_avail_key );
	}
	unset( $opencode_connector_avail_key );
	opencode_connector_delete_ai_client_site_caches();
} else {
	delete_option( 'opencode_connector_settings' );
	delete_site_option( 'opencode_connector_settings' );
	foreach ( $opencode_connector_avail_keys as $opencode_connector_avail_key ) {
		delete_transient( $opencode_connector_avail_key );
		delete_site_transient( $opencode_connector_avail_key );
	}
	unset( $opencode_connector_avail_key );
	opencode_connector_delete_ai_client_caches();
	opencode_connector_delete_ai_client_site_caches();
}
unset( $opencode_connector_avail_keys, $opencode_connector_autoload );

/**
 * Delete AI Client model caches (ai_client_<VERSION>_<md5>_models) for the current site.
 *
 * Best-effort cleanup via direct DB fallback. Must run inside the per-site
 * loop on multisite so $wpdb->options points at each site's table.
 *
 * @since 0.1.9
 *
 * @return void
 */
function opencode_connector_delete_ai_client_caches(): void {
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_ai_client_' ) . '%_models', $wpdb->esc_like( '_transient_timeout_ai_client_' ) . '%_models' ) );
}

/**
 * Delete AI Client model caches stored as site-transients.
 *
 * Runs once: site-transients live in sitemeta regardless of the current blog.
 *
 * @since 0.1.9
 *
 * @return void
 */
function opencode_connector_delete_ai_client_site_caches(): void {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( '_site_transient_ai_client_' ) . '%_models', $wpdb->esc_like( '_site_transient_timeout_ai_client_' ) . '%_models' ) );
}
