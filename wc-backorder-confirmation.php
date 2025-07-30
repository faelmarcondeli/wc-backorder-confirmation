<?php
/*
Plugin Name: Woocommerce Pedido Sob Encomenda
Description: Confirmação de backorder no produto, validação de “sob encomenda ao adicionar ao carrinho, e notificações no carrinho e no pedido.
Version: 1.4.2
Author: Rafael Moreno   
Text Domain: wc-backorder-confirmation
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // evita acesso direto
}

// 1) Carrega text domain e registra assets
add_action( 'init', function() {
    load_plugin_textdomain( 'wc-backorder-confirmation', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/**
 * Enfileira scripts e estilos somente na página de produto.
 */
function wcbc_enqueue_assets() {
    if ( is_product() ) {
        wp_enqueue_style( 'wcbc-backorder', plugins_url( 'assets/css/backorder.css', __FILE__ ), [], '1.0' );
        wp_enqueue_script( 'wcbc-backorder', plugins_url( 'assets/js/backorder.js', __FILE__ ), [ 'jquery' ], '1.0', true );
        wp_localize_script( 'wcbc-backorder', 'wcBackorder', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'verificar_backorder_nonce' ),
            'product_id' => get_the_ID(),
        ] );
    }
}
add_action( 'wp_enqueue_scripts', 'wcbc_enqueue_assets' );

// 2) Hooks de validação e renderização só na página de produto
add_action( 'template_redirect', function() {
    if ( is_product() ) {
        add_filter( 'woocommerce_add_to_cart_validation', 'validar_sob_encomenda', 10, 3 );
        // Mudar hook para depois do botão de add to cart
        add_action( 'woocommerce_after_add_to_cart_button', 'renderizar_checkbox_sob_encomenda' );
    }
} );

/**
 * 3) Validação no backend ao adicionar ao carrinho
 */
function validar_sob_encomenda( $passed, $product_id, $quantity ) {
    $id = ( isset( $_POST['variation_id'] ) && absint( $_POST['variation_id'] ) > 0 )
        ? absint( $_POST['variation_id'] )
        : $product_id;

    $product = wc_get_product( $id );
    if ( ! $product ) {
        return false;
    }

    $stock_qty          = $product->get_stock_quantity();
    $permite_encomenda  = $product->backorders_allowed();
    $gerencia_estoque   = $product->managing_stock();
    $ultrapassa_estoque = ( $stock_qty !== null && $quantity > $stock_qty );

    $aceita_sob_encomenda = isset( $_POST['aceita_sob_encomenda'] )
        && filter_var( wp_unslash( $_POST['aceita_sob_encomenda'] ), FILTER_VALIDATE_BOOLEAN );

    if ( $gerencia_estoque && $permite_encomenda && $ultrapassa_estoque && ! $aceita_sob_encomenda ) {
        wc_add_notice( __( 'Você deve confirmar que aceita o prazo de encomenda para este produto.', 'wc-backorder-confirmation' ), 'error' );
        return false;
    }

    return $passed;
}

/**
 * 4) Renderiza o checkbox “Sob Encomenda” após o botão de adicionar ao carrinho
 */
function renderizar_checkbox_sob_encomenda() {
    global $product;

    // Exibe o bloco Flatsome, inicialmente oculto
    echo '<div id="sob-encomenda-checkbox" style="display:none;">';
    $html = get_transient( 'wcbc_aviso_encomenda' );
    if ( false === $html ) {
        $html = do_shortcode( '[block id="aviso-encomenda"]' );
        set_transient( 'wcbc_aviso_encomenda', $html, DAY_IN_SECONDS );
    }
    echo $html;
    echo '</div>';
}


add_action( 'wp_ajax_verificar_backorder', 'verificar_backorder_ajax' );
add_action( 'wp_ajax_nopriv_verificar_backorder', 'verificar_backorder_ajax' );

