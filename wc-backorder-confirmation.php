<?php
/*
Plugin Name: Woocommerce Pedido Sob Encomenda
Description: Mostra checkbox de aceitação apenas quando a quantidade excede o estoque e o produto permite encomenda. Inclui validação, avisos e e-mail. Sem AJAX.
Version: 1.6.4
Author: Rafael Moreno
Text Domain: wc-backorder-confirmation
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCBC_VERSION', '1.6.4' );
define( 'WCBC_TD', 'wc-backorder-confirmation' );

/** 1) I18n */
add_action( 'init', function() {
        load_plugin_textdomain( WCBC_TD, false, plugin_basename( __DIR__ ) . '/languages/' );
} );

/** 2) (Opcional) Tiny Webhook */
add_action( 'woocommerce_loaded', function() {
        if ( class_exists( 'WC_Integration_Tiny_Webhook' ) ) return;
        $path = plugin_dir_path( __FILE__ ) . 'includes/class-wc-integration-tiny-webhook.php';
        if ( file_exists( $path ) ) require_once $path;
}, 20 );

add_filter( 'woocommerce_email_classes', function( $email_classes ) {
        $path = __DIR__ . '/includes/class-wc-email-encomenda.php';
        if ( file_exists( $path ) ) {
                require_once $path;
                $email_classes['WC_Email_Encomenda'] = new WC_Email_Encomenda();
        }
        return $email_classes;
} );

/** 3) Helpers */
// Está "amostras" no carrinho?
function wcbc_cart_has_amostras() {
        if ( ! function_exists('WC') || ! WC()->cart ) return false;
        $applied = array_map( 'strtolower', WC()->cart->get_applied_coupons() );
        return in_array( 'amostras', $applied, true );
}

// Está "amostras" no pedido?
function wcbc_order_has_amostras( WC_Order $order ) {
        $codes = method_exists( $order, 'get_coupon_codes' ) ? $order->get_coupon_codes() : $order->get_used_coupons();
        $codes = array_map( 'strtolower', (array) $codes );
        return in_array( 'amostras', $codes, true );
}

function wcbc_notice_text() {
        return apply_filters( 'wcbc_backorder_notice_text', __( 'Estará disponível em até 15 dias úteis', WCBC_TD ) );
}
function wcbc_notice_html() {
        $html = '<p class="backorder_notification backorder_notification_custom">' . esc_html( wcbc_notice_text() ) . '</p>';
        return apply_filters( 'wcbc_backorder_notice_html', $html );
}

/** Bloco UI (aviso + checkbox). Se existir [block id="aviso-encomenda"], usa; senão, fallback com input correto. */
function wcbc_render_checkbox_block() {
        $block = ( shortcode_exists( 'block' ) ) ? do_shortcode( '[block id="aviso-encomenda"]' ) : '';
        $has_input = (bool) preg_match( '/name=["\']aceita_sob_encomenda["\']/', $block );
        $out  = '<div class="wcbc-encomenda" data-wcbc="1">';
        $out .= wcbc_notice_html();
        if ( $block ) $out .= $block;
        if ( ! $has_input ) {
                $out .= '<label class="sob-encomenda-optin">
                                        <input type="checkbox" name="aceita_sob_encomenda" value="1">
                                        ' . esc_html__( 'Estou ciente do prazo de encomenda.', WCBC_TD ) . '
                                </label>';
        }
        $out .= '</div>';
        return $out;
}

/** 4) Render: PRODUTO SIMPLES – um único wrapper oculto com data-* */
add_action( 'woocommerce_after_add_to_cart_button', function () {
        if ( ! is_product() ) return;
        global $product;
        if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) ) return;

        $stock      = $product->get_stock_quantity();
        $backorders = $product->backorders_allowed();

        printf(
                '<div id="wcbc-wrap" style="display:none" data-type="simple" data-stock="%s" data-backorders="%d">%s</div>',
                is_null( $stock ) ? '' : (int) $stock,
                $backorders ? 1 : 0,
                wcbc_render_checkbox_block()
        );
}, 12 );

/** 5) Render: PRODUTO VARIÁVEL – um único wrapper; dados vêm nas variações */
add_action( 'woocommerce_after_add_to_cart_button', function () {
        if ( ! is_product() ) return;
        global $product;
        if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) return;

        echo '<div id="wcbc-wrap" style="display:none" data-type="variable">'
           . wcbc_render_checkbox_block()
           . '</div>';
}, 12 );

/** 6) Dados por variação (sem HTML duplicado) */
add_filter( 'woocommerce_available_variation', function( $data, $product, $variation ) {
        if ( $variation instanceof WC_Product_Variation ) {
                $data['wcbc_stock']      = is_null( $variation->get_stock_quantity() ) ? '' : (int) $variation->get_stock_quantity();
                $data['wcbc_backorders'] = $variation->backorders_allowed() ? 1 : 0;
                // $data['wcbc_requires'] = $variation->backorders_require_notification() ? 1 : 0;
        }
        return $data;
}, 10, 3 );

