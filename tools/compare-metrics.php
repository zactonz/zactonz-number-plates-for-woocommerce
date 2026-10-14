<?php
/**
 * Checks a shipped metrics table against the font it claims to describe.
 *
 * Usage: php tools/compare-metrics.php data/de-font-metrics.php assets/fonts/Face.woff
 *
 * Run by tools/lint.sh. Exits non-zero on any disagreement, naming it.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root    = dirname( __DIR__ );
$table   = $argv[1] ?? '';
$face    = $argv[2] ?? '';

if ( '' === $table || '' === $face ) {
	fwrite( STDERR, "usage: php tools/compare-metrics.php <metrics.php> <font file>\n" );
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

$shipped = include $root . '/' . ltrim( str_replace( $root . '/', '', $table ), '/' );

if ( ! is_array( $shipped ) || ! isset( $shipped['advances'] ) ) {
	fwrite( STDERR, "  {$table}: does not return a metrics array\n" );
	exit( 1 );
}

$characters = implode( '', array_keys( $shipped['advances'] ) );
$command    = sprintf(
	'%s %s %s %s',
	escapeshellarg( PHP_BINARY ),
	escapeshellarg( $root . '/tools/font-metrics.php' ),
	escapeshellarg( $root . '/' . ltrim( str_replace( $root . '/', '', $face ), '/' ) ),
	escapeshellarg( $characters )
);

$output = shell_exec( $command );

if ( null === $output || '' === trim( (string) $output ) ) {
	fwrite( STDERR, "  could not measure {$face}\n" );
	exit( 1 );
}

$tmp = tempnam( sys_get_temp_dir(), 'zctznp' );
file_put_contents( $tmp, $output );
$measured = include $tmp;
unlink( $tmp );

if ( ! is_array( $measured ) || ! isset( $measured['advances'] ) ) {
	fwrite( STDERR, "  measuring {$face} produced nothing usable\n" );
	exit( 1 );
}

$problems = array();

foreach ( array( 'units_per_em', 'cap_height' ) as $key ) {
	if ( (int) $shipped[ $key ] !== (int) $measured[ $key ] ) {
		$problems[] = sprintf( '%s: table says %d, font says %d', $key, (int) $shipped[ $key ], (int) $measured[ $key ] );
	}
}

foreach ( $shipped['advances'] as $char => $units ) {
	$found = $measured['advances'][ $char ] ?? null;

	if ( null === $found ) {
		$problems[] = sprintf( "'%s': in the table but not in the font", $char );
		continue;
	}

	if ( (int) $units !== (int) $found ) {
		$problems[] = sprintf( "'%s': table says %d, font says %d", $char, (int) $units, (int) $found );
	}
}

foreach ( $problems as $problem ) {
	fwrite( STDERR, '    ' . $problem . "\n" );
}

exit( array() === $problems ? 0 : 1 );
