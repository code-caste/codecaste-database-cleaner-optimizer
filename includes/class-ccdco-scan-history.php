<?php
/**
 * Scan history storage.
 *
 * Stores a capped history list in a WordPress option. Older releases used a
 * custom table; migrate_legacy_table() imports those rows and removes it.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scan_History
 */
class CCDCO_Scan_History {

	/**
	 * Option used for history rows.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'ccdco_scan_history';

	/**
	 * Maximum stored history rows.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 100;

	/**
	 * How long a history row is kept before it is auto-deleted.
	 *
	 * @var int Seconds.
	 */
	const RETENTION_SECONDS = DAY_IN_SECONDS; // 24 hours.

	/**
	 * Recurring cron hook used to purge expired history rows in the background,
	 * so history is deleted after 24 hours even if nobody opens the admin screen.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'ccdco_purge_scan_history';

	/**
	 * Build the display format: the site's date + time format (no timezone label).
	 *
	 * @return string
	 */
	private static function display_format() {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	/**
	 * Format a Unix timestamp for display in the site's own timezone
	 * (Settings > General > Timezone), so users in any country see their local time.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string Empty string when there is no timestamp.
	 */
	public static function format_timestamp( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return '';
		}
		return wp_date( self::display_format(), $timestamp );
	}

	/**
	 * Format a stored scan_date for display in the site's timezone.
	 *
	 * scan_date is stored in UTC, so changing the site timezone later still shows correct
	 * times for existing rows.
	 *
	 * @param string $mysql_date UTC datetime string such as "2026-09-29 09:34:05".
	 * @return string Empty string when the value cannot be parsed.
	 */
	public static function format_scan_date( $mysql_date ) {
		if ( empty( $mysql_date ) ) {
			return '';
		}
		try {
			$dt = new DateTime( (string) $mysql_date, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return '';
		}
		return self::format_timestamp( $dt->getTimestamp() );
	}

	/**
	 * Get the current date/time string (MySQL format) in UTC.
	 *
	 * @return string
	 */
	private static function current_utc_mysql() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Remove rows older than the 24-hour retention window.
	 *
	 * @param array $history Raw history rows, newest first.
	 * @return array Filtered rows, newest first.
	 */
	private static function purge_expired( $history ) {
		if ( empty( $history ) || ! is_array( $history ) ) {
			return array();
		}

		try {
			$cutoff = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return $history;
		}
		$cutoff->modify( '-' . self::RETENTION_SECONDS . ' seconds' );

		$kept = array();
		foreach ( $history as $row ) {
			if ( ! is_array( $row ) || empty( $row['scan_date'] ) ) {
				continue;
			}
			try {
				$row_time = new DateTime( $row['scan_date'], new DateTimeZone( 'UTC' ) );
			} catch ( Exception $e ) {
				continue;
			}
			if ( $row_time >= $cutoff ) {
				$kept[] = $row;
			}
		}

		return $kept;
	}

	/**
	 * Purge expired rows from storage right now and persist the result.
	 * Used by the hourly cron job and can be called directly at any time.
	 *
	 * @return void
	 */
	public static function purge_now() {
		$history = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $history ) ) {
			return;
		}

		$fresh = self::purge_expired( $history );

