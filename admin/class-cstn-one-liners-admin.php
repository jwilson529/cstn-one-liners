<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://centerstone.org
 * @since      1.0.0
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/admin
 */

/**
 * Handles the admin-specific functionality of the plugin.
 *
 * This class includes methods for registering settings, adding the admin menu,
 * enqueuing scripts and styles, and testing the API key and Assistant ID.
 *
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/admin
 * @author     James Wilson <james.wilson@centerstone.org>
 */
class Cstn_One_Liners_Admin {

	/**
	 * Plugin ID.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $cstnOneLiners    The ID of this plugin.
	 */
	private $cstnOneLiners;

	/**
	 * Plugin version.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Constructor to initialize the class and set its properties.
	 *
	 * @since 1.0.0
	 * @param string $cstnOneLiners The name of this plugin.
	 * @param string $version       The version of this plugin.
	 */
	public function __construct( $cstnOneLiners, $version ) {
		$this->cstnOneLiners = $cstnOneLiners;
		$this->version       = $version;
	}

	/**
	 * Enqueue the stylesheets for the admin area.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_styles() {
		wp_enqueue_style( $this->cstnOneLiners, plugin_dir_url( __FILE__ ) . 'css/cstn-one-liners-admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Enqueue admin scripts and localize the nonce for AJAX requests.
	 */
	public function enqueue_scripts() {
	    wp_enqueue_script( $this->cstnOneLiners, plugin_dir_url( __FILE__ ) . 'js/cstn-one-liners-admin.js', array( 'jquery' ), $this->version, true );

	    // Localize script with the nonce value.
	    wp_localize_script(
	        $this->cstnOneLiners,
	        'cstn_one_liners_vars',
	        array(
	            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
	            'cstn_ajax_nonce' => wp_create_nonce( 'cstn_ajax_nonce' ),
	        )
	    );
	}

	/**
	 * Add a settings page to the admin menu.
	 *
	 * @since 1.0.0
	 */
	public function add_plugin_settings_page() {
		add_menu_page(
			__( 'Cstn One-Liners Settings', 'cstn-one-liners' ), // Page title.
			__( 'Cstn One-Liners', 'cstn-one-liners' ),          // Menu title.
			'manage_options',                                    // Capability.
			'cstn_one_liners_settings',                          // Menu slug.
			array( $this, 'create_settings_page' ),              // Callback function.
			'dashicons-admin-generic',                           // Icon URL.
			100                                                  // Position.
		);
	}

	/**
	 * Render the settings page for this plugin with tabs.
	 *
	 * @since 1.0.0
	 */
	public function create_settings_page() {
	    // Determine which tab is active based on the `tab` query parameter.
	    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'api_config';

	    ?>
	    <div class="wrap">
	        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	        <h2 class="nav-tab-wrapper">
	            <a href="?page=cstn_one_liners_settings&tab=api_config" class="nav-tab <?php echo $active_tab === 'api_config' ? 'nav-tab-active' : ''; ?>">
	                <?php esc_html_e( 'API Configuration', 'cstn-one-liners' ); ?>
	            </a>
	            <a href="?page=cstn_one_liners_settings&tab=gravity_forms" class="nav-tab <?php echo $active_tab === 'gravity_forms' ? 'nav-tab-active' : ''; ?>">
	                <?php esc_html_e( 'Gravity Forms Integration', 'cstn-one-liners' ); ?>
	            </a>
	        </h2>

	        <form method="post" action="options.php">
	            <?php
	            // Only output settings fields for the active tab.
	            if ( $active_tab === 'api_config' ) {
	                // Output settings fields for the API Configuration tab.
	                settings_fields( 'cstn_one_liners_settings' );
	                do_settings_sections( 'cstn_one_liners_settings' );
	            } elseif ( $active_tab === 'gravity_forms' ) {
	                // Output settings fields for the Gravity Forms Integration tab.
	                settings_fields( 'cstn_gravity_forms_settings' );
	                do_settings_sections( 'cstn_gravity_forms_settings' );

	                ?>
	                <hr>
	                <h2><?php esc_html_e( 'Retrieve and Process Entries', 'cstn-one-liners' ); ?></h2>
	                <button type="button" id="retrieve_entries" class="button button-primary">
	                    <?php esc_html_e( 'Retrieve Entries', 'cstn-one-liners' ); ?>
	                </button>
	                <div id="gf_entries_display"></div>
	                <button type="button" id="process_entries" class="button button-secondary" style="display:none;">
	                    <?php esc_html_e( 'Send to Assistant', 'cstn-one-liners' ); ?>
	                </button>
	                <div id="assistant_response"></div>
	                <?php
	            }
	            ?>
	            <?php submit_button(); ?>
	        </form>
	    </div>
	    <?php
	}



