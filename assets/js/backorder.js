jQuery(function($){
    function atualizarCheckbox(variationId = null){
        const qty = parseInt($('input.qty').val()) || 1;
        $.post(wcBackorder.ajax_url, {
            action: 'verificar_backorder',
            nonce: wcBackorder.nonce,
            product_id: wcBackorder.product_id,
            variation_id: variationId,
            quantidade: qty
        }, function(res){
            const box = $('#sob-encomenda-checkbox');
            const checkbox = box.find('input[name="aceita_sob_encomenda"]');
            if(res.backorder){
                box.show();
                if(checkbox.length){ checkbox.prop('required', true); }
            } else {
                box.hide();
                if(checkbox.length){ checkbox.prop('required', false); }
            }
        });
    }

    $('form.variations_form').on('found_variation', function(_, variation){
        atualizarCheckbox(variation.variation_id);
    }).on('reset_data', function(){
        $('#sob-encomenda-checkbox').hide().find('input').prop('required', false);
    });

    let debounceTimer;
    $('input.qty').on('change keyup input', function(){
        clearTimeout(debounceTimer);
        const variationId = $('input.variation_id').val() || null;
        debounceTimer = setTimeout(function(){
            atualizarCheckbox(variationId);
        }, 300);
    });

    atualizarCheckbox();
});
