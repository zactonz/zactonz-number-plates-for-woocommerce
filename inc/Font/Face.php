<?php
/**
 * A typeface and the measurements the renderer needs from it.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Font;

defined( 'ABSPATH' ) || exit;

/**
 * One plate typeface, with its advance widths.
 *
 * Plate glyphs are placed one at a time from measured advances rather than set as a
 * text run, so a face with no measurements is of no use here - the renderer would have
 * nothing to compute the next character's position from. Metrics are therefore part of
 * a face rather than an optional extra, and a face that cannot measure a character is
 * treated as unable to render it instead of silently advancing by zero.
 */
final class Face {

	/**
	 * Advance width per character, in font units.
	 *
	 * @var array<string, int>
	 */
	private array $advances;

	/**
	 * Glyph outline per character, as SVG path data in font units.
	 *
	 * @var array<string, string>
	 */
	private array $outlines;

	/**
	 * Horizontal ink extent per character, in font units; null where unknown.
	 *
	 * @var array<string, array{0: float, 1: float}|null>
	 */
	private array $bounds = array();

	/**
	 * CSS font family name.
	 *
	 * @var string
	 */
	private string $family;

	/**
	 * Absolute path to the font file.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Public URL of the font file.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Absolute path to a bare SFNT of the same face, readable by FreeType.
	 *
	 * @var string
	 */
	private string $sfnt;

	/**
	 * Design units per em.
	 *
	 * @var int
	 */
	private int $units_per_em;

	/**
	 * Cap height in design units.
	 *
	 * @var int
	 */
	private int $cap_height;

	/**
	 * @param string             $family       CSS font family name.
	 * @param string             $path         Absolute path to the font file.
	 * @param string             $url          Public URL of the font file.
	 * @param string             $sfnt         Absolute path to a bare SFNT of the same face.
	 * @param int                $units_per_em Design units per em.
	 * @param int                $cap_height   Cap height in design units.
	 * @param array<string, int>    $advances  Advance width per character, in design units.
	 * @param array<string, string> $outlines  Glyph outline per character, as SVG path data.
	 */
	public function __construct(
		string $family,
		string $path,
		string $url,
		string $sfnt,
		int $units_per_em,
		int $cap_height,
		array $advances,
		array $outlines = array()
	) {
		$this->family       = self::clean_family( $family );
		$this->path         = $path;
		$this->url          = $url;
		$this->sfnt         = $sfnt;
		$this->units_per_em = $units_per_em;
		$this->cap_height   = $cap_height;
		$this->advances     = $advances;
		$this->outlines     = $outlines;
	}

	/**
	 * Reduces a family name to what can safely be written into a CSS string.
	 *
	 * The name reaches a quoted CSS identifier in a @font-face rule as well as an SVG
	 * attribute, and a face may be declared by a merchant through zctz_np_profile_faces
	 * from a descriptor or a font file's own name table. Cleaning it here means the one
	 * value that is interpolated rather than escaped cannot close the string it sits in,
	 * whatever supplied it.
	 *
	 * @param string $family Raw family name.
	 * @return string Family name, safe in a CSS string and an attribute.
	 */
	private static function clean_family( string $family ): string {
		$clean = preg_replace( '/[^A-Za-z0-9 _-]/', '', $family );

		return trim( (string) $clean );
	}

	/**
	 * Returns a character's outline as SVG path data, in font units with y upwards.
	 *
	 * @param string $char One character.
	 * @return string Path data, or an empty string when this face has no outline for it.
	 */
	public function outline( string $char ): string {
		return (string) ( $this->outlines[ $char ] ?? '' );
	}

	/**
	 * Returns the blank space between a character's left edge and the ink, in millimetres.
	 *
	 * The regulation measures to the lettering, not to the box a typesetter would draw
	 * round it: Anlage 4 gives a minimum of 8 mm "zwischen dem Eurofeld und den
	 * Buchstaben". A face's advance width includes a side bearing at each end - 4.5 mm
	 * on the bundled face at a 75 mm cap - so positioning by advance alone puts the ink
	 * somewhere other than where the regulation says it goes.
	 *
	 * Zero when the face has no outline for the character, which is honest rather than
	 * convenient: without the shape there is no way to know where its ink starts, and
	 * the layout then falls back to measuring the advance box, as it used to.
	 *
	 * @param string $char One character.
	 * @param float  $cap  Cap height in millimetres.
	 * @return float Left side bearing in millimetres.
	 */
	public function left_bearing( string $char, float $cap ): float {
		$bounds = $this->ink_bounds( $char );

		return null === $bounds ? 0.0 : $bounds[0] * $this->unit_scale( $cap );
	}

	/**
	 * Returns the blank space between a character's ink and its right edge, in millimetres.
	 *
	 * @param string $char One character.
	 * @param float  $cap  Cap height in millimetres.
	 * @return float Right side bearing in millimetres.
	 */
	public function right_bearing( string $char, float $cap ): float {
		$bounds = $this->ink_bounds( $char );

		return null === $bounds ? 0.0 : $this->advance( $char, $cap ) - ( $bounds[1] * $this->unit_scale( $cap ) );
	}

