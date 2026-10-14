<?php
/**
 * The plate on an order the shopper reads.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Storefront;

use Zactonz\NumberPlates\Cart\ItemData;
use Zactonz\NumberPlates\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the plate on the order confirmation, in the account, and in the email.
 *
 * One hook covers all three: woocommerce_order_item_meta_end fires from the order
 * details template, which the thank-you page and the account's order view both use,
 * and from the email items template. It is handed a flag saying whether this is the
 * plain-text email, which is the one place a picture cannot go.
 */
final class OrderDisplay {

	/**
	 * Thumbnail height, in pixels.
	 */
	public const HEIGHT = 64;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render' ), 10, 4 );
	}

	/**
	 * Renders the plate under an order line.
	 *
	 * @param int   $item_id    Order item identifier.
	 * @param mixed $item       Order item.
	 * @param mixed $order      Order.
	 * @param bool  $plain_text Whether this is the plain-text email.
	 */
	public function render( $item_id, $item, $order, $plain_text = false ): void {
		unset( $order );

		if ( $plain_text ) {
			return;
		}

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$stored = json_decode( (string) $item->get_meta( ItemData::ORDER_META, true ), true );

		if ( ! is_array( $stored ) ) {
			return;
		}

		$profile = Plugin::profile_for_item( $item );

		if ( null === $profile ) {
			return;
		}

		$id = (int) $item_id;

		$markup = Image::thumbnail(
			$profile,
			$stored,
			Image::order_url( $id, Image::thumbnail_format(), self::HEIGHT * 2 ),
			Image::order_url( $id, 'svg' ),
			self::HEIGHT
		);

		if ( '' === $markup ) {
			return;
		}

		/*
		 * Already escaped where it is built, and it has to reach an email as markup:
		 * WooCommerce runs no kses over this hook's output.
		 */
		echo '<div class="zctz-np-thumb-wrap">' . $markup . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by Image::thumbnail().
	}
}
