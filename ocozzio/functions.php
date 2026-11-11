<?php
/**
 * Ocozzio base theme: setup, assets and site-wide behaviour shared by every client theme.
 *
 * Client (child) themes hold the generated files: header.php, footer.php, page-<page>.php,
 * style.css (below its header), css/templates/<page>.css and js/main.js. Client-prefixed
 * classes come from ocozzio_prefix(), read from the child theme's folder name (rxp-theme -> rxp).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Parent-theme modules (get_template_directory: always this theme, even with a child theme active).
require_once get_template_directory() . '/inc/tracking.php';
require_once get_template_directory() . '/inc/activity-log.php';

/**
 * The client code used to prefix generated classes: the child theme folder without "-theme".
 * Override with add_filter( 'ocozzio_prefix', ... ) if a child folder doesn't follow that pattern.
 */
function ocozzio_prefix() {
	$prefix = preg_replace( '/-theme$/', '', get_stylesheet() );
	return apply_filters( 'ocozzio_prefix', $prefix );
}

add_action( 'after_setup_theme', 'ocozzio_setup' );
function ocozzio_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'ocozzio' ),
			'footer'  => __( 'Footer Menu', 'ocozzio' ),
		)
	);

	add_image_size( 'square-thumb', 750, 750, true );
}

/**
 * Enqueue a child theme file with its modified time as the version (cache busting).
 */
function ocozzio_enqueue( $handle, $file ) {
	$path = get_stylesheet_directory() . '/' . $file;
	if ( ! file_exists( $path ) ) {
		return;
	}
	$url = get_stylesheet_directory_uri() . '/' . $file;
	if ( '.js' === substr( $file, -3 ) ) {
		wp_enqueue_script( $handle, $url, array(), filemtime( $path ), true );
	} else {
		wp_enqueue_style( $handle, $url, array(), filemtime( $path ) );
	}
}

add_action( 'wp_enqueue_scripts', 'ocozzio_enqueue_assets' );
function ocozzio_enqueue_assets() {
	global $template;
	ocozzio_enqueue( 'ocozzio-style', 'style.css' );
	ocozzio_enqueue( 'ocozzio-main', 'js/main.js' );

	// The stylesheet matching the template WordPress chose: page-about.php -> about.css, single-video.php -> single-video.css.
	if ( $template ) {
		$name = preg_replace( '/^page-/', '', basename( $template, '.php' ) );
		ocozzio_enqueue( 'ocozzio-page', 'css/templates/' . sanitize_file_name( $name ) . '.css' );
	}
}

/**
 * Primary menu markup to match the starter's nav styles and main.js dropdowns.
 */
add_filter( 'nav_menu_css_class', 'ocozzio_menu_item_classes', 10, 4 );
function ocozzio_menu_item_classes( $classes, $item, $args, $depth ) {
	if ( ! empty( $args->ocozzio_item_class ) ) {
		$classes[] = $args->ocozzio_item_class;
	}
	if ( 'primary' === $args->theme_location && 0 === $depth ) {
		$p         = ocozzio_prefix();
		$classes[] = $p . '-nav-item';
		if ( in_array( 'menu-item-has-children', $classes, true ) ) {
			$classes[] = $p . '-dropdown';
		}
	}
	return $classes;
}

add_filter( 'nav_menu_link_attributes', 'ocozzio_menu_link_attributes', 10, 4 );
function ocozzio_menu_link_attributes( $atts, $item, $args, $depth ) {
	if ( ! empty( $args->ocozzio_link_class ) ) {
		$atts['class'] = trim( ( $atts['class'] ?? '' ) . ' ' . $args->ocozzio_link_class );
	}
	if ( 'primary' === $args->theme_location ) {
		$p = ocozzio_prefix();
		if ( 0 === $depth ) {
			$atts['class'] = $p . '-nav-link';
			if ( in_array( 'menu-item-has-children', (array) $item->classes, true ) ) {
				$atts['class']         .= ' ' . $p . '-dropdown-toggle';
				$atts['data-bs-toggle'] = 'dropdown';
				$atts['role']           = 'button';
				$atts['aria-expanded']  = 'false';
			}
		} else {
			$atts['class'] = $p . '-dropdown-item';
		}
	}
	if ( 'footer' === $args->theme_location ) {
		$atts['class'] = 'footer-link';
	}
	return $atts;
}

