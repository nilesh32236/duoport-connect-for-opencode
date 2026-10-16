<?php
/**
 * Availability probe orchestration.
 *
 * Probe half of the OpenCodeProviderAvailability split: the lightweight
 * availability round-trip (cache read, credential resolution, HTTP send,
 * result persistence, last-known-good application) lives here so edits to
 * the verification probe or the fail-open policy never share a file with
 * it. The facade keeps the boolean contract, the diagnostics seam, and
 * the flag seam; this class owns the transient/cache semantics.
 *
 * @package OpenCodeConnector
 * @since 0.1.9
 */

declare(strict_types=1);

namespace OpenCodeConnector\Availability;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Metadata\Catalog;

/**
 * Runs the lightweight availability probe for one catalog.
 *
 * Never throws: transport, construction, and classifier faults classify
 * as uncheckable so the stampede-lock release always runs and the caller
 * can fail open on last-known-good.
 *
 * @package OpenCodeConnector
 * @since 0.1.9
 */
final class AvailabilityProbe {
	/**
	 * Build a probe for one catalog.
	 *
	 * @since 0.1.9
	 *
	 * @param string                $catalog         Catalog slug.
	 * @param ConnectionDiagnostics $diagnostics     Diagnostics helper.
	 * @param \Closure              $resolve_auth    Resolves the request authentication (may throw).
	 * @param \Closure              $send            Sends a Request, returning the SDK response (may throw).
	 * @param LastGoodStore         $last_good       Flag store for this catalog.
	 * @param \Closure              $missing_credential Predicate over a credential-resolution throwable.
	 */
	public function __construct(
		private readonly string $catalog,
		private readonly ConnectionDiagnostics $diagnostics,
		private readonly \Closure $resolve_auth,
		private readonly \Closure $send,
		private readonly LastGoodStore $last_good,
		private readonly \Closure $missing_credential
	) {}

	/**
	 * Probe, classify, and briefly cache the safe result.
	 *
	 * @since 0.1.9
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$tkey     = Catalog::AVAIL_PREFIX . $this->catalog;
		$lock_key = $tkey . '_lock';
		$early    = $this->readProbeCache( $tkey, $lock_key );
		if ( null !== $early ) {
			return $early;
		}

		$credential = $this->resolveCredentialOrUncheckable();
		if ( is_array( $credential ) ) {
			return $credential;
		}

		// Resolve the catalog BEFORE taking the stampede lock so an
		// unrecognised slug cannot leak the lock for its full 10s TTL.
		// An unrecognised catalog fails closed instead of silently probing as
		// the Zen catalog; see verify() for why the mapping has one home.
		$cls = Catalog::providerClassFor( $this->catalog );
		if ( '' === $cls ) {
			$result = $this->diagnostics->notConfigured();
			$this->writeLastGood( false );
			return $result;
		}

		// Set lock before network I/O (10s).
		TransientCache::set( $lock_key, 1, 10 );

		$result = $this->sendAvailabilityProbe( $cls, $credential );
		TransientCache::delete( $lock_key );
		$state = isset( $result['state'] ) && is_string( $result['state'] ) ? $result['state'] : '';
		$this->persistProbeResult( $tkey, $state, $result );
		return $result;
	}

	/**
	 * Resolve the request credential, or return an early verdict.
	 *
	 * Returns the resolved authenticator when the caller should proceed to
	 * probe, or an early verdict array otherwise. A proven-absent
	 * credential reports not-configured (and clears last-known-good); any
	 * other resolution fault degrades to could-not-be-checked and leaves
	 * the flag alone. Never throws.
	 *
	 * The authenticator is returned rather than discarded so the send does
	 * not resolve the credential a second time.
	 *
	 * @since 0.1.9
	 *
	 * @return object|array<string, mixed> Resolved authenticator, or early verdict.
	 */
	private function resolveCredentialOrUncheckable(): object|array {
		try {
			return ( $this->resolve_auth )();
		} catch ( \Throwable $exception ) {
			if ( ! ( $this->missing_credential )( $exception ) ) {
				return $this->diagnostics->uncheckable();
			}
			$result = $this->diagnostics->notConfigured();
			$this->writeLastGood( false );
			return $result;
		}
	}

