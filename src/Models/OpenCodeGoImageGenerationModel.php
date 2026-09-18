<?php
/**
 * OpenCode Go image generation model.
 *
 * Go image generation model.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */

declare(strict_types=1);

namespace OpenCodeConnector\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Providers\OpenCodeGoProvider;

/**
 * Go image generation model.
 *
 * @package OpenCodeConnector
 * @since 0.1.4
 */
final class OpenCodeGoImageGenerationModel extends AbstractOpenCodeImageGenerationModel {
	/**
	 * Provider class FQCN.
	 *
	 * @since 0.1.4
	 *
	 * @return string
	 */
	protected function providerClass(): string {
		return OpenCodeGoProvider::class;
	}
}
