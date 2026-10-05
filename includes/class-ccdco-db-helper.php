<?php
/**
 * Safe database helper.
 *
 * Centralizes $wpdb access so that every scanner/cleanup routine goes
 * through prepared statements and a whitelist of known table names.
 * Table/column identifiers can never come from user input in this plugin.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_DB_Helper
 */
class CCDCO_DB_Helper {

	/**
	 * Get the $wpdb global.
	 *
	 * @return wpdb
	 */
	public static function db() {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Get all base table names in the current database with their sizes.
	 *
	 * Uses information_schema which requires no dynamic identifiers.
	 *
	 * @return array[] List of associative arrays: name, engine, rows, data_size, index_size, total_size.
	 */
	public static function get_all_table_sizes() {
		$wpdb = self::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					TABLE_NAME AS name,
					ENGINE AS engine,
					TABLE_ROWS AS table_rows,
					COALESCE(DATA_LENGTH, 0) AS data_size,
					COALESCE(INDEX_LENGTH, 0) AS index_size,
					( COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) ) AS total_size
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s
					AND TABLE_TYPE = 'BASE TABLE'
				ORDER BY total_size DESC",
				DB_NAME
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Whitelisted lookup of a single table's size info, restricted to this
	 * install's own prefix so arbitrary table names can never be queried.
	 *
	 * @param string $unprefixed_table Table name without the $wpdb prefix, e.g. 'options'.
	 * @return array|null
	 */
	public static function get_table_size( $unprefixed_table ) {
		$wpdb  = self::db();
		$table = $wpdb->prefix . preg_replace( '/[^a-zA-Z0-9_]/', '', $unprefixed_table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					TABLE_NAME AS name,
					DATA_LENGTH AS data_size,
					INDEX_LENGTH AS index_size,
					( DATA_LENGTH + INDEX_LENGTH ) AS total_size,
					TABLE_ROWS AS table_rows
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
				DB_NAME,
				$table
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Whether a given core table exists for this install (prefix aware).
	 *
	 * @param string $unprefixed_table Table name without prefix.
	 * @return bool
	 */
	public static function table_exists( $unprefixed_table ) {
		$wpdb  = self::db();
		$table = $wpdb->prefix . preg_replace( '/[^a-zA-Z0-9_]/', '', $unprefixed_table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return ( $found === $table );
	}

	/**
	 * Format a byte count into a human readable string.
	 *
	 * @param int|float $bytes    Number of bytes.
	 * @param int       $decimals Decimal precision.
	 * @return string
	 */
	public static function format_bytes( $bytes, $decimals = 2 ) {
		$bytes = (float) $bytes;

		if ( $bytes <= 0 ) {
			return '0 B';
		}

		// Use decimal SI units for the human-readable UI. This matches WP-CLI's
		// uppercase MB/GB formats (1 MB = 1,000,000 bytes). Exact bytes remain
		// the authoritative measurement.
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$power = (int) floor( log( $bytes, 1000 ) );
		$power = min( $power, count( $units ) - 1 );

		$value = $bytes / pow( 1000, $power );

		return number_format_i18n( $value, $decimals ) . ' ' . $units[ $power ];
	}
}