	/**
	 * Send the availability HTTP round-trip and classify the outcome.
	 *
	 * Probe models are chosen to discriminate AUTHENTICATION, not model
	 * availability: paid models answer 401 CreditsError for a valid but
	 * empty-balance key (configured) versus other 401s for a bad key.
	 * Never throws; transport and construction faults classify as
	 * uncheckable so the stampede-lock release in run() always runs.
	 *
	 * @since 0.1.9
	 *
	 * @param string $cls  Provider class FQCN.
	 * @param object $auth Resolved request authentication.
	 * @return array<string, mixed> Safe diagnosis.
	 */
	private function sendAvailabilityProbe( string $cls, object $auth ): array {
		try {
			$res  = ProbeRequest::send( $this->catalog, $cls, $auth, $this->send );
			$data = $res->getData();
			return $this->diagnostics->classify( $res->getStatusCode(), is_array( $data ) ? $data : null, null, OpenCodeProviderAvailability::PROBE_MODEL );
		} catch ( \Throwable $exception ) {
			// A classifier fault here must not escape run() and skip the
			// stampede-lock release, so the fallback is a local verdict.
			try {
				return $this->diagnostics->classify( 0, null, $exception );
			} catch ( \Throwable $classify_exception ) {
				unset( $classify_exception );
				return $this->diagnostics->uncheckable();
			}
		}
	}

	/**
	 * Read a cached probe result or stampede-lock signal.
	 *
	 * Returns the result to report immediately (a cached verdict with its
	 * legacy boolean-cache projection, or a could-not-be-checked verdict
	 * when a concurrent probe holds the lock), or null when no cache entry
	 * applies and the caller should proceed to probe.
	 *
	 * @since 0.1.9
	 *
	 * @param string $tkey     Probe result transient key.
	 * @param string $lock_key Stampede-lock transient key.
	 * @return array<string, mixed>|null Result to return, or null to proceed.
	 */
	private function readProbeCache( string $tkey, string $lock_key ): ?array {
		$cached = TransientCache::get( $tkey );
		if ( is_array( $cached ) && isset( $cached['state'] ) && is_string( $cached['state'] ) ) {
			return $cached;
		}
		if ( true === $cached ) {
			// Legacy boolean-true cache entry. A false miss sentinel falls
			// through to the lock check and then a fresh probe.
			return $this->diagnostics->verified();
		}
		if ( false !== TransientCache::get( $lock_key ) ) {
			// Concurrent probe: surface could-not-be-checked so isConfigured()
			// can fail open on last-known-good instead of flipping to false.
			return $this->diagnostics->uncheckable();
		}
		return null;
	}

	/**
	 * Write a probe outcome to the cache and the last-known-good flag.
	 *
	 * The safety-critical write-back policy, kept behind the transient
	 * reads and the HTTP round-trip so future edits to those cannot land
	 * inside it: could-not-be-checked verdicts never touch last-known-good
	 * (they only refresh their cache window), definitive keyed outcomes
	 * refresh it, and only definitive negatives clear it (via the flag
	 * store). Membership is read from the published
	 * ConnectionDiagnostics helpers rather than restated lists.
	 *
	 * Could-not-be-checked (5xx, transport failure, concurrent probe, and
	 * any unrecognised response): never write the failure to
	 * last-known-good. The verdict is cached so a persistent condition costs
	 * one probe per window instead of one per call, while isConfigured()
	 * keeps failing open.
	 *
	 * The two kinds of could-not-be-checked get different windows, and the
	 * reason is not only cost. A transport failure or a 5xx resolves on its
	 * own, so the one-minute window notices a recovered gateway quickly.
	 * `unknown` is not transient: the gateway answered with something this
	 * plugin has no rule for, and that does not resolve itself in a minute —
	 * a 404 after an upstream model rename is the standing case.
	 * `probe_model_unavailable` is the same shape for the same reason: the
	 * gateway has said the model this plugin probes with is gone, and it will
	 * still be gone in a minute.
	 *
	 * Both also take the full jittered window because the one-minute branch
	 * has no jitter at all, so routing them there would make every site whose
	 * upstream answered that way re-probe in lockstep on the same 60-second
	 * boundary — precisely the synchronized stampede the jitter exists to
	 * prevent. Membership is read from the published helpers, so adding a
	 * third persistent state later cannot land on the short branch by being
	 * forgotten here. A persistent verdict must NOT reach the
	 * last-known-good writes — neither state proves anything about the key,
	 * and writing false there would reinstate the exact production bug the
	 * persistent window exists to fix.
	 *
	 * Definitive keyed outcomes refresh last-known-good. A Zen free-tier
	 * quota stop proves the key is valid, so it counts as a good result.
	 *
	 * @since 0.1.9
	 *
	 * @param string               $tkey   Probe result transient key.
	 * @param string               $state  Classified state name.
	 * @param array<string, mixed> $result Classified result to cache.
	 * @return void
	 */
	private function persistProbeResult( string $tkey, string $state, array $result ): void {
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
		if ( ConnectionDiagnostics::isUncheckableState( $state ) ) {
			if ( ! ConnectionDiagnostics::isPersistentUncheckableState( $state ) ) {
				// Jittered 45-75s, not a fixed 60s: a fixed window makes every
				// site whose upstream is failing the same way re-probe in
				// lockstep on the same boundary.
				TransientCache::set( $tkey, $result, TransientCache::jitteredShortTtl() );
				return;
			}
			// Persistent: the longer jittered window, and NOT the
			// last-known-good writes — neither state proves anything about the
			// key, and writing false there would reinstate the exact production
			// bug the persistent window exists to fix.
			TransientCache::set( $tkey, $result, TransientCache::jitteredTtl( 5 * $minute ) );
			return;
		}
		$this->applyLastGood( $state );
		// Stagger expiry ±60s to avoid synchronized stampedes.
		TransientCache::set( $tkey, $result, TransientCache::jitteredTtl( 5 * $minute ) );
	}

