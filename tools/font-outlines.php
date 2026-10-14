<?php
/**
 * Extracts glyph outlines from a font as SVG path data.
 *
 * Usage:
 *   php tools/font-outlines.php assets/fonts/Face.woff [characters]
 *
 * The production artwork a merchant manufactures from must not depend on a font being
 * installed, or on the software opening it understanding @font-face. It does not:
 * librsvg, which is what a great many tools rasterise SVG with, ignores an embedded
 * WOFF and silently substitutes a system face - so a plate opened in a cutter would be
 * set in the wrong typeface and the umlaut would vanish. Shapes have no such problem.
 *
 * Outlines are extracted here, once, with a person looking at the result, rather than
 * in the plugin on every request: interpreting charstrings is the kind of work that
 * fails quietly and at full size.
 *
 * Handles CFF (OpenType/OTTO) outlines. TrueType glyf outlines are not handled; a face
 * that uses them reports so rather than emitting something wrong.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once __DIR__ . '/font-tables.php';

/**
 * Reads the CFF table and turns charstrings into path data.
 */
final class Zctz_Np_Cff {

	/**
	 * CFF table bytes.
	 *
	 * @var string
	 */
	private string $cff;

	/**
	 * Charstring offsets, one entry per glyph.
	 *
	 * @var array<int, string>
	 */
	private array $charstrings = array();

	/**
	 * Global subroutines.
	 *
	 * @var array<int, string>
	 */
	private array $gsubrs = array();

	/**
	 * Local subroutines.
	 *
	 * @var array<int, string>
	 */
	private array $subrs = array();

	/**
	 * @param string $cff CFF table bytes.
	 * @throws RuntimeException When the table cannot be read.
	 */
	public function __construct( string $cff ) {
		$this->cff = $cff;

		$header_size = ord( $cff[2] );
		$offset      = $header_size;

		// Name INDEX, Top DICT INDEX, String INDEX, Global Subr INDEX, in that order.
		$offset       = $this->skip_index( $offset );
		$top_dicts    = $this->read_index( $offset );
		$offset       = $top_dicts['end'];
		$offset       = $this->skip_index( $offset );
		$gsubr        = $this->read_index( $offset );
		$this->gsubrs = $gsubr['items'];

		if ( array() === $top_dicts['items'] ) {
			throw new RuntimeException( 'CFF has no Top DICT.' );
		}

		$top = $this->parse_dict( $top_dicts['items'][0] );

		if ( isset( $top[1230] ) ) {
			throw new RuntimeException( 'CID-keyed CFF fonts are not handled.' );
		}

		if ( ! isset( $top[17] ) ) {
			throw new RuntimeException( 'CFF Top DICT has no CharStrings offset.' );
		}

		$charstrings       = $this->read_index( (int) $top[17][0] );
		$this->charstrings = $charstrings['items'];

		if ( isset( $top[18] ) && count( $top[18] ) >= 2 ) {
			$private_size   = (int) $top[18][0];
			$private_offset = (int) $top[18][1];
			$private        = $this->parse_dict( substr( $cff, $private_offset, $private_size ) );

			if ( isset( $private[19] ) ) {
				$local       = $this->read_index( $private_offset + (int) $private[19][0] );
				$this->subrs = $local['items'];
			}
		}
	}

	/**
	 * @return int Number of glyphs.
	 */
	public function glyph_count(): int {
		return count( $this->charstrings );
	}

	/**
	 * Returns one glyph as SVG path data, in font units with y upwards.
	 *
	 * @param int $glyph Glyph identifier.
	 * @return string Path data.
	 * @throws RuntimeException When the charstring cannot be run.
	 */
	public function path( int $glyph ): string {
		if ( ! isset( $this->charstrings[ $glyph ] ) ) {
			throw new RuntimeException( sprintf( 'No charstring for glyph %d.', $glyph ) );
		}

		$state = array(
			'x'       => 0.0,
			'y'       => 0.0,
			'stack'   => array(),
			'stems'   => 0,
			'width'   => false,
			'path'    => array(),
			'open'    => false,
			'depth'   => 0,
			'trans'   => array(),
		);

		$this->run( $this->charstrings[ $glyph ], $state );

		if ( $state['open'] ) {
			$state['path'][] = 'Z';
		}

		return implode( ' ', $state['path'] );
	}

