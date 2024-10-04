<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://centerstone.org
 * @since      1.0.0
 *
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/public
 * @author     James Wilson <james.wilson@centerstone.org>
 */
class Cstn_One_Liners_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $cstnOneLiners    The ID of this plugin.
	 */
	private $cstnOneLiners;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $cstnOneLiners       The name of the plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $cstnOneLiners, $version ) {

		$this->cstnOneLiners = $cstnOneLiners;
		$this->version       = $version;
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Cstn_One_Liners_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Cstn_One_Liners_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->cstnOneLiners, plugin_dir_url( __FILE__ ) . 'css/cstn-one-liners-public.css', array(), $this->version, 'all' );
	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Cstn_One_Liners_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Cstn_One_Liners_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->cstnOneLiners, plugin_dir_url( __FILE__ ) . 'js/cstn-one-liners-public.js', array( 'jquery' ), $this->version, false );
	}
}
