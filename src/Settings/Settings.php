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
use OpenCodeConnector\Availability\OpenCodeProviderAvailability;

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
			delete_transient( 'opencode_connector_avail_go' );
			delete_transient( 'opencode_connector_avail_zen' );
			if ( function_exists( 'delete_transient' ) ) {
				try {
					delete_transient( 'opencode_connector_avail_go_cause' );
					delete_transient( 'opencode_connector_avail_zen_cause' );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
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
		delete_transient( 'opencode_connector_avail_go' );
		delete_transient( 'opencode_connector_avail_zen' );
		if ( function_exists( 'delete_transient' ) ) {
			try {
				delete_transient( 'opencode_connector_avail_go_cause' );
				delete_transient( 'opencode_connector_avail_zen_cause' );
			} catch ( \Throwable $e ) {
				unset( $e );
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
	 * @since 0.1.1
	 * @param string $class_name FQCN.
	 * @return string
	 */
	private function modelCacheKey( string $class_name ): string {
		$ai_version = defined( AiClient::class . '::VERSION' ) ? AiClient::VERSION : '0.0.0';
		return 'ai_client_' . $ai_version . '_' . md5( $class_name ) . '_models';
	}

	/**
	 * Plugin-owned save-marker transient for a catalog.
	 *
	 * Credential-blind: the marker records THAT a key save was observed (set
	 * by the key-change bust hooks), never the key value itself.
	 *
	 * @since 0.1.5
	 *
	 * @param string $catalog Catalog slug (go|zen).
	 * @return string
	 */
	public static function saveMarkerKey( string $catalog ): string {
		$catalog = 'zen' === $catalog ? 'zen' : 'go';
		return 'opencode_connector_key_seen_' . $catalog;
	}

	/**
	 * Plugin-owned diagnosis-cause transient for a catalog.
	 *
	 * Written by the availability probe alongside its boolean verdict;
	 * diagnostics only read this status, never secret values.
	 *
	 * @since 0.1.5
	 *
	 * @param string $catalog Catalog slug (go|zen).
	 * @return string
	 */
	public static function causeKey( string $catalog ): string {
		$catalog = 'zen' === $catalog ? 'zen' : 'go';
		return 'opencode_connector_avail_' . $catalog . '_cause';
	}

	/**
	 * Allowlisted diagnosis cause classes.
	 *
	 * Delegates to the availability probe when available.
	 *
	 * @since 0.1.5
	 *
	 * @return string[]
	 */
	public static function validCauses(): array {
		$fallback = array( 'ok', 'valid-no-credits', 'throttled', 'bad-key', 'network-failure', 'server-error', 'unconfigured', 'unknown' );
		// Probe the SDK first with side-effect-free checks: loading the
		// availability class without its SDK traits is an uncatchable fatal,
		// so never touch it unless the SDK contracts are present.
		try {
			if ( ! interface_exists( 'WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface' ) ) {
				return $fallback;
			}
			if ( ! trait_exists( 'WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait' ) ) {
				return $fallback;
			}
			if ( class_exists( OpenCodeProviderAvailability::class ) && method_exists( OpenCodeProviderAvailability::class, 'validCauses' ) ) {
				$causes = OpenCodeProviderAvailability::validCauses();
				if ( is_array( $causes ) ) {
					return $causes;
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $fallback;
	}

	/**
	 * Persisted state per catalog (did a key save reach us?).
	 *
	 * Derived credential-blind from the plugin-owned save markers only;
	 * never reads any connectors_ai_* option value.
	 *
	 * @since 0.1.5
	 *
	 * @return array{go: string, zen: string} Each entry is 'yes' or 'unknown'.
	 */
	private function persistedState(): array {
		$state = array(
			'go'  => 'unknown',
			'zen' => 'unknown',
		);
		if ( ! function_exists( 'get_transient' ) ) {
			return $state;
		}
		foreach ( array( 'go', 'zen' ) as $catalog ) {
			try {
				$seen = get_transient( self::saveMarkerKey( $catalog ) );
				if ( ! empty( $seen ) ) {
					$state[ $catalog ] = 'yes';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return $state;
	}

	/**
	 * Verified-state diagnosis per catalog.
	 *
	 * Reads only the cached cause status (never secret values) and fails
	 * open to unknown when the cache is empty or WP APIs are unavailable.
	 *
	 * @since 0.1.5
	 *
	 * @param string $catalog    Catalog slug (go|zen).
	 * @param bool   $configured Live boolean verdict from the registry.
	 * @return array{configured: bool, cause: string}
	 */
	private function diagnosisFor( string $catalog, bool $configured ): array {
		$catalog = 'zen' === $catalog ? 'zen' : 'go';
		$cause   = $configured ? 'ok' : 'unknown';
		if ( function_exists( 'get_transient' ) ) {
			try {
				$cached = get_transient( self::causeKey( $catalog ) );
				if ( is_string( $cached ) && in_array( $cached, self::validCauses(), true ) ) {
					$cause = $cached;
					if ( 'unknown' !== $cause ) {
						$configured = in_array( $cause, array( 'ok', 'valid-no-credits', 'throttled' ), true );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return array(
			'configured' => $configured,
			'cause'      => $cause,
		);
	}

	/**
	 * Human-readable message for a cause class.
	 *
	 * Each message names its cause class (bad key vs network failure vs
	 * server error vs unconfigured) so validation errors are differentiated.
	 *
	 * @since 0.1.5
	 *
	 * @param string $cause Cause class.
	 * @return string
	 */
	public static function causeMessage( string $cause ): string {
		switch ( $cause ) {
			case 'ok':
				return __( 'Verified: connected.', 'duoport-connect-for-opencode' );
			case 'valid-no-credits':
				return __( 'Verified: connected (valid key, no credits remaining).', 'duoport-connect-for-opencode' );
			case 'throttled':
				return __( 'Verified: connected (requests are throttled, but the key is valid).', 'duoport-connect-for-opencode' );
			case 'bad-key':
				return __( 'Validation failed: bad key — the API rejected the key.', 'duoport-connect-for-opencode' );
			case 'network-failure':
				return __( 'Validation failed: network failure — could not reach the API.', 'duoport-connect-for-opencode' );
			case 'server-error':
				return __( 'Validation failed: server error — the API is temporarily unavailable.', 'duoport-connect-for-opencode' );
			case 'unconfigured':
				return __( 'Not configured: no key has been verified yet.', 'duoport-connect-for-opencode' );
			default:
				return __( 'Status unknown: the connection has not been checked yet.', 'duoport-connect-for-opencode' );
		}
	}

	/**
	 * Whether the diagnosis looks like a reverted save.
	 *
	 * A save marker fired (persisted=yes) but verification still reports an
	 * unverified cause — the exact confusion from the historical shared-setting
	 * bug, where core validated a masked placeholder and silently reverted.
	 *
	 * @since 0.1.5
	 *
	 * @param string $saved Persisted state ('yes'|'unknown').
	 * @param array  $diagnosis Diagnosis array with configured + cause keys.
	 * @return bool
	 */
	private static function isSaveReverted( string $saved, array $diagnosis ): bool {
		if ( 'yes' !== $saved || ! empty( $diagnosis['configured'] ) ) {
			return false;
		}
		$cause = isset( $diagnosis['cause'] ) && is_string( $diagnosis['cause'] ) ? $diagnosis['cause'] : 'unknown';
		return in_array( $cause, array( 'bad-key', 'unconfigured', 'unknown' ), true );
	}

	/**
	 * Keyless dry-run/trial preview info.
	 *
	 * Needs no key and performs no external call: it only describes what a
	 * trial looks like so the screen is useful before any key is saved.
	 *
	 * @since 0.1.5
	 *
	 * @return array{supported: bool, message: string}
	 */
	public function dryRunPreview(): array {
		$message = __( 'Preview both catalogs without saving a key: Go is the subscription catalog, Zen is the pay-as-you-go catalog including free models. Saving a key is only required for live requests.', 'duoport-connect-for-opencode' );
		if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) ) {
			try {
				if ( has_filter( 'opencode_connector_dry_run_message' ) ) {
					$filtered = apply_filters( 'opencode_connector_dry_run_message', $message );
					if ( is_string( $filtered ) && '' !== $filtered ) {
						$message = $filtered;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		$supported = true;
		if ( function_exists( 'get_bloginfo' ) ) {
			try {
				$supported = version_compare( (string) get_bloginfo( 'version' ), '7.0', '>=' );
			} catch ( \Throwable $e ) {
				unset( $e );
				$supported = true;
			}
		}
		return array(
			'supported' => $supported,
			'message'   => $message,
		);
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
		// Persisted state (was a key save observed?) is tracked credential-blind
		// via plugin-owned markers; verified state comes from the cached probe.
		$persisted = $this->persistedState();
		$go_diag   = $this->diagnosisFor( 'go', (bool) $go_ok );
		$zen_diag  = $this->diagnosisFor( 'zen', (bool) $zen_ok );
		$preview   = $this->dryRunPreview();
		$errors    = array();
		if ( function_exists( 'get_settings_errors' ) ) {
			try {
				$fetched = get_settings_errors();
				if ( is_array( $fetched ) ) {
					$errors = $fetched;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$errors = array();
			}
		}
		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
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
			<h2><?php esc_html_e( 'Connection status', 'duoport-connect-for-opencode' ); ?></h2>
			<?php $this->renderCatalogStatus( 'Go', $persisted['go'], $go_diag, $debug ); ?>
			<?php $this->renderCatalogStatus( 'Zen', $persisted['zen'], $zen_diag, $debug ); ?>
			<?php if ( ! empty( $errors ) ) : ?>
				<h2><?php esc_html_e( 'Settings notices', 'duoport-connect-for-opencode' ); ?></h2>
				<?php foreach ( $errors as $notice ) : ?>
					<?php if ( is_array( $notice ) && isset( $notice['message'] ) && is_string( $notice['message'] ) && '' !== $notice['message'] ) : ?>
						<p class="description"><?php echo esc_html( $notice['message'] ); ?></p>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Trial preview (no key required)', 'duoport-connect-for-opencode' ); ?></h2>
			<p><?php echo esc_html( $preview['message'] ); ?></p>
			<p class="description"><?php esc_html_e( 'Dry run: this preview needs no key and makes no required external call; save a key on Settings → Connectors to enable live requests.', 'duoport-connect-for-opencode' ); ?></p>
			<?php if ( empty( $preview['supported'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Trial preview requires WordPress 7.0+.', 'duoport-connect-for-opencode' ); ?></p>
			<?php endif; ?>
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

	/**
	 * Render one catalog's persisted vs verified states plus diagnostics.
	 *
	 * Saved and verified are shown as distinct states so a persisted-but-
	 * unverified key reads as a validation problem, not a silent mystery.
	 *
	 * @since 0.1.5
	 *
	 * @param string $label Catalog label (Go|Zen).
	 * @param string $saved Persisted state ('yes'|'unknown').
	 * @param array  $diagnosis Diagnosis array with configured + cause keys.
	 * @param bool   $debug Whether to append the technical cause slug.
	 * @return void
	 */
	private function renderCatalogStatus( string $label, string $saved, array $diagnosis, bool $debug ): void {
		$catalog       = 'Zen' === $label ? 'zen' : 'go';
		$saved_label   = 'yes' === $saved ? __( 'saved', 'duoport-connect-for-opencode' ) : __( 'unknown — no save observed yet', 'duoport-connect-for-opencode' );
		$verified_bool = ! empty( $diagnosis['configured'] );
		$verified      = $verified_bool ? __( 'connected', 'duoport-connect-for-opencode' ) : __( 'not connected', 'duoport-connect-for-opencode' );
		$cause         = isset( $diagnosis['cause'] ) && is_string( $diagnosis['cause'] ) ? $diagnosis['cause'] : 'unknown';
		if ( ! in_array( $cause, self::validCauses(), true ) ) {
			$cause = 'unknown';
		}
		?>
		<p>
		<?php
			/* translators: 1: catalog label, 2: saved (persisted) state, 3: verified state */
			printf( esc_html__( '%1$s — Saved: %2$s · Verified: %3$s', 'duoport-connect-for-opencode' ), esc_html( $label ), esc_html( $saved_label ), esc_html( $verified ) );
		?>
		</p>
		<p class="description">
		<?php
			/* translators: %s: catalog label */
			printf( esc_html__( '%s validation: ', 'duoport-connect-for-opencode' ), esc_html( $label ) );
			echo esc_html( self::causeMessage( $cause ) );
		if ( self::isSaveReverted( $saved, $diagnosis ) ) {
			echo ' ';
			esc_html_e( 'The key save may have been reverted (masked placeholder validation) — re-enter the key on Settings → Connectors.', 'duoport-connect-for-opencode' );
		}
		if ( $debug ) {
			/* translators: %s: technical cause slug */
			printf( ' ' . esc_html__( '(cause: %s)', 'duoport-connect-for-opencode' ), esc_html( $catalog . ':' . $cause ) );
		}
		?>
		</p>
		<?php
	}
}
