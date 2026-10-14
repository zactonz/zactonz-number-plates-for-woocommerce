<?php
/**
 * Product-level plate settings.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Admin;

use Zactonz\NumberPlates\Cart\Pricing;
use Zactonz\NumberPlates\Profile\Profile;
use Zactonz\NumberPlates\Storefront\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Number plate panel to the WooCommerce product editor.
 *
 * Binding is deliberately per product rather than global: a shop sells plates
 * alongside frames, bulbs and fixings, and only some of its catalogue is configurable.
 */
final class ProductSettings {

	public const META_ENABLED  = '_zctz_np_enabled';
	public const META_PROFILE  = '_zctz_np_profile';
	public const META_TEMPLATE = '_zctz_np_template';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
	}

	/**
	 * Adds the panel's tab.
	 *
	 * @param array<string, mixed> $tabs Existing tabs.
	 * @return array<string, mixed> Tabs.
	 */
	public function add_tab( array $tabs ): array {
		$tabs['zctz_np'] = array(
			'label'    => __( 'Number plate', 'zactonz-number-plates-for-woocommerce' ),
			'target'   => 'zctz_np_product_data',
			'class'    => array( 'show_if_simple' ),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * Renders the panel.
	 */
	public function render_panel(): void {
		global $post;

		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$enabled    = 'yes' === get_post_meta( $product_id, self::META_ENABLED, true );
		$profile    = (string) get_post_meta( $product_id, self::META_PROFILE, true );
		$profiles   = Profile::available();

		echo '<div id="zctz_np_product_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_ENABLED,
				'value'       => $enabled ? 'yes' : 'no',
				'label'       => __( 'Sell as a number plate', 'zactonz-number-plates-for-woocommerce' ),
				'description' => __( 'Shoppers configure a registration on the product page and the order carries production artwork.', 'zactonz-number-plates-for-woocommerce' ),
			)
		);

		if ( array() === $profiles ) {
			echo '<p class="form-field">' . esc_html__( 'No country profile is installed.', 'zactonz-number-plates-for-woocommerce' ) . '</p>';
		} else {
			woocommerce_wp_select(
				array(
					'id'          => self::META_PROFILE,
					'value'       => '' !== $profile ? $profile : (string) array_key_first( $profiles ),
					'label'       => __( 'Country', 'zactonz-number-plates-for-woocommerce' ),
					'options'     => $profiles,
					'description' => __( 'Decides the permitted formats, sizes, typeface and validation rules.', 'zactonz-number-plates-for-woocommerce' ),
				)
			);
		}

		$template  = (string) get_post_meta( $product_id, self::META_TEMPLATE, true );
		$templates = Templates::available();

		woocommerce_wp_select(
			array(
				'id'          => self::META_TEMPLATE,
				'value'       => isset( $templates[ $template ] ) ? $template : Templates::default_template(),
				'label'       => __( 'Layout', 'zactonz-number-plates-for-woocommerce' ),
				'options'     => $templates,
				'description' => __( 'How the configurator is arranged on the product page. All three collect the same values.', 'zactonz-number-plates-for-woocommerce' ),
			)
		);

		echo '</div>';

		$this->surcharges( $product_id, '' !== $profile ? $profile : (string) array_key_first( $profiles ) );

		echo '</div>';
	}

	/**
	 * Renders the optional per-size and per-plate-type surcharges.
	 *
	 * @param int    $product_id Product identifier.
	 * @param string $profile_id Profile identifier.
	 */
	private function surcharges( int $product_id, string $profile_id ): void {
		$profile = Profile::get( $profile_id );

		if ( null === $profile ) {
			return;
		}

		$sizes    = Pricing::table( $product_id, Pricing::META_SIZE );
		$variants = Pricing::table( $product_id, Pricing::META_VARIANT );

		echo '<div class="options_group">';

		printf(
			'<p class="form-field"><strong>%s</strong><br /><span class="description">%s</span></p>',
			esc_html__( 'Surcharges', 'zactonz-number-plates-for-woocommerce' ),
			esc_html__( 'Added to the product price for the size and plate type the shopper chooses. Leave blank for one flat price.', 'zactonz-number-plates-for-woocommerce' )
		);

		foreach ( $profile->sizes() as $key => $size ) {
			woocommerce_wp_text_input(
				array(
					'id'          => Pricing::META_SIZE . '_' . $key,
					'name'        => Pricing::META_SIZE . '[' . $key . ']',
					'value'       => isset( $sizes[ $key ] ) ? (string) wc_format_localized_price( $sizes[ $key ] ) : '',
					'label'       => sprintf(
						/* translators: 1: plate width in millimetres, 2: plate height in millimetres. */
						__( 'Size %1$d x %2$d mm', 'zactonz-number-plates-for-woocommerce' ),
						(int) $size['w'],
						(int) $size['h']
					),
					'data_type'   => 'price',
					'desc_tip'    => false,
					'placeholder' => wc_format_localized_price( 0 ),
				)
			);
		}

		foreach ( $profile->variants() as $key => $variant ) {
			woocommerce_wp_text_input(
				array(
					'id'          => Pricing::META_VARIANT . '_' . $key,
					'name'        => Pricing::META_VARIANT . '[' . $key . ']',
					'value'       => isset( $variants[ $key ] ) ? (string) wc_format_localized_price( $variants[ $key ] ) : '',
					'label'       => sprintf(
						/* translators: %s: plate type label, for example Standard or Motorcycle. */
						__( 'Type: %s', 'zactonz-number-plates-for-woocommerce' ),
						(string) ( $variant['label'] ?? $key )
					),
					'data_type'   => 'price',
					'desc_tip'    => false,
					'placeholder' => wc_format_localized_price( 0 ),
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Saves the panel.
	 *
	 * WooCommerce verifies the product nonce and capability before firing this action,
	 * but both are checked again here: this method is reachable by any code that fires
	 * the same hook, and a mistake would let an untrusted request flip a product into
	 * plate mode.
	 *
	 * @param int $product_id Product identifier.
	 */
	public function save( int $product_id ): void {
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['woocommerce_meta_nonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
			return;
		}

		/*
		 * A plate is one made-to-order thing at one price, so the panel is offered on
		 * simple products only. The switch is cleared when the product is saved as any
		 * other type, because hiding the panel does not clear it: a product left in
		 * plate mode and then made variable would price every variation from the
		 * parent's own figure and still offer the configurator.
		 */
		$type    = isset( $_POST['product-type'] ) ? sanitize_title( wp_unslash( (string) $_POST['product-type'] ) ) : 'simple';
		$enabled = ( isset( $_POST[ self::META_ENABLED ] ) && 'simple' === $type ) ? 'yes' : 'no';

		update_post_meta( $product_id, self::META_ENABLED, $enabled );

		$profile  = isset( $_POST[ self::META_PROFILE ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::META_PROFILE ] ) ) : '';
		$profiles = Profile::available();

		if ( isset( $profiles[ $profile ] ) ) {
			update_post_meta( $product_id, self::META_PROFILE, $profile );
		}

		$template = isset( $_POST[ self::META_TEMPLATE ] ) ? sanitize_key( wp_unslash( (string) $_POST[ self::META_TEMPLATE ] ) ) : '';

		if ( isset( Templates::available()[ $template ] ) ) {
			update_post_meta( $product_id, self::META_TEMPLATE, $template );
		}

		// Saved after the profile, because which surcharge keys are valid depends on it.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified above.
		Pricing::save( $product_id, $_POST );
	}
}
