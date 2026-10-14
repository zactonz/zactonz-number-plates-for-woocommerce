# Zactonz Number Plates for WooCommerce

Sell made-to-order vehicle number plates through WooCommerce. A shopper configures a
registration and watches the plate being drawn at its real dimensions; the order carries
the same drawing as production-ready SVG.

## The one idea worth knowing

There is a single layout. The live preview, the file the merchant manufactures from and
the thumbnail in the order email all come out of `Svg::layout()`, which computes every
position in millimetres once. A JavaScript preview would have been a second layout engine
free to drift from the first, and a plate that looks right in a browser and wrong at
520 mm is the failure this product exists to avoid.

There are two *painters* over that one layout: `Render\Svg` for vector and `Render\Png`
for raster. The raster exists only because email clients do not draw SVG. It decides
nothing except how many pixels a millimetre is worth — every coordinate it paints at
comes from the shared layout unchanged.

The vector painter draws every character as a filled shape rather than setting it as
text. An SVG that sets text depends on whatever opens it honouring `@font-face`, and both
ends of the chain fail at that: librsvg, which much production tooling rasterises with,
ignores an embedded WOFF and substitutes a system face, so a plate comes out in the wrong
typeface with the umlaut gone; and in a browser the font is a request that a content
policy, an asset rewriter or an offline moment can lose, which would leave a shopper
approving their plate in the wrong letterforms. Shapes remove both. They also mean a
product page fetches no font file at all. Outlines are extracted once by
`tools/font-outlines.php` and shipped as data; a face with no outline for a character
falls back to text, which is the only path that still sets any.

Nothing is stored as an image. An order holds the recipe — plate type, size, district
code, letters, digits — and the artwork is rebuilt from it on request, so orders stay
small and a later fix to the renderer reaches orders placed before it.

## Where the characters go

Anlage 4 Abschnitt 1 Nr. 2.1 is the rule the horizontal layout obeys:

> Der waagerechte Abstand der Beschriftung einschliesslich der Plaketten zum schwarzen,
> gruenen oder roten Rand, zum Euro-Feld … muss auf beiden Seiten gleich sein.

The lettering is therefore **centred** between the Euro field and the far border, never
stretched to fill the width. The gaps inside it are ranges rather than single figures, so
slack is taken up in order: the Plaketten gap first, because that is the one that visibly
stretches on a real plate, then the gap between the letters and the digits, each only as
far as the regulation lets it grow. What is still left over becomes equal margin on both
sides.

Everything is measured to the **ink**. A face carries a side bearing at each end of a
glyph — 4.5 mm on the bundled face at a 75 mm cap — and Anlage 4's 8 mm minimum is a
distance to the letters, not to the box a typesetter would draw round them, so `Face`
reads each glyph's extent off its outline and the layout takes the bearing off.

Rendering Anlage 4's own example, `HH HU 199` on a 520 mm plate, lands on every figure it
gives: 9 mm between characters (it allows 8–10), 24 mm from the letters to the digits (it
says 24–30), 63.5 mm for the Plaketten (published summaries say 63.5–67.5), and 8 mm of
clearance at each end — equal, and exactly the stated minimum. None of those were tuned
to match; they fall out of centring the block and letting the gaps breathe.

`tools/measure-plates.php` re-measures all of it from the emitted paths on every run, and
each check is proven to trip by fault injection.

## What ships

- The profile engine: a country is data, not a code branch.
- The German profile, complete — Anlage 4 sizes and cap heights, four plate types, all
  733 district codes, and the FE-Mittelschrift face the regulation prescribes. Electric
  and Historic plates carry the E and H markings, drawn by the renderer rather than typed:
  Anlage 4 appends them to the Erkennungsnummer "ohne Leerzeichen in gleicher Schriftart",
  and neither counts towards the position limit.
- Server-side validation: structure, the real district list, per-type position limits,
  and whether the plate can physically be drawn at the chosen size.
- Storefront configurator, cart and order persistence, pricing, artwork download.

## Layout

```
igniter.php                  bootstrap, autoloader, WooCommerce feature declarations
uninstall.php                opt-in removal of the product settings; orders never
data/de-profile.php          the German specification, sourced from FZV Anlage 4
data/de-districts.php        733 Unterscheidungszeichen
data/de-font-metrics.php     advance widths, measured from the bundled face
inc/Profile/                 the profile value object
inc/Font/                    faces and their measurements
inc/Validation/              the server-side authority on what is a legal plate
inc/Render/Svg.php           one renderer, millimetres throughout
inc/Storefront/              configurator and its three layouts, the preview and
                             image endpoints, and the plate on order and email pages
inc/Cart/                    cart and order persistence, pricing
inc/Admin/                   product binding, surcharges, artwork download
tools/                       gates and generators; not shipped in the package
```

## Working on it

