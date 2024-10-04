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
class Cstn_One_Liners_Openai {

	// Define a property to hold all collected sentences across all entries.
	private $all_collected_sentences = array();

	/**
	 * AJAX handler to process entries, run them through the assistant, store embeddings, and display one-liners.
	 *
	 * @since 1.0.0
	 */
	public function cstn_process_entries() {
	    // Verify the nonce to ensure the request is valid and secure.
	    if ( ! isset( $_POST['security'] ) || ! check_ajax_referer( 'cstn_ajax_nonce', 'security', false ) ) {
	        wp_send_json_error( __( 'Nonce verification failed. Please refresh the page and try again.', 'cstn-one-liners' ) );
	        return;
	    }

	    // Retrieve API key, assistant ID, and vector store ID from the plugin settings.
	    $api_key         = get_option( 'cstn_one_liners_api_key' );
	    $assistant_id    = get_option( 'cstn_one_liners_assistant_id' );
	    $vector_store_id = get_option( 'cstn_one_liners_vector_store_id' );

	    if ( empty( $api_key ) || empty( $assistant_id ) || empty( $vector_store_id ) ) {
	        wp_send_json_error( __( 'API Key, Assistant ID, or Vector Store ID is not configured. Please configure the settings first.', 'cstn-one-liners' ) );
	        return;
	    }

	    // Retrieve the form ID from the plugin settings.
	    $form_id = get_option( 'cstn_one_liners_form_id' );
	    if ( empty( $form_id ) ) {
	        wp_send_json_error( __( 'Form ID is not configured. Please configure the settings first.', 'cstn-one-liners' ) );
	        return;
	    }

	    // Retrieve only active entries from the specified Gravity Forms form.
	    $search_criteria = array( 'status' => 'active' );
	    $entries = GFAPI::get_entries( $form_id, $search_criteria );
	    if ( is_wp_error( $entries ) ) {
	        wp_send_json_error( __( 'Failed to retrieve entries: ' . $entries->get_error_message(), 'cstn-one-liners' ) );
	        return;
	    }

	    if ( empty( $entries ) ) {
	        wp_send_json_error( __( 'No entries found in the form.', 'cstn-one-liners' ) );
	        return;
	    }

	    // Store one-liners and embeddings for display and tracking.
	    $one_liners = array();

	    // Create a new thread for processing.
	    $thread_id = $this->create_thread( $api_key );
	    if ( ! $thread_id ) {
	        wp_send_json_error( __( 'Failed to create a new thread for processing.', 'cstn-one-liners' ) );
	        return;
	    }

	    // Loop through each entry, send to the assistant, and store responses and embeddings.
	    foreach ( $entries as $entry ) {
	        $field_1 = $entry[1] ?? ''; // What brought you to Centerstone?
	        $field_3 = $entry[3] ?? ''; // How does Centerstone support the community?
	        $field_4 = $entry[4] ?? ''; // In a word, what does Noble Purpose mean to you?

	        // Combine fields into a single query to send to the assistant.
	        $entry_text = sprintf(
	            "What brought you to Centerstone?\n%s\n\nHow does Centerstone support the community?\n%s\n\nIn a word, what does Noble Purpose mean to you?\n%s",
	            $field_1,
	            $field_3,
	            $field_4
	        );

	        error_log( '[INFO] Processing entry ID: ' . $entry['id'] . ' with content: ' . $entry_text );

	        // Send the entry text to the assistant and get a response.
	        $one_liner_response = $this->add_message_and_run_thread( $api_key, $thread_id, $assistant_id, $entry_text );

	        // Log the full structure of the one-liner response for debugging.
	        error_log( '[INFO] Full one-liner response for entry ID ' . $entry['id'] . ': ' . print_r( $one_liner_response, true ) );

	        // Check if the response is valid.
	        if ( is_wp_error( $one_liner_response ) ) {
	            error_log( '[ERROR] Assistant response error for entry ID ' . $entry['id'] . ': ' . $one_liner_response->get_error_message() );
	            $one_liners[] = array(
	                'entry_id' => $entry['id'],
	                'error'    => $one_liner_response->get_error_message(),
	            );
	            continue;
	        }

	        // Handle the one-liner response based on its array structure.
	        if ( is_array( $one_liner_response ) && isset( $one_liner_response['summary'] ) && is_array( $one_liner_response['summary'] ) ) {
	            $summary_text_array = $one_liner_response['summary'];

	            // Generate a single vector embedding for the original entry text.
	            $vector = $this->generate_vector_for_text( $api_key, $entry_text );
	            if ( is_wp_error( $vector ) ) {
	                error_log( '[ERROR] Embedding generation error for entry ID ' . $entry['id'] . ': ' . $vector->get_error_message() );
	                $one_liners[] = array(
	                    'entry_id' => $entry['id'],
	                    'error'    => 'Embedding Error: ' . $vector->get_error_message(),
	                );
	                continue;
	            }

	            // Store the generated vector in the vector store.
	            $storage_result = $this->store_vector_in_vector_store( $api_key, $vector_store_id, $vector, $entry['id'], $entry_text );
	            if ( is_wp_error( $storage_result ) ) {
	                error_log( '[ERROR] Vector store error for entry ID ' . $entry['id'] . ': ' . $storage_result->get_error_message() );
	                $one_liners[] = array(
	                    'entry_id' => $entry['id'],
	                    'error'    => 'Vector Store Error: ' . $storage_result->get_error_message(),
	                );
	                continue;
	            }

	            // Collect the three one-liners (sentences) from this entry.
	            $this->all_collected_sentences = array_merge( $this->all_collected_sentences, $summary_text_array );

	            // Add the processed summary text and vector to the final one-liners array.
	            $one_liners[] = array(
	                'entry_id'  => $entry['id'],
	                'one_liner' => $summary_text_array, // Store the summary as an array for separate sentences.
	                'vector'    => $vector,             // Store the vector for the entire entry text.
	            );
	        } else {
	            error_log( '[ERROR] Missing or invalid "summary" key in the one-liner response for entry ID ' . $entry['id'] );
	            $one_liners[] = array(
	                'entry_id' => $entry['id'],
	                'error'    => 'Missing or invalid summary in one-liner response.',
	            );
	        }
	    }

	    // After processing all entries, generate the final cumulative summary using all collected sentences.
	    $final_summary = $this->generate_final_cumulative_summary( $api_key, $assistant_id, $thread_id, $this->all_collected_sentences );

	    // Include the final cumulative summary in the response.
	    $response = array(
	        'entries'        => $one_liners,
	        'final_summary'  => $final_summary, // Include the actual final summary text.
	    );

	    // Log the final cumulative summary for verification.
	    error_log( '[INFO] Final cumulative summary: ' . $final_summary );

	    // Send the one-liners and final summary back for display.
	    wp_send_json_success( $response );
	}

