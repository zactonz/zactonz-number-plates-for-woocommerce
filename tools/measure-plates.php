<?php
/**
 * Measures rendered plates against Anlage 4 and against the plate's own edges.
 *
 * Run by tools/lint.sh. Exits non-zero on any disagreement.
 *
 * A plate is a dimensioned object, so "it looks right" is not a test: the preview is a
 * few hundred pixels wide and the thing manufactured from it is half a metre. Everything
 * here is checked in millimetres against the figures Anlage 4 Abschnitt 1 Nr. 3 states,
 * and against whether the drawing stays inside the plate it claims to be.
 *
 * Deliberately runs without a WordPress install: the renderer needs five functions from
 * it, shimmed below, so this gate works in a bare checkout and in CI.
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
define( 'ZCTZ_NP_URL', 'https://example.test/wp-content/plugins/' . basename( $root ) . '/' );

// The whole of WordPress this render path touches.
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
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

use Zactonz\NumberPlates\Font\Face;
use Zactonz\NumberPlates\Profile\Profile;
use Zactonz\NumberPlates\Render\Svg;

/**
 * What Anlage 4 Abschnitt 1 Nr. 3 states, per plate type. The renderer must reproduce
 * these from the profile data; they are repeated here so the gate has an independent
 * expectation rather than reading the same array the renderer reads.
 */
$expected = array(
	'einzeilig'   => array( 'w' => 45.0, 'h' => 88.0, 'kranz' => 30.0, 'code' => 20.0, 'gap' => 17.0 ),
	'zweizeilig'  => array( 'w' => 40.0, 'h' => 88.0, 'kranz' => 30.0, 'code' => 20.0, 'gap' => 17.0 ),
	'verkleinert' => array( 'w' => 35.0, 'h' => 56.0, 'kranz' => 22.5, 'code' => 15.0, 'gap' => 8.0 ),
);

/**
 * Returns where each drawn glyph's ink begins and ends, in millimetres, left to right.
 *
 * The renderer places a shape with translate(pen baseline) scale(k -k) and the path
 * itself is in font units, so the ink runs from pen + min(x) * k to pen + max(x) * k.
 * Every number in the path is a point on the contour or a control point of one, and a
 * Bezier stays inside the hull of its control points, so those extremes bound the ink.
 *
 * @param string $svg Outline render.
 * @return array<int, array{0: float, 1: float}> Ink spans, in drawing order.
 */
function znp_ink_spans( $svg ) {
	preg_match_all(
		'~<path d="([^"]+)" fill="[^"]*" transform="translate\(([-0-9.]+) [-0-9.]+\) scale\(([-0-9.]+) [-0-9.]+\)"/>~',
		$svg,
		$paths,
		PREG_SET_ORDER
	);

	$spans = array();

	foreach ( $paths as $path ) {
		preg_match_all( '/-?\d+(?:\.\d+)?/', $path[1], $numbers );

		$xs = array();

		foreach ( $numbers[0] as $index => $number ) {
			if ( 0 === $index % 2 ) {
				$xs[] = (float) $number;
			}
		}

		if ( array() === $xs ) {
			continue;
		}

		$pen     = (float) $path[2];
		$scale   = (float) $path[3];
		$spans[] = array( $pen + ( min( $xs ) * $scale ), $pen + ( max( $xs ) * $scale ) );
	}

	return $spans;
}

/**
 * One registration per size that is legal for it, short enough to fit in Mittelschrift.
 */
$samples = array(
	'einzeilig-520'   => array( 'variant' => 'standard', 'district' => 'B',   'letters' => 'AB', 'digits' => '12' ),
	'zweizeilig-340'  => array( 'variant' => 'standard', 'district' => 'GÖ',  'letters' => 'HK', 'digits' => '19' ),
	'zweizeilig-280'  => array( 'variant' => 'standard', 'district' => 'M',   'letters' => 'ZZ', 'digits' => '42' ),
	'kraftrad-220'    => array( 'variant' => 'kraftrad', 'district' => 'HH',  'letters' => 'K',  'digits' => '77' ),
	'kraftrad-180'    => array( 'variant' => 'kraftrad', 'district' => 'KA',  'letters' => 'B',  'digits' => '7' ),
	'verkleinert-255' => array( 'variant' => 'kraftrad', 'district' => 'BÜD', 'letters' => 'Q',  'digits' => '5' ),
);

