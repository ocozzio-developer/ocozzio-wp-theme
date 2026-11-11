<?php
/**
 * Fallback site header (client themes generate their own header.php): document head, skip link, primary nav, and the opening <main>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
	<a class="skip-link" href="#main-content"><?php esc_html_e( 'Skip to main content', 'ocozzio' ); ?></a>
	<div id="body-wrapper">
		<header id="page-header">
			<nav class="<?php echo esc_attr( ocozzio_prefix() ); ?>-navbar" aria-label="<?php esc_attr_e( 'Primary', 'ocozzio' ); ?>">
				<div class="container-responsive">
					<a class="<?php echo esc_attr( ocozzio_prefix() ); ?>-navbar-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php ocozzio_brand(); ?></a>

					<button class="<?php echo esc_attr( ocozzio_prefix() ); ?>-navbar-toggler collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#navbarDropdown" aria-controls="navbarDropdown" aria-expanded="false" aria-label="<?php esc_attr_e( 'Toggle navigation', 'ocozzio' ); ?>">
						<span class="burger-icon"></span>
					</button>

					<div class="collapse <?php echo esc_attr( ocozzio_prefix() ); ?>-navbar-collapse" id="navbarDropdown">
						<?php
						wp_nav_menu(
							array(
								'theme_location' => 'primary',
								'container'      => false,
								'menu_id'        => 'primary-nav',
								'menu_class'     => ocozzio_prefix() . '-nav-list',
								'depth'          => 2,
								'fallback_cb'    => false,
							)
						);
						?>
					</div>
				</div>
			</nav>
		</header>

		<main id="main-content" tabindex="-1">
