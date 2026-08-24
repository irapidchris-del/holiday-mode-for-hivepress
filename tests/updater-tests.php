<?php
/**
 * Logic tests for the GitHub updater (native update_plugins_github.com flow).
 * Kept out of the release package by package.ps1, and out of PHPCS by phpcs.xml.
 *
 * WHAT THIS SUITE ASSERTS, AND WHY IT IS SHAPED THIS WAY
 *
 * The updater has been through two changes that a test written against the
 * original synchronous flow cannot survive, and both are load-bearing:
 *
 *  - the zero-quota fix moved the lookup to github.com, which sets no anonymous
 *    rate limit, keeping api.github.com as a fallback only. Sixty requests an
 *    hour are shared by every plugin on the site and by every other site on the
 *    same address, and spending them turned a refusal into "could not reach
 *    GitHub";
 *  - the 2026-08-20 non-blocking fix moved the fetch out of the page render. A
 *    site with nine of these extensions measured 18.6 seconds on one admin
 *    screen. On a cold cache without $force, get_latest_release() now queues a
 *    background refresh and returns null having made no HTTP call at all. Only
 *    the manual "Check for updates" link still fetches inline, because there a
 *    person is waiting for the answer.
 *
 * So this suite never asserts "check_for_update() made N HTTP calls", which was
 * the old shape and is now a statement about the bug rather than about the fix.
 * It primes the cache the way the scheduler does, through refresh_release(),
 * and then asserts that check_for_update() reads it. The two behaviours that
 * replaced the old ones - that an unforced check is silent, and that github.com
 * is asked before the API - get sections of their own, [H] and [I], because
 * they are the whole point of the two changes and nothing was covering them.
 *
 * The HTTP stub routes by URL rather than answering every request with one
 * canned response. The github.com route is three requests (the latest-release
 * redirect, the expanded_assets fragment, releases.atom) and a single canned
 * answer cannot tell that route from the API's one, which is precisely the
 * distinction [I] exists to defend.
 *
 * Convention: new_updater() resets every stub global, so fixtures are always
 * set up AFTER it, never before.
 *
 * This is the only copy of this harness. No other extension in the tree carries
 * tests/updater-tests.php, so a change here is not a fleet-wide job.
 *
 * @package Holiday_Mode_For_HivePress
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

// The details popup carries the donate link. Defined here because the popup is
// now actually built by [D]: under the old suite get_plugin_information() always
// returned early on a cold cache, so this constant was never reached and the
// section was asserting against `false`.
define( 'HPHM_SUPPORT_URL', 'https://ko-fi.com/chrisbathivepresscommunity' );

$GLOBALS['_sitetransients'] = [];
$GLOBALS['_transient_ttl']  = [];
$GLOBALS['_routes']         = [];
$GLOBALS['_http']           = null;
$GLOBALS['_urls']           = [];
$GLOBALS['_args']           = [];
$GLOBALS['_http_calls']     = 0;
$GLOBALS['_scheduled']      = [];
$GLOBALS['_caps']           = [ 'update_plugins' => true ];

const BASENAME = 'holiday-mode-for-hivepress/holiday-mode-for-hivepress.php';
const REPO     = 'irapidchris-del/holiday-mode-for-hivepress';
const CACHE_K  = 'holiday_mode_for_hivepress_release';
const REASON_K = 'holiday_mode_for_hivepress_release_reason';

function plugin_basename( $f ) { return BASENAME; }
function add_filter( $t, $cb, $p = 10, $a = 1 ) { return true; }
function add_action( $t, $cb, $p = 10, $a = 1 ) { return true; }

// WP-Cron, used by schedule_release_refresh() since the update check was made
// non-blocking (2026-08-20): the lookup no longer runs inside a page render, it
// queues a single event and returns. Recorded so a test can assert the queueing
// happened exactly once rather than on every call.
function wp_next_scheduled( $hook, $args = [] ) {
	return in_array( $hook, $GLOBALS['_scheduled'] ?? [], true ) ? time() : false;
}
function wp_schedule_single_event( $ts, $hook, $args = [] ) {
	$GLOBALS['_scheduled'][] = $hook;
	return true;
}
function wp_clear_scheduled_hook( $hook, $args = [] ) {
	$GLOBALS['_scheduled'] = array_values( array_diff( $GLOBALS['_scheduled'] ?? [], [ $hook ] ) );
	return 1;
}
function __( $t, $d = null ) { return $t; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $u ) { return $u; }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function wpautop( $t ) { return '<p>' . $t . '</p>'; }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function current_user_can( $c ) { return ! empty( $GLOBALS['_caps'][ $c ] ); }
function self_admin_url( $p ) { return 'http://example.test/wp-admin/' . $p; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=abc123'; }
function trailingslashit( $p ) { return rtrim( $p, '/\\' ) . '/'; }
function untrailingslashit( $p ) { return rtrim( $p, '/\\' ); }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $k ) ); }
function wp_unslash( $v ) { return $v; }

function get_file_data( $file, $fields ) {
	$out = [];
	foreach ( $fields as $k => $label ) {
		$map = [
			'Version' => $GLOBALS['_installed_version'] ?? '1.1.0',
			'Plugin Name' => 'Holiday Mode for HivePress',
			'Description' => 'Vendor holiday mode.',
			'Author' => 'Chris Bruce',
			'Author URI' => 'https://example.test/author',
			'Requires at least' => '6.0',
			'Requires PHP' => '7.4',
		];
		$out[ $k ] = $map[ $label ] ?? '';
	}
	return $out;
}

function get_site_transient( $k ) { return $GLOBALS['_sitetransients'][ $k ] ?? false; }
function set_site_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['_sitetransients'][ $k ] = $v;
	$GLOBALS['_transient_ttl'][ $k ]  = $ttl;
	return true;
}

// Reached only once a fetch actually runs, which is why the old suite never
// needed it: with the non-blocking change nothing in sections A to D got as far
// as a successful lookup, so the missing stub never surfaced.
function delete_site_transient( $k ) {
	unset( $GLOBALS['_sitetransients'][ $k ], $GLOBALS['_transient_ttl'][ $k ] );
	return true;
}

class WP_Error { public $code; public $msg; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/*
 * Routed HTTP stub. Routes match in insertion order, first match wins, so a
 * test that wants api.github.com to behave differently from github.com
 * registers the API route first. Every URL and argument list is recorded, which
 * is what lets [I] assert that the API allowance was never spent.
 */
