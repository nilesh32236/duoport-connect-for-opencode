<?php
/**
 * Centralized OpenCode gateway URLs.
 *
 * Single source of truth for gateway hosts, per-catalog bases, and well-known
 * paths so a host or path change means one edit instead of coordinated edits
 * across providers, the model radar, and the availability probe.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */

declare(strict_types=1);

namespace OpenCodeConnector\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OpenCodeConnector\Metadata\Catalog;

/**
 * Centralized OpenCode gateway URLs.
 *
 * @package OpenCodeConnector
 * @since 0.1.6
 */
final class Endpoints {

	/**
	 * Gateway host.
	 *
	 * @since 0.1.6
	 */
	public const HOST = 'https://opencode.ai';

	/**
	 * API-key management URL.
	 *
	 * @since 0.1.6
	 */
	public const AUTH_URL = 'https://opencode.ai/auth';

	/**
	 * Chat completions path.
	 *
	 * @since 0.1.6
	 */
	public const CHAT_COMPLETIONS_PATH = 'chat/completions';

	/**
	 * Models listing path appended to a catalog base URL.
	 *
	 * @since 0.1.6
	 */
	public const MODELS_PATH = 'models';

	/**
	 * Per-catalog API base URLs.
	 *
	 * @since 0.1.6
	 *
	 * @var array<string, string>
	 */
	private const BASES = array(
		Catalog::GO  => 'https://opencode.ai/zen/go/v1',
		Catalog::ZEN => 'https://opencode.ai/zen/v1',
	);

	/**
	 * API base URL for a catalog.
	 *
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return string
	 */
	public static function base( string $catalog ): string {
		if ( Catalog::GO === $catalog ) {
			return self::BASES[ Catalog::GO ];
		}
		if ( Catalog::ZEN === $catalog ) {
			return self::BASES[ Catalog::ZEN ];
		}
		return self::HOST;
	}

	/**
	 * Full /models listing URL for a catalog.
	 *
	 * @since 0.1.6
	 *
	 * @param string $catalog Catalog slug.
	 * @return string
	 */
	public static function modelsUrl( string $catalog ): string {
		return rtrim( self::base( $catalog ), '/' ) . '/' . self::MODELS_PATH;
	}

	/**
	 * Catalog source URLs used by the shipped radar report.
	 *
	 * @since 0.1.6
	 *
	 * @return array<string, string>
	 */
	public static function sources(): array {
		$sources = array();
		foreach ( Catalog::all() as $catalog ) {
			$sources[ $catalog ] = self::modelsUrl( $catalog );
		}
		return $sources;
	}

	/**
	 * API-key management URL.
	 *
	 * @since 0.1.6
	 *
	 * @return string
	 */
	public static function authUrl(): string {
		return self::AUTH_URL;
	}

	/**
	 * Chat completions path.
	 *
	 * @since 0.1.6
	 *
	 * @return string
	 */
	public static function chatPath(): string {
		return self::CHAT_COMPLETIONS_PATH;
	}
}
