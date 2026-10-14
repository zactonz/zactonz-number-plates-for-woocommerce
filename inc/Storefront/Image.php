<?php
/**
 * Plate images for the cart, the order and the email.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Storefront;

use Zactonz\NumberPlates\Cart\ItemData;
use Zactonz\NumberPlates\Plugin;
use Zactonz\NumberPlates\Render\Png;
use Zactonz\NumberPlates\Render\Svg;

defined( 'ABSPATH' ) || exit;

/**
 * Serves a picture of a configured plate, and builds the URLs that point at it.
 *
 * Shoppers should see the plate they configured everywhere it is mentioned - in the
 * cart, at checkout, in the confirmation email, and in their account - not just a list
 * of the values they typed.
 *
 * Three things shape how this works.
 *
 * Item meta is passed through wp_kses_post before it is displayed, both by WooCommerce's
 * templates and by the Store API, which strips an inline SVG and a data: URI alike; so
 * the picture has to be an <img> with an ordinary URL behind it.
 *
 * A registration is the shopper's own data, so it is not put in that URL: an order line
 * is addressed by its id and a signature, and a cart line by its session key.
 *
 * And this is a front-end response rather than a REST route, which it was first written
 * as. WordPress ignores cookie authentication on a REST request that carries no nonce,
 * so the route saw every shopper as logged out, WooCommerce started a fresh guest
 * session, and the cart it looked in was always empty. An image in an <img> tag cannot
 * carry a nonce header, and a guest's cart is identified by the WooCommerce session
 * cookie rather than by a login, so the request belongs where that session is already
 * loaded: template_redirect, after WooCommerce has set the cart up.
 */
final class Image {

	/**
	 * The query argument that asks for a plate picture.
	 */
	public const QUERY = 'zctz_np_plate';

