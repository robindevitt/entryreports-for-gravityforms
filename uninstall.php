<?php
/**
 * Uninstall handler.
 *
 * Fires when the plugin is deleted from the Plugins screen (or via WP-CLI). This is a
 * separate path from ERGF_AddOn::uninstall(), which only runs through Gravity Forms' own
 * "Uninstall" link in Form Settings > Entry Reports - deleting the plugin directly never
 * loads that class, so this file has to clear the same data on its own.
 *
 * The addon slug and cron hook name are duplicated from ERGF_AddOn as plain strings since
 * the plugin's classes aren't loaded at this point - keep them in sync with
 * includes/class-ergf-addon.php if either ever changes.
 *
 * @package entryreports-for-gravityforms
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die();
}

global $wpdb;

wp_clear_scheduled_hook( 'ergf_process_reports' );

// Report feed configs (recipients, frequency, last_sent, etc.) live in Gravity Forms'
// shared feed table, keyed by addon slug - mirrors GFFeedAddOn::uninstall().
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->prepare( "DELETE FROM {$wpdb->prefix}gf_addon_feed WHERE addon_slug = %s", 'entryreports' )
);

// Options Gravity Forms' Add-On framework maintains for this addon.
delete_option( 'gravityformsaddon_entryreports_settings' );
delete_option( 'gravityformsaddon_entryreports_app_settings' );
delete_option( 'gravityformsaddon_entryreports_version' );

// Per-user "Send Test Now" result transients. These expire after 60 seconds on their own,
// but are cleared here too in case uninstall happens to run inside that window.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_ergf_test_notice_' ) . '%'
	)
);
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_ergf_test_notice_' ) . '%'
	)
);
