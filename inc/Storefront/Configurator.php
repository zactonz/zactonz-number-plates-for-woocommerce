<?php
/**
 * The storefront configurator.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Storefront;

use Zactonz\NumberPlates\Plugin;
use Zactonz\NumberPlates\Profile\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the plate fields and the live preview on the product page.
 *
 * The fields are rendered through woocommerce_before_add_to_cart_button, which places
 * them inside the add-to-cart form on a classic theme. On a block theme using the
 * Add to Cart + Options block, WooCommerce inspects this hook's output for form
 * elements and, finding them, renders a real multipart form instead of its
 * Interactivity API path - so the same markup works on both, at the cost of the
 * no-reload add to cart. That fallback is WooCommerce's own, not a workaround.
 */
final class Configurator {

	public const FIELD = 'zctz_np';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the configurator assets on plate products only.
	 */
	public function enqueue(): void {
		if ( ! is_product() ) {
			return;
		}

		$product_id = (int) get_the_ID();
		$profile    = Plugin::profile_for_product( $product_id );

		if ( null === $profile ) {
			return;
		}

		wp_enqueue_style(
			'zctz-np',
			ZCTZ_NP_URL . 'assets/css/configurator.css',
			array(),
			\Zactonz\NumberPlates\VERSION
		);

		wp_enqueue_script(
			'zctz-np',
			ZCTZ_NP_URL . 'assets/js/configurator.js',
			array(),
			\Zactonz\NumberPlates\VERSION,
			true
		);

		wp_localize_script(
			'zctz-np',
			'zctzNumberPlates',
			array(
				'endpoint' => esc_url_raw( rest_url( Rest::ROUTE_NAMESPACE . '/preview' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'product'  => $product_id,
				'strings'  => array(
					'checking' => __( 'Checking…', 'zactonz-number-plates-for-woocommerce' ),
					'failed'   => __( 'The preview could not be updated. Your plate is still checked when you add it to the basket.', 'zactonz-number-plates-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Renders the configurator.
	 */
	public function render(): void {
		global $product;

		// Simple only, as the product panel is; the object here is already loaded.
		if ( $product instanceof \WC_Product && ! $product->is_type( 'simple' ) ) {
			return;
		}

		$product_id = $product instanceof \WC_Product ? (int) $product->get_id() : (int) get_the_ID();
		$profile    = Plugin::profile_for_product( $product_id );

		if ( null === $profile ) {
			return;
		}

		$template = (string) get_post_meta( $product_id, \Zactonz\NumberPlates\Admin\ProductSettings::META_TEMPLATE, true );

		Templates::render( '' !== $template ? $template : Templates::default_template(), $profile, $product_id );
	}

	/**
	 * Reads and normalises the configurator's submitted values.
	 *
	 * Shared by the cart so that the storefront and the cart agree on exactly what a
	 * submission looks like.
	 *
	 * @param array<string, mixed> $source Raw source, usually $_POST.
	 * @return array<string, string> Normalised values.
	 */
	public static function read( array $source ): array {
		$raw = isset( $source[ self::FIELD ] ) && is_array( $source[ self::FIELD ] ) ? $source[ self::FIELD ] : array();
		$out = array();

		foreach ( array( 'variant', 'size', 'district', 'letters', 'digits' ) as $key ) {
			$value       = isset( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $raw[ $key ] ) ) : '';
			$out[ $key ] = mb_substr( trim( $value ), 0, 24 );
		}

		return $out;
	}
}
