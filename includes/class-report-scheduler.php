<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pro: scheduled email reports with CSV attachment.
 */
class DQA_Report_Scheduler {

	const CRON_HOOK = 'dqa_run_scheduled_reports';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'ensure_schedule' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_due_report' ] );
	}

	public static function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function clear_schedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	public static function run_due_report(): void {
		if ( ! DQA_Feature_Gates::is_enabled( 'scheduled_reports' ) ) {
			return;
		}
		if ( ! DQA_Settings::get( 'pro_reports_enabled' ) ) {
			return;
		}

		$next_run = (int) DQA_Settings::get( 'pro_reports_next_run', 0 );
		if ( $next_run > time() ) {
			return;
		}

		$sql   = trim( (string) DQA_Settings::get( 'pro_reports_sql', '' ) );
		$email = sanitize_email( (string) DQA_Settings::get( 'pro_reports_email', '' ) );
		if ( '' === $sql || ! is_email( $email ) ) {
			self::schedule_next();
			return;
		}

		$rows = DQA_Query_Executor::execute( $sql );
		if ( is_wp_error( $rows ) ) {
			DQA_Logger::warn( 'Scheduled report failed: ' . $rows->get_error_message() );
			self::schedule_next();
			return;
		}

		$attachments = [];
		$tmp_file    = wp_tempnam( 'dqa-report.csv' );
		if ( $tmp_file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing temporary attachment before wp_mail().
			file_put_contents( $tmp_file, self::rows_to_csv( $rows ) );
			$attachments[] = $tmp_file;
		}

		$subject = sprintf(
			/* translators: %s: datetime in site timezone */
			__( 'Data Query Assistant report (%s)', 'data-query-assistant' ),
			wp_date( 'Y-m-d H:i' )
		);
		$body = sprintf(
			/* translators: %d: number of rows in report */
			__( 'Rows returned: %d', 'data-query-assistant' ),
			count( $rows )
		) . "\n\n" . __( 'SQL:', 'data-query-assistant' ) . "\n" . $sql;

		$sent = wp_mail( $email, $subject, $body, [], $attachments );
		if ( ! $sent ) {
			DQA_Logger::warn( 'Scheduled report email failed to send.' );
		}

		foreach ( $attachments as $file ) {
			if ( is_string( $file ) && file_exists( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_exists -- Temp file cleanup after mail send.
				wp_delete_file( $file );
			}
		}

		self::set_runtime_values(
			[
				'pro_reports_last_run' => time(),
			]
		);
		self::schedule_next();
	}

	private static function rows_to_csv( array $rows ): string {
		if ( empty( $rows ) ) {
			return "no_rows\n";
		}

		$headers = array_keys( $rows[0] );
		$lines   = [ self::csv_line( $headers ) ];
		foreach ( $rows as $row ) {
			$values = [];
			foreach ( $headers as $header ) {
				$values[] = (string) ( $row[ $header ] ?? '' );
			}
			$lines[] = self::csv_line( $values );
		}
		return implode( "\n", $lines ) . "\n";
	}

	private static function csv_line( array $cells ): string {
		return implode(
			',',
			array_map(
				function ( $cell ) {
					$cell = str_replace( '"', '""', (string) $cell );
					if ( preg_match( '/[",\n]/', $cell ) ) {
						return '"' . $cell . '"';
					}
					return $cell;
				},
				$cells
			)
		);
	}

	private static function schedule_next(): void {
		$frequency = (string) DQA_Settings::get( 'pro_reports_frequency', 'daily' );
		$next      = match ( $frequency ) {
			'weekly'  => time() + WEEK_IN_SECONDS,
			'monthly' => time() + MONTH_IN_SECONDS,
			default   => time() + DAY_IN_SECONDS,
		};

		self::set_runtime_values(
			[
				'pro_reports_next_run' => (int) $next,
			]
		);
	}

	private static function set_runtime_values( array $updates ): void {
		$opts = get_option( DQA_Settings::OPTION_KEY, [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		foreach ( $updates as $key => $value ) {
			$opts[ $key ] = $value;
		}
		update_option( DQA_Settings::OPTION_KEY, $opts, false );
		DQA_Settings::bust_cache();
	}
}