	/**
	 * Generate the final cumulative summary using all collected sentences.
	 *
	 * @since 1.0.0
	 * @param string $api_key        The OpenAI API key.
	 * @param string $assistant_id   The assistant ID.
	 * @param string $thread_id      The thread ID.
	 * @param array  $all_sentences  The array of all collected sentences from each entry.
	 * @return mixed The final cumulative summary as an array or an error message.
	 */
	private function generate_final_cumulative_summary( $api_key, $assistant_id, $thread_id, $all_sentences ) {
	    // Combine all collected sentences into a single block of text.
	    $combined_text = implode( "\n", $all_sentences );

	    // Log the combined text before sending it to the assistant.
	    error_log( '[INFO] Combined text to send to assistant: ' . $combined_text );

	    // Send the combined text to the assistant for a final summarization.
	    $final_summary_response = $this->add_message_and_run_thread( $api_key, $thread_id, $assistant_id, $combined_text );

	    // Log the raw response to see its full structure.
	    error_log( '[INFO] Final summary response from assistant (raw): ' . print_r( $final_summary_response, true ) );

	    // Check if there was an error with the request.
	    if ( is_wp_error( $final_summary_response ) ) {
	        error_log( '[ERROR] Final summary generation error: ' . $final_summary_response->get_error_message() );
	        return 'Error generating final summary: ' . $final_summary_response->get_error_message();
	    }

	    // If the response is in the expected format, return the three sentences as an array.
	    if ( is_array( $final_summary_response ) && isset( $final_summary_response['sentence_1'], $final_summary_response['sentence_2'], $final_summary_response['sentence_3'] ) ) {
	        // Create and return an array of the three sentences.
	        return array(
	            $final_summary_response['sentence_1'],
	            $final_summary_response['sentence_2'],
	            $final_summary_response['sentence_3'],
	        );
	    }

	    // If the response format is unexpected, return an error message.
	    error_log( '[ERROR] Invalid response format. Expected sentence keys not found.' );
	    return 'Failed to generate final summary. Invalid response format.';
	}






