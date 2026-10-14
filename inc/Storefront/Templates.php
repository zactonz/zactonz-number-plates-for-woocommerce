<?php
/**
 * The three configurator layouts.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Storefront;

use Zactonz\NumberPlates\Profile\Profile;
use Zactonz\NumberPlates\Render\Svg;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the configurator in one of three arrangements.
 *
 * The same fields, the same names, the same data attributes and the same script drive
 * all three: only the arrangement differs. That is the whole point - a merchant can
 * match their theme without a developer, and a change to how a plate is validated or
 * drawn reaches every layout at once because there is only one of each.
 *
 * Controls are radio groups rather than dropdowns. A shopper comparing four plate types
 * should see four plate types, not open a menu to find out what exists; and a radio
 * group is what a set of mutually exclusive choices is, so it keyboards and announces
 * correctly without any help.
 *
 * They are also plain, visible radios and plain labels, styled by the theme. This
 * plugin arranges things and sets widths; it does not decide what an input or a button
 * looks like. A plugin that paints its own controls looks wrong in every theme except
 * the one it was written against, and goes on looking wrong after the shop is
 * redesigned.
 */
final class Templates {

	/**
	 * Returns the layouts a merchant may choose from.
	 *
	 * @return array<string, string> Identifier to label.
	 */
	public static function available(): array {
		/**
		 * Filters the configurator layouts offered on the product editor.
		 *
		 * A layout added here must be rendered by the zctz_np_render_template filter.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, string> $templates Identifier to label.
		 */
		return (array) apply_filters(
			'zctz_np_templates',
			array(
				'stacked' => __( 'Stacked - preview above the fields', 'zactonz-number-plates-for-woocommerce' ),
				'studio'  => __( 'Studio - preview beside the fields', 'zactonz-number-plates-for-woocommerce' ),
				'guided'  => __( 'Guided - one step at a time', 'zactonz-number-plates-for-woocommerce' ),
			)
		);
	}

	/**
	 * Returns the layout used when a product names none.
	 *
	 * @return string Layout identifier.
	 */
	public static function default_template(): string {
		/**
		 * Filters the configurator layout used by products that do not name one.
		 *
		 * A shop selling many plate products sets its house layout here in one line
		 * rather than on every product.
		 *
		 * @since 1.1.0
		 *
		 * @param string $template Layout identifier.
		 */
		$template = (string) apply_filters( 'zctz_np_default_template', 'stacked' );

		return isset( self::available()[ $template ] ) ? $template : 'stacked';
	}

	/**
	 * Renders one layout.
	 *
	 * @param string  $template   Layout identifier.
	 * @param Profile $profile    Country profile.
	 * @param int     $product_id Product identifier.
	 */
	public static function render( string $template, Profile $profile, int $product_id ): void {
		$template = isset( self::available()[ $template ] ) ? $template : self::default_template();

		/**
		 * Filters the markup of the configurator.
		 *
		 * Returning a string short-circuits the built-in layouts, which is how a layout
		 * added through zctz_np_templates is drawn.
		 *
		 * @since 1.1.0
		 *
		 * @param string|null $markup     Markup, or null to use a built-in layout.
		 * @param string      $template   Layout identifier.
		 * @param Profile     $profile    Country profile.
		 * @param int         $product_id Product identifier.
		 */
		$custom = apply_filters( 'zctz_np_render_template', null, $template, $profile, $product_id );

		if ( is_string( $custom ) ) {
			echo wp_kses_post( $custom );

			return;
		}

		printf( '<div class="zctz-np zctz-np--%s" data-zctz-np>', esc_attr( $template ) );

		if ( 'studio' === $template ) {
			self::studio( $profile );
		} elseif ( 'guided' === $template ) {
			self::guided( $profile );
		} else {
			self::stacked( $profile );
		}

		echo '</div>';
	}

	/**
	 * Preview above, fields in one column under it.
	 *
	 * @param Profile $profile Country profile.
	 */
	private static function stacked( Profile $profile ): void {
		self::preview( $profile );
		self::variants( $profile );
		self::sizes( $profile );
		self::registration( $profile );
	}

