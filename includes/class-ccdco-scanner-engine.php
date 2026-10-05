<?php
/**
 * Scanner engine / registry.
 *
 * New scanners register themselves here and the engine can enumerate,
 * filter, and run them without knowing their implementation details.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Scanner_Engine
 */
class CCDCO_Scanner_Engine {

	/**
	 * Registered scanner instances, keyed by id.
	 *
	 * @var CCDCO_Scanner_Interface[]
	 */
	private static $scanners = array();

	/**
	 * Whether default scanners have been registered.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register a scanner instance.
	 *
	 * @param CCDCO_Scanner_Interface $scanner Scanner instance.
	 */
	public static function register( CCDCO_Scanner_Interface $scanner ) {
		self::$scanners[ $scanner->get_id() ] = $scanner;
	}

	/**
	 * Register all bundled scanners. Runs once.
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$bundled = array(
			new CCDCO_Scanner_Table_Size(),
			new CCDCO_Scanner_Autoloaded_Options(),
			new CCDCO_Scanner_Transients(),
			new CCDCO_Scanner_Post_Revisions(),
			new CCDCO_Scanner_Auto_Drafts(),
			new CCDCO_Scanner_Orphaned_Postmeta(),
			new CCDCO_Scanner_Orphaned_Termmeta(),
			new CCDCO_Scanner_Orphaned_Commentmeta(),
			new CCDCO_Scanner_Action_Scheduler(),
		);

		foreach ( $bundled as $scanner ) {
			self::register( $scanner );
		}

		/**
		 * Fires after bundled scanners are registered so third parties can
		 * register additional scanners without touching core files.
		 *
		 * @param string Class name of the engine, for calling ::register().
		 */
		do_action( 'ccdco_register_scanners', __CLASS__ );
	}

	/**
	 * Get all registered scanners.
	 *
	 * @param bool $only_enabled Only return scanners enabled in settings.
	 * @return CCDCO_Scanner_Interface[]
	 */
	public static function get_scanners( $only_enabled = true ) {
		self::boot();

		$scanners = self::$scanners;

		if ( $only_enabled ) {
			$enabled  = CCDCO_Settings::get_enabled_scanners();
			$scanners = array_filter(
				$scanners,
				function ( $scanner ) use ( $enabled ) {
					return in_array( $scanner->get_id(), $enabled, true );
				}
			);
		}

		return array_filter(
			$scanners,
			function ( $scanner ) {
				return $scanner->is_available();
			}
		);
	}

	/**
	 * Get a single scanner by id.
	 *
	 * @param string $id Scanner id.
	 * @return CCDCO_Scanner_Interface|null
	 */
	public static function get_scanner( $id ) {
		self::boot();
		$id = sanitize_key( $id );
		return isset( self::$scanners[ $id ] ) ? self::$scanners[ $id ] : null;
	}

	/**
	 * Run every enabled scanner and return the combined results.
	 *
	 * @return array[]
	 */
	public static function run_all() {
		$results = array();

		foreach ( self::get_scanners( true ) as $scanner ) {
			$result             = $scanner->scan();
			$results[ $scanner->get_id() ] = $result;
		}

		/**
		 * Filter the full set of scan results before they are stored/displayed.
		 *
		 * @param array[] $results Scan results keyed by scanner id.
		 */
		return apply_filters( 'ccdco_scan_results', $results );
	}
}
