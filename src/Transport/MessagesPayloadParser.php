<?php
/**
 * Messages-family payload builder and response parser.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates chat-style request data to the Anthropic Messages wire shape
 * and extracts assistant text from a Messages envelope.
 *
 * The Messages family takes `system` separately from `messages` and serves
 * the credential on `x-api-key` (see EndpointAuth); the Anthropic version
 * header is applied there as well. Both directions degrade, never fatal.
 * Never throws.
 */
final class MessagesPayloadParser {

	/**
	 * Build a Messages request payload from chat-style data.
	 *
	 * A leading `system` message is lifted to the top-level `system`
	 * string; every other message passes through. Tools pass through
	 * unchanged. Never throws.
	 *
	 * @param string $model_id Verified model ID.
	 * @param array  $data     Chat-style request data.
	 * @return array Messages wire payload.
	 */
	public static function buildPayload( string $model_id, array $data ): array {
		try {
			$payload = array( 'model' => $model_id );

			$messages = $data['messages'] ?? null;
			if ( is_array( $messages ) ) {
				$system = array();
				$rest   = array();
				foreach ( $messages as $message ) {
					if ( is_array( $message ) && 'system' === ( $message['role'] ?? null ) ) {
						$content = $message['content'] ?? null;
						if ( is_string( $content ) && '' !== $content ) {
							$system[] = $content;
						}
						continue;
					}
					$rest[] = $message;
				}
				if ( array() !== $system ) {
					$payload['system'] = implode( "\n", $system );
				}
				$payload['messages'] = $rest;
			}

			if ( isset( $data['system'] ) && is_string( $data['system'] ) && '' !== $data['system'] && ! isset( $payload['system'] ) ) {
				$payload['system'] = $data['system'];
			}

			foreach ( array( 'tools', 'tool_choice', 'temperature', 'top_p', 'top_k', 'max_tokens', 'stream' ) as $key ) {
				if ( array_key_exists( $key, $data ) ) {
					$payload[ $key ] = $data[ $key ];
				}
			}

			return $payload;
		} catch ( \Throwable ) {
			return array( 'model' => $model_id );
		}
	}

	/**
	 * Extract assistant text from a Messages envelope.
	 *
	 * Reads `content[]` blocks carrying a `text` string (`text` blocks,
	 * then any block with text). Anything else reads as empty text. Never
	 * throws.
	 *
	 * @param mixed $data Decoded response data.
	 * @return string Assistant text, or an empty string when absent.
	 */
	public static function extractText( $data ): string {
		try {
			if ( ! is_array( $data ) ) {
				return '';
			}

			$content = $data['content'] ?? null;
			if ( ! is_array( $content ) ) {
				return '';
			}

			$texts = array();
			foreach ( $content as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$text = $block['text'] ?? null;
				if ( is_string( $text ) && '' !== $text ) {
					$texts[] = $text;
				}
			}

			return implode( '', $texts );
		} catch ( \Throwable ) {
			return '';
		}
	}
}
