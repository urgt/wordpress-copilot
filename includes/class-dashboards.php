<?php
defined( 'ABSPATH' ) || exit;

/**
 * DQA Dashboards — top-level admin page with SQL widgets and Pro config tabs.
 */
class DQA_Dashboards {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function register_page(): void {
		add_menu_page(
			__( 'Data Query Assistant', 'data-query-assistant' ),
			__( 'DQA Dashboards', 'data-query-assistant' ),
			'manage_options',
			'dqa-dashboards',
			[ __CLASS__, 'render_page' ],
			'dashicons-chart-bar',
			58
		);
	}

	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_dqa-dashboards' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'dqa-admin',
			DQA_URL . 'assets/css/admin.css',
			[],
			DQA_VERSION
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'data-query-assistant' ), 403 );
		}

		$opts    = get_option( DQA_Settings::OPTION_KEY, [] );
		$active  = sanitize_text_field( wp_unslash( $_GET['dqa_tab'] ?? 'widgets' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab selection only; no state change.
		$allowed = [ 'widgets', 'reports', 'alerts' ];
		if ( ! in_array( $active, $allowed, true ) ) {
			$active = 'widgets';
		}
		?>
		<div class="dqa-admin-page">

		<div class="dqa-admin-header">
			<h1>📊 <?php esc_html_e( 'Dashboards', 'data-query-assistant' ); ?> <span>v<?php echo esc_html( DQA_VERSION ); ?></span></h1>
		</div>

		<!-- Tabs nav -->
		<div class="dqa-tabs">
			<button class="dqa-tab-btn<?php echo 'widgets' === $active ? ' active' : ''; ?>" data-tab="widgets">📈 <?php esc_html_e( 'Dashboard Widgets', 'data-query-assistant' ); ?></button>
			<button class="dqa-tab-btn<?php echo 'reports' === $active ? ' active' : ''; ?>" data-tab="reports">📋 <?php esc_html_e( 'Scheduled Reports', 'data-query-assistant' ); ?></button>
			<button class="dqa-tab-btn<?php echo 'alerts' === $active ? ' active' : ''; ?>" data-tab="alerts">🔔 <?php esc_html_e( 'Smart Alerts', 'data-query-assistant' ); ?></button>
		</div>

		<!-- Tab: Widgets -->
		<div class="dqa-tab-panel<?php echo 'widgets' === $active ? ' active' : ''; ?>" data-panel="widgets">
			<?php self::render_widgets_tab( $opts ); ?>
		</div>

		<!-- Tab: Scheduled Reports -->
		<div class="dqa-tab-panel<?php echo 'reports' === $active ? ' active' : ''; ?>" data-panel="reports">
			<?php self::render_reports_tab( $opts ); ?>
		</div>

		<!-- Tab: Smart Alerts -->
		<div class="dqa-tab-panel<?php echo 'alerts' === $active ? ' active' : ''; ?>" data-panel="alerts">
			<?php self::render_alerts_tab( $opts ); ?>
		</div>

		</div><!-- .dqa-admin-page -->

		<script>
		jQuery(function($){
			$('.dqa-tab-btn').on('click', function(){
				var tab = $(this).data('tab');
				$('.dqa-tab-btn').removeClass('active');
				$('.dqa-tab-panel').removeClass('active');
				$(this).addClass('active');
				$('[data-panel="'+tab+'"]').addClass('active');
			});
		});
		</script>
		<?php
	}

	/* ── Dashboard Widgets tab ──────────────────────────────────── */

	private static function render_widgets_tab( array $opts ): void {
		if ( ! DQA_Feature_Gates::is_enabled( 'saved_dashboards' ) ) {
			self::render_pro_locked(
				__( 'Dashboard Widgets', 'data-query-assistant' ),
				__( 'Create SQL-powered dashboard widgets that display live data from your WordPress database.', 'data-query-assistant' ),
				'📈'
			);
			return;
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dqa_group' ); ?>
			<div class="dqa-card">
				<h3><?php esc_html_e( 'Widget Queries', 'data-query-assistant' ); ?></h3>
				<div class="dqa-field">
					<label for="dqa_dash_queries"><?php esc_html_e( 'Widget queries', 'data-query-assistant' ); ?></label>
					<div>
						<textarea id="dqa_dash_queries" name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_dashboard_queries]" rows="8" class="large-text"><?php echo esc_textarea( $opts['pro_dashboard_queries'] ?? '' ); ?></textarea>
						<p class="dqa-desc"><?php esc_html_e( 'One widget per line: Title | SELECT … (max 6 widgets, max 20 rows each)', 'data-query-assistant' ); ?></p>
					</div>
				</div>
				<?php submit_button( __( 'Save Widgets', 'data-query-assistant' ) ); ?>
			</div>
		</form>
		<?php
		$widgets = self::parse_widgets( (string) ( $opts['pro_dashboard_queries'] ?? '' ) );
		if ( empty( $widgets ) ) {
			echo '<div class="dqa-card"><p class="dqa-desc" style="margin:0">' . esc_html__( 'No widgets configured yet. Add SQL queries above and save.', 'data-query-assistant' ) . '</p></div>';
			return;
		}

		foreach ( $widgets as $widget ) {
			echo '<div class="dqa-card">';
			echo '<h3>' . esc_html( $widget['title'] ) . '</h3>';

			$rows = DQA_Query_Executor::execute( $widget['sql'] );
			if ( is_wp_error( $rows ) ) {
				echo '<p style="color:#c0392b;font-size:13px;margin:0">' . esc_html( $rows->get_error_message() ) . '</p>';
				echo '</div>';
				continue;
			}

			$rows      = array_slice( $rows, 0, 20 );
			$formatted = DQA_Query_Executor::format_results( $rows, '' );
			echo wp_kses_post( $formatted['html'] );
			echo '</div>';
		}
	}

	/* ── Scheduled Reports tab ──────────────────────────────────── */

	private static function render_reports_tab( array $opts ): void {
		if ( ! DQA_Feature_Gates::is_enabled( 'scheduled_reports' ) ) {
			self::render_pro_locked(
				__( 'Scheduled Reports', 'data-query-assistant' ),
				__( 'Automate email reports with SQL queries on a daily, weekly, or monthly schedule. Reports are delivered as CSV attachments.', 'data-query-assistant' ),
				'📋'
			);
			return;
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dqa_group' ); ?>
			<div class="dqa-card">
				<h3><?php esc_html_e( 'Report Configuration', 'data-query-assistant' ); ?></h3>
				<div class="dqa-toggle-row">
					<input type="checkbox" id="opt_pro_reports_enabled" name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_reports_enabled]" value="1"
						<?php checked( ! empty( $opts['pro_reports_enabled'] ) ); ?>>
					<div>
						<label for="opt_pro_reports_enabled"><?php esc_html_e( 'Enable scheduled report emails', 'data-query-assistant' ); ?></label>
					</div>
				</div>
				<hr class="dqa-section-divider">
				<div class="dqa-field">
					<label><?php esc_html_e( 'Recipient email', 'data-query-assistant' ); ?></label>
					<div><input type="email" class="regular-text" name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_reports_email]" value="<?php echo esc_attr( $opts['pro_reports_email'] ?? '' ); ?>"></div>
				</div>
				<div class="dqa-field">
					<label><?php esc_html_e( 'Frequency', 'data-query-assistant' ); ?></label>
					<div>
						<select name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_reports_frequency]">
							<option value="daily" <?php selected( ( $opts['pro_reports_frequency'] ?? 'daily' ), 'daily' ); ?>><?php esc_html_e( 'Daily', 'data-query-assistant' ); ?></option>
							<option value="weekly" <?php selected( ( $opts['pro_reports_frequency'] ?? 'daily' ), 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'data-query-assistant' ); ?></option>
							<option value="monthly" <?php selected( ( $opts['pro_reports_frequency'] ?? 'daily' ), 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'data-query-assistant' ); ?></option>
						</select>
					</div>
				</div>
				<div class="dqa-field">
					<label><?php esc_html_e( 'SQL query', 'data-query-assistant' ); ?></label>
					<div>
						<textarea name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_reports_sql]" rows="5" class="large-text"><?php echo esc_textarea( $opts['pro_reports_sql'] ?? '' ); ?></textarea>
						<p class="dqa-desc"><?php esc_html_e( 'Must be a read-only SELECT query. The report is emailed with a CSV attachment.', 'data-query-assistant' ); ?></p>
					</div>
				</div>
				<?php submit_button( __( 'Save Report Settings', 'data-query-assistant' ) ); ?>
			</div>
		</form>
		<?php
	}

	/* ── Smart Alerts tab ───────────────────────────────────────── */

	private static function render_alerts_tab( array $opts ): void {
		if ( ! DQA_Feature_Gates::is_enabled( 'smart_alerts' ) ) {
			self::render_pro_locked(
				__( 'Smart Alerts', 'data-query-assistant' ),
				__( 'Set threshold-based alerts that monitor SQL query results and send email notifications when conditions are met.', 'data-query-assistant' ),
				'🔔'
			);
			return;
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'dqa_group' ); ?>
			<div class="dqa-card">
				<h3><?php esc_html_e( 'Alert Configuration', 'data-query-assistant' ); ?></h3>
				<div class="dqa-toggle-row">
					<input type="checkbox" id="opt_pro_alerts_enabled" name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_alerts_enabled]" value="1"
						<?php checked( ! empty( $opts['pro_alerts_enabled'] ) ); ?>>
					<div>
						<label for="opt_pro_alerts_enabled"><?php esc_html_e( 'Enable threshold alerts', 'data-query-assistant' ); ?></label>
					</div>
				</div>
				<hr class="dqa-section-divider">
				<div class="dqa-field">
					<label><?php esc_html_e( 'Recipient email', 'data-query-assistant' ); ?></label>
					<div><input type="email" class="regular-text" name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_alerts_email]" value="<?php echo esc_attr( $opts['pro_alerts_email'] ?? '' ); ?>"></div>
				</div>
				<div class="dqa-field">
					<label><?php esc_html_e( 'Condition', 'data-query-assistant' ); ?></label>
					<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
						<select name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_alerts_operator]">
							<option value="gt" <?php selected( ( $opts['pro_alerts_operator'] ?? 'gt' ), 'gt' ); ?>>&gt;</option>
							<option value="gte" <?php selected( ( $opts['pro_alerts_operator'] ?? 'gt' ), 'gte' ); ?>>&gt;=</option>
							<option value="lt" <?php selected( ( $opts['pro_alerts_operator'] ?? 'gt' ), 'lt' ); ?>>&lt;</option>
							<option value="lte" <?php selected( ( $opts['pro_alerts_operator'] ?? 'gt' ), 'lte' ); ?>>&lt;=</option>
						</select>
						<input type="number" step="0.01" class="small-text" name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_alerts_threshold]" value="<?php echo esc_attr( $opts['pro_alerts_threshold'] ?? 0 ); ?>">
						<select name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_alerts_frequency]">
							<option value="daily" <?php selected( ( $opts['pro_alerts_frequency'] ?? 'daily' ), 'daily' ); ?>><?php esc_html_e( 'Daily', 'data-query-assistant' ); ?></option>
							<option value="weekly" <?php selected( ( $opts['pro_alerts_frequency'] ?? 'daily' ), 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'data-query-assistant' ); ?></option>
							<option value="monthly" <?php selected( ( $opts['pro_alerts_frequency'] ?? 'daily' ), 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'data-query-assistant' ); ?></option>
						</select>
					</div>
				</div>
				<div class="dqa-field">
					<label><?php esc_html_e( 'SQL query', 'data-query-assistant' ); ?></label>
					<div>
						<textarea name="<?php echo esc_attr( DQA_Settings::OPTION_KEY ); ?>[pro_alerts_sql]" rows="5" class="large-text"><?php echo esc_textarea( $opts['pro_alerts_sql'] ?? '' ); ?></textarea>
						<p class="dqa-desc"><?php esc_html_e( 'Return a numeric scalar value (first row, first column).', 'data-query-assistant' ); ?></p>
					</div>
				</div>
				<?php submit_button( __( 'Save Alert Settings', 'data-query-assistant' ) ); ?>
			</div>
		</form>
		<?php
	}

	/* ── Pro feature locked state ───────────────────────────────── */

	private static function render_pro_locked( string $title, string $desc, string $icon ): void {
		?>
		<div class="dqa-card" style="text-align:center;padding:40px 24px;">
			<div style="font-size:40px;margin-bottom:12px;"><?php echo esc_html( $icon ); ?></div>
			<h3 style="justify-content:center;margin-bottom:8px;"><?php echo esc_html( $title ); ?> <span class="dqa-badge"><?php esc_html_e( 'Pro', 'data-query-assistant' ); ?></span></h3>
			<p style="font-size:13px;color:#757575;max-width:420px;margin:0 auto 16px;line-height:1.6;"><?php echo esc_html( $desc ); ?></p>
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=data-query-assistant' ) ); ?>" class="dqa-inline-btn">
				<?php esc_html_e( 'Enable Pro in Settings → Advanced', 'data-query-assistant' ); ?>
			</a>
		</div>
		<?php
	}

	/* ── Helpers ────────────────────────────────────────────────── */

	/**
	 * Parses lines in format "Title | SELECT ...".
	 */
	private static function parse_widgets( string $raw ): array {
		$lines   = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		$widgets = [];
		foreach ( $lines as $line ) {
			$title = '';
			$sql   = $line;
			if ( str_contains( $line, '|' ) ) {
				$parts = explode( '|', $line, 2 );
				$title = trim( (string) ( $parts[0] ?? '' ) );
				$sql   = trim( (string) ( $parts[1] ?? '' ) );
			}
			if ( '' === $sql ) {
				continue;
			}
			if ( '' === $title ) {
				$title = mb_strimwidth( $sql, 0, 60, '…' );
			}
			$widgets[] = [
				'title' => $title,
				'sql'   => $sql,
			];
			if ( count( $widgets ) >= 6 ) {
				break;
			}
		}
		return $widgets;
	}
}
