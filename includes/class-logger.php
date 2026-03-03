<?php
defined( 'ABSPATH' ) || exit;

class WPC_Logger {

	public static function log( string $msg ): void {
		if ( WPC_Settings::get( 'debug_mode' ) ) {
			error_log( '[WP Copilot] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function warn( string $msg ): void {
		error_log( '[WP Copilot WARN] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	public static function error( string $msg ): void {
		error_log( '[WP Copilot ERROR] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
