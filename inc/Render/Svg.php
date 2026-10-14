<?php
/**
 * Plate rendering.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Render;

use Zactonz\NumberPlates\Font\Face;
use Zactonz\NumberPlates\Font\Faces;
use Zactonz\NumberPlates\Profile\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Draws a plate as SVG at its true physical size.
 *
 * There is one renderer and one geometry. The live preview and the production artwork
 * differ only in how the typeface is delivered - referenced by URL on the storefront,
 * embedded in the file the merchant downloads - so what a shopper approves is what gets
 * manufactured. Every coordinate is a millimetre.
 */
final class Svg {

	/**
	 * Marks this plugin's SVGs so the typeface rule cannot leak to other text.
	 */
	public const ROOT_CLASS = 'zctz-np-plate';

	/**
	 * Country profile.
	 *
	 * @var Profile
	 */
	private Profile $profile;

	/**
	 * Typefaces available to this profile, most preferred first.
	 *
	 * @var array<int, Face>
	 */
	private array $faces;

	/**
	 * @param Profile $profile Country profile.
	 */
	public function __construct( Profile $profile ) {
		$this->profile = $profile;
		$this->faces   = Faces::for_profile( $profile );
	}

	/**
	 * Works out where everything sits, without drawing anything.
	 *
	 * Separated from rendering so that validation can ask whether a plate is
	 * physically renderable before it reaches the cart. A registration can be legal on
	 * character count and still not fit the size the shopper picked.
	 *
	 * Faces are tried in the profile's preference order and the first one that measures
	 * every character and fits the plate wins, which is how the regulation's own
	 * Mittelschrift-then-Engschrift rule falls out of the data rather than the code.
	 *
	 * @param array<string, string> $plate Plate values: variant, size, district, letters, digits.
	 * @return array<string, mixed>|null Layout, or null when no face can fit the content.
	 */
	public function layout( array $plate ): ?array {
		$size = $this->profile->size( (string) ( $plate['size'] ?? '' ) );

		if ( null === $size ) {
			return null;
		}

		foreach ( $this->faces as $face ) {
			$layout = $this->layout_with( $face, $plate, $size );

			if ( null !== $layout ) {
				return $layout;
			}
		}

		return null;
	}

