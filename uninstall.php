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

// Remove the holiday-mode flag from every user BEFORE restoring listings.
// If this file ever runs in a process where the plugin code was loaded
// (e.g. WP-CLI uninstalling with --deactivate), the still-registered
// enforcement hook would otherwise re-draft each listing as it is restored.
// With the flags gone, that hook stands down.
delete_metadata( 'user', 0, $user_meta_key, '', true );

// The Badges extension counts every transition TO publish/pending as a new
// submission, so restoring here would inflate its listings_submitted
// counters. Stand its listener down while we restore.
$hm_badges = ( function_exists( 'hivepress' ) && hivepress()->get_version( 'badges' ) ) ? hivepress()->badge : null;

if ( $hm_badges ) {
	remove_action( 'hivepress/v1/models/listing/update_status', [ $hm_badges, 'update_listing_status' ], 10 );
}

// Restore any listings still hidden by the plugin. A direct postmeta scan is
// the only way to find them at uninstall time (HivePress may be inactive),
// and caching is pointless for a one-off cleanup query.
$listing_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

// Remove the update checker's cached release details.
delete_site_transient( 'holiday_mode_for_hivepress_release' );
