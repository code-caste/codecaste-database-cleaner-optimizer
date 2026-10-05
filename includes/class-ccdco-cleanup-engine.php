<?php
/**
 * Cleanup engine.
 *
 * Thin dispatcher that routes a validated cleanup request to the
 * relevant scanner's cleanup_batch() implementation. It never runs
 * during a scan and every call here already passed capability and
 * nonce checks in CCDCO_Ajax.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Cleanup_Engine
 */
class CCDCO_Cleanup_Engine {

	/**
	 * Run a single cleanup batch for a scanner.
	 *
	 * @param string $scanner_id Scanner id.
	 * @param array  $args       Batch args (limit, ids).
	 * @return array|WP_Error
	 */
	public static function run_batch( $scanner_id, $args = array() ) {
		$scanner = CCDCO_Scanner_Engine::get_scanner( $scanner_id );

		if ( ! $scanner ) {
			return new WP_Error( 'ccdco_invalid_scanner', __( 'Unknown scanner.', 'codecaste-database-cleaner-optimizer' ) );
		}

		if ( ! in_array( $scanner_id, CCDCO_Settings::get_enabled_scanners(), true ) ) {
			return new WP_Error( 'ccdco_scanner_disabled', __( 'This scanner is disabled in settings.', 'codecaste-database-cleaner-optimizer' ) );
		}

		if ( ! $scanner->supports_cleanup() ) {
			return new WP_Error( 'ccdco_no_cleanup', __( 'This scanner does not support cleanup.', 'codecaste-database-cleaner-optimizer' ) );
		}

		if ( ! $scanner->is_available() ) {
			return new WP_Error( 'ccdco_not_available', __( 'This scanner is not available on this site.', 'codecaste-database-cleaner-optimizer' ) );
		}

		/**
		 * Fires immediately before a cleanup batch runs.
		 *
		 * @param string $scanner_id Scanner id.
		 * @param array  $args       Batch args.
		 */
		do_action( 'ccdco_before_cleanup_batch', $scanner_id, $args );

		$result = $scanner->cleanup_batch( $args );

		if ( ! is_wp_error( $result ) && ! empty( $result['done'] ) ) {
			// Refresh this scanner's live result so completed cleanup items disappear
			// from the Cleanup screen instead of remaining as stale findings.
			$fresh_result = $scanner->scan();
			$stored       = get_option( 'ccdco_last_scan_results', array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			$stored[ $scanner_id ] = $fresh_result;
			update_option( 'ccdco_last_scan_results', $stored, false );

			$fresh_health = CCDCO_Health_Score::calculate( $stored );
			update_option( 'ccdco_last_scan_health', $fresh_health, false );

			$result['refreshed_result'] = $fresh_result;
		}

		/**
		 * Fires immediately after a cleanup batch runs.
		 *
		 * @param string $scanner_id Scanner id.
		 * @param array  $result     Batch result.
		 */
		do_action( 'ccdco_after_cleanup_batch', $scanner_id, $result );

		return $result;
	}
}
