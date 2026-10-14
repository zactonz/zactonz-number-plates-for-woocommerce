<?php
/**
 * House rules for this plugin. Run through tools/lint.sh.
 *
 * Checks what a generic linter cannot: that the shipped data is internally consistent,
 * that the bundled typeface can actually draw every district code in the list, and that
 * nothing in the tree carries an attribution that does not belong to this shop.
 */

$root = dirname( __DIR__ );
$fail = array();
$warn = array();

define( 'ABSPATH', $root . '/' );

/**
 * @param string $root    Directory to walk.
 * @param string $pattern Pattern a path must match.
 * @return array<int, string> Matching paths.
 */
function znp_files( $root, $pattern ) {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $it as $file ) {
		$path = $file->getPathname();

		if ( preg_match( '~/(\.git|dist|node_modules)/~', $path ) ) {
			continue;
		}

		if ( preg_match( $pattern, $path ) ) {
			$out[] = $path;
		}
	}

	sort( $out );

	return $out;
}

$php_files   = znp_files( $root, '~\.php$~' );
$shipped_php = array_values( array_filter( $php_files, fn( $f ) => ! str_contains( $f, '/tools/' ) ) );
$js_files    = znp_files( $root, '~\.js$~' );
$css_min     = znp_files( $root, '~/assets/css/[^/]*(?<!un-compressed)\.css$~' );
$igniter     = (string) file_get_contents( $root . '/igniter.php' );

$rel = fn( $f ) => str_replace( $root . '/', '', $f );

/**
 * Returns a file's code with its comments removed.
 *
 * Checks that look for a forbidden construct have to read code rather than prose, or
 * the documentation explaining why the construct is forbidden trips the check that
 * forbids it.
 *
 * @param string $file Absolute path.
 * @return string Code, comments replaced by whitespace.
 */
function znp_code_only( $file ) {
	$out = '';

	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$out .= ' ';
			continue;
		}

		$out .= is_array( $token ) ? $token[1] : $token;
	}

	return $out;
}

// 1. Required files.
$required = array(
	'igniter.php',
	'readme.txt',
	'LICENSE',
	'data/de-profile.php',
	'data/de-districts.php',
	'data/de-font-metrics.php',
	'assets/css/configurator.css',
	'assets/css/configurator.un-compressed.css',
	'assets/js/configurator.js',
);

foreach ( $required as $file ) {
	if ( ! file_exists( $root . '/' . $file ) ) {
		$fail[] = "missing required file: {$file}";
	}
}

// 2. Slug, from the Plugin Name header, must equal the directory and the text domain.
$name = '';
if ( preg_match( '~^\s*\*\s*Plugin Name:\s*(.+)$~m', $igniter, $m ) ) {
	$name = trim( $m[1] );
}

$slug = strtolower( preg_replace( '~[^a-z0-9]+~i', '-', $name ) );
$slug = trim( $slug, '-' );

if ( '' === $name ) {
	$fail[] = 'igniter.php has no Plugin Name header';
} elseif ( basename( $root ) !== $slug ) {
	$fail[] = sprintf( 'Plugin Name "%s" derives slug "%s" but the directory is "%s"', $name, $slug, basename( $root ) );
}

$domain = '';
if ( preg_match( '~^\s*\*\s*Text Domain:\s*(.+)$~m', $igniter, $m ) ) {
	$domain = trim( $m[1] );
}

if ( $domain !== $slug ) {
	$fail[] = sprintf( 'text domain "%s" does not match slug "%s"', $domain, $slug );
}

// 3. Every translator call must use that text domain.
foreach ( $shipped_php as $file ) {
	$code = (string) file_get_contents( $file );

	if ( preg_match_all( '~\b(?:__|_e|_x|_n|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x)\s*\(~', $code, $calls, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $calls[0] as $call ) {
			$tail = substr( $code, $call[1], 400 );

			if ( ! str_contains( $tail, "'" . $domain . "'" ) ) {
				$fail[] = $rel( $file ) . ': a translator call does not use the ' . $domain . ' text domain';
				break;
			}
		}
	}
}

