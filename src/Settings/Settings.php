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
	 * Default temperature when none is stored.
	 *
	 * @since 0.1.5
	 */
	const DEFAULT_TEMPERATURE = 0.7;

	/**
	 * Default max tokens when none is stored.
	 *
	 * @since 0.1.5
	 */
	const DEFAULT_MAX_TOKENS = 1024;

	/**
	 * Maximum accepted max-tokens value.
	 *
	 * @since 0.1.5
	 */
	const MAX_TOKENS_LIMIT = 8192;

	/**
	 * Site-wide defaults for the plugin-owned option.
	 *
	 * Additive keys only; never touches any `connectors_ai_*` setting.
	 *
	 * @since 0.1.5
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'show_all_models' => false,
			'default_model'   => '',
			'temperature'     => self::DEFAULT_TEMPERATURE,
			'max_tokens'      => self::DEFAULT_MAX_TOKENS,
		);
	}

	/**
	 * Read the plugin-owned option defensively (credential-blind).
	 *
	 * Reads ONLY the plugin-owned `opencode_connector_settings` option and
	 * never any `connectors_ai_*` option value. Fail-open: non-array or
	 * missing options yield safe defaults.
	 *
	 * @since 0.1.5
	 *
	 * @return array
	 */
	public static function stored_options(): array {
		$defaults = self::defaults();
		try {
			if ( ! function_exists( 'get_option' ) ) {
				return $defaults;
			}
			$raw = get_option( \OpenCodeConnector\OPTION_NAME, array() );
		} catch ( \Throwable $e ) {
			return $defaults;
		}
		if ( ! is_array( $raw ) ) {
			return $defaults;
		}
		return array_merge( $defaults, $raw );
	}

	/**
	 * Merge stored generation defaults into request data.
	 *
	 * Pure helper: caller-supplied values always win; stored defaults fill
	 * gaps only. Invalid stored model IDs are ignored (fail-open).
	 *
	 * @since 0.1.5
	 *
	 * @param mixed $data   Request data (expected array for chat/completions).
	 * @param array $stored Stored options (use stored_options() at runtime).
	 * @return mixed Request data with defaults applied (unchanged when not an array).
	 */
	public static function merge_generation_defaults( $data, array $stored ): array {
		if ( ! is_array( $data ) ) {
			return is_array( $data ) ? $data : array();
		}
		$merged = $data;
		try {
			$default_model = isset( $stored['default_model'] ) && is_string( $stored['default_model'] ) ? trim( $stored['default_model'] ) : '';
			if ( ( ! isset( $merged['model'] ) || '' === $merged['model'] ) && '' !== $default_model ) {
				$allowed = false;
				if ( class_exists( \OpenCodeConnector\Metadata\ModelAllowlist::class ) && method_exists( \OpenCodeConnector\Metadata\ModelAllowlist::class, 'isAllowed' ) ) {
					$allowed = \OpenCodeConnector\Metadata\ModelAllowlist::isAllowed( $default_model, 'go' ) || \OpenCodeConnector\Metadata\ModelAllowlist::isAllowed( $default_model, 'zen' );
				} else {
					$allowed = true;
				}
				if ( $allowed ) {
					$merged['model'] = $default_model;
				}
			}
			if ( ! array_key_exists( 'temperature', $merged ) || null === $merged['temperature'] ) {
				if ( isset( $stored['temperature'] ) && is_numeric( $stored['temperature'] ) ) {
					$merged['temperature'] = (float) $stored['temperature'];
				}
			}
			if ( ! array_key_exists( 'max_tokens', $merged ) || null === $merged['max_tokens'] ) {
				if ( isset( $stored['max_tokens'] ) && is_numeric( $stored['max_tokens'] ) ) {
					$merged['max_tokens'] = (int) $stored['max_tokens'];
				}
			}
		} catch ( \Throwable $e ) {
			return $data;
		}
		return $merged;
	}

	/**
	 * Apply stored generation defaults to request data (runtime entry point).
	 *
	 * Reads only the plugin-owned option; credential-blind and fail-open:
	 * any failure returns $data unchanged.
	 *
	 * @since 0.1.5
	 *
	 * @param mixed $data Request data.
	 * @return mixed Request data with defaults applied.
	 */
	public static function apply_to_request_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		try {
			return self::merge_generation_defaults( $data, self::stored_options() );
		} catch ( \Throwable $e ) {
			return $data;
		}
	}

	/**
	 * Sanitize a default-model value (allowlisted or empty for auto).
	 *
	 * @since 0.1.5
	 *
	 * @param mixed $value Raw value.
	 * @return string Sanitized model ID or ''.
	 */
	public static function sanitize_default_model( $value ): string {
		$text = '';
		try {
			if ( ! is_string( $value ) ) {
				return '';
			}
			if ( function_exists( 'sanitize_text_field' ) ) {
				$text = sanitize_text_field( $value );
			} else {
				$text = trim( strip_tags( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			}
			$text = trim( $text );
			if ( '' === $text ) {
				return '';
			}
			if ( class_exists( \OpenCodeConnector\Metadata\ModelAllowlist::class ) && method_exists( \OpenCodeConnector\Metadata\ModelAllowlist::class, 'isAllowed' ) ) {
				if ( ! \OpenCodeConnector\Metadata\ModelAllowlist::isAllowed( $text, 'go' ) && ! \OpenCodeConnector\Metadata\ModelAllowlist::isAllowed( $text, 'zen' ) ) {
					return '';
				}
			}
			return $text;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Sanitize a temperature value (clamped 0–2).
	 *
	 * @since 0.1.5
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function sanitize_temperature( $value ): float {
		try {
			if ( ! is_numeric( $value ) ) {
				return self::DEFAULT_TEMPERATURE;
			}
			$temp = (float) $value;
			if ( $temp < 0 ) {
				return 0.0;
			}
			if ( $temp > 2 ) {
				return 2.0;
			}
			return $temp;
		} catch ( \Throwable $e ) {
			return self::DEFAULT_TEMPERATURE;
		}
	}

	/**
	 * Sanitize a max-tokens value (clamped positive int).
	 *
	 * @since 0.1.5
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_max_tokens( $value ): int {
		try {
			if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
				return self::DEFAULT_MAX_TOKENS;
			}
			if ( function_exists( 'absint' ) ) {
				$int = absint( $value );
			} else {
				$int = abs( (int) $value );
			}
			if ( $int < 1 ) {
				return 1;
			}
			if ( $int > self::MAX_TOKENS_LIMIT ) {
				return self::MAX_TOKENS_LIMIT;
			}
			return $int;
		} catch ( \Throwable $e ) {
			return self::DEFAULT_MAX_TOKENS;
		}
	}

	/**
	 * Register settings and hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( function_exists( 'register_setting' ) ) {
			register_setting(
				'opencode_connector',
				\OpenCodeConnector\OPTION_NAME,
				array(
					'type'              => 'array',
					'default'           => self::defaults(),
					'sanitize_callback' => array( $this, 'sanitize' ),
				)
			);
		}
		if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'menu' ) );
		} elseif ( ! function_exists( 'is_admin' ) && function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'menu' ) );
		}
		if ( function_exists( 'add_action' ) ) {
			add_action( 'update_option_' . \OpenCodeConnector\OPTION_NAME, array( $this, 'bustCaches' ), 10, 2 );
			add_action( 'add_option_' . \OpenCodeConnector\OPTION_NAME, array( $this, 'bustCachesAdd' ), 10, 2 );
			if ( class_exists( ConnectionTest::class ) ) {
				try {
					( new ConnectionTest() )->register();
				} catch ( \Throwable $e ) {
					// Fail-open: settings still work without the test endpoint.
					unset( $e );
				}
			}
		}
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
		$defaults = self::defaults();
		if ( ! is_array( $value ) ) {
			return $defaults;
		}
		return array(
			'show_all_models' => ! empty( $value['show_all_models'] ),
			'default_model'   => self::sanitize_default_model( $value['default_model'] ?? '' ),
			'temperature'     => self::sanitize_temperature( $value['temperature'] ?? $defaults['temperature'] ),
			'max_tokens'      => self::sanitize_max_tokens( $value['max_tokens'] ?? $defaults['max_tokens'] ),
		);
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
		$old  = is_array( $old_value ) ? $old_value : array();
		$new  = is_array( $new_value ) ? $new_value : array();
		$keys = array( 'show_all_models', 'default_model', 'temperature', 'max_tokens' );
		foreach ( $keys as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				$this->clearModelCaches();
				$this->clearAvailabilityCaches();
				return;
			}
		}
	}

	/**
	 * Delete availability + verdict transients (credential-blind).
	 *
	 * @since 0.1.5
	 *
	 * @return void
	 */
	private function clearAvailabilityCaches(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'opencode_connector_avail_go' );
			delete_transient( 'opencode_connector_avail_zen' );
			delete_transient( 'opencode_connector_test_go' );
			delete_transient( 'opencode_connector_test_zen' );
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
		$defaults = self::defaults();
		$opts     = $defaults;
		try {
			if ( function_exists( 'get_option' ) ) {
				$raw = get_option( \OpenCodeConnector\OPTION_NAME, $defaults );
				if ( is_array( $raw ) ) {
					$opts = array_merge( $defaults, $raw );
				}
			}
		} catch ( \Throwable $e ) {
			$opts = $defaults;
		}
		$default_model = isset( $opts['default_model'] ) && is_string( $opts['default_model'] ) ? $opts['default_model'] : '';
		$temperature   = isset( $opts['temperature'] ) && is_numeric( $opts['temperature'] ) ? (float) $opts['temperature'] : self::DEFAULT_TEMPERATURE;
		$max_tokens    = isset( $opts['max_tokens'] ) && is_numeric( $opts['max_tokens'] ) ? (int) $opts['max_tokens'] : self::DEFAULT_MAX_TOKENS;

		$models = array();
		try {
			if ( class_exists( \OpenCodeConnector\Metadata\ModelAllowlist::class ) && method_exists( \OpenCodeConnector\Metadata\ModelAllowlist::class, 'all_models' ) ) {
				$models = \OpenCodeConnector\Metadata\ModelAllowlist::all_models();
			}
		} catch ( \Throwable $e ) {
			$models = array();
		}
		if ( ! is_array( $models ) ) {
			$models = array();
		}
		// Allow filtering of picker options; guarded for legacy installs.
		if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) ) {
			try {
				if ( has_filter( 'opencode_connector_default_model_options' ) ) {
					$filtered = apply_filters( 'opencode_connector_default_model_options', $models );
					if ( is_array( $filtered ) ) {
						$models = array_values( array_unique( array_filter( $filtered, 'is_string' ) ) );
					}
				}
			} catch ( \Throwable $e ) {
				// Fail-open: keep built-in options.
					unset( $e );
			}
		}
		$has_picker = array() !== $models;

		// Legacy WP fallback: static status line only when core helpers are missing.
		$wp_version_ok = true;
		try {
			if ( function_exists( 'get_bloginfo' ) && function_exists( 'version_compare' ) ) {
				$wp_version_ok = version_compare( (string) get_bloginfo( 'version' ), '7.0', '>=' );
			}
		} catch ( \Throwable $e ) {
			$wp_version_ok = true;
		}
		unset( $wp_version_ok );

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
		$option_name = \OpenCodeConnector\OPTION_NAME;
		$nonce       = '';
		if ( function_exists( 'wp_create_nonce' ) && class_exists( ConnectionTest::class ) ) {
			try {
				$nonce = wp_create_nonce( ConnectionTest::NONCE_ACTION );
			} catch ( \Throwable $e ) {
				$nonce = '';
			}
		}
		$can_test = true;
		if ( function_exists( 'current_user_can' ) ) {
			try {
				$can_test = current_user_can( 'manage_options' );
			} catch ( \Throwable $e ) {
				$can_test = true;
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
				<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Show all models', 'duoport-connect-for-opencode' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $option_name ); ?>[show_all_models]" value="1" <?php checked( ! empty( $opts['show_all_models'] ) ); ?> /> <?php esc_html_e( 'Show every model from the API (including non-chat models that may fail)', 'duoport-connect-for-opencode' ); ?></label></td>
				</tr>
				<tr>
					<th><label for="opencode-connector-default-model"><?php esc_html_e( 'Default model', 'duoport-connect-for-opencode' ); ?></label></th>
					<td>
					<?php if ( $has_picker ) : ?>
						<select id="opencode-connector-default-model" name="<?php echo esc_attr( $option_name ); ?>[default_model]">
							<option value=""><?php esc_html_e( 'Auto (caller decides)', 'duoport-connect-for-opencode' ); ?></option>
							<?php foreach ( $models as $model_id ) : ?>
								<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $default_model, $model_id ); ?>><?php echo esc_html( $model_id ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Used when a request does not specify a model. A caller-supplied model always wins.', 'duoport-connect-for-opencode' ); ?></p>
					<?php else : ?>
						<input type="text" id="opencode-connector-default-model" name="<?php echo esc_attr( $option_name ); ?>[default_model]" value="<?php echo esc_attr( $default_model ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Used when a request does not specify a model. Leave empty for auto.', 'duoport-connect-for-opencode' ); ?></p>
					<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="opencode-connector-temperature"><?php esc_html_e( 'Temperature', 'duoport-connect-for-opencode' ); ?></label></th>
					<td><input type="number" id="opencode-connector-temperature" name="<?php echo esc_attr( $option_name ); ?>[temperature]" value="<?php echo esc_attr( (string) $temperature ); ?>" step="0.1" min="0" max="2" />
						<p class="description"><?php esc_html_e( 'Sampling temperature, 0 to 2. Lower is more deterministic.', 'duoport-connect-for-opencode' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="opencode-connector-max-tokens"><?php esc_html_e( 'Max tokens', 'duoport-connect-for-opencode' ); ?></label></th>
					<td><input type="number" id="opencode-connector-max-tokens" name="<?php echo esc_attr( $option_name ); ?>[max_tokens]" value="<?php echo esc_attr( (string) $max_tokens ); ?>" step="1" min="1" max="8192" />
						<p class="description"><?php esc_html_e( 'Maximum tokens per response, 1 to 8192.', 'duoport-connect-for-opencode' ); ?></p></td>
				</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php if ( $can_test ) : ?>
			<h2><?php esc_html_e( 'Connection test', 'duoport-connect-for-opencode' ); ?></h2>
			<p>
				<button type="button" class="button" id="opencode-connector-test-go" data-catalog="go"><?php esc_html_e( 'Test Go connection', 'duoport-connect-for-opencode' ); ?></button>
				<button type="button" class="button" id="opencode-connector-test-zen" data-catalog="zen"><?php esc_html_e( 'Test Zen connection', 'duoport-connect-for-opencode' ); ?></button>
				<?php
				if ( function_exists( 'wp_nonce_field' ) && class_exists( ConnectionTest::class ) ) {
					wp_nonce_field( ConnectionTest::NONCE_ACTION, 'opencode_connector_test_nonce' );
				}
				?>
			</p>
			<p id="opencode-connector-test-result" aria-live="polite"></p>
			<script type="text/javascript">
			(function () {
				function verdictText( catalog, payload ) {
					if ( payload && payload[ catalog ] && payload[ catalog ].message ) {
						return payload[ catalog ].message;
					}
					return catalog + ': network error.';
				}
				function runTest( catalog, el ) {
					var nonceField = document.querySelector( '#opencode_connector_test_nonce' );
					var nonce = nonceField ? nonceField.value : '<?php echo esc_js( $nonce ); ?>';
					el.textContent = catalog + ': …';
					var params = new URLSearchParams();
					params.append( 'action', '<?php echo esc_js( class_exists( ConnectionTest::class ) ? ConnectionTest::AJAX_ACTION : 'opencode_connector_test' ); ?>' );
					params.append( 'catalog', catalog );
					params.append( 'nonce', nonce );
					fetch( ( typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php' ), {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
						body: params.toString()
					} ).then( function ( res ) { return res.json(); } ).then( function ( json ) {
						var data = json && json.data ? json.data : null;
						el.textContent = verdictText( catalog, data );
					} ).catch( function () {
						el.textContent = catalog + ': network error.';
					} );
				}
				document.querySelectorAll( '#opencode-connector-test-go, #opencode-connector-test-zen' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () { runTest( btn.getAttribute( 'data-catalog' ), btn ); } );
				} );
			})();
			</script>
			<?php endif; ?>
			<p><a href="https://opencode.ai/auth" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API key', 'duoport-connect-for-opencode' ); ?></a></p>
		</div>
		<?php
	}
}
