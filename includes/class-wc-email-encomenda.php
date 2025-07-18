<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Email_Encomenda extends WC_Email {

    public function __construct() {
        $this->id             = 'encomenda';
        $this->title          = 'Pedido de Encomenda';
        $this->description    = 'Envia as condições de encomenda quando o pedido entra em processamento e possui encomenda.';
        $this->subject        = '[{site_title}] Encomenda do seu Pedido #{order_number}';
        $this->heading        = 'Detalhes da sua Encomenda';

        $this->template_html  = 'emails/encomenda.php';
        $this->template_plain = 'emails/plain/encomenda.php';

        $this->customer_email = true;

        parent::__construct();
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
}
