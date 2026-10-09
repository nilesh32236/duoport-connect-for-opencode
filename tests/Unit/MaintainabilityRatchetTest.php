<?php
/**
 * Cross-cutting maintainability ratchets for the audit cleanup.
 *
 * Each test pins one "single source of truth" invariant: curated data and
 * state vocabularies that used to be hand-copied across files must stay in
 * agreement, so the next addition cannot update one copy and forget the
 * other. Behavioural first, source-shape second: where a source assertion
 * exists it targets the defect shape (a second copy), never a count that
 * would block a legitimate new call site.
 *
 * @package OpenCodeConnector
 * @since 0.1.8
 */

declare(strict_types=1);

namespace OpenCodeConnector\Tests\Unit\Bootstrap {
	require_once __DIR__ . '/Fixtures/SdkStubs.php';
}

namespace OpenCodeConnector\Tests\Unit {
	use Brain\Monkey\Functions;
	use OpenCodeConnector\Availability\ConnectionDiagnostics;
	use OpenCodeConnector\Metadata\Catalog;
	use OpenCodeConnector\Metadata\ModelAllowlist;
	use OpenCodeConnector\Metadata\ModelRadar;
	use OpenCodeConnector\Metadata\ModelRegistry;
	use OpenCodeConnector\Metadata\OpenCodeGoModelMetadataDirectory;
	use OpenCodeConnector\Providers\OpenCodeGoProvider;
	use OpenCodeConnector\Providers\OpenCodeZenProvider;
	use WordPress\AiClient\Providers\Http\DTO\Response;

	/**
	 * Single-source-of-truth invariants across the plugin.
	 */
	final class MaintainabilityRatchetTest extends MonkeyTestCase {

		/**
		 * Boot the plugin autoloader and the option-name constant.
		 */
		public static function setUpBeforeClass(): void {
			parent::setUpBeforeClass();
			if ( ! defined( 'OpenCodeConnector\\OPTION_NAME' ) ) {
				define( 'OpenCodeConnector\\OPTION_NAME', 'opencode_connector_settings' );
			}
			require_once dirname( __DIR__, 2 ) . '/src/autoload.php';
		}

		/**
		 * Stub translation.
		 */
		protected function setUp(): void {
			parent::setUp();
			Functions\when( '__' )->alias(
				static function ( ...$args ): string {
					return (string) ( $args[0] ?? '' );
				}
			);
		}

		/**
		 * Every produced state is classified by exactly one bucket helper.
		 *
		 * A new state added to ConnectionDiagnostics without a bucket would
		 * otherwise land on a fail-closed default at a call site, silently
		 * flipping valid keys to not-connected.
		 */
		public function test_every_diagnostics_state_is_bucketed_exactly_once(): void {
			$states = ConnectionDiagnostics::allStates();
			self::assertNotSame( array(), $states, 'The vocabulary must not be empty.' );
			foreach ( $states as $state ) {
				$buckets = (int) ConnectionDiagnostics::isConfiguredState( $state )
					+ (int) ConnectionDiagnostics::isUncheckableState( $state )
					+ (int) ConnectionDiagnostics::isDefinitiveNegativeState( $state );
				self::assertSame( 1, $buckets, "State '{$state}' must be classified by exactly one bucket helper." );
			}
		}

		/**
		 * Every diagnostics factory result carries a bucketed state.
		 */
		public function test_every_factory_result_carries_a_bucketed_state(): void {
			$diagnostics = new ConnectionDiagnostics();
			$results     = array(
				$diagnostics->notConfigured(),
				$diagnostics->verified(),
				$diagnostics->unknown( 400 ),
				$diagnostics->probeModelUnavailable( 404 ),
				$diagnostics->uncheckable(),
				$diagnostics->networkError(),
				$diagnostics->noCredits( 401 ),
				$diagnostics->invalidKey( 401 ),
				$diagnostics->rateLimited( 429 ),
				$diagnostics->freeTierLimit( 429 ),
				$diagnostics->serverError( 500 ),
			);
			$allowed       = ConnectionDiagnostics::allStates();
			$verify_states = array( 'valid', 'invalid_key', 'could-not-be-checked' );
			foreach ( $results as $result ) {
				self::assertContains(
					$result['state'],
					$allowed,
					'A factory produced a state outside the published vocabulary.'
				);
				self::assertContains(
					$diagnostics->verify_state( $result ),
					$verify_states,
					'Every diagnosis must map to an explicit verification state.'
				);
			}
		}

