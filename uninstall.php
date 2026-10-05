<?php
/**
 * Uninstall handler.
 *
 * Fires only when the plugin is deleted from the Plugins screen (not on
 * simple deactivation). Removes plugin options, the scan history table
 * and any transients this plugin created. It never touches core
 * WordPress content tables (posts, postmeta, options belonging to
 * other plugins, etc.) beyond its own footprint.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

// Exit if not called by WordPress during an actual uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove plugin options.
delete_option( 'ccdco_settings' );
delete_option( 'ccdco_db_version' );
delete_option( 'ccdco_activated_time' );
delete_option( 'ccdco_last_scan_results' );
delete_option( 'ccdco_last_scan_health' );
delete_option( 'ccdco_last_scan_time' );
delete_option( 'ccdco_last_scan_duration' );
delete_option( 'ccdco_scan_history' );
delete_option( 'ccdco_table_ownership_index_v1' );
delete_option( 'ccdco_unused_tables_last_scan' );
delete_option( 'ccdco_unused_tables_last_results' );

// Also remove the site option in case this ran in a multisite context.
delete_site_option( 'ccdco_settings' );

// Drop the plugin's own scan history table.
$ccdco_table = $wpdb->prefix . 'ccdco_scan_history';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS `{$ccdco_table}`" );

// Clean up any leftover transients created by this plugin (uses its own prefix only).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_ccdco_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ccdco_' ) . '%'
	)
);
