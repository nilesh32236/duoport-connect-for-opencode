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
delete_option( 'opencode_connector_settings' );
delete_site_option( 'opencode_connector_settings' );
delete_transient( 'opencode_connector_avail_go' );
delete_transient( 'opencode_connector_avail_zen' );
delete_site_transient( 'opencode_connector_avail_go' );
delete_site_transient( 'opencode_connector_avail_zen' );
delete_transient( 'opencode_connector_avail_go_lock' );
delete_transient( 'opencode_connector_avail_zen_lock' );
delete_site_transient( 'opencode_connector_avail_go_lock' );
delete_site_transient( 'opencode_connector_avail_zen_lock' );
// AI Client model caches (ai_client_<VERSION>_<md5>_models) — best-effort cleanup
// for both single-site transients and multisite site-transients + direct DB fallback.
global $wpdb;
if ( isset( $wpdb ) ) {
	$like = $wpdb->esc_like( 'ai_client_' ) . '%_models';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_ai_client_' ) . '%_models', $wpdb->esc_like( '_transient_timeout_ai_client_' ) . '%_models' ) );
	// Also clear raw LIKE for non-prefixed fallback (defensive).
	unset( $like );
	if ( is_multisite() ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( '_site_transient_ai_client_' ) . '%_models', $wpdb->esc_like( '_site_transient_timeout_ai_client_' ) . '%_models' ) );
	}
}