	/**
	 * Preview held beside the controls.
	 *
	 * @param Profile $profile Country profile.
	 */
	private static function studio( Profile $profile ): void {
		/*
		 * The split lives on an inner element so the outer one can be the query
		 * container: an element cannot size itself against its own container query.
		 */
		echo '<div class="zctz-np__split">';

		echo '<div class="zctz-np__stage">';
		self::preview( $profile );
		echo '</div>';

		echo '<div class="zctz-np__controls">';
		self::variants( $profile );
		self::sizes( $profile );
		self::registration( $profile );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Three steps, one open at a time.
	 *
	 * The accordion is a details element per step sharing one name, which makes the
	 * browser itself keep a single step open. No script: a step that will not collapse
	 * because JavaScript failed is still a usable form, and on a browser without
	 * exclusive details the steps simply all open, which is the stacked layout again.
	 *
	 * @param Profile $profile Country profile.
	 */
	private static function guided( Profile $profile ): void {
		self::preview( $profile );

		$steps = array(
			array(
				'label'  => __( 'Plate type', 'zactonz-number-plates-for-woocommerce' ),
				'render' => static function () use ( $profile ): void {
					self::variants( $profile, false );
				},
			),
			array(
				'label'  => __( 'Size', 'zactonz-number-plates-for-woocommerce' ),
				'render' => static function () use ( $profile ): void {
					self::sizes( $profile, false );
				},
			),
			array(
				'label'  => __( 'Registration', 'zactonz-number-plates-for-woocommerce' ),
				'render' => static function () use ( $profile ): void {
					self::registration( $profile );
				},
			),
		);

		foreach ( $steps as $index => $step ) {
			/*
			 * The summary keeps its native disclosure marker and the theme's own type.
			 * Replacing it with a styled badge would mean this plugin deciding what a
			 * heading looks like, which is the theme's job.
			 */
			printf(
				'<details class="zctz-np__step" name="zctz-np-step"%s><summary class="zctz-np__step-summary">%s</summary>'
				. '<div class="zctz-np__step-body">',
				0 === $index ? ' open' : '',
				esc_html(
					sprintf(
						/* translators: 1: step number, 2: step name, for example "Plate type". */
						__( '%1$d. %2$s', 'zactonz-number-plates-for-woocommerce' ),
						(int) $index + 1,
						(string) $step['label']
					)
				)
			);

			$step['render']();

			echo '</div></details>';
		}
	}

	/**
	 * The plate, the problems with it, and what it costs.
	 *
	 * The plate is drawn empty on the server before anything is typed, rather than the
	 * box being left blank until the first keystroke. It shows the shopper what they
	 * are configuring, it reserves exactly the height the filled plate will take so
	 * nothing jumps when it arrives, and on a page where the script never runs it is
	 * still a picture of the product.
	 *
	 * @param Profile $profile Country profile.
	 */
	private static function preview( Profile $profile ): void {
		$variants = $profile->variants();
		$sizes    = $profile->sizes();

		// Shapes, for the reasons set out where the preview endpoint renders.
		$blank = ( new Svg( $profile ) )->render(
			array(
				'variant'  => (string) array_key_first( $variants ),
				'size'     => (string) array_key_first( $sizes ),
				'district' => '',
				'letters'  => '',
				'digits'   => '',
			),
			array( 'outline' => true )
		);

		/*
		 * Deliberately not a live region. A live region announces its subtree, and a
		 * screen reader walking an SVG would spell the plate out a character at a time.
		 * The announcement is made from the status line below instead, which says one
		 * sentence this plugin controls.
		 */
		// Built by our own renderer from a fixed shape, and kses would strip the svg.
		echo '<div class="zctz-np__preview" data-zctz-np-preview>' . $blank . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG this plugin generated; every value in it is escaped where it is written.
		echo '<p class="zctz-np__errors" data-zctz-np-errors role="alert"></p>';
		echo '<p class="zctz-np__price" data-zctz-np-price aria-live="polite"></p>';
		echo '<p class="zctz-np__sr-only" data-zctz-np-status role="status"></p>';
	}

	/**
	 * The plate types.
	 *
	 * @param Profile $profile Country profile.
	 * @param bool    $legend  Whether to show the group's legend.
	 */
	private static function variants( Profile $profile, bool $legend = true ): void {
		$variants = $profile->variants();
		$first    = (string) array_key_first( $variants );

		self::open_group( __( 'Plate type', 'zactonz-number-plates-for-woocommerce' ), 'zctz-np__options', $legend );

		foreach ( $variants as $key => $variant ) {
			$id = 'zctz-np-variant-' . sanitize_html_class( (string) $key );

			printf(
				'<span class="zctz-np__option"><input type="radio" id="%1$s" name="%2$s[variant]" value="%3$s" '
				. 'data-zctz-np-variant data-sizes="%4$s"%5$s /><label for="%1$s">%6$s</label></span>',
				esc_attr( $id ),
				esc_attr( Configurator::FIELD ),
				esc_attr( (string) $key ),
				esc_attr( implode( ',', (array) ( $variant['sizes'] ?? array() ) ) ),
				(string) $key === $first ? ' checked="checked"' : '',
				esc_html( (string) ( $variant['label'] ?? $key ) )
			);
		}

		self::close_group();
	}

	/**
	 * The plate sizes.
	 *
	 * Every size the profile defines is written out, and the script hides the ones the
	 * chosen type does not allow. Rendering only the allowed ones would mean the page
	 * had to be rebuilt each time the type changed.
	 *
	 * @param Profile $profile Country profile.
	 * @param bool    $legend  Whether to show the group's legend.
	 */
	private static function sizes( Profile $profile, bool $legend = true ): void {
		$sizes = $profile->sizes();
		$first = (string) array_key_first( $sizes );

		self::open_group( __( 'Size', 'zactonz-number-plates-for-woocommerce' ), 'zctz-np__options zctz-np__options--sizes', $legend );

		foreach ( $sizes as $key => $size ) {
			$id    = 'zctz-np-size-' . sanitize_html_class( (string) $key );
			$lines = 1 === (int) $size['lines']
				? __( 'one line', 'zactonz-number-plates-for-woocommerce' )
				: __( 'two lines', 'zactonz-number-plates-for-woocommerce' );

			/*
			 * The line count is part of the label's own text rather than a span styled
			 * smaller, so the theme styles one label and this plugin styles nothing.
			 */
			printf(
				'<span class="zctz-np__option" data-zctz-np-size-option="%1$s"><input type="radio" id="%2$s" name="%3$s[size]" '
				. 'value="%1$s" data-zctz-np-size data-key="%1$s"%4$s /><label for="%2$s">%5$s</label></span>',
				esc_attr( (string) $key ),
				esc_attr( $id ),
				esc_attr( Configurator::FIELD ),
				(string) $key === $first ? ' checked="checked"' : '',
				esc_html(
					sprintf(
						/* translators: 1: plate width in mm, 2: plate height in mm, 3: "one line" or "two lines". */
						__( '%1$d × %2$d mm — %3$s', 'zactonz-number-plates-for-woocommerce' ),
						(int) $size['w'],
						(int) $size['h'],
						$lines
					)
				)
			);
		}

		self::close_group();
	}

	/**
	 * The registration itself.
	 *
	 * @param Profile $profile Country profile.
	 */
	private static function registration( Profile $profile ): void {
		$fields = $profile->fields();

		echo '<div class="zctz-np__registration">';

		foreach ( array(
			'district' => __( 'District', 'zactonz-number-plates-for-woocommerce' ),
			'letters'  => __( 'Letters', 'zactonz-number-plates-for-woocommerce' ),
			'digits'   => __( 'Numbers', 'zactonz-number-plates-for-woocommerce' ),
		) as $key => $label ) {
			$max = (int) ( $fields[ $key ]['max'] ?? 4 );

			printf(
				'<p class="zctz-np__field"><label for="zctz-np-%1$s">%2$s</label>'
				. '<input type="text" id="zctz-np-%1$s" name="%3$s[%1$s]" maxlength="%4$d" autocomplete="off" '
				. 'spellcheck="false" inputmode="%5$s" data-zctz-np-input="%1$s" value="" /></p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( Configurator::FIELD ),
				absint( $max ),
				esc_attr( 'digits' === $key ? 'numeric' : 'text' )
			);
		}

		echo '</div>';
	}

	/**
	 * Opens a radio group.
	 *
	 * @param string $label   Group label.
	 * @param string $classes Classes for the options container.
	 * @param bool   $legend  Whether to show the legend. A step already carries its own
	 *                        heading, so repeating it there would announce it twice.
	 */
	private static function open_group( string $label, string $classes, bool $legend ): void {
		echo '<fieldset class="zctz-np__group">';

		printf(
			'<legend class="zctz-np__legend%s">%s</legend>',
			$legend ? '' : ' zctz-np__sr-only',
			esc_html( $label )
		);

		printf( '<div class="%s">', esc_attr( $classes ) );
	}

	/**
	 * Closes a radio group.
	 */
	private static function close_group(): void {
		echo '</div></fieldset>';
	}
}
