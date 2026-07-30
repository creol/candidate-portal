<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcodes:
 *   [candidate_list elections="slug-a,slug-b"]  - candidate grid(s)
 *   [election_event event="slug"]               - full event page layout
 *   [candidate_portal]                          - candidate login + self-edit
 */
class CP_Frontend {

	const DISCLOSURE_TEXT = 'I have read the SLCoGOP Platform and Bylaws. I support them except for any provisions I outline below, and accept it as the standard by which my performance as a candidate and as an officeholder should be evaluated.';

	public static function init() {
		add_shortcode( 'candidate_list', array( __CLASS__, 'shortcode_list' ) );
		add_shortcode( 'election_event', array( __CLASS__, 'shortcode_event' ) );
		add_shortcode( 'candidate_portal', array( __CLASS__, 'shortcode_portal' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_profile_save' ) );
	}

	public static function assets() {
		wp_register_style( 'cp-frontend', CP_PLUGIN_URL . 'assets/cp-frontend.css', array(), CP_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/*  Candidate grids                                                    */
	/* ------------------------------------------------------------------ */

	public static function shortcode_list( $atts ) {
		wp_enqueue_style( 'cp-frontend' );
		$atts  = shortcode_atts( array( 'elections' => '', 'election' => '' ), $atts, 'candidate_list' );
		$slugs = array_filter( array_map( 'trim', explode( ',', $atts['elections'] ? $atts['elections'] : $atts['election'] ) ) );
		if ( ! $slugs ) {
			return '<p><em>' . esc_html__( 'No election specified.', 'candidate-portal' ) . '</em></p>';
		}
		return '<div class="cp-list">' . self::render_elections_by_slug( $slugs, count( $slugs ) > 1 ) . '</div>';
	}

	private static function render_elections_by_slug( $slugs, $with_headings ) {
		$out = '';
		foreach ( $slugs as $slug ) {
			$election = get_page_by_path( $slug, OBJECT, 'cp_election' );
			if ( $election && 'publish' === $election->post_status ) {
				$out .= self::render_election( $election, $with_headings );
			}
		}
		return $out;
	}

	private static function render_election( $election, $with_heading ) {
		$candidates = get_posts( array( 'post_type' => 'cp_candidate', 'posts_per_page' => -1, 'post_status' => 'publish' ) );
		$candidates = array_values( array_filter( $candidates, function ( $c ) use ( $election ) {
			return in_array( $election->ID, array_map( 'intval', (array) get_post_meta( $c->ID, '_cp_elections', true ) ), true );
		} ) );
		$candidates = CP_Alphabets::sort_candidates( $candidates, get_post_meta( $election->ID, '_cp_alphabet_id', true ) );

		$out = '';
		if ( $with_heading ) {
			$date = get_post_meta( $election->ID, '_cp_election_date', true );
			$out .= '<h2 class="cp-election-title">' . esc_html( $election->post_title )
				. ( $date ? ' <span class="cp-election-date">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ) . '</span>' : '' )
				. '</h2>';
		}
		if ( ! $candidates ) {
			return $out . '<p><em>' . esc_html__( 'Candidate profiles will appear here soon.', 'candidate-portal' ) . '</em></p>';
		}
		$out .= '<div class="cp-grid">';
		foreach ( $candidates as $c ) {
			$out .= self::render_card( $c );
		}
		return $out . '</div>';
	}

	private static function render_card( $c ) {
		$photo = get_the_post_thumbnail( $c->ID, 'medium', array( 'class' => 'cp-photo', 'alt' => esc_attr( $c->post_title ) ) );

		$links  = '';
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

		// Contact details, only when the candidate chose to show them.
		$contact = '';
		if ( get_post_meta( $c->ID, '_cp_show_email', true ) && ( $email = get_post_meta( $c->ID, '_cp_email', true ) ) ) {
			$contact .= '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
		}
		if ( get_post_meta( $c->ID, '_cp_show_phone', true ) && ( $phone = get_post_meta( $c->ID, '_cp_phone', true ) ) ) {
			$contact .= '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>';
		}

		// Platform exceptions - collapsed behind a click.
		$exceptions = '';
		$exc_text   = get_post_meta( $c->ID, '_cp_exceptions', true );
		if ( get_post_meta( $c->ID, '_cp_disclosure_date', true ) && trim( (string) $exc_text ) ) {
			$exceptions = '<details class="cp-exceptions"><summary>' . esc_html__( 'View platform exceptions', 'candidate-portal' ) . '</summary><div class="cp-exceptions-body">' . wpautop( esc_html( $exc_text ) ) . '</div></details>';
		}

		$bio = apply_filters( 'the_content', get_post( $c->ID )->post_content );

		$withdrawn = '1' === get_post_meta( $c->ID, '_cp_withdrawn', true );

		return '<div class="cp-card-public' . ( $withdrawn ? ' cp-withdrawn' : '' ) . '">'
			. ( $withdrawn ? '<span class="cp-withdrawn-badge">' . esc_html__( 'Withdrawn', 'candidate-portal' ) . '</span>' : '' )
			. ( $photo ? $photo : '<div class="cp-photo cp-photo-empty" aria-hidden="true"></div>' )
			. '<h3 class="cp-name">' . esc_html( $c->post_title ) . '</h3>'
			. '<div class="cp-bio">' . $bio . '</div>'
			. ( $contact ? '<div class="cp-contact">' . $contact . '</div>' : '' )
			. ( $links ? '<div class="cp-links">' . $links . '</div>' : '' )
			. $exceptions
			. '</div>';
	}

	/* ------------------------------------------------------------------ */
	/*  Event page                                                         */
	/* ------------------------------------------------------------------ */

	public static function shortcode_event( $atts ) {
		wp_enqueue_style( 'cp-frontend' );
		$atts  = shortcode_atts( array( 'event' => '' ), $atts, 'election_event' );
		$event = $atts['event'] ? get_page_by_path( sanitize_title( $atts['event'] ), OBJECT, 'cp_event' ) : null;
		if ( ! $event || 'publish' !== $event->post_status ) {
			return '<p><em>' . esc_html__( 'Event not found.', 'candidate-portal' ) . '</em></p>';
		}

		$m = function ( $key ) use ( $event ) {
			return get_post_meta( $event->ID, '_cp_event_' . $key, true );
		};
		$fmt_time = function ( $t ) {
			return $t ? date_i18n( get_option( 'time_format' ), strtotime( $t ) ) : '';
		};

		$out = '<div class="cp-event">';

		$out .= '<h1 class="cp-event-title cp-event-title-top">' . esc_html( $event->post_title ) . '</h1>';

		// Banner image.
		$images = array_map( 'intval', (array) get_post_meta( $event->ID, '_cp_event_images', true ) );
		if ( $images ) {
			$out .= '<div class="cp-event-banner">' . wp_get_attachment_image( $images[0], 'large' ) . '</div>';
		}

		// Date / time / venue facts.
		$out .= '<div class="cp-event-facts">';
		if ( $m( 'date' ) ) {
			$out .= '<div class="cp-fact"><span class="cp-fact-label">' . esc_html__( 'Date', 'candidate-portal' ) . '</span><span>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $m( 'date' ) ) ) ) . '</span></div>';
		}
		if ( $m( 'start_time' ) ) {
			$out .= '<div class="cp-fact"><span class="cp-fact-label">' . esc_html__( 'Starts', 'candidate-portal' ) . '</span><span>' . esc_html( $fmt_time( $m( 'start_time' ) ) ) . '</span></div>';
		}
		if ( $m( 'call_to_order' ) ) {
			$out .= '<div class="cp-fact"><span class="cp-fact-label">' . esc_html__( 'Call to order', 'candidate-portal' ) . '</span><span>' . esc_html( $fmt_time( $m( 'call_to_order' ) ) ) . '</span></div>';
		}
		if ( $m( 'end_time' ) ) {
			$out .= '<div class="cp-fact"><span class="cp-fact-label">' . esc_html__( 'Ends', 'candidate-portal' ) . '</span><span>' . esc_html( $fmt_time( $m( 'end_time' ) ) ) . '</span></div>';
		}
		if ( $m( 'venue' ) ) {
			$venue = esc_html( $m( 'venue' ) );
			if ( $m( 'maps_url' ) ) {
				$venue .= ' <a class="cp-map-link" href="' . esc_url( $m( 'maps_url' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Map', 'candidate-portal' ) . ' &rarr;</a>';
			}
			$out .= '<div class="cp-fact cp-fact-wide"><span class="cp-fact-label">' . esc_html__( 'Venue', 'candidate-portal' ) . '</span><span>' . $venue . '</span></div>';
		}
		$out .= '</div>';

		if ( $m( 'description' ) ) {
			$out .= '<div class="cp-event-desc">' . wpautop( esc_html( $m( 'description' ) ) ) . '</div>';
		}

		if ( $m( 'agenda' ) ) {
			$out .= '<h2>' . esc_html__( 'Agenda', 'candidate-portal' ) . '</h2><ul class="cp-agenda">';
			foreach ( array_filter( array_map( 'trim', explode( "\n", $m( 'agenda' ) ) ) ) as $line ) {
				$out .= '<li>' . esc_html( $line ) . '</li>';
			}
			$out .= '</ul>';
		}

		// Extra images.
		if ( count( $images ) > 1 ) {
			$out .= '<div class="cp-event-gallery">';
			foreach ( array_slice( $images, 1 ) as $iid ) {
				$out .= wp_get_attachment_image( $iid, 'medium' );
			}
			$out .= '</div>';
		}

		// Every election assigned to this event, each with its own heading
		// and its own alphabet ordering.
		$elections = get_posts( array( 'post_type' => 'cp_election', 'posts_per_page' => -1, 'post_status' => 'publish', 'meta_key' => '_cp_event_id', 'meta_value' => $event->ID, 'orderby' => 'title', 'order' => 'ASC' ) );
		if ( $elections ) {
			$out .= '<h2 class="cp-event-elections-heading">' . esc_html__( 'Meet the Candidates', 'candidate-portal' ) . '</h2>';
			foreach ( $elections as $election ) {
				$out .= self::render_election( $election, true );
			}
		}

		return $out . '</div>';
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

		$saved  = isset( $_GET['cp_saved'] ) ? '<div class="cp-saved">' . esc_html__( 'Saved. Your public profile is live.', 'candidate-portal' ) . '</div>' : '';
		$photo  = get_the_post_thumbnail( $post->ID, 'medium', array( 'class' => 'cp-photo' ) );
		$meta   = function ( $k, $d = '' ) use ( $post ) {
			$v = get_post_meta( $post->ID, $k, true );
			return '' === $v ? $d : $v;
		};
		// Email/phone visibility defaults to ON for brand-new profiles.
		$is_new      = '' === get_post_meta( $post->ID, '_cp_voter_id', true ) && '' === get_post_meta( $post->ID, '_cp_show_email', true );
		$show_email  = $is_new ? '1' : get_post_meta( $post->ID, '_cp_show_email', true );
		$show_phone  = $is_new ? '1' : get_post_meta( $post->ID, '_cp_show_phone', true );
		$disc_date   = get_post_meta( $post->ID, '_cp_disclosure_date', true );

		ob_start();
		?>
		<div class="cp-portal">
			<?php echo $saved; // phpcs:ignore ?>
			<h3><?php echo esc_html( sprintf( __( 'Your public profile, %s', 'candidate-portal' ), $user->first_name ? $user->first_name : $user->display_name ) ); ?></h3>
			<p class="cp-hint"><?php esc_html_e( 'Everything you save here is published immediately on the party website - except your Voter ID, which is never shown publicly.', 'candidate-portal' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'cp_save_profile', 'cp_profile_nonce' ); ?>
				<input type="hidden" name="cp_profile_save" value="1" />

				<p class="cp-field"><label><?php esc_html_e( 'First name', 'candidate-portal' ); ?><br/>
					<input type="text" name="first_name" required value="<?php echo esc_attr( $meta( '_cp_first_name' ) ); ?>" /></label></p>

				<p class="cp-field"><label><?php esc_html_e( 'Last name', 'candidate-portal' ); ?><br/>
					<input type="text" name="last_name" required value="<?php echo esc_attr( $meta( '_cp_last_name' ) ); ?>" /></label></p>

				<p class="cp-field"><label><?php esc_html_e( 'Profile photo', 'candidate-portal' ); ?><br/>
					<?php echo $photo ? $photo : ''; // phpcs:ignore ?>
					<input type="file" name="cp_photo" accept="image/jpeg,image/png,image/webp" /></label>
					<span class="cp-hint"><?php esc_html_e( 'JPG or PNG. Uploading a new photo replaces the old one.', 'candidate-portal' ); ?></span></p>

				<p class="cp-field"><label><?php esc_html_e( 'About you (shown to voters)', 'candidate-portal' ); ?><br/>
					<textarea name="bio" rows="8"><?php echo esc_textarea( $post->post_content ); ?></textarea></label></p>

				<p class="cp-field"><label><?php esc_html_e( 'Email', 'candidate-portal' ); ?><br/>
					<input type="email" name="cp_email" value="<?php echo esc_attr( $meta( '_cp_email', $user->user_email ) ); ?>" /></label><br/>
					<label class="cp-inline"><input type="checkbox" name="show_email" value="1" <?php checked( $show_email, '1' ); ?> /> <?php esc_html_e( 'Show my email publicly', 'candidate-portal' ); ?></label></p>

				<p class="cp-field"><label><?php esc_html_e( 'Phone', 'candidate-portal' ); ?><br/>
					<input type="tel" name="cp_phone" value="<?php echo esc_attr( $meta( '_cp_phone' ) ); ?>" /></label><br/>
					<label class="cp-inline"><input type="checkbox" name="show_phone" value="1" <?php checked( $show_phone, '1' ); ?> /> <?php esc_html_e( 'Show my phone publicly', 'candidate-portal' ); ?></label></p>

				<p class="cp-field"><label><?php esc_html_e( 'State Voter ID (required - never shown publicly)', 'candidate-portal' ); ?><br/>
					<input type="text" name="voter_id" required value="<?php echo esc_attr( $meta( '_cp_voter_id' ) ); ?>" /></label></p>

				<p class="cp-field"><label><?php esc_html_e( 'Website', 'candidate-portal' ); ?><br/>
					<input type="url" name="website" placeholder="https://" value="<?php echo esc_attr( $meta( '_cp_website' ) ); ?>" /></label></p>

				<p class="cp-field"><label>Facebook<br/>
					<input type="url" name="facebook" placeholder="https://facebook.com/..." value="<?php echo esc_attr( $meta( '_cp_facebook' ) ); ?>" /></label></p>

				<p class="cp-field"><label>X / Twitter<br/>
					<input type="url" name="twitter" placeholder="https://x.com/..." value="<?php echo esc_attr( $meta( '_cp_twitter' ) ); ?>" /></label></p>

				<p class="cp-field"><label>Instagram<br/>
					<input type="url" name="instagram" placeholder="https://instagram.com/..." value="<?php echo esc_attr( $meta( '_cp_instagram' ) ); ?>" /></label></p>

				<div class="cp-disclosure">
					<h4><?php esc_html_e( 'Candidate Disclosure Statement', 'candidate-portal' ); ?></h4>
					<p><?php echo esc_html( self::DISCLOSURE_TEXT ); ?></p>
					<p><label class="cp-inline"><input type="checkbox" name="disclosure" value="1" required <?php checked( (bool) $disc_date ); ?> /> <?php esc_html_e( 'I agree (required)', 'candidate-portal' ); ?></label>
					<?php if ( $disc_date ) : ?>
						<span class="cp-hint"><?php echo esc_html( sprintf( __( 'Accepted on %s', 'candidate-portal' ), date_i18n( get_option( 'date_format' ), strtotime( $disc_date ) ) ) ); ?></span>
					<?php endif; ?></p>
					<p class="cp-field"><label><?php esc_html_e( 'Excepted Provisions:', 'candidate-portal' ); ?><br/>
						<textarea name="exceptions" rows="4" placeholder="<?php esc_attr_e( 'Leave blank if you support the platform in full.', 'candidate-portal' ); ?>"><?php echo esc_textarea( $meta( '_cp_exceptions' ) ); ?></textarea></label></p>
				</div>

				<div class="cp-withdraw-box">
					<?php $is_withdrawn = '1' === get_post_meta( $post->ID, '_cp_withdrawn', true ); ?>
					<?php if ( $is_withdrawn ) : ?>
						<p><strong><?php esc_html_e( 'You have withdrawn your candidacy.', 'candidate-portal' ); ?></strong><br/>
						<span class="cp-hint"><?php esc_html_e( 'Your profile is marked as Withdrawn on the public page. Only a party administrator can reverse this - please contact them if this was a mistake.', 'candidate-portal' ); ?></span></p>
						<input type="hidden" name="withdrawn" value="1" />
					<?php else : ?>
						<p><label class="cp-inline"><input type="checkbox" name="withdrawn" value="1" onchange="if(this.checked&&!confirm('Are you sure you want to WITHDRAW your candidacy? This is permanent - you will NOT be able to undo it yourself. Only a party administrator can reverse it.')){this.checked=false;}" /> <?php esc_html_e( 'Withdraw my candidacy', 'candidate-portal' ); ?></label><br/>
						<span class="cp-hint"><?php esc_html_e( 'Warning: once you save with this checked, you cannot uncheck it yourself.', 'candidate-portal' ); ?></span></p>
					<?php endif; ?>
				</div>

				<p><button type="submit" class="cp-save"><?php esc_html_e( 'Save and publish', 'candidate-portal' ); ?></button>
					<a class="cp-logout" href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Sign out', 'candidate-portal' ); ?></a></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

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
		update_post_meta( $post->ID, '_cp_email', sanitize_email( wp_unslash( $_POST['cp_email'] ) ) );
		update_post_meta( $post->ID, '_cp_phone', sanitize_text_field( wp_unslash( $_POST['cp_phone'] ) ) );
		update_post_meta( $post->ID, '_cp_show_email', empty( $_POST['show_email'] ) ? '0' : '1' );
		update_post_meta( $post->ID, '_cp_show_phone', empty( $_POST['show_phone'] ) ? '0' : '1' );
		update_post_meta( $post->ID, '_cp_voter_id', sanitize_text_field( wp_unslash( $_POST['voter_id'] ) ) );
		update_post_meta( $post->ID, '_cp_website', esc_url_raw( wp_unslash( $_POST['website'] ) ) );
		update_post_meta( $post->ID, '_cp_facebook', esc_url_raw( wp_unslash( $_POST['facebook'] ) ) );
		update_post_meta( $post->ID, '_cp_twitter', esc_url_raw( wp_unslash( $_POST['twitter'] ) ) );
		update_post_meta( $post->ID, '_cp_instagram', esc_url_raw( wp_unslash( $_POST['instagram'] ) ) );
		update_post_meta( $post->ID, '_cp_exceptions', sanitize_textarea_field( wp_unslash( $_POST['exceptions'] ) ) );

		// Withdrawal is one-way for candidates: they can set it, but once
		// set only an administrator can clear it (from the admin screen).
		$currently_withdrawn = '1' === get_post_meta( $post->ID, '_cp_withdrawn', true );
		if ( ! empty( $_POST['withdrawn'] ) ) {
			update_post_meta( $post->ID, '_cp_withdrawn', '1' );
		} elseif ( $currently_withdrawn && ! current_user_can( 'manage_options' ) ) {
			// Ignore any attempt to un-withdraw without admin rights.
			update_post_meta( $post->ID, '_cp_withdrawn', '1' );
		} elseif ( $currently_withdrawn ) {
			update_post_meta( $post->ID, '_cp_withdrawn', '0' );
		}

		// Disclosure: record the date the first time it is accepted.
		if ( ! empty( $_POST['disclosure'] ) ) {
			if ( ! get_post_meta( $post->ID, '_cp_disclosure_date', true ) ) {
				update_post_meta( $post->ID, '_cp_disclosure_date', current_time( 'Y-m-d' ) );
			}
		} else {
			delete_post_meta( $post->ID, '_cp_disclosure_date' );
		}

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
