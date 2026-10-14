<?php
/**
 * Measures a font file and prints the metrics array a plate face needs.
 *
 * Usage:
 *   php tools/font-metrics.php path/to/font.woff [characters]
 *
 * The renderer places plate glyphs one at a time from measured advance widths, so a
 * face is unusable without them. This measuring runs here, once, with a person looking
 * at the numbers - rather than inside the plugin on every merchant's server, where a
 * misparsed table would silently produce a plate that is wrong at full size.
 *
 * Handles WOFF and bare SFNT (TTF/OTF). WOFF2 is not supported: its table stream is
 * Brotli-compressed and PHP has no bundled Brotli. Convert a WOFF2 to WOFF or TTF
 * first, or measure the original.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once __DIR__ . '/font-tables.php';

$path       = $argv[1] ?? '';
$characters = $argv[2] ?? implode( '', array_merge( range( 'A', 'Z' ), array( 'Ä', 'Ö', 'Ü' ), range( '0', '9' ) ) );

if ( '' === $path || ! is_readable( $path ) ) {
	fwrite( STDERR, "usage: php tools/font-metrics.php path/to/font.woff [characters]\n" );
	exit( 1 );
}

$binary = file_get_contents( $path );

if ( false === $binary ) {
	fwrite( STDERR, "could not read the font file\n" );
	exit( 1 );
}

try {
	$metrics = new Zctz_Np_Font_Metrics( new Zctz_Np_Font_Reader( $binary ) );
} catch ( RuntimeException $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}

$upem = $metrics->units_per_em();
$cap  = $metrics->cap_height();
$list = preg_split( '//u', $characters, -1, PREG_SPLIT_NO_EMPTY ) ?: array();

$measured = array();
$missing  = array();

foreach ( $list as $char ) {
	$advance = $metrics->advance( $char );

	if ( null === $advance || 0 === $advance ) {
		$missing[] = $char;
		continue;
	}

	$measured[ $char ] = $advance;
}

if ( array() !== $missing ) {
	fwrite( STDERR, sprintf( "no glyph or zero advance for: %s\n", implode( ' ', $missing ) ) );
}

if ( 0 === $cap ) {
	fwrite( STDERR, "this font declares no OS/2 sCapHeight - supply cap_height by hand, in design units\n" );
}

printf( "<?php\n/**\n * Measured from %s by tools/font-metrics.php.\n */\n\nreturn array(\n", basename( $path ) );
printf( "\t'units_per_em' => %d,\n", $upem );
printf( "\t'cap_height'   => %d,\n", $cap );
printf( "\t'advances'     => array(\n" );

foreach ( $measured as $char => $advance ) {
	printf( "\t\t'%s' => %d,\n", $char, $advance );
}

printf( "\t),\n);\n" );