```bash
bash tools/lint.sh          # parse, PHP 7.4 compatibility, CSS currency, metrics, house rules
bash tools/package.sh       # build dist/<slug>.zip
bash tools/check-pcp.sh     # Plugin Check against the packaged tree
```

`tools/woff-to-sfnt.php` unwraps a WOFF into the bare SFNT that FreeType needs, which is
what GD draws the raster thumbnail with; both files come from the same bytes, and
`tools/lint.sh` proves they still measure identically.

`tools/font-outlines.php` extracts glyph outlines as SVG path data by interpreting the
font's Type 2 charstrings; `tools/lint.sh` re-extracts them on every run and fails if the
shipped paths have drifted from the font.

`tools/font-metrics.php` measures a font file and prints the metrics array a face needs.
It handles WOFF and bare SFNT; WOFF2 is refused, because its table stream is
Brotli-compressed and PHP bundles no Brotli. `tools/lint.sh` uses it to prove the shipped
metrics still match the shipped font, since the renderer positions every glyph from them.

## Adding a typeface

The longest German registrations need FE-Engschrift, which is not bundled — no licence
was found permitting redistribution. A merchant licensed to use one adds it:

```php
add_filter(
	'zctz_np_profile_faces',
	function ( array $faces, string $profile_id ): array {
		if ( 'de' !== $profile_id ) {
			return $faces;
		}

		$faces[] = array(
			'family'  => 'FE-Engschrift',
			'path'    => '/absolute/path/to/engschrift.woff',
			'url'     => 'https://example.com/fonts/engschrift.woff',
			'metrics' => array( /* from tools/font-metrics.php */ ),
		);

		return $faces;
	},
	10,
	2
);
```

Faces are tried in order; the first that measures every character and fits the plate is
used. That reproduces the regulation's own Mittelschrift-then-Engschrift rule without the
renderer knowing anything about Germany. A face that cannot measure a character is passed
over for plates containing it rather than drawn with a glyph of zero width.

## The three layouts

`Storefront\Templates` renders one of Stacked, Studio or Guided, chosen per product.
They differ only in arrangement: the same fields, the same names, the same data
attributes, one stylesheet and one script. A layout cannot drift from the others on
validation or pricing because it owns neither.

Studio sizes itself with a container query rather than a media query — it is dropped
into whatever column a theme gives it, and on the theme this was built against that is
about 600px inside a 1400px window, where a width media query would have kept two
columns of 290px and made the plate unreadable.

Guided is three `<details name="zctz-np-step">` elements. The browser keeps one open by
itself, so the accordion needs no JavaScript; where exclusive `details` is unsupported
the steps all open, which is the Stacked layout again.

`zctz_np_default_template` sets the layout for a whole shop in one line.
`zctz_np_templates` plus `zctz_np_render_template` add one of your own.

### The stylesheet owns arrangement, not appearance

`assets/css/configurator.css` declares no colour, no border on any control, no
background, no font size and no button styling. Controls are plain visible radios,
labels and inputs that the theme styles; the plugin positions them and caps the plate's
width. Verified by swapping themes: the same markup takes a 1px `rgb(91,97,104)` border
and Arial 13px under one, and `rgb(240,240,240)` on `rgb(247,248,249)` with
`-apple-system` 17px under another, with nothing in the plugin changing.

The one edge it does draw — the Guided step boxes — comes from `currentColor` at 22%
rather than a grey of ours, so it reads correctly on a dark theme too.

## Adding a country

`zctz_np_profiles` maps a profile identifier to its specification array, or to the path
of a PHP file returning one:

```php
add_filter( 'zctz_np_profiles', function ( array $registered ): array {
	$registered['at'] = __DIR__ . '/profiles/at-profile.php';

	return $registered;
} );
```

One registry answers both "which countries may a merchant pick" and "load this one", so
the dropdown cannot offer something the loader then refuses — which it did until this was
fixed: the filter used to take labels only, and choosing the country it added made the
product silently stop being a plate product.

Keep the profile outside this plugin's directory, which an update replaces, and give its
font metrics and outlines inline as arrays rather than naming a file in `data/`.

A callback may build on a shipped profile — `Profile::get( 'de' )` inside the filter is
the obvious thing to write, and it re-enters the registry, so a re-entrant call gets the
shipped profiles rather than recursing. The filter itself runs once per request.

## The plate in the Cart and Checkout blocks

Those blocks render line item details through a component whose allowlist is
`a, b, em, i, strong, br, abbr, span` — no `img`, by WooCommerce's design — so the
picture cannot go beside the registration there. It goes where the blocks already draw
one: `woocommerce_store_api_cart_item_images` replaces the line's thumbnail with the
configured plate. No JavaScript, no build step, and the Mini Cart gets it too.

Two things that filter will not tell you. It validates what comes back with property
access — `$image->id`, `$image->thumbnail`, `$image->src` — so it must be given objects,
not arrays; an array is discarded with a warning in the log and the product's own images
are used instead. And both URLs must carry a host.

