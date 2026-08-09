<?php
/**
 * Uninstall routine for Holiday Mode for HivePress.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on
 * deactivation, so switching the plugin off temporarily loses nothing.
 *
 * **Deleting the plugin keeps the owner's settings by default**, along with
 * any away messages vendors wrote for themselves. Someone who deletes a
 * plugin by accident, or removes it to install a clean copy, gets
 * their settings back when they reinstall. Destruction is opt-in, through the
 * "Delete All Data" checkbox in the Removing the Plugin section of the
 * settings page, and is never a surprise.
 *
 * There is no way to ask at delete time. The confirmation form in
 * `wp-admin/plugins.php:398-410` is hard-coded with no `do_action` or
 * `apply_filters` inside it, so a checkbox cannot be added to that screen.
 * Worse, WordPress prints "(will also delete its data)" there whenever an
 * uninstall.php exists at all (`wp-admin/plugins.php:376-380`), whatever the
 * file actually does, so the setting's own description corrects it.
 *
 * Two things happen whichever way the setting is set, because they are not the
 * owner's data to keep:
 *
 * 1. **Hidden listings are always restored.** The vendor holiday flags are
 *    live enforcement state, not stored preferences: with the plugin gone
 *    nothing could ever bring those listings back, so retaining them would
 *    strand a vendor's whole portfolio invisibly and for good. Restoring also
 *    means the flags themselves must go, or they would claim a holiday that is
 *    no longer being enforced.
 * 2. **Cached values are cleared**, being regenerable runtime junk.
 *
 * The plugin schedules no actions, so there are none to unschedule.
 *
 * @package Holiday_Mode_For_HivePress
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Read the owner's choice first, before anything is touched.
$hm_delete_all = (bool) get_option( 'hp_holiday_mode_for_hivepress_delete_data' );

$hm_user_meta_key    = '_holiday_mode_for_hivepress';
$hm_listing_meta_key = '_holiday_mode_for_hivepress_prev_status';
$hm_hideable         = [ 'publish', 'pending', 'private', 'future' ];

/*
 * -----------------------------------------------------------------------------
 * Always done, whichever way the setting is set.
 * -----------------------------------------------------------------------------
 */

// Remove the holiday-mode flag from every user BEFORE restoring listings.
// If this file ever runs in a process where the plugin code was loaded
// (e.g. WP-CLI uninstalling with --deactivate), the still-registered
// enforcement hook would otherwise re-draft each listing as it is restored.
// With the flags gone, that hook stands down.
delete_metadata( 'user', 0, $hm_user_meta_key, '', true );

// Two extensions treat any transition to publish/pending as a brand new
// submission. Badges would inflate its listings_submitted counter; Paid
// Listings would spend one of the vendor's paid submissions per listing and
// could delete their package outright. Stand both listeners down while we
// restore, so removing the plugin never bills anyone.
$hm_suspended = [];

if ( function_exists( 'hivepress' ) ) {
	if ( hivepress()->get_version( 'badges' ) && hivepress()->badge ) {
		$hm_suspended[] = [ hivepress()->badge, 'update_listing_status' ];
	}

	if ( hivepress()->get_version( 'paid_listings' ) && hivepress()->listing_package ) {
		$hm_suspended[] = [ hivepress()->listing_package, 'update_user_packages' ];
	}
}

foreach ( $hm_suspended as $hm_listener ) {
	remove_action( 'hivepress/v1/models/listing/update_status', $hm_listener, 10 );
}

// Restore any listings still hidden by the plugin. A direct postmeta scan is
// the only way to find them at uninstall time (HivePress may be inactive),
// and caching is pointless for a one-off cleanup query.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$hm_listing_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$hm_listing_meta_key
	)
);

foreach ( (array) $hm_listing_ids as $hm_listing_id ) {
	$hm_listing_id = (int) $hm_listing_id;
	$hm_prev       = get_post_meta( $hm_listing_id, $hm_listing_meta_key, true );
	$hm_expired    = get_post_meta( $hm_listing_id, 'hp_expired_time', true );

	// Skip a listing whose own expiry passed while it was hidden, exactly as
	// the plugin's own restore does: removing the plugin must not hand a
	// listing visible time it is no longer entitled to.
	$hm_is_expired = '' !== $hm_expired && (int) $hm_expired && (int) $hm_expired < time();

	if ( 'draft' === get_post_status( $hm_listing_id ) && in_array( $hm_prev, $hm_hideable, true ) && ! $hm_is_expired ) {
		wp_update_post(
			[
				'ID'          => $hm_listing_id,
				'post_status' => $hm_prev,
			]
		);
	}

	delete_post_meta( $hm_listing_id, $hm_listing_meta_key );
}

// The updater's cached release lookup. A site transient lives under its own
// prefix, so neither an option sweep nor a plain delete_option() reaches it.
delete_site_transient( 'holiday_mode_for_hivepress_release' );

// Any other transient the plugin has ever set. Nothing writes one today, but a
// transient is stored as "_transient_{name}" plus a separate
// "_transient_timeout_{name}" row, so a prefix sweep anchored on the plugin
// name cannot match them, and a leftover timeout row with no value row is the
// classic orphan.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$hm_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( 'holiday_mode_for_hivepress' ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( 'holiday_mode_for_hivepress' ) . '%'
	)
);

foreach ( (array) $hm_transients as $hm_transient_name ) {
	delete_option( $hm_transient_name );
}

/*
 * -----------------------------------------------------------------------------
 * Everything below happens only when the owner asked for it.
 * -----------------------------------------------------------------------------
 */

if ( $hm_delete_all ) {

	// The away messages vendors wrote for themselves. On the retain path they
	// survive alongside the settings (they are stored words, not enforcement
	// state, and vendors reuse them holiday after holiday); the delete path
	// wipes them like everything else.
	delete_metadata( 'user', 0, '_holiday_mode_for_hivepress_headline', '', true );
	delete_metadata( 'user', 0, '_holiday_mode_for_hivepress_message', '', true );

	// Delete the settings. The "delete all data" option itself is excluded
	// here and removed at the very end: if this run fails part-way through,
	// the flag is still set, so a second attempt finishes the job rather than
	// silently reverting the site to "retain" with half the data gone.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$hm_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
			$wpdb->esc_like( 'hp_holiday_mode_for_hivepress' ) . '%',
			'hp_holiday_mode_for_hivepress_delete_data'
		)
	);

	foreach ( (array) $hm_option_names as $hm_option_name ) {
		delete_option( $hm_option_name );
	}

	// Last, and only once everything above has succeeded.
	delete_option( 'hp_holiday_mode_for_hivepress_delete_data' );
}
