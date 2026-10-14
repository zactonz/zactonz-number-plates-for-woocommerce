=== Zactonz Number Plates for WooCommerce ===
Contributors: zactonz
Tags: woocommerce, number plate, licence plate, product configurator, made to order
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Sell made-to-order number plates. Shoppers configure a registration, see it drawn to scale, and the order carries production-ready artwork.

== Description ==

Turn a WooCommerce product into a number plate a shopper configures themselves. They type a registration, pick a plate type and size, and see the plate drawn at its true dimensions as they type. The order that reaches you carries the same drawing as a scalable SVG, measured in millimetres, ready to send to a plotter or an engraver.

The drawing a shopper approves and the file you manufacture from come out of one renderer, so they cannot disagree. Nothing is stored as an image: the order holds the registration and the choices behind it, and the artwork is rebuilt from them whenever it is asked for.

**Germany is the country profile that ships.** Plates follow Anlage 4 of the Fahrzeug-Zulassungsverordnung: the sizes it lists, its cap heights, the Euro field, and the FE-Mittelschrift typeface it prescribes, which is bundled. All 733 district codes are included, so a registration is checked against the real list rather than a pattern.

= What the shopper gets =

* A live preview at the plate's real proportions, drawn on the server by the same code that produces your artwork.
* A picture of the plate they configured wherever the order is shown to them - the cart, the checkout, the confirmation email and their account - each one clicking through to the full-size plate.
* Immediate, specific feedback: an unknown district code, a registration too long for the plate type, a size that cannot fit what they typed.
* The price for the exact configuration, updated as they change it.

= What the merchant gets =

* A per-product switch, so only part of a catalogue is configurable.
* A choice of three layouts for the configurator, so it suits a narrow product column, a wide one, or a catalogue where shoppers need leading through the choices.
* Optional surcharges per size and per plate type, on top of the product's own price. Leave them blank for one flat price.
* The plate drawn on each order line, beside a **Download plate artwork (SVG)** button that gives you the vector to manufacture from. The registration is drawn as shapes, not as text, so the file needs no font installed and cannot come out in the wrong typeface whatever opens it.
* Validation that runs on the server. A plate that would be impossible to manufacture does not reach the cart.

= What it does not do =

It does not make you compliant with anything. Whether you may manufacture plates, and what you must record about who buys them, is between you and your regulator. This plugin draws plates; it makes no claim about your entitlement to sell them.

It does not offer Versicherungskennzeichen (moped plates) or Saisonkennzeichen (seasonal plates). Both need DIN 1451-2 for part of their lettering rather than FE-Schrift — the whole of a moped plate, and the two months in a seasonal plate's operating-period field — and no DIN 1451 face may be redistributed. Offering either would produce a plate that cannot legally be used.

The longest registrations - eight positions on a plate that cannot fit them in Mittelschrift - are declined rather than drawn badly. The regulation's answer is the narrower FE-Engschrift, and no Engschrift face was found under a licence that permits redistribution. If you are licensed to use one, add it with the `zctz_np_profile_faces` filter and those registrations start rendering. The filter needs the face's advance widths in font units; a tool that measures a WOFF or TrueType file and prints them ready to paste ships with the source, linked from the plugin page above.

= The three layouts =

Pick one per product, in the Number plate tab. All three collect the same values and are driven by the same script, so nothing about validation, pricing or artwork changes with the choice.

* **Stacked** - the plate above, the fields in one column under it. Fits any theme and any column width; the safe default.
* **Studio** - the plate held beside the controls, on a tinted stage. It measures its own column rather than the window, so in a narrow theme it folds back to a single column by itself instead of squeezing.
* **Guided** - three numbered steps, one open at a time, built on the browser's own accordion so it needs no script to collapse.

Plate type and size are radio groups rather than dropdowns: a shopper comparing four plate types should see four plate types, and a set of mutually exclusive choices is what a radio group is, so it keyboards and announces correctly without help.

**Your theme styles the controls.** The plugin sets no colours, no borders on inputs, no button look and no type sizes - it arranges the fields and decides how wide the plate may be, and leaves the rest to your theme, so the configurator matches your shop on the day you install it and still matches it after a redesign.