function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['_http_calls']++;
	$GLOBALS['_urls'][] = $url;
	$GLOBALS['_args'][] = $args;

	foreach ( $GLOBALS['_routes'] as $needle => $response ) {
		if ( false !== strpos( $url, $needle ) ) {
			return $response;
		}
	}

	return $GLOBALS['_http'];
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $r, $k ) { return is_array( $r ) ? ( $r['headers'][ strtolower( $k ) ] ?? '' ) : ''; }

require dirname( __DIR__ ) . '/includes/class-hphm-updater.php';

$pass = 0; $fail = 0;
function ok( $c, $l ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

/* ---------------- fixtures ---------------- */

function http_ok( $b ) { return [ 'response' => [ 'code' => 200 ], 'body' => $b, 'headers' => [] ]; }
function http_code( $code, $body = '', $headers = [] ) {
	return [ 'response' => [ 'code' => $code ], 'body' => $body, 'headers' => $headers ];
}
function http_redirect( $location ) {
	return [ 'response' => [ 'code' => 302 ], 'body' => '', 'headers' => [ 'location' => $location ] ];
}

function asset( $name, $url ) { return [ 'name' => $name, 'browser_download_url' => $url ]; }

/** The API's own answer shape, used for the fallback branch. */
function release_json( $tag, $assets = [] ) {
	return json_encode( [
		'tag_name' => $tag, 'html_url' => 'https://github.com/o/r/releases/tag/' . $tag,
		'published_at' => '2026-07-27T10:00:00Z', 'body' => '* Fixed things',
		'assets' => $assets, 'zipball_url' => 'https://api.github.com/zipball/x',
	] );
}

/** The fragment the release page uses to list its own downloads. */
function assets_html( $tag, $names ) {
	$html = '<div>';
	foreach ( $names as $name ) {
		$html .= '<a href="/' . REPO . '/releases/download/' . $tag . '/' . $name . '">' . $name . '</a>';
	}
	return $html . '</div>';
}

/** releases.atom, whose <content> is escaped HTML exactly as GitHub serves it. */
function atom_feed( $tag, $notes = '<ul><li>Fixed things</li></ul>', $updated = '2026-07-27T10:00:00Z' ) {
	return '<?xml version="1.0"?><feed><entry>'
		. '<link href="https://github.com/' . REPO . '/releases/tag/' . $tag . '"/>'
		. '<updated>' . $updated . '</updated>'
		. '<content type="html">' . htmlspecialchars( $notes, ENT_QUOTES ) . '</content>'
		. '</entry></feed>';
}

/**
 * Wires up a complete, healthy github.com route: the latest-release redirect,
 * the asset fragment and the notes feed. This is the path production takes.
 */
function route_site_release( $tag = 'v1.2.0', $names = [ 'holiday-mode-for-hivepress.zip' ], $notes = '<ul><li>Fixed things</li></ul>' ) {
	$GLOBALS['_routes'] = [
		'/releases/expanded_assets/' => http_ok( assets_html( $tag, $names ) ),
		'/releases.atom'             => http_ok( atom_feed( $tag, $notes ) ),
		'/releases/latest'           => http_redirect( 'https://github.com/' . REPO . '/releases/tag/' . $tag ),
	];
}

/** Every stub global is reset here, so fixtures are always set up AFTER this. */
function new_updater( $installed = '1.1.0' ) {
	$GLOBALS['_sitetransients']    = [];
	$GLOBALS['_transient_ttl']     = [];
	$GLOBALS['_routes']            = [];
	$GLOBALS['_http']              = null;
	$GLOBALS['_urls']              = [];
	$GLOBALS['_args']              = [];
	$GLOBALS['_http_calls']        = 0;
	$GLOBALS['_scheduled']         = [];
	$GLOBALS['_installed_version'] = $installed;

	return new Hphm_Updater( '/plugins/holiday-mode-for-hivepress/holiday-mode-for-hivepress.php', REPO );
}

function urls_touching( $needle ) {
	return array_values(
		array_filter(
			$GLOBALS['_urls'],
			function ( $u ) use ( $needle ) {
				return false !== strpos( $u, $needle );
			}
		)
	);
}

echo "=== Updater tests: native update_plugins_github.com flow ===\n";

// Runs first: get_version() memoises in a static and any release fetch primes
// it (the user agent uses it), so a later run reads the cache. Production is
// unaffected: one instance, one version, per request.
echo "\n[G] version source of truth\n";
$u = new_updater( '2.5.1' );
ok( $u->get_version() === '2.5.1', 'G1 version read from the plugin Version: header' );


/* =====================================================================
 * [H] The non-blocking contract (2026-08-20). An unforced check must cost
 * the page render nothing at all.
 * ===================================================================== */
echo "\n[H] non-blocking update check\n";

$u = new_updater();
route_site_release();
$r = $u->check_for_update( false, [ 'Version' => '1.1.0' ], BASENAME );
ok( 0 === $GLOBALS['_http_calls'], 'H1 cold cache, unforced: no HTTP call during the render' );
ok( in_array( CACHE_K . '_refresh', $GLOBALS['_scheduled'], true ), 'H2 cold cache, unforced: background refresh queued' );

$u->check_for_update( false, [ 'Version' => '1.1.0' ], BASENAME );
$u->check_for_update( false, [ 'Version' => '1.1.0' ], BASENAME );
ok( 1 === count( $GLOBALS['_scheduled'] ), 'H3 repeated unforced checks coalesce into one queued job' );
ok( 0 === $GLOBALS['_http_calls'], 'H3b repeated unforced checks still make no HTTP call' );

// Returning false here would strip the slug from the plugin row and take "View
// details", and the donate link inside it, off the Plugins screen.
ok( is_array( $r ) && 'holiday-mode-for-hivepress' === $r['slug'] && $r['plugin'] === BASENAME, 'H4 unforced check still answers with slug (View details survives)' );
ok( ! isset( $r['package'] ), 'H4b no-update answer offers no package' );
ok( '1.1.0' === $r['version'], 'H5 no-update answer reports the installed version' );

// The manual link is the one place a person is waiting, so it fetches inline.
$u = new_updater();
route_site_release();
$rel = $u->get_latest_release( true );
ok( $GLOBALS['_http_calls'] > 0, 'H6 forced check (manual link) fetches inline' );
ok( is_array( $rel ) && '1.2.0' === $rel['version'], 'H6b forced check returns the release' );

// refresh_release() is what the scheduler runs; it must fill the cache.
$u = new_updater();
route_site_release();
$u->refresh_release();
ok( is_array( get_site_transient( CACHE_K ) ) && ! empty( get_site_transient( CACHE_K ) ), 'H7 refresh_release() fills the cache' );

$GLOBALS['_http_calls'] = 0;
$GLOBALS['_scheduled']  = [];
$r = $u->check_for_update( false, [], BASENAME );
ok( 0 === $GLOBALS['_http_calls'] && '1.2.0' === $r['version'], 'H8 warm cache: unforced check is served from it, no HTTP' );
ok( [] === $GLOBALS['_scheduled'], 'H8b warm cache queues no further refresh' );


/* =====================================================================
 * [I] github.com first, the API only as a fallback (zero-quota fix).
 * ===================================================================== */
echo "\n[I] release lookup route (GitHub API allowance)\n";

$u = new_updater();
route_site_release();
$rel = $u->get_latest_release( true );
ok( is_array( $rel ) && '1.2.0' === $rel['version'], 'I1 healthy github.com route returns the release' );
ok( [] === urls_touching( 'api.github.com' ), 'I2 a successful lookup never touches api.github.com' );
ok( 0 === strpos( $GLOBALS['_urls'][0], 'https://github.com/' . REPO . '/releases/latest' ), 'I3 first request is the github.com latest-release redirect' );
ok( isset( $GLOBALS['_args'][0]['redirection'] ) && 0 === $GLOBALS['_args'][0]['redirection'], 'I4 redirect is not followed (the Location header IS the answer)' );
ok( 'https://github.com/' . REPO . '/releases/download/v1.2.0/holiday-mode-for-hivepress.zip' === $rel['package'], 'I5 package read from the expanded_assets fragment' );
ok( false !== strpos( $rel['notes'], 'Fixed things' ), 'I6 notes read from releases.atom' );
ok( '2026-07-27T10:00:00Z' === $rel['published'], 'I7 published date read from the feed entry' );

// A repository with nothing published is a definite answer, not a fault, and
// asking the API would only repeat it at the cost of one of the sixty.
$u = new_updater();
$GLOBALS['_routes'] = [ '/releases/latest' => http_code( 404, 'Not Found' ) ];
$rel = $u->get_latest_release( true );
ok( null === $rel, 'I8 github.com 404 (no releases yet) -> no update' );
ok( [] === urls_touching( 'api.github.com' ), 'I9 a definite "no releases" never spends API allowance' );
ok( 'no_release' === get_site_transient( REASON_K ), 'I10 "no releases" recorded as its own reason, not as unreachable' );

// github.com unusable is NOT a definite answer, so the API is allowed its say.
$u = new_updater();
$GLOBALS['_routes'] = [
	'api.github.com'   => http_ok( release_json( 'v1.4.0', [ asset( 'hm.zip', 'https://gh/dl/hm.zip' ) ] ) ),
	'/releases/latest' => http_code( 500, 'boom' ),
];
$rel = $u->get_latest_release( true );
ok( is_array( $rel ) && '1.4.0' === $rel['version'], 'I11 github.com unusable -> API fallback answers' );
ok( 1 === count( urls_touching( 'api.github.com' ) ), 'I12 fallback asks the API exactly once' );

// A release github.com describes but cannot supply an installable asset for is
// not a definite answer either.
$u = new_updater();
$GLOBALS['_routes'] = [
	'api.github.com'             => http_ok( release_json( 'v1.5.0', [ asset( 'hm.zip', 'https://gh/dl/hm.zip' ) ] ) ),
	'/releases/expanded_assets/' => http_ok( assets_html( 'v1.5.0', [ 'notes.txt' ] ) ),
	'/releases.atom'             => http_ok( atom_feed( 'v1.5.0' ) ),
	'/releases/latest'           => http_redirect( 'https://github.com/' . REPO . '/releases/tag/v1.5.0' ),
];
$rel = $u->get_latest_release( true );
ok( is_array( $rel ) && '1.5.0' === $rel['version'] && 'https://gh/dl/hm.zip' === $rel['package'], 'I13 no zip on github.com -> API fallback supplies the package' );

// A spent allowance must not be reported as a network fault.
$u = new_updater();
$GLOBALS['_routes'] = [
	'api.github.com'   => http_code( 403, '', [ 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) ( time() + 600 ) ] ),
	'/releases/latest' => http_code( 500, 'boom' ),
];
ok( null === $u->get_latest_release( true ), 'I14 API 403 with nothing left -> no update' );
ok( 'rate_limited' === get_site_transient( REASON_K ), 'I15 spent allowance reported as rate_limited, not unreachable' );


