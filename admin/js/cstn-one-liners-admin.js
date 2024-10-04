(function($) {
    'use strict';

    jQuery(document).ready(function($) {
        // Detect the active tab based on the URL query parameter.
        let urlParams = new URLSearchParams(window.location.search);
        let activeTab = urlParams.get('tab') || 'api_config';

        // Listen for changes on the form submit button.
        $('form').on('submit', function(event) {
            event.preventDefault();

            // Save settings and perform validation only on the API Configuration tab.
            if (activeTab === 'api_config') {
                let apiKey = $('#cstn_one_liners_api_key').val();
                let assistantId = $('#cstn_one_liners_assistant_id').val();

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

        $('#retrieve_entries').on('click', function() {
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
                        // console.log(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    // Log the error message for debugging.
                    console.log('AJAX Request Failed:', error);
                    console.log('Status:', status);
                    console.log('XHR:', xhr);
                }
            });
        });

        $('#process_entries').on('click', function() {
            // Send the AJAX request to process entries in batches.
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'cstn_process_entries',
                    security: securityNonce,
                },
                success: function(response) {
                    if (response.success) {
                        // Get the final summary array from the response.
                        var finalSummary = response.data.final_summary;

                        // Check if finalSummary exists and is an array.
                        if (Array.isArray(finalSummary)) {
                            // Create HTML to display each sentence separately.
                            var formattedSummary = '<div class="final-summary">';
                            formattedSummary += '<h3>Final Summary</h3>';
                            formattedSummary += '<ol>'; // Use an ordered list to show each sentence in order.
                            
                            // Loop through each sentence and format it.
                            finalSummary.forEach(function(sentence) {
                                formattedSummary += '<li>' + sentence + '</li>';
                            });
                            
                            formattedSummary += '</ol>';
                            formattedSummary += '</div>';
                            
                            // Display the formatted summary in the #assistant_response div.
                            $('#assistant_response').html(formattedSummary);
                        } else {
                            // If finalSummary is not valid, show an error message.
                            $('#assistant_response').html('<p>Error: Unable to retrieve final summary.</p>');
                        }
                    } else {
                        console.log(response.data);
                        alert('Failed to process entries: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.log('AJAX Error: ', error);
                }
            });
        });

    });


})(jQuery);