<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Hook único para processar só pedidos com backorder
add_action( 'woocommerce_order_status_processing', 'tiny_notifica_encomenda_backorder', 10, 1 );
function tiny_notifica_encomenda_backorder( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // 1) Detecta se existe pelo menos um item com quantidade > estoque
    $has_backorder = false;
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) {
            continue;
        }

        if ( $product->managing_stock() && $product->backorders_allowed() ) {
            $stock = $product->get_stock_quantity();
            $qty   = $item->get_quantity();

            if ( $stock !== null && $qty > $stock ) {
                $has_backorder = true;
                break;
            }
        }
    }

    // Se não houver backorder, sai imediatamente
    if ( ! $has_backorder ) {
        return;
    }

    // 2) Define seu token e URLs
    $token           = 'f4dc1859bf53d79c8ffdfb36bad3428a20124a73';
    $url_busca       = 'https://api.tiny.com.br/api2/pedidos.pesquisa';
    $url_marcador    = 'https://api.tiny.com.br/api2/pedido.marcadores.incluir';

    // 3) Busca o idPedido no Tiny passando numeroEcommerce = $order_id
    $search_args = [
        'token'           => $token,
        'numeroEcommerce' => $order_id,
        'formato'         => 'JSON',
    ];
    $res_search = wp_remote_get( add_query_arg( $search_args, $url_busca ), [
        'timeout' => 15,
        'headers' => [ 'Accept' => 'application/json' ],
    ] );
    if ( is_wp_error( $res_search ) ) {
        error_log( "Tiny busca falhou (#{$order_id}): " . $res_search->get_error_message() );
        return;
    }
    $data_search = json_decode( wp_remote_retrieve_body( $res_search ), true );
    if ( empty( $data_search['retorno']['pedidos']['pedido'][0]['idPedido'] ) ) {
        error_log( "Tiny não retornou idPedido para ecommerce #{$order_id}: " . print_r( $data_search, true ) );
        return;
    }
    $tiny_id = $data_search['retorno']['pedidos']['pedido'][0]['idPedido'];

    // 4) Monta o payload do marcador “encomenda”
    $marcadores = [
        'marcadores' => [
            [
                'marcador' => [
                    'id'        => '185669',
                    'descricao' => 'Encomenda',
                ],
            ],
        ],
    ];

    // 5) Envia inclusão do marcador
    $marker_args = [
        'token'      => $token,
        'idPedido'   => $tiny_id,
        'marcadores' => wp_json_encode( $marcadores ),
        'formato'    => 'json',
    ];
    $res_marker = wp_remote_get( add_query_arg( $marker_args, $url_marcador ), [
        'timeout' => 15,
        'headers' => [ 'Accept' => 'application/json' ],
    ] );
    if ( is_wp_error( $res_marker ) ) {
        error_log( "Tiny marcador falhou (Woo #{$order_id} → Tiny #{$tiny_id}): " . $res_marker->get_error_message() );
        return;
    }
    $data_marker = json_decode( wp_remote_retrieve_body( $res_marker ), true );
    if ( empty( $data_marker['retorno']['status'] ) || 'SUCCESS' !== strtoupper( $data_marker['retorno']['status'] ) ) {
        error_log( "Tiny marcador retornou erro (Woo #{$order_id} → Tiny #{$tiny_id}): " . print_r( $data_marker, true ) );
    }
}