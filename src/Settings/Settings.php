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
		// Deleting this option fires neither of the hooks above, so the
		// caches would outlive it. This is the same rule the connector-key
		// hooks in the main plugin file follow: any event that removes the
		// thing a cache describes must clear that cache, rather than waiting
		// out its TTL. The callback takes no arguments because
		// `delete_option_{option}` fires with the option name alone.
		add_action( 'delete_option_' . \OpenCodeConnector\OPTION_NAME, array( $this, 'bustCachesDelete' ), 10, 0 );
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
			$this->clearAvailabilityCaches();
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
		$this->clearAvailabilityCaches();
	}

	/**
	 * Bust caches on option delete.
	 *
	 * Registered with zero accepted arguments because `delete_option_{option}`
	 * fires with the option name alone, which is not the `(option, value)`
	 * shape `bustCachesAdd()` expects.
	 *
	 * @return void
	 */
	public function bustCachesDelete(): void {
		$this->clearModelCaches();
		$this->clearAvailabilityCaches();
	}

	/**
	 * Clear the stale availability verdicts a plugin-settings change invalidates.
	 *
	 * Credential-blind by design: only transient deletes, never reads or
	 * writes any `connectors_ai_*` option value. Keys come from the
	 * dependency-free Catalog so renames stay in one place without loading any
	 * SDK-trait-dependent class.
	 *
	 * Covers the availability result, its stampede lock, and the opt-in
	 * verification verdict with its lock, so a settings change can never leave a
	 * stale verdict behind.
	 *
	 * It deliberately does NOT delete the last-known-good flags. The delete list
	 * used to be Catalog::allTransientKeys(), which is every key for both
	 * catalogs — correct for uninstall.php, wrong here, and the same
	 * cross-catalog shape the entry-file key bust had just been scoped away from.
	 * This option holds `show_all_models` and nothing else, so saving, adding or
	 * deleting it adjudicates no credential; it cannot invalidate what either
	 * catalog's flag asserts. Both flags are independent 30-day fail-open
	 * fallbacks and `unknown` never re-arms one, so deleting them here meant a
	 * site that merely toggled a display setting lost its fallback until a
	 * *keyed* response arrived — after which, with the gateway answering
	 * 400/402/403/404, both connectors reported not-configured with no error to
	 * explain it. That is this PR's defect reached by saving a preference.
	 *
	 * A cache must be cleared by the event that invalidates what it describes.
	 * A key change does (see the entry-file bust, which still clears its own
	 * catalog's flag); a display setting does not. Uninstall still wants the
	 * flags gone, and still gets the full set from Catalog::allTransientKeys().
	 *
	 * @since 0.1.6
	 *
	 * @return void
	 */
	public static function clearAvailabilityCaches(): void {
		foreach ( \OpenCodeConnector\Metadata\Catalog::ALL as $catalog ) {
			$keys = array_merge(
				\OpenCodeConnector\Metadata\Catalog::availabilityResultKeys( $catalog ),
				\OpenCodeConnector\Metadata\Catalog::verifyKeys( $catalog )
			);
			foreach ( $keys as $key ) {
				delete_transient( $key );
				if ( function_exists( 'delete_site_transient' ) ) {
					delete_site_transient( $key );
				}
			}
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
		$classes = array(
			\OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory::class,
			\OpenCodeConnector\Metadata\OpenCodeZenModelMetadataDirectory::class,
		);
		$cache   = null;
		if ( class_exists( AiClient::class ) && method_exists( AiClient::class, 'getCache' ) ) {
			try {
				$cache = AiClient::getCache();
			} catch ( \Throwable ) {
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
	 * Fetch Go/Zen provider connection status without reading credentials.
	 *
	 * Credential-blind by design: status comes only from the boolean
	 * `isProviderConfigured()` probe. Never reads `connectors_ai_*` values.
	 *
	 * @since 0.1.6
	 *
	 * @return array{go: bool, zen: bool}
	 */
	private function fetchProviderStatus(): array {
		$status = array(
			'go'  => false,
			'zen' => false,
		);
		if ( ! class_exists( AiClient::class ) || ! method_exists( AiClient::class, 'defaultRegistry' ) ) {
			return $status;
		}
		try {
			$registry = AiClient::defaultRegistry();
			if ( ! is_object( $registry ) || ! method_exists( $registry, 'isProviderConfigured' ) ) {
				return $status;
			}
			// Use non-blocking check: transient-backed isConfigured() already has
			// stampede lock + jitter; avoid double HTTP on render by tolerating exceptions.
			foreach ( \OpenCodeConnector\Metadata\Catalog::ALL as $catalog ) {
				$provider_id = 'opencode-' . $catalog;
				try {
					$status[ $catalog ] = (bool) $registry->isProviderConfigured( $provider_id );
				} catch ( \Throwable ) {
					$status[ $catalog ] = false;
				}
			}
		} catch ( \Throwable ) {
			return array(
				'go'  => false,
				'zen' => false,
			);
		}
		return $status;
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
		$status = $this->fetchProviderStatus();
		$go_ok  = $status['go'];
		$zen_ok = $status['zen'];
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
