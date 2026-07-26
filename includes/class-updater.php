<?php
/**
 * GitHub release updater.
 *
 * Lets the plugin update itself from GitHub releases, so a single stable
 * repository link can be shared and users always receive the latest version
 * through the normal WordPress update flow.
 *
 * @package Holiday_Mode_For_HivePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Holiday_Mode_For_HivePress_Updater' ) ) :

	/**
	 * Checks GitHub releases and feeds updates into WordPress.
	 */
	final class Holiday_Mode_For_HivePress_Updater {

		/**
		 * Cached release payload.
		 */
		const TRANSIENT = 'holiday_mode_for_hivepress_release';

		/**
		 * How long a successful lookup is cached.
		 */
		const CACHE_TTL = 43200; // 12 hours.

		/**
		 * How long a failed lookup is cached (avoids hammering the API).
		 */
		const CACHE_TTL_FAIL = 3600; // 1 hour.

		/**
		 * Query arg used by the "Check for updates" link.
		 */
		const CHECK_ARG = 'holiday_mode_check_update';

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
		 * Plugin directory slug.
		 *
		 * @var string
		 */
		private $slug;

		/**
		 * Installed version.
		 *
		 * @var string
		 */
		private $version;

		/**
		 * GitHub repository in owner/name form.
		 *
		 * @var string
		 */
		private $repo;

		/**
		 * Constructor.
		 *
		 * @param string $file    Main plugin file.
		 * @param string $version Installed version.
		 * @param string $repo    GitHub repository (owner/name).
		 */
		public function __construct( $file, $version, $repo ) {
			$this->file     = $file;
			$this->basename = plugin_basename( $file );
			$this->slug     = dirname( $this->basename );
			$this->version  = $version;
			$this->repo     = $repo;

			add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
			add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
			add_filter( 'plugin_action_links_' . $this->basename, [ $this, 'action_links' ] );
			add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
			add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 0 );
			add_action( 'admin_init', [ $this, 'handle_manual_check' ] );
			add_action( 'admin_notices', [ $this, 'manual_check_notice' ] );
		}

		/* ---------------- Remote lookup ---------------- */

		/**
		 * Returns the latest release data, cached.
		 *
		 * @param bool $force Bypass the cache.
		 * @return array|false Release data, or false when unavailable.
		 */
		private function get_release( $force = false ) {
			if ( ! $force ) {
				$cached = get_site_transient( self::TRANSIENT );
				if ( is_array( $cached ) ) {
					return $cached;
				}
				if ( 'none' === $cached ) {
					return false;
				}
			}

			$response = wp_remote_get(
				'https://api.github.com/repos/' . $this->repo . '/releases/latest',
				[
					'timeout' => 10,
					'headers' => [
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'holiday-mode-for-hivepress/' . $this->version,
					],
				]
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				set_site_transient( self::TRANSIENT, 'none', self::CACHE_TTL_FAIL );
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
				set_site_transient( self::TRANSIENT, 'none', self::CACHE_TTL_FAIL );
				return false;
			}

			$release = [
				'version'      => ltrim( (string) $body['tag_name'], 'vV' ),
				'download_url' => $this->pick_download_url( $body ),
				'url'          => ! empty( $body['html_url'] ) ? $body['html_url'] : 'https://github.com/' . $this->repo,
				'published_at' => ! empty( $body['published_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $body['published_at'] ) ) : '',
				'body'         => ! empty( $body['body'] ) ? (string) $body['body'] : '',
			];

			if ( empty( $release['download_url'] ) ) {
				set_site_transient( self::TRANSIENT, 'none', self::CACHE_TTL_FAIL );
				return false;
			}

			set_site_transient( self::TRANSIENT, $release, self::CACHE_TTL );

			return $release;
		}

		/**
		 * Chooses the best download URL: a packaged .zip asset if one was
		 * attached to the release, otherwise GitHub's generated source archive.
		 *
		 * @param array $body Decoded release payload.
		 * @return string
		 */
		private function pick_download_url( $body ) {
			$fallback = '';

			if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
				foreach ( $body['assets'] as $asset ) {
					if ( empty( $asset['browser_download_url'] ) ) {
						continue;
					}
					$name = isset( $asset['name'] ) ? strtolower( $asset['name'] ) : '';
					if ( substr( $name, -4 ) !== '.zip' ) {
						continue;
					}
					// Prefer an asset named exactly after the plugin slug.
					if ( $this->slug . '.zip' === $name ) {
						return $asset['browser_download_url'];
					}
					if ( ! $fallback ) {
						$fallback = $asset['browser_download_url'];
					}
				}
			}

			if ( $fallback ) {
				return $fallback;
			}

			return ! empty( $body['zipball_url'] ) ? $body['zipball_url'] : '';
		}

		/* ---------------- Update wiring ---------------- */

		/**
		 * Adds our release to the plugin update transient.
		 *
		 * @param mixed $transient Update transient.
		 * @return mixed
		 */
		public function inject_update( $transient ) {
			if ( ! is_object( $transient ) ) {
				$transient = new stdClass();
			}

			$release = $this->get_release();
			if ( ! $release ) {
				return $transient;
			}

			$item = (object) [
				'id'            => 'github.com/' . $this->repo,
				'slug'          => $this->slug,
				'plugin'        => $this->basename,
				'new_version'   => $release['version'],
				'url'           => $release['url'],
				'package'       => $release['download_url'],
				'requires'      => '6.0',
				'requires_php'  => '7.4',
				'icons'         => [],
				'banners'       => [],
				'banners_rtl'   => [],
				'compatibility' => new stdClass(),
			];

			if ( version_compare( $release['version'], $this->version, '>' ) ) {
				$transient->response[ $this->basename ] = $item;
				unset( $transient->no_update[ $this->basename ] );
			} else {
				$transient->no_update[ $this->basename ] = $item;
				unset( $transient->response[ $this->basename ] );
			}

			return $transient;
		}

		/**
		 * Supplies data for the plugin details modal.
		 *
		 * @param mixed  $result Response object or false.
		 * @param string $action The API action being performed.
		 * @param object $args   API arguments.
		 * @return mixed
		 */
		public function plugin_info( $result, $action, $args ) {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}
			if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
				return $result;
			}

			$release = $this->get_release();
			if ( ! $release ) {
				return $result;
			}

			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$data = get_plugin_data( $this->file, false, false );

			return (object) [
				'name'           => ! empty( $data['Name'] ) ? $data['Name'] : 'Holiday Mode for HivePress',
				'slug'           => $this->slug,
				'version'        => $release['version'],
				'author'         => ! empty( $data['Author'] ) ? $data['Author'] : '',
				'homepage'       => 'https://github.com/' . $this->repo,
				'download_link'  => $release['download_url'],
				'trunk'          => $release['download_url'],
				'requires'       => '6.0',
				'requires_php'   => '7.4',
				'last_updated'   => $release['published_at'],
				'sections'       => [
					'description' => ! empty( $data['Description'] ) ? wpautop( wp_kses_post( $data['Description'] ) ) : '',
					'changelog'   => $this->render_notes( $release['body'] ),
				],
				'external'       => true,
				'banners'        => [],
			];
		}

		/**
		 * Renders GitHub release notes as safe HTML.
		 *
		 * @param string $notes Raw release notes.
		 * @return string
		 */
		private function render_notes( $notes ) {
			if ( '' === trim( (string) $notes ) ) {
				return esc_html__( 'See the GitHub releases page for details.', 'holiday-mode-for-hivepress' );
			}
			return wpautop( esc_html( $notes ) );
		}

		/**
		 * Ensures the extracted folder is named after the plugin slug, so an
		 * update replaces the existing install instead of creating a new one.
		 *
		 * @param string $source        Extracted source directory.
		 * @param string $remote_source Working directory.
		 * @param object $upgrader      Upgrader instance.
		 * @param array  $hook_extra    Extra arguments.
		 * @return string|WP_Error
		 */
		public function fix_source_dir( $source, $remote_source, $upgrader = null, $hook_extra = [] ) {
			if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
				return $source;
			}

			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				return $source;
			}

			$desired = trailingslashit( $remote_source ) . $this->slug;

			if ( untrailingslashit( $source ) === $desired ) {
				return $source;
			}

			if ( $wp_filesystem->exists( $desired ) ) {
				$wp_filesystem->delete( $desired, true );
			}

			if ( $wp_filesystem->move( untrailingslashit( $source ), $desired ) ) {
				return trailingslashit( $desired );
			}

			return $source;
		}

		/**
		 * Clears the cached release after any upgrade completes.
		 *
		 * @return void
		 */
		public function clear_cache() {
			delete_site_transient( self::TRANSIENT );
		}

		/* ---------------- Manual check ---------------- */

		/**
		 * Adds a "Check for updates" link to the plugin row.
		 *
		 * @param array $links Existing action links.
		 * @return array
		 */
		public function action_links( $links ) {
			if ( ! current_user_can( 'update_plugins' ) ) {
				return $links;
			}

			$url = wp_nonce_url(
				add_query_arg( self::CHECK_ARG, '1', self_admin_url( 'plugins.php' ) ),
				self::CHECK_ARG,
				'_hmnonce'
			);

			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'holiday-mode-for-hivepress' ) . '</a>';

			return $links;
		}

		/**
		 * Handles the manual update check.
		 *
		 * @return void
		 */
		public function handle_manual_check() {
			if ( ! isset( $_GET[ self::CHECK_ARG ] ) ) {
				return;
			}
			if ( ! current_user_can( 'update_plugins' ) ) {
				return;
			}
			$nonce = isset( $_GET['_hmnonce'] ) ? sanitize_key( wp_unslash( $_GET['_hmnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::CHECK_ARG ) ) {
				return;
			}

			delete_site_transient( self::TRANSIENT );

			$release   = $this->get_release( true );
			$has_update = $release && version_compare( $release['version'], $this->version, '>' );

			// Force WordPress to rebuild its update data too.
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();

			$args = [ 'holiday_mode_checked' => $has_update ? '1' : '0' ];
			if ( ! $release ) {
				$args['holiday_mode_checked'] = 'error';
			} elseif ( $has_update ) {
				$args['holiday_mode_version'] = rawurlencode( $release['version'] );
			}

			wp_safe_redirect( add_query_arg( $args, self_admin_url( 'plugins.php' ) ) );
			exit;
		}

		/**
		 * Shows the result of a manual update check.
		 *
		 * @return void
		 */
		public function manual_check_notice() {
			if ( ! isset( $_GET['holiday_mode_checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			if ( ! current_user_can( 'update_plugins' ) ) {
				return;
			}

			$state = sanitize_key( wp_unslash( $_GET['holiday_mode_checked'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( 'error' === $state ) {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Holiday Mode for HivePress: could not reach GitHub to check for updates. Please try again later.', 'holiday-mode-for-hivepress' );
				echo '</p></div>';
				return;
			}

			if ( '1' === $state ) {
				$version = isset( $_GET['holiday_mode_version'] ) ? sanitize_text_field( wp_unslash( $_GET['holiday_mode_version'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<div class="notice notice-warning is-dismissible"><p>';
				printf(
					/* translators: %s: the new version number. */
					esc_html__( 'Holiday Mode for HivePress: version %s is available. Use the update link on the plugin row to install it.', 'holiday-mode-for-hivepress' ),
					esc_html( $version )
				);
				echo '</p></div>';
				return;
			}

			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %s: the installed version number. */
				esc_html__( 'Holiday Mode for HivePress is up to date (version %s).', 'holiday-mode-for-hivepress' ),
				esc_html( $this->version )
			);
			echo '</p></div>';
		}
	}

endif;
