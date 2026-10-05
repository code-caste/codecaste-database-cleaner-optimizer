<?php
/**
 * Orphaned term meta scanner.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scanner_Orphaned_Termmeta
 */
class CCDCO_Scanner_Orphaned_Termmeta extends CCDCO_Abstract_Scanner {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'orphaned_termmeta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Orphaned Term Meta', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Finds termmeta rows whose associated term no longer exists. These are labeled as potentially orphaned and reviewed before removal.', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return CCDCO_DB_Helper::table_exists( 'termmeta' );
	}

	/**
	 * Get eligible orphaned meta ids.
	 *
	 * @param int $limit Optional limit.
	 * @return int[]
	 */
	private function get_eligible_ids( $limit = 0 ) {
		$wpdb = CCDCO_DB_Helper::db();

		$sql = "
			SELECT tm.meta_id
			FROM {$wpdb->termmeta} tm
			LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
			WHERE t.term_id IS NULL
		";

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $sql );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Fetch a small preview of the actual orphaned rows so an admin can see
	 * real values (not just a count) before choosing to clean them up.
	 *
	 * @param int $limit Max rows to preview.
	 * @return array[]
	 */
	private function get_preview_items( $limit ) {
		$wpdb = CCDCO_DB_Helper::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tm.meta_id, tm.term_id, tm.meta_key, tm.meta_value
				FROM {$wpdb->termmeta} tm
				LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
				WHERE t.term_id IS NULL
				ORDER BY tm.meta_id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'            => (int) ( $row['meta_id'] ?? 0 ),
				'parent_id'     => (int) ( $row['term_id'] ?? 0 ),
				'meta_key_name' => (string) ( $row['meta_key'] ?? '' ),
				'meta_preview'  => $this->truncate_preview_value( $row['meta_value'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * {@inheritDoc}
	 */
	public function scan() {
		if ( ! $this->is_available() ) {
			return $this->build_result( 'info', __( 'Orphaned Term Meta', 'codecaste-database-cleaner-optimizer' ), 0, 0 );
		}

		$wpdb = CCDCO_DB_Helper::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$size = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(tm.meta_key) + LENGTH(tm.meta_value)) FROM {$wpdb->termmeta} tm LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id WHERE t.term_id IS NULL"
		);

		$details = $count > 0 ? $this->get_preview_items( $this->get_preview_limit() ) : array();

		return $this->build_result(
			$count > 500 ? 'warning' : ( $count > 0 ? 'notice' : 'info' ),
			__( 'Potentially Orphaned Term Meta', 'codecaste-database-cleaner-optimizer' ),
			$count,
			$size,
			$details
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function cleanup_batch( $args ) {
		if ( ! $this->is_available() ) {
			return array(
				'processed' => 0,
				'remaining' => 0,
				'done'      => true,
			);
		}

		$wpdb       = CCDCO_DB_Helper::db();
		$batch_size = isset( $args['limit'] ) ? (int) $args['limit'] : $this->get_batch_size();
		// Always derive the deletion set from the live orphan query. Never trust
		// caller-supplied IDs, even when the caller is an administrator.
		$ids = $this->get_eligible_ids( $batch_size );

		$processed = 0;
		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$processed = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->termmeta} WHERE meta_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...$ids
				)
			);
		}

		$remaining = count( $this->get_eligible_ids() );

		return array(
			'processed' => max( 0, $processed ),
			'remaining' => $remaining,
			'done'      => ( 0 === $remaining ),
		);
	}
}
