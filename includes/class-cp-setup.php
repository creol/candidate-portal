<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers data structures and the candidate role, and keeps
 * candidates out of the WordPress dashboard entirely.
 */
class CP_Setup {

	const ROLE = 'cp_candidate';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'admin_init', array( __CLASS__, 'block_dashboard_for_candidates' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar_for_candidates' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'candidate_login_redirect' ), 10, 3 );
	}

	public static function activate() {
		self::register_post_types();

		// Candidate role: can log in and read, nothing else. All editing
		// happens through the front-end portal, never wp-admin.
		add_role( self::ROLE, __( 'Candidate', 'candidate-portal' ), array( 'read' => true ) );

		// Seed a default alphabet (A=1 ... Z=26) if none exist yet.
		if ( ! get_option( 'cp_alphabets' ) ) {
			$letters = array();
			foreach ( range( 'A', 'Z' ) as $i => $l ) {
				$letters[ $l ] = $i + 1;
			}
			$default = array(
				'id'      => 'standard',
				'name'    => 'Standard A-Z',
				'start'   => '',
				'end'     => '',
				'letters' => $letters,
			);
			update_option( 'cp_alphabets', array( 'standard' => $default ), false );
		}

		flush_rewrite_rules();
	}

	public static function register_post_types() {
		// Elections. No public UI of their own; rendered via shortcode.
		register_post_type( 'cp_election', array(
			'labels'       => array( 'name' => __( 'Elections', 'candidate-portal' ), 'singular_name' => __( 'Election', 'candidate-portal' ) ),
			'public'       => false,
			'show_ui'      => false,
			'supports'     => array( 'title' ),
			'hierarchical' => false,
		) );

		// Events: gatherings that hold one or more elections.
		register_post_type( 'cp_event', array(
			'labels'       => array( 'name' => __( 'Election Events', 'candidate-portal' ), 'singular_name' => __( 'Election Event', 'candidate-portal' ) ),
			'public'       => false,
			'show_ui'      => false,
			'supports'     => array( 'title', 'thumbnail' ),
			'hierarchical' => false,
		) );

		// Candidates. One post per person, reusable across elections.
		register_post_type( 'cp_candidate', array(
			'labels'       => array( 'name' => __( 'Candidates', 'candidate-portal' ), 'singular_name' => __( 'Candidate', 'candidate-portal' ) ),
			'public'       => false,
			'show_ui'      => false,
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'hierarchical' => false,
		) );
	}

	/** True if the given (or current) user is a candidate and not an admin. */
	public static function is_candidate( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
	}

	/** Candidates never see wp-admin; send them to the portal page. */
	public static function block_dashboard_for_candidates() {
		if ( self::is_candidate() && ! wp_doing_ajax() ) {
			wp_safe_redirect( self::portal_url() );
			exit;
		}
	}

	public static function hide_admin_bar_for_candidates( $show ) {
		return self::is_candidate() ? false : $show;
	}

	public static function candidate_login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && self::is_candidate( $user ) ) {
			return self::portal_url();
		}
		return $redirect_to;
	}

	/** URL of the page containing the [candidate_portal] shortcode. */
	public static function portal_url() {
		$page_id = (int) get_option( 'cp_portal_page_id' );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ? $url : home_url( '/' );
	}

	/** Candidate post belonging to a WP user, or null. */
	public static function candidate_post_for_user( $user_id ) {
		$posts = get_posts( array(
			'post_type'      => 'cp_candidate',
			'posts_per_page' => 1,
			'post_status'    => array( 'publish', 'draft' ),
			'meta_key'       => '_cp_user_id',
			'meta_value'     => (int) $user_id,
		) );
		return $posts ? $posts[0] : null;
	}
}
