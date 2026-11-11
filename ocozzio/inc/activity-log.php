<?php
/**
 * Activity log: a permanent audit trail in its own table ({prefix}ocozzio_activity_log).
 * Nothing is ever purged or deleted from the UI, and the table survives theme switches and plugin removals.
 * Shown in a Dashboard widget (recent entries) and on Dashboard -> Activity Log (full, filterable).
 *
 * Log from anywhere: ocozzio_log( 'my_action', 'Human readable message', array( 'object_type' => ..., 'details' => array() ) ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OCOZZIO_LOG_DB_VERSION = '1';

function ocozzio_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'ocozzio_activity_log';
}

/**
 * Whether the table is installed at the current schema.
 */
function ocozzio_log_ready() {
	return OCOZZIO_LOG_DB_VERSION === get_option( 'ocozzio_log_db_version' );
}

/**
 * Create / upgrade the table. Runs on init because sites update the theme automatically (after_switch_theme doesn't fire).
 */
add_action( 'init', 'ocozzio_log_maybe_install', 1 );
function ocozzio_log_maybe_install() {
	if ( ocozzio_log_ready() ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = ocozzio_log_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			user_role varchar(60) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			action varchar(64) NOT NULL DEFAULT '',
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_name varchar(255) NOT NULL DEFAULT '',
			message text NOT NULL,
			details longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id),
			KEY action (action)
		) {$charset};"
	);

	// Only mark installed once the table really exists, so a failed create retries next request.
	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		update_option( 'ocozzio_log_db_version', OCOZZIO_LOG_DB_VERSION, true );
	}
}

/**
 * The visitor's IP. REMOTE_ADDR only (forwarded headers are spoofable); filter ocozzio_log_ip behind a trusted proxy.
 */
function ocozzio_log_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	return (string) apply_filters( 'ocozzio_log_ip', $ip ? $ip : '' );
}

/**
 * Write a log entry. $args: user (WP_User|int, defaults to the current user), object_type, object_id, object_name, details (array).
 */
function ocozzio_log( $action, $message, $args = array() ) {
	if ( ! ocozzio_log_ready() ) {
		return null;
	}
	global $wpdb;

	$user = $args['user'] ?? wp_get_current_user();
	$user = is_numeric( $user ) ? get_userdata( (int) $user ) : $user;
	$user = $user instanceof WP_User && $user->exists() ? $user : null;

	$row = array(
		'created_at'  => current_time( 'mysql', true ),
		'user_id'     => $user ? $user->ID : 0,
		'user_login'  => $user ? mb_substr( $user->user_login, 0, 60 ) : '',
		'user_role'   => $user && $user->roles ? mb_substr( (string) reset( $user->roles ), 0, 60 ) : '',
		'ip'          => ocozzio_log_ip(),
		'action'      => mb_substr( sanitize_key( $action ), 0, 64 ),
		'object_type' => mb_substr( (string) ( $args['object_type'] ?? '' ), 0, 32 ),
		'object_id'   => (int) ( $args['object_id'] ?? 0 ),
		'object_name' => mb_substr( (string) ( $args['object_name'] ?? '' ), 0, 255 ),
		'message'     => (string) $message,
		'details'     => empty( $args['details'] ) ? null : wp_json_encode( $args['details'] ),
	);

	return $wpdb->insert( ocozzio_log_table(), $row ) ? $wpdb->insert_id : null;
}

/* -------------------------------------------------------------------------
 * Events
 * ---------------------------------------------------------------------- */

/* Users */

add_action( 'wp_login', 'ocozzio_log_login', 10, 2 );
function ocozzio_log_login( $login, $user ) {
	ocozzio_log( 'user_login', 'Logged in', array( 'user' => $user, 'object_type' => 'user', 'object_id' => $user->ID, 'object_name' => $login ) );
}

add_action( 'wp_logout', 'ocozzio_log_logout' );
function ocozzio_log_logout( $user_id = 0 ) {
	ocozzio_log( 'user_logout', 'Logged out', array( 'user' => $user_id, 'object_type' => 'user', 'object_id' => $user_id ) );
}

add_action( 'wp_login_failed', 'ocozzio_log_login_failed' );
function ocozzio_log_login_failed( $username ) {
	ocozzio_log( 'login_failed', sprintf( 'Failed login for "%s"', $username ), array( 'user' => 0, 'object_type' => 'user', 'object_name' => $username ) );
}

add_action( 'user_register', 'ocozzio_log_user_register' );
function ocozzio_log_user_register( $user_id ) {
	$u = get_userdata( $user_id );
	ocozzio_log( 'user_created', sprintf( 'Created user "%s"', $u ? $u->user_login : $user_id ), array( 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $u ? $u->user_login : '' ) );
}