// 4. Required plugin headers.
foreach ( array( 'Requires at least', 'Requires PHP', 'Requires Plugins', 'License', 'Version', 'Description' ) as $header ) {
	if ( ! preg_match( '~^\s*\*\s*' . preg_quote( $header, '~' ) . ':\s*\S~m', $igniter ) ) {
		$fail[] = "igniter.php has no {$header} header";
	}
}

// 5. Version agreement across the header, the constant and readme.txt.
$versions = array();
if ( preg_match( '~^\s*\*\s*Version:\s*(.+)$~m', $igniter, $m ) ) {
	$versions['header'] = trim( $m[1] );
}
if ( preg_match( "~const VERSION\s*=\s*'([^']+)'~", $igniter, $m ) ) {
	$versions['constant'] = $m[1];
}
if ( file_exists( $root . '/readme.txt' ) ) {
	$readme = (string) file_get_contents( $root . '/readme.txt' );

	if ( preg_match( '~^Stable tag:\s*(.+)$~m', $readme, $m ) ) {
		$versions['readme stable tag'] = trim( $m[1] );
	}
}

if ( count( array_unique( $versions ) ) > 1 ) {
	$fail[] = 'version disagreement: ' . json_encode( $versions );
}

// 6. Prefixing. Anything declared at global scope has to carry the prefix.
foreach ( $shipped_php as $file ) {
	$code      = (string) file_get_contents( $file );
	$namespaced = (bool) preg_match( '~^namespace\s+Zactonz\\\\NumberPlates~m', $code );

	if ( preg_match_all( '~^function\s+([a-zA-Z0-9_]+)~m', $code, $fns ) ) {
		foreach ( $fns[1] as $fn ) {
			if ( ! $namespaced && 0 !== stripos( $fn, 'zctz_np_' ) ) {
				$fail[] = $rel( $file ) . ": global function {$fn}() is not prefixed";
			}
		}
	}

	if ( preg_match_all( "~\\bdefine\\s*\\(\\s*'([^']+)'~", $code, $consts ) ) {
		foreach ( $consts[1] as $const ) {
			if ( 0 !== strpos( $const, 'ZCTZ_NP_' ) ) {
				$fail[] = $rel( $file ) . ": constant {$const} is not prefixed";
			}
		}
	}

	if ( ! $namespaced && preg_match_all( '~^(?:final\s+)?class\s+([a-zA-Z0-9_]+)~m', $code, $classes ) ) {
		foreach ( $classes[1] as $class ) {
			if ( 0 !== stripos( $class, 'Zctz_Np_' ) ) {
				$fail[] = $rel( $file ) . ": global class {$class} is not prefixed";
			}
		}
	}
}

// 7. Direct access guard on every shipped PHP file.
foreach ( $shipped_php as $file ) {
	$code = (string) file_get_contents( $file );

	// uninstall.php is run by WordPress, which guards it with its own constant.
	$guard = str_contains( $file, '/uninstall.php' ) ? "defined( 'WP_UNINSTALL_PLUGIN' )" : "defined( 'ABSPATH' )";

	if ( ! str_contains( $code, $guard ) ) {
		$fail[] = $rel( $file ) . ': no ' . ( str_contains( $file, '/uninstall.php' ) ? 'WP_UNINSTALL_PLUGIN' : 'ABSPATH' ) . ' guard';
	}
}

// 8. Nothing may bootstrap WordPress itself. This was the old build's worst habit.
foreach ( array_merge( $shipped_php, $js_files ) as $file ) {
	$code = str_ends_with( $file, '.php' ) ? znp_code_only( $file ) : (string) file_get_contents( $file );

	if ( preg_match( '~wp-load\.php|wp-config\.php|wp-blog-header\.php~', $code ) ) {
		$fail[] = $rel( $file ) . ': bootstraps WordPress directly';
	}
}

