<?php
defined( 'ABSPATH' ) || exit;

class WPC_Logger {

    public static function log( string $msg ): void {
        if ( WPC_Settings::get( 'debug_mode' ) ) {
            error_log( '[WP Copilot] ' . $msg );
        }
    }

    public static function warn( string $msg ): void {
        error_log( '[WP Copilot WARN] ' . $msg );
    }

    public static function error( string $msg ): void {
        error_log( '[WP Copilot ERROR] ' . $msg );
    }
}