/** 7) JS – funciona com variações pré-carregadas E via AJAX (>30 variações) */
add_action( 'wp_footer', function() {
        if ( ! is_product() ) return; ?>
<script>
jQuery(function($){
  const box = $('#wcbc-wrap'); if (!box.length) return;
  const $qty = $('input.qty');
  const $confirm = box.find('input[name="aceita_sob_encomenda"]');

  // Mapa de variações (id -> {stock, backorders}). Pode ser preenchido por preload ou on-demand (AJAX).
  let vmap = null;
  const $form = $('form.variations_form');

  // 7.1 — Preload (quando total de variações <= threshold do Woo e elas vêm no HTML)
  if (box.data('type') === 'variable' && $form.length) {
    const vars = $form.data('product_variations') || [];
    if (vars.length) {
      vmap = {};
      for (const v of vars) {
        vmap[String(v.variation_id)] = {
          stock: (v.wcbc_stock === '' ? null : parseInt(v.wcbc_stock,10)),
          backorders: !!v.wcbc_backorders
        };
      }
    }
  }

  // 7.2 — AJAX (quando total de variações > threshold, o Woo envia 1 variação por vez em found_variation)
  if ($form.length) {
    $form.on('found_variation', function(e, variation){
      // Garante o mapa e armazena a variação atual
      if (!vmap) vmap = {};
      if (variation && typeof variation.variation_id !== 'undefined') {
        vmap[String(variation.variation_id)] = {
          stock: (variation.wcbc_stock === '' || typeof variation.wcbc_stock === 'undefined' ? null : parseInt(variation.wcbc_stock,10)),
          backorders: !!variation.wcbc_backorders
        };
      }
      toggleBox();
    });

    $form.on('reset_data', function(){
      box.hide();
      $confirm.prop('required', false).prop('checked', false);
    });
  }

  function shouldShow(){
    const q = parseInt($qty.val(),10) || 1;

    if (box.data('type') === 'variable') {
      const vid = $('input.variation_id').val();
      if (!vid || !vmap || !vmap[String(vid)]) return false;
      const data = vmap[String(vid)];
      if (!data.backorders) return false;    // não permite encomenda
      const stock = data.stock;
      if (stock === null) return false;      // sem estoque definido -> não compara
      // Se passar a exigir "requires": return data.backorders && data.requires && q > stock;
      return q > stock;
    }

    // simples
    const backorders = !!box.data('backorders');
    const stockRaw = box.data('stock');
    const stock = (stockRaw === '' || typeof stockRaw === 'undefined') ? null : parseInt(stockRaw,10);
    if (!backorders) return false;
    if (stock === null) return false;
    return q > stock;
  }

  let t=null;
  function toggleBox(){
    clearTimeout(t);
    t = setTimeout(() => {
      const show = shouldShow();
      box.toggle(show);
      $confirm.prop('required', show);
      if (!show) $confirm.prop('checked', false);
    }, 60);
  }

  $(document).on('input change', 'input.qty', toggleBox);
  // Nota: found_variation/reset_data já são tratados acima para suportar AJAX
  toggleBox();
});
</script>
<?php
} );

/** 8) Validação server-side (cobre qualquer falha do front) */
add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $quantity ) {
        $id      = ( isset( $_POST['variation_id'] ) && absint( $_POST['variation_id'] ) > 0 ) ? absint( $_POST['variation_id'] ) : $product_id; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product = wc_get_product( $id );
        if ( ! $product ) return $passed;

        $aceita = ! empty( $_POST['aceita_sob_encomenda'] ) && filter_var( wp_unslash( $_POST['aceita_sob_encomenda'] ), FILTER_VALIDATE_BOOLEAN ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        // Exigir confirmação apenas quando realmente está em backorder para a quantidade
        if ( $product->backorders_require_notification() && $product->is_on_backorder( $quantity ) && ! $aceita ) {
                wc_add_notice( esc_html__( 'Você deve confirmar que aceita o prazo de encomenda para este produto.', WCBC_TD ), 'error' );
                return false;
        }
        return $passed;
}, 10, 3 );