	/**
	 * The tallest raster this endpoint will draw. Nothing here asks for more; the
	 * vector is there for anyone who wants the plate larger than this.
	 */
	public const MAX_HEIGHT = 480;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
		add_filter( 'woocommerce_store_api_cart_item_images', array( $this, 'cart_item_image' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	/**
	 * Loads the thumbnail's few rules where a thumbnail can appear.
	 *
	 * The configurator's stylesheet is deliberately confined to product pages, and the
	 * cart, the checkout and the account are none of those.
	 */
	public function enqueue(): void {
		$wanted = ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );

		if ( ! $wanted ) {
			return;
		}

		$this->enqueue_style();
	}

	/**
	 * Loads the same rules on the order screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_admin( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen instanceof \WP_Screen ? $screen->id : (string) $hook;

		if ( false === strpos( $id, 'wc-orders' ) && 'shop_order' !== $id ) {
			return;
		}

		$this->enqueue_style();
	}

	/**
	 * Registers and enqueues the stylesheet.
	 */
	private function enqueue_style(): void {
		wp_enqueue_style(
			'zctz-np-thumb',
			ZCTZ_NP_URL . 'assets/css/thumbnail.css',
			array(),
			\Zactonz\NumberPlates\VERSION
		);
	}

	/**
	 * Makes the configured plate the line item's own picture in the Cart and Checkout blocks.
	 *
	 * Those blocks render line item details through a component that permits a, b, em,
	 * i, strong, br, abbr and span and no images at all, so the plate cannot be put in
	 * the details beside the registration. It can be put where the blocks already draw
	 * a picture: the thumbnail they take from the Store API. The shopper then sees the
	 * plate they configured rather than a stock photograph of a blank one.
	 *
	 * The product's own image is replaced rather than added to, because the blocks show
	 * the first image only, and on a made-to-order plate the configured plate is the
	 * more useful of the two.
	 *
	 * @param array<int, array<string, mixed>> $images        Image objects.
	 * @param array<string, mixed>             $cart_item     Cart item.
	 * @param string                           $cart_item_key Cart item key.
	 * @return array<int, object> Image objects.
	 */
	public function cart_item_image( $images, $cart_item, $cart_item_key ) {
		$images = is_array( $images ) ? $images : array();
		$plate  = isset( $cart_item[ ItemData::KEY ] ) && is_array( $cart_item[ ItemData::KEY ] ) ? $cart_item[ ItemData::KEY ] : array();
		$key    = (string) $cart_item_key;

		if ( array() === $plate || '' === $key ) {
			return $images;
		}

		$profile = Plugin::profile_for_product( (int) ( $cart_item['product_id'] ?? 0 ) );

		if ( null === $profile ) {
			return $images;
		}

		$label = $profile->registration( $plate );

		/*
		 * An object, not an array: WooCommerce validates what comes back from this
		 * filter with property access - $image->id, $image->thumbnail, $image->src -
		 * and quietly discards anything that fails, logging a warning and falling back
		 * to the product's own images. Both URLs must carry a host for the same reason.
		 */
		$image = new \stdClass();

		$image->id        = 0;
		$image->src       = self::cart_url( $key, self::thumbnail_format(), 320 );
		$image->thumbnail = self::cart_url( $key, self::thumbnail_format(), 220 );
		$image->srcset    = '';
		$image->sizes     = '';
		$image->name      = $label;
		$image->alt       = sprintf(
			/* translators: %s: the registration, for example FU AB 123. */
			__( 'Number plate %s', 'zactonz-number-plates-for-woocommerce' ),
			$label
		);

		return array( $image );
	}

	/**
	 * Returns the signature that makes an order line's image addressable.
	 *
	 * Tied to the site's own salt and to the line, so it cannot be guessed and does not
	 * carry over to another order. It is deliberately stable rather than expiring: an
	 * order email is read, and re-read, long after it was sent.
	 *
	 * @param int $item_id Order item identifier.
	 * @return string Signature.
	 */
	public static function token( int $item_id ): string {
		return substr( hash_hmac( 'sha256', 'zctz_np_plate|' . $item_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Builds the URL of an order line's plate picture.
	 *
	 * @param int    $item_id Order item identifier.
	 * @param string $format  'png' or 'svg'.
	 * @param int    $height  Height in pixels, for the raster.
	 * @return string URL.
	 */
	public static function order_url( int $item_id, string $format = 'png', int $height = 120 ): string {
		return add_query_arg(
			array(
				self::QUERY => 1,
				'item'   => $item_id,
				'token'  => self::token( $item_id ),
				'format' => 'svg' === $format ? 'svg' : 'png',
				'h'      => $height,
			),
			home_url( '/' )
		);
	}

	/**
	 * Builds the URL of a cart line's plate picture.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param string $format        'png' or 'svg'.
	 * @param int    $height        Height in pixels, for the raster.
	 * @return string URL.
	 */
	public static function cart_url( string $cart_item_key, string $format = 'png', int $height = 120 ): string {
		return add_query_arg(
			array(
				self::QUERY => 1,
				'cart_item' => $cart_item_key,
				'format'    => 'svg' === $format ? 'svg' : 'png',
				'h'         => $height,
			),
			home_url( '/' )
		);
	}

	/**
	 * Returns the format to use for a thumbnail here.
	 *
	 * Raster wherever it can be produced, because the one place a thumbnail has to work
	 * without negotiation is an email, and no mail client of consequence draws SVG.
	 * Using the same format everywhere else keeps one code path rather than two.
	 *
	 * @return string 'png' or 'svg'.
	 */
	public static function thumbnail_format(): string {
		return Png::available() ? 'png' : 'svg';
	}

	/**
	 * Builds the thumbnail markup: the picture, linked to the full vector.
	 *
	 * Sized with width and height attributes rather than a stylesheet, because this
	 * same markup goes into an order email, where no stylesheet of ours is loaded and
	 * an unsized image reflows the layout as it arrives. The attributes also reserve
	 * the space on the page, so nothing shifts while the image loads.
	 *
	 * The link carries a label as well as the picture because one surface drops the
	 * picture: WooCommerce's Cart and Checkout blocks render item details through a
	 * component whose allowlist is a, b, em, i, strong, br, abbr and span - no img, by
	 * their design, not by our oversight. There the shopper gets a working link to the
	 * plate instead of a broken-looking empty one.
	 *
	 * @param Profile               $profile Country profile.
	 * @param array<string, string> $plate   Plate values.
	 * @param string                $png     Raster URL.
	 * @param string                $svg     Vector URL, linked for a closer look.
	 * @param int                   $height  Height in pixels.
	 * @return string Markup.
	 */
	public static function thumbnail( \Zactonz\NumberPlates\Profile\Profile $profile, array $plate, string $png, string $svg, int $height ): string {
		$size = $profile->size( (string) ( $plate['size'] ?? '' ) );

		if ( null === $size || (int) $size['h'] < 1 ) {
			return '';
		}

		$width = (int) round( $height * ( (float) $size['w'] / (float) $size['h'] ) );

		$label = $profile->registration( $plate );

		$alt = sprintf(
			/* translators: %s: the registration, for example FU AB 123. */
			__( 'Number plate %s', 'zactonz-number-plates-for-woocommerce' ),
			$label
		);

		// An empty raster URL means the caller wants the link without the picture.
		$picture = '' === $png
			? ''
			: sprintf(
				'<img src="%1$s" width="%2$d" height="%3$d" alt="%4$s" class="zctz-np-thumb__img" />',
				esc_url( $png ),
				$width,
				$height,
				esc_attr( $alt )
			);

		return sprintf(
			'<a href="%1$s" class="zctz-np-thumb" target="_blank" rel="noopener">%2$s'
			. '<span class="zctz-np-thumb__label">%3$s</span></a>',
			esc_url( $svg ),
			$picture,
			esc_html__( 'View full size', 'zactonz-number-plates-for-woocommerce' )
		);
	}

	/**
	 * Reads one query argument as a string, and treats anything else as absent.
	 *
	 * A query string can say token[]=abc as easily as token=abc, and casting the array
	 * that produces to string is a PHP warning. On a host with display_errors on that
	 * warning is written into the response, which hands an anonymous caller the
	 * plugin's absolute path; with it off it is still an unbounded anonymous write to
	 * the error log. This endpoint answers on every front-end URL, so both are reachable
	 * by anyone. An argument that is not a scalar is not one of ours.
	 *
	 * @param string $name Query argument.
	 * @return string Value, or an empty string when absent or not a scalar.
	 */
	private static function param( string $name ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only image request; what authorises it is the signature checked below or the requester's own session, not a form submission.
		if ( ! isset( $_GET[ $name ] ) || ! is_scalar( $_GET[ $name ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Answers a request for a plate picture, and ignores every other page view.
	 */
	public function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only image request; what authorises it is the signature below or the requester's own session, not a form submission.
		if ( ! isset( $_GET[ self::QUERY ] ) ) {
			return;
		}

		$item_id = absint( self::param( 'item' ) );
		$given   = self::param( 'token' );
		$key     = self::param( 'cart_item' );
		$format  = 'svg' === self::param( 'format' ) ? 'svg' : 'png';
		$height  = '' === self::param( 'h' ) ? 120 : absint( self::param( 'h' ) );

		$plate   = null;
		$profile = null;

		if ( $item_id > 0 ) {
			if ( ! hash_equals( self::token( $item_id ), $given ) ) {
				$this->refuse( 403 );
			}

			$item = new \WC_Order_Item_Product( $item_id );

			if ( 0 === $item->get_id() ) {
				$this->refuse( 404 );
			}

			$stored  = json_decode( (string) $item->get_meta( ItemData::ORDER_META, true ), true );
			$plate   = is_array( $stored ) ? $stored : null;
			$profile = Plugin::profile_for_item( $item );
		} elseif ( '' !== $key ) {
			$found = $this->cart_item( $key );

			if ( null !== $found ) {
				$plate   = $found['plate'];
				$profile = Plugin::profile_for_product( $found['product_id'] );
			}
		}

		if ( null === $plate || null === $profile ) {
			$this->refuse( 404 );
		}

		/*
		 * Rastering is the one expensive thing this plugin does, and this endpoint is
		 * open, so the height a caller may ask for is capped at more than anything here
		 * requests. Without a cap, h=1200 is a six-megapixel canvas per request, which
		 * is a way to make a shop's own server do a lot of work for nothing.
		 */
		$height = min( self::MAX_HEIGHT, max( 24, $height ) );

		/*
		 * The drawing is a pure function of the recipe, the profile and the size asked
		 * for, so the validator can be built from those and answered before anything is
		 * drawn. A caller that already holds the picture is then told so for the cost of
		 * reading the request, instead of paying for a raster that is thrown away. The
		 * plugin version is in the key so a renderer change invalidates it.
		 */
		$etag = $this->etag( $profile->id(), $plate, $format, $height );

		if ( $this->already_held( $etag ) ) {
			$this->not_modified( $etag );
		}

		if ( 'svg' === $format ) {
			$body = ( new Svg( $profile ) )->render( $plate, array( 'outline' => true, 'embed_font' => true ) );
			$type = 'image/svg+xml';
		} else {
			$body = ( new Png( $profile ) )->render( $plate, $height );
			$type = 'image/png';

			// A host without GD still gets a picture, just a vector one.
			if ( '' === $body ) {
				$body = ( new Svg( $profile ) )->render( $plate, array( 'outline' => true, 'embed_font' => true ) );
				$type = 'image/svg+xml';
			}
		}

		if ( '' === $body ) {
			$this->refuse( 409 );
		}

		$this->send( $body, $type, $etag );
	}

	/**
	 * Builds the validator for a picture, from the things that decide what it looks like.
	 *
	 * @param string               $profile_id Profile identifier.
	 * @param array<string, mixed> $plate      Plate recipe.
	 * @param string               $format     'png' or 'svg'.
	 * @param int                  $height     Height in pixels.
	 * @return string Entity tag, quoted.
	 */
	private function etag( string $profile_id, array $plate, string $format, int $height ): string {
		return '"' . md5(
			implode(
				'|',
				array(
					\Zactonz\NumberPlates\VERSION,
					$profile_id,
					$format,
					(string) $height,
					(string) wp_json_encode( $plate ),
				)
			)
		) . '"';
	}

	/**
	 * Whether the caller says it already holds this picture.
	 *
	 * @param string $etag Entity tag.
	 * @return bool Whether the caller's copy is current.
	 */
	private function already_held( string $etag ): bool {
		if ( ! isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			return false;
		}

		$sent = trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) ) );

		// A proxy may revalidate several at once, and may have weakened the tag.
		foreach ( explode( ',', $sent ) as $candidate ) {
			if ( ltrim( trim( $candidate ), 'W/' ) === $etag ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells the caller its copy is still good.
	 *
	 * @param string $etag Entity tag.
	 */
	private function not_modified( string $etag ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 304 );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: ' . self::cache_control() );
		exit;
	}

	/**
	 * Returns how long, and to whom, this picture may be cached.
	 *
	 * An order's picture is addressed by a signature that does not expire, so a shared
	 * cache may hold it. A cart's picture is authorised by the requester's own session -
	 * the key only resolves against their cart - and a response whose authorisation is
	 * the session is not one to let a proxy store and hand to somebody else, whatever
	 * the bytes would have been.
	 *
	 * @return string Cache-Control value.
	 */
	private static function cache_control(): string {
		$scope = '' === self::param( 'cart_item' ) ? 'public' : 'private';

		return $scope . ', max-age=31536000, immutable';
	}

	/**
	 * Ends the request without saying which of the possible reasons applied.
	 *
	 * @param int $status HTTP status.
	 */
	private function refuse( int $status ): void {
		status_header( $status );
		nocache_headers();
		exit;
	}

	/**
	 * Writes the image and stops.
	 *
	 * @param string $body Image bytes.
	 * @param string $type Media type.
	 * @param string $etag Entity tag, computed before the drawing.
	 */
	private function send( string $body, string $type, string $etag ): void {
		/*
		 * Anything already buffered would be prepended to the image and corrupt it - a
		 * stray notice from any plugin on the site is enough, and it is not hypothetical:
		 * a deprecation notice from GD did exactly that during development. A file
		 * response starts at the first byte of the file.
		 */
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: ' . $type );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: ' . self::cache_control() );
		header( 'ETag: ' . $etag );

		// Image bytes this plugin generated, sent as a file with its own content type.
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image payload, never markup in a page.

		exit;
	}

	/**
	 * Finds a plate on the requester's own cart.
	 *
	 * The cart is session-scoped, so a key only resolves for the shopper it belongs to;
	 * that is the access check. REST requests do not load the cart on their own, which
	 * is why it is loaded here, exactly as the Store API does.
	 *
	 * @param string $key Cart item key.
	 * @return array{plate: array<string, string>, product_id: int}|null Plate and product.
	 */
	private function cart_item( string $key ): ?array {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		/*
		 * The same two steps WooCommerce's own Store API takes, and both are needed:
		 * wc_load_cart() builds the cart object, but the thing that fills it from the
		 * session is hooked to wp_loaded, which fired long before a REST request got
		 * here. Calling get_cart() is what actually loads it - without it the cart is
		 * real but empty, and every plate looks as though it had vanished.
		 */
		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( null === WC()->cart ) {
			return null;
		}

		WC()->cart->get_cart();

		$item = WC()->cart->get_cart_item( $key );

		if ( ! is_array( $item ) || ! isset( $item[ ItemData::KEY ] ) || ! is_array( $item[ ItemData::KEY ] ) ) {
			return null;
		}

		return array(
			'plate'      => $item[ ItemData::KEY ],
			'product_id' => (int) ( $item['product_id'] ?? 0 ),
		);
	}
}
