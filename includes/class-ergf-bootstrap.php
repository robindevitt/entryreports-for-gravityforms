<?php
/**
 * ERGF Bootstrap.
 *
 * @package entryreports-for-gravityforms
 */

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Class setup.
 */
class ERGF_Bootstrap {

	/**
	 * Loads the add-on once Gravity Forms itself has loaded. Bails out silently if the
	 * feed add-on framework isn't available, e.g. an older Gravity Forms version.
	 */
	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		GFForms::include_feed_addon_framework();

		require_once ERGF_PLUGIN_DIR . 'includes/class-ergf-report-generator.php';
		require_once ERGF_PLUGIN_DIR . 'includes/class-ergf-addon.php';

		GFAddOn::register( 'ERGF_AddOn' );
	}
}
