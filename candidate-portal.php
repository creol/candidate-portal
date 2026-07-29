<?php
/**
 * Plugin Name:       Candidate Portal
 * Description:       Simple candidate self-service portal: admin creates elections, alphabets, and candidate accounts; candidates edit their own public profile; everything versioned to GitHub.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Built with Claude
 * License:           GPL-2.0-or-later
 * Text Domain:       candidate-portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CP_VERSION', '1.1.0' );
define( 'CP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once CP_PLUGIN_DIR . 'includes/class-cp-setup.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-alphabets.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-github.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-admin.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-frontend.php';
require_once CP_PLUGIN_DIR . 'includes/class-cp-updater.php';

register_activation_hook( __FILE__, array( 'CP_Setup', 'activate' ) );

add_action( 'plugins_loaded', function () {
	CP_Setup::init();
	CP_Admin::init();
	CP_Frontend::init();
	CP_GitHub::init();
	CP_Updater::init();
} );