add_action( 'profile_update', 'ocozzio_log_profile_update' );
function ocozzio_log_profile_update( $user_id ) {
	$u = get_userdata( $user_id );
	ocozzio_log( 'user_updated', sprintf( 'Updated profile of "%s"', $u ? $u->user_login : $user_id ), array( 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $u ? $u->user_login : '' ) );
}

add_action( 'set_user_role', 'ocozzio_log_user_role', 10, 3 );
function ocozzio_log_user_role( $user_id, $role, $old_roles ) {
	if ( ! $old_roles ) {
		return; // A new user getting its first role: already logged as user_created.
	}
	$u = get_userdata( $user_id );
	ocozzio_log(
		'user_role_changed',
		sprintf( 'Changed role of "%s" from %s to %s', $u ? $u->user_login : $user_id, implode( ', ', $old_roles ), $role ),
		array( 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $u ? $u->user_login : '', 'details' => array( 'from' => $old_roles, 'to' => $role ) )
	);
}

add_action( 'delete_user', 'ocozzio_log_user_delete', 10, 2 );
function ocozzio_log_user_delete( $user_id, $reassign = null ) {
	$u = get_userdata( $user_id );
	ocozzio_log( 'user_deleted', sprintf( 'Deleted user "%s"', $u ? $u->user_login : $user_id ), array( 'object_type' => 'user', 'object_id' => $user_id, 'object_name' => $u ? $u->user_login : '', 'details' => $reassign ? array( 'content_reassigned_to' => $reassign ) : array() ) );
}

add_action( 'after_password_reset', 'ocozzio_log_password_reset' );
function ocozzio_log_password_reset( $user ) {
	ocozzio_log( 'password_reset', 'Reset password', array( 'user' => $user, 'object_type' => 'user', 'object_id' => $user->ID, 'object_name' => $user->user_login ) );
}

/* Content */

/**
 * Posts worth logging: real content of a type editable in wp-admin (no revisions, menu items, changesets, …).
 */
function ocozzio_log_is_content( $post ) {
	if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'attachment' === $post->post_type ) {
		return false;
	}
	$type = get_post_type_object( $post->post_type );
	return $type && $type->show_ui;
}

function ocozzio_log_post_args( $post, $details = array() ) {
	return array( 'object_type' => $post->post_type, 'object_id' => $post->ID, 'object_name' => $post->post_title, 'details' => $details );
}

function ocozzio_log_post_label( $post ) {
	$type = get_post_type_object( $post->post_type );
	return sprintf( '%s "%s"', $type ? strtolower( $type->labels->singular_name ) : $post->post_type, $post->post_title ? $post->post_title : '(no title)' );
}

add_action( 'transition_post_status', 'ocozzio_log_post_status', 10, 3 );
function ocozzio_log_post_status( $new, $old, $post ) {
	if ( $new === $old || in_array( $new, array( 'auto-draft', 'inherit' ), true ) || ! ocozzio_log_is_content( $post ) ) {
		return;
	}
	$verbs = array(
		'publish' => 'Published',
		'future'  => 'Scheduled',
		'pending' => 'Submitted for review',
		'private' => 'Made private',
		'trash'   => 'Moved to trash',
	);
	if ( 'draft' === $new ) {
		$verb = 'trash' === $old ? 'Restored from trash' : ( in_array( $old, array( 'new', 'auto-draft' ), true ) ? 'Created draft' : 'Reverted to draft' );
	} else {
		$verb = $verbs[ $new ] ?? sprintf( 'Changed status (%s → %s) of', $old, $new );
	}
	ocozzio_log( 'post_' . $new, $verb . ' ' . ocozzio_log_post_label( $post ), ocozzio_log_post_args( $post, array( 'from' => $old, 'to' => $new ) ) );
}

/**
 * Edits to live content (status changes are logged above). Once per post per request: editors and ACF can save a post several times.
 */