	/**
	 * Apply a classified verdict to the last-known-good flag.
	 *
	 * Three outcomes, not two: arm, clear, or leave alone. Only a state in
	 * DEFINITIVE_NEGATIVE_STATES may clear it, because only those are a
	 * proven verdict about the credential. Everything else has proved
	 * nothing, and proving nothing must not cost a site its connection.
	 *
	 * @since 0.1.9
	 *
	 * @param string $state Classified state name.
	 * @return void
	 */
	private function applyLastGood( string $state ): void {
		// apply() classifies the state once and updates the store memo, so
		// the facade's read-through sees the same decision.
		$this->last_good->apply( $state );
	}

	/**
	 * Record or clear the last-known-good configured flag for this catalog.
	 *
	 * THE WINDOW IS ROLLING AND MUST STAY ROLLING. Every keyed success
	 * re-writes the flag and pushes its 30-day TTL forward. Do not "optimise"
	 * the redundant write away — that was tried and reverted, and the
	 * optimisation is a bug.
	 *
	 * Why it must roll. This flag is the entire mechanism by which a working
	 * key keeps looking working when the gateway answers something the plugin
	 * cannot interpret. If the window were absolute — set once and left to
	 * count down — then a site whose last confirmed good probe was on day 1
	 * and whose upstream starts answering unrecognisably on day 29 loses its
	 * fallback on day 31, and `isConfigured()` starts reporting that valid key
	 * as not connected again. That is not a subtler version of the fix; it is
	 * the original defect returning on a 30-day horizon, with a good key and no
	 * upstream error to explain it.
	 *
	 * Rolling is what makes the guarantee hold for as long as the credential
	 * keeps working: each keyed success says "still good, today", so the
	 * fallback cannot lapse while the key is genuinely healthy, and it starts
	 * counting down only from the last real confirmation.
	 *
	 * The cost is one options-row UPDATE per keyed probe, about every five
	 * minutes per catalog for the life of the install — and that figure is
	 * conditional, not universal. WP core's `set_transient()` branches on
	 * `wp_using_ext_object_cache()`: on a site with a persistent object cache
	 * (Redis, Memcached) it returns through `wp_cache_set()` and writes no
	 * options row at all, so the database cost is zero there. Only the default
	 * path, which writes `_transient_*` rows with a raw UPDATE and no equality
	 * short-circuit, pays it.
	 *
	 * The decision does not depend on the cost. Rolling is required for
	 * correctness; the price is what it happens to be on the sites that pay it.
	 * That write is the accepted price of the guarantee above, not an
	 * oversight. If it ever needs to be avoided, the fix belongs at the storage
	 * layer (an equality check that still extends the TTL), never at the cost of
	 * the rolling behaviour.
	 *
	 * Clearing is unaffected: the first definitive negative deletes the flag
	 * outright and immediately, so a genuinely revoked key is never held open
	 * by any of this.
	 *
	 * The stored shape is `array( 'v' => '1', 'ts' => time() )`, still
	 * credential-blind. The string sentinel (not int `1`) keeps the stored
	 * type stable across the database round-trip; it does not skip the
	 * value-row write, because `ts` changes on every keyed success so the
	 * serialised value is never equal — both the value row and the timeout
	 * row move on each rewrite, and that rewrite is the rolling behaviour
	 * above, not a pointless write. The timestamp is what the read side
	 * bounds by LastGoodStore::MAX_AGE (timestamped shapes only; pre-timestamp
	 * int/string shapes read as armed until a keyed success upgrades them),
	 * so a flag that has not been confirmed since before a `wp-config.php` or
	 * environment rotation stops failing open.
	 *
	 * @since 0.1.9
	 *
	 * @param bool $good Whether a keyed probe just succeeded.
	 * @return void
	 */
	private function writeLastGood( bool $good ): void {
		$this->last_good->write( $good );
	}
}
