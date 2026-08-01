<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens: Candidates / Elections / Events / Alphabets / Settings.
 */
class CP_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_cp_admin_action', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( false !== strpos( $hook, 'cp-' ) || false !== strpos( $hook, 'candidate-portal' ) ) {
			wp_enqueue_style( 'cp-admin', CP_PLUGIN_URL . 'assets/cp-admin.css', array(), CP_VERSION );
			wp_enqueue_script( 'cp-admin', CP_PLUGIN_URL . 'assets/cp-admin.js', array(), CP_VERSION, true );
			wp_enqueue_style( 'cp-cropper', CP_PLUGIN_URL . 'assets/cropper.min.css', array(), '1.6.2' );
			wp_enqueue_style( 'cp-crop-ui', CP_PLUGIN_URL . 'assets/cp-crop.css', array(), CP_VERSION );
			wp_enqueue_script( 'cp-cropper', CP_PLUGIN_URL . 'assets/cropper.min.js', array(), '1.6.2', true );
			wp_enqueue_script( 'cp-crop', CP_PLUGIN_URL . 'assets/cp-crop.js', array( 'cp-cropper' ), CP_VERSION, true );
		}
	}

	public static function menu() {
		add_menu_page( __( 'Candidate Portal', 'candidate-portal' ), __( 'Candidate Portal', 'candidate-portal' ), 'manage_options', 'cp-candidates', array( __CLASS__, 'page_candidates' ), 'dashicons-groups', 26 );
		add_submenu_page( 'cp-candidates', __( 'Candidates', 'candidate-portal' ), __( 'Candidates', 'candidate-portal' ), 'manage_options', 'cp-candidates', array( __CLASS__, 'page_candidates' ) );
		add_submenu_page( 'cp-candidates', __( 'Elections', 'candidate-portal' ), __( 'Elections', 'candidate-portal' ), 'manage_options', 'cp-elections', array( __CLASS__, 'page_elections' ) );
		add_submenu_page( 'cp-candidates', __( 'Events', 'candidate-portal' ), __( 'Events', 'candidate-portal' ), 'manage_options', 'cp-events', array( __CLASS__, 'page_events' ) );
		add_submenu_page( 'cp-candidates', __( 'Alphabets', 'candidate-portal' ), __( 'Alphabets', 'candidate-portal' ), 'manage_options', 'cp-alphabets', array( __CLASS__, 'page_alphabets' ) );
		add_submenu_page( 'cp-candidates', __( 'Settings', 'candidate-portal' ), __( 'Settings', 'candidate-portal' ), 'manage_options', 'cp-settings', array( __CLASS__, 'page_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Form handling                                                      */
	/* ------------------------------------------------------------------ */

	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'cp_admin_action' );

		$task   = isset( $_POST['task'] ) ? sanitize_key( $_POST['task'] ) : '';
		$notice = '';
		$back   = 'cp-candidates';

		switch ( $task ) {

			case 'add_candidate':
				$notice = self::task_add_candidate();
				break;

			case 'update_candidate':
				$notice = self::task_update_candidate();
				break;

			case 'delete_candidate':
				$post_id = (int) $_POST['candidate_id'];
				$user_id = (int) get_post_meta( $post_id, '_cp_user_id', true );
				wp_trash_post( $post_id );
				$user = $user_id ? get_user_by( 'id', $user_id ) : null;
				if ( $user && in_array( CP_Setup::ROLE, (array) $user->roles, true ) && 1 === count( (array) $user->roles ) ) {
					// Only delete accounts that exist purely as candidates.
					wp_delete_user( $user_id );
					$notice = 'Candidate removed and their login deleted.';
				} else {
					if ( $user ) {
						$user->remove_role( CP_Setup::ROLE );
					}
					$notice = 'Candidate profile removed. Their existing user account was kept.';
				}
				break;

			case 'resend_invite':
				$notice = self::send_invite( (int) $_POST['user_id'] ) ? 'Invitation email sent again.' : 'Could not send the email.';
				break;

			case 'save_election':
				$id    = isset( $_POST['election_id'] ) ? (int) $_POST['election_id'] : 0;
				$title = sanitize_text_field( wp_unslash( $_POST['election_name'] ) );
				$args  = array( 'post_type' => 'cp_election', 'post_title' => $title, 'post_status' => 'publish' );
				if ( $id ) {
					$args['ID'] = $id;
					$id = wp_update_post( $args );
				} else {
					$id = wp_insert_post( $args );
				}
				update_post_meta( $id, '_cp_alphabet_id', sanitize_key( $_POST['alphabet_id'] ) );
				update_post_meta( $id, '_cp_election_date', sanitize_text_field( $_POST['election_date'] ) );
				update_post_meta( $id, '_cp_event_id', (int) $_POST['event_id'] );

				update_post_meta( $id, '_cp_disc_required', empty( $_POST['disc_required'] ) ? '0' : '1' );
				update_post_meta( $id, '_cp_disc_text', wp_kses( wp_unslash( $_POST['disc_text'] ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ), 'strong' => array(), 'em' => array(), 'br' => array() ) ) );

				self::save_docs( $id, '_cp_election_docs' );

				// Candidate assignment checkboxes: checked = in this election.
				if ( isset( $_POST['candidates_present'] ) ) {
					$checked = array_map( 'intval', isset( $_POST['assigned_candidates'] ) ? (array) $_POST['assigned_candidates'] : array() );
					$all     = get_posts( array( 'post_type' => 'cp_candidate', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) );
					foreach ( $all as $cid ) {
						$current = array_map( 'intval', (array) get_post_meta( $cid, '_cp_elections', true ) );
						$in_now  = in_array( $id, $current, true );
						$should  = in_array( $cid, $checked, true );
						if ( $should && ! $in_now ) {
							$current[] = $id;
							update_post_meta( $cid, '_cp_elections', array_values( array_unique( $current ) ) );
							do_action( 'cp_candidate_saved', $cid );
						} elseif ( ! $should && $in_now ) {
							update_post_meta( $cid, '_cp_elections', array_values( array_diff( $current, array( $id ) ) ) );
							do_action( 'cp_candidate_saved', $cid );
						}
					}
				}

				do_action( 'cp_data_changed' );
				$notice = 'Election saved.';
				$back   = 'cp-elections';
				break;

			case 'delete_election':
				wp_trash_post( (int) $_POST['election_id'] );
				do_action( 'cp_data_changed' );
				$notice = 'Election removed. Candidates were not deleted.';
				$back   = 'cp-elections';
				break;

			case 'save_event':
				$notice = self::task_save_event();
				$back   = 'cp-events';
				break;

			case 'delete_event':
				wp_trash_post( (int) $_POST['event_id'] );
				do_action( 'cp_data_changed' );
				$notice = 'Event removed. Its elections and page were kept.';
				$back   = 'cp-events';
				break;

			case 'save_alphabet':
				$letters = array();
				foreach ( range( 'A', 'Z' ) as $l ) {
					$v = isset( $_POST[ 'letter_' . $l ] ) ? (int) $_POST[ 'letter_' . $l ] : 0;
					$letters[ $l ] = max( 1, min( 26, $v ) );
				}
				CP_Alphabets::save( array(
					'id'      => isset( $_POST['alphabet_id'] ) ? sanitize_key( $_POST['alphabet_id'] ) : '',
					'name'    => sanitize_text_field( wp_unslash( $_POST['alphabet_name'] ) ),
					'start'   => sanitize_text_field( $_POST['alphabet_start'] ),
					'end'     => sanitize_text_field( $_POST['alphabet_end'] ),
					'letters' => $letters,
				) );
				do_action( 'cp_data_changed' );
				$notice = 'Alphabet saved.';
				$back   = 'cp-alphabets';
				break;

			case 'delete_alphabet':
				CP_Alphabets::delete( sanitize_key( $_POST['alphabet_id'] ) );
				do_action( 'cp_data_changed' );
				$notice = 'Alphabet removed.';
				$back   = 'cp-alphabets';
				break;

			case 'save_settings':
				update_option( 'cp_portal_page_id', (int) $_POST['portal_page_id'] );
				update_option( 'cp_gh_owner', sanitize_text_field( $_POST['gh_owner'] ) );
				update_option( 'cp_gh_repo', sanitize_text_field( $_POST['gh_repo'] ) );
				update_option( 'cp_gh_branch', sanitize_text_field( $_POST['gh_branch'] ) ? sanitize_text_field( $_POST['gh_branch'] ) : 'main' );
				if ( ! empty( $_POST['gh_token'] ) ) {
					update_option( 'cp_gh_token', trim( sanitize_text_field( $_POST['gh_token'] ) ) );
				}
				update_option( 'cp_upd_owner', sanitize_text_field( $_POST['upd_owner'] ) );
				update_option( 'cp_upd_repo', sanitize_text_field( $_POST['upd_repo'] ) );
				delete_transient( CP_Updater::CACHE_KEY );
				$notice = 'Settings saved.';
				$back   = 'cp-settings';
				break;

			case 'check_updates':
				delete_transient( CP_Updater::CACHE_KEY );
				$release = CP_Updater::latest_release( true );
				if ( ! $release ) {
					$notice = 'Could not find any release in that repository yet.';
				} elseif ( version_compare( $release['version'], CP_VERSION, '>' ) ) {
					$notice = 'Version ' . $release['version'] . ' is available! Go to Dashboard > Updates to install it.';
					wp_update_plugins();
				} else {
					$notice = 'You are up to date (version ' . CP_VERSION . ').';
				}
				$back = 'cp-settings';
				break;

			case 'sync_all':
				CP_GitHub::sync_all();
				$notice = 'Everything was pushed to GitHub. Last result: ' . esc_html( get_option( 'cp_gh_last_result', '' ) );
				$back   = 'cp-settings';
				break;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => $back, 'cp_notice' => rawurlencode( $notice ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function task_add_candidate() {
		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ) );

		if ( ! $first || ! $last || ! is_email( $email ) ) {
			return 'Please fill in first name, last name, and a valid email.';
		}

		$existing_id = email_exists( $email );

		if ( $existing_id ) {
			// Existing site user (admin, editor, past candidate...) - reuse
			// their login instead of failing.
			$user = get_user_by( 'id', $existing_id );

			$existing_post = CP_Setup::candidate_post_for_user( $existing_id );
			if ( $existing_post ) {
				// Already a candidate: just add the new election(s).
				$current = array_map( 'intval', (array) get_post_meta( $existing_post->ID, '_cp_elections', true ) );
				$merged  = array_values( array_unique( array_merge( $current, self::posted_election_ids() ) ) );
				update_post_meta( $existing_post->ID, '_cp_elections', $merged );
				do_action( 'cp_candidate_saved', $existing_post->ID );
				return esc_html( $user->display_name ) . ' is already a candidate - added them to the selected election(s) instead.';
			}

			$user->add_role( CP_Setup::ROLE ); // keeps their existing role(s)
			$user_id = $existing_id;
			$is_new_account = false;
		} else {
			$user_id = wp_insert_user( array(
				'user_login'   => sanitize_user( strtolower( $first . '.' . $last ) . wp_rand( 10, 99 ), true ),
				'user_email'   => $email,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => $first . ' ' . $last,
				'user_pass'    => wp_generate_password( 24 ),
				'role'         => CP_Setup::ROLE,
			) );
			if ( is_wp_error( $user_id ) ) {
				return 'Could not create the account: ' . $user_id->get_error_message();
			}
			$is_new_account = true;
		}

		$post_id = wp_insert_post( array(
			'post_type'    => 'cp_candidate',
			'post_title'   => $first . ' ' . $last,
			'post_status'  => 'publish',
			'post_content' => '',
		) );
		update_post_meta( $post_id, '_cp_user_id', $user_id );
		update_post_meta( $post_id, '_cp_first_name', $first );
		update_post_meta( $post_id, '_cp_last_name', $last );
		update_post_meta( $post_id, '_cp_elections', self::posted_election_ids() );
		update_post_meta( $post_id, '_cp_email', $email );

		self::send_invite( $user_id, $is_new_account );
		do_action( 'cp_candidate_saved', $post_id );

		return $is_new_account
			? 'Candidate added and invitation email sent to ' . esc_html( $email ) . '.'
			: 'Candidate profile created for existing user ' . esc_html( $email ) . ' - they log in with the password they already have.';
	}

	private static function task_update_candidate() {
		$post_id = (int) $_POST['candidate_id'];
		$first   = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
		$last    = sanitize_text_field( wp_unslash( $_POST['last_name'] ) );

		wp_update_post( array(
			'ID'           => $post_id,
			'post_title'   => $first . ' ' . $last,
			'post_content' => isset( $_POST['bio'] ) ? wp_kses_post( wp_unslash( $_POST['bio'] ) ) : get_post( $post_id )->post_content,
		) );
		update_post_meta( $post_id, '_cp_first_name', $first );
		update_post_meta( $post_id, '_cp_last_name', $last );
		update_post_meta( $post_id, '_cp_elections', self::posted_election_ids() );

		if ( isset( $_POST['cp_email'] ) ) {
			update_post_meta( $post_id, '_cp_email', sanitize_email( wp_unslash( $_POST['cp_email'] ) ) );
			update_post_meta( $post_id, '_cp_phone', sanitize_text_field( wp_unslash( $_POST['cp_phone'] ) ) );
			update_post_meta( $post_id, '_cp_show_email', empty( $_POST['show_email'] ) ? '0' : '1' );
			update_post_meta( $post_id, '_cp_show_phone', empty( $_POST['show_phone'] ) ? '0' : '1' );
			update_post_meta( $post_id, '_cp_voter_id', sanitize_text_field( wp_unslash( $_POST['voter_id'] ) ) );
			update_post_meta( $post_id, '_cp_website', esc_url_raw( wp_unslash( $_POST['website'] ) ) );
			update_post_meta( $post_id, '_cp_facebook', esc_url_raw( wp_unslash( $_POST['facebook'] ) ) );
			update_post_meta( $post_id, '_cp_twitter', esc_url_raw( wp_unslash( $_POST['twitter'] ) ) );
			update_post_meta( $post_id, '_cp_instagram', esc_url_raw( wp_unslash( $_POST['instagram'] ) ) );
			update_post_meta( $post_id, '_cp_exceptions', sanitize_textarea_field( wp_unslash( $_POST['exceptions'] ) ) );
			// Admins may set or clear withdrawal freely.
			update_post_meta( $post_id, '_cp_withdrawn', empty( $_POST['withdrawn'] ) ? '0' : '1' );
			update_post_meta( $post_id, '_cp_disclosure_bypass', empty( $_POST['disclosure_bypass'] ) ? '0' : '1' );
		}

		if ( ! empty( $_FILES['cp_photo']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );
			if ( in_array( $_FILES['cp_photo']['type'], $allowed, true ) ) {
				$att = media_handle_upload( 'cp_photo', $post_id );
				if ( ! is_wp_error( $att ) ) {
					$old = get_post_thumbnail_id( $post_id );
					set_post_thumbnail( $post_id, $att );
					if ( $old && $old !== $att ) {
						wp_delete_attachment( $old, true );
					}
				}
			}
		}

		do_action( 'cp_candidate_saved', $post_id );
		return 'Candidate updated.';
	}

	/** Shared document handling: removals, uploads, and URL links. */
	private static function save_docs( $post_id, $meta_key ) {
		$docs = array_values( array_filter( (array) get_post_meta( $post_id, $meta_key, true ), 'is_array' ) );
		if ( ! empty( $_POST['remove_docs'] ) ) {
			$remove = array_map( 'intval', (array) $_POST['remove_docs'] );
			foreach ( $remove as $ri ) {
				if ( isset( $docs[ $ri ]['attachment_id'] ) && $docs[ $ri ]['attachment_id'] ) {
					wp_delete_attachment( (int) $docs[ $ri ]['attachment_id'], true );
				}
				unset( $docs[ $ri ] );
			}
			$docs = array_values( $docs );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		for ( $i = 0; $i < 3; $i++ ) {
			$title = isset( $_POST[ 'doc_title_' . $i ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'doc_title_' . $i ] ) ) : '';
			$url   = isset( $_POST[ 'doc_url_' . $i ] ) ? esc_url_raw( wp_unslash( $_POST[ 'doc_url_' . $i ] ) ) : '';
			$has_file = ! empty( $_FILES[ 'doc_file_' . $i ]['name'] );
			if ( ! $title || ( ! $url && ! $has_file ) ) {
				continue; // needs a title plus a file or a link
			}
			$entry = array( 'title' => $title, 'url' => '', 'attachment_id' => 0 );
			if ( $has_file ) {
				$att = media_handle_upload( 'doc_file_' . $i, $post_id );
				if ( ! is_wp_error( $att ) ) {
					$entry['attachment_id'] = (int) $att;
				} elseif ( $url ) {
					$entry['url'] = $url;
				} else {
					continue; // upload failed and no fallback link
				}
			} else {
				$entry['url'] = $url;
			}
			$docs[] = $entry;
		}
		update_post_meta( $post_id, $meta_key, $docs );
	}

	private static function task_save_event() {
		$id    = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
		$title = sanitize_text_field( wp_unslash( $_POST['event_name'] ) );
		if ( ! $title ) {
			return 'The event needs a name.';
		}
		$args = array( 'post_type' => 'cp_event', 'post_title' => $title, 'post_status' => 'publish' );
		$requested_slug = isset( $_POST['event_slug'] ) ? sanitize_title( wp_unslash( $_POST['event_slug'] ) ) : '';
		if ( $requested_slug ) {
			$args['post_name'] = $requested_slug;
		}
		if ( $id ) {
			$args['ID'] = $id;
			$id = wp_update_post( $args );
		} else {
			$id = wp_insert_post( $args );
		}
		update_post_meta( $id, '_cp_event_wide', empty( $_POST['event_wide'] ) ? '0' : '1' );

		foreach ( array( 'date', 'start_time', 'end_time', 'venue' ) as $t ) {
			update_post_meta( $id, '_cp_event_show_' . $t, empty( $_POST[ 'show_' . $t ] ) ? '0' : '1' );
		}
		$fields = array( 'date', 'start_time', 'call_to_order', 'end_time', 'venue', 'maps_url', 'agenda', 'description' );
		foreach ( $fields as $f ) {
			$raw = isset( $_POST[ 'event_' . $f ] ) ? wp_unslash( $_POST[ 'event_' . $f ] ) : '';
			$val = in_array( $f, array( 'agenda', 'description' ), true ) ? sanitize_textarea_field( $raw )
				 : ( 'maps_url' === $f ? esc_url_raw( $raw ) : sanitize_text_field( $raw ) );
			update_post_meta( $id, '_cp_event_' . $f, $val );
		}

		// Image uploads (multiple allowed; appended to the event's gallery).
		if ( ! empty( $_FILES['event_images']['name'][0] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$gallery = array_map( 'intval', (array) get_post_meta( $id, '_cp_event_images', true ) );
			$files   = $_FILES['event_images'];
			$count   = count( $files['name'] );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( empty( $files['name'][ $i ] ) ) {
					continue;
				}
				$_FILES['cp_single'] = array(
					'name'     => $files['name'][ $i ],
					'type'     => $files['type'][ $i ],
					'tmp_name' => $files['tmp_name'][ $i ],
					'error'    => $files['error'][ $i ],
					'size'     => $files['size'][ $i ],
				);
				if ( in_array( $_FILES['cp_single']['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
					$att = media_handle_upload( 'cp_single', $id );
					if ( ! is_wp_error( $att ) ) {
						$gallery[] = (int) $att;
					}
				}
			}
			unset( $_FILES['cp_single'] );
			$gallery = array_values( array_filter( array_unique( $gallery ) ) );
			update_post_meta( $id, '_cp_event_images', $gallery );
			if ( $gallery && ! has_post_thumbnail( $id ) ) {
				set_post_thumbnail( $id, $gallery[0] );
			}
		}

		if ( isset( $_POST['clear_images'] ) ) {
			update_post_meta( $id, '_cp_event_images', array() );
			delete_post_thumbnail( $id );
		}

		self::save_docs( $id, '_cp_event_docs' );

		// Auto-create the public page containing the event shortcode. The
		// page uses Elementor's full-width template (no theme title/sidebar)
		// and its web address (slug) follows the event's slug.
		$event   = get_post( $id );
		$page_id = (int) get_post_meta( $id, '_cp_event_page_id', true );
		if ( ! $page_id || ! get_post( $page_id ) ) {
			$page_id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_name'    => $event->post_name,
				'post_status'  => 'publish',
				'post_content' => '[election_event event="' . $event->post_name . '"]',
			) );
			update_post_meta( $id, '_cp_event_page_id', $page_id );
		} else {
			$page        = get_post( $page_id );
			$new_content = preg_replace( '/\[election_event\s+event="[^"]*"\]/', '[election_event event="' . $event->post_name . '"]', $page->post_content );
			wp_update_post( array(
				'ID'           => $page_id,
				'post_title'   => $title,
				'post_name'    => $event->post_name,
				'post_content' => $new_content,
			) );
		}
		// Full-width, title-less canvas (falls back gracefully if Elementor
		// is ever deactivated).
		update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );

		// Election assignment checkboxes: checked = held at this event.
		if ( isset( $_POST['elections_present'] ) ) {
			$checked = array_map( 'intval', isset( $_POST['assigned_elections'] ) ? (array) $_POST['assigned_elections'] : array() );
			$all     = get_posts( array( 'post_type' => 'cp_election', 'posts_per_page' => -1, 'post_status' => 'publish', 'fields' => 'ids' ) );
			foreach ( $all as $eid ) {
				$current_event = (int) get_post_meta( $eid, '_cp_event_id', true );
				if ( in_array( $eid, $checked, true ) ) {
					update_post_meta( $eid, '_cp_event_id', $id );
				} elseif ( $current_event === (int) $id ) {
					update_post_meta( $eid, '_cp_event_id', 0 );
				}
			}
		}

		do_action( 'cp_data_changed' );
		return 'Event saved. Its public page is ready - see the View page link in the table below.';
	}

	private static function posted_election_ids() {
		$ids = isset( $_POST['elections'] ) ? (array) $_POST['elections'] : array();
		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	private static function send_invite( $user_id, $is_new_account = true ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( $is_new_account ) {
			$key = get_password_reset_key( $user );
			if ( is_wp_error( $key ) ) {
				return false;
			}
			$set_url = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
			$body    = sprintf(
				"Hello %s,\n\nYou have been approved as a candidate on %s.\n\n1) Set your password here:\n%s\n\n2) Then log in and complete your public profile here:\n%s\n\nYour username is: %s\n",
				$user->first_name, get_bloginfo( 'name' ), $set_url, CP_Setup::portal_url(), $user->user_login
			);
		} else {
			$body = sprintf(
				"Hello %s,\n\nYou have been approved as a candidate on %s.\n\nLog in with your existing account and complete your public candidate profile here:\n%s\n",
				$user->first_name, get_bloginfo( 'name' ), CP_Setup::portal_url()
			);
		}
		return wp_mail( $user->user_email, sprintf( '[%s] Your candidate profile', get_bloginfo( 'name' ) ), $body );
	}

	/* ------------------------------------------------------------------ */
	/*  Screens                                                            */
	/* ------------------------------------------------------------------ */

	private static function logo_heading( $title ) {
		echo '<h1 class="cp-heading"><img src="' . esc_url( CP_PLUGIN_URL . 'assets/portal-logo.jpg' ) . '" alt="" class="cp-admin-logo" /> ' . esc_html( $title ) . '</h1>';
	}

	private static function notice() {
		if ( ! empty( $_GET['cp_notice'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( rawurldecode( wp_unslash( $_GET['cp_notice'] ) ) ) . '</p></div>';
		}
	}

	private static function form_open( $task, $extra = '', $upload = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="cp_admin_action" />';
		echo '<input type="hidden" name="task" value="' . esc_attr( $task ) . '" />';
		echo $extra; // phpcs:ignore
		wp_nonce_field( 'cp_admin_action' );
	}

	private static function elections_checklist( $checked_ids = array() ) {
		$elections = get_posts( array( 'post_type' => 'cp_election', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		if ( ! $elections ) {
			echo '<em>' . esc_html__( 'No elections yet - create one on the Elections screen.', 'candidate-portal' ) . '</em>';
			return;
		}
		foreach ( $elections as $e ) {
			printf(
				'<label class="cp-check"><input type="checkbox" name="elections[]" value="%d" %s /> %s</label>',
				(int) $e->ID,
				checked( in_array( $e->ID, $checked_ids, true ), true, false ),
				esc_html( $e->post_title )
			);
		}
	}

	public static function page_candidates() {
		$editing = isset( $_GET['edit'] ) ? get_post( (int) $_GET['edit'] ) : null;
		echo '<div class=\"wrap cp-wrap\">'; self::logo_heading( __( 'Candidates', 'candidate-portal' ) );
		self::notice();

		if ( $editing && 'cp_candidate' === $editing->post_type ) {
			$user_id = (int) get_post_meta( $editing->ID, '_cp_user_id', true );
			$user    = get_user_by( 'id', $user_id );
			$m       = function ( $k ) use ( $editing ) {
				return get_post_meta( $editing->ID, $k, true );
			};
			echo '<div class="cp-card"><h2>' . esc_html( 'Edit: ' . $editing->post_title ) . '</h2>';
			self::form_open( 'update_candidate', '<input type="hidden" name="candidate_id" value="' . (int) $editing->ID . '" />', true );
			echo '<p><label>First name<br/><input type="text" name="first_name" required value="' . esc_attr( $m( '_cp_first_name' ) ) . '" /></label></p>';
			echo '<p><label>Last name<br/><input type="text" name="last_name" required value="' . esc_attr( $m( '_cp_last_name' ) ) . '" /></label></p>';

			echo '<p><label>Profile photo<br/>';
			if ( has_post_thumbnail( $editing->ID ) ) {
				echo get_the_post_thumbnail( $editing->ID, array( 120, 120 ) ) . '<br/>';
			}
			echo '<input type="file" name="cp_photo" accept="image/jpeg,image/png,image/webp" data-cp-crop="square" /></label> <span class="description">Uploading replaces the current photo. A crop tool opens so you can zoom and recenter.</span></p>';

			echo '<p><label>About (public bio)<br/><textarea name="bio" rows="6">' . esc_textarea( $editing->post_content ) . '</textarea></label></p>';

			echo '<p><label>Email<br/><input type="email" name="cp_email" value="' . esc_attr( $m( '_cp_email' ) ) . '" /></label> ';
			echo '<label class="cp-check"><input type="checkbox" name="show_email" value="1" ' . checked( $m( '_cp_show_email' ), '1', false ) . ' /> shown publicly</label></p>';
			echo '<p><label>Phone<br/><input type="text" name="cp_phone" placeholder="(801)555-1234" value="' . esc_attr( CP_Frontend::format_phone( $m( '_cp_phone' ) ) ) . '" /></label> ';
			echo '<label class="cp-check"><input type="checkbox" name="show_phone" value="1" ' . checked( $m( '_cp_show_phone' ), '1', false ) . ' /> shown publicly</label></p>';

			echo '<p><label>State Voter ID (never public)<br/><input type="text" name="voter_id" value="' . esc_attr( $m( '_cp_voter_id' ) ) . '" /></label></p>';

			echo '<p><label>Website<br/><input type="url" name="website" value="' . esc_attr( $m( '_cp_website' ) ) . '" /></label></p>';
			echo '<p><label>Facebook<br/><input type="url" name="facebook" value="' . esc_attr( $m( '_cp_facebook' ) ) . '" /></label></p>';
			echo '<p><label>X / Twitter<br/><input type="url" name="twitter" value="' . esc_attr( $m( '_cp_twitter' ) ) . '" /></label></p>';
			echo '<p><label>Instagram<br/><input type="url" name="instagram" value="' . esc_attr( $m( '_cp_instagram' ) ) . '" /></label></p>';

			echo '<p><label>Excepted Provisions (public if filled in)<br/><textarea name="exceptions" rows="4">' . esc_textarea( $m( '_cp_exceptions' ) ) . '</textarea></label></p>';
			echo '<p class="description">Disclosure status: ' . CP_Frontend::admin_disclosure_status( $editing->ID, true ) . '</p>'; // phpcs:ignore
			echo '<p><label class="cp-check"><input type="checkbox" name="disclosure_bypass" value="1" ' . checked( $m( '_cp_disclosure_bypass' ), '1', false ) . ' /> <strong>Bypass disclosure requirement</strong></label> <span class="description">Shows this candidate publicly even though they have not accepted the disclosure statement themselves.</span></p>';

			echo '<p><label class="cp-check"><input type="checkbox" name="withdrawn" value="1" ' . checked( $m( '_cp_withdrawn' ), '1', false ) . ' /> <strong>Withdrawn</strong></label> <span class="description">Admins can check or uncheck this. Candidates can only check it themselves.</span></p>';

			echo '<p><strong>Elections this candidate appears in:</strong><br/>';
			self::elections_checklist( array_map( 'intval', (array) get_post_meta( $editing->ID, '_cp_elections', true ) ) );
			echo '</p>';
			if ( $user ) {
				echo '<p class="description">Login email: ' . esc_html( $user->user_email ) . '</p>';
			}
			submit_button( 'Save candidate' );
			echo '</form></div>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=cp-candidates' ) ) . '">&larr; Back to all candidates</a></p>';
			echo '</div>';
			return;
		}

		echo '<div class="cp-card"><h2>' . esc_html__( 'Add a candidate', 'candidate-portal' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'New email: creates their login and emails a set-password link. Email of an existing site user (including admins): reuses their current login and just adds the candidate role.', 'candidate-portal' ) . '</p>';
		self::form_open( 'add_candidate' );
		echo '<p><label>First name<br/><input type="text" name="first_name" required /></label></p>';
		echo '<p><label>Last name<br/><input type="text" name="last_name" required /></label></p>';
		echo '<p><label>Email<br/><input type="email" name="email" required /></label></p>';
		echo '<p><strong>Elections:</strong><br/>';
		self::elections_checklist();
		echo '</p>';
		submit_button( 'Add candidate and send invite' );
		echo '</form></div>';

		$candidates = get_posts( array( 'post_type' => 'cp_candidate', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<h2>' . esc_html__( 'All candidates', 'candidate-portal' ) . '</h2>';
		if ( ! $candidates ) {
			echo '<p><em>' . esc_html__( 'None yet.', 'candidate-portal' ) . '</em></p></div>';
			return;
		}
		echo '<table class="widefat striped cp-table"><thead><tr><th>Name</th><th>Elections</th><th>Photo</th><th>Voter ID</th><th>Disclosure</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $candidates as $c ) {
			$names = array();
			foreach ( array_map( 'intval', (array) get_post_meta( $c->ID, '_cp_elections', true ) ) as $eid ) {
				$e = get_post( $eid );
				if ( $e && 'publish' === $e->post_status ) {
					$names[] = $e->post_title;
				}
			}
			$user_id = (int) get_post_meta( $c->ID, '_cp_user_id', true );
			echo '<tr><td>' . esc_html( $c->post_title ) . '</td>';
			echo '<td>' . esc_html( $names ? implode( ', ', $names ) : '—' ) . '</td>';
			echo '<td>' . ( has_post_thumbnail( $c->ID ) ? '&#10003;' : '—' ) . '</td>';
			echo '<td>' . ( get_post_meta( $c->ID, '_cp_voter_id', true ) ? '&#10003;' : '—' ) . '</td>';
			$disc = CP_Frontend::admin_disclosure_status( $c->ID );
			echo '<td>' . $disc . '</td>'; // phpcs:ignore
			echo '<td class="cp-actions">';
			echo '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=cp-candidates&edit=' . $c->ID ) ) . '">Edit</a> ';
			self::form_open( 'resend_invite', '<input type="hidden" name="user_id" value="' . $user_id . '" />' );
			echo '<button class="button button-small">Resend invite</button></form> ';
			self::form_open( 'delete_candidate', '<input type="hidden" name="candidate_id" value="' . (int) $c->ID . '" />' );
			echo '<button class="button button-small cp-danger" onclick="return confirm(\'Remove this candidate?\')">Remove</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_elections() {
		$editing   = isset( $_GET['edit'] ) ? get_post( (int) $_GET['edit'] ) : null;
		$alphabets = CP_Alphabets::all();
		$events    = get_posts( array( 'post_type' => 'cp_event', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );

		echo '<div class=\"wrap cp-wrap\">'; self::logo_heading( __( 'Elections', 'candidate-portal' ) );
		self::notice();

		echo '<div class="cp-card"><h2>' . esc_html( $editing ? 'Edit election' : 'New election' ) . '</h2>';
		self::form_open( 'save_election', $editing ? '<input type="hidden" name="election_id" value="' . (int) $editing->ID . '" />' : '', true );
		echo '<p><label>Election name<br/><input type="text" name="election_name" required value="' . esc_attr( $editing ? $editing->post_title : '' ) . '" placeholder="e.g. Vice Chair Special Election 2026" /></label></p>';
		echo '<p><label>Election date<br/><input type="date" name="election_date" value="' . esc_attr( $editing ? get_post_meta( $editing->ID, '_cp_election_date', true ) : '' ) . '" /></label></p>';

		$current_event = $editing ? (int) get_post_meta( $editing->ID, '_cp_event_id', true ) : 0;
		echo '<p><label>Part of event (optional)<br/><select name="event_id"><option value="0">— none —</option>';
		foreach ( $events as $ev ) {
			echo '<option value="' . (int) $ev->ID . '" ' . selected( $current_event, $ev->ID, false ) . '>' . esc_html( $ev->post_title ) . '</option>';
		}
		echo '</select></label> <span class="description">An event page automatically shows every election assigned to it.</span></p>';

		$current_alpha = $editing ? get_post_meta( $editing->ID, '_cp_alphabet_id', true ) : '';
		echo '<p><label>Alphabet used for candidate order<br/><select name="alphabet_id">';
		foreach ( $alphabets as $a ) {
			$range = ( $a['start'] || $a['end'] ) ? ' (' . $a['start'] . ' to ' . $a['end'] . ')' : '';
			echo '<option value="' . esc_attr( $a['id'] ) . '" ' . selected( $current_alpha, $a['id'], false ) . '>' . esc_html( $a['name'] . $range ) . '</option>';
		}
		echo '</select></label></p>';

		// Disclosure settings for this election.
		$disc_required = $editing ? ( '0' !== get_post_meta( $editing->ID, '_cp_disc_required', true ) ) : true;
		$disc_text     = $editing ? get_post_meta( $editing->ID, '_cp_disc_text', true ) : '';
		if ( '' === $disc_text ) {
			$disc_text = CP_Frontend::default_disclosure_html();
		}
		echo '<p><label class="cp-check"><input type="checkbox" name="disc_required" value="1" ' . checked( $disc_required, true, false ) . ' /> <strong>' . esc_html__( 'Require a disclosure statement', 'candidate-portal' ) . '</strong></label><br/><span class="description">' . esc_html__( 'When required, candidates must accept the statement below in their portal before they appear publicly in this election.', 'candidate-portal' ) . '</span></p>';
		echo '<p><label>' . esc_html__( 'Disclosure statement text', 'candidate-portal' ) . '<br/><textarea name="disc_text" rows="4">' . esc_textarea( $disc_text ) . '</textarea></label><br/><span class="description">' . esc_html__( 'Plain text, or paste simple links like &lt;a href="https://..."&gt;Platform&lt;/a&gt;.', 'candidate-portal' ) . '</span></p>';

		// Documents: title + file upload or URL (same as events).
		echo '<p><strong>' . esc_html__( 'Documents', 'candidate-portal' ) . '</strong><br/><span class="description">' . esc_html__( 'Shown in a Documents box above this election\'s candidates. Give each a title and either upload a file or paste a link.', 'candidate-portal' ) . '</span></p>';
		if ( $editing ) {
			$e_docs = array_values( array_filter( (array) get_post_meta( $editing->ID, '_cp_election_docs', true ), 'is_array' ) );
			if ( $e_docs ) {
				echo '<div class="cp-doc-list">';
				foreach ( $e_docs as $i => $doc ) {
					$href = ! empty( $doc['attachment_id'] ) ? wp_get_attachment_url( (int) $doc['attachment_id'] ) : ( isset( $doc['url'] ) ? $doc['url'] : '' );
					echo '<label class="cp-pick"><input type="checkbox" name="remove_docs[]" value="' . (int) $i . '" /> remove &nbsp; <a href="' . esc_url( $href ) . '" target="_blank">' . esc_html( $doc['title'] ) . '</a>' . ( ! empty( $doc['attachment_id'] ) ? ' <em class="description">(uploaded file)</em>' : ' <em class="description">(link)</em>' ) . '</label>';
				}
				echo '</div>';
			}
		}
		for ( $i = 0; $i < 3; $i++ ) {
			echo '<div class="cp-doc-row">';
			echo '<input type="text" name="doc_title_' . $i . '" placeholder="' . esc_attr__( 'Document title', 'candidate-portal' ) . '" /> ';
			echo '<input type="file" name="doc_file_' . $i . '" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" /> ';
			echo '<span class="description">or</span> <input type="url" name="doc_url_' . $i . '" placeholder="https://link-to-document" />';
			echo '</div>';
		}

		// Candidate assignment: all candidates, assigned ones on top, searchable.
		$all_candidates = get_posts( array( 'post_type' => 'cp_candidate', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<input type="hidden" name="candidates_present" value="1" />';
		echo '<p><strong>' . esc_html__( 'Candidates in this election', 'candidate-portal' ) . '</strong><br/>';
		echo '<span class="description">' . esc_html__( 'Check to add, uncheck to remove. Assigned candidates are listed first.', 'candidate-portal' ) . '</span></p>';
		if ( $all_candidates ) {
			echo '<p><input type="search" id="cp-candidate-search" class="cp-quick" data-cp-filter=".cp-candidate-picker" placeholder="' . esc_attr__( 'Search candidates...', 'candidate-portal' ) . '" autocomplete="off" /></p>';
			$assigned = array();
			$rest     = array();
			foreach ( $all_candidates as $c ) {
				$in = $editing && in_array( $editing->ID, array_map( 'intval', (array) get_post_meta( $c->ID, '_cp_elections', true ) ), true );
				if ( $in ) {
					$assigned[] = $c;
				} else {
					$rest[] = $c;
				}
			}
			echo '<div class="cp-candidate-picker cp-picker">';
			foreach ( array_merge( $assigned, $rest ) as $c ) {
				$in = $editing && in_array( $editing->ID, array_map( 'intval', (array) get_post_meta( $c->ID, '_cp_elections', true ) ), true );
				$wd = '1' === get_post_meta( $c->ID, '_cp_withdrawn', true );
				printf(
					'<label class="cp-pick%s" data-name="%s"><input type="checkbox" name="assigned_candidates[]" value="%d" %s /> %s%s</label>',
					$in ? ' cp-pick-assigned' : '',
					esc_attr( strtolower( $c->post_title ) ),
					(int) $c->ID,
					checked( $in, true, false ),
					esc_html( $c->post_title ),
					$wd ? ' <em class="cp-wd-tag">(withdrawn)</em>' : ''
				);
			}
			echo '</div>';
		} else {
			echo '<p><em>' . esc_html__( 'No candidates yet - add them on the Candidates screen.', 'candidate-portal' ) . '</em></p>';
		}

		submit_button( $editing ? 'Save election' : 'Create election' );
		echo '</form></div>';

		$elections = get_posts( array( 'post_type' => 'cp_election', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<h2>' . esc_html__( 'All elections', 'candidate-portal' ) . '</h2>';
		if ( ! $elections ) {
			echo '<p><em>None yet.</em></p></div>';
			return;
		}
		echo '<p class="description">Show one or more elections on any page with a shortcode, e.g. <code>[candidate_list elections="slug-one,slug-two"]</code>. Elections assigned to an event appear on that event\'s page automatically.</p>';
		echo '<table class="widefat striped cp-table"><thead><tr><th>Election</th><th>Date</th><th>Event</th><th>Alphabet</th><th>Shortcode</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $elections as $e ) {
			$a    = CP_Alphabets::get( get_post_meta( $e->ID, '_cp_alphabet_id', true ) );
			$date = get_post_meta( $e->ID, '_cp_election_date', true );
			$evid = (int) get_post_meta( $e->ID, '_cp_event_id', true );
			$ev   = $evid ? get_post( $evid ) : null;
			echo '<tr><td>' . esc_html( $e->post_title ) . '</td>';
			echo '<td>' . esc_html( $date ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $ev ? $ev->post_title : '—' ) . '</td>';
			echo '<td>' . esc_html( $a ? $a['name'] : 'Standard A-Z' ) . '</td>';
			echo '<td><code>[candidate_list elections="' . esc_html( $e->post_name ) . '"]</code></td>';
			echo '<td class="cp-actions"><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=cp-elections&edit=' . $e->ID ) ) . '">Edit</a> ';
			self::form_open( 'delete_election', '<input type="hidden" name="election_id" value="' . (int) $e->ID . '" />' );
			echo '<button class="button button-small cp-danger" onclick="return confirm(\'Remove this election? Candidates are kept.\')">Remove</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_events() {
		$editing = isset( $_GET['edit'] ) ? get_post( (int) $_GET['edit'] ) : null;

		echo '<div class=\"wrap cp-wrap\">'; self::logo_heading( __( 'Election Events', 'candidate-portal' ) );
		self::notice();
		echo '<p class="description">An event is a gathering (convention, organizing meeting) that can hold one or more elections. Saving an event automatically creates its public page with everything laid out - date, venue, agenda, and every assigned election\'s candidates.</p>';

		$m = function ( $key ) use ( $editing ) {
			return $editing ? get_post_meta( $editing->ID, '_cp_event_' . $key, true ) : '';
		};

		echo '<div class="cp-card"><h2>' . esc_html( $editing ? 'Edit event' : 'New event' ) . '</h2>';
		self::form_open( 'save_event', $editing ? '<input type="hidden" name="event_id" value="' . (int) $editing->ID . '" />' : '', true );
		echo '<p><label>Event name<br/><input type="text" name="event_name" id="cp-event-name" required value="' . esc_attr( $editing ? $editing->post_title : '' ) . '" placeholder="e.g. 2026 Organizing Convention" /></label></p>';
		echo '<p><label>Slug (used in the page shortcode and web address)<br/><input type="text" name="event_slug" id="cp-event-slug" value="' . esc_attr( $editing ? $editing->post_name : '' ) . '" placeholder="auto-generated from the name" pattern="[a-z0-9\-]*" /></label><br/><span class="description">Lowercase letters, numbers, and dashes only. Leave blank to auto-generate.</span></p>';
		echo '<p><label class="cp-check"><input type="checkbox" name="event_wide" value="1" ' . checked( $m( 'wide' ), '1', false ) . ' /> Wide page layout on desktop</label> <span class="description">The event page stretches nearly full-width on large screens. Mobile is unaffected.</span></p>';
		$show = function ( $key ) use ( $editing, $m ) {
			// Default to shown for new events and for events saved before
			// this option existed.
			return ! $editing || '0' !== get_post_meta( $editing->ID, '_cp_event_show_' . $key, true );
		};
		echo '<p><label>Date<br/><input type="date" name="event_date" value="' . esc_attr( $m( 'date' ) ) . '" /></label> <label class="cp-check"><input type="checkbox" name="show_date" value="1" ' . checked( $show( 'date' ), true, false ) . ' /> show on page</label></p>';
		echo '<p class="cp-times">';
		echo '<label>Start time<br/><input type="time" name="event_start_time" value="' . esc_attr( $m( 'start_time' ) ) . '" /> <label class="cp-check"><input type="checkbox" name="show_start_time" value="1" ' . checked( $show( 'start_time' ), true, false ) . ' /> show</label></label> ';
		echo '<label>Call to order (optional)<br/><input type="time" name="event_call_to_order" value="' . esc_attr( $m( 'call_to_order' ) ) . '" /></label> ';
		echo '<label>End time (optional)<br/><input type="time" name="event_end_time" value="' . esc_attr( $m( 'end_time' ) ) . '" /> <label class="cp-check"><input type="checkbox" name="show_end_time" value="1" ' . checked( $show( 'end_time' ), true, false ) . ' /> show</label></label>';
		echo '</p>';
		echo '<p><label>Additional agenda items (optional, one per line, e.g. <code>6:30 PM - Registration opens</code>)<br/><textarea name="event_agenda" rows="4">' . esc_textarea( $m( 'agenda' ) ) . '</textarea></label></p>';
		echo '<p><label>Venue<br/><input type="text" name="event_venue" value="' . esc_attr( $m( 'venue' ) ) . '" placeholder="e.g. County Convention Center, Hall B" /></label> <label class="cp-check"><input type="checkbox" name="show_venue" value="1" ' . checked( $show( 'venue' ), true, false ) . ' /> show on page</label></p>';
		echo '<p><label>Google Maps link<br/><input type="url" name="event_maps_url" value="' . esc_attr( $m( 'maps_url' ) ) . '" placeholder="https://maps.app.goo.gl/..." /></label></p>';
		echo '<p><label>Description<br/><textarea name="event_description" rows="5">' . esc_textarea( $m( 'description' ) ) . '</textarea></label></p>';

		// Documents: title + file upload or URL.
		echo '<p><strong>' . esc_html__( 'Documents', 'candidate-portal' ) . '</strong><br/><span class="description">' . esc_html__( 'Shown in a Documents box on the event page, above the candidates. Give each a title and either upload a file or paste a link.', 'candidate-portal' ) . '</span></p>';
		if ( $editing ) {
			$docs = array_values( array_filter( (array) get_post_meta( $editing->ID, '_cp_event_docs', true ), 'is_array' ) );
			if ( $docs ) {
				echo '<div class="cp-doc-list">';
				foreach ( $docs as $i => $doc ) {
					$href = ! empty( $doc['attachment_id'] ) ? wp_get_attachment_url( (int) $doc['attachment_id'] ) : ( isset( $doc['url'] ) ? $doc['url'] : '' );
					echo '<label class="cp-pick"><input type="checkbox" name="remove_docs[]" value="' . (int) $i . '" /> remove &nbsp; <a href="' . esc_url( $href ) . '" target="_blank">' . esc_html( $doc['title'] ) . '</a>' . ( ! empty( $doc['attachment_id'] ) ? ' <em class="description">(uploaded file)</em>' : ' <em class="description">(link)</em>' ) . '</label>';
				}
				echo '</div>';
			}
		}
		for ( $i = 0; $i < 3; $i++ ) {
			echo '<div class="cp-doc-row">';
			echo '<input type="text" name="doc_title_' . $i . '" placeholder="' . esc_attr__( 'Document title', 'candidate-portal' ) . '" /> ';
			echo '<input type="file" name="doc_file_' . $i . '" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt" /> ';
			echo '<span class="description">or</span> <input type="url" name="doc_url_' . $i . '" placeholder="https://link-to-document" />';
			echo '</div>';
		}

		// Election assignment: all elections, assigned ones first, searchable.
		$all_elections = get_posts( array( 'post_type' => 'cp_election', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<input type="hidden" name="elections_present" value="1" />';
		echo '<p><strong>' . esc_html__( 'Elections held at this event', 'candidate-portal' ) . '</strong><br/>';
		echo '<span class="description">' . esc_html__( 'Check to add, uncheck to remove. An election can only belong to one event - checking one that belongs to another event moves it here.', 'candidate-portal' ) . '</span></p>';
		if ( $all_elections ) {
			echo '<p><input type="search" id="cp-election-search" class="cp-quick" data-cp-filter=".cp-election-picker" placeholder="' . esc_attr__( 'Search elections...', 'candidate-portal' ) . '" autocomplete="off" /></p>';
			$assigned = array();
			$rest     = array();
			foreach ( $all_elections as $e ) {
				$in = $editing && (int) get_post_meta( $e->ID, '_cp_event_id', true ) === (int) $editing->ID;
				if ( $in ) {
					$assigned[] = $e;
				} else {
					$rest[] = $e;
				}
			}
			echo '<div class="cp-election-picker cp-picker">';
			foreach ( array_merge( $assigned, $rest ) as $e ) {
				$in       = $editing && (int) get_post_meta( $e->ID, '_cp_event_id', true ) === (int) $editing->ID;
				$other_id = (int) get_post_meta( $e->ID, '_cp_event_id', true );
				$other    = ( $other_id && ( ! $editing || $other_id !== (int) $editing->ID ) ) ? get_post( $other_id ) : null;
				printf(
					'<label class="cp-pick%s" data-name="%s"><input type="checkbox" name="assigned_elections[]" value="%d" %s /> %s%s</label>',
					$in ? ' cp-pick-assigned' : '',
					esc_attr( strtolower( $e->post_title ) ),
					(int) $e->ID,
					checked( $in, true, false ),
					esc_html( $e->post_title ),
					$other ? ' <em class="cp-wd-tag">(at: ' . esc_html( $other->post_title ) . ')</em>' : ''
				);
			}
			echo '</div>';
		} else {
			echo '<p><em>' . esc_html__( 'No elections yet - create them on the Elections screen.', 'candidate-portal' ) . '</em></p>';
		}

		if ( $editing ) {
			$imgs = array_map( 'intval', (array) get_post_meta( $editing->ID, '_cp_event_images', true ) );
			if ( $imgs ) {
				echo '<p><strong>Current images:</strong></p><p class="cp-thumbs">';
				foreach ( $imgs as $iid ) {
					echo wp_get_attachment_image( $iid, array( 90, 90 ) );
				}
				echo '</p><p><label><input type="checkbox" name="clear_images" value="1" /> Remove all current images on save</label></p>';
			}
		}
		echo '<p><label>Event image(s) - the first becomes the banner<br/><input type="file" name="event_images[]" accept="image/jpeg,image/png,image/webp" data-cp-crop="free" multiple /></label> <span class="description">A crop tool opens for each image so you can zoom, recenter, and crop.</span></p>';
		submit_button( $editing ? 'Save event' : 'Create event and build its page' );
		echo '</form></div>';

		$events = get_posts( array( 'post_type' => 'cp_event', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<h2>All events</h2>';
		if ( ! $events ) {
			echo '<p><em>None yet.</em></p></div>';
			return;
		}
		echo '<table class="widefat striped cp-table"><thead><tr><th>Event</th><th>Date</th><th>Venue</th><th>Elections</th><th>Page</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $events as $ev ) {
			$date = get_post_meta( $ev->ID, '_cp_event_date', true );
			$elections = get_posts( array( 'post_type' => 'cp_election', 'posts_per_page' => -1, 'post_status' => 'publish', 'meta_key' => '_cp_event_id', 'meta_value' => $ev->ID ) );
			$page_id = (int) get_post_meta( $ev->ID, '_cp_event_page_id', true );
			echo '<tr><td>' . esc_html( $ev->post_title ) . '</td>';
			echo '<td>' . esc_html( $date ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( get_post_meta( $ev->ID, '_cp_event_venue', true ) ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $elections ? implode( ', ', wp_list_pluck( $elections, 'post_title' ) ) : '—' ) . '</td>';
			echo '<td>' . ( $page_id && get_post( $page_id ) ? '<a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank">View page</a>' : '—' ) . '</td>';
			echo '<td class="cp-actions"><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=cp-events&edit=' . $ev->ID ) ) . '">Edit</a> ';
			self::form_open( 'delete_event', '<input type="hidden" name="event_id" value="' . (int) $ev->ID . '" />' );
			echo '<button class="button button-small cp-danger" onclick="return confirm(\'Remove this event? Its elections and page are kept.\')">Remove</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_alphabets() {
		$editing = isset( $_GET['edit'] ) ? CP_Alphabets::get( sanitize_key( $_GET['edit'] ) ) : null;

		echo '<div class=\"wrap cp-wrap\">'; self::logo_heading( __( 'Alphabets', 'candidate-portal' ) );
		self::notice();
		echo '<p class="description">Type the letters in their new order in the quick-entry box (dashes appear automatically) and the number boxes fill themselves in for you to verify. Or set the numbers by hand - either way works. Candidates are ordered by last name, then first name. Start and end dates are reminders only; any alphabet can be reused in any election at any time.</p>';

		echo '<div class="cp-card"><h2>' . esc_html( $editing ? 'Edit alphabet' : 'New alphabet' ) . '</h2>';
		self::form_open( 'save_alphabet', $editing ? '<input type="hidden" name="alphabet_id" value="' . esc_attr( $editing['id'] ) . '" />' : '' );
		echo '<p><label>Name<br/><input type="text" name="alphabet_name" required value="' . esc_attr( $editing ? $editing['name'] : '' ) . '" placeholder="e.g. 2025-2027" /></label></p>';
		echo '<p><label>In use from <input type="date" name="alphabet_start" value="' . esc_attr( $editing ? $editing['start'] : '' ) . '" /></label> ';
		echo '<label> to <input type="date" name="alphabet_end" value="' . esc_attr( $editing ? $editing['end'] : '' ) . '" /></label> <span class="description">(reminder only)</span></p>';

		echo '<p><label>Quick entry - type the 26 letters in their new order<br/>';
		echo '<input type="text" id="cp-quick-alphabet" class="cp-quick" placeholder="e.g. Q-W-E-R-T-Y..." autocomplete="off" spellcheck="false" /></label><br/>';
		echo '<span class="description" id="cp-quick-status">Typed letters fill in the number boxes below.</span></p>';

		echo '<div class="cp-letters">';
		foreach ( range( 'A', 'Z' ) as $i => $l ) {
			$v = $editing && isset( $editing['letters'][ $l ] ) ? (int) $editing['letters'][ $l ] : $i + 1;
			echo '<label class="cp-letter"><span>' . esc_html( $l ) . '</span><input type="number" min="1" max="26" name="letter_' . esc_attr( $l ) . '" value="' . esc_attr( $v ) . '" /></label>';
		}
		echo '</div>';
		submit_button( $editing ? 'Save alphabet' : 'Create alphabet' );
		echo '</form></div>';

		echo '<h2>All alphabets</h2><table class="widefat striped cp-table"><thead><tr><th>Name</th><th>Dates</th><th>Order preview</th><th>Actions</th></tr></thead><tbody>';
		foreach ( CP_Alphabets::all() as $a ) {
			$order = $a['letters'];
			asort( $order );
			echo '<tr><td>' . esc_html( $a['name'] ) . '</td>';
			echo '<td>' . esc_html( trim( $a['start'] . ' to ' . $a['end'], ' to' ) ?: '—' ) . '</td>';
			echo '<td><code>' . esc_html( implode( '-', array_keys( $order ) ) ) . '</code></td>';
			echo '<td class="cp-actions"><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=cp-alphabets&edit=' . $a['id'] ) ) . '">Edit</a> ';
			if ( 'standard' !== $a['id'] ) {
				self::form_open( 'delete_alphabet', '<input type="hidden" name="alphabet_id" value="' . esc_attr( $a['id'] ) . '" />' );
				echo '<button class="button button-small cp-danger" onclick="return confirm(\'Remove this alphabet?\')">Remove</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_settings() {
		echo '<div class=\"wrap cp-wrap\">'; self::logo_heading( __( 'Candidate Portal Settings', 'candidate-portal' ) );
		self::notice();

		echo '<div class="cp-card"><h2>Portal page</h2>';
		self::form_open( 'save_settings' );
		echo '<p class="description">Pick the page that contains the <code>[candidate_portal]</code> shortcode. That is where candidates log in and edit their profile.</p>';
		echo '<p>';
		wp_dropdown_pages( array( 'name' => 'portal_page_id', 'selected' => (int) get_option( 'cp_portal_page_id' ), 'show_option_none' => '— choose a page —' ) );
		echo '</p>';

		echo '<h2>GitHub versioning (optional)</h2>';
		echo '<p class="description">Every save is also stored as a commit in a GitHub repository (your private data repo), giving full history and an off-site backup. State Voter IDs are never synced to GitHub - they stay only in WordPress. Create a fine-grained personal access token with Contents: Read and write permission on that one repository.</p>';
		echo '<p><label>Repository owner (user or organization)<br/><input type="text" name="gh_owner" value="' . esc_attr( get_option( 'cp_gh_owner', '' ) ) . '" placeholder="your-party-org" /></label></p>';
		echo '<p><label>Repository name<br/><input type="text" name="gh_repo" value="' . esc_attr( get_option( 'cp_gh_repo', '' ) ) . '" placeholder="candidate-data" /></label></p>';
		echo '<p><label>Branch<br/><input type="text" name="gh_branch" value="' . esc_attr( get_option( 'cp_gh_branch', 'main' ) ) . '" /></label></p>';
		echo '<p><label>Access token ' . ( get_option( 'cp_gh_token' ) ? '(saved - leave blank to keep the current one)' : '' ) . '<br/><input type="password" name="gh_token" value="" autocomplete="new-password" /></label></p>';

		echo '<h2>Plugin updates from GitHub (optional)</h2>';
		echo '<p class="description">Point this at the <strong>public</strong> repository that holds the plugin code itself (not your candidate-data repo). When a newer release is published there, an Update button appears under Dashboard &rarr; Updates.</p>';
		echo '<p><label>Code repository owner<br/><input type="text" name="upd_owner" value="' . esc_attr( get_option( 'cp_upd_owner', '' ) ) . '" placeholder="your-party-org" /></label></p>';
		echo '<p><label>Code repository name<br/><input type="text" name="upd_repo" value="' . esc_attr( get_option( 'cp_upd_repo', '' ) ) . '" placeholder="candidate-portal" /></label></p>';
		submit_button( 'Save settings' );
		echo '</form>';

		if ( CP_Updater::enabled() ) {
			self::form_open( 'check_updates' );
			echo '<p><button class="button">Check for plugin updates now</button> <span class="description">Current version: ' . esc_html( CP_VERSION ) . '. WordPress also checks automatically twice a day.</span></p></form>';
		}
		if ( CP_GitHub::enabled() ) {
			self::form_open( 'sync_all' );
			echo '<p><button class="button">Push everything to GitHub now</button> <span class="description">Last sync result: ' . esc_html( get_option( 'cp_gh_last_result', 'never run' ) ) . '</span></p></form>';
		}
		echo '</div></div>';
	}
}
