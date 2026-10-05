<?php
/**
 * Post revision scanner.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scanner_Post_Revisions
 */
class CCDCO_Scanner_Post_Revisions extends CCDCO_Abstract_Scanner {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'post_revisions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Post Revisions', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Counts stored post revisions and their approximate size. A configurable number of the most recent revisions per post is always kept.', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * How many of the most recent revisions per post to always keep.
	 *
	 * @return int
	 */
	private function get_keep_latest() {
		$settings = CCDCO_Settings::get_settings();
		$keep     = isset( $settings['revisions_keep_latest'] ) ? (int) $settings['revisions_keep_latest'] : 5;
		return max( 0, $keep );
	}

	/**
	 * Get the IDs of revisions eligible for cleanup (beyond the retention count).
	 *
	 * @param int $limit Optional limit on number of ids returned.
	 * @return int[]
	 */
	private function get_eligible_revision_ids( $limit = 0 ) {
		$wpdb = CCDCO_DB_Helper::db();
		$keep = $this->get_keep_latest();

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT r.ID
					FROM {$wpdb->posts} r
					INNER JOIN (
						SELECT ID, post_parent,
							( SELECT COUNT(*) FROM {$wpdb->posts} r2
								WHERE r2.post_parent = r1.post_parent
								AND r2.post_type = 'revision'
								AND r2.ID >= r1.ID
							) AS rank_from_newest
						FROM {$wpdb->posts} r1
						WHERE r1.post_type = 'revision'
					) ranked ON ranked.ID = r.ID
					WHERE r.post_type = 'revision'
					AND ranked.rank_from_newest > %d
					ORDER BY r.ID ASC
					LIMIT %d",
					$keep,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT r.ID
					FROM {$wpdb->posts} r
					INNER JOIN (
						SELECT ID, post_parent,
							( SELECT COUNT(*) FROM {$wpdb->posts} r2
								WHERE r2.post_parent = r1.post_parent
								AND r2.post_type = 'revision'
								AND r2.ID >= r1.ID
							) AS rank_from_newest
						FROM {$wpdb->posts} r1
						WHERE r1.post_type = 'revision'
					) ranked ON ranked.ID = r.ID
					WHERE r.post_type = 'revision'
					AND ranked.rank_from_newest > %d
					ORDER BY r.ID ASC",
					$keep
				)
			);
		}

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * {@inheritDoc}
	 */
	public function scan() {
		$wpdb = CCDCO_DB_Helper::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );

		$eligible_ids   = $this->get_eligible_revision_ids();
		$eligible_count = count( $eligible_ids );

		$size = 0;
		if ( $eligible_count > 0 ) {
			$chunks = array_chunk( $eligible_ids, 1000 );
			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$size += (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT SUM(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt)) FROM {$wpdb->posts} WHERE ID IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$chunk
					)
				);
			}
		}

		return $this->build_result(
			$eligible_count > 200 ? 'warning' : ( $eligible_count > 0 ? 'notice' : 'info' ),
			__( 'Post Revisions', 'codecaste-database-cleaner-optimizer' ),
			$eligible_count,
			$size,
			array(
				'total_revisions'    => $total_count,
				'eligible_for_cleanup' => $eligible_count,
				'keep_latest_per_post' => $this->get_keep_latest(),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function cleanup_batch( $args ) {
		$batch_size = isset( $args['limit'] ) ? (int) $args['limit'] : $this->get_batch_size();

		$ids = $this->get_eligible_revision_ids( $batch_size );

		$processed = 0;
		foreach ( $ids as $id ) {
			// wp_delete_post_revision() runs core hooks and deletes associated meta safely.
			if ( wp_delete_post_revision( $id ) ) {
				++$processed;
			}
		}

		$remaining = count( $this->get_eligible_revision_ids() );

		return array(
			'processed' => $processed,
			'remaining' => $remaining,
			'done'      => ( 0 === $remaining ),
		);
	}
}
