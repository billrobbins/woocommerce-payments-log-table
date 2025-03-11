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
     */
    const VERSION = '1.0.0';

    /**
     * Table name without prefix
     */
    const TABLE_NAME = 'wc_payments_log';
    
    /**
     * Database version
     */
    const DB_VERSION = '1.0.0';

    /**
     * Payment event types
     */
    const EVENT_TYPE_PAYMENT = 'payment';
    const EVENT_TYPE_REFUND  = 'refund';

    /**
     * Initialize the plugin
     *
     * @return void
     */
    public static function init(): void {
        register_activation_hook( __FILE__, [ self::class, 'install' ] );
        
        add_action( 'plugins_loaded', [ self::class, 'setup_hooks' ] );
        add_action( 'plugins_loaded', [ self::class, 'load_files' ] );
    }

    /**
     * Load required files
     *
     * @return void
     */
    public static function load_files(): void {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-payment-history-metabox.php';
        
        WC_Payments_Log_Table_Metabox::init();
    }

    /**
     * Set up hooks once WooCommerce is loaded
     *
     * @return void
     */
    public static function setup_hooks(): void {    
        add_action( 'woocommerce_payment_complete', [ self::class, 'log_payment' ] );
        add_action( 'woocommerce_refund_created', [ self::class, 'log_refund' ], 10, 2 );
    }

    /**
     * On install, create the database table.
     *
     * @return void
     */
    public static function install(): void {
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

    /**
     * Validate data before insertion
     *
     * @param array $data The data to validate
     * @return bool Whether the data is valid
     */
    private static function validate_data( array $data ): bool {
        $errors          = [];
        $required_fields = [
            'user_id'         => 'integer',
            'order_id'        => 'integer',
            'event_type'      => 'string',
            'currency'        => 'string',
            'payment_amount'  => 'numeric',
            'payment_gateway' => 'string',
            'payment_method'  => 'string',
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
            }
        }

        // Validate event_type
        if ( isset( $data['event_type'] ) && ! in_array ( $data['event_type'], [ self::EVENT_TYPE_PAYMENT, self::EVENT_TYPE_REFUND ], true ) ) {
            $errors[] = sprintf( 'Invalid event_type: %s', $data['event_type'] );
        }

        // Validate metadata is valid JSON if present
        if ( isset( $data['payment_metadata'] ) ) {
            if ( ! is_string( $data['payment_metadata'] ) ) {
                $errors[] = 'payment_metadata must be a JSON string';
            } else {
                json_decode( $data['payment_metadata'] );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    $errors[] = 'payment_metadata must be valid JSON';
                }
            }
        }

        if ( ! empty( $errors ) ) {
            error_log(
                sprintf(
                    'WC Payments Log Table: Data validation failed: %s',
                    implode( ', ', $errors )
                )
            );
            return false;
        }

        return true;
    }

    /**
     * Insert data into the payments log table with transaction handling
     *
     * @param array $data The data to insert
     * @return bool Whether the insert was successful
     */
    private static function insert_with_transaction( array $data ): bool {

        if ( ! self::validate_data( $data ) ) {
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
            error_log( sprintf( 'WC Payments Log Table: Failed to insert record: %s', $e->getMessage() ) );
            return false;
        }
    }

    /**
     * Log a successful payment
     *
     * @param int $order_id The WooCommerce order ID
     * @return void
     */
    public static function log_payment( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Get transaction ID from order
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
                    'created_via'          => $order->get_created_via(),
                    'date_paid'            => $order->get_date_paid() ? $order->get_date_paid()->format('Y-m-d H:i:s') : null,
                ]
            )
        ];

        $data = apply_filters( 'wc_payments_log_payment_data', $data, $order );

        self::insert_with_transaction( $data );
    }

    /**
     * Get the refund transaction ID based on the payment gateway
     *
     * @param WC_Order_Refund $refund       The refund object
     * @param WC_Order        $parent_order The parent order object
     * @return string|null The refund transaction ID or null if not found
     */
    private static function get_refund_transaction_id( $refund, $parent_order ): ?string {
        $gateway = $parent_order->get_payment_method();

        switch ( $gateway ) {
            case 'stripe':
                // Stripe stores the refund ID as a single value in the parent order meta.
                return $parent_order->get_meta( '_stripe_refund_id' );
            case 'woocommerce_payments':
                // WooCommerce Payments stores the refund ID in the refund's meta.
                return $refund->get_meta( '_wcpay_refund_id' );
            case 'bd-credit-card':
                // The BD Credit Card Gateway stores the refund ID in the refund's meta.
                return $refund->get_meta( '_bd_receipt_id' );
            case 'apple_in_app_purchase':
                // The Apple In-App Purchase Gateway stores the refund ID in the parent order meta.
                return  $parent_order->get_meta( '_apple_refund_id' );
            case 'android_in_app_purchase':
                // The Android In-App Purchase Gateway does not store a refund ID.
                return null;
            case 'ppcp-gateway':
                // PayPal stores the refund IDs as an array in the parent order meta.
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
     * Log a refund
     *
     * @param int   $refund_id The refund ID
     * @param array $args      Refund arguments (not used)
     * @return void
     */
    public static function log_refund( int $refund_id, array $args ): void {
        $refund = wc_get_order( $refund_id );
        if ( ! $refund ) {
            return;
        }

        $parent_order = wc_get_order( $refund->get_parent_id() );
        if ( ! $parent_order ) {
            return;
        }

        // Get gateway-specific refund transaction ID.
        $refund_transaction_id = self::get_refund_transaction_id( $refund, $parent_order );

        // Determine refund method.
        $refund_method = $refund->get_refunded_payment() ? 'gateway_api' : 'manual';

        $data = [
            'user_id'                => $parent_order->get_customer_id(),
            'order_id'               => $refund->get_parent_id(),
            'event_type'             => self::EVENT_TYPE_REFUND,
            'currency'               => $refund->get_currency(),
            'payment_amount'         => -1 * abs( (float) $refund->get_amount() ), // Store refunds as negative amounts
            'gateway_transaction_id' => $refund_transaction_id ?? '—', // Use gateway-specific refund ID if available
            'payment_gateway'        => $parent_order->get_payment_method(),
            'payment_method'         => $parent_order->get_payment_method_title(),
            'payment_metadata'       => wp_json_encode(
                [
                    'refund_id'            => $refund_id,
                    'refund_reason'        => $refund->get_reason(),
                    'refund_method'        => $refund_method,
                    'refunded_by'          => $refund->get_refunded_by(),
                ]
            )
        ];

        $data = apply_filters( 'wc_payments_log_refund_data', $data, $refund, $parent_order );

        self::insert_with_transaction( $data );
    }

    /**
     * Get payment events for an order
     *
     * @param int $order_id The order ID
     * @return array Array of payment events
     */
    public static function get_order_payment_events( int $order_id ): array {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY event_ts DESC",
                $order_id
            )
        );

        if ( null === $events && ! empty( $wpdb->last_error ) ) {
            error_log( sprintf( 'WC Payments Log Table: Failed to fetch payment events: %s', $wpdb->last_error ) );
        }

        return $events ?? [];
    }
}

// Initialize the plugin
WC_Payments_Log_Table::init();
