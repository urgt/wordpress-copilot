<?php
defined( 'ABSPATH' ) || exit;

/**
 * DB-backed chat storage.
 * Table: wp_dqa_chats
 */
class DQA_Chat_Storage {

	const TABLE_SUFFIX = 'dqa_chats';
	const MSG_LIMIT    = 80;
	const CHAT_LIMIT   = 20;

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
            id          VARCHAR(36)  NOT NULL,
            user_id     BIGINT UNSIGNED NOT NULL,
            provider    VARCHAR(50)  NOT NULL DEFAULT '',
            title       VARCHAR(255) NOT NULL DEFAULT 'New chat',
            messages    LONGTEXT     NOT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id_provider (user_id, provider),
            KEY updated_at (updated_at)
        ) {$charset};"
		);
	}

	/* ── CRUD ───────────────────────────────────────────────────── */

	public static function load_chats( int $user_id, string $provider ): array {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chat history is user-specific, per-request; caching would return stale data.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, title, messages, created_at, updated_at
             FROM %i
             WHERE user_id = %d AND provider = %s
             ORDER BY updated_at DESC
             LIMIT %d',
				self::table(),
				$user_id,
				$provider,
				self::CHAT_LIMIT
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		return array_map(
			function ( $row ) {
				$msgs = json_decode( $row['messages'] ?? '[]', true );
				return [
					'id'        => $row['id'],
					'title'     => $row['title'],
					'messages'  => is_array( $msgs ) ? $msgs : [],
					'createdAt' => strtotime( $row['created_at'] ) * 1000,
					'updatedAt' => strtotime( $row['updated_at'] ) * 1000,
				];
			},
			$rows
		);
	}

	public static function save_chat( int $user_id, string $provider, array $chat ): bool {
		global $wpdb;

		$id       = sanitize_text_field( $chat['id'] ?? '' );
		$title    = mb_strimwidth( sanitize_text_field( $chat['title'] ?? 'New chat' ), 0, 255 );
		$messages = $chat['messages'] ?? [];

		if ( ! $id ) {
			return false;
		}

		// Trim messages to limit
		if ( count( $messages ) > self::MSG_LIMIT ) {
			$messages = array_slice( $messages, - self::MSG_LIMIT );
		}

		// Strip heavy HTML from bot messages to keep DB size sane
		$messages      = array_map(
			function ( $msg ) {
				if ( ( $msg['role'] ?? '' ) === 'bot' && isset( $msg['data']['html'] ) ) {
					unset( $msg['data']['html'] );
				}
				return $msg;
			},
			$messages
		);
		$messages_json = wp_json_encode( $messages );
		if ( false === $messages_json ) {
			DQA_Logger::warn( 'Failed to encode chat messages for DB storage.' );
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check before write; caching inappropriate here.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id = %s AND user_id = %d',
				self::table(),
				$id,
				$user_id
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- write operations (INSERT/UPDATE/DELETE) cannot be cached.
		if ( $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- UPDATE write operation.
			$result = $wpdb->update(
				self::table(),
				[
					'title'      => $title,
					'messages'   => $messages_json,
					'updated_at' => current_time( 'mysql' ),
				],
				[
					'id'      => $id,
					'user_id' => $user_id,
				],
				[ '%s', '%s', '%s' ],
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
					'messages'   => $messages_json,
					'created_at' => current_time( 'mysql' ),
					'updated_at' => current_time( 'mysql' ),
				],
				[ '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
			);
		}

		return false !== $result;
	}

	public static function delete_chat( int $user_id, string $chat_id ): bool {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE write operation.
		return (bool) $wpdb->delete(
			self::table(),
			[
				'id'      => $chat_id,
				'user_id' => $user_id,
			],
			[ '%s', '%d' ]
		);
	}

	/* ── AJAX handlers ──────────────────────────────────────────── */

	public static function register_ajax(): void {
		add_action( 'wp_ajax_dqa_chats_load', [ __CLASS__, 'ajax_load' ] );
		add_action( 'wp_ajax_dqa_chat_save', [ __CLASS__, 'ajax_save' ] );
		add_action( 'wp_ajax_dqa_chat_delete', [ __CLASS__, 'ajax_delete' ] );
	}

	public static function ajax_load(): void {
		self::verify_nonce(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! DQA_Chat_Widget::current_user_allowed() ) {
			wp_send_json_error( __( 'Access denied.', 'data-query-assistant' ), 403 );
		}
		$user_id  = get_current_user_id();
		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json_success( self::load_chats( $user_id, $provider ) );
	}

	public static function ajax_save(): void {
		self::verify_nonce(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! DQA_Chat_Widget::current_user_allowed() ) {
			wp_send_json_error( __( 'Access denied.', 'data-query-assistant' ), 403 );
		}
		$user_id  = get_current_user_id();
		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$chat_raw = wp_unslash( $_POST['chat'] ?? null );

		if ( ! $chat_raw ) {
			wp_send_json_error( __( 'Missing chat data', 'data-query-assistant' ) );
		}

		$chat = is_array( $chat_raw ) ? array_map( 'sanitize_text_field', (array) $chat_raw ) : json_decode( (string) $chat_raw, true );
		if ( ! is_array( $chat ) ) {
			wp_send_json_error( __( 'Invalid chat data', 'data-query-assistant' ) );
		}

		$ok = self::save_chat( $user_id, $provider, $chat );
		$ok ? wp_send_json_success() : wp_send_json_error( __( 'DB error', 'data-query-assistant' ) );
	}

	public static function ajax_delete(): void {
		self::verify_nonce(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! DQA_Chat_Widget::current_user_allowed() ) {
			wp_send_json_error( __( 'Access denied.', 'data-query-assistant' ), 403 );
		}
		$user_id = get_current_user_id();
		$chat_id = sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $chat_id ) {
			wp_send_json_error( __( 'Missing chat_id', 'data-query-assistant' ) );
		}
		self::delete_chat( $user_id, $chat_id );
		wp_send_json_success();
	}

	private static function verify_nonce(): void {
		if ( ! check_ajax_referer( 'dqa_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid nonce', 'data-query-assistant' ), 403 );
		}
	}
}
