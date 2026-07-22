<?php
/**
 * Plugin Name:       Holiday Mode for HivePress
 * Plugin URI:        https://community.hivepress.io/u/chrisb/summary
 * Description:       Vendor-only Holiday Mode toggle that hides (drafts) and restores all of a vendor's listings, with an on-site banner while active. Restoring listings requires an active WooCommerce Subscription (admins bypass; sites without WooCommerce Subscriptions are not gated).
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  hivepress
 * Author:            Chris Bruce
 * Author URI:        https://community.hivepress.io/u/chrisb/summary
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       holiday-mode-for-hivepress
 * Domain Path:       /languages
 *
 * @package Holiday_Mode_For_HivePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Holiday_Mode_For_HivePress' ) ) :

	/**
	 * Adds a vendor-facing "Holiday mode" toggle to the HivePress account
	 * settings form that hides and restores all of the vendor's listings.
	 */
	final class Holiday_Mode_For_HivePress {

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
			add_action( 'hivepress/v1/models/user/update', [ $this, 'apply_toggle' ], 1000, 2 );

			// While holiday mode is on, keep any newly visible listing hidden.
			add_action( 'hivepress/v1/models/listing/update', [ $this, 'enforce_draft_while_holiday' ], 1000, 2 );

			// Membership rule: admins bypass, else an active Woo Subscription is
			// required to restore. Sites without Woo Subscriptions are not gated.
			add_filter( 'holiday_mode_for_hivepress_has_active_membership', [ $this, 'membership_gate_wcs' ], 10, 2 );

			// Banner on account pages while holiday mode is active.
			add_action( 'wp_footer', [ $this, 'maybe_print_banner' ], 1000 );

			// Translations for self-distributed copies.
			add_action( 'init', [ $this, 'load_textdomain' ] );
		}

		/**
		 * Loads the plugin text domain.
		 *
		 * @return void
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'holiday-mode-for-hivepress', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
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
			if ( is_object( $form_object ) && 'HivePress\Forms\User_Update' !== get_class( $form_object ) ) {
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

			$form['fields'][ self::FIELD ] = [
				'label'       => __( 'Holiday mode (hide all listings)', 'holiday-mode-for-hivepress' ),
				'description' => __( 'Enable to hide all of your listings until you turn it off. When disabling, your listings are restored only if your membership/subscription is active.', 'holiday-mode-for-hivepress' ),
				'type'        => 'checkbox',
				'default'     => (bool) get_user_meta( $user_id, self::USER_META_KEY, true ),
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

			// null => the field is not part of this form; false/true => submitted value.
			$value = $form->get_value( self::FIELD );
			if ( null === $value ) {
				return $errors;
			}

			$user_id = get_current_user_id();
			if ( ! $user_id || ( ! current_user_can( 'manage_options' ) && ! $this->is_user_vendor( $user_id ) ) ) {
				return $errors;
			}

			$submitted = (bool) $value;
			$previous  = (bool) get_user_meta( $user_id, self::USER_META_KEY, true );

			if ( $submitted === $previous ) {
				return $errors;
			}

			if ( ! $submitted ) {
				/**
				 * Filters whether the user may restore (un-hide) their listings.
				 *
				 * @param bool $has_access Default false; the bundled handler
				 *                          grants access to admins, to users with
				 *                          an active WooCommerce Subscription, and
				 *                          on sites without Woo Subscriptions.
				 * @param int  $user_id    The user being evaluated.
				 */
				$can_restore = (bool) apply_filters( 'holiday_mode_for_hivepress_has_active_membership', false, $user_id );

				if ( ! $can_restore ) {
					// Refuse the switch-off so state stays consistent (flag on,
					// banner on, listings hidden) and tell the vendor why.
					$errors[] = esc_html__( 'Your subscription is not active, so holiday mode cannot be switched off yet — your listings would remain hidden. Please renew your subscription to restore them.', 'holiday-mode-for-hivepress' );
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
		 * @param int   $user_id The saved user ID.
		 * @param mixed $model   Unused (HivePress passes the model name).
		 * @return void
		 */
		public function apply_toggle( $user_id, $model = null ) {
			if ( empty( $this->pending_toggle ) ) {
				return;
			}
			if ( (int) $this->pending_toggle['user_id'] !== (int) $user_id ) {
				return;
			}

			$enable               = (bool) $this->pending_toggle['enable'];
			$this->pending_toggle = null;

			update_user_meta( $user_id, self::USER_META_KEY, $enable );

			if ( $enable ) {
				$this->bulk_set_draft( $user_id );
			} else {
				$this->bulk_restore( $user_id );
			}
		}

		/* ---------------- ENFORCE while ON ---------------- */

		/**
		 * Keeps a vendor's listings hidden while holiday mode is on: any listing
		 * that becomes visible (or is scheduled) is pushed back to draft.
		 *
		 * @param int   $listing_id The listing post ID.
		 * @param mixed $listing    Unused.
		 * @return void
		 */
		public function enforce_draft_while_holiday( $listing_id, $listing = null ) {
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

			$this->suspend_enforce = true;
			foreach ( $listing_ids as $listing_id ) {
				$prev = get_post_meta( $listing_id, self::LISTING_META_PREV, true );
				$curr = get_post_status( $listing_id );

				if ( 'draft' === $curr && in_array( $prev, self::HIDEABLE, true ) ) {
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

		/* ---------------- Membership (Admin bypass + Woo Subscriptions) ---------------- */

		/**
		 * Default restore-access rule: admins bypass; an active WooCommerce
		 * Subscription qualifies; sites without Woo Subscriptions are not gated
		 * (so vendors are never permanently trapped by a system that isn't
		 * present). Site owners can override via the filter.
		 *
		 * @param bool $has_access Incoming value.
		 * @param int  $user_id    The user being evaluated.
		 * @return bool
		 */
		public function membership_gate_wcs( $has_access, $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
				return true;
			}

			// No subscription system installed: don't gate restores at all.
			if ( ! function_exists( 'wcs_user_has_subscription' ) ) {
				return true;
			}

			if ( wcs_user_has_subscription( $user_id, '', 'active' ) ) {
				return true;
			}

			return (bool) $has_access;
		}

		/* ---------------- Banner ---------------- */

		/**
		 * Resolves the account-settings page URL.
		 *
		 * @return string
		 */
		private function get_account_settings_url() {
			if ( function_exists( 'hivepress' ) ) {
				try {
					$router = hivepress()->router;
					if ( $router ) {
						$url = $router->get_url( 'user_edit_settings_page' );
						if ( $url ) {
							return $url;
						}
					}
				} catch ( \Throwable $e ) {
					// Fall through to the default below.
				}
			}
			return home_url( '/account/settings/' );
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
				'banner.style.cssText="position:sticky;top:0;z-index:9999;background:#fff3cd;color:#664d03;border:1px solid #ffeeba;border-left:0;border-right:0;padding:10px 16px;margin-bottom:12px;display:flex;gap:10px;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.05)";' .
				'var strong=document.createElement("strong");' .
				'strong.style.marginRight="4px";' .
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
				'btn.style.cssText="margin-left:auto;cursor:pointer;background:transparent;border:0;color:inherit;font-size:18px;line-height:1";' .
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

if ( ! function_exists( 'holiday_mode_for_hivepress_bootstrap' ) ) {
	/**
	 * Boots the plugin once all plugins are loaded, or shows a notice if
	 * HivePress is not active.
	 *
	 * @return void
	 */
	function holiday_mode_for_hivepress_bootstrap() {
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
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Holiday Mode for HivePress requires HivePress to be installed and active.', 'holiday-mode-for-hivepress' );
		echo '</p></div>';
	}
}

add_action( 'plugins_loaded', 'holiday_mode_for_hivepress_bootstrap' );
