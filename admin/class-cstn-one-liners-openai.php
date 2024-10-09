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

	public function cstn_process_all_entries_test() {
	    $api_key         = get_option( 'cstn_one_liners_api_key' );
	    $assistant_id    = get_option( 'cstn_one_liners_assistant_id' );
		$thread_id = $this->create_thread( $api_key ); 
		$summary_response = $this->generate_final_cumulative_summary($api_key, $assistant_id, $thread_id);

		// Prepare final response
		$final_response = array(
		    'success' => true,
		    'entry_statuses' => "1",
		    'final_summary' => $summary_response,
		    'partial' => false
		);

		error_log('[INFO] Final response prepared: ' . print_r($final_response, true));
		wp_send_json($final_response);
	}

	public function cstn_process_all_entries() {
	    error_log('[INFO] Starting cstn_process_all_entries AJAX handler.');

	    ini_set('max_execution_time', 300);
	    ini_set('memory_limit', '256M');

	    // Verify nonce
	    if (!isset($_POST['security']) || !check_ajax_referer('cstn_ajax_nonce', 'security', false)) {
	        error_log('[ERROR] Security check failed.');
	        wp_send_json(array(
	            'success' => false,
	            'message' => 'Security verification failed. Please refresh the page and try again.'
	        ));
	        return;
	    }

	    // Validate entry IDs
	    $entry_ids = isset($_POST['entry_ids']) ? $_POST['entry_ids'] : array();
	    if (empty($entry_ids) || !is_array($entry_ids)) {
	        error_log('[ERROR] No entry IDs provided or invalid format.');
	        wp_send_json(array(
	            'success' => false,
	            'message' => 'No entry IDs provided or invalid format.'
	        ));
	        return;
	    }

	    // Validate API settings
	    $api_key = get_option('cstn_one_liners_api_key');
	    $assistant_id = get_option('cstn_one_liners_assistant_id');
	    $vector_store_id = get_option('cstn_one_liners_vector_store_id');

	    if (empty($api_key) || empty($vector_store_id) || empty($assistant_id)) {
	        error_log('[ERROR] Missing API configuration.');
	        wp_send_json(array(
	            'success' => false,
	            'message' => 'API configuration missing. Please check settings.'
	        ));
	        return;
	    }

	    $entry_statuses = array();

	    // Process entries
	    foreach ($entry_ids as $entry_id) {
	        error_log("[INFO] Processing entry ID: $entry_id");

	        $entry = GFAPI::get_entry($entry_id);
	        if (is_wp_error($entry)) {
	            error_log("[ERROR] Failed to retrieve entry $entry_id: " . $entry->get_error_message());
	            $entry_statuses[$entry_id] = 'Failed to retrieve entry';
	            continue;
	        }

	        // Generate entry text
	        $entry_text = sprintf(
	            "What brought you to Centerstone?\n%s\n\nHow does Centerstone support the community?\n%s\n\nIn a word, what does Noble Purpose mean to you?\n%s",
	            isset($entry[1]) ? $entry[1] : '',
	            isset($entry[3]) ? $entry[3] : '',
	            isset($entry[4]) ? $entry[4] : ''
	        );

	        // Generate and store embedding
	        $embedding = Cstn_One_Liners_Vectors::generate_vector_for_text($api_key, $entry_text);
	        if (is_wp_error($embedding)) {
	            error_log("[ERROR] Failed to generate embedding for entry $entry_id: " . $embedding->get_error_message());
	            $entry_statuses[$entry_id] = 'Failed to generate embedding';
	            continue;
	        }

	        $storage_result = Cstn_One_Liners_Vectors::store_vector_in_vector_store(
	            $api_key,
	            $vector_store_id,
	            $embedding,
	            $entry_id,
	            $entry_text
	        );

	        if (is_wp_error($storage_result)) {
	            error_log("[ERROR] Failed to store vector for entry $entry_id: " . $storage_result->get_error_message());
	            $entry_statuses[$entry_id] = 'Failed to store vector';
	            continue;
	        }

	        $entry_statuses[$entry_id] = 'Complete';
	    }

	    // Generate final summary
	    error_log('[INFO] Generating final cumulative summary.');
	    $thread_id = $this->create_thread($api_key);
	    $summary_response = $this->generate_final_cumulative_summary($api_key, $assistant_id, $thread_id);

	    // Prepare final response
	    $final_response = array(
	        'success' => true,
	        'entry_statuses' => $entry_statuses,
	        'final_summary' => $summary_response,
	        'partial' => false
	    );

	    error_log('[INFO] Final response prepared: ' . print_r($final_response, true));

	    // Send the final response as a single JSON object
	    wp_send_json($final_response);
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
				'timeout' => 30, // Set the timeout to 30 seconds (or more if needed)
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

	public function add_message_and_run_thread( $api_key, $thread_id, $assistant_id, $query ) {
	    $message_api_url = "https://api.openai.com/v1/threads/{$thread_id}/messages";
	    $body            = wp_json_encode(
	        array(
	            'role'    => 'user',
	            'content' => $query,
	        )
	    );

	    error_log( '[INFO] Sending message to thread. URL: ' . $message_api_url );
	    error_log( '[INFO] Message body: ' . $body );

	    $response        = wp_remote_post(
	        $message_api_url,
	        array(
	            'headers' => array(
	                'Content-Type'  => 'application/json',
	                'Authorization' => 'Bearer ' . $api_key,
	                'OpenAI-Beta'   => 'assistants=v2',
	            ),
	            'body'    => $body,
	            'timeout' => 30, // Set the timeout to 30 seconds (or more if needed)
	        )
	    );

	    if ( is_wp_error( $response ) ) {
	        error_log( '[ERROR] Failed to add message. Error: ' . $response->get_error_message() );
	        return 'Failed to add message.';
	    }

	    $response_body = wp_remote_retrieve_body( $response );
	    error_log( '[INFO] Response from adding message: ' . $response_body );

	    // Step 4: Run the thread.
	    $run_api_url = "https://api.openai.com/v1/threads/{$thread_id}/runs";
	    $body        = wp_json_encode(
	        array(
	            'assistant_id' => $assistant_id,
	        )
	    );
	    
	    error_log( '[INFO] Running thread. URL: ' . $run_api_url );
	    error_log( '[INFO] Run body: ' . $body );

	    $response    = wp_remote_post(
	        $run_api_url,
	        array(
	            'headers' => array(
	                'Content-Type'  => 'application/json',
	                'Authorization' => 'Bearer ' . $api_key,
	                'OpenAI-Beta'   => 'assistants=v2',
	            ),
	            'body'    => $body,
	            'timeout' => 30, // Set the timeout to 30 seconds (or more if needed)
	        )
	    );

	    if ( is_wp_error( $response ) ) {
	        error_log( '[ERROR] Failed to run thread. Error: ' . $response->get_error_message() );
	        return 'Failed to run thread.';
	    }

	    $response_body    = wp_remote_retrieve_body( $response );
	    $decoded_response = json_decode( $response_body, true );

	    error_log( '[INFO] Response from running thread: ' . print_r( $decoded_response, true ) );

	    if ( 'queued' === $decoded_response['status'] || 'running' === $decoded_response['status'] ) {
	        error_log( '[INFO] Assistant is processing the request. Status: ' . $decoded_response['status'] );
	        return $this->wait_for_run_completion( $api_key, $decoded_response['id'], $thread_id );
	    } elseif ( 'completed' === $decoded_response['status'] ) {
	        error_log( '[INFO] Run completed. Fetching messages from thread.' );
	        return $this->fetch_messages_from_thread( $api_key, $thread_id );
	    } else {
	        error_log( '[ERROR] Run failed or was cancelled. Response: ' . print_r( $decoded_response, true ) );
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
	private function wait_for_run_completion($api_key, $run_id, $thread_id) {
	    $status_check_url = "https://api.openai.com/v1/threads/{$thread_id}/runs/{$run_id}";
	    error_log('[INFO] Checking run status at URL: ' . $status_check_url);

	    $attempts = 0;
	    $max_attempts = 20;

	    while ($attempts < $max_attempts) {
	        sleep(5);
	        $response = wp_remote_get(
	            $status_check_url,
	            array(
	                'headers' => array(
	                    'Authorization' => 'Bearer ' . $api_key,
	                    'OpenAI-Beta'   => 'assistants=v2',
	                ),
	            )
	        );

	        if (is_wp_error($response)) {
	            $error_message = $response->get_error_message();
	            error_log('[ERROR] Failed to check run status: ' . $error_message);
	            return 'Failed to check run status: ' . $error_message;
	        }

	        $response_code = wp_remote_retrieve_response_code($response);
	        if ($response_code !== 200) {
	            error_log('[ERROR] Non-200 response code: ' . $response_code);
	            error_log('[ERROR] Response body: ' . wp_remote_retrieve_body($response));
	            return 'Error checking run status. Response code: ' . $response_code;
	        }

	        $response_body = wp_remote_retrieve_body($response);
	        $decoded_response = json_decode($response_body, true);

	        error_log('[DEBUG] Run status response: ' . print_r($decoded_response, true));

	        if (!isset($decoded_response['status'])) {
	            error_log('[ERROR] Status field missing in response');
	            return 'Invalid response format from run status check';
	        }

	        switch ($decoded_response['status']) {
	            case 'completed':
	                error_log('[INFO] Run completed successfully');
	                return $this->fetch_messages_from_thread($api_key, $thread_id);
	            
	            case 'failed':
	            case 'cancelled':
	                error_log('[ERROR] Run ' . $decoded_response['status']);
	                return 'Run ' . $decoded_response['status'];
	            
	            case 'requires_action':
	                error_log('[INFO] Run requires action');
	                return $this->handle_requires_action($api_key, $run_id, $thread_id, $decoded_response['required_action']);
	            
	            default:
	                error_log('[INFO] Run still in progress. Status: ' . $decoded_response['status']);
	        }

	        $attempts++;
	    }

	    error_log('[ERROR] Run did not complete within the maximum number of attempts');
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
	    error_log('[INFO] Handling requires_action: ' . print_r($required_action, true));

	    if ( 'submit_tool_outputs' === $required_action['type'] ) {
	        $tool_calls   = $required_action['submit_tool_outputs']['tool_calls'];
	        $tool_outputs = array();

	        foreach ( $tool_calls as $tool_call ) {
	            // Log each tool call for debugging
	            error_log('[INFO] Handling tool call: ' . print_r($tool_call, true));

	            // Customize the tool output based on tool_call requirements
	            $output = wp_json_encode( array( 'success' => 'true', 'message' => 'Output for ' . $tool_call['id'] ) );

	            $tool_outputs[] = array(
	                'tool_call_id' => $tool_call['id'],
	                'output'       => $output,
	            );
	        }

	        error_log('[INFO] Prepared tool outputs: ' . print_r($tool_outputs, true));

	        $submit_tool_outputs_url = "https://api.openai.com/v1/threads/{$thread_id}/runs/{$run_id}/submit_tool_outputs";
	        
	        // Increase the timeout to 30 seconds for this request
	        $response = wp_remote_post(
	            $submit_tool_outputs_url,
	            array(
	                'headers' => array(
	                    'Authorization' => 'Bearer ' . $api_key,
	                    'OpenAI-Beta'   => 'assistants=v2',
	                    'Content-Type'  => 'application/json',
	                ),
	                'body'    => wp_json_encode( array( 'tool_outputs' => $tool_outputs ) ),
	                'timeout' => 30, // Set the timeout to 30 seconds (or more if needed)
	            )
	        );

	        if ( is_wp_error( $response ) ) {
	            error_log('[ERROR] Failed to submit tool outputs. Error: ' . $response->get_error_message());
	            return 'Failed to submit tool outputs.';
	        }

	        error_log('[INFO] Tool outputs submitted successfully.');

	        return $this->wait_for_run_completion( $api_key, $run_id, $thread_id );
	    }

	    error_log('[ERROR] Unhandled requires_action: ' . print_r($required_action, true));
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
	public function fetch_messages_from_thread($api_key, $thread_id) {
	    $messages_url = "https://api.openai.com/v1/threads/{$thread_id}/messages";
	    error_log('[INFO] Fetching messages from thread. URL: ' . $messages_url);

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

	    if (is_wp_error($response)) {
	        $error_message = $response->get_error_message();
	        error_log('[ERROR] Failed to fetch messages: ' . $error_message);
	        return 'Failed to fetch messages: ' . $error_message;
	    }

	    $response_code = wp_remote_retrieve_response_code($response);
	    if ($response_code !== 200) {
	        error_log('[ERROR] Non-200 response code when fetching messages: ' . $response_code);
	        error_log('[ERROR] Response body: ' . wp_remote_retrieve_body($response));
	        return 'Error fetching messages. Response code: ' . $response_code;
	    }

	    $response_body = wp_remote_retrieve_body($response);
	    $decoded_response = json_decode($response_body, true);

	    error_log('[DEBUG] Messages response: ' . print_r($decoded_response, true));

	    if (!isset($decoded_response['data']) || empty($decoded_response['data'])) {
	        error_log('[ERROR] No messages found in response');
	        return 'No messages found in thread response.';
	    }

	    $messages = $decoded_response['data'];
	    foreach ($messages as $message) {
	        if ($message['role'] === 'assistant' && !empty($message['content'])) {
	            foreach ($message['content'] as $content) {
	                if ($content['type'] === 'text') {
	                    $text_value = $content['text']['value'];
	                    error_log('[DEBUG] Processing message text: ' . $text_value);
	                    
	                    // Try to extract JSON from code block if present
	                    if (preg_match('/```json\s*(.*?)\s*```/s', $text_value, $matches)) {
	                        $json_string = $matches[1];
	                    } else {
	                        $json_string = $text_value;
	                    }
	                    
	                    $decoded_json = json_decode($json_string, true);
	                    if (json_last_error() === JSON_ERROR_NONE && isset($decoded_json['summary'])) {
	                        error_log('[INFO] Successfully extracted summary');
	                        return $decoded_json;
	                    }
	                }
	            }
	        }
	    }

	    error_log('[ERROR] No valid summary found in messages');
	    return 'Failed to find valid summary in messages.';
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
	 * Generate the final cumulative summary using the vector store.
	 *
	 * @param string $api_key The OpenAI API key.
	 * @param string $assistant_id The assistant ID.
	 * @param string $thread_id The thread ID.
	 * @return array|string The final summary array or an error message string.
	 */
	private function generate_final_cumulative_summary($api_key, $assistant_id, $thread_id) {
	    $minimal_trigger_message = "true";
	    error_log('[INFO] Sending minimal trigger message to assistant for cumulative summary.');

	    // Send the message and get the response
	    $response = $this->add_message_and_run_thread($api_key, $thread_id, $assistant_id, $minimal_trigger_message);
	    error_log('[DEBUG] Full response from assistant: ' . print_r($response, true));

	    // Fetch messages from the thread
	    $messages_url = "https://api.openai.com/v1/threads/{$thread_id}/messages";
	    $fetch_response = wp_remote_get(
	        $messages_url,
	        array(
	            'headers' => array(
	                'Authorization' => 'Bearer ' . $api_key,
	                'Content-Type'  => 'application/json',
	                'OpenAI-Beta'   => 'assistants=v2',
	            ),
	        )
	    );

	    if (is_wp_error($fetch_response)) {
	        error_log('[ERROR] Failed to fetch messages: ' . $fetch_response->get_error_message());
	        return 'Failed to fetch messages.';
	    }

	    $response_body = wp_remote_retrieve_body($fetch_response);
	    $messages = json_decode($response_body, true);

	    if (!isset($messages['data']) || empty($messages['data'])) {
	        error_log('[ERROR] No messages found in thread.');
	        return 'No messages found in thread.';
	    }

	    // Look for the JSON response in the latest assistant message
	    foreach ($messages['data'] as $message) {
	        if ($message['role'] === 'assistant') {
	            foreach ($message['content'] as $content) {
	                if ($content['type'] === 'text') {
	                    $text_value = $content['text']['value'];
	                    
	                    // Extract JSON from the text if it's wrapped in ```json
	                    if (preg_match('/```json\s*(.*?)\s*```/s', $text_value, $matches)) {
	                        $json_string = $matches[1];
	                    } else {
	                        $json_string = $text_value;
	                    }

	                    $decoded_json = json_decode($json_string, true);
	                    
	                    if (json_last_error() === JSON_ERROR_NONE && isset($decoded_json['summary'])) {
	                        error_log('[INFO] Successfully extracted summary: ' . print_r($decoded_json['summary'], true));
	                        return $decoded_json['summary'];
	                    }
	                }
	            }
	        }
	    }

	    error_log('[ERROR] No valid summary found in messages');
	    return 'Failed to generate final summary. Could not find summary in response.';
	}


}
