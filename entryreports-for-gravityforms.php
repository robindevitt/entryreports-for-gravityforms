<?php
/**
 * Plugin Name:        Entry Reports for Gravity Forms
 * Plugin URI:         https://github.com/robindevitt/entryreports-for-gravityforms
 * Description:        Emails scheduled (weekly or monthly) summary reports of Gravity Forms entries to chosen recipients.
 * Version:            1.0.0
 * Requires at least:  6.0
 * Requires PHP:       7.4
 * Author:             Robin Devitt
 * License:            GPL v3 or later
 * License URI:        https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:        entryreports-for-gravityforms
 *
 * @package entryreports-for-gravityforms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'ERGF_VERSION', '1.0.0' );
define( 'ERGF_PLUGIN_FILE', __FILE__ );
define( 'ERGF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ERGF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ERGF_PLUGIN_DIR . 'includes/class-ergf-bootstrap.php';

add_action( 'gform_loaded', array( 'ERGF_Bootstrap', 'load' ), 5 );

/**
 * Convenience accessor for the add-on singleton, mirroring the pattern used by other
 * Gravity Forms feed add-ons.
 *
 * @return ERGF_AddOn
 */
function ergf_addon() {
	return ERGF_AddOn::get_instance();
}

/**
 * Let the admin know Gravity Forms is required if it isn't active.
 */
function ergf_missing_gravityforms_notice() {
	if ( class_exists( 'GFForms' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Entry Reports for Gravity Forms requires Gravity Forms to be installed and active.', 'entryreports-for-gravityforms' ); ?></p>
	</div>
	<?php
}
add_action( 'admin_notices', 'ergf_missing_gravityforms_notice' );
