<?php
/**
 * Exception for unsupported OpenCode endpoint families.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised before transport when a model has no verified endpoint route.
 */
final class UnsupportedEndpointFamilyException extends \RuntimeException {
}
