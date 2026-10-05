<?php
/**
 * Abstract base scanner.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Abstract_Scanner
 */
abstract class CCDCO_Abstract_Scanner implements CCDCO_Scanner_Interface {

	/**
	 * Default batch size used by cleanup routines, filterable per scanner.
	 *
	 * @return int
	 */
	protected function get_batch_size() {
		$settings   = CCDCO_Settings::get_settings();
		$batch_size = isset( $settings['batch_size'] ) ? (int) $settings['batch_size'] : 500;

		/**
		 * Filter the batch size used for a given scanner's cleanup routine.
		 *
		 * @param int    $batch_size Batch size.
		 * @param string $scanner_id Scanner id.
		 */
		return (int) apply_filters( 'ccdco_scanner_batch_size', $batch_size, $this->get_id() );
	}

	/**
	 * Build the normalized result array used by every scanner.
	 *
	 * @param string $severity    Severity level.
	 * @param string $title       Title.
	 * @param int    $count       Affected record count.
	 * @param int    $size        Estimated size in bytes.
	 * @param array  $details     Extra details.
	 * @return array
	 */
	protected function build_result( $severity, $title, $count, $size, $details = array() ) {
		return array(
			'type'        => $this->get_id(),
			'severity'    => $severity,
			'title'       => $title,
			'count'       => (int) $count,
			'size'        => (int) $size,
			'can_cleanup' => $this->supports_cleanup() && $count > 0,
			'details'     => $details,
		);
	}

	/**
	 * Default: cleanup is supported unless a scanner overrides this.
	 *
	 * @return bool
	 */
	public function supports_cleanup() {
		return true;
	}

	/**
	 * Maximum number of individual records shown as a "review" preview
	 * before cleanup. Keeps the payload small while still giving the
	 * admin real values to look at instead of only a count/size.
	 *
	 * @return int
	 */
	protected function get_preview_limit() {
		/**
		 * Filter how many individual records are shown in the pre-cleanup
		 * review preview for a given scanner.
		 *
		 * @param int    $limit      Preview row limit.
		 * @param string $scanner_id Scanner id.
		 */
		return (int) apply_filters( 'ccdco_scanner_preview_limit', 20, $this->get_id() );
	}

	/**
	 * Shorten and single-line a raw value for safe display in a preview list.
	 *
	 * @param string $value  Raw value.
	 * @param int    $length Max length before truncating.
	 * @return string
	 */
	protected function truncate_preview_value( $value, $length = 60 ) {
		$value = (string) $value;
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $value ) > $length ) {
				$value = mb_substr( $value, 0, $length ) . '…';
			}
		} elseif ( strlen( $value ) > $length ) {
				$value = substr( $value, 0, $length ) . '…';
		}

		return $value;
	}

	/**
	 * Default availability: always available. Override for optional deps.
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}
}