add_filter( 'nav_menu_submenu_css_class', 'ocozzio_submenu_classes', 10, 2 );
function ocozzio_submenu_classes( $classes, $args ) {
	if ( ! empty( $args->ocozzio_submenu_class ) ) {
		$classes[] = $args->ocozzio_submenu_class;
	}
	if ( 'primary' === $args->theme_location ) {
		$classes[] = ocozzio_prefix() . '-dropdown-menu';
	}
	return $classes;
}

/**
 * A field of the current (or given) post: ACF's get_field() when ACF is active, post meta otherwise.
 * $format = false returns the raw stored value (e.g. an attachment ID for image fields).
 */
function ocozzio_field( $name, $post_id = null, $format = true ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	if ( function_exists( 'get_field' ) ) {
		$value = get_field( $name, $post_id, $format );
	} else {
		$value = get_post_meta( $post_id, $name, true );
	}
	if ( is_array( $value ) ) {
		if ( isset( $value['url'] ) ) {
			return $value['url'];
		}
		if ( isset( $value['ID'] ) ) {
			return $value['ID'];
		}
	}
	return $value;
}

/**
 * Post IDs stored in a relationship / post object field (ACF or raw meta).
 */
function ocozzio_related_ids( $name, $post_id = null ) {
	$value = ocozzio_field( $name, $post_id, false );
	$ids   = array_filter( array_map( 'intval', (array) maybe_unserialize( $value ) ) );
	return $ids ? $ids : array( 0 );
}

/**
 * An attachment image without width/height attributes (sizing is done in CSS).
 */
function ocozzio_image( $attachment_id, $size = 'large', $attr = array() ) {
	if ( ! $attachment_id || ! is_numeric( $attachment_id ) ) {
		return '';
	}
	$html = wp_get_attachment_image( (int) $attachment_id, $size, false, $attr );
	return preg_replace( '/\s(width|height)="\d+"/', '', $html );
}

/**
 * Logo image for the header/footer brand link; falls back to the site name.
 */
function ocozzio_brand() {
	$logo_id = get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		echo wp_get_attachment_image( $logo_id, 'full', false, array( 'class' => 'logo', 'alt' => get_bloginfo( 'name' ) ) );
		return;
	}
	echo esc_html( get_bloginfo( 'name' ) );
}

/**
 * Classic editor for posts and widgets.
 */
add_filter( 'use_block_editor_for_post', '__return_false' );
add_filter( 'gutenberg_use_widgets_block_editor', '__return_false', 100 );
add_filter( 'use_widgets_block_editor', '__return_false' );

/**
 * Enable shortcodes in text widgets.
 */
add_filter( 'widget_text', 'do_shortcode' );

/**
 * Login screen: show the site's custom logo, linking to the site.
 */
add_action( 'login_head', 'ocozzio_login_logo' );
function ocozzio_login_logo() {
	$logo_id = get_theme_mod( 'custom_logo' );
	if ( ! $logo_id ) {
		return;
	}
	$logo = wp_get_attachment_image_url( $logo_id, 'full' );
	echo '<style>.login h1 a { background-image: url("' . esc_url( $logo ) . '") !important; height: 150px !important; width: 320px !important; background-size: contain !important; }</style>';
}

add_filter( 'login_headerurl', 'ocozzio_login_url' );
function ocozzio_login_url() {
	return home_url();
}

/**
 * Open external links in a new tab.
 */
add_action( 'wp_footer', 'ocozzio_external_links_new_tab' );
function ocozzio_external_links_new_tab() {
	?>
	<script>
		document.addEventListener('DOMContentLoaded', function () {
			var siteHost = window.location.hostname;
			document.querySelectorAll('a[href]').forEach(function (link) {
				var url = new URL(link.href, window.location.href);
				if (/^https?:$/.test(url.protocol) && url.hostname !== siteHost) {
					link.setAttribute('target', '_blank');
					link.setAttribute('rel', 'noopener noreferrer');
				}
			});
		});
	</script>
	<?php
}

/**
 * Redirect all 404s to the homepage.
 */
add_action( 'template_redirect', 'ocozzio_redirect_404' );
function ocozzio_redirect_404() {
	if ( is_404() && ! is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