Developers: `zctz_np_default_template` sets the layout for every product that names none, which saves setting it on each one; `zctz_np_templates` and `zctz_np_render_template` add a layout of your own.

= Why the artwork contains no text =

An SVG that sets text depends on whatever opens it honouring the embedded font, and production software often does not - a plate opened in a cutter or a plotter can come out in a substituted typeface with the umlauts missing, which is both wrong and not registrable. Every character in the download is therefore a filled shape taken from the same font, so the file renders identically everywhere and needs nothing installed. It is also about a fifth of the size.

= Where the picture appears =

Everywhere the order is shown: the cart, the checkout, the order confirmation, the order email, the customer's account page and the order screen in your admin. It is a PNG, because no mail client of consequence renders SVG - Gmail strips it and Outlook draws mail with Word - and the link beside it always opens the full-size vector.

On the classic cart and checkout the plate appears with the line's details. On WooCommerce's Cart and Checkout **blocks** it appears as the line item's own picture, in place of the product photograph, because those blocks render line item details through a component that permits a, b, em, i, strong, br, abbr and span and no images at all. Either way the shopper sees the plate they configured rather than a stock photograph of a blank one.

Drawing the raster needs GD with FreeType, which almost every host has. Without it the plugin falls back to the vector on the web and leaves the picture out of the email rather than sending a broken one.

= External services =

None. The plugin makes no outbound requests, loads no fonts or scripts from a third party, and sends nothing anywhere. The preview is produced by your own site.

= Bundled third-party assets =

One font is bundled, because a German plate has to be set in FE-Schrift - the typeface is part of the legal specification, not a styling choice.

