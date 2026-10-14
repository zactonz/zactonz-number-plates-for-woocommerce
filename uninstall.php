<?php
/**
 * What is removed when the plugin is deleted.
 *
 * Nothing, unless the site asks for it. The plugin holds no options of its own; what it
 * stores is the plate settings on products, and orders carry the configuration a shopper
 * approved. Deleting the plugin to replace it with a newer copy is ordinary maintenance,
 * and discarding a catalogue's worth of settings on the way would be a poor reward for
 * it - so removal is opt-in, as WooCommerce itself makes it.
 *
 * A site that does want a clean slate defines ZCTZ_NP_REMOVE_DATA as true in wp-config.php
 * before deleting the plugin. Order data is never touched either way: an order is a
 * business record, and the plate on it is what the customer bought.
 *
 * @package Zactonz\NumberPlates
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ZCTZ_NP_REMOVE_DATA' ) || ! ZCTZ_NP_REMOVE_DATA ) {
	return;
}

foreach (
	array(
		'_zctz_np_enabled',
		'_zctz_np_profile',
		'_zctz_np_template',
		'_zctz_np_price_size',
		'_zctz_np_price_variant',
	) as $zctz_np_meta_key
) {
	delete_post_meta_by_key( $zctz_np_meta_key );
}
