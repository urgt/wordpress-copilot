<?php
defined( 'ABSPATH' ) || exit;

class WPC_Query_Executor {

    /** Blocked SQL keywords — defence-in-depth after AI-level restriction */
    const BLOCKED_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
        'CREATE', 'REPLACE', 'RENAME', 'GRANT', 'REVOKE',
        'CALL', 'EXEC', 'EXECUTE', 'LOAD_FILE', 'INTO OUTFILE', 'INTO DUMPFILE',
        'PROCEDURE', 'HANDLER',
    ];

    /* ── Validate ───────────────────────────────────────────────── */

    /** @return true|WP_Error */
    public static function validate( string $sql ) {
        $trimmed = ltrim( $sql );

        if ( ! preg_match( '/^SELECT\s/i', $trimmed ) ) {
            return new WP_Error( 'unsafe_query', 'Only SELECT queries are allowed.' );
        }

        foreach ( self::BLOCKED_KEYWORDS as $kw ) {
            if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/i', $trimmed ) ) {
                return new WP_Error(
                    'unsafe_query',
                    "Blocked keyword detected: {$kw}. Only pure SELECT queries are permitted."
                );
            }
        }

        return true;
    }

    /* ── Execute ────────────────────────────────────────────────── */

    public static function execute( string $sql ): array|WP_Error {
        $v = self::validate( $sql );
        if ( is_wp_error( $v ) ) return $v;

        global $wpdb;

        // Optional query timeout for large databases
        $timeout = (int) WPC_Settings::get( 'query_timeout', 15 );
        if ( $timeout > 0 ) {
            $wpdb->query( "SET SESSION MAX_EXECUTION_TIME=" . ( $timeout * 1000 ) );
        }

        $wpdb->hide_errors();
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $wpdb->show_errors();

        if ( $wpdb->last_error ) {
            return new WP_Error( 'sql_error', $wpdb->last_error );
        }

        $rows = $rows ?? [];

        // Unserialize PHP-serialized values (e.g. wp_options transient data)
        $rows = self::format_row_values( $rows );

        // Apply data anonymizer
        if ( ! empty( $rows ) && WPC_Settings::get( 'anonymize_enabled' ) ) {
            $rows = self::anonymize_rows( $rows );
        }

        return $rows;
    }

    /**
     * Unserialize PHP-serialized values (e.g. wp_options data).
     * Does NOT reformat plain strings — that's handled by render_cell at display time.
     */
    public static function format_row_values( array $rows ): array {
        return array_map( function ( $row ) {
            foreach ( $row as $col => $val ) {
                if ( ! is_string( $val ) || $val === '' ) continue;

                if ( ! self::is_serialized( $val ) ) continue;

                $unserialized = @unserialize( $val );
                if ( $unserialized === false ) continue;

                if ( is_array( $unserialized ) ) {
                    // Flat array → comma-separated, nested → compact JSON
                    $flat = array_filter( $unserialized, fn( $v ) => ! is_array( $v ) && ! is_object( $v ) );
                    $row[ $col ] = count( $flat ) === count( $unserialized )
                        ? implode( ', ', array_values( $unserialized ) )
                        : json_encode( $unserialized, JSON_UNESCAPED_UNICODE );
                } elseif ( is_object( $unserialized ) ) {
                    $row[ $col ] = json_encode( $unserialized, JSON_UNESCAPED_UNICODE );
                } else {
                    $row[ $col ] = (string) $unserialized;
                }
            }
            return $row;
        }, $rows );
    }

    /** Render a table cell value as safe HTML */
    private static function render_cell( $cell ): string {
        if ( $cell === null || $cell === '' ) return '<span class="wpc-cell-null">—</span>';

        $val = (string) $cell;

        // JSON object/array → collapsible <details>
        if ( str_starts_with( ltrim( $val ), '{' ) || str_starts_with( ltrim( $val ), '[' ) ) {
            $decoded = json_decode( $val, true );
            if ( is_array( $decoded ) ) {
                // Try to show a short summary line
                $count   = count( $decoded );
                $summary = is_int( array_key_first( $decoded ) )
                    ? $count . ' items'
                    : implode( ', ', array_slice( array_keys( $decoded ), 0, 3 ) ) . ( $count > 3 ? '…' : '' );
                $pretty  = json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
                return '<details class="wpc-cell-details"><summary class="wpc-cell-json-summary">{'
                    . esc_html( $summary ) . '}</summary>'
                    . '<pre class="wpc-cell-json-pre">' . esc_html( $pretty ) . '</pre></details>';
            }
            // Fallback: show truncated
            return '<span class="wpc-cell-long" title="' . esc_attr( mb_strimwidth( $val, 0, 500 ) ) . '">'
                . esc_html( mb_strimwidth( $val, 0, 80, '…' ) ) . '</span>';
        }

        // Comma-separated list → tags
        if ( substr_count( $val, ',' ) >= 3 && mb_strlen( $val ) > 80 ) {
            $items = array_filter( array_map( 'trim', explode( ',', $val ) ) );
            // If many items, show first 6 and a "+ N more" toggle
            $visible = array_slice( $items, 0, 8 );
            $hidden  = array_slice( $items, 8 );
            $tags = implode( '', array_map( fn( $item ) =>
                '<span class="wpc-cell-tag">' . esc_html( $item ) . '</span>', $visible
            ) );
            if ( $hidden ) {
                $more = implode( '', array_map( fn( $item ) =>
                    '<span class="wpc-cell-tag wpc-cell-tag-hidden">' . esc_html( $item ) . '</span>', $hidden
                ) );
                $tags .= '<span class="wpc-cell-tag wpc-cell-tag-more" data-count="' . count($hidden) . '">+' . count($hidden) . ' more</span>';
                $tags .= '<span class="wpc-cell-tag-extra" style="display:none">' . $more . '</span>';
            }
            return '<div class="wpc-cell-tags">' . $tags . '</div>';
        }

        // Numeric
        if ( is_numeric( $val ) ) {
            return '<span class="wpc-cell-num">' . esc_html( number_format_i18n( (float) $val, str_contains( $val, '.' ) ? 2 : 0 ) ) . '</span>';
        }

        // Long plain text → truncate with tooltip
        if ( mb_strlen( $val ) > 120 ) {
            return '<span class="wpc-cell-long" title="' . esc_attr( $val ) . '">' . esc_html( mb_strimwidth( $val, 0, 120, '…' ) ) . '</span>';
        }

        return esc_html( $val );
    }


    private static function is_serialized( string $data ): bool {
        if ( $data === 'b:0;' ) return true;
        $data = trim( $data );
        if ( ! in_array( $data[0] ?? '', [ 'a', 'O', 's', 'i', 'd', 'b', 'N' ], true ) ) return false;
        return (bool) preg_match( '/^[aOsidbN]:[0-9]*:/s', $data );
    }

    /**
     * Replace sensitive column values with [REDACTED].
     */
    public static function anonymize_rows( array $rows ): array {
        $patterns_raw = WPC_Settings::get( 'anonymize_columns', WPC_Settings::default_anon_columns() );
        $patterns = array_filter( array_map( 'strtolower', array_map( 'trim', explode( "\n", $patterns_raw ) ) ) );
        if ( empty( $patterns ) ) return $rows;

        return array_map( function ( $row ) use ( $patterns ) {
            foreach ( $row as $col => $val ) {
                if ( in_array( strtolower( $col ), $patterns, true ) ) {
                    $row[ $col ] = '[REDACTED]';
                }
            }
            return $row;
        }, $rows );
    }

    /* ── Format results into HTML ───────────────────────────────── */

    public static function format_results( array $rows, string $explanation ): array {
        $count = count( $rows );

        if ( $count === 0 ) {
            return [
                'html'    => '<p class="wpc-no-results">No results found.</p>',
                'summary' => $explanation . ' — No results found.',
                'count'   => 0,
            ];
        }

        // Single scalar value (COUNT, SUM, etc.)
        if ( $count === 1 && count( $rows[0] ) === 1 ) {
            $label = key( $rows[0] );
            $value = reset( $rows[0] );
            $display = is_numeric( $value )
                ? number_format_i18n( (float) $value, str_contains( (string) $value, '.' ) ? 2 : 0 )
                : self::render_cell( $value );
            return [
                'html'    => sprintf(
                    '<div class="wpc-scalar"><span class="wpc-scalar-label">%s</span>'
                    . '<span class="wpc-scalar-value">%s</span></div>',
                    esc_html( $label ),
                    $display   // already safe (render_cell escapes, number_format is numeric-only)
                ),
                'summary' => "{$explanation} — {$label}: {$value}",
                'count'   => 1,
            ];
        }

        // Table
        $headers = array_keys( $rows[0] );
        $html    = '<div class="wpc-table-wrap"><table class="wpc-table"><thead><tr>';
        foreach ( $headers as $h ) {
            $html .= '<th>' . esc_html( $h ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $html .= '<tr>';
            foreach ( $row as $cell ) {
                $html .= '<td>' . self::render_cell( $cell ) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        $max_rows = (int) WPC_Settings::get( 'max_rows', 100 );
        $limited  = $count >= $max_rows
            ? ' <span class="wpc-limit-note">(limited to ' . $max_rows . ' rows)</span>'
            : '';

        return [
            'html'    => $html,
            'summary' => $explanation . " — {$count} rows{$limited}",
            'count'   => $count,
        ];
    }
}
