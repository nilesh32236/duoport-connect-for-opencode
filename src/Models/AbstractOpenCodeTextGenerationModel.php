<?php
/**
 * Shared text generation model.
 *
 * Shared text generation model for chat completions.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Metadata\CapabilityAwareFallback;
use OpenCodeConnector\Metadata\Catalog;
use OpenCodeConnector\Metadata\ModelRegistry;
use OpenCodeConnector\Transport\BuildsProviderRequest;
use OpenCodeConnector\Transport\EndpointRoute;
use OpenCodeConnector\Transport\UnsupportedEndpointFamilyException;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Shared text generation model (chat/completions).
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
abstract class AbstractOpenCodeTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
	use BuildsProviderRequest;

	/**
	 * Fallback model selected during tool preparation for the next request.
	 *
	 * @var string|null
	 */
	private ?string $prepared_route_model_id = null;

	/**
	 * Injected fallback selector override (test seam; defaults to canonical).
	 *
	 * @var CapabilityAwareFallback|null
	 */
	private ?CapabilityAwareFallback $fallback_override = null;

	/**
	 * Inject a fallback selector double for isolated tests.
	 *
	 * Production callers never set this; the canonical registry-backed
	 * selector is used by default.
	 *
	 * @since 0.1.6
	 *
	 * @param CapabilityAwareFallback $fallback Fallback selector double.
	 * @return void
	 */
	public function setFallback( CapabilityAwareFallback $fallback ): void {
		$this->fallback_override = $fallback;
	}

	/**
	 * Fallback selector for this request (canonical unless overridden).
	 *
	 * Protected as a test seam so unit tests can substitute a stub resolver
	 * without running the full ModelRegistry/ModelAllowlist stack.
	 *
	 * @since 0.1.6
	 *
	 * @return CapabilityAwareFallback
	 */
	protected function fallbackSelector(): CapabilityAwareFallback {
		return $this->fallback_override ?? new CapabilityAwareFallback();
	}

	/**
	 * Provider class FQCN.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	abstract protected function providerClass(): string;

	/**
	 * Create a request for the provider.
	 *
	 * Go requests carry the stable `x-opencode-session` header plus the
	 * shared client User-Agent (same fingerprint as the Go image path, so
	 * gateway observability sees one client). Zen requests are sent
	 * unchanged. Header failures always fall back to a headerless send;
	 * never fatal.
	 *
	 * @since 0.1.0
	 * @since 0.1.5 Added shared client User-Agent on the Go path.
	 *
	 * @param HttpMethodEnum $method  HTTP method.
	 * @param string         $path    Request path.
	 * @param array          $headers Request headers.
	 * @param mixed          $data    Request data.
	 * @return Request
	 * @throws UnsupportedEndpointFamilyException When route metadata or the model route is unsupported.
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$cls               = $this->providerClass();
		$prepared_model_id = $this->prepared_route_model_id;
		$capability        = null !== $prepared_model_id || ( is_array( $data ) && ! empty( $data['tools'] ) ) ? 'tools' : 'text';
		$route             = $this->selectRouteModel( $capability, $prepared_model_id );
		if ( '' !== $route['reason'] ) {
			throw new UnsupportedEndpointFamilyException(
				'no_candidate' === $route['reason']
					? 'No verified model candidate is available for this route.'
					: 'Model route metadata is unavailable.'
			);
		}
		$model_id = $route['model_id'];
		$catalog  = $route['catalog'];
		if ( is_array( $data ) ) {
			$data['model'] = $model_id;
		}
		$path = EndpointRoute::pathForModel( $model_id, $catalog );
		if ( Catalog::GO === $catalog ) {
			$headers = $this->goHeaders( $headers, $data );
		}
		$request                       = $this->buildProviderRequest( $cls, $method, $path, $headers, $data );
		$this->prepared_route_model_id = null;
		return $request;
	}

	/**
	 * Select the route model for a capability through the fallback selector.
	 *
	 * Single owner for the fallback-selection block createRequest() and
	 * prepareToolsParam() each wrote by hand: both resolved the bound
	 * catalog, both called select() with the same fallback list, and both
	 * null-checked the selection — but with different failure semantics
	 * that have to stay semantically in step. Callers interpret the same
	 * result with their own fail-open/fail-closed behaviour.
	 *
	 * @since 0.1.8
	 *
	 * @param string      $capability  Required capability key.
	 * @param string|null $prepared_id Pre-selected model ID, if any.
	 * @return array{model_id: string, catalog: string, reason: string} Empty reason on success;
	 *                                                                  `unavailable` when the model ID or catalog
	 *                                                                  cannot be resolved, `no_candidate` when no
	 *                                                                  verified candidate exists.
	 */
	private function selectRouteModel( string $capability, ?string $prepared_id ): array {
		$model_id = null !== $prepared_id ? $prepared_id : $this->route_model_id();
		$catalog  = $this->catalog_key_for_tool_gate();
		if ( '' === $model_id || '' === $catalog ) {
			return array(
				'model_id' => '',
				'catalog'  => $catalog,
				'reason'   => 'unavailable',
			);
		}
		$selection = $this->fallbackSelector()->select(
			$catalog,
			$model_id,
			$capability,
			$this->fallback_model_ids()
		);
		if ( ! is_string( $selection['selected_id'] ?? null ) ) {
			return array(
				'model_id' => '',
				'catalog'  => $catalog,
				'reason'   => 'no_candidate',
			);
		}
		return array(
			'model_id' => $selection['selected_id'],
			'catalog'  => $catalog,
			'reason'   => '',
		);
	}

	/**
	 * OpenCode expects json_schema with name wrapper; SDK sends bare schema.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed>|null $output_schema Output schema.
	 * @return array<string, mixed>
	 */
	protected function prepareResponseFormatParam( ?array $output_schema ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		if ( is_array( $output_schema ) ) {
			return array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'response',
					'schema' => $output_schema,
					'strict' => true,
				),
			);
		}
		return array( 'type' => 'json_object' );
	}

	/**
	 * Map function declarations to the OpenAI-compatible tools wire shape.
	 *
	 * Gated behind the canonical ModelRegistry capability record: unsupported,
	 * free, unimplemented-endpoint, DeepSeek, or unknown models return an empty array
	 * so the request
	 * degrades to a plain-text completion instead of failing at the
	 * gateway. Never throws; fail-open by design.
	 *
	 * Intentionally does not call parent::prepareToolsParam() so unit tests
	 * stay runnable against SDK-free stubs and oldest installs without the
	 * method keep working; the mapping below replicates the verified
	 * upstream shape.
	 *
	 * @since 0.1.4
	 *
	 * @param array $function_declarations Function declarations.
	 * @return array<int, array<string, mixed>>
	 */
	protected function prepareToolsParam( array $function_declarations ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
		$this->prepared_route_model_id = null;
		try {
			if ( array() === $function_declarations ) {
				return array();
			}
			if ( ! class_exists( ModelRegistry::class ) || ! method_exists( ModelRegistry::class, 'supports' ) ) {
				return array();
			}
			$route = $this->selectRouteModel( 'tools', null );
			if ( '' !== $route['reason'] ) {
				return array();
			}
			$model_id = $route['model_id'];
			$catalog  = $route['catalog'];
			if ( ! ModelRegistry::supports( $model_id, $catalog, 'tools' ) ) {
				return array();
			}
			$tools = array();
			foreach ( $function_declarations as $declaration ) {
				$tool = $this->mapDeclarationToTool( $declaration );
				if ( null !== $tool ) {
					$tools[] = $tool;
				}
			}
			if ( array() !== $tools ) {
				$this->prepared_route_model_id = $model_id;
			}
			return $tools;
		} catch ( \Throwable ) {
			return array();
		}
	}

	/**
	 * Map one function declaration to the tools wire shape.
	 *
	 * Returns null for unmappable declarations so the caller fails open to
	 * plain text. Never throws.
	 *
	 * @since 0.1.6
	 *
	 * @param mixed $declaration Function declaration.
	 * @return array<string, mixed>|null
	 */
	private function mapDeclarationToTool( $declaration ): ?array {
		try {
			if ( ! is_object( $declaration ) || ! method_exists( $declaration, 'toArray' ) ) {
				return null;
			}
			$declaration_array = $declaration->toArray();
			if ( ! is_array( $declaration_array ) ) {
				return null;
			}
			return array(
				'type'     => 'function',
				'function' => $declaration_array,
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Resolve the model ID used for endpoint routing.
	 *
	 * Protected as a test seam for SDK-free model doubles; production models
	 * use the metadata/reflection resolver below.
	 *
	 * @return string
	 */
	protected function route_model_id(): string {
		return $this->model_id_for_tool_gate();
	}

	/**
	 * Return explicitly reviewed same-catalog fallback IDs for this model.
	 *
	 * The default is empty: callers must opt into a bounded fallback list.
	 * Selection still enforces endpoint, capability, and verification equality.
	 *
	 * @return array<int, string>
	 */
	protected function fallback_model_ids(): array {
		return array();
	}

	/**
	 * Resolve the model ID for the tool-capability gate.
	 *
	 * Probes SDK metadata accessors first, then protected properties via
	 * reflection. Returns an empty string when unresolvable so the caller
	 * fails open to plain text. Never throws.
	 *
	 * @since 0.1.4
	 *
	 * @return string
	 */
	private function model_id_for_tool_gate(): string {
		try {
			$via_accessors = $this->resolveViaAccessors();
			if ( '' !== $via_accessors ) {
				return $via_accessors;
			}
			return $this->resolveViaReflection();
		} catch ( \Throwable ) {
			return '';
		}
	}

	/**
	 * Resolve the model ID via SDK metadata accessors.
	 *
	 * @since 0.1.6
	 *
	 * @return string Empty string when unresolvable. Never throws.
	 */
	private function resolveViaAccessors(): string {
		foreach ( array( 'metadata', 'getModelMetadata', 'getMetadata', 'getModel', 'model' ) as $accessor ) {
			if ( ! method_exists( $this, $accessor ) ) {
				continue;
			}
			try {
				$metadata = $this->{$accessor}();
			} catch ( \Throwable ) {
				continue;
			}
			if ( ! is_object( $metadata ) || ! method_exists( $metadata, 'getId' ) ) {
				continue;
			}
			try {
				$id = (string) $metadata->getId();
			} catch ( \Throwable ) {
				continue;
			}
			if ( '' !== $id ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Last-resort model ID resolution via reflection.
	 *
	 * Reflects over object properties looking for SDK metadata holding a
	 * getId() accessor (targets the WordPress AI Client OpenAI-compatible
	 * base model). The public-accessor loop above is primary; prefer an
	 * explicit model-id accessor if the SDK exposes one. No setAccessible()
	 * call: it has been a no-op since PHP 8.1 and the floor here is 8.2.
	 * Never throws.
	 *
	 * @since 0.1.6
	 *
	 * @return string Empty string when unresolvable.
	 */
	private function resolveViaReflection(): string {
		try {
			$reflection = new \ReflectionObject( $this );
			foreach ( $reflection->getProperties() as $prop ) {
				try {
					$candidate = $prop->getValue( $this );
				} catch ( \Throwable ) {
					continue;
				}
				if ( is_object( $candidate ) && method_exists( $candidate, 'getId' ) ) {
					try {
						$id = (string) $candidate->getId();
					} catch ( \Throwable ) {
						continue;
					}
					if ( '' !== $id ) {
						return $id;
					}
				}
			}
		} catch ( \Throwable ) {
			return '';
		}
		return '';
	}

	/**
	 * Resolve the catalog key for the tool-capability gate.
	 *
	 * Derived from the bound provider class (Go vs Zen) through
	 * Catalog::providerClassFor() so models never share or alias catalog
	 * keys and never default an unrecognised provider to a catalog.
	 * Returns an empty string when unresolvable so the caller fails open to
	 * plain text. Never throws.
	 *
	 * @since 0.1.4
	 *
	 * @return string
	 */
	private function catalog_key_for_tool_gate(): string {
		try {
			if ( ! method_exists( $this, 'providerClass' ) ) {
				return '';
			}
			$cls = $this->providerClass();
			if ( ! is_string( $cls ) || '' === $cls ) {
				return '';
			}
			foreach ( Catalog::ALL as $slug ) {
				if ( Catalog::providerClassFor( $slug ) === $cls ) {
					return $slug;
				}
			}
		} catch ( \Throwable ) {
			return '';
		}
		return '';
	}
}
