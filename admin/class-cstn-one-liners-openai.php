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

	// Holds all collected sentences across entries.
	private $all_collected_sentences = array();

	/**
	 * AJAX handler to generate embeddings for entries, store them in the vector store, and generate a final summary.
	 *
	 * @since 1.0.0
	 */
	public function cstn_generate_embeddings() {
	    // Verify the nonce to ensure the request is valid and secure.
	    if ( ! isset( $_POST['security'] ) || ! check_ajax_referer( 'cstn_ajax_nonce', 'security', false ) ) {
	        wp_send_json_error( __( 'Nonce verification failed. Please refresh the page and try again.', 'cstn-one-liners' ) );
	        return;
	    }

	    // Retrieve necessary options from the plugin settings.
	    $api_key         = get_option( 'cstn_one_liners_api_key' );
	    $assistant_id    = get_option( 'cstn_one_liners_assistant_id' );
	    $vector_store_id = get_option( 'cstn_one_liners_vector_store_id' );

	    // Validate the presence of essential configuration options.
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
	    $entries         = GFAPI::get_entries( $form_id, $search_criteria );

	    // Handle potential errors when fetching entries.
	    if ( is_wp_error( $entries ) ) {
	        wp_send_json_error( __( 'Failed to retrieve entries: ' . $entries->get_error_message(), 'cstn-one-liners' ) );
	        return;
	    }

	    // Check if there are any entries in the form.
	    if ( empty( $entries ) ) {
	        wp_send_json_error( __( 'No entries found in the form.', 'cstn-one-liners' ) );
	        return;
	    }

	    // Initialize arrays to track the status of each entry and collect all texts used in embedding.
	    $entry_statuses = array();
	    $all_collected_sentences = array(); // This will hold all sentences used for embeddings.

	    // Create a new thread for the assistant.
	    $thread_id = $this->create_thread( $api_key );
	    if ( ! $thread_id ) {
	        wp_send_json_error( __( 'Failed to create a new thread for processing.', 'cstn-one-liners' ) );
	        return;
	    }

	    // Loop through each entry and generate an embedding.
	    foreach ( $entries as $entry ) {
	        $entry_id = $entry['id'];
	        $entry_statuses[ $entry_id ] = 'Processing';

	        // Retrieve fields from the entry and ensure they have default empty values if not set.
	        $field_1 = isset( $entry[1] ) ? $entry[1] : '';
	        $field_3 = isset( $entry[3] ) ? $entry[3] : '';
	        $field_4 = isset( $entry[4] ) ? $entry[4] : '';

	        // Combine fields into a single text to send to the embedding endpoint.
	        $entry_text = sprintf(
	            "What brought you to Centerstone?\n%s\n\nHow does Centerstone support the community?\n%s\n\nIn a word, what does Noble Purpose mean to you?\n%s",
	            $field_1,
	            $field_3,
	            $field_4
	        );

	        // Collect this text to be used in the final cumulative summary.
	        $all_collected_sentences[] = $entry_text;

	        // 1. Generate the embedding for the entry text.
	        $embedding = Cstn_One_Liners_Vectors::generate_vector_for_text( $api_key, $entry_text );
	        if ( is_wp_error( $embedding ) ) {
	            $entry_statuses[ $entry_id ] = 'Embedding Error: ' . $embedding->get_error_message();
	            continue;
	        }

	        // 2. Store the generated embedding in the vector store using `store_vector_in_vector_store`.
	        $storage_result = Cstn_One_Liners_Vectors::store_vector_in_vector_store( $api_key, $vector_store_id, $embedding, $entry_id, $entry_text );
	        if ( is_wp_error( $storage_result ) ) {
	            $entry_statuses[ $entry_id ] = 'Vector Store Error: ' . $storage_result->get_error_message();
	            continue;
	        }

	        // Update the status to 'Complete' for this entry.
	        $entry_statuses[ $entry_id ] = 'Complete';
	    }

	    // Step 3: Generate the final cumulative summary using all collected sentences.
	    $final_summary = $this->generate_final_cumulative_summary( $api_key, $assistant_id, $thread_id, $all_collected_sentences );

	    // Prepare the response to be sent back to the client.
	    $response = array(
	        'entry_statuses' => $entry_statuses, // Include the status of each entry.
	        'final_summary'  => $final_summary,  // Include the final summary generated.
	    );

	    // Send the statuses and final summary back for display.
	    wp_send_json_success( $response );
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
	 * Fetch messages from the thread and return the one containing the summary.
	 *
	 * @since 1.0.0
	 * @param string $api_key   The OpenAI API key.
	 * @param string $thread_id The thread ID.
	 * @return mixed The summary message if found, or an error message.
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

	    // Log the full response for debugging purposes.
	    // error_log( '[DEBUG] Full messages response: ' . print_r( $decoded_response, true ) );

	    if ( ! isset( $decoded_response['data'] ) ) {
	        return 'No messages found.';
	    }

	    $messages = $decoded_response['data'];
	    $summary_message = null;

	    // Iterate through each message and look for one that contains a `summary` key.
	    foreach ( $messages as $message ) {
	        foreach ( $message['content'] as $content ) {
	            if ( 'text' === $content['type'] ) {
	                $decoded_text = json_decode( $content['text']['value'], true );

	                // Log each decoded text for analysis.
	                error_log( '[DEBUG] Decoded message content: ' . print_r( $decoded_text, true ) );

	                // If this message contains a `summary` key, consider it as the final cumulative summary.
	                if ( is_array( $decoded_text ) && isset( $decoded_text['summary'] ) ) {
	                    $summary_message = $decoded_text;
	                    break 2; // Exit both loops once we find the correct message.
	                }
	            }
	        }
	    }

	    // Return the summary message if found.
	    if ( ! is_null( $summary_message ) ) {
	        // error_log( '[INFO] Successfully retrieved the summary: ' . print_r( $summary_message, true ) );
	        return $summary_message;
	    } else {
	        // error_log( '[ERROR] No valid summary message found in thread messages.' );
	        return 'Failed to generate final summary. Invalid response format.';
	    }
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
	    // Combine all collected sentences into a single block of text to send to the assistant.
	    $combined_text = implode( "\n", $all_sentences );

	    // Log the combined text for debugging.
	    error_log( '[INFO] Combined text to send to assistant for cumulative summary: ' . $combined_text );

	    // Send the combined text to the assistant and run the thread.
	    $final_summary_response = $this->add_message_and_run_thread( $api_key, $thread_id, $assistant_id, $combined_text );

	    // Log the response immediately after running the thread.
	    error_log( '[INFO] Final summary response from assistant after cumulative run: ' . print_r( $final_summary_response, true ) );

	    // Check if the assistant returned any error message or invalid format.
	    if ( ! is_array( $final_summary_response ) || empty( $final_summary_response ) ) {
	        // error_log( '[ERROR] Cumulative summary run did not return a valid response. Check thread execution.' );
	        return 'Failed to generate final summary. Invalid response format or empty response.';
	    }

	    // Fetch the messages from the thread to confirm the summary.
	    $final_summary = $this->fetch_messages_from_thread( $api_key, $thread_id );

	    // Log the fetched summary for debugging.
	    error_log( '[INFO] Fetched cumulative summary after thread completion: ' . print_r( $final_summary, true ) );

	    // Check if the summary is in the expected format and return it.
	    if ( is_array( $final_summary ) && isset( $final_summary['summary'] ) && is_array( $final_summary['summary'] ) ) {
	        // error_log( '[INFO] Final cumulative summary retrieved successfully: ' . print_r( $final_summary['summary'], true ) );
	        return $final_summary['summary'];
	    }

	    // If not in expected format, return an error message.
	    // error_log( '[ERROR] Final cumulative summary retrieval failed or returned unexpected format.' );
	    return 'Failed to generate final summary. Invalid response format.';
	}

}