/* =====================================================================
 * [A] check_for_update payload, read from a primed cache.
 * ===================================================================== */
echo "\n[A] check_for_update payload\n";

$u = new_updater();
route_site_release( 'v1.2.0', [ 'holiday-mode-for-hivepress.zip' ] );
$u->refresh_release();
$r = $u->check_for_update( false, [], BASENAME );
ok( is_array( $r ) && $r['version'] === '1.2.0', 'A1 version from the release tag, "v" stripped' );
ok( $r['package'] === 'https://github.com/' . REPO . '/releases/download/v1.2.0/holiday-mode-for-hivepress.zip', 'A2 package = the .zip asset URL' );
ok( $r['plugin'] === BASENAME && $r['slug'] === 'holiday-mode-for-hivepress', 'A3 plugin + slug set correctly' );
ok( ! isset( $r['new_version'] ) && isset( $r['version'] ), 'A4 uses "version" key (native API contract)' );
ok( $r['url'] === 'https://github.com/' . REPO . '/releases/tag/v1.2.0', 'A4b url points at the release page' );

// Other plugin passes through untouched.
$u = new_updater();
$r = $u->check_for_update( false, [], 'other/other.php' );
ok( $r === false, 'A5 other plugins pass through untouched' );
ok( 0 === $GLOBALS['_http_calls'] && [] === $GLOBALS['_scheduled'], 'A5b another plugin triggers neither fetch nor queue' );

