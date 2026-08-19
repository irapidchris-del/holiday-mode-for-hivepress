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
			$release = $force ? false : get_site_transient( self::CACHE_KEY );

			if ( ! is_array( $release ) ) {
				$release = $this->fetch_latest_release();

				// Failures are cached briefly so the API is not queried repeatedly.
				set_site_transient( self::CACHE_KEY, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
			}

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
			// Set the user agent explicitly. Left out, WordPress fills in
			// "WordPress/{version}; {site url}" (wp-includes/class-wp-http.php:211),
			// which would tell GitHub the site's address and its exact
			// WordPress version on every check. GitHub only requires the header
			// to identify something, so the plugin name and version satisfy it
			// while sending nothing about the site.
			$response = wp_remote_get(
				'https://api.github.com/repos/' . $this->repo . '/releases/latest',
				[
					'timeout'    => 10,
					'user-agent' => 'holiday-mode-for-hivepress/' . $this->get_version(),

					'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
				]
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return [];
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

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

			$status = 'none';

			if ( ! $release ) {
				$status = 'error';
			} elseif ( version_compare( $release['version'], $this->get_version(), '>' ) ) {
				$status = 'available';
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
