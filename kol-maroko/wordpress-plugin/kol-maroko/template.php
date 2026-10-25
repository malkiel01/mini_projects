<?php
/**
 * התבנית של עמוד קול מרוקו: הכותרת והתחתית של ערכת הנושא, ובאמצע — רק הגרסה מהסטודיו.
 * עובד גם בערכה קלאסית וגם בערכת בלוקים, וגם אם העמוד נבנה באלמנטור (אלמנטור לא נטען כאן בכלל).
 */
if (!defined('ABSPATH')) exit;

$kol_body = '<main id="kol-maroko" class="kol-maroko-main">' . kol_maroko_render() . '</main>';

if (function_exists('wp_is_block_theme') && wp_is_block_theme()) : ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php block_template_part('header'); ?>
<?php echo $kol_body; // phpcs:ignore — HTML חתום מהסטודיו ?>
<?php block_template_part('footer'); ?>
</div>
<?php wp_footer(); ?>
</body>
</html>
<?php else :
    get_header();
    echo $kol_body; // phpcs:ignore — HTML חתום מהסטודיו
    get_footer();
endif;