// WP compares versions itself: we always return the release, even if older.
$u = new_updater( '1.1.0' );
route_site_release( 'v1.0.0', [ 'x.zip' ] );
$u->refresh_release();
$r = $u->check_for_update( false, [], BASENAME );
ok( is_array( $r ) && $r['version'] === '1.0.0', 'A6 returns release regardless of order (WP compares)' );


/* =====================================================================
 * [B] asset selection.
 * ===================================================================== */
echo "\n[B] asset selection\n";

$u = new_updater();
route_site_release( 'v1.2.0', [ 'notes.txt', 'first.zip', 'second.zip' ] );
$u->refresh_release();
$r = $u->check_for_update( false, [], BASENAME );
ok( $r['package'] === 'https://github.com/' . REPO . '/releases/download/v1.2.0/first.zip', 'B1 first .zip asset wins; non-zip skipped' );

// No zip on either route. The zipball must NOT stand in for it: it packs a
// differently named folder and is not the release the author published.
$u = new_updater();
$GLOBALS['_routes'] = [
	'api.github.com'             => http_ok( release_json( 'v1.2.0', [ asset( 'notes.txt', 'https://gh/dl/notes.txt' ) ] ) ),
	'/releases/expanded_assets/' => http_ok( assets_html( 'v1.2.0', [ 'notes.txt' ] ) ),
	'/releases.atom'             => http_ok( atom_feed( 'v1.2.0' ) ),
	'/releases/latest'           => http_redirect( 'https://github.com/' . REPO . '/releases/tag/v1.2.0' ),
];
$u->refresh_release();
$r = $u->check_for_update( false, [ 'Version' => '1.1.0' ], BASENAME );
ok( ! isset( $r['package'] ), 'B2 no .zip asset anywhere -> no update offered (zipball NOT used)' );
ok( '1.1.0' === $r['version'], 'B2b and the row still reports the installed version' );

