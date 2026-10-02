<?php
/**
 * Fail-open-by-omission specs.
 *
 * The guard against the next person adding a state and breaking the
 * fallthrough. An unbucketed state — one that appears in no bucket at all — must
 * behave exactly like a could-not-be-checked verdict: fall back to
 * last-known-good, never clear it, and never render as a plain "connected".
 *
 * Such a state is not hypothetical. It reaches production through the verdict
 * cache: a value written by a version that had a state we have since removed, or
 * a state added ahead of the bucket edit that describes it.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Availability\ConnectionDiagnostics;
	use OpenCodeConnector\Settings\Settings;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;

	/**
	 * Verdict for a state no code path in this repository produces.
	 */
	final class UnbucketedVerdict {
		/**
		 * The state, deliberately outside every bucket.
		 *
		 * @var string
		 */
		public const STATE = 'quota_policy_pending';

		/**
		 * Build a cached verdict carrying that state.
		 *
		 * @return array<string, mixed>
		 */
		public static function cached(): array {
			return array(
				'state'      => self::STATE,
				'configured' => true,
				'verified'   => false,
				'usable'     => false,
				'status'     => 451,
				'code'       => self::STATE,
			);
		}
	}

	/**
	 * A state in no bucket at all must fail open.
	 */
	final class UnbucketedStateTest extends MonkeyTestCase {

		/**
		 * Boot the probe surface.
		 *
		 * @return void
		 */
		private function boot(): void {
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
			if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
				define( 'MINUTE_IN_SECONDS', 60 );
			}
		}

		/**
		 * Prove the state really is unbucketed, or this test proves nothing.
		 *
		 * @return void
		 */
		public function test_the_probe_state_used_here_is_in_no_bucket(): void {
			self::assertNotContains(
				UnbucketedVerdict::STATE,
				ConnectionDiagnostics::KEYED_STATES,
				'If this state were keyed it would not be testing the unbucketed fallthrough.'
			);
			self::assertNotContains(
				UnbucketedVerdict::STATE,
				ConnectionDiagnostics::COULD_NOT_BE_CHECKED_STATES,
				'If this state were bucketed it would not be testing the unbucketed fallthrough.'
			);
			self::assertNotContains( UnbucketedVerdict::STATE, ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES );
			self::assertSame( 'could-not-be-checked', ( new ConnectionDiagnostics() )->verify_state( array( 'state' => UnbucketedVerdict::STATE ) ) );
		}

		/**
		 * The last-known-good CLEAR is a positive membership test, not a bare else.
		 *
		 * Source-level, and honestly labelled: the read path above is behavioural,
		 * but the WRITE path cannot be exercised today. probe() returns a cached
		 * verdict before reaching it, and classify() cannot produce an unbucketed
		 * state because `unknown` catches every unrecognised response. So this
		 * guards the case that actually matters — someone adding a state to
		 * classify() and forgetting the bucket — which no behavioural test can
		 * reach until that state exists.
		 *
		 * A bare `else` there would clear last-known-good for every state nobody
		 * classified, which is this PR's original defect wearing a different hat.
		 *
		 * @return void
		 */
		public function test_last_good_clear_is_a_positive_membership_test(): void {
			$source = (string) file_get_contents(
				dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php'
			);
			self::assertSame(
				1,
				preg_match( '/private function probe\\(\\): array \\{(?<body>.*?)\\n\\t\\}/s', $source, $m ),
				'probe() body must be locatable, or this ratchet proves nothing.'
			);
			$body = $m['body'] ?? '';

			self::assertMatchesRegularExpression(
				'~} elseif \( in_array\( \$state, ConnectionDiagnostics::DEFINITIVE_NEGATIVE_STATES, true \) \) \{\s*\$this->writeLastGood\( false \);~',
				$body,
				'Clearing last-known-good must be guarded by positive membership of DEFINITIVE_NEGATIVE_STATES.'
			);
			self::assertDoesNotMatchRegularExpression(
				'~} else \{\s*\$this->writeLastGood\(~',
				$body,
				'A bare else clears last-known-good for every state nobody classified. That is the defect, not a style choice.'
			);
		}

		/**
		 * DIRECTION 1: with last-known-good, an unbucketed state stays connected.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unbucketed_state_with_last_known_good_stays_connected(): void {
			$this->boot();

			$deleted = array();
			$store   = array(
				'opencode_connector_avail_go'         => UnbucketedVerdict::cached(),
				'opencode_connector_avail_go_last_good' => 1,
			);
			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) use ( &$store ) {
					return $store[ $key ] ?? false;
				}
			);
			Functions\when( 'set_transient' )->alias(
				static function ( string $key, mixed $value, int $ttl ) use ( &$store ): bool {
					$store[ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				static function ( string $key ) use ( &$deleted ): bool {
					$deleted[] = $key;
					return true;
				}
			);
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new \OpenCodeConnector\Availability\OpenCodeProviderAvailability( 'go' );

			self::assertTrue(
				$availability->isConfigured(),
				'A state nobody classified must not read as not configured; that is the original defect.'
			);
			self::assertNotContains(
				'opencode_connector_avail_go_last_good',
				$deleted,
				'An unbucketed state must not CLEAR last-known-good; failing open means leaving it alone.'
			);
		}

		/**
		 * DIRECTION 2: with no last-known-good, it reports not configured.
		 *
		 * Fails open, not up: an unclassifiable state must not manufacture a
		 * connected verdict out of nothing.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unbucketed_state_without_last_known_good_is_not_configured(): void {
			$this->boot();

			Functions\when( 'get_transient' )->alias(
				static function ( string $key ) {
					return 'opencode_connector_avail_go' === $key ? UnbucketedVerdict::cached() : false;
				}
			);
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'delete_transient' )->justReturn( true );
			Functions\when( 'wp_rand' )->justReturn( 0 );

			$availability = new \OpenCodeConnector\Availability\OpenCodeProviderAvailability( 'go' );

			self::assertFalse(
				$availability->isConfigured(),
				'With nothing to fall back to there is no basis for reporting configured.'
			);
		}

		/**
		 * DIRECTION 3: it never renders as a plain "connected".
		 *
		 * The settings line is driven by the verdict, not the boolean, precisely
		 * so a preserved flag cannot be presented as a current verified
		 * connection. Asserted for the unbucketed state AND for a recognised
		 * could-not-be-checked state, because the two must render identically.
		 *
		 * @return void
		 */
		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_unbucketed_state_never_renders_as_plain_connected(): void {
			$this->boot();
			Functions\when( '__' )->alias(
				static function ( string $text, string $domain = '' ): string {
					unset( $domain );
					return $text;
				}
			);
			$settings = new Settings();

			$label   = ( new \ReflectionMethod( Settings::class, 'statusLabel' ) );
			$render  = static function ( string $state, bool $configured ) use ( $settings, $label ): string {
				return (string) $label->invoke( $settings, array( 'configured' => $configured, 'state' => $state ) );
			};

			// Positive cases: a real keyed verdict IS a plain "connected".
			self::assertSame( 'connected', $render( 'verified', true ) );
			self::assertSame( 'connected', $render( 'no_credits', true ) );

			// The unbucketed state, and the recognised could-not-be-checked
			// state, must both say verification could not happen.
			foreach ( array( UnbucketedVerdict::STATE, 'unknown', 'uncheckable', 'probe_model_unavailable', '' ) as $state ) {
				$shown = $render( $state, true );
				self::assertStringContainsString(
					'could not verify',
					$shown,
					'"' . $state . '" fell back to last-known-good and must say so; it is not a current, verified connection.'
				);
			}

			// A definitive negative never claims connection at all.
			self::assertSame( 'not connected', $render( UnbucketedVerdict::STATE, false ) );
			self::assertSame( 'not connected', $render( 'invalid_key', true ) );
		}
	}
}
