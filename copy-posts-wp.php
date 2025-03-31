<?php
	/**
	 * Plugin Name: Copy Posts WP
	 * Plugin URI:  https://mattgraham.ca/copy-posts
	 * Description: Sync posts from an external WordPress site via WP-JSON API.
	 * Version:     1.0.0
	 * Author:      Matt Graham
	 * Author URI:  https://mattgraham.ca/
	 * License:     GPL2
	 */

	if (!defined('ABSPATH')) {
		exit; // Exit if accessed directly.
	}

	// Define plugin constants
	define('MGCPWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
	define('MGCPWP_PLUGIN_URL', plugin_dir_url(__FILE__));

	// Include required files
	require_once MGCPWP_PLUGIN_DIR . 'includes/class-post-sync.php';
	require_once MGCPWP_PLUGIN_DIR . 'includes/class-settings.php';

	// Initialize the plugin
	function mgcpwp_init() {
		MGCPWP_Settings::init();
		MGCPWP_Post_Sync::init();
	}
	add_action('plugins_loaded', 'mgcpwp_init');

	// Register activation hook, add the scheduled event
	register_activation_hook(__FILE__, function() {
		if (!wp_next_scheduled('mgcpwp_scheduled_sync')) {
			wp_schedule_event(time(), 'weekly', 'mgcpwp_scheduled_sync');
		}
	});

	// Register deactivation hook, clear the scheduled event
	register_deactivation_hook(__FILE__, function() {
		wp_clear_scheduled_hook('mgcpwp_scheduled_sync');
	});