// The API branch picks its asset the same way.
$u = new_updater();
$GLOBALS['_routes'] = [
	'api.github.com'   => http_ok( release_json( 'v1.2.0', [ asset( 'notes.txt', 'https://gh/dl/notes.txt' ), asset( 'a.zip', 'https://gh/dl/a.zip' ), asset( 'b.zip', 'https://gh/dl/b.zip' ) ] ) ),
	'/releases/latest' => http_code( 500, 'boom' ),
];
$u->refresh_release();
$r = $u->check_for_update( false, [], BASENAME );
ok( $r['package'] === 'https://gh/dl/a.zip', 'B3 API fallback also takes the first .zip' );


/* =====================================================================
 * [C] caching.
 * ===================================================================== */
echo "\n[C] caching\n";

$u = new_updater();
route_site_release();
$u->refresh_release();
ok( $GLOBALS['_transient_ttl'][ CACHE_K ] === 6 * HOUR_IN_SECONDS, 'C1 success cached for 6 hours' );

$GLOBALS['_http_calls'] = 0;
$u->check_for_update( false, [], BASENAME );
$u->check_for_update( false, [], BASENAME );
ok( 0 === $GLOBALS['_http_calls'], 'C2 warm cache serves further checks with no HTTP at all' );

// Network error on every route.
$u = new_updater();
$GLOBALS['_http'] = new WP_Error( 'fail' );
$rel = $u->get_latest_release( true );
ok( null === $rel, 'C3 network error -> no update' );
ok( $GLOBALS['_transient_ttl'][ CACHE_K ] === HOUR_IN_SECONDS, 'C4 failure cached for 1 hour (retried promptly, not hammered)' );

