<?php
defined( 'ABSPATH' ) || exit;

class WPC_Query_Executor {

	/** Blocked SQL keywords — defence-in-depth after AI-level restriction */
	const BLOCKED_KEYWORDS = [
		'INSERT',
		'UPDATE',
		'DELETE',
		'DROP',
		'ALTER',
		'TRUNCATE',
		'CREATE',
		'REPLACE',
		'RENAME',
		'GRANT',
		'REVOKE',
		'CALL',
		'EXEC',
		'EXECUTE',
		'LOAD_FILE',
		'INTO OUTFILE',
		'INTO DUMPFILE',
		'PROCEDURE',
		'HANDLER',
	];

	/* ── Validate ───────────────────────────────────────────────── */

	/** @return true|WP_Error */
	public static function validate( string $sql ) {
		$trimmed = ltrim( $sql );

		if ( ! preg_match( '/^SELECT\s/i', $trimmed ) ) {
			return new WP_Error( 'unsafe_query', __( 'Only SELECT queries are allowed.', 'data-query-assistant' ) );
		}

		foreach ( self::BLOCKED_KEYWORDS as $kw ) {
			if ( preg_match( '/\b' . preg_quote( $kw, '/' ) . '\b/i', $trimmed ) ) {
				return new WP_Error(
					'unsafe_query',
					/* translators: %s: SQL keyword that was blocked */
					sprintf( __( 'Blocked keyword detected: %s. Only pure SELECT queries are permitted.', 'data-query-assistant' ), $kw )
				);
			}
		}

		return true;
	}

	/* ── Execute ────────────────────────────────────────────────── */

	public static function execute( string $sql ): array|WP_Error {
		$v = self::validate( $sql );
		if ( is_wp_error( $v ) ) {
			return $v;
		}

		global $wpdb;

		// Optional query timeout for large databases
		$timeout = (int) WPC_Settings::get( 'query_timeout', 15 );
		if ( $timeout > 0 ) {
			self::set_session_timeout( $timeout );
		}

		$wpdb->hide_errors();
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is validated as read-only SELECT by self::validate().
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
	 * Set query timeout in a DB-engine compatible way.
	 */
	private static function set_session_timeout( int $timeout ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'SET SESSION MAX_EXECUTION_TIME=%d', $timeout * 1000 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '' === $wpdb->last_error ) {
			return;
		}

		$mysql_error = $wpdb->last_error;
		$wpdb->query( $wpdb->prepare( 'SET SESSION max_statement_time=%f', (float) $timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '' !== $wpdb->last_error ) {
			WPC_Logger::warn(
				sprintf(
					'Unable to apply query timeout (MAX_EXECUTION_TIME: %1$s | max_statement_time: %2$s)',
					$mysql_error,
					$wpdb->last_error
				)
			);
		}
	}

	/**
	 * Unserialize PHP-serialized values (e.g. wp_options data).
	 * Does NOT reformat plain strings — that's handled by render_cell at display time.
	 */
	public static function format_row_values( array $rows ): array {
		return array_map(
			function ( $row ) {
				foreach ( $row as $col => $val ) {
					if ( ! is_string( $val ) || '' === $val ) {
						continue;
					}

					if ( ! self::is_serialized( $val ) ) {
						continue;
					}

					$serialized_value = trim( $val );
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- pre-validated by is_serialized(), reading existing WP-serialized DB data; classes disabled for safety.
					$unserialized = @unserialize( $serialized_value, [ 'allowed_classes' => false ] );
					if ( false === $unserialized && 'b:0;' !== $serialized_value ) {
						continue;
					}

					if ( is_array( $unserialized ) ) {
						// Flat array → comma-separated, nested → compact JSON
						$flat        = array_filter( $unserialized, fn( $v ) => ! is_array( $v ) && ! is_object( $v ) );
						$row[ $col ] = count( $flat ) === count( $unserialized )
							? implode( ', ', array_values( $unserialized ) )
							: wp_json_encode( $unserialized, JSON_UNESCAPED_UNICODE );
					} elseif ( is_object( $unserialized ) ) {
						$row[ $col ] = wp_json_encode( $unserialized, JSON_UNESCAPED_UNICODE );
					} else {
						$row[ $col ] = (string) $unserialized;
					}
				}
				return $row;
			},
			$rows
		);
	}

	/** Render a table cell value as safe HTML */
	private static function render_cell( $cell ): string {
		if ( null === $cell || '' === $cell ) {
			return '<span class="wpc-cell-null">' . esc_html( __( '—', 'data-query-assistant' ) ) . '</span>';
		}

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
				$pretty  = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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
			$tags    = implode(
				'',
				array_map(
					fn( $item ) =>
						'<span class="wpc-cell-tag">' . esc_html( $item ) . '</span>',
					$visible
				)
			);
			if ( $hidden ) {
				$more  = implode(
					'',
					array_map(
						fn( $item ) =>
							'<span class="wpc-cell-tag wpc-cell-tag-hidden">' . esc_html( $item ) . '</span>',
						$hidden
					)
				);
				$tags .= '<span class="wpc-cell-tag wpc-cell-tag-more" data-count="' . count( $hidden ) . '">+' . count( $hidden ) . ' more</span>';
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
		if ( 'b:0;' === $data ) {
			return true;
		}
		$data = trim( $data );
		if ( ! in_array( $data[0] ?? '', [ 'a', 'O', 's', 'i', 'd', 'b', 'N' ], true ) ) {
			return false;
		}
		return (bool) preg_match( '/^[aOsidbN]:[0-9]*:/s', $data );
	}

	/**
	 * Replace sensitive column values with [REDACTED].
	 */
	public static function anonymize_rows( array $rows ): array {
		$patterns_raw = WPC_Settings::get( 'anonymize_columns', WPC_Settings::default_anon_columns() );
		$patterns     = array_filter( array_map( 'strtolower', array_map( 'trim', explode( "\n", $patterns_raw ) ) ) );
		if ( empty( $patterns ) ) {
			return $rows;
		}

		return array_map(
			function ( $row ) use ( $patterns ) {
				foreach ( $row as $col => $val ) {
					if ( in_array( strtolower( $col ), $patterns, true ) ) {
						$row[ $col ] = '[REDACTED]';
					}
				}
				return $row;
			},
			$rows
		);
	}

	/* ── Format results into HTML ───────────────────────────────── */

	public static function format_results( array $rows, string $explanation ): array {
		$count = count( $rows );

		if ( 0 === $count ) {
			return [
				'html'    => '<p class="wpc-no-results">' . esc_html( __( 'No results found.', 'data-query-assistant' ) ) . '</p>',
				'summary' => $explanation . ' — ' . __( 'No results found.', 'data-query-assistant' ),
				'count'   => 0,
			];
		}

		// Single scalar value (COUNT, SUM, etc.)
		if ( 1 === $count && 1 === count( $rows[0] ) ) {
			$label   = key( $rows[0] );
			$value   = reset( $rows[0] );
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
			? ' <span class="wpc-limit-note">' . sprintf(
				/* translators: %d: maximum number of rows */
				__( '(limited to %d rows)', 'data-query-assistant' ),
				$max_rows
			) . '</span>'
			: '';

		return [
			'html'    => $html,
			'summary' => $explanation . " — {$count} rows{$limited}",
			'count'   => $count,
		];
	}
}
