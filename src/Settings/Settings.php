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

use OpenCodeConnector\Availability\ConnectionDiagnostics;
use OpenCodeConnector\Availability\OpenCodeProviderAvailability;
use OpenCodeConnector\Availability\TransientCache;
use OpenCodeConnector\Metadata\Catalog;
use WordPress\AiClient\AiClient;

/**
 * Settings page and cache-bust handlers.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
final class Settings {
	/**
	 * Admin-post action and nonce name for the per-catalog recheck.
	 *
	 * One string serves both so a rename cannot desynchronise the form from
	 * the verifier.
	 *
	 * @since 0.1.10
	 */
	const RECHECK_ACTION = 'opencode_connector_recheck';

	/**
	 * Nonce name alias for the recheck form.
	 *
	 * @since 0.1.10
	 */
	const RECHECK_NONCE = self::RECHECK_ACTION;

	/**
	 * Cooldown transient key prefix, one per catalog.
	 *
	 * Deliberately not part of Catalog::allTransientKeys(): this is a
	 * presentation throttle, not a verdict a key rotation must invalidate.
	 *
	 * @since 0.1.10
	 */
	const RECHECK_COOLDOWN_PREFIX = 'opencode_connector_recheck_cooldown_';

	/**
	 * Seconds between accepted rechecks for one catalog.
	 *
	 * @since 0.1.10
	 */
	const RECHECK_COOLDOWN = 30;

	/**
	 * Alias spelling out the unit of RECHECK_COOLDOWN.
	 *
	 * @since 0.1.10
	 */
	const RECHECK_COOLDOWN_SECONDS = self::RECHECK_COOLDOWN;

	/**
	 * Last-checked timestamp prefix (owned by ConnectionReport).
	 *
	 * @since 0.1.10
	 */
	const LAST_CHECKED_PREFIX = ConnectionReport::LAST_CHECKED_PREFIX;

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
			add_action( 'admin_post_' . self::RECHECK_ACTION, array( $this, 'handleRecheck' ) );
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
			self::clearAvailabilityCaches();
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
		self::clearAvailabilityCaches();
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
		self::clearAvailabilityCaches();
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
	 * @param string|null $catalog Optional catalog slug; null clears both catalogs.
	 * @return void
	 */
	public static function clearAvailabilityCaches( ?string $catalog = null ): void {
		if ( null === $catalog ) {
			$catalogs = Catalog::ALL;
		} elseif ( Catalog::isValid( $catalog ) ) {
			$catalogs = array( $catalog );
		} else {
			// An unrecognised slug must not turn into an unscoped bust.
			$catalogs = array();
		}
		foreach ( $catalogs as $slug ) {
			foreach ( self::availabilityCacheKeys( $slug ) as $key ) {
				delete_transient( $key );
				if ( function_exists( 'delete_site_transient' ) ) {
					delete_site_transient( $key );
				}
			}
		}
	}

	/**
	 * Clear one catalog's stale availability verdicts, and only that catalog's.
	 *
	 * Same credential-blind delete path as clearAvailabilityCaches(), scoped
	 * to the slug the recheck handler validated. The last-known-good flag is
	 * deliberately absent: a recheck that fails must preserve it.
	 *
	 * @since 0.1.10
	 *
	 * @param string $catalog Catalog slug.
	 * @return void
	 */
	public static function clearAvailabilityCachesFor( string $catalog ): void {
		self::clearAvailabilityCaches( $catalog );
	}

	/**
	 * The transient keys a bust of one catalog may delete.
	 *
	 * @param string $catalog Catalog slug.
	 * @return list<string>
	 */
	private static function availabilityCacheKeys( string $catalog ): array {
		return array_merge(
			Catalog::availabilityResultKeys( $catalog ),
			Catalog::verifyKeys( $catalog )
		);
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
				$provider_id = \OpenCodeConnector\Metadata\Catalog::providerId( $catalog );
				if ( '' === $provider_id ) {
					continue;
				}
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
	 * Build the classified display record for both catalogs.
	 *
	 * Reads only the cached availability transients, so a warm verdict never
	 * costs another outbound probe. A catalog with no cached diagnosis keeps
	 * `checked = false` and is rendered through the legacy boolean fallback.
	 *
	 * @since 0.1.10
	 *
	 * @return array<string, array{state: string, checked: bool, checked_at: int, sentence: string, last_checked: string}>
	 */
	private function fetchConnectionReport(): array {
		$report = array();
		foreach ( Catalog::ALL as $catalog ) {
			$entry                 = ConnectionReport::forCatalog( $catalog );
			$entry['last_checked'] = ConnectionReport::formatLastChecked( $entry['checked_at'] );
			$report[ $catalog ]    = $entry;
		}
		return $report;
	}

	/**
	 * Handle a nonce-, capability-, and method-gated per-catalog recheck.
	 *
	 * Rejection happens before the cache bust and before any probe, so a GET
	 * request, an unprivileged caller, a bad nonce, or an unknown catalog can
	 * never force an outbound request. The accepted path clears only the
	 * selected catalog's availability transients through the same
	 * credential-blind delete helper used by the settings bust, then probes
	 * that catalog once and records the last-checked time.
	 *
	 * @since 0.1.10
	 *
	 * @return void
	 */
	public function handleRecheck(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to recheck the connection.', 'duoport-connect-for-opencode' ) );
		}
		if ( ! $this->isPostRequest() ) {
			wp_die( esc_html__( 'A connection recheck must be submitted as a POST request.', 'duoport-connect-for-opencode' ) );
		}
		check_admin_referer( self::RECHECK_ACTION );
		$catalog = $this->postedCatalog();
		if ( '' === $catalog ) {
			wp_die( esc_html__( 'The selected catalog is not valid.', 'duoport-connect-for-opencode' ) );
		}
		if ( $this->isRecheckCoolingDown( $catalog ) ) {
			$this->redirectToSettings();
			return;
		}
		$this->startRecheckCooldown( $catalog );
		self::clearAvailabilityCachesFor( $catalog );
		$this->runRecheck( $catalog );
		$this->recordLastChecked( $catalog );
		$this->redirectToSettings();
	}

	/**
	 * Alias for callers that use the WordPress-ish method spelling.
	 *
	 * @since 0.1.10
	 *
	 * @return void
	 */
	public function handle_recheck(): void {
		$this->handleRecheck();
	}

	/**
	 * Alias used by tests and future callers that name the action after its verb.
	 *
	 * @since 0.1.10
	 *
	 * @return void
	 */
	public function recheck(): void {
		$this->handleRecheck();
	}

	/**
	 * Whether the current request is a POST.
	 *
	 * Missing REQUEST_METHOD is treated as not-POST: an action that can spend
	 * a billed probe must never run on an ambiguous request.
	 *
	 * @return bool
	 */
	private function isPostRequest(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The request method is compared to a constant and never stored or rendered.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';
		return 'POST' === strtoupper( $method );
	}

	/**
	 * Read and strictly validate the posted catalog slug.
	 *
	 * @return string Catalog slug, or an empty string when absent/invalid.
	 */
	private function postedCatalog(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- check_admin_referer() runs before this method; the value is only compared against the Catalog allowlist.
		$catalog = isset( $_POST['catalog'] ) && is_string( $_POST['catalog'] ) ? $_POST['catalog'] : '';
		return Catalog::isValid( $catalog ) ? $catalog : '';
	}

	/**
	 * Whether the per-catalog recheck cooldown is still active.
	 *
	 * @param string $catalog Catalog slug.
	 * @return bool
	 */
	private function isRecheckCoolingDown( string $catalog ): bool {
		$stored = TransientCache::get( self::RECHECK_COOLDOWN_PREFIX . $catalog );
		if ( false === $stored || null === $stored ) {
			return false;
		}
		if ( is_bool( $stored ) ) {
			return $stored;
		}
		if ( is_array( $stored ) && isset( $stored['ts'] ) && is_numeric( $stored['ts'] ) ) {
			$stored = (int) $stored['ts'];
		}
		if ( is_numeric( $stored ) ) {
			$timestamp = (int) $stored;
			// Real timestamps get an explicit age check so a hidden/legacy
			// value cannot impose an unbounded cooldown. Small values keep
			// their "present means active" meaning.
			if ( $timestamp > 1000000000 ) {
				return ( time() - $timestamp ) < self::RECHECK_COOLDOWN;
			}
			return $timestamp > 0;
		}
		return true;
	}

	/**
	 * Start the per-catalog recheck cooldown.
	 *
	 * @param string $catalog Catalog slug.
	 * @return void
	 */
	private function startRecheckCooldown( string $catalog ): void {
		TransientCache::set( self::RECHECK_COOLDOWN_PREFIX . $catalog, time(), self::RECHECK_COOLDOWN );
	}

	/**
	 * Force one fresh classified probe for the selected catalog.
	 *
	 * Prefers the provider's availability instance (the same path the SDK
	 * uses) so tests and production share one classifier. Any missing SDK
	 * surface or throwable degrades to could-not-be-checked and writes that
	 * verdict, never a credential verdict, so last-known-good is preserved.
	 *
	 * @param string $catalog Catalog slug.
	 * @return void
	 */
	private function runRecheck( string $catalog ): void {
		$diagnosis = $this->probeCatalog( $catalog );
		$state     = isset( $diagnosis['state'] ) && is_string( $diagnosis['state'] ) ? $diagnosis['state'] : '';
		if ( '' === $state ) {
			$diagnosis = ( new ConnectionDiagnostics() )->uncheckable();
			$state     = 'uncheckable';
		}
		$diagnosis['checked_at'] = time();
		$ttl                     = $this->recheckTtl( $state );
		TransientCache::set( Catalog::AVAIL_PREFIX . $catalog, $diagnosis, $ttl );
	}

	/**
	 * Probe one catalog through its classified availability surface.
	 *
	 * @param string $catalog Catalog slug.
	 * @return array<string, mixed>
	 */
	private function probeCatalog( string $catalog ): array {
		$cls = Catalog::providerClassFor( $catalog );
		if ( '' === $cls ) {
			return array();
		}
		try {
			if ( is_callable( array( $cls, 'availability' ) ) ) {
				$availability = $cls::availability();
				if ( is_object( $availability ) && method_exists( $availability, 'diagnose' ) ) {
					$result = $availability->diagnose();
					return is_array( $result ) ? $result : array();
				}
			}
		} catch ( \Throwable $provider_exception ) {
			unset( $provider_exception );
			// A half-installed SDK must fall through to the direct helper, not
			// turn an admin recheck into a fatal error.
		}
		try {
			if ( class_exists( OpenCodeProviderAvailability::class ) ) {
				return ( new OpenCodeProviderAvailability( $catalog ) )->diagnose();
			}
		} catch ( \Throwable $direct_exception ) {
			unset( $direct_exception );
			// The caller degrades an empty result to could-not-be-checked.
		}
		return array();
	}

	/**
	 * Cache window matching the availability probe's state policy.
	 *
	 * @param string $state Classified state name.
	 * @return int
	 */
	private function recheckTtl( string $state ): int {
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		if ( ConnectionDiagnostics::isUncheckableState( $state ) && ! ConnectionDiagnostics::isPersistentUncheckableState( $state ) ) {
			return TransientCache::jitteredShortTtl();
		}
		return TransientCache::jitteredTtl( 5 * $minute );
	}

	/**
	 * Record the last-checked timestamp for display.
	 *
	 * @param string $catalog Catalog slug.
	 * @return void
	 */
	private function recordLastChecked( string $catalog ): void {
		TransientCache::set( self::LAST_CHECKED_PREFIX . $catalog, time(), ConnectionReport::LAST_CHECKED_TTL );
	}

	/**
	 * Redirect back to this Settings page after a recheck.
	 *
	 * @return void
	 */
	private function redirectToSettings(): void {
		wp_safe_redirect( admin_url( 'options-general.php?page=duoport-connect-for-opencode' ) );
		exit;
	}

	/**
	 * Render settings page.
	 *
	 * Capability is enforced at the callback, not only at the registration
	 * site. `menu()` already passes `manage_options` to `add_options_page()`,
	 * and core's `user_can_access_admin_page()` enforces it, so today this is
	 * defence in depth rather than a live bypass. It is worth having at the
	 * method anyway: `render()` calls `fetchProviderStatus()`, which drives the
	 * registry into `isProviderConfigured()` and can therefore trigger a real
	 * outbound probe to the OpenCode gateway. That effect belongs behind the
	 * capability check, not behind a menu registration some future caller
	 * might not go through — the same pattern the plugin entry file already
	 * uses for its own admin output.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'duoport-connect-for-opencode' ) );
		}
		$opts     = get_option( \OpenCodeConnector\OPTION_NAME, array( 'show_all_models' => false ) );
		$report   = $this->fetchConnectionReport();
		$has_miss = false;
		foreach ( Catalog::ALL as $catalog ) {
			if ( empty( $report[ $catalog ]['checked'] ) ) {
				$has_miss = true;
			}
		}
		// Only a cache miss needs the legacy boolean registry probe. A warm
		// classified verdict is rendered without any outbound request.
		$status       = $has_miss ? $this->fetchProviderStatus() : array();
		$descriptions = array();
		$last_checked = array();
		foreach ( Catalog::ALL as $catalog ) {
			if ( ! empty( $report[ $catalog ]['checked'] ) ) {
				$descriptions[ $catalog ] = (string) $report[ $catalog ]['sentence'];
			} else {
				$connected                = ! empty( $status[ $catalog ] );
				$descriptions[ $catalog ] = $connected
					? __( 'connected (not checked yet)', 'duoport-connect-for-opencode' )
					: __( 'not connected (not checked yet)', 'duoport-connect-for-opencode' );
			}
			$last_checked[ $catalog ] = '' !== (string) $report[ $catalog ]['last_checked']
				? (string) $report[ $catalog ]['last_checked']
				: __( 'not checked yet', 'duoport-connect-for-opencode' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DuoPort Connector', 'duoport-connect-for-opencode' ); ?></h1>
			<p>
			<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: URL to Settings → Connectors screen */
						__( 'Enter your API key in both the Go and Zen fields on <a href="%s">Settings → Connectors</a>. In most cases, the same opencode.ai key works for both catalogs.', 'duoport-connect-for-opencode' ),
						esc_url( admin_url( 'options-connectors.php' ) )
					)
				);
			?>
				</p>
			<p><?php esc_html_e( 'Go: subscription catalog. Zen: pay-as-you-go catalog including free models (no credit cost, still needs a key).', 'duoport-connect-for-opencode' ); ?></p>
			<p>
			<?php
				/* translators: 1: Go connection status, 2: Zen connection status */
				printf( esc_html__( 'Go: %1$s · Zen: %2$s', 'duoport-connect-for-opencode' ), esc_html( $descriptions[ Catalog::GO ] ), esc_html( $descriptions[ Catalog::ZEN ] ) );
			?>
			</p>
			<p>
			<?php
				/* translators: 1: Go last-checked time, 2: Zen last-checked time */
				printf( esc_html__( 'Last checked: Go: %1$s · Zen: %2$s', 'duoport-connect-for-opencode' ), esc_html( $last_checked[ Catalog::GO ] ), esc_html( $last_checked[ Catalog::ZEN ] ) );
			?>
			</p>
			<div class="opencode-connector-recheck">
				<?php foreach ( Catalog::ALL as $catalog ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::RECHECK_ACTION ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RECHECK_ACTION ); ?>" />
					<input type="hidden" name="catalog" value="<?php echo esc_attr( $catalog ); ?>" />
					<button type="submit" class="button">
					<?php
					/* translators: %s: catalog name (Go or Zen) */
					printf( esc_html__( 'Recheck %s', 'duoport-connect-for-opencode' ), esc_html( ucfirst( $catalog ) ) );
					?>
					</button>
				</form>
				<?php endforeach; ?>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( 'opencode_connector' ); ?>
				<table class="form-table"><tr>
					<th><?php esc_html_e( 'Show all models', 'duoport-connect-for-opencode' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( \OpenCodeConnector\OPTION_NAME ); ?>[show_all_models]" value="1" <?php checked( ! empty( $opts['show_all_models'] ) ); ?> /> <?php esc_html_e( 'Show every model from the API (including non-chat models that may fail)', 'duoport-connect-for-opencode' ); ?></label></td>
				</tr></table>
				<?php submit_button(); ?>
			</form>
			<p><a href="<?php echo esc_url( \OpenCodeConnector\Metadata\Catalog::AUTH_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API key', 'duoport-connect-for-opencode' ); ?></a></p>
		</div>
		<?php
	}
}
