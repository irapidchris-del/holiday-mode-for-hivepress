=== Holiday Mode for HivePress ===
Contributors: chrisb
Tags: hivepress, marketplace, vendor, listings, holiday
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A vendor-only Holiday Mode toggle for HivePress that hides and restores all of a vendor's listings, with an on-site banner while active.

== Description ==

Holiday Mode for HivePress adds a "Holiday mode" checkbox to the HivePress
account settings page (Account → Settings). When a vendor enables it, all of
their visible and scheduled listings are hidden (set to draft) at once. While
holiday mode is on, any listing that becomes visible is automatically pushed
back to draft, and a banner is shown on the vendor's account pages so they
always know their listings are hidden.

When the vendor turns holiday mode off, their listings are restored to the
exact status they had before (published stays published, scheduled stays
scheduled, and so on). Listings that were already drafts, or that were changed
by the vendor or an administrator during the holiday period, are left untouched.

= Restore gating =

Restoring listings can be gated behind a membership requirement:

* Administrators can always restore.
* By default, a user with an **active WooCommerce Subscription** can restore.
* On sites where WooCommerce Subscriptions is **not** installed, restoring is
  **not** gated — vendors are never trapped by a system that isn't present.

If a vendor without an active subscription tries to switch holiday mode off,
the change is refused and an explanatory message is shown; their listings stay
hidden and holiday mode stays on until the subscription is active again.

= Developer filters =

* `holiday_mode_for_hivepress_has_active_membership` ( bool $has_access, int $user_id )
  Return true to allow a user to restore their listings. Use this to integrate
  a different membership system (e.g. HivePress Memberships, WooCommerce
  Memberships).
* `holiday_mode_for_hivepress_is_vendor` ( bool $is_vendor, int $user_id )
  Return true to treat a user as a vendor (who then sees the toggle).

= Automatic updates =

The plugin checks its GitHub repository for new releases and offers updates
through the normal WordPress Plugins screen, just like a wp.org plugin. You can
also trigger a check yourself with the "Check for updates" link on the plugin's
row on the Plugins page. Update checks are cached for 12 hours.

== Requirements ==

* HivePress (required).
* WooCommerce Subscriptions (optional; only needed for the default restore gate).

== Installation ==

1. Upload the `holiday-mode-for-hivepress` folder to `/wp-content/plugins/`, or
   install the plugin ZIP through Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Make sure HivePress is installed and active. Vendors will find the
   "Holiday mode" toggle under Account → Settings.

Once installed, future versions can be updated in place from the Plugins screen
— no need to download the ZIP again.

== Frequently Asked Questions ==

= Who can see the Holiday mode toggle? =

Vendors (users with a HivePress vendor profile or at least one listing) and
administrators.

= What happens to listings that were already drafts? =

They are left alone. Only listings that were visible or scheduled when holiday
mode was enabled are hidden and later restored, so unfinished or intentionally
unpublished drafts are never published for you.

= A vendor can't turn holiday mode off. Why? =

Their WooCommerce Subscription is not active. Restoring listings is gated behind
an active subscription (administrators are exempt). Once the subscription is
active, they can switch holiday mode off and their listings are restored. On
sites without WooCommerce Subscriptions, there is no such restriction.

= How do I get updates? =

The plugin updates itself from its GitHub releases. When a new version is
published, WordPress shows the usual update notice on the Plugins screen. To
check immediately, use the "Check for updates" link on the plugin's row.

= What happens to my listings if I delete the plugin? =

On uninstall, any listings still hidden by the plugin are restored to their
previous status and all of the plugin's data is removed, so nothing is left
stranded in draft.

== Changelog ==

= 1.1.0 =
* Added: automatic updates direct from the plugin's GitHub releases, plus a
  "Check for updates" link on the plugin's row on the Plugins screen.
* Fixed: unrelated profile updates (WooCommerce account edits, wp-admin user
  edits, or any `wp_update_user()` call) no longer silently toggle holiday mode
  off or restore/hide listings. The toggle now runs only from the account
  settings form.
* Fixed: switching holiday mode off without an active subscription is now
  refused with a clear message instead of leaving listings stranded in draft
  with every on-screen indicator cleared.
* Fixed: listings that were already drafts are no longer force-published on
  restore.
* Fixed: trashing a listing while holiday mode is on no longer immediately
  un-trashes it; enforcement now only affects visible/scheduled listings.
* Fixed: vendor detection now uses the correct HivePress query API.
* Fixed: the account-settings link in the banner now uses the correct HivePress
  route and a reliable fallback.
* Added: activation notice when HivePress is not active; the plugin no longer
  does work when its dependency is missing.
* Added: `uninstall.php` that restores hidden listings and cleans up plugin data.
* Improved: sites without WooCommerce Subscriptions are no longer gated, so
  vendors cannot be permanently locked out of restoring their listings.
* Improved: internationalization — all strings (including the banner) are now
  translatable under the plugin's own text domain.
* Improved: banner accessibility and readability; the toggle now sits in a
  sensible position within the settings form.

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Important reliability fixes: holiday mode is no longer toggled by unrelated
profile updates, and listings are no longer stranded or wrongly published.
Upgrade recommended for all users.
