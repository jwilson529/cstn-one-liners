<?php
/**
 * The admin-specific functionality of the plugin concerning settings and OpenAI API interaction.
 *
 * Defines methods for interacting with the OpenAI API and managing assistant-related tasks.
 *
 * @link       https://centerstone.org
 * @since      1.0.0
 *
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/admin
 */

/**
 * Class Cstn_One_Liners_Openai
 *
 * Provides methods for interacting with the OpenAI API, managing threads, and using assistants.
 *
 * @package    Cstn_One_Liners
 * @subpackage Cstn_One_Liners/admin
 */
class Cstn_One_Liners_Vectors {

	/**
	 * Helper method to generate a vector for a given text.
	 *
	 * @since 1.0.0
	 * @param string $api_key The OpenAI API key.
	 * @param string $text    The text content to generate a vector for.
	 * @return array|WP_Error The generated vector or WP_Error on failure.
	 */
	public static function generate_vector_for_text( $api_key, $text ) {
		// Check if $text is an array and convert it to a string if necessary.
		if ( is_array( $text ) ) {
			// error_log( '[WARNING] Input text is an array. Converting array to JSON string.' );
			$text = json_encode( $text );
		}

		// Ensure $text is a string.
		if ( ! is_string( $text ) ) {
			// error_log( '[ERROR] Input text is not a string. Type received: ' . gettype( $text ) );
			return new WP_Error( 'invalid_input', __( 'Input text must be a string.', 'cstn-one-liners' ) );
		}

		// Log the text being sent for embedding generation.
		// error_log( '[INFO] Generating vector for text: ' . $text );

		$response = wp_remote_post(
			'https://api.openai.com/v1/embeddings',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'input' => $text,
						'model' => 'text-embedding-ada-002',
					)
				),
				'timeout' => 30, // Set the timeout to 30 seconds (or more if needed)
			)
		);

		// Check for errors in the API request.
		if ( is_wp_error( $response ) ) {
			// error_log( '[ERROR] Failed to generate embedding: ' . $response->get_error_message() );
			return new WP_Error( 'embedding_failed', __( 'Failed to generate embedding: ' . $response->get_error_message(), 'cstn-one-liners' ) );
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Check if the expected embedding data is present in the response.
		if ( ! isset( $response_body['data'][0]['embedding'] ) ) {
			// error_log( '[ERROR] Embedding data not found in response: ' . print_r( $response_body, true ) );
			return new WP_Error( 'embedding_missing', __( 'Embedding data not found in response.', 'cstn-one-liners' ) );
		}

		// error_log( '[INFO] Successfully generated embedding for text: ' . print_r( $response_body['data'][0]['embedding'], true ) );

		return $response_body['data'][0]['embedding'];
	}



	/**
	 * Create a vector file to be uploaded to the OpenAI vector store.
	 *
	 * @since 1.0.0
	 * @param string $api_key  The OpenAI API key.
	 * @param array  $vector   The generated vector data.
	 * @param string $entry_id The ID of the entry being processed.
	 * @param string $text     The original text content.
	 * @return array|WP_Error  The ID of the uploaded file or a WP_Error on failure.
	 */
	public static function create_vector_file( $api_key, $vector, $entry_id, $text ) {
		// Prepare file content to be stored in OpenAI.
		$file_content = json_encode(
			array(
				'vector'   => $vector,
				'entry_id' => $entry_id,
				'text'     => $text,
			)
		);

		// Create a temporary file for the vector data, using a name that includes the entry ID.
		$file_name      = "centerstone_vector_data_entry_{$entry_id}.json"; // Create a more descriptive file name.
		$temp_file_path = wp_tempnam( $file_name ); // Use the descriptive file name instead of a generic one.
		file_put_contents( $temp_file_path, $file_content );

		// Initialize cURL for the upload process.
		$ch = curl_init();

		// Set cURL options.
		curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/files' );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		// Set a longer timeout for the request to allow enough time for file upload.
		curl_setopt( $ch, CURLOPT_TIMEOUT, 60 ); // Set the timeout to 60 seconds or more as needed.
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 30 ); // Set a connection timeout of 30 seconds.

		// Set headers
		$headers = array(
			'Authorization: Bearer ' . $api_key,
			'OpenAI-Beta: assistants=v2',
			// Do not set Content-Type header; cURL will set it automatically for multipart/form-data
		);

		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

		// Set the file path and purpose for the POST request.
		$postfields = array(
			'purpose' => 'assistants', // Use a valid purpose according to OpenAI API documentation.
			'file'    => new CURLFile( $temp_file_path, 'application/json', $file_name ), // Use the new descriptive file name.
		);

		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postfields );

		// Execute cURL request.
		$response = curl_exec( $ch );
		$httpcode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err      = curl_error( $ch );
		curl_close( $ch );

		// Remove the temporary file after uploading.
		unlink( $temp_file_path );

		// Check for errors during the upload process.
		if ( $err ) {
			return new WP_Error( 'file_creation_failed', __( 'Failed to create file: ' . $err, 'cstn-one-liners' ) );
		} else {
			$response_body = json_decode( $response, true );

			if ( $httpcode !== 200 ) {
				$error_message = isset( $response_body['error']['message'] ) ? $response_body['error']['message'] : 'Unknown error';
				return new WP_Error( 'file_creation_failed', __( 'Failed to create file: ' . $error_message, 'cstn-one-liners' ) );
			}

			if ( ! isset( $response_body['id'] ) ) {
				return new WP_Error( 'file_id_missing', __( 'File ID missing from response.', 'cstn-one-liners' ) );
			}

			return $response_body['id'];
		}
	}







	/**
	 * Attach a single file to a vector store in the OpenAI system.
	 *
	 * @since 1.0.0
	 * @param string $api_key        The OpenAI API key.
	 * @param string $vector_store_id The vector store ID to which the file should be attached.
	 * @param string $file_id        The file ID to attach to the vector store.
	 * @return array|WP_Error        The vector store file object or WP_Error on failure.
	 */
	public static function attach_file_to_vector_store( $api_key, $vector_store_id, $file_id ) {
		// Prepare the request body with a singular file ID.
		$body = array(
			'file_id' => $file_id,
		);

		// Make the API request to attach the file to the vector store.
		$response = wp_remote_post(
			"https://api.openai.com/v1/vector_stores/{$vector_store_id}/files",
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30, // Set the timeout to 30 seconds (or more if needed)
			)
		);

		// Log the raw response for debugging purposes.
		// error_log( 'Attach File to Vector Store Response: ' . print_r( $response, true ) );

		// Check if the request resulted in an error.
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'attach_file_failed', __( 'Failed to attach file to vector store: ' . $response->get_error_message(), 'cstn-one-liners' ) );
		}

		// Parse the response body and check for errors.
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Log the parsed response body for debugging purposes.
		// error_log( 'Attach File Parsed Response: ' . print_r( $response_body, true ) );

		if ( isset( $response_body['error'] ) ) {
			return new WP_Error( 'attach_file_error', __( 'Error attaching file: ' . $response_body['error']['message'], 'cstn-one-liners' ) );
		}

		return $response_body;
	}




	/**
	 * Store the generated vector and original text in the vector store with metadata.
	 *
	 * @since 1.0.0
	 * @param string $api_key         The OpenAI API key.
	 * @param string $vector_store_id The vector store ID.
	 * @param array  $vector          The generated vector to store.
	 * @param string $entry_id        The Gravity Forms entry ID.
	 * @param string $text            The original text content.
	 * @return array|WP_Error The result of the storage operation or WP_Error on failure.
	 */
	public static function store_vector_in_vector_store( $api_key, $vector_store_id, $vector, $entry_id, $text ) {
		// Create a file with both the vector and the original text.
		$file_content = json_encode(
			array(
				'vector'   => $vector,
				'entry_id' => $entry_id,
				'text'     => $text, // Include the original text along with the vector.
			)
		);

		// Step 1: Create the file in OpenAI's system.
		$file_id = self::create_vector_file( $api_key, $vector, $entry_id, $text );
		if ( is_wp_error( $file_id ) ) {
			return $file_id;
		}

		// Step 2: Attach the file to the vector store.
		$attachment_result = self::attach_file_to_vector_store( $api_key, $vector_store_id, $file_id );
		if ( is_wp_error( $attachment_result ) ) {
			return $attachment_result;
		}

		// Step 3: Return success or log the result.
		return $attachment_result;
	}

	/**
	 * Store vector with retry logic.
	 */
	public function store_vector_with_retry( $api_key, $vector_store_id, $vector, $entry_id, $text, $max_retries = 3 ) {
		$retry_count = 0;
		$result      = null;

		while ( $retry_count < $max_retries ) {
			$result = self::store_vector_in_vector_store( $api_key, $vector_store_id, $vector, $entry_id, $text );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			++$retry_count;
			error_log( "[RETRY] Failed to store vector for entry ID: {$entry_id}. Retry {$retry_count} of {$max_retries}." );
			sleep( 1 ); // Wait 1 second before retrying.
		}

		return $result; // Return the final result (either success or WP_Error).
	}
}
