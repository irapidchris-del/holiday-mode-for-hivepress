=== Holiday Mode for HivePress ===
Contributors: chrisb
Tags: hivepress, marketplace, vendor, listings, holiday
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.1
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

Holiday mode never becomes a way to get something the vendor is not entitled
to, and never punishes a vendor for having used it. Two independent checks
run when holiday mode is switched off.

**Each listing keeps its own expiry date.** Hiding a listing does not pause
its expiry, so a listing whose date passed while it was hidden is not
brought back: it stays a draft with that date intact, ready for the vendor's
usual Renew option. Every other listing is restored to exactly the status it
had.

**A vendor is held to the membership they actually hold.** If the vendor
holds a HivePress Membership, or a WooCommerce Subscription, it must be
active for them to switch holiday mode off. If it has lapsed, the change is
refused with an explanation, and their listings stay hidden until it is
renewed. Administrators, and anyone who can edit other people's listings,
always bypass this.

Crucially, only a system the vendor is actually enrolled in gets a say. A
vendor who has never held a membership or subscription is never blocked,
even on a site where those systems are installed for other purposes. That
matches how HivePress itself behaves: no HivePress extension hides a
vendor's existing listings when their membership or package runs out, so
holiday mode must not invent that punishment either.

= Developer filters =

* `holiday_mode_for_hivepress_entitlement` ( array $entitlement, int $user_id )
  The full decision behind switching holiday mode off: `allowed` (bool),
  `reason` (string, e.g. `ungoverned`, `bypass`, `memberships_lapsed`,
  `subscriptions_lapsed`) and `message` (the text shown to the vendor when a
  switch-off is refused). Use this to plug in any other membership system.
* `holiday_mode_for_hivepress_has_active_membership` ( bool $has_access, int $user_id )
  The original, simpler filter, still supported: it receives the decision the
  bundled checks reached and can override it.
* `holiday_mode_for_hivepress_is_vendor` ( bool $is_vendor, int $user_id )
  Return true to treat a user as a vendor (who then sees the toggle).
* `holiday_mode_for_hivepress_vendor_notice` ( array $notice, int $user_id )
  Change the `title` and `message` of the public "away" notice shown on the
  vendor's profile page, or return an empty value to remove it.

= Automatic updates =

The plugin checks its GitHub repository for new releases and offers updates
through the normal WordPress Plugins screen, just like a wp.org plugin. You can
also trigger a check yourself with the "Check for updates" link on the plugin's
row on the Plugins page. Update checks are cached for 6 hours.

== Requirements ==

* HivePress (required).
* HivePress Memberships, Paid Listings or WooCommerce Subscriptions
  (all optional; holiday mode recognises whichever of them a vendor is
  enrolled in).

== Installation ==

1. Upload the `holiday-mode-for-hivepress` folder to `/wp-content/plugins/`, or
   install the plugin ZIP through Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Make sure HivePress is installed and active. Vendors will find the
   "Holiday mode" toggle under Account → Settings.

Once installed, future versions can be updated in place from the Plugins
screen, with no need to download the ZIP again.

== Frequently Asked Questions ==

= Who can see the Holiday mode toggle? =

Vendors (users with a HivePress vendor profile or at least one listing) and
administrators.

= What happens to listings that were already drafts? =

They are left alone. Only listings that were visible or scheduled when holiday
mode was enabled are hidden and later restored, so unfinished or intentionally
unpublished drafts are never published for you.

= A vendor can't turn holiday mode off. Why? =

The membership or subscription they hold is not active, and the message on
screen says which. Once it is renewed they can switch holiday mode off and
their listings are restored. Administrators are always exempt.

This only ever applies to a vendor who actually holds one: a vendor who has
never had a membership or subscription is never blocked, even if those
systems are installed for other purposes.

= Does it require WooCommerce Subscriptions or Memberships? =

No. Both are optional. With neither installed, and for any vendor not
enrolled in them, holiday mode simply switches on and off freely. Each
listing's own expiry date is always respected either way.

= How do I get updates? =