	/**
	 * Helper method to generate a vector for a given text.
	 *
	 * @since 1.0.0
	 * @param string $api_key The OpenAI API key.
	 * @param string $text    The text content to generate a vector for.
	 * @return array|WP_Error The generated vector or WP_Error on failure.
	 */
	private function generate_vector_for_text( $api_key, $text ) {
	    // Check if $text is an array and convert it to a string if necessary.
	    if ( is_array( $text ) ) {
	        error_log( '[WARNING] Input text is an array. Converting array to JSON string.' );
	        $text = json_encode( $text );
	    }

	    // Ensure $text is a string.
	    if ( ! is_string( $text ) ) {
	        error_log( '[ERROR] Input text is not a string. Type received: ' . gettype( $text ) );
	        return new WP_Error( 'invalid_input', __( 'Input text must be a string.', 'cstn-one-liners' ) );
	    }

	    // Log the text being sent for embedding generation.
	    error_log( '[INFO] Generating vector for text: ' . $text );

	    $response = wp_remote_post(
	        'https://api.openai.com/v1/embeddings',
	        array(
	            'headers' => array(
	                'Authorization' => 'Bearer ' . $api_key,
	                'Content-Type'  => 'application/json',
	            ),
	            'body'    => wp_json_encode( array( 'input' => $text, 'model' => 'text-embedding-ada-002' ) ),
	        )
	    );

	    // Check for errors in the API request.
	    if ( is_wp_error( $response ) ) {
	        error_log( '[ERROR] Failed to generate embedding: ' . $response->get_error_message() );
	        return new WP_Error( 'embedding_failed', __( 'Failed to generate embedding: ' . $response->get_error_message(), 'cstn-one-liners' ) );
	    }

	    $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

	    // Check if the expected embedding data is present in the response.
	    if ( ! isset( $response_body['data'][0]['embedding'] ) ) {
	        error_log( '[ERROR] Embedding data not found in response: ' . print_r( $response_body, true ) );
	        return new WP_Error( 'embedding_missing', __( 'Embedding data not found in response.', 'cstn-one-liners' ) );
	    }

	    // error_log( '[INFO] Successfully generated embedding for text: ' . print_r( $response_body['data'][0]['embedding'], true ) );

	    return $response_body['data'][0]['embedding'];
	}



