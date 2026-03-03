<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Gemini engine.
 *
 * Non-streaming: POST /v1beta/models/{model}:generateContent?key={apiKey}
 * Streaming:     POST /v1beta/models/{model}:streamGenerateContent?key={apiKey}&alt=sse
 *
 * Response format differs from OpenAI/Anthropic — handled in parse_stream_chunk().
 */
class WPC_Engine_Google extends WPC_Engine_Core {

	const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

	/* ── Generate SQL ───────────────────────────────────────────── */

	public function generate_sql( string $user_query, string $schema, ?callable $on_chunk = null ): array|WP_Error {
		$system_prompt = $this->build_system_prompt( $schema );
		$is_streaming  = ! is_null( $on_chunk );

		$headers = [
			'Content-Type' => 'application/json',
		];

		// Gemini puts the API key as a query parameter
		$action = $is_streaming ? 'streamGenerateContent' : 'generateContent';
		$url    = self::BASE_URL . '/' . rawurlencode( $this->model ) . ':' . $action
					. '?key=' . rawurlencode( $this->api_key );
		if ( $is_streaming ) {
			$url .= '&alt=sse';
		}

		$body = [
			// System instruction is a separate top-level field for Gemini
			'system_instruction' => [
				'parts' => [
					[ 'text' => $system_prompt ],
				],
			],
			'contents'           => [
				[
					'role'  => 'user',
					'parts' => [
						[ 'text' => $user_query ],
					],
				],
			],
			'generationConfig'   => array_merge(
				[
					// Gemini 2.5 Pro uses thinking tokens that count against maxOutputTokens.
					// Set high enough so actual SQL output isn't truncated after thinking.
					'maxOutputTokens'  => 8192,
					'temperature'      => 0.0,
					// Ask Gemini to return plain JSON (no markdown)
					'responseMimeType' => 'application/json',
				],
				// For Flash models: disable thinking to reduce latency.
				// Pro models don't support thinkingBudget:0 and will return HTTP 400.
				str_contains( $this->model, 'flash' ) ? [ 'thinkingConfig' => [ 'thinkingBudget' => 0 ] ] : []
			),
		];

		WPC_Logger::log( "Google request: model={$this->model}, streaming=" . ( $is_streaming ? 'yes' : 'no' ) );

		$result = $this->post( $url, $headers, $body, $on_chunk );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/* ── Non-streaming ──────────────────────────────────────── */
		if ( ! $is_streaming ) {
			$usage            = $result['usageMetadata'] ?? [];
			$this->in_tokens  = $usage['promptTokenCount'] ?? 0;
			$this->out_tokens = $usage['candidatesTokenCount'] ?? 0;

			// Skip thought parts (Gemini 2.5 Pro thinking mode)
			$text = '';
			foreach ( $result['candidates'][0]['content']['parts'] ?? [] as $part ) {
				if ( ! empty( $part['thought'] ) ) {
					continue;
				}
				$text = $part['text'] ?? '';
				break;
			}
			return $this->parse_ai_json( $text );
		}

		/* ── Streaming ──────────────────────────────────────────── */
		return $this->parse_ai_json( $this->stream_content );
	}

	public function complete_text( string $system_prompt, string $user_prompt ): string|WP_Error {
		$url = self::BASE_URL . '/' . rawurlencode( $this->model ) . ':generateContent'
			. '?key=' . rawurlencode( $this->api_key );

		$body = [
			'system_instruction' => [
				'parts' => [
					[ 'text' => $system_prompt ],
				],
			],
			'contents'           => [
				[
					'role'  => 'user',
					'parts' => [
						[ 'text' => $user_prompt ],
					],
				],
			],
			'generationConfig'   => array_merge(
				[
					'maxOutputTokens' => 4096,
					'temperature'     => 0.1,
				],
				str_contains( $this->model, 'flash' ) ? [ 'thinkingConfig' => [ 'thinkingBudget' => 0 ] ] : []
			),
		];

		$result = $this->post( $url, [ 'Content-Type' => 'application/json' ], $body, null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$usage             = $result['usageMetadata'] ?? [];
		$this->in_tokens  += $usage['promptTokenCount'] ?? 0;
		$this->out_tokens += $usage['candidatesTokenCount'] ?? 0;
		return trim( (string) ( $result['candidates'][0]['content']['parts'][0]['text'] ?? '' ) );
	}

	/* ── Parse streaming chunk ──────────────────────────────────── */

	protected function parse_stream_chunk( array $json ): ?string {
		// Token usage — appears in the final chunk
		if ( isset( $json['usageMetadata'] ) ) {
			$meta             = $json['usageMetadata'];
			$this->in_tokens  = $meta['promptTokenCount'] ?? $this->in_tokens;
			$this->out_tokens = $meta['candidatesTokenCount'] ?? $this->out_tokens;
		}

		// Find the first non-thought part (Gemini 2.5 Pro thinking mode sends
		// parts with "thought":true that must be excluded from the JSON output)
		$parts = $json['candidates'][0]['content']['parts'] ?? [];
		foreach ( $parts as $part ) {
			if ( ! empty( $part['thought'] ) ) {
				continue;
			}
			return $part['text'] ?? null;
		}
		return null;
	}

	/* ── Override: Google error format is different ─────────────── */

	protected function post( string $url, array $headers, array $body, ?callable $on_chunk ): array|WP_Error {
		$this->reset_stream();
		$is_streaming = ! is_null( $on_chunk );

		if ( $is_streaming ) {
			$this->stream_callback = $on_chunk;
			add_action( 'http_api_curl', [ $this, 'stream_handler' ], 10, 3 );
		}

		try {
			$encoded_body = $this->safe_json_encode( $body );
		} catch ( \RuntimeException $e ) {
			return new WP_Error(
				'json_encode_error',
				sprintf(
					/* translators: %s: json encoding error message */
					__( 'Failed to encode API request body: %s', 'data-query-assistant' ),
					$e->getMessage()
				)
			);
		}

		$options = [
			'method'    => 'POST',
			'timeout'   => WPC_TIMEOUT,
			'sslverify' => true,
			'headers'   => $headers,
			'body'      => $encoded_body,
		];

		$response = wp_remote_post( $url, $options );

		if ( $is_streaming ) {
			remove_action( 'http_api_curl', [ $this, 'stream_handler' ], 10 );
			$this->stream_callback = null;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$resp_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			// Google wraps errors in {error: {code, message, status}}
			$msg = is_array( $resp_body ) ? ( $resp_body['error']['message'] ?? null ) : null;
			if ( empty( $msg ) ) {
				$msg = 'Google API error (HTTP ' . $code . ')';
			}
			return new WP_Error( 'api_error', $msg );
		}

		// For streaming, Google SSE returns an array of candidates at the top level
		// wrapped in an array (each SSE chunk is a full JSON candidate).
		// wp_remote_post captures the final (non-streamed) response body, which for
		// streamGenerateContent is a JSON array of response objects.
		if ( $is_streaming ) {
			// Streamed content was accumulated via stream_handler
			return [];
		}

		return $resp_body;
	}
}
