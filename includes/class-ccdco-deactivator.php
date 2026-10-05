<?php
/**
 * Deactivation routine.
 *
 * Deactivation intentionally leaves all plugin data (settings, scan
 * history, custom table) in place, since deactivating is not the same
 * as asking to delete data. Data removal only happens via uninstall.php
 * and only if the corresponding setting is enabled.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Deactivator
 */
class CCDCO_Deactivator {

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate() {
		// Clear any scheduled events this plugin may have set (none by default in MVP,
		// but this keeps the hook point ready for a future scheduled-scans feature).
		$timestamp = wp_next_scheduled( 'ccdco_scheduled_scan' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ccdco_scheduled_scan' );
		}

		// Stop the hourly scan-history purge job.
		if ( class_exists( 'CCDCO_Scan_History' ) ) {
			CCDCO_Scan_History::unschedule_purge_event();
		}
	}
}
