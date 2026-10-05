<?php
/**
 * Activation and upgrade routine.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Activator
 */
class CCDCO_Activator {

	/**
	 * Current plugin data schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '2.0.0';

	/**
	 * Run on plugin activation.
	 *
	 * No custom database table is created. Scan history uses a WordPress option.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-db-helper.php';
		require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-scan-history.php';
		require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-settings.php';

		CCDCO_Scan_History::migrate_legacy_table();
		CCDCO_Scan_History::schedule_purge_event();

		if ( false === get_option( CCDCO_Settings::OPTION_NAME, false ) ) {
			update_option( CCDCO_Settings::OPTION_NAME, CCDCO_Settings::get_defaults() );
		}

		update_option( 'ccdco_db_version', self::DB_VERSION, false );

		if ( false === get_option( 'ccdco_activated_time', false ) ) {
			update_option( 'ccdco_activated_time', time(), false );
		}
	}

	/**
	 * Upgrade existing installations without creating a custom table.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'ccdco_db_version', '' );
		if ( version_compare( (string) $current, self::DB_VERSION, '>=' ) ) {
			return;
		}

		CCDCO_Scan_History::migrate_legacy_table();
		update_option( 'ccdco_db_version', self::DB_VERSION, false );
	}

	/**
	 * Deprecated compatibility method. New versions do not create a history table.
	 *
	 * @deprecated 2.0.0
	 * @return void
	 */
	public static function create_tables() {
		return;
	}
}