	/**
	 * Lays the plate out in one specific face.
	 *
	 * @param Face                  $face  Typeface.
	 * @param array<string, string> $plate Plate values.
	 * @param array<string, mixed>  $size  Plate size from the profile.
	 * @return array<string, mixed>|null Layout, or null when this face cannot fit the content.
	 */
	private function layout_with( Face $face, array $plate, array $size ): ?array {
		$geo = $this->geometry();
		$cap = (float) $size['cap'];
		$eu  = $this->eu_field( $size );

		$district = mb_strtoupper( (string) ( $plate['district'] ?? '' ) );
		$letters  = mb_strtoupper( (string) ( $plate['letters'] ?? '' ) );
		$digits   = (string) ( $plate['digits'] ?? '' );

		if ( ! $face->covers( $district . $letters . $digits ) ) {
			return null;
		}

		$lines = (int) $size['lines'];
		$width = (float) $size['w'];

		/*
		 * Everything horizontal is measured from where the ink may go: the inner edge of
		 * the black border, and the Euro field's right edge on the line it sits on. The
		 * border's stroke straddles its own path, so its inner edge is half a stroke in
		 * from the inset - the same arithmetic the band uses, so the two cannot disagree.
		 */
		$inner_left  = (float) $geo['border_inset'] + ( (float) $geo['border_stroke'] / 2 );
		$inner_right = $width - $inner_left;
		$eu_right    = (float) $geo['border_inset'] + (float) $geo['eu_light_edge'] + (float) $eu['w'];
		$margin      = (float) $geo['side_margin'];

		/*
		 * The Euro field takes width off the line it sits on, and on a two-line plate
		 * that is the first line only - Anlage 4 Abschnitt 1 Nr. 3.2 puts it "in der
		 * ersten Zeile an der linken oberen Seite". The second line has the full width.
		 */
		/*
		 * Three groups, and four on a plate that carries a marking: the
		 * Unterscheidungszeichen, the letters and digits of the Erkennungsnummer - which
		 * are two groups with a gap between them, not one word - and then the E or H.
		 *
		 * The marking is drawn, never typed. Abschnitt 4 Nr. 4 and Abschnitt 5a Nr. 6
		 * both say it follows the Erkennungsnummer "ohne Leerzeichen in gleicher
		 * Schriftart", so it belongs to the plate's type rather than to the shopper's
		 * input, and a shopper who picks Electric gets the E without asking for it.
		 */
		$variant = $this->profile->variant( (string) ( $plate['variant'] ?? '' ) );
		$suffix  = null === $variant ? '' : (string) ( $variant['suffix'] ?? '' );
		$groups = array( $district, $letters, $digits );
		$gaps   = 1 === $lines ? array( 'gap_seal', 'gap_group' ) : array( 'gap_group' );

		if ( '' !== $suffix && $face->covers( $suffix ) ) {
			$groups[] = $suffix;
			$gaps[]   = 'gap_suffix';
		}

		$runs = array_map(
			function ( string $group ) use ( $face, $cap ): float {
				return $face->run_width( $group, $cap );
			},
			$groups
		);

		if ( 1 === $lines ) {
			$placed = $this->place( $groups, $runs, $gaps, $eu_right + $margin, $inner_right - $margin, $geo, $face, $cap );

			if ( null === $placed ) {
				return null;
			}

			$positions = $placed;
			$baselines = array_fill( 0, count( $positions ), $this->baseline( (float) $size['h'], $cap, 1, 0 ) );
		} else {
			/*
			 * The Unterscheidungszeichen has the first line to itself, beside the Euro
			 * field; the Erkennungsnummer and its marking have the second, which is full
			 * width because the Plaketten go under the Euro field on a two-line plate
			 * rather than between the groups. Both lines are centred, for the same
			 * Nr. 2.1 reason.
			 */
			$upper = $this->place( array( $groups[0] ), array( $runs[0] ), array(), $eu_right + $margin, $inner_right - $margin, $geo, $face, $cap );
			$lower = $this->place( array_slice( $groups, 1 ), array_slice( $runs, 1 ), $gaps, $inner_left + $margin, $inner_right - $margin, $geo, $face, $cap );

			if ( null === $upper || null === $lower ) {
				return null;
			}

			$positions = array_merge( $upper, $lower );
			$baselines = array_merge(
				array( $this->baseline( (float) $size['h'], $cap, 2, 0 ) ),
				array_fill( 0, count( $lower ), $this->baseline( (float) $size['h'], $cap, 2, 1 ) )
			);
		}

		return array(
			'width'     => $width,
			'height'    => (float) $size['h'],
			'lines'     => $lines,
			'cap'       => $cap,
			'font_size' => $face->font_size( $cap ),
			'face'      => $face,
			'eu'        => $eu,
			'groups'    => $positions,
			'baselines' => $baselines,
			'geometry'  => $geo,
		);
	}

	/**
	 * Renders the plate.
	 *
	 * @param array<string, string> $plate   Plate values.
	 * @param array<string, mixed>  $options 'outline' to draw glyphs as shapes, 'embed_font'
	 *                                       to inline the typeface, 'font_url' otherwise.
	 * @return string SVG markup, or an empty string when the plate cannot be rendered.
	 */
	public function render( array $plate, array $options = array() ): string {
		$layout = $this->layout( $plate );

		if ( null === $layout ) {
			return '';
		}

		$colours = $this->profile->colours();
		$geo     = $layout['geometry'];
		$face    = $layout['face'];
		$family  = $face->family();

		$w = $layout['width'];
		$h = $layout['height'];

		$parts   = array();
		$parts[] = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" class="%4$s" width="%1$smm" height="%2$smm" viewBox="0 0 %1$s %2$s" role="img" aria-label="%3$s">',
			$this->num( $w ),
			$this->num( $h ),
			esc_attr( $this->readable( $plate ) ),
			esc_attr( self::ROOT_CLASS )
		);

