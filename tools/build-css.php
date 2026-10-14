<?php
/**
 * Builds the minified stylesheets from their *.un-compressed.css sources.
 *
 * Usage:
 *   php tools/build-css.php            write the minified files
 *   php tools/build-css.php --check    report whether they are current, write nothing
 *
 * The check mode exists because the gate that asks "is the build current?" used to
 * answer by running the build and diffing the result, which rewrote two files in the
 * tree on every run. A gate has to be able to run on a read-only checkout, and twice at
 * once, without touching what it is inspecting.
 */

$check   = in_array( '--check', array_slice( $argv, 1 ), true );
$sources = glob( __DIR__ . '/../assets/css/*.un-compressed.css' );

if ( ! $sources ) {
	fwrite( STDERR, "no *.un-compressed.css sources found\n" );
	exit( 1 );
}

/**
 * Minifies one stylesheet.
 *
 * @param string $css Stylesheet source.
 * @return string Minified stylesheet.
 */
function znp_minify_css( $css ) {
	$css = preg_replace( '~/\*.*?\*/~s', '', $css );
	$css = preg_replace( '~\s+~', ' ', $css );
	$css = preg_replace( '~\s*([{}>,])\s*~', '$1', $css );
	$css = preg_replace_callback( '~\{[^{}]*\}~', fn( $m ) => preg_replace( '~\s*([:;])\s*~', '$1', $m[0] ), $css );
	$css = str_replace( ';}', '}', $css );

	return trim( $css ) . "\n";
}

$stale = array();

foreach ( $sources as $src ) {
	$out      = preg_replace( '~\.un-compressed\.css$~', '.css', $src );
	$source   = (string) file_get_contents( $src );
	$minified = znp_minify_css( $source );

	if ( $check ) {
		$current = is_readable( $out ) ? (string) file_get_contents( $out ) : '';

		if ( $current !== $minified ) {
			$stale[] = basename( $out );
		}

		continue;
	}

	file_put_contents( $out, $minified );
	printf( "%s: %d bytes -> %d bytes\n", basename( $out ), strlen( $source ), strlen( $minified ) );
}

if ( $check && array() !== $stale ) {
	printf( "stale: %s\n", implode( ', ', $stale ) );
	exit( 1 );
}
