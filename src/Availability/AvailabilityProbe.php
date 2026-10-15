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
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

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
	 * Flag memo delta applied by the last run, if any.
	 *
	 * Mirrors the facade's per-instance memo so a flag write followed by
	 * a read in the same request never goes back to storage. Null when
	 * the run touched nothing (cache hit, uncheckable verdict).
	 *
	 * @var bool|null
	 */
	private ?bool $flag_memo = null;

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
	 * Flag memo delta applied by the last run, if any.
	 *
	 * @since 0.1.9
	 *
	 * @return bool|null True when armed, false when cleared, null when untouched.
	 */
	public function flagMemo(): ?bool {
		return $this->flag_memo;
	}

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
		if ( null !== $credential ) {
			return $credential;
		}

		// Set lock before network I/O (10s).
		TransientCache::set( $lock_key, 1, 10 );

		// An unrecognised catalog fails closed instead of silently probing as
		// the Zen catalog; see verify() for why the mapping has one home.
		$cls = Catalog::providerClassFor( $this->catalog );
		if ( '' === $cls ) {
			$result = $this->diagnostics->notConfigured();
			$this->writeLastGood( false );
			return $result;
		}
		$result = $this->sendAvailabilityProbe( $cls );
		TransientCache::delete( $lock_key );
		$state = isset( $result['state'] ) && is_string( $result['state'] ) ? $result['state'] : '';
		$this->persistProbeResult( $tkey, $state, $result );
		return $result;
	}

	/**
	 * Resolve the request credential, or return an early verdict.
	 *
	 * Returns null when the credential resolved and the caller should
	 * proceed to probe. A proven-absent credential reports not-configured
	 * (and clears last-known-good); any other resolution fault degrades to
	 * could-not-be-checked and leaves the flag alone. Never throws.
	 *
	 * @since 0.1.9
	 *
	 * @return array<string, mixed>|null Early verdict, or null to proceed.
	 */
	private function resolveCredentialOrUncheckable(): ?array {
		try {
			( $this->resolve_auth )();
		} catch ( \Throwable $exception ) {
			if ( ! ( $this->missing_credential )( $exception ) ) {
				return $this->diagnostics->uncheckable();
			}
			$result = $this->diagnostics->notConfigured();
			$this->writeLastGood( false );
			return $result;
		}
		return null;
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
	 * @param string $cls Provider class FQCN.
	 * @return array<string, mixed> Safe diagnosis.
	 */
	private function sendAvailabilityProbe( string $cls ): array {
		$probe_model   = OpenCodeProviderAvailability::PROBE_MODEL;
		$probe_data    = ProbeRequest::payload( $probe_model );
		$probe_headers = ProbeRequest::headers( $this->catalog, $probe_data );
		try {
			// Construction belongs INSIDE the try. `$cls::url()` is a static call
			// on the provider class and HttpMethodEnum::POST() is a magic factory
			// served by __callStatic, so a broken or half-installed SDK throws
			// \Error or \BadMethodCallException here rather than returning a value
			// that could be checked.
			$req  = new Request(
				HttpMethodEnum::POST(),
				$cls::url( 'chat/completions' ),
				$probe_headers,
				$probe_data
			);
			$auth = ( $this->resolve_auth )();
			$req  = $auth->authenticateRequest( $req );
			$res  = ( $this->send )( $req );
			$data = $res->getData();
			return $this->diagnostics->classify( $res->getStatusCode(), is_array( $data ) ? $data : null, null, $probe_model );
		} catch ( \Throwable $exception ) {
			return $this->diagnostics->classify( 0, null, $exception );
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
		if ( is_array( $cached ) && isset( $cached['state'] ) ) {
			return $cached;
		}
		if ( false !== $cached && is_bool( $cached ) ) {
			return $cached ? $this->diagnostics->verified() : $this->diagnostics->notConfigured();
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
			$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
			TransientCache::set( $tkey, $result, TransientCache::jitteredTtl( 5 * $minute ) );
			return;
		}
		$this->applyLastGood( $state );
		// Stagger expiry ±60s to avoid synchronized stampedes.
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
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
		$this->last_good->apply( $state );
		// Keep the facade memo in step: apply() may arm or clear the store,
		// and a stale memo would otherwise disagree with it.
		if ( ConnectionDiagnostics::isConfiguredState( $state ) ) {
			$this->flag_memo = true;
			return;
		}
		if ( ConnectionDiagnostics::isDefinitiveNegativeState( $state ) ) {
			$this->flag_memo = false;
		}
	}

	/**
	 * Record or clear the last-known-good flag, tracking the memo delta.
	 *
	 * @since 0.1.9
	 *
	 * @param bool $good Whether a keyed probe just succeeded.
	 * @return void
	 */
	private function writeLastGood( bool $good ): void {
		$this->last_good->write( $good );
		$this->flag_memo = $good;
	}
}
