<?php
/**
 * Fallback template: blog index, archives and search results.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<section id="<?php echo esc_attr( ocozzio_prefix() ); ?>-posts">
	<div class="container-responsive">
		<?php if ( is_home() && ! is_front_page() ) : ?>
			<h1><?php single_post_title(); ?></h1>
		<?php elseif ( is_archive() ) : ?>
			<?php the_archive_title( '<h1>', '</h1>' ); ?>
		<?php elseif ( is_search() ) : ?>
			<h1><?php printf( esc_html__( 'Search results for: %s', 'ocozzio' ), esc_html( get_search_query() ) ); ?></h1>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<?php while ( have_posts() ) : the_post(); ?>
				<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
					<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php the_excerpt(); ?>
				</article>
			<?php endwhile; ?>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing found.', 'ocozzio' ); ?></p>
		<?php endif; ?>
	</div>
</section>
<?php
get_footer();
