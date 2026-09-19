<?php
/**
 * Settings page and cache-bust handlers.
 *
 * Handles settings registration and cache busting.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\AiClient;
use OpenCodeConnector\Catalog;

/**
 * Settings page and cache-bust handlers.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
final class Settings {
	/**
	 * Register settings and hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		register_setting(
			'opencode_connector',
			\OpenCodeConnector\OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => array( 'show_all_models' => false ),
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ) );
		}
		add_action( 'update_option_' . \OpenCodeConnector\OPTION_NAME, array( $this, 'bustCaches' ), 10, 2 );
		add_action( 'add_option_' . \OpenCodeConnector\OPTION_NAME, array( $this, 'bustCachesAdd' ), 10, 2 );
	}

	/**
	 * Sanitize settings.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'show_all_models' => false );
		}
		return array( 'show_all_models' => ! empty( $value['show_all_models'] ) );
	}

	/**
	 * Bust caches on option update.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public function bustCaches( $old_value, $new_value ): void {
		if ( ( $old_value['show_all_models'] ?? false ) !== ( $new_value['show_all_models'] ?? false ) ) {
			$this->clearModelCaches();
			$this->bustAvailabilityCaches();
		}
	}

	/**
	 * Bust caches on option add.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return void
	 */
	public function bustCachesAdd( string $option, $value ): void {
		unset( $option, $value );
		$this->clearModelCaches();
		$this->bustAvailabilityCaches();
	}

	/**
	 * Delete every catalog availability transient.
	 *
	 * Keys resolve from Catalog so a renamed prefix or a new catalog cannot
	 * orphan a cache. Credential-blind: transient deletes only, never reads
	 * or writes any connectors_ai_* option.
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	private function bustAvailabilityCaches(): void {
		foreach ( Catalog::all() as $catalog ) {
			delete_transient( Catalog::transient_key( $catalog ) );
		}
	}

	/**
	 * Clear model caches.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function clearModelCaches(): void {
		// Directory list resolves from the Catalog registry so a new catalog
		// is busted without editing this method.
		$classes = Catalog::directory_classes();
		$cache   = null;
		if ( class_exists( AiClient::class ) && method_exists( AiClient::class, 'getCache' ) ) {
			try {
				$cache = AiClient::getCache();
			} catch ( \Throwable $e ) {
				$cache = null;
			}
		}
		foreach ( $classes as $cls ) {
			$full_key = $this->modelCacheKey( $cls );
			if ( is_object( $cache ) && method_exists( $cache, 'delete' ) ) {
				$cache->delete( $full_key );
			}
			delete_transient( $full_key );
			delete_site_transient( $full_key );
			// Fallback: direct option delete for object-cache-less installs (single + multisite).
			global $wpdb;
			if ( isset( $wpdb ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fallback for object-cache-less installs.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s", '_transient_' . $full_key, '_transient_timeout_' . $full_key ) );
				if ( is_multisite() ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key = %s OR meta_key = %s", '_site_transient_' . $full_key, '_site_transient_timeout_' . $full_key ) );
				}
			}
		}
	}

	/**
	 * Build the AI Client model-cache key for a directory class.
	 *
	 * Centralizes the ai_client_<VERSION>_<md5>_models pattern so VERSION bumps
	 * and trait changes stay in one place.
	 *
	 * NOTE: the key formula mirrors the SDK's internal cache layout. Pinned
	 * against AiClient 1.x (tested range: 1.0–1.3, see CompatGuardsTest); if
	 * the SDK changes its key format, stale model lists orphan — revisit this
	 * method on SDK major bumps.
	 *
	 * @since 0.1.1
	 * @param string $class_name FQCN.
	 * @return string
	 */
	private function modelCacheKey( string $class_name ): string {
		$ai_version = defined( AiClient::class . '::VERSION' ) ? AiClient::VERSION : '0.0.0';
		return 'ai_client_' . $ai_version . '_' . md5( $class_name ) . '_models';
	}

	/**
	 * Add options page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function menu(): void {
		add_options_page( __( 'DuoPort Connector', 'duoport-connect-for-opencode' ), __( 'DuoPort Connector', 'duoport-connect-for-opencode' ), 'manage_options', 'duoport-connect-for-opencode', array( $this, 'render' ) );
	}

	/**
	 * Render settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		$opts   = get_option( \OpenCodeConnector\OPTION_NAME, array( 'show_all_models' => false ) );
		$go_ok  = false;
		$zen_ok = false;
		if ( class_exists( AiClient::class ) && method_exists( AiClient::class, 'defaultRegistry' ) ) {
			try {
				$registry = AiClient::defaultRegistry();
				if ( is_object( $registry ) && method_exists( $registry, 'isProviderConfigured' ) ) {
					// Use non-blocking check: transient-backed isConfigured() already has
					// stampede lock + jitter; avoid double HTTP on render by tolerating exceptions.
					try {
						$go_ok = $registry->isProviderConfigured( 'opencode-go' );
					} catch ( \Throwable $e ) {
						$go_ok = false;
					}
					try {
						$zen_ok = $registry->isProviderConfigured( 'opencode-zen' );
					} catch ( \Throwable $e ) {
						$zen_ok = false;
					}
				}
			} catch ( \Throwable $e ) {
				$go_ok  = false;
				$zen_ok = false;
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DuoPort Connector', 'duoport-connect-for-opencode' ); ?></h1>
			<p>
			<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: URL to Settings → Connectors screen */
						__( 'Enter your API key in both the Go and Zen fields on <a href="%s">Settings → Connectors</a>. The same opencode.ai key works for both catalogs.', 'duoport-connect-for-opencode' ),
						esc_url( admin_url( 'options-connectors.php' ) )
					)
				);
			?>
				</p>
			<p><?php esc_html_e( 'Go: subscription catalog. Zen: pay-as-you-go catalog including free models (no credit cost, still needs a key).', 'duoport-connect-for-opencode' ); ?></p>
			<p>
			<?php
				/* translators: 1: Go connection status, 2: Zen connection status */
				printf( esc_html__( 'Go: %1$s · Zen: %2$s', 'duoport-connect-for-opencode' ), $go_ok ? esc_html__( 'connected', 'duoport-connect-for-opencode' ) : esc_html__( 'not connected', 'duoport-connect-for-opencode' ), $zen_ok ? esc_html__( 'connected', 'duoport-connect-for-opencode' ) : esc_html__( 'not connected', 'duoport-connect-for-opencode' ) );
			?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'opencode_connector' ); ?>
				<table class="form-table"><tr>
					<th><?php esc_html_e( 'Show all models', 'duoport-connect-for-opencode' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( \OpenCodeConnector\OPTION_NAME ); ?>[show_all_models]" value="1" <?php checked( ! empty( $opts['show_all_models'] ) ); ?> /> <?php esc_html_e( 'Show every model from the API (including non-chat models that may fail)', 'duoport-connect-for-opencode' ); ?></label></td>
				</tr></table>
				<?php submit_button(); ?>
			</form>
			<p><a href="https://opencode.ai/auth" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API key', 'duoport-connect-for-opencode' ); ?></a></p>
		</div>
		<?php
	}
}
