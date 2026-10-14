<?php
/**
 * A country plate profile.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * One country's plate specification, read from data rather than expressed in code.
 *
 * A country is a profile, not a branch: adding one means adding a data file and a
 * typeface, never a new code path. Everything that varies between countries - the
 * ordered fields and their character classes, the permitted sizes in millimetres, the
 * typography, the colours and the per-variant length limits - lives in the spec array
 * this object wraps.
 */
final class Profile {

	/**
	 * The specification this profile was built from.
	 *
	 * @var array<string, mixed>
	 */
	private array $spec;

	/**
	 * Lazily loaded district codes, keyed by code.
	 *
	 * @var array<string, array{district: string, state: string}>|null
	 */
	private ?array $districts = null;

	/**
	 * Profiles already built this request, keyed by identifier; null for one that does
	 * not resolve, so a missing profile is not looked for twice.
	 *
	 * @var array<string, self|null>
	 */
	private static array $loaded = array();

	/**
	 * The registry, once built. The filter that makes it runs once per request.
	 *
	 * @var array<string, array<string, mixed>|string>|null
	 */
	private static ?array $registry = null;

	/**
	 * Whether the registry is being built right now.
	 *
	 * @var bool
	 */
	private static bool $building = false;

	/**
	 * @param array<string, mixed> $spec Specification array.
	 */
	private function __construct( array $spec ) {
		$this->spec = $spec;
	}

	/**
	 * Returns every profile that can be loaded, as identifier to source.
	 *
	 * A source is either the specification array itself, or the path of a PHP file that
	 * returns one. The profiles shipped with the plugin are registered as paths and read
	 * only when something asks for them, so listing the countries does not mean loading
	 * all of them.
	 *
	 * @return array<string, array<string, mixed>|string> Identifier to source.
	 */
	private static function registry(): array {
		if ( null !== self::$registry ) {
			return self::$registry;
		}

		$registered = array();

		foreach ( (array) glob( ZCTZ_NP_DIR . '/data/*-profile.php' ) as $path ) {
			$registered[ str_replace( '-profile.php', '', basename( (string) $path ) ) ] = (string) $path;
		}

		/*
		 * Building a country out of a shipped one is the obvious thing to write in a
		 * zctz_np_profiles callback, and calling Profile::get() from inside it lands
		 * back here. Without this the filter would run again, and again. A re-entrant
		 * call gets the shipped profiles, which is what such a callback is reaching
		 * for; the outer call still returns the filtered set.
		 */
		if ( self::$building ) {
			return $registered;
		}

		self::$building = true;

		/**
		 * Filters the plate profiles this installation offers.
		 *
		 * Each entry is a profile identifier mapped either to its specification array or
		 * to the absolute path of a PHP file returning one. Use it to add a country the
		 * plugin does not ship, or to replace one it does.
		 *
		 * A profile added here is loadable, not merely listed: the same registry answers
		 * both "which countries may a merchant pick" and "load this one", so the dropdown
		 * cannot offer something the loader will then refuse.
		 *
		 * Keep a profile outside the plugin directory. Anything inside it is replaced on
		 * update. A profile's font metrics and glyph outlines may be given inline as
		 * arrays for the same reason; only the profiles shipped here name a data file.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>|string> $registered Identifier to source.
		 */
		$filtered = (array) apply_filters( 'zctz_np_profiles', $registered );

		self::$building = false;
		self::$registry = $filtered;

		return $filtered;
	}

