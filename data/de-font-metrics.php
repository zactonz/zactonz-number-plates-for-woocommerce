<?php

/**
 * Advance widths for the bundled FE-Mittelschrift face, in font units.
 *
 * Measured from assets/fonts/GL-Nummernschild-Mtl.woff by tools/font-metrics.php - do
 * not edit by hand. Millimetre advance for a given cap height is
 * units / cap_height_units * cap_height_mm.
 *
 * Reproduce with:
 *
 *     php tools/font-metrics.php assets/fonts/GL-Nummernschild-Mtl.woff
 *
 * which reads head, hhea, hmtx, cmap and OS/2 out of the file itself and prints this
 * array. Generated 2026-10-09.
 *
 * @return array{units_per_em: int, cap_height: int, advances: array<string, int>}
 */

defined( 'ABSPATH' ) || exit;

return array(
	'units_per_em' => 1000,
	'cap_height'   => 600,
	'advances'     => array(
		'A' => 452,
		'B' => 452,
		'C' => 452,
		'D' => 452,
		'E' => 452,
		'F' => 452,
		'G' => 452,
		'H' => 452,
		'I' => 356,
		'J' => 452,
		'K' => 452,
		'L' => 452,
		'M' => 452,
		'N' => 452,
		'O' => 452,
		'P' => 452,
		'Q' => 452,
		'R' => 452,
		'S' => 452,
		'T' => 452,
		'U' => 452,
		'V' => 452,
		'W' => 452,
		'X' => 452,
		'Y' => 452,
		'Z' => 452,
		'Ä' => 452,
		'Ö' => 452,
		'Ü' => 452,
		'0' => 428,
		'1' => 428,
		'2' => 428,
		'3' => 428,
		'4' => 428,
		'5' => 428,
		'6' => 428,
		'7' => 428,
		'8' => 428,
		'9' => 428,
	),
);
