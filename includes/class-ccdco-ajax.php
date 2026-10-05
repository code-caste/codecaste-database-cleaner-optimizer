<?php
/**
 * AJAX handlers.
 *
 * Every handler here enforces:
 *  - a logged in admin with the 'manage_options' capability, and
 *  - a valid nonce created specifically for the CodeCaste Database Cleaner & Optimizer screens.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Ajax
 */
class CCDCO_Ajax {

	/**
	 * Capability required to use any AJAX endpoint of this plugin.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Nonce action name.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'ccdco_ajax_nonce';

	/**
	 * Register AJAX hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_ccdco_run_scan', array( __CLASS__, 'run_scan' ) );
		add_action( 'wp_ajax_ccdco_cleanup_batch', array( __CLASS__, 'cleanup_batch' ) );
		add_action( 'wp_ajax_ccdco_clear_history', array( __CLASS__, 'clear_history' ) );
		add_action( 'wp_ajax_ccdco_unused_tables_scan', array( __CLASS__, 'unused_tables_scan' ) );
		add_action( 'wp_ajax_ccdco_delete_unused_table', array( __CLASS__, 'delete_unused_table' ) );
	}

	/**
	 * Verify the current request is an authorized admin request for this plugin.
	 *
	 * Sends a JSON error and stops execution on failure.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to do this.', 'codecaste-database-cleaner-optimizer' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Run a full scan across all enabled scanners and store history.
	 *
	 * Scanning never modifies data.
	 *
	 * @return void
	 */
	public static function run_scan() {
		self::guard();

		$start = microtime( true );

		$results = CCDCO_Scanner_Engine::run_all();

		// Add display metadata for the admin UI without exposing table-level details.
		foreach ( $results as $scanner_id => &$result ) {
			$scanner = CCDCO_Scanner_Engine::get_scanner( $scanner_id );
			if ( $scanner ) {
				$result['label']       = $scanner->get_label();
				$result['description'] = $scanner->get_description();
			}
		}
		unset( $result );

		$health   = CCDCO_Health_Score::calculate( $results );
		$duration = microtime( true ) - $start;

		$total_db_size = 0;
		if ( isset( $results['table_size']['size'] ) ) {
			$total_db_size = (int) $results['table_size']['size'];
		}

		$cleanable_size = 0;
		$issue_count    = 0;
		foreach ( $results as $result ) {
			if ( ! empty( $result['can_cleanup'] ) ) {
				$cleanable_size += (int) $result['size'];
			}
			if ( 'info' !== $result['severity'] ) {
				++$issue_count;
			}
		}

		CCDCO_Scan_History::record(
			array(
				'database_size'  => $total_db_size,
				'health_score'   => $health['score'],
				'issue_count'    => $issue_count,
				'cleanable_size' => $cleanable_size,
				'scan_duration'  => $duration,
			)
		);

		update_option( 'ccdco_last_scan_results', $results, false );
		update_option( 'ccdco_last_scan_health', $health, false );
		$scan_time = time();
		update_option( 'ccdco_last_scan_time', $scan_time, false );
		update_option( 'ccdco_last_scan_duration', (float) $duration, false );

		wp_send_json_success(
			array(
				'results'  => $results,
				'health'   => $health,
				'duration' => round( $duration, 2 ),
				'db_size'  => $total_db_size,
				// Formatted server-side in the site's timezone so the UI matches the History tab.
				'scan_time_label' => CCDCO_Scan_History::format_timestamp( $scan_time ),
			)
		);
	}

