<?php
/**
 * Raster painting.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Render;

use Zactonz\NumberPlates\Font\Face;
use Zactonz\NumberPlates\Profile\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Paints a plate as PNG, from the same layout the SVG is drawn from.
 *
 * This is a second painter, not a second layout engine. Every position it draws at
 * comes out of Svg::layout() unchanged - the millimetre geometry, the chosen face, the
 * Euro field, the per-glyph advances - so a raster thumbnail and the vector artwork a
 * merchant manufactures from cannot drift apart. The only thing this class decides is
 * how many pixels a millimetre is worth.
 *
 * It exists because email clients do not render SVG: Gmail strips it and Outlook draws
 * mail with Word, which has no SVG support. A shopper's order confirmation would show
 * a broken image. Everywhere else the vector is still what is used.
 */
final class Png {

	/**
	 * The plate is drawn larger than asked for and scaled back down, which is how the
	 * rounded corners and the stars come out smooth: GD will not antialias a filled
	 * arc, but it resamples well.
	 *
	 * The multiple is chosen per request rather than fixed, because the work grows with
	 * the output area times the multiple squared. A fixed 3 cost 27ms for an email
	 * thumbnail and 266ms for a large one - the same picture, the same smoothing, ten
	 * times the work. Holding the working canvas to roughly this many pixels instead
	 * gives small output the full smoothing, where stair-stepping on a 6px star is
	 * obvious, and spends less on large output, where it is not.
	 */
	private const WORKING_PIXELS = 700000;

	/**
	 * The most the canvas may ever be multiplied by.
	 */
	private const SUPERSAMPLE_MAX = 3;

	/**
	 * GD takes a type size in points and rasterises at 96 dpi, so a size asked for in
	 * pixels comes out a third too large. Measured rather than assumed: this face's cap
	 * height is 0.6 em, and at 100pt imagettfbbox reports an 80px cap - 133.33px per em,
	 * exactly 96/72. The layout is in millimetres, so every size crossing into GD is
	 * converted here.
	 */
	private const POINTS_PER_PIXEL = 72 / 96;

	/**
	 * Country profile.
	 *
	 * @var Profile
	 */
	private Profile $profile;

	/**
	 * @param Profile $profile Country profile.
	 */
	public function __construct( Profile $profile ) {
		$this->profile = $profile;
	}

	/**
	 * Whether this installation can raster at all.
	 *
	 * @return bool Whether GD with FreeType is present.
	 */
	public static function available(): bool {
		return function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagettftext' )
			&& function_exists( 'imagepng' );
	}

	/**
	 * Renders a plate as PNG bytes.
	 *
	 * @param array<string, string> $plate  Plate values.
	 * @param int                   $height Target height in pixels.
	 * @return string PNG bytes, or an empty string when the plate cannot be rastered.
	 */
	public function render( array $plate, int $height = 120 ): string {
		if ( ! self::available() ) {
			return '';
		}

		$layout = ( new Svg( $this->profile ) )->layout( $plate );

		if ( null === $layout ) {
			return '';
		}

		$face = $layout['face'];

		if ( ! $face instanceof Face || '' === $face->sfnt() ) {
			return '';
		}

		$height = max( 24, min( 2000, $height ) );
		$mm     = (float) $height / (float) $layout['height'];
		$width  = (int) round( (float) $layout['width'] * $mm );

		$factor = self::supersample( $width, $height );
		$canvas = $this->paint( $layout, $face, $mm * $factor );

		if ( null === $canvas ) {
			return '';
		}

		$final = imagecreatetruecolor( $width, $height );

		if ( false === $final ) {
			self::free( $canvas );

			return '';
		}

		imagealphablending( $final, false );
		imagesavealpha( $final, true );
		imagecopyresampled(
			$final,
			$canvas,
			0,
			0,
			0,
			0,
			$width,
			$height,
			imagesx( $canvas ),
			imagesy( $canvas )
		);
		self::free( $canvas );

		ob_start();
		imagepng( $final, null, 6 );
		$bytes = (string) ob_get_clean();
		self::free( $final );

		return $bytes;
	}

	/**
	 * Releases a GD image, on the versions of PHP where that means anything.
	 *
	 * Before PHP 8.0 a GD image is a resource that has to be freed by hand. From 8.0 it
	 * is an object the engine collects, imagedestroy() does nothing, and from 8.5 it is
	 * deprecated - and a deprecation notice printed into a response whose body is a PNG
	 * corrupts the image. This plugin supports 7.4 upwards, so it has to do both.
	 *
	 * @param \GdImage|resource $image Image.
	 */
	private static function free( $image ): void {
		if ( ! $image instanceof \GdImage && function_exists( 'imagedestroy' ) ) {
			imagedestroy( $image );
		}
	}

