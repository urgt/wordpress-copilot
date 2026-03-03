<?php
/**
 * Uninstall WordPress Copilot.
 *
 * Fires when the plugin is deleted via the WordPress admin.
 * Removes all plugin data: database tables and options.
 *
 * @package WordPress_Copilot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop plugin tables.
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}copilot_logs`" );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}copilot_chats`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Delete plugin options and transients.
delete_option( 'wpc_settings_v2' );
delete_transient( 'wpc_schema_v2' );
