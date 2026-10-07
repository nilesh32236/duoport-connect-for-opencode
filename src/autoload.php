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
		$rel = substr( $class_name, strlen( __NAMESPACE__ ) + 1 );
		if ( '' === $rel ) {
			return;
		}
		$rel = str_replace( '\\', '/', $rel );
		// PSR-4 maps a namespace remainder onto a relative filesystem path, and
		// that remainder is whatever a caller passed to `new $var()` or
		// call_user_func(). Turning it into a path with no check on its segments
		// means a name containing `..` resolves OUTSIDE this directory and is
		// require()d from there. Reaching it needs an attacker to control a
		// dynamically-constructed class name, which normally implies execution
		// already — so this is hardening, not a fix for a live hole. It is still
		// the one place in the plugin where untrusted-shaped input reaches a
		// require, and it costs three lines to close.
		//
		// Only plain identifiers may appear in a PSR-4 remainder, so requiring
		// that is both sufficient and necessary: every class this plugin ships
		// satisfies it, and `..`, `.`, empty segments, absolute paths, NUL bytes
		// and stream wrappers all cannot.
		foreach ( explode( '/', $rel ) as $segment ) {
			if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $segment ) ) {
				return;
			}
		}
		$file = __DIR__ . '/' . $rel . '.php';
		$real = realpath( $file );
		// Belt and braces: assert the resolved path is inside __DIR__ before
		// including anything. realpath() also resolves symlinks, which the
		// segment check above deliberately does not try to reason about.
		if ( false === $real || ! str_starts_with( $real, __DIR__ . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
			return;
		}
		require $real;
	}
);
