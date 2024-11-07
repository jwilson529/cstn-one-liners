<?php
/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://centerstone.org
 * @since      1.0.0
 *
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/includes
 * @author     James Wilson <james.wilson@centerstone.org>
 */
class Cstn_One_Liners_I18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'cstn-one-liners',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);
	}
}
