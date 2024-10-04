<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://centerstone.org
 * @since             1.0.0
 * @package           Cstn_One_Liners
 *
 * @wordpress-plugin
 * Plugin Name:       O&D Navigator One-liners
 * Plugin URI:        https://centerstone.org
 * Description:       Takes gravity forms entries and summarizes them with AI
 * Version:           1.0.0
 * Author:            James Wilson
 * Author URI:        https://centerstone.org/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       cstn-one-liners
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'CSTN_ONE_LINERS_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-cstn-one-liners-activator.php
 */
function activate_cstnOneLiners() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-cstn-one-liners-activator.php';
	Cstn_One_Liners_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-cstn-one-liners-deactivator.php
 */
function deactivate_cstnOneLiners() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-cstn-one-liners-deactivator.php';
	Cstn_One_Liners_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_cstnOneLiners' );
register_deactivation_hook( __FILE__, 'deactivate_cstnOneLiners' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-cstn-one-liners.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_cstnOneLiners() {

	$plugin = new Cstn_One_Liners();
	$plugin->run();
}
run_cstnOneLiners();