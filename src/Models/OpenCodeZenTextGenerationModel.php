<?php
/**
 * OpenCode Zen text generation model.
 *
 * Zen text generation model.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Providers\OpenCodeZenProvider;

/**
 * Zen text generation model.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
final class OpenCodeZenTextGenerationModel extends AbstractOpenCodeTextGenerationModel {
	/**
	 * Provider class FQCN.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected function providerClass(): string {
		return OpenCodeZenProvider::class;
	}

	/**
	 * Curated same-catalog, same-endpoint fallback for tool requests.
	 *
	 * @return array<int, string>
	 */
	protected function fallback_model_ids(): array {
		return array( 'glm-5.2' );
	}
}