	/**
	 * Chooses how much larger than the output to draw.
	 *
	 * @param int $width  Output width in pixels.
	 * @param int $height Output height in pixels.
	 * @return int Multiple, at least 1.
	 */
	private static function supersample( int $width, int $height ): int {
		$output = max( 1, $width * $height );
		$factor = (int) floor( sqrt( self::WORKING_PIXELS / $output ) );

		return max( 1, min( self::SUPERSAMPLE_MAX, $factor ) );
	}

	/**
	 * Paints the plate at a given pixels-per-millimetre.
	 *
	 * @param array<string, mixed> $layout Layout from Svg::layout().
	 * @param Face                 $face   Typeface.
	 * @param float                $mm     Pixels per millimetre.
	 * @return \GdImage|resource|null Canvas.
	 */
	private function paint( array $layout, Face $face, float $mm ) {
		$colours = $this->profile->colours();
		$geo     = $layout['geometry'];

		$width  = (int) round( (float) $layout['width'] * $mm );
		$height = (int) round( (float) $layout['height'] * $mm );
		$canvas = imagecreatetruecolor( $width, $height );

		if ( false === $canvas ) {
			return null;
		}

		imagealphablending( $canvas, true );
		imagesavealpha( $canvas, true );

		$transparent = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
		imagefill( $canvas, 0, 0, $transparent );

		$ground = $this->colour( $canvas, (string) ( $colours['ground'] ?? '#ffffff' ) );
		$ink    = $this->colour( $canvas, (string) ( $colours['ink'] ?? '#000000' ) );
		$border = $this->colour( $canvas, (string) ( $colours['border'] ?? '#000000' ) );

		$radius = (float) $geo['corner_radius'] * $mm;
		$this->rounded_rect( $canvas, 0.0, 0.0, (float) $width, (float) $height, $radius, $ground );

		// The border is an inset rounded outline, drawn as a filled shape with the
		// ground painted back inside it: GD has no stroked rounded rectangle.
		$inset  = (float) $geo['border_inset'] * $mm;
		$stroke = (float) $geo['border_stroke'] * $mm;
		$outer  = max( 0.0, $radius - $inset );

		$this->rounded_rect( $canvas, $inset, $inset, (float) $width - $inset, (float) $height - $inset, $outer, $border );
		$this->rounded_rect(
			$canvas,
			$inset + $stroke,
			$inset + $stroke,
			(float) $width - $inset - $stroke,
			(float) $height - $inset - $stroke,
			max( 0.0, $outer - $stroke ),
			$ground
		);

		$this->eu_band( $canvas, $layout, $face, $mm );

		$sfnt = $face->sfnt();
		$size = (float) $layout['font_size'] * $mm * self::POINTS_PER_PIXEL;

		foreach ( $layout['groups'] as $i => $group ) {
			$cursor   = (float) $group['x'];
			$baseline = (float) $layout['baselines'][ $i ];

			foreach ( Face::characters( (string) $group['text'] ) as $char ) {
				imagettftext(
					$canvas,
					$size,
					0.0,
					(int) round( $cursor * $mm ),
					(int) round( $baseline * $mm ),
					$ink,
					$sfnt,
					$char
				);

				$cursor += $face->advance( $char, (float) $layout['cap'] );
			}
		}

		return $canvas;
	}

