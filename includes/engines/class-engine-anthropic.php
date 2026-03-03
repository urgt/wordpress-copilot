<?php
defined( 'ABSPATH' ) || exit;

class WPC_Engine_Anthropic extends WPC_Engine_Core {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/* ── Generate SQL ───────────────────────────────────────────── */

	public function generate_sql( string $user_query, string $schema, ?callable $on_chunk = null ): array|WP_Error {
		$system_prompt = $this->build_system_prompt( $schema );
		$is_streaming  = ! is_null( $on_chunk );

		$headers = [
			'Content-Type'      => 'application/json',
			'x-api-key'         => $this->api_key,
			'anthropic-version' => '2023-06-01',
			// prompt-caching: the large schema block gets cached between requests
			'anthropic-beta'    => 'prompt-caching-2024-07-31',
		];

		$body = [
			'model'      => $this->model,
			'max_tokens' => 2048,
			'stream'     => $is_streaming,
			// Cache the system prompt (schema is large and static — ideal for caching)
			'system'     => [
				[
					'type'          => 'text',
					'text'          => $system_prompt,
					'cache_control' => [ 'type' => 'ephemeral' ],  // ← AI Engine pattern
				],
			],
			'messages'   => [
				[
					'role'    => 'user',
					'content' => $user_query,
				],
			],
		];

		WPC_Logger::log( "Anthropic request: model={$this->model}, streaming=" . ( $is_streaming ? 'yes' : 'no' ) );

		$result = $this->post( self::ENDPOINT, $headers, $body, $on_chunk );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/* ── Non-streaming: parse response directly ─────────────── */
		if ( ! $is_streaming ) {
			$this->in_tokens  = $result['usage']['input_tokens'] ?? 0;
			$this->out_tokens = $result['usage']['output_tokens'] ?? 0;
			$text             = $result['content'][0]['text'] ?? '';
			return $this->parse_ai_json( $text );
		}

		/* ── Streaming: content was accumulated in stream_content ── */
		return $this->parse_ai_json( $this->stream_content );
	}

	public function complete_text( string $system_prompt, string $user_prompt ): string|WP_Error {
		$headers = [
			'Content-Type'      => 'application/json',
			'x-api-key'         => $this->api_key,
			'anthropic-version' => '2023-06-01',
		];

		$body = [
			'model'      => $this->model,
			'max_tokens' => 2048,
			'stream'     => false,
			'system'     => $system_prompt,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => $user_prompt,
				],
			],
		];

		$result = $this->post( self::ENDPOINT, $headers, $body, null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->in_tokens  += $result['usage']['input_tokens'] ?? 0;
		$this->out_tokens += $result['usage']['output_tokens'] ?? 0;
		return trim( (string) ( $result['content'][0]['text'] ?? '' ) );
	}

	/* ── Parse streaming chunk ──────────────────────────────────── */

	protected function parse_stream_chunk( array $json ): ?string {
		$type = $json['type'] ?? null;

		if ( 'message_start' === $type ) {
			$this->in_tokens = $json['message']['usage']['input_tokens'] ?? 0;
		}

		if ( 'message_delta' === $type ) {
			$this->out_tokens = $json['usage']['output_tokens'] ?? 0;
		}

		if ( 'content_block_delta' === $type
			&& isset( $json['delta']['type'] )
			&& 'text_delta' === $json['delta']['type'] ) {
			return $json['delta']['text'] ?? null;
		}

		return null;
	}
}
