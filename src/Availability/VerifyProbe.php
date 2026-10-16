<?php
/**
 * Verification probe orchestration.
 *
 * Verify half of the OpenCodeProviderAvailability split: the explicit
 * one-token generation probe for genuine credential verification lives
 * here so edits to the lightweight availability probe never share a file
 * with it. The facade keeps the boolean contract, the diagnostics seam,
 * and the flag seam; this class owns the verification verdict cache.
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
 * Runs the explicit credential-verification probe for one catalog.
 *
 * Separate from the lightweight availability probe: sends a minimal
 * `chat/completions` request (`max_tokens: 1`) and maps the outcome to
 * one of `valid`, `invalid_key`, or `could-not-be-checked`. The verdict
 * is cached about five minutes and never contains key material.
 * Fail-open: verification failures degrade to `could-not-be-checked`,
 * never fatal, and never block chat. Configured does not equal verified.
 *
 * @package OpenCodeConnector
 * @since 0.1.9
 */
final class VerifyProbe {
	/**
	 * Build a verification probe for one catalog.
	 *
	 * @since 0.1.9
	 *
	 * @param string                     $catalog         Catalog slug.
	 * @param ConnectionDiagnostics|null $diagnostics     Diagnostics helper, if available.
	 * @param \Closure                   $resolve_auth    Resolves the request authentication (may throw).
	 * @param \Closure                   $send            Sends a Request, returning the SDK response (may throw).
	 * @param \Closure                   $missing_credential Predicate over a credential-resolution throwable.
	 */
	public function __construct(
		private readonly string $catalog,
		private readonly ?ConnectionDiagnostics $diagnostics,
		private readonly \Closure $resolve_auth,
		private readonly \Closure $send,
		private readonly \Closure $missing_credential
	) {}

	/**
	 * Run the verification round-trip, reusing the cached verdict when fresh.
	 *
	 * @since 0.1.9
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$tkey     = Catalog::VERIFY_PREFIX . $this->catalog;
		$lock_key = $tkey . '_lock';

		$early = $this->readVerifyCache( $tkey, $lock_key );
		if ( null !== $early ) {
			return $early;
		}

		try {
			( $this->resolve_auth )();
		} catch ( \Throwable $exception ) {
			// Same split as the availability probe: only a PROVEN absent
			// credential is an `invalid_key` verdict. Any other throwable
			// here is an infrastructure fault — a throwing filter, an
			// unregistered Connectors registry, a broken SDK bootstrap — and
			// caching it as `invalid_key` for five minutes renders that fault
			// on the settings page as a credential verdict the plugin never
			// actually received.
			$verdict = $this->verifyResult(
				( $this->missing_credential )( $exception ) ? 'invalid_key' : 'could-not-be-checked',
				null
			);
			$this->storeVerifyVerdict( $tkey, $lock_key, $verdict );
			return $verdict;
		}

		TransientCache::set( $lock_key, 1, 10 );

		// An unrecognised catalog fails closed instead of silently probing as
		// the Zen catalog: the mapping lives in Catalog::providerClassFor()
		// so a future third catalog cannot fall through to a wrong branch.
		$cls = Catalog::providerClassFor( $this->catalog );
		if ( '' === $cls ) {
			$verdict = $this->verifyResult( 'invalid_key', null );
			$this->storeVerifyVerdict( $tkey, $lock_key, $verdict );
			return $verdict;
		}

		$diagnosis = $this->sendVerifyProbe( $cls );

		$state = null !== $this->diagnostics && null !== $diagnosis ? $this->diagnostics->verify_state( $diagnosis ) : 'could-not-be-checked';
		// Explicit fail-open: transport/server/unknown outcomes already map to
		// `could-not-be-checked` via verify_state(); never fatal here.
		$verdict = $this->verifyResult( $state, $diagnosis );
		$this->storeVerifyVerdict( $tkey, $lock_key, $verdict );
		return $verdict;
	}

	/**
	 * Read a cached verification verdict or stampede-lock signal.
	 *
	 * Returns the verdict to report immediately (a cached verdict, or a
	 * `could-not-be-checked` verdict when a concurrent probe holds the
	 * lock), or null when no cache entry applies and the caller should
	 * proceed to probe. Never throws; an unreadable cache is a miss.
	 *
	 * @since 0.1.9
	 *
	 * @param string $tkey     Verdict transient key.
	 * @param string $lock_key Lock transient key.
	 * @return array<string, mixed>|null Verdict to return, or null to proceed.
	 */
	private function readVerifyCache( string $tkey, string $lock_key ): ?array {
		$cached = TransientCache::get( $tkey );
		if ( is_array( $cached ) && isset( $cached['state'] ) && is_string( $cached['state'] ) ) {
			return $cached;
		}
		if ( false !== TransientCache::get( $lock_key ) ) {
			return $this->verifyResult( 'could-not-be-checked', null );
		}
		return null;
	}