**GL-Nummernschild-Mtl**, version 2.018, by Gutenberg Labo (http://gutenberg.osdn.jp/), derived from Karlgeorg Hoefer's FE-Mittelschrift. Shipped as `assets/fonts/GL-Nummernschild-Mtl.woff`.

The font file's own name table states its terms, quoted verbatim:

> These fonts are free softwares. Unlimited permission is granted to use, copy, and distribute it, with or without modification, either commercially and noncommercially. THESE FONTS ARE PROVIDED "AS IS" WITHOUT WARRANTY.

That is an all-permissive grant, compatible with this plugin's GPL-2.0-or-later licence. The font remains the copyright of Gutenberg Labo and is not covered by that licence. The full text, with the copyright notice, ships alongside the font as `assets/fonts/LICENSE-GL-Nummernschild.txt`.

No other font is bundled. Faces for other countries, and FE-Engschrift for the longest German registrations, are added by the merchant from a file they are licensed to use.

= Developers =

* `zctz_np_profile_faces` - the typefaces a country profile may set a plate in, in preference order. The first face that measures every character and fits the plate wins.
* `zctz_np_blocked_sequences` - letter sequences refused on the Erkennungsnummer. The shipped list is a convention, not a statute; see the FAQ.
* `zctz_np_load_admin`, `zctz_np_load_artwork`, `zctz_np_load_configurator`, `zctz_np_load_cart`, `zctz_np_load_pricing`, `zctz_np_load_rest` - switch off any one part.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate this plugin.
3. Edit a product, open the **Number plate** tab in Product data, and tick **Sell as a number plate**.
4. Choose the country, and set surcharges per size or plate type if you want them.
5. Publish. The configurator appears on the product page.

== Frequently Asked Questions ==

= Is the preview the same thing I manufacture? =

Yes, and that is the point of the design. One layout computes every position, in millimetres, and everything is drawn from it: the preview on the product page, the picture in the email, and the artwork you download. All three draw the registration as shapes rather than setting it as text, so none of them depends on a font arriving and being honoured. A shopper cannot be shown their plate in a substituted typeface and approve it, and the file you manufacture from needs nothing installed.

= Why does a short registration leave so much white space? =

Because the regulation asks for it. Anlage 4 requires the horizontal distance from the lettering to the border and to the Euro field to be the same on both sides, so the characters are centred rather than stretched across the plate. A three-character registration on a 520 mm plate genuinely has wide, equal margins — in practice a registration that short is ordered on a narrower plate, and the plate sizes a shopper can pick come from Anlage 4 rather than from this plugin.

The gaps inside the registration are not stretched either. They have the ranges the regulation gives and stay inside them, which is why a long registration is set a little tighter than a short one.

= Where do the forbidden letter combinations come from? =

The regulation says a registration must not offend against good morals; it does not publish a list. Germany's registration offices maintain their own, and they differ. The plugin therefore ships a short, documented default and exposes `zctz_np_blocked_sequences` so you can match the office you actually deal with. Treat the default as a starting point, not as law.

= Can I add another country? =

Yes, with the `zctz_np_profiles` filter. The engine already treats a country as data: ordered fields with character classes, sizes in millimetres, colours, geometry and typefaces. What a new country needs is that data plus a typeface you are licensed to use, because the face is usually part of the specification. Germany ships complete; no other profile does yet.

Register it from your own plugin and keep its files there - anything inside this plugin's directory is replaced when it updates. Give the profile its font metrics and glyph outlines as arrays rather than naming a data file, for the same reason. The same list answers both which countries a merchant may pick and which one to load, so a country you add is genuinely usable rather than merely listed.

= Can a shop owner add a plate size? =

Not from the admin, by design. German plate sizes are fixed by Anlage 4; a size typed into a settings screen would produce a plate that cannot be registered, which is the failure this plugin exists to prevent. A developer who needs different sizes registers a profile of their own with the filter above.

= Does it work with the block Cart and Checkout? =

Yes. The configured plate is attached through `woocommerce_add_cart_item_data`, which WooCommerce's own Store API applies as well, so the classic form and the block checkout take the same path. The plugin declares compatibility with High-Performance Order Storage and with the cart and checkout blocks.

= Why does my block theme's Add to Cart reload the page? =

Because the configurator puts real form fields on the product page. WooCommerce's Add to Cart + Options block looks for form elements from extensions and, finding them, renders an ordinary form instead of its no-reload path. That fallback is WooCommerce's own behaviour, and it is what makes the same fields work on classic and block themes alike.

= Are any custom database tables created? =

No. Profiles are code and data, the product binding and surcharges are product meta, and the configured plate lives on the cart item and then the order line. There is nothing to migrate.

Deleting the plugin leaves your product settings alone, because deleting a plugin is usually how it gets replaced and losing a catalogue's worth of settings would be a poor reward for it. If you want them gone, define `ZCTZ_NP_REMOVE_DATA` as true in `wp-config.php` before you delete. Orders are never touched either way.

= What happens to an old order if I change the product? =

Nothing. Each order line records the country rules it was configured under, so the artwork is still there after you switch the product to another country, turn plate mode off, or delete the product outright. An order you took is a record of what someone bought, and it stays manufacturable.

== Screenshots ==

1. The configurator on a product page, with the plate drawn to scale as the shopper types.
2. The Number plate tab in Product data: the country, the layout, and the optional surcharges.
3. A plate line on an order, with the artwork download.
4. The cart, with each line showing the plate that was configured rather than a stock photograph.

== Changelog ==

= 1.0.0 =
* First release.
* Three configurator layouts - Stacked, Studio and Guided - chosen per product.
* German profile complete: Anlage 4 sizes and cap heights, four plate types with the E and H markings drawn, all 733 district codes, bundled FE-Mittelschrift.
* Server-rendered live preview at true millimetre scale, shared with the production artwork.
* Server-side validation of district code, structure, length and per-type position limits.
* Optional surcharges per size and per plate type.
* Production SVG download on the order screen, with every character drawn as a shape so the file needs no font installed.
* Compatible with High-Performance Order Storage and the cart and checkout blocks.
* The configured plate shown as a picture on the cart, the checkout, the order, the email and the account, each linking to the full-size vector.
* Production artwork drawn as outlines rather than text, so it does not depend on a font being installed wherever it is opened.

== Upgrade Notice ==

= 1.0.0 =
First release.
