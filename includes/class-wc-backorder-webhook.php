<?php
/**
 * Tiny Webhook Integration for WooCommerce
 *
 * Text Domain: wc-backorder-confirmation
 * File: includes/class-wc-integration-tiny-webhook.php
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Ensure WooCommerce is active
if (!class_exists('WC_Integration')) {
    return;
}

class WC_Integration_Tiny_Webhook extends WC_Integration {
    public const TRANSIENT_PREFIX = 'wcbc_tiny_id_';
    public const LOG_SOURCE       = 'tiny-webhook';
    // Delay set to 3 hours
    public const DELAY_SECONDS    = 3 * HOUR_IN_SECONDS;

    public function __construct() {
        $this->id                 = 'tiny-webhook';
        $this->method_title       = __( 'Tiny Webhook', 'wc-backorder-confirmation' );
        $this->method_description = __( 'Envia marcadores ao Tiny para pedidos com produtos em backorder.', 'wc-backorder-confirmation' );

        // Define form fields
        $this->init_form_fields();
        // Load saved settings
        $this->init_settings();

        // Save admin options
        add_action( 'woocommerce_update_options_integration_' . $this->id, [ $this, 'process_admin_options' ] );

        // Schedule and process notifications
        add_action( 'woocommerce_order_status_processing', [ $this, 'schedule_notification' ], 20, 1 );
        add_action( 'wcbc_tiny_notify_backorder',     [ $this, 'process_notification' ], 10, 1 );
    }

    // Define fields displayed on WooCommerce > Settings > Integrations > Tiny Webhook
    public function init_form_fields() {
        $this->form_fields = [
            'token' => [
                'title'       => __( 'Tiny API Token', 'wc-backorder-confirmation' ),
                'type'        => 'text',
                'description' => __( 'API token fornecido pelo Tiny.', 'wc-backorder-confirmation' ),
                'desc_tip'    => true,
            ],
            'marker_id' => [
                'title'       => __( 'Tiny Marker ID', 'wc-backorder-confirmation' ),
                'type'        => 'number',
                'default'     => 185669,
                'description' => __( 'ID do marcador no Tiny.', 'wc-backorder-confirmation' ),
                'desc_tip'    => true,
            ],
            'marker_desc' => [
                'title'       => __( 'Tiny Marker Description', 'wc-backorder-confirmation' ),
                'type'        => 'text',
                'default'     => 'Encomenda',
                'description' => __( 'Descrição do marcador no Tiny.', 'wc-backorder-confirmation' ),
                'desc_tip'    => true,
            ],
        ];
    }

    // Agenda a notificação com atraso, evitando duplicatas
    public function schedule_notification( int $order_id ): void {
        if ( $order_id <= 0 ) {
            $this->log( 'Invalid order ID.', 'warning', ['order_id' => $order_id] );
            return;
        }

        $token = $this->get_option( 'token' );
        if ( empty( $token ) ) {
            $this->log( 'Tiny API Token not set.', 'error', ['order_id' => $order_id] );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || ! $this->has_backorder_items( $order ) ) {
            $this->log( 'No backorder items.', 'info', ['order_id' => $order_id] );
            return;
        }

        // Evita agendamento duplicado
        if ( function_exists( 'as_next_scheduled_action' )
            && as_next_scheduled_action( 'wcbc_tiny_notify_backorder', ['order_id' => $order_id], 'wcbc' ) ) {
            $this->log( 'Notification already scheduled.', 'debug', ['order_id' => $order_id] );
            return;
        }

        // Agenda via Action Scheduler se disponível
        if ( function_exists( 'as_schedule_single_action' ) ) {
            $when = time() + self::DELAY_SECONDS;
            $this->log( sprintf( 'Scheduling Tiny check at %s.', date( 'Y-m-d H:i:s', $when ) ), 'debug', ['order_id' => $order_id, 'when' => $when] );
            as_schedule_single_action( $when, 'wcbc_tiny_notify_backorder', ['order_id' => $order_id], 'wcbc' );
        } else {
            $this->log( 'Action Scheduler unavailable. Using wp_schedule_single_event fallback.', 'warning', ['order_id' => $order_id] );
            wp_schedule_single_event( time() + self::DELAY_SECONDS, 'wcbc_tiny_notify_backorder', ['order_id' => $order_id] );
        }
    }

    // Processa e envia o marcador ao Tiny
    public function process_notification( $args ): void {
        $order_id = is_array( $args ) && isset( $args['order_id'] )
            ? absint( $args['order_id'] )
            : absint( $args );

        if ( $order_id <= 0 ) {
            $this->log( 'Invalid order ID in process.', 'warning', ['args' => $args] );
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || ! $this->has_backorder_items( $order ) ) {
            $this->log( 'No backorder items in process.', 'info', ['order_id' => $order_id] );
            return;
        }

        if ( $order->get_meta( 'tiny_marker_sent' ) ) {
            $this->log( 'Marker already sent.', 'info', ['order_id' => $order_id] );
            return;
        }

        $tiny_id = $this->get_tiny_id( $order_id );
        if ( ! $tiny_id ) {
            $this->log( 'Unable to retrieve Tiny ID.', 'error', ['order_id' => $order_id] );
            return;
        }

        if ( $this->send_marker( $tiny_id, $order_id ) ) {
            $order->update_meta_data( 'tiny_order_id', $tiny_id );
            $order->update_meta_data( 'tiny_marker_sent', 'yes' );
            $order->save_meta_data();
            $order->add_order_note( sprintf( 'Tiny marker sent (ID: %s).', esc_html( (string) $tiny_id ) ), false );
            $this->log( 'Marker sent successfully.', 'info', ['order_id' => $order_id, 'tiny_id' => $tiny_id] );
        }
    }

    // Detecta itens em backorder utilizando API interna do WC_Order_Item
    protected function has_backorder_items( WC_Order $order ): bool {
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $item->is_on_backorder() ) {
                return true;
            }
        }
        return false;
    }

    // Retorna Tiny ID via transient ou busca na API
    protected function get_tiny_id( int $order_id ) {
        $key = self::TRANSIENT_PREFIX . $order_id;
        if ( $cached = get_transient( $key ) ) {
            $this->log( 'Tiny ID cached.', 'debug', ['order_id' => $order_id] );
            return (int) $cached;
        }
        if ( $id = $this->fetch_tiny_id( $order_id ) ) {
            set_transient( $key, $id, self::DELAY_SECONDS );
            return $id;
        }
        return false;
    }

    // Consulta Tiny para obter o ID do pedido
    protected function fetch_tiny_id( int $order_id ) {
        $token = $this->get_option( 'token' );
        $url   = 'https://api.tiny.com.br/api2/pedidos.pesquisa';
        $args  = [ 'token' => $token, 'numeroEcommerce' => $order_id, 'formato' => 'JSON' ];
        $req   = add_query_arg( $args, $url );
        $res   = wp_remote_get( $req, ['timeout' => 15] );

        if ( is_wp_error( $res ) ) {
            $this->log( 'Tiny API error: ' . $res->get_error_message(), 'error', ['order_id' => $order_id] );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $res );
        if ( 200 !== $code ) {
            $this->log( "Tiny API HTTP {$code}", 'error', ['order_id' => $order_id] );
            return false;
        }

        $body = wp_remote_retrieve_body( $res );
        $data = json_decode( $body, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            $this->log( 'JSON parse error: ' . json_last_error_msg(), 'error', ['order_id' => $order_id] );
            return false;
        }

        $pedidos = $data['retorno']['pedidos'] ?? [];
        if ( empty( $pedidos ) || ! is_array( $pedidos ) ) {
            return false;
        }

        $first = reset( $pedidos );
        $pd    = $first['pedido'] ?? $first;
        return isset( $pd['id'] ) ? (int) $pd['id'] : false;
    }

    // Envia marcador ao Tiny e valida resposta
    protected function send_marker( int $tiny_id, int $order_id ): bool {
        $token     = $this->get_option( 'token' );
        $marker_id = (int) $this->get_option( 'marker_id' );
        $desc      = $this->get_option( 'marker_desc' );
        $url       = 'https://api.tiny.com.br/api2/pedido.marcadores.incluir';
        $payload   = [
            'token'      => $token,
            'idPedido'   => $tiny_id,
            'marcadores' => [[ 'marcador' => ['id' => $marker_id, 'descricao' => $desc] ]],
            'formato'    => 'json',
        ];

        $res = wp_remote_post( $url, [
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        ]);

        if ( is_wp_error( $res ) ) {
            $this->log( 'Tiny HTTP error: ' . $res->get_error_message(), 'error', ['order_id' => $order_id, 'tiny_id' => $tiny_id] );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $res );
        if ( 200 !== $code ) {
            $this->log( "Tiny marker HTTP {$code}", 'error', ['order_id' => $order_id, 'tiny_id' => $tiny_id] );
            return false;
        }

        $body = wp_remote_retrieve_body( $res );
        $data = json_decode( $body, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            $this->log( 'Marker JSON parse error: ' . json_last_error_msg(), 'error', ['order_id' => $order_id, 'tiny_id' => $tiny_id] );
            return false;
        }

        $status = $data['retorno']['status'] ?? null;
        if ( null === $status || strtoupper( $status ) !== 'OK' ) {
            $this->log( 'Tiny marker inclusion failed: ' . wp_json_encode( $data ), 'error', ['order_id' => $order_id, 'tiny_id' => $tiny_id] );
            return false;
        }

        return true;
    }

    // Logging centralizado
    protected function log( string $msg, string $level = 'info', array $context = [] ): void {
        $context = array_merge( ['source' => self::LOG_SOURCE], $context );
        $logger  = wc_get_logger();
        $logger->log( $level, $msg, $context );
    }
}

// Registra a integração no WooCommerce
add_filter( 'woocommerce_integrations', function( array $integrations ): array {
    $integrations[] = 'WC_Integration_Tiny_Webhook';
    return $integrations;
} );