add_action( 'post_updated', 'ocozzio_log_post_updated', 10, 3 );
function ocozzio_log_post_updated( $post_id, $after, $before ) {
	static $logged = array();
	if ( isset( $logged[ $post_id ] ) || $after->post_status !== $before->post_status || ! in_array( $after->post_status, array( 'publish', 'private', 'future' ), true ) || ! ocozzio_log_is_content( $after ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	$logged[ $post_id ] = true;
	$changed            = array();
	foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_parent', 'menu_order', 'post_author' ) as $field ) {
		if ( $after->$field != $before->$field ) { // phpcs:ignore Universal.Operators.StrictComparisons -- DB values are strings.
			$changed[] = $field;
		}
	}
	ocozzio_log( 'post_updated', 'Updated ' . ocozzio_log_post_label( $after ), ocozzio_log_post_args( $after, $changed ? array( 'changed' => $changed ) : array() ) );
}

add_action( 'before_delete_post', 'ocozzio_log_post_deleted', 10, 2 );
function ocozzio_log_post_deleted( $post_id, $post = null ) {
	$post = $post ? $post : get_post( $post_id );
	if ( ocozzio_log_is_content( $post ) ) {
		ocozzio_log( 'post_deleted', 'Permanently deleted ' . ocozzio_log_post_label( $post ), ocozzio_log_post_args( $post ) );
	}
}

/* Media */

add_action( 'add_attachment', 'ocozzio_log_media_added' );
function ocozzio_log_media_added( $post_id ) {
	$file = basename( (string) get_attached_file( $post_id ) );
	ocozzio_log( 'media_uploaded', sprintf( 'Uploaded "%s"', $file ), array( 'object_type' => 'attachment', 'object_id' => $post_id, 'object_name' => $file ) );
}

add_action( 'delete_attachment', 'ocozzio_log_media_deleted' );
function ocozzio_log_media_deleted( $post_id ) {
	$file = basename( (string) get_attached_file( $post_id ) );
	ocozzio_log( 'media_deleted', sprintf( 'Deleted media "%s"', $file ), array( 'object_type' => 'attachment', 'object_id' => $post_id, 'object_name' => $file ) );
}

/* Plugins, themes, core */

function ocozzio_log_plugin_name( $file ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugins = get_plugins();
	return isset( $plugins[ $file ]['Name'] ) ? $plugins[ $file ]['Name'] : $file;
}

add_action( 'activated_plugin', 'ocozzio_log_plugin_activated', 10, 2 );
function ocozzio_log_plugin_activated( $file, $network = false ) {
	$name = ocozzio_log_plugin_name( $file );
	ocozzio_log( 'plugin_activated', sprintf( 'Activated plugin "%s"%s', $name, $network ? ' (network)' : '' ), array( 'object_type' => 'plugin', 'object_name' => $file ) );
}

add_action( 'deactivated_plugin', 'ocozzio_log_plugin_deactivated', 10, 2 );
function ocozzio_log_plugin_deactivated( $file, $network = false ) {
	$name = ocozzio_log_plugin_name( $file );
	ocozzio_log( 'plugin_deactivated', sprintf( 'Deactivated plugin "%s"%s', $name, $network ? ' (network)' : '' ), array( 'object_type' => 'plugin', 'object_name' => $file ) );
}

add_action( 'deleted_plugin', 'ocozzio_log_plugin_deleted', 10, 2 );
function ocozzio_log_plugin_deleted( $file, $deleted ) {
	if ( $deleted ) {
		ocozzio_log( 'plugin_deleted', sprintf( 'Deleted plugin "%s"', $file ), array( 'object_type' => 'plugin', 'object_name' => $file ) );
	}
}

add_action( 'switch_theme', 'ocozzio_log_theme_switched', 10, 3 );
function ocozzio_log_theme_switched( $new_name, $new_theme, $old_theme ) {
	ocozzio_log( 'theme_switched', sprintf( 'Switched theme from "%s" to "%s"', $old_theme->get( 'Name' ), $new_name ), array( 'object_type' => 'theme', 'object_name' => $new_theme->get_stylesheet() ) );
}

add_action( 'upgrader_process_complete', 'ocozzio_log_upgrader', 10, 2 );
function ocozzio_log_upgrader( $upgrader, $extra ) {
	$type   = $extra['type'] ?? '';
	$action = $extra['action'] ?? '';
	if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! in_array( $action, array( 'install', 'update' ), true ) ) {
		return; // Core is logged by _core_updated_successfully; translations aren't worth an entry.
	}

	if ( 'install' === $action ) {
		$data = 'plugin' === $type ? ( $upgrader->new_plugin_data ?? array() ) : ( $upgrader->new_theme_data ?? array() );
		$name = $data['Name'] ?? '';
		if ( ! $name && method_exists( $upgrader, 'plugin_info' ) && 'plugin' === $type ) {
			$name = (string) $upgrader->plugin_info();
		}
		ocozzio_log( $type . '_installed', sprintf( 'Installed %s "%s"', $type, $name ), array( 'object_type' => $type, 'object_name' => $name ) );
		return;
	}

	$items = (array) ( $extra[ $type . 's' ] ?? ( isset( $extra[ $type ] ) ? array( $extra[ $type ] ) : array() ) );
	foreach ( $items as $item ) {
		if ( 'plugin' === $type ) {
			$name    = ocozzio_log_plugin_name( $item );
			$plugins = get_plugins();
			$version = $plugins[ $item ]['Version'] ?? '';
		} else {
			$theme   = wp_get_theme( $item );
			$name    = $theme->exists() ? $theme->get( 'Name' ) : $item;
			$version = $theme->exists() ? $theme->get( 'Version' ) : '';
		}
		ocozzio_log( $type . '_updated', sprintf( 'Updated %s "%s"%s', $type, $name, $version ? ' to ' . $version : '' ), array( 'object_type' => $type, 'object_name' => $item, 'details' => array( 'version' => $version ) ) );
	}
}