/** 9) Sinalizações, e-mails e avisos auxiliares */
// Marca o item no carrinho
add_filter( 'woocommerce_add_cart_item_data', function( $cart_item, $product_id ) {
        if ( ! empty( $_POST['aceita_sob_encomenda'] ) && filter_var( wp_unslash( $_POST['aceita_sob_encomenda'] ), FILTER_VALIDATE_BOOLEAN ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $cart_item['aceita_sob_encomenda'] = true;
        }
        return $cart_item;
}, 10, 2 );

// Salva flag no pedido
add_action( 'woocommerce_checkout_create_order', function( $order ) {
        if ( ! function_exists('WC') || ! WC()->cart ) return;
        foreach ( WC()->cart->get_cart() as $item ) {
                if ( ! empty( $item['aceita_sob_encomenda'] ) ) {
                        $order->update_meta_data( 'has_sob_encomenda', 'yes' );
                        break;
                }
        }
}, 10, 1 );

/** 10) Rótulo "pode ser encomendado" na disponibilidade (single + AJAX de variações) */
function wcbc_is_variation_context() {
        // Single de produto
        if ( function_exists('is_product') && is_product() ) return true;

        // Woo AJAX moderno: ?wc-ajax=get_variations
        if ( isset($_GET['wc-ajax']) && $_GET['wc-ajax'] === 'get_variations' ) return true;

        // Admin-ajax/legacy
        if ( ( defined('DOING_AJAX') && DOING_AJAX )
          && ( isset($_POST['product_id']) || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'woocommerce_get_variation') ) ) {
                return true;
        }

        return false;
}

add_filter( 'woocommerce_get_availability_text', function( $availability, $product ) {
        // Só no PDP e no AJAX de variações (não vaza para carrinho/checkout/emails)
        if ( ! wcbc_is_variation_context() || ! ( $product instanceof WC_Product ) ) {
                return $availability;
        }

        // Produto que realmente gerencia o estoque (variação ou pai)
        $managed_id = method_exists( $product, 'get_stock_managed_by_id' )
                ? $product->get_stock_managed_by_id()
                : $product->get_id();

        $managed = wc_get_product( $managed_id );
        if ( ! $managed ) return $availability;

        // Se permite encomenda, limpamos o sufixo padrão "(pode ser encomendado)" do Woo
        if ( $managed->backorders_allowed() ) {

                // 1) Remove o sufixo padrão em PT-BR (e variações) — seguro e insensível a maiúsculas
                $availability = preg_replace( '/\s*\((?:[^)]*encomendado[^)]*)\)\s*$/iu', '', $availability );

                // 2) (Opcional) remove também variações em EN, caso o site mude de idioma
                $availability = preg_replace( '/\s*\((?:available on backorder|on backorder[^)]*)\)\s*$/iu', '', $availability );

                // 3) Agora adiciona apenas o seu rótulo customizado com pipe
                $availability .= ' | ' . __( 'pode ser encomendado', WCBC_TD );
        }

        return $availability;
}, 15, 2 );



// Suprime o e-mail padrão "processando" quando for sob encomenda
add_filter( 'woocommerce_email_enabled_customer_processing_order', function( $enabled, $order ) {
        if ( ! $order instanceof WC_Order ) return $enabled;
        if ( 'yes' === $order->get_meta( 'has_sob_encomenda' ) ) {
                return false;
        }
        return $enabled;
}, 10, 2 );

// Dispara e-mail personalizado quando entra em "processing" — suprimido se houver cupom "amostras"
add_action( 'woocommerce_order_status_processing', function( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Não envia e-mail se tiver cupom "amostras"
        if ( wcbc_order_has_amostras( $order ) ) return;

        if ( 'yes' === $order->get_meta( 'has_sob_encomenda' ) ) {
                $mailer = WC()->mailer();
                $emails = $mailer->get_emails();
                if ( ! empty( $emails['WC_Email_Encomenda'] ) ) {
                        $emails['WC_Email_Encomenda']->trigger( $order_id );
                }
        }
}, 10, 1 );

// Aviso no carrinho (abaixo do nome) — suprimido se houver cupom "amostras"
add_action( 'woocommerce_after_cart_item_name', function( $cart_item, $cart_item_key ) {
        if ( wcbc_cart_has_amostras() ) return;

        $product  = $cart_item['data'];
        $quantity = $cart_item['quantity'];
        if ( $product instanceof WC_Product && $product->backorders_require_notification() && $product->is_on_backorder( $quantity ) ) {
                echo wp_kses_post( wcbc_notice_html() );
        }
}, 10, 2 );

// Aviso por item na página "Thank You" — suprimido se houver cupom "amostras"
add_action( 'woocommerce_order_item_meta_end', function( $item_id, $item, $order, $plain_text ) {
        if ( ! is_wc_endpoint_url( 'order-received' ) ) return;
        if ( $order instanceof WC_Order && wcbc_order_has_amostras( $order ) ) return;

        $product  = $item->get_product();
        $quantity = $item->get_quantity();
        if ( $product->backorders_require_notification() && $product->is_on_backorder( $quantity ) ) {
                echo wp_kses_post( wcbc_notice_html() );
        }
}, 10, 4 );
