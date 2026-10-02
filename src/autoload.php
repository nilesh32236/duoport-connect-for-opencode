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
		$rel   = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		$base  = realpath( __DIR__ );
		$file  = realpath( __DIR__ . '/' . str_replace( '\\', '/', $rel ) . '.php' );
		// Containment check: this is the one place in src/ that turns an
		// external class name into a filesystem path, so a resolved path that
		// escapes the plugin directory (symlink, widened namespace mapping) is
		// never required. Both sides are resolved, so a plugin directory that
		// is itself a symlink still resolves its own classes.
		if ( false === $base || false === $file || ! str_starts_with( $file, $base . DIRECTORY_SEPARATOR ) ) {
			return;
		}
		require $file;
	}
);
