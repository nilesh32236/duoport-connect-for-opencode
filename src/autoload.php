<?php
/**
 * Autoloader for the plugin.
 *
 * Registers a PSR-4 autoloader for the OpenCodeConnector namespace.
 *
 * @package OpenCodeConnector
 * @since 0.1.0
 */

declare(strict_types=1);

namespace OpenCodeConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$rel  = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$rel  = str_replace( '\\', '/', $rel );
		$file = __DIR__ . '/' . $rel . '.php';
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);
