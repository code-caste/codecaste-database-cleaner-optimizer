<?php
/**
 * Admin menu, assets, settings, and single-page dashboard registration.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Admin
 */
class CCDCO_Admin {

	/**
	 * Top-level menu slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'codecaste-database-cleaner-optimizer';

	/**
	 * Capability required to view any plugin screen.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Hook suffixes for our own screens, used to scope asset loading.
	 *
	 * @var array
	 */
	private $screen_hooks = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_settings_notice' ) );
	}

	/**
	 * Register the single Database Optimizer admin screen.
	 * No sub-pages — everything is rendered inline via tabs.
	 */
	public function register_menu() {
		$this->screen_hooks[] = add_menu_page(
			__( 'Database Optimizer', 'codecaste-database-cleaner-optimizer' ),
			__( 'DB Optimizer', 'codecaste-database-cleaner-optimizer' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-database',
			80
		);
	}

	/**
	 * Whether the current screen belongs to this plugin.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	private function is_our_screen( $hook ) {
		return in_array( $hook, $this->screen_hooks, true );
	}

	/**
	 * Enqueue admin CSS/JS only on our own screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! $this->is_our_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'ccdco-admin',
			CCDCO_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			CCDCO_VERSION
		);

		wp_enqueue_script(
			'ccdco-admin',
			CCDCO_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			CCDCO_VERSION,
			true
		);

		wp_localize_script(
			'ccdco-admin',
			'ccdcoAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( CCDCO_Ajax::NONCE_ACTION ),
				'initialTables' => array_values( array_filter( (array) get_option( 'ccdco_unused_tables_last_results', array() ), function ( $row ) { return is_array( $row ) && 'possible_orphan' === ( $row['status'] ?? '' ) && CCDCO_Unused_Tables::RULES_VERSION === (string) ( $row['orphan_rules_version'] ?? '' ); } ) ),
				'orphanRulesVersion' => CCDCO_Unused_Tables::RULES_VERSION,
				'historyEnabled' => ! empty( CCDCO_Settings::get_settings()['enable_scan_history'] ),
				'i18n'          => array(
					'scanning'         => __( 'Scanning database…', 'codecaste-database-cleaner-optimizer' ),
					'saving'           => __( 'Saving…', 'codecaste-database-cleaner-optimizer' ),
					'scanningShort'    => __( 'Scanning…', 'codecaste-database-cleaner-optimizer' ),
					'scanComplete'     => __( 'Scan Complete', 'codecaste-database-cleaner-optimizer' ),
					'scanSteps'        => array(
						__( 'Checking post revisions…', 'codecaste-database-cleaner-optimizer' ),
						__( 'Checking auto drafts…', 'codecaste-database-cleaner-optimizer' ),
						__( 'Checking expired transients…', 'codecaste-database-cleaner-optimizer' ),
						__( 'Looking for orphaned metadata…', 'codecaste-database-cleaner-optimizer' ),
						__( 'Checking autoloaded options…', 'codecaste-database-cleaner-optimizer' ),
						__( 'Checking Action Scheduler…', 'codecaste-database-cleaner-optimizer' ),
						__( 'Measuring table sizes…', 'codecaste-database-cleaner-optimizer' ),
						__( 'Calculating health score…', 'codecaste-database-cleaner-optimizer' ),
					),
					'scanDone'         => __( 'Scan Complete — Review The Cleanup Items Below.', 'codecaste-database-cleaner-optimizer' ),
					'error'            => __( 'Something went wrong. Please try again.', 'codecaste-database-cleaner-optimizer' ),
					'confirmDelete'    => __( 'Delete this database table permanently? This cannot be undone. Make sure you have a current database backup.', 'codecaste-database-cleaner-optimizer' ),
					'deleting'         => __( 'Deleting…', 'codecaste-database-cleaner-optimizer' ),
					'deleted'          => __( 'Table deleted successfully.', 'codecaste-database-cleaner-optimizer' ),
					'typeTableName'    => __( 'For safety, type the exact table name to confirm deletion:', 'codecaste-database-cleaner-optimizer' ),
					'colDate'          => __( 'Date', 'codecaste-database-cleaner-optimizer' ),
					'colDbSize'        => __( 'DB Size', 'codecaste-database-cleaner-optimizer' ),
					'colScore'         => __( 'Health Score', 'codecaste-database-cleaner-optimizer' ),
					'colIssues'        => __( 'Issues', 'codecaste-database-cleaner-optimizer' ),
					'colReclaimable'   => __( 'Reclaimable', 'codecaste-database-cleaner-optimizer' ),
					'colDuration'      => __( 'Duration', 'codecaste-database-cleaner-optimizer' ),
					'tableNameMismatch'=> __( 'The table name did not match. Nothing was deleted.', 'codecaste-database-cleaner-optimizer' ),
				),
			)
		);
	}

	/**
	 * Render a view file with the given variables in scope.
	 *
	 * @param string $view Bare view filename without extension, in admin/views/.
	 * @param array  $vars Variables to extract into scope.
	 */
	private function render_view( $view, $vars = array() ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'codecaste-database-cleaner-optimizer' ) );
		}

		$file = CCDCO_PLUGIN_DIR . 'admin/views/' . $view . '.php';

		if ( ! file_exists( $file ) ) {
			return;
		}

		if ( ! empty( $vars ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- controlled, plugin-internal view variables only.
			extract( $vars, EXTR_SKIP );
		}

		include $file;
	}

	/**
	 * Render the single-page tabbed dashboard.
	 * Collects all data needed by every tab and passes it to dashboard.php.
	 */
	public function render_dashboard() {
		$last_results = get_option( 'ccdco_last_scan_results', array() );
		$last_time    = get_option( 'ccdco_last_scan_time', 0 );
		$scanners     = CCDCO_Scanner_Engine::get_scanners( true );
		$all_scanners = CCDCO_Scanner_Engine::get_scanners( false );
		$settings     = CCDCO_Settings::get_settings();
		$history      = CCDCO_Scan_History::get_history( 50, 0 );
		$health       = get_option( 'ccdco_last_scan_health', array() );
		$tables       = array_values( array_filter( (array) get_option( 'ccdco_unused_tables_last_results', array() ), function ( $row ) { return is_array( $row ) && 'possible_orphan' === ( $row['status'] ?? '' ) && CCDCO_Unused_Tables::RULES_VERSION === (string) ( $row['orphan_rules_version'] ?? '' ); } ) );

		$this->render_view(
			'dashboard',
			array(
				'ccdco_last_results' => $last_results,
				'ccdco_last_time'    => $last_time,
				'ccdco_scanners'     => $scanners,
				'ccdco_all_scanners' => $all_scanners,
				'ccdco_settings'     => $settings,
				'ccdco_history'      => $history,
				'ccdco_tables'       => $tables,
				'ccdco_health'       => is_array( $health ) ? $health : array(),
			)
		);
	}

	/**
	 * Handle the settings form submission (standard POST + nonce, not AJAX).
	 * Settings are now submitted from the Settings tab within the dashboard.
	 */
	public function handle_settings_save() {
		if ( ! isset( $_POST['ccdco_settings_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! check_admin_referer( 'ccdco_save_settings', 'ccdco_settings_nonce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw input array is sanitized inside CCDCO_Settings::update().
		$raw = isset( $_POST['ccdco_settings'] ) && is_array( $_POST['ccdco_settings'] ) ? wp_unslash( $_POST['ccdco_settings'] ) : array();

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		CCDCO_Settings::update( $raw );

		set_transient( 'ccdco_settings_saved_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );

		// Redirect back to the single dashboard page (Settings tab will re-open via hash if needed).
		wp_safe_redirect( add_query_arg( 'page', self::MENU_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Show a "settings saved" admin notice once, on our screen.
	 */
	public function maybe_show_settings_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		$key = 'ccdco_settings_saved_' . get_current_user_id();

		if ( get_transient( $key ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-success is-dismissible"><p>' .
			     esc_html__( 'Settings saved.', 'codecaste-database-cleaner-optimizer' ) .
			     '</p></div>';
		}
	}
}

new CCDCO_Admin();
