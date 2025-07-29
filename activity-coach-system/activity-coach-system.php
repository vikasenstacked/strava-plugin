<?php
/*
Plugin Name: Activity Coach System
Description: A coaching platform for fitness activity tracking with Coach and Mentee dashboards.
Version: 1.0.0
Author: Your Name
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'ACS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'ACS_VERSION', '1.0.0' );

define( 'ACS_ASSETS_URL', ACS_PLUGIN_URL . 'assets/' );

define( 'ACS_INC', ACS_PLUGIN_DIR . 'includes/' );

// Autoload core classes
require_once ACS_INC . 'class-database.php';
require_once ACS_INC . 'class-user-roles.php';
require_once ACS_INC . 'class-api.php';
require_once ACS_INC . 'class-ajax.php';
require_once ACS_INC . 'class-dashboards.php';
require_once ACS_INC . 'class-settings.php';
require_once ACS_INC . 'functions.php';

// Activation/Deactivation hooks
register_activation_hook( __FILE__, [ 'ACS_Database', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'ACS_Database', 'deactivate' ] );

// Initialize plugin
add_action( 'plugins_loaded', function() {
    ACS_User_Roles::init();
    ACS_Database::init();
    ACS_Dashboards::init();
    ACS_Ajax::init();
    ACS_Settings::init();
    ACS_API::init();
});