		if ( count( $fresh ) !== count( $history ) ) {
			update_option( self::OPTION_NAME, $fresh, false );
		}
	}

	/**
	 * Schedule the hourly background purge, if not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule_purge_event() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the hourly background purge.
	 *
	 * @return void
	 */
	public static function unschedule_purge_event() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Whether scan history is enabled in settings.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = CCDCO_Settings::get_settings();
		return ! empty( $settings['enable_scan_history'] );
	}

	/**
	 * Record a completed scan.
	 *
	 * @param array $data Scan summary.
	 * @return bool
	 */
	public static function record( $data ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$history = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$row = array(
			'scan_date'      => self::current_utc_mysql(),
			'database_size'  => isset( $data['database_size'] ) ? (int) $data['database_size'] : 0,
			'health_score'   => isset( $data['health_score'] ) ? (int) $data['health_score'] : 0,
			'issue_count'    => isset( $data['issue_count'] ) ? (int) $data['issue_count'] : 0,
			'cleanable_size' => isset( $data['cleanable_size'] ) ? (int) $data['cleanable_size'] : 0,
			'scan_duration'  => isset( $data['scan_duration'] ) ? (float) $data['scan_duration'] : 0.0,
		);

		array_unshift( $history, $row );
		$history = self::purge_expired( $history );
		$history = array_slice( $history, 0, self::MAX_ENTRIES );

		return update_option( self::OPTION_NAME, $history, false );
	}

	/**
	 * Get scan history rows, most recent first. Rows older than the 24-hour
	 * retention window are purged from storage as a side effect.
	 *
	 * @param int $limit  Number of rows.
	 * @param int $offset Offset.
	 * @return array[]
	 */
	public static function get_history( $limit = 20, $offset = 0 ) {
		$history = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $history ) ) {
			return array();
		}

		$fresh = self::purge_expired( $history );
		if ( count( $fresh ) !== count( $history ) ) {
			update_option( self::OPTION_NAME, $fresh, false );
		}
		$history = $fresh;

		$limit  = max( 1, min( self::MAX_ENTRIES, (int) $limit ) );
		$offset = max( 0, (int) $offset );

		$rows = array_slice( $history, $offset, $limit );

		// Normalize rows from older plugin releases so the admin UI can safely
		// render history even when a field was not present in an older record.
		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$issue_count = 0;
			if ( isset( $row['issue_count'] ) ) {
				$issue_count = (int) $row['issue_count'];
			} elseif ( isset( $row['issues'] ) && is_array( $row['issues'] ) ) {
				$issue_count = count( $row['issues'] );
			} elseif ( isset( $row['issues_found'] ) ) {
				$issue_count = (int) $row['issues_found'];
			}

			$cleanable_size = 0;
			if ( isset( $row['cleanable_size'] ) ) {
				$cleanable_size = (int) $row['cleanable_size'];
			} elseif ( isset( $row['potentially_cleanable'] ) ) {
				$cleanable_size = (int) $row['potentially_cleanable'];
			}

			$scan_duration = 0.0;
			if ( isset( $row['scan_duration'] ) ) {
				$scan_duration = (float) $row['scan_duration'];
			} elseif ( isset( $row['duration'] ) ) {
				$scan_duration = (float) $row['duration'];
			}

			$normalized[] = array(
				'scan_date'      => isset( $row['scan_date'] ) ? sanitize_text_field( $row['scan_date'] ) : '',
				'database_size'  => isset( $row['database_size'] ) ? (int) $row['database_size'] : 0,
				'health_score'   => isset( $row['health_score'] ) ? (int) $row['health_score'] : 0,
				'issue_count'    => max( 0, $issue_count ),
				'cleanable_size' => max( 0, $cleanable_size ),
				'scan_duration'  => max( 0.0, $scan_duration ),
			);
		}

		return $normalized;
	}

	/**
	 * Get the most recent scan history row, if any.
	 *
	 * @return array|null
	 */
	public static function get_latest() {
		$rows = self::get_history( 1, 0 );
		return ! empty( $rows ) ? $rows[0] : null;
	}

	/**
	 * Total number of stored history rows.
	 *
	 * @return int
	 */
	public static function count_all() {
		self::purge_now();
		$history = get_option( self::OPTION_NAME, array() );
		return is_array( $history ) ? count( $history ) : 0;
	}

	/**
	 * Clear all stored scan history.
	 *
	 * @return bool
	 */
	public static function clear_all() {
		return delete_option( self::OPTION_NAME );
	}

	/**
	 * Migrate history from the legacy custom table and remove that table.
	 *
	 * This is safe to run repeatedly. Existing option-backed history is preserved
	 * and legacy rows are prepended only when the option is empty.
	 *
	 * @return void
	 */
	public static function migrate_legacy_table() {
		global $wpdb;

		$legacy_table = $wpdb->prefix . 'ccdco_scan_history';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy_table ) ) );
		if ( $exists !== $legacy_table ) {
			return;
		}

		$current = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$legacy_rows = $wpdb->get_results(
			"SELECT scan_date, database_size, health_score, issue_count, cleanable_size, scan_duration FROM `{$legacy_table}` ORDER BY scan_date DESC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
			ARRAY_A
		);

		if ( empty( $current ) && is_array( $legacy_rows ) && ! empty( $legacy_rows ) ) {
			$migrated = array();
			foreach ( $legacy_rows as $row ) {
				$migrated[] = array(
					'scan_date'      => isset( $row['scan_date'] ) ? sanitize_text_field( $row['scan_date'] ) : '',
					'database_size'  => isset( $row['database_size'] ) ? (int) $row['database_size'] : 0,
					'health_score'   => isset( $row['health_score'] ) ? (int) $row['health_score'] : 0,
					'issue_count'    => isset( $row['issue_count'] ) ? (int) $row['issue_count'] : 0,
					'cleanable_size' => isset( $row['cleanable_size'] ) ? (int) $row['cleanable_size'] : 0,
					'scan_duration'  => isset( $row['scan_duration'] ) ? (float) $row['scan_duration'] : 0.0,
				);
			}
			update_option( self::OPTION_NAME, array_slice( $migrated, 0, self::MAX_ENTRIES ), false );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectQuery.NoCaching,WordPress.DB.DirectQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `{$legacy_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}

/**
 * Background hook: purge scan-history rows older than 24 hours.
 * Scheduled hourly on activation so history is deleted automatically
 * even when nobody opens the admin screen.
 */
add_action( CCDCO_Scan_History::CRON_HOOK, array( 'CCDCO_Scan_History', 'purge_now' ) );