	/**
	 * Runs a Type 2 charstring.
	 *
	 * @param string               $code  Charstring bytes.
	 * @param array<string, mixed> $state Interpreter state, by reference.
	 * @throws RuntimeException On malformed input.
	 */
	private function run( string $code, array &$state ): void {
		if ( $state['depth'] > 10 ) {
			throw new RuntimeException( 'Charstring subroutine nesting is too deep.' );
		}

		$i      = 0;
		$length = strlen( $code );

		while ( $i < $length ) {
			$b = ord( $code[ $i ] );

			// Operands.
			if ( $b >= 32 || 28 === $b ) {
				if ( 28 === $b ) {
					$value = unpack( 'n', substr( $code, $i + 1, 2 ) )[1];
					$value = $value >= 0x8000 ? $value - 0x10000 : $value;
					$i    += 3;
				} elseif ( $b <= 246 ) {
					$value = $b - 139;
					$i    += 1;
				} elseif ( $b <= 250 ) {
					$value = ( ( $b - 247 ) * 256 ) + ord( $code[ $i + 1 ] ) + 108;
					$i    += 2;
				} elseif ( $b <= 254 ) {
					$value = ( -( $b - 251 ) * 256 ) - ord( $code[ $i + 1 ] ) - 108;
					$i    += 2;
				} else {
					$raw   = unpack( 'N', substr( $code, $i + 1, 4 ) )[1];
					$raw   = $raw >= 0x80000000 ? $raw - 0x100000000 : $raw;
					$value = $raw / 65536;
					$i    += 5;
				}

				$state['stack'][] = (float) $value;

				continue;
			}

			++$i;

			switch ( $b ) {
				case 1:  // hstem.
				case 3:  // vstem.
				case 18: // hstemhm.
				case 23: // vstemhm.
					$this->take_width( $state, true );
					$state['stems'] += (int) ( count( $state['stack'] ) / 2 );
					$state['stack']  = array();
					break;

				case 19: // hintmask.
				case 20: // cntrmask.
					$this->take_width( $state, true );
					$state['stems'] += (int) ( count( $state['stack'] ) / 2 );
					$state['stack']  = array();
					$i              += (int) ceil( $state['stems'] / 8 );
					break;

				case 21: // rmoveto.
					$this->take_width( $state, count( $state['stack'] ) > 2 );
					$this->move( $state, (float) ( $state['stack'][0] ?? 0 ), (float) ( $state['stack'][1] ?? 0 ) );
					$state['stack'] = array();
					break;

				case 22: // hmoveto.
					$this->take_width( $state, count( $state['stack'] ) > 1 );
					$this->move( $state, (float) ( $state['stack'][0] ?? 0 ), 0.0 );
					$state['stack'] = array();
					break;

				case 4: // vmoveto.
					$this->take_width( $state, count( $state['stack'] ) > 1 );
					$this->move( $state, 0.0, (float) ( $state['stack'][0] ?? 0 ) );
					$state['stack'] = array();
					break;

				case 5: // rlineto.
					for ( $n = 0; $n + 1 < count( $state['stack'] ); $n += 2 ) {
						$this->line( $state, (float) $state['stack'][ $n ], (float) $state['stack'][ $n + 1 ] );
					}
					$state['stack'] = array();
					break;

				case 6: // hlineto.
				case 7: // vlineto.
					$horizontal = ( 6 === $b );
					foreach ( $state['stack'] as $value ) {
						if ( $horizontal ) {
							$this->line( $state, (float) $value, 0.0 );
						} else {
							$this->line( $state, 0.0, (float) $value );
						}
						$horizontal = ! $horizontal;
					}
					$state['stack'] = array();
					break;

				case 8: // rrcurveto.
					for ( $n = 0; $n + 5 < count( $state['stack'] ); $n += 6 ) {
						$this->curve( $state, ...array_map( 'floatval', array_slice( $state['stack'], $n, 6 ) ) );
					}
					$state['stack'] = array();
					break;

				case 24: // rcurveline.
					$n = 0;
					for ( ; $n + 5 < count( $state['stack'] ) - 2; $n += 6 ) {
						$this->curve( $state, ...array_map( 'floatval', array_slice( $state['stack'], $n, 6 ) ) );
					}
					$this->line( $state, (float) ( $state['stack'][ $n ] ?? 0 ), (float) ( $state['stack'][ $n + 1 ] ?? 0 ) );
					$state['stack'] = array();
					break;

				case 25: // rlinecurve.
					$n = 0;
					for ( ; count( $state['stack'] ) - $n > 6; $n += 2 ) {
						$this->line( $state, (float) $state['stack'][ $n ], (float) $state['stack'][ $n + 1 ] );
					}
					$this->curve( $state, ...array_map( 'floatval', array_slice( $state['stack'], $n, 6 ) ) );
					$state['stack'] = array();
					break;

				case 26: // vvcurveto.
					$stack = $state['stack'];
					$dx1   = 0.0;
					if ( 0 !== count( $stack ) % 4 ) {
						$dx1 = (float) array_shift( $stack );
					}
					for ( $n = 0; $n + 3 < count( $stack ); $n += 4 ) {
						$this->curve( $state, $dx1, (float) $stack[ $n ], (float) $stack[ $n + 1 ], (float) $stack[ $n + 2 ], 0.0, (float) $stack[ $n + 3 ] );
						$dx1 = 0.0;
					}
					$state['stack'] = array();
					break;

				case 27: // hhcurveto.
					$stack = $state['stack'];
					$dy1   = 0.0;
					if ( 0 !== count( $stack ) % 4 ) {
						$dy1 = (float) array_shift( $stack );
					}
					for ( $n = 0; $n + 3 < count( $stack ); $n += 4 ) {
						$this->curve( $state, (float) $stack[ $n ], $dy1, (float) $stack[ $n + 1 ], (float) $stack[ $n + 2 ], (float) $stack[ $n + 3 ], 0.0 );
						$dy1 = 0.0;
					}
					$state['stack'] = array();
					break;

				case 30: // vhcurveto.
				case 31: // hvcurveto.
					$this->alternating_curves( $state, 31 === $b );
					break;

				case 10: // callsubr.
					$index = (int) array_pop( $state['stack'] ) + $this->bias( count( $this->subrs ) );
					if ( ! isset( $this->subrs[ $index ] ) ) {
						throw new RuntimeException( sprintf( 'Missing local subroutine %d.', $index ) );
					}
					++$state['depth'];
					$this->run( $this->subrs[ $index ], $state );
					--$state['depth'];
					break;

				case 29: // callgsubr.
					$index = (int) array_pop( $state['stack'] ) + $this->bias( count( $this->gsubrs ) );
					if ( ! isset( $this->gsubrs[ $index ] ) ) {
						throw new RuntimeException( sprintf( 'Missing global subroutine %d.', $index ) );
					}
					++$state['depth'];
					$this->run( $this->gsubrs[ $index ], $state );
					--$state['depth'];
					break;

				case 11: // return.
					return;

				case 14: // endchar.
					$this->take_width( $state, count( $state['stack'] ) > 0 && 4 !== count( $state['stack'] ) );
					if ( $state['open'] ) {
						$state['path'][] = 'Z';
						$state['open']   = false;
					}
					$state['stack'] = array();
					return;

				case 12:
					$b2 = ord( $code[ $i ] );
					++$i;
					$this->escaped( $b2, $state );
					break;

				default:
					// Hint and arithmetic operators this face does not use.
					$state['stack'] = array();
					break;
			}
		}
	}