	/**
	 * Run a single cleanup batch for one scanner.
	 *
	 * @return void
	 */
	public static function cleanup_batch() {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::guard().
		$scanner_id = isset( $_POST['scanner'] ) ? sanitize_key( wp_unslash( $_POST['scanner'] ) ) : '';

		if ( '' === $scanner_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing scanner id.', 'codecaste-database-cleaner-optimizer' ) ), 400 );
		}

		// Explicit confirmation flag required for every destructive call, in addition to the capability + nonce guard.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::guard().
		$confirmed = isset( $_POST['confirm'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['confirm'] ) );
		if ( ! $confirmed ) {
			wp_send_json_error( array( 'message' => __( 'Cleanup was not confirmed.', 'codecaste-database-cleaner-optimizer' ) ), 400 );
		}

		$args = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::guard().
		if ( isset( $_POST['limit'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::guard().
			$args['limit'] = absint( wp_unslash( $_POST['limit'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::guard().
		if ( isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ) {
			$args['ids'] = array_map( 'absint', wp_unslash( $_POST['ids'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		}

		$result = CCDCO_Cleanup_Engine::run_batch( $scanner_id, $args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		// When cleanup is complete, return updated aggregate stats so the
		// frontend can refresh stat cards automatically without a rescan.
		if ( ! empty( $result['done'] ) ) {
			$stored = get_option( 'ccdco_last_scan_results', array() );
			if ( is_array( $stored ) ) {
				$cleanable_count = 0;
				$cleanable_size  = 0;
				$db_size         = 0;

				foreach ( $stored as $sid => $res ) {
					if ( ! is_array( $res ) ) {
						continue;
					}
					if ( 'table_size' === $sid && isset( $res['size'] ) ) {
						$db_size = (int) $res['size'];
					}
					if ( ! empty( $res['can_cleanup'] ) && ! empty( $res['count'] ) ) {
						++$cleanable_count;
						$cleanable_size += (int) ( $res['size'] ?? 0 );
					}
				}

				$result['updated_stats'] = array(
					'db_size'         => $db_size,
					'cleanable_count' => $cleanable_count,
					'cleanable_size'  => $cleanable_size,
				);
			}
		}

		wp_send_json_success( $result );
	}

	/** Run the focused unused-table scan. */
	public static function unused_tables_scan() {
		self::guard();
		$start = microtime( true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::guard().
		$force = isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
		$rows  = array_values( array_filter( CCDCO_Unused_Tables::scan( $force ), function ( $row ) { return is_array( $row ) && 'possible_orphan' === ( $row['status'] ?? '' ); } ) );
		update_option( 'ccdco_unused_tables_last_scan', time(), false );
		update_option( 'ccdco_unused_tables_last_results', $rows, false );
		wp_send_json_success( array( 'rows' => $rows, 'duration' => round( microtime( true ) - $start, 2 ) ) );
	}

	/** Delete one verified orphan candidate. */
	public static function delete_unused_table() {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::guard().
		$table = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in self::guard().
		$confirmed = isset( $_POST['confirm'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['confirm'] ) );
		if ( ! $confirmed ) {
			wp_send_json_error( array( 'message' => __( 'Deletion was not confirmed.', 'codecaste-database-cleaner-optimizer' ) ), 400 );
		}

		$result = CCDCO_Unused_Tables::delete_table( $table );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		// IMPORTANT: do not re-run the heuristic plugin/theme source scan here to build the
		// post-delete list. The table just deleted was already independently re-verified as
		// reference-free immediately before DROP TABLE (see the live, single-table check in
		// CCDCO_Unused_Tables::delete_table()), so we know for certain it belonged in the list
		// the user was looking at. Deleting one table cannot change whether any *other* table
		// has a source reference, so the correct, guaranteed-consistent post-delete list is
		// simply "the list the user was already looking at, minus that one row" — not a fresh
		// scan. A fresh scan is a heuristic filesystem walk and is not guaranteed to produce
		// byte-for-byte identical results between two independent runs on every site (order of
		// directory iteration, the per-component file cap, etc.), which is what was previously
		// making the count jump around right after a delete instead of decreasing by exactly
		// one. Full re-verification of every table is still available on demand via "Force
		// full rescan".
		$existing = get_option( 'ccdco_unused_tables_last_results', array() );
		$existing = is_array( $existing ) ? $existing : array();

		$found_deleted_row = false;
		$rows              = array();
		foreach ( $existing as $row ) {
			if ( ! is_array( $row ) || 'possible_orphan' !== ( $row['status'] ?? '' ) ) {
				continue;
			}
			$row_name = (string) ( $row['table_name'] ?? ( $row['name'] ?? '' ) );
			if ( $row_name === $table ) {
				$found_deleted_row = true;
				continue;
			}
			$rows[] = $row;
		}

		// Defensive fallback only: if the deleted table wasn't in the stored list (e.g. it was
		// empty, such as right after activation with no scan run yet), fall back to a cached
		// read rather than guessing at what changed.
		if ( ! $found_deleted_row ) {
			$rows = array_values( array_filter( CCDCO_Unused_Tables::scan( false ), function ( $row ) { return is_array( $row ) && 'possible_orphan' === ( $row['status'] ?? '' ); } ) );
		}

		update_option( 'ccdco_unused_tables_last_scan', time(), false );
		update_option( 'ccdco_unused_tables_last_results', $rows, false );
		wp_send_json_success( array_merge( (array) $result, array( 'rows' => $rows ) ) );
	}

	/**
	 * Clear all stored scan history.
	 *
	 * @return void
	 */
	public static function clear_history() {
		self::guard();

		CCDCO_Scan_History::clear_all();

		wp_send_json_success( array( 'message' => __( 'Scan history cleared.', 'codecaste-database-cleaner-optimizer' ) ) );
	}
}

CCDCO_Ajax::init();
