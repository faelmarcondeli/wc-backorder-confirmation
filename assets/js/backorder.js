;(function($){
    function updateCheckbox(variationId) {
        var qty = parseInt($('input.qty').val(), 10) || 1;
        $.post(wc_backorder.ajax_url, {
            action: 'wc_check_backorder',
            product_id: wc_backorder.product_id,
            variation_id: variationId,
            quantity: qty
        }, function(res) {
            var box = $('#wc-backorder-checkbox');
            var checkbox = box.find('input[name="accept_backorder"]');
            if (res.success && res.data.backorder) {
                box.show();
                checkbox.prop('required', true);
            } else {
                box.hide();
                checkbox.prop('required', false);
            }
        });
    }

    $(document).ready(function(){
        var form = $('form.variations_form');
        if (form.length) {
            form.on('found_variation', function(event, variation) {
                updateCheckbox(variation.variation_id);
            }).on('reset_data', function() {
                $('#wc-backorder-checkbox').hide().find('input').prop('required', false);
            });
        }
        $('input.qty').on('change keyup input', function(){
            var variationId = $('input.variation_id').val() || null;
            updateCheckbox(variationId);
        });
    });
})(jQuery);
