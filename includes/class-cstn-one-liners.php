<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://centerstone.org
 * @since      1.0.0
 *
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/includes
 * @author     James Wilson <james.wilson@centerstone.org>
 */
class Cstn_One_Liners {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Cstn_One_Liners_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $cstnOneLiners    The string used to uniquely identify this plugin.
	 */
	protected $cstnOneLiners;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'CSTN_ONE_LINERS_VERSION' ) ) {
			$this->version = CSTN_ONE_LINERS_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->cstnOneLiners = 'cstn-one-liners';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Cstn_One_Liners_Loader. Orchestrates the hooks of the plugin.
	 * - Cstn_One_Liners_i18n. Defines internationalization functionality.
	 * - Cstn_One_Liners_Admin. Defines all hooks for the admin area.
	 * - Cstn_One_Liners_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-cstn-one-liners-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-cstn-one-liners-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-cstn-one-liners-admin.php';

		/**
		 * The class responsible for defining all AI Assistant functions.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-cstn-one-liners-openai.php';

		/**
		 * The class responsible for defining all vector create and storage functions.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-cstn-one-liners-vectors.php';


		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'public/class-cstn-one-liners-public.php';

		$this->loader = new Cstn_One_Liners_Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Cstn_One_Liners_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Cstn_One_Liners_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function define_admin_hooks() {

	    $plugin_admin = new Cstn_One_Liners_Admin( $this->get_cstnOneLiners(), $this->get_version() );
	    $plugin_openai = new Cstn_One_Liners_Openai();
	    $plugin_vectors = new Cstn_One_Liners_Vectors();

	    // Enqueue styles and scripts for the admin area.
	    $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
	    $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

	    // Add the plugin settings page and register the settings.
	    $this->loader->add_action( 'admin_menu', $plugin_admin, 'add_plugin_settings_page' );
	    $this->loader->add_action( 'admin_init', $plugin_admin, 'register_plugin_settings' );

	    // Register the AJAX handler for testing the API and Assistant ID.
	    $this->loader->add_action( 'wp_ajax_cstn_test_api_and_assistant', $plugin_admin, 'cstn_test_api_and_assistant' );

	    // Register the AJAX handler for retrieving Gravity Forms entries.
	    $this->loader->add_action( 'wp_ajax_cstn_retrieve_entries', $plugin_admin, 'cstn_retrieve_entries' );
	    $this->loader->add_action( 'wp_ajax_cstn_process_entries', $plugin_openai, 'cstn_process_all_entries' );

	    // Optional: Register any other AJAX handlers here as needed.
	}


	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Cstn_One_Liners_Public( $this->get_cstnOneLiners(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_cstnOneLiners() {
		return $this->cstnOneLiners;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Cstn_One_Liners_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
