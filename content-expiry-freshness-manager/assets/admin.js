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








// -----------------------------------
// wocommerce product hide/show functionality
// ----------------------------------

jQuery(document).ready(function ($) {

    // Read saved option (passed from PHP)
    const cefmEnableExpiry = cefmAdminData.enableExpiry;  

    // Detect WooCommerce product edit screen
    const isWooProduct = $("body.post-type-product").length > 0;

    // If not a product page → DO NOTHING
    if (!isWooProduct) {
        return;
    }

    // Target the meta box (must exist)
    const $expiryMetaBox = $('#cefm_expiry_box');

    // Apply visibility based on plugin setting
    if (!cefmEnableExpiry) {
        $expiryMetaBox.hide();
    } else {
        $expiryMetaBox.show();
    }

    /**
     * OPTIONAL:
     * If checkbox exists on settings page,
     * toggle the meta box instantly.
     */
    $('#cefm_wc_enable_product_expiry').on('change', function () {

        if (!isWooProduct) return;

        if ($(this).is(':checked')) {
            $expiryMetaBox.show();
        } else {
            $expiryMetaBox.hide();
        }
    });
});
