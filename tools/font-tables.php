<?php
/**
 * Shared font table reading for the tools in this directory.
 *
 * Not shipped: tools/ is excluded from the package. Two tools read the same tables -
 * one to measure advances, one to extract outlines - and they must agree about what
 * the font says, so they read it through the same code rather than through two parsers
 * that could drift.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

/**
 * Reads a font's tables, whether the file is WOFF or bare SFNT.
 */
final class Zctz_Np_Font_Reader {

	/**
	 * Raw table data keyed by four-character tag.
	 *
	 * @var array<string, string>
	 */
	private array $tables = array();

	/**
	 * @param string $binary Font file contents.
	 * @throws RuntimeException When the container cannot be read.
	 */
	public function __construct( string $binary ) {
		$magic = substr( $binary, 0, 4 );

		if ( 'wOF2' === $magic ) {
			throw new RuntimeException( 'WOFF2 is not supported: its table stream is Brotli-compressed. Convert to WOFF or TTF first.' );
		}

		if ( 'wOFF' === $magic ) {
			$this->read_woff( $binary );

			return;
		}

		$this->read_sfnt( $binary );
	}

	/**
	 * @param string $tag Four-character table tag.
	 * @return string|null Table data.
	 */
	public function table( string $tag ): ?string {
		return $this->tables[ $tag ] ?? null;
	}

	/**
	 * @return array<int, string> Tags present in the file.
	 */
	public function tags(): array {
		return array_keys( $this->tables );
	}

	/**
	 * @param string $binary Font file contents.
	 * @throws RuntimeException When a table cannot be decompressed.
	 */
	private function read_woff( string $binary ): void {
		$count = self::u16( $binary, 12 );

		for ( $i = 0; $i < $count; $i++ ) {
			$entry = 44 + ( $i * 20 );
			$tag   = substr( $binary, $entry, 4 );
			$off   = self::u32( $binary, $entry + 4 );
			$comp  = self::u32( $binary, $entry + 8 );
			$orig  = self::u32( $binary, $entry + 12 );
			$data  = substr( $binary, $off, $comp );

			if ( $comp < $orig ) {
				$plain = @zlib_decode( $data );

				if ( false === $plain ) {
					throw new RuntimeException( sprintf( 'Table %s would not decompress.', $tag ) );
				}

				$data = $plain;
			}

			$this->tables[ $tag ] = $data;
		}
	}

	/**
	 * @param string $binary Font file contents.
	 */
	private function read_sfnt( string $binary ): void {
		$count = self::u16( $binary, 4 );

		for ( $i = 0; $i < $count; $i++ ) {
			$entry = 12 + ( $i * 16 );
			$tag   = substr( $binary, $entry, 4 );
			$off   = self::u32( $binary, $entry + 8 );
			$len   = self::u32( $binary, $entry + 12 );

			$this->tables[ $tag ] = substr( $binary, $off, $len );
		}
	}

	/**
	 * @param string $s      Binary string.
	 * @param int    $offset Offset.
	 * @return int Unsigned 16-bit big-endian value.
	 */
	public static function u16( string $s, int $offset ): int {
		$v = unpack( 'n', substr( $s, $offset, 2 ) );

		return false === $v ? 0 : (int) $v[1];
	}

	/**
	 * @param string $s      Binary string.
	 * @param int    $offset Offset.
	 * @return int Signed 16-bit big-endian value.
	 */
	public static function s16( string $s, int $offset ): int {
		$v = self::u16( $s, $offset );

		return $v >= 0x8000 ? $v - 0x10000 : $v;
	}

	/**
	 * @param string $s      Binary string.
	 * @param int    $offset Offset.
	 * @return int Unsigned 32-bit big-endian value.
	 */
	public static function u32( string $s, int $offset ): int {
		$v = unpack( 'N', substr( $s, $offset, 4 ) );

		return false === $v ? 0 : (int) $v[1];
	}
}

/**
 * Maps characters to glyph ids and glyph ids to advance widths.
 */
final class Zctz_Np_Font_Metrics {

	/**
	 * Font reader.
	 *
	 * @var Zctz_Np_Font_Reader
	 */
	private Zctz_Np_Font_Reader $font;

	/**
	 * Advance width per glyph id, in design units.
	 *
	 * @var array<int, int>
	 */
	private array $advances;

	/**
	 * Glyph id per code point.
	 *
	 * @var array<int, int>
	 */
	private array $cmap;

