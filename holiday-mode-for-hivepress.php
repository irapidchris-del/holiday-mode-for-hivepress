<?php
/**
 * Plugin Name: Holiday Mode for HivePress
 * Description: Vendor-only Holiday Mode toggle to hide (draft) and restore all listings. Shows a banner on Account pages while active. Requires an active WooCommerce Subscription (admins bypass) to restore.
 * Version:     1.0
 * Author:      Chris Bruce
 * Author URI:  https://community.hivepress.io/u/chrisb/summary
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Holiday_Mode_For_HivePress {
	const USER_META_KEY     = '_holiday_mode_for_hivepress';
	const LISTING_META_PREV = '_holiday_mode_for_hivepress_prev_status';

	public function __construct() {
		// Add checkbox on both possible Account → Settings forms.
		add_filter( 'hivepress/v1/forms/vendor_update', [ $this, 'extend_user_settings_form' ], 1000 );
		add_filter( 'hivepress/v1/forms/user_update',   [ $this, 'extend_user_settings_form' ], 1000 );

		// Persist & act when the settings form is saved.
		add_action( 'hivepress/v1/models/user/update',    [ $this, 'handle_toggle_save' ], 1000, 2 );

		// While holiday mode is ON, any listing change is kept in draft.
		add_action( 'hivepress/v1/models/listing/update', [ $this, 'enforce_draft_while_holiday' ], 1000, 2 );

		// Membership rule (admins bypass, else active Woo Subscriptions required).
		add_filter( 'holiday_mode_for_hivepress_has_active_membership', [ $this, 'membership_gate_wcs' ], 10, 2 );

		// Banner: inject on account pages via JS (robust across themes).
		add_action( 'wp_footer', [ $this, 'maybe_print_banner_js' ], 1000 );
	}

	/* ---------------- UI: field ---------------- */
	public function extend_user_settings_form( $form ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) { return $form; }

		// Show to admins (for testing) and to vendors.
		if ( ! current_user_can( 'manage_options' ) && ! $this->is_user_vendor( $user_id ) ) {
			return $form;
		}

		$form['fields']['holiday_mode_for_hivepress'] = [
			'label'       => __( 'Holiday mode (hide all listings)', 'hivepress' ),
			'description' => __( 'Enable to hide all your listings until you turn it off. When disabling, listings are restored only if your membership/subscription is active.', 'hivepress' ),
			'type'        => 'checkbox',
			'default'     => (bool) get_user_meta( $user_id, self::USER_META_KEY, true ),
			'order'       => 3,
		];

		return $form;
	}

	/* ---------------- SAVE: toggle ---------------- */
	public function handle_toggle_save( $user_id, $user_model ) {
		if ( ! current_user_can( 'manage_options' ) && ! $this->is_user_vendor( $user_id ) ) { return; }

		$submitted = isset( $_POST['holiday_mode_for_hivepress'] ) ? (bool) $_POST['holiday_mode_for_hivepress'] : false;
		$previous  = (bool) get_user_meta( $user_id, self::USER_META_KEY, true );

		update_user_meta( $user_id, self::USER_META_KEY, $submitted );

		if ( $submitted === $previous ) { return; }

		if ( $submitted ) {
			$this->bulk_set_draft( $user_id );
			$this->flash_notice( __( 'Holiday mode enabled — your listings are now hidden.', 'hivepress' ) );
		} else {
			// Enforce membership: default FALSE; filter will return TRUE only for admin or active WCS.
			$can_restore = apply_filters( 'holiday_mode_for_hivepress_has_active_membership', false, $user_id );
			$this->bulk_restore( $user_id, $can_restore );
			if ( $can_restore ) {
				$this->flash_notice( __( 'Holiday mode disabled — your listings have been restored.', 'hivepress' ) );
			} else {
				$this->flash_notice( __( 'Holiday mode turned OFF, but your membership/subscription is not active — listings remain hidden.', 'hivepress' ), 'error' );
			}
		}
	}

	/* ---------------- ENFORCE while ON ---------------- */
	public function enforce_draft_while_holiday( $listing_id, $listing_model ) {
		$post = get_post( $listing_id );
		if ( ! $post ) { return; }

		$user_id = (int) $post->post_author;
		if ( ! $user_id ) { return; }

		if ( ! get_user_meta( $user_id, self::USER_META_KEY, true ) ) { return; }

		$curr = get_post_status( $listing_id );
		if ( 'draft' !== $curr ) {
			if ( ! get_post_meta( $listing_id, self::LISTING_META_PREV, true ) ) {
				update_post_meta( $listing_id, self::LISTING_META_PREV, $curr );
			}
			wp_update_post( [ 'ID' => $listing_id, 'post_status' => 'draft' ] );
		}
	}

	/* ---------------- Vendor detection ---------------- */
	private function is_user_vendor( $user_id ) {
		$is_vendor = false;

		// Canonical: HivePress Vendor model
		if ( function_exists( 'hivepress' ) ) {
			try {
				$vendor = hivepress()->model( 'vendor' )->get_by_user_id( $user_id );
				if ( $vendor && ! is_wp_error( $vendor ) ) { $is_vendor = true; }
			} catch ( \Throwable $e ) {}
		}

		// Or: has authored at least one listing
		if ( ! $is_vendor ) {
			$has_listings = get_posts( [
				'post_type'   => 'hp_listing',
				'post_status' => [ 'publish', 'pending', 'private', 'future', 'draft' ],
				'author'      => $user_id,
				'fields'      => 'ids',
				'numberposts' => 1,
				'no_found_rows'=> true,
			] );
			if ( $has_listings ) { $is_vendor = true; }
		}

		return (bool) apply_filters( 'holiday_mode_for_hivepress_is_vendor', $is_vendor, $user_id );
	}

	/* ---------------- Bulk ops ---------------- */
	private function bulk_set_draft( $user_id ) {
		$listings = $this->get_vendor_listings( $user_id, [ 'publish', 'pending', 'private', 'future', 'draft' ] );
		foreach ( $listings as $listing ) {
			$curr = get_post_status( $listing->ID );
			if ( 'draft' === $curr ) {
				if ( ! get_post_meta( $listing->ID, self::LISTING_META_PREV, true ) ) {
					update_post_meta( $listing->ID, self::LISTING_META_PREV, 'draft' );
				}
				continue;
			}
			update_post_meta( $listing->ID, self::LISTING_META_PREV, $curr );
			wp_update_post( [ 'ID' => $listing->ID, 'post_status' => 'draft' ] );
		}
	}

	private function bulk_restore( $user_id, $can_restore ) {
		$listings = $this->get_vendor_listings( $user_id, [ 'draft', 'publish', 'pending', 'private', 'future' ] );
		foreach ( $listings as $listing ) {
			$prev = get_post_meta( $listing->ID, self::LISTING_META_PREV, true );
			if ( ! $prev ) { continue; }
			if ( ! $can_restore ) { continue; }

			$target_status = in_array( $prev, [ 'publish', 'pending', 'private', 'future' ], true ) ? $prev : 'publish';
			wp_update_post( [ 'ID' => $listing->ID, 'post_status' => $target_status ] );
			delete_post_meta( $listing->ID, self::LISTING_META_PREV );
		}
	}

	private function get_vendor_listings( $user_id, $statuses ) {
		$q = new WP_Query( [
			'post_type'      => 'hp_listing',
			'post_status'    => $statuses,
			'author'         => $user_id,
			'fields'         => 'all',
			'nopaging'       => true,
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );
		return $q->posts;
	}

	/* ---------------- Notices ---------------- */
	private function flash_notice( $message, $type = 'success' ) {
		add_action( 'wp_footer', function () use ( $message, $type ) {
			$bg = ( 'error' === $type ) ? '#f8d7da' : '#d1e7dd';
			$bd = ( 'error' === $type ) ? '#f5c2c7' : '#badbcc';
			?>
			<div style="position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;padding:12px 16px;background:<?php echo esc_attr( $bg ); ?>;border:1px solid <?php echo esc_attr( $bd ); ?>;border-radius:8px;box-shadow:0 6px 24px rgba(0,0,0,.08);">
				<?php echo wp_kses_post( $message ); ?>
			</div>
			<?php
		} );
	}

	/* ---------------- Membership (Admin bypass + Woo Subscriptions) ---------------- */
	public function membership_gate_wcs( $has_access, $user_id ) {
		// Admins always allowed.
		$user = get_user_by( 'id', $user_id );
		if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
			return true;
		}

		// WooCommerce Subscriptions: any active subscription qualifies.
		if ( function_exists( 'wcs_user_has_subscription' ) ) {
			if ( wcs_user_has_subscription( $user_id, '', 'active' ) ) {
				return true;
			}
		}

		// Otherwise, respect the upstream value (we passed FALSE by default).
		return (bool) $has_access;
	}

	/* ---------------- Banner helpers ---------------- */
	private function get_account_settings_url() {
		// Use HivePress router if available, else fall back.
		if ( function_exists( 'hivepress' ) && isset( hivepress()->router ) ) {
			try {
				$url = hivepress()->router->get_url( 'user_update' ); // usually /account/settings/
				if ( $url ) { return $url; }
			} catch ( \Throwable $e ) {}
		}
		return site_url( '/account/settings/' );
	}

	public function maybe_print_banner_js() {
		if ( ! is_user_logged_in() ) { return; }

		$user_id = get_current_user_id();
		if ( ! $this->is_user_vendor( $user_id ) ) { return; }

		$holiday_on = (bool) get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! $holiday_on ) { return; }

		$settings_url = esc_url( $this->get_account_settings_url() );
		?>
		<script>
		(function(){
			try {
				// Only on HivePress account templates.
				if ( ! document.body.classList.contains('hp-template--user-account-page') ) { return; }
				if ( document.getElementById('holiday-mode-for-hivepress-banner') ) { return; }

				var target =
					  document.querySelector('.hp-page__content')
				   || document.querySelector('.hp-page.site-main')
				   || document.getElementById('content')
				   || document.body;

				var banner = document.createElement('div');
				banner.id = 'holiday-mode-for-hivepress-banner';
				banner.setAttribute('role','status');
				banner.setAttribute('aria-live','polite');
				banner.style.cssText = 'position:sticky;top:0;z-index:9999;background:#fff3cd;border:1px solid #ffeeba;border-left:0;border-right:0;padding:10px 16px;margin-bottom:12px;display:flex;gap:10px;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.05)';

				var strong = document.createElement('strong');
				strong.style.marginRight = '4px';
				strong.appendChild(document.createTextNode('Holiday mode is ON.'));

				var span = document.createElement('span');
				span.innerHTML = 'Your listings are hidden until you turn this off in <a href="<?php echo $settings_url; ?>">Account → Settings</a>.';

				var btn = document.createElement('button');
				btn.type = 'button';
				btn.setAttribute('aria-label','Dismiss');
				btn.style.cssText = 'margin-left:auto;cursor:pointer;background:transparent;border:0;font-size:18px;line-height:1;';
				btn.appendChild(document.createTextNode('×'));
				btn.addEventListener('click', function(){ banner.style.display = 'none'; });

				banner.appendChild(strong);
				banner.appendChild(span);
				banner.appendChild(btn);

				if ( target.firstChild ) {
					target.insertBefore(banner, target.firstChild);
				} else {
					target.appendChild(banner);
				}

				// For sticky headers: avoid content being hidden when jumping to anchors.
				var style = document.createElement('style');
				style.textContent = 'html{scroll-padding-top:48px}';
				document.head.appendChild(style);
			} catch(e) {}
		})();
		</script>
		<?php
	}
}

new Holiday_Mode_For_HivePress();
