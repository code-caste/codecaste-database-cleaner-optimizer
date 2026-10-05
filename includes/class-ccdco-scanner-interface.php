<?php
/**
 * Scanner interface.
 *
 * Every scanner must implement this contract so the engine can treat
 * them interchangeably and new scanners can be added without changes
 * to the core.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CCDCO_Scanner_Interface
 */
interface CCDCO_Scanner_Interface {

	/**
	 * Unique machine name for this scanner, e.g. 'orphaned_postmeta'.
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human readable label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Short description of what this scanner looks for.
	 *
	 * @return string
	 */
	public function get_description();

	/**
	 * Whether this scanner's dependencies (e.g. a required table) are met
	 * on the current install.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Whether this scanner supports a cleanup action.
	 *
	 * @return bool
	 */
	public function supports_cleanup();

	/**
	 * Run the scan and return a normalized result array. Must never
	 * modify data.
	 *
	 * @return array {
	 *     @type string $type        Scanner id.
	 *     @type string $severity    One of 'info', 'notice', 'warning'.
	 *     @type string $title       Human readable title.
	 *     @type int    $count       Number of affected records.
	 *     @type int    $size        Estimated size in bytes (0 if unknown).
	 *     @type bool   $can_cleanup Whether cleanup is available.
	 *     @type array  $details     Extra scanner-specific detail rows.
	 * }
	 */
	public function scan();

	/**
	 * Perform a single batch of cleanup for this scanner.
	 *
	 * @param array $args {
	 *     @type int   $offset Batch offset.
	 *     @type int   $limit  Batch size.
	 *     @type array $ids    Optional explicit list of record ids to clean.
	 * }
	 * @return array {
	 *     @type int  $processed Number of records processed in this batch.
	 *     @type int  $remaining Estimated remaining records.
	 *     @type bool $done      Whether the cleanup is complete.
	 * }
	 */
	public function cleanup_batch( $args );
}
