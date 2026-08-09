<?php
/**
 * Plugin Name:       Holiday Mode for HivePress
 * Plugin URI:        https://community.hivepress.io/u/chrisb/summary
 * Description:       Vendor-only Holiday Mode toggle that hides (drafts) and restores all of a vendor's listings, with an on-site banner while active and an away notice on the vendor's public profile. Restoring is entitlement-aware: it respects each listing's own expiry date, and any HivePress Membership or WooCommerce Subscription the vendor actually holds.
 * Version:           1.6.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  hivepress
 * Author:            Chris Bruce
 * Author URI:        https://community.hivepress.io/u/chrisb/summary
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       holiday-mode-for-hivepress
 * Domain Path:       /languages/
 * Update URI:        https://github.com/irapidchris-del/holiday-mode-for-hivepress
 *
 * @package Holiday_Mode_For_HivePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'HOLIDAY_MODE_FOR_HIVEPRESS_REPO' ) ) {
	define( 'HOLIDAY_MODE_FOR_HIVEPRESS_REPO', 'irapidchris-del/holiday-mode-for-hivepress' );
}

// Keep in step with the Version header above on every release.
if ( ! defined( 'HOLIDAY_MODE_FOR_HIVEPRESS_VERSION' ) ) {
	define( 'HOLIDAY_MODE_FOR_HIVEPRESS_VERSION', '1.6.1' );
}

require_once __DIR__ . '/includes/class-holiday-mode-for-hivepress-updater.php';

if ( ! class_exists( 'Holiday_Mode_For_HivePress' ) ) :

	/**
	 * Adds a vendor-facing "Holiday mode" toggle to the HivePress account
	 * settings form that hides and restores all of the vendor's listings.
	 */
	final class Holiday_Mode_For_HivePress {

		/**
		 * Settings tab slug, also used by the Plugins-screen quick link.
		 */
		const SETTINGS_TAB = 'holiday_mode';

		/**
		 * Option storing whether deleting the plugin should wipe its data.
		 * HivePress prefixes stored options with `hp_`.
		 */
		const DELETE_DATA_OPTION = 'hp_holiday_mode_for_hivepress_delete_data';

		/**
		 * Option recording the last version whose upgrade routines have run.
		 */
		const VERSION_OPTION = 'hp_holiday_mode_for_hivepress_version';

		/**
		 * User meta flag storing whether holiday mode is on.
		 */
		const USER_META_KEY = '_holiday_mode_for_hivepress';

		/**
		 * Listing post meta storing the status a listing had before it was hidden.
		 */
		const LISTING_META_PREV = '_holiday_mode_for_hivepress_prev_status';

		/**
		 * Form field name for the toggle.
		 */
		const FIELD = 'holiday_mode_for_hivepress';

		/**
		 * Statuses that represent a visible/scheduled listing we should hide.
		 * Anything else (draft, trash, auto-draft, inherit) is left untouched.
		 */
		const HIDEABLE = [ 'publish', 'pending', 'private', 'future' ];

		/**
		 * Singleton instance.
		 *
		 * @var Holiday_Mode_For_HivePress|null
		 */
		private static $instance = null;

		/**
		 * Intent recorded during form validation and applied once the user
		 * model actually saves. Prevents unrelated profile updates from
		 * toggling holiday mode.
		 *
		 * @var array|null
		 */
		private $pending_toggle = null;

		/**
		 * Guards against re-entering the listing enforcement hook from our own
		 * wp_update_post() calls.
		 *
		 * @var bool
		 */
		private $suspend_enforce = false;

		/**
		 * Per-request cache of vendor detection results.
		 *
		 * @var array
		 */
		private $vendor_cache = [];

		/**
		 * Returns the singleton instance.
		 *
		 * @return Holiday_Mode_For_HivePress
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Registers hooks. Private to enforce the singleton.
		 */
		private function __construct() {
			// Add the toggle to the account settings form only.
			add_filter( 'hivepress/v1/forms/user_update', [ $this, 'extend_settings_form' ], 1000, 2 );

			// Validate the toggle during the settings-form submission (this is
			// the only place we can reliably tell it apart from every other
			// profile update, and where we can surface a blocking error).
			add_filter( 'hivepress/v1/forms/user_update/errors', [ $this, 'validate_toggle' ], 1000, 2 );

			// Apply the toggle once the user model actually saves.
			add_action( 'hivepress/v1/models/user/update', [ $this, 'apply_toggle' ], 1000 );

			// While holiday mode is on, keep any newly visible listing hidden.
			// Both hooks are needed: HivePress fires `create` for listings
			// inserted directly with a visible status and `update` for every
			// later save.
			add_action( 'hivepress/v1/models/listing/create', [ $this, 'enforce_draft_while_holiday' ], 1000 );
			add_action( 'hivepress/v1/models/listing/update', [ $this, 'enforce_draft_while_holiday' ], 1000 );

			// Banner on account pages while holiday mode is active.
			add_action( 'wp_footer', [ $this, 'maybe_print_banner' ], 1000 );

			// Public notice on the vendor's profile page, so buyers know the
			// vendor is away and may be slower to reply. The `/blocks` variant
			// is required: it is the only template hook where the vendor
			// context exists.
			add_filter( 'hivepress/v1/templates/vendor_view_page/blocks', [ $this, 'add_vendor_notice_block' ], 100, 2 );

			// Settings tab, holding the delete-on-uninstall control.
			add_filter( 'hivepress/v1/settings', [ $this, 'add_settings' ] );

			if ( is_admin() ) {

				// One-time upgrade routines, run on the first admin visit
				// after an update.
				add_action( 'admin_init', [ $this, 'maybe_upgrade' ] );

				// Show admins why a vendor's listings are drafts.
				add_filter( 'manage_hp_listing_posts_columns', [ $this, 'add_listing_admin_columns' ] );
				add_action( 'manage_hp_listing_posts_custom_column', [ $this, 'render_listing_admin_columns' ], 10, 2 );
			}
		}

		/* ---------------- Upgrades ---------------- */

		/**
		 * Runs one-time upgrade routines after the plugin is updated.
		 *
		 * @return void
		 */
		public function maybe_upgrade() {
			$stored = (string) get_option( self::VERSION_OPTION );

			if ( HOLIDAY_MODE_FOR_HIVEPRESS_VERSION === $stored ) {
				return;
			}

			// Run on every version change rather than gating on the version
			// that introduced the sweep: it costs one indexed query when there
			// is nothing to do, and re-running it on each upgrade means a site
			// that was rolled back to a pre-1.3.2 build (which recreates the
			// debris) heals itself on its next update instead of never.
			$this->cleanup_stale_flags();

			update_option( self::VERSION_OPTION, HOLIDAY_MODE_FOR_HIVEPRESS_VERSION, false );
		}

		/**
		 * Removes the empty holiday-flag rows left behind by 1.3.1 and older.
		 *
		 * Those versions stored an empty string when holiday mode was switched
		 * off instead of deleting the row, so long-standing sites carry one
		 * inert row per vendor who ever used the feature. Only rows whose value
		 * is exactly '' are removed: an active flag holds '1' and must survive.
		 * This cannot go through delete_metadata(), which treats an empty
		 * meta value as "match any value" and would wipe active flags too.
		 *
		 * @return void
		 */
		private function cleanup_stale_flags() {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off upgrade sweep: the meta API cannot target rows by stored value, and caching a single-run query is pointless.
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
					self::USER_META_KEY,
					''
				)
			);

			if ( empty( $user_ids ) ) {
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- value-matched delete: delete_metadata() treats an empty value as "match any" and would wipe active flags; caches are invalidated per user below.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
					self::USER_META_KEY,
					''
				)
			);

			// A direct delete bypasses WordPress's meta cache, which matters on
			// hosts with a persistent object cache: without this, the deleted
			// rows would appear to survive until the cache expired.
			foreach ( array_unique( array_map( 'intval', $user_ids ) ) as $user_id ) {
				wp_cache_delete( $user_id, 'user_meta' );
			}
		}

		/* ---------------- Settings ---------------- */

		/**
		 * Adds the plugin's settings tab.
		 *
		 * The only setting is the delete-on-uninstall control, because a site
		 * owner cannot be asked at delete time: the confirmation form in
		 * `wp-admin/plugins.php` is hard-coded with no hook inside it, and
		 * WordPress prints its own "will also delete its data" warning whenever
		 * an uninstall.php exists at all, whatever that file really does. The
		 * section description has to correct that warning.
		 *
		 * @param array $settings Settings configuration.
		 * @return array
		 */
		public function add_settings( $settings ) {
			$settings[ self::SETTINGS_TAB ] = [
				'title'    => esc_html__( 'Holiday Mode', 'holiday-mode-for-hivepress' ),
				'_order'   => 100,

				'sections' => [
					'holiday_mode_for_hivepress_removal' => [
						'title'       => esc_html__( 'Removing the Plugin', 'holiday-mode-for-hivepress' ),
						'description' => esc_html__( 'Your settings are kept if you delete this plugin, so you can reinstall it and carry on. WordPress shows its own warning on the delete screen saying the data goes too, but that warning is the same for every plugin and does not apply here unless you tick the box below. Switching the plugin off never removes anything, and deleting it always brings hidden listings back first.', 'holiday-mode-for-hivepress' ),
						'_order'      => 10,

						'fields'      => [
							'holiday_mode_for_hivepress_delete_data' => [
								'label'       => esc_html__( 'Delete All Data', 'holiday-mode-for-hivepress' ),
								'caption'     => esc_html__( 'Delete this plugin\'s settings when the plugin is deleted', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'Leave this unticked unless you are certain. With it ticked, deleting the plugin also removes every setting on this page. It cannot be undone and there is no confirmation step. Either way, deleting the plugin restores every hidden listing to the status it had and switches holiday mode off for everyone, because listings must never be left hidden with no way to bring them back.', 'holiday-mode-for-hivepress' ),
								'type'        => 'checkbox',
								'_order'      => 10,
							],
						],
					],
				],
			];

			return $settings;
		}

		/* ---------------- UI: field ---------------- */

		/**
		 * Adds the holiday-mode checkbox to the account settings form.
		 *
		 * The `hivepress/v1/forms/user_update` filter also fires for subclasses
		 * (e.g. the "Complete Profile" form), so we bail unless this is exactly
		 * the User_Update form to avoid leaking the field into other flows.
		 *
		 * @param array       $form Form arguments.
		 * @param object|null $form_object The form instance, when provided.
		 * @return array
		 */
		public function extend_settings_form( $form, $form_object = null ) {
			// Fail closed: only the exact User_Update instance receives the
			// field, so a third-party apply_filters() call without the form
			// object cannot pick it up either.
			if ( ! is_object( $form_object ) || 'HivePress\Forms\User_Update' !== get_class( $form_object ) ) {
				return $form;
			}

			$user_id = get_current_user_id();
			if ( ! $user_id ) {
				return $form;
			}

			// Show to admins (for testing) and to vendors.
			if ( ! current_user_can( 'manage_options' ) && ! $this->is_user_vendor( $user_id ) ) {
				return $form;
			}

			if ( ! isset( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
				$form['fields'] = [];
			}

			// Mention the restore gate only where one will actually apply:
			// the bundled gate needs WooCommerce Subscriptions, and promising
			// a "membership" check the plugin never performs misleads vendors
			// (found on staging, 2026-08-04).
			$description = __( 'Turn this on to hide all of your listings until you switch it off.', 'holiday-mode-for-hivepress' );

			if ( function_exists( 'wcs_user_has_subscription' ) ) {
				$description .= ' ' . __( 'Your listings are restored when you switch it off, as long as your subscription is active at that time.', 'holiday-mode-for-hivepress' );
			}

			$form['fields'][ self::FIELD ] = [
				'label'       => __( 'Holiday mode (hide all listings)', 'holiday-mode-for-hivepress' ),
				'caption'     => __( 'Hide all of my listings while I am away', 'holiday-mode-for-hivepress' ),
				'description' => $description,
				'type'        => 'checkbox',
				'default'     => (bool) get_user_meta( $user_id, self::USER_META_KEY, true ),

				// Form-only field: never merge with a same-named user model
				// field (an admin-defined user attribute could create one) and
				// never persist through the model save. Same pattern as core's
				// current_password field.
				'_separate'   => true,
				'_order'      => 330,
			];

			return $form;
		}

		/* ---------------- Validate / gate ---------------- */

		/**
		 * Reads the submitted toggle from the settings form, enforces the
		 * membership gate for switching off, and records the intent to apply
		 * after the model saves.
		 *
		 * Runs only during the genuine User_Update form submission, so unrelated
		 * profile updates (WooCommerce account edits, wp-admin user edits, any
		 * wp_update_user() call) never reach the toggle logic.
		 *
		 * @param array       $errors Current validation errors.
		 * @param object|null $form   The submitting form instance.
		 * @return array
		 */
		public function validate_toggle( $errors, $form = null ) {
			if ( ! is_object( $form ) || 'HivePress\Forms\User_Update' !== get_class( $form ) ) {
				return $errors;
			}

			// Browsers do not submit unticked checkboxes at all, and HivePress
			// keeps an absent value as null, so the submitted state cannot be
			// read from the value alone. The field is registered on this form
			// only for vendors and admins, so its presence is the reliable
			// signal that the toggle belongs to this submission; a null value
			// then simply means the box was unticked.
			$fields = $form->get_fields();

			if ( ! isset( $fields[ self::FIELD ] ) ) {
				return $errors;
			}

			$user_id = get_current_user_id();
			if ( ! $user_id || ( ! current_user_can( 'manage_options' ) && ! $this->is_user_vendor( $user_id ) ) ) {
				return $errors;
			}

			// Act only on the user's own settings form, not on an admin
			// updating another user through the same endpoint.
			$model = $form->get_model();

			if ( $model && $model->get_id() && (int) $model->get_id() !== (int) $user_id ) {
				return $errors;
			}

			$submitted = (bool) $form->get_value( self::FIELD );
			$previous  = (bool) get_user_meta( $user_id, self::USER_META_KEY, true );

			if ( $submitted === $previous ) {
				return $errors;
			}

			if ( ! $submitted ) {
				$entitlement = $this->get_entitlement( $user_id );

				if ( ! $entitlement['allowed'] ) {
					// Refuse the switch-off so state stays consistent (flag on,
					// banner on, listings hidden) and tell the vendor why.
					$errors[] = $entitlement['message'];
					return $errors;
				}
			}

			$this->pending_toggle = [
				'user_id' => (int) $user_id,
				'enable'  => $submitted,
			];

			return $errors;
		}

		/**
		 * Applies a validated toggle once the user model has actually saved.
		 *
		 * @param int $user_id The saved user ID.
		 * @return void
		 */
		public function apply_toggle( $user_id ) {
			if ( empty( $this->pending_toggle ) ) {
				return;
			}
			if ( (int) $this->pending_toggle['user_id'] !== (int) $user_id ) {
				return;
			}

			$enable               = (bool) $this->pending_toggle['enable'];
			$this->pending_toggle = null;

			// Re-check the gate here, not just during validation: the form can
			// fail after our validation ran (the current-password check happens
			// later in the controller), which leaves the recorded intent behind
			// for the rest of the request.
			if ( ! $enable ) {
				$entitlement = $this->get_entitlement( $user_id );

				if ( ! $entitlement['allowed'] ) {
					return;
				}
			}

			if ( $enable ) {
				// Draft nothing unless the flag is genuinely stored: if this
				// write is ever lost (it can race the one-time debris cleanup
				// on a legacy row), hiding the listings anyway would leave
				// them invisible while every indicator reads "off".
				if ( ! update_user_meta( $user_id, self::USER_META_KEY, true ) ) {
					return;
				}

				$this->bulk_set_draft( $user_id );
			} else {
				// Delete the flag rather than storing an empty value, so
				// switching off leaves no meta row behind (stale empty rows
				// were observed accumulating on staging, 2026-08-04).
				delete_user_meta( $user_id, self::USER_META_KEY );
				$this->bulk_restore( $user_id );
			}
		}

		/* ---------------- ENFORCE while ON ---------------- */

		/**
		 * Keeps a vendor's listings hidden while holiday mode is on: any listing
		 * that becomes visible (or is scheduled) is pushed back to draft.
		 *
		 * @param int $listing_id The listing post ID.
		 * @return void
		 */
		public function enforce_draft_while_holiday( $listing_id ) {
			if ( $this->suspend_enforce ) {
				return;
			}

			$curr = get_post_status( $listing_id );
			if ( ! in_array( $curr, self::HIDEABLE, true ) ) {
				// Leave draft, trash, auto-draft, inherit, etc. alone.
				return;
			}

			$post = get_post( $listing_id );
			if ( ! $post ) {
				return;
			}

			$user_id = (int) $post->post_author;
			if ( ! $user_id || ! get_user_meta( $user_id, self::USER_META_KEY, true ) ) {
				return;
			}

			update_post_meta( $listing_id, self::LISTING_META_PREV, $curr );

			$this->suspend_enforce = true;
			wp_update_post(
				[
					'ID'          => $listing_id,
					'post_status' => 'draft',
				]
			);
			$this->suspend_enforce = false;
		}

		/* ---------------- Vendor page notice ---------------- */

		/**
		 * Adds the "vendor is away" notice to the vendor profile page while
		 * the vendor has holiday mode switched on.
		 *
		 * @param array  $blocks   Template blocks.
		 * @param object $template Template object, with the vendor context.
		 * @return array
		 */
		public function add_vendor_notice_block( $blocks, $template ) {
			if ( ! class_exists( '\HivePress\Models\Vendor' ) || ! is_object( $template ) ) {
				return $blocks;
			}

			$vendor = $template->get_context( 'vendor' );

			if ( ! $vendor instanceof \HivePress\Models\Vendor ) {
				return $blocks;
			}

			$user_id = (int) $vendor->get_user__id();

			if ( ! $user_id || ! get_user_meta( $user_id, self::USER_META_KEY, true ) ) {
				return $blocks;
			}

			// While holiday mode is on every visible listing is drafted, so
			// the results container can only ever render core's "Nothing
			// found" fallback here. Swap the container for our callback, so
			// the vendor's away message appears in that exact place and
			// style instead of a confusing empty-search message. Merging
			// overwrites the block type while keeping its position.
			return hivepress()->template->merge_blocks(
				$blocks,
				[
					'listings_container' => [
						'type'     => 'callback',
						'callback' => 'holiday_mode_for_hivepress_vendor_notice',
						'params'   => [ $user_id ],
						'return'   => true,
					],
				]
			);
		}

		/* ---------------- Admin columns ---------------- */

		/**
		 * Adds the holiday-mode column to the listings screen in wp-admin.
		 *
		 * @param array $columns Admin columns.
		 * @return array
		 */
		public function add_listing_admin_columns( $columns ) {
			return array_merge(
				array_slice( $columns, 0, 3, true ),
				[
					'holiday_mode_for_hivepress' => esc_html__( 'Holiday mode', 'holiday-mode-for-hivepress' ),
				],
				array_slice( $columns, 3, null, true )
			);
		}

		/**
		 * Renders the holiday-mode column for a listing.
		 *
		 * @param string $column Column name.
		 * @param int    $listing_id Listing ID.
		 */
		public function render_listing_admin_columns( $column, $listing_id ) {
			if ( 'holiday_mode_for_hivepress' !== $column ) {
				return;
			}

			$output = '&mdash;';

			// Only a draft carrying our previous-status meta, whose author still
			// has holiday mode on, is hidden by this plugin; anything else is an
			// ordinary listing.
			$prev = get_post_meta( $listing_id, self::LISTING_META_PREV, true );

			if ( $prev && 'draft' === get_post_status( $listing_id ) ) {
				$user_id = (int) get_post_field( 'post_author', $listing_id );

				if ( $user_id && get_user_meta( $user_id, self::USER_META_KEY, true ) ) {
					$status = get_post_status_object( $prev );

					$output = '<span title="' . esc_attr__( 'This listing is restored automatically when the vendor switches holiday mode off.', 'holiday-mode-for-hivepress' ) . '">';

					/* translators: %s: the status the listing returns to when holiday mode is switched off. */
					$output .= sprintf( esc_html__( 'Hidden (was %s)', 'holiday-mode-for-hivepress' ), esc_html( $status ? $status->label : $prev ) );

					$output .= '</span>';
				}
			}

			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/* ---------------- Vendor detection ---------------- */

		/**
		 * Determines whether a user is a vendor (has a vendor profile or has
		 * authored at least one listing).
		 *
		 * @param int $user_id The user ID.
		 * @return bool
		 */
		private function is_user_vendor( $user_id ) {
			$user_id = (int) $user_id;
			if ( ! $user_id ) {
				return false;
			}

			if ( isset( $this->vendor_cache[ $user_id ] ) ) {
				return $this->vendor_cache[ $user_id ];
			}

			$is_vendor = false;

			// Canonical: a HivePress vendor profile linked to this user.
			if ( function_exists( 'hivepress' ) && class_exists( '\HivePress\Models\Vendor' ) ) {
				try {
					$vendor_id = \HivePress\Models\Vendor::query()->filter(
						[ 'user' => $user_id ]
					)->get_first_id();
					if ( $vendor_id ) {
						$is_vendor = true;
					}
				} catch ( \Throwable $e ) {
					$is_vendor = false;
				}
			}

			// Fallback: the user has authored at least one listing.
			if ( ! $is_vendor ) {
				$has_listings = get_posts(
					[
						'post_type'     => 'hp_listing',
						'post_status'   => [ 'publish', 'pending', 'private', 'future', 'draft' ],
						'author'        => $user_id,
						'fields'        => 'ids',
						'numberposts'   => 1,
						'no_found_rows' => true,
					]
				);
				if ( ! empty( $has_listings ) ) {
					$is_vendor = true;
				}
			}

			/**
			 * Filters whether a user is treated as a vendor for holiday mode.
			 *
			 * @param bool $is_vendor Whether the user is a vendor.
			 * @param int  $user_id   The user ID.
			 */
			$is_vendor = (bool) apply_filters( 'holiday_mode_for_hivepress_is_vendor', $is_vendor, $user_id );

			$this->vendor_cache[ $user_id ] = $is_vendor;

			return $is_vendor;
		}

		/* ---------------- Bulk ops ---------------- */

		/**
		 * Hides all of a vendor's currently visible/scheduled listings, saving
		 * each one's previous status. Listings that were already drafts are left
		 * untouched so they are not published on restore.
		 *
		 * @param int $user_id The vendor user ID.
		 * @return void
		 */
		private function bulk_set_draft( $user_id ) {
			$listing_ids = $this->get_vendor_listings( $user_id, self::HIDEABLE );

			$this->suspend_enforce = true;
			foreach ( $listing_ids as $listing_id ) {
				$curr = get_post_status( $listing_id );
				if ( ! in_array( $curr, self::HIDEABLE, true ) ) {
					continue;
				}
				update_post_meta( $listing_id, self::LISTING_META_PREV, $curr );
				wp_update_post(
					[
						'ID'          => $listing_id,
						'post_status' => 'draft',
					]
				);
			}
			$this->suspend_enforce = false;
		}

		/**
		 * Restores the listings this plugin hid, back to their saved status.
		 * Only listings still in draft that we hid (and had a publishable
		 * previous status) are republished; anything changed in the meantime is
		 * left as-is. The tracking meta is always cleared.
		 *
		 * @param int $user_id The vendor user ID.
		 * @return void
		 */
		private function bulk_restore( $user_id ) {
			$listing_ids = $this->get_vendor_listings( $user_id, 'any', true );

			// Two extensions treat any transition to publish/pending as a brand
			// new submission, so a restore would be billed as one. Stand both
			// listeners down for the loop: hiding and restoring must be neutral
			// in every ledger.
			//
			// Badges counts +1 towards its listings_submitted total, with no
			// decrement anywhere (`badges/includes/components/class-badge.php:357-381`).
			// Paid Listings is worse: it spends one of the vendor's paid
			// submissions, deletes the package when the balance hits zero, and
			// overwrites the listing's expiry with a fresh full period
			// (`paid-listings/includes/components/class-listing-package.php:95-181`).
			$suspended = [];

			if ( function_exists( 'hivepress' ) ) {
				if ( hivepress()->get_version( 'badges' ) && hivepress()->badge ) {
					$suspended[] = [ hivepress()->badge, 'update_listing_status', 4 ];
				}

				if ( hivepress()->get_version( 'paid_listings' ) && hivepress()->listing_package ) {
					$suspended[] = [ hivepress()->listing_package, 'update_user_packages', 3 ];
				}
			}

			foreach ( $suspended as $listener ) {
				remove_action( 'hivepress/v1/models/listing/update_status', [ $listener[0], $listener[1] ], 10 );
			}

			$this->suspend_enforce = true;
			foreach ( $listing_ids as $listing_id ) {
				$prev = get_post_meta( $listing_id, self::LISTING_META_PREV, true );
				$curr = get_post_status( $listing_id );

				if ( 'draft' === $curr && in_array( $prev, self::HIDEABLE, true ) && ! $this->is_listing_expired( $listing_id ) ) {
					wp_update_post(
						[
							'ID'          => $listing_id,
							'post_status' => $prev,
						]
					);
				}

				delete_post_meta( $listing_id, self::LISTING_META_PREV );
			}
			$this->suspend_enforce = false;

			foreach ( $suspended as $listener ) {
				add_action( 'hivepress/v1/models/listing/update_status', [ $listener[0], $listener[1] ], 10, $listener[2] );
			}
		}

		/**
		 * Checks whether a listing's own expiry date has already passed.
		 *
		 * A listing whose paid period ran out while it was hidden must not come
		 * back visible, because holiday mode must never buy a listing extra
		 * time. This mirrors core exactly: its own hide/unhide toggle refuses to
		 * un-hide an expired draft
		 * (`hivepress/includes/controllers/class-listing.php:434-436`), and its
		 * hourly cron would immediately re-draft such a listing anyway
		 * (`components/class-listing.php:290-326`). Leaving it drafted puts it
		 * exactly where the vendor's Renew option expects to find it.
		 *
		 * @param int $listing_id The listing post ID.
		 * @return bool
		 */
		private function is_listing_expired( $listing_id ) {
			$expired_time = get_post_meta( $listing_id, 'hp_expired_time', true );

			return '' !== $expired_time && (int) $expired_time && (int) $expired_time < time();
		}

		/**
		 * Returns a vendor's listing IDs.
		 *
		 * @param int          $user_id   The vendor user ID.
		 * @param string|array $statuses  Post status(es) to query.
		 * @param bool         $only_ours When true, only listings carrying our
		 *                                previous-status meta are returned.
		 * @return int[]
		 */
		private function get_vendor_listings( $user_id, $statuses, $only_ours = false ) {
			$args = [
				'post_type'              => 'hp_listing',
				'post_status'            => $statuses,
				'author'                 => (int) $user_id,
				'fields'                 => 'ids',
				'nopaging'               => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			];

			if ( $only_ours ) {
				// Only listings we hid carry this meta key.
				$args['meta_key'] = self::LISTING_META_PREV; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			}

			$query = new WP_Query( $args );

			return $query->posts;
		}

		/* ---------------- Entitlement ---------------- */

		/**
		 * Decides whether a vendor may switch holiday mode off.
		 *
		 * Only a system that demonstrably governs THIS vendor gets a say. That
		 * matters because no HivePress monetisation system hides listings when
		 * entitlement lapses: Memberships only redirects the submit route and
		 * drafts the membership post itself
		 * (`memberships/includes/components/class-membership.php:872-917`), and
		 * Paid Listings only blocks the submit flow. So a vendor outside every
		 * system, or on a site that merely has one installed, must never be
		 * blocked: their listings would still be visible had they never used
		 * holiday mode at all, and blocking would eventually cost them the
		 * listings entirely once the storage period trashes expired drafts.
		 *
		 * Enrolment, not mere installation, is what confers jurisdiction.
		 *
		 * @param int $user_id The user being evaluated.
		 * @return array `allowed` (bool), `reason` (string) and `message` (string).
		 */
		public function get_entitlement( $user_id ) {
			$user_id = (int) $user_id;

			$entitlement = [
				'allowed' => true,
				'reason'  => 'ungoverned',
				'message' => '',
			];

			// Anyone who can edit others' listings bypasses every gate. This is
			// the capability the whole ecosystem uses for exactly this purpose
			// (`memberships/includes/components/class-membership.php:2190`).
			if ( user_can( $user_id, 'edit_others_posts' ) ) {
				$entitlement['reason'] = 'bypass';

				return $this->filter_entitlement( $entitlement, $user_id );
			}

			foreach ( [ 'memberships', 'subscriptions' ] as $system ) {
				$verdict = call_user_func( [ $this, 'check_' . $system ], $user_id );

				if ( is_null( $verdict ) ) {
					// This system does not govern the vendor: no opinion.
					continue;
				}

				if ( $verdict ) {
					$entitlement['reason'] = $system . '_active';

					return $this->filter_entitlement( $entitlement, $user_id );
				}

				$entitlement['allowed'] = false;
				$entitlement['reason']  = $system . '_lapsed';

				if ( 'memberships' === $system ) {
					$entitlement['message'] = esc_html__( 'Your membership is not active, so holiday mode cannot be switched off yet and your listings stay hidden. Please renew your membership to restore them.', 'holiday-mode-for-hivepress' );
				} else {
					$entitlement['message'] = esc_html__( 'Your subscription is not active, so holiday mode cannot be switched off yet and your listings stay hidden. Please renew your subscription to restore them.', 'holiday-mode-for-hivepress' );
				}

				break;
			}

			return $this->filter_entitlement( $entitlement, $user_id );
		}

		/**
		 * Applies the public entitlement filters and normalises the result.
		 *
		 * @param array $entitlement The computed entitlement.
		 * @param int   $user_id     The user being evaluated.
		 * @return array
		 */
		private function filter_entitlement( $entitlement, $user_id ) {
			/**
			 * Filters whether the user may restore (un-hide) their listings.
			 *
			 * Kept for backwards compatibility: it receives, and can override,
			 * the decision the bundled entitlement checks reached.
			 *
			 * @param bool $has_access Whether the restore is currently allowed.
			 * @param int  $user_id    The user being evaluated.
			 */
			$entitlement['allowed'] = (bool) apply_filters( 'holiday_mode_for_hivepress_has_active_membership', $entitlement['allowed'], $user_id );

			/**
			 * Filters the full entitlement decision behind switching holiday
			 * mode off, so a site can add its own membership system.
			 *
			 * @param array $entitlement `allowed` (bool), `reason` (string) and
			 *                           `message` (string, shown to the vendor
			 *                           when the switch-off is refused).
			 * @param int   $user_id     The user being evaluated.
			 */
			$entitlement = (array) apply_filters( 'holiday_mode_for_hivepress_entitlement', $entitlement, $user_id );

			$entitlement['allowed'] = ! empty( $entitlement['allowed'] );
			$entitlement['reason']  = isset( $entitlement['reason'] ) ? (string) $entitlement['reason'] : '';

			if ( ! $entitlement['allowed'] && empty( $entitlement['message'] ) ) {
				$entitlement['message'] = esc_html__( 'Holiday mode cannot be switched off yet, so your listings stay hidden. Please contact the site owner to restore them.', 'holiday-mode-for-hivepress' );
			}

			return $entitlement;
		}

		/**
		 * HivePress Memberships verdict for a vendor.
		 *
		 * Governs only when listing restrictions are switched on for the site
		 * (`hp_membership_models`, read at
		 * `memberships/includes/components/class-membership.php:46`) and the
		 * vendor holds a membership record. A membership post is `publish`
		 * while active and `draft` once expired (`models/class-membership.php:31-45`).
		 *
		 * @param int $user_id The user being evaluated.
		 * @return bool|null True if entitled, false if lapsed, null if not governed.
		 */
		private function check_memberships( $user_id ) {
			if ( ! function_exists( 'hivepress' ) || ! hivepress()->get_version( 'memberships' ) || ! class_exists( '\HivePress\Models\Membership' ) ) {
				return null;
			}

			if ( ! in_array( 'listing', (array) get_option( 'hp_membership_models', [ 'listing' ] ), true ) ) {
				return null;
			}

			try {
				$active = \HivePress\Models\Membership::query()->filter(
					[
						'status' => 'publish',
						'user'   => $user_id,
					]
				)->get_first_id();

				if ( $active ) {
					return true;
				}

				// Only a vendor who has actually held a membership is governed
				// by one; anyone else got their listings another way.
				$lapsed = \HivePress\Models\Membership::query()->filter(
					[
						'status__in' => [ 'draft', 'pending' ],
						'user'       => $user_id,
					]
				)->get_first_id();

				return $lapsed ? false : null;
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		/**
		 * WooCommerce Subscriptions verdict for a vendor.
		 *
		 * Governs only a vendor who holds a subscription of some kind, so a site
		 * that uses subscriptions for something unrelated (or has none yet)
		 * never traps its vendors.
		 *
		 * @param int $user_id The user being evaluated.
		 * @return bool|null True if entitled, false if lapsed, null if not governed.
		 */
		private function check_subscriptions( $user_id ) {
			if ( ! function_exists( 'wcs_user_has_subscription' ) ) {
				return null;
			}

			if ( wcs_user_has_subscription( $user_id, 0, 'active' ) ) {
				return true;
			}

			return wcs_user_has_subscription( $user_id, 0, 'any' ) ? false : null;
		}

		/* ---------------- Banner ---------------- */

		/**
		 * Resolves the account-settings page URL.
		 *
		 * @return string
		 */
		private function get_account_settings_url() {
			$url = '';

			if ( function_exists( 'hivepress' ) ) {
				try {
					$router = hivepress()->router;

					if ( $router ) {
						$url = (string) $router->get_url( 'user_edit_settings_page' );
					}
				} catch ( \Throwable $e ) {
					$url = '';
				}
			}

			return $url ? $url : home_url( '/account/settings/' );
		}

		/**
		 * Prints the "holiday mode is on" banner on HivePress account pages.
		 *
		 * @return void
		 */
		public function maybe_print_banner() {
			if ( ! is_user_logged_in() ) {
				return;
			}

			$user_id = get_current_user_id();

			// Cheap, autoloaded meta check first; only vendors can ever set it.
			if ( ! get_user_meta( $user_id, self::USER_META_KEY, true ) ) {
				return;
			}
			if ( ! $this->is_user_vendor( $user_id ) ) {
				return;
			}

			$data = [
				'url'     => $this->get_account_settings_url(),
				'title'   => __( 'Holiday mode is ON.', 'holiday-mode-for-hivepress' ),
				/* translators: %s is the linked "Account → Settings" text. */
				'message' => __( 'Your listings are hidden until you turn this off in %s.', 'holiday-mode-for-hivepress' ),
				'link'    => __( 'Account → Settings', 'holiday-mode-for-hivepress' ),
				'dismiss' => __( 'Dismiss', 'holiday-mode-for-hivepress' ),
			];

			$js = '(function(){try{' .
				'if(!document.body.classList.contains("hp-template--user-account-page")){return;}' .
				'if(document.getElementById("holiday-mode-for-hivepress-banner")){return;}' .
				'var d=' . wp_json_encode( $data ) . ';' .
				'var target=document.querySelector(".hp-page__content")||document.querySelector(".hp-page.site-main")||document.getElementById("content")||document.body;' .
				'var banner=document.createElement("div");' .
				'banner.id="holiday-mode-for-hivepress-banner";' .
				'banner.setAttribute("role","status");' .
				'banner.setAttribute("aria-live","polite");' .
				'banner.style.cssText="position:sticky;top:0;z-index:9999;box-sizing:border-box;max-width:100%;background:#fff3cd;color:#664d03;border:1px solid #ffeeba;border-left:0;border-right:0;padding:0.5rem 1rem;margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.05)";' .
				'var strong=document.createElement("strong");' .
				'strong.appendChild(document.createTextNode(d.title));' .
				'var span=document.createElement("span");' .
				'var parts=String(d.message).split("%s");' .
				'span.appendChild(document.createTextNode(parts[0]||""));' .
				'var a=document.createElement("a");' .
				'a.href=d.url;a.style.color="inherit";' .
				'a.appendChild(document.createTextNode(d.link));' .
				'span.appendChild(a);' .
				'span.appendChild(document.createTextNode(parts.length>1?parts[1]:""));' .
				'var btn=document.createElement("button");' .
				'btn.type="button";' .
				'btn.setAttribute("aria-label",d.dismiss);' .
				'btn.style.cssText="margin-left:auto;cursor:pointer;background:transparent;border:0;color:inherit;font-size:120%;line-height:1";' .
				'btn.appendChild(document.createTextNode("×"));' .
				'btn.addEventListener("click",function(){if(banner.parentNode){banner.parentNode.removeChild(banner);}});' .
				'banner.appendChild(strong);banner.appendChild(span);banner.appendChild(btn);' .
				'if(target.firstChild){target.insertBefore(banner,target.firstChild);}else{target.appendChild(banner);}' .
				'}catch(e){}})();';

			if ( function_exists( 'wp_print_inline_script_tag' ) ) {
				wp_print_inline_script_tag( $js );
			} else {
				echo '<script>' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}

endif;

if ( ! function_exists( 'holiday_mode_for_hivepress' ) ) {
	/**
	 * Returns the plugin instance (available after `plugins_loaded`).
	 *
	 * @return Holiday_Mode_For_HivePress|null
	 */
	function holiday_mode_for_hivepress() {
		if ( class_exists( 'Holiday_Mode_For_HivePress' ) ) {
			return Holiday_Mode_For_HivePress::instance();
		}
		return null;
	}
}

if ( ! function_exists( 'holiday_mode_for_hivepress_vendor_notice' ) ) {
	/**
	 * Renders the public "vendor is away" notice for a vendor profile page.
	 *
	 * A named function because HivePress's Callback block only accepts one.
	 * All markup reuses core classes (widget card, `hp-status` pill,
	 * `hp-meta` text) so every theme styles it natively with no CSS of ours.
	 *
	 * @param int $user_id The vendor's user ID.
	 * @return string
	 */
	function holiday_mode_for_hivepress_vendor_notice( $user_id ) {
		/**
		 * Filters the public vendor-away notice. Return an empty value to
		 * remove the notice entirely, or change the `title` (the status
		 * pill) and `message` (the text under it).
		 *
		 * @param array $notice  Notice strings: `title`, `message`.
		 * @param int   $user_id The vendor's user ID.
		 */
		$notice = apply_filters(
			'holiday_mode_for_hivepress_vendor_notice',
			[
				'title'   => __( 'Away on holiday', 'holiday-mode-for-hivepress' ),
				'message' => __( 'This vendor is taking a break at the moment, so they may take longer than usual to reply.', 'holiday-mode-for-hivepress' ),
			],
			$user_id
		);

		if ( empty( $notice ) || ! is_array( $notice ) ) {
			return '';
		}

		// Mirrors core's templates/page/no-results-message.php exactly
		// (`.hp-no-results` with an h2 and a paragraph), so every theme
		// styles the away message precisely like the message it replaces.
		$output = '<div class="hp-no-results holiday-mode-for-hivepress-vendor-notice">';

		if ( ! empty( $notice['title'] ) ) {
			$output .= '<h2>' . esc_html( $notice['title'] ) . '</h2>';
		}

		if ( ! empty( $notice['message'] ) ) {
			$output .= '<p>' . esc_html( $notice['message'] ) . '</p>';
		}

		return $output . '</div>';
	}
}

if ( ! function_exists( 'holiday_mode_for_hivepress_bootstrap' ) ) {
	/**
	 * Boots the plugin once all plugins are loaded, or shows a notice if
	 * HivePress is not active.
	 *
	 * @return void
	 */
	function holiday_mode_for_hivepress_bootstrap() {
		// The updater runs regardless of HivePress, so a site missing its
		// dependency can still receive plugin updates. It is registered on
		// every request (not just admin) so background update checks run by
		// WP-Cron also see our releases; the remote lookup itself is cached.
		if ( class_exists( 'Holiday_Mode_For_HivePress_Updater' ) ) {
			new Holiday_Mode_For_HivePress_Updater( __FILE__, HOLIDAY_MODE_FOR_HIVEPRESS_REPO );
		}

		if ( ! function_exists( 'hivepress' ) ) {
			add_action( 'admin_notices', 'holiday_mode_for_hivepress_missing_hivepress_notice' );
			return;
		}
		holiday_mode_for_hivepress();
	}
}

if ( ! function_exists( 'holiday_mode_for_hivepress_missing_hivepress_notice' ) ) {
	/**
	 * Admin notice shown when HivePress is missing.
	 *
	 * @return void
	 */
	function holiday_mode_for_hivepress_missing_hivepress_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo esc_html__( 'Holiday Mode for HivePress requires HivePress to be installed and active.', 'holiday-mode-for-hivepress' );
		echo '</p></div>';
	}
}

add_action( 'plugins_loaded', 'holiday_mode_for_hivepress_bootstrap' );
