<?php
/**
 * Main plugin singleton.
 *
 * @package CodeCaste_Database_Cleaner_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CCDCO_Plugin
 */
final class CCDCO_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var CCDCO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance, creating it if necessary.
	 *
	 * @return CCDCO_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Boots the scanner registry and adds the settings link.
	 */
	private function __construct() {
		CCDCO_Scanner_Engine::boot();

		add_filter( 'plugin_action_links_' . CCDCO_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
	}

	/**
	 * Add a "Settings" link on the Plugins list screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=codecaste-database-cleaner-optimizer' ) ),
			esc_html__( 'Open Optimizer', 'codecaste-database-cleaner-optimizer' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Prevent cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing of the singleton.
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cannot unserialize a singleton.', 'codecaste-database-cleaner-optimizer' ), '1.0.0' );
	}
}
