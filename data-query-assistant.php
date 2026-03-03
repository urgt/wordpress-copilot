<?php
/**
 * Plugin Name:  Data Query Assistant
 * Plugin URI:   https://github.com/urgt/data-query-assistant
 * Description:  AI assistant for safe, read-only WordPress data insights. Ask in plain language and get SQL-powered answers instantly.
 * Version:      1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author:       G'ayrat Urinbaev
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  data-query-assistant
 *
 * @package Data_Query_Assistant
 */

defined( 'ABSPATH' ) || exit;

define( 'WPC_VERSION', '1.0.0' );
define( 'WPC_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPC_URL', plugin_dir_url( __FILE__ ) );
define( 'WPC_TIMEOUT', 90 );

/* ── Autoload ────────────────────────────────────────────────────── */
require_once WPC_PATH . 'includes/class-logger.php';
require_once WPC_PATH . 'includes/class-settings.php';
require_once WPC_PATH . 'includes/class-db-schema.php';
require_once WPC_PATH . 'includes/class-query-executor.php';
require_once WPC_PATH . 'includes/engines/class-engine-core.php';
require_once WPC_PATH . 'includes/engines/class-engine-anthropic.php';
require_once WPC_PATH . 'includes/engines/class-engine-openai.php';
require_once WPC_PATH . 'includes/engines/class-engine-google.php';
require_once WPC_PATH . 'includes/engines/class-engine-factory.php';
require_once WPC_PATH . 'includes/class-chat-storage.php';
require_once WPC_PATH . 'includes/class-chat-widget.php';

/* ── Bootstrap ───────────────────────────────────────────────────── */
add_action(
	'plugins_loaded',
	function () {
		WPC_Settings::init();
		WPC_Chat_Storage::register_ajax();
		WPC_Chat_Widget::init();

		// Suppress WP DB error output for AJAX requests — nonce is verified in each handler.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( wp_doing_ajax() && in_array( $_POST['action'] ?? '', [ 'wpc_query', 'wpc_stream' ], true ) ) {
			ob_start();
			add_action(
				'shutdown',
				function () {
					ob_start(
						function ( $output ) {
							return preg_replace( '/<div id="error"><p class="wpdberror">.*?<\/div>/s', '', $output );
						}
					);
				},
				5
			);
		}
	}
);

/* ── Activation: create logs table ──────────────────────────────── */
register_activation_hook(
	__FILE__,
	function () {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = $wpdb->prefix . 'copilot_logs';
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$table} (
        id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id        BIGINT UNSIGNED NOT NULL,
        user_query     TEXT            NOT NULL,
        generated_sql  TEXT            NOT NULL,
        provider       VARCHAR(30)     NOT NULL DEFAULT '',
        model          VARCHAR(80)     NOT NULL DEFAULT '',
        in_tokens      INT             NOT NULL DEFAULT 0,
        out_tokens     INT             NOT NULL DEFAULT 0,
        row_count      INT             NOT NULL DEFAULT 0,
        exec_ms        INT             NOT NULL DEFAULT 0,
        status         VARCHAR(20)     NOT NULL DEFAULT 'success',
        error_msg      TEXT,
        executed_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY executed_at (executed_at)
    ) {$charset};"
		);

		WPC_Chat_Storage::create_table();
		WPC_DB_Schema::flush_cache();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		WPC_DB_Schema::flush_cache();
	}
);
