<?php
/**
 * Logic QA harness for Holiday Mode for HivePress (current working tree).
 * Stubs WordPress/HivePress, then drives the real plugin code.
 *
 * Env: HM_WCS=absent    -> no wcs_user_has_subscription()
 *      HM_VENDOR=absent -> no \HivePress\Models\Vendor
 *      HM_BADGES=1      -> simulate the Badges extension
 *      HM_MESSAGES=1    -> simulate the Messages extension
 */

namespace HivePress\Forms {
	class User_Update {
		public $vals = [];
		public $fields = [];
		public $model = null;
		public function get_value( $n ) { return array_key_exists( $n, $this->vals ) ? $this->vals[ $n ] : null; }
		public function get_fields() { return $this->fields; }
		public function get_model() { return $this->model; }
	}
	class User_Update_Profile extends User_Update {}
}

namespace HivePress\Models {
	if ( getenv( 'HM_VENDOR' ) !== 'absent' ) {
		class Vendor {
			public static function query() { return new self(); }
			public function filter( $a ) { return $this; }
			public function get_first_id() { return $GLOBALS['_vendor_first_id'] ?? 0; }
		}
	}

	// HivePress Memberships. check_memberships() asks twice: once for a
	// `publish` record (active) and once for `draft`/`pending` (lapsed), so the
	// stub has to remember which filter it was handed.
	if ( getenv( 'HM_MEMBERSHIPS' ) !== 'absent' ) {
		class Membership {
			private $wants_lapsed = false;
			public static function query() { return new self(); }
			public function filter( $a ) {
				$this->wants_lapsed = isset( $a['status__in'] );
				return $this;
			}
			public function get_first_id() {
				return $this->wants_lapsed
					? ( $GLOBALS['_mem_draft_id'] ?? 0 )
					: ( $GLOBALS['_mem_publish_id'] ?? 0 );
			}
		}
	}
}

namespace HivePress\Fields {
	// Core has had a Color field since 1.7.26; the plugin falls back to text.
	class Color {}
}

namespace {

	define( 'ABSPATH', '/tmp/wp/' );
	define( 'HM_PLUGIN_FILE', dirname( __DIR__ ) . '/holiday-mode-for-hivepress.php' );

	function reset_globals() {
		$GLOBALS['_usermeta'] = []; $GLOBALS['_users'] = []; $GLOBALS['_can'] = [];
		$GLOBALS['_roles'] = [ 'administrator' => 'Administrator', 'contributor' => 'Contributor', 'hp_vendor' => 'Vendor' ];
		$GLOBALS['_current_user_id'] = 0; $GLOBALS['_posts'] = []; $GLOBALS['_vendor_first_id'] = 0;
		$GLOBALS['_wcs_active'] = false; $GLOBALS['_wcs_has_any'] = false; $GLOBALS['_router_url'] = '';
		$GLOBALS['_mem_ext'] = false; $GLOBALS['_mem_publish_id'] = 0; $GLOBALS['_mem_draft_id'] = 0;
		$GLOBALS['_cascade_instance'] = null; $GLOBALS['_cascade_depth'] = 0;
		$GLOBALS['_is_admin'] = false; $GLOBALS['_badge_actions'] = [];
		$GLOBALS['_user_caps'] = []; $GLOBALS['_options'] = [];
		$GLOBALS['_styles_registered'] = []; $GLOBALS['_styles_enqueued'] = [];
		$GLOBALS['_wpdb_rows'] = []; $GLOBALS['_wpdb_deleted'] = null; $GLOBALS['_cache_deleted'] = [];
	}
	$GLOBALS['_filters'] = [];
	reset_globals();

	class FakeModel { private $id; public function __construct( $i ) { $this->id = $i; } public function get_id() { return $this->id; } }
	class FakeBadge { public function update_listing_status() {} }
	class FakePackage { public function update_user_packages() {} }

