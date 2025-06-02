<?php
/**
 * Template canvas file to render gig posts.
 * Adapted from wp-includes/template-canvas.php
 *
 * @package WordPress
 */

$template_html = gigwp_get_the_block_template_html();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<?php echo $template_html; ?>

<?php wp_footer(); ?>
</body>
</html>