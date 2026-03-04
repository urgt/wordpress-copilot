<?php
defined( 'ABSPATH' ) || exit;

class DQA_Logger {

	public static function log( string $msg ): void {
		if ( DQA_Settings::get( 'debug_mode' ) ) {
			error_log( '[Data Query Assistant] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function warn( string $msg ): void {
		error_log( '[Data Query Assistant WARN] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	public static function error( string $msg ): void {
		error_log( '[Data Query Assistant ERROR] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
