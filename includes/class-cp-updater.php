<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click updates from GitHub releases.
 *
 * Point Settings at a public GitHub repository that holds this plugin's
 * code. When a release with a higher version tag (e.g. v1.0.2) exists,
 * WordPress shows it under Dashboard -> Updates like any other plugin.
 *
 * Preferred: attach a file named candidate-portal.zip to each release.
 * Fallback: the release's auto-generated source zip is used, and the
 * extracted folder is renamed so the plugin stays in place.
 */
class CP_Updater {

	const CACHE_KEY = 'cp_update_check';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'noop' ) ); // marker only
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details_popup' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 3 );
	}

	public static function noop( $v ) {
		return $v;
	}

	private static function repo() {
		return array(
			'owner' => get_option( 'cp_upd_owner', '' ),
			'repo'  => get_option( 'cp_upd_repo', '' ),
		);
	}

	public static function enabled() {
		$r = self::repo();
		return $r['owner'] && $r['repo'];
	}

	private static function plugin_file() {
		return plugin_basename( CP_PLUGIN_DIR . 'candidate-portal.php' );
	}

	/** Fetch the latest release from GitHub, cached for 6 hours. */
	public static function latest_release( $force = false ) {
		if ( ! self::enabled() ) {
			return null;
		}
		$cached = get_transient( self::CACHE_KEY );
		if ( ! $force && false !== $cached ) {
			return $cached ? $cached : null; // '' means "checked, nothing found"
		}

		$r        = self::repo();
		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $r['owner'] ), rawurlencode( $r['repo'] ) );
		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'candidate-portal-wp' ),
		) );

		$release = null;
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $body['tag_name'] ) ) {
				$package = ! empty( $body['zipball_url'] ) ? $body['zipball_url'] : '';
				if ( ! empty( $body['assets'] ) ) {
					foreach ( $body['assets'] as $asset ) {
						if ( 'candidate-portal.zip' === $asset['name'] && ! empty( $asset['browser_download_url'] ) ) {
							$package = $asset['browser_download_url'];
							break;
						}
					}
				}
				$release = array(
					'version' => ltrim( $body['tag_name'], 'vV' ),
					'package' => $package,
					'url'     => ! empty( $body['html_url'] ) ? $body['html_url'] : '',
					'notes'   => ! empty( $body['body'] ) ? $body['body'] : '',
				);
			}
		}

		set_transient( self::CACHE_KEY, $release ? $release : '', 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/** Tell WordPress when a newer release exists. */
	public static function check( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::latest_release();
		if ( ! $release || ! $release['package'] ) {
			return $transient;
		}
		if ( version_compare( $release['version'], CP_VERSION, '>' ) ) {
			$item = (object) array(
				'slug'        => 'candidate-portal',
				'plugin'      => self::plugin_file(),
				'new_version' => $release['version'],
				'url'         => $release['url'],
				'package'     => $release['package'],
			);
			$transient->response[ self::plugin_file() ] = $item;
		}
		return $transient;
	}

	/** "View details" popup content. */
	public static function details_popup( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'candidate-portal' !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Candidate Portal',
			'slug'          => 'candidate-portal',
			'version'       => $release['version'],
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>Candidate self-service portal for party elections.</p>',
				'changelog'   => '<pre>' . esc_html( $release['notes'] ) . '</pre>',
			),
		);
	}

	/**
	 * GitHub's auto-generated source zips extract to a folder like
	 * owner-repo-abc1234. Rename it so the plugin keeps its folder.
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader ) {
		if ( ! is_string( $source ) || false === strpos( basename( $source ), 'candidate-portal' ) ) {
			// Could be a source zip with a hashed name - only touch our own update.
			$plugin = isset( $upgrader->skin->plugin ) ? $upgrader->skin->plugin : '';
			if ( false === strpos( $plugin, 'candidate-portal' ) ) {
				return $source;
			}
		}
		// If the extracted folder contains the repo at top level with our
		// plugin folder inside, or is misnamed, normalize to candidate-portal.
		$target = trailingslashit( dirname( $source ) ) . 'candidate-portal/';
		if ( untrailingslashit( $source ) === untrailingslashit( $target ) ) {
			return $source;
		}
		// If the zip wrapped everything in a repo-named folder that itself
		// contains candidate-portal/, use the inner folder.
		if ( is_dir( trailingslashit( $source ) . 'candidate-portal' ) ) {
			return trailingslashit( $source ) . 'candidate-portal/';
		}
		if ( is_file( trailingslashit( $source ) . 'candidate-portal.php' ) ) {
			global $wp_filesystem;
			if ( $wp_filesystem && $wp_filesystem->move( $source, $target ) ) {
				return $target;
			}
		}
		return $source;
	}
}