	/**
	 * @param Zctz_Np_Font_Reader $font Font reader.
	 * @throws RuntimeException When a required table is missing.
	 */
	public function __construct( Zctz_Np_Font_Reader $font ) {
		$this->font = $font;

		foreach ( array( 'head', 'hhea', 'hmtx', 'cmap' ) as $required ) {
			if ( null === $font->table( $required ) ) {
				throw new RuntimeException(
					sprintf( 'Required table %s is missing. Tables present: %s', $required, implode( ' ', $font->tags() ) )
				);
			}
		}

		$this->advances = $this->read_hmtx();
		$this->cmap     = $this->read_cmap();
	}

	/**
	 * @return int Design units per em.
	 */
	public function units_per_em(): int {
		return Zctz_Np_Font_Reader::u16( (string) $this->font->table( 'head' ), 18 );
	}

	/**
	 * Returns the cap height the font declares, or zero when it declares none.
	 *
	 * OS/2 only carries sCapHeight from table version 2 onwards. A font that predates
	 * that, or omits it, has to be told rather than guessed at: deriving cap height
	 * from a glyph outline would mean parsing glyf or CFF, and getting it wrong scales
	 * every character on the plate.
	 *
	 * @return int Cap height in design units, or zero.
	 */
	public function cap_height(): int {
		$os2 = $this->font->table( 'OS/2' );

		if ( null === $os2 || strlen( $os2 ) < 90 ) {
			return 0;
		}

		if ( Zctz_Np_Font_Reader::u16( $os2, 0 ) < 2 ) {
			return 0;
		}

		return Zctz_Np_Font_Reader::s16( $os2, 88 );
	}

	/**
	 * Returns the glyph a character maps to.
	 *
	 * @param string $char One character.
	 * @return int|null Glyph identifier, or null when the font has no glyph for it.
	 */
	public function glyph_for( string $char ): ?int {
		$code = self::code_point( $char );

		if ( null === $code || ! isset( $this->cmap[ $code ] ) ) {
			return null;
		}

		return $this->cmap[ $code ];
	}

	/**
	 * @param string $char One character.
	 * @return int|null Advance width in design units, or null when the font has no glyph for it.
	 */
	public function advance( string $char ): ?int {
		$code = self::code_point( $char );

		if ( null === $code || ! isset( $this->cmap[ $code ] ) ) {
			return null;
		}

		$glyph = $this->cmap[ $code ];
		$last  = array_key_last( $this->advances );

		return $this->advances[ $glyph ] ?? ( null === $last ? null : $this->advances[ $last ] );
	}

	/**
	 * @return array<int, int> Advance width per glyph id.
	 */
	private function read_hmtx(): array {
		$hhea  = (string) $this->font->table( 'hhea' );
		$hmtx  = (string) $this->font->table( 'hmtx' );
		$count = Zctz_Np_Font_Reader::u16( $hhea, 34 );

		$out = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$offset = $i * 4;

			if ( $offset + 2 > strlen( $hmtx ) ) {
				break;
			}

			$out[ $i ] = Zctz_Np_Font_Reader::u16( $hmtx, $offset );
		}

