<?php
/**
 * Tracking & consent: cookie banner snippets (Termly, CookieYes, …), Google Consent Mode v2 defaults and GTM,
 * edited from a dashboard widget and printed on the front end in the order consent tools need:
 * consent defaults -> banner head snippet -> GTM (head), then banner body snippet -> GTM noscript (body open).
 * The UserWay accessibility widget loads in the footer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCOZZIO_TRACKING_OPTION = 'ocozzio_tracking';

/**
 * Saved tracking settings merged over the defaults.
 */
function ocozzio_tracking_settings() {
	$defaults = array(
		'gtm_id'       => '',
		'consent_mode' => false,
		'consent_head' => '',
		'consent_body' => '',
		'userway_id'   => '',
	);
	return array_merge( $defaults, (array) get_option( OCOZZIO_TRACKING_OPTION, array() ) );
}

/**
 * Who may edit the snippets: they are printed unfiltered, so the user needs unfiltered_html too
 * (super admins only on multisite, nobody when DISALLOW_UNFILTERED_HTML is set).
 */
function ocozzio_tracking_can_edit() {
	return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
}

/* -------------------------------------------------------------------------
 * Front end
 * ---------------------------------------------------------------------- */

/**
 * The embed rule: a value that exists renders, anything else is null (prints nothing at all).
 */
function ocozzio_embed( $value, callable $render ) {
	return ( is_string( $value ) ? '' !== trim( $value ) : ! empty( $value ) ) ? $render( $value ) : null;
}

function ocozzio_embed_consent_defaults() {
	$defaults = apply_filters(
		'ocozzio_consent_defaults',
		array(
			'ad_storage'              => 'denied',
			'ad_user_data'            => 'denied',
			'ad_personalization'      => 'denied',
			'analytics_storage'       => 'denied',
			'functionality_storage'   => 'denied',
			'personalization_storage' => 'denied',
			'security_storage'        => 'granted',
			'wait_for_update'         => 500,
		)
	);
	return "<script>\nwindow.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\n"
		. "gtag('consent', 'default', " . wp_json_encode( $defaults ) . ");\n"
		. "gtag('set', 'ads_data_redaction', true);\n</script>\n";
}

// Raw by design: third-party snippets saved only by users with unfiltered_html.
function ocozzio_embed_raw( $snippet ) {
	return $snippet . "\n";
}

function ocozzio_embed_gtm_head( $id ) {
	$id = esc_js( $id );
	return "<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');</script>\n<!-- End Google Tag Manager -->\n";
}

function ocozzio_embed_gtm_body( $id ) {
	$src = esc_url( 'https://www.googletagmanager.com/ns.html?id=' . $id );
	return '<!-- Google Tag Manager (noscript) --><noscript><iframe src="' . $src . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript><!-- End Google Tag Manager (noscript) -->' . "\n";
}

// Head order matters: consent defaults -> banner -> GTM.
add_action( 'wp_head', 'ocozzio_tracking_head', -100 );
function ocozzio_tracking_head() {
	$s = ocozzio_tracking_settings();
	// phpcs:disable WordPress.Security.EscapeOutput -- renderers escape their own values; raw snippets are intentional.
	echo ocozzio_embed( $s['consent_mode'], 'ocozzio_embed_consent_defaults' );
	echo ocozzio_embed( $s['consent_head'], 'ocozzio_embed_raw' );
	echo ocozzio_embed( $s['gtm_id'], 'ocozzio_embed_gtm_head' );
	// phpcs:enable
}

add_action( 'wp_body_open', 'ocozzio_tracking_body', 1 );
function ocozzio_tracking_body() {
	$s = ocozzio_tracking_settings();
	// phpcs:disable WordPress.Security.EscapeOutput
	echo ocozzio_embed( $s['consent_body'], 'ocozzio_embed_raw' );
	echo ocozzio_embed( $s['gtm_id'], 'ocozzio_embed_gtm_body' );
	// phpcs:enable
}

function ocozzio_embed_userway( $account ) {
	return '<script src="https://cdn.userway.org/widget.js" data-account="' . esc_attr( $account ) . '" async></script>' . "\n";
}

