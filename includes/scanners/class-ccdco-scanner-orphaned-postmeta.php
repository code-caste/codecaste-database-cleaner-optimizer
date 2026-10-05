<?php
/**
 * Orphaned post meta scanner.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scanner_Orphaned_Postmeta
 */
class CCDCO_Scanner_Orphaned_Postmeta extends CCDCO_Abstract_Scanner {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'orphaned_postmeta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Orphaned Post Meta', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Finds postmeta rows whose associated post no longer exists. These are labeled as potentially orphaned and reviewed before removal.', 'codecaste-database-cleaner-optimizer' );
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
			SELECT pm.meta_id
			FROM {$wpdb->postmeta} pm
			LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.ID IS NULL
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
				"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
				FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.ID IS NULL
				ORDER BY pm.meta_id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'            => (int) ( $row['meta_id'] ?? 0 ),
				'parent_id'     => (int) ( $row['post_id'] ?? 0 ),
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
		$wpdb = CCDCO_DB_Helper::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$size = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(pm.meta_key) + LENGTH(pm.meta_value)) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
		);

		$details = $count > 0 ? $this->get_preview_items( $this->get_preview_limit() ) : array();

		return $this->build_result(
			$count > 1000 ? 'warning' : ( $count > 0 ? 'notice' : 'info' ),
			__( 'Potentially Orphaned Post Meta', 'codecaste-database-cleaner-optimizer' ),
			$count,
			$size,
			$details
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function cleanup_batch( $args ) {
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
					"DELETE FROM {$wpdb->postmeta} WHERE meta_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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
