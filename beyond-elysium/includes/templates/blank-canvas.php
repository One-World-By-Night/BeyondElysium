<?php
/**
 * Bare WordPress page template with no theme chrome.
 *
 * Skips get_header()/get_footer(), so no theme header, nav, footer or
 * page-title markup renders on this page. wp_head()/wp_footer() still
 * fire so WordPress core and other plugins that depend on them (including
 * this plugin's own React bundle) continue to work. Used for a print-
 * friendly blank canvas layout.
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
