<?php
defined( 'ABSPATH' ) || exit;

class WPC_Engine_OpenAI extends WPC_Engine_Core {

    const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /* ── Generate SQL ───────────────────────────────────────────── */

    public function generate_sql( string $user_query, string $schema, ?callable $on_chunk = null ): array|WP_Error {
        $system_prompt = $this->build_system_prompt( $schema );
        $is_streaming  = ! is_null( $on_chunk );

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
        ];

        $body = [
            'model'       => $this->model,
            'max_tokens'  => 2048,
            'stream'      => $is_streaming,
            'messages'    => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $user_query    ],
            ],
        ];

        if ( $is_streaming ) {
            // stream_options lets us get usage in the final [DONE] chunk
            $body['stream_options'] = [ 'include_usage' => true ];
        }

        WPC_Logger::log( "OpenAI request: model={$this->model}, streaming=" . ($is_streaming ? 'yes' : 'no') );

        $result = $this->post( self::ENDPOINT, $headers, $body, $on_chunk );

        if ( is_wp_error( $result ) ) return $result;

        /* ── Non-streaming ──────────────────────────────────────── */
        if ( ! $is_streaming ) {
            $this->in_tokens  = $result['usage']['prompt_tokens']     ?? 0;
            $this->out_tokens = $result['usage']['completion_tokens'] ?? 0;
            $text = $result['choices'][0]['message']['content'] ?? '';
            return $this->parse_ai_json( $text );
        }

        /* ── Streaming ──────────────────────────────────────────── */
        return $this->parse_ai_json( $this->stream_content );
    }

    public function complete_text( string $system_prompt, string $user_prompt ): string|WP_Error {
        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
        ];

        $body = [
            'model'      => $this->model,
            'max_tokens' => 2048,
            'messages'   => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
        ];

        $result = $this->post( self::ENDPOINT, $headers, $body, null );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->in_tokens  += $result['usage']['prompt_tokens']     ?? 0;
        $this->out_tokens += $result['usage']['completion_tokens'] ?? 0;
        return trim( (string) ( $result['choices'][0]['message']['content'] ?? '' ) );
    }

    /* ── Parse streaming chunk ──────────────────────────────────── */

    protected function parse_stream_chunk( array $json ): ?string {
        // Final usage chunk (stream_options: include_usage)
        if ( isset( $json['usage'] ) ) {
            $this->in_tokens  = $json['usage']['prompt_tokens']     ?? $this->in_tokens;
            $this->out_tokens = $json['usage']['completion_tokens'] ?? $this->out_tokens;
        }

        $delta = $json['choices'][0]['delta'] ?? null;
        if ( $delta && isset( $delta['content'] ) ) {
            return $delta['content'];
        }

        return null;
    }
}
