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
}
