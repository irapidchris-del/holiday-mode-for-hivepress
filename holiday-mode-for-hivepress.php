<?php
/**
 * Plugin Name:       Holiday Mode for HivePress
 * Plugin URI:        https://github.com/irapidchris-del/holiday-mode-for-hivepress
 * Description:       Holiday Mode toggle that hides and restores all of a vendor's listings, with an on-site banner while active and an away notice on the vendor's public profile. Restoring respects each listing's own expiry date, so a holiday never buys a listing extra visible time.
 * Version:           1.7.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  hivepress
 * Author:            ChrisB @ HivePress Community
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

/**
 * The author's support page.
 *
 * One place, so the Plugins row and the View details popup can never drift apart.
 */
define( 'HPHM_SUPPORT_URL', 'https://ko-fi.com/chrisbathivepresscommunity' );

if ( ! defined( 'HOLIDAY_MODE_FOR_HIVEPRESS_REPO' ) ) {
	define( 'HOLIDAY_MODE_FOR_HIVEPRESS_REPO', 'irapidchris-del/holiday-mode-for-hivepress' );
}

// Keep in step with the Version header above on every release.
if ( ! defined( 'HOLIDAY_MODE_FOR_HIVEPRESS_VERSION' ) ) {
	define( 'HOLIDAY_MODE_FOR_HIVEPRESS_VERSION', '1.7.6' );
}

