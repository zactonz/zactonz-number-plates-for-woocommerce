<?php
/**
 * Resolving a profile's typefaces.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Font;

use Zactonz\NumberPlates\Profile\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a profile's font declarations into measured faces, in preference order.
 *
 * A profile may declare more than one face because the German regulation itself does:
 * Anlage 4 sets a registration in Mittelschrift and falls back to the narrower
 * Engschrift when it will not fit the plate. The renderer walks this list in order and
 * uses the first face that both measures every character and fits, which reproduces
 * that rule without the renderer knowing anything about Germany.
 *
 * Only Mittelschrift ships, because it is the only plate face found under a licence
 * that permits redistribution. Engschrift and the faces other countries mandate are
 * added through the zctz_np_profile_faces filter by a merchant who holds a licence for
 * them - which a licensed plate maker already does, since the law obliges them to use
 * that exact typeface.
 */
final class Faces {

	/**
	 * Returns the faces available for a profile, most preferred first.
	 *
	 * @param Profile $profile Country profile.
	 * @return array<int, Face> Faces.
	 */
	public static function for_profile( Profile $profile ): array {
		$declared = $profile->fonts();

		/**
		 * Filters the typefaces a profile may set a plate in, in preference order.
		 *
		 * Each entry is an array with:
		 *   family  - string, the CSS font family name.
		 *   path    - string, absolute path to the font file. Falls back to 'file',
		 *             which is resolved inside the plugin's own assets/fonts directory.
		 *   url     - string, public URL of the font file. Derived from 'file' when absent.
		 *   sfnt    - string, the name of a TrueType or OpenType file in the plugin's
		 *             assets/fonts directory, or 'sfnt_path' for one elsewhere. Only a
		 *             WOFF needs this; it is what the raster thumbnail is drawn with,
		 *             because FreeType does not read WOFF. Produce one from a WOFF with
		 *             tools/woff-to-sfnt.php.
		 *   outlines - map of character to SVG path data in font units, or the base name
		 *             of a data file returning one. Used for the artwork a merchant
		 *             manufactures from, which is drawn as shapes rather than text so it
		 *             does not depend on the opening software honouring a font. Produce
		 *             one with tools/font-outlines.php.
		 *   metrics - array with units_per_em, cap_height and an advances map of
		 *             character to advance width in design units; or the base name of a
		 *             data file in the plugin's data directory that returns one.
		 *
		 * Generate the metrics array with tools/font-metrics.php, which prints it ready
		 * to paste. A face whose advances do not cover a character is skipped for any
		 * plate containing it rather than being drawn wrongly.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $declared   Declared faces.
		 * @param string                           $profile_id Profile identifier.
		 */
		$declared = apply_filters( 'zctz_np_profile_faces', $declared, $profile->id() );

		$faces = array();

		foreach ( (array) $declared as $descriptor ) {
			$face = self::build( (array) $descriptor );

			if ( $face instanceof Face ) {
				$faces[] = $face;
			}
		}

		return $faces;
	}

	/**
	 * Builds one face, or nothing when the declaration is unusable.
	 *
	 * @param array<string, mixed> $descriptor Face declaration.
	 * @return Face|null Face.
	 */
	private static function build( array $descriptor ): ?Face {
		$family = (string) ( $descriptor['family'] ?? '' );

		if ( '' === $family ) {
			return null;
		}

		$file = '' !== (string) ( $descriptor['file'] ?? '' ) ? basename( (string) $descriptor['file'] ) : '';
		$path = (string) ( $descriptor['path'] ?? '' );
		$url  = (string) ( $descriptor['url'] ?? '' );
		$sfnt = (string) ( $descriptor['sfnt_path'] ?? '' );

		if ( '' === $sfnt && '' !== (string) ( $descriptor['sfnt'] ?? '' ) ) {
			$sfnt = ZCTZ_NP_DIR . '/assets/fonts/' . basename( (string) $descriptor['sfnt'] );
		}

		/*
		 * A face supplied as TrueType or OpenType is already what FreeType wants, so it
		 * needs no separate file; only a WOFF does.
		 */
		if ( '' === $sfnt && '' !== $path && in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), array( 'ttf', 'otf' ), true ) ) {
			$sfnt = $path;
		}

		if ( '' === $path && '' !== $file ) {
			$path = ZCTZ_NP_DIR . '/assets/fonts/' . $file;
		}

		if ( '' === $url && '' !== $file ) {
			$url = ZCTZ_NP_URL . 'assets/fonts/' . $file;
		}

		$metrics  = self::metrics( $descriptor['metrics'] ?? array() );
		$outlines = self::outlines( $descriptor['outlines'] ?? array() );

		if ( array() === $metrics['advances'] ) {
			return null;
		}

		return new Face(
			$family,
			$path,
			$url,
			$sfnt,
			$metrics['units_per_em'],
			$metrics['cap_height'],
			$metrics['advances'],
			$outlines
		);
	}

	/**
	 * Normalises an outline declaration, which may be an array or a data file name.
	 *
	 * @param mixed $source Outline map, or the base name of a file in the data directory.
	 * @return array<string, string> Path data per character.
	 */
	private static function outlines( $source ): array {
		if ( is_string( $source ) && '' !== $source ) {
			$path = ZCTZ_NP_DIR . '/data/' . basename( $source ) . '.php';

			if ( ! is_readable( $path ) ) {
				return array();
			}

			$source = require $path;
		}

		if ( ! is_array( $source ) ) {
			return array();
		}

		$out = array();

		foreach ( $source as $char => $data ) {
			if ( is_string( $data ) && '' !== $data ) {
				$out[ (string) $char ] = $data;
			}
		}

		return $out;
	}

	/**
	 * Normalises a metrics declaration, which may be an array or a data file name.
	 *
	 * @param mixed $source Metrics array, or the base name of a file in the data directory.
	 * @return array{units_per_em: int, cap_height: int, advances: array<string, int>} Metrics.
	 */
	private static function metrics( $source ): array {
		$empty = array(
			'units_per_em' => 1000,
			'cap_height'   => 1000,
			'advances'     => array(),
		);

		if ( is_string( $source ) && '' !== $source ) {
			$path = ZCTZ_NP_DIR . '/data/' . basename( $source ) . '.php';

			if ( ! is_readable( $path ) ) {
				return $empty;
			}

			$source = require $path;
		}

		if ( ! is_array( $source ) || ! isset( $source['advances'] ) || ! is_array( $source['advances'] ) ) {
			return $empty;
		}

		$advances = array();

		foreach ( $source['advances'] as $char => $units ) {
			$advances[ (string) $char ] = (int) $units;
		}

		$upem = (int) ( $source['units_per_em'] ?? 0 );
		$cap  = (int) ( $source['cap_height'] ?? 0 );

		if ( $upem <= 0 || $cap <= 0 ) {
			return $empty;
		}

		return array(
			'units_per_em' => $upem,
			'cap_height'   => $cap,
			'advances'     => $advances,
		);
	}
}