	private function create_vector_file( $api_key, $vector, $entry_id, $text ) {
	    // Prepare file content to be stored in OpenAI.
	    $file_content = json_encode( array(
	        'vector'   => $vector,
	        'entry_id' => $entry_id,
	        'text'     => $text,
	    ) );

	    // Create a temporary file for the vector data.
	    $temp_file_path = wp_tempnam( 'vector_data' );
	    file_put_contents( $temp_file_path, $file_content );

	    // Initialize cURL
	    $ch = curl_init();

	    // Set cURL options
	    curl_setopt( $ch, CURLOPT_URL, 'https://api.openai.com/v1/files' );
	    curl_setopt( $ch, CURLOPT_POST, 1 );
	    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

	    $headers = array(
	        'Authorization: Bearer ' . $api_key,
	        'OpenAI-Beta: assistants=v2',
	        // Do not set Content-Type header; cURL will set it automatically for multipart/form-data
	    );

	    curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

	    $postfields = array(
	        'purpose' => 'fine-tune', // Use a valid purpose according to OpenAI API documentation
	        'file'    => new CURLFile( $temp_file_path, 'application/json', 'vector_data.json' ),
	    );

	    curl_setopt( $ch, CURLOPT_POSTFIELDS, $postfields );

	    // Execute cURL request
	    $response = curl_exec( $ch );
	    $httpcode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	    $err = curl_error( $ch );
	    curl_close( $ch );

	    // Remove the temporary file after uploading.
	    unlink( $temp_file_path );

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
	private function attach_file_to_vector_store( $api_key, $vector_store_id, $file_id ) {
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
	 * Store the generated vector in the vector store with metadata.
	 *
	 * @since 1.0.0
	 * @param string $api_key         The OpenAI API key.
	 * @param string $vector_store_id The vector store ID.
	 * @param array  $vector          The generated vector to store.
	 * @param string $entry_id        The Gravity Forms entry ID.
	 * @param string $text            The original text content.
	 * @return array|WP_Error The result of the storage operation or WP_Error on failure.
	 */
	private function store_vector_in_vector_store( $api_key, $vector_store_id, $vector, $entry_id, $text ) {
	    // Step 1: Create a file with the vector data.
	    $file_id = $this->create_vector_file( $api_key, $vector, $entry_id, $text );
	    if ( is_wp_error( $file_id ) ) {
	        return $file_id;
	    }

	    // Step 2: Attach the file to the vector store.
	    $attachment_result = $this->attach_file_to_vector_store( $api_key, $vector_store_id, $file_id );
	    if ( is_wp_error( $attachment_result ) ) {
	        return $attachment_result;
	    }

	    // Optionally, you can return or log the result
	    return $attachment_result;
	}




		/**
	 * Create a new thread in the OpenAI API.
	 *
	 * @since 1.0.0
	 * @param string $api_key The OpenAI API key.
	 * @return string|null The thread ID or null if failed.
	 */
	public function create_thread( $api_key ) {
		$response = wp_remote_post(
			'https://api.openai.com/v1/threads',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'body'    => '{}',
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $response_body['id'] ) ) {
			return null;
		}

		return $response_body['id'];
	}

	/**
	 * Add a message and run the thread in the OpenAI API.
	 *
	 * @since 1.0.0
	 * @param string $api_key      The OpenAI API key.
	 * @param string $thread_id    The thread ID.
	 * @param string $assistant_id The assistant ID.
	 * @param string $query        The query to add as a message.
	 * @return mixed The result of the run or an error message.
	 */
	public function add_message_and_run_thread( $api_key, $thread_id, $assistant_id, $query ) {
		// Step 3: Add a message to the thread.
		$message_api_url = "https://api.openai.com/v1/threads/{$thread_id}/messages";
		$body            = wp_json_encode(
			array(
				'role'    => 'user',
				'content' => $query,
			)
		);
		$response        = wp_remote_post(
			$message_api_url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'Failed to add message.';
		}

		// Step 4: Run the thread.
		$run_api_url = "https://api.openai.com/v1/threads/{$thread_id}/runs";
		$body        = wp_json_encode(
			array(
				'assistant_id' => $assistant_id,
			)
		);
		$response    = wp_remote_post(
			$run_api_url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'Failed to run thread.';
		}

		$response_body    = wp_remote_retrieve_body( $response );
		$decoded_response = json_decode( $response_body, true );

		if ( 'queued' === $decoded_response['status'] || 'running' === $decoded_response['status'] ) {
			return $this->wait_for_run_completion( $api_key, $decoded_response['id'], $thread_id );
		} elseif ( 'completed' === $decoded_response['status'] ) {
			return $this->fetch_messages_from_thread( $api_key, $thread_id );
		} else {
			return 'Run failed or was cancelled.';
		}
	}

	/**
	 * Wait for the run to complete in the OpenAI API.
	 *
	 * @since 1.0.0
	 * @param string $api_key   The OpenAI API key.
	 * @param string $run_id    The run ID.
	 * @param string $thread_id The thread ID.
	 * @return mixed The run result or an error message.
	 */
	private function wait_for_run_completion( $api_key, $run_id, $thread_id ) {
		$status_check_url = "https://api.openai.com/v1/threads/{$thread_id}/runs/{$run_id}";

		$attempts     = 0;
		$max_attempts = 20;

		while ( $attempts < $max_attempts ) {
			sleep( 5 );
			$response = wp_remote_get(
				$status_check_url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'OpenAI-Beta'   => 'assistants=v2',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return 'Failed to check run status.';
			}

			$response_body    = wp_remote_retrieve_body( $response );
			$decoded_response = json_decode( $response_body, true );

			if ( isset( $decoded_response['error'] ) ) {
				return 'Error retrieving run status: ' . $decoded_response['error']['message'];
			}

			if ( isset( $decoded_response['status'] ) && 'completed' === $decoded_response['status'] ) {
				$this->cancel_run( $api_key, $thread_id, $decoded_response['id'] );
				return $this->fetch_messages_from_thread( $api_key, $thread_id );
			} elseif ( isset( $decoded_response['status'] ) && ( 'failed' === $decoded_response['status'] || 'cancelled' === $decoded_response['status'] ) ) {
				return 'Run failed or was cancelled.';
			} elseif ( isset( $decoded_response['status'] ) && 'requires_action' === $decoded_response['status'] ) {
				return $this->handle_requires_action( $api_key, $run_id, $thread_id, $decoded_response['required_action'] );
			}

			++$attempts;
		}

		return 'Run did not complete in expected time.';
	}

	/**
	 * Handle required actions for the run.
	 *
	 * @since 1.0.0
	 * @param string $api_key         The OpenAI API key.
	 * @param string $run_id          The run ID.
	 * @param string $thread_id       The thread ID.
	 * @param array  $required_action The required action details.
	 * @return mixed The run result or an error message.
	 */
	private function handle_requires_action( $api_key, $run_id, $thread_id, $required_action ) {
		if ( 'submit_tool_outputs' === $required_action['type'] ) {
			$tool_calls   = $required_action['submit_tool_outputs']['tool_calls'];
			$tool_outputs = array();

			foreach ( $tool_calls as $tool_call ) {
				$output = wp_json_encode( array( 'success' => 'true' ) );

				$tool_outputs[] = array(
					'tool_call_id' => $tool_call['id'],
					'output'       => $output,
				);
			}

			$submit_tool_outputs_url = "https://api.openai.com/v1/threads/{$thread_id}/runs/{$run_id}/submit_tool_outputs";
			$response                = wp_remote_post(
				$submit_tool_outputs_url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'OpenAI-Beta'   => 'assistants=v2',
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( array( 'tool_outputs' => $tool_outputs ) ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return 'Failed to submit tool outputs.';
			}

			return $this->wait_for_run_completion( $api_key, $run_id, $thread_id );
		}

		return 'Unhandled requires_action.';
	}

	/**
	 * Fetch messages from the thread.
	 *
	 * @since 1.0.0
	 * @param string $api_key   The OpenAI API key.
	 * @param string $thread_id The thread ID.
	 * @return mixed The messages from the thread or an error message.
	 */
	public function fetch_messages_from_thread( $api_key, $thread_id ) {
		$messages_url = "https://api.openai.com/v1/threads/{$thread_id}/messages";

		$response = wp_remote_get(
			$messages_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'Failed to fetch messages.';
		}

		$response_body    = wp_remote_retrieve_body( $response );
		$decoded_response = json_decode( $response_body, true );

		if ( ! isset( $decoded_response['data'] ) ) {
			return 'No messages found.';
		}

		$messages = array_map(
			function ( $message ) {
				foreach ( $message['content'] as $content ) {
					if ( 'text' === $content['type'] ) {
						return json_decode( $content['text']['value'], true );
					}
				}
				return 'No text content.';
			},
			$decoded_response['data']
		);

		return $messages[0];
	}

	/**
	 * Cancel the run when complete.
	 *
	 * @since 1.0.0
	 * @param string $api_key   The OpenAI API key.
	 * @param string $thread_id The thread ID.
	 * @param string $run_id    The run ID.
	 * @return mixed The result of the cancellation or an error message.
	 */
	private function cancel_run( $api_key, $thread_id, $run_id ) {
		$cancel_url = "https://api.openai.com/v1/threads/{$thread_id}/runs/{$run_id}/cancel";
		$response   = wp_remote_post(
			$cancel_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'OpenAI-Beta'   => 'assistants=v2',
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'Failed to cancel run.';
		}

		return 'Run cancelled successfully.';
	}
	/**
	 * Validate if the given Assistant ID is valid using OpenAI's API.
	 *
	 * @since 1.0.0
	 * @param string $assistant_id The Assistant ID to validate.
	 * @param string $api_key      The OpenAI API key.
	 * @return bool True if the Assistant ID is valid, false otherwise.
	 */
	public function is_assistant_valid( $assistant_id, $api_key ) {
		if ( empty( $assistant_id ) || empty( $api_key ) ) {
			return false;
		}

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

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return isset( $data['id'] ) && $data['id'] === $assistant_id;
	}
}
