<?php

/**
 * @package Gigiau Events Posters
 * @version 1.0
 * @wordpress-plugin
 * 
 * Plugin Name: Gigiau Events Posters
 * Description: Events listings based on posters. 
 * Requires Plugins: wp-api
 * Author: Alan Wills
 * Version: 1.0
 */

/*
 Place shortcode [gigwp ] in a page. 
 While signed in, open the page and click "Add" (bottom right).
 Select one or more pictures; optionally set titles and put dates in the caption.
 (Either single date, or two dates with hyphen: 31/1/2026 - 3/2/2026)
 Click Edit to adjust titles and dates.
 Sign out and look at the same page to see the posters.
 Posters will automatically disappear after their end date.
 Use Recur fields to make date automatically reset after end.
 */

// Requisites for category creation
require_once(ABSPATH . 'wp-config.php');
require_once(ABSPATH . 'wp-includes/class-wpdb.php');
require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');

define("GIGWP_CATEGORY", "gig");
$gigwp_category_id;

function gigwp_nqscripts()
{
    if (current_user_can('edit_others_pages')) {
        wp_enqueue_script("gigwpjs", "/wp-content/plugins/gigwp/gigwp.js", ["jquery-core"]);
    }
    wp_enqueue_style("gigwpcss", '/wp-content/plugins/gigwp/gigwp.css');
}
add_action('wp_enqueue_scripts', 'gigwp_nqscripts');


add_action('wp_enqueue_scripts', function ($hook_suffix) {

    wp_enqueue_media();
});


function gigwp_events_list_shortcode($attributes = [])
{
    global $gigwp_category_id;
    extract(shortcode_atts(
        [
            'asIfDate' => null,
            'category' => GIGWP_CATEGORY,
            'width' => 340,
            'popImages' => true
        ],
        $attributes
    ));

    $gigwp_category_id = wp_create_category($category);

    return gigwp_gig_list($_GET['asif'] ?? $asIfDate ?? date('Y-m-d'), $category, $width, $popImages);
}

add_shortcode("gigiau", "gigwp_events_list_shortcode");


function gigwp_install() {}
function gigwp_deactivate() {}
function gigwp_uninstall() {}
register_activation_hook(__FILE__, 'gigwp_install');
register_deactivation_hook(__FILE__, 'gigwp_deactivate');
register_uninstall_hook(__FILE__, 'gigwp_uninstall');


function gigwp_get_gigs($fromDate, $category)
{
    // https://developer.wordpress.org/reference/classes/WP_Query/parse_query/
    $query = [
        'category_name' => $category,
        // dtend && dtend>now || !dtend && (!dtstart || dtstart>now)
        'meta_query' => [
            'relation' => 'OR',
            [
                'key' => 'dtend',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => 'dtend',
                'compare' => '=',
                'value' => '',
            ],
            [
                'key' => 'dtend',
                'value' => $fromDate,
                'compare' => '>=',
                'type' => 'DATE',
            ]
        ]
    ];


    $gigs = [];
    $query = new WP_QUERY($query);
    while ($query->have_Posts()):
        $query->the_post();
        $id = get_the_id();
        $item = [];
        $item['id'] = $id;
        $item['title'] = get_the_title();
        $item['content'] = get_the_content();
        $item['thumbnail_image'] = get_the_post_thumbnail_url(null, "full");
        $item['dtstart'] = get_post_meta($id, "dtstart", true);
        $item['dtinfo'] = get_post_meta($id, "dtinfo", true);
        $item['dtend'] = get_post_meta($id, "dtend", true);
        $item['recursday'] = get_post_meta($id, "recursday", true);
        $item['recursweeks'] = get_post_meta($id, "recursweeks", true);

        $gigs[] = $item;
    endwhile;
    wp_reset_postdata();
    usort($gigs, function ($a, $b) {
        return strcmp($a['dtstart'] ?? "", $b['dtstart'] ?? "");
    });
    return $gigs;
}

function gigwp_fdate($dt)
{
    return date_format(date_create($dt), "D jS M Y");
}

