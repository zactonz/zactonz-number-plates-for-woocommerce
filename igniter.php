<?php
/**
 * Plugin Name:       Zactonz Number Plates for WooCommerce
 * Plugin URI:        https://developers.zactonz.com/wordpress/plugins/zactonz-number-plates-for-woocommerce/
 * Description:       Sell made-to-order vehicle number plates. Shoppers configure a plate, see it rendered live, and the order carries production-ready artwork.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.6
 * WC tested up to:   11.2
 * Version:           1.0.0
 * Author:            Zactonz Technologies
 * Author URI:        https://zactonz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       zactonz-number-plates-for-woocommerce
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

define( 'ZCTZ_NP_FILE', __FILE__ );
define( 'ZCTZ_NP_DIR', __DIR__ );
define( 'ZCTZ_NP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Maps this plugin's namespace onto inc/, one class per file.
 *
 * Hand-rolled rather than Composer because the plugin ships no third-party
 * dependency, and a vendor directory the directory reviewer has to read through
 * is a cost with nothing on the other side of it.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = ZCTZ_NP_DIR . '/inc/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Tells WooCommerce this plugin is safe with the storage and checkout it now defaults to.
 *
 * Both declarations are required even though the plugin stores nothing itself:
 * without them WooCommerce shows the store owner an incompatibility warning.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ZCTZ_NP_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ZCTZ_NP_FILE, true );
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		Plugin::instance()->boot();
	}
);
