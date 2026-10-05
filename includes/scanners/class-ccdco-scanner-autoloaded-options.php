<?php
/**
 * Autoloaded options scanner.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scanner_Autoloaded_Options
 */
class CCDCO_Scanner_Autoloaded_Options extends CCDCO_Abstract_Scanner {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'autoloaded_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Autoloaded Options', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Reports total autoloaded option data and the largest individual autoloaded options. A large autoload size is worth reviewing, but is not automatically unsafe.', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * No automated cleanup is offered; autoloaded data often belongs to
	 * active plugins/themes and must be reviewed manually.
	 *
	 * @return bool
	 */
	public function supports_cleanup() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function scan() {
		$wpdb = CCDCO_DB_Helper::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_size = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = %s",
				'yes'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = %s",
				'yes'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$largest = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size
				FROM {$wpdb->options}
				WHERE autoload = %s
				ORDER BY size DESC LIMIT 20",
				'yes'
			),
			ARRAY_A
		);

		$details = array();
		foreach ( (array) $largest as $row ) {
			$details[] = array(
				'name' => $row['option_name'],
				'size' => (int) $row['size'],
			);
		}

		$severity = 'info';
		if ( $total_size > 3145728 ) { // 3 MB.
			$severity = 'warning';
		} elseif ( $total_size > 1048576 ) { // 1 MB.
			$severity = 'notice';
		}

		return $this->build_result(
			$severity,
			__( 'Autoloaded Options', 'codecaste-database-cleaner-optimizer' ),
			$total_count,
			$total_size,
			$details
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function cleanup_batch( $args ) {
		return array(
			'processed' => 0,
			'remaining' => 0,
			'done'      => true,
		);
	}
}
