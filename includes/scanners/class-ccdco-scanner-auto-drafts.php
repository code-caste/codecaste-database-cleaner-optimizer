<?php
/**
 * Auto-draft scanner.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scanner_Auto_Drafts
 */
class CCDCO_Scanner_Auto_Drafts extends CCDCO_Abstract_Scanner {

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'auto_drafts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Auto-Drafts', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Finds abandoned auto-draft posts (created by the editor but never saved) that are older than a day and safe to remove.', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * Get eligible auto-draft post IDs (older than 24 hours).
	 *
	 * @param int $limit Optional limit.
	 * @return int[]
	 */
	private function get_eligible_ids( $limit = 0 ) {
		$wpdb   = CCDCO_DB_Helper::db();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date_gmt < %s LIMIT %d",
					$cutoff,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date_gmt < %s",
					$cutoff
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

		$ids   = $this->get_eligible_ids();
		$count = count( $ids );

		$size = 0;
		if ( $count > 0 ) {
			$chunks = array_chunk( $ids, 1000 );
			foreach ( $chunks as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$size += (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT SUM(LENGTH(post_content) + LENGTH(post_title)) FROM {$wpdb->posts} WHERE ID IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$chunk
					)
				);
			}
		}

		return $this->build_result(
			$count > 100 ? 'warning' : ( $count > 0 ? 'notice' : 'info' ),
			__( 'Auto-Drafts', 'codecaste-database-cleaner-optimizer' ),
			$count,
			$size
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function cleanup_batch( $args ) {
		$batch_size = isset( $args['limit'] ) ? (int) $args['limit'] : $this->get_batch_size();
		// Always derive the deletion set from the live eligibility query. Never
		// accept caller-supplied post IDs for destructive operations.
		$ids = $this->get_eligible_ids( $batch_size );

		$processed = 0;
		foreach ( $ids as $id ) {
			// wp_delete_post() with force delete bypasses trash for auto-drafts and cleans meta/terms.
			if ( wp_delete_post( $id, true ) ) {
				++$processed;
			}
		}

		$remaining = count( $this->get_eligible_ids() );

		return array(
			'processed' => $processed,
			'remaining' => $remaining,
			'done'      => ( 0 === $remaining ),
		);
	}
}
