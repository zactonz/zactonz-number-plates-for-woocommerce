<?php
/**
 * Server-side plate validation.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

namespace Zactonz\NumberPlates\Validation;

use Zactonz\NumberPlates\Profile\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a configured plate is one the authorities would actually issue.
 *
 * The browser runs the same rules for instant feedback, but this class is the
 * authority: a plate that fails here never reaches the cart, however the request was
 * made. That matters more here than in most configurators, because the output is a
 * physical regulated object - an unvalidated order means a merchant manufacturing a
 * plate that cannot be registered.
 */
final class Validator {

	/**
	 * The profile whose rules are being applied.
	 *
	 * @var Profile
	 */
	private Profile $profile;

	/**
	 * @param Profile $profile Country profile.
	 */
	public function __construct( Profile $profile ) {
		$this->profile = $profile;
	}

	/**
	 * Validates one configured plate.
	 *
	 * @param array<string, string> $input Raw input: variant, size, district, letters, digits.
	 * @return array<int, string> Human-readable errors; empty when the plate is valid.
	 */
	public function validate( array $input ): array {
		$errors = array();

		$variant_key = isset( $input['variant'] ) ? (string) $input['variant'] : '';
		$size_key    = isset( $input['size'] ) ? (string) $input['size'] : '';
		$variant     = $this->profile->variant( $variant_key );
		$size        = $this->profile->size( $size_key );

		if ( null === $variant ) {
			$errors[] = __( 'Choose a plate type.', 'zactonz-number-plates-for-woocommerce' );
			return $errors;
		}

		if ( null === $size ) {
			$errors[] = __( 'Choose a plate size.', 'zactonz-number-plates-for-woocommerce' );
			return $errors;
		}

		$allowed_sizes = (array) ( $variant['sizes'] ?? array() );

		if ( ! in_array( $size_key, $allowed_sizes, true ) ) {
			$errors[] = __( 'That size is not available for this plate type.', 'zactonz-number-plates-for-woocommerce' );
		}

		$district = $this->normalise( $input['district'] ?? '' );
		$letters  = $this->normalise( $input['letters'] ?? '' );
		$digits   = $this->normalise( $input['digits'] ?? '' );

		$errors = array_merge( $errors, $this->check_district( $district ) );
		$errors = array_merge( $errors, $this->check_letters( $letters ) );
		$errors = array_merge( $errors, $this->check_digits( $digits ) );

		if ( array() !== $errors ) {
			return $errors;
		}

		return $this->check_combination( $district, $letters, $digits, $variant );
	}

	/**
	 * Uppercases and strips whitespace without touching multibyte characters.
	 *
	 * @param string $value Raw value.
	 * @return string Normalised value.
	 */
	private function normalise( string $value ): string {
		return mb_strtoupper( trim( $value ) );
	}

	/**
	 * Checks the district code (Unterscheidungszeichen).
	 *
	 * Umlauts are permitted here and only here: 42 of the 733 German codes contain one -
	 * FÜ, GÖ, AÖ - so an A-Z test would reject Fürth and Göttingen.
	 *
	 * @param string $district District code.
	 * @return array<int, string> Errors.
	 */
	private function check_district( string $district ): array {
		if ( '' === $district ) {
			return array( __( 'Enter the district code.', 'zactonz-number-plates-for-woocommerce' ) );
		}

		if ( ! preg_match( '/^[A-ZÄÖÜ]{1,3}$/u', $district ) ) {
			return array( __( 'The district code may only contain one to three letters.', 'zactonz-number-plates-for-woocommerce' ) );
		}

		if ( null === $this->profile->district( $district ) ) {
			return array(
				sprintf(
					/* translators: %s: the district code the shopper entered. */
					__( '%s is not a district code that is issued.', 'zactonz-number-plates-for-woocommerce' ),
					$district
				),
			);
		}

		return array();
	}