	/**
	 * Runs the two-byte operators, which here means the flex family.
	 *
	 * @param int                  $op    Second operator byte.
	 * @param array<string, mixed> $state Interpreter state, by reference.
	 */
	private function escaped( int $op, array &$state ): void {
		$s = array_map( 'floatval', $state['stack'] );

		switch ( $op ) {
			case 35: // flex.
				$this->curve( $state, $s[0], $s[1], $s[2], $s[3], $s[4], $s[5] );
				$this->curve( $state, $s[6], $s[7], $s[8], $s[9], $s[10], $s[11] );
				break;

			case 34: // hflex.
				$this->curve( $state, $s[0], 0.0, $s[1], $s[2], $s[3], 0.0 );
				$this->curve( $state, $s[4], 0.0, $s[5], -$s[2], $s[6], 0.0 );
				break;

			case 36: // hflex1.
				$this->curve( $state, $s[0], $s[1], $s[2], $s[3], $s[4], 0.0 );
				$this->curve( $state, $s[5], 0.0, $s[6], $s[7], $s[8], -( $s[1] + $s[3] + $s[7] ) );
				break;

			case 37: // flex1.
				$dx = $s[0] + $s[2] + $s[4] + $s[6] + $s[8];
				$dy = $s[1] + $s[3] + $s[5] + $s[7] + $s[9];
				$this->curve( $state, $s[0], $s[1], $s[2], $s[3], $s[4], $s[5] );
				$this->curve( $state, $s[6], $s[7], $s[8], $s[9], $s[10], -$dy );
				unset( $dx );
				break;
		}

		$state['stack'] = array();
	}

