<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Email_Encomenda extends WC_Email {

    public function __construct() {
        $this->id             = 'encomenda';
        $this->title          = __( 'Pedido de Encomenda', 'wc-backorder-confirmation' );
        $this->description    = __( 'Envia as condições de encomenda quando o pedido entra em processamento e possui encomenda.', 'wc-backorder-confirmation' );
        $this->subject        = __( '[{site_title}] Encomenda do seu Pedido #{order_number}', 'wc-backorder-confirmation' );
        $this->heading        = __( 'Detalhes da sua Encomenda', 'wc-backorder-confirmation' );

        $this->placeholders = [
            '{order_number}' => '',
        ];

        $this->template_html  = 'emails/encomenda.php';
        $this->template_plain = 'emails/plain/encomenda.php';

        $this->customer_email = true;

        parent::__construct();

        add_filter( 'woocommerce_email_enabled_customer_processing_order', [ $this, 'maybe_disable_default_processing_email' ], 10, 3 );
    }

    public function init_form_fields() {
        parent::init_form_fields();

        $this->form_fields['disable_regular_processing_email'] = [
            'title'       => __( 'Apenas este email', 'wc-backorder-confirmation' ),
            'type'        => 'checkbox',
            'label'       => __( 'Não enviar o email de "Pedido em Processamento" padrão quando este email for enviado', 'wc-backorder-confirmation' ),
            'default'     => 'no',
            'description' => __( 'Quando marcado, substitui o email de pedido em processamento padrão para pedidos com itens sob encomenda.', 'wc-backorder-confirmation' ),
        ];
    }

    public function trigger( $order_id, $order = false ) {
        if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $this->object    = $order;
        $this->recipient = $order->get_billing_email();

        $this->placeholders['{order_number}'] = $order->get_order_number();

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
            return;
        }

        $this->send(
            $this->get_recipient(),
            $this->get_subject(),
            $this->get_content(),
            $this->get_headers(),
            $this->get_attachments()
        );
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $this,
            ],
            '',
            plugin_dir_path( __FILE__ ) . '../templates/'
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            [
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => true,
                'email'         => $this,
            ],
            '',
            plugin_dir_path( __FILE__ ) . '../templates/'
        );
    }

    public function maybe_disable_default_processing_email( $enabled, $order, $email ) {
        if ( 'yes' === $this->get_option( 'disable_regular_processing_email', 'no' ) &&
             $order instanceof WC_Order &&
             'yes' === $order->get_meta( 'has_sob_encomenda' ) ) {
            return false;
        }

        return $enabled;
    }
}
