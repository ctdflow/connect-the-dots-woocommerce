<?php
/**
 * Plugin Name:          Connect the Dots: Delivery Route Planner for WooCommerce
 * Description:          Plans delivery routes for your WooCommerce orders with Connect the Dots.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * WC requires at least: 8.0
 * WC tested up to:      11.3
 * Requires Plugins:     woocommerce
 * Author:               Connect the Dots
 * Author URI:           https://ctdflow.com
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          connect-the-dots
 * Domain Path:          /languages
 *
 * @package ConnectTheDots
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';

ConnectTheDots\Autoloader::register( __DIR__ . '/src' );

ConnectTheDots\Plugin::instance()->boot( __FILE__ );

register_activation_hook( __FILE__, array( ConnectTheDots\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( ConnectTheDots\Plugin::class, 'deactivate' ) );
