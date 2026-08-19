<?php
/**
 * GitHub release updater.
 *
 * The plugin is distributed via GitHub releases rather than wp.org, so
 * update checks go through the native `update_plugins_{$hostname}` API
 * introduced in WordPress 5.8, keyed off the Update URI header in the main
 * plugin file. The update package is the release asset named `*.zip`, which
 * must contain a single `holiday-mode-for-hivepress` directory.
 *
 * @package Holiday_Mode_For_HivePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Hphm_Updater' ) ) :

	/**
	 * Serves GitHub releases to the WordPress update system.
	 */
	final class Hphm_Updater {

		/**
		 * Cached release payload.
		 */
		const CACHE_KEY = 'holiday_mode_for_hivepress_release';

		/**
		 * Why the last release check came back empty, so the notice can say which.
		 */
		const REASON_KEY = 'holiday_mode_for_hivepress_release_reason';

		/**
		 * When GitHub's hourly allowance for this server is expected back. While this is set the
		 * API is not called at all, so a site that has run out does not spend the rest of the
		 * window making requests that can only fail.
		 */
		const RATE_LIMIT_KEY = 'holiday_mode_for_hivepress_release_rate_limit';

		/**
		 * Query arg used by the manual "Check for updates" link.
		 */
		const CHECK_ARG = 'holiday_mode_check_updates';

		/**
		 * Query arg carrying the manual check result.
		 */
		const RESULT_ARG = 'holiday_mode_checked';

		/**
		 * Absolute path to the main plugin file.
		 *
		 * @var string
		 */
		private $file;

		/**
		 * Plugin basename, e.g. holiday-mode-for-hivepress/holiday-mode-for-hivepress.php.
		 *
		 * @var string
		 */
		private $basename;

		/**
		 * Plugin slug (directory name).
		 *
		 * @var string
		 */
		private $slug;

		/**
		 * GitHub repository in owner/name form.
		 *
		 * @var string
		 */
		private $repo;

		/**
		 * Constructor.
		 *
		 * @param string $file Main plugin file.
		 * @param string $repo GitHub repository (owner/name).
		 */
		public function __construct( $file, $repo ) {
			$this->file     = $file;
			$this->basename = plugin_basename( $file );
			$this->slug     = dirname( $this->basename );
			$this->repo     = $repo;

			add_filter( 'update_plugins_github.com', [ $this, 'check_for_update' ], 10, 3 );
			add_filter( 'plugins_api', [ $this, 'get_plugin_information' ], 10, 3 );
			add_filter( 'plugin_action_links_' . $this->basename, [ $this, 'add_settings_link' ] );
			add_filter( 'plugin_action_links_' . $this->basename, [ $this, 'add_update_check_link' ] );
			add_filter( 'network_admin_plugin_action_links_' . $this->basename, [ $this, 'add_update_check_link' ] );
			add_filter( 'upgrader_source_selection', [ $this, 'fix_update_directory' ], 10, 4 );
			add_action( 'admin_init', [ $this, 'handle_update_check' ] );
			add_action( 'admin_notices', [ $this, 'show_update_check_notice' ] );
			add_action( 'network_admin_notices', [ $this, 'show_update_check_notice' ] );
		}

		/**
		 * Gets the installed plugin version from the file header.
		 *
		 * Reading the header keeps the version in a single place, so releasing
		 * only requires bumping the `Version:` line.
		 *
		 * @return string
		 */
		public function get_version() {
			static $version = null;

			if ( null === $version ) {
				$data    = get_file_data( $this->file, [ 'Version' => 'Version' ] );
				$version = $data['Version'];
			}

			return $version;
		}

		/* ---------------- Remote lookup ---------------- */

		/**
		 * Gets the latest GitHub release details, cached for 6 hours.
		 *
		 * @param bool $force Bypass the cache.
		 * @return array<string, string>|null
		 */
		public function get_latest_release( $force = false ) {
			$cached = get_site_transient( self::CACHE_KEY );

			if ( ! $force && is_array( $cached ) ) {
				return $cached ? $cached : null;
			}

			$release = $this->fetch_latest_release();

			// A failed check must not erase what the last good one found. Overwriting the cache with an
			// empty result took a genuinely pending update off the Plugins screen for an hour with nothing
			// to say why, which is worse than showing a result that is at most a few hours old. The short
			// lifetime means the next check still tries again promptly.

			if ( ! $release && $cached ) {
				set_site_transient( self::CACHE_KEY, $cached, HOUR_IN_SECONDS );

				return $cached;
			}

			// Failures are cached briefly so the lookup is not repeated on every admin page load.
			set_site_transient( self::CACHE_KEY, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

			return $release ? $release : null;
		}

		/**
		 * Fetches the latest release details from the GitHub API.
		 *
		 * Draft and pre-release entries are excluded by the endpoint itself, so
		 * publishing a pre-release never triggers an update notice.
		 *
		 * @return array<string, string>
		 */
		private function fetch_latest_release() {
			$data = $this->fetch_release_data();

			if ( ! is_array( $data ) ) {
				return [];
			}

			// The version is read from the release tag, with or without a "v" prefix.
			$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );

			if ( ! $version ) {
				return [];
			}

			// The update package is the first release asset named `*.zip`.
			$package = '';

			foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : [] ) as $asset ) {
				$name = strtolower( (string) ( isset( $asset['name'] ) ? $asset['name'] : '' ) );

				if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
					$package = (string) $asset['browser_download_url'];

					break;
				}
			}

			if ( ! $package ) {
				return [];
			}

			return [
				'version'   => $version,
				'package'   => $package,
				'url'       => (string) ( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . $this->repo ),
				'notes'     => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
				'published' => (string) ( isset( $data['published_at'] ) ? $data['published_at'] : '' ),
			];
		}

		/**
		 * Gets the latest release, from github.com in preference to the GitHub API.
		 *
		 * WHY THIS DOES NOT SIMPLY CALL THE API
		 *
		 * Without a token `api.github.com` allows **60 requests an hour per IP address**, and that
		 * allowance is shared by every plugin on the site, by every other site on the same server, and by
		 * anything else calling the API from that address. A site running several of these extensions,
		 * plus a few clicks of "Check for updates" - which deliberately bypasses the cache - spends it
		 * easily; on shared hosting a neighbouring site can spend it alone. GitHub then answers 403, and
		 * reporting that as "could not reach GitHub" sends the owner hunting a network fault that does not
		 * exist. That is the same family of bug as reporting a 404 as unreachable: a refusal is an answer,
		 * not a failure to get one.
		 *
		 * Everything this lookup needs is also published on github.com itself, which carries no such
		 * allowance:
		 *
		 *   - `/releases/latest` answers 302, and the Location header names the release GitHub considers
		 *     latest, with drafts and pre-releases excluded exactly as the API excludes them;
		 *   - `/releases/expanded_assets/{tag}` is the fragment the release page uses to list its own
		 *     downloads, so it names the asset;
		 *   - `/releases.atom` carries the release notes.
		 *
		 * Measured against GitHub's own rate-limit counter on 2026-08-19, thirteen full update checks
		 * through this route moved it by zero. The API is kept as a fallback so that a change at github.com
		 * cannot leave the plugin with no way to check at all.
		 *
		 * @return array<string, mixed>|null Release data in the API's own shape, or null.
		 */
		private function fetch_release_data() {
			$site = $this->fetch_release_from_site();

			if ( isset( $site['release'] ) ) {
				delete_site_transient( self::REASON_KEY );

				return $site['release'];
			}

			// github.com has given a definite answer that nothing is published. Asking the API would only
			// repeat it, at the cost of one of the sixty.
			if ( isset( $site['reason'] ) && 'no_release' === $site['reason'] ) {
				set_site_transient( self::REASON_KEY, 'no_release', HOUR_IN_SECONDS );

				return null;
			}

			return $this->fetch_release_from_api();
		}

		/**
		 * Reads the latest release from github.com, without touching the API allowance.
		 *
		 * @return array<string, mixed> Either a `release` in the API's shape, a `reason`, or empty to fall
		 *                              back to the API.
		 */
		private function fetch_release_from_site() {
			$base = 'https://github.com/' . $this->repo;

			$response = $this->request(
				$base . '/releases/latest',
				[
					// Do not follow it. The redirect target is the answer.
					'redirection' => 0,
				]
			);

			if ( ! $response ) {
				return [];
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			// A repository with nothing published answers 404 here, which is the normal state of a new
			// repository rather than a fault.
			if ( 404 === $code ) {
				return [ 'reason' => 'no_release' ];
			}

			if ( 301 !== $code && 302 !== $code ) {
				return [];
			}

			$location = wp_remote_retrieve_header( $response, 'location' );

			// WordPress hands back an array when a header repeats.
			if ( is_array( $location ) ) {
				$location = end( $location );
			}

			if ( ! preg_match( '#/releases/tag/(.+)$#', (string) $location, $matches ) ) {
				return [];
			}

			$tag = rawurldecode( trim( $matches[1] ) );

			$asset = $this->fetch_release_asset( $base, $tag );

			// No downloadable asset means there is nothing the updater could install, so let the API have
			// its say rather than reporting a release that cannot be applied.
			if ( ! $asset ) {
				return [];
			}

			$notes = $this->fetch_release_notes( $base, $tag );

			// Shaped exactly like the API's own answer, so everything downstream is identical either way.
			return [
				'release' => [
					'tag_name'     => $tag,
					'html_url'     => $base . '/releases/tag/' . rawurlencode( $tag ),
					'body'         => $notes['body'],
					'published_at' => $notes['published'],
					'assets'       => [
						[
							'name'                 => $asset['name'],
							'browser_download_url' => $asset['url'],
						],
					],
				],
			];
		}

		/**
		 * Reads a release's asset from the fragment the release page uses to list its own downloads.
		 *
		 * @param string $base Repository URL.
		 * @param string $tag Release tag.
		 * @return array<string, string>|null
		 */
		private function fetch_release_asset( $base, $tag ) {
			$response = $this->request( $base . '/releases/expanded_assets/' . rawurlencode( $tag ) );

			if ( ! $response || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			if ( ! preg_match_all( '#href="(/[^"]*/releases/download/[^"]+\.zip)"#i', wp_remote_retrieve_body( $response ), $matches ) ) {
				return null;
			}

			// Take the first zip, matching what the API branch does with the assets list.
			$path = html_entity_decode( $matches[1][0], ENT_QUOTES, 'UTF-8' );

			return [
				'name' => rawurldecode( basename( $path ) ),
				'url'  => 'https://github.com' . $path,
			];
		}

		/**
		 * Reads a release's notes and publication date from the releases feed.
		 *
		 * Only the changelog in the plugin details popup depends on this, so a failure here is not fatal.
		 *
		 * @param string $base Repository URL.
		 * @param string $tag Release tag.
		 * @return array<string, string>
		 */
		private function fetch_release_notes( $base, $tag ) {
			$empty = [
				'body'      => '',
				'published' => '',
			];

			$response = $this->request( $base . '/releases.atom' );

			if ( ! $response || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return $empty;
			}

			if ( ! preg_match_all( '#<entry>(.*?)</entry>#s', wp_remote_retrieve_body( $response ), $entries ) ) {
				return $empty;
			}

			foreach ( $entries[1] as $entry ) {

				// Match the tag rather than taking the newest entry: the feed also carries pre-releases,
				// which the latest-release redirect deliberately skips.
				if ( false === strpos( $entry, '/releases/tag/' . $tag ) ) {
					continue;
				}

				$notes = '';

				if ( preg_match( '#<content[^>]*>(.*?)</content>#s', $entry, $content ) ) {
					$notes = $this->release_notes_to_text( $content[1] );
				}

				$published = '';

				if ( preg_match( '#<updated>(.*?)</updated>#s', $entry, $updated ) ) {
					$published = trim( $updated[1] );
				}

				return [
					'body'      => $notes,
					'published' => $published,
				];
			}

			return $empty;
		}

		/**
		 * Turns the rendered notes in the feed back into the plain text the API would have returned.
		 *
		 * The API hands back the release body as it was written, in Markdown, and the details popup prints
		 * that as text. The feed carries the rendered HTML instead, so headings, bold runs and list items
		 * are put back into their Markdown spelling to keep the popup reading the same either way.
		 *
		 * @param string $html Rendered notes.
		 * @return string
		 */
		private function release_notes_to_text( $html ) {
			$text = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

			$text = preg_replace( '#<h[1-6][^>]*>(.*?)</h[1-6]>#is', "\n**$1**\n", $text );
			$text = preg_replace( '#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', $text );
			$text = preg_replace( '#<(em|i)[^>]*>(.*?)</\1>#is', '*$2*', $text );
			$text = preg_replace( '#<li[^>]*>#i', "\n- ", $text );
			$text = preg_replace( '#</(p|div|ul|ol|li|pre|blockquote)>#i', "\n", $text );
			$text = preg_replace( '#<br\s*/?>#i', "\n", $text );

			$text = wp_strip_all_tags( (string) $text );

			// Collapse the blank lines the substitutions leave behind.
			$text = preg_replace( '#\n{3,}#', "\n\n", (string) $text );

			return trim( (string) $text );
		}

		/**
		 * Reads the latest release from the GitHub API.
		 *
		 * Kept as a fallback only. See `fetch_release_data()` for why it is not the first choice.
		 *
		 * @return array<string, mixed>|null
		 */
		private function fetch_release_from_api() {

			// GitHub has already said the allowance is spent, so sit the window out rather than spending it
			// on requests that can only be refused.
			if ( get_site_transient( self::RATE_LIMIT_KEY ) ) {
				set_site_transient( self::REASON_KEY, 'rate_limited', HOUR_IN_SECONDS );

				return null;
			}

			$response = wp_remote_get(
				'https://api.github.com/repos/' . $this->repo . '/releases/latest',
				[
					'timeout'    => 10,
					'headers'    => [ 'Accept' => 'application/vnd.github+json' ],

					// Our own User-Agent, because WordPress's default is "WordPress/{version}; {site url}"
					// (wp-includes/class-wp-http.php:211) and that puts the site's address and its exact
					// WordPress version into every release check. GitHub only requires that the header
					// identifies something, so this satisfies it while telling them nothing about the site.
					'user-agent' => 'holiday-mode-for-hivepress/' . $this->get_version(),
				]
			);

			if ( is_wp_error( $response ) ) {
				set_site_transient( self::REASON_KEY, 'unreachable', HOUR_IN_SECONDS );

				return null;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				$reason = 404 === $code ? 'no_release' : 'unreachable';

				// A 403 or 429 with nothing left on the counter means this server's hourly allowance is
				// spent. Nothing is wrong with the site, the plugin or the repository, so it must not be
				// reported as though something were.
				if ( ( 403 === $code || 429 === $code ) && '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
					$reason = 'rate_limited';
					$reset  = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
					$wait   = $reset > time() ? min( $reset - time(), HOUR_IN_SECONDS ) : 5 * MINUTE_IN_SECONDS;

					set_site_transient( self::RATE_LIMIT_KEY, $reset ? $reset : time() + $wait, $wait );
				}

				set_site_transient( self::REASON_KEY, $reason, HOUR_IN_SECONDS );

				return null;
			}

			delete_site_transient( self::RATE_LIMIT_KEY );
			delete_site_transient( self::REASON_KEY );

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			return is_array( $data ) ? $data : null;
		}

		/**
		 * Makes a request to github.com.
		 *
		 * The User-Agent is set for the same reason as in the API branch: WordPress's default would put the
		 * site's address and its exact WordPress version into every check.
		 *
		 * @param string               $url Request URL.
		 * @param array<string, mixed> $args Extra request arguments.
		 * @return array<string, mixed>|null
		 */
		private function request( $url, $args = [] ) {
			$response = wp_remote_get(
				$url,
				array_merge(
					[
						'timeout'    => 10,
						'headers'    => [ 'Accept' => 'text/html, application/xml;q=0.9, */*;q=0.8' ],
						'user-agent' => 'holiday-mode-for-hivepress/' . $this->get_version(),
					],
					$args
				)
			);

			return is_wp_error( $response ) ? null : $response;
		}

		/* ---------------- Update wiring ---------------- */

		/**
		 * Provides the update details to the WordPress update system.
		 *
		 * WordPress matches the plugin to this filter via the Update URI header
		 * hostname and compares the versions itself, filing the result under
		 * either the available updates or the up-to-date list.
		 *
		 * @param array<string, mixed>|false $update      Update data.
		 * @param array<string, string>      $plugin_data Plugin headers.
		 * @param string                     $plugin_file Plugin basename.
		 * @return array<string, mixed>|false
		 */
		public function check_for_update( $update, $plugin_data, $plugin_file ) {
			if ( $this->basename !== $plugin_file ) {
				return $update;
			}

			$release = $this->get_latest_release();

			if ( ! $release ) {
				return $update;
			}

			return [
				'id'      => 'https://github.com/' . $this->repo,
				'slug'    => $this->slug,
				'plugin'  => $plugin_file,
				'version' => $release['version'],
				'url'     => $release['url'],
				'package' => $release['package'],
			];
		}

		/**
		 * Provides the plugin details for the update information popup.
		 *
		 * Without this the "View version x.x.x details" link on the Plugins
		 * screen would open an empty modal, since the plugin is not on wp.org.
		 *
		 * @param object|array|false $result Result object.
		 * @param string             $action API action.
		 * @param object             $args   API arguments.
		 * @return object|array|false
		 */
		public function get_plugin_information( $result, $action, $args ) {
			$slug = isset( $args->slug ) ? $args->slug : '';

			if ( 'plugin_information' !== $action || ! is_object( $args ) || $slug !== $this->slug ) {
				return $result;
			}

			$release = $this->get_latest_release();

			if ( ! $release ) {
				return $result;
			}

			$plugin_data = get_file_data(
				$this->file,
				[
					'Name'        => 'Plugin Name',
					'Description' => 'Description',
					'Author'      => 'Author',
					'AuthorURI'   => 'Author URI',
					'RequiresWP'  => 'Requires at least',
					'RequiresPHP' => 'Requires PHP',
				]
			);

			return (object) [
				'name'          => $plugin_data['Name'],
				'slug'          => $this->slug,
				'version'       => $release['version'],
				'author'        => '<a href="' . esc_url( $plugin_data['AuthorURI'] ) . '">' . esc_html( $plugin_data['Author'] ) . '</a>',
				'homepage'      => 'https://github.com/' . $this->repo,
				'requires'      => $plugin_data['RequiresWP'],
				'requires_php'  => $plugin_data['RequiresPHP'],
				'last_updated'  => $release['published'],
				'download_link' => $release['package'],
				'donate_link'   => HPHM_SUPPORT_URL,
				'sections'      => [
					'description' => wpautop( esc_html( $plugin_data['Description'] ) ),
					'changelog'   => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>' . esc_html__( 'See the GitHub releases page for the changelog.', 'holiday-mode-for-hivepress' ) . '</p>',
				],
			];
		}

		/* ---------------- Manual check ---------------- */

		/**
		 * Adds the settings link to the plugin row.
		 *
		 * Prepended rather than appended, so it sits where WordPress users
		 * expect to find it.
		 *
		 * @param array<string> $links Plugin action links.
		 * @return array<string>
		 */
		public function add_settings_link( $links ) {
			if ( current_user_can( 'manage_options' ) && function_exists( 'hivepress' ) ) {
				array_unshift(
					$links,
					'<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=holiday_mode' ) ) . '">' . esc_html__( 'Settings', 'holiday-mode-for-hivepress' ) . '</a>'
				);
			}

			return $links;
		}

		/**
		 * Adds the manual update check link to the plugin row.
		 *
		 * @param array<string> $links Plugin action links.
		 * @return array<string>
		 */
		public function add_update_check_link( $links ) {
			if ( current_user_can( 'update_plugins' ) ) {
				$links[] = '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?' . self::CHECK_ARG . '=1' ), self::CHECK_ARG ) ) . '">' . esc_html__( 'Check for updates', 'holiday-mode-for-hivepress' ) . '</a>';
			}

			return $links;
		}

		/**
		 * Handles the manual update check.
		 *
		 * Refreshes the cached release, re-runs the update check and redirects
		 * back to the Plugins screen with the result.
		 *
		 * @return void
		 */
		public function handle_update_check() {
			if ( ! isset( $_GET[ self::CHECK_ARG ] ) || ! current_user_can( 'update_plugins' ) ) {
				return;
			}

			check_admin_referer( self::CHECK_ARG );

			$release = $this->get_latest_release( true );

			wp_clean_plugins_cache();
			wp_update_plugins();

			// Read why the lookup ended as it did rather than inferring it from the result. Since a failed
			// check now keeps the last good answer, the presence of a release no longer proves the check
			// itself succeeded, and reporting a stale answer as a fresh one would be a lie.
			$reason = get_site_transient( self::REASON_KEY );

			if ( 'no_release' === $reason ) {
				$status = 'empty';
			} elseif ( 'rate_limited' === $reason ) {
				$status = 'limited';
			} elseif ( 'unreachable' === $reason ) {
				$status = 'error';
			} elseif ( $release && version_compare( $release['version'], $this->get_version(), '>' ) ) {
				$status = 'available';
			} else {
				$status = 'none';
			}

			wp_safe_redirect( add_query_arg( self::RESULT_ARG, $status, self_admin_url( 'plugins.php' ) ) );

			exit;
		}

		/**
		 * Shows the manual update check result.
		 *
		 * @return void
		 */
		public function show_update_check_notice() {
			if ( ! isset( $_GET[ self::RESULT_ARG ] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			$status = sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( 'available' === $status ) {
				$release = $this->get_latest_release();

				/* translators: %s: new version number. */
				$message = sprintf( __( 'A new version of Holiday Mode for HivePress (%s) is available.', 'holiday-mode-for-hivepress' ), $release ? $release['version'] : '' );
				$class   = 'notice-success';
			} elseif ( 'none' === $status ) {
				$message = __( 'Holiday Mode for HivePress is up to date.', 'holiday-mode-for-hivepress' );
				$class   = 'notice-success';
			} elseif ( 'empty' === $status ) {
				$message = __( 'No releases have been published for Holiday Mode for HivePress yet, so there is nothing to update to. This is normal for a brand new copy and does not mean anything is wrong.', 'holiday-mode-for-hivepress' );
				$class   = 'notice-info';
			} elseif ( 'limited' === $status ) {
				$message = __( 'GitHub limits how many update checks one server may make each hour, and this server has reached that limit. Nothing is wrong with the plugin or your site, and checking will work again within the hour.', 'holiday-mode-for-hivepress' );
				$class   = 'notice-warning';
			} elseif ( 'error' === $status ) {
				$message = __( 'Could not reach GitHub to check for updates. Please try again later.', 'holiday-mode-for-hivepress' );
				$class   = 'notice-error';
			} else {
				return;
			}

			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		/* ---------------- Install location ---------------- */

		/**
		 * Keeps updates installing into the current plugin directory.
		 *
		 * The extracted release folder is renamed to match the directory the
		 * plugin is installed in, so an update can never end up in a differently
		 * named folder even if the release zip is packaged unexpectedly.
		 *
		 * @param string               $source        Extracted update source.
		 * @param string               $remote_source Remote source directory.
		 * @param object               $upgrader      Upgrader instance.
		 * @param array<string, mixed> $hook_extra    Extra hook arguments.
		 * @return string|WP_Error
		 */
		public function fix_update_directory( $source, $remote_source, $upgrader, $hook_extra = [] ) {
			global $wp_filesystem;

			$plugin = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';

			if ( $plugin !== $this->basename || ! $wp_filesystem ) {
				return $source;
			}

			$directory = dirname( $this->basename );

			if ( '.' === $directory ) {
				return $source;
			}

			$target = trailingslashit( $remote_source ) . $directory . '/';

			if ( trailingslashit( $source ) === $target ) {
				return $source;
			}

			if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) ) ) {
				return new WP_Error( 'holiday_mode_rename_failed', __( 'Could not rename the update directory.', 'holiday-mode-for-hivepress' ) );
			}

			return $target;
		}
	}

endif;
