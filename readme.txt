=== Holiday Mode for HivePress ===
Contributors: chrisb
Tags: hivepress, marketplace, vendor, listings, holiday
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.7.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Holiday Mode toggle for HivePress that hides and restores all of a vendor's listings, with an on-site banner while active.

== Description ==

Holiday Mode for HivePress adds a "Holiday mode" checkbox to the HivePress
account settings page (Account → Settings). When a vendor enables it, all of
their visible and scheduled listings are hidden from visitors at once. While
holiday mode is on, any listing that becomes visible is automatically hidden
again, and a banner is shown on the vendor's account pages so they always know
their listings are hidden.

When the vendor turns holiday mode off, their listings are restored to the
exact status they had before (published stays published, scheduled stays
scheduled, and so on). Listings that were already drafts, or that were changed
by the vendor or an administrator during the holiday period, are left untouched.

= What you can set =

Two things are yours to decide, and both arrive set the way the plugin has
always behaved, so updating changes nothing until you change it.

**Who is offered the switch.** Out of the box that is anyone HivePress has
given a vendor profile plus anyone who has written a listing, whatever its
status. You can narrow it to vendor profiles only, or hand it to particular
user roles instead, under HivePress → Settings → Holiday Mode → Who Can Use
Holiday Mode. Administrators always see it, whichever you choose.

**Which listings are hidden.** Published, pending, private and scheduled
listings are all hidden, and all four are ticked to begin with under Hiding
Listings. Untick any you would rather leave visible while a vendor is away.
Narrowing this only changes what a future holiday hides: listings already
hidden always come back to the status they actually had, so nobody is left
stranded out of sight by a change made while they are away.

= Restore gating =

Holiday mode never becomes a way to get something the vendor is not entitled
to, and never punishes a vendor for having used it.

**Each listing keeps its own expiry date.** Hiding a listing does not pause
its expiry, so a listing whose date passed while it was hidden is not
brought back: it stays hidden with that date intact, ready for the vendor's
usual Renew option. Every other listing is restored to exactly the status it
had.

**An unrelated subscription can no longer trap a vendor on holiday.**
Versions before 1.7.5 gated the switch-off on the vendor's WooCommerce
Subscription as well as their HivePress Membership. Because there is no way
to tell a subscription that governs listings from one that pays for
something unrelated, that gate could trap a vendor for good over a lapsed
newsletter subscription, so the subscription check has been removed.

**The HivePress Memberships check is now yours to switch on, and is off
until you do.** Tick "Membership Required to Restore" under HivePress →
Settings → Holiday Mode → Restoring Listings and a vendor whose membership
has lapsed is asked to renew before their listings come back. Leave it
unticked, which is how every site starts and how every site upgrading from
1.7.5 or earlier arrives, and a lapsed membership is simply not consulted.

It was made a choice in 1.7.6 because an imposed gate protected nothing.
HivePress does not re-gate listings that are already published: when a
membership lapses it emails the vendor and closes the membership record,
and the submission limit turns away someone submitting or renewing a
listing, but no part of HivePress hides or re-checks the listings a vendor
already has out. A lapsed member who never touched holiday mode therefore
keeps every listing visible, so refusing to end the holiday for the vendor
next to them took nothing back from anybody: it only left the one who used
the feature worse off.

Whichever way you set it, the gate still only bites where HivePress
Memberships is active AND membership restrictions cover listings, and only
for a vendor who actually holds a membership. Anyone who can edit other
people's listings bypasses it entirely. A site whose own code really does
hide listings on lapse can add its own gate through the
`holiday_mode_for_hivepress_entitlement` filter below.

= Developer filters =