	/**
	 * Send the verification HTTP round-trip and classify the outcome.
	 *
	 * Flat guard-clause shape: a surface failure classifies without
	 * sending, a transport failure classifies as uncheckable, and a
	 * classifier failure degrades to null so the caller fails open. Never
	 * throws.
	 *
	 * @since 0.1.9
	 *
	 * @param string $cls Provider class FQCN.
	 * @return array<string, mixed>|null Safe diagnosis, or null when none exists.
	 */
	private function sendVerifyProbe( string $cls ): ?array {
		$diagnostics = $this->diagnostics;
		if ( ! ProbeRequest::surfaceAvailable( $cls ) ) {
			if ( null === $diagnostics ) {
				return null;
			}
			try {
				return $diagnostics->classify( 0, null, new \RuntimeException( 'verify surface unavailable' ) );
			} catch ( \Throwable $surface_exception ) {
				unset( $surface_exception );
				return null;
			}
		}
		try {
			$probe_data    = ProbeRequest::payload( OpenCodeProviderAvailability::PROBE_MODEL );
			$probe_headers = ProbeRequest::headers( $this->catalog, $probe_data );
			$req           = new Request(
				HttpMethodEnum::POST(),
				$cls::url( 'chat/completions' ),
				$probe_headers,
				$probe_data
			);
			$auth          = ( $this->resolve_auth )();
			$req           = $auth->authenticateRequest( $req );
			$res           = ( $this->send )( $req );
			return null !== $diagnostics ? $diagnostics->classify( $res->getStatusCode(), $res->getData(), null, OpenCodeProviderAvailability::PROBE_MODEL ) : null;
		} catch ( \Throwable $exception ) {
			unset( $exception );
			if ( null === $diagnostics ) {
				return null;
			}
			try {
				return $diagnostics->classify( 0, null, new \RuntimeException( 'verify transport failure' ) );
			} catch ( \Throwable $classify_exception ) {
				unset( $classify_exception );
				return null;
			}
		}
	}

	/**
	 * Build a credential-blind verification verdict.
	 *
	 * Stores only the safe state plus the safe diagnosis; never request
	 * headers, payloads, or key material.
	 *
	 * A null diagnosis means no response exists, and it therefore projects
	 * `uncheckable()`, never `unknown()`. `unknown()` carries
	 * `verified = true`, which asserts the backend was reached and identified —
	 * and every path that arrives here with no diagnosis reached nothing: a
	 * concurrent probe holds the stampede lock, the SDK's request surface is
	 * unavailable, or the classifier itself threw. Substituting `unknown()`
	 * there claimed a request had arrived at OpenCode and been understood. It
	 * had not been sent. `uncheckable()` is the verdict that means exactly this,
	 * and it reports `verified = false`.
	 *
	 * `invalid_key` is the one branch that keeps a verdict of its own: the
	 * probe never got as far as sending because the credential was PROVEN
	 * absent, which is a statement about the key rather than about reach. A
	 * credential-resolution fault that proves nothing takes
	 * `could-not-be-checked` instead and lands on `uncheckable()` here.
	 *
	 * @since 0.1.9
	 *
	 * @param string                   $state     Verification state.
	 * @param array<string,mixed>|null $diagnosis Safe diagnosis, if any.
	 * @return array<string, mixed>
	 */
	private function verifyResult( string $state, ?array $diagnosis ): array {
		$diagnostics = $this->diagnostics;
		if ( null === $diagnosis ) {
			if ( null !== $diagnostics ) {
				try {
					$diagnosis = 'invalid_key' === $state
						? $diagnostics->notConfigured()
						: $diagnostics->uncheckable();
				} catch ( \Throwable $verdict_exception ) {
					unset( $verdict_exception );
					// The helper itself could not be called. This is
					// belt-and-braces — its factories are pure array literals —
					// but the fallback must not reintroduce the claim this method
					// was fixed to stop making, so it carries the same verdict
					// name rather than the "reached and identified" one.
					$diagnosis = array( 'state' => 'uncheckable' );
				}
			} else {
				$diagnosis = array( 'state' => 'uncheckable' );
			}
		}
		return array(
			'state'     => $state,
			'diagnosis' => $diagnosis,
			'catalog'   => $this->catalog,
		);
	}

	/**
	 * Cache a verification verdict for about five minutes.
	 *
	 * @since 0.1.9
	 *
	 * @param string               $tkey    Transient key.
	 * @param string               $lock_key Lock transient key.
	 * @param array<string, mixed> $verdict Safe verdict.
	 * @return void
	 */
	private function storeVerifyVerdict( string $tkey, string $lock_key, array $verdict ): void {
		TransientCache::delete( $lock_key );
		$base = defined( 'MINUTE_IN_SECONDS' ) ? 5 * MINUTE_IN_SECONDS : 300;
		TransientCache::set( $tkey, $verdict, TransientCache::jitteredTtl( (int) $base ) );
	}
}