The plugin updates itself from its GitHub releases. When a new version is
published, WordPress shows the usual update notice on the Plugins screen. To
check immediately, use the "Check for updates" link on the plugin's row.

= What happens to my listings if I delete the plugin? =

Every hidden listing is restored to the status it had, and holiday mode is
switched off for everyone, so nothing is ever left stranded out of sight.
Listings whose own expiry date has already passed stay as drafts, exactly as
they would anywhere else.

Your settings are kept, so you can reinstall and carry on. WordPress shows a
warning on the delete screen saying the plugin's data goes too, but that
warning appears for every plugin that has an uninstall file and does not
apply here unless you tick "Delete All Data" under HivePress → Settings →
Holiday Mode first. Switching the plugin off, rather than deleting it,
changes nothing at all.

= Does holiday mode give my listings extra time before they expire? =

No. A listing that has an expiry date keeps exactly that date: holiday mode
never moves it, so the clock keeps running for the whole time you are away.

If the date passes while your listings are hidden, they are not brought back
when you switch holiday mode off. They stay as drafts with their expiry date
untouched, ready for the usual Renew option, which is exactly where
HivePress itself would leave them. Taking a holiday is not a way to get more
days out of a listing.

Site owners charging for listings can rely on this: a vendor cannot use
holiday mode to pause a paid listing period or a subscription term.

One detail worth knowing if you set an expiration period after some listings
were already published: those older listings have no expiry date recorded
yet, and HivePress gives them one the next time they are published, whether
that is by holiday mode restoring them or by any other route. That shortens
their life rather than extending it, and it is HivePress core doing it, not
holiday mode.

= Do buyers know that I am away? =

Yes. While holiday mode is on, your public vendor profile replaces the usual
empty "Nothing found" message with an "Away on holiday" notice in exactly
the same style, letting buyers know you may take longer than usual to
reply. It disappears the moment you switch holiday mode off. On sites with
page caching, it can take until the cache next refreshes for the notice to
appear or disappear for logged-out visitors. Site owners can reword or
remove the notice with the `holiday_mode_for_hivepress_vendor_notice`
filter.

= What happens to my products and bookings while I am away? =

If you sell listings with the Marketplace extension, each hidden listing's
product is hidden with it, so nobody can buy while you are away, and both
come back together. Existing bookings and orders are not touched or
cancelled. Your per-listing Statistics page is unavailable while a listing
is hidden and returns when it is restored.

== Changelog ==

= 1.6.1 =
* Added: a one-time tidy-up after updating that removes the leftover empty
  database rows written by versions before 1.3.2 whenever a vendor switched
  holiday mode off. Vendors currently on holiday are untouched.

= 1.6.0 =
* Added: a settings page (HivePress → Settings → Holiday Mode), reachable
  from the Settings link on the plugin's row, with a "Delete All Data"
  choice.
* Changed: deleting the plugin now KEEPS your settings unless you ask for
  them to be removed. It still always restores hidden listings and switches
  holiday mode off, so nothing is left stranded.
* Fixed: update checks no longer send your site's address and WordPress
  version to GitHub. They now identify only the plugin and its version.
* Changed: the "requires HivePress" notice is now dismissible.

= 1.5.0 =
* Added: listings are no longer brought back if their own expiry date passed
  while they were hidden. They stay drafts, ready to renew, exactly where
  HivePress itself would leave them, so a holiday can never buy a listing
  extra visible time.
* Added: HivePress Memberships support. A vendor holding a membership must
  have an active one to switch holiday mode off, with a message naming the
  membership rather than a subscription.
* Changed: the restore check now applies only to a vendor actually enrolled
  in a membership or subscription. Previously, installing WooCommerce
  Subscriptions for any reason could permanently trap vendors who had never
  held one.
* Fixed: on sites using Paid Listings, restoring listings charged the vendor
  one paid submission per listing, could delete their package outright, and
  reset each listing's expiry to a fresh full period. Restoring is now free
  and leaves expiry dates alone, as it always should have been.
* Fixed: a refused switch-off could still be applied later in the same
  request if the settings form failed for an unrelated reason.