add_action( '_core_updated_successfully', 'ocozzio_log_core_updated' );
function ocozzio_log_core_updated( $version ) {
	ocozzio_log( 'core_updated', sprintf( 'Updated WordPress to %s', $version ), array( 'object_type' => 'core', 'object_name' => 'WordPress', 'details' => array( 'version' => $version ) ) );
}

/* Site */

add_action( 'export_wp', 'ocozzio_log_export' );
function ocozzio_log_export( $args ) {
	ocozzio_log( 'site_exported', sprintf( 'Exported content (%s)', $args['content'] ?? 'all' ), array( 'object_type' => 'site', 'details' => $args ) );
}

add_action( 'updated_option', 'ocozzio_log_option', 10, 3 );
function ocozzio_log_option( $option, $old, $value ) {
	$watched = apply_filters( 'ocozzio_log_watched_options', array( 'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'blog_public', 'permalink_structure' ) );
	if ( ! in_array( $option, $watched, true ) || ! is_scalar( $old ) || ! is_scalar( $value ) ) {
		return;
	}
	ocozzio_log( 'option_updated', sprintf( 'Changed setting "%s" from "%s" to "%s"', $option, $old, $value ), array( 'object_type' => 'option', 'object_name' => $option, 'details' => array( 'from' => $old, 'to' => $value ) ) );
}

/* -------------------------------------------------------------------------
 * Admin: dashboard widget + full log page
 * ---------------------------------------------------------------------- */

function ocozzio_log_who( $row ) {
	return $row->user_login ? $row->user_login : ( 'login_failed' === $row->action ? '—' : __( 'System', 'ocozzio' ) );
}

add_action( 'wp_dashboard_setup', 'ocozzio_log_dashboard_widget' );
function ocozzio_log_dashboard_widget() {
	if ( current_user_can( 'manage_options' ) ) {
		wp_add_dashboard_widget( 'ocozzio_activity', __( 'Recent Activity', 'ocozzio' ), 'ocozzio_log_widget_render', null, null, 'side', 'high' );
	}
}

function ocozzio_log_widget_render() {
	global $wpdb;
	$rows = ocozzio_log_ready() ? $wpdb->get_results( 'SELECT * FROM ' . ocozzio_log_table() . ' ORDER BY id DESC LIMIT 10' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.

	if ( ! $rows ) {
		echo '<p>' . esc_html__( 'No activity logged yet.', 'ocozzio' ) . '</p>';
	} else {
		echo '<ul style="margin:0">';
		foreach ( $rows as $row ) {
			printf(
				'<li style="padding:6px 0;border-bottom:1px solid #f0f0f1;margin:0"><strong>%s</strong> %s<br><span class="description" title="%s">%s</span></li>',
				esc_html( ocozzio_log_who( $row ) ),
				esc_html( $row->message ),
				esc_attr( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
				/* translators: %s: human time difference, e.g. "5 mins". */
				esc_html( sprintf( __( '%s ago', 'ocozzio' ), human_time_diff( strtotime( $row->created_at . ' UTC' ) ) ) )
			);
		}
		echo '</ul>';
	}
	echo '<p style="margin-bottom:0"><a href="' . esc_url( admin_url( 'index.php?page=ocozzio-activity-log' ) ) . '">' . esc_html__( 'View full log →', 'ocozzio' ) . '</a></p>';
}

add_action( 'admin_menu', 'ocozzio_log_admin_page' );
function ocozzio_log_admin_page() {
	add_dashboard_page( __( 'Activity Log', 'ocozzio' ), __( 'Activity Log', 'ocozzio' ), 'manage_options', 'ocozzio-activity-log', 'ocozzio_log_page_render' );
}

function ocozzio_log_page_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table    = ocozzio_log_table();
	$per_page = 50;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$f_user   = isset( $_GET['log_user'] ) ? sanitize_user( wp_unslash( $_GET['log_user'] ) ) : '';
	$f_action = isset( $_GET['log_action'] ) ? sanitize_key( $_GET['log_action'] ) : '';
	$f_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
	// phpcs:enable

	echo '<div class="wrap"><h1>' . esc_html__( 'Activity Log', 'ocozzio' ) . '</h1>';

	if ( ! ocozzio_log_ready() ) {
		echo '<p>' . esc_html__( 'The activity log table is not installed yet. Reload the page; if this persists, check the database user can create tables.', 'ocozzio' ) . '</p></div>';
		return;
	}

	$where  = array( '1=1' );
	$params = array();
	if ( $f_user ) {
		$where[]  = 'user_login = %s';
		$params[] = $f_user;
	}
	if ( $f_action ) {
		$where[]  = 'action = %s';
		$params[] = $f_action;
	}
	if ( $f_search ) {
		$like     = '%' . $wpdb->esc_like( $f_search ) . '%';
		$where[]  = '(message LIKE %s OR object_name LIKE %s OR user_login LIKE %s OR ip LIKE %s)';
		$params   = array_merge( $params, array( $like, $like, $like, $like ) );
	}
	$where_sql = implode( ' AND ', $where );

	// phpcs:disable WordPress.DB.PreparedSQL -- table name is internal; values go through prepare().
	$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
	$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
	$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) );
	$users     = $wpdb->get_col( "SELECT DISTINCT user_login FROM {$table} WHERE user_login <> '' ORDER BY user_login" );
	$actions   = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action" );
	// phpcs:enable
	?>
	<form method="get" style="margin:1em 0">
		<input type="hidden" name="page" value="ocozzio-activity-log">
		<select name="log_user">
			<option value=""><?php esc_html_e( 'All users', 'ocozzio' ); ?></option>
			<?php foreach ( $users as $u ) : ?>
				<option value="<?php echo esc_attr( $u ); ?>" <?php selected( $f_user, $u ); ?>><?php echo esc_html( $u ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="log_action">
			<option value=""><?php esc_html_e( 'All events', 'ocozzio' ); ?></option>
			<?php foreach ( $actions as $a ) : ?>
				<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $f_action, $a ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $a ) ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $f_search ); ?>" placeholder="<?php esc_attr_e( 'Search messages, objects, IPs', 'ocozzio' ); ?>">
		<?php submit_button( __( 'Filter', 'ocozzio' ), 'secondary', '', false ); ?>
		<?php if ( $f_user || $f_action || $f_search ) : ?>
			<a class="button-link" style="margin-left:8px" href="<?php echo esc_url( admin_url( 'index.php?page=ocozzio-activity-log' ) ); ?>"><?php esc_html_e( 'Clear', 'ocozzio' ); ?></a>
		<?php endif; ?>
		<span style="float:right;line-height:30px">
			<?php
			/* translators: %s: number of entries. */
			echo esc_html( sprintf( _n( '%s entry', '%s entries', $total, 'ocozzio' ), number_format_i18n( $total ) ) );
			?>
		</span>
	</form>

	<table class="widefat striped">
		<thead>
			<tr>
				<th style="width:170px"><?php esc_html_e( 'When', 'ocozzio' ); ?></th>
				<th style="width:160px"><?php esc_html_e( 'User', 'ocozzio' ); ?></th>
				<th><?php esc_html_e( 'Event', 'ocozzio' ); ?></th>
				<th style="width:130px"><?php esc_html_e( 'IP', 'ocozzio' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No entries found.', 'ocozzio' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i:s' ) ); ?></td>
					<td>
						<?php echo esc_html( ocozzio_log_who( $row ) ); ?>
						<?php if ( $row->user_role ) : ?>
							<br><span class="description"><?php echo esc_html( $row->user_role ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php echo esc_html( $row->message ); ?>
						<?php if ( $row->details ) : ?>
							<details><summary class="description" style="cursor:pointer"><?php esc_html_e( 'Details', 'ocozzio' ); ?></summary>
								<pre style="white-space:pre-wrap;margin:4px 0 0"><?php echo esc_html( wp_json_encode( json_decode( $row->details ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
							</details>
						<?php endif; ?>
					</td>
					<td><code><?php echo esc_html( $row->ip ); ?></code></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	$pages = (int) ceil( $total / $per_page );
	if ( $pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			)
		) . '</div></div>';
	}
	echo '</div>';
}
