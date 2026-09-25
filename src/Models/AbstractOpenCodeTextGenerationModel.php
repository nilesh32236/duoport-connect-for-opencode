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

use OpenCodeConnector\Http\GoRequestHeaders;
use OpenCodeConnector\Metadata\CapabilityAwareFallback;
use OpenCodeConnector\Metadata\Catalog;
use OpenCodeConnector\Metadata\ModelRegistry;
use OpenCodeConnector\Providers\OpenCodeGoProvider;
use OpenCodeConnector\Providers\OpenCodeZenProvider;
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
	/**
	 * Fallback model selected during tool preparation for the next request.
	 *
	 * @var string|null
	 */
	private ?string $prepared_route_model_id = null;

	/**
	 * Injectable fallback selector (test seam).
	 *
	 * @since 0.1.6
	 *
	 * @var CapabilityAwareFallback|null
	 */
	private ?CapabilityAwareFallback $fallback_selector = null;

	/**
	 * Inject a fallback selector, e.g. with an isolated record resolver.
	 *
	 * Production callers omit this and get the canonical registry-backed
	 * selector. Never throws.
	 *
	 * @since 0.1.6
	 *
	 * @param CapabilityAwareFallback $fallback Selector to use.
	 * @return void
	 */
	public function set_fallback_selector( CapabilityAwareFallback $fallback ): void {
		$this->fallback_selector = $fallback;
	}

	/**
	 * Resolve the fallback selector, defaulting to the canonical instance.
	 *
	 * @since 0.1.6
	 *
	 * @return CapabilityAwareFallback
	 */
	protected function fallback_selector(): CapabilityAwareFallback {
		if ( null === $this->fallback_selector ) {
			$this->fallback_selector = new CapabilityAwareFallback();
		}
		return $this->fallback_selector;
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
		$model_id          = null !== $prepared_model_id ? $prepared_model_id : $this->route_model_id();
		$catalog           = $this->catalog_key_for_tool_gate();
		if ( '' === $model_id || '' === $catalog ) {
			throw new UnsupportedEndpointFamilyException( 'Model route metadata is unavailable.' );
		}
		$capability = null !== $prepared_model_id || ( is_array( $data ) && ! empty( $data['tools'] ) ) ? 'tools' : 'text';
		$selection  = $this->fallback_selector()->select(
			$catalog,
			$model_id,
			$capability,
			$this->fallback_model_ids()
		);
		if ( ! is_string( $selection['selected_id'] ?? null ) ) {
			throw new UnsupportedEndpointFamilyException( 'No verified model candidate is available for this route.' );
		}
		$model_id = $selection['selected_id'];
		if ( is_array( $data ) ) {
			$data['model'] = $model_id;
		}
		$path = EndpointRoute::pathForModel( $model_id, $catalog );
		if ( OpenCodeGoProvider::class === $cls ) {
			$headers = GoRequestHeaders::for_go( $headers, $data );
		}
		$request                       = new Request( $method, $cls::url( $path ), $headers, $data, $this->getRequestOptions() );
		$this->prepared_route_model_id = null;
		return $request;
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
			$model_id = $this->model_id_for_tool_gate();
			$catalog  = $this->catalog_key_for_tool_gate();
			if ( '' === $model_id || '' === $catalog ) {
				return array();
			}
			$selection = $this->fallback_selector()->select(
				$catalog,
				$model_id,
				'tools',
				$this->fallback_model_ids()
			);
			if ( ! is_string( $selection['selected_id'] ?? null ) ) {
				return array();
			}
			$model_id = $selection['selected_id'];
			if ( ! ModelRegistry::supports( $model_id, $catalog, 'tools' ) ) {
				return array();
			}
			$tools = array();
			foreach ( $function_declarations as $declaration ) {
				try {
					if ( ! is_object( $declaration ) || ! method_exists( $declaration, 'toArray' ) ) {
						continue;
					}
					$declaration_array = $declaration->toArray();
					if ( ! is_array( $declaration_array ) ) {
						continue;
					}
					$tools[] = array(
						'type'     => 'function',
						'function' => $declaration_array,
					);
				} catch ( \Throwable ) {
					continue;
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
			if ( null !== $via_accessors ) {
				return $via_accessors;
			}
			return $this->resolveViaReflection();
		} catch ( \Throwable ) {
			return '';
		}
	}

	/**
	 * Resolve the model ID through SDK metadata accessors.
	 *
	 * Probes SDK metadata accessors first. Returns null when no accessor
	 * resolves so the caller falls back to reflection, or the resolved ID
	 * (possibly empty, failing open downstream). Never throws.
	 *
	 * @since 0.1.6
	 *
	 * @return string|null
	 */
	private function resolveViaAccessors(): ?string {
		foreach ( array( 'metadata', 'getModelMetadata', 'getMetadata', 'getModel', 'model' ) as $accessor ) {
			if ( ! method_exists( $this, $accessor ) ) {
				continue;
			}
			try {
				$metadata = $this->{$accessor}();
			} catch ( \Throwable ) {
				continue;
			}
			if ( is_object( $metadata ) && method_exists( $metadata, 'getId' ) ) {
				try {
					return (string) $metadata->getId();
				} catch ( \Throwable ) {
					continue;
				}
			}
		}
		return null;
	}

	/**
	 * Resolve the model ID by reflecting over object properties.
	 *
	 * Last-resort fallback: reflect over object properties looking for
	 * SDK metadata holding a getId() accessor (targets the
	 * WordPress AI Client OpenAI-compatible base model). The
	 * public-accessor loop above is primary; prefer an explicit
	 * model-id accessor if the SDK exposes one. No setAccessible()
	 * call: it has been a no-op since PHP 8.1 and the floor here is 8.2.
	 * Returns an empty string when unresolvable. Never throws.
	 *
	 * @since 0.1.6
	 *
	 * @return string
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
	 * Derived from the bound provider class (Go vs Zen) so models never
	 * share or alias catalog keys. Returns an empty string when
	 * unresolvable so the caller fails open to plain text. Never throws.
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
			if ( OpenCodeZenProvider::class === $cls ) {
				return Catalog::ZEN;
			}
			if ( OpenCodeGoProvider::class === $cls ) {
				return Catalog::GO;
			}
		} catch ( \Throwable ) {
			return '';
		}
		return '';
	}
}
