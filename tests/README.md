# Tests

Logic tests for Holiday Mode for HivePress. They need **PHP only** — no
WordPress install, no database, no Composer, no PHPUnit.

WordPress and HivePress are stubbed in the test files themselves, so the real
plugin code runs against fakes that mirror how core actually behaves.

## Running them

From the plugin folder:

```sh
php tests/run.php
```

Or individually:

```sh
php tests/logic-tests.php
php tests/updater-tests.php
```

Each prints one line per assertion and exits non-zero if anything fails, so it
can be dropped into CI as-is.

## Testing other configurations

The plugin behaves differently depending on which HivePress extensions are
present. The logic tests take environment variables so each combination can be
checked:

```sh
HM_BADGES=1 php tests/logic-tests.php          # HivePress Badges active
HM_MESSAGES=1 php tests/logic-tests.php        # HivePress Messages active
HM_WCS=absent php tests/logic-tests.php        # no WooCommerce Subscriptions
HM_VENDOR=absent php tests/logic-tests.php     # no HivePress Vendor model
HM_MEMBERSHIPS=absent php tests/logic-tests.php # no HivePress Memberships model
```

`tests/run.php` runs the whole matrix in one go.

## What is covered

- **Entitlement** — the opt-in HivePress Memberships gate in both states
  (off by default, so a lapsed member is allowed and `ungoverned`; on, so a
  lapsed member is refused with `memberships_lapsed` and told to renew), that
  the option does nothing where Memberships is absent or does not cover
  listings, the enrolment rule (a vendor who never held a membership is never
  blocked), the capability bypass, both developer filters, and the re-check
  that stops a refused switch-off being applied later in the same request.
- **The toggle** — the settings form only, never an unrelated profile update;
  unticking correctly read as "switch off"; an admin editing another user
  ignored.
- **Hiding and restoring** — statuses restored exactly, pre-existing drafts left
  alone, listings changed during the holiday left alone, and listings whose own
  expiry passed left as drafts.
- **Enforcement** — visible listings re-drafted, trashed listings not
  un-trashed, and the re-entrancy guard.
- **Extension interop** — the Badges and Paid Listings listeners stood down for
  the restore loop, so restoring never bills a vendor.
- **The notices** — the account banner, the public profile notice replacing the
  listings container, the page heading blanked, colour and wording fallbacks,
  and per-vendor away messages.
- **Settings and upgrades** — the settings tab, and the one-time sweep of legacy
  meta rows, including that its `DELETE` is value-matched so active flags
  survive.
- **Who can use holiday mode** — all three choices, that the shipped default is
  the pre-1.7.6 behaviour, that "vendors only" excludes a listing author with no
  vendor profile, that a role choice ignores both other tests, that the memoised
  vendor cache cannot answer for a setting that has since changed within the same
  request, and that the `holiday_mode_for_hivepress_is_vendor` filter still runs
  last and still overrides in both directions.
- **Which listings get hidden** — a narrowed list hiding fewer statuses on both
  the bulk and the mid-holiday path, an empty list reading as all four rather
  than as "hide nothing", junk in the stored list being dropped, and above all
  the stranding case: a listing hidden while the list was wide is still restored
  after the list is narrowed, because restoring reads the status recorded on the
  listing and never the setting. Narrowing a live setting must not cost a vendor
  a listing, and the marker is cleared either way, so nothing would put it back.
- **The updater** — release parsing, asset selection, caching, failure handling,
  and the folder rename that keeps updates landing in the same directory.

## Note on the version drift guard

The plugin version lives in two places: the `Version:` file header and the
`HOLIDAY_MODE_FOR_HIVEPRESS_VERSION` constant. A mismatch would put WordPress
into an update loop, so a test asserts they agree. If that test fails after a
version bump, update whichever one was missed.

## These files are not shipped

`.github/workflows/release.yml` copies named files into the release package, so
this folder never reaches the distributed ZIP.
