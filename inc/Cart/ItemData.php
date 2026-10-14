<?php
/**
 * Carrying the plate from the product page to the order.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Cart;

use Zactonz\NumberPlates\Plugin;
use Zactonz\NumberPlates\Profile\Profile;
use Zactonz\NumberPlates\Render\Svg;
use Zactonz\NumberPlates\Storefront\Configurator;
use Zactonz\NumberPlates\Storefront\Image;
use Zactonz\NumberPlates\Validation\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches the configured plate to the cart item and then to the order line.
 *
 * What is stored is the recipe - variant, size, district, letters, digits - and never
 * the drawing. The artwork is derived from the recipe whenever it is asked for, so an
 * order stays small, and a later improvement to the renderer reaches orders that were
 * placed before it.
 *
 * woocommerce_add_cart_item_data is deliberately the entry point: WooCommerce's own
 * Store API applies the same filter in CartController::filter_request_data(), so this
 * one implementation covers both the classic form post and the block checkout.
 */
final class ItemData {

	public const KEY        = 'zctz_np';
	public const ORDER_META = '_zctz_np_plate';

	/**
	 * Thumbnail height, in CSS pixels. The raster is fetched at twice this so it stays
	 * sharp on a high-density screen.
	 */
	public const THUMBNAIL_HEIGHT = 56;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add' ), 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'attach' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display' ), 10, 2 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'reorder' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( $this, 'check_cart_api' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist' ), 10, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_recipe' ) );
	}

	/**
	 * Carries the plate forward when a shopper orders the same thing again.
	 *
	 * WooCommerce rebuilds the cart from the order's lines and hands each one through
	 * this filter. Without it the plate is left behind: the add-to-cart validation
	 * finds no submitted values, refuses the line, and the shopper is told to enter a
	 * district code they never had the chance to type.
	 *
	 * @param array<string, mixed> $cart_item_data Cart item data being assembled.
	 * @param mixed                $item           Order line item.
	 * @return array<string, mixed> Cart item data.
	 */
	public function reorder( $cart_item_data, $item ): array {
		$cart_item_data = is_array( $cart_item_data ) ? $cart_item_data : array();

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return $cart_item_data;
		}

		$profile = Plugin::profile_for_item( $item );

		if ( null === $profile ) {
			return $cart_item_data;
		}

		$plate = json_decode( (string) $item->get_meta( self::ORDER_META, true ), true );

		if ( ! is_array( $plate ) || array() !== $this->problems( $profile, $plate ) ) {
			return $cart_item_data;
		}

		$cart_item_data[ self::KEY ] = $this->normalise( $profile, $plate );

		return $cart_item_data;
	}

	/**
	 * Stops a plate line that cannot be manufactured from reaching an order.
	 *
	 * Everything is checked when the line is added, but a cart outlives the moment it
	 * was filled: a merchant can switch the product's country or stop selling plates
	 * while the item sits there, and a line added by anything that bypassed the
	 * configurator carries no plate at all. The output of this plugin is a physical
	 * object, so a line that can no longer be drawn is refused at the checkout rather
	 * than left for the merchant to discover on the shop floor.
	 *
	 * Reported twice over, because the two checkouts ask differently: the classic one
	 * reads notices, the Store API wants a WP_Error. WooCommerce fires its own hook
	 * first and stops there if anything was added to it, so only one of the two ever
	 * speaks in a given request and the shopper is never told twice.
	 */
	public function check_cart(): void {
		foreach ( $this->unmakeable() as $problem ) {
			wc_add_notice( $problem, 'error' );
		}
	}

	/**
	 * The same check, in the form the Store API asks for.
	 *
	 * @param mixed $errors WP_Error the Store API collects into.
	 */
	public function check_cart_api( $errors ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}

		foreach ( $this->unmakeable() as $problem ) {
			$errors->add( 'zctz_np_unmakeable', $problem );
		}
	}

	/**
	 * Returns one sentence for every plate line in the cart that could not be made.
	 *
	 * @return array<int, string> Problems, ready to show a shopper.
	 */
	private function unmakeable(): array {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return array();
		}

		$problems = array();

		foreach ( WC()->cart->get_cart() as $item ) {
			$profile = Plugin::profile_for_product( (int) ( $item['product_id'] ?? 0 ) );

			if ( null === $profile ) {
				continue;
			}

			$plate  = isset( $item[ self::KEY ] ) && is_array( $item[ self::KEY ] ) ? $item[ self::KEY ] : array();
			$errors = array() === $plate
				? array( __( 'No registration was configured.', 'zactonz-number-plates-for-woocommerce' ) )
				: $this->problems( $profile, $plate );

			if ( array() === $errors ) {
				continue;
			}

			$problems[] = sprintf(
				/* translators: 1: product name, 2: the reason the plate cannot be made. */
				__( '%1$s cannot be made as configured: %2$s Remove it and configure it again.', 'zactonz-number-plates-for-woocommerce' ),
				$item['data'] instanceof \WC_Product ? $item['data']->get_name() : '',
				$errors[0]
			);
		}

		return $problems;
	}

	/**
	 * Keeps the machine-readable recipe out of the order screen.
	 *
	 * The line already carries the same plate as four readable rows. Printing the JSON
	 * beside them shows the merchant nothing they cannot already read and makes the
	 * line harder to scan; the underscore prefix alone does not hide it here, because
	 * the admin order view lists meta the storefront would suppress.
	 *
	 * @param array<int, string> $hidden Meta keys hidden on the order screen.
	 * @return array<int, string> Meta keys.
	 */
	public function hide_recipe( $hidden ): array {
		$hidden   = is_array( $hidden ) ? $hidden : array();
		$hidden[] = self::ORDER_META;

		return $hidden;
	}

	/**
	 * Refuses an invalid plate before it reaches the cart.
	 *
	 * The plate does not always arrive in $_POST. WooCommerce passes cart item data to
	 * this filter on the paths that do not come from a form - the Store API's add-to-cart
	 * request, and the cart WooCommerce rebuilds when a shopper orders again - and on
	 * those the submitted values are empty. Reading $_POST alone refused both.
	 *
	 * @param bool                 $passed         Whether validation has passed so far.
	 * @param int                  $product_id     Product identifier.
	 * @param int                  $quantity       Quantity.
	 * @param int                  $variation_id   Variation identifier.
	 * @param array<string, mixed> $variation      Chosen variation attributes.
	 * @param array<string, mixed> $cart_item_data Cart item data, on the paths that supply it.
	 * @return bool Whether the item may be added.
	 */
	public function validate_add( $passed, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ): bool {
		unset( $quantity, $variation_id, $variation );

		$profile = Plugin::profile_for_product( (int) $product_id );

		if ( null === $profile ) {
			return (bool) $passed;
		}

		$supplied = is_array( $cart_item_data ) && isset( $cart_item_data[ self::KEY ] ) && is_array( $cart_item_data[ self::KEY ] )
			? $cart_item_data[ self::KEY ]
			: array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce owns the add-to-cart request and its nonce; this filter only inspects the values it already accepted.
		$plate  = array() !== $supplied ? $supplied : Configurator::read( $_POST );
		$errors = $this->problems( $profile, $plate );

		if ( array() === $errors ) {
			return (bool) $passed;
		}

		foreach ( $errors as $error ) {
			wc_add_notice( $error, 'error' );
		}

		return false;
	}

	/**
	 * Attaches the plate to the cart item.
	 *
	 * @param array<string, mixed> $cart_item_data Existing cart item data.
	 * @param int                  $product_id     Product identifier.
	 * @param int                  $variation_id   Variation identifier.
	 * @return array<string, mixed> Cart item data.
	 */
	public function attach( $cart_item_data, $product_id, $variation_id ): array {
		unset( $variation_id );

		$cart_item_data = is_array( $cart_item_data ) ? $cart_item_data : array();
		$profile        = Plugin::profile_for_product( (int) $product_id );

		if ( null === $profile ) {
			return $cart_item_data;
		}

		/*
		 * On the classic form post the values arrive in $_POST. On the Store API path
		 * a caller may instead have placed them in cart_item_data already, which is
		 * why the existing value wins when it is present.
		 */
		$existing = isset( $cart_item_data[ self::KEY ] ) && is_array( $cart_item_data[ self::KEY ] )
			? $cart_item_data[ self::KEY ]
			: array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see validate_add(); WooCommerce has already authenticated this request.
		$plate = array() !== $existing ? $existing : Configurator::read( $_POST );

		if ( array() !== $this->problems( $profile, $plate ) ) {
			return $cart_item_data;
		}

		$cart_item_data[ self::KEY ] = $this->normalise( $profile, $plate );

		return $cart_item_data;
	}

	/**
	 * Shows the plate in the cart, the checkout and the order emails.
	 *
	 * @param array<int, array<string, mixed>> $item_data Existing rows.
	 * @param array<string, mixed>             $cart_item Cart item.
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function display( $item_data, $cart_item ): array {
		$item_data = is_array( $item_data ) ? $item_data : array();
		$plate     = isset( $cart_item[ self::KEY ] ) && is_array( $cart_item[ self::KEY ] ) ? $cart_item[ self::KEY ] : array();

		if ( array() === $plate ) {
			return $item_data;
		}

		$profile = Plugin::profile_for_product( (int) ( $cart_item['product_id'] ?? 0 ) );
		$key     = (string) ( $cart_item['key'] ?? '' );

		if ( null !== $profile && '' !== $key ) {
			/*
			 * The Cart and Checkout blocks read this through the Store API and then
			 * strip the image out of the details, because their renderer allows no
			 * images there - but the browser has already begun downloading it by then,
			 * so the shop renders and serves a picture that is thrown away, once per
			 * line, on every view. Those surfaces show the plate as the line's own
			 * thumbnail instead, so here they get the link alone.
			 *
			 * A REST request is the Store API; a page render is the classic template,
			 * which does show the image.
			 */
			$over_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;

			$thumbnail = Image::thumbnail(
				$profile,
				$plate,
				$over_rest ? '' : Image::cart_url( $key, Image::thumbnail_format(), self::THUMBNAIL_HEIGHT * 2 ),
				Image::cart_url( $key, 'svg' ),
				self::THUMBNAIL_HEIGHT
			);

			if ( '' !== $thumbnail ) {
				/*
				 * raw_key is what WooCommerce's own schema asks extensions to set, so a
				 * Store API client can find this entry without matching a label that
				 * has been translated out from under it.
				 */
				$item_data[] = array(
					'key'     => __( 'Your plate', 'zactonz-number-plates-for-woocommerce' ),
					'raw_key' => 'zctz_np_preview',
					'value'   => '',
					'display' => $thumbnail,
				);
			}
		}

		foreach ( $this->summary( $profile, $plate ) as $raw => $row ) {
			$item_data[] = array(
				'key'     => $row['label'],
				'raw_key' => $raw,
				'value'   => $row['value'],
				'display' => '',
			);
		}

		return $item_data;
	}

	/**
	 * Writes the plate onto the order line item.
	 *
	 * @param \WC_Order_Item_Product $item          Order line item.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array<string, mixed>   $values        Cart item values.
	 * @param \WC_Order              $order         Order.
	 */
	public function persist( $item, $cart_item_key, $values, $order ): void {
		unset( $cart_item_key, $order );

		$plate = isset( $values[ self::KEY ] ) && is_array( $values[ self::KEY ] ) ? $values[ self::KEY ] : array();

		if ( array() === $plate ) {
			return;
		}

		$profile = Plugin::profile_for_product( (int) $item->get_product_id() );

		// Machine-readable, for the renderer and any downstream production system.
		$item->add_meta_data( self::ORDER_META, wp_json_encode( $plate ), true );

		// Human-readable, so the order screen and the emails read without decoding.
		foreach ( $this->summary( $profile, $plate ) as $row ) {
			$item->add_meta_data( $row['label'], $row['value'], true );
		}
	}

	/**
	 * Returns the problems with a plate, including whether it can actually be drawn.
	 *
	 * @param Profile              $profile Country profile.
	 * @param array<string, string> $plate   Plate values.
	 * @return array<int, string> Problems.
	 */
	private function problems( Profile $profile, array $plate ): array {
		$errors = ( new Validator( $profile ) )->validate( $plate );

		if ( array() !== $errors ) {
			return $errors;
		}

		if ( null === ( new Svg( $profile ) )->layout( $plate ) ) {
			return array( __( 'That registration does not fit this plate size. Choose a larger size.', 'zactonz-number-plates-for-woocommerce' ) );
		}

		return array();
	}

	/**
	 * Returns the recipe as it is stored, with the rules it was configured under.
	 *
	 * The profile travels with the plate so the order line is self-describing. It is
	 * what keeps a placed order manufacturable after the product it came from has been
	 * switched to another country, taken out of plate mode, or deleted.
	 *
	 * @param Profile               $profile Country profile.
	 * @param array<string, string> $plate   Plate values.
	 * @return array<string, string> Normalised plate values.
	 */
	private function normalise( Profile $profile, array $plate ): array {
		return array(
			'profile'  => $profile->id(),
			'variant'  => (string) ( $plate['variant'] ?? '' ),
			'size'     => (string) ( $plate['size'] ?? '' ),
			// Trimmed as well as uppercased, so what is stored is what the validator saw.
			'district' => mb_strtoupper( trim( (string) ( $plate['district'] ?? '' ) ) ),
			'letters'  => mb_strtoupper( trim( (string) ( $plate['letters'] ?? '' ) ) ),
			'digits'   => trim( (string) ( $plate['digits'] ?? '' ) ),
		);
	}

	/**
	 * Builds the human-readable rows shown to shopper and merchant.
	 *
	 * Keyed by a stable identifier rather than by the label, so a client can find a row
	 * without matching text that translation will change, while the label travels with
	 * it for display.
	 *
	 * @param Profile|null          $profile Country profile.
	 * @param array<string, string> $plate   Plate values.
	 * @return array<string, array{label: string, value: string}> Rows.
	 */
	private function summary( ?Profile $profile, array $plate ): array {
		/*
		 * Without a profile there is no way to know the plate type's marking, so the
		 * registration is written from the values alone. That path is reached only by an
		 * order line whose product has gone, and it loses a trailing E or H rather than
		 * the registration.
		 */
		$registration = $profile instanceof Profile
			? $profile->registration( $plate )
			: trim(
				sprintf(
					'%s %s %s',
					(string) ( $plate['district'] ?? '' ),
					(string) ( $plate['letters'] ?? '' ),
					(string) ( $plate['digits'] ?? '' )
				)
			);

		$rows = array(
			'zctz_np_registration' => array(
				'label' => __( 'Registration', 'zactonz-number-plates-for-woocommerce' ),
				'value' => $registration,
			),
		);

		if ( ! $profile instanceof Profile ) {
			return $rows;
		}

		$variant = $profile->variant( (string) ( $plate['variant'] ?? '' ) );
		$size    = $profile->size( (string) ( $plate['size'] ?? '' ) );

		if ( null !== $variant ) {
			$rows['zctz_np_variant'] = array(
				'label' => __( 'Plate type', 'zactonz-number-plates-for-woocommerce' ),
				'value' => (string) ( $variant['label'] ?? '' ),
			);
		}

		if ( null !== $size ) {
			$rows['zctz_np_size'] = array(
				'label' => __( 'Size', 'zactonz-number-plates-for-woocommerce' ),
				'value' => sprintf(
					/* translators: 1: plate width in millimetres, 2: plate height in millimetres. */
					__( '%1$d × %2$d mm', 'zactonz-number-plates-for-woocommerce' ),
					(int) $size['w'],
					(int) $size['h']
				),
			);
		}

		$district = $profile->district( (string) ( $plate['district'] ?? '' ) );

		if ( null !== $district ) {
			$rows['zctz_np_district'] = array(
				'label' => __( 'District', 'zactonz-number-plates-for-woocommerce' ),
				'value' => (string) $district['district'],
			);
		}

		return $rows;
	}
}
