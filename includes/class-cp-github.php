<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional GitHub versioning. Every save pushes plain JSON files (and photos)
 * to a repo, giving a full who-changed-what history and an off-site backup.
 * The live site never depends on GitHub - if sync fails, WordPress still works.
 */
class CP_GitHub {

	public static function init() {
		add_action( 'cp_candidate_saved', array( __CLASS__, 'sync_candidate' ), 10, 1 );
		add_action( 'cp_data_changed', array( __CLASS__, 'sync_reference_data' ) );
	}

	public static function settings() {
		return array(
			'token'  => get_option( 'cp_gh_token', '' ),
			'owner'  => get_option( 'cp_gh_owner', '' ),
			'repo'   => get_option( 'cp_gh_repo', '' ),
			'branch' => get_option( 'cp_gh_branch', 'main' ),
		);
	}

	public static function enabled() {
		$s = self::settings();
		return $s['token'] && $s['owner'] && $s['repo'];
	}

	/** Create or update one file in the repo. Returns true|WP_Error. */
	public static function put_file( $path, $content_raw, $message ) {
		$s = self::settings();
		if ( ! self::enabled() ) {
			return new WP_Error( 'cp_gh_off', 'GitHub sync is not configured.' );
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/contents/%s',
			rawurlencode( $s['owner'] ),
			rawurlencode( $s['repo'] ),
			str_replace( '%2F', '/', rawurlencode( $path ) )
		);

		$headers = array(
			'Authorization' => 'Bearer ' . $s['token'],
			'Accept'        => 'application/vnd.github+json',
			'User-Agent'    => 'candidate-portal-wp',
		);

		// Updating an existing file requires its current sha.
		$sha      = '';
		$existing = wp_remote_get( add_query_arg( 'ref', $s['branch'], $url ), array( 'headers' => $headers, 'timeout' => 15 ) );
		if ( ! is_wp_error( $existing ) && 200 === wp_remote_retrieve_response_code( $existing ) ) {
			$body = json_decode( wp_remote_retrieve_body( $existing ), true );
			$sha  = isset( $body['sha'] ) ? $body['sha'] : '';
		}

		$payload = array(
			'message' => $message,
			'content' => base64_encode( $content_raw ),
			'branch'  => $s['branch'],
		);
		if ( $sha ) {
			$payload['sha'] = $sha;
		}

		$response = wp_remote_request( $url, array(
			'method'  => 'PUT',
			'headers' => $headers,
			'body'    => wp_json_encode( $payload ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = isset( $body['message'] ) ? $body['message'] : ( 'HTTP ' . $code );
			return new WP_Error( 'cp_gh_http', 'GitHub: ' . $msg );
		}
		return true;
	}

	/** Push one candidate's profile (JSON + photo) to the repo. */
	public static function sync_candidate( $post_id ) {
		if ( ! self::enabled() ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'cp_candidate' !== $post->post_type ) {
			return;
		}

		$election_ids   = array_map( 'intval', (array) get_post_meta( $post_id, '_cp_elections', true ) );
		$election_slugs = array();
		foreach ( $election_ids as $eid ) {
			$e = get_post( $eid );
			if ( $e ) {
				$election_slugs[] = $e->post_name;
			}
		}

		$photo_repo_path = '';
		$thumb_id        = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			$file = get_attached_file( $thumb_id );
			if ( $file && file_exists( $file ) ) {
				$photo_repo_path = 'photos/' . $post_id . '-' . sanitize_file_name( basename( $file ) );
				self::put_file( $photo_repo_path, file_get_contents( $file ), 'Update photo: ' . $post->post_title );
			}
		}

		$data = array(
			'id'         => $post_id,
			'first_name' => get_post_meta( $post_id, '_cp_first_name', true ),
			'last_name'  => get_post_meta( $post_id, '_cp_last_name', true ),
			'bio'        => $post->post_content,
			'website'    => get_post_meta( $post_id, '_cp_website', true ),
			'facebook'   => get_post_meta( $post_id, '_cp_facebook', true ),
			'twitter'    => get_post_meta( $post_id, '_cp_twitter', true ),
			'instagram'  => get_post_meta( $post_id, '_cp_instagram', true ),
			'elections'  => $election_slugs,
			'photo'      => $photo_repo_path,
			'updated'    => current_time( 'mysql' ),
			'updated_by' => wp_get_current_user()->user_login,
		);

		$path = 'candidates/' . $post_id . '-' . sanitize_title( $post->post_title ) . '.json';
		$result = self::put_file( $path, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), 'Update profile: ' . $post->post_title );
		update_option( 'cp_gh_last_result', is_wp_error( $result ) ? $result->get_error_message() : 'OK ' . current_time( 'mysql' ), false );
	}

	/** Push elections + alphabets as reference JSON. */
	public static function sync_reference_data() {
		if ( ! self::enabled() ) {
			return;
		}

		$elections = array();
		foreach ( get_posts( array( 'post_type' => 'cp_election', 'posts_per_page' => -1, 'post_status' => 'publish' ) ) as $e ) {
			$elections[] = array(
				'slug'     => $e->post_name,
				'name'     => $e->post_title,
				'alphabet' => get_post_meta( $e->ID, '_cp_alphabet_id', true ),
			);
		}
		self::put_file( 'data/elections.json', wp_json_encode( $elections, JSON_PRETTY_PRINT ), 'Update elections' );
		self::put_file( 'data/alphabets.json', wp_json_encode( array_values( CP_Alphabets::all() ), JSON_PRETTY_PRINT ), 'Update alphabets' );
	}

	/** Full push of everything - used by the settings-page button. */
	public static function sync_all() {
		self::sync_reference_data();
		foreach ( get_posts( array( 'post_type' => 'cp_candidate', 'posts_per_page' => -1, 'post_status' => array( 'publish', 'draft' ) ) ) as $c ) {
			self::sync_candidate( $c->ID );
		}
	}
}