function verificar_backorder_ajax() {
    check_ajax_referer( 'verificar_backorder_nonce', 'nonce' );

    $product_id   = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
    $variation_id = isset( $_POST['variation_id'] ) ? intval( $_POST['variation_id'] ) : 0;
    $qty          = isset( $_POST['quantidade'] ) ? intval( $_POST['quantidade'] ) : 1;

    $product = $variation_id ? wc_get_product($variation_id) : wc_get_product($product_id);
    $backorder = false;

    if ($product && $product->managing_stock() && $product->backorders_allowed()) {
        $stock = $product->get_stock_quantity();
        // Só exibe o aviso se o estoque não for nulo e a quantidade for maior que o estoque disponível
        if ($stock !== null && $qty > $stock) {
            $backorder = true;
        }
    }

    wp_send_json(['backorder' => $backorder]);
}


/**
 * 5) Helpers para cupom “AMOSTRAS” e notificações de backorder
 */
// 5.1 Verifica cupom amostras”
function wc_has_sample_coupon() {
    if ( ! WC()->cart ) {
        return false;
    }
    return in_array( 'amostras', WC()->cart->get_applied_coupons(), true );
}
// 5.2 Detecta backorder que requer notificaão
function wc_is_on_backorder_require_notification( WC_Product $product, $quantity = 1 ) {
    if ( wc_has_sample_coupon() ) {
        return false;
    }
    return $product->backorders_require_notification()
        && $product->is_on_backorder( $quantity );
}
// 5.3 Texto da notificação
function wc_backorder_notice_text() {
    return __( 'Estará disponível entre 4 a 15 dias úteis', 'wc-backorder-confirmation' );
}
// 5.4 HTML da notificação
function wc_backorder_notice_html() {
    return '<p class="backorder_notification backorder_notification_custom">'
         . wc_backorder_notice_text()
         . '</p>';
}

// 5.5) Exibe aviso no carrinho, abaixo do nome do item
add_action( 'woocommerce_after_cart_item_name', function( $cart_item, $cart_item_key ) {
    $product  = $cart_item['data'];
    $quantity = $cart_item['quantity'];

    if ( wc_is_on_backorder_require_notification( $product, $quantity ) ) {
        echo wc_backorder_notice_html();
    }
}, 10, 2 );

// 6) Registra o email personalizado
add_filter( 'woocommerce_email_classes', function( $email_classes ) {
    require_once __DIR__ . '/includes/class-wc-email-encomenda.php';
    $email_classes['WC_Email_Encomenda'] = new WC_Email_Encomenda();
    return $email_classes;
} );

// 7) Marca o item no carrinho
add_filter( 'woocommerce_add_cart_item_data', function( $cart_item, $product_id ) {
    if ( ! empty( $_POST['aceita_sob_encomenda'] ) && filter_var( wp_unslash( $_POST['aceita_sob_encomenda'] ), FILTER_VALIDATE_BOOLEAN ) ) {
        $cart_item['aceita_sob_encomenda'] = true;
        $cart_item['unique_key'] = md5( microtime() . rand() );
    }
    return $cart_item;
}, 10, 2 );

// 7.1) Salva flag no pedido
add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
    foreach ( WC()->cart->get_cart() as $item ) {
        if ( ! empty( $item['aceita_sob_encomenda'] ) ) {
            $order->update_meta_data( 'has_sob_encomenda', 'yes' );
            break;
        }
    }
}, 10, 2 );

// 8) Dispara o e-mail quando o pedido entra em 'processing'
function wcbc_trigger_backorder_email_on_processing( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( $order && 'yes' === $order->get_meta( 'has_sob_encomenda' ) ) {
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();
        if ( ! empty( $emails['WC_Email_Encomenda'] ) ) {
            $emails['WC_Email_Encomenda']->trigger( $order_id );
        }
    }
}

add_action( 'woocommerce_order_status_processing', 'wcbc_trigger_backorder_email_on_processing', 10, 1 );