	/**
	 * Runs vhcurveto and hvcurveto, which alternate direction every curve.
	 *
	 * @param array<string, mixed> $state      Interpreter state, by reference.
	 * @param bool                 $horizontal Whether the first tangent is horizontal.
	 */
	private function alternating_curves( array &$state, bool $horizontal ): void {
		$stack = array_map( 'floatval', $state['stack'] );
		$n     = 0;
		$count = count( $stack );

		while ( $count - $n >= 4 ) {
			// A trailing fifth value on the last curve sets the other tangent.
			$last = ( $count - $n === 5 );

			if ( $horizontal ) {
				$this->curve( $state, $stack[ $n ], 0.0, $stack[ $n + 1 ], $stack[ $n + 2 ], $last ? $stack[ $n + 4 ] : 0.0, $stack[ $n + 3 ] );
			} else {
				$this->curve( $state, 0.0, $stack[ $n ], $stack[ $n + 1 ], $stack[ $n + 2 ], $stack[ $n + 3 ], $last ? $stack[ $n + 4 ] : 0.0 );
			}

			$n         += 4;
			$horizontal = ! $horizontal;
		}

		$state['stack'] = array();
	}

	/**
	 * Drops the optional leading width operand.
	 *
	 * @param array<string, mixed> $state   Interpreter state, by reference.
	 * @param bool                 $present Whether a width is on the stack.
	 */
	private function take_width( array &$state, bool $present ): void {
		if ( $state['width'] ) {
			return;
		}

		$state['width'] = true;

		if ( $present && 0 !== count( $state['stack'] ) % 2 ) {
			array_shift( $state['stack'] );
		} elseif ( $present && 1 === count( $state['stack'] ) ) {
			array_shift( $state['stack'] );
		}
	}

	/**
	 * @param array<string, mixed> $state Interpreter state, by reference.
	 * @param float                $dx    Relative x.
	 * @param float                $dy    Relative y.
	 */
	private function move( array &$state, float $dx, float $dy ): void {
		if ( $state['open'] ) {
			$state['path'][] = 'Z';
		}

		$state['x']     += $dx;
		$state['y']     += $dy;
		$state['path'][] = 'M' . self::n( $state['x'] ) . ' ' . self::n( $state['y'] );
		$state['open']   = true;
	}

