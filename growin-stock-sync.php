<?php
/**
 * Plugin Name: Growin Stock Sync
 * Plugin URI:  https://hanfjack.de/
 * Description: Hält den Warenbestand von Grow-In-Produkten aktuell, indem die B2B-Preisliste mehrmals täglich aus dem Growin-Kundenbereich abgerufen und per Artikelnummer mit den WooCommerce-Produkten abgeglichen wird. Führt zusätzlich einen vollständigen Katalog aller Growin-Artikelnummern für künftige Produktanlagen.
 * Version:     1.2.1
 * Author:      hanfjack.de
 * Text Domain: growin-stock-sync
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * WC tested up to: 11.1
 *
 * @package GrowinStockSync
 */

defined( 'ABSPATH' ) || exit;

define( 'GSS_VERSION', '1.2.1' );
define( 'GSS_FILE', __FILE__ );
define( 'GSS_PATH', plugin_dir_path( __FILE__ ) );
define( 'GSS_URL', plugin_dir_url( __FILE__ ) );
define( 'GSS_SLUG', 'growin-stock-sync' );

require_once GSS_PATH . 'includes/class-gss-settings.php';
require_once GSS_PATH . 'includes/class-gss-logger.php';
require_once GSS_PATH . 'includes/class-gss-parser.php';
require_once GSS_PATH . 'includes/class-gss-source.php';
require_once GSS_PATH . 'includes/class-gss-catalog.php';
require_once GSS_PATH . 'includes/class-gss-bloomtech.php';
require_once GSS_PATH . 'includes/class-gss-sync.php';
require_once GSS_PATH . 'includes/class-gss-admin.php';
require_once GSS_PATH . 'includes/class-gss-plugin.php';

/**
 * Haupt-Instanz.
 *
 * @return GSS_Plugin
 */
function gss() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new GSS_Plugin();
	}

	return $instance;
}

add_action( 'plugins_loaded', 'gss', 5 );

/**
 * HPOS-Kompatibilität melden.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', GSS_FILE, true );
		}
	}
);

register_activation_hook( GSS_FILE, array( 'GSS_Plugin', 'activate' ) );
register_deactivation_hook( GSS_FILE, array( 'GSS_Plugin', 'deactivate' ) );