	/**
	 * Checks the Erkennungsnummer letters.
	 *
	 * Unlike the district code, these exclude the umlauts entirely.
	 *
	 * @param string $letters Letters.
	 * @return array<int, string> Errors.
	 */
	private function check_letters( string $letters ): array {
		if ( '' === $letters ) {
			return array( __( 'Enter the letters.', 'zactonz-number-plates-for-woocommerce' ) );
		}

		if ( ! preg_match( '/^[A-Z]{1,2}$/u', $letters ) ) {
			return array( __( 'The letters may only be one or two characters, A to Z, without umlauts.', 'zactonz-number-plates-for-woocommerce' ) );
		}

		return array();
	}

	/**
	 * Checks the Erkennungsnummer digits.
	 *
	 * @param string $digits Digits.
	 * @return array<int, string> Errors.
	 */
	private function check_digits( string $digits ): array {
		if ( '' === $digits ) {
			return array( __( 'Enter the numbers.', 'zactonz-number-plates-for-woocommerce' ) );
		}

		if ( ! preg_match( '/^[0-9]{1,4}$/', $digits ) ) {
			return array( __( 'The numbers may only be one to four digits.', 'zactonz-number-plates-for-woocommerce' ) );
		}

		if ( '0' === substr( $digits, 0, 1 ) ) {
			return array( __( 'The numbers may not begin with a zero.', 'zactonz-number-plates-for-woocommerce' ) );
		}

		return array();
	}

	/**
	 * Checks the rules that only apply once the parts are put together.
	 *
	 * @param string               $district District code.
	 * @param string               $letters  Erkennungsnummer letters.
	 * @param string               $digits   Erkennungsnummer digits.
	 * @param array<string, mixed> $variant  Variant specification.
	 * @return array<int, string> Errors.
	 */
	private function check_combination( string $district, string $letters, string $digits, array $variant ): array {
		$errors = array();

		$max         = (int) ( $variant['max_positions'] ?? 8 );
		$recognition = mb_strlen( $letters ) + mb_strlen( $digits );
		$total       = mb_strlen( $district ) + $recognition;

		if ( $total > $max ) {
			$errors[] = sprintf(
				/*
				 * Phrased without an article on purpose: the plate type label is data,
				 * and "A Electric plate" is what an article hard-coded here produces.
				 */
				/* translators: 1: maximum number of characters, 2: the plate type label. */
				__( '%2$s plates may carry at most %1$d characters in total.', 'zactonz-number-plates-for-woocommerce' ),
				$max,
				(string) ( $variant['label'] ?? '' )
			);
		}

		/*
		 * A three-letter district code leaves at most five characters for the
		 * Erkennungsnummer, which is tighter than the overall ceiling implies.
		 */
		if ( 3 === mb_strlen( $district ) && $recognition > 5 ) {
			$errors[] = __( 'With a three-letter district code, the letters and numbers may total at most five characters.', 'zactonz-number-plates-for-woocommerce' );
		}

		if ( in_array( $letters, $this->blocked_sequences(), true ) ) {
			$errors[] = sprintf(
				/* translators: %s: the letter combination the shopper entered. */
				__( 'The combination %s is not issued.', 'zactonz-number-plates-for-woocommerce' ),
				$letters
			);
		}

		return $errors;
	}

	/**
	 * Returns the letter combinations this plugin refuses by default.
	 *
	 * There is no codified national list. The FZV requires only that a combination
	 * "nicht gegen die guten Sitten verstoßen" darf - must not offend public decency -
	 * and each registration office applies that judgement itself, so practice varies by
	 * district. The defaults below are the sequences conventionally withheld across
	 * Germany; they are offered as a sensible default rather than as law, which is why
	 * they are filterable. Do not describe them to a shopper as illegal.
	 *
	 * @return array<int, string> Blocked letter sequences.
	 */
	private function blocked_sequences(): array {
		/**
		 * Filters the letter combinations refused on the Erkennungsnummer.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $blocked    Blocked sequences.
		 * @param string             $profile_id Profile identifier.
		 */
		return (array) apply_filters(
			'zctz_np_blocked_sequences',
			array( 'HJ', 'KZ', 'NS', 'SA', 'SS' ),
			$this->profile->id()
		);
	}
}