	/**
	 * Register plugin settings and add fields.
	 *
	 * @since 1.0.0
	 */
	public function register_plugin_settings() {
	    // Register new settings for API Configuration tab.
	    register_setting( 'cstn_one_liners_settings', 'cstn_one_liners_assistant_id' );
	    register_setting( 'cstn_one_liners_settings', 'cstn_one_liners_api_key' );
	    register_setting( 'cstn_one_liners_settings', 'cstn_one_liners_vector_store_id' );


	    // Add a new section for API configuration.
	    add_settings_section(
	        'cstn_one_liners_section',
	        __( 'API Configuration', 'cstn-one-liners' ),
	        array( $this, 'settings_section_callback' ),
	        'cstn_one_liners_settings'
	    );

	    // Add fields for the Assistant ID and API Key in API Configuration tab.
	    add_settings_field(
	        'cstn_one_liners_assistant_id',
	        __( 'Assistant ID', 'cstn-one-liners' ),
	        array( $this, 'assistant_id_callback' ),
	        'cstn_one_liners_settings',
	        'cstn_one_liners_section'
	    );
	    add_settings_field(
	        'cstn_one_liners_api_key',
	        __( 'API Key', 'cstn-one-liners' ),
	        array( $this, 'api_key_callback' ),
	        'cstn_one_liners_settings',
	        'cstn_one_liners_section'
	    );

	    // Output the form fields.
	    add_settings_field(
	        'cstn_one_liners_vector_store_id',     // Field ID
	        'Vector Store ID',                     // Field title
	        array( $this, 'vector_store_id_callback' ), // Callback function to render the field
	        'cstn_one_liners_settings',            // Page slug
	        'cstn_one_liners_section'              // Section ID
	    );

	    // Register new settings for Gravity Forms Integration tab.
	    register_setting( 'cstn_gravity_forms_settings', 'cstn_one_liners_form_id' );

	    // Add a new section for Gravity Forms configuration.
	    add_settings_section(
	        'cstn_gravity_forms_section',
	        __( 'Gravity Forms Integration', 'cstn-one-liners' ),
	        array( $this, 'gravity_forms_section_callback' ),
	        'cstn_gravity_forms_settings'
	    );

	    // Add field for the Gravity Forms ID in the Gravity Forms Integration tab.
	    add_settings_field(
	        'cstn_one_liners_form_id',
	        __( 'Gravity Forms ID', 'cstn-one-liners' ),
	        array( $this, 'gravity_forms_id_callback' ),
	        'cstn_gravity_forms_settings',
	        'cstn_gravity_forms_section'
	    );
	}



	public function vector_store_id_callback() {
	    $vector_store_id = get_option( 'cstn_one_liners_vector_store_id' );
	    echo '<input type="text" id="cstn_one_liners_vector_store_id" name="cstn_one_liners_vector_store_id" value="' . esc_attr( $vector_store_id ) . '" class="regular-text">';
	}

	/**
	 * Callback function for the Gravity Forms section description.
	 *
	 * @since 1.0.0
	 */
	public function gravity_forms_section_callback() {
	    echo '<p>' . esc_html__( 'Configure Gravity Forms settings to retrieve entries for processing.', 'cstn-one-liners' ) . '</p>';
	}

	/**
	 * Callback function for the Gravity Forms ID field.
	 *
	 * @since 1.0.0
	 */
	public function gravity_forms_id_callback() {
	    $form_id = get_option( 'cstn_one_liners_form_id' );
	    echo '<input type="number" id="cstn_one_liners_form_id" name="cstn_one_liners_form_id" value="' . esc_attr( $form_id ) . '" class="regular-text">';
	}

	/**
	 * AJAX handler to retrieve Gravity Forms entries.
	 *
	 * @since 1.0.0
	 */
	public function cstn_retrieve_entries() {
		// Verify the nonce to ensure the request is valid and secure.
		if ( ! isset( $_POST['security'] ) || ! check_ajax_referer( 'cstn_ajax_nonce', 'security', false ) ) {
			wp_send_json_error( __( 'Nonce verification failed. Please refresh the page and try again.', 'cstn-one-liners' ) );
			return;
		}

		// Get the form ID from the request.
		$form_id = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : 0;

		// Check if the form ID is valid.
		if ( empty( $form_id ) ) {
			wp_send_json_error( __( 'Invalid Form ID. Please enter a valid Form ID and try again.', 'cstn-one-liners' ) );
			return;
		}

		$search_criteria = array(
			'status' => 'active', // Only retrieve entries that are active.
		);

		$entries = GFAPI::get_entries( $form_id, $search_criteria );
		if ( is_wp_error( $entries ) ) {
			wp_send_json_error( __( 'Failed to retrieve entries: ' . $entries->get_error_message(), 'cstn-one-liners' ) );
			return;
		}

		// Create a table to display the IDs, links, and status.
		ob_start();
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr><th>ID</th><th>Link</th><th>Status</th></tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$entry_id  = esc_html( $entry['id'] );
			$entry_url = admin_url( "admin.php?page=gf_entries&view=entry&id={$form_id}&lid={$entry_id}" );

			// Placeholder status message.
			$status = 'Pending';

			echo '<tr id="entry-' . $entry_id . '" data-entry-id="' . $entry_id . '">';
			echo '<td>' . $entry_id . '</td>';
			echo '<td><a href="' . esc_url( $entry_url ) . '" target="_blank">' . __( 'View Entry', 'cstn-one-liners' ) . '</a></td>';
			echo '<td class="entry-status">' . esc_html( $status ) . '</td>'; // Add the new Status column here.
			echo '</tr>';
		}
		echo '</tbody></table>';
		$output = ob_get_clean();

