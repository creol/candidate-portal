<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin side. Four plain screens, form-driven, no jargon:
 * Candidates / Elections / Alphabets / Settings.
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
		}
	}

	public static function menu() {
		add_menu_page( __( 'Candidate Portal', 'candidate-portal' ), __( 'Candidate Portal', 'candidate-portal' ), 'manage_options', 'cp-candidates', array( __CLASS__, 'page_candidates' ), 'dashicons-groups', 26 );
		add_submenu_page( 'cp-candidates', __( 'Candidates', 'candidate-portal' ), __( 'Candidates', 'candidate-portal' ), 'manage_options', 'cp-candidates', array( __CLASS__, 'page_candidates' ) );
		add_submenu_page( 'cp-candidates', __( 'Elections', 'candidate-portal' ), __( 'Elections', 'candidate-portal' ), 'manage_options', 'cp-elections', array( __CLASS__, 'page_elections' ) );
		add_submenu_page( 'cp-candidates', __( 'Alphabets', 'candidate-portal' ), __( 'Alphabets', 'candidate-portal' ), 'manage_options', 'cp-alphabets', array( __CLASS__, 'page_alphabets' ) );
		add_submenu_page( 'cp-candidates', __( 'Settings', 'candidate-portal' ), __( 'Settings', 'candidate-portal' ), 'manage_options', 'cp-settings', array( __CLASS__, 'page_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Form handling (one endpoint, routed by the "task" field)           */
	/* ------------------------------------------------------------------ */

	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'cp_admin_action' );

		$task = isset( $_POST['task'] ) ? sanitize_key( $_POST['task'] ) : '';
		$notice = '';

		switch ( $task ) {

			case 'add_candidate':
				$notice = self::task_add_candidate();
				$back   = 'cp-candidates';
				break;

			case 'update_candidate':
				$notice = self::task_update_candidate();
				$back   = 'cp-candidates';
				break;

			case 'delete_candidate':
				$post_id = (int) $_POST['candidate_id'];
				$user_id = (int) get_post_meta( $post_id, '_cp_user_id', true );
				wp_trash_post( $post_id );
				if ( $user_id && CP_Setup::is_candidate( get_user_by( 'id', $user_id ) ) ) {
					wp_delete_user( $user_id );
				}
				$notice = 'Candidate removed. Their login no longer works.';
				$back   = 'cp-candidates';
				break;

			case 'resend_invite':
				$user_id = (int) $_POST['user_id'];
				$notice  = self::send_invite( $user_id ) ? 'Invitation email sent again.' : 'Could not send the email.';
				$back    = 'cp-candidates';
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
				if ( ! empty( $_POST['gh_token'] ) ) { // only overwrite when a new one is typed
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

			default:
				$back = 'cp-candidates';
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
		if ( email_exists( $email ) ) {
			return 'A user with that email already exists.';
		}

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

		self::send_invite( $user_id );
		do_action( 'cp_candidate_saved', $post_id );

		return 'Candidate added and invitation email sent to ' . esc_html( $email ) . '.';
	}

	private static function task_update_candidate() {
		$post_id = (int) $_POST['candidate_id'];
		$first   = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
		$last    = sanitize_text_field( wp_unslash( $_POST['last_name'] ) );

		wp_update_post( array( 'ID' => $post_id, 'post_title' => $first . ' ' . $last ) );
		update_post_meta( $post_id, '_cp_first_name', $first );
		update_post_meta( $post_id, '_cp_last_name', $last );
		update_post_meta( $post_id, '_cp_elections', self::posted_election_ids() );

		do_action( 'cp_candidate_saved', $post_id );
		return 'Candidate updated.';
	}

	private static function posted_election_ids() {
		$ids = isset( $_POST['elections'] ) ? (array) $_POST['elections'] : array();
		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/** Email the candidate a set-your-password link plus the portal address. */
	private static function send_invite( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return false;
		}
		$set_url = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$body = sprintf(
			"Hello %s,\n\nYou have been approved as a candidate on %s.\n\n1) Set your password here:\n%s\n\n2) Then log in and edit your public profile here:\n%s\n\nYour username is: %s\n",
			$user->first_name,
			get_bloginfo( 'name' ),
			$set_url,
			CP_Setup::portal_url(),
			$user->user_login
		);
		return wp_mail( $user->user_email, sprintf( '[%s] Your candidate profile login', get_bloginfo( 'name' ) ), $body );
	}

	/* ------------------------------------------------------------------ */
	/*  Screens                                                            */
	/* ------------------------------------------------------------------ */

	private static function notice() {
		if ( ! empty( $_GET['cp_notice'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( rawurldecode( wp_unslash( $_GET['cp_notice'] ) ) ) . '</p></div>';
		}
	}

	private static function form_open( $task, $extra = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
		echo '<div class="wrap cp-wrap"><h1>' . esc_html__( 'Candidates', 'candidate-portal' ) . '</h1>';
		self::notice();

		if ( $editing && 'cp_candidate' === $editing->post_type ) {
			$user_id = (int) get_post_meta( $editing->ID, '_cp_user_id', true );
			$user    = get_user_by( 'id', $user_id );
			echo '<div class="cp-card"><h2>' . esc_html( 'Edit: ' . $editing->post_title ) . '</h2>';
			self::form_open( 'update_candidate', '<input type="hidden" name="candidate_id" value="' . (int) $editing->ID . '" />' );
			echo '<p><label>First name<br/><input type="text" name="first_name" required value="' . esc_attr( get_post_meta( $editing->ID, '_cp_first_name', true ) ) . '" /></label></p>';
			echo '<p><label>Last name<br/><input type="text" name="last_name" required value="' . esc_attr( get_post_meta( $editing->ID, '_cp_last_name', true ) ) . '" /></label></p>';
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

		// Add form.
		echo '<div class="cp-card"><h2>' . esc_html__( 'Add a candidate', 'candidate-portal' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This creates their login and emails them a link to set a password and fill in their own profile.', 'candidate-portal' ) . '</p>';
		self::form_open( 'add_candidate' );
		echo '<p><label>First name<br/><input type="text" name="first_name" required /></label></p>';
		echo '<p><label>Last name<br/><input type="text" name="last_name" required /></label></p>';
		echo '<p><label>Email<br/><input type="email" name="email" required /></label></p>';
		echo '<p><strong>Elections:</strong><br/>';
		self::elections_checklist();
		echo '</p>';
		submit_button( 'Add candidate and send invite' );
		echo '</form></div>';

		// List.
		$candidates = get_posts( array( 'post_type' => 'cp_candidate', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<h2>' . esc_html__( 'All candidates', 'candidate-portal' ) . '</h2>';
		if ( ! $candidates ) {
			echo '<p><em>' . esc_html__( 'None yet.', 'candidate-portal' ) . '</em></p></div>';
			return;
		}
		echo '<table class="widefat striped cp-table"><thead><tr><th>Name</th><th>Elections</th><th>Photo</th><th>Actions</th></tr></thead><tbody>';
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
			echo '<td class="cp-actions">';
			echo '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=cp-candidates&edit=' . $c->ID ) ) . '">Edit</a> ';
			self::form_open( 'resend_invite', '<input type="hidden" name="user_id" value="' . $user_id . '" />' );
			echo '<button class="button button-small">Resend invite</button></form> ';
			self::form_open( 'delete_candidate', '<input type="hidden" name="candidate_id" value="' . (int) $c->ID . '" />' );
			echo '<button class="button button-small cp-danger" onclick="return confirm(\'Remove this candidate and their login?\')">Remove</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_elections() {
		$editing = isset( $_GET['edit'] ) ? get_post( (int) $_GET['edit'] ) : null;
		$alphabets = CP_Alphabets::all();

		echo '<div class="wrap cp-wrap"><h1>' . esc_html__( 'Elections', 'candidate-portal' ) . '</h1>';
		self::notice();

		echo '<div class="cp-card"><h2>' . esc_html( $editing ? 'Edit election' : 'New election' ) . '</h2>';
		self::form_open( 'save_election', $editing ? '<input type="hidden" name="election_id" value="' . (int) $editing->ID . '" />' : '' );
		echo '<p><label>Election name<br/><input type="text" name="election_name" required value="' . esc_attr( $editing ? $editing->post_title : '' ) . '" placeholder="e.g. Vice Chair Special Election 2026" /></label></p>';
		echo '<p><label>Election date<br/><input type="date" name="election_date" value="' . esc_attr( $editing ? get_post_meta( $editing->ID, '_cp_election_date', true ) : '' ) . '" /></label></p>';
		$current_alpha = $editing ? get_post_meta( $editing->ID, '_cp_alphabet_id', true ) : '';
		echo '<p><label>Alphabet used for candidate order<br/><select name="alphabet_id">';
		foreach ( $alphabets as $a ) {
			$range = ( $a['start'] || $a['end'] ) ? ' (' . $a['start'] . ' to ' . $a['end'] . ')' : '';
			echo '<option value="' . esc_attr( $a['id'] ) . '" ' . selected( $current_alpha, $a['id'], false ) . '>' . esc_html( $a['name'] . $range ) . '</option>';
		}
		echo '</select></label></p>';
		submit_button( $editing ? 'Save election' : 'Create election' );
		echo '</form></div>';

		$elections = get_posts( array( 'post_type' => 'cp_election', 'posts_per_page' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<h2>' . esc_html__( 'All elections', 'candidate-portal' ) . '</h2>';
		if ( ! $elections ) {
			echo '<p><em>None yet.</em></p></div>';
			return;
		}
		echo '<p class="description">To show an election on any WordPress page, paste its shortcode into that page. To show several elections together on one page, combine them: <code>[candidate_list elections="slug-one,slug-two"]</code></p>';
		echo '<table class="widefat striped cp-table"><thead><tr><th>Election</th><th>Date</th><th>Alphabet</th><th>Shortcode (copy this into a page)</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $elections as $e ) {
			$a = CP_Alphabets::get( get_post_meta( $e->ID, '_cp_alphabet_id', true ) );
			$date = get_post_meta( $e->ID, '_cp_election_date', true );
			echo '<tr><td>' . esc_html( $e->post_title ) . '</td>';
			echo '<td>' . esc_html( $date ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $a ? $a['name'] : 'Standard A-Z' ) . '</td>';
			echo '<td><code>[candidate_list elections="' . esc_html( $e->post_name ) . '"]</code></td>';
			echo '<td class="cp-actions"><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=cp-elections&edit=' . $e->ID ) ) . '">Edit</a> ';
			self::form_open( 'delete_election', '<input type="hidden" name="election_id" value="' . (int) $e->ID . '" />' );
			echo '<button class="button button-small cp-danger" onclick="return confirm(\'Remove this election? Candidates are kept.\')">Remove</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_alphabets() {
		$editing = isset( $_GET['edit'] ) ? CP_Alphabets::get( sanitize_key( $_GET['edit'] ) ) : null;

		echo '<div class="wrap cp-wrap"><h1>' . esc_html__( 'Alphabets', 'candidate-portal' ) . '</h1>';
		self::notice();
		echo '<p class="description">Give each letter a position from 1 to 26. Candidates are ordered by last name, then first name, using these values. Start and end dates are reminders only - an alphabet can be reused in any election at any time.</p>';

		echo '<div class="cp-card"><h2>' . esc_html( $editing ? 'Edit alphabet' : 'New alphabet' ) . '</h2>';
		self::form_open( 'save_alphabet', $editing ? '<input type="hidden" name="alphabet_id" value="' . esc_attr( $editing['id'] ) . '" />' : '' );
		echo '<p><label>Name<br/><input type="text" name="alphabet_name" required value="' . esc_attr( $editing ? $editing['name'] : '' ) . '" placeholder="e.g. 2025-2027" /></label></p>';
		echo '<p><label>In use from <input type="date" name="alphabet_start" value="' . esc_attr( $editing ? $editing['start'] : '' ) . '" /></label> ';
		echo '<label> to <input type="date" name="alphabet_end" value="' . esc_attr( $editing ? $editing['end'] : '' ) . '" /></label> <span class="description">(reminder only)</span></p>';
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
			echo '<td><code>' . esc_html( implode( ' ', array_keys( $order ) ) ) . '</code></td>';
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
		echo '<div class="wrap cp-wrap"><h1>' . esc_html__( 'Candidate Portal Settings', 'candidate-portal' ) . '</h1>';
		self::notice();

		echo '<div class="cp-card"><h2>Portal page</h2>';
		self::form_open( 'save_settings' );
		echo '<p class="description">Pick the page that contains the <code>[candidate_portal]</code> shortcode. That is where candidates log in and edit their profile.</p>';
		echo '<p>';
		wp_dropdown_pages( array( 'name' => 'portal_page_id', 'selected' => (int) get_option( 'cp_portal_page_id' ), 'show_option_none' => '— choose a page —' ) );
		echo '</p>';

		echo '<h2>GitHub versioning (optional)</h2>';
		echo '<p class="description">Every save is also stored as a commit in a GitHub repository, giving you full history and an off-site backup. Create a fine-grained personal access token with Contents: Read and write permission on that one repository.</p>';
		echo '<p><label>Repository owner (user or organization)<br/><input type="text" name="gh_owner" value="' . esc_attr( get_option( 'cp_gh_owner', '' ) ) . '" placeholder="your-party-org" /></label></p>';
		echo '<p><label>Repository name<br/><input type="text" name="gh_repo" value="' . esc_attr( get_option( 'cp_gh_repo', '' ) ) . '" placeholder="candidate-profiles" /></label></p>';
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
