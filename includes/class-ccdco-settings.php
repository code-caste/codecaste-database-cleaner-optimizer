<?php
/**
 * Plugin settings.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Settings
 */
class CCDCO_Settings {

	/**
	 * Option name used to store all settings as a single array.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'ccdco_settings';

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'enable_scan_history'             => 1,
			'batch_size'                       => 500,
			'revisions_keep_latest'            => 5,
			'action_scheduler_retention_days'  => 30,
			'enabled_scanners'                 => array(
				'table_size',
				'autoloaded_options',
				'expired_transients',
				'post_revisions',
				'auto_drafts',
				'orphaned_postmeta',
				'orphaned_termmeta',
				'orphaned_commentmeta',
				'action_scheduler',
			),
		);
	}

	/**
	 * Get current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::get_defaults() );
	}

	/**
	 * Get the list of currently enabled scanner ids.
	 *
	 * @return string[]
	 */
	public static function get_enabled_scanners() {
		$settings = self::get_settings();
		$enabled  = isset( $settings['enabled_scanners'] ) ? $settings['enabled_scanners'] : array();
		return array_map( 'sanitize_key', (array) $enabled );
	}

	/**
	 * Sanitize a raw settings array coming from $_POST.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		$defaults  = self::get_defaults();
		$sanitized = array();

		$sanitized['enable_scan_history']            = empty( $input['enable_scan_history'] ) ? 0 : 1;

		$sanitized['batch_size'] = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $defaults['batch_size'];
		$sanitized['batch_size'] = min( 5000, max( 50, $sanitized['batch_size'] ) );

		$sanitized['revisions_keep_latest'] = isset( $input['revisions_keep_latest'] ) ? absint( $input['revisions_keep_latest'] ) : $defaults['revisions_keep_latest'];
		$sanitized['revisions_keep_latest'] = min( 50, max( 0, $sanitized['revisions_keep_latest'] ) );

		$sanitized['action_scheduler_retention_days'] = isset( $input['action_scheduler_retention_days'] ) ? absint( $input['action_scheduler_retention_days'] ) : $defaults['action_scheduler_retention_days'];
		$sanitized['action_scheduler_retention_days'] = min( 365, max( 1, $sanitized['action_scheduler_retention_days'] ) );

		$allowed_scanner_ids = array(
			'table_size',
			'autoloaded_options',
			'expired_transients',
			'post_revisions',
			'auto_drafts',
			'orphaned_postmeta',
			'orphaned_termmeta',
			'orphaned_commentmeta',
			'action_scheduler',
		);

		$submitted = isset( $input['enabled_scanners'] ) && is_array( $input['enabled_scanners'] ) ? $input['enabled_scanners'] : array();
		$submitted = array_map( 'sanitize_key', $submitted );

		$sanitized['enabled_scanners'] = array_values( array_intersect( $allowed_scanner_ids, $submitted ) );

		// Database & Table Size has no on/off toggle in the UI (the dashboard's
		// Database Size stat depends on it), so always keep it enabled regardless
		// of what was submitted in the form.
		if ( ! in_array( 'table_size', $sanitized['enabled_scanners'], true ) ) {
			array_unshift( $sanitized['enabled_scanners'], 'table_size' );
		}

		return $sanitized;
	}

	/**
	 * Update settings from sanitized input.
	 *
	 * @param array $input Raw input array (e.g. from $_POST).
	 * @return void
	 */
	public static function update( $input ) {
		update_option( self::OPTION_NAME, self::sanitize( $input ) );
	}
}
