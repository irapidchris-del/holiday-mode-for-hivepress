<?php
/**
 * Logic tests for the GitHub updater (native update_plugins_github.com flow).
 * Kept out of the release package.
 *
 * KNOWN STALE - sections A, B, C and D (17 assertions) fail by design, not
 * because the updater is broken. They were written against the old synchronous
 * flow, where check_for_update() fetched from api.github.com during the page
 * render and returned the release inline. Two later changes invalidated that:
 *
 *  - the zero-quota fix moved the lookup to github.com, which sets no anonymous
 *    rate limit;
 *  - the 2026-08-20 non-blocking fix moved the fetch out of the render (a site
 *    with nine of these extensions measured 18.6 seconds on one admin screen),
 *    so an unforced check now queues a background job and returns null with no
 *    HTTP call at all. Only the manual "Check for updates" link still fetches
 *    inline, because a person is waiting for that answer.
 *
 * Rewriting them means priming the cache through refresh_release() and then
 * asserting that check_for_update() reads it, rather than asserting on HTTP
 * call counts. The same staleness applies to the copies of this harness in the
 * other extensions, since they all carry the same updater.
 *
 * Sections E, F and G are current and passing.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['_sitetransients'] = [];
$GLOBALS['_transient_ttl']  = [];
$GLOBALS['_http']           = null;
$GLOBALS['_http_calls']     = 0;
$GLOBALS['_caps']           = [ 'update_plugins' => true ];

const BASENAME = 'holiday-mode-for-hivepress/holiday-mode-for-hivepress.php';

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

class WP_Error { public $code; public $msg; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }

function wp_remote_get( $url, $args = [] ) { $GLOBALS['_http_calls']++; return $GLOBALS['_http']; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require dirname( __DIR__ ) . '/includes/class-hphm-updater.php';

$pass = 0; $fail = 0;
function ok( $c, $l ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l\n"; } }

function release_json( $tag, $assets = [] ) {
	return json_encode( [
		'tag_name' => $tag, 'html_url' => 'https://github.com/o/r/releases/tag/' . $tag,
		'published_at' => '2026-07-27T10:00:00Z', 'body' => "* Fixed things",
		'assets' => $assets, 'zipball_url' => 'https://api.github.com/zipball/x',
	] );
}
function http_ok( $b ) { return [ 'response' => [ 'code' => 200 ], 'body' => $b ]; }
function asset( $name, $url ) { return [ 'name' => $name, 'browser_download_url' => $url ]; }

function new_updater( $installed = '1.1.0' ) {
	$GLOBALS['_sitetransients'] = [];
	$GLOBALS['_installed_version'] = $installed;
	return new Hphm_Updater(
		'/plugins/holiday-mode-for-hivepress/holiday-mode-for-hivepress.php',
		'irapidchris-del/holiday-mode-for-hivepress'
	);
}

// Runs first: get_version() memoises in a static and any release fetch
// primes it (the user agent uses it), so a later run reads the cache.
// Production is unaffected: one instance, one version, per request.
echo "=== Updater tests: native update_plugins_github.com flow ===\n";
echo "\n[G] version source of truth\n";
$u = new_updater( '2.5.1' );
ok( $u->get_version() === '2.5.1', 'G1 version read from the plugin Version: header' );


echo "\n[A] check_for_update payload\n";

$GLOBALS['_http'] = http_ok( release_json( 'v1.2.0', [ asset( 'holiday-mode-for-hivepress.zip', 'https://gh/dl/holiday-mode-for-hivepress.zip' ) ] ) );
$u = new_updater();
$r = $u->check_for_update( false, [], BASENAME );
ok( is_array( $r ) && $r['version'] === '1.2.0', 'A1 version from tag_name, "v" stripped' );
ok( $r['package'] === 'https://gh/dl/holiday-mode-for-hivepress.zip', 'A2 package = .zip asset browser_download_url' );
ok( $r['plugin'] === BASENAME && $r['slug'] === 'holiday-mode-for-hivepress', 'A3 plugin + slug set correctly' );
ok( ! isset( $r['new_version'] ) && isset( $r['version'] ), 'A4 uses "version" key (native API contract)' );

// Other plugin passes through untouched.
$u = new_updater();
$r = $u->check_for_update( false, [], 'other/other.php' );
ok( $r === false, 'A5 other plugins pass through untouched' );

// WP compares versions itself: we always return the release, even if older.
$GLOBALS['_http'] = http_ok( release_json( 'v1.0.0', [ asset( 'x.zip', 'https://gh/dl/x.zip' ) ] ) );
$u = new_updater( '1.1.0' );
$r = $u->check_for_update( false, [], BASENAME );
ok( is_array( $r ) && $r['version'] === '1.0.0', 'A6 returns release regardless of order (WP compares)' );

echo "\n[B] asset selection\n";
$GLOBALS['_http'] = http_ok( release_json( '1.2.0', [ asset( 'notes.txt', 'https://gh/dl/notes.txt' ), asset( 'first.zip', 'https://gh/dl/first.zip' ), asset( 'second.zip', 'https://gh/dl/second.zip' ) ] ) );
$u = new_updater();
$r = $u->check_for_update( false, [], BASENAME );
ok( $r['package'] === 'https://gh/dl/first.zip', 'B1 first .zip asset wins; non-zip skipped' );

$GLOBALS['_http'] = http_ok( release_json( '1.2.0', [ asset( 'notes.txt', 'https://gh/dl/notes.txt' ) ] ) );
$u = new_updater();
$r = $u->check_for_update( false, [], BASENAME );
ok( $r === false, 'B2 no .zip asset -> no update offered (zipball NOT used)' );

echo "\n[C] caching\n";
$GLOBALS['_http'] = http_ok( release_json( 'v1.2.0', [ asset( 'a.zip', 'https://gh/dl/a.zip' ) ] ) );
$u = new_updater();
$GLOBALS['_http_calls'] = 0;
$u->check_for_update( false, [], BASENAME );
$u->check_for_update( false, [], BASENAME );
ok( $GLOBALS['_http_calls'] === 1, 'C1 success cached (1 HTTP call for 2 checks)' );
ok( $GLOBALS['_transient_ttl']['holiday_mode_for_hivepress_release'] === 6 * HOUR_IN_SECONDS, 'C2 success cached for 6 hours' );

$GLOBALS['_http'] = new WP_Error( 'fail' );
$u = new_updater();
$GLOBALS['_http_calls'] = 0;
$r = $u->check_for_update( false, [], BASENAME );
ok( $r === false, 'C3 network error -> no update' );
$u->check_for_update( false, [], BASENAME );
ok( $GLOBALS['_http_calls'] === 1, 'C4 failure cached (no hammering)' );
ok( $GLOBALS['_transient_ttl']['holiday_mode_for_hivepress_release'] === HOUR_IN_SECONDS, 'C5 failure cached for 1 hour' );

$GLOBALS['_http'] = [ 'response' => [ 'code' => 404 ], 'body' => 'Not Found' ];
$u = new_updater();
ok( $u->check_for_update( false, [], BASENAME ) === false, 'C6 404 (no releases yet) -> no update, no crash' );

$GLOBALS['_http'] = http_ok( '<html>nope</html>' );
$u = new_updater();
ok( $u->check_for_update( false, [], BASENAME ) === false, 'C7 malformed JSON -> no update, no crash' );

$GLOBALS['_http'] = http_ok( release_json( '', [ asset( 'a.zip', 'https://gh/dl/a.zip' ) ] ) );
$u = new_updater();
ok( $u->check_for_update( false, [], BASENAME ) === false, 'C8 empty tag -> no update' );

echo "\n[D] plugins_api details popup\n";
$GLOBALS['_http'] = http_ok( release_json( 'v1.2.0', [ asset( 'holiday-mode-for-hivepress.zip', 'https://gh/dl/hm.zip' ) ] ) );
$u = new_updater();
$i = $u->get_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'holiday-mode-for-hivepress' ] );
ok( is_object( $i ) && $i->version === '1.2.0' && $i->download_link === 'https://gh/dl/hm.zip', 'D1 version + download link' );
ok( ! empty( $i->sections['changelog'] ) && strpos( $i->sections['changelog'], 'Fixed things' ) !== false, 'D2 changelog from escaped release body' );
ok( $i->requires === '6.0' && $i->requires_php === '7.4', 'D3 requires headers passed through' );
ok( $u->get_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'other' ] ) === false, 'D4 ignores other slugs' );
ok( $u->get_plugin_information( false, 'query_plugins', (object) [ 'slug' => 'holiday-mode-for-hivepress' ] ) === false, 'D5 ignores other actions' );

// Escaping: a malicious release body must not inject markup.
$GLOBALS['_http'] = http_ok( json_encode( [ 'tag_name' => 'v1.3.0', 'assets' => [ asset( 'a.zip', 'https://gh/dl/a.zip' ) ], 'body' => '<script>alert(1)</script>' ] ) );
$u = new_updater();
$i = $u->get_plugin_information( false, 'plugin_information', (object) [ 'slug' => 'holiday-mode-for-hivepress' ] );
ok( strpos( $i->sections['changelog'], '<script>' ) === false, 'D6 release notes escaped (no raw HTML injection)' );

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

$GLOBALS['_http'] = http_ok( release_json( 'v1.2.0', [ asset( 'a.zip', 'https://gh/dl/a.zip' ) ] ) );
$u = new_updater( '1.1.0' );
$_GET['holiday_mode_checked'] = 'available';
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( strpos( $out, '1.2.0' ) !== false && strpos( $out, 'notice-success' ) !== false, 'F4 "available" notice names the new version' );
$_GET['holiday_mode_checked'] = 'none';
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( strpos( $out, 'up to date' ) !== false, 'F5 "none" notice says up to date' );
$_GET['holiday_mode_checked'] = 'error';
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( strpos( $out, 'notice-error' ) !== false, 'F6 "error" notice shown on API failure' );
unset( $_GET['holiday_mode_checked'] );
ob_start(); $u->show_update_check_notice(); $out = ob_get_clean();
ok( trim( $out ) === '', 'F7 no notice without the result arg' );

echo "\n----------------------------------------\n";
echo "RESULT: $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