require_once __DIR__ . '/includes/class-hphm-updater.php';

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
		 * User meta storing a vendor's own away-message headline and text,
		 * used only while the site owner has vendor messages switched on.
		 */
		const USER_META_HEADLINE = '_holiday_mode_for_hivepress_headline';
		const USER_META_MESSAGE  = '_holiday_mode_for_hivepress_message';

		/**
		 * Option gating the vendor-written away message feature.
		 */
		const VENDOR_CUSTOM_OPTION = 'hp_holiday_mode_for_hivepress_vendor_custom';

		/**
		 * Option switching the HivePress Memberships restore gate on. Off by
		 * default since 1.7.6; see get_entitlement() for why.
		 */
		const REQUIRE_MEMBERSHIP_OPTION = 'hp_holiday_mode_for_hivepress_require_membership';

		/**
		 * Option choosing who is offered the holiday mode switch, plus the
		 * companion role list read only when that option is set to 'roles'.
		 * Both are blank out of the box, which means the 1.7.5 behaviour.
		 */
		const AUDIENCE_OPTION       = 'hp_holiday_mode_for_hivepress_audience';
		const AUDIENCE_ROLES_OPTION = 'hp_holiday_mode_for_hivepress_audience_roles';

		/**
		 * Option choosing which listing statuses holiday mode hides. Blank
		 * means every status in self::HIDEABLE, so an upgrading site keeps
		 * exactly the behaviour it had.
		 */
		const HIDEABLE_OPTION = 'hp_holiday_mode_for_hivepress_hideable_statuses';

		/**
		 * Listing post meta storing the status a listing had before it was hidden.
		 */
		const LISTING_META_PREV = '_holiday_mode_for_hivepress_prev_status';

		/**
		 * Form field name for the toggle.
		 */
		const FIELD = 'holiday_mode_for_hivepress';

		/**
		 * Form field names for the vendor's own away message, plus the hidden
		 * marker proving the two text inputs were really in the submitting
		 * page (see extend_settings_form for the trap it closes).
		 */
		const FIELD_HEADLINE       = 'holiday_mode_for_hivepress_headline';
		const FIELD_MESSAGE        = 'holiday_mode_for_hivepress_message';
		const FIELD_CUSTOM_PRESENT = 'holiday_mode_for_hivepress_custom_present';

		/**
		 * Every status that represents a visible/scheduled listing holiday mode
		 * knows how to hide, and therefore every status it may ever have
		 * recorded on a hidden listing. Anything else (draft, trash,
		 * auto-draft, inherit) is left untouched.
		 *
		 * Since 1.7.6 the owner may hide a narrower set than this, so this
		 * constant is NOT the hide list: get_hideable_statuses() is. It stays
		 * the full set on purpose, because it is what the settings screen
		 * offers and what the restore path measures a recorded status against.
		 */
		const HIDEABLE = [ 'publish', 'pending', 'private', 'future' ];

		/**
		 * Statuses a hidden listing must never be restored INTO, whatever its
		 * recorded previous status says. A marker holding one of these could
		 * only come from corrupt data or a third party writing the meta by
		 * hand, and acting on it would either be a no-op or resurrect content
		 * somebody removed.
		 */
		const NOT_RESTORABLE = [ 'draft', 'trash', 'auto-draft', 'inherit' ];

		/**
		 * Default colour for the notice text, labels and icons, and the
		 * fixed background/border of the info box design.
		 */
		const COLOR_DEFAULT = '#31708f';
		const COLOR_BG      = '#d9edf7';
		const COLOR_BORDER  = '#bce8f1';

		/**
		 * Default Font Awesome icon (bare name, as core's icon picker stores).
		 */
		const ICON_DEFAULT = 'info-circle';

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
		 * Vendor away-message text captured during form validation, applied
		 * once the user model actually saves. Same pattern as the toggle, and
		 * for the same reason: unrelated profile updates must never touch it.
		 *
		 * @var array|null
		 */
		private $pending_custom = null;

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

			// A trashed listing leaves holiday mode's custody at once: the
			// previous-status marker goes with it, or a later untrash plus
			// holiday cycle would republish removed content (see
			// clear_marker_on_trash for the full trap). The core hook names
			// the exact transition; the model 'update' hook above also fires
			// on a trash, but only alongside every other save.
			add_action( 'trashed_post', [ $this, 'clear_marker_on_trash' ] );

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

				// Colour picker for the notice colour settings.
				add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );

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
		 * Two customisation sections (one per notice) and the
		 * delete-on-uninstall control. The removal section exists because a
		 * site owner cannot be asked at delete time: the confirmation form in
		 * `wp-admin/plugins.php` is hard-coded with no hook inside it, and
		 * WordPress prints its own "will also delete its data" warning whenever
		 * an uninstall.php exists at all, whatever that file really does. The
		 * section description has to correct that warning.
		 *
		 * Text fields are deliberately NOT given a `default`: HivePress writes
		 * config defaults into the database, and a stored translated string
		 * would then shadow every translation. Blank means "use the built-in
		 * wording", which stays translator-reachable; the placeholder shows
		 * what that wording is. Colours likewise stay blank so the built-in
		 * palette can evolve without walking back stored values.
		 *
		 * The behavioural fields added in 1.7.6 follow the same rule for a
		 * sharper reason: HivePress copies every `default` straight into the
		 * options table on activation
		 * (`hivepress/includes/components/class-admin.php:265`), so a default
		 * here is not a suggestion, it is a decision taken on behalf of every
		 * site that upgrades. Each one is therefore left blank and read as the
		 * pre-1.7.6 behaviour by get_audience() and get_hideable_statuses(),
		 * which is the only way an upgrade can be guaranteed to change nothing.
		 *
		 * @param array $settings Settings configuration.
		 * @return array
		 */
		public function add_settings( $settings ) {
			// Core has a Color field (with hex validation) since 1.7.26; fall
			// back to a plain text field on older cores.
			$color_type = class_exists( '\HivePress\Fields\Color' ) ? 'color' : 'text';

			$color_description = esc_html__( 'Pick a colour or paste a 6-digit hex code such as #31708f. Leave blank to use the standard blue.', 'holiday-mode-for-hivepress' );

			$icon_color_description = esc_html__( 'Pick a colour or paste a 6-digit hex code such as #31708f. Leave blank to match the label colour.', 'holiday-mode-for-hivepress' );

			// A non-empty 'html' list makes HivePress sanitise through
			// wp_kses() instead of sanitize_text_field(), which silently
			// strips every %-plus-two-hex-digits pair ("%20 off" saves as
			// "off"). Output is escaped everywhere, so the allowed tags
			// render as typed text at worst.
			$prose_html = [
				'em'     => [],
				'strong' => [],
			];

			// The icon-colour swatches must show the colour actually in
			// effect when the field is blank, which is the (possibly
			// customised) label colour, not always the standard blue.
			$banner_icon_default = $this->get_color_option( 'banner_label_color', self::COLOR_DEFAULT );
			$notice_icon_default = $this->get_color_option( 'notice_label_color', self::COLOR_DEFAULT );

			$bg_color_description = esc_html__( 'Pick a colour or paste a 6-digit hex code such as #d9edf7. Leave blank to use the standard light blue. The border shades itself to match, and if you choose a dark background, set the label and text colours to something light.', 'holiday-mode-for-hivepress' );

			// The blank key is the current behaviour on purpose. Core's Select
			// field prepends its own '&mdash;' placeholder option whenever the
			// options array has no '' key (`hivepress/includes/fields/class-select.php:171-178`),
			// which would put an em-dash on the screen and, worse, make the
			// default read as "nothing chosen". Naming the blank option keeps
			// the stored value empty (so no 'default' is ever written to the
			// database) while the screen still says what empty means.
			$audience_options = [
				''        => esc_html__( 'Vendors and anyone with a listing (recommended)', 'holiday-mode-for-hivepress' ),
				'vendors' => esc_html__( 'Vendors only', 'holiday-mode-for-hivepress' ),
				'roles'   => esc_html__( 'Chosen roles', 'holiday-mode-for-hivepress' ),
			];

			// The Chosen Roles field is deliberately NOT given `'_parent' =>
			// 'holiday_mode_for_hivepress_audience'`. HivePress's dependent-field
			// support is truthiness-only: it shows the child whenever the parent
			// holds any non-empty value at all
			// (`hivepress/assets/js/common.js:1280-1289`), which is right for the
			// checkbox parents core uses it with and wrong for a three-way
			// select, because "Vendors only" would reveal a role list that is
			// never read. Two plain fields plus a description that says when the
			// second one applies is honest; a box that appears at the wrong
			// moment is not.
			$role_options = [];

			if ( function_exists( 'wp_roles' ) ) {
				foreach ( wp_roles()->get_names() as $role_slug => $role_name ) {
					// A role name can come from any plugin on the site, so it is
					// escaped here rather than trusted the way a core status
					// label could be.
					$role_options[ $role_slug ] = esc_html( translate_user_role( $role_name ) );
				}
			}

			// Core's own labels, so "Scheduled" and "Pending Review" read the
			// same here as on the Listings screen. They are registered in
			// wp-settings.php, long before this filter runs, but the fallback
			// covers a site that has somehow unregistered one.
			$status_options = [];

			foreach ( self::HIDEABLE as $status ) {
				$status_object = get_post_status_object( $status );

				$status_options[ $status ] = esc_html( $status_object && $status_object->label ? $status_object->label : $status );
			}

			$settings[ self::SETTINGS_TAB ] = [
				'title'    => esc_html__( 'Holiday Mode', 'holiday-mode-for-hivepress' ),
				'_order'   => 100,

				'sections' => [
					'holiday_mode_for_hivepress_access'  => [
						'title'       => esc_html__( 'Who Can Use Holiday Mode', 'holiday-mode-for-hivepress' ),
						'description' => esc_html__( 'Administrators always see the switch, whichever choice you make here, so you can try it on your own account.', 'holiday-mode-for-hivepress' ),
						'_order'      => 5,

						'fields'      => [
							'holiday_mode_for_hivepress_audience' => [
								'label'       => esc_html__( 'Offer the Switch To', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'Leave this alone unless you have a reason to narrow it. Vendors and anyone with a listing is how the plugin has always behaved: a user counts if HivePress has given them a vendor profile, or if they have written at least one listing in any status. Vendors only drops that second half, so somebody whose listing is still unfinished does not get the switch until HivePress has made them a vendor. Chosen roles ignores both tests and goes purely by the roles you tick below.', 'holiday-mode-for-hivepress' ),
								'type'        => 'select',
								'options'     => $audience_options,
								'_order'      => 10,
							],

							'holiday_mode_for_hivepress_audience_roles' => [
								'label'       => esc_html__( 'Chosen Roles', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'Read only when the choice above is set to chosen roles, and ignored entirely otherwise. A user holding any one of the ticked roles is offered the switch. Tick nothing and nobody but an administrator is, which is worth knowing before you set the choice above and walk away.', 'holiday-mode-for-hivepress' ),
								'type'        => 'checkboxes',
								'options'     => $role_options,
								'_order'      => 20,
							],
						],
					],

					'holiday_mode_for_hivepress_banner'  => [
						'title'       => esc_html__( 'Vendor Banner', 'holiday-mode-for-hivepress' ),
						'description' => esc_html__( 'This banner appears at the top of the account pages, but only for a vendor whose own holiday mode is switched on. It reminds them that their listings are currently hidden. Leave any field blank to use the standard design.', 'holiday-mode-for-hivepress' ),
						'_order'      => 10,

						'fields'      => [
							'holiday_mode_for_hivepress_banner_label' => [
								'label'       => esc_html__( 'Banner Label', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'The short bold text at the start of the banner. Leave blank to use the standard wording.', 'holiday-mode-for-hivepress' ),
								'placeholder' => __( 'Holiday mode is active.', 'holiday-mode-for-hivepress' ),
								'type'        => 'text',
								'max_length'  => 100,
								'html'        => $prose_html,
								'_order'      => 10,
							],

							'holiday_mode_for_hivepress_banner_message' => [
								'label'       => esc_html__( 'Banner Message', 'holiday-mode-for-hivepress' ),
								/* translators: %s is typed literally by the site owner; it marks where the account settings link goes in the banner. */
								'description' => esc_html__( 'The sentence after the label. Type %s where the link to the account settings page should appear. Leave blank to use the standard wording.', 'holiday-mode-for-hivepress' ),
								/* translators: %s is the linked "Account → Settings" text. */
								'placeholder' => __( 'Your listings are hidden from visitors until you switch it off in %s.', 'holiday-mode-for-hivepress' ),
								'type'        => 'textarea',
								'max_length'  => 500,
								'html'        => $prose_html,
								'_order'      => 20,
							],

							'holiday_mode_for_hivepress_banner_icon' => [
								'label'       => esc_html__( 'Banner Icon', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'The icon shown at the start of the banner. Leave empty to use the information icon.', 'holiday-mode-for-hivepress' ),
								'type'        => 'select',
								'options'     => 'icons',
								'_order'      => 30,
							],

							'holiday_mode_for_hivepress_banner_icon_color' => [
								'label'       => esc_html__( 'Banner Icon Colour', 'holiday-mode-for-hivepress' ),
								'description' => $icon_color_description,
								'type'        => $color_type,
								'attributes'  => [
									'data-default-color' => $banner_icon_default,
								],
								'_order'      => 35,
							],

							'holiday_mode_for_hivepress_banner_label_color' => [
								'label'       => esc_html__( 'Banner Label Colour', 'holiday-mode-for-hivepress' ),
								'description' => $color_description,
								'type'        => $color_type,
								'attributes'  => [
									'data-default-color' => self::COLOR_DEFAULT,
								],
								'_order'      => 40,
							],

							'holiday_mode_for_hivepress_banner_text_color' => [
								'label'       => esc_html__( 'Banner Text Colour', 'holiday-mode-for-hivepress' ),
								'description' => $color_description,
								'type'        => $color_type,
								'attributes'  => [
									'data-default-color' => self::COLOR_DEFAULT,
								],
								'_order'      => 50,
							],

							'holiday_mode_for_hivepress_banner_bg_color' => [
								'label'       => esc_html__( 'Banner Background Colour', 'holiday-mode-for-hivepress' ),
								'description' => $bg_color_description,
								'type'        => $color_type,
								'attributes'  => [
									'data-default-color' => self::COLOR_BG,
								],
								'_order'      => 60,
							],
						],
					],

					'holiday_mode_for_hivepress_notice'  => [
						'title'       => esc_html__( 'Profile Notice', 'holiday-mode-for-hivepress' ),
						'description' => esc_html__( 'This notice appears on a vendor\'s public profile while their holiday mode is switched on. It replaces the listings heading and the listings area, so visitors know the vendor is away rather than gone.', 'holiday-mode-for-hivepress' ),
						'_order'      => 20,

						'fields'      => [
							'holiday_mode_for_hivepress_vendor_custom' => [
								'label'       => esc_html__( 'Vendor Messages', 'holiday-mode-for-hivepress' ),
								'caption'     => esc_html__( 'Let each vendor write their own away message', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'With this ticked, two extra fields appear under the Holiday mode switch in each vendor\'s account settings: a headline and a short explanation. A vendor\'s own words then replace the label and message below on their profile, keeping the same icon and colours. Vendors who leave them blank get the notice configured here.', 'holiday-mode-for-hivepress' ),
								'type'        => 'checkbox',
								'_order'      => 5,
							],

							'holiday_mode_for_hivepress_notice_label' => [
								'label'       => esc_html__( 'Notice Label', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'The short bold heading of the notice. Leave blank to use the standard wording.', 'holiday-mode-for-hivepress' ),
								'placeholder' => __( 'On holiday', 'holiday-mode-for-hivepress' ),
								'type'        => 'text',
								'max_length'  => 100,
								'html'        => $prose_html,
								'_order'      => 10,
							],

							'holiday_mode_for_hivepress_notice_message' => [
								'label'       => esc_html__( 'Notice Message', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'The text under the heading. Leave blank to use the standard wording, which mentions messaging only when the Messages extension is active.', 'holiday-mode-for-hivepress' ),
								'placeholder' => __( 'This user is on holiday at the moment. You can still send them a message, but they may take longer than usual to reply.', 'holiday-mode-for-hivepress' ),
								'type'        => 'textarea',
								'max_length'  => 500,
								'html'        => $prose_html,
								'_order'      => 20,
							],

							'holiday_mode_for_hivepress_notice_icon' => [
								'label'       => esc_html__( 'Notice Icon', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'The icon shown at the start of the notice. Leave empty to use the information icon.', 'holiday-mode-for-hivepress' ),
								'type'        => 'select',
								'options'     => 'icons',
								'_order'      => 30,
							],

							'holiday_mode_for_hivepress_notice_icon_color' => [
								'label'       => esc_html__( 'Notice Icon Colour', 'holiday-mode-for-hivepress' ),
								'description' => $icon_color_description,
								'type'        => $color_type,
								'attributes'  => [
									'data-default-color' => $notice_icon_default,
								],
								'_order'      => 35,
							],

							'holiday_mode_for_hivepress_notice_label_color' => [
								'label'       => esc_html__( 'Notice Label Colour', 'holiday-mode-for-hivepress' ),
								'description' => $color_description,
								'type'        => $color_type,
								'attributes'  => [
									'data-default-color' => self::COLOR_DEFAULT,
								],
								'_order'      => 40,
							],

							'holiday_mode_for_hivepress_notice_text_color' => [
								'label'       => esc_html__( 'Notice Text Colour', 'holiday-mode-for-hivepress' ),
								'description' => $color_description,
								'type'        => $color_type,
								'attributes'  => [
									'data-default-color' => self::COLOR_DEFAULT,
								],
								'_order'      => 50,
							],

							'holiday_mode_for_hivepress_notice_bg_color' => [
								'label'       => esc_html__( 'Notice Background Colour', 'holiday-mode-for-hivepress' ),
								'description' => $bg_color_description,
								'type'        => $color_type,
								'attributes'  => [
									'data-default-color' => self::COLOR_BG,
								],
								'_order'      => 60,
							],
						],
					],

					'holiday_mode_for_hivepress_hiding'  => [
						'title'       => esc_html__( 'Hiding Listings', 'holiday-mode-for-hivepress' ),
						'description' => esc_html__( 'Holiday mode only ever touches listings in the statuses ticked below. Anything else, including a vendor\'s own unfinished listings and anything in the bin, is left exactly where it is.', 'holiday-mode-for-hivepress' ),
						'_order'      => 25,

						'fields'      => [
							'holiday_mode_for_hivepress_hideable_statuses' => [
								'label'       => esc_html__( 'Statuses to Hide', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'Leave all four ticked unless you have a reason not to. Narrowing this changes only what a future holiday hides: listings already hidden still come back to the status they actually had, so nothing a vendor is away on is stranded by a change made here. Unticking every box is read as all four, because a holiday mode that hides nothing would be a switch that does nothing.', 'holiday-mode-for-hivepress' ),
								'type'        => 'checkboxes',
								'options'     => $status_options,
								'_order'      => 10,
							],
						],
					],

					'holiday_mode_for_hivepress_restore' => [
						'title'       => esc_html__( 'Restoring Listings', 'holiday-mode-for-hivepress' ),
						'description' => esc_html__( 'Holiday mode brings a vendor\'s listings back when they switch it off. This section decides whether a lapsed HivePress Membership is allowed to stand in the way.', 'holiday-mode-for-hivepress' ),
						'_order'      => 30,

						'fields'      => [
							'holiday_mode_for_hivepress_require_membership' => [
								'label'       => esc_html__( 'Membership Required to Restore', 'holiday-mode-for-hivepress' ),
								'caption'     => esc_html__( 'Ask a vendor to renew a lapsed membership before their listings come back', 'holiday-mode-for-hivepress' ),
								'description' => esc_html__( 'Leave this unticked unless you deliberately want that gate. With it ticked, a vendor whose HivePress Membership has lapsed cannot switch holiday mode off: they are asked to renew first, and their listings stay hidden until they do. It has any effect at all only where HivePress Memberships is active and membership restrictions cover listings. Bear in mind that HivePress leaves a vendor\'s already published listings visible when their membership lapses, so ticking this asks something of a vendor who used holiday mode that is asked of nobody else.', 'holiday-mode-for-hivepress' ),
								'type'        => 'checkbox',
								'_order'      => 10,
							],
						],
					],

					'holiday_mode_for_hivepress_removal' => [
						'title'       => esc_html__( 'Removing the Plugin', 'holiday-mode-for-hivepress' ),
						'description' => esc_html__( 'Your settings are kept if you delete this plugin, so you can reinstall it and carry on. WordPress shows its own warning on the delete screen saying the data goes too, but that warning is the same for every plugin and does not apply here unless you tick the box below. Switching the plugin off never removes anything, and deleting it always brings hidden listings back first.', 'holiday-mode-for-hivepress' ),
						'_order'      => 100,

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

		/**
		 * Loads the WordPress colour picker on the plugin's settings tab and
		 * attaches it to the four colour fields.
		 *
		 * Core's Color field is a bare input with no picker of its own, so the
		 * picker is ours to add. The inline script guards two traps: Iris seeds
		 * an empty input with #000000 (which would silently save black for
		 * every colour left blank), so anything untouched is blanked again on
		 * init and on submit; and the field's server-side pattern rejects
		 * 3-digit shorthand, so #abc is expanded to #aabbcc before submission.
		 *
		 * @return void
		 */
		public function enqueue_settings_assets() {
			// Screen detection only, no form data is read or written.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of which admin page is rendering.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of which admin page is rendering.
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

			if ( 'hp_settings' !== $page || self::SETTINGS_TAB !== $tab ) {
				return;
			}

			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );

			// The submit guard compares values instead of listening for events:
			// Iris writes palette picks into the input with jQuery .val(),
			// which fires no DOM event (only the widget-level "irischange"),
			// so an event-based "was it touched" flag misses the picker's
			// primary interaction and would wipe a palette-picked colour on
			// save. Comparing against the seeded default is deterministic,
			// and storing '' for a value equal to the shown default is
			// semantically identical anyway: blank renders that same colour.
			$js = 'jQuery(function($){' .
				'$(\'input[name^="hp_holiday_mode_for_hivepress_"][name$="_color"]\').each(function(){' .
					'var input=this,$input=$(input);' .
					'var initial=input.getAttribute("value")||"";' .
					'var def=(input.getAttribute("data-default-color")||"").toLowerCase();' .
					'$input.attr("type","text");' .
					'function expand(){var m=/^#([0-9a-fA-F]{3})$/.exec($input.val());if(m){$input.val("#"+m[1].replace(/([0-9a-fA-F])/g,"$1$1"));}}' .
					'$input.wpColorPicker();' .
					// An empty input must not read as "black is chosen": show the
					// colour actually in effect (the default) in the swatch, and
					// let the submit guard keep the stored value empty unless the
					// admin picks something different.
					'if(""===initial){if(def){$input.wpColorPicker("color",def);}else{$input.val("");}}' .
					'$input.on("change blur",expand);' .
					'$input.on("keydown",function(e){if("Enter"===e.key){expand();}});' .
					'$input.closest("form").on("submit",function(){expand();if(""===initial&&$input.val().toLowerCase()===def){$input.val("");}});' .
				'});' .
			'});';

			wp_add_inline_script( 'wp-color-picker', $js );
		}

		/* ---------------- Notice customisation ---------------- */

		/**
		 * Returns the resolved label, message, icon and colours for one of the
		 * two notices, applying any admin customisation from the settings tab.
		 *
		 * Absent options and stored empty strings both fall back to the
		 * built-in value: every field on the settings tab says "leave blank to
		 * use the standard ...", so a blank must behave as the default rather
		 * than as a deliberate empty.
		 *
		 * @param string $context Either 'banner' (vendor-facing, account pages)
		 *                        or 'notice' (public profile).
		 * @return array `label`, `message`, `icon` (bare Font Awesome name),
		 *               `label_color`, `text_color` and `icon_color` (6-digit hex).
		 */
		public function get_notice_args( $context ) {
			$context = 'banner' === $context ? 'banner' : 'notice';

			if ( 'banner' === $context ) {
				$label = __( 'Holiday mode is active.', 'holiday-mode-for-hivepress' );

				/* translators: %s is the linked "Account → Settings" text. */
				$message = __( 'Your listings are hidden from visitors until you switch it off in %s.', 'holiday-mode-for-hivepress' );
			} else {
				$label   = __( 'On holiday', 'holiday-mode-for-hivepress' );
				$message = __( 'This user is on holiday at the moment, so their listings are hidden until they return.', 'holiday-mode-for-hivepress' );

				// Promise messaging only where the Messages extension provides
				// it; on any other site the sentence would point at a button
				// that does not exist.
				if ( function_exists( 'hivepress' ) && hivepress()->get_version( 'messages' ) ) {
					$message = __( 'This user is on holiday at the moment. You can still send them a message, but they may take longer than usual to reply.', 'holiday-mode-for-hivepress' );
				}
			}

			$label_color = $this->get_color_option( $context . '_label_color', self::COLOR_DEFAULT );
			$bg_color    = $this->get_color_option( $context . '_bg_color', self::COLOR_BG );

			return [
				'label'        => $this->get_text_option( $context . '_label', $label ),
				'message'      => $this->get_text_option( $context . '_message', $message ),
				'icon'         => $this->get_icon_option( $context . '_icon' ),
				'label_color'  => $label_color,
				'text_color'   => $this->get_color_option( $context . '_text_color', self::COLOR_DEFAULT ),

				// A blank icon colour follows the label colour, custom or not,
				// so the icon and label stay a matched pair by default.
				'icon_color'   => $this->get_color_option( $context . '_icon_color', $label_color ),

				'bg_color'     => $bg_color,
				'border_color' => $this->get_border_color( $bg_color ),
			];
		}

		/**
		 * Returns the border colour for a notice background: the standard
		 * pairing for the standard background, otherwise a slightly darker
		 * shade of the chosen colour, so one background setting always
		 * produces a matched box with no second field to keep in step.
		 *
		 * @param string $bg_color Validated 6-digit hex background colour.
		 * @return string
		 */
		public function get_border_color( $bg_color ) {
			if ( 0 === strcasecmp( $bg_color, self::COLOR_BG ) ) {
				return self::COLOR_BORDER;
			}

			$border = '#';

			foreach ( [ 1, 3, 5 ] as $offset ) {
				$border .= sprintf( '%02x', (int) round( hexdec( substr( $bg_color, $offset, 2 ) ) * 0.88 ) );
			}

			return $border;
		}

		/**
		 * Reads a text setting, falling back to the built-in wording when the
		 * option is absent or blank.
		 *
		 * @param string $key      Option key without the plugin prefix.
		 * @param string $fallback Built-in wording.
		 * @return string
		 */
		private function get_text_option( $key, $fallback ) {
			$value = get_option( 'hp_holiday_mode_for_hivepress_' . $key );

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return $fallback;
			}

			return $value;
		}

		/**
		 * Reads a colour setting. The value lands inside a style attribute, so
		 * it is validated here as well as at save time; anything unexpected
		 * falls back to the given colour.
		 *
		 * @param string $key      Option key without the plugin prefix.
		 * @param string $fallback Colour to use when unset or invalid.
		 * @return string
		 */
		private function get_color_option( $key, $fallback ) {
			$value = get_option( 'hp_holiday_mode_for_hivepress_' . $key );

			if ( is_string( $value ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
				return $value;
			}

			return $fallback;
		}

		/**
		 * Reads an icon setting. Core's icon picker stores the bare Font
		 * Awesome name; the value lands inside a class attribute, so only a
		 * plain icon-name shape is accepted.
		 *
		 * @param string $key Option key without the plugin prefix.
		 * @return string
		 */
		private function get_icon_option( $key ) {
			$value = get_option( 'hp_holiday_mode_for_hivepress_' . $key );

			if ( is_string( $value ) && preg_match( '/^[a-z0-9-]+$/', $value ) ) {
				return $value;
			}

			return self::ICON_DEFAULT;
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

			// Mention the restore gate only where one will actually apply. The
			// bundled gate is HivePress Memberships alone, and since 1.7.6
			// only where the site owner has switched it on, so promising a
			// check the plugin does not perform misleads vendors - the same
			// trap the pre-1.3.1 wording fell into (found on staging,
			// 2026-08-04). The old subscription sentence went with the
			// subscription check (see get_entitlement).
			$description = __( 'Turn this on to hide all of your listings until you switch it off.', 'holiday-mode-for-hivepress' );

			if ( $this->membership_gate_enabled() && $this->memberships_govern_listings() ) {
				$description .= ' ' . __( 'Your listings are restored when you switch it off, as long as your membership is active at that time.', 'holiday-mode-for-hivepress' );
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

			// The vendor's own away message, where the site owner allows it.
			// Gated on the option in PHP, not just hidden: an admin switching
			// the feature off must silence stored vendor text everywhere, and
			// the fields must stop being read the moment the box is unticked.
			if ( get_option( self::VENDOR_CUSTOM_OPTION ) ) {
				$notice_defaults = $this->get_notice_args( 'notice' );

				// The 'html' list routes sanitisation through wp_kses() instead
				// of sanitize_text_field(), which silently strips every
				// %-plus-two-hex-digits pair from prose (a pasted URL with
				// %20 loses characters). Output is escaped, so the allowed
				// tags render as typed text at worst.
				$vendor_html = [
					'em'     => [],
					'strong' => [],
				];

				$form['fields'][ self::FIELD_HEADLINE ] = [
					'label'       => __( 'Away message headline', 'holiday-mode-for-hivepress' ),
					'description' => __( 'Shown on your public profile while holiday mode is on. Leave blank to use the site\'s standard message.', 'holiday-mode-for-hivepress' ),
					'placeholder' => $notice_defaults['label'],
					'type'        => 'text',
					'max_length'  => 100,
					'html'        => $vendor_html,
					'default'     => (string) get_user_meta( $user_id, self::USER_META_HEADLINE, true ),
					'_separate'   => true,
					'_order'      => 331,
				];

				$form['fields'][ self::FIELD_MESSAGE ] = [
					'label'       => __( 'Away message text', 'holiday-mode-for-hivepress' ),
					'description' => __( 'The sentence or two under the headline. Leave blank to use the site\'s standard message.', 'holiday-mode-for-hivepress' ),
					'placeholder' => $notice_defaults['message'],
					'type'        => 'textarea',
					'max_length'  => 500,
					'html'        => $vendor_html,
					'default'     => (string) get_user_meta( $user_id, self::USER_META_MESSAGE, true ),
					'_separate'   => true,
					'_order'      => 332,
				];

				// Marker proving the two text inputs above were really in the
				// submitting page. Form::set_values() writes null onto every
				// server-side field whose param is absent from the request
				// (`forms/class-form.php:307-321`), so a save posted from a
				// page rendered while this feature was OFF, or any partial
				// POST, would otherwise read as "both fields cleared" and
				// silently delete the vendor's stored message. A stale page
				// cannot echo the marker back, so its absence means "the
				// fields were not shown" rather than "the vendor cleared
				// them".
				$form['fields'][ self::FIELD_CUSTOM_PRESENT ] = [
					'type'      => 'hidden',
					'default'   => '1',
					'_separate' => true,
					'_order'    => 333,
				];
			}

			return $form;
		}

		/* ---------------- Validate / gate ---------------- */

		/**
		 * Reads the submitted toggle from the settings form, applies any
		 * site-installed entitlement filter for switching off, and records
		 * the intent to apply after the model saves.
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

			// Capture the vendor's own away message BEFORE the unchanged-toggle
			// early return below: the text must save on every settings-form
			// submission, not only when the toggle flips. Read only while the
			// site owner has the feature switched on, and only when the
			// hidden marker proves the text inputs were in the submitting
			// page: a null marker means the page was rendered without them
			// (feature toggled on since, or a partial POST), where a null
			// text value must not be mistaken for a deliberate clearing.
			if ( get_option( self::VENDOR_CUSTOM_OPTION ) && isset( $fields[ self::FIELD_HEADLINE ] ) && null !== $form->get_value( self::FIELD_CUSTOM_PRESENT ) ) {
				$this->pending_custom = [
					'user_id'  => (int) $user_id,
					'headline' => trim( (string) $form->get_value( self::FIELD_HEADLINE ) ),
					'message'  => trim( (string) $form->get_value( self::FIELD_MESSAGE ) ),
				];
			}

			$submitted = (bool) $form->get_value( self::FIELD );
			$previous  = (bool) get_user_meta( $user_id, self::USER_META_KEY, true );

			if ( $submitted === $previous ) {
				return $errors;
			}

			if ( ! $submitted ) {
				// Out of the box nothing here refuses: the bundled Memberships
				// gate is opt-in since 1.7.6, so a switch-off is refused only
				// where the owner ticked "Membership Required to Restore" and
				// the vendor's membership has lapsed, or where a site's own
				// code says so through the entitlement filters. See
				// get_entitlement for why the gate is a choice rather than a
				// rule.
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
			$this->apply_custom_messages( $user_id );

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

				$hidden = $this->bulk_set_draft( $user_id );

				/**
				 * Fires when a vendor switches holiday mode on, once their listings are hidden.
				 *
				 * @hook holiday_mode_for_hivepress/started
				 * @param {int} $user_id The vendor's user ID.
				 * @param {int} $hidden How many listings were hidden.
				 */
				do_action( 'holiday_mode_for_hivepress/started', $user_id, $hidden );
			} else {
				// Delete the flag rather than storing an empty value, so
				// switching off leaves no meta row behind (stale empty rows
				// were observed accumulating on staging, 2026-08-04).
				delete_user_meta( $user_id, self::USER_META_KEY );

				$counts = $this->bulk_restore( $user_id );

				/**
				 * Fires when a vendor switches holiday mode off, once their listings are back.
				 *
				 * The two counts are deliberately separate. Listings that expired while the vendor
				 * was away stay hidden, and burying that inside a welcome-back count is exactly how
				 * it goes unnoticed.
				 *
				 * @hook holiday_mode_for_hivepress/ended
				 * @param {int} $user_id The vendor's user ID.
				 * @param {int} $restored How many listings were made visible again.
				 * @param {int} $expired How many stayed hidden because they had expired.
				 */
				do_action( 'holiday_mode_for_hivepress/ended', $user_id, $counts['restored'], $counts['expired'] );
			}
		}

		/**
		 * Saves the vendor's own away message once the user model has saved.
		 *
		 * @param int $user_id The saved user ID.
		 * @return void
		 */
		private function apply_custom_messages( $user_id ) {
			if ( empty( $this->pending_custom ) || (int) $this->pending_custom['user_id'] !== (int) $user_id ) {
				return;
			}

			$custom               = $this->pending_custom;
			$this->pending_custom = null;

			$meta_map = [
				self::USER_META_HEADLINE => $custom['headline'],
				self::USER_META_MESSAGE  => $custom['message'],
			];

			foreach ( $meta_map as $meta_key => $value ) {
				if ( '' === $value ) {
					// Delete rather than store an empty value, so a cleared
					// field leaves no meta row behind (same as the flag).
					delete_user_meta( $user_id, $meta_key );
				} else {
					// The form values arrive unslashed, and update_user_meta()
					// runs wp_unslash() on what it is given, which would eat
					// any literal backslash a vendor typed. Slash to compensate.
					update_user_meta( $user_id, $meta_key, wp_slash( $value ) );
				}
			}
		}

		/**
		 * Returns a vendor's own away message, but only while the site owner
		 * has vendor messages switched on: stored text must go quiet the
		 * moment the feature is turned off, with no orphaned control.
		 *
		 * @param int $user_id The vendor's user ID.
		 * @return array `headline` and `message`, each possibly empty.
		 */
		public function get_vendor_custom( $user_id ) {
			$custom = [
				'headline' => '',
				'message'  => '',
			];

			if ( ! get_option( self::VENDOR_CUSTOM_OPTION ) ) {
				return $custom;
			}

			$custom['headline'] = trim( (string) get_user_meta( $user_id, self::USER_META_HEADLINE, true ) );
			$custom['message']  = trim( (string) get_user_meta( $user_id, self::USER_META_MESSAGE, true ) );

			return $custom;
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
			if ( ! in_array( $curr, $this->get_hideable_statuses(), true ) ) {
				// Leave draft, trash, auto-draft, inherit, and anything the
				// owner has taken off the hide list, alone.
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

			/**
			 * Fires when a listing is pushed back to hidden because holiday mode is still on.
			 *
			 * This is the one that answers "why will my listing not publish?" before it is asked. A
			 * scheduled listing going live, or a new one submitted mid-holiday, currently disappears
			 * with no explanation anywhere in the interface.
			 *
			 * The bulk pass at holiday start sets `suspend_enforce` and returns above, so switching
			 * holiday mode on never fires this once per listing on top of the start notification.
			 *
			 * @hook holiday_mode_for_hivepress/enforced
			 * @param {int} $listing_id The listing that was hidden again.
			 * @param {int} $user_id The vendor's user ID.
			 */
			do_action( 'holiday_mode_for_hivepress/enforced', $listing_id, $user_id );
		}

		/**
		 * Drops the previous-status marker the moment a listing is trashed.
		 *
		 * A listing trashed mid-holiday - an admin takedown, or the vendor
		 * deleting it themselves - has been removed by someone with the right
		 * to overrule the promise to bring it back, so the marker must not
		 * survive the removal. Left behind, it outlives the trash state:
		 * untrashing parks the post in draft (WordPress restores trashed
		 * posts to draft since 5.6), which is exactly the shape bulk_restore
		 * looks for, so a stale marker would let the vendor's NEXT holiday
		 * cycle republish content somebody deliberately took down - with the
		 * Badges/Paid Listings listeners deliberately stood down for the
		 * transition, no less. The restore sweep clears leftover markers too,
		 * but only when a restore happens to run; this closes the window at
		 * the source.
		 *
		 * @param int $post_id The trashed post ID.
		 * @return void
		 */
		public function clear_marker_on_trash( $post_id ) {
			if ( 'hp_listing' !== get_post_type( $post_id ) ) {
				return;
			}

			delete_post_meta( $post_id, self::LISTING_META_PREV );
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
			// the vendor's away message appears in that exact place instead
			// of a confusing empty-search message. Merging overwrites the
			// block type while keeping its position.
			$blocks = hivepress()->template->merge_blocks(
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

			// Also blank the "Listings by ..." page heading: with no listings
			// on show it would sit above the away notice announcing a list
			// that is not there. A separate merge call because merge_blocks
			// never visits a matched block's children in the same pass, so
			// multi-target maps can silently drop entries. The Callback block
			// needs a named function and __return_empty_string() is exactly
			// that.
			return hivepress()->template->merge_blocks(
				$blocks,
				[
					'page_title' => [
						'type'     => 'callback',
						'callback' => '__return_empty_string',
						'params'   => [],
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
		 * Returns the site owner's answer to "who is offered the switch".
		 *
		 * An unrecognised or empty stored value means 'listings', which is what
		 * the plugin did before 1.7.6. See add_settings() for why the option is
		 * allowed to be empty rather than carrying a `default`.
		 *
		 * @return string One of 'listings', 'vendors' or 'roles'.
		 */
		private function get_audience() {
			// Scalar-checked before the cast, the way every other option reader
			// in this file is. An option row is writable by anything on the
			// site - an importer, WP-CLI, another plugin - and casting an array
			// straight to string raises an "Array to string conversion" warning
			// on PHP 8 every time this runs, which is once per capability check.
			$audience = get_option( self::AUDIENCE_OPTION );
			$audience = is_scalar( $audience ) ? (string) $audience : '';

			return in_array( $audience, [ 'vendors', 'roles' ], true ) ? $audience : 'listings';
		}

		/**
		 * Returns the roles ticked under Chosen Roles, as a clean slug list.
		 *
		 * @return array
		 */
		private function get_audience_roles() {
			$roles = get_option( self::AUDIENCE_ROLES_OPTION );

			if ( ! is_array( $roles ) ) {
				$roles = [];
			}

			return array_values(
				array_filter(
					array_map(
						static function ( $role ) {
							return is_scalar( $role ) ? (string) $role : '';
						},
						$roles
					)
				)
			);
		}

		/**
		 * Whether a user holds any of the roles ticked under Chosen Roles.
		 *
		 * @param int $user_id The user ID.
		 * @return bool
		 */
		private function user_has_chosen_role( $user_id ) {
			$roles = $this->get_audience_roles();

			if ( ! $roles ) {
				return false;
			}

			$user = get_userdata( $user_id );

			if ( ! $user || empty( $user->roles ) ) {
				return false;
			}

			return (bool) array_intersect( $roles, array_map( 'strval', (array) $user->roles ) );
		}

		/**
		 * Determines whether a user may use holiday mode.
		 *
		 * Named for what it meant before 1.7.6, when the only answer was "has a
		 * vendor profile or has authored a listing". That is still the default
		 * and still what the `holiday_mode_for_hivepress_is_vendor` filter is
		 * handed, so the name and the filter both stay put; what changed is
		 * that the site owner can now narrow the question.
		 *
		 * @param int $user_id The user ID.
		 * @return bool
		 */
		private function is_user_vendor( $user_id ) {
			$user_id = (int) $user_id;
			if ( ! $user_id ) {
				return false;
			}

			$audience = $this->get_audience();

			// The cache is keyed by the settings the verdict was reached under,
			// not by user ID alone. A settings save and a later capability check
			// can share one request, and the memoised answer from before the
			// save would otherwise be handed back after it: the owner would move
			// to "Vendors only", reload, and still see the switch offered to a
			// listing author until the next page load, which reads as the
			// setting not working. The roles list is in the key for the same
			// reason, since it can change without the mode changing.
			$cache_key = $audience . '|' . implode( ',', $this->get_audience_roles() ) . '|' . $user_id;

			if ( isset( $this->vendor_cache[ $cache_key ] ) ) {
				return $this->vendor_cache[ $cache_key ];
			}

			$is_vendor = false;

			if ( 'roles' === $audience ) {
				$is_vendor = $this->user_has_chosen_role( $user_id );
			} else {

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

				// Fallback: the user has authored at least one listing. This is
				// the half "Vendors only" switches off.
				if ( ! $is_vendor && 'listings' === $audience ) {
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
			}

			/**
			 * Filters whether a user is treated as a vendor for holiday mode.
			 *
			 * Runs last, after the site owner's Who Can Use Holiday Mode choice
			 * has been applied, so a site with its own idea of who counts still
			 * has the final word in either direction.
			 *
			 * @param bool $is_vendor Whether the user is a vendor.
			 * @param int  $user_id   The user ID.
			 */
			$is_vendor = (bool) apply_filters( 'holiday_mode_for_hivepress_is_vendor', $is_vendor, $user_id );

			$this->vendor_cache[ $cache_key ] = $is_vendor;

			return $is_vendor;
		}

		/* ---------------- Hide list ---------------- */

		/**
		 * Returns the statuses holiday mode hides on this site right now.
		 *
		 * Read by the hiding paths ONLY. bulk_restore() must never consult it;
		 * see the comment there for the listings that would be lost if it did.
		 *
		 * An empty stored value means the full list, which covers both a site
		 * that has never opened the setting and an owner who unticked every
		 * box: holiday mode that hides nothing is a switch that does nothing,
		 * and the field says so.
		 *
		 * @return array
		 */
		private function get_hideable_statuses() {
			$statuses = get_option( self::HIDEABLE_OPTION );

			if ( ! is_array( $statuses ) ) {
				$statuses = [];
			}

			$statuses = array_values( array_intersect( self::HIDEABLE, array_map( 'strval', $statuses ) ) );

			return $statuses ? $statuses : self::HIDEABLE;
		}

		/**
		 * Whether a recorded previous status is one we are willing to put a
		 * hidden listing back into.
		 *
		 * Deliberately not "is this status still on the hide list". The hide
		 * list is the owner's current preference; this is a sanity check on a
		 * value written months ago and read once, so it asks only whether the
		 * status is real and whether restoring into it makes sense.
		 *
		 * @param mixed $status The status recorded when the listing was hidden.
		 * @return bool
		 */
		private function is_restorable_status( $status ) {
			$status = is_scalar( $status ) ? (string) $status : '';

			if ( '' === $status || in_array( $status, self::NOT_RESTORABLE, true ) ) {
				return false;
			}

			return (bool) get_post_status_object( $status );
		}

		/* ---------------- Bulk ops ---------------- */

		/**
		 * Hides all of a vendor's currently visible/scheduled listings, saving
		 * each one's previous status. Listings that were already drafts are left
		 * untouched so they are not published on restore.
		 *
		 * @param int $user_id The vendor user ID.
		 * @return int How many listings were hidden.
		 */
		private function bulk_set_draft( $user_id ) {
			// The hiding path, and only the hiding path, follows the owner's
			// current Statuses to Hide setting.
			$hideable    = $this->get_hideable_statuses();
			$listing_ids = $this->get_vendor_listings( $user_id, $hideable );
			$hidden      = 0;

			$this->suspend_enforce = true;
			foreach ( $listing_ids as $listing_id ) {
				$curr = get_post_status( $listing_id );
				if ( ! in_array( $curr, $hideable, true ) ) {
					continue;
				}
				update_post_meta( $listing_id, self::LISTING_META_PREV, $curr );
				wp_update_post(
					[
						'ID'          => $listing_id,
						'post_status' => 'draft',
					]
				);
				++$hidden;
			}
			$this->suspend_enforce = false;

			return $hidden;
		}

		/**
		 * Restores the listings this plugin hid, back to their saved status.
		 * Only listings still in draft that we hid (and had a publishable
		 * previous status) are republished; anything changed in the meantime is
		 * left as-is. The tracking meta is always cleared.
		 *
		 * @param int $user_id The vendor user ID.
		 * @return array `restored` and `expired` counts.
		 */
		private function bulk_restore( $user_id ) {
			// Every registered status, spelled out: WP_Query's 'any' quietly
			// skips statuses flagged exclude_from_search - 'trash' and
			// 'auto-draft' among them - so a listing an admin trashed
			// mid-holiday was invisible to this sweep and kept its marker.
			// The promise below that the tracking meta is always cleared
			// silently failed, and once an untrash later parked the post in
			// draft (WordPress restores trashed posts to draft since 5.6),
			// the vendor's NEXT holiday cycle republished admin-removed
			// content from the stale marker. Seeing a listing here never
			// republishes it by itself: the criteria in the loop still
			// require its current status to be draft.
			$listing_ids = $this->get_vendor_listings( $user_id, array_values( get_post_stati() ), true );

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

			$restored = 0;
			$expired  = 0;

			$this->suspend_enforce = true;
			foreach ( $listing_ids as $listing_id ) {
				$prev = get_post_meta( $listing_id, self::LISTING_META_PREV, true );
				$curr = get_post_status( $listing_id );

				// Hiding asks the owner's current Statuses to Hide setting;
				// restoring asks the listing. The two must never share one
				// list. Were this test written against the hide list instead,
				// an owner narrowing that setting while vendors were away
				// would strand every listing whose recorded status had just
				// been dropped from it: the restore would skip the listing,
				// the marker would still be deleted at the foot of this loop,
				// and the vendor would come back to listings hidden with
				// nothing left on them saying what they used to be. The only
				// safe thing to restore into is the status the listing
				// actually had, so the test below is a sanity check on that
				// recorded value, never a re-run of today's setting.
				if ( 'draft' === $curr && $this->is_restorable_status( $prev ) ) {
					// A listing whose own paid period ran out while it was hidden stays hidden,
					// because holiday mode must never buy a listing extra time. Counted separately
					// so the caller can say so: the behaviour is correct but completely invisible,
					// and a vendor comes back from holiday quietly short of listings.
					if ( $this->is_listing_expired( $listing_id ) ) {
						++$expired;
					} else {
						wp_update_post(
							[
								'ID'          => $listing_id,
								'post_status' => $prev,
							]
						);

						++$restored;
					}
				}

				delete_post_meta( $listing_id, self::LISTING_META_PREV );
			}
			$this->suspend_enforce = false;

			foreach ( $suspended as $listener ) {
				add_action( 'hivepress/v1/models/listing/update_status', [ $listener[0], $listener[1] ], 10, $listener[2] );
			}

			return [
				'restored' => $restored,
				'expired'  => $expired,
			];
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
		 * Only a system that demonstrably governs THIS vendor gets a say.
		 * Enrolment, not mere installation, is what confers jurisdiction: a
		 * vendor outside every system, or on a site that merely has one
		 * installed, must never be blocked, because their listings would still
		 * be visible had they never used holiday mode at all, and blocking
		 * would eventually cost them the listings entirely once the storage
		 * period trashes expired drafts.
		 *
		 * Since 1.7.5 the WooCommerce Subscriptions check is retired.
		 * `wcs_user_has_subscription()` is product-agnostic, so it could not
		 * tell a subscription that governs listings from a lapsed newsletter
		 * or coffee-box one, and a vendor who had once bought ANY subscription
		 * and cancelled it was trapped on holiday for good. There is no
		 * product-scoping option to consult, the way check_memberships() reads
		 * `hp_membership_models`, so the check could not be scoped - only
		 * retired.
		 *
		 * Since 1.7.6 the HivePress Memberships check is an opt-in setting
		 * (HivePress > Settings > Holiday Mode > Restoring Listings) that
		 * defaults to OFF, on upgrades as well as fresh installs. HivePress
		 * never re-gates listings that are already published: Memberships'
		 * expire_memberships() only emails the vendor and drafts or trashes
		 * the membership post, and the submission limit is a route guard that
		 * redirects someone submitting or renewing. Nothing anywhere hides or
		 * re-checks a vendor's live listings once their entitlement lapses.
		 * Refusing to end a holiday therefore protected no entitlement at all;
		 * it only left a vendor who had used holiday mode worse off than an
		 * identical vendor who never did. The gate stays available for an
		 * owner who deliberately wants it, but it is no longer imposed.
		 *
		 * What else gates a restore: each listing's own expiry date (checked
		 * per listing in bulk_restore, so a holiday never buys visible time),
		 * and the entitlement filters below, for a site whose own code hides
		 * listings on lapse and wants its own gate.
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

			// Opt-in since 1.7.6 (see above): with the box unticked the vendor
			// is ungoverned, exactly as on a site carrying no membership
			// system at all. Only the option is read here; check_memberships()
			// still decides jurisdiction once it is asked.
			if ( $this->membership_gate_enabled() ) {
				$verdict = $this->check_memberships( $user_id );

				if ( ! is_null( $verdict ) ) {
					if ( $verdict ) {
						$entitlement['reason'] = 'memberships_active';
					} else {
						$entitlement['allowed'] = false;
						$entitlement['reason']  = 'memberships_lapsed';
						$entitlement['message'] = esc_html__( 'Your membership is not active, so holiday mode cannot be switched off yet and your listings stay hidden. Please renew your membership to restore them.', 'holiday-mode-for-hivepress' );
					}
				}
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
			 * the decision the bundled membership check reached.
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
		 * Whether the site owner has asked for the Memberships restore gate.
		 *
		 * Off by default, and deliberately never migrated on: an install
		 * coming from 1.7.5 or earlier loses no entitlement by having it
		 * switched off, because the gate protected none (see get_entitlement).
		 *
		 * @return bool
		 */
		private function membership_gate_enabled() {
			return (bool) get_option( self::REQUIRE_MEMBERSHIP_OPTION );
		}

		/**
		 * Whether HivePress Memberships is active AND set to restrict listings.
		 *
		 * Split out of check_memberships() so the settings-form description can
		 * ask the same question without running a membership query for a vendor
		 * who is only looking at the form.
		 *
		 * @return bool
		 */
		private function memberships_govern_listings() {
			if ( ! function_exists( 'hivepress' ) || ! hivepress()->get_version( 'memberships' ) || ! class_exists( '\HivePress\Models\Membership' ) ) {
				return false;
			}

			// Read at `memberships/includes/components/class-membership.php:46`.
			return in_array( 'listing', (array) get_option( 'hp_membership_models', [ 'listing' ] ), true );
		}

		/**
		 * HivePress Memberships verdict for a vendor.
		 *
		 * Governs only when listing restrictions are switched on for the site
		 * and the vendor holds a membership record. A membership post is
		 * `publish` while active and `draft` once expired
		 * (`models/class-membership.php:31-45`).
		 *
		 * @param int $user_id The user being evaluated.
		 * @return bool|null True if entitled, false if lapsed, null if not governed.
		 */
		private function check_memberships( $user_id ) {
			if ( ! $this->memberships_govern_listings() ) {
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

			$args = $this->get_notice_args( 'banner' );

			$data = [
				'url'         => $this->get_account_settings_url(),
				'title'       => $args['label'],
				'message'     => $args['message'],
				'icon'        => $args['icon'],
				'iconColor'   => $args['icon_color'],
				'labelColor'  => $args['label_color'],
				'textColor'   => $args['text_color'],
				'bgColor'     => $args['bg_color'],
				'borderColor' => $args['border_color'],
				'link'        => __( 'Account → Settings', 'holiday-mode-for-hivepress' ),
				'dismiss'     => __( 'Dismiss', 'holiday-mode-for-hivepress' ),
			];

			// The icon name and colours are validated server-side (plain
			// icon-name shape, 6-digit hex) before they are JSON-encoded, so
			// they are safe inside className and cssText below.
			$js = '(function(){try{' .
				'if(!document.body.classList.contains("hp-template--user-account-page")){return;}' .
				'if(document.getElementById("holiday-mode-for-hivepress-banner")){return;}' .
				'var d=' . wp_json_encode( $data ) . ';' .
				'var target=document.querySelector(".hp-page__content")||document.querySelector(".hp-page.site-main")||document.getElementById("content")||document.body;' .
				'var banner=document.createElement("div");' .
				'banner.id="holiday-mode-for-hivepress-banner";' .
				'banner.setAttribute("role","status");' .
				'banner.setAttribute("aria-live","polite");' .
				'banner.style.cssText="position:sticky;top:0.5rem;z-index:9999;box-sizing:border-box;max-width:100%;background:"+d.bgColor+";color:"+d.textColor+";border:1px solid "+d.borderColor+";border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1rem;display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.05)";' .
				'var icon=document.createElement("i");' .
				'icon.className="fas fa-"+d.icon;' .
				'icon.setAttribute("aria-hidden","true");' .
				'icon.style.cssText="color:"+d.iconColor+";font-size:130%;line-height:1";' .
				'var strong=document.createElement("strong");' .
				'strong.style.color=d.labelColor;' .
				'strong.appendChild(document.createTextNode(d.title));' .
				'var span=document.createElement("span");' .
				'var parts=String(d.message).split("%s");' .
				'span.appendChild(document.createTextNode(parts[0]||""));' .
				'if(parts.length>1){' .
				'var a=document.createElement("a");' .
				'a.href=d.url;a.style.color="inherit";' .
				'a.appendChild(document.createTextNode(d.link));' .
				'span.appendChild(a);' .
				'span.appendChild(document.createTextNode(parts[1]||""));' .
				'}' .
				'var btn=document.createElement("button");' .
				'btn.type="button";' .
				'btn.setAttribute("aria-label",d.dismiss);' .
				'btn.style.cssText="margin-left:auto;cursor:pointer;background:transparent;border:0;color:inherit;font-size:120%;line-height:1";' .
				'btn.appendChild(document.createTextNode("×"));' .
				'btn.addEventListener("click",function(){if(banner.parentNode){banner.parentNode.removeChild(banner);}});' .
				'banner.appendChild(icon);banner.appendChild(strong);banner.appendChild(span);banner.appendChild(btn);' .
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
	 * An information box (icon, bold label, message) inline-styled with rem
	 * spacing and percentage font sizes, so it scales with every theme and
	 * ships no stylesheet. Core enqueues Font Awesome 5 solid site-wide, so
	 * the icon font is always available on the front end.
	 *
	 * @param int $user_id The vendor's user ID.
	 * @return string
	 */
	function holiday_mode_for_hivepress_vendor_notice( $user_id ) {
		$plugin = holiday_mode_for_hivepress();

		if ( ! $plugin ) {
			return '';
		}

		$defaults = $plugin->get_notice_args( 'notice' );

		// The vendor's own words, where the site owner has switched vendor
		// messages on. Each field falls back independently, so a vendor who
		// writes only a headline keeps the standard text under it. Colours
		// and the icon always come from the site owner's settings.
		$custom = $plugin->get_vendor_custom( $user_id );

		if ( '' !== $custom['headline'] ) {
			$defaults['label'] = $custom['headline'];
		}

		if ( '' !== $custom['message'] ) {
			$defaults['message'] = $custom['message'];
		}

		/**
		 * Filters the public vendor-away notice. Return an empty value to
		 * remove the notice entirely. Keys: `title` (the bold label),
		 * `message` (the text under it), `icon` (bare Font Awesome name)
		 * and `label_color` / `text_color` / `icon_color` / `bg_color`
		 * (6-digit hex; the border is derived from the background). The
		 * values already reflect any admin customisation from the settings
		 * tab, and the vendor's own away message where the site owner has
		 * enabled vendor messages.
		 *
		 * @param array $notice  Notice arguments.
		 * @param int   $user_id The vendor's user ID.
		 */
		$notice = apply_filters(
			'holiday_mode_for_hivepress_vendor_notice',
			[
				'title'       => $defaults['label'],
				'message'     => $defaults['message'],
				'icon'        => $defaults['icon'],
				'label_color' => $defaults['label_color'],
				'text_color'  => $defaults['text_color'],
				'icon_color'  => $defaults['icon_color'],
				'bg_color'    => $defaults['bg_color'],
			],
			$user_id
		);

		if ( empty( $notice ) || ! is_array( $notice ) ) {
			return '';
		}

		// The filter can return anything, and the icon and colours land in
		// class and style attributes, so validate them again here rather
		// than trusting the save-time checks.
		$icon = isset( $notice['icon'] ) && preg_match( '/^[a-z0-9-]+$/', (string) $notice['icon'] ) ? (string) $notice['icon'] : Holiday_Mode_For_HivePress::ICON_DEFAULT;

		$label_color = isset( $notice['label_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $notice['label_color'] ) ? (string) $notice['label_color'] : Holiday_Mode_For_HivePress::COLOR_DEFAULT;

		$text_color = isset( $notice['text_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $notice['text_color'] ) ? (string) $notice['text_color'] : Holiday_Mode_For_HivePress::COLOR_DEFAULT;

		$icon_color = isset( $notice['icon_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $notice['icon_color'] ) ? (string) $notice['icon_color'] : $label_color;

		$bg_color = isset( $notice['bg_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $notice['bg_color'] ) ? (string) $notice['bg_color'] : Holiday_Mode_For_HivePress::COLOR_BG;

		$border_color = $plugin->get_border_color( $bg_color );

		$output = '<div class="holiday-mode-for-hivepress-vendor-notice" role="status" style="display:flex;align-items:flex-start;gap:0.75rem;box-sizing:border-box;background:' . esc_attr( $bg_color ) . ';border:1px solid ' . esc_attr( $border_color ) . ';border-radius:0.5rem;padding:1rem;margin:0 0 2rem;">';

		$output .= '<i class="fas fa-' . esc_attr( $icon ) . '" aria-hidden="true" style="color:' . esc_attr( $icon_color ) . ';font-size:150%;line-height:1.4;"></i>';

		$output .= '<div>';

		if ( ! empty( $notice['title'] ) ) {
			$output .= '<strong style="color:' . esc_attr( $label_color ) . ';">' . esc_html( $notice['title'] ) . '</strong>';
		}

		if ( ! empty( $notice['message'] ) ) {
			$margin  = empty( $notice['title'] ) ? '0' : '0.25rem';
			$output .= '<p style="color:' . esc_attr( $text_color ) . ';margin:' . $margin . ' 0 0;">' . esc_html( $notice['message'] ) . '</p>';
		}

		return $output . '</div></div>';
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
		if ( class_exists( 'Hphm_Updater' ) ) {
			new Hphm_Updater( __FILE__, HOLIDAY_MODE_FOR_HIVEPRESS_REPO );
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

/**
 * Adds the house "Donate" link to this plugin's row on the Plugins screen.
 *
 * WordPress fires plugin_row_meta for EVERY plugin on the screen, so without the basename
 * test the link would appear on every row on the site. The markup is copied verbatim from
 * the house spec in `releasing.md` rather than composed here: every plugin's row has to look
 * identical and sessions have drifted before. The label is exactly "Donate", matching the
 * wording WordPress itself uses in the details popup, and the icon is a Dashicon rather than
 * Font Awesome because Dashicons is the admin's own font and is always loaded there.
 * WordPress joins row-meta items with " | " itself, so this returns a bare anchor.
 *
 * @param array<string> $meta        Row meta links.
 * @param string        $plugin_file Plugin file the row belongs to.
 * @return array<string>
 */
function hphm_add_row_meta( $meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) === $plugin_file ) {
		$meta[] = '<a href="' . esc_url( HPHM_SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'holiday-mode-for-hivepress' )
			. '</a>';
	}

	return $meta;
}

add_filter( 'plugin_row_meta', 'hphm_add_row_meta', 10, 2 );