	/**
	 * Paints the Euro field, its stars and the country code.
	 *
	 * @param \GdImage|resource    $canvas Canvas.
	 * @param array<string, mixed> $layout Layout.
	 * @param Face                 $face   Typeface.
	 * @param float                $mm     Pixels per millimetre.
	 */
	private function eu_band( $canvas, array $layout, Face $face, float $mm ): void {
		$colours = $this->profile->colours();
		$geo     = $layout['geometry'];
		$eu      = $layout['eu'];

		$inner = (float) $geo['border_inset'] + ( (float) $geo['border_stroke'] / 2 );
		$x     = (float) $geo['border_inset'] + (float) $geo['eu_light_edge'];
		$y     = 1 === (int) $layout['lines']
			? ( (float) $layout['height'] - (float) $eu['h'] ) / 2
			: $inner + (float) $geo['eu_light_edge'];

		$band = $this->colour( $canvas, (string) ( $colours['eu_band'] ?? '#003399' ) );
		$star     = $this->colour( $canvas, (string) ( $colours['eu_stars'] ?? '#ffcc00' ) );
		$code_ink = $this->colour( $canvas, (string) ( $colours['eu_code'] ?? '#ffffff' ) );

		imagefilledrectangle(
			$canvas,
			(int) round( $x * $mm ),
			(int) round( $y * $mm ),
			(int) round( ( $x + (float) $eu['w'] ) * $mm ),
			(int) round( ( $y + (float) $eu['h'] ) * $mm ),
			$band
		);

		$kranz  = (float) $eu['kranz'];
		$radius = $kranz / 2;
		$cx     = $x + ( (float) $eu['w'] / 2 );
		$cy     = $y + ( (float) $eu['h'] / 4 );
		$dot    = max( 1.0, $kranz / 6 * $mm );

		for ( $i = 0; $i < 12; $i++ ) {
			$angle = ( M_PI / 6 * $i ) - ( M_PI / 2 );

			imagefilledellipse(
				$canvas,
				(int) round( ( $cx + ( $radius * cos( $angle ) ) ) * $mm ),
				(int) round( ( $cy + ( $radius * sin( $angle ) ) ) * $mm ),
				(int) round( $dot ),
				(int) round( $dot ),
				$star
			);
		}

		$sfnt = $face->sfnt();

		if ( '' === $sfnt ) {
			return;
		}

		$code = (string) ( $geo['country_code'] ?? '' );

		if ( '' === $code ) {
			return;
		}

		$size     = $face->font_size( (float) $eu['code_height'] ) * $mm * self::POINTS_PER_PIXEL;
		$baseline = $cy + $radius + (float) $eu['code_gap'] + (float) $eu['code_height'];
		$box      = imagettfbbox( $size, 0.0, $sfnt, $code );

		// Centred on the field, the way the vector's text-anchor="middle" places it.
		$offset = false === $box ? 0.0 : ( (float) ( $box[2] - $box[0] ) / 2 );

		imagettftext(
			$canvas,
			$size,
			0.0,
			(int) round( ( $cx * $mm ) - $offset ),
			(int) round( $baseline * $mm ),
			$code_ink,
			$sfnt,
			$code
		);
	}

	/**
	 * Fills a rounded rectangle.
	 *
	 * @param \GdImage|resource $canvas Canvas.
	 * @param float             $x1     Left.
	 * @param float             $y1     Top.
	 * @param float             $x2     Right.
	 * @param float             $y2     Bottom.
	 * @param float             $radius Corner radius.
	 * @param int               $colour Allocated colour.
	 */
	private function rounded_rect( $canvas, float $x1, float $y1, float $x2, float $y2, float $radius, int $colour ): void {
		$radius = max( 0.0, min( $radius, ( $x2 - $x1 ) / 2, ( $y2 - $y1 ) / 2 ) );

		if ( $radius < 1.0 ) {
			imagefilledrectangle( $canvas, (int) round( $x1 ), (int) round( $y1 ), (int) round( $x2 ), (int) round( $y2 ), $colour );

			return;
		}

		$d = (int) round( $radius * 2 );

		imagefilledrectangle( $canvas, (int) round( $x1 + $radius ), (int) round( $y1 ), (int) round( $x2 - $radius ), (int) round( $y2 ), $colour );
		imagefilledrectangle( $canvas, (int) round( $x1 ), (int) round( $y1 + $radius ), (int) round( $x2 ), (int) round( $y2 - $radius ), $colour );

		foreach ( array(
			array( $x1 + $radius, $y1 + $radius ),
			array( $x2 - $radius, $y1 + $radius ),
			array( $x1 + $radius, $y2 - $radius ),
			array( $x2 - $radius, $y2 - $radius ),
		) as $corner ) {
			imagefilledellipse( $canvas, (int) round( $corner[0] ), (int) round( $corner[1] ), $d, $d, $colour );
		}
	}

	/**
	 * @param \GdImage|resource $canvas Canvas.
	 * @param string            $hex    Colour as #rrggbb.
	 * @return int Allocated colour.
	 */
	private function colour( $canvas, string $hex ): int {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return (int) imagecolorallocate( $canvas, 0, 0, 0 );
		}

		return (int) imagecolorallocate(
			$canvas,
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) )
		);
	}
}
