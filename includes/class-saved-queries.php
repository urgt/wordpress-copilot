<?php
defined( 'ABSPATH' ) || exit;

/**
 * DB-backed saved queries and templates.
 * Table: wp_dqa_saved_queries
 */
class DQA_Saved_Queries {

	const TABLE_SUFFIX = 'dqa_saved_queries';
	const ENTRY_LIMIT  = 100;

	/* ── Table management ───────────────────────────────────────── */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function create_table(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$table} (
            id          VARCHAR(40)      NOT NULL,
            user_id     BIGINT UNSIGNED  NOT NULL,
            provider    VARCHAR(50)      NOT NULL DEFAULT '',
            title       VARCHAR(190)     NOT NULL DEFAULT 'Saved query',
            query_text  TEXT             NOT NULL,
            query_kind  VARCHAR(20)      NOT NULL DEFAULT 'saved',
            created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_provider (user_id, provider),
            KEY user_provider_kind (user_id, provider, query_kind),
            KEY updated_at (updated_at)
        ) {$charset};"
		);
	}

	/* ── CRUD ───────────────────────────────────────────────────── */

	public static function load_entries( int $user_id, string $provider ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- User-scoped list, order by recency, must return live data.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, title, query_text, query_kind, created_at, updated_at
             FROM %i
             WHERE user_id = %d AND provider = %s
             ORDER BY updated_at DESC
             LIMIT %d',
				self::table(),
				$user_id,
				$provider,
				self::ENTRY_LIMIT
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		return array_map(
			function ( $row ) {
				return [
					'id'        => (string) ( $row['id'] ?? '' ),
					'title'     => (string) ( $row['title'] ?? '' ),
					'query'     => (string) ( $row['query_text'] ?? '' ),
					'kind'      => in_array( $row['query_kind'] ?? '', [ 'saved', 'template' ], true ) ? $row['query_kind'] : 'saved',
					'createdAt' => strtotime( (string) ( $row['created_at'] ?? '' ) ) * 1000,
					'updatedAt' => strtotime( (string) ( $row['updated_at'] ?? '' ) ) * 1000,
				];
			},
			$rows
		);
	}

	public static function save_entry( int $user_id, string $provider, array $entry ): bool {
		global $wpdb;

		$id    = sanitize_text_field( $entry['id'] ?? '' );
		$query = sanitize_textarea_field( $entry['query'] ?? '' );
		$title = sanitize_text_field( $entry['title'] ?? '' );
		$kind  = sanitize_key( $entry['kind'] ?? 'saved' );

		if ( ! in_array( $kind, [ 'saved', 'template' ], true ) ) {
			$kind = 'saved';
		}

		if ( '' === $id || '' === trim( $query ) ) {
			return false;
		}

		$query = mb_strimwidth( $query, 0, 10000, '' );
		$title = mb_strimwidth( $title, 0, 190, '' );
		if ( '' === $title ) {
			$title = mb_strimwidth( $query, 0, 56, '…' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check before write operation.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id = %s AND user_id = %d',
				self::table(),
				$id,
				$user_id
			)
		);

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE write operation.
			$result = $wpdb->update(
				self::table(),
				[
					'title'      => $title,
					'query_text' => $query,
					'query_kind' => $kind,
					'updated_at' => current_time( 'mysql' ),
				],
				[
					'id'      => $id,
					'user_id' => $user_id,
				],
				[ '%s', '%s', '%s', '%s' ],
				[ '%s', '%d' ]
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- INSERT write operation.
			$result = $wpdb->insert(
				self::table(),
				[
					'id'         => $id,
					'user_id'    => $user_id,
					'provider'   => $provider,
					'title'      => $title,
					'query_text' => $query,
					'query_kind' => $kind,
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				],
				[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		}

		if ( false === $result ) {
			return false;
		}

		self::trim_user_entries( $user_id, $provider );
		return true;
	}

	public static function delete_entry( int $user_id, string $entry_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE write operation.
		return (bool) $wpdb->delete(
			self::table(),
			[
				'id'      => $entry_id,
				'user_id' => $user_id,
			],
			[ '%s', '%d' ]
		);
	}

	private static function trim_user_entries( int $user_id, string $provider ): void {
		global $wpdb;
		$limit = self::ENTRY_LIMIT;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Maintenance delete for user/provider cap.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
             WHERE user_id = %d AND provider = %s
             AND id NOT IN (
                SELECT id_keep FROM (
                    SELECT id AS id_keep
                    FROM %i
                    WHERE user_id = %d AND provider = %s
                    ORDER BY updated_at DESC
                    LIMIT %d
                ) t
             )',
				self::table(),
				$user_id,
				$provider,
				self::table(),
				$user_id,
				$provider,
				$limit
			)
		);
	}

	/* ── AJAX handlers ──────────────────────────────────────────── */

	public static function register_ajax(): void {
		add_action( 'wp_ajax_dqa_saved_queries_load', [ __CLASS__, 'ajax_load' ] );
		add_action( 'wp_ajax_dqa_saved_query_save', [ __CLASS__, 'ajax_save' ] );
		add_action( 'wp_ajax_dqa_saved_query_delete', [ __CLASS__, 'ajax_delete' ] );
	}

	public static function ajax_load(): void {
		self::verify_nonce(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! DQA_Chat_Widget::current_user_allowed() ) {
			wp_send_json_error( __( 'Access denied.', 'data-query-assistant' ), 403 );
		}
		DQA_Feature_Gates::assert_enabled( 'saved_queries' );
		$user_id  = get_current_user_id();
		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json_success( self::load_entries( $user_id, $provider ) );
	}

	public static function ajax_save(): void {
		self::verify_nonce(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! DQA_Chat_Widget::current_user_allowed() ) {
			wp_send_json_error( __( 'Access denied.', 'data-query-assistant' ), 403 );
		}
		DQA_Feature_Gates::assert_enabled( 'saved_queries' );
		$user_id  = get_current_user_id();
		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$entry_raw = wp_unslash( $_POST['entry'] ?? null );

		if ( ! $entry_raw ) {
			wp_send_json_error( __( 'Missing saved query data.', 'data-query-assistant' ) );
		}

		$entry = is_array( $entry_raw ) ? $entry_raw : json_decode( (string) $entry_raw, true );
		if ( ! is_array( $entry ) ) {
			wp_send_json_error( __( 'Invalid saved query data.', 'data-query-assistant' ) );
		}

		$ok = self::save_entry( $user_id, $provider, $entry );
		$ok ? wp_send_json_success() : wp_send_json_error( __( 'Unable to save query.', 'data-query-assistant' ) );
	}

	public static function ajax_delete(): void {
		self::verify_nonce(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! DQA_Chat_Widget::current_user_allowed() ) {
			wp_send_json_error( __( 'Access denied.', 'data-query-assistant' ), 403 );
		}
		DQA_Feature_Gates::assert_enabled( 'saved_queries' );
		$user_id  = get_current_user_id();
		$entry_id = sanitize_text_field( wp_unslash( $_POST['entry_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $entry_id ) {
			wp_send_json_error( __( 'Missing saved query id.', 'data-query-assistant' ) );
		}
		self::delete_entry( $user_id, $entry_id );
		wp_send_json_success();
	}

	private static function verify_nonce(): void {
		if ( ! check_ajax_referer( 'dqa_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid nonce', 'data-query-assistant' ), 403 );
		}
	}
}