		/*
		 * Shapes rather than text wherever the file leaves this site. An SVG that sets
		 * text depends on whatever opens it honouring the embedded font, and the
		 * software a plate is actually manufactured with does not: librsvg, which a
		 * great deal of tooling rasterises with, ignores an embedded WOFF outright and
		 * substitutes a system face - the registration comes out in the wrong typeface
		 * and the umlaut disappears. Shapes carry no such dependency, and the file is
		 * a fifth of the size without the font in it.
		 */
		$outline = ! empty( $options['outline'] ) && $face->outlines( $this->glyph_text( $layout ) );

		if ( ! $outline ) {
			$parts[] = '<style>' . $this->font_face( $face, $options ) . $this->font_rule( $family ) . '</style>';
		}

		// Ground and the black border, inset from the edge as the regulation draws it.
		$parts[] = sprintf(
			'<rect x="0" y="0" width="%s" height="%s" rx="%s" fill="%s"/>',
			$this->num( $w ),
			$this->num( $h ),
			$this->num( $geo['corner_radius'] ),
			esc_attr( (string) ( $colours['ground'] ?? '#ffffff' ) )
		);

		$inset = $geo['border_inset'];
		$parts[] = sprintf(
			'<rect x="%s" y="%s" width="%s" height="%s" rx="%s" fill="none" stroke="%s" stroke-width="%s"/>',
			$this->num( $inset ),
			$this->num( $inset ),
			$this->num( $w - ( 2 * $inset ) ),
			$this->num( $h - ( 2 * $inset ) ),
			$this->num( max( 0.0, $geo['corner_radius'] - $inset ) ),
			esc_attr( (string) ( $colours['border'] ?? '#000000' ) ),
			$this->num( $geo['border_stroke'] )
		);

		$parts[] = $this->eu_band( $layout, $colours, $geo, $face, $outline );

		$ink = esc_attr( (string) ( $colours['ink'] ?? '#000000' ) );

		foreach ( $layout['groups'] as $i => $group ) {
			$parts[] = $outline
				? $this->shape_run(
					(string) $group['text'],
					(float) $group['x'],
					(float) $layout['baselines'][ $i ],
					(float) $layout['cap'],
					$face,
					$ink
				)
				: $this->glyph_run(
					(string) $group['text'],
					(float) $group['x'],
					(float) $layout['baselines'][ $i ],
					(float) $layout['cap'],
					$face,
					$ink,
					(float) $layout['font_size']
				);
		}

		$parts[] = '</svg>';

