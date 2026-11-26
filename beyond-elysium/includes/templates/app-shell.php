<?php
/**
 * Beyond Elysium's own page shell for My Chronicle and the Storyteller Toolkit: no theme header, footer or page title.
 *
 * @var WP_Post $post
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
	<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php endif; ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'be-app' ); ?>>
<?php wp_body_open(); ?>
<main id="be-app" class="be-app__main">
<?php
while ( have_posts() ) :
	the_post();
	the_content();
endwhile;
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
