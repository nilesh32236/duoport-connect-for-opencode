<?php
/**
 * Exception for unsupported OpenCode endpoint families.
 *
 * @package OpenCodeConnector
 * @since 0.1.5
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised before transport when a model has no verified endpoint route.
 *
 * @since 0.1.5
 */
final class UnsupportedEndpointFamilyException extends \RuntimeException {
}
