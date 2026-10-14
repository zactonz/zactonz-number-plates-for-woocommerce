<?php
/**
 * Plugin wiring.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates;

use Zactonz\NumberPlates\Admin\OrderArtwork;
use Zactonz\NumberPlates\Admin\ProductSettings;
use Zactonz\NumberPlates\Cart\ItemData;
use Zactonz\NumberPlates\Cart\Pricing;
use Zactonz\NumberPlates\Storefront\Configurator;
use Zactonz\NumberPlates\Storefront\Image;
use Zactonz\NumberPlates\Storefront\OrderDisplay;
use Zactonz\NumberPlates\Storefront\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin's pieces.
 *
 * Each piece registers its own hooks and is independently loadable, so a site can
 * switch one off with a filter without the rest noticing.
 */
final class Plugin {

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * @return self The shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers everything.
	 */
	public function boot(): void {
		foreach ( $this->modules() as $key => $module ) {
			/**
			 * Filters whether one of the plugin's modules loads.
			 *
			 * @since 1.0.0
			 *
			 * @param bool $load Whether to load the module.
			 */
			if ( ! apply_filters( "zctz_np_load_{$key}", true ) ) {
				continue;
			}

			$module->register();
		}
	}

	/**
	 * @return array<string, object> Modules keyed by short name.
	 */
	private function modules(): array {
		return array(
			'admin'        => new ProductSettings(),
			'artwork'      => new OrderArtwork(),
			'configurator' => new Configurator(),
			'cart'         => new ItemData(),
			'pricing'      => new Pricing(),
			'rest'         => new Rest(),
			'image'        => new Image(),
			'orderdisplay' => new OrderDisplay(),
		);
	}

	/**
	 * Returns the profile a product is configured for, if it is a plate product at all.
	 *
	 * Shared by the storefront, the cart and the REST route so that the question
	 * "is this a plate, and whose rules apply" is answered in exactly one place.
	 *
	 * @param int $product_id Product identifier.
	 * @return Profile\Profile|null The profile, or null when the product is not a plate.
	 */
	public static function profile_for_product( int $product_id ): ?Profile\Profile {
		if ( $product_id <= 0 ) {
			return null;
		}

		if ( 'yes' !== get_post_meta( $product_id, ProductSettings::META_ENABLED, true ) ) {
			return null;
		}

		/*
		 * A product nobody may see is not a plate product to anyone who may not see it.
		 * The preview route is open by design and takes a product id from the caller, so
		 * without this an anonymous caller could walk the id space and read back which
		 * unpublished products are plates and what they are priced at. Editors still get
		 * a working configurator on a draft they are allowed to read.
		 */
		if ( 'publish' !== get_post_status( $product_id ) && ! current_user_can( 'read_post', $product_id ) ) {
			return null;
		}

		$id = (string) get_post_meta( $product_id, ProductSettings::META_PROFILE, true );

		return Profile\Profile::get( '' !== $id ? $id : 'de' );
	}

	/**
	 * Returns the profile an order line was configured under.
	 *
	 * An order is a business record that has to stay manufacturable for years, so the
	 * line is asked first and the product is only the fallback, for lines written
	 * before the recipe carried its profile.
	 *
	 * Reading the product alone is not enough. Unticking "Sell as a number plate",
	 * switching the product to another country, or deleting the product outright would
	 * each take the production artwork away from every order already placed.
	 *
	 * @param \WC_Order_Item_Product $item Order line item.
	 * @return Profile\Profile|null The profile, or null when the line carries no plate.
	 */
	public static function profile_for_item( \WC_Order_Item_Product $item ): ?Profile\Profile {
		$recipe = json_decode( (string) $item->get_meta( ItemData::ORDER_META, true ), true );

		if ( ! is_array( $recipe ) ) {
			return null;
		}

		$id      = isset( $recipe['profile'] ) ? (string) $recipe['profile'] : '';
		$profile = '' !== $id ? Profile\Profile::get( $id ) : null;

		return $profile ?? self::profile_for_product( (int) $item->get_product_id() );
	}
}
