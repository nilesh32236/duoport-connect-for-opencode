<?php
/**
 * SystemOne-family payload builder and response parser.
 *
 * @package OpenCodeConnector
 */

declare(strict_types=1);

namespace OpenCodeConnector\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates chat-style request data to the SystemOne decision wire shape
 * and extracts decision text from a SystemOne envelope.
 *
 * SystemOne is a structured decision endpoint, not a text-generation
 * model: it is never advertised as `text_generation` (no registry record
 * maps there), and this parser only exists so a verified route degrades
 * instead of fataling. Both directions degrade, never fatal. Never throws.
 */
final class SystemOnePayloadParser {

	/**
	 * Build a SystemOne request payload from chat-style data.
	 *
	 * The decision endpoint takes `input` where chat completions takes
	 * `messages`. Never throws.
	 *
	 * @param string $model_id Verified model ID.
	 * @param array  $data     Chat-style request data.
	 * @return array SystemOne wire payload.
	 */
	public static function buildPayload( string $model_id, array $data ): array {
		try {
			$payload = array( 'model' => $model_id );

			$input = $data['input'] ?? $data['messages'] ?? null;
			if ( null !== $input ) {
				$payload['input'] = $input;
			}

			return $payload;
		} catch ( \Throwable ) {
			return array( 'model' => $model_id );
		}
	}

	/**
	 * Extract decision text from a SystemOne envelope.
	 *
	 * Reads the `output_text` convenience shape, then `output[]` message
	 * items and their `content[]` text parts, then a `decision.text`
	 * shape. Anything else reads as empty text. Never throws.
	 *
	 * @param mixed $data Decoded response data.
	 * @return string Decision text, or an empty string when absent.
	 */
	public static function extractText( $data ): string {
		try {
			if ( ! is_array( $data ) ) {
				return '';
			}

			$flat = $data['output_text'] ?? null;
			if ( is_string( $flat ) && '' !== $flat ) {
				return $flat;
			}

			$output = $data['output'] ?? null;
			if ( is_array( $output ) ) {
				$texts = array();
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
				if ( array() !== $texts ) {
					return implode( '', $texts );
				}
			}

			$decision = $data['decision'] ?? null;
			if ( is_array( $decision ) ) {
				$text = $decision['text'] ?? null;
				if ( is_string( $text ) ) {
					return $text;
				}
			}

			return '';
		} catch ( \Throwable ) {
			return '';
		}
	}
}
