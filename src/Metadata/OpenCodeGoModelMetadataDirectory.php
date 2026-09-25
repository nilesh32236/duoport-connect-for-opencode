<?php
/**
 * OpenCode Go model metadata directory.
 *
 * Go catalog model directory.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Providers\OpenCodeGoProvider;

/**
 * Go catalog model directory.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */
final class OpenCodeGoModelMetadataDirectory extends AbstractOpenCodeModelMetadataDirectory {
	/**
	 * Provider class FQCN.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected function providerClass(): string {
		return OpenCodeGoProvider::class;
	}

	/**
	 * Catalog key.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected function catalogKey(): string {
		return Catalog::GO;
	}
}