WooCommerce reserves a square slot for a product photograph, which makes a 520 mm plate
17px tall. `assets/css/thumbnail.css` widens that slot only where our own image sits,
matched on the query argument that serves it, so every other product keeps WooCommerce's
layout. The cart and the checkout summary need different figures, and the summary's slot
is a shrinking flex item whose image carries its own `max-width` cap. On a phone the cart
is a grid with an 80px image track; the plate fills the track and no more, because
rewriting their responsive grid is what breaks on the next release.

## What costs anything

Measured, not guessed. Profile loading, the 733-row district list, validation and the
SVG render are all **under half a millisecond**; rastering is the only expensive thing
here, and it is the only thing tuned.

`Render\Png` draws larger than asked and scales down, because GD will not antialias a
filled arc. The multiple is chosen per request rather than fixed, since the work grows
with output area times the multiple squared — a fixed 3 cost 27ms for an email thumbnail
and 266ms for a large one, the same picture and the same smoothing for ten times the
work. Holding the working canvas near a fixed pixel count instead gives small output the
full smoothing, where a stair-stepped 6px star is obvious, and spends less on large
output, where it is not. The block cart's thumbnail went 82ms → 18ms, and the largest
size this endpoint will draw is capped, so an open endpoint cannot be asked for a
six-megapixel canvas.

The Cart and Checkout blocks also used to download an image they then threw away: they
read the line's details through the Store API and strip images out of them, but the
browser has begun the fetch by the time they do. Those surfaces now get the link alone
and the plate as the line's own thumbnail, which took a block cart line from 104ms of
server work to 18ms.

A picture is a pure function of the recipe, the profile, the format and the height, so
the image endpoint builds its `ETag` from those and answers a revalidation before it
draws anything. A caller that already holds the picture pays for reading the request and
nothing else.

A product page fetches no font: with the preview drawn as shapes there is no `@font-face`
anywhere on it. That is worth about 19 KB and a connection on a first view, and a preview
response costs roughly a kilobyte more in exchange.

## What an order is owed

An order is a business record. It has to stay manufacturable for years, through whatever
happens to the catalogue afterwards, and three things make that true:

* **The line names its own rules.** The recipe stored on an order line carries the profile
  it was configured under, and the order surfaces ask the line before they ask the
  product. Turning plate mode off, switching the product to another country or deleting
  the product outright no longer takes the artwork away from orders already placed. A line
  written before this falls back to the product, as it did.
* **A plate that cannot be made cannot be checked out.** Validation re-runs on every
  plate line at the cart and the checkout, so a line that lost its recipe or was
  invalidated while it sat there is refused with a reason rather than becoming an order
  nobody can fill. It is reported twice over because the two checkouts ask differently —
  `woocommerce_check_cart_items` for the classic one, `woocommerce_store_api_cart_errors`
  for the blocks. WooCommerce fires its own first and stops there if anything was added
  to it, so only one ever speaks and a shopper is never told twice.
* **Ordering again works.** `woocommerce_order_again_cart_item_data` carries the plate
  forward, and the add-to-cart validation reads the cart item data WooCommerce passes it
  rather than only `$_POST` — which is also what makes the Store API's own add-to-cart
  path work, since neither sends a form.

## What is deliberately not offered

Two German plate types are withheld, both for the same reason: Anlage 4 prescribes
**DIN 1451-2** for part of their lettering rather than FE-Schrift, and no DIN 1451 face
may be redistributed, so drawing them would mean setting a regulated plate in the wrong
typeface.

- **Versicherungskennzeichen** (moped plates) — Abschnitt 1 Nr. 2.3 puts their whole
  lettering in DIN 1451-2.
- **Saisonkennzeichen** (seasonal plates) — the registration itself is Mittelschrift and
  would draw correctly, but a seasonal plate is not one without its Betriebszeitraum: a
  30 × 75 mm field at the right-hand end carrying the first and last month of the
  operating period, one above a hyphen and one below. Abschnitt 5 Nr. 4 is explicit —
  *"Die Ausführung der Ziffern, die den Betriebszeitraum angeben, erfolgt nach
  DIN 1451-2:1986-02."*

The second was offered until an independent QA pass found that the profile declared
`season => true` and nothing read it, so picking Seasonal drew an ordinary plate. Drawing
a plate the shopper did not ask for is worse than not offering the type, and worse than
refusing it: the whole claim of the design is that what is approved is what is made.

## Scope

The plugin draws plates. It makes no claim that a merchant is entitled to manufacture or
sell them, and it does not implement any jurisdiction's record-keeping. Moped plates
(Versicherungskennzeichen) are deliberately absent: they are specified in DIN 1451-2, not
FE-Schrift.

## Licence

GPL-2.0-or-later. The bundled typeface is the copyright of Gutenberg Labo and carries its
own all-permissive grant; see `assets/fonts/LICENSE-GL-Nummernschild.txt`, which quotes
the font file's own name table verbatim.
