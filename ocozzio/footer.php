<?php
/**
 * Fallback site footer (client themes generate their own footer.php): closes <main>, footer nav, and the document.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		</main>

		<footer id="page-footer">
			<div class="container-responsive">
				<nav aria-label="<?php esc_attr_e( 'Footer', 'ocozzio' ); ?>">
					<a class="footer-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php ocozzio_brand(); ?></a>
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'footer',
							'container'      => false,
							'depth'          => 1,
							'fallback_cb'    => false,
						)
					);
					?>
				</nav>
			</div>
		</footer>
	</div>
<?php wp_footer(); ?>
</body>
</html>