* Changed: the administrator bypass now uses the same capability HivePress
  itself uses, so shop managers and editors with listing permissions are
  covered too.

= 1.4.2 =
* Changed: on the vendor's profile page, the away notice now replaces the
  "Nothing found" empty-listings message entirely, using its exact heading
  and paragraph styling, so buyers see one clear explanation instead of an
  empty-search message.

= 1.4.1 =
* Changed: the vendor profile "Away on holiday" notice now sits in the main
  content area directly below the "Listings by ..." heading rather than in
  the sidebar, at full text size, with clearer spacing below the pill.

= 1.4.0 =
* Added: a public "Away on holiday" notice on the vendor's profile page
  while holiday mode is on, so buyers know the vendor may take longer than
  usual to reply. Styled entirely with HivePress's own classes, so it
  matches every official theme, and removable or rewordable via the
  `holiday_mode_for_hivepress_vendor_notice` filter.

= 1.3.3 =
* Added: answers in the readme confirming that holiday mode never extends a
  listing's expiry date, and covering what happens to products, bookings and
  statistics while you are away. No functional change.

= 1.3.2 =
* Improved: switching holiday mode off now removes the stored flag entirely
  instead of leaving an empty database row behind for each user.

= 1.3.1 =
* Fixed: the checkbox description claimed listings are restored only if a
  "membership or subscription" is active. The bundled restore gate checks a
  WooCommerce Subscription only, so the description now says exactly that,
  and only on sites where WooCommerce Subscriptions is installed. Sites
  without it see no mention of a gate, because none applies.

= 1.3.0 =
* Added: a translation template (languages folder), so every string can be
  reworded or translated with Loco Translate or a similar tool.
* Changed: translations now load through WordPress itself, matching how the
  official HivePress extensions handle them.
* Changed: the banner now follows the HivePress sizing conventions, so its
  spacing and text scale with the active theme.
* Fixed: on sites running the HivePress Badges extension, restoring listings
  no longer counts each restored listing as a newly submitted one, so holiday
  cycles cannot inflate "listings submitted" badge counts.
* Fixed: the settings checkbox is now added only when the settings form
  instance is confirmed, closing a theoretical route for the field to appear
  through third-party code.

= 1.2.2 =
* Changed: the holiday mode checkbox is now registered as a form-only field,
  so it can never clash with an admin-defined user attribute of the same name
  or be saved through the user profile itself.

= 1.2.1 =
* Added: a "Holiday mode" column on the Listings screen in wp-admin, so site
  owners can see at a glance which drafts are listings hidden by holiday mode
  and which status each one returns to.

= 1.2.0 =
* Fixed: switching holiday mode off from the settings form had no effect.
  Browsers do not submit unticked checkboxes, so the plugin never saw the
  change; the setting is now read correctly and listings are restored as
  intended. This also means the subscription check now runs when switching
  off, so its explanatory message appears when a subscription is not active.
* Fixed: listings created directly with a visible status while holiday mode
  is on are now hidden as well.
* Changed: the checkbox now shows a short caption beside the box instead of
  repeating the field label.
* Changed: clearer wording for the checkbox description and the subscription
  message.
* Fixed: the update checker's cached release details are now removed on
  uninstall.
* Tested up to WordPress 7.0.

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
* Improved: internationalisation, with all strings (including the banner) now
  translatable under the plugin's own text domain.
* Improved: banner accessibility and readability; the toggle now sits in a
  sensible position within the settings form.

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 1.5.0 =
Recommended for everyone. Buyers now see an "Away on holiday" notice on the
vendor's profile. Restoring respects each listing's own expiry date and any
membership or subscription the vendor actually holds. On sites using Paid
Listings, restoring no longer spends the vendor's paid submissions.

= 1.2.0 =
Critical fix: switching holiday mode off now works. On 1.1.0 the setting could
be switched on but never off, leaving listings hidden. Upgrade immediately.

= 1.1.0 =
Important reliability fixes: holiday mode is no longer toggled by unrelated
profile updates, and listings are no longer stranded or wrongly published.
Upgrade recommended for all users.
