jQuery(document).ready(function($){
    // Intercept form submission.
    $('#tpi-feed-form').on('submit', function(e){
        e.preventDefault();
        var formData = $(this).serialize();
        // Disable the submit button.
        $('#tpi-feed-submit').prop('disabled', true);
        // Send initial AJAX request to save feed settings.
        $.post(tpi_ajax.ajaxurl, formData + "&action=tpi_import_feed_ajax&nonce=" + tpi_ajax.nonce, function(response){
            if(response.success){
                var feed_id = response.data.feed_id;
                $('#tpi-progress').show();
                // Increase chunk size to 10 items per request for improved performance.
                importChunks(feed_id, 0, 10);
            } else {
                alert("Error initializing feed import.");
                $('#tpi-feed-submit').prop('disabled', false);
            }
        });
    });

    function importChunks(feed_id, offset, limit) {
        $.post(tpi_ajax.ajaxurl, {
            action: 'tpi_import_feed_progress',
            nonce: tpi_ajax.nonce,
            feed_id: feed_id,
            offset: offset,
            limit: limit
        }, function(response){
            if(response.success){
                var new_offset = response.data.new_offset;
                var total = response.data.total;
                var percent = Math.min((new_offset / total) * 100, 100);
                $('#tpi-progress-bar').css('width', percent + '%');
                $('#tpi-progress-text').text("Imported " + new_offset + " of " + total);
                if(!response.data.done) {
                    importChunks(feed_id, new_offset, limit);
                } else {
                    // Build popup HTML using the summary function.
                    var popupHTML = tpi_buildPopupHTML(response.data.results, feed_id, total);
                    $('#tpi-popup').html(popupHTML);
                    $('#tpi-popup-overlay').show();
                    $('#tpi-feed-submit').prop('disabled', false);
                    $('#tpi-progress').hide();
                }
            } else {
                alert("Error importing feed chunk: " + response.data);
                $('#tpi-feed-submit').prop('disabled', false);
            }
        });
    }

    /**
     * Build a summary popup HTML.
     * Displays a large green tick with margins, the import type (full or date-wise import),
     * and a message summarizing the number of posts imported out of the total eligible posts.
     *
     * @param {Array} results The array of import results.
     * @param {String} feed_id The unique feed identifier.
     * @param {Number} total The total number of eligible items.
     * @return {String} The HTML string for the popup.
     */
    function tpi_buildPopupHTML(results, feed_id, total) {
        var successCount = 0;
        if(results && results.length > 0) {
            for (var i = 0; i < results.length; i++) {
                if(results[i].status === 'success'){
                    successCount++;
                }
            }
        }
        // Determine the import type based on the value of the import date field.
        var importDateValue = $('#import_date').val();
        var importType = ( importDateValue === "" )
            ? "Full import"
            : "Date-wise import from " + importDateValue;
        
        var html = '<div style="text-align:center; padding:20px;">';
        // Add additional top and bottom margin to the tick icon.
        html += '<div style="font-size:64px; color:green; margin:20px 0;">&#10004;</div>'; // Green tick icon.
        html += '<h3 style="margin:10px 0;">Import Completed Successfully</h3>';
        html += '<p>' + importType + '</p>';
        html += '<p>Imported ' + successCount + ' post' + (successCount !== 1 ? 's' : '') +
                ' out of ' + total + ' eligible post' + (total !== 1 ? 's' : '') + '.</p>';
        html += '<button id="tpi-popup-close" style="margin-top:20px; padding:10px 20px;">Close</button>';
        html += '</div>';
        return html;
    }

    // Handler for closing the popup.
    $(document).on('click', '#tpi-popup-close', function(){
        $('#tpi-popup-overlay').hide();
    });
});