	/**
	 * Returns a character's horizontal ink extent in font units.
	 *
	 * Read off the outline, which is the only description of the shape this face has.
	 * Every x in the path is a point on or a control point of the contour, and a Bezier
	 * never leaves the hull of its control points, so the extremes of those numbers
	 * bound the ink. For an upright face whose curves are extremum-anchored - which a
	 * plate face is, by specification - they are the ink. Cached because the layout asks
	 * about the same few characters repeatedly.
	 *
	 * @param string $char One character.
	 * @return array{0: float, 1: float}|null Minimum and maximum x, or null without an outline.
	 */
	private function ink_bounds( string $char ): ?array {
		if ( array_key_exists( $char, $this->bounds ) ) {
			return $this->bounds[ $char ];
		}

		$path                   = $this->outline( $char );
		$this->bounds[ $char ] = null;

		if ( '' !== $path && preg_match_all( '/-?\d+(?:\.\d+)?/', $path, $found ) ) {
			$xs = array();

			foreach ( $found[0] as $index => $number ) {
				if ( 0 === $index % 2 ) {
					$xs[] = (float) $number;
				}
			}

			if ( array() !== $xs ) {
				$this->bounds[ $char ] = array( min( $xs ), max( $xs ) );
			}
		}

		return $this->bounds[ $char ];
	}

	/**
	 * Whether this face can be drawn as shapes for every character in the text.
	 *
	 * @param string $text Text to check.
	 * @return bool Whether every character has an outline.
	 */
	public function outlines( string $text ): bool {
		if ( array() === $this->outlines ) {
			return false;
		}

		foreach ( self::characters( $text ) as $char ) {
			if ( '' === (string) ( $this->outlines[ $char ] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Converts a cap height into the scale that maps font units onto millimetres.
	 *
	 * @param float $cap Cap height in millimetres.
	 * @return float Scale factor.
	 */
	public function unit_scale( float $cap ): float {
		if ( 0 === $this->cap_height ) {
			return 0.0;
		}

		return $cap / (float) $this->cap_height;
	}

	/**
	 * @return string CSS font family name.
	 */
	public function family(): string {
		return $this->family;
	}

	/**
	 * @return string Absolute path to the font file.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * @return string Public URL of the font file.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Returns a file FreeType can draw with, or an empty string when the face has none.
	 *
	 * A browser is happy with WOFF; FreeType, which GD draws through, is not. A face
	 * without this can still be set in the browser - it just cannot be rastered, so the
	 * raster thumbnail falls back to vector and the email thumbnail is left out.
	 *
	 * @return string Absolute path, or an empty string.
	 */
	public function sfnt(): string {
		return ( '' !== $this->sfnt && is_readable( $this->sfnt ) ) ? $this->sfnt : '';
	}

	/**
	 * Returns the CSS format() token for this file, so the browser does not have to sniff.
	 *
	 * @return string Format token.
	 */
	public function format(): string {
		$map = array(
			'woff'  => 'woff',
			'woff2' => 'woff2',
			'ttf'   => 'truetype',
			'otf'   => 'opentype',
		);

		return $map[ $this->extension() ] ?? 'woff';
	}

	/**
	 * @return string Media type for a data URI.
	 */
	public function mime(): string {
		$map = array(
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
		);

		return $map[ $this->extension() ] ?? 'font/woff';
	}

	/**
	 * @return bool Whether the font file can be read for embedding.
	 */
	public function readable(): bool {
		return '' !== $this->path && is_readable( $this->path );
	}

	/**
	 * Whether this face has a measurement for every character in the text.
	 *
	 * A missing advance is not a cosmetic problem. The renderer would place the next
	 * glyph on top of the unmeasured one, producing a plate that looks plausible in
	 * miniature and is wrong at 520 mm, so an unmeasured character disqualifies the
	 * face and the caller moves on to the next one.
	 *
	 * @param string $text Text to check.
	 * @return bool Whether every character is measured.
	 */
	public function covers( string $text ): bool {
		foreach ( self::characters( $text ) as $char ) {
			if ( 0 === (int) ( $this->advances[ $char ] ?? 0 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $char One character.
	 * @param float  $cap  Cap height in millimetres.
	 * @return float Advance in millimetres.
	 */
	public function advance( string $char, float $cap ): float {
		$units = (int) ( $this->advances[ $char ] ?? 0 );

		if ( 0 === $units || 0 === $this->cap_height ) {
			return 0.0;
		}

		return $units / (float) $this->cap_height * $cap;
	}

	/**
	 * @param string $text Text.
	 * @param float  $cap  Cap height in millimetres.
	 * @return float Run width in millimetres.
	 */
	public function run_width( string $text, float $cap ): float {
		$width = 0.0;

		foreach ( self::characters( $text ) as $char ) {
			$width += $this->advance( $char, $cap );
		}

		return $width;
	}

	/**
	 * Converts a legal cap height into the font size that produces it.
	 *
	 * The regulation specifies cap height, not font size, and the ratio between them is
	 * a property of the typeface - which is why this cannot be a constant.
	 *
	 * @param float $cap Cap height in millimetres.
	 * @return float Font size in millimetres.
	 */
	public function font_size( float $cap ): float {
		if ( 0 === $this->cap_height ) {
			return $cap;
		}

		return $cap / ( (float) $this->cap_height / (float) $this->units_per_em );
	}

	/**
	 * Splits a string into characters without breaking a multibyte district code.
	 *
	 * @param string $text Text.
	 * @return array<int, string> Characters.
	 */
	public static function characters( string $text ): array {
		return preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	}

	/**
	 * @return string Lowercased file extension.
	 */
	private function extension(): string {
		return strtolower( pathinfo( $this->path, PATHINFO_EXTENSION ) );
	}
}