		return $out;
	}

	/**
	 * Reads the character map, preferring a Unicode subtable.
	 *
	 * @return array<int, int> Glyph id per code point.
	 * @throws RuntimeException When no readable Unicode subtable is found.
	 */
	private function read_cmap(): array {
		$cmap   = (string) $this->font->table( 'cmap' );
		$count  = Zctz_Np_Font_Reader::u16( $cmap, 2 );
		$chosen = null;
		$best   = -1;

		for ( $i = 0; $i < $count; $i++ ) {
			$entry    = 4 + ( $i * 8 );
			$platform = Zctz_Np_Font_Reader::u16( $cmap, $entry );
			$encoding = Zctz_Np_Font_Reader::u16( $cmap, $entry + 2 );
			$offset   = Zctz_Np_Font_Reader::u32( $cmap, $entry + 4 );

			// Windows full repertoire, then Windows BMP, then any Unicode platform table.
			$rank = -1;

			if ( 3 === $platform && 10 === $encoding ) {
				$rank = 3;
			} elseif ( 3 === $platform && 1 === $encoding ) {
				$rank = 2;
			} elseif ( 0 === $platform ) {
				$rank = 1;
			}

			if ( $rank > $best ) {
				$best   = $rank;
				$chosen = $offset;
			}
		}

		if ( null === $chosen ) {
			throw new RuntimeException( 'No Unicode cmap subtable found.' );
		}

		$format = Zctz_Np_Font_Reader::u16( $cmap, $chosen );

		if ( 4 === $format ) {
			return self::read_cmap_4( $cmap, $chosen );
		}

		if ( 6 === $format ) {
			return self::read_cmap_6( $cmap, $chosen );
		}

		if ( 12 === $format ) {
			return self::read_cmap_12( $cmap, $chosen );
		}

		throw new RuntimeException( sprintf( 'cmap subtable format %d is not supported.', $format ) );
	}

	/**
	 * @param string $cmap Table data.
	 * @param int    $base Subtable offset.
	 * @return array<int, int> Glyph id per code point.
	 */
	private static function read_cmap_4( string $cmap, int $base ): array {
		$segments = (int) ( Zctz_Np_Font_Reader::u16( $cmap, $base + 6 ) / 2 );
		$ends     = $base + 14;
		$starts   = $ends + ( $segments * 2 ) + 2;
		$deltas   = $starts + ( $segments * 2 );
		$ranges   = $deltas + ( $segments * 2 );

		$out = array();

		for ( $s = 0; $s < $segments; $s++ ) {
			$end   = Zctz_Np_Font_Reader::u16( $cmap, $ends + ( $s * 2 ) );
			$start = Zctz_Np_Font_Reader::u16( $cmap, $starts + ( $s * 2 ) );
			$delta = Zctz_Np_Font_Reader::u16( $cmap, $deltas + ( $s * 2 ) );
			$range = Zctz_Np_Font_Reader::u16( $cmap, $ranges + ( $s * 2 ) );

			if ( 0xffff === $start && 0xffff === $end ) {
				continue;
			}

			for ( $c = $start; $c <= $end; $c++ ) {
				if ( 0 === $range ) {
					$glyph = ( $c + $delta ) & 0xffff;
				} else {
					$at    = $ranges + ( $s * 2 ) + $range + ( ( $c - $start ) * 2 );
					$glyph = Zctz_Np_Font_Reader::u16( $cmap, $at );

					if ( 0 !== $glyph ) {
						$glyph = ( $glyph + $delta ) & 0xffff;
					}
				}

				if ( 0 !== $glyph ) {
					$out[ $c ] = $glyph;
				}
			}
		}

		return $out;
	}

	/**
	 * @param string $cmap Table data.
	 * @param int    $base Subtable offset.
	 * @return array<int, int> Glyph id per code point.
	 */
	private static function read_cmap_6( string $cmap, int $base ): array {
		$first = Zctz_Np_Font_Reader::u16( $cmap, $base + 6 );
		$count = Zctz_Np_Font_Reader::u16( $cmap, $base + 8 );

		$out = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$glyph = Zctz_Np_Font_Reader::u16( $cmap, $base + 10 + ( $i * 2 ) );

			if ( 0 !== $glyph ) {
				$out[ $first + $i ] = $glyph;
			}
		}

		return $out;
	}

	/**
	 * @param string $cmap Table data.
	 * @param int    $base Subtable offset.
	 * @return array<int, int> Glyph id per code point.
	 */
	private static function read_cmap_12( string $cmap, int $base ): array {
		$groups = Zctz_Np_Font_Reader::u32( $cmap, $base + 12 );
		$out    = array();

		for ( $g = 0; $g < $groups; $g++ ) {
			$entry = $base + 16 + ( $g * 12 );
			$start = Zctz_Np_Font_Reader::u32( $cmap, $entry );
			$end   = Zctz_Np_Font_Reader::u32( $cmap, $entry + 4 );
			$glyph = Zctz_Np_Font_Reader::u32( $cmap, $entry + 8 );

			for ( $c = $start; $c <= $end && $c - $start < 0x10000; $c++ ) {
				$out[ $c ] = $glyph + ( $c - $start );
			}
		}

		return $out;
	}

	/**
	 * @param string $char One character.
	 * @return int|null Code point.
	 */
	private static function code_point( string $char ): ?int {
		$converted = mb_convert_encoding( $char, 'UTF-32BE', 'UTF-8' );

		if ( '' === $converted || strlen( $converted ) < 4 ) {
			return null;
		}

		return Zctz_Np_Font_Reader::u32( $converted, 0 );
	}
}

