<?php
/**
 * Gemini API Client — handles communication with Google Gemini API.
 *
 * @package Schema_AI
 */

defined( 'ABSPATH' ) || exit;

class Schema_AI_Gemini {

	/**
	 * Gemini API base URL.
	 */
	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * Maximum content characters (~6000 tokens).
	 */
	const MAX_CONTENT_CHARS = 24000;

	/**
	 * Initialize hooks.
	 */
	public function init(): void {
		// Reserved for future hooks.
	}

	/**
	 * Generate schema using Gemini API.
	 *
	 * @param string $prompt The prompt to send to Gemini.
	 * @return array{success: bool, data?: array, error?: string, tokens_used?: int, model?: string}
	 */
	public static function generate( string $prompt ): array {
		$api_key = Schema_AI_Core::get_api_key();

		if ( empty( $api_key ) ) {
			return array(
				'success' => false,
				'error'   => __( 'API key is not configured.', 'schema-ai' ),
			);
		}

		$model = get_option( 'schema_ai_model', 'gemini-2.0-flash' );
		$url   = self::API_BASE . $model . ':generateContent';

		$body = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'      => 0.1,
				'maxOutputTokens'  => 4096,
				'responseMimeType' => 'application/json',
			),
		);

		// First attempt.
		$result = self::make_request( $url, $body, $api_key );

		if ( ! $result['success'] ) {
			// Retry once after 2 seconds.
			sleep( 2 );
			$result = self::make_request( $url, $body, $api_key );
		}

		if ( ! $result['success'] ) {
			return $result;
		}

		$text = $result['text'];

		// Try to parse JSON from response.
		$parsed = self::parse_json_response( $text );

		if ( null !== $parsed ) {
			return array(
				'success'     => true,
				'data'        => $parsed,
				'tokens_used' => $result['tokens_used'] ?? 0,
				'model'       => $model,
			);
		}

		// Retry with explicit JSON instruction.
		$retry_body = $body;
		$retry_body['contents'][0]['parts'][0]['text'] = $prompt . "\n\nIMPORTANT: Return raw JSON only. No markdown, no code fences, no explanation.";

		$retry_result = self::make_request( $url, $retry_body, $api_key );

		if ( $retry_result['success'] ) {
			$retry_parsed = self::parse_json_response( $retry_result['text'] );

			if ( null !== $retry_parsed ) {
				return array(
					'success'     => true,
					'data'        => $retry_parsed,
					'tokens_used' => ( $result['tokens_used'] ?? 0 ) + ( $retry_result['tokens_used'] ?? 0 ),
					'model'       => $model,
				);
			}
		}

		return array(
			'success' => false,
			'error'   => sprintf(
				/* translators: %s: first 200 characters of response text */
				__( 'Failed to parse JSON from Gemini response: %s', 'schema-ai' ),
				substr( $text, 0, 200 )
			),
		);
	}

	/**
	 * Make an HTTP request to the Gemini API.
	 *
	 * @param string $url     Full API URL.
	 * @param array  $body    Request body.
	 * @param string $api_key API key.
	 * @return array{success: bool, text?: string, tokens_used?: int, error?: string}
	 */
	private static function make_request( string $url, array $body, string $api_key ): array {
		$backoff_delays = array( 2, 4, 8 );
		$attempt        = 0;

		do {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 60,
					'headers' => array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $api_key,
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'error'   => $response->get_error_message(),
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );

			// Handle rate limiting with exponential backoff.
			if ( 429 === $status_code && $attempt < count( $backoff_delays ) ) {
				sleep( $backoff_delays[ $attempt ] );
				$attempt++;
				continue;
			}

			break;
		} while ( true );

		if ( 200 !== $status_code ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Gemini API returned status %d.', 'schema-ai' ),
					$status_code
				),
			);
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		$text = $response_body['candidates'][0]['content']['parts'][0]['text'] ?? null;

		if ( null === $text ) {
			return array(
				'success' => false,
				'error'   => __( 'No text in Gemini API response.', 'schema-ai' ),
			);
		}

		$tokens_used = $response_body['usageMetadata']['totalTokenCount'] ?? 0;

		return array(
			'success'     => true,
			'text'        => $text,
			'tokens_used' => $tokens_used,
		);
	}

	/**
	 * Parse JSON from API response text.
	 *
	 * Tries direct decode, regex extraction from code fences, and JSON boundary detection.
	 *
	 * @param string $text Response text.
	 * @return array|null Parsed array or null on failure.
	 */
	public static function parse_json_response( string $text ): ?array {
		$text = trim( $text );

		// Try direct JSON decode.
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Try extracting from ```json ... ``` fences.
		if ( preg_match( '/```json\s*(.*?)\s*```/s', $text, $matches ) ) {
			$decoded = json_decode( $matches[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Try finding JSON boundaries { ... }.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false !== $start && false !== $end && $end > $start ) {
			$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Truncate content to fit within token limits.
	 *
	 * @param string $content Raw content (may contain HTML).
	 * @return array{content: string, truncated: bool}
	 */
	public static function truncate_content( string $content ): array {
		$plain_text = wp_strip_all_tags( $content );

		if ( mb_strlen( $plain_text ) <= self::MAX_CONTENT_CHARS ) {
			return array(
				'content'   => $content,
				'truncated' => false,
			);
		}

		// Truncate proportionally: ratio of max chars to plain text length.
		$ratio          = self::MAX_CONTENT_CHARS / mb_strlen( $plain_text );
		$truncated_len  = (int) ( mb_strlen( $content ) * $ratio );
		$truncated_text = mb_substr( $content, 0, $truncated_len );

		return array(
			'content'   => $truncated_text,
			'truncated' => true,
		);
	}
}