add_action( 'wp_footer', 'ocozzio_tracking_footer', 20 );
function ocozzio_tracking_footer() {
	$s = ocozzio_tracking_settings();
	echo ocozzio_embed( $s['userway_id'], 'ocozzio_embed_userway' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the renderer.
}

/* -------------------------------------------------------------------------
 * Dashboard widget
 * ---------------------------------------------------------------------- */

add_action( 'wp_dashboard_setup', 'ocozzio_tracking_dashboard_widget' );
function ocozzio_tracking_dashboard_widget() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'ocozzio_tracking', __( 'Tracking & Consent', 'ocozzio' ), 'ocozzio_tracking_widget_render', null, null, 'normal', 'high' );
}

function ocozzio_tracking_widget_render() {
	$s        = ocozzio_tracking_settings();
	$can_edit = ocozzio_tracking_can_edit();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirect.
	if ( isset( $_GET['ocozzio_tracking'] ) ) {
		$status = sanitize_key( $_GET['ocozzio_tracking'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'saved' === $status ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Tracking settings saved.', 'ocozzio' ) . '</p></div>';
		} elseif ( 'bad_gtm' === $status ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'That GTM container ID doesn\'t look right (expected GTM-XXXXXXX). Other settings were saved.', 'ocozzio' ) . '</p></div>';
		} elseif ( 'bad_userway' === $status ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'That UserWay account ID doesn\'t look right (letters and numbers only). Other settings were saved.', 'ocozzio' ) . '</p></div>';
		}
	}

	$on  = '<span style="color:#008a20">&#9679;</span> ';
	$off = '<span style="color:#a7aaad">&#9675;</span> ';
	echo '<ul style="margin-top:0">';
	echo '<li>' . ( $s['gtm_id'] ? $on . esc_html( sprintf( __( 'GTM: %s', 'ocozzio' ), $s['gtm_id'] ) ) : $off . esc_html__( 'GTM: not set', 'ocozzio' ) ) . '</li>';
	echo '<li>' . ( $s['consent_mode'] ? $on : $off ) . esc_html__( 'Consent Mode v2 defaults', 'ocozzio' ) . '</li>';
	echo '<li>' . ( trim( $s['consent_head'] . $s['consent_body'] ) ? $on . esc_html__( 'Cookie banner snippet set', 'ocozzio' ) : $off . esc_html__( 'No cookie banner snippet', 'ocozzio' ) ) . '</li>';
	echo '<li>' . ( $s['userway_id'] ? $on . esc_html__( 'UserWay widget on', 'ocozzio' ) : $off . esc_html__( 'UserWay: not set', 'ocozzio' ) ) . '</li>';
	echo '</ul>';

	if ( ! $can_edit ) {
		echo '<p class="description">' . esc_html__( 'Editing these needs the unfiltered_html capability (a super admin on multisite).', 'ocozzio' ) . '</p>';
		return;
	}
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ocozzio_save_tracking">
		<?php wp_nonce_field( 'ocozzio_save_tracking' ); ?>

		<p>
			<label for="ocozzio-gtm-id"><strong><?php esc_html_e( 'GTM container ID', 'ocozzio' ); ?></strong></label><br>
			<input type="text" id="ocozzio-gtm-id" name="gtm_id" class="regular-text" placeholder="GTM-XXXXXXX" value="<?php echo esc_attr( $s['gtm_id'] ); ?>">
		</p>

		<p>
			<label>
				<input type="checkbox" name="consent_mode" value="1" <?php checked( $s['consent_mode'] ); ?>>
				<?php esc_html_e( 'Set Google Consent Mode v2 defaults (everything denied until the banner grants consent)', 'ocozzio' ); ?>
			</label><br>
			<span class="description"><?php esc_html_e( 'Leave off if the banner script already sets consent defaults itself.', 'ocozzio' ); ?></span>
		</p>

		<p>
			<label for="ocozzio-consent-head"><strong><?php esc_html_e( 'Cookie banner: head snippet', 'ocozzio' ); ?></strong></label><br>
			<span class="description"><?php esc_html_e( 'Printed at the top of <head>, before GTM (e.g. Termly\'s resource blocker).', 'ocozzio' ); ?></span>
			<textarea id="ocozzio-consent-head" name="consent_head" rows="4" class="large-text code" spellcheck="false"><?php echo esc_textarea( $s['consent_head'] ); ?></textarea>
		</p>

		<p>
			<label for="ocozzio-consent-body"><strong><?php esc_html_e( 'Cookie banner: body snippet', 'ocozzio' ); ?></strong></label><br>
			<span class="description"><?php esc_html_e( 'Printed right after <body> opens. Optional.', 'ocozzio' ); ?></span>
			<textarea id="ocozzio-consent-body" name="consent_body" rows="3" class="large-text code" spellcheck="false"><?php echo esc_textarea( $s['consent_body'] ); ?></textarea>
		</p>

		<p>
			<label for="ocozzio-userway-id"><strong><?php esc_html_e( 'UserWay account ID', 'ocozzio' ); ?></strong></label><br>
			<span class="description"><?php esc_html_e( 'The data-account value from the UserWay embed code. Loads the accessibility widget in the footer.', 'ocozzio' ); ?></span><br>
			<input type="text" id="ocozzio-userway-id" name="userway_id" class="regular-text" value="<?php echo esc_attr( $s['userway_id'] ); ?>">
		</p>

		<?php submit_button( __( 'Save tracking settings', 'ocozzio' ), 'primary', 'submit', false ); ?>
	</form>
	<?php
}

