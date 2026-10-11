<?php
/**
 * Responses-family payload builder and response parser.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates chat-style request data to the OpenAI Responses wire shape and
 * extracts assistant text from a Responses envelope.
 *
 * Both directions degrade, never fatal: a malformed request still carries
 * the model so the gateway answers with a typed error, and an
 * unrecognised response reads as empty text. Never throws.
 */
final class ResponsesPayloadParser {

	/**
	 * Build a Responses request payload from chat-style data.
	 *
	 * The Responses family takes `input` where chat completions takes
	 * `messages`; tools and structured-output shapes pass through
	 * unchanged. Never throws.
	 *
	 * @param string $model_id Verified model ID.
	 * @param array  $data     Chat-style request data.
	 * @return array Responses wire payload.
	 */
	public static function buildPayload( string $model_id, array $data ): array {
		try {
			$payload = array( 'model' => $model_id );

			$input = $data['input'] ?? $data['messages'] ?? null;
			if ( null !== $input ) {
				$payload['input'] = $input;
			}

			foreach ( array( 'tools', 'tool_choice', 'response_format', 'max_tokens', 'max_output_tokens', 'temperature', 'top_p', 'stream' ) as $key ) {
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
	 * Extract assistant text from a Responses envelope.
	 *
	 * Reads `output[]` message items and their `content[]` text parts
	 * (`output_text` parts, then any part carrying a `text` string), then
	 * the top-level `output_text` convenience shape. Anything else reads
	 * as empty text. Never throws.
	 *
	 * @param mixed $data Decoded response data.
	 * @return string Assistant text, or an empty string when absent.
	 */
	public static function extractText( $data ): string {
		try {
			if ( ! is_array( $data ) ) {
				return '';
			}

			$texts = array();

			$output = $data['output'] ?? null;
			if ( is_array( $output ) ) {
				foreach ( $output as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$content = $item['content'] ?? null;
					if ( ! is_array( $content ) ) {
						continue;
					}
					foreach ( $content as $part ) {
						if ( ! is_array( $part ) ) {
							continue;
						}
						$text = $part['text'] ?? null;
						if ( is_string( $text ) && '' !== $text ) {
							$texts[] = $text;
						}
					}
				}
			}

			if ( array() !== $texts ) {
				return implode( '', $texts );
			}

			$flat = $data['output_text'] ?? null;
			if ( is_string( $flat ) ) {
				return $flat;
			}
			if ( is_array( $flat ) ) {
				$joined = '';
				foreach ( $flat as $chunk ) {
					if ( is_string( $chunk ) ) {
						$joined .= $chunk;
					}
				}
				return $joined;
			}

			return '';
		} catch ( \Throwable ) {
			return '';
		}
	}
}
