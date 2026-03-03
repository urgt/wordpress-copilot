<?php
defined( 'ABSPATH' ) || exit;

/**
 * Abstract base engine.
 *
 * Streaming works exactly like AI Engine:
 *  1. add_action('http_api_curl', [$this, 'stream_handler']) — hooks into WP HTTP cURL
 *  2. CURLOPT_WRITEFUNCTION intercepts each data chunk
 *  3. Each chunk is parsed and streamed to the browser via SSE (echo + flush)
 */
abstract class WPC_Engine_Core {

    protected string $api_key   = '';
    protected string $model     = '';
    protected int    $max_rows  = 100;

    // Streaming state (reset before each request)
    /** @var callable|null */
    protected $stream_callback    = null;
    protected string    $stream_temp_buffer = '';
    protected string    $stream_content     = '';
    protected int       $in_tokens          = 0;
    protected int       $out_tokens         = 0;

    public function __construct( string $api_key, string $model, int $max_rows ) {
        $this->api_key  = $api_key;
        $this->model    = $model;
        $this->max_rows = $max_rows;
    }

    /* ── Public interface ───────────────────────────────────────── */

    /**
     * Generate SQL + explanation from a natural language query.
     *
     * @param string        $user_query   Natural language question.
     * @param string        $schema       Full DB schema prompt.
     * @param callable|null $on_chunk     If set, stream tokens in real-time: fn(string $token)
     * @return array{sql:string, explanation:string, in_tokens:int, out_tokens:int}|WP_Error
     */
    abstract public function generate_sql( string $user_query, string $schema, ?callable $on_chunk = null );

    /**
     * Generate a plain-text answer for post-processing (summaries/insights).
     *
     * @return string|WP_Error
     */
    abstract public function complete_text( string $system_prompt, string $user_prompt );

    /* ── Streaming (AI Engine pattern) ─────────────────────────── */

