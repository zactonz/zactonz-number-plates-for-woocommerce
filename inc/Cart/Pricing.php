<?php
/**
 * Plate pricing.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Cart;

use Zactonz\NumberPlates\Admin\ProductSettings;
use Zactonz\NumberPlates\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Prices a configured plate: the product's own price plus the surcharges it earns.
 *
 * Size and plate type are priced separately because they cost the merchant separately -
 * a 520 mm blank is more material than a 180 mm one, and an E or H plate carries an
 * extra marking. Both are optional, and a shop that charges one flat price simply
 * leaves them at zero.
 *
 * The price is recomputed from the stored recipe on every totals pass rather than
 * frozen into the cart item, so a merchant who corrects a surcharge sees it take effect
 * in carts that already hold the item, and nothing stale can be checked out.
 */
final class Pricing {

	public const META_SIZE    = '_zctz_np_price_size';
	public const META_VARIANT = '_zctz_np_price_variant';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply' ), 20 );
	}

	/**
	 * Applies the configured price to every plate line in the cart.
	 *
	 * @param mixed $cart Cart, as WooCommerce passes it.
	 */
	public function apply( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			$plate   = isset( $item[ ItemData::KEY ] ) && is_array( $item[ ItemData::KEY ] ) ? $item[ ItemData::KEY ] : array();
			$product = $item['data'] ?? null;

			/*
			 * Simple only, read off the object the cart already holds so the check costs
			 * nothing. A line of any other type has a price this class has no basis to
			 * overwrite: a variation's figure is not the parent's.
			 */
			if ( array() === $plate || ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
				continue;
			}

			$product_id = (int) ( $item['product_id'] ?? 0 );

			if ( null === Plugin::profile_for_product( $product_id ) ) {
				continue;
			}

			$product->set_price( (string) self::price( $product_id, $plate ) );
		}
	}

	/**
	 * Returns what a configured plate costs.
	 *
	 * @param int                   $product_id Product identifier.
	 * @param array<string, string> $plate      Plate values.
	 * @return float Price.
	 */
	public static function price( int $product_id, array $plate ): float {
		return max( 0.0, self::base( $product_id ) + self::surcharge( $product_id, $plate ) );
	}

	/**
	 * Returns the surcharges a configured plate earns.
	 *
	 * @param int                   $product_id Product identifier.
	 * @param array<string, string> $plate      Plate values.
	 * @return float Surcharge.
	 */
	public static function surcharge( int $product_id, array $plate ): float {
		$sizes    = self::table( $product_id, self::META_SIZE );
		$variants = self::table( $product_id, self::META_VARIANT );

		$size    = (string) ( $plate['size'] ?? '' );
		$variant = (string) ( $plate['variant'] ?? '' );

		return (float) ( $sizes[ $size ] ?? 0.0 ) + (float) ( $variants[ $variant ] ?? 0.0 );
	}

	/**
	 * Reads the product's own price straight from its meta.
	 *
	 * Deliberately not read from the cart item's product object. That object is the one
	 * this class calls set_price() on, and WooCommerce runs the totals hook more than
	 * once per request, so reading it back would compound the surcharge on every pass.
	 * The stored _price is the figure WooCommerce itself maintains for a simple product,
	 * sale price included, and it is untouched by anything this class does.
	 *
	 * @param int $product_id Product identifier.
	 * @return float Base price.
	 */
	private static function base( int $product_id ): float {
		$stored = get_post_meta( $product_id, '_price', true );

		if ( '' !== $stored && null !== $stored ) {
			return (float) wc_format_decimal( (string) $stored );
		}

		$product = wc_get_product( $product_id );

		return $product instanceof \WC_Product ? (float) $product->get_regular_price() : 0.0;
	}

	/**
	 * Reads one surcharge table off the product.
	 *
	 * @param int    $product_id Product identifier.
	 * @param string $key        Meta key.
	 * @return array<string, float> Surcharge per key.
	 */
	public static function table( int $product_id, string $key ): array {
		$stored = get_post_meta( $product_id, $key, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$out = array();

		foreach ( $stored as $slug => $amount ) {
			$out[ (string) $slug ] = (float) wc_format_decimal( (string) $amount );
		}

		return $out;
	}

	/**
	 * Saves the surcharge tables from a submitted product form.
	 *
	 * Called by ProductSettings, which has already checked capability and nonce.
	 *
	 * @param int                  $product_id Product identifier.
	 * @param array<string, mixed> $source     Raw submitted values.
	 */
	public static function save( int $product_id, array $source ): void {
		$profile = \Zactonz\NumberPlates\Profile\Profile::get(
			(string) get_post_meta( $product_id, ProductSettings::META_PROFILE, true )
		);

		$allowed = array(
			self::META_SIZE    => null === $profile ? array() : array_keys( $profile->sizes() ),
			self::META_VARIANT => null === $profile ? array() : array_keys( $profile->variants() ),
		);

		foreach ( $allowed as $meta_key => $keys ) {
			$raw = isset( $source[ $meta_key ] ) && is_array( $source[ $meta_key ] ) ? $source[ $meta_key ] : array();
			$out = array();

			// Only keys the profile actually declares are stored, so a stale or forged
			// field cannot leave an orphan surcharge behind on the product.
			foreach ( $keys as $key ) {
				$value = isset( $raw[ $key ] ) ? wc_format_decimal( wp_unslash( (string) $raw[ $key ] ) ) : '';

				if ( '' !== $value && 0.0 !== (float) $value ) {
					$out[ (string) $key ] = $value;
				}
			}

			if ( array() === $out ) {
				delete_post_meta( $product_id, $meta_key );
				continue;
			}

			update_post_meta( $product_id, $meta_key, $out );
		}
	}
}
