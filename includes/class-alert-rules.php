<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pro: threshold-based smart alerts.
 */
class DQA_Alert_Rules {

	const CRON_HOOK = 'dqa_run_smart_alerts';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'ensure_schedule' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_due_alert' ] );
	}

	public static function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 420, 'hourly', self::CRON_HOOK );
		}
	}

	public static function clear_schedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	public static function run_due_alert(): void {
		if ( ! DQA_Feature_Gates::is_enabled( 'smart_alerts' ) ) {
			return;
		}
		if ( ! DQA_Settings::get( 'pro_alerts_enabled' ) ) {
			return;
		}

		$next_check = (int) DQA_Settings::get( 'pro_alerts_next_check', 0 );
		if ( $next_check > time() ) {
			return;
		}

		$sql   = trim( (string) DQA_Settings::get( 'pro_alerts_sql', '' ) );
		$email = sanitize_email( (string) DQA_Settings::get( 'pro_alerts_email', '' ) );
		if ( '' === $sql || ! is_email( $email ) ) {
			self::schedule_next();
			return;
		}

		$rows = DQA_Query_Executor::execute( $sql );
		if ( is_wp_error( $rows ) ) {
			DQA_Logger::warn( 'Smart alert query failed: ' . $rows->get_error_message() );
			self::schedule_next();
			return;
		}
		$value = self::extract_numeric_value( $rows );
		if ( null === $value ) {
			DQA_Logger::warn( 'Smart alert expected a numeric scalar result.' );
			self::schedule_next();
			return;
		}

		$operator  = (string) DQA_Settings::get( 'pro_alerts_operator', 'gt' );
		$threshold = (float) DQA_Settings::get( 'pro_alerts_threshold', 0 );
		if ( ! self::compare( $value, $operator, $threshold ) ) {
			self::set_runtime_values(
				[
					'pro_alerts_last_value' => $value,
				]
			);
			self::schedule_next();
			return;
		}

		$subject = __( 'Data Query Assistant alert triggered', 'data-query-assistant' );
		$body    = sprintf(
			/* translators: 1: numeric value, 2: operator label, 3: threshold */
			__( 'Alert value %1$s matched condition %2$s %3$s.', 'data-query-assistant' ),
			(string) $value,
			self::operator_label( $operator ),
			(string) $threshold
		) . "\n\n" . __( 'SQL:', 'data-query-assistant' ) . "\n" . $sql;

		$sent = wp_mail( $email, $subject, $body );
		if ( ! $sent ) {
			DQA_Logger::warn( 'Smart alert email failed to send.' );
		}

		self::set_runtime_values(
			[
				'pro_alerts_last_value' => $value,
				'pro_alerts_last_sent'  => time(),
			]
		);
		self::schedule_next();
	}

	private static function extract_numeric_value( array $rows ): ?float {
		if ( empty( $rows ) || ! isset( $rows[0] ) || ! is_array( $rows[0] ) ) {
			return null;
		}
		$first = reset( $rows[0] );
		if ( ! is_numeric( $first ) ) {
			return null;
		}
		return (float) $first;
	}

	private static function compare( float $value, string $operator, float $threshold ): bool {
		return match ( $operator ) {
			'lt'      => $value < $threshold,
			'lte'     => $value <= $threshold,
			'gte'     => $value >= $threshold,
			default   => $value > $threshold,
		};
	}

	private static function operator_label( string $operator ): string {
		return match ( $operator ) {
			'lt'      => '<',
			'lte'     => '<=',
			'gte'     => '>=',
			default   => '>',
		};
	}

	private static function schedule_next(): void {
		$frequency = (string) DQA_Settings::get( 'pro_alerts_frequency', 'daily' );
		$next      = match ( $frequency ) {
			'weekly'  => time() + WEEK_IN_SECONDS,
			'monthly' => time() + MONTH_IN_SECONDS,
			default   => time() + DAY_IN_SECONDS,
		};

		self::set_runtime_values(
			[
				'pro_alerts_next_check' => (int) $next,
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
