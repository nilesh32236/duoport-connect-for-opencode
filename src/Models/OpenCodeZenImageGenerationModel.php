<?php
/**
 * OpenCode Zen image generation model.
 *
 * Zen image generation model.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Providers\OpenCodeZenProvider;

/**
 * Zen image generation model.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */
final class OpenCodeZenImageGenerationModel extends AbstractOpenCodeImageGenerationModel {
	/**
	 * Provider class FQCN.
	 *
	 * @since 0.1.4
	 *
	 * @return string
	 */
	protected function providerClass(): string {
		return OpenCodeZenProvider::class;
	}
}
