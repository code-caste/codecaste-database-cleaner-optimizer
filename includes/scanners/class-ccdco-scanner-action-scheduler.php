<?php
/**
 * Action Scheduler scanner.
 *
 * Action Scheduler (bundled with WooCommerce and other plugins) stores
 * background job history in wp_actionscheduler_actions. This scanner
 * only runs when that table is present.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scanner_Action_Scheduler
 */
class CCDCO_Scanner_Action_Scheduler extends CCDCO_Abstract_Scanner {

	/**
	 * Statuses considered safe to clean once old enough.
	 *
	 * @var string[]
	 */
	private $cleanup_statuses = array( 'complete', 'failed', 'canceled' );

	/**
	 * {@inheritDoc}
	 */
	public function get_id() {
		return 'action_scheduler';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label() {
		return __( 'Action Scheduler Records', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'When Action Scheduler is present (e.g. via WooCommerce), analyzes completed, failed and canceled scheduled actions older than the retention window.', 'codecaste-database-cleaner-optimizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return CCDCO_DB_Helper::table_exists( 'actionscheduler_actions' );
	}

	/**
	 * Age in days before an eligible action is considered for cleanup.
	 *
	 * @return int
	 */
	private function get_retention_days() {
		$settings = CCDCO_Settings::get_settings();
		return isset( $settings['action_scheduler_retention_days'] ) ? max( 1, (int) $settings['action_scheduler_retention_days'] ) : 30;
	}

	/**
	 * Get eligible action ids.
	 *
	 * @param int $limit Optional limit.
	 * @return int[]
	 */
	private function get_eligible_ids( $limit = 0 ) {
		$wpdb   = CCDCO_DB_Helper::db();
		$table  = $wpdb->prefix . 'actionscheduler_actions';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $this->get_retention_days() * DAY_IN_SECONDS ) );

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action_id FROM `{$table}` WHERE status IN ('complete', 'failed', 'canceled') AND last_attempt_gmt < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cutoff,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action_id FROM `{$table}` WHERE status IN ('complete', 'failed', 'canceled') AND last_attempt_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		if ( ! $this->is_available() ) {
			return $this->build_result( 'info', __( 'Action Scheduler Records', 'codecaste-database-cleaner-optimizer' ), 0, 0 );
		}

		$wpdb   = CCDCO_DB_Helper::db();
		$table  = $wpdb->prefix . 'actionscheduler_actions';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $this->get_retention_days() * DAY_IN_SECONDS ) );

		$statuses = $this->cleanup_statuses;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE status IN ('complete', 'failed', 'canceled') AND last_attempt_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return $this->build_result(
			$count > 5000 ? 'warning' : ( $count > 0 ? 'notice' : 'info' ),
			__( 'Old Action Scheduler Records', 'codecaste-database-cleaner-optimizer' ),
			$count,
			0,
			array(
				'retention_days' => $this->get_retention_days(),
				'statuses'       => $statuses,
			)
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
		$table      = $wpdb->prefix . 'actionscheduler_actions';
		$logs_table = $wpdb->prefix . 'actionscheduler_logs';
		$batch_size = isset( $args['limit'] ) ? (int) $args['limit'] : $this->get_batch_size();

		$ids = $this->get_eligible_ids( $batch_size );

		$processed = 0;
		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			if ( CCDCO_DB_Helper::table_exists( 'actionscheduler_logs' ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `{$logs_table}` WHERE action_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
						...$ids
					)
				);
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$processed = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE action_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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