		return implode( '', $parts );
	}

	/**
	 * Places each character at a computed position.
	 *
	 * Characters are positioned individually from the measured advance widths rather
	 * than left to the renderer, because a plate is a dimensioned object: the same
	 * markup has to land identically in a browser and in whatever the merchant's
	 * production software opens it with.
	 *
	 * @param string $text      Group text.
	 * @param float  $x         Left edge in millimetres.
	 * @param float  $baseline  Baseline in millimetres.
	 * @param float  $cap       Cap height in millimetres.
	 * @param Face   $face      Typeface.
	 * @param string $ink       Fill colour, already escaped.
	 * @param float  $font_size Font size in millimetres.
	 * @return string SVG markup.
	 */
	private function glyph_run( string $text, float $x, float $baseline, float $cap, Face $face, string $ink, float $font_size ): string {
		if ( '' === $text ) {
			return '';
		}

		$out    = array();
		$cursor = $x;

		foreach ( Face::characters( $text ) as $char ) {
			$out[]   = sprintf(
				'<text x="%s" y="%s" font-family="%s" font-size="%s" fill="%s" xml:space="preserve">%s</text>',
				$this->num( $cursor ),
				$this->num( $baseline ),
				esc_attr( $face->family() ),
				$this->num( $font_size ),
				$ink,
				esc_html( $char )
			);
			$cursor += $face->advance( $char, $cap );
		}

		return implode( '', $out );
	}

	/**
	 * Draws the blue EU field with its ring of stars and the country code.
	 *
	 * @param array<string, mixed> $layout  Computed layout.
	 * @param array<string, mixed> $colours Colour tokens.
	 * @param array<string, mixed> $geo     Geometry.
	 * @return string SVG markup.
	 */
	private function eu_band( array $layout, array $colours, array $geo, Face $face, bool $outline = false ): string {
		$eu = $layout['eu'];
		$h  = (float) $layout['height'];
		$x  = (float) $geo['border_inset'] + (float) $geo['eu_light_edge'];

		/*
		 * Anlage 4 Nr. 3.1 puts the field "an der linken Seite" of a single-line plate,
		 * so it is centred on the plate's height; Nr. 3.2 and 3.3 put it "an der linken
		 * oberen Seite" of the first line, so on a two-line plate it sits at the top.
		 *
		 * Top means offset by the light edge from the border's inner edge, not flush
		 * against it. Centring the field in the upper band instead put a 56 mm field on
		 * a 255x130 plate at 4.5 mm, which is underneath a border stroke that reaches
		 * 6 mm in - blue printed over black - and left the taller plates with no light
		 * edge at all where the regulation allows up to 2 mm.
		 */
		$inner = (float) $geo['border_inset'] + ( (float) $geo['border_stroke'] / 2 );

		if ( 1 === (int) $layout['lines'] ) {
			$y = ( $h - (float) $eu['h'] ) / 2;
		} else {
			$y = $inner + (float) $geo['eu_light_edge'];
		}

		$out   = array();
		$out[] = sprintf(
			'<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>',
			$this->num( $x ),
			$this->num( $y ),
			$this->num( (float) $eu['w'] ),
			$this->num( (float) $eu['h'] ),
			esc_attr( (string) ( $colours['eu_band'] ?? '#003399' ) )
		);

		$kranz  = (float) $eu['kranz'];
		$radius = $kranz / 2;
		$cx     = $x + ( (float) $eu['w'] / 2 );

		// "Der Sternenkranz befindet sich mittig in der linken oberen Haelfte des
		// Eurofelds" - the centre of the field's upper half.
		$cy   = $y + ( (float) $eu['h'] / 4 );
		$star = esc_attr( (string) ( $colours['eu_stars'] ?? '#ffcc00' ) );
		$code_ink = esc_attr( (string) ( $colours['eu_code'] ?? '#ffffff' ) );

		// "Der Durchmesser jedes Sterns betraegt ein Sechstel des Durchmessers des
		// Sternenkranzes." Derived rather than stored, so the two can never disagree.
		$star_radius = $kranz / 6 / 2;

		for ( $i = 0; $i < 12; $i++ ) {
			$angle = ( M_PI / 6 * $i ) - ( M_PI / 2 );
			$out[] = sprintf(
				'<circle cx="%s" cy="%s" r="%s" fill="%s"/>',
				$this->num( $cx + ( $radius * cos( $angle ) ) ),
				$this->num( $cy + ( $radius * sin( $angle ) ) ),
				$this->num( $star_radius ),
				$star
			);
		}

		/*
		 * The country code is specified by height - 20 mm, or 15 mm on a reduced plate -
		 * at a stated distance below the star circle. Height is not font size, and the
		 * ratio between them belongs to the typeface, so it is set in the plate face,
		 * whose cap height is measured. A system font would land at whatever height its
		 * own proportions produced.
		 */
		$code_height = (float) $eu['code_height'];
		$baseline    = $cy + $radius + (float) $eu['code_gap'] + $code_height;

		$code = (string) ( $geo['country_code'] ?? '' );

		if ( $outline && $face->outlines( $code ) ) {
			// Centred the way text-anchor="middle" would centre it.
			$width = $face->run_width( $code, $code_height );

			$out[] = $this->shape_run( $code, $cx - ( $width / 2 ), $baseline, $code_height, $face, $code_ink );
		} else {
			$out[] = sprintf(
				'<text x="%s" y="%s" text-anchor="middle" class="%s" font-size="%s" fill="%s">%s</text>',
				$this->num( $cx ),
				$this->num( $baseline ),
				esc_attr( self::ROOT_CLASS . '__code' ),
				$this->num( $face->font_size( $code_height ) ),
				$code_ink,
				esc_html( $code )
			);
		}

		return implode( '', $out );
	}

	/**
	 * Draws a run of characters as filled shapes.
	 *
	 * The outlines are in the font's own units with y upwards, so each glyph is placed
	 * by translating to its pen position and scaling by the same factor the advances
	 * are scaled by, with y negated to match SVG's downward axis. Positions come from
	 * the shared layout, exactly as the text path's do.
	 *
	 * @param string $text     Group text.
	 * @param float  $x        Left edge in millimetres.
	 * @param float  $baseline Baseline in millimetres.
	 * @param float  $cap      Cap height in millimetres.
	 * @param Face   $face     Typeface.
	 * @param string $ink      Fill colour, already escaped.
	 * @return string SVG markup.
	 */
	private function shape_run( string $text, float $x, float $baseline, float $cap, Face $face, string $ink ): string {
		if ( '' === $text ) {
			return '';
		}

		$scale = $face->unit_scale( $cap );

		if ( 0.0 === $scale ) {
			return '';
		}

		$out    = array();
		$cursor = $x;

		foreach ( Face::characters( $text ) as $char ) {
			$path = $face->outline( $char );

			if ( '' !== $path ) {
				$out[] = sprintf(
					'<path d="%s" fill="%s" transform="translate(%s %s) scale(%s %s)"/>',
					esc_attr( $path ),
					$ink,
					$this->num( $cursor ),
					$this->num( $baseline ),
					$this->num( $scale ),
					$this->num( -$scale )
				);
			}

			$cursor += $face->advance( $char, $cap );
		}

		return implode( '', $out );
	}

	/**
	 * Returns every character the layout will draw, for a coverage check.
	 *
	 * @param array<string, mixed> $layout Layout.
	 * @return string Characters.
	 */
	private function glyph_text( array $layout ): string {
		$text = '';

		foreach ( $layout['groups'] as $group ) {
			$text .= (string) $group['text'];
		}

		return $text;
	}

	/**
	 * Returns the @font-face rule, embedding the typeface for production output.
	 *
	 * @param Face                 $face    Typeface.
	 * @param array<string, mixed> $options Render options.
	 * @return string CSS.
	 */
	private function font_face( Face $face, array $options ): string {
		if ( ! empty( $options['embed_font'] ) && $face->readable() ) {
			$data = file_get_contents( $face->path() );

			if ( false !== $data ) {
				return sprintf(
					"@font-face{font-family:'%s';src:url(data:%s;base64,%s) format('%s');}",
					$face->family(),
					$face->mime(),
					base64_encode( $data ),
					$face->format()
				);
			}
		}

		$url = isset( $options['font_url'] ) ? (string) $options['font_url'] : $face->url();

		return sprintf(
			"@font-face{font-family:'%s';src:url('%s') format('%s');}",
			$face->family(),
			esc_url_raw( $url ),
			$face->format()
		);
	}

	/**
	 * Returns the rule that actually applies the typeface.
	 *
	 * An SVG font-family presentation attribute carries the lowest specificity there
	 * is, so any theme or plugin rule matching text beats it. On a stock WooCommerce
	 * storefront that is exactly what happened: a serif was substituted silently and
	 * every measured glyph position was thrown out, because the positions are computed
	 * from this font's advance widths. A rule scoped to this SVG's own class settles
	 * it without reaching any other text on the page.
	 *
	 * @param string $family Font family.
	 * @return string CSS.
	 */
	private function font_rule( string $family ): string {
		return sprintf( ".%s text{font-family:'%s';}", self::ROOT_CLASS, $family );
	}

	/**
	 * @param float $height Plate height in millimetres.
	 * @param float $cap    Cap height in millimetres.
	 * @param int   $lines  Number of lines.
	 * @param int   $line   Zero-based line index.
	 * @return float Baseline position in millimetres.
	 */
	private function baseline( float $height, float $cap, int $lines, int $line ): float {
		if ( 1 === $lines ) {
			return ( $height + $cap ) / 2;
		}

		$band = $height / 2;

		return ( $band * $line ) + ( ( $band + $cap ) / 2 );
	}

	/**
	 * Resolves the Euro field a given plate size carries.
	 *
	 * Keyed on the size's own declaration rather than on cap height: Anlage 4 gives
	 * einzeilige and zweizeilige plates different field widths at the same cap height.
	 *
	 * @param array<string, mixed> $size Plate size from the profile.
	 * @return array{w: float, h: float, kranz: float, code_height: float, code_gap: float} Euro field.
	 */
	private function eu_field( array $size ): array {
		$fields = (array) ( $this->geometry()['eu_fields'] ?? array() );
		$key    = (string) ( $size['eu'] ?? '' );
		$spec   = $fields[ $key ] ?? reset( $fields );

		if ( ! is_array( $spec ) ) {
			$spec = array();
		}

		return array(
			'w'           => (float) ( $spec['w'] ?? 0 ),
			'h'           => (float) ( $spec['h'] ?? 0 ),
			'kranz'       => (float) ( $spec['kranz'] ?? 0 ),
			'code_height' => (float) ( $spec['code_height'] ?? 0 ),
			'code_gap'    => (float) ( $spec['code_gap'] ?? 0 ),
		);
	}

	/**
	 * @return array<string, mixed> Geometry section of the profile.
	 */
	private function geometry(): array {
		return $this->profile->layout();
	}

	/**
	 * Lays one row of groups out between two edges, and centres what is left over.
	 *
	 * The edges given are where the *ink* may reach. A face carries a side bearing at
	 * each end of a glyph, so the row's advance boxes are allowed to overhang them by
	 * exactly that much, and every gap figure is shortened by the bearings of the two
	 * characters it sits between. Anlage 4 measures to the lettering throughout.
	 *
	 * Slack is given to the gaps in the order they are named - on a single line that is
	 * the Plaketten gap before the one inside the Erkennungsnummer, because the Plaketten
	 * gap is the one that visibly stretches on a real plate - and never past the maximum
	 * the regulation allows each. Whatever remains is split evenly, which is Abschnitt 1
	 * Nr. 2.1: the distance to the Euro field and to the border must be the same on both
	 * sides. Without that last step a three-character registration put a 291 mm gap in
	 * the middle of a 520 mm plate.
	 *
	 * @param array<int, string>   $texts  Group texts, left to right.
	 * @param array<int, float>    $runs   Advance width of each group.
	 * @param array<int, string>   $gaps   Gap names between consecutive groups; one fewer than groups.
	 * @param float                $ink_left  Leftmost millimetre the ink may occupy.
	 * @param float                $ink_right Rightmost millimetre the ink may occupy.
	 * @param array<string, mixed> $geo    Layout geometry.
	 * @param Face                 $face   Typeface.
	 * @param float                $cap    Cap height in millimetres.
	 * @return array<int, array{text: string, x: float}>|null Placed groups, or null when the row will not fit.
	 */
	private function place( array $texts, array $runs, array $gaps, float $ink_left, float $ink_right, array $geo, Face $face, float $cap ): ?array {
		$count = count( $texts );

		if ( 0 === $count ) {
			return array();
		}

		$from = $ink_left - $face->left_bearing( $this->first_char( $texts[0] ), $cap );
		$to   = $ink_right + $face->right_bearing( $this->last_char( $texts[ $count - 1 ] ), $cap );

		$ranges  = array();
		$between = array();

		foreach ( $gaps as $index => $name ) {
			$range  = $this->range( $geo, $name, $cap );
			$trim   = $face->right_bearing( $this->last_char( $texts[ $index ] ), $cap )
				+ $face->left_bearing( $this->first_char( $texts[ $index + 1 ] ), $cap );
			$ranges[]  = $range;
			$between[] = $range['min'] - $trim;
		}

		$surplus = ( $to - $from ) - array_sum( $runs ) - array_sum( $between );

		if ( $surplus < 0 ) {
			return null;
		}

		foreach ( $ranges as $index => $range ) {
			$widen            = min( $surplus, $range['max'] - $range['min'] );
			$between[ $index ] += $widen;
			$surplus          -= $widen;
		}

		$positions = array();
		$cursor    = $from + ( $surplus / 2 );

		foreach ( $texts as $index => $text ) {
			$positions[] = array( 'text' => $text, 'x' => $cursor );
			$cursor     += $runs[ $index ] + ( $between[ $index ] ?? 0.0 );
		}

		return $positions;
	}

	/**
	 * Reads a gap's permitted range out of the profile.
	 *
	 * A profile that gives a single number rather than a range is read as a gap fixed at
	 * that figure, so a country whose rule is one measurement rather than a tolerance
	 * says so plainly.
	 *
	 * A gap that names the cap height its figures are stated at is scaled to the plate
	 * being drawn. A gap that does not is absolute, which is right for one that has to
	 * hold a physical object: a Plakette is the same size whatever the plate.
	 *
	 * @param array<string, mixed> $geo Layout geometry.
	 * @param string               $key Gap name.
	 * @param float                $cap Cap height of the plate being drawn, in millimetres.
	 * @return array{min: float, max: float} Range in millimetres, measured to the ink.
	 */
	private function range( array $geo, string $key, float $cap ): array {
		$spec = $geo[ $key ] ?? 0.0;

		if ( ! is_array( $spec ) ) {
			return array( 'min' => (float) $spec, 'max' => (float) $spec );
		}

		$at    = (float) ( $spec['at_cap'] ?? 0.0 );
		$scale = $at > 0.0 ? $cap / $at : 1.0;
		$min   = (float) ( $spec['min'] ?? 0.0 ) * $scale;
		$max   = (float) ( $spec['max'] ?? ( $spec['min'] ?? 0.0 ) ) * $scale;

		return array( 'min' => $min, 'max' => max( $min, $max ) );
	}

	/**
	 * @param string $text Group text.
	 * @return string The first character, or an empty string.
	 */
	private function first_char( string $text ): string {
		return '' === $text ? '' : mb_substr( $text, 0, 1 );
	}

	/**
	 * @param string $text Group text.
	 * @return string The last character, or an empty string.
	 */
	private function last_char( string $text ): string {
		return '' === $text ? '' : mb_substr( $text, -1, 1 );
	}

	/**
	 * @param array<string, string> $plate Plate values.
	 * @return string Human-readable registration.
	 */
	private function readable( array $plate ): string {
		$registration = $this->profile->registration(
			array(
				'variant'  => (string) ( $plate['variant'] ?? '' ),
				'district' => mb_strtoupper( (string) ( $plate['district'] ?? '' ) ),
				'letters'  => mb_strtoupper( (string) ( $plate['letters'] ?? '' ) ),
				'digits'   => (string) ( $plate['digits'] ?? '' ),
			)
		);

		// An empty plate is drawn before the shopper has typed anything; an image with
		// an empty label is announced as an unnamed graphic, which tells them nothing.
		if ( '' === $registration ) {
			return __( 'An empty number plate', 'zactonz-number-plates-for-woocommerce' );
		}

		return $registration;
	}

	/**
	 * Formats a millimetre value without trailing noise.
	 *
	 * @param float $value Value.
	 * @return string Formatted value.
	 */
	private function num( float $value ): string {
		return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
	}
}