add_action( 'admin_post_ocozzio_save_tracking', 'ocozzio_tracking_save' );
function ocozzio_tracking_save() {
	if ( ! ocozzio_tracking_can_edit() ) {
		wp_die( esc_html__( 'You are not allowed to edit tracking settings.', 'ocozzio' ), 403 );
	}
	check_admin_referer( 'ocozzio_save_tracking' );

	$old    = ocozzio_tracking_settings();
	$status = 'saved';

	$gtm_id = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['gtm_id'] ?? '' ) ) ) );
	if ( '' !== $gtm_id && ! preg_match( '/^GTM-[A-Z0-9]{4,12}$/', $gtm_id ) ) {
		$gtm_id = $old['gtm_id'];
		$status = 'bad_gtm';
	}

	$userway_id = trim( sanitize_text_field( wp_unslash( $_POST['userway_id'] ?? '' ) ) );
	if ( '' !== $userway_id && ! preg_match( '/^[A-Za-z0-9]{4,32}$/', $userway_id ) ) {
		$userway_id = $old['userway_id'];
		$status     = 'bad_userway';
	}

	$new = array(
		'gtm_id'       => $gtm_id,
		'consent_mode' => ! empty( $_POST['consent_mode'] ),
		// Raw by design: these are third-party <script> tags. Only unfiltered_html users reach this point.
		'consent_head' => trim( wp_unslash( $_POST['consent_head'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		'consent_body' => trim( wp_unslash( $_POST['consent_body'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		'userway_id'   => $userway_id,
	);

	update_option( OCOZZIO_TRACKING_OPTION, $new, true );

	$changes = array();
	foreach ( $new as $key => $value ) {
		if ( $old[ $key ] !== $value ) {
			// Snippets can be long: log that they changed and their sizes, not their contents.
			$changes[ $key ] = in_array( $key, array( 'consent_head', 'consent_body' ), true )
				? array( 'from_length' => strlen( $old[ $key ] ), 'to_length' => strlen( $value ) )
				: array( 'from' => $old[ $key ], 'to' => $value );
		}
	}
	if ( $changes && function_exists( 'ocozzio_log' ) ) {
		ocozzio_log( 'tracking_updated', 'Updated tracking & consent settings: ' . implode( ', ', array_keys( $changes ) ), array( 'object_type' => 'settings', 'object_name' => 'Tracking & Consent', 'details' => $changes ) );
	}

	wp_safe_redirect( add_query_arg( 'ocozzio_tracking', $status, admin_url( 'index.php' ) ) . '#ocozzio_tracking' );
	exit;
}
