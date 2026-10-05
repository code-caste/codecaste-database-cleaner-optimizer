<?php
/**
 * Plugin Name:       CodeCaste Database Cleaner & Optimizer
 * Plugin URI:        https://github.com/code-caste/codecaste-database-cleaner-optimizer
 * Description:       Scan WordPress database health, review optimization opportunities, and safely optimize supported records in controlled batches.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            CodeCaste
 * Author URI:        https://www.codecaste.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       codecaste-database-cleaner-optimizer
 * Domain Path:       /languages
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'CCDCO_VERSION', '1.0.0' );
define( 'CCDCO_PLUGIN_FILE', __FILE__ );
define( 'CCDCO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CCDCO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CCDCO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CCDCO_MIN_PHP', '7.4' );
define( 'CCDCO_MIN_WP', '6.2' );

/**
 * Check environment requirements before doing anything else.
 *
 * @return bool True if the environment is compatible.
 */
function ccdco_environment_ok() {
	global $wp_version;

	if ( version_compare( PHP_VERSION, CCDCO_MIN_PHP, '<' ) ) {
		return false;
	}

	if ( isset( $wp_version ) && version_compare( $wp_version, CCDCO_MIN_WP, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Show an admin notice when the environment requirements are not met.
 */
function ccdco_environment_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: required PHP version, 2: required WordPress version. */
				esc_html__( 'CodeCaste Database Cleaner & Optimizer requires PHP %1$s+ and WordPress %2$s+. The plugin has been deactivated.', 'codecaste-database-cleaner-optimizer' ),
				esc_html( CCDCO_MIN_PHP ),
				esc_html( CCDCO_MIN_WP )
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Load the plugin text domain for translations.
 */
function ccdco_load_textdomain() {
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	load_plugin_textdomain( 'codecaste-database-cleaner-optimizer', false, dirname( CCDCO_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'init', 'ccdco_load_textdomain' );

/**
 * Bootstrap the plugin once we know the environment is compatible.
 */
function ccdco_bootstrap() {
	if ( ! ccdco_environment_ok() ) {
		add_action( 'admin_notices', 'ccdco_environment_notice' );
		return;
	}

	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-db-helper.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-scanner-interface.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/abstract-class-ccdco-scanner.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-scanner-engine.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-health-score.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-scan-history.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-settings.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-cleanup-engine.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-ajax.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-unused-tables.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-admin.php';

	require_once CCDCO_PLUGIN_DIR . 'includes/scanners/class-ccdco-scanner-table-size.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/scanners/class-ccdco-scanner-autoloaded-options.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/scanners/class-ccdco-scanner-transients.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/scanners/class-ccdco-scanner-post-revisions.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/scanners/class-ccdco-scanner-auto-drafts.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/scanners/class-ccdco-scanner-orphaned-postmeta.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/scanners/class-ccdco-scanner-orphaned-termmeta.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/scanners/class-ccdco-scanner-orphaned-commentmeta.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/scanners/class-ccdco-scanner-action-scheduler.php';

	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-plugin.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-activator.php';
	CCDCO_Plugin::instance();
	add_action( 'admin_init', array( 'CCDCO_Activator', 'maybe_upgrade' ), 1 );
	CCDCO_Scan_History::schedule_purge_event();
}
add_action( 'plugins_loaded', 'ccdco_bootstrap', 20 );

/**
 * Activation callback. Creates default options and the scan history table.
 */
function ccdco_activate_plugin() {
	if ( ! ccdco_environment_ok() ) {
		deactivate_plugins( CCDCO_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'CodeCaste Database Cleaner & Optimizer could not be activated because your server does not meet the minimum PHP or WordPress version requirements.', 'codecaste-database-cleaner-optimizer' ),
			esc_html__( 'Plugin Activation Error', 'codecaste-database-cleaner-optimizer' ),
			array( 'back_link' => true )
		);
	}

	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-activator.php';
	CCDCO_Activator::activate();
}
register_activation_hook( __FILE__, 'ccdco_activate_plugin' );

/**
 * Deactivation callback. Leaves data intact; only clears transient locks/schedules.
 */
function ccdco_deactivate_plugin() {
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-scan-history.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-settings.php';
	require_once CCDCO_PLUGIN_DIR . 'includes/class-ccdco-deactivator.php';
	CCDCO_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'ccdco_deactivate_plugin' );
