<?php
/**
 * Database / table size scanner.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scanner_Table_Size
 */
class CCDCO_Scanner_Table_Size extends CCDCO_Abstract_Scanner {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'table_size';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Database & Table Size', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Reports the largest tables in your database by data and index size, with row counts and share of total database size.', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * This scanner is informational only; there is nothing to clean up.
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
		$tables      = CCDCO_DB_Helper::get_all_table_sizes();
		$total_size  = 0;
		$table_count = count( $tables );

		foreach ( $tables as $table ) {
			$total_size += (int) $table['total_size'];
		}

		$details = array();
		foreach ( $tables as $table ) {
			$total       = (int) $table['total_size'];
			$percentage  = $total_size > 0 ? round( ( $total / $total_size ) * 100, 2 ) : 0;
			$details[]   = array(
				'name'       => $table['name'],
				'rows'       => (int) $table['table_rows'],
				'data_size'  => (int) $table['data_size'],
				'index_size' => (int) $table['index_size'],
				'total_size' => $total,
				'percentage' => $percentage,
			);
		}

		$severity = 'info';
		if ( $total_size > 1073741824 ) { // 1 GB.
			$severity = 'warning';
		} elseif ( $total_size > 268435456 ) { // 256 MB.
			$severity = 'notice';
		}

		return $this->build_result(
			$severity,
			__( 'Database Size Overview', 'codecaste-database-cleaner-optimizer' ),
			$table_count,
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
