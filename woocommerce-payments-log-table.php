<?php

/**
 * Plugin Name:       WooCommerce Payments Log Table
 * Description:       Logs all WooCommerce order payments and refunds to a database table.
 * Version:           1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'WC_PAYMENTS_LOG_TABLE_FILE', __FILE__ );

class WC_Payments_Log_Table {
    /**
     * Plugin version
     *
     * @var string
     */
    const VERSION = '1.0.0';

    /**
     * Table name without prefix
     *
     * @var string
     */
    const TABLE_NAME = 'wc_payments_log';
    
    /**
     * Database version
     *
     * @var string
     */
    const DB_VERSION = '1.0.0';

    /**
     * Payment event types
     *
     * @var string
     */
    const EVENT_TYPE_PAYMENT = 'payment';
    const EVENT_TYPE_REFUND  = 'refund';

    /**
     * Logger instance
     *
     * @var WC_Logger
     */
    private $logger;

    /**
     * Class constructor
     *
     * @param WC_Logger $logger Logger instance
     */
    public function __construct( $logger ) {
        $this->logger = $logger;

        register_activation_hook( WC_PAYMENTS_LOG_TABLE_FILE, [ $this, 'install' ] );
        
        // Load dependencies and set up hooks when WooCommerce is loaded
        add_action( 'plugins_loaded', [ $this, 'load_files' ] );
        add_action( 'plugins_loaded', [ $this, 'setup_hooks' ] );
    }

    /**
     * Load required files
     *
     * @return void
     */
    public function load_files(): void {
        require_once plugin_dir_path( WC_PAYMENTS_LOG_TABLE_FILE ) . 'includes/class-payment-history-metabox.php';
        
        new WC_Payments_Log_Table_Metabox( $this );
    }

    /**
     * Set up hooks once WooCommerce is loaded
     *
     * @return void
     */
    public function setup_hooks(): void {    
        add_action( 'woocommerce_payment_complete', [ $this, 'log_payment' ] );
        add_action( 'woocommerce_refund_created', [ $this, 'log_refund' ], 10, 2 );
    }

    /**
     * Log a successful payment
     *
     * @param int $order_id The WooCommerce order ID
     * @return void
     */
    public function log_payment( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $transaction_id = $order->get_transaction_id();

        $data = [
            'user_id'                => $order->get_customer_id(),
            'order_id'               => $order_id,
            'event_type'             => self::EVENT_TYPE_PAYMENT,
            'currency'               => $order->get_currency(),
            'payment_amount'         => $order->get_total(),
            'gateway_transaction_id' => $transaction_id,
            'payment_gateway'        => $order->get_payment_method(),
            'payment_method'         => $order->get_payment_method_title(),
            'payment_metadata'       => wp_json_encode(
                [
                    'created_via' => $order->get_created_via(),
                    'date_paid'   => $order->get_date_paid() ? $order->get_date_paid()->format('Y-m-d H:i:s') : null,
                ]
            )
        ];

        $data = apply_filters( 'wc_payments_log_payment_data', $data, $order );

        $this->insert_with_transaction( $data );
    }

    /**
     * Log a refund
     *
     * @param int   $refund_id The refund ID
     * @param array $args      Refund arguments (not used)
     * @return void
     */
    public function log_refund( int $refund_id, array $args ): void {
        $refund = wc_get_order( $refund_id );
        if ( ! $refund ) {
            return;
        }

        $parent_order = wc_get_order( $refund->get_parent_id() );
        if ( ! $parent_order ) {
            return;
        }

        $refund_transaction_id = $this->get_refund_transaction_id( $refund, $parent_order );
        $refund_method = $refund->get_refunded_payment() ? 'gateway_api' : 'manual';

        $data = [
            'user_id'                => $parent_order->get_customer_id(),
            'order_id'               => $refund->get_parent_id(),
            'event_type'             => self::EVENT_TYPE_REFUND,
            'currency'               => $refund->get_currency(),
            'payment_amount'         => -1 * abs( (float) $refund->get_amount() ),
            'gateway_transaction_id' => $refund_transaction_id ?? '—',
            'payment_gateway'        => $parent_order->get_payment_method(),
            'payment_method'         => $parent_order->get_payment_method_title(),
            'payment_metadata'       => wp_json_encode(
                [
                    'refund_id'     => $refund_id,
                    'refund_reason' => $refund->get_reason(),
                    'refund_method' => $refund_method,
                    'refunded_by'   => $refund->get_refunded_by(),
                ]
            )
        ];

        $data = apply_filters( 'wc_payments_log_refund_data', $data, $refund, $parent_order );

        $this->insert_with_transaction( $data );
    }

    /**
     * Get payment events for an order
     *
     * @param int $order_id The order ID
     * @return array Array of payment events
     */
    public function get_order_payment_events( int $order_id ): array {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY event_ts DESC",
                $order_id
            )
        );

        if ( null === $events && ! empty( $wpdb->last_error ) ) {
            $this->log( sprintf( 'Failed to fetch payment events: %s', $wpdb->last_error ) );
        }

        return $events ?? [];
    }

    /**
     * Insert data into the payments log table with transaction handling
     *
     * @param array $data The data to insert
     * @return bool Whether the insert was successful
     */
    private function insert_with_transaction( array $data ): bool {
        if ( ! $this->validate_data( $data ) ) {
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $wpdb->query( 'START TRANSACTION' );

        try {
            $result = $wpdb->insert( $table_name, $data );
            
            if ( false === $result ) {
                throw new Exception( $wpdb->last_error );
            }

            $wpdb->query( 'COMMIT' );
            return true;

        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            $this->log( sprintf( 'Failed to insert record: %s', $e->getMessage() ) );
            return false;
        }
    }

    /**
     * Log a message to WooCommerce logs
     *
     * @param string $message The message to log
     * @param string $level   Optional. The logging level. Default 'error'.
     * @return void
     */
    private function log( string $message, string $level = 'error' ): void {
        $context = [ 'source' => 'wc-payments-log-table' ];
        $this->logger->log( $level, $message, $context );
    }

    /**
     * Get the refund transaction ID based on the payment gateway
     *
     * @param WC_Order_Refund $refund       The refund object
     * @param WC_Order        $parent_order The parent order object
     * @return string|null The refund transaction ID or null if not found
     */
    private function get_refund_transaction_id( $refund, $parent_order ): ?string {
        $gateway = $parent_order->get_payment_method();

        switch ( $gateway ) {
            case 'stripe':
                return $parent_order->get_meta( '_stripe_refund_id' );
            case 'woocommerce_payments':
                return $refund->get_meta( '_wcpay_refund_id' );
            case 'bd-credit-card':
                return $refund->get_meta( '_bd_receipt_id' );
            case 'apple_in_app_purchase':
                return $parent_order->get_meta( '_apple_refund_id' );
            case 'android_in_app_purchase':
                return null;
            case 'ppcp-gateway':
                $refund_ids = $parent_order->get_meta( '_ppcp_refunds' );
                if ( is_array( $refund_ids ) && ! empty( $refund_ids ) ) {
                    return current( $refund_ids );
                }
                return null;
            default:
                return null;
        }
    }

    /**
     * Validate data before insertion
     *
     * @param array $data The data to validate
     * @return bool Whether the data is valid
     */
    private function validate_data( array $data ): bool {
        $errors          = [];
        $required_fields = [
            'user_id'          => 'integer',
            'order_id'         => 'integer',
            'event_type'       => 'string',
            'currency'         => 'string',
            'payment_amount'   => 'numeric',
            'payment_gateway'  => 'string',
            'payment_method'   => 'string',
            'payment_metadata' => 'json',
        ];

        foreach ( $required_fields as $field => $type ) {
            if ( ! isset( $data[ $field ] ) ) {
                $errors[] = sprintf( 'Missing required field: %s', $field );
                continue;
            }

            switch ( $type ) {
                case 'integer':
                    if ( ! is_int( $data[$field] ) && ! ctype_digit( ( string ) $data[ $field ] ) ) {
                        $errors[] = sprintf( 'Field %s must be an integer', $field );
                    }
                    break;
                case 'numeric':
                    if ( ! is_numeric( $data[ $field ] ) ) {
                        $errors[] = sprintf( 'Field %s must be numeric', $field );
                    }
                    break;
                case 'string':
                    if ( ! is_string( $data[ $field ] ) ) {
                        $errors[] = sprintf( 'Field %s must be a string', $field );
                    }
                    break;
                case 'json':
                    if ( is_string( $data[ $field ] ) ) {
                        json_decode( $data[ $field ] );
                        if ( json_last_error() !== JSON_ERROR_NONE ) {
                            $errors[] = sprintf( 'Field %s must be valid JSON: %s', $field, json_last_error_msg() );
                        }
                    }
                    break;
            }
        }

        if ( isset( $data['event_type'] ) && ! in_array ( $data['event_type'], [ self::EVENT_TYPE_PAYMENT, self::EVENT_TYPE_REFUND ], true ) ) {
            $errors[] = sprintf( 'Invalid event_type: %s', $data['event_type'] );
        }

        if ( ! empty( $errors ) ) {
            $this->log(
                sprintf(
                    'Data validation failed: %s',
                    implode( ', ', $errors )
                )
            );
            return false;
        }

        return true;
    }

    /**
     * On install, create the database table.
     *
     * @return void
     */
    public function install(): void {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            payments_events_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(20) NOT NULL,
            event_ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            currency VARCHAR(3) NOT NULL,
            payment_amount DECIMAL(19,4) NOT NULL,
            gateway_transaction_id VARCHAR(255),
            payment_gateway VARCHAR(100) NOT NULL,
            payment_method VARCHAR(100) NOT NULL,
            payment_metadata JSON,
            PRIMARY KEY (payments_events_id),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY event_type (event_type),
            KEY event_ts (event_ts)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}

/**
 * Initialize the plugin
 *
 * @return void
 */
function wc_payments_log_table_init(): void {
    new WC_Payments_Log_Table( wc_get_logger() );
}

add_action( 'plugins_loaded', 'wc_payments_log_table_init' );