	/**
	 * Loads a profile by its identifier.
	 *
	 * @param string $id Profile identifier, e.g. 'de'.
	 * @return self|null The profile, or null when no profile is registered under that identifier.
	 */
	public static function get( string $id ): ?self {
		$id = strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $id ) );

		if ( '' === $id ) {
			return null;
		}

		if ( array_key_exists( $id, self::$loaded ) ) {
			return self::$loaded[ $id ];
		}

		$registry = self::registry();
		$spec     = isset( $registry[ $id ] ) ? self::resolve( $registry[ $id ] ) : null;

		self::$loaded[ $id ] = null === $spec ? null : new self( $spec );

		return self::$loaded[ $id ];
	}

	/**
	 * Turns a registry entry into a specification.
	 *
	 * @param array<string, mixed>|string $source Specification, or the path of a file returning one.
	 * @return array<string, mixed>|null Specification.
	 */
	private static function resolve( $source ): ?array {
		if ( is_array( $source ) ) {
			return array() === $source ? null : $source;
		}

		if ( ! is_string( $source ) || '' === $source || ! is_readable( $source ) ) {
			return null;
		}

		$spec = require $source;

		return is_array( $spec ) && array() !== $spec ? $spec : null;
	}

	/**
	 * Lists the profiles a merchant may choose from.
	 *
	 * Built from the same registry the loader uses, so every country offered here can
	 * actually be loaded.
	 *
	 * @return array<string, string> Identifier to human label.
	 */
	public static function available(): array {
		$found = array();

		foreach ( array_keys( self::registry() ) as $id ) {
			$profile = self::get( (string) $id );

			if ( $profile instanceof self ) {
				$found[ (string) $id ] = $profile->label();
			}
		}

		return $found;
	}

	/**
	 * @return string Profile identifier.
	 */
	public function id(): string {
		return (string) ( $this->spec['id'] ?? '' );
	}

	/**
	 * @return string Human-readable country label.
	 */
	public function label(): string {
		return (string) ( $this->spec['label'] ?? '' );
	}

	/**
	 * @return array<string, mixed> Field definitions in display order.
	 */
	public function fields(): array {
		return (array) ( $this->spec['fields'] ?? array() );
	}

	/**
	 * @return array<string, mixed> Colour tokens.
	 */
	public function colours(): array {
		return (array) ( $this->spec['colours'] ?? array() );
	}

	/**
	 * Returns the typefaces this profile may set a plate in, most preferred first.
	 *
	 * @return array<int, array<string, mixed>> Face declarations.
	 */
	public function fonts(): array {
		return (array) ( $this->spec['fonts'] ?? array() );
	}

	/**
	 * @return array<string, mixed> Plate geometry in millimetres.
	 */
	public function layout(): array {
		return (array) ( $this->spec['layout'] ?? array() );
	}

	/**
	 * @return array<string, mixed> All permitted sizes, keyed by size identifier.
	 */
	public function sizes(): array {
		return (array) ( $this->spec['sizes'] ?? array() );
	}

	/**
	 * Returns one size's specification.
	 *
	 * @param string $key Size identifier.
	 * @return array<string, mixed>|null Size spec, or null when unknown.
	 */
	public function size( string $key ): ?array {
		$sizes = $this->sizes();

		return isset( $sizes[ $key ] ) ? (array) $sizes[ $key ] : null;
	}

	/**
	 * @return array<string, mixed> All plate variants, keyed by variant identifier.
	 */
	public function variants(): array {
		return (array) ( $this->spec['variants'] ?? array() );
	}

	/**
	 * Returns one variant's specification.
	 *
	 * @param string $key Variant identifier.
	 * @return array<string, mixed>|null Variant spec, or null when unknown.
	 */
	public function variant( string $key ): ?array {
		$variants = $this->variants();

		return isset( $variants[ $key ] ) ? (array) $variants[ $key ] : null;
	}

	/**
	 * Returns a configured plate written out, as a human would say it.
	 *
	 * One place, because five surfaces need the same string - the cart rows, the order
	 * line, the email, the picture's alt text and the downloaded file's name - and a
	 * plate that reads differently in the email from on the plate is a support ticket.
	 *
	 * The plate type's marking is part of the registration and is appended with no
	 * space: Anlage 4 has the E and the H following the Erkennungsnummer "ohne
	 * Leerzeichen". The gap the plate shows between them is a matter of how it is set,
	 * not of how it is written.
	 *
	 * @param array<string, string> $plate Plate values.
	 * @return string Registration, for example FU AB 123E.
	 */
	public function registration( array $plate ): string {
		$variant = $this->variant( (string) ( $plate['variant'] ?? '' ) );
		$suffix  = null === $variant ? '' : (string) ( $variant['suffix'] ?? '' );

		$written = trim(
			sprintf(
				'%s %s %s',
				(string) ( $plate['district'] ?? '' ),
				(string) ( $plate['letters'] ?? '' ),
				(string) ( $plate['digits'] ?? '' )
			)
		);

		return '' === $written ? '' : $written . $suffix;
	}

	/**
	 * Returns the district codes this profile recognises.
	 *
	 * Loaded on first use rather than with the profile, because the German list alone
	 * is 733 entries and most requests never need it.
	 *
	 * @return array<string, array{district: string, state: string}> Keyed by code.
	 */
	public function districts(): array {
		if ( null !== $this->districts ) {
			return $this->districts;
		}

		$this->districts = array();

		$fields = $this->fields();
		$source = isset( $fields['district']['source'] ) ? (string) $fields['district']['source'] : '';

		if ( '' === $source ) {
			return $this->districts;
		}

		$path = ZCTZ_NP_DIR . '/data/' . basename( $source ) . '.php';

		if ( is_readable( $path ) ) {
			$loaded = require $path;

			if ( is_array( $loaded ) ) {
				$this->districts = $loaded;
			}
		}

		return $this->districts;
	}

	/**
	 * Looks a district code up.
	 *
	 * @param string $code District code.
	 * @return array{district: string, state: string}|null The entry, or null when not recognised.
	 */
	public function district( string $code ): ?array {
		$districts = $this->districts();
		$code      = strtoupper( $code );

		return isset( $districts[ $code ] ) ? (array) $districts[ $code ] : null;
	}
}