		/**
		 * Sampled classifier outputs stay inside the published vocabulary.
		 */
		public function test_classifier_samples_stay_inside_the_vocabulary(): void {
			$diagnostics = new ConnectionDiagnostics();
			$samples     = array(
				$diagnostics->classify( 200 ),
				$diagnostics->classify( 401, array( 'error' => array( 'type' => 'CreditsError' ) ) ),
				$diagnostics->classify( 401, array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'Invalid API key provided' ) ) ),
				$diagnostics->classify( 429 ),
				$diagnostics->classify( 429, array( 'error' => array( 'type' => 'FreeUsageLimitError' ) ) ),
				$diagnostics->classify( 500 ),
				$diagnostics->classify( 0, null, new \RuntimeException( 'transport down' ) ),
				$diagnostics->classify( 400 ),
			);
			foreach ( $samples as $sample ) {
				$state    = (string) ( $sample['state'] ?? '' );
				$bucketed = ConnectionDiagnostics::isConfiguredState( $state )
					|| ConnectionDiagnostics::isUncheckableState( $state )
					|| ConnectionDiagnostics::isDefinitiveNegativeState( $state );
				self::assertTrue( $bucketed, "Classifier state '{$state}' is in no bucket." );
			}
		}

		/**
		 * Curated free and pending-verification IDs stay inside the allowlist.
		 *
		 * FREE and PENDING_ENDPOINT_VERIFICATION are maintained separately
		 * from ALLOW, so a model added to one but not the other silently
		 * flips isFree()/isToolCapable() or the endpoint gate with no failure.
		 */
		public function test_curated_free_and_unsupported_ids_stay_allowlisted(): void {
			$free = self::private_const( ModelAllowlist::class, 'FREE' );
			self::assertIsArray( $free );
			self::assertNotSame( array(), $free[ Catalog::ZEN ], 'The Zen free set must not silently empty out.' );
			self::assertSame( array(), $free[ Catalog::GO ], 'The Go free set is reviewed empty.' );
			foreach ( $free as $catalog => $ids ) {
				self::assertIsArray( $ids );
				foreach ( $ids as $id ) {
					self::assertTrue(
						ModelAllowlist::isAllowed( (string) $id, (string) $catalog ),
						"Free model '{$id}' must stay allowlisted in '{$catalog}' or isFree() disagrees with the catalog."
					);
					self::assertTrue(
						ModelAllowlist::isFree( (string) $id, (string) $catalog ),
						"Free model '{$id}' must read as free in '{$catalog}'."
					);
				}
			}
			$pending = self::private_const( ModelRegistry::class, 'PENDING_ENDPOINT_VERIFICATION' );
			self::assertIsArray( $pending );
			foreach ( $pending as $catalog => $ids ) {
				self::assertIsArray( $ids );
				foreach ( $ids as $id ) {
					self::assertTrue(
						ModelAllowlist::isAllowed( (string) $id, (string) $catalog ),
						"Pending model '{$id}' must stay allowlisted in '{$catalog}' or record() cannot mark it verification-required."
					);
					$record = ModelRegistry::record( (string) $id, (string) $catalog );
					self::assertIsArray( $record );
					self::assertSame( ModelRegistry::ENDPOINT_FAMILY_UNSUPPORTED, $record['endpoint_family'] );
					self::assertSame( ModelRegistry::VERIFICATION_REQUIRED_STATUS, $record['verification_status'] );
				}
			}
		}

		/**
		 * The registry accepts every verification status it can assign.
		 *
		 * ModelRegistry owns the reviewed vocabulary; its consumers
		 * delegate to isVerified(), so every status the registry assigns
		 * must be accepted there.
		 */
		public function test_registry_verification_vocabulary_is_complete(): void {
			foreach ( ModelRegistry::VERIFIED_STATUSES as $status ) {
				self::assertTrue(
					ModelRegistry::isVerified( array( 'verification_status' => $status ) ),
					"Status '{$status}' must count as verified."
				);
			}
			self::assertFalse( ModelRegistry::isVerified( array( 'verification_status' => 'needs-adapter' ) ) );
			self::assertFalse( ModelRegistry::isVerified( array( 'verification_status' => ModelRegistry::VERIFICATION_REQUIRED_STATUS ) ) );
			self::assertTrue( ModelRegistry::needsVerification( array( 'verification_status' => ModelRegistry::VERIFICATION_REQUIRED_STATUS ) ) );
			self::assertTrue( ModelRegistry::needsVerification( array( 'verification_status' => 'needs-adapter' ) ) );
			self::assertFalse( ModelRegistry::needsVerification( array( 'verification_status' => 'legacy-verified' ) ) );
			self::assertFalse( ModelRegistry::isVerified( array( 'verification_status' => 'bogus' ) ) );
			self::assertFalse( ModelRegistry::isVerified( array() ) );
		}

		/**
		 * Provider IDs agree across Catalog, providers, and call sites.
		 *
		 * Settings and the entry file used to reconstruct IDs by
		 * concatenating 'opencode-' . $catalog; a prefix change there would
		 * silently query IDs that do not exist and report not-connected
		 * forever for working keys.
		 */
		public function test_provider_ids_agree_across_sources(): void {
			self::assertSame( 'opencode-go', Catalog::providerId( Catalog::GO ) );
			self::assertSame( 'opencode-zen', Catalog::providerId( Catalog::ZEN ) );
			self::assertSame( '', Catalog::providerId( 'unknown' ), 'Unknown catalogs fail closed to an empty ID.' );
			self::assertSame( Catalog::providerId( Catalog::GO ), OpenCodeGoProvider::PROVIDER_ID );
			self::assertSame( Catalog::providerId( Catalog::ZEN ), OpenCodeZenProvider::PROVIDER_ID );

			$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php' );
			self::assertStringNotContainsString(
				"isProviderConfigured( 'opencode-go' )",
				$main,
				'The entry file must resolve the Go ID through Catalog, not a literal.'
			);
			self::assertStringNotContainsString(
				"isProviderConfigured( 'opencode-zen' )",
				$main,
				'The entry file must resolve the Zen ID through Catalog, not a literal.'
			);

			$settings = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Settings/Settings.php' );
			self::assertStringNotContainsString(
				"'opencode-' . \$catalog",
				$settings,
				'Settings must resolve provider IDs through Catalog::providerId().'
			);
		}

		/**
		 * Provider classes resolve through Catalog and fail closed.
		 */
		public function test_provider_class_mapping_has_one_home(): void {
			self::assertSame( OpenCodeGoProvider::class, Catalog::providerClassFor( Catalog::GO ) );
			self::assertSame( OpenCodeZenProvider::class, Catalog::providerClassFor( Catalog::ZEN ) );
			self::assertSame( '', Catalog::providerClassFor( 'unknown' ), 'Unknown catalogs must not default to Zen.' );

			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Availability/OpenCodeProviderAvailability.php' );
			self::assertDoesNotMatchRegularExpression(
				"/'go' === \\\$this->catalog \\? /",
				$source,
				'The availability probes must use Catalog::providerClassFor(), not an inline ternary.'
			);
		}

		/**
		 * Uninstall and bust-hook fallback key lists match Catalog.
		 *
		 * The fallbacks only run when the autoloader is unavailable, so a
		 * new cache family added to Catalog would otherwise silently
		 * survive uninstall and key rotation.
		 */
		public function test_transient_fallback_key_lists_match_catalog(): void {
			$expected = Catalog::allTransientKeys();
			sort( $expected );

			$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
			preg_match_all( "/'((?:opencode_connector_avail|opencode_connector_verify)[^']*)'/", $uninstall, $matches );
			$literals = array_values( array_unique( $matches[1] ) );
			sort( $literals );
			self::assertSame( $expected, $literals, 'uninstall.php fallback literals must equal Catalog::allTransientKeys().' );
			self::assertStringContainsString( 'Catalog::allTransientKeys', $uninstall );

			$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/duoport-connect-for-opencode.php' );
			self::assertStringContainsString( 'Catalog::allKeys', $main );
			foreach ( array( "'opencode_connector_avail_'", "'opencode_connector_verify_'" ) as $fragment ) {
				self::assertStringContainsString( $fragment, $main, 'The bust-hook fallback must build the same key families.' );
			}
			foreach ( array( "'_lock'", "'_last_good'" ) as $fragment ) {
				self::assertStringContainsString( $fragment, $main, 'The bust-hook fallback must cover locks and last-known-good.' );
			}
		}

		/**
		 * No bare catalog-slug literal drives a branch in src/.
		 *
		 * A slug comparison against 'go'/'zen' bypasses Catalog: if the
		 * slug ever moved, the `?? array()` fallbacks would swallow it and
		 * blank a catalog's model list with no error. Array keys and the
		 * constant definitions themselves are fine; branches are not.
		 */
		public function test_no_bare_catalog_slug_branch_in_src(): void {
			$root      = dirname( __DIR__, 2 ) . '/src';
			$offenders = array();
			$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}
				if ( 'Catalog.php' === $file->getBasename() ) {
					continue;
				}
				$source = (string) file_get_contents( $file->getPathname() );
				if ( 1 === preg_match( "/return\\s+['\"](go|zen)['\"]\\s*;/", $source )
					|| 1 === preg_match( "/['\"](go|zen)['\"]\\s*===|===\\s*['\"](go|zen)['\"]/", $source )
					|| 1 === preg_match( "/\\.\\s*['\"](go|zen)['\"]|['\"](go|zen)['\"]\\s*\\./", $source ) ) {
					$offenders[] = $file->getPathname();
				}
			}
			self::assertSame( array(), $offenders, 'Catalog slugs must be resolved through Catalog, not literals.' );
		}

		/**
		 * The account URL has one home.
		 */
		public function test_auth_url_has_one_home(): void {
			$root  = dirname( __DIR__, 2 ) . '/src';
			$found = array();
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
					continue;
				}
				if ( str_contains( (string) file_get_contents( $file->getPathname() ), 'https://opencode.ai/auth' ) ) {
					$found[] = $file->getFilename();
				}
			}
			sort( $found );
			self::assertSame( array( 'Catalog.php' ), $found, 'The signup URL literal must live in Catalog only.' );

			$provider = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Providers/AbstractOpenCodeProvider.php' );
			self::assertStringContainsString( 'Catalog::AUTH_URL', $provider );
		}

		/**
		 * Radar sources track the Catalog model URLs.
		 */
		public function test_radar_sources_track_catalog_urls(): void {
			$report = ( new ModelRadar() )->report( array( 'go' => array(), 'zen' => array() ) );
			self::assertSame(
				array(
					Catalog::GO  => Catalog::modelsUrl( Catalog::GO ),
					Catalog::ZEN => Catalog::modelsUrl( Catalog::ZEN ),
				),
				$report['sources']
			);
		}

		/**
		 * Reachable and unreachable catalogs share one summary schema, and
		 * no internal scaffolding key leaks into the shipped changes.
		 */
		public function test_radar_summary_schemas_match_and_changes_are_public(): void {
			$report = ( new ModelRadar() )->report(
				array(
					'go'  => null,
					'zen' => array( array( 'id' => 'glm-5.2' ) ),
				),
				'2026-09-25T00:00:00+00:00'
			);
			$unreachable_keys = array_keys( $report['catalogs']['go']['summary'] );
			$reachable_keys   = array_keys( $report['catalogs']['zen']['summary'] );
			sort( $unreachable_keys );
			sort( $reachable_keys );
			self::assertSame( $reachable_keys, $unreachable_keys, 'Both branches must expose an identical summary key set.' );
			foreach ( $report['catalogs']['zen']['changes'] as $change ) {
				self::assertIsArray( $change );
				foreach ( array_keys( $change ) as $key ) {
					self::assertDoesNotMatchRegularExpression( '/^_/', (string) $key, 'Scaffolding keys must not leak into the report.' );
				}
			}
		}

		/**
		 * A JSON-capable model advertises outputSchema exactly once.
		 *
		 * The option used to be spliced in at a hardcoded positional
		 * index, so reordering the shared list silently moved it.
		 */
		public function test_json_capable_model_advertises_output_schema_once(): void {
			Functions\when( 'get_option' )->justReturn( array( 'show_all_models' => false ) );
			$directory = new OpenCodeGoModelMetadataDirectory();
			$method    = new \ReflectionMethod( $directory, 'parseResponseToModelMetadataList' );
			$method->setAccessible( true );
			$list = $method->invoke(
				$directory,
				new Response(
					array(
						'data' => array(
							array( 'id' => 'glm-5.2' ),
							array( 'id' => 'deepseek-v4-flash' ),
						),
					)
				)
			);
			$by_id = array();
			foreach ( $list as $metadata ) {
				$by_id[ $metadata->getId() ] = $metadata;
			}
			self::assertArrayHasKey( 'glm-5.2', $by_id );
			self::assertArrayHasKey( 'deepseek-v4-flash', $by_id );
			self::assertSame( 1, self::count_option( $by_id['glm-5.2']->getSupportedOptions(), 'outputSchema' ) );
			self::assertSame( 0, self::count_option( $by_id['deepseek-v4-flash']->getSupportedOptions(), 'outputSchema' ) );
		}

		/**
		 * No test file declares SDK stubs inline.
		 *
		 * tests/Unit/Fixtures/SdkStubs.php is the single home; a second
		 * copy in a test file silently diverges and exercises a different
		 * SDK shape while still passing.
		 */
		public function test_no_test_file_declares_sdk_stubs_inline(): void {
			$offenders = array();
			foreach ( (array) glob( dirname( __DIR__ ) . '/*Test.php' ) as $file ) {
				if ( str_contains( (string) file_get_contents( $file ), 'namespace WordPress\\' ) ) {
					$offenders[] = basename( $file );
				}
			}
			self::assertSame( array(), $offenders, 'SDK stubs must live in Fixtures/SdkStubs.php only.' );
		}

		/**
		 * The fallback selector evaluates through a context object.
		 *
		 * The by-reference seen map was the only cycle protection and was
		 * invisible in the signature; a context object makes it a type.
		 */
		public function test_fallback_selector_uses_candidate_context(): void {
			$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Metadata/CapabilityAwareFallback.php' );
			self::assertStringContainsString( 'CandidateContext', $source );
			self::assertStringNotContainsString( 'array &$seen', $source );
		}

		/**
		 * Read a private class constant for white-box subset ratchets.
		 *
		 * @param class-string $class Class name.
		 * @param string       $name  Constant name.
		 * @return mixed
		 */
		private static function private_const( string $class, string $name ): mixed {
			$reflection = new \ReflectionClassConstant( $class, $name );
			return $reflection->getValue();
		}

		/**
		 * Count supported options advertising one factory name.
		 *
		 * @param array  $options Supported options.
		 * @param string $factory Option factory name.
		 * @return int
		 */
		private static function count_option( array $options, string $factory ): int {
			$count = 0;
			foreach ( $options as $option ) {
				if ( is_object( $option ) && method_exists( $option, 'getOption' ) ) {
					$inner = $option->getOption();
					if ( is_object( $inner ) && method_exists( $inner, 'getValue' ) ) {
						try {
							if ( $factory === (string) $inner->getValue() ) {
								++$count;
							}
						} catch ( \Throwable ) {
							continue;
						}
					}
				}
			}
			return $count;
		}
	}
}