    /**
     * WordPress http_api_curl hook — intercepts the cURL handle to capture streaming data.
     */
    public function stream_handler( $handle, array $args, string $url ): void {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Must use curl_setopt directly to install CURLOPT_WRITEFUNCTION on WP's managed curl handle.
        curl_setopt( $handle, CURLOPT_WRITEFUNCTION, function ( $curl, $data ) {
            $length = strlen( $data );
            $this->stream_temp_buffer .= $data;

            $lines = explode( "\n", $this->stream_temp_buffer );

            // If buffer doesn't end with newline, last element is incomplete — keep it
            if ( substr( $this->stream_temp_buffer, -1 ) !== "\n" ) {
                $this->stream_temp_buffer = array_pop( $lines );
            } else {
                $this->stream_temp_buffer = '';
            }

            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( $line === '' || $line === 'data: [DONE]' ) continue;

                if ( strpos( $line, 'data:' ) === 0 ) {
                    $raw  = trim( substr( $line, 5 ) );
                    $json = json_decode( $raw, true );
                    if ( json_last_error() === JSON_ERROR_NONE ) {
                        $token = $this->parse_stream_chunk( $json );
                        if ( $token !== null && $this->stream_callback ) {
                            $this->stream_content .= $token;
                            ( $this->stream_callback )( $token );
                        }
                    }
                }
            }

            return $length;
        });
    }

    /**
     * Parse a single SSE JSON chunk and extract the text token.
     * Override in subclass for provider-specific format.
     */
    abstract protected function parse_stream_chunk( array $json ): ?string;

    /* ── HTTP helpers ───────────────────────────────────────────── */

    protected function post( string $url, array $headers, array $body, ?callable $on_chunk ): array|WP_Error {
        $this->reset_stream();
        $is_streaming = ! is_null( $on_chunk );

        if ( $is_streaming ) {
            $this->stream_callback = $on_chunk;
            add_action( 'http_api_curl', [ $this, 'stream_handler' ], 10, 3 );
        }

        $options = [
            'method'    => 'POST',
            'timeout'   => WPC_TIMEOUT,
            'sslverify' => true,
            'headers'   => $headers,
            'body'      => $this->safe_json_encode( $body ),
        ];

        $response = wp_remote_post( $url, $options );

        if ( $is_streaming ) {
            remove_action( 'http_api_curl', [ $this, 'stream_handler' ], 10 );
            $this->stream_callback = null;
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['error']['message']
                ?? $body['error']['errors'][0]['message'];
            if ( $msg === null ) {
                /* translators: %d: HTTP status code */
                $msg = sprintf( __( 'API error (HTTP %d)', 'wordpress-copilot' ), $code );
            }
            return new WP_Error( 'api_error', $msg );
        }

        return $body;
    }

    protected function reset_stream(): void {
        $this->stream_temp_buffer = '';
        $this->stream_content     = '';
        $this->stream_callback    = null;
        $this->in_tokens          = 0;
        $this->out_tokens         = 0;
    }

    /* ── System prompt ──────────────────────────────────────────── */

    protected function build_system_prompt( string $schema ): string {
        $max = $this->max_rows;
        return <<<PROMPT
You are WordPress Copilot — an expert MySQL assistant embedded inside the WordPress admin panel.
Your ONLY job is to convert natural language questions into safe, read-only SQL SELECT queries.

RULES (never break these):
1. Generate ONLY SELECT queries. Never INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, CREATE, REPLACE.
2. Always include LIMIT {$max} unless the user explicitly asks for ALL records.
3. Use EXACT table and column names from the schema below.
4. Use column aliases to make output human-readable (e.g. p.post_title AS product_name).
5. When joining wp_postmeta, use separate JOINs for each meta key.
6. Return ONLY a valid JSON object — no markdown fences, no extra text:
   {"sql": "SELECT ...", "explanation": "Short human explanation of what this query does."}

DATABASE SCHEMA:
{$schema}

EXAMPLES:
Q: "Top 10 best-selling products in category Hunting and Fishing"
A: {"sql":"SELECT p.post_title AS product_name, SUM(CAST(oim.meta_value AS UNSIGNED)) AS total_sold FROM wp_posts p JOIN wp_term_relationships tr ON tr.object_id=p.ID JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN wp_terms t ON t.term_id=tt.term_id JOIN wp_woocommerce_order_items oi ON oi.order_item_name=p.post_title JOIN wp_woocommerce_order_itemmeta oim ON oim.order_item_id=oi.order_item_id AND oim.meta_key='_qty' WHERE tt.taxonomy='product_cat' AND t.slug='hunting-fishing' AND p.post_type='product' AND p.post_status='publish' GROUP BY p.ID ORDER BY total_sold DESC LIMIT 10","explanation":"Top 10 products by total quantity sold in the Hunting & Fishing category."}

Q: "How many users registered this month?"
A: {"sql":"SELECT COUNT(*) AS new_users_this_month FROM wp_users WHERE MONTH(user_registered)=MONTH(NOW()) AND YEAR(user_registered)=YEAR(NOW()) LIMIT 1","explanation":"Count of new user registrations in the current calendar month."}

Q: "Products with stock below 5"
A: {"sql":"SELECT p.post_title AS product_name, pm.meta_value AS stock FROM wp_posts p JOIN wp_postmeta pm ON pm.post_id=p.ID AND pm.meta_key='_stock' WHERE p.post_type='product' AND p.post_status='publish' AND CAST(pm.meta_value AS UNSIGNED)<5 ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC LIMIT 100","explanation":"Published products with stock level below 5, sorted by lowest stock first."}

Q: "Total revenue last 30 days"
A: {"sql":"SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,2))) AS total_revenue FROM wp_posts o JOIN wp_postmeta pm ON pm.post_id=o.ID AND pm.meta_key='_order_total' WHERE o.post_type='shop_order' AND o.post_status IN ('wc-completed','wc-processing') AND o.post_date>=DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1","explanation":"Sum of completed/processing order totals from the last 30 days."}
PROMPT;
    }

    /* ── Parse AI JSON response ─────────────────────────────────── */

    protected function parse_ai_json( string $raw_text ): array|WP_Error {
        // Strip possible markdown fences
        $text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw_text ) );
        $text = preg_replace( '/\s*```$/', '', $text );
        $text = trim( $text );

        // 1. Try clean decode
        $parsed = json_decode( $text, true );

        // 2. Try first JSON object in the string
        if ( ! is_array( $parsed ) && preg_match( '/\{[\s\S]*\}/', $text, $m ) ) {
            $parsed = json_decode( trim( $m[0] ), true );
        }

        // 3. Repair truncated JSON: extract partial "sql" value
        if ( ! is_array( $parsed ) || ! isset( $parsed['sql'] ) ) {
            if ( preg_match( '/"sql"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/s', $text, $m ) ) {
                $partial_sql = trim( stripslashes( $m[1] ) );
                // Reject if SQL ends with an incomplete keyword — it would cause a syntax error
                if ( ! empty( $partial_sql ) && ! preg_match( '/\b(SELECT|FROM|WHERE|ORDER\s+BY|GROUP\s+BY|HAVING|JOIN|LEFT|RIGHT|INNER|OUTER|ON|AND|OR|NOT|LIMIT|OFFSET|UNION|SET|INTO|VALUES|UPDATE|DELETE|IN|BETWEEN|LIKE|AS|BY)\s*$/i', $partial_sql ) ) {
                    $parsed = [
                        'sql'         => $partial_sql,
                        'explanation' => '(response was truncated — partial SQL recovered)',
                    ];
                } else {
                    WPC_Logger::warn( 'Truncated SQL ends mid-keyword, discarding: ' . mb_strimwidth( $partial_sql, 0, 100 ) );
                }
            }
        }

        if ( ! is_array( $parsed ) || ! isset( $parsed['sql'] ) ) {
            WPC_Logger::error( 'AI did not return valid JSON. Raw: ' . mb_strimwidth( $text, 0, 300 ) );
            return new WP_Error( 'parse_error', __( 'AI response could not be parsed.', 'wordpress-copilot' ) . ' Raw: ' . mb_strimwidth( $text, 0, 200 ) );
        }

        return $parsed;
    }

    /* ── Safe JSON encode (AI Engine pattern) ───────────────────── */

    protected function safe_json_encode( $data ): string {
        $json = wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE );
        if ( $json === false ) {
        throw new \RuntimeException( 'JSON encode failed: ' . json_last_error_msg() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $json;
    }

    /* ── Token getters ──────────────────────────────────────────── */

    public function get_model(): string { return $this->model; }
    public function get_in_tokens():  int { return $this->in_tokens;  }
    public function get_out_tokens(): int { return $this->out_tokens; }
}
