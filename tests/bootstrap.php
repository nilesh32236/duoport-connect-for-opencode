<?php
/**
 * PHPUnit bootstrap for the OpenCode Connector test suite.
 *
 * Defines ABSPATH the way WordPress would (pointed at a temp dir, since no
 * WP core is loaded in unit tests) and wires up the Composer autoloader so
 * PHPUnit and Brain Monkey are available.
 *
 * @package OpenCodeConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

require_once __DIR__ . '/../vendor/autoload.php';