	/**
	 * @param array<string, mixed> $state Interpreter state, by reference.
	 * @param float                $dx    Relative x.
	 * @param float                $dy    Relative y.
	 */
	private function line( array &$state, float $dx, float $dy ): void {
		$state['x']     += $dx;
		$state['y']     += $dy;
		$state['path'][] = 'L' . self::n( $state['x'] ) . ' ' . self::n( $state['y'] );
	}

	/**
	 * @param array<string, mixed> $state Interpreter state, by reference.
	 * @param float                $dx1   First control point, relative x.
	 * @param float                $dy1   First control point, relative y.
	 * @param float                $dx2   Second control point, relative x.
	 * @param float                $dy2   Second control point, relative y.
	 * @param float                $dx3   End point, relative x.
	 * @param float                $dy3   End point, relative y.
	 */
	private function curve( array &$state, float $dx1, float $dy1, float $dx2, float $dy2, float $dx3, float $dy3 ): void {
		$x1 = $state['x'] + $dx1;
		$y1 = $state['y'] + $dy1;
		$x2 = $x1 + $dx2;
		$y2 = $y1 + $dy2;

		$state['x'] = $x2 + $dx3;
		$state['y'] = $y2 + $dy3;

		$state['path'][] = 'C' . self::n( $x1 ) . ' ' . self::n( $y1 )
			. ' ' . self::n( $x2 ) . ' ' . self::n( $y2 )
			. ' ' . self::n( $state['x'] ) . ' ' . self::n( $state['y'] );
	}

	/**
	 * @param int $count Number of subroutines.
	 * @return int Index bias.
	 */
	private function bias( int $count ): int {
		if ( $count < 1240 ) {
			return 107;
		}

		if ( $count < 33900 ) {
			return 1131;
		}

		return 32768;
	}

	/**
	 * Reads a CFF INDEX.
	 *
	 * @param int $offset Offset of the INDEX.
	 * @return array{items: array<int, string>, end: int} Items and the offset after them.
	 */
	private function read_index( int $offset ): array {
		$count = Zctz_Np_Font_Reader::u16( $this->cff, $offset );

		if ( 0 === $count ) {
			return array( 'items' => array(), 'end' => $offset + 2 );
		}

		$off_size = ord( $this->cff[ $offset + 2 ] );
		$offsets  = array();

		for ( $i = 0; $i <= $count; $i++ ) {
			$at    = $offset + 3 + ( $i * $off_size );
			$value = 0;

			for ( $b = 0; $b < $off_size; $b++ ) {
				$value = ( $value << 8 ) | ord( $this->cff[ $at + $b ] );
			}

			$offsets[] = $value;
		}

		$base  = $offset + 3 + ( ( $count + 1 ) * $off_size ) - 1;
		$items = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$items[] = substr( $this->cff, $base + $offsets[ $i ], $offsets[ $i + 1 ] - $offsets[ $i ] );
		}

