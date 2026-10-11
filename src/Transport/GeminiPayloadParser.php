<?php
/**
 * Gemini-family payload builder and response parser.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates chat-style request data to the native Gemini generateContent
 * wire shape and extracts assistant text from a generateContent envelope.
 *
 * The Gemini family is a model-scoped path (`models/{id}:generateContent`,
 * see EndpointRoute) served with `x-goog-api-key` (see EndpointAuth), and
 * its request carries `contents`/`systemInstruction` instead of
 * `messages`. Both directions degrade, never fatal. Never throws.
 */
final class GeminiPayloadParser {

	/**
	 * Build a generateContent request payload from chat-style data.
	 *
	 * Chat roles map to Gemini roles (`assistant` becomes `model`);
	 * `system` messages become `systemInstruction`. Generation knobs pass
	 * through as `generationConfig`. Never throws.
	 *
	 * @param string $model_id Verified model ID.
	 * @param array  $data     Chat-style request data.
	 * @return array generateContent wire payload.
	 */
	public static function buildPayload( string $model_id, array $data ): array {
		try {
			unset( $model_id );

			$payload  = array();
			$contents = array();

			$messages = $data['messages'] ?? null;
			if ( is_array( $messages ) ) {
				foreach ( $messages as $message ) {
					if ( ! is_array( $message ) ) {
						continue;
					}
					$role = (string) ( $message['role'] ?? 'user' );
					if ( 'assistant' === $role ) {
						$role = 'model';
					} elseif ( 'system' !== $role && 'user' !== $role && 'model' !== $role ) {
						$role = 'user';
					}
					if ( 'system' === $role ) {
						continue;
					}
					$content = $message['content'] ?? null;
					if ( ! is_string( $content ) || '' === $content ) {
						continue;
					}
					$contents[] = array(
						'role'  => $role,
						'parts' => array( array( 'text' => $content ) ),
					);
				}
			}

			if ( array() !== $contents ) {
				$payload['contents'] = $contents;
			}

			$system = self::system_text( $data );
			if ( '' !== $system ) {
				$payload['systemInstruction'] = array(
					'parts' => array( array( 'text' => $system ) ),
				);
			}

			$config = array();
			if ( array_key_exists( 'temperature', $data ) ) {
				$config['temperature'] = $data['temperature'];
			}
			if ( array_key_exists( 'top_p', $data ) ) {
				$config['topP'] = $data['top_p'];
			}
			if ( array_key_exists( 'max_tokens', $data ) ) {
				$config['maxOutputTokens'] = $data['max_tokens'];
			}
			if ( array() !== $config ) {
				$payload['generationConfig'] = $config;
			}

			return $payload;
		} catch ( \Throwable ) {
			return array();
		}
	}

	/**
	 * Extract assistant text from a generateContent envelope.
	 *
	 * Reads `candidates[].content.parts[]` text parts in order. Anything
	 * else reads as empty text. Never throws.
	 *
	 * @param mixed $data Decoded response data.
	 * @return string Assistant text, or an empty string when absent.
	 */
	public static function extractText( $data ): string {
		try {
			if ( ! is_array( $data ) ) {
				return '';
			}

			$candidates = $data['candidates'] ?? null;
			if ( ! is_array( $candidates ) ) {
				return '';
			}

			$texts = array();
			foreach ( $candidates as $candidate ) {
				if ( ! is_array( $candidate ) ) {
					continue;
				}
				$content = $candidate['content'] ?? null;
				if ( ! is_array( $content ) ) {
					continue;
				}
				$parts = $content['parts'] ?? null;
				if ( ! is_array( $parts ) ) {
					continue;
				}
				foreach ( $parts as $part ) {
					if ( ! is_array( $part ) ) {
						continue;
					}
					$text = $part['text'] ?? null;
					if ( is_string( $text ) && '' !== $text ) {
						$texts[] = $text;
					}
				}
			}

			return implode( '', $texts );
		} catch ( \Throwable ) {
			return '';
		}
	}

	/**
	 * Collect the system text from chat-style data.
	 *
	 * @param array $data Chat-style request data.
	 * @return string System text, or an empty string when absent.
	 */
	private static function system_text( array $data ): string {
		$system = $data['system'] ?? null;
		if ( is_string( $system ) && '' !== $system ) {
			return $system;
		}

		$messages = $data['messages'] ?? null;
		if ( ! is_array( $messages ) ) {
			return '';
		}

		$texts = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || 'system' !== ( $message['role'] ?? null ) ) {
				continue;
			}
			$content = $message['content'] ?? null;
			if ( is_string( $content ) && '' !== $content ) {
				$texts[] = $content;
			}
		}

		return implode( "\n", $texts );
	}
}
