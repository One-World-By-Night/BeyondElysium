<?php
/**
 * Bare WordPress page template with no theme chrome.
 *
 * @var WP_Post $post
 */

defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( get_the_title() ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'be-print-canvas' ); ?>>
<?php
while ( have_posts() ) :
	the_post();
	the_content();
endwhile;
?>
<?php wp_footer(); ?>
</body>
</html>
