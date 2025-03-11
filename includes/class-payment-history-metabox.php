<?php

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Handles the payment history metabox in the order admin screen
 */
class WC_Payments_Log_Table_Metabox {
    /**
     * The payments log table instance
     *
     * @var WC_Payments_Log_Table
     */
    private $payments_log_table;

    /**
     * Constructor
     *
     * @param WC_Payments_Log_Table $payments_log_table The payments log table instance
     */
    public function __construct( WC_Payments_Log_Table $payments_log_table ) {
        $this->payments_log_table = $payments_log_table;
        $this->init();
    }

    /**
     * Initialize the metabox
     *
     * @return void
     */
    public function init(): void {
        add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    /**
     * Register the payment history metabox
     *
     * @return void
     */
    public function register_metabox(): void {
        add_meta_box(
            'wc_payments_log_history',
            __( 'Payment History', 'wc-payments-log-table' ),
            [ $this, 'render_metabox' ],
            'woocommerce_page_wc-orders',
            'normal',
            'default'
        );
    }

    /**
     * Enqueue admin styles
     *
     * @return void
     */
    public function enqueue_styles(): void {
        $screen = get_current_screen();
        if ( 'woocommerce_page_wc-orders' !== $screen->id ) {
            return;
        }

        wp_enqueue_style(
            'wc-payments-log-table-admin',
            plugins_url( 'assets/css/admin.css', WC_PAYMENTS_LOG_TABLE_FILE ),
            [],
            WC_Payments_Log_Table::VERSION
        );
    }

    /**
     * Display payment history metabox in order admin
     *
     * @param \WC_Order $order The order object
     * @return void
     */
    public function render_metabox( \WC_Order $order ): void {
        $events = $this->payments_log_table->get_order_payment_events( $order->get_id() );

        ?>
        <div class="woocommerce-order-data">
            <table class="wc-order-totals">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'wc-payments-log-table' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'wc-payments-log-table' ); ?></th>
                        <th class="amount"><?php esc_html_e( 'Amount', 'wc-payments-log-table' ); ?></th>
                        <th><?php esc_html_e( 'Gateway', 'wc-payments-log-table' ); ?></th>
                        <th><?php esc_html_e( 'Created Via', 'wc-payments-log-table' ); ?></th>
                        <th><?php esc_html_e( 'Transaction ID', 'wc-payments-log-table' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $events ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No payment events found for this order.', 'wc-payments-log-table' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $events as $event ) : 
                            $metadata = json_decode( $event->payment_metadata, true );
                            $created_via = '';

                            if ( WC_Payments_Log_Table::EVENT_TYPE_PAYMENT === $event->event_type ) {
                                $created_via = ! empty( $metadata['created_via'] ) ? $metadata['created_via'] : '—';
                            } else {
                                $created_via = 'gateway_api' === $metadata['refund_method']
                                    ? __( 'Gateway API', 'wc-payments-log-table' )
                                    : __( 'Manual', 'wc-payments-log-table' );
                                
                                if ( ! empty( $metadata['refunded_by'] ) ) {
                                    $user_data = get_userdata( $metadata['refunded_by'] );
                                    if ( $user_data ) {
                                        $created_via .= ' (' . esc_html( $user_data->display_name ) . ')';
                                    }
                                }
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->event_ts ) ) ); ?></td>
                                <td>
                                    <?php if ( WC_Payments_Log_Table::EVENT_TYPE_PAYMENT === $event->event_type ) : ?>
                                        <mark class="order-status status-processing">
                                            <span><?php esc_html_e( 'Payment', 'wc-payments-log-table' ); ?></span>
                                        </mark>
                                    <?php else : ?>
                                        <mark class="order-status status-refunded">
                                            <span><?php esc_html_e( 'Refund', 'wc-payments-log-table' ); ?></span>
                                        </mark>
                                    <?php endif; ?>
                                </td>
                                <td class="amount"><?php echo wp_kses_post( wc_price( $event->payment_amount, [ 'currency' => $event->currency ] ) ); ?></td>
                                <td><?php echo esc_html( $event->payment_gateway ); ?></td>
                                <td><?php echo esc_html( $created_via ); ?></td>
                                <td><?php echo esc_html( $event->gateway_transaction_id ?: '—' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
} 