$r = $u->check_for_update( false, [ 'Version' => '1.1.0' ], BASENAME );
ok( ! isset( $r['package'] ), 'C4b a cached failure offers no update' );

/*
 * A failed check must not erase what the last good one found. Overwriting the
 * cache with an empty result took a genuinely pending update off the Plugins
 * screen for an hour with nothing to say why.
 */
$u = new_updater();
route_site_release( 'v1.9.0', [ 'hm.zip' ] );
$u->refresh_release();
$GLOBALS['_routes'] = [];
$GLOBALS['_http']   = new WP_Error( 'fail' );
$rel = $u->get_latest_release( true );
ok( is_array( $rel ) && '1.9.0' === $rel['version'], 'C5 a failed check keeps the last good answer' );
$cached = get_site_transient( CACHE_K );
ok( is_array( $cached ) && '1.9.0' === $cached['version'], 'C5b the good answer stays in the cache' );
ok( $GLOBALS['_transient_ttl'][ CACHE_K ] === HOUR_IN_SECONDS, 'C5c but only for an hour, so it retries promptly' );

$u = new_updater();
$GLOBALS['_http'] = http_code( 404, 'Not Found' );
ok( null === $u->get_latest_release( true ), 'C6 404 (no releases yet) -> no update, no crash' );

