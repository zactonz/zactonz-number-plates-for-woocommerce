<?php
/**
 * Checks shipped glyph outlines against the font they were taken from.
 *
 * Usage: php tools/compare-outlines.php data/de-font-outlines.php assets/fonts/Face.woff
 *
 * Run by tools/lint.sh. The production artwork is drawn from these paths, so if they
 * drift from the font the merchant's preview and the thing they manufacture stop being
 * the same plate - and nothing on screen would say so.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root  = dirname( __DIR__ );
$table = $argv[1] ?? '';
$face  = $argv[2] ?? '';

if ( '' === $table || '' === $face ) {
	fwrite( STDERR, "usage: php tools/compare-outlines.php <outlines.php> <font file>\n" );
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

$shipped = include $root . '/' . ltrim( str_replace( $root . '/', '', $table ), '/' );

if ( ! is_array( $shipped ) || array() === $shipped ) {
	fwrite( STDERR, "  {$table}: does not return outlines\n" );
	exit( 1 );
}

$command = sprintf(
	'%s %s %s %s',
	escapeshellarg( PHP_BINARY ),
	escapeshellarg( $root . '/tools/font-outlines.php' ),
	escapeshellarg( $root . '/' . ltrim( str_replace( $root . '/', '', $face ), '/' ) ),
	escapeshellarg( implode( '', array_keys( $shipped ) ) )
);

$output = shell_exec( $command );

if ( null === $output || '' === trim( (string) $output ) ) {
	fwrite( STDERR, "  could not extract outlines from {$face}\n" );
	exit( 1 );
}

$tmp = tempnam( sys_get_temp_dir(), 'zctznp' );
file_put_contents( $tmp, $output );
$fresh = include $tmp;
unlink( $tmp );

if ( ! is_array( $fresh ) ) {
	fwrite( STDERR, "  extracting outlines from {$face} produced nothing usable\n" );
	exit( 1 );
}

$problems = array();

foreach ( $shipped as $char => $path ) {
	$found = $fresh[ $char ] ?? null;

	if ( null === $found ) {
		$problems[] = sprintf( "'%s': in the data file but not in the font", $char );
		continue;
	}

	if ( $found !== $path ) {
		$problems[] = sprintf( "'%s': outline differs from the font", $char );
	}
}

foreach ( array_keys( $fresh ) as $char ) {
	if ( ! isset( $shipped[ $char ] ) ) {
		$problems[] = sprintf( "'%s': in the font but not in the data file", $char );
	}
}

foreach ( $problems as $problem ) {
	fwrite( STDERR, '    ' . $problem . "\n" );
}

exit( array() === $problems ? 0 : 1 );
