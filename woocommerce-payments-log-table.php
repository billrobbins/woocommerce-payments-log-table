<?php

/**
 * Plugin Name:       WooCommerce Payments Log
 * Description:       Logs all WooCommerce order payments and refunds to a database table.
 * Version:           1.0.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'WC_PAYMENTS_LOG_TABLE_FILE', __FILE__ );
define( 'WC_PAYMENTS_LOG_TABLE_VERSION', '1.0.0' );
define( 'WC_PAYMENTS_LOG_TABLE_DB_VERSION', '1.0.0' );
define( 'WC_PAYMENTS_LOG_TABLE_NAME', 'wc_payments_log' );

/**
 * Initialize the plugin
 *
 * @return void
 */
function wc_payments_log_table_init(): void {
	// Load required files
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-payments-logger.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-payment-history-metabox.php';

	// Initialize logger
	$logger = new WC_Payments_Logger( wc_get_logger() );

	// Initialize metabox
	new WC_Payments_Log_Table_Metabox( $logger );
}

// Initialize plugin when WooCommerce is loaded
add_action( 'plugins_loaded', 'wc_payments_log_table_init' );

/**
 * Install the plugin database table
 *
 * @return void
 */
function wc_payments_log_table_install(): void {
	global $wpdb;

	$table_name      = $wpdb->prefix . WC_PAYMENTS_LOG_TABLE_NAME;
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

// Register activation hook for table creation
register_activation_hook( __FILE__, 'wc_payments_log_table_install' );
