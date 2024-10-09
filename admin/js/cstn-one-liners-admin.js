(function($) {
    'use strict';

    jQuery(document).ready(function($) {
        // Detect the active tab based on the URL query parameter.
        let urlParams = new URLSearchParams(window.location.search);
        let activeTab = urlParams.get('tab') || 'api_config';

        // Listen for changes on the form submit button.
        $('form#one-liners-save').on('submit', function(event) {
            event.preventDefault(); // Prevent default only for this specific form submission
            console.log('Form submit detected and prevented.');

            if (activeTab === 'api_config') {
                let apiKey = $('#cstn_one_liners_api_key').val();
                let assistantId = $('#cstn_one_liners_assistant_id').val();

                console.log('Performing validation and API test for:', { apiKey, assistantId });

                // Perform validation and test the API Key and Assistant ID.
                $.post(ajaxurl, {
                    action: 'cstn_test_api_and_assistant', // Action name must match the PHP hook.
                    api_key: apiKey,
                    assistant_id: assistantId
                }, function(response) {
                    if (response.success) {
                        alert('Settings saved successfully and API Key and Assistant ID are valid!');
                    } else {
                        alert('Settings saved, but there was an issue with the API Key or Assistant ID: ' + response.data);
                    }
                    // Submit the form after validation.
                    $('form').off('submit').submit();
                }).fail(function(xhr, status, error) {
                    // Log the error message for debugging.
                    console.log('AJAX Request Failed:', error);
                    console.log('Status:', status);
                    console.log('XHR:', xhr);
                    alert('There was an error during the validation. Please try again.');
                });
            } else {
                // Directly submit the form if we are not on the API Configuration tab.
                $('form').off('submit').submit();
            }
        });

        // Get the nonce value from the localized script variables.
        let securityNonce = cstn_one_liners_vars.cstn_ajax_nonce;

        retrieveEntries(); // Run on page load if needed.

        // Set up the click handler for the button.
        $('#retrieve_entries').on('click', function() {
            retrieveEntries(); // Run when button is clicked.
        });

        // Define a reusable function to retrieve entries.
        function retrieveEntries() {
            var formId = $('#cstn_one_liners_form_id').val();

            // Log the Form ID for debugging.
            console.log('Form ID: ' + formId);

            // AJAX request to retrieve entries.
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cstn_retrieve_entries',
                    form_id: formId,
                    security: securityNonce, // Add the nonce value here.
                },
                success: function(response) {
                    if (response.success) {
                        $('#gf_entries_display').html(response.data);
                        $('#process_entries').show();
                    } else {
                        alert('Failed to retrieve entries: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    // Log the error message for debugging.
                    console.log('AJAX Request Failed:', error);
                    console.log('Status:', status);
                    console.log('XHR:', xhr);
                }
            });
        }

        $('#process_entries').on('click', function () {
            $('#assistant_response').html('<p>Processing entries, please wait...</p>');

            var entryIds = [];
            $('.entry-status').each(function () {
                var entryId = $(this).closest('tr').data('entry-id');
                entryIds.push(entryId);
            });

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cstn_process_entries',
                    security: securityNonce,
                    entry_ids: entryIds
                },
                beforeSend: function() {
                    console.log("AJAX request being sent. Data:", {
                        action: 'cstn_process_entries',
                        security: securityNonce,
                        entry_ids: entryIds
                    }); // Log AJAX data before sending
                    updateAllStatuses('Processing...');
                },
                success: function(response) {
                    console.log("Full response received from server:", response); // Log the complete response from the server

                    // Check if response is already an object, if so skip parsing
                    if (typeof response === 'string') {
                        try {
                            response = JSON.parse(response);
                        } catch (e) {
                            console.error("Failed to parse JSON response:", e);
                            return; // Exit the function if parsing fails
                        }
                    }

                    if (response.success) {
                        var entryStatuses = response.entry_statuses || {};
                        var finalSummary = response.final_summary;

                        console.log("Final summary received:", finalSummary); // Log the final summary value

                        // Update statuses on the UI
                        for (var entryId in entryStatuses) {
                            updateEntryStatus(entryId, entryStatuses[entryId]);
                        }

                        // Display the final summary if it exists
                        if (finalSummary && Array.isArray(finalSummary)) {
                            console.log("Valid final summary format. Rendering summary."); // Log if finalSummary is valid and is an array

                            // Properly format and display the final summary
                            var formattedSummary = '<div class="final-summary">';
                            formattedSummary += '<h3>Final Summary</h3>';
                            formattedSummary += '<ol>';
                            finalSummary.forEach(function(sentence) {
                                console.log("Adding sentence to summary:", sentence); // Log each sentence being added to the formatted summary
                                formattedSummary += '<li>' + sentence + '</li>';
                            });
                            formattedSummary += '</ol>';
                            formattedSummary += '</div>';
                            $('#assistant_response').html(formattedSummary);
                        } else {
                            console.error('Invalid or missing summary format:', finalSummary); // Log if summary is invalid or missing
                            $('#assistant_response').html('<p>Error: No valid summary found.</p>');
                        }
                    } else {
                        console.error('Response data error:', response.data); // Log error if response is not successful
                        alert('Failed to process entries: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error: ', error); // Log any AJAX errors
                    $('#assistant_response').html('<p>Error: ' + error + '</p>');
                }
            });

        });

        function updateAllStatuses(status) {
            $('.entry-status').each(function() {
                $(this).text(status);
            });
        }

        function updateEntryStatus(entryId, status) {
            console.log('Updating status for entry ID:', entryId, 'with status:', status);
            $('#entry-' + entryId).find('.entry-status').text(status);
        }




    });


})(jQuery);