		return array( 'items' => $items, 'end' => $base + $offsets[ $count ] );
	}

	/**
	 * @param int $offset Offset of the INDEX.
	 * @return int Offset after it.
	 */
	private function skip_index( int $offset ): int {
		$index = $this->read_index( $offset );

		return $index['end'];
	}

	/**
	 * Parses a CFF DICT into operator to operands.
	 *
	 * @param string $data DICT bytes.
	 * @return array<int, array<int, float>> Operands by operator.
	 */
	private function parse_dict( string $data ): array {
		$out      = array();
		$operands = array();
		$i        = 0;
		$length   = strlen( $data );

		while ( $i < $length ) {
			$b = ord( $data[ $i ] );

			if ( $b <= 21 ) {
				$op = $b;
				++$i;

				if ( 12 === $b ) {
					$op = 1200 + ord( $data[ $i ] );
					++$i;
				}

				$out[ $op ] = $operands;
				$operands   = array();

				continue;
			}

			if ( 28 === $b ) {
				$value      = Zctz_Np_Font_Reader::s16( $data, $i + 1 );
				$operands[] = (float) $value;
				$i         += 3;
			} elseif ( 29 === $b ) {
				$raw        = Zctz_Np_Font_Reader::u32( $data, $i + 1 );
				$operands[] = (float) ( $raw >= 0x80000000 ? $raw - 0x100000000 : $raw );
				$i         += 5;
			} elseif ( 30 === $b ) {
				// Real number: nibble encoded, terminated by 0xf.
				$text = '';
				++$i;
				while ( $i < $length ) {
					$byte = ord( $data[ $i ] );
					++$i;
					foreach ( array( $byte >> 4, $byte & 0x0f ) as $nibble ) {
						if ( $nibble <= 9 ) {
							$text .= (string) $nibble;
						} elseif ( 10 === $nibble ) {
							$text .= '.';
						} elseif ( 11 === $nibble ) {
							$text .= 'E';
						} elseif ( 12 === $nibble ) {
							$text .= 'E-';
						} elseif ( 14 === $nibble ) {
							$text .= '-';
						} elseif ( 15 === $nibble ) {
							break 2;
						}
					}
				}
				$operands[] = (float) $text;
			} elseif ( $b >= 32 && $b <= 246 ) {
				$operands[] = (float) ( $b - 139 );
				++$i;
			} elseif ( $b >= 247 && $b <= 250 ) {
				$operands[] = (float) ( ( ( $b - 247 ) * 256 ) + ord( $data[ $i + 1 ] ) + 108 );
				$i         += 2;
			} elseif ( $b >= 251 && $b <= 254 ) {
				$operands[] = (float) ( ( -( $b - 251 ) * 256 ) - ord( $data[ $i + 1 ] ) - 108 );
				$i         += 2;
			} else {
				++$i;
			}
		}

		return $out;
	}

	/**
	 * Formats a coordinate without trailing noise.
	 *
	 * @param float $value Value.
	 * @return string Formatted value.
	 */
	private static function n( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}
}

$path       = $argv[1] ?? '';
$characters = $argv[2] ?? implode( '', array_merge( range( 'A', 'Z' ), array( 'Ä', 'Ö', 'Ü' ), range( '0', '9' ) ) );

if ( '' === $path || ! is_readable( $path ) ) {
	fwrite( STDERR, "usage: php tools/font-outlines.php path/to/font.woff [characters]\n" );
	exit( 1 );
}

try {
	$reader = new Zctz_Np_Font_Reader( (string) file_get_contents( $path ) );
	$table  = $reader->table( 'CFF ' );

	if ( null === $table ) {
		throw new RuntimeException( 'This font has no CFF table; only CFF outlines are handled.' );
	}

	$metrics = new Zctz_Np_Font_Metrics( $reader );
	$cff     = new Zctz_Np_Cff( $table );
} catch ( RuntimeException $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}

$list    = preg_split( '//u', $characters, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
$paths   = array();
$missing = array();

foreach ( $list as $char ) {
	$glyph = $metrics->glyph_for( $char );

	if ( null === $glyph ) {
		$missing[] = $char;
		continue;
	}

	try {
		$paths[ $char ] = $cff->path( $glyph );
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, sprintf( "'%s': %s\n", $char, $e->getMessage() ) );
		exit( 1 );
	}
}

if ( array() !== $missing ) {
	fwrite( STDERR, sprintf( "no glyph for: %s\n", implode( ' ', $missing ) ) );
}

printf(
	"<?php\n/**\n * Glyph outlines for %s, as SVG path data in font units.\n *\n"
	. " * Extracted by tools/font-outlines.php - do not edit by hand. Coordinates are in the\n"
	. " * font's own units with y upwards, so a glyph is placed by translating to its pen\n"
	. " * position and scaling by font size over units per em, with y negated.\n *\n"
	. " * The production download draws these rather than setting text, because software that\n"
	. " * opens an SVG for manufacture does not reliably honour an embedded font.\n *\n"
	. " * @return array<string, string>\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nreturn array(\n",
	basename( $path )
);

foreach ( $paths as $char => $data ) {
	printf( "\t'%s' => '%s',\n", $char, $data );
}

printf( ");\n" );
