jQuery(document).ready(function ($) {
    const actionField = $('#expiry_action');
    const redirectField = $('#expiry_redirect_field');
    const messageField = $('#expiry_message_field');

    function toggleFields() {
        const val = actionField.val();

        // Hide both by default
        redirectField.hide();
        messageField.hide();

        // Show based on selected action
        if (val === 'redirect') {
            redirectField.show();
        } else if (val === 'replace') {
            messageField.show();
        }
    }

    // Run once on load and again on change
    toggleFields();
    actionField.on('change', toggleFields);
});