		// Return the generated table as the response.
		wp_send_json_success( $output );
	}



	/**
	 * Callback function for the section description.
	 *
	 * @since 1.0.0
	 */
	public function settings_section_callback() {
		echo '<p>' . esc_html__( 'Enter your Assistant ID and API Key for the Cstn One-Liners plugin.', 'cstn-one-liners' ) . '</p>';
	}

	/**
	 * Callback function for the Assistant ID field.
	 *
	 * @since 1.0.0
	 */
	public function assistant_id_callback() {
		$assistant_id = get_option( 'cstn_one_liners_assistant_id' );
		echo '<input type="text" id="cstn_one_liners_assistant_id" name="cstn_one_liners_assistant_id" value="' . esc_attr( $assistant_id ) . '" class="regular-text">';
	}

	/**
	 * Callback function for the API Key field.
	 *
	 * @since 1.0.0
	 */
	public function api_key_callback() {
		$api_key = get_option( 'cstn_one_liners_api_key' );
		echo '<input type="password" id="cstn_one_liners_api_key" name="cstn_one_liners_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text">';
	}

	/**
	 * Test the API key and Assistant ID after saving the settings.
	 *
	 * @since 1.0.0
	 */
	public function cstn_test_api_and_assistant() {
	    // Check if the request contains the necessary parameters.
	    if ( ! isset( $_POST['api_key'] ) || ! isset( $_POST['assistant_id'] ) ) {
	        wp_send_json_error( __( 'Missing API Key or Assistant ID.', 'cstn-one-liners' ) );
	    }

	    $api_key      = sanitize_text_field( $_POST['api_key'] );
	    $assistant_id = sanitize_text_field( $_POST['assistant_id'] );

	    // Log the received API key and Assistant ID for debugging.
	    error_log( 'Received API Key: ' . $api_key );
	    error_log( 'Received Assistant ID: ' . $assistant_id );

	    // Validate the API key first.
	    $api_key_valid = $this->validate_api_key( $api_key );
	    if ( ! $api_key_valid ) {
	        error_log( 'API Key validation failed.' );
	        wp_send_json_error( __( 'Invalid API Key. Please check your key and try again.', 'cstn-one-liners' ) );
	    }
	    error_log( 'API Key validated successfully.' );

	    // Validate the Assistant ID using the new function.
	    $assistant_valid = $this->validate_assistant_id( $api_key, $assistant_id );
	    if ( ! $assistant_valid ) {
	        error_log( 'Assistant ID validation failed.' );
	        wp_send_json_error( __( 'Invalid Assistant ID. Please check the ID and try again.', 'cstn-one-liners' ) );
	    }
	    error_log( 'Assistant ID validated successfully.' );

	    // If both are valid, log success and return success response.
	    error_log( 'Both API Key and Assistant ID validated successfully.' );
	    wp_send_json_success( __( 'API Key and Assistant ID are valid!', 'cstn-one-liners' ) );
	}


	/**
	 * Validate the API key by making a sample request to OpenAI's API.
	 *
	 * @since 1.0.0
	 * @param string $api_key The API key to validate.
	 * @return bool True if the API key is valid, false otherwise.
	 */
	private function validate_api_key( $api_key ) {
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			)
		);

		// Check if the response is successful.
		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
	}

	/**
	 * Validate if the given Assistant ID is valid using OpenAI's Assistant API endpoint.
	 *
	 * @param string $api_key      The OpenAI API key.
	 * @param string $assistant_id The Assistant ID to validate.
	 * @return bool True if the Assistant ID is valid, false otherwise.
	 */
	private function validate_assistant_id( $api_key, $assistant_id ) {
		// Log the Assistant ID being tested.
		error_log( 'Testing Assistant ID: ' . $assistant_id );

		// Make a request to the /v1/assistants/{assistant_id} endpoint.
		$response = wp_remote_get(
			"https://api.openai.com/v1/assistants/{$assistant_id}",
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'OpenAI-Beta'   => 'assistants=v2',
				),
			)
		);

		// Check for errors in the response.
		if ( is_wp_error( $response ) ) {
			error_log( 'Assistant ID Validation Error: ' . $response->get_error_message() );
			return false;
		}

		// Retrieve and decode the response body.
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Log the response body for debugging purposes.
		error_log( 'Assistant ID Response Body: ' . print_r( $data, true ) );

		// Check if the response contains the assistant ID.
		return isset( $data['id'] ) && $data['id'] === $assistant_id;
	}
}
