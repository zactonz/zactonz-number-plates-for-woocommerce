<?php
/**
 * German plate profile.
 *
 * Every dimension here is taken from Anlage 4 of the Fahrzeug-Zulassungsverordnung
 * (FZV 2023), the binding specification, rather than from a vendor's catalogue:
 * https://www.gesetze-im-internet.de/fzv_2023/anlage_4.html
 *
 * Known ambiguity in the source, recorded rather than resolved: Abschnitt 1 gives the
 * Kraftrad maximum width as 220 mm, while Abschnitt 2 Nr. 2a's illustration says 340 mm
 * and Abschnitt 2a Nr. 3 says 240 mm for the Wechselkennzeichen variant. The stricter
 * 220 mm is used, so this plugin never offers a plate wider than the regulation's own
 * narrowest reading.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return array(
	'id'       => 'de',
	'label'    => 'Germany',

	/*
	 * Ordered by preference, which is also the order Anlage 4 Abschnitt 1 Nr. 2.1
	 * applies: a registration is set in Mittelschrift, and only a registration that
	 * will not fit is set in the narrower Engschrift.
	 *
	 * Engschrift is not listed because no Engschrift face was found under a licence
	 * permitting redistribution. A merchant who holds one appends it through the
	 * zctz_np_profile_faces filter, and the long registrations this plugin currently
	 * declines then render without any other change.
	 */
	'fonts'    => array(
		array(
			'family'  => 'GL-Nummernschild-Mtl',
			'file'    => 'GL-Nummernschild-Mtl.woff',
			'sfnt'    => 'GL-Nummernschild-Mtl.otf',
			'metrics'  => 'de-font-metrics',
			'outlines' => 'de-font-outlines',
			'bundled' => true,
			'licence' => 'LICENSE-GL-Nummernschild.txt',
		),
	),

	/*
	 * eu_code is the colour of the country letter in the Euro field, and it is a token
	 * of its own rather than borrowed from eu_stars because the two need not be the
	 * same colour.
	 *
	 * Anlage 4 Abschnitt 1 Nr. 3 states no colour and defers: "Die Ausfuehrung des
	 * Erkennungsbuchstabens 'D' erfolgt nach der Verordnung (EG) Nr. 2411/98." That
	 * regulation's annex is published on EUR-Lex as an image, but its text survives in
	 * the Council common position that carried it, and the wording is:
	 *
	 *   Retro-reflecting blue background (Munsell reference 5,9 pb 3,4/15,1)
	 *   12 retro-reflecting yellow stars
	 *   Retro-reflecting distinguishing sign of the Member State of registration,
	 *   of a white or yellow colour, height = minimum 20 mm
	 *
	 * So white and yellow are both permitted for the letter, and white is what a German
	 * plate uses. The stars have no such latitude: yellow. Anlage 4's own 20 mm for the
	 * D is the minimum that annex states, and its 30 mm Sternenkranz is the annex's
	 * "15-mm radius circle" the star centres sit on, so the two agree where they meet.
	 *
	 * The blue and the yellow are the European emblem's own legislated web-palette
	 * values - Pantone Reflex Blue as RGB 0/51/153 and Pantone Yellow as RGB 255/204/0 -
	 * rather than anyone's eye for them. Anlage 4 names the blue as DIN 6171 "blau -
	 * Euro-Feld", which is the same colour expressed for retro-reflective sheeting, and
	 * the EU annex gives it a third time as Munsell 5,9 PB 3,4/15,1.
	 */
	'colours'  => array(
		'ground'    => '#ffffff',
		'ink'       => '#000000',
		'border'    => '#000000',
		'eu_band'   => '#003399',
		'eu_stars'  => '#ffcc00',
		'eu_code'   => '#ffffff',
	),

	/*
	 * A German registration is three ordered parts: the district code
	 * (Unterscheidungszeichen) and the Erkennungsnummer's letters and digits. The
	 * per-field limits below are the structural ones; the total-length rule is a
	 * property of the variant, because an E or H plate is allowed fewer positions
	 * than a standard one.
	 */
	'fields'   => array(
		'district' => array(
			'charset'  => 'letters',
			'min'      => 1,
			'max'      => 3,
			'required' => true,
			'source'   => 'de-districts',
		),
		'letters'  => array(
			'charset'  => 'letters',
			'min'      => 1,
			'max'      => 2,
			'required' => true,
		),
		'digits'   => array(
			'charset'  => 'digits',
			'min'      => 1,
			'max'      => 4,
			'required' => true,
		),
	),

	/*
	 * Cap height drives the typography: Mittel- and Engschrift are both 75 mm, and the
	 * verkleinerte Mittelschrift is 49 mm and permitted only on reduced two-line and
	 * motorcycle plates (Anlage 4 Abschnitt 1 Nr. 2.2).
	 */
	'sizes'    => array(
		'einzeilig-520'   => array( 'w' => 520, 'h' => 110, 'lines' => 1, 'cap' => 75, 'eu' => 'einzeilig' ),
		'zweizeilig-340'  => array( 'w' => 340, 'h' => 200, 'lines' => 2, 'cap' => 75, 'eu' => 'zweizeilig' ),
		'zweizeilig-280'  => array( 'w' => 280, 'h' => 200, 'lines' => 2, 'cap' => 75, 'eu' => 'zweizeilig' ),
		'kraftrad-220'    => array( 'w' => 220, 'h' => 200, 'lines' => 2, 'cap' => 49, 'eu' => 'zweizeilig' ),
		'kraftrad-180'    => array( 'w' => 180, 'h' => 200, 'lines' => 2, 'cap' => 49, 'eu' => 'zweizeilig' ),
		'verkleinert-255' => array( 'w' => 255, 'h' => 130, 'lines' => 2, 'cap' => 49, 'eu' => 'verkleinert' ),
	),

	/*
	 * max_positions counts the district code plus the Erkennungsnummer. Eight is the
	 * general ceiling (Abschnitt 1 Nr. 4); Oldtimer is capped at seven (Abschnitt 4
	 * Nr. 4) and Elektro at six (Abschnitt 5a Nr. 6).
	 *
	 * The suffix is a marking rather than a position and is not counted: both Abschnitt
	 * 4 Nr. 4 and Abschnitt 5a Nr. 6 word their limits as the letters and digits of the
	 * Erkennungsnummer, and neither names the mark. It is drawn by the renderer, never
	 * typed by a shopper - "Der Kennbuchstabe 'H' ist der Erkennungsnummer ohne
	 * Leerzeichen in gleicher Schriftart anzufuegen", and the same for "E".
	 */
	'variants' => array(
		'standard'  => array(
			'label'         => 'Standard',
			'max_positions' => 8,
			'suffix'        => null,
			'sizes'         => array( 'einzeilig-520', 'zweizeilig-340', 'zweizeilig-280' ),
		),
		/*
		 * verkleinert-255 belongs to the motorcycle variant, not to standard: Anlage 4
		 * Abschnitt 1 Nr. 1 allows the reduced two-line plate only for Leichtkrafträder
		 * and the vehicles named in § 12 Abs. 6 Nr. 1.
		 */
		'kraftrad'  => array(
			'label'         => 'Motorcycle',
			'max_positions' => 8,
			'suffix'        => null,
			'sizes'         => array( 'kraftrad-220', 'kraftrad-180', 'verkleinert-255' ),
		),
		'elektro'   => array(
			'label'         => 'Electric',
			'max_positions' => 6,
			'suffix'        => 'E',
			'sizes'         => array( 'einzeilig-520', 'zweizeilig-340', 'zweizeilig-280', 'kraftrad-220' ),
		),
		'oldtimer'  => array(
			'label'         => 'Historic',
			'max_positions' => 7,
			'suffix'        => 'H',
			'sizes'         => array( 'einzeilig-520', 'zweizeilig-340', 'zweizeilig-280' ),
		),
	),

	/*
	 * Plate geometry, all in millimetres.
	 *
	 * The Euro field is keyed by plate type rather than by cap height, because Anlage 4
	 * Abschnitt 1 Nr. 3 keys it that way and the two do not line up: an einzeiliges and
	 * a zweizeiliges Kennzeichen are both set at 75 mm cap height and carry 45 mm and
	 * 40 mm wide fields respectively. An earlier version of this file keyed on cap
	 * height and so could not express that.
	 *
	 * Quoted from Abschnitt 1 Nr. 3:
	 *
	 *   3.1 einzeilig            "Die Breite des Euro-Felds betraegt 45 mm und die
	 *                             Hoehe 88 mm." Sternenkranz 30 mm, "darunter in einem
	 *                             Abstand von 17 mm ... der Kennbuchstabe D mit einer
	 *                             Hoehe von 20 mm".
	 *   3.2 zweizeilig/Kraftrad  Breite 40 mm, Hoehe 88 mm; Sternenkranz 30 mm; D 20 mm
	 *                             at 17 mm.
	 *   3.3 verkleinert          Breite 35 mm, Hoehe 56 mm; Sternenkranz 22,5 mm; D
	 *                             15 mm at 8 mm.
	 *
	 * The individual star is not given in millimetres, only as a ratio: "Der Durchmesser
	 * jedes Sterns betraegt ein Sechstel des Durchmessers des Sternenkranzes." The
	 * renderer derives it rather than carrying a hard-coded radius.
	 *
	 * Verified 10 Oct 2026 against the published Anlage 4 on gesetze-im-internet.de,
	 * which carries the dimensioned drawings as figure descriptions: all three field
	 * sizes, both Sternenkranz diameters, both D heights and both star-to-D gaps are
	 * quoted there in millimetres and match what is below.
	 *
	 * Gaps are ranges, and so is the margin. Anlage 4 Abschnitt 1 Nr. 2.1 settles how
	 * they are used: "Der waagerechte Abstand der Beschriftung einschliesslich der
	 * Plaketten zum schwarzen, gruenen oder roten Rand, zum Euro-Feld ... muss auf
	 * beiden Seiten gleich sein." The lettering is therefore centred between the Euro
	 * field and the far border, and the gaps take up slack only as far as their maxima;
	 * whatever is left over becomes equal margin on both sides. A long registration is
	 * set tighter than a short one, which is what real plates do, and a short one no
	 * longer stretches one gap across half the plate. When the content will not fit even
	 * at the minima the renderer tries the next face, and refuses if none fits.
	 *
	 * Every figure here is measured to the ink, as the regulation measures: a face's
	 * advance width carries a side bearing at each end, and the renderer takes it off.
	 */
	'layout'   => array(
		'corner_radius' => 10.0,
		'border_inset'  => 4.0,
		'border_stroke' => 4.0,
		'side_margin'   => 8.0,
		'eu_light_edge' => 2.0,
		'country_code'  => 'D',

		/*
		 * The room the Stempelplakette sits in, between the Unterscheidungszeichen and
		 * the Erkennungsnummer. The minimum is the larger Plakette itself - Anlage 4
		 * draws it at 45 mm, with the 35 mm one above it - because a gap narrower than
		 * the seal cannot hold the seal. The maximum is secondary: published summaries
		 * of the single-line plate give this room as 63.5 to 67.5 mm, which is not in
		 * the regulation's running text. Both bracket what the geometry produces for
		 * Anlage 4's own example, HH HU 199, which lands at 63.5 mm.
		 */
		'gap_seal'      => array( 'min' => 45.0, 'max' => 67.5 ),

		/*
		 * Between the letters and the digits of the Erkennungsnummer. Anlage 4's figure
		 * description for the single-line plate: "In einem Abstand von 24 bis 30 mm
		 * schliesst sich Zahlenfolge 199 in schwarzer Mittelschrift an." Distinct from
		 * the 8 to 10 mm between characters within a group, which the face's own advance
		 * widths carry.
		 *
		 * Stated for a 75 mm cap, so at_cap says so and the renderer scales it for the
		 * shorter plates rather than putting a 75 mm plate's gap on a 49 mm one. That
		 * the figures scale is not an assumption: Anlage 4 gives 8 to 10 mm between
		 * characters at a 75 mm cap, and the face it prescribes sets them 5.75 mm apart
		 * at a 49 mm cap, which is the same figures scaled.
		 */
		'gap_group'     => array( 'min' => 24.0, 'max' => 30.0, 'at_cap' => 75.0 ),

		/*
		 * Between the Erkennungsnummer and the E or H marking. Anlage 4 words the mark
		 * as following "ohne Leerzeichen" - without a space - which governs how the
		 * registration is written, not how the plate is set: its figures put 20 to
		 * 30 mm between the last digit and the mark on a single-line plate. Stated at a
		 * 75 mm cap like the other gaps, and the figures for the smaller plates fall
		 * inside the same range once scaled, which is why one range serves all of them.
		 */
		'gap_suffix'    => array( 'min' => 20.0, 'max' => 30.0, 'at_cap' => 75.0 ),
		'eu_fields'     => array(
			'einzeilig'   => array(
				'w'           => 45.0,
				'h'           => 88.0,
				'kranz'       => 30.0,
				'code_height' => 20.0,
				'code_gap'    => 17.0,
			),
			'zweizeilig'  => array(
				'w'           => 40.0,
				'h'           => 88.0,
				'kranz'       => 30.0,
				'code_height' => 20.0,
				'code_gap'    => 17.0,
			),
			'verkleinert' => array(
				'w'           => 35.0,
				'h'           => 56.0,
				'kranz'       => 22.5,
				'code_height' => 15.0,
				'code_gap'    => 8.0,
			),
		),
	),

	/*
	 * Deliberately not offered, both for the same reason: Anlage 4 prescribes
	 * DIN 1451-2 for part of them, no DIN 1451 face may be redistributed, and drawing
	 * the wrong typeface would produce a plate that cannot legally be used.
	 *
	 * Versicherungskennzeichen (moped plates) - Abschnitt 1 Nr. 2.3 sets their whole
	 * lettering in DIN 1451-2 Mittelschrift rather than FE-Schrift.
	 *
	 * Saisonkennzeichen (seasonal plates) - the registration itself is Mittelschrift and
	 * would draw correctly, but the plate is not a plate without its Betriebszeitraum:
	 * a 30 x 75 mm field at the right-hand end carrying the first and last month of the
	 * operating period, one above a hyphen and one below. Abschnitt 5 Nr. 4 is explicit
	 * about the face: "Die Ausfuehrung der Ziffern, die den Betriebszeitraum angeben,
	 * erfolgt nach DIN 1451-2:1986-02." Offering the variant and drawing an ordinary
	 * plate - which is what this profile did until the field was found to have no
	 * reader - is worse than not offering it, because the shopper approves a plate that
	 * is not the one they asked for.
	 */
	'excluded' => array( 'versicherungskennzeichen', 'saisonkennzeichen' ),
);