	function __( $t, $d = null ) { return $t; }
	function esc_html__( $t, $d = null ) { return $t; }
	function esc_attr__( $t, $d = null ) { return $t; }
	function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
	function esc_url( $u ) { return $u; }
	function wp_kses( $t, $a = [] ) { return strip_tags( (string) $t, '<em><strong>' ); }
	function wp_kses_post( $t ) { return $t; }
	function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
	function sanitize_hex_color( $c ) { return preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $c ) ? $c : ''; }
	function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
	function wp_unslash( $v ) { return $v; }
	function wp_json_encode( $d ) { return json_encode( $d ); }
	function wp_print_inline_script_tag( $js ) { echo '<script>' . $js . '</script>'; }
	function wp_add_inline_script( $h, $js, $p = 'after' ) { return true; }
	function wp_enqueue_script( ...$a ) { return true; }
	function wp_enqueue_style( ...$a ) { $GLOBALS['_styles_enqueued'][] = $a[0] ?? ''; return true; }
	function wp_register_style( $h, ...$a ) { $GLOBALS['_styles_registered'][ $h ] = 'plugin'; return true; }
	function wp_style_is( $h, $s = 'enqueued' ) {
		if ( 'registered' === $s ) { return ! empty( $GLOBALS['_styles_registered'][ $h ] ); }
		return in_array( $h, $GLOBALS['_styles_enqueued'], true );
	}
	function wp_add_inline_style( $h, $css ) { return true; }
	function plugin_basename( $f ) { return 'holiday-mode-for-hivepress/holiday-mode-for-hivepress.php'; }
	function plugin_dir_url( $f ) { return 'http://example.test/wp-content/plugins/holiday-mode-for-hivepress/'; }
	function home_url( $p = '' ) { return 'http://example.test' . $p; }
	function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
	function self_admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . $p; }
	function is_admin() { return (bool) $GLOBALS['_is_admin']; }
	function __return_empty_string() { return ''; }

	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) {
		$GLOBALS['_filters'][ $tag ][] = [ 'cb' => $cb, 'prio' => $prio, 'args' => $args ]; return true;
	}
	function add_action( $tag, $cb, $prio = 10, $args = 1 ) {
		if ( 'hivepress/v1/models/listing/update_status' === $tag ) { $GLOBALS['_badge_actions'][] = 'add'; }
		return true;
	}
	function remove_action( $tag, $cb, $prio = 10 ) {
		if ( 'hivepress/v1/models/listing/update_status' === $tag ) { $GLOBALS['_badge_actions'][] = 'remove'; }
		return true;
	}
	// Matches by callback identity, like core: bulk_restore() removes only its
	// own hpsp_push_event callback, and a tag-wide unset here would silently
	// strip test-registered filters sharing the tag.
	function remove_filter( $tag, $cb, $prio = 10 ) {
		foreach ( $GLOBALS['_filters'][ $tag ] ?? [] as $i => $h ) {
			if ( $h['cb'] === $cb ) { unset( $GLOBALS['_filters'][ $tag ][ $i ] ); }
		}
		return true;
	}
	// Records every fired action so tests can assert on the public hooks.
	function do_action( $tag ) {
		$GLOBALS['_did_actions'][] = [ 'tag' => $tag, 'args' => array_slice( func_get_args(), 1 ) ];
	}
	function apply_filters( $tag, $value ) {
		$extra = array_slice( func_get_args(), 2 );
		if ( empty( $GLOBALS['_filters'][ $tag ] ) ) { return $value; }
		$hooks = $GLOBALS['_filters'][ $tag ];
		usort( $hooks, function ( $a, $b ) { return $a['prio'] <=> $b['prio']; } );
		foreach ( $hooks as $h ) {
			$argv  = array_slice( array_merge( [ $value ], $extra ), 0, max( 1, (int) $h['args'] ) );
			$value = call_user_func_array( $h['cb'], $argv );
		}
		return $value;
	}

	function get_current_user_id() { return (int) $GLOBALS['_current_user_id']; }
	function is_user_logged_in() { return $GLOBALS['_current_user_id'] > 0; }
	function current_user_can( $c ) { return ! empty( $GLOBALS['_can'][ $c ] ); }
	function user_can( $uid, $c ) { return ! empty( $GLOBALS['_user_caps'][ $uid ][ $c ] ); }
	function get_user_by( $f, $id ) { return isset( $GLOBALS['_users'][ $id ] ) ? (object) $GLOBALS['_users'][ $id ] : false; }
	// WP_User exposes ->roles as a plain slug array; the role-based audience
	// added in 1.7.6 reads nothing else off the user object.
	function get_userdata( $id ) { return isset( $GLOBALS['_users'][ $id ] ) ? (object) $GLOBALS['_users'][ $id ] : false; }
	function wp_roles() { return new class { public function get_names() { return $GLOBALS['_roles']; } }; }
	function translate_user_role( $n ) { return $n; }
	function get_user_meta( $uid, $k, $s = false ) { return $GLOBALS['_usermeta'][ $uid ][ $k ] ?? ''; }
	function update_user_meta( $uid, $k, $v ) { $GLOBALS['_usermeta'][ $uid ][ $k ] = $v; return true; }
	function delete_user_meta( $uid, $k ) { unset( $GLOBALS['_usermeta'][ $uid ][ $k ] ); return true; }
	function user_meta_row_exists( $uid, $k ) { return array_key_exists( $k, $GLOBALS['_usermeta'][ $uid ] ?? [] ); }

	function get_option( $k, $d = false ) { return $GLOBALS['_options'][ $k ] ?? $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['_options'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['_options'][ $k ] ); return true; }
	function wp_cache_delete( $id, $g = '' ) { $GLOBALS['_cache_deleted'][] = $id; return true; }

	function get_post_status( $id ) { return isset( $GLOBALS['_posts'][ $id ] ) ? $GLOBALS['_posts'][ $id ]['status'] : false; }
	// Core's real list, so bulk_restore's "every status including trash" sweep
	// (the 1.7.5 stale-marker fix) can be driven by the harness.
	function get_post_stati( $args = [], $output = 'names' ) {
		return [
			'publish' => 'publish',
			'future'  => 'future',
			'draft'   => 'draft',
			'pending' => 'pending',
			'private' => 'private',
			'trash'   => 'trash',
			'auto-draft' => 'auto-draft',
			'inherit' => 'inherit',
		];
	}
	function get_post( $id ) {
		if ( ! isset( $GLOBALS['_posts'][ $id ] ) ) { return null; }
		return (object) [ 'ID' => $id, 'post_author' => $GLOBALS['_posts'][ $id ]['author'] ];
	}
	function get_post_field( $f, $id ) { return 'post_author' === $f ? ( $GLOBALS['_posts'][ $id ]['author'] ?? 0 ) : ''; }
	function get_post_status_object( $s ) {
		$l = [ 'publish' => 'Published', 'pending' => 'Pending Review', 'private' => 'Private', 'future' => 'Scheduled' ];
		return isset( $l[ $s ] ) ? (object) [ 'label' => $l[ $s ] ] : null;
	}
	function wp_update_post( $arr ) {
		$id = $arr['ID'];
		if ( isset( $arr['post_status'] ) && isset( $GLOBALS['_posts'][ $id ] ) ) {
			$GLOBALS['_posts'][ $id ]['status'] = $arr['post_status'];
			if ( $GLOBALS['_cascade_instance'] ) {
				$GLOBALS['_cascade_depth']++;
				if ( $GLOBALS['_cascade_depth'] > 20 ) { throw new \Exception( 'runaway recursion' ); }
				$GLOBALS['_cascade_instance']->enforce_draft_while_holiday( $id );
			}
		}
		return $id;
	}
	function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['_posts'][ $id ]['meta'][ $k ] ?? ''; }
	function update_post_meta( $id, $k, $v ) { $GLOBALS['_posts'][ $id ]['meta'][ $k ] = $v; return true; }
	function delete_post_meta( $id, $k ) { unset( $GLOBALS['_posts'][ $id ]['meta'][ $k ] ); return true; }

	function _match_status( $s, $f ) {
		if ( 'any' === $f ) { return ! in_array( $s, [ 'trash', 'auto-draft' ], true ); }
		if ( is_array( $f ) ) { return in_array( $s, $f, true ); }
		return $s === $f;
	}
	function get_posts( $args ) {
		$out = [];
		foreach ( $GLOBALS['_posts'] as $id => $p ) {
			if ( (int) $p['author'] !== (int) $args['author'] ) { continue; }
			if ( ! _match_status( $p['status'], $args['post_status'] ?? 'publish' ) ) { continue; }
			$out[] = $id;
			if ( ! empty( $args['numberposts'] ) && count( $out ) >= $args['numberposts'] ) { break; }
		}
		return $out;
	}
	class WP_Query {
		public $posts = [];
		public function __construct( $args ) {
			foreach ( $GLOBALS['_posts'] as $id => $p ) {
				if ( (int) $p['author'] !== (int) $args['author'] ) { continue; }
				if ( ! _match_status( $p['status'], $args['post_status'] ) ) { continue; }
				if ( ! empty( $args['meta_key'] ) && ! array_key_exists( $args['meta_key'], $p['meta'] ) ) { continue; }
				$this->posts[] = $id;
			}
		}
	}

	if ( getenv( 'HM_WCS' ) !== 'absent' ) {
		function wcs_user_has_subscription( $uid, $product = 0, $status = '' ) {
			if ( 'any' === $status ) { return ! empty( $GLOBALS['_wcs_has_any'] ); }
			return ! empty( $GLOBALS['_wcs_active'] );
		}
	}

	// Mirrors HivePress\Components\Template::merge_blocks()/_merge_blocks().
	class FakeTemplateComponent {
		public function merge_blocks( &$template, $blocks ) {
			if ( isset( $template['blocks'] ) ) { $template['blocks'] = $this->_m( $template['blocks'], $blocks ); }
			else { $template = $this->_m( $template, $blocks ); }
			return $template;
		}
		private function _m( &$template, &$blocks ) {
			$names = array_keys( $blocks );
			foreach ( $template as $name => $block ) {
				if ( ! $names ) { break; }
				$i = array_search( $name, $names, true );
				if ( false !== $i ) {
					$template[ $name ] = array_replace_recursive( $template[ $name ], $blocks[ $name ] );
					unset( $blocks[ $name ], $names[ $i ] );
				} elseif ( isset( $block['blocks'] ) ) {
					$template[ $name ]['blocks'] = $this->_m( $template[ $name ]['blocks'], $blocks );
				}
			}
			return $template;
		}
	}

	class FakeWpdb {
		public $usermeta = 'wp_usermeta'; public $options = 'wp_options'; public $postmeta = 'wp_postmeta';
		private $last = [];
		public function prepare( $sql, ...$a ) { $this->last = $a; return $sql; }
		public function get_col( $sql ) { return $GLOBALS['_wpdb_rows']; }
		public function query( $sql ) { $GLOBALS['_wpdb_deleted'] = $this->last; return count( $GLOBALS['_wpdb_rows'] ); }
		public function esc_like( $x ) { return $x; }
	}
	$GLOBALS['wpdb'] = new FakeWpdb();

	function hivepress() {
		return new class {
			public $router; public $badge; public $template; public $listing_package;
			public function __construct() {
				$this->router          = new class { public function get_url( $n ) { return $GLOBALS['_router_url']; } };
				$this->badge           = new FakeBadge();
				$this->listing_package = new FakePackage();
				$this->template        = new FakeTemplateComponent();
			}
			public function get_version( $ext ) {
				if ( 'badges' === $ext ) { return getenv( 'HM_BADGES' ) === '1' ? '1.0.0' : null; }
				if ( 'messages' === $ext ) { return getenv( 'HM_MESSAGES' ) === '1' ? '1.0.0' : null; }
				if ( 'memberships' === $ext ) { return empty( $GLOBALS['_mem_ext'] ) ? null : '1.0.0'; }
				return null;
			}
			// A slice of core's FA5 icon config is enough for the picker tests.
			public function get_config( $name ) {
				return 'icons' === $name ? [ 'info-circle' => 'info-circle', 'anchor' => 'anchor' ] : [];
			}
		};
	}

	require HM_PLUGIN_FILE;

	$GLOBALS['_pass'] = 0; $GLOBALS['_fail'] = 0;
	function ok( $c, $l ) {
		if ( $c ) { $GLOBALS['_pass']++; echo "  PASS  $l\n"; }
		else { $GLOBALS['_fail']++; echo "  FAIL  $l\n"; }
	}

	$INST = Holiday_Mode_For_HivePress::instance();

	function reset_state() {
		global $INST;
		reset_globals();
		$r = new ReflectionClass( $INST );
		foreach ( [ 'pending_toggle' => null, 'suspend_enforce' => false, 'vendor_cache' => [] ] as $p => $v ) {
			if ( $r->hasProperty( $p ) ) { $pr = $r->getProperty( $p ); $pr->setAccessible( true ); $pr->setValue( $INST, $v ); }
		}
	}
	function set_priv( $i, $p, $v ) { $x = new ReflectionProperty( $i, $p ); $x->setAccessible( true ); $x->setValue( $i, $v ); }
	function get_priv( $i, $p ) { $x = new ReflectionProperty( $i, $p ); $x->setAccessible( true ); return $x->getValue( $i ); }
	function call_priv( $i, $m, $a = [] ) { $x = new ReflectionMethod( $i, $m ); $x->setAccessible( true ); return $x->invokeArgs( $i, $a ); }
	function add_post( $id, $au, $st, $meta = [] ) { $GLOBALS['_posts'][ $id ] = [ 'author' => $au, 'status' => $st, 'meta' => $meta ]; }
	// The Memberships restore gate is an opt-in setting since 1.7.6, so every
	// test that exercises it has to tick the box first. reset_state() clears
	// $GLOBALS['_options'], so this belongs AFTER the reset, never before it.
	function require_membership() { $GLOBALS['_options']['hp_holiday_mode_for_hivepress_require_membership'] = 1; }
	function settings_form( $val, $uid = 10, $cls = 'HivePress\Forms\User_Update' ) {
		$f = new $cls();
		$f->fields = [ 'holiday_mode_for_hivepress' => [ 'type' => 'checkbox' ] ];
		$f->vals   = ( null === $val ) ? [] : [ 'holiday_mode_for_hivepress' => $val ];
		$f->model  = new FakeModel( $uid );
		return $f;
	}

	$WCS = getenv( 'HM_WCS' ) !== 'absent';
	$VEN = getenv( 'HM_VENDOR' ) !== 'absent';
	$MEM = getenv( 'HM_MEMBERSHIPS' ) !== 'absent';
	$BDG = getenv( 'HM_BADGES' ) === '1';
	$MSG = getenv( 'HM_MESSAGES' ) === '1';
	echo '=== Holiday Mode QA (WCS ' . ( $WCS ? 'on' : 'ABSENT' ) . ', Vendor ' . ( $VEN ? 'on' : 'ABSENT' ) .
		', Badges ' . ( $BDG ? 'on' : 'off' ) . ', Messages ' . ( $MSG ? 'on' : 'off' ) . ") ===\n";

	/* ===================== A. entitlement ===================== */
	echo "\n[A] get_entitlement\n";
	// Subscriptions no longer govern at all (1.7.5): wcs_user_has_subscription()
	// is product-agnostic, so a lapsed newsletter subscription used to trap a
	// vendor on holiday for good. Memberships CAN still govern, but only where
	// the owner ticked the 1.7.6 opt-in AND hp_membership_models covers
	// listings: HivePress never re-gates listings that are already published,
	// so an imposed gate protected nothing and only left the vendor who used
	// holiday mode worse off than one who never did.
	if ( $WCS ) {
		reset_state(); $GLOBALS['_wcs_has_any'] = true; $GLOBALS['_wcs_active'] = true;
		$e = $INST->get_entitlement( 8 );
		ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A1 active subscription is no longer consulted [1.7.5]' );

		reset_state(); $GLOBALS['_wcs_has_any'] = true; $GLOBALS['_wcs_active'] = false;
		$e = $INST->get_entitlement( 9 );
		ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A2 lapsed subscription no longer traps the vendor [1.7.5]' );

		reset_state(); $GLOBALS['_wcs_has_any'] = false;
		$e = $INST->get_entitlement( 10 );
		ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A4 installed but never enrolled -> allowed [1.5.0]' );

		reset_state(); $GLOBALS['_wcs_has_any'] = true; $GLOBALS['_user_caps'][7] = [ 'edit_others_posts' => true ];
		$e = $INST->get_entitlement( 7 );
		ok( true === $e['allowed'] && 'bypass' === $e['reason'], 'A5 edit_others_posts bypasses' );
	} else {
		reset_state();
		$e = $INST->get_entitlement( 9 );
		ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A6 no subscription system -> ungoverned' );
	}

	/* --- Memberships gate, restored in 1.7.5 after being over-retired --- */

	// Gate ticked ON but the extension is absent entirely: the option on its
	// own must never govern anybody.
	reset_state(); require_membership(); $GLOBALS['_mem_publish_id'] = 55;
	$e = $INST->get_entitlement( 20 );
	ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A9 gate ON but Memberships inactive -> ungoverned [1.7.6]' );

	// Gate ticked ON and the extension active, but listing restrictions
	// switched OFF for the site.
	reset_state(); require_membership(); $GLOBALS['_mem_ext'] = true;
	$GLOBALS['_options']['hp_membership_models'] = [ 'vendor' ];
	$GLOBALS['_mem_draft_id'] = 77;
	$e = $INST->get_entitlement( 21 );
	ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A10 listings not restricted -> ungoverned even with a lapsed membership' );

	if ( $MEM ) {
		// GATE OFF, which is the default and what an install upgrading from
		// 1.7.5 gets. Everything that would govern is present and the
		// membership has lapsed, and the vendor must STILL be waved through:
		// HivePress leaves their already published listings visible on lapse,
		// so refusing here would only punish them for having used the feature.
		reset_state(); $GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_draft_id'] = 77;
		$e = $INST->get_entitlement( 26 );
		ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A9b gate OFF by default -> lapsed member allowed [1.7.6]' );
		ok( '' === $e['message'], 'A9c gate OFF -> no renew-your-membership message [1.7.6]' );

		// GATE OFF with an ACTIVE membership: not consulted either, so the
		// reason stays 'ungoverned' rather than 'memberships_active'.
		reset_state(); $GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_publish_id'] = 55;
		$e = $INST->get_entitlement( 27 );
		ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A9d gate OFF -> membership not consulted at all [1.7.6]' );

		// GATE ON, governed and active.
		reset_state(); require_membership(); $GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_publish_id'] = 55;
		$e = $INST->get_entitlement( 22 );
		ok( true === $e['allowed'] && 'memberships_active' === $e['reason'], 'A11 gate ON, active membership -> allowed' );

		// GATE ON, governed and lapsed: the 1.5.0 behaviour, now opt-in.
		reset_state(); require_membership(); $GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_draft_id'] = 77;
		$e = $INST->get_entitlement( 23 );
		ok( false === $e['allowed'] && 'memberships_lapsed' === $e['reason'], 'A12 gate ON, lapsed membership -> blocked [1.7.6]' );
		ok( stripos( $e['message'], 'membership' ) !== false, 'A13 message names the membership' );

		// Never held one: enrolment, not installation, confers jurisdiction.
		reset_state(); require_membership(); $GLOBALS['_mem_ext'] = true;
		$e = $INST->get_entitlement( 24 );
		ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A14 never enrolled -> allowed' );

		// Admin bypass must win over a lapsed membership.
		reset_state(); require_membership(); $GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_draft_id'] = 77;
		$GLOBALS['_user_caps'][7] = [ 'edit_others_posts' => true ];
		$e = $INST->get_entitlement( 7 );
		ok( true === $e['allowed'] && 'bypass' === $e['reason'], 'A15 edit_others_posts beats a lapsed membership' );
	} else {
		// Memberships not installed: the class-exists guard must keep the whole
		// gate out of the way rather than fatalling on a missing model, whether
		// or not the owner ticked the option on.
		reset_state(); $GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_draft_id'] = 77;
		$e = $INST->get_entitlement( 25 );
		ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A11b Memberships model absent -> ungoverned, no fatal' );

		reset_state(); require_membership(); $GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_draft_id'] = 77;
		$e = $INST->get_entitlement( 28 );
		ok( true === $e['allowed'] && 'ungoverned' === $e['reason'], 'A11c option ON has no effect with Memberships absent [1.7.6]' );
	}

	reset_state();
	$GLOBALS['_filters']['holiday_mode_for_hivepress_has_active_membership'] = [ [ 'cb' => function ( $a ) { return false; }, 'prio' => 10, 'args' => 1 ] ];
	$e = $INST->get_entitlement( 11 );
	ok( false === $e['allowed'] && ! empty( $e['message'] ), 'A7 legacy filter blocks with fallback message' );
	unset( $GLOBALS['_filters']['holiday_mode_for_hivepress_has_active_membership'] );

	reset_state();
	$GLOBALS['_filters']['holiday_mode_for_hivepress_entitlement'] = [ [ 'cb' => function ( $x ) { $x['allowed'] = false; $x['message'] = 'Nope'; return $x; }, 'prio' => 10, 'args' => 1 ] ];
	$e = $INST->get_entitlement( 12 );
	ok( false === $e['allowed'] && 'Nope' === $e['message'], 'A8 entitlement filter plugs in another system' );
	unset( $GLOBALS['_filters']['holiday_mode_for_hivepress_entitlement'] );

	// Gate refusal is now driven by a lapsed MEMBERSHIP rather than a lapsed
	// subscription, but the property under test is unchanged since 1.5.0:
	// apply_toggle re-checks entitlement, so a queued switch-off cannot restore.
	if ( $MEM ) {
		reset_state(); require_membership(); $GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_draft_id'] = 77;
		add_post( 900, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'publish' ] );
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => false ] );
		$INST->apply_toggle( 10 );
		ok( 'draft' === get_post_status( 900 ), 'A16 apply_toggle re-checks gate; stale intent cannot restore [1.5.0]' );
	}

	if ( $WCS && $VEN ) {
		/* ===================== B. field injection ===================== */
		echo "\n[B] extend_settings_form\n";
		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		$out = $INST->extend_settings_form( [ 'fields' => [] ], new HivePress\Forms\User_Update() );
		ok( isset( $out['fields']['holiday_mode_for_hivepress'] ), 'B1 toggle added on User_Update' );
		ok( true === ( $out['fields']['holiday_mode_for_hivepress']['_separate'] ?? false ), 'B2 field is form-only (_separate)' );

		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		$out = $INST->extend_settings_form( [ 'fields' => [] ], new HivePress\Forms\User_Update_Profile() );
		ok( ! isset( $out['fields']['holiday_mode_for_hivepress'] ), 'B3 subclass form excluded' );

		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		ok( ! isset( $INST->extend_settings_form( [ 'fields' => [] ], null )['fields']['holiday_mode_for_hivepress'] ), 'B4 no form object -> fails closed' );

		reset_state(); $GLOBALS['_current_user_id'] = 11;
		ok( ! isset( $INST->extend_settings_form( [ 'fields' => [] ], new HivePress\Forms\User_Update() )['fields']['holiday_mode_for_hivepress'] ), 'B5 non-vendor sees nothing' );

		// 1.7.0: per-vendor away-message fields appear only when enabled.
		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		$out = $INST->extend_settings_form( [ 'fields' => [] ], new HivePress\Forms\User_Update() );
		ok( ! isset( $out['fields']['holiday_mode_for_hivepress_headline'] ), 'B6 vendor message fields hidden by default [1.7.0]' );

		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		$GLOBALS['_options']['hp_holiday_mode_for_hivepress_vendor_custom'] = 1;
		$out = $INST->extend_settings_form( [ 'fields' => [] ], new HivePress\Forms\User_Update() );
		ok( isset( $out['fields']['holiday_mode_for_hivepress_headline'] ) && isset( $out['fields']['holiday_mode_for_hivepress_message'] ),
			'B7 vendor message fields appear when Vendor Messages is on [1.7.0]' );

		// 1.7.6: the vendor-facing sentence promises a membership check, so it
		// may only appear where one will actually run. Promising a check the
		// plugin will not perform misleads every vendor on the site, which is
		// the trap the pre-1.3.1 wording fell into.
		$promise = 'membership is active at that time';

		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		$GLOBALS['_mem_ext'] = true;
		$out = $INST->extend_settings_form( [ 'fields' => [] ], new HivePress\Forms\User_Update() );
		ok( false === strpos( $out['fields']['holiday_mode_for_hivepress']['description'], $promise ),
			'B8 gate off -> the vendor is promised no membership check [1.7.6]' );

		if ( $MEM ) {
			reset_state(); require_membership(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
			$GLOBALS['_mem_ext'] = true;
			$out = $INST->extend_settings_form( [ 'fields' => [] ], new HivePress\Forms\User_Update() );
			ok( false !== strpos( $out['fields']['holiday_mode_for_hivepress']['description'], $promise ),
				'B9 gate on + Memberships governing -> the promise is shown [1.7.6]' );
		}

		reset_state(); require_membership(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		$GLOBALS['_mem_ext'] = true; $GLOBALS['_options']['hp_membership_models'] = [ 'vendor' ];
		$out = $INST->extend_settings_form( [ 'fields' => [] ], new HivePress\Forms\User_Update() );
		ok( false === strpos( $out['fields']['holiday_mode_for_hivepress']['description'], $promise ),
			'B10 gate on but restrictions do not cover listings -> no promise [1.7.6]' );

		/* ===================== C. validate_toggle ===================== */
		echo "\n[C] validate_toggle\n";
		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = false;
		$e = $INST->validate_toggle( [], settings_form( true ) );
		$pt = get_priv( $INST, 'pending_toggle' );
		ok( $e === [] && $pt && true === $pt['enable'], 'C1 tick -> enable recorded' );

		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		$e = $INST->validate_toggle( [], settings_form( null ) );
		$pt = get_priv( $INST, 'pending_toggle' );
		ok( $e === [] && $pt && false === $pt['enable'], 'C2 UNTICK (absent value) -> disable recorded [1.2.0 fix]' );

		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		$f = new HivePress\Forms\User_Update(); $f->fields = [ 'first_name' => [] ]; $f->model = new FakeModel( 10 );
		ok( $INST->validate_toggle( [], $f ) === [] && null === get_priv( $INST, 'pending_toggle' ), 'C3 field not on form -> ignored' );

		reset_state(); $GLOBALS['_current_user_id'] = 1; $GLOBALS['_can']['manage_options'] = true;
		ok( $INST->validate_toggle( [], settings_form( true, 99 ) ) === [] && null === get_priv( $INST, 'pending_toggle' ), 'C4 admin editing another user -> ignored' );

		if ( $MEM ) {
			reset_state(); require_membership(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
			$GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_draft_id'] = 77;
			$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
			$e = $INST->validate_toggle( [], settings_form( null ) );
			ok( count( $e ) === 1 && null === get_priv( $INST, 'pending_toggle' ), 'C5 gate ON, lapsed membership -> switch-off refused' );
			ok( true === get_user_meta( 10, '_holiday_mode_for_hivepress', true ), 'C6 flag stays ON after refusal' );

			// Same vendor, same lapsed membership, gate left at its default:
			// the switch-off must go straight through.
			reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 55;
			$GLOBALS['_mem_ext'] = true; $GLOBALS['_mem_draft_id'] = 77;
			$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
			$e  = $INST->validate_toggle( [], settings_form( null ) );
			$pt = get_priv( $INST, 'pending_toggle' );
			ok( $e === [] && $pt && false === $pt['enable'], 'C6b gate OFF, lapsed membership -> switch-off accepted [1.7.6]' );
		}

		/* ===================== D. apply_toggle ===================== */
		echo "\n[D] apply_toggle\n";
		reset_state(); $GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		$INST->apply_toggle( 10 );
		ok( true === get_user_meta( 10, '_holiday_mode_for_hivepress', true ), 'D1 no pending -> unrelated save untouched' );

		reset_state();
		add_post( 100, 10, 'publish' ); add_post( 101, 10, 'pending' );
		add_post( 102, 10, 'draft' ); add_post( 103, 10, 'future' );
		set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => true ] );
		$INST->apply_toggle( 10 );
		ok( 'draft' === get_post_status( 100 ) && 'publish' === get_post_meta( 100, '_holiday_mode_for_hivepress_prev_status', true ), 'D2 publish hidden' );
		ok( 'draft' === get_post_status( 101 ) && 'draft' === get_post_status( 103 ), 'D3 pending + future hidden' );
		ok( '' === get_post_meta( 102, '_holiday_mode_for_hivepress_prev_status', true ), 'D4 pre-existing draft not snapshotted' );

		set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => false ] );
		$INST->apply_toggle( 10 );
		ok( ! user_meta_row_exists( 10, '_holiday_mode_for_hivepress' ), 'D5 switch-off DELETES meta row [1.3.2]' );
		ok( 'publish' === get_post_status( 100 ) && 'pending' === get_post_status( 101 ) && 'future' === get_post_status( 103 ), 'D6 statuses restored exactly' );
		ok( 'draft' === get_post_status( 102 ), 'D7 pre-existing draft still draft' );

		// Expiry rule (1.5.0).
		reset_state();
		add_post( 700, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'publish', 'hp_expired_time' => time() - 3600 ] );
		add_post( 701, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'publish', 'hp_expired_time' => time() + 86400 ] );
		add_post( 702, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'publish' ] );
		add_post( 703, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'publish', 'hp_expired_time' => '' ] );
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => false ] );
		$INST->apply_toggle( 10 );
		ok( 'draft' === get_post_status( 700 ), 'D8 expired listing stays drafted [1.5.0]' );
		ok( 'publish' === get_post_status( 701 ) && 'publish' === get_post_status( 702 ) && 'publish' === get_post_status( 703 ), 'D9 non-expired restored' );
		ok( '' === get_post_meta( 700, '_holiday_mode_for_hivepress_prev_status', true ), 'D10 meta cleared even when not restored' );

		reset_state();
		add_post( 200, 10, 'publish', [ '_holiday_mode_for_hivepress_prev_status' => 'publish' ] );
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => false ] );
		$INST->apply_toggle( 10 );
		ok( 'publish' === get_post_status( 200 ), 'D11 listing changed during holiday left as-is' );

		/* ===================== E. extension interop ===================== */
		echo "\n[E] Badges / Paid Listings interop\n";
		reset_state();
		add_post( 300, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'publish' ] );
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => false ] );
		$INST->apply_toggle( 10 );
		if ( $BDG ) {
			ok( $GLOBALS['_badge_actions'] === [ 'remove', 'add' ], 'E1 badges listener stood down then restored' );
		} else {
			ok( $GLOBALS['_badge_actions'] === [], 'E1 badges absent -> untouched' );
		}
		ok( 'publish' === get_post_status( 300 ), 'E2 restore works alongside extension handling' );
	}

	/* ===================== F. enforcement ===================== */
	echo "\n[F] enforce_draft_while_holiday\n";
	reset_state(); $GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
	add_post( 400, 10, 'publish' ); $INST->enforce_draft_while_holiday( 400 );
	ok( 'draft' === get_post_status( 400 ), 'F1 visible listing forced to draft' );

	reset_state(); $GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
	add_post( 401, 10, 'trash' ); $INST->enforce_draft_while_holiday( 401 );
	ok( 'trash' === get_post_status( 401 ), 'F2 trashed listing NOT un-trashed' );

	reset_state(); $GLOBALS['_usermeta'][20]['_holiday_mode_for_hivepress'] = false;
	add_post( 403, 20, 'publish' ); $INST->enforce_draft_while_holiday( 403 );
	ok( 'publish' === get_post_status( 403 ), 'F3 holiday OFF -> no enforcement' );

	reset_state(); $GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
	add_post( 404, 10, 'publish' ); $GLOBALS['_cascade_instance'] = $INST;
	$threw = false;
	try { $INST->enforce_draft_while_holiday( 404 ); } catch ( \Throwable $ex ) { $threw = true; }
	ok( ! $threw && 'draft' === get_post_status( 404 ) && 1 === $GLOBALS['_cascade_depth'], 'F4 re-entrancy guard holds' );

	/* ===================== G. vendor detection ===================== */
	echo "\n[G] is_user_vendor\n";
	if ( $VEN ) {
		reset_state(); $GLOBALS['_vendor_first_id'] = 77;
		ok( true === call_priv( $INST, 'is_user_vendor', [ 10 ] ), 'G1 vendor profile -> true' );
	}
	reset_state(); add_post( 500, 10, 'publish' );
	ok( true === call_priv( $INST, 'is_user_vendor', [ 10 ] ), 'G2 listing-author fallback -> true' );
	reset_state();
	ok( false === call_priv( $INST, 'is_user_vendor', [ 11 ] ), 'G3 neither -> false' );

	/* ===================== H. admin column ===================== */
	if ( $VEN ) {
		echo "\n[H] wp-admin Holiday mode column\n";
		reset_state();
		$cols = $INST->add_listing_admin_columns( [ 'cb' => '', 'title' => '', 'author' => '', 'date' => '' ] );
		ok( isset( $cols['holiday_mode_for_hivepress'] ), 'H1 column registered' );

		reset_state(); $GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		add_post( 600, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'publish' ] );
		ob_start(); $INST->render_listing_admin_columns( 'holiday_mode_for_hivepress', 600 ); $o = ob_get_clean();
		ok( false !== strpos( $o, 'Hidden' ) && false !== strpos( $o, 'Published' ), 'H2 hidden listing shows its return status' );

		reset_state(); add_post( 602, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'publish' ] );
		ob_start(); $INST->render_listing_admin_columns( 'holiday_mode_for_hivepress', 602 ); $o = ob_get_clean();
		ok( '&mdash;' === trim( $o ), 'H3 stale meta w/ holiday off -> dash (no false positive)' );
	}

	/* ===================== I. banner ===================== */
	if ( $VEN ) {
		echo "\n[I] account banner\n";
		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 88;
		$GLOBALS['_router_url'] = 'http://example.test/account/settings/';
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		ob_start(); $INST->maybe_print_banner(); $o = ob_get_clean();
		ok( false !== strpos( $o, 'holiday-mode-for-hivepress-banner' ) && false !== strpos( $o, 'example.test' ), 'I1 banner printed with settings URL' );
		ok( false !== strpos( $o, 'hp-template--user-account-page' ), 'I2 gated to account template' );

		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 88;
		ob_start(); $INST->maybe_print_banner(); $o = ob_get_clean();
		ok( '' === trim( $o ), 'I3 holiday OFF -> no banner' );

		reset_state(); $GLOBALS['_current_user_id'] = 0;
		ob_start(); $INST->maybe_print_banner(); $o = ob_get_clean();
		ok( '' === trim( $o ), 'I4 logged out -> no banner' );

		// 1.8.0: the banner is deliberately not dismissible - it is the only
		// reminder that every listing is hidden.
		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 88;
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		ob_start(); $INST->maybe_print_banner(); $o = ob_get_clean();
		ok( false === strpos( $o, 'button' ) && false === stripos( $o, 'dismiss' ), 'I5 banner carries no dismiss control [1.8.0]' );

		// 1.8.0: %username% resolves to the vendor's display name.
		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 88;
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		$GLOBALS['_users'][10] = [ 'display_name' => 'Alice Vendor' ];
		$GLOBALS['_options']['hp_holiday_mode_for_hivepress_banner_label'] = 'Holiday Mode Enabled - %username% is away.';
		ob_start(); $INST->maybe_print_banner(); $o = ob_get_clean();
		ok( false !== strpos( $o, 'Alice Vendor is away.' ) && false === strpos( $o, '%username%' ), 'I6 banner %username% token replaced with the display name [1.8.0]' );
	}

	/* ===================== J. public vendor notice ===================== */
	if ( $VEN ) {
		echo "\n[J] public vendor profile notice\n";
		$mk_tpl = function ( $v ) {
			return new class( $v ) {
				private $v;
				public function __construct( $v ) { $this->v = $v; }
				public function get_context( $n = null, $d = null ) { return 'vendor' === $n ? $this->v : $d; }
			};
		};
		$mk_vendor = function ( $uid ) {
			return new class( $uid ) extends HivePress\Models\Vendor {
				private $uid;
				public function __construct( $uid ) { $this->uid = $uid; }
				public function get_user__id() { return $this->uid; }
			};
		};
		$base = [
			'page_content' => [
				'blocks' => [
					'page_title'         => [ 'type' => 'part', '_order' => 10 ],
					'listings_container' => [ 'type' => 'results', '_order' => 20, 'blocks' => [ 'listings' => [ 'type' => 'listings' ] ] ],
				],
			],
		];

		reset_state(); $GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		$out = $INST->add_vendor_notice_block( $base, $mk_tpl( $mk_vendor( 10 ) ) );
		$lc  = $out['page_content']['blocks']['listings_container'] ?? null;
		$pt  = $out['page_content']['blocks']['page_title'] ?? null;
		ok( is_array( $lc ) && 'callback' === $lc['type'] && function_exists( $lc['callback'] ), 'J1 listings_container swapped for callback' );
		ok( 20 === ( $lc['_order'] ?? null ), 'J2 container position preserved' );
		ok( [ 10 ] === ( $lc['params'] ?? [] ), 'J3 vendor user ID passed' );
		// 1.7.0: the "Listings by ..." heading is blanked too.
		ok( is_array( $pt ) && 'callback' === $pt['type'] && '__return_empty_string' === $pt['callback'], 'J4 page heading blanked [1.7.0]' );
		ok( function_exists( $pt['callback'] ), 'J5 heading callback is a real function (Callback block requires it)' );

		reset_state();
		$out = $INST->add_vendor_notice_block( $base, $mk_tpl( $mk_vendor( 10 ) ) );
		ok( 'results' === $out['page_content']['blocks']['listings_container']['type'] && 'part' === $out['page_content']['blocks']['page_title']['type'],
			'J6 holiday OFF -> template untouched' );

		reset_state(); $GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		ok( $INST->add_vendor_notice_block( $base, $mk_tpl( null ) ) === $base, 'J7 no vendor context -> unchanged' );
		ok( $INST->add_vendor_notice_block( $base, null ) === $base, 'J8 no template object -> unchanged' );
		ok( $INST->add_vendor_notice_block( $base, $mk_tpl( new stdClass() ) ) === $base, 'J9 non-Vendor context -> unchanged' );

		// Rendering.
		reset_state();
		$html = holiday_mode_for_hivepress_vendor_notice( 10 );
		ok( false !== stripos( $html, 'holiday' ), 'J10 renders the away notice' );
		ok( false !== strpos( $html, 'hp-holiday-notice' ) || false !== strpos( $html, 'holiday-mode-for-hivepress' ), 'J11 carries a plugin/HivePress class hook' );

		reset_state();
		$GLOBALS['_filters']['holiday_mode_for_hivepress_vendor_notice'] = [ [ 'cb' => function ( $n ) { return []; }, 'prio' => 10, 'args' => 1 ] ];
		ok( '' === holiday_mode_for_hivepress_vendor_notice( 10 ), 'J12 filter can remove the notice' );
		unset( $GLOBALS['_filters']['holiday_mode_for_hivepress_vendor_notice'] );

		reset_state();
		$GLOBALS['_filters']['holiday_mode_for_hivepress_vendor_notice'] = [ [ 'cb' => function ( $n ) { $n['title'] = '<script>x</script>'; return $n; }, 'prio' => 10, 'args' => 1 ] ];
		ok( false === strpos( holiday_mode_for_hivepress_vendor_notice( 10 ), '<script>' ), 'J13 notice output is escaped' );
		unset( $GLOBALS['_filters']['holiday_mode_for_hivepress_vendor_notice'] );
	}

	/* ===================== K. settings + upgrade sweep ===================== */
	echo "\n[K] settings, version, upgrade sweep\n";
	$hdr = [];
	preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', file_get_contents( HM_PLUGIN_FILE ), $hdr );
	ok( trim( $hdr[1] ) === HOLIDAY_MODE_FOR_HIVEPRESS_VERSION, 'K1 Version header matches version constant (drift guard)' );

	reset_state();
	$s   = $INST->add_settings( [] );
	$tab = $s['holiday_mode'] ?? null;
	ok( is_array( $tab ) && ! empty( $tab['title'] ) && isset( $tab['sections'] ), 'K2 settings tab registered' );
	ok( isset( $tab['sections']['holiday_mode_for_hivepress_removal'] ), 'K3 removal section present' );
	ok( isset( $tab['sections']['holiday_mode_for_hivepress_banner'] ), 'K4 banner section present [1.7.0]' );
	ok( isset( $tab['sections']['holiday_mode_for_hivepress_notice'] ), 'K5 profile notice section present [1.7.0]' );
	$gate = $tab['sections']['holiday_mode_for_hivepress_restore']['fields']['holiday_mode_for_hivepress_require_membership'] ?? null;
	// No 'default' key: HivePress writes config defaults into the database, so
	// a default of true would switch the gate on for everybody at first save.
	ok( is_array( $gate ) && 'checkbox' === $gate['type'] && ! array_key_exists( 'default', $gate ), 'K5b restore gate is an opt-in checkbox with no default [1.7.6]' );
	$icon = $tab['sections']['holiday_mode_for_hivepress_notice']['fields']['holiday_mode_for_hivepress_notice_icon'] ?? null;
	// 1.8.0: the field carries the resolved list itself (core's plus the
	// FA6/7 and brand additions) with the data-template attribute core's
	// resolver would have set, so the select2 previews keep working.
	ok( is_array( $icon ) && 'select' === $icon['type'] && is_array( $icon['options'] )
		&& isset( $icon['options']['anchor'], $icon['options']['sailboat'], $icon['options']['github'] )
		&& 'icon' === ( $icon['attributes']['data-template'] ?? null ),
		'K6 icon list keeps core icons, adds FA6/7 + brands, previews intact [1.8.0]' );
	$ref = new ReflectionClass( $INST );
	ok( 'hp_holiday_mode_for_hivepress_delete_data' === $ref->getConstant( 'DELETE_DATA_OPTION' ), 'K7 option constant matches hp\prefix()' );
	ok( 'hp_holiday_mode_for_hivepress_require_membership' === $ref->getConstant( 'REQUIRE_MEMBERSHIP_OPTION' ), 'K7b gate constant matches its hp\prefix()ed field key [1.7.6]' );
	$keep = $INST->add_settings( [ 'listings' => [ 'title' => 'Listings' ] ] );
	ok( isset( $keep['listings'] ) && isset( $keep['holiday_mode'] ), 'K8 preserves core tabs' );

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_version'] = '1.0';
	$GLOBALS['_wpdb_rows'] = [ 7 ];
	$INST->maybe_upgrade();
	ok( is_array( $GLOBALS['_wpdb_deleted'] )
		&& '_holiday_mode_for_hivepress' === ( $GLOBALS['_wpdb_deleted'][0] ?? null )
		&& '' === ( $GLOBALS['_wpdb_deleted'][1] ?? null ),
		'K9 sweep DELETE is value-matched to empty string (active flags survive)' );
	ok( in_array( 7, $GLOBALS['_cache_deleted'], true ), 'K10 user_meta cache invalidated for swept users' );
	ok( HOLIDAY_MODE_FOR_HIVEPRESS_VERSION === $GLOBALS['_options']['hp_holiday_mode_for_hivepress_version'], 'K11 stored version updated' );

	$GLOBALS['_wpdb_deleted'] = null;
	$INST->maybe_upgrade();
	ok( null === $GLOBALS['_wpdb_deleted'], 'K12 same version -> sweep does not re-run' );

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_version'] = '1.0';
	$GLOBALS['_wpdb_rows'] = []; $GLOBALS['_wpdb_deleted'] = null;
	$INST->maybe_upgrade();
	ok( null === $GLOBALS['_wpdb_deleted'], 'K13 no stale rows -> no DELETE issued' );

	/* ===================== L. notice customisation (1.7.0) ===================== */
	echo "\n[L] notice customisation\n";
	reset_state();
	$d = $INST->get_notice_args( 'notice' );
	ok( ! empty( $d['label'] ) && ! empty( $d['message'] ), 'L1 defaults supplied when nothing configured' );
	ok( isset( $d['label_color'], $d['text_color'], $d['icon_color'] ), 'L2 colour keys always present' );
	ok( $d['icon_color'] === $d['label_color'], 'L3 blank icon colour follows the label colour' );

	// Messages extension changes the standard wording.
	reset_state();
	$msg = $INST->get_notice_args( 'notice' )['message'];
	if ( $MSG ) {
		ok( false !== stripos( $msg, 'message' ), 'L4 Messages active -> wording mentions messaging [1.7.0]' );
	} else {
		ok( false === stripos( $msg, 'send them a message' ), 'L4 Messages absent -> no promise of messaging [1.7.0]' );
	}

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_label']       = 'Back soon';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_label_color'] = '#123456';
	$d = $INST->get_notice_args( 'notice' );
	ok( 'Back soon' === $d['label'], 'L5 configured label overrides the default' );
	ok( '#123456' === $d['label_color'] && '#123456' === $d['icon_color'], 'L6 configured colour applies, icon follows it' );

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_label'] = '   ';
	ok( ! empty( trim( $INST->get_notice_args( 'notice' )['label'] ) ), 'L7 blank setting falls back to the standard wording' );

	// Banner and notice contexts are independent.
	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_banner_label'] = 'Banner label';
	ok( 'Banner label' === $INST->get_notice_args( 'banner' )['label']
		&& 'Banner label' !== $INST->get_notice_args( 'notice' )['label'], 'L8 banner and notice settings are independent' );

	// Per-vendor away message (1.7.0).
	reset_state();
	$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress_headline'] = 'Gone fishing';
	$c = $INST->get_vendor_custom( 10 );
	ok( '' === $c['headline'], 'L9 vendor message ignored while the setting is off [1.7.0]' );

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_vendor_custom'] = 1;
	$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress_headline'] = '  Gone fishing  ';
	$c = $INST->get_vendor_custom( 10 );
	ok( 'Gone fishing' === $c['headline'] && '' === $c['message'], 'L10 vendor headline used and trimmed; blank message falls back' );

	if ( $VEN ) {
		reset_state();
		$GLOBALS['_options']['hp_holiday_mode_for_hivepress_vendor_custom'] = 1;
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress_headline'] = 'Gone fishing';
		$html = holiday_mode_for_hivepress_vendor_notice( 10 );
		ok( false !== strpos( $html, 'Gone fishing' ), 'L11 vendor headline appears on the profile [1.7.0]' );

		reset_state();
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress_headline'] = 'Gone fishing';
		ok( false === strpos( holiday_mode_for_hivepress_vendor_notice( 10 ), 'Gone fishing' ),
			'L12 unticking Vendor Messages returns every profile to the site-wide message [1.7.0]' );
	}

	// Background and derived border (1.7.1).
	reset_state();
	$d = $INST->get_notice_args( 'notice' );
	ok( isset( $d['bg_color'], $d['border_color'] ), 'L13 background and border keys present [1.7.1]' );
	ok( '#d9edf7' === $d['bg_color'] && '#bce8f1' === $d['border_color'], 'L14 standard background keeps the standard border pairing [1.7.1]' );

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_bg_color'] = '#ffffff';
	$d = $INST->get_notice_args( 'notice' );
	ok( '#ffffff' === $d['bg_color'] && '#e0e0e0' === $d['border_color'], 'L15 custom background shades its own border [1.7.1]' );

	// The border maths must always yield a valid 6-digit hex, including at
	// the extremes where rounding or padding could go wrong.
	reset_state();
	$edge_ok = true;
	foreach ( [ '#000000', '#ffffff', '#010101', '#ff0000', '#0a0b0c' ] as $bg ) {
		if ( ! preg_match( '/^#[0-9a-f]{6}$/', $INST->get_border_color( $bg ) ) ) { $edge_ok = false; }
	}
	ok( $edge_ok, 'L16 border is always a valid 6-digit hex (no short bytes) [1.7.1]' );
	ok( '#000000' === $INST->get_border_color( '#000000' ), 'L17 black background yields black border, not a negative value [1.7.1]' );
	ok( strcasecmp( $INST->get_border_color( '#D9EDF7' ), '#bce8f1' ) === 0, 'L18 standard-background match is case-insensitive [1.7.1]' );

	// Banner and notice backgrounds stay independent.
	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_banner_bg_color'] = '#ffffff';
	ok( '#ffffff' === $INST->get_notice_args( 'banner' )['bg_color']
		&& '#d9edf7' === $INST->get_notice_args( 'notice' )['bg_color'], 'L19 banner and notice backgrounds are independent [1.7.1]' );

	// A malformed stored value must fall back rather than reach the maths.
	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_bg_color'] = 'red';
	$d = $INST->get_notice_args( 'notice' );
	ok( '#d9edf7' === $d['bg_color'] && '#bce8f1' === $d['border_color'], 'L20 invalid stored colour falls back to the standard pair [1.7.1]' );

	if ( $VEN ) {
		reset_state();
		$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_bg_color'] = '#ffffff';
		$html = holiday_mode_for_hivepress_vendor_notice( 10 );
		ok( false !== strpos( $html, '#ffffff' ) && false !== strpos( $html, '#e0e0e0' ), 'L21 profile notice renders the chosen background and its border [1.7.1]' );

		// The filter can supply a background; anything malformed is rejected.
		reset_state();
		$GLOBALS['_filters']['holiday_mode_for_hivepress_vendor_notice'] = [ [ 'cb' => function ( $n ) { $n['bg_color'] = 'javascript:alert(1)'; return $n; }, 'prio' => 10, 'args' => 1 ] ];
		$html = holiday_mode_for_hivepress_vendor_notice( 10 );
		ok( false === strpos( $html, 'javascript' ), 'L22 malformed background from the filter is rejected [1.7.1]' );
		unset( $GLOBALS['_filters']['holiday_mode_for_hivepress_vendor_notice'] );
	}

	// The %username% token (1.8.0). Resolved at render time against the
	// vendor the notice is about, wherever the wording came from.
	reset_state();
	$GLOBALS['_users'][10] = [ 'display_name' => 'Alice Vendor' ];
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_message'] = '%username% is taking a break right now.';
	$html = holiday_mode_for_hivepress_vendor_notice( 10 );
	ok( false !== strpos( $html, 'Alice Vendor is taking a break right now.' ) && false === strpos( $html, '%username%' ),
		'L23 %username% in the notice message becomes the display name [1.8.0]' );

	reset_state();
	$GLOBALS['_users'][10] = [ 'display_name' => 'Alice Vendor' ];
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_label'] = 'About %username%';
	ok( false !== strpos( holiday_mode_for_hivepress_vendor_notice( 10 ), 'About Alice Vendor' ),
		'L24 %username% works in the notice label too [1.8.0]' );

	// A display name is user-supplied text and the token must not become an
	// injection point: the replacement happens before the output escaping.
	reset_state();
	$GLOBALS['_users'][10] = [ 'display_name' => '<script>x</script>' ];
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_label'] = 'About %username%';
	ok( false === strpos( holiday_mode_for_hivepress_vendor_notice( 10 ), '<script>' ),
		'L25 a display name carrying markup is escaped on output [1.8.0]' );

	// An unknown user resolves to an empty name rather than a leftover token.
	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_label'] = 'About %username%';
	ok( false === strpos( holiday_mode_for_hivepress_vendor_notice( 10 ), '%username%' ),
		'L26 no such user -> token removed, not printed [1.8.0]' );

	// The vendor's own away message supports the token as well, since the
	// replacement runs after every text source has been merged.
	reset_state();
	$GLOBALS['_users'][10] = [ 'display_name' => 'Alice Vendor' ];
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_vendor_custom'] = 1;
	$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress_headline'] = '%username% is fishing';
	ok( false !== strpos( holiday_mode_for_hivepress_vendor_notice( 10 ), 'Alice Vendor is fishing' ),
		'L27 %username% in a vendor-written message resolves too [1.8.0]' );

	/* ===================== M. audience + hide list (1.7.6) ===================== */
	echo "\n[M] who can use holiday mode, and what gets hidden\n";

	// --- the two new fields, and the reason neither carries a 'default' ---
	reset_state();
	$s      = $INST->add_settings( [] );
	$tab    = $s['holiday_mode'];
	$aud    = $tab['sections']['holiday_mode_for_hivepress_access']['fields']['holiday_mode_for_hivepress_audience'] ?? null;
	$aud_rl = $tab['sections']['holiday_mode_for_hivepress_access']['fields']['holiday_mode_for_hivepress_audience_roles'] ?? null;
	$hide   = $tab['sections']['holiday_mode_for_hivepress_hiding']['fields']['holiday_mode_for_hivepress_hideable_statuses'] ?? null;

	ok( is_array( $aud ) && 'select' === $aud['type'] && ! array_key_exists( 'default', $aud ), 'M1 audience is a select with no default [1.7.6]' );
	// The blank option must be named. Core prepends its own '&mdash;' placeholder
	// when the options array has no '' key, which would print an em-dash and make
	// the shipping behaviour read as "nothing chosen".
	ok( isset( $aud['options'][''] ) && '' !== trim( (string) $aud['options'][''] )
		&& false === strpos( (string) $aud['options'][''], "\u{2014}" ), 'M2 the default choice is a named option, not an em-dash placeholder [1.7.6]' );
	ok( isset( $aud['options']['vendors'], $aud['options']['roles'] ) && 3 === count( $aud['options'] ), 'M3 exactly three choices offered [1.7.6]' );
	ok( is_array( $aud_rl ) && 'checkboxes' === $aud_rl['type'] && ! array_key_exists( 'default', $aud_rl )
		&& isset( $aud_rl['options']['contributor'] ), 'M4 companion role list built from the site roles, no default [1.7.6]' );
	// Truthiness-only dependent fields (common.js) would show the role list for
	// "Vendors only" too, so it is deliberately absent. If HivePress ever gains
	// value matching, this is the assertion to revisit.
	ok( ! array_key_exists( '_parent', $aud_rl ), 'M5 role list does not use _parent, which cannot express a three-way select [1.7.6]' );
	ok( is_array( $hide ) && 'checkboxes' === $hide['type'] && ! array_key_exists( 'default', $hide )
		&& [ 'publish', 'pending', 'private', 'future' ] === array_keys( $hide['options'] ), 'M6 hide list offers all four statuses with no default [1.7.6]' );

	$ref = new ReflectionClass( $INST );
	ok( 'hp_holiday_mode_for_hivepress_audience' === $ref->getConstant( 'AUDIENCE_OPTION' )
		&& 'hp_holiday_mode_for_hivepress_audience_roles' === $ref->getConstant( 'AUDIENCE_ROLES_OPTION' )
		&& 'hp_holiday_mode_for_hivepress_hideable_statuses' === $ref->getConstant( 'HIDEABLE_OPTION' ), 'M7 new option constants match their hp\prefix()ed field keys [1.7.6]' );

	// --- defaults behave exactly as 1.7.5 did ---
	reset_state(); add_post( 800, 10, 'publish' );
	ok( 'listings' === call_priv( $INST, 'get_audience' ), 'M8 unset option reads as the old behaviour [1.7.6]' );
	ok( true === call_priv( $INST, 'is_user_vendor', [ 10 ] ), 'M9 default: listing author still counts [1.7.6]' );
	reset_state();
	ok( [ 'publish', 'pending', 'private', 'future' ] === call_priv( $INST, 'get_hideable_statuses' ), 'M10 unset hide list reads as all four [1.7.6]' );

	// --- vendors only ---
	reset_state(); add_post( 801, 12, 'publish' );
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience'] = 'vendors';
	ok( false === call_priv( $INST, 'is_user_vendor', [ 12 ] ), 'M11 vendors only: a listing author with no vendor profile is excluded [1.7.6]' );
	if ( $VEN ) {
		reset_state(); $GLOBALS['_vendor_first_id'] = 91;
		$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience'] = 'vendors';
		ok( true === call_priv( $INST, 'is_user_vendor', [ 12 ] ), 'M12 vendors only: a real vendor profile still counts [1.7.6]' );
	}

	// --- chosen roles ---
	reset_state(); add_post( 802, 13, 'publish' ); $GLOBALS['_vendor_first_id'] = 91;
	$GLOBALS['_users'][13] = [ 'ID' => 13, 'roles' => [ 'contributor' ] ];
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience']       = 'roles';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience_roles'] = [ 'contributor' ];
	ok( true === call_priv( $INST, 'is_user_vendor', [ 13 ] ), 'M13 chosen roles: a ticked role counts [1.7.6]' );

	reset_state(); add_post( 803, 14, 'publish' ); $GLOBALS['_vendor_first_id'] = 91;
	$GLOBALS['_users'][14] = [ 'ID' => 14, 'roles' => [ 'subscriber' ] ];
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience']       = 'roles';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience_roles'] = [ 'contributor' ];
	ok( false === call_priv( $INST, 'is_user_vendor', [ 14 ] ), 'M14 chosen roles: an unticked role does not count, listings and vendor profile ignored [1.7.6]' );

	reset_state(); $GLOBALS['_users'][15] = [ 'ID' => 15, 'roles' => [ 'contributor' ] ];
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience'] = 'roles';
	ok( false === call_priv( $INST, 'is_user_vendor', [ 15 ] ), 'M15 chosen roles with nothing ticked offers the switch to nobody [1.7.6]' );

	// --- the memoised cache must not answer for the wrong setting ---
	// Settings are saved and capabilities re-checked inside one request, so a
	// verdict reached under the old setting must not survive the save.
	reset_state(); add_post( 804, 16, 'publish' );
	ok( true === call_priv( $INST, 'is_user_vendor', [ 16 ] ), 'M16 cache warmed under the default [1.7.6]' );
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience'] = 'vendors';
	ok( false === call_priv( $INST, 'is_user_vendor', [ 16 ] ), 'M17 same user, same request, narrowed setting -> not served from cache [1.7.6]' );
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience']       = 'roles';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience_roles'] = [ 'contributor' ];
	$GLOBALS['_users'][16] = [ 'ID' => 16, 'roles' => [ 'contributor' ] ];
	ok( true === call_priv( $INST, 'is_user_vendor', [ 16 ] ), 'M18 and again when the roles list changes [1.7.6]' );

	// --- the public filter still has the last word, under every setting ---
	reset_state(); add_post( 805, 17, 'publish' );
	$GLOBALS['_filters']['holiday_mode_for_hivepress_is_vendor'] = [ [ 'cb' => function ( $v ) { return false; }, 'prio' => 10, 'args' => 1 ] ];
	ok( false === call_priv( $INST, 'is_user_vendor', [ 17 ] ), 'M19 is_vendor filter still overrides a yes [1.7.6]' );
	unset( $GLOBALS['_filters']['holiday_mode_for_hivepress_is_vendor'] );

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience'] = 'vendors';
	$GLOBALS['_filters']['holiday_mode_for_hivepress_is_vendor'] = [ [ 'cb' => function ( $v ) { return true; }, 'prio' => 10, 'args' => 1 ] ];
	ok( true === call_priv( $INST, 'is_user_vendor', [ 18 ] ), 'M20 is_vendor filter still overrides a no, even under "vendors only" [1.7.6]' );
	unset( $GLOBALS['_filters']['holiday_mode_for_hivepress_is_vendor'] );

	// --- a narrowed hide list hides fewer statuses ---
	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_hideable_statuses'] = [ 'publish' ];
	add_post( 810, 10, 'publish' ); add_post( 811, 10, 'pending' );
	add_post( 812, 10, 'private' ); add_post( 813, 10, 'future' );
	set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => true ] );
	$INST->apply_toggle( 10 );
	ok( 'draft' === get_post_status( 810 ), 'M21 narrowed list still hides the ticked status [1.7.6]' );
	ok( 'pending' === get_post_status( 811 ) && 'private' === get_post_status( 812 ) && 'future' === get_post_status( 813 ), 'M22 narrowed list leaves the unticked statuses alone [1.7.6]' );
	ok( '' === get_post_meta( 811, '_holiday_mode_for_hivepress_prev_status', true ), 'M23 an untouched listing gets no marker [1.7.6]' );

	// Enforcement follows the same narrowed list.
	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_hideable_statuses'] = [ 'publish' ];
	$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
	add_post( 820, 10, 'pending' ); $INST->enforce_draft_while_holiday( 820 );
	ok( 'pending' === get_post_status( 820 ), 'M24 mid-holiday enforcement respects the narrowed list [1.7.6]' );
	add_post( 821, 10, 'publish' ); $INST->enforce_draft_while_holiday( 821 );
	ok( 'draft' === get_post_status( 821 ), 'M25 and still hides what is ticked [1.7.6]' );

	// Unticking everything means all four, not nothing.
	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_hideable_statuses'] = [];
	ok( [ 'publish', 'pending', 'private', 'future' ] === call_priv( $INST, 'get_hideable_statuses' ), 'M26 an empty hide list reads as all four, never as "hide nothing" [1.7.6]' );
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_hideable_statuses'] = [ 'publish', 'nonsense', 'trash' ];
	ok( [ 'publish' ] === call_priv( $INST, 'get_hideable_statuses' ), 'M27 junk in the stored list is dropped, not hidden on [1.7.6]' );

	// --- THE STRANDING CASE ---
	// A vendor goes on holiday while all four statuses are ticked, the owner
	// then narrows the setting, and the vendor comes back. Every listing must
	// still be restored: the recorded status is what the listing had, and
	// bulk_restore() clears the marker whether it restores or not, so a listing
	// skipped here would be hidden for good with nothing left saying what it
	// used to be.
	reset_state();
	add_post( 830, 10, 'publish' ); add_post( 831, 10, 'pending' );
	add_post( 832, 10, 'private' ); add_post( 833, 10, 'future' );
	set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => true ] );
	$INST->apply_toggle( 10 );
	ok( 'draft' === get_post_status( 830 ) && 'draft' === get_post_status( 831 )
		&& 'draft' === get_post_status( 832 ) && 'draft' === get_post_status( 833 ), 'M28 all four hidden while the list was wide [1.7.6]' );

	// The owner narrows the setting to publish only, mid-holiday.
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_hideable_statuses'] = [ 'publish' ];

	set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => false ] );
	$INST->apply_toggle( 10 );
	ok( 'publish' === get_post_status( 830 ), 'M29 stranding: publish restored after the list was narrowed [1.7.6]' );
	ok( 'pending' === get_post_status( 831 ) && 'private' === get_post_status( 832 ) && 'future' === get_post_status( 833 ),
		'M30 stranding: pending, private and future restored too, though the setting no longer lists them [1.7.6]' );
	// The marker is cleared whether or not the listing was restored, which is
	// what makes stranding permanent rather than merely delayed: assert the
	// pair, so a regression cannot pass this by clearing markers on listings
	// it never brought back.
	ok( '' === get_post_meta( 831, '_holiday_mode_for_hivepress_prev_status', true ) && 'pending' === get_post_status( 831 ),
		'M31 stranding: no listing loses its marker without first coming back [1.7.6]' );

	// The restore-side check is a sanity check on the recorded value, not a
	// re-run of the hide list: junk must not be acted on.
	reset_state();
	add_post( 840, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'nonsense' ] );
	add_post( 841, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'trash' ] );
	add_post( 842, 10, 'draft', [ '_holiday_mode_for_hivepress_prev_status' => 'private' ] );
	$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
	set_priv( $INST, 'pending_toggle', [ 'user_id' => 10, 'enable' => false ] );
	$INST->apply_toggle( 10 );
	ok( 'draft' === get_post_status( 840 ) && 'draft' === get_post_status( 841 ), 'M32 an unreal or non-restorable recorded status is not acted on [1.7.6]' );
	ok( 'private' === get_post_status( 842 ), 'M33 a real recorded status still restores [1.7.6]' );

	// --- a settings row is writable by anything on the site ---
	// An importer, WP-CLI or another plugin can leave an array in a row that
	// the screen only ever writes a string into. get_audience() cast straight
	// to string, which on PHP 8 raises "Array to string conversion" once per
	// capability check - so on every account page load, for every vendor.
	$warned = false;
	set_error_handler(
		function ( $no, $str ) use ( &$warned ) {
			$warned = true;
			return true;
		}
	);

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience'] = [ 'roles' ];
	$junk_audience = call_priv( $INST, 'get_audience' );

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_audience_roles']    = 'not-an-array';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_hideable_statuses'] = 'not-an-array';
	$junk_roles = call_priv( $INST, 'get_audience_roles' );
	$junk_hide  = call_priv( $INST, 'get_hideable_statuses' );

	restore_error_handler();

	ok( 'listings' === $junk_audience && [] === $junk_roles
		&& [ 'publish', 'pending', 'private', 'future' ] === $junk_hide,
		'M34 a wrong-typed settings row reads as the pre-1.7.6 behaviour in all three readers [1.7.6]' );
	ok( ! $warned, 'M35 and raises no PHP warning on the way [1.7.6]' );

	/* ===================== N. icon options (1.8.0) ===================== */
	echo "\n[N] icon size, weight, Font Awesome 6/7 + brands\n";

	// Class mapping: brands live in their own font family, the FA6/7 solid
	// additions need the plugin stylesheet's fa-solid class, and everything
	// FA5-era keeps the fas class core's own stylesheet renders.
	reset_state();
	ok( 'fa-brands fa-github' === $INST->get_icon_class( 'github' ), 'N1 brand icon -> fa-brands [1.8.0]' );
	ok( 'fa-solid fa-sailboat' === $INST->get_icon_class( 'sailboat' ), 'N2 FA6/7 solid icon -> fa-solid [1.8.0]' );
	ok( 'fas fa-info-circle' === $INST->get_icon_class( 'info-circle' ), 'N3 FA5-era icon keeps fas [1.8.0]' );
	ok( $INST->icon_needs_fontawesome( 'github' ) && $INST->icon_needs_fontawesome( 'sailboat' )
		&& ! $INST->icon_needs_fontawesome( 'info-circle' ), 'N4 only added icons ask for the plugin stylesheet [1.8.0]' );

	// Size and weight defaults: the pre-1.8.0 hardcoded percentages survive.
	reset_state();
	$d = $INST->get_notice_args( 'notice' );
	$b = $INST->get_notice_args( 'banner' );
	ok( 150 === $d['icon_size'] && 130 === $b['icon_size'] && '' === $d['icon_weight'],
		'N5 defaults keep the pre-1.8.0 sizes and normal weight [1.8.0]' );

	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_icon_size']   = '220';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_icon_weight'] = 'bold';
	$d = $INST->get_notice_args( 'notice' );
	ok( 220 === $d['icon_size'] && 'bold' === $d['icon_weight'], 'N6 configured size and weight are read [1.8.0]' );

	// A cleared number field stores '' (the stored-empty trap), and junk of
	// any kind must fall back rather than reach a style attribute.
	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_icon_size']   = '';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_banner_icon_size']   = '9000';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_icon_weight'] = 'ultra';
	ok( 150 === $INST->get_notice_args( 'notice' )['icon_size']
		&& 130 === $INST->get_notice_args( 'banner' )['icon_size']
		&& '' === $INST->get_notice_args( 'notice' )['icon_weight'],
		'N7 blank, out-of-range and unknown stored values fall back [1.8.0]' );

	// The stroke is a fixed map in currentColor, so it composes with the
	// icon colour option by definition.
	ok( '' === $INST->get_icon_stroke_css( '' )
		&& '-webkit-text-stroke:0.3px currentColor;paint-order:stroke fill;' === $INST->get_icon_stroke_css( 'semibold' )
		&& '-webkit-text-stroke:0.5px currentColor;paint-order:stroke fill;' === $INST->get_icon_stroke_css( 'bold' ),
		'N8 weight maps to the two stroke widths, in currentColor [1.8.0]' );

	// Profile notice rendering: class, size, stroke and the stylesheet.
	reset_state();
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_icon']        = 'github';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_icon_size']   = '200';
	$GLOBALS['_options']['hp_holiday_mode_for_hivepress_notice_icon_weight'] = 'semibold';
	$html = holiday_mode_for_hivepress_vendor_notice( 10 );
	ok( false !== strpos( $html, 'fa-brands fa-github' ), 'N9 profile notice emits the brand class [1.8.0]' );
	ok( false !== strpos( $html, 'font-size:200%' ), 'N10 configured icon size rendered [1.8.0]' );
	ok( false !== strpos( $html, '-webkit-text-stroke:0.3px currentColor' ), 'N11 weight stroke rendered [1.8.0]' );
	ok( in_array( 'freestylr-fontawesome', $GLOBALS['_styles_enqueued'], true ), 'N12 brand icon enqueues the shared stylesheet [1.8.0]' );

	reset_state();
	$html = holiday_mode_for_hivepress_vendor_notice( 10 );
	ok( false !== strpos( $html, 'fas fa-info-circle' ) && false !== strpos( $html, 'font-size:150%' ),
		'N13 defaults render exactly as before 1.8.0 [1.8.0]' );
	ok( ! in_array( 'freestylr-fontawesome', $GLOBALS['_styles_enqueued'], true ),
		'N14 an FA5-era icon loads no extra stylesheet [1.8.0]' );

	// The filter can hand back junk for the new keys too.
	reset_state();
	$GLOBALS['_filters']['holiday_mode_for_hivepress_vendor_notice'] = [ [ 'cb' => function ( $n ) { $n['icon_size'] = 'expression(alert(1))'; $n['icon_weight'] = '";}'; return $n; }, 'prio' => 10, 'args' => 1 ] ];
	$html = holiday_mode_for_hivepress_vendor_notice( 10 );
	ok( false !== strpos( $html, 'font-size:150%' ) && false === strpos( $html, 'expression' ) && false === strpos( $html, 'text-stroke' ),
		'N15 junk size and weight from the filter fall back cleanly [1.8.0]' );
	unset( $GLOBALS['_filters']['holiday_mode_for_hivepress_vendor_notice'] );

	// Banner rendering and the early enqueue (the banner prints after the
	// late-styles cutoff, so its stylesheet decision runs at
	// wp_enqueue_scripts and must mirror the banner gates).
	if ( $VEN ) {
		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 88;
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		$GLOBALS['_options']['hp_holiday_mode_for_hivepress_banner_icon']        = 'github';
		$GLOBALS['_options']['hp_holiday_mode_for_hivepress_banner_icon_weight'] = 'bold';
		ob_start(); $INST->maybe_print_banner(); $o = ob_get_clean();
		ok( false !== strpos( $o, 'fa-brands fa-github' ) && false !== strpos( $o, '0.5px currentColor' ),
			'N16 banner emits the brand class and stroke [1.8.0]' );

		$INST->maybe_enqueue_fontawesome();
		ok( in_array( 'freestylr-fontawesome', $GLOBALS['_styles_enqueued'], true ),
			'N17 banner with a brand icon enqueues the stylesheet early [1.8.0]' );

		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 88;
		$GLOBALS['_usermeta'][10]['_holiday_mode_for_hivepress'] = true;
		$INST->maybe_enqueue_fontawesome();
		ok( ! in_array( 'freestylr-fontawesome', $GLOBALS['_styles_enqueued'], true ),
			'N18 default icon -> no early enqueue [1.8.0]' );

		reset_state(); $GLOBALS['_current_user_id'] = 10; $GLOBALS['_vendor_first_id'] = 88;
		$GLOBALS['_options']['hp_holiday_mode_for_hivepress_banner_icon'] = 'github';
		$INST->maybe_enqueue_fontawesome();
		ok( ! in_array( 'freestylr-fontawesome', $GLOBALS['_styles_enqueued'], true ),
			'N19 holiday off -> no early enqueue [1.8.0]' );
	}

	// The shared handle: registered only when nobody else got there first,
	// so one copy serves every plugin using it.
	reset_state();
	$INST->enqueue_fontawesome();
	ok( ! empty( $GLOBALS['_styles_registered']['freestylr-fontawesome'] )
		&& in_array( 'freestylr-fontawesome', $GLOBALS['_styles_enqueued'], true ),
		'N20 stylesheet registered and enqueued under the shared handle [1.8.0]' );

	reset_state();
	$GLOBALS['_styles_registered']['freestylr-fontawesome'] = 'someone-else';
	$INST->enqueue_fontawesome();
	ok( 'someone-else' === $GLOBALS['_styles_registered']['freestylr-fontawesome']
		&& in_array( 'freestylr-fontawesome', $GLOBALS['_styles_enqueued'], true ),
		'N21 an already-registered shared handle is enqueued, not re-registered [1.8.0]' );

	echo "\n----------------------------------------\n";
	echo "RESULT: {$GLOBALS['_pass']} passed, {$GLOBALS['_fail']} failed\n";
	exit( $GLOBALS['_fail'] > 0 ? 1 : 0 );
}
