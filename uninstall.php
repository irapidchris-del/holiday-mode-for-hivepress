<?php
/**
 * Uninstall routine for Holiday Mode for HivePress.
 *
 * Restores any listings still hidden by the plugin (so vendors are not left
 * with a silently empty storefront) and removes the meta the plugin created.
 *
 * @package Holiday_Mode_For_HivePress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$user_meta_key    = '_holiday_mode_for_hivepress';
$listing_meta_key = '_holiday_mode_for_hivepress_prev_status';
$hideable         = [ 'publish', 'pending', 'private', 'future' ];

// Restore any listings still hidden by the plugin.
$listing_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$listing_meta_key
	)
);

if ( ! empty( $listing_ids ) ) {
	foreach ( $listing_ids as $listing_id ) {
		$listing_id = (int) $listing_id;
		$prev       = get_post_meta( $listing_id, $listing_meta_key, true );

		if ( 'draft' === get_post_status( $listing_id ) && in_array( $prev, $hideable, true ) ) {
			wp_update_post(
				[
					'ID'          => $listing_id,
					'post_status' => $prev,
				]
			);
		}

		delete_post_meta( $listing_id, $listing_meta_key );
	}
}

// Remove the holiday-mode flag from every user.
delete_metadata( 'user', 0, $user_meta_key, '', true );
