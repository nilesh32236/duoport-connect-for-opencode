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

// The AI Client model-cache rows this plugin owns. The SDK cache key is
// `ai_client_<VERSION>_<md5(directory class)>_models` (the formula
// Settings::modelCacheKey() centralises), so the per-directory md5 is what
// identifies this plugin's cache. The provider-agnostic wildcard
// `ai_client_%_models` is deliberately NOT used: on a network it would purge
// every other component's active model cache. Anchoring the LIKE on the md5
// keeps the delete plugin-scoped and still covers rows written by an older
// AI Client version. The directory FQCNs are spelled literally because the
// autoloader is optional at uninstall time.
$opencode_connector_model_keys   = array();
$opencode_connector_option_likes = array();
$opencode_connector_meta_likes   = array();
$opencode_connector_ai_version   = defined( 'WordPress\\AiClient\\AiClient::VERSION' ) ? constant( 'WordPress\\AiClient\\AiClient::VERSION' ) : '0.0.0';
foreach ( array( 'OpenCodeConnector\\Metadata\\OpenCodeGoModelMetadataDirectory', 'OpenCodeConnector\\Metadata\\OpenCodeZenModelMetadataDirectory' ) as $opencode_connector_dir_class ) {
	$opencode_connector_model_hash   = md5( $opencode_connector_dir_class );
	$opencode_connector_model_keys[] = 'ai_client_' . $opencode_connector_ai_version . '_' . $opencode_connector_model_hash . '_models';
	// The only wildcard left is the version segment, so an older AI Client
	// version's rows are still cleaned; the md5 keeps the delete plugin-scoped.
	$opencode_connector_option_likes[] = '\\_transient\\_ai\\_client\\_%\\_' . $opencode_connector_model_hash . '\\_models';
	$opencode_connector_option_likes[] = '\\_transient\\_timeout\\_ai\\_client\\_%\\_' . $opencode_connector_model_hash . '\\_models';
	$opencode_connector_meta_likes[]   = '\\_site\\_transient\\_ai\\_client\\_%\\_' . $opencode_connector_model_hash . '\\_models';
	$opencode_connector_meta_likes[]   = '\\_site\\_transient\\_timeout\\_ai\\_client\\_%\\_' . $opencode_connector_model_hash . '\\_models';
}
unset( $opencode_connector_model_hash, $opencode_connector_dir_class );

// Clean one site's rows: the settings option, every plugin transient, and this
// plugin's own AI Client model-cache rows. Safe to run on a site that never
// used the plugin: every call is a targeted delete.
$opencode_connector_clean_site = static function () use ( $opencode_connector_option, $opencode_connector_avail_keys, $opencode_connector_model_keys, $opencode_connector_option_likes ): void {
	delete_option( $opencode_connector_option );
	foreach ( $opencode_connector_avail_keys as $opencode_connector_avail_key ) {
		delete_transient( $opencode_connector_avail_key );
	}
	// AI Client model caches (ai_client_<VERSION>_<md5>_models) for this site,
	// limited to this plugin's two directory classes. The exact key clears the
	// object cache; the md5-anchored LIKE clears the rows an older AI Client
	// version wrote.
	foreach ( $opencode_connector_model_keys as $opencode_connector_model_key ) {
		delete_transient( $opencode_connector_model_key );
	}
	global $wpdb;
	if ( isset( $wpdb ) ) {
		$opencode_connector_clauses = array();
		foreach ( $opencode_connector_option_likes as $opencode_connector_option_like ) {
			$opencode_connector_clauses[] = $wpdb->prepare( 'option_name LIKE %s', $opencode_connector_option_like );
		}
		if ( array() !== $opencode_connector_clauses ) {
			// Each clause is already a prepared fragment; the only interpolated
			// part is the table name, which is a $wpdb property.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Uninstall cleanup.
			$wpdb->query( 'DELETE FROM ' . $wpdb->options . ' WHERE ' . implode( ' OR ', $opencode_connector_clauses ) );
		}
		unset( $opencode_connector_clauses );
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
	$opencode_connector_meta_clauses = array();
	foreach ( $opencode_connector_meta_likes as $opencode_connector_meta_like ) {
		$opencode_connector_meta_clauses[] = $wpdb->prepare( 'meta_key LIKE %s', $opencode_connector_meta_like );
	}
	if ( array() !== $opencode_connector_meta_clauses ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Uninstall cleanup.
		$wpdb->query( 'DELETE FROM ' . $wpdb->sitemeta . ' WHERE ' . implode( ' OR ', $opencode_connector_meta_clauses ) );
	}
	unset( $opencode_connector_meta_clauses, $opencode_connector_meta_like );
}

// Multisite: the settings row is registered per blog (Settings::register()
// calls register_setting for the current site only) and the transients are
// per-site, so every other subsite keeps its own rows after a network
// uninstall. Activation scope cannot be read here — core clears
// active_sitewide_plugins before uninstall runs — so sweep the whole network in
// batches of $opencode_connector_site_batch sites, advancing an offset so every
// site is reached exactly once instead of re-cleaning the same lowest-ID prefix
// and skipping the rest. Cache invalidation is suspended for the sweep because
// each switch_to_blog() would otherwise invalidate once per site.
$opencode_connector_site_batch = 100;
if ( is_multisite() && function_exists( 'get_sites' ) && function_exists( 'switch_to_blog' ) ) {
	if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
		wp_suspend_cache_invalidation( true );
	}
	try {
		$opencode_connector_offset = 0;
		do {
			$opencode_connector_sites   = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => $opencode_connector_site_batch,
					'offset'                 => $opencode_connector_offset,
					'orderby'                => 'id',
					'order'                  => 'ASC',
					'update_site_meta_cache' => false,
				)
			);
			$opencode_connector_offset += $opencode_connector_site_batch;
			if ( ! is_array( $opencode_connector_sites ) || array() === $opencode_connector_sites ) {
				break;
			}
			foreach ( $opencode_connector_sites as $opencode_connector_site_id ) {
				if ( ! is_scalar( $opencode_connector_site_id ) ) {
					continue;
				}
				// Only pop a stack entry switch_to_blog() actually pushed, and pop
				// it even when cleanup throws, so the rest of the request cannot run
				// against another blog's options table.
				$opencode_connector_switched = switch_to_blog( (int) $opencode_connector_site_id );
				try {
					$opencode_connector_clean_site();
				} finally {
					if ( $opencode_connector_switched ) {
						restore_current_blog();
					}
				}
				unset( $opencode_connector_switched );
			}
			$opencode_connector_fetched = count( $opencode_connector_sites );
			unset( $opencode_connector_sites );
		} while ( $opencode_connector_fetched >= $opencode_connector_site_batch );
		unset( $opencode_connector_fetched, $opencode_connector_offset );
	} finally {
		// Never leave the request running with cache invalidation suspended.
		if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
			wp_suspend_cache_invalidation( false );
		}
	}
}
unset( $opencode_connector_clean_site, $opencode_connector_avail_keys, $opencode_connector_avail_key, $opencode_connector_autoload, $opencode_connector_option, $opencode_connector_model_keys, $opencode_connector_model_key, $opencode_connector_option_likes, $opencode_connector_option_like, $opencode_connector_meta_likes, $opencode_connector_ai_version, $opencode_connector_site_batch, $opencode_connector_site_id );
