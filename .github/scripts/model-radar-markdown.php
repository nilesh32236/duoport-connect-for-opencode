<?php
/**
 * Render an existing credential-free Model Radar JSON report as Markdown.
 *
 * Usage: php model-radar-markdown.php < model-radar.json
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

$repo_root = dirname( __DIR__, 2 );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $repo_root . '/' );
}
require_once $repo_root . '/src/autoload.php';

$raw = stream_get_contents( STDIN );
$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
if ( ! is_array( $data ) ) {
	fwrite( STDERR, "Model Radar JSON input is invalid.\n" );
	exit( 1 );
}

$radar = new \OpenCodeConnector\Metadata\ModelRadar();
echo $radar->markdown( $data ), "\n";
