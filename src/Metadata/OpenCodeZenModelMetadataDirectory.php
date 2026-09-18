<?php
/**
 * OpenCode Zen model metadata directory.
 *
 * Zen catalog model directory.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Providers\OpenCodeZenProvider;

/**
 * Zen catalog model directory.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
final class OpenCodeZenModelMetadataDirectory extends AbstractOpenCodeModelMetadataDirectory {
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
	 * Catalog key.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected function catalogKey(): string {
		return 'zen';
	}
}