$u = new_updater();
$GLOBALS['_routes'] = [
	'api.github.com'   => http_ok( '<html>nope</html>' ),
	'/releases/latest' => http_code( 500, 'boom' ),
];
ok( null === $u->get_latest_release( true ), 'C7 malformed JSON -> no update, no crash' );

$u = new_updater();
$GLOBALS['_routes'] = [
	'api.github.com'   => http_ok( release_json( '', [ asset( 'a.zip', 'https://gh/dl/a.zip' ) ] ) ),
	'/releases/latest' => http_code( 500, 'boom' ),
];
ok( null === $u->get_latest_release( true ), 'C8 empty tag -> no update' );


/* =====================================================================
 * [D] plugins_api details popup, read from a primed cache.
 * ===================================================================== */
echo "\n[D] plugins_api details popup\n";

$u = new_updater();
route_site_release( 'v1.2.0', [ 'hm.zip' ] );
$u->refresh_release();
$i = $u->get_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'holiday-mode-for-hivepress' ] );
ok( is_object( $i ) && $i->version === '1.2.0' && $i->download_link === 'https://github.com/' . REPO . '/releases/download/v1.2.0/hm.zip', 'D1 version + download link' );
ok( ! empty( $i->sections['changelog'] ) && strpos( $i->sections['changelog'], 'Fixed things' ) !== false, 'D2 changelog from escaped release body' );
ok( $i->requires === '6.0' && $i->requires_php === '7.4', 'D3 requires headers passed through' );
ok( $u->get_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'other' ] ) === false, 'D4 ignores other slugs' );
ok( $u->get_plugin_information( false, 'query_plugins', (object) [ 'slug' => 'holiday-mode-for-hivepress' ] ) === false, 'D5 ignores other actions' );

// Escaping: a malicious release body must not inject markup.
$u = new_updater();
route_site_release( 'v1.3.0', [ 'a.zip' ], '<script>alert(1)</script>' );
$u->refresh_release();
$i = $u->get_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'holiday-mode-for-hivepress' ] );
ok( strpos( $i->sections['changelog'], '<script>' ) === false, 'D6 release notes escaped (no raw HTML injection)' );

// The popup is rendered during a page load, so it must not fetch either.
$u = new_updater();
route_site_release();
$GLOBALS['_http_calls'] = 0;
$u->get_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'holiday-mode-for-hivepress' ] );
ok( 0 === $GLOBALS['_http_calls'], 'D7 popup on a cold cache does not fetch during the render' );


echo "\n[E] upgrader_source_selection -> installed directory\n";
class FakeFS { public $moved = null; public function move( $a, $b ) { $this->moved = [ $a, $b ]; return true; } }
class FakeFSFail { public function move( $a, $b ) { return false; } }

$GLOBALS['wp_filesystem'] = new FakeFS();
$u = new_updater();
$s = $u->fix_update_directory( '/tmp/up/holiday-mode-for-hivepress-1.2.0/', '/tmp/up/', null, [ 'plugin' => BASENAME ] );
ok( $s === '/tmp/up/holiday-mode-for-hivepress/', 'E1 versioned folder renamed to installed directory' );