* `holiday_mode_for_hivepress_entitlement` ( array $entitlement, int $user_id )
  The full decision behind switching holiday mode off: `allowed` (bool),
  `reason` (string, `ungoverned`, `memberships_active`, `memberships_lapsed`,
  or `bypass` for anyone who can edit other people's listings) and `message`
  (the text shown to the vendor when a switch-off is refused). Out of the box
  the plugin never passes `allowed` as false: it does so only for a lapsed
  HivePress Membership, on a site restricting listings, where the owner has
  ticked "Membership Required to Restore". Until then `reason` is
  `ungoverned` for every vendor and the membership is not consulted at all.
  Use this filter to wire in any other system that genuinely hides listings
  when it lapses.
* `holiday_mode_for_hivepress_has_active_membership` ( bool $has_access, int $user_id )
  The original, simpler filter, still supported: it receives the same
  decision and can override it.
* `holiday_mode_for_hivepress_is_vendor` ( bool $is_vendor, int $user_id )
  Return true to treat a user as a vendor (who then sees the toggle). It runs
  after the "Who Can Use Holiday Mode" setting has been applied and has the
  final say either way, so a site with its own idea of who counts can still
  override any of the three choices.
* `holiday_mode_for_hivepress_vendor_notice` ( array $notice, int $user_id )
  Change the `title`, `message`, `icon`, `label_color`, `text_color` or
  `icon_color` of the public "away" notice shown on the vendor's profile page,
  or return an empty value to remove it. The values already reflect any
  customisation made under HivePress → Settings → Holiday Mode.

= Automatic updates =

The plugin checks its GitHub repository for new releases and offers updates
through the normal WordPress Plugins screen, just like a wp.org plugin. You can
also trigger a check yourself with the "Check for updates" link on the plugin's
row on the Plugins page. Update checks are cached for 6 hours.

== Requirements ==

* HivePress (required). Works alongside HivePress Memberships, Paid Listings
  and WooCommerce Subscriptions without needing any of them, and does not
  gate restoring on any of them unless you ask it to.

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

Out of the box, vendors (users with a HivePress vendor profile or at least one
listing, whatever its status) and administrators.

You can change that under HivePress → Settings → Holiday Mode → Who Can Use
Holiday Mode. "Vendors only" drops the second half, so somebody whose listing
is still unfinished waits until HivePress has made them a vendor. "Chosen
roles" ignores both tests and goes purely by the roles you tick, which suits a
site where the people who need the switch are not the people HivePress calls
vendors. Administrators keep seeing it either way, and the
`holiday_mode_for_hivepress_is_vendor` filter still has the final say.

= Can I stop holiday mode hiding some kinds of listing? =

Yes. Published, pending, private and scheduled listings are all hidden to
begin with, and each one is a tick box under HivePress → Settings → Holiday
Mode → Hiding Listings. Untick any you would rather leave where it is.

Narrowing the list only affects holidays that start afterwards. A listing that
is already hidden comes back to the status it actually had when it was hidden,
whatever the setting says by then, so changing your mind while vendors are
away never costs anybody a listing. Unticking every box is read as all four,
because a holiday mode that hides nothing would be a switch that does nothing.

= What happens to listings that were already drafts? =

They are left alone. Only listings that were visible or scheduled when holiday
mode was enabled are hidden and later restored, so unfinished or intentionally
unpublished drafts are never published for you.

= A vendor can't turn holiday mode off. Why? =

Out of the box, every vendor always can. A switch-off is refused for one of
two reasons, and neither of them is in force until you put it there.

The first is the "Membership Required to Restore" setting under HivePress →
Settings → Holiday Mode → Restoring Listings. If you have ticked it, and you
run HivePress Memberships with membership restrictions covering listings,
then a vendor holding a lapsed membership is asked to renew before their
listings come back, and the message on the form says so. Untick it and that
refusal stops. Sites upgrading from 1.7.5 or earlier arrive with it unticked,
so if a vendor could not end their holiday before the update and can now,
this is why.

The second is your own code, or another plugin, using either of the
entitlement filters listed above: `holiday_mode_for_hivepress_entitlement`,
or the older `holiday_mode_for_hivepress_has_active_membership`.

Two older gates are gone. Versions before 1.7.5 also refused a switch-off
while a vendor's WooCommerce Subscription was lapsed, which could trap a
vendor for good over a subscription that had nothing to do with their
listings. Versions before 1.7.6 applied the membership gate whether you had
asked for it or not.

Separately from all of this, a listing whose own expiry date passed while it
was hidden stays hidden and keeps that date, ready for the usual Renew
option. That is not a refusal to end the holiday, and it is exactly where
HivePress itself would leave the listing.

= Does it require WooCommerce Subscriptions or Memberships? =

Neither is required, and neither gates anything unless you ask. WooCommerce
Subscriptions is not checked at all. HivePress Memberships is checked only
when all three of these are true: the extension is active, membership
restrictions cover listings, and you have ticked "Membership Required to
Restore" under HivePress → Settings → Holiday Mode. With that ticked, a
vendor holding a lapsed membership is asked to renew before their listings
come back; a vendor who has never held a membership is never refused. With
it unticked, which is the default everywhere, holiday mode switches on and
off freely. Each listing's own expiry date is always respected either way.

= How do I get updates? =

The plugin updates itself from its GitHub releases. When a new version is
published, WordPress shows the usual update notice on the Plugins screen. To
check immediately, use the "Check for updates" link on the plugin's row.

= What happens to my listings if I delete the plugin? =

Every hidden listing is restored to the status it had, and holiday mode is
switched off for everyone, so nothing is ever left stranded out of sight.
Listings whose own expiry date has already passed stay hidden, exactly as
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
when you switch holiday mode off. They stay hidden with their expiry date
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

Yes. While holiday mode is on, your public vendor profile replaces the
"Listings by ..." heading and the listings area with a clear "On holiday"
information notice, letting visitors know you are away rather than gone,
and (where the Messages extension is active) that you may take longer than
usual to reply. It disappears the moment you switch holiday mode off. On
sites with page caching, it can take until the cache next refreshes for the
notice to appear or disappear for logged-out visitors.

Site owners can change the notice's wording, icon and colours under
HivePress → Settings → Holiday Mode, and developers can reword or remove it
entirely with the `holiday_mode_for_hivepress_vendor_notice` filter.

= Can vendors write their own away message? =

Yes, if you allow it. Tick "Let each vendor write their own away message"
under HivePress → Settings → Holiday Mode, and every vendor gets two extra
fields under their Holiday mode switch: a headline and a short explanation.
Their words then replace the site-wide message on their own profile, using
the same icon and colours you chose. A vendor who leaves the fields blank
gets the standard notice, and if you untick the setting later, every profile
goes back to the site-wide message immediately, whatever vendors typed.

= What happens to my products and bookings while I am away? =

If you sell listings with the Marketplace extension, each hidden listing's
product is hidden with it, so nobody can buy while you are away, and both
come back together. Existing bookings and orders are not touched or
cancelled. Your per-listing Statistics page is unavailable while a listing
is hidden and returns when it is restored.

== Changelog ==

= 1.7.7 =
* Fixed - ending a holiday no longer fills the site with false activity pop-ups. With Social Proof
  for HivePress active, restoring a vendor's listings was read as that vendor posting every one of
  them again, so a vendor with twenty listings replaced the whole genuine activity feed with
  twenty "just posted a new listing" pop-ups stamped "just now". Restoring is now as quiet in the
  activity feed as it already was in the badge and package counts.
* Fixed - deleting the plugin now also clears the update check's own leftovers and cancels its
  background update check.

= 1.7.6 =
* Changed - holding a vendor's listings back over a lapsed HivePress Membership is now a setting you
  switch on, "Membership Required to Restore" under HivePress → Settings → Holiday Mode → Restoring
  Listings, and it starts unticked on every site, including every site updating from an earlier
  version. Nothing is switched on to preserve the old behaviour, because there was nothing to
  preserve: HivePress does not re-gate listings that are already published. When a membership lapses
  it emails the vendor and closes the membership record, and the submission limit turns away someone
  submitting or renewing a listing, but nothing hides or re-checks the listings a vendor already has
  out. A lapsed member who never used holiday mode kept every listing visible, so refusing to end
  the holiday for the vendor beside them took nothing back from anyone and only left the one who
  used the feature worse off, with listings eventually cleaned up as old hidden ones. If you
  deliberately want that gate, tick the new box and it behaves exactly as before.
* Added - "Who Can Use Holiday Mode" under HivePress → Settings → Holiday Mode. Choose between
  vendors and anyone with a listing (how it has always worked, and what every site starts and
  upgrades on), vendors only, or roles you tick yourself. Administrators always see the switch
  whichever you pick, and the `holiday_mode_for_hivepress_is_vendor` filter still runs last and
  still has the final word in both directions.
* Added - "Statuses to Hide" under Hiding Listings, so published, pending, private and scheduled
  listings no longer have to be hidden as a set. All four are ticked to begin with. Narrowing the
  list changes only what a future holiday hides: a listing already hidden is always restored to the
  status it actually had, whatever the setting says by the time the vendor comes back, so nobody
  loses a listing to a change made while they were away. Unticking every box is read as all four.
* Changed - the readme and the Plugins-screen description no longer call a hidden or restored
  listing a draft. It is how WordPress stores them, not what they are, and reading "your listings
  are drafts" is alarming in a way the actual behaviour is not.
* No change to how a hidden listing is restored, to each listing's own expiry date being respected,
  to the capability bypass, or to either developer filter.

= 1.7.5 =
* Fixed - a vendor can no longer be trapped on holiday by an unrelated subscription. Switching
  holiday mode off was refused unless the vendor's WooCommerce Subscription was active, but there
  is no way to tell a subscription that governs listings from one that pays for a newsletter, so a
  vendor who once bought any subscription and let it lapse could never end their holiday, and
  their hidden listings were eventually swept away by tools that tidy up old unpublished posts. The
  subscription check cannot be
  narrowed to the right products, so it has been removed. The HivePress Memberships check is
  unchanged: it only applies where you have switched listing restrictions on, and only to a vendor
  who actually holds a membership. Each listing's own expiry date is still respected, and a site
  with its own entitlement rules can still refuse a switch-off through the
  `holiday_mode_for_hivepress_entitlement` filter.
* Fixed - a listing removed by an admin during a vendor's holiday can no longer come back on its
  own. The end-of-holiday restore could not see trashed listings, so such a listing kept its
  "restore me" marker forever, and if an admin later untrashed it for review, the vendor's NEXT
  holiday cycle republished it without anyone deciding that. Trashing a listing now clears the
  marker immediately, and the restore sweep also cleans up markers on listings whose status was
  changed some other way in the meantime.

= 1.7.4 =
* Three new hooks so Notifications for HivePress can confirm holiday mode going on and off, and warn
  about listings that stayed hidden: `holiday_mode_for_hivepress/started`, `/ended` and `/enforced`.
* Listings that expire while a vendor is away are now counted separately from the ones that come
  back. They have always stayed hidden, correctly, because holiday mode must never buy a listing
  extra time, but nothing anywhere said so and vendors returned quietly short of listings.
* No change to which listings are hidden or restored.
* Fixed - "View details" is back on the Plugins screen. WordPress only offers that link for a
  plugin that has told it about itself, and this one stayed quiet whenever there was nothing to
  update to, which is almost always. The details popup, its changelog and the donate link inside
  it were all unreachable from the Plugins screen as a result.
* Fixed - checking for updates no longer holds up an admin page. The check ran while WordPress was
  building the Plugins screen, so on a site with several of these extensions one page load made one
  request to GitHub after another and could sit there for many seconds, once, before behaving
  normally again for hours. The check now runs in the background moments later. Pressing Check for
  updates still asks GitHub straight away, because you are waiting for that answer.

= 1.7.3 =
* Checking for updates no longer reports "Could not reach GitHub" when nothing is wrong. GitHub allows a server only a limited number of anonymous update checks each hour, shared by every plugin on the site and, on shared hosting, by every other site on the same server. Running out is ordinary, but it was reported as though the site could not reach GitHub at all. Update checks now read the release from github.com, which sets no such limit, so the message no longer appears. If the limit is ever reached by some other route, the notice now says so plainly instead of blaming your connection.
* A failed update check no longer hides an update that is genuinely waiting. The last successful answer is kept until a later check succeeds, so a pending update stays on the Plugins screen instead of disappearing for an hour.

= 1.7.2 =
* Fixed: the author shown on the Plugins screen now reads "ChrisB @ HivePress Community", matching every other extension in the range.
* Fixed: the plugin's own link on the Plugins screen now points at its source repository rather than the author profile.
* Added: a "Donate" link on the Plugins screen and in the plugin details popup, for anyone who would like to support the work. It appears nowhere else and gates nothing.
* Changed: the updater class and its file are now prefixed, so they cannot collide with HivePress or a future official extension.

= 1.7.1 =
* Added: a Background Colour setting for each notice. The border shades
  itself to match the chosen background, so one colour is all you set.
  Leave it blank to keep the standard light blue.

= 1.7.0 =
* Changed: both notices now use a clear information-box design, a light blue
  panel with an icon, a bold label and the message text, on the vendor's
  account pages and on their public profile alike.
* Changed: while holiday mode is on, the vendor's public profile no longer
  shows the "Listings by ..." heading above the away notice, so visitors see
  one clear explanation instead of a heading for a list that is not there.
* Added: the profile notice's standard wording now mentions sending a message
  only on sites where the HivePress Messages extension is active.
* Added: a Vendor Banner section and a Profile Notice section under
  HivePress → Settings → Holiday Mode. Each lets you set the label, the
  message, the icon (chosen from HivePress's own icon list) and the label,
  text and icon colours, with a colour picker. Leave any field blank to keep
  the standard design.
* Added: an optional Vendor Messages setting. With it ticked, each vendor
  gets an "Away message headline" and "Away message text" field under their
  Holiday mode switch, and their own words appear on their profile in place
  of the site-wide message, keeping the site's icon and colours. Vendors who
  leave the fields blank get the standard notice, and unticking the setting
  returns every profile to the site-wide message.

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
  while they were hidden. They stay hidden, ready to renew, exactly where
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
  owners can see at a glance which listings are hidden by holiday mode and
  which status each one returns to.

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
  refused with a clear message instead of leaving listings stranded out of
  sight with every on-screen indicator cleared.
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

= 1.7.6 =
Running HivePress Memberships? To keep the old behaviour, tick "Membership
Required to Restore" under HivePress → Settings → Holiday Mode → Restoring
Listings. It now arrives unticked on every site, including yours, because the
gate only ever penalised vendors who used holiday mode. Everyone else:
recommended, and nothing changes until you want it to. Two new settings
choose who is offered the switch and which listings it hides, and both arrive
set exactly as the plugin already behaved.

= 1.7.5 =
Recommended for every site, and important for any site selling WooCommerce
Subscriptions of any kind: a vendor with a lapsed subscription could be
locked into holiday mode permanently. That check has been removed. Membership
based restoring is unchanged, and a listing an admin removed during a holiday
can no longer republish itself on a later holiday cycle.

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
