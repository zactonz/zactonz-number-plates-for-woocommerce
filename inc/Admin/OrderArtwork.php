<?php
/**
 * Production artwork on the order screen.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Admin;

use Zactonz\NumberPlates\Cart\ItemData;
use Zactonz\NumberPlates\Plugin;
use Zactonz\NumberPlates\Render\Svg;
use Zactonz\NumberPlates\Storefront\Image;

defined( 'ABSPATH' ) || exit;

/**
 * Gives the merchant the file they manufacture from.
 *
 * The artwork is rebuilt from the recipe stored on the line item rather than fetched
 * from storage, because the recipe is what the shopper approved and the renderer is the
 * same one that drew their preview. An order therefore carries no file to go missing,
 * and a later correction to the renderer reaches orders placed before it.
 *
 * The font is embedded in the download. A production file that depends on the shop
 * still being reachable to look right is not a production file.
 */
final class OrderArtwork {

	public const ACTION = 'zctz_np_artwork';

	/**
	 * Thumbnail height on the order screen, in pixels.
	 */
	public const THUMBNAIL_HEIGHT = 64;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'link' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
	}

	/**
	 * Adds the download link under a plate line item.
	 *
	 * @param int   $item_id Order item identifier.
	 * @param mixed $item    Order item.
	 */
	public function link( $item_id, $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$stored = json_decode( (string) $item->get_meta( ItemData::ORDER_META, true ), true );

		if ( ! is_array( $stored ) ) {
			return;
		}

		$profile = Plugin::profile_for_item( $item );

		if ( null !== $profile ) {
			$thumbnail = Image::thumbnail(
				$profile,
				$stored,
				Image::order_url( (int) $item_id, Image::thumbnail_format(), self::THUMBNAIL_HEIGHT * 2 ),
				Image::order_url( (int) $item_id, 'svg' ),
				self::THUMBNAIL_HEIGHT
			);

			// Escaped where it is built; this hook's output is not passed through kses.
			echo '<p class="zctz-np-thumb-wrap">' . $thumbnail . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by Image::thumbnail().
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'item'   => (int) $item_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . (int) $item_id
		);

		printf(
			'<p><a href="%s" class="button button-small">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Download plate artwork (SVG)', 'zactonz-number-plates-for-woocommerce' )
		);
	}

	/**
	 * Sends the artwork.
	 */
	public function download(): void {
		$item_id = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';

		if ( 0 === $item_id || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $item_id ) ) {
			wp_die( esc_html__( 'That link has expired. Reload the order and try again.', 'zactonz-number-plates-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$item = new \WC_Order_Item_Product( $item_id );

		if ( 0 === $item->get_id() ) {
			wp_die( esc_html__( 'That order line no longer exists.', 'zactonz-number-plates-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		/*
		 * Checked against the order the line belongs to, not the shop at large: a shop
		 * manager restricted to some orders must not read a plate off another one.
		 */
		if ( ! current_user_can( 'edit_shop_order', $item->get_order_id() ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this order.', 'zactonz-number-plates-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$plate = json_decode( (string) $item->get_meta( ItemData::ORDER_META, true ), true );

		if ( ! is_array( $plate ) ) {
			wp_die( esc_html__( 'This order line carries no plate configuration.', 'zactonz-number-plates-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		$profile = Plugin::profile_for_item( $item );

		if ( null === $profile ) {
			wp_die( esc_html__( 'The country profile this plate was configured under is no longer installed.', 'zactonz-number-plates-for-woocommerce' ), '', array( 'response' => 409 ) );
		}

		$svg = ( new Svg( $profile ) )->render( $plate, array( 'outline' => true, 'embed_font' => true ) );

		if ( '' === $svg ) {
			wp_die( esc_html__( 'This plate cannot be drawn with the typefaces now installed.', 'zactonz-number-plates-for-woocommerce' ), '', array( 'response' => 409 ) );
		}

		// Same reasoning as the image endpoint: a buffered notice would be prepended to
		// the file the merchant manufactures from.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->filename( $item, $profile->registration( $plate ) ) . '"' );
		header( 'Content-Length: ' . strlen( $svg ) );
		header( 'X-Content-Type-Options: nosniff' );

		// Our own generated SVG, sent as a file rather than into a page.
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG this plugin generated, sent as a downloaded file with its own content type, never into a page.

		exit;
	}

	/**
	 * Builds a filename the merchant can recognise in a downloads folder.
	 *
	 * @param \WC_Order_Item_Product $item         Order item.
	 * @param string                 $registration The plate, written out.
	 * @return string Filename.
	 */
	private function filename( \WC_Order_Item_Product $item, string $registration ): string {
		$name = sprintf(
			'plate-%d-%s',
			$item->get_order_id(),
			'' !== $registration ? str_replace( ' ', '-', $registration ) : (string) $item->get_id()
		);

		return sanitize_file_name( $name . '.svg' );
	}
}