$GLOBALS['wp_filesystem'] = new FakeFS();
$u = new_updater();
$s = $u->fix_update_directory( '/tmp/up/irapidchris-del-holiday-mode-for-hivepress-abc123/', '/tmp/up/', null, [ 'plugin' => BASENAME ] );
ok( $s === '/tmp/up/holiday-mode-for-hivepress/', 'E2 GitHub zipball folder renamed correctly' );

$GLOBALS['wp_filesystem'] = new FakeFS();
$u = new_updater();
$s = $u->fix_update_directory( '/tmp/up/holiday-mode-for-hivepress/', '/tmp/up/', null, [ 'plugin' => BASENAME ] );
ok( $s === '/tmp/up/holiday-mode-for-hivepress/' && $GLOBALS['wp_filesystem']->moved === null, 'E3 already-correct folder: no move' );

$GLOBALS['wp_filesystem'] = new FakeFS();
$u = new_updater();
$s = $u->fix_update_directory( '/tmp/up/other/', '/tmp/up/', null, [ 'plugin' => 'other/other.php' ] );
ok( $s === '/tmp/up/other/' && $GLOBALS['wp_filesystem']->moved === null, 'E4 other plugins untouched' );

$GLOBALS['wp_filesystem'] = new FakeFSFail();
$u = new_updater();
$s = $u->fix_update_directory( '/tmp/up/wrong-name/', '/tmp/up/', null, [ 'plugin' => BASENAME ] );
ok( $s instanceof WP_Error, 'E5 failed rename surfaces a WP_Error (install aborts loudly)' );

echo "\n[F] plugin row link + notice\n";
$u = new_updater();
$links = $u->add_update_check_link( [ '<a href="#">Deactivate</a>' ] );
ok( count( $links ) === 2 && strpos( end( $links ), 'Check for updates' ) !== false, 'F1 link added' );
ok( strpos( end( $links ), 'holiday_mode_check_updates=1' ) !== false && strpos( end( $links ), '_wpnonce=' ) !== false, 'F2 link is nonce-protected' );
$GLOBALS['_caps'] = [];
$u = new_updater();
ok( count( $u->add_update_check_link( [ '<a href="#">Deactivate</a>' ] ) ) === 1, 'F3 hidden without update_plugins cap' );
$GLOBALS['_caps'] = [ 'update_plugins' => true ];

// The notice is rendered on the redirect that follows a manual check, by which
// point that check has already filled the cache. It reads; it never fetches.
$u = new_updater( '1.1.0' );
route_site_release( 'v1.2.0', [ 'a.zip' ] );
$u->refresh_release();
$GLOBALS['_http_calls'] = 0;
$_GET['holiday_mode_checked'] = 'available';
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( strpos( $out, '1.2.0' ) !== false && strpos( $out, 'notice-success' ) !== false, 'F4 "available" notice names the new version' );
ok( 0 === $GLOBALS['_http_calls'], 'F4b the notice reads the cache rather than fetching again' );
$_GET['holiday_mode_checked'] = 'none';
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( strpos( $out, 'up to date' ) !== false, 'F5 "none" notice says up to date' );
$_GET['holiday_mode_checked'] = 'error';
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( strpos( $out, 'notice-error' ) !== false, 'F6 "error" notice shown on API failure' );

// The two statuses the zero-quota fix introduced. Both exist so that a refusal
// and an empty repository stop being reported as "could not reach GitHub".
$_GET['holiday_mode_checked'] = 'empty';
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( strpos( $out, 'No releases' ) !== false && strpos( $out, 'notice-info' ) !== false, 'F8 "empty" notice says no releases published yet' );
$_GET['holiday_mode_checked'] = 'limited';
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( strpos( $out, 'limits how many update checks' ) !== false && strpos( $out, 'notice-warning' ) !== false, 'F9 "limited" notice explains the hourly limit, not a fault' );

unset( $_GET['holiday_mode_checked'] );
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( trim( $out ) === '', 'F7 no notice without the result arg' );

echo "\n----------------------------------------\n";
echo "RESULT: $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
