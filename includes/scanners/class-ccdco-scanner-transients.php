<?php
/**
 * Expired transients scanner.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scanner_Transients
 */
class CCDCO_Scanner_Transients extends CCDCO_Abstract_Scanner {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'expired_transients';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Expired Transients', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Finds transient options that have already expired and can be safely deleted, including their timeout markers.', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * Get the expired transient option names (without the _transient_ prefix)
	 * for single site installs. Handles both regular and site transients.
	 *
	 * @param int $limit Optional limit.
	 * @return array
	 */
	private function get_expired_names( $limit = 0 ) {
		$wpdb = CCDCO_DB_Helper::db();
		$now  = time();
		$like = $wpdb->esc_like( '_transient_timeout_' ) . '%';

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT SUBSTRING(option_name, 20) AS name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
					$like,
					$now,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT SUBSTRING(option_name, 20) AS name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
					$like,
					$now
				)
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scan() {
		$wpdb = CCDCO_DB_Helper::db();
		$now  = time();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$size = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(option_name) + LENGTH(option_value))
				FROM {$wpdb->options}
				WHERE ( option_name LIKE %s OR option_name LIKE %s )
				AND option_name LIKE %s",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_transient_' ) . '%'
			)
		);

		// Each expired transient has both a value row and a timeout row, so double the count for the estimate.
		$estimated_row_count = $count * 2;

		return $this->build_result(
			$count > 0 ? 'notice' : 'info',
			__( 'Expired Transients', 'codecaste-database-cleaner-optimizer' ),
			$estimated_row_count,
			(int) $size,
			array(
				'expired_timeout_rows' => $count,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function cleanup_batch( $args ) {
		$wpdb       = CCDCO_DB_Helper::db();
		$batch_size = isset( $args['limit'] ) ? (int) $args['limit'] : $this->get_batch_size();
		$now        = time();

		$names = $this->get_expired_names( $batch_size );

		$processed = 0;
		foreach ( $names as $name ) {
			$name = sanitize_text_field( $name );
			if ( '' === $name ) {
				continue;
			}
			// delete_transient() handles both the value and its timeout row safely.
			if ( delete_transient( $name ) ) {
				++$processed;
			} else {
				// delete_transient() can return false if already gone; still count it as handled.
				++$processed;
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		return array(
			'processed' => $processed,
			'remaining' => $remaining,
			'done'      => ( 0 === $remaining ),
		);
	}
}
