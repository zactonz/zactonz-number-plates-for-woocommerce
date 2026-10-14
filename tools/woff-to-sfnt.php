<?php
/**
 * Unwraps a WOFF into the bare SFNT that FreeType can read.
 *
 * Usage:
 *   php tools/woff-to-sfnt.php assets/fonts/Face.woff assets/fonts/Face.otf
 *
 * The browser is served the WOFF; GD draws the raster thumbnail through FreeType, which
 * does not read WOFF. Rather than unwrap on every request - which would mean writing a
 * font file somewhere on a host that may not let us - the SFNT is produced here and
 * shipped beside the WOFF. Both come from the same bytes, so they cannot disagree.
 *
 * WOFF is a container, not a different outline format: a 44-byte header, a table
 * directory, and each table optionally zlib-compressed. Unwrapping is mechanical and
 * loses nothing, which is why the glyph data is byte-identical to the original.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$source = $argv[1] ?? '';
$target = $argv[2] ?? '';

if ( '' === $source || '' === $target || ! is_readable( $source ) ) {
	fwrite( STDERR, "usage: php tools/woff-to-sfnt.php <font.woff> <font.otf>\n" );
	exit( 1 );
}

$woff = (string) file_get_contents( $source );

if ( 'wOFF' !== substr( $woff, 0, 4 ) ) {
	fwrite( STDERR, "not a WOFF file\n" );
	exit( 1 );
}

$u16 = static fn( string $s, int $o ): int => (int) unpack( 'n', substr( $s, $o, 2 ) )[1];
$u32 = static fn( string $s, int $o ): int => (int) unpack( 'N', substr( $s, $o, 4 ) )[1];

$flavor = substr( $woff, 4, 4 );
$count  = $u16( $woff, 12 );
$tables = array();

for ( $i = 0; $i < $count; $i++ ) {
	$entry = 44 + ( $i * 20 );
	$tag   = substr( $woff, $entry, 4 );
	$data  = substr( $woff, $u32( $woff, $entry + 4 ), $u32( $woff, $entry + 8 ) );
	$orig  = $u32( $woff, $entry + 12 );

	if ( strlen( $data ) < $orig ) {
		$plain = @zlib_decode( $data );

		if ( false === $plain ) {
			fwrite( STDERR, sprintf( "table %s will not decompress\n", $tag ) );
			exit( 1 );
		}

		$data = $plain;
	}

	if ( strlen( $data ) !== $orig ) {
		fwrite( STDERR, sprintf( "table %s is %d bytes, the directory says %d\n", $tag, strlen( $data ), $orig ) );
		exit( 1 );
	}

	$tables[ $tag ] = array( 'data' => $data, 'checksum' => $u32( $woff, $entry + 16 ) );
}

// An SFNT table directory is sorted by tag.
ksort( $tables );

$n        = count( $tables );
$entry_sel = (int) floor( log( $n, 2 ) );
$search    = (int) pow( 2, $entry_sel ) * 16;

$out    = $flavor . pack( 'nnnn', $n, $search, $entry_sel, ( $n * 16 ) - $search );
$offset = 12 + ( $n * 16 );
$body   = '';

foreach ( $tables as $tag => $table ) {
	$length = strlen( $table['data'] );
	$out   .= $tag . pack( 'NNN', $table['checksum'], $offset, $length );

	// Every table starts on a four-byte boundary.
	$padding = ( 4 - ( $length % 4 ) ) % 4;
	$body   .= $table['data'] . str_repeat( "\0", $padding );
	$offset += $length + $padding;
}

if ( false === file_put_contents( $target, $out . $body ) ) {
	fwrite( STDERR, "could not write the target\n" );
	exit( 1 );
}

printf(
	"%s -> %s\n  flavour %s, %d tables, %d bytes\n",
	basename( $source ),
	basename( $target ),
	preg_replace( '~[^\x20-\x7e]~', '.', $flavor ),
	$n,
	(int) filesize( $target )
);

// Prove FreeType will actually take it, rather than assuming the bytes are right.
if ( ! function_exists( 'imagettfbbox' ) ) {
	fwrite( STDERR, "  GD has no FreeType support here, so the result is unverified\n" );
	exit( 0 );
}

$box = @imagettfbbox( 100.0, 0.0, $target, 'ABC 123' );

if ( false === $box ) {
	fwrite( STDERR, "  FreeType refused the result\n" );
	exit( 1 );
}

printf( "  FreeType reads it: 'ABC 123' measures %dx%d at 100pt\n", $box[2] - $box[0], $box[1] - $box[7] );