$problems = array();
$checked  = 0;

foreach ( Profile::available() as $profile_id => $label ) {
	$profile = Profile::get( (string) $profile_id );

	if ( null === $profile ) {
		$problems[] = "profile {$profile_id} will not load";
		continue;
	}

	$renderer = new Svg( $profile );
	$geo      = $profile->layout();
	$inner    = (float) $geo['border_inset'] + ( (float) $geo['border_stroke'] / 2 );
	$margin_ink = (float) $geo['side_margin'];
	$metrics  = $profile->fonts();
	$face     = $metrics[0] ?? array();
	$table    = is_string( $face['metrics'] ?? '' ) ? ( include $root . '/data/' . $face['metrics'] . '.php' ) : array();
	$ratio    = ( (int) ( $table['cap_height'] ?? 0 ) > 0 )
		? (float) $table['cap_height'] / (float) $table['units_per_em']
		: 0.0;

	foreach ( $profile->sizes() as $size_key => $size ) {
		if ( ! isset( $samples[ $size_key ] ) ) {
			$problems[] = "{$profile_id}/{$size_key}: no sample registration in this gate";
			continue;
		}

		$plate  = array_merge( $samples[ $size_key ], array( 'size' => $size_key ) );
		$markup = $renderer->render( $plate );
		$where  = "{$profile_id}/{$size_key}";

		if ( '' === $markup ) {
			$problems[] = "{$where}: refused a registration that should fit";
			continue;
		}

		++$checked;

		$plate_w = (float) $size['w'];
		$plate_h = (float) $size['h'];
		$lines   = (int) $size['lines'];
		$want    = $expected[ (string) ( $size['eu'] ?? '' ) ] ?? null;

		if ( null === $want ) {
			$problems[] = "{$where}: declares Euro field type '" . (string) ( $size['eu'] ?? '' ) . "', which this gate has no figures for";
			continue;
		}

		// --- the Euro field -------------------------------------------------
		if ( ! preg_match( '~<rect x="([0-9.]+)" y="([0-9.]+)" width="([0-9.]+)" height="([0-9.]+)" fill="' . preg_quote( (string) $profile->colours()['eu_band'], '~' ) . '"/>~', $markup, $r ) ) {
			$problems[] = "{$where}: no Euro field drawn";
			continue;
		}

		$fx = (float) $r[1];
		$fy = (float) $r[2];
		$fw = (float) $r[3];
		$fh = (float) $r[4];

		if ( abs( $fw - $want['w'] ) > 0.01 || abs( $fh - $want['h'] ) > 0.01 ) {
			$problems[] = sprintf( '%s: Euro field is %gx%gmm, Anlage 4 says %gx%gmm', $where, $fw, $fh, $want['w'], $want['h'] );
		}

		if ( $fx < $inner || $fy < $inner ) {
			$problems[] = sprintf( '%s: Euro field starts at %g,%gmm, inside a border reaching %gmm', $where, $fx, $fy, $inner );
		}

		$band = 1 === $lines ? $plate_h : $plate_h / 2;

		if ( $fy + $fh > $band + 0.01 ) {
			$problems[] = sprintf( '%s: Euro field runs to %gmm, past its %gmm band', $where, $fy + $fh, $band );
		}

		// --- the stars ------------------------------------------------------
		preg_match_all( '~<circle cx="([0-9.]+)" cy="([0-9.]+)" r="([0-9.]+)"~', $markup, $c );

		if ( 12 !== count( $c[1] ) ) {
			$problems[] = sprintf( '%s: %d stars, not 12', $where, count( $c[1] ) );
		} else {
			$xs    = array_map( 'floatval', $c[1] );
			$ys    = array_map( 'floatval', $c[2] );
			$kranz = max( max( $xs ) - min( $xs ), max( $ys ) - min( $ys ) );

			if ( abs( $kranz - $want['kranz'] ) > 0.01 ) {
				$problems[] = sprintf( '%s: Sternenkranz is %gmm across, Anlage 4 says %gmm', $where, $kranz, $want['kranz'] );
			}

			// "Der Durchmesser jedes Sterns betraegt ein Sechstel des Durchmessers des
			// Sternenkranzes."
			$star = 2 * (float) $c[3][0];

			if ( abs( $star - ( $want['kranz'] / 6 ) ) > 0.01 ) {
				$problems[] = sprintf( '%s: each star is %gmm across, the ratio gives %gmm', $where, $star, $want['kranz'] / 6 );
			}

			if ( count( array_unique( $c[3] ) ) > 1 ) {
				$problems[] = "{$where}: the twelve stars are not all the same size";
			}
		}

		// --- the country code ----------------------------------------------
		if ( preg_match( '~<text x="([0-9.]+)" y="([0-9.]+)" text-anchor="middle" class="[^"]*__code" font-size="([0-9.]+)"~', $markup, $t ) && $ratio > 0.0 ) {
			$code_cap = (float) $t[3] * $ratio;

			if ( abs( $code_cap - $want['code'] ) > 0.01 ) {
				$problems[] = sprintf( '%s: country code is %gmm tall, Anlage 4 says %gmm', $where, round( $code_cap, 3 ), $want['code'] );
			}

			if ( (float) $t[2] > $fy + $fh + 0.01 ) {
				$problems[] = sprintf( '%s: country code baseline at %gmm is below the Euro field', $where, (float) $t[2] );
			}
		} else {
			$problems[] = "{$where}: no country code drawn, or the face has no measured cap height";
		}

		// --- the shape version draws the same characters --------------------
		$shapes = $renderer->render( $plate, array( 'outline' => true ) );

		if ( '' === $shapes ) {
			$problems[] = "{$where}: the outline render produced nothing";
		} else {
			$glyph_count = 0;

			foreach ( array( 'district', 'letters', 'digits' ) as $field ) {
				$glyph_count += mb_strlen( (string) ( $samples[ $size_key ][ $field ] ?? '' ) );
			}

			// One path per registration character, plus the country code.
			$wanted_shapes = $glyph_count + mb_strlen( (string) ( $geo['country_code'] ?? '' ) );
			$drawn         = substr_count( $shapes, '<path' );

			if ( $drawn !== $wanted_shapes ) {
				$problems[] = sprintf( '%s: outline render drew %d shapes, expected %d', $where, $drawn, $wanted_shapes );
			}

			if ( false !== strpos( $shapes, '<text' ) ) {
				$problems[] = "{$where}: outline render still sets text";
			}

			if ( false !== strpos( $shapes, '@font-face' ) ) {
				$problems[] = "{$where}: outline render still carries a font";
			}

			/*
			 * --- the horizontal spacing Anlage 4 asks for -----------------------
			 *
			 * Measured off the ink, because that is what the regulation measures and
			 * what a shopper sees - the blank the face carries either side of a glyph
			 * is not part of the lettering. The figures are read back out of the paths
			 * the renderer emitted rather than recomputed from the layout, so this is a
			 * check on the drawing rather than a restatement of the arithmetic.
			 */
			$ink = znp_ink_spans( $shapes );

			// The first shape is the country code, inside the Euro field.
			array_shift( $ink );

			$counts = array();

			foreach ( array( 'district', 'letters', 'digits' ) as $field ) {
				$counts[ $field ] = mb_strlen( (string) ( $samples[ $size_key ][ $field ] ?? '' ) );
			}

			$total = array_sum( $counts );

			if ( count( $ink ) !== $total ) {
				$problems[] = sprintf( '%s: measured %d glyph shapes, the registration has %d', $where, count( $ink ), $total );
			} else {
				$district_end = $ink[ $counts['district'] - 1 ][1];
				$letters_from = $ink[ $counts['district'] ][0];
				$letters_end  = $ink[ $counts['district'] + $counts['letters'] - 1 ][1];
				$digits_from  = $ink[ $counts['district'] + $counts['letters'] ][0];

				/*
				 * The seal gap exists only on a single line - on two lines the Plaketten
				 * go under the Euro field - but the letters and digits of the
				 * Erkennungsnummer are two groups with a gap between them on both.
				 */
				$gaps = array( 'gap_group' => $digits_from - $letters_end );

				if ( 1 === $lines ) {
					$gaps['gap_seal'] = $letters_from - $district_end;
				}

				foreach ( $gaps as $gap_key => $measured ) {
					$range = $geo[ $gap_key ] ?? array();
					$min   = (float) ( is_array( $range ) ? ( $range['min'] ?? 0 ) : $range );
					$max   = (float) ( is_array( $range ) ? ( $range['max'] ?? $min ) : $range );

					// A figure stated at a given cap height applies scaled to this plate.
					$at = (float) ( is_array( $range ) ? ( $range['at_cap'] ?? 0 ) : 0 );

					if ( $at > 0.0 ) {
						$min *= (float) $size['cap'] / $at;
						$max *= (float) $size['cap'] / $at;
					}

					if ( $measured < $min - 0.01 || $measured > $max + 0.01 ) {
						$problems[] = sprintf(
							'%s: %s is %gmm, outside the %g to %gmm this profile allows',
							$where,
							$gap_key,
							round( $measured, 2 ),
							$min,
							$max
						);
					}
				}

				/*
				 * Anlage 4 Abschnitt 1 Nr. 2.1: the horizontal distance from the
				 * lettering to the border and to the Euro field "muss auf beiden Seiten
				 * gleich sein". One line is measured as a whole; two lines are measured
				 * line by line, since only the first gives up room to the Euro field.
				 */
				$rows = 1 === $lines
					? array( array( $ink[0][0], $ink[ $total - 1 ][1], $fx + $fw ) )
					: array(
						array( $ink[0][0], $district_end, $fx + $fw ),
						array( $letters_from, $ink[ $total - 1 ][1], $inner ),
					);

				foreach ( $rows as $index => $row ) {
					list( $from, $to, $left_edge ) = $row;

					$left  = $from - $left_edge;
					$right = ( $plate_w - $inner ) - $to;

					if ( abs( $left - $right ) > 0.5 ) {
						$problems[] = sprintf(
							'%s: line %d sits %gmm from the left and %gmm from the right; Anlage 4 Nr. 2.1 wants them equal',
							$where,
							$index + 1,
							round( $left, 2 ),
							round( $right, 2 )
						);
					}

					if ( min( $left, $right ) < $margin_ink - 0.01 ) {
						$problems[] = sprintf(
							'%s: line %d comes within %gmm of the edge, under the %gmm minimum',
							$where,
							$index + 1,
							round( min( $left, $right ), 2 ),
							$margin_ink
						);
					}
				}
			}
		}

		// --- the registration stays on the plate ----------------------------
		preg_match_all(
			'~<text x="([0-9.]+)" y="([0-9.]+)" font-family="[^"]*" font-size="[0-9.]+" fill="[^"]*" xml:space="preserve">(.*?)</text>~u',
			$markup,
			$glyphs,
			PREG_SET_ORDER
		);

		if ( array() === $glyphs ) {
			$problems[] = "{$where}: no registration glyphs drawn";
			continue;
		}

		$margin   = (float) $geo['side_margin'];
		$advances = (array) ( $table['advances'] ?? array() );
		$cap      = (float) $size['cap'];
		$unit     = (float) ( $table['cap_height'] ?? 0 );

		foreach ( $glyphs as $glyph ) {
			$gx   = (float) $glyph[1];
			$gy   = (float) $glyph[2];
			$char = html_entity_decode( $glyph[3], ENT_QUOTES, 'UTF-8' );

			// Where the glyph ends, not just where it starts: a run can begin inside
			// the margin and still print off the end of the plate.
			$advance = ( $unit > 0.0 && isset( $advances[ $char ] ) )
				? ( (float) $advances[ $char ] / $unit * $cap )
				: 0.0;
			$right   = $gx + $advance;

			if ( $gx < $margin - 0.01 ) {
				$problems[] = sprintf( '%s: a glyph starts at %gmm, inside the %gmm side margin', $where, $gx, $margin );
				break;
			}

			if ( $right > $plate_w - $margin + 0.01 ) {
				$problems[] = sprintf(
					'%s: the registration reaches %gmm, past the %gmm margin on a %gmm plate',
					$where,
					round( $right, 2 ),
					$margin,
					$plate_w
				);
				break;
			}

			if ( $gy > $plate_h + 0.01 || $gy < 0.0 ) {
				$problems[] = sprintf( '%s: a glyph baseline sits at %gmm on a %gmm plate', $where, $gy, $plate_h );
				break;
			}

			// On a two-line plate the first line has to clear the Euro field; on a
			// single-line plate every glyph does.
			$on_first_line = 1 === $lines || $gy <= $plate_h / 2;

			if ( $on_first_line && $gx < $fx + $fw ) {
				$problems[] = sprintf( '%s: a glyph at %gmm overlaps the Euro field ending at %gmm', $where, $gx, $fx + $fw );
				break;
			}
		}
	}

	/*
	 * --- a plate type that carries a marking must actually carry it ----------
	 *
	 * The profile declared suffix letters for Electric and Historic and nothing read
	 * them, so all three marked types drew byte-identical to a standard plate and a
	 * shopper who picked Electric was sold an ordinary plate. Nothing else in this gate
	 * would have noticed: the dimensions were all correct, because it was the right
	 * drawing of the wrong plate.
	 */
	foreach ( $profile->variants() as $variant_key => $variant ) {
		$mark = (string) ( $variant['suffix'] ?? '' );

		if ( '' === $mark ) {
			continue;
		}

		$sizes = (array) ( $variant['sizes'] ?? array() );

		foreach ( $sizes as $size_key ) {
			$size = $profile->size( (string) $size_key );

			if ( null === $size || ! isset( $samples[ $size_key ] ) ) {
				continue;
			}

			$where  = "{$profile_id}/{$size_key}/{$variant_key}";
			$plate  = array_merge( $samples[ $size_key ], array( 'size' => $size_key, 'variant' => $variant_key ) );
			$shapes = $renderer->render( $plate, array( 'outline' => true ) );

			if ( '' === $shapes ) {
				// The sample may be too long for this type's position limit; not a fault.
				continue;
			}

			$ink   = znp_ink_spans( $shapes );
			$plain = $renderer->render( array_merge( $plate, array( 'variant' => 'standard' ) ), array( 'outline' => true ) );

			if ( '' !== $plain && $plain === $shapes ) {
				$problems[] = sprintf( '%s: draws byte-identical to a standard plate, so its %s marking is missing', $where, $mark );
				continue;
			}

			$characters = 0;

			foreach ( array( 'district', 'letters', 'digits' ) as $field ) {
				$characters += mb_strlen( (string) ( $samples[ $size_key ][ $field ] ?? '' ) );
			}

			// Country code, the registration, and the marking.
			$wanted = 1 + $characters + mb_strlen( $mark );

			if ( count( $ink ) !== $wanted ) {
				$problems[] = sprintf( '%s: drew %d shapes, expected %d with the marking', $where, count( $ink ), $wanted );
				continue;
			}

			$gap   = $ink[ count( $ink ) - 1 ][0] - $ink[ count( $ink ) - 2 ][1];
			$range = $geo['gap_suffix'] ?? array();
			$min   = (float) ( $range['min'] ?? 0 );
			$max   = (float) ( $range['max'] ?? $min );
			$at    = (float) ( $range['at_cap'] ?? 0 );

			if ( $at > 0.0 ) {
				$min *= (float) $size['cap'] / $at;
				$max *= (float) $size['cap'] / $at;
			}

			if ( $gap < $min - 0.01 || $gap > $max + 0.01 ) {
				$problems[] = sprintf(
					'%s: the %s marking sits %gmm from the registration, outside the %g to %gmm this profile allows',
					$where,
					$mark,
					round( $gap, 2 ),
					round( $min, 2 ),
					round( $max, 2 )
				);
			}
		}
	}
}

foreach ( $problems as $problem ) {
	fwrite( STDERR, '  FAIL  ' . $problem . "\n" );
}

printf( "  %d plate(s) measured, %d problem(s)\n", $checked, count( $problems ) );

exit( array() === $problems ? 0 : 1 );