// 9. No jQuery.
foreach ( array_merge( $shipped_php, $js_files ) as $file ) {
	if ( preg_match( '~jquery|\$\(\s*document\s*\)~i', (string) file_get_contents( $file ) ) ) {
		$fail[] = $rel( $file ) . ': references jQuery';
	}
}

// 10. No database tables of our own.
foreach ( $shipped_php as $file ) {
	if ( preg_match( '~CREATE TABLE|\$wpdb~i', (string) file_get_contents( $file ) ) ) {
		$fail[] = $rel( $file ) . ': touches the database directly';
	}
}

// 11. No remote resources. The SVG namespace and documentation links are not fetches.
$allowed = '~^https?://(www\.w3\.org/|developers\.zactonz\.com/|zactonz\.com/|spdx\.org/|wordpress\.org/|www\.gesetze-im-internet\.de/|de\.wikipedia\.org/|gutenberg\.osdn\.jp/|bundesrecht\.juris\.de/|www\.stvzo\.de/|commons\.wikimedia\.org/)~';

foreach ( array_merge( $shipped_php, $js_files, $css_min ) as $file ) {
	if ( preg_match_all( '~https?://[^\s"\'()<>,]+~', (string) file_get_contents( $file ), $urls ) ) {
		foreach ( array_unique( $urls[0] ) as $url ) {
			if ( ! preg_match( $allowed, $url ) ) {
				$fail[] = $rel( $file ) . ": remote resource {$url}";
			}
		}
	}
}

// 12. Minified CSS must ship with a readable source.
foreach ( $css_min as $file ) {
	$source = preg_replace( '~\.css$~', '.un-compressed.css', $file );

	if ( ! file_exists( $source ) ) {
		$fail[] = $rel( $file ) . ': minified CSS ships without its un-compressed source';
	}
}

// 13. Attribution. Only this shop may be named as the author.
// Assembled from fragments so this line cannot match itself and mask a real hit.
$author_pattern = '~\b(' . implode(
	'|',
	array(
		'co-' . 'authored-by',
		'gener' . 'ated with',
		'auth' . 'ored by',
		'assis' . 'ted by',
		'cla' . 'ude\.ai',
		'cla' . 'ude\.com',
		'anthro' . 'pic\.com/claude-code',
	)
) . ')\b~i';

foreach ( znp_files( $root, '~\.(php|js|css|txt|md|json|sh)$~' ) as $file ) {
	$code = (string) file_get_contents( $file );

	if ( preg_match( $author_pattern, $code, $m ) ) {
		$fail[] = $rel( $file ) . ': attribution that is not this shop - "' . $m[1] . '"';
	}
}

if ( preg_match( '~^\s*\*\s*Author:\s*(.+)$~m', $igniter, $m ) && 'Zactonz Technologies' !== trim( $m[1] ) ) {
	$fail[] = 'igniter.php Author header is not Zactonz Technologies';
}