function gigwp_gig_show($gigs, $width, $category, $popImages)
{
    global $gigwp_category_id;
    ob_start();
?>
    <style>
        .gig>div {
            width: <?= $width ?>px;
        }

        .gigpic {
            width: <?= $width ?>px;
        }
    </style>
    <div class='giglist'>
        <?php if (current_user_can('edit_others_pages')) {  ?>
            <script>
                window.gigiauCategoryId = "<?= $gigwp_category_id ?>";
                window.gigiauCategory = "<?= $category ?>";
            </script>
            <div class='controls'>
                <button id="addButton" onclick='addGig()'>Add</button>
                <button id="editButton" onclick='editGig()'>Edit</button>
            </div>
        <?php } ?>
        <?php
        foreach ($gigs as $gig) {
        ?>
            <div class="gig" data-id="<?= $gig['id'] ?>">
                <div class="gig-title gig-field"><?= $gig['title'] ?></div>
                <img src="<?= $gig['thumbnail_image'] ?>" class="gigpic" />
                <div class="prop-show">
                    <span class="show-dtstart"><?= gigwp_fdate($gig['dtstart']) ?></span>
                    <?php
                    if ($gig['dtstart'] != $gig['dtend']) {
                    ?>
                        <span class="show-dtend"> - <?= gigwp_fdate($gig['dtend']) ?></span>
                    <?php
                    }
                    ?>
                    <span class="show-info"><?= $gig['dtinfo'] ?></span>
                </div>

                <?php if (current_user_can('edit_others_pages')) {  ?>
                    <div class="prop-edit" style="display:none">
                        <div>
                            <input class="gig-dtstart gig-field" type="date" value="<?= $gig['dtstart'] ?>" />
                            <span> -
                                <input class="gig-dtend gig-field" type="date" value="<?= $gig['dtend'] ?>" />
                            </span>
                        </div>
                        <div><input class="gig-dtinfo gig-field" type="text" placeholder="extra info" value="<?= $gig['dtinfo'] ?>" /></div>

                        <fieldset>
                            <legend>Recurrence</legend>
                            Recurs day:
                            <select class="gig-recursday">
                                <?php 
                                $day = ["-","Mon","Tue","Wed","Thu","Fri","Sat","Sun"];
                                for ($i=0;$i<=7;$i++) {
                                    ?>
                                    <option value="<?=$i?>" <?=($i==$gig['recursday']?"selected" : "")?>><?=$day[$i]?></option>
                                    <?php
                                } 
                                ?>
                            </select>
                            <br />
                            <fieldset class="gig-recursweek">
                                <legend>Recurs weeks of month</legend>
                                <?php
                                for ($i = 1; $i <= 5; $i++) {
                                    $id = "gig-rw-$i";
                                ?>
                                    <span>
                                        <input type="checkbox" id="<?= $id ?>" name="<?= $id ?>"
                                            <?= (strpos("  " . $gig['recursweeks'], "" . $i) ? "checked" : "") ?> />
                                        <label for="<?= $id ?>"><?=($i==5 ? "last" : $i)?></label>
                                    </span>
                                <?php
                                }
                                ?>
                            </fieldset>
                        </fieldset>
                    </div>
                <?php } ?>
            </div>
        <?php
        }
        ?>
    </div>
    <?php
    if ($popImages) {
    ?>
        <script>
            function nevernExpandImg(src) {
                //window.open(src, "expandedpix");
                if (src) {
                    jQuery("#nevernBigPicImg")[0].src = src;
                    jQuery("#nevernBigPic").show();
                } else {
                    jQuery('#nevernBigPic').hide();
                    jQuery("#nevernBigPicImg")[0].src = "";
                }
            }

            function nevernExpandImages() {
                let html = `<div ` +
                    `style="position:fixed;top:0;left:0;height:100%;width:100%;background-color:black;z-index:99999;cursor:pointer;display:none;" ` +
                    `id="nevernBigPic" onclick="nevernExpandImg('')" onkeydown="nevernExpandImg('')">` +
                    `<img id="nevernBigPicImg" alt="image expanded to fill screen - ESC to collapse"` +
                    ` style="height:100%;width:100%;object-fit:contain;"  onkeydown="nevernExpandImg('')" src=""/></div>`;
                jQuery(document.body).append(html);

                jQuery(".gig img").click(function() {
                    let img = jQuery(this)[0];
                    nevernExpandImg(img.src);
                }).css("cursor", "pointer");
                jQuery("body").keydown(event => {
                    if (event.keyCode === 27) nevernExpandImg();
                });

            }

            jQuery(function() {
                nevernExpandImages();
            })
        </script>
<?php
    } // if $popImages

    return ob_get_clean();
}

function gigwp_gig_list($fromDate, $category, $width, $popImages)
{
    $gigs = gigwp_get_gigs($fromDate, $category);

    return gigwp_gig_show($gigs, $width, $category, $popImages);
}

// ************ Editor REST ***********

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script('wp-api');
});


// Allow metadata to be updated via API
// https://stackoverflow.com/questions/42384841/wp-rest-api-create-posts-with-custom-fields-generated-by-cpt/53237658#53237658
add_action("rest_insert_post", function ($post, $request, $creating) {
    $metas = $request->get_param("meta");
    if (is_array($metas)) {
        foreach ($metas as $name => $value) {
            update_post_meta($post->ID, $name, $value);
        }
    }
}, 10, 3);
