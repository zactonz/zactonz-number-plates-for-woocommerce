<?php
/**
 * Builds the WordPress.org listing icon and banner.
 *
 * Usage:
 *   php tools/build-listing-assets.php
 *
 * Writes into .wporg/, which packaging excludes: these files belong in the assets
 * directory of the plugin's SVN checkout, not in the plugin a merchant installs.
 *
 * The artwork is drawn from the plugin's own profile data and its own renderer, so the
 * plate on the listing is the plate the plugin makes - the same millimetre geometry,
 * the same Euro field, the same typeface outlines. A mocked-up plate drawn by hand
 * would be a picture of a promise rather than of the product.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root = dirname( __DIR__ );

define( 'ABSPATH', $root . '/' );
define( 'ZCTZ_NP_DIR', $root );
define( 'ZCTZ_NP_URL', 'https://example.test/' );

foreach ( array( 'esc_attr', 'esc_html' ) as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( 'function ' . $fn . '( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, "UTF-8" ); }' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) {
		return (string) $u;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

spl_autoload_register(
	static function ( string $class_name ) use ( $root ): void {
		$prefix = 'Zactonz\\NumberPlates\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$path = $root . '/inc/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

use Zactonz\NumberPlates\Profile\Profile;
use Zactonz\NumberPlates\Render\Svg;

$profile = Profile::get( 'de' );

if ( null === $profile ) {
	fwrite( STDERR, "the German profile will not load\n" );
	exit( 1 );
}

$renderer = new Svg( $profile );
$out      = $root . '/.wporg';

if ( ! is_dir( $out ) && ! mkdir( $out, 0755, true ) ) {
	fwrite( STDERR, "cannot create .wporg\n" );
	exit( 1 );
}

/**
 * Strips the outer svg element off a rendered plate so it can be placed in another one.
 *
 * @param string $markup Rendered plate.
 * @return string Inner markup.
 */
function zctz_np_plate_body( string $markup ): string {
	$inner = preg_replace( '~^<svg[^>]*>~', '', $markup );
	$inner = preg_replace( '~</svg>$~', '', (string) $inner );

	return (string) $inner;
}

$colours = $profile->colours();
$ground  = (string) ( $colours['ground'] ?? '#ffffff' );

// ---------------------------------------------------------------- the icon
//
// A two-line plate, because a 520x110 one laid in a square leaves a thin strip and
// nothing legible at 128px. Drawn by the real renderer, then placed on a plain ground
// with the plugin's own corner radius.
$icon_plate = $renderer->render(
	array(
		'variant'  => 'standard',
		'size'     => 'zweizeilig-340',
		'district' => 'B',
		'letters'  => 'ZT',
		'digits'   => '26',
	),
	array( 'outline' => true )
);

if ( '' === $icon_plate ) {
	fwrite( STDERR, "the icon plate will not render\n" );
	exit( 1 );
}

$icon_scale = 196 / 340;
$icon_x     = ( 256 - ( 340 * $icon_scale ) ) / 2;
$icon_y     = ( 256 - ( 200 * $icon_scale ) ) / 2;

$icon = sprintf(
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256" role="img" '
	. 'aria-label="Zactonz Number Plates for WooCommerce">'
	. '<rect width="256" height="256" rx="56" fill="#f2f2ef"/>'
	. '<g transform="translate(%s %s) scale(%s)">%s</g>'
	. '</svg>',
	round( $icon_x, 2 ),
	round( $icon_y, 2 ),
	round( $icon_scale, 5 ),
	zctz_np_plate_body( $icon_plate )
);

file_put_contents( $out . '/icon.svg', $icon );
printf( "icon.svg            %6d bytes\n", (int) filesize( $out . '/icon.svg' ) );

// -------------------------------------------------------------- the banner
//
// One single-line plate, at its real proportions, with the name beside it. Nothing is
// claimed in the artwork that the plugin does not do.
foreach ( array( array( 772, 250 ), array( 1544, 500 ) ) as $size ) {
	list( $width, $height ) = $size;
	$k = $width / 772;

	/*
	 * Seven positions rather than four. The renderer justifies a run across the plate,
	 * putting the surplus in the seal gap, so a short registration on a 520 mm plate
	 * leaves a wide hole in the middle - true to how a real plate is set, and poor as
	 * a banner.
	 */
	$banner_plate = $renderer->render(
		array(
			'variant'  => 'standard',
			'size'     => 'einzeilig-520',
			'district' => 'B',
			'letters'  => 'ZT',
			'digits'   => '2026',
		),
		array( 'outline' => true )
	);

	if ( '' === $banner_plate ) {
		fwrite( STDERR, "the banner plate will not render\n" );
		exit( 1 );
	}

	$plate_w = 420 * $k;
	$scale   = $plate_w / 520;
	$plate_x = ( $width - $plate_w ) / 2;
	$plate_y = ( 96 * $k );

	$banner = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img" '
		. 'aria-label="Zactonz Number Plates for WooCommerce">'
		. '<rect width="%1$d" height="%2$d" fill="#f2f2ef"/>'
		. '<text x="%3$s" y="%4$s" text-anchor="middle" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" '
		. 'font-size="%5$s" font-weight="600" fill="#15161a">Number Plates for WooCommerce</text>'
		. '<text x="%3$s" y="%6$s" text-anchor="middle" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" '
		. 'font-size="%7$s" fill="#5a5c63">Shoppers configure a plate. The order carries the artwork.</text>'
		. '<g transform="translate(%8$s %9$s) scale(%10$s)">%11$s</g>'
		. '</svg>',
		$width,
		$height,
		round( $width / 2, 2 ),
		round( 52 * $k, 2 ),
		round( 30 * $k, 2 ),
		round( 78 * $k, 2 ),
		round( 16 * $k, 2 ),
		round( $plate_x, 2 ),
		round( $plate_y, 2 ),
		round( $scale, 5 ),
		zctz_np_plate_body( $banner_plate )
	);

	$name = sprintf( '%s/banner-%dx%d.svg', $out, $width, $height );
	file_put_contents( $name, $banner );
	printf( "%-19s %6d bytes\n", basename( $name ), (int) filesize( $name ) );
}

printf( "\nSVG written. Rasterise to the PNG sizes the directory serves with:\n" );
printf( "  rsvg-convert -w 256 .wporg/icon.svg          -o .wporg/icon-256x256.png\n" );
printf( "  rsvg-convert -w 128 .wporg/icon.svg          -o .wporg/icon-128x128.png\n" );
printf( "  rsvg-convert -w 772 .wporg/banner-772x250.svg  -o .wporg/banner-772x250.png\n" );
printf( "  rsvg-convert -w 1544 .wporg/banner-1544x500.svg -o .wporg/banner-1544x500.png\n" );