// 14. Profile integrity. A profile that refers to a size or a face that is not there
//     would fail on the storefront rather than here, so it is checked here.
foreach ( znp_files( $root . '/data', '~-profile\.php$~' ) as $file ) {
	$profile = include $file;
	$id      = $rel( $file );

	if ( ! is_array( $profile ) ) {
		$fail[] = "{$id}: does not return an array";
		continue;
	}

	$sizes = array_keys( (array) ( $profile['sizes'] ?? array() ) );

	foreach ( (array) ( $profile['variants'] ?? array() ) as $key => $variant ) {
		foreach ( (array) ( $variant['sizes'] ?? array() ) as $size ) {
			if ( ! in_array( $size, $sizes, true ) ) {
				$fail[] = "{$id}: variant {$key} allows size {$size}, which the profile does not define";
			}
		}

		if ( ! isset( $variant['max_positions'] ) || (int) $variant['max_positions'] < 1 ) {
			$fail[] = "{$id}: variant {$key} has no usable max_positions";
		}
	}

	foreach ( (array) ( $profile['sizes'] ?? array() ) as $key => $size ) {
		foreach ( array( 'w', 'h', 'lines', 'cap' ) as $field ) {
			if ( ! isset( $size[ $field ] ) || (int) $size[ $field ] < 1 ) {
				$fail[] = "{$id}: size {$key} has no usable {$field}";
			}
		}

		/*
		 * Every size names the Euro field it carries, and that name has to resolve.
		 * Anlage 4 keys the field on plate type, not on cap height - einzeilig is
		 * 45 mm wide and zweizeilig 40 mm at the same 75 mm cap - so a size that
		 * named nothing would silently inherit whichever spec happened to be first.
		 */
		$eu_fields = (array) ( $profile['layout']['eu_fields'] ?? array() );
		$eu_key    = (string) ( $size['eu'] ?? '' );

		if ( '' === $eu_key ) {
			$fail[] = "{$id}: size {$key} names no Euro field";
		} elseif ( ! isset( $eu_fields[ $eu_key ] ) ) {
			$fail[] = "{$id}: size {$key} names Euro field {$eu_key}, which the profile does not define";
		} else {
			foreach ( array( 'w', 'h', 'kranz', 'code_height', 'code_gap' ) as $measure ) {
				if ( ! isset( $eu_fields[ $eu_key ][ $measure ] ) || (float) $eu_fields[ $eu_key ][ $measure ] <= 0.0 ) {
					$fail[] = "{$id}: Euro field {$eu_key} has no usable {$measure}";
				}
			}

			// The field has to fit the band it sits in, clear of the border.
			$inner = (float) ( $profile['layout']['border_inset'] ?? 0 ) + ( (float) ( $profile['layout']['border_stroke'] ?? 0 ) / 2 );
			$band  = 1 === (int) $size['lines'] ? (float) $size['h'] : (float) $size['h'] / 2;

			if ( (float) $eu_fields[ $eu_key ]['h'] + $inner > $band ) {
				$fail[] = sprintf(
					'%s: size %s gives the Euro field a %gmm band but %s is %gmm tall plus a %gmm border',
					$id,
					$key,
					$band,
					$eu_key,
					(float) $eu_fields[ $eu_key ]['h'],
					$inner
				);
			}
		}
	}

	// 15. Every declared face must exist, carry a licence file, and be measurable.
	foreach ( (array) ( $profile['fonts'] ?? array() ) as $i => $font ) {
		$file_name = (string) ( $font['file'] ?? '' );

		if ( '' === $file_name ) {
			$fail[] = "{$id}: face {$i} declares no file";
			continue;
		}

		if ( ! file_exists( $root . '/assets/fonts/' . $file_name ) ) {
			$fail[] = "{$id}: face {$i} refers to assets/fonts/{$file_name}, which is not shipped";
		}

		$sfnt = (string) ( $font['sfnt'] ?? '' );

		if ( '' !== $sfnt && ! file_exists( $root . '/assets/fonts/' . $sfnt ) ) {
			$fail[] = "{$id}: face {$i} names raster font assets/fonts/{$sfnt}, which is not shipped";
		}

		$licence = (string) ( $font['licence'] ?? '' );

		if ( '' === $licence ) {
			$fail[] = "{$id}: bundled face {$i} declares no licence file";
		} elseif ( ! file_exists( $root . '/assets/fonts/' . $licence ) ) {
			$fail[] = "{$id}: face {$i} refers to licence assets/fonts/{$licence}, which is not shipped";
		}

		$metrics_ref = $font['metrics'] ?? '';

		if ( is_string( $metrics_ref ) && '' !== $metrics_ref ) {
			$metrics_path = $root . '/data/' . $metrics_ref . '.php';

			if ( ! file_exists( $metrics_path ) ) {
				$fail[] = "{$id}: face {$i} refers to metrics {$metrics_ref}, which is not shipped";
				continue;
			}

			$metrics = include $metrics_path;

			if ( ! is_array( $metrics ) || empty( $metrics['advances'] ) ) {
				$fail[] = "{$id}: metrics {$metrics_ref} carry no advances";
				continue;
			}

			if ( (int) ( $metrics['cap_height'] ?? 0 ) < 1 || (int) ( $metrics['units_per_em'] ?? 0 ) < 1 ) {
				$fail[] = "{$id}: metrics {$metrics_ref} have no usable cap_height or units_per_em";
			}

			// 16. The face has to be able to draw every character the data can produce.
			$needed = array();

			foreach ( range( 'A', 'Z' ) as $letter ) {
				$needed[ $letter ] = true;
			}

			foreach ( range( '0', '9' ) as $digit ) {
				$needed[ $digit ] = true;
			}

			$districts_ref = (string) ( $profile['fields']['district']['source'] ?? '' );

			if ( '' !== $districts_ref && file_exists( $root . '/data/' . $districts_ref . '.php' ) ) {
				$districts = include $root . '/data/' . $districts_ref . '.php';

				foreach ( array_keys( (array) $districts ) as $code ) {
					foreach ( preg_split( '//u', (string) $code, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
						$needed[ $char ] = true;
					}
				}
			}

			$missing = array_values( array_diff( array_keys( $needed ), array_keys( (array) $metrics['advances'] ) ) );

			if ( array() !== $missing ) {
				$fail[] = sprintf(
					'%s: face %s cannot measure %d character(s) the data uses: %s',
					$id,
					$i,
					count( $missing ),
					implode( ' ', $missing )
				);
			}

			// The same coverage is needed for the shapes the download is drawn with,
			// or a plate falls back to embedded text for one awkward district code.
			$outlines_ref = $font['outlines'] ?? '';

			if ( is_string( $outlines_ref ) && '' !== $outlines_ref ) {
				$outlines_path = $root . '/data/' . $outlines_ref . '.php';

				if ( ! file_exists( $outlines_path ) ) {
					$fail[] = "{$id}: face {$i} refers to outlines {$outlines_ref}, which is not shipped";
				} else {
					$outlines = include $outlines_path;
					$absent   = is_array( $outlines )
						? array_values( array_diff( array_keys( $needed ), array_keys( $outlines ) ) )
						: array_keys( $needed );

					if ( array() !== $absent ) {
						$fail[] = sprintf(
							'%s: face %s has no outline for %d character(s) the data uses: %s',
							$id,
							$i,
							count( $absent ),
							implode( ' ', $absent )
						);
					}
				}
			}
		}
	}
}

// 17. District codes must be the shape the validator accepts, or they are unreachable.
foreach ( znp_files( $root . '/data', '~-districts\.php$~' ) as $file ) {
	$districts = include $file;
	$bad       = array();

	foreach ( array_keys( (array) $districts ) as $code ) {
		if ( ! preg_match( '~^[A-ZÄÖÜ]{1,3}$~u', (string) $code ) ) {
			$bad[] = $code;
		}
	}

	if ( array() !== $bad ) {
		$fail[] = sprintf( '%s: %d code(s) the validator would reject: %s', $rel( $file ), count( $bad ), implode( ' ', array_slice( $bad, 0, 12 ) ) );
	}

	foreach ( (array) $districts as $code => $entry ) {
		if ( ! is_array( $entry ) || '' === (string) ( $entry['district'] ?? '' ) || '' === (string) ( $entry['state'] ?? '' ) ) {
			$fail[] = $rel( $file ) . ": code {$code} has no district or state name";
			break;
		}
	}
}

// 18. A tool referred to by name has to be there. A data file that credits a generator
//     which is not shipped tells a maintainer something untrue about how to rebuild it.
foreach ( znp_files( $root, '~\.(php|txt|md)$~' ) as $file ) {
	if ( preg_match_all( '~tools/([a-z0-9._-]+\.(?:php|sh|py))~', (string) file_get_contents( $file ), $refs ) ) {
		foreach ( array_unique( $refs[1] ) as $tool ) {
			if ( ! file_exists( $root . '/tools/' . $tool ) ) {
				$fail[] = $rel( $file ) . ": refers to tools/{$tool}, which is not in the tree";
			}
		}
	}
}

// 19. Every silenced sniff must say why. A suppression without a reason is how a real
//     guideline breach reaches a reviewer unnoticed, which has happened to this shop
//     before: direct cURL calls sat behind a silenced sniff and the report read clean.
foreach ( $shipped_php as $file ) {
	foreach ( file( $file ) as $number => $line ) {
		if ( ! str_contains( $line, 'phpcs:ignore' ) && ! str_contains( $line, 'phpcs:disable' ) ) {
			continue;
		}

		if ( ! str_contains( $line, ' -- ' ) ) {
			$fail[] = sprintf( '%s:%d: a silenced sniff gives no reason', $rel( $file ), $number + 1 );
		}
	}
}

// 20. A shipped file readme.txt names has to exist, and has to be one that packaging
//     actually includes. Pointing a merchant at a file that is not in their copy is the
//     same defect as crediting a generator that is not in the tree.
if ( file_exists( $root . '/readme.txt' ) ) {
	$readme_text = (string) file_get_contents( $root . '/readme.txt' );
	$excluded    = array( 'tools/', 'dist/', 'docs/' );

	if ( preg_match_all( '~`?((?:assets|inc|data)/[A-Za-z0-9/._-]+)`?~', $readme_text, $named ) ) {
		foreach ( array_unique( $named[1] ) as $path ) {
			$path = rtrim( $path, '.' );

			if ( ! file_exists( $root . '/' . $path ) ) {
				$fail[] = "readme.txt names {$path}, which is not in the tree";
			}
		}
	}

	foreach ( $excluded as $directory ) {
		if ( str_contains( $readme_text, $directory ) ) {
			$fail[] = "readme.txt refers to {$directory}, which packaging excludes from the plugin merchants receive";
		}
	}
}

// 21. No inline styles in shipped markup.
foreach ( $shipped_php as $file ) {
	if ( preg_match( '~style\s*=\s*["\']~', (string) file_get_contents( $file ) ) ) {
		$warn[] = $rel( $file ) . ': inline style attribute';
	}
}

// 22. uninstall.php runs without the plugin loaded, so it names its meta keys as
// literals rather than through the constants that define them. That is the only way it
// can work and the one place the two can drift apart, so they are compared here.
$declared = array();

foreach ( $shipped_php as $file ) {
	if ( str_contains( $file, '/uninstall.php' ) ) {
		continue;
	}

	if ( preg_match_all( "~const\\s+[A-Z_]+\\s*=\\s*'(_zctz_np_[a-z_]+)'~", (string) file_get_contents( $file ), $found ) ) {
		$declared = array_merge( $declared, $found[1] );
	}
}

$uninstall = $root . '/uninstall.php';
$listed    = array();

if ( is_readable( $uninstall ) && preg_match_all( "~'(_zctz_np_[a-z_]+)'~", znp_code_only( $uninstall ), $found ) ) {
	$listed = $found[1];
}

$declared = array_unique( $declared );

/*
 * The plate recipe is deliberately absent: it lives on order lines, and an order is a
 * business record this plugin does not delete.
 */
$keeps = array( '_zctz_np_plate' );

foreach ( array_diff( $declared, $listed, $keeps ) as $missing ) {
	$fail[] = "uninstall.php does not remove {$missing}";
}

foreach ( array_diff( $listed, $declared ) as $unknown ) {
	$fail[] = "uninstall.php removes {$unknown}, which nothing declares";
}

foreach ( $warn as $message ) {
	echo "  WARN  {$message}\n";
}

foreach ( $fail as $message ) {
	echo "  FAIL  {$message}\n";
}

printf(
	"  %d check group(s): %d failure(s), %d warning(s)\n",
	22,
	count( $fail ),
	count( $warn )
);

exit( array() === $fail ? 0 : 1 );
