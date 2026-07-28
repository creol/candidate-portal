<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two shortcodes:
 *   [candidate_list elections="slug-a,slug-b"]  - public display, custom-alphabet order
 *   [candidate_portal]                          - login + self-edit form for candidates
 * Styling stays minimal and inherits the active theme.
 */
class CP_Frontend {

	public static function init() {
		add_shortcode( 'candidate_list', array( __CLASS__, 'shortcode_list' ) );
		add_shortcode( 'candidate_portal', array( __CLASS__, 'shortcode_portal' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_profile_save' ) );
	}

	public static function assets() {
		wp_register_style( 'cp-frontend', CP_PLUGIN_URL . 'assets/cp-frontend.css', array(), CP_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/*  Public candidate list                                              */
	/* ------------------------------------------------------------------ */

	public static function shortcode_list( $atts ) {
		wp_enqueue_style( 'cp-frontend' );
		$atts  = shortcode_atts( array( 'elections' => '', 'election' => '' ), $atts, 'candidate_list' );
		$slugs = array_filter( array_map( 'trim', explode( ',', $atts['elections'] ? $atts['elections'] : $atts['election'] ) ) );
		if ( ! $slugs ) {
			return '<p><em>' . esc_html__( 'No election specified.', 'candidate-portal' ) . '</em></p>';
		}

		$multiple = count( $slugs ) > 1;
		$out      = '<div class="cp-list">';

		foreach ( $slugs as $slug ) {
			$election = get_page_by_path( $slug, OBJECT, 'cp_election' );
			if ( ! $election || 'publish' !== $election->post_status ) {
				continue;
			}

			$candidates = get_posts( array(
				'post_type'      => 'cp_candidate',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			) );
			$candidates = array_values( array_filter( $candidates, function ( $c ) use ( $election ) {
				return in_array( $election->ID, array_map( 'intval', (array) get_post_meta( $c->ID, '_cp_elections', true ) ), true );
			} ) );

			$alphabet_id = get_post_meta( $election->ID, '_cp_alphabet_id', true );
			$candidates  = CP_Alphabets::sort_candidates( $candidates, $alphabet_id );

			if ( $multiple ) {
				$out .= '<h2 class="cp-election-title">' . esc_html( $election->post_title ) . '</h2>';
			}
			if ( ! $candidates ) {
				$out .= '<p><em>' . esc_html__( 'Candidate profiles will appear here soon.', 'candidate-portal' ) . '</em></p>';
				continue;
			}

			$out .= '<div class="cp-grid">';
			foreach ( $candidates as $c ) {
				$out .= self::render_card( $c );
			}
			$out .= '</div>';
		}

		return $out . '</div>';
	}

	private static function render_card( $c ) {
		$photo = get_the_post_thumbnail( $c->ID, 'medium', array( 'class' => 'cp-photo', 'alt' => esc_attr( $c->post_title ) ) );
		$links = '';
		$fields = array(
			'_cp_website'   => __( 'Website', 'candidate-portal' ),
			'_cp_facebook'  => 'Facebook',
			'_cp_twitter'   => 'X / Twitter',
			'_cp_instagram' => 'Instagram',
		);
		foreach ( $fields as $key => $label ) {
			$url = get_post_meta( $c->ID, $key, true );
			if ( $url ) {
				$links .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $label ) . '</a>';
			}
		}

		$bio = apply_filters( 'the_content', $c->post_content );

		return '<div class="cp-card-public">'
			. ( $photo ? $photo : '<div class="cp-photo cp-photo-empty" aria-hidden="true"></div>' )
			. '<h3 class="cp-name">' . esc_html( $c->post_title ) . '</h3>'
			. '<div class="cp-bio">' . $bio . '</div>'
			. ( $links ? '<div class="cp-links">' . $links . '</div>' : '' )
			. '</div>';
	}

	/* ------------------------------------------------------------------ */
	/*  Candidate self-edit portal                                         */
	/* ------------------------------------------------------------------ */

	public static function shortcode_portal() {
		wp_enqueue_style( 'cp-frontend' );

		if ( ! is_user_logged_in() ) {
			return '<div class="cp-portal"><h3>' . esc_html__( 'Candidate sign in', 'candidate-portal' ) . '</h3>'
				. wp_login_form( array( 'echo' => false, 'redirect' => get_permalink() ) )
				. '<p><a href="' . esc_url( wp_lostpassword_url( get_permalink() ) ) . '">' . esc_html__( 'Forgot your password?', 'candidate-portal' ) . '</a></p></div>';
		}

		$user = wp_get_current_user();
		$post = CP_Setup::candidate_post_for_user( $user->ID );
		if ( ! $post && ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'Your account is not linked to a candidate profile. Please contact the party administrator.', 'candidate-portal' ) . '</p>';
		}
		if ( ! $post ) {
			return '<p>' . esc_html__( 'Admins manage candidates from the WordPress dashboard under Candidate Portal.', 'candidate-portal' ) . '</p>';
		}

		$saved = isset( $_GET['cp_saved'] ) ? '<div class="cp-saved">' . esc_html__( 'Saved. Your public profile is live.', 'candidate-portal' ) . '</div>' : '';

		$photo = get_the_post_thumbnail( $post->ID, 'medium', array( 'class' => 'cp-photo' ) );

		ob_start();
		?>
		<div class="cp-portal">
			<?php echo $saved; // phpcs:ignore ?>
			<h3><?php echo esc_html( sprintf( __( 'Your public profile, %s', 'candidate-portal' ), $user->first_name ) ); ?></h3>
			<p class="cp-hint"><?php esc_html_e( 'Everything you save here is published immediately on the party website.', 'candidate-portal' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'cp_save_profile', 'cp_profile_nonce' ); ?>
				<input type="hidden" name="cp_profile_save" value="1" />

				<p class="cp-field"><label><?php esc_html_e( 'First name', 'candidate-portal' ); ?><br/>
					<input type="text" name="first_name" required value="<?php echo esc_attr( get_post_meta( $post->ID, '_cp_first_name', true ) ); ?>" /></label></p>

				<p class="cp-field"><label><?php esc_html_e( 'Last name', 'candidate-portal' ); ?><br/>
					<input type="text" name="last_name" required value="<?php echo esc_attr( get_post_meta( $post->ID, '_cp_last_name', true ) ); ?>" /></label></p>

				<p class="cp-field"><label><?php esc_html_e( 'Profile photo', 'candidate-portal' ); ?><br/>
					<?php echo $photo ? $photo : ''; // phpcs:ignore ?>
					<input type="file" name="cp_photo" accept="image/jpeg,image/png,image/webp" /></label>
					<span class="cp-hint"><?php esc_html_e( 'JPG or PNG. Uploading a new photo replaces the old one.', 'candidate-portal' ); ?></span></p>

				<p class="cp-field"><label><?php esc_html_e( 'About you (shown to voters)', 'candidate-portal' ); ?><br/>
					<textarea name="bio" rows="8"><?php echo esc_textarea( $post->post_content ); ?></textarea></label></p>

				<p class="cp-field"><label><?php esc_html_e( 'Website', 'candidate-portal' ); ?><br/>
					<input type="url" name="website" placeholder="https://" value="<?php echo esc_attr( get_post_meta( $post->ID, '_cp_website', true ) ); ?>" /></label></p>

				<p class="cp-field"><label>Facebook<br/>
					<input type="url" name="facebook" placeholder="https://facebook.com/..." value="<?php echo esc_attr( get_post_meta( $post->ID, '_cp_facebook', true ) ); ?>" /></label></p>

				<p class="cp-field"><label>X / Twitter<br/>
					<input type="url" name="twitter" placeholder="https://x.com/..." value="<?php echo esc_attr( get_post_meta( $post->ID, '_cp_twitter', true ) ); ?>" /></label></p>

				<p class="cp-field"><label>Instagram<br/>
					<input type="url" name="instagram" placeholder="https://instagram.com/..." value="<?php echo esc_attr( get_post_meta( $post->ID, '_cp_instagram', true ) ); ?>" /></label></p>

				<p><button type="submit" class="cp-save"><?php esc_html_e( 'Save and publish', 'candidate-portal' ); ?></button>
					<a class="cp-logout" href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Sign out', 'candidate-portal' ); ?></a></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Save handler for the front-end profile form. */
	public static function maybe_handle_profile_save() {
		if ( empty( $_POST['cp_profile_save'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! isset( $_POST['cp_profile_nonce'] ) || ! wp_verify_nonce( $_POST['cp_profile_nonce'], 'cp_save_profile' ) ) {
			return;
		}

		$user = wp_get_current_user();
		$post = CP_Setup::candidate_post_for_user( $user->ID );
		if ( ! $post ) {
			return; // only a linked candidate may save - and only their own record
		}

		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ) );

		wp_update_post( array(
			'ID'           => $post->ID,
			'post_title'   => $first . ' ' . $last,
			'post_content' => wp_kses_post( wp_unslash( $_POST['bio'] ) ),
		) );
		update_post_meta( $post->ID, '_cp_first_name', $first );
		update_post_meta( $post->ID, '_cp_last_name', $last );
		update_post_meta( $post->ID, '_cp_website', esc_url_raw( wp_unslash( $_POST['website'] ) ) );
		update_post_meta( $post->ID, '_cp_facebook', esc_url_raw( wp_unslash( $_POST['facebook'] ) ) );
		update_post_meta( $post->ID, '_cp_twitter', esc_url_raw( wp_unslash( $_POST['twitter'] ) ) );
		update_post_meta( $post->ID, '_cp_instagram', esc_url_raw( wp_unslash( $_POST['instagram'] ) ) );

		// Photo upload (replaces the previous one).
		if ( ! empty( $_FILES['cp_photo']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';

			$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );
			$type    = isset( $_FILES['cp_photo']['type'] ) ? $_FILES['cp_photo']['type'] : '';
			if ( in_array( $type, $allowed, true ) ) {
				$attachment_id = media_handle_upload( 'cp_photo', $post->ID );
				if ( ! is_wp_error( $attachment_id ) ) {
					$old = get_post_thumbnail_id( $post->ID );
					set_post_thumbnail( $post->ID, $attachment_id );
					if ( $old && $old !== $attachment_id ) {
						wp_delete_attachment( $old, true );
					}
				}
			}
		}

		do_action( 'cp_candidate_saved', $post->ID );

		wp_safe_redirect( add_query_arg( 'cp_saved', '1', remove_query_arg( 'cp_saved', wp_get_referer() ? wp_get_referer() : CP_Setup::portal_url() ) ) );
		exit;
	}
}
