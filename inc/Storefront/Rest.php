<?php
/**
 * The preview endpoint.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Storefront;

use Zactonz\NumberPlates\Cart\Pricing;
use Zactonz\NumberPlates\Plugin;
use Zactonz\NumberPlates\Render\Svg;
use Zactonz\NumberPlates\Validation\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a plate and draws it, in one request.
 *
 * The preview is rendered here rather than in the browser on purpose. The claim this
 * product rests on is that what a shopper approves is what gets manufactured, and the
 * only way to guarantee that is for the preview and the production artwork to come out
 * of the same renderer. A JavaScript re-implementation would be a second layout engine
 * free to drift from the first.
 */
final class Rest {

	public const ROUTE_NAMESPACE = 'zctz-np/v1';

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public function routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				/*
				 * Open by design: shoppers are not logged in, and the endpoint only
				 * echoes a drawing of values the caller already supplied. It reads no
				 * user data, writes nothing, and its work is bounded by the field
				 * lengths the schema enforces below.
				 */
				'permission_callback' => '__return_true',
				'args'                => $this->args(),
			)
		);
	}

	/**
	 * @return array<string, mixed> Argument schema.
	 */
	private function args(): array {
		$text = static function ( $value ): string {
			return mb_substr( sanitize_text_field( (string) $value ), 0, 24 );
		};

		return array(
			'product'  => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'variant'  => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => $text ),
			'size'     => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => $text ),
			'district' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => $text ),
			'letters'  => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => $text ),
			'digits'   => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => $text ),
		);
	}

	/**
	 * Validates and renders.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response Response.
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request->get_param( 'product' );
		$profile    = Plugin::profile_for_product( $product_id );

		if ( null === $profile ) {
			return new \WP_REST_Response(
				array(
					'valid'  => false,
					'errors' => array( __( 'This product is not a number plate.', 'zactonz-number-plates-for-woocommerce' ) ),
					'svg'    => '',
					'price'  => '',
				),
				200
			);
		}

		$plate = array(
			'variant'  => (string) $request->get_param( 'variant' ),
			'size'     => (string) $request->get_param( 'size' ),
			'district' => (string) $request->get_param( 'district' ),
			'letters'  => (string) $request->get_param( 'letters' ),
			'digits'   => (string) $request->get_param( 'digits' ),
		);

		$errors = ( new Validator( $profile ) )->validate( $plate );
		$svg    = '';

		if ( array() === $errors ) {
			$renderer = new Svg( $profile );

			/*
			 * Shapes, exactly as the production artwork uses, so the preview is the same
			 * drawing rather than a second one that happens to agree. Set as text, the
			 * shopper's plate would depend on the browser fetching and honouring the
			 * typeface - a content policy, an asset rewriter or a blocked font leaves the
			 * glyph positions right and the letterforms wrong, and the shopper approves
			 * it. Shapes also mean a product page fetches no font file at all.
			 */
			$svg = $renderer->render( $plate, array( 'outline' => true ) );

			/*
			 * A registration can satisfy every rule and still not fit the size chosen.
			 * The regulation's answer is the narrower Engschrift face, which no licence
			 * permits redistributing, so none is bundled; a merchant who holds one adds
			 * it through zctz_np_profile_faces and these registrations then render.
			 * Until then, saying so beats drawing something that overflows.
			 */
			if ( '' === $svg ) {
				$errors[] = __( 'That registration does not fit this plate size. Choose a larger size.', 'zactonz-number-plates-for-woocommerce' );
			}
		}

		/*
		 * The price travels with the preview because the two answer the same question -
		 * what the shopper is about to buy - and a shopper who picks a larger plate
		 * should not have to reach the cart to discover that it costs more.
		 */
		$price = array() === $errors ? wc_price( Pricing::price( $product_id, $plate ) ) : '';

		return new \WP_REST_Response(
			array(
				'valid'  => array() === $errors,
				'errors' => array_values( $errors ),
				'svg'    => $svg,
				'price'  => $price,
			),
			200
		);
	}
}
