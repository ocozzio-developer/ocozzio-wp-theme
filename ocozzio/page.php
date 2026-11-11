<?php
/**
 * Default page template, for pages without a generated page-<slug>.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<section id="<?php echo esc_attr( ocozzio_prefix() ); ?>-page">
	<div class="container-responsive">
		<?php while ( have_posts() ) : the_post(); ?>
			<h1><?php the_title(); ?></h1>
			<?php the_content(); ?>
		<?php endwhile; ?>
	</div>
</section>
<?php
get_footer();
