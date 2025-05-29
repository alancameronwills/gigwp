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
 * Version: 1.1
 * TODO: delete, dup, asif UI; booking link, price, adjustable format
 */

/*
 Place shortcode [gigwp ] in a page. 

 While signed in, open the page and click "Add" (bottom right).
 Select one or more pictures; optionally set titles and put dates & info in the caption.
 One or two dates with month in the middle and 4-digit year. E.g.:
     31/1/2026 - 2026-06-02 £4 book by text

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

// Our .js and .css files
function gigwp_nqscripts()
{
    if (current_user_can('edit_others_pages')) {
        wp_enqueue_script("gigwpeditjs", "/wp-content/plugins/gigwp/gigwp-edit.js", ["jquery-core"]);
    }
    wp_enqueue_script("gigwpjs", '/wp-content/plugins/gigwp/gigwp.js', ["jquery-core"]);
    wp_enqueue_style("gigwpcss", '/wp-content/plugins/gigwp/gigwp.css');
}
add_action('wp_enqueue_scripts', 'gigwp_nqscripts');


add_action('wp_enqueue_scripts', function ($hook_suffix) {
    wp_enqueue_media();
});

function gigwp_install() {}
function gigwp_deactivate() {}
function gigwp_uninstall() {}
register_activation_hook(__FILE__, 'gigwp_install');
register_deactivation_hook(__FILE__, 'gigwp_deactivate');
register_uninstall_hook(__FILE__, 'gigwp_uninstall');


// ******* Shortcode **********

add_shortcode("gigiau", "gigwp_events_list_shortcode");

function gigwp_events_list_shortcode($attributes = [])
{
    global $gigwp_category_id;
    extract(shortcode_atts(
        [
            'layout' => "title image dates", // order of appearance in each gig
            'width' => 340,  // px width of images
            'asIfDate' => null, // Display from this date - can also use ?asif=YYYY-MM-DD
            'category' => GIGWP_CATEGORY,
            'popImages' => true // expand image on user click
        ],
        $attributes
    ));

    // If this is first time:
    $gigwp_category_id = wp_create_category($category);

    return gigwp_gig_list($_GET['asif'] ?? $asIfDate ?? date('Y-m-d'), $category, $width, $popImages, $layout);
}



function gigwp_gig_list($fromDate, $category, $width, $popImages, $layout)
{
    $gigs = gigwp_get_gigs($fromDate, $category);

    return gigwp_gig_show($gigs, $width, $category, $popImages, $layout);
}

/**
 * Return a sorted list of Posts representing Gigs
 * @param (Date) $fromDate Earliest event start date to retrieve
 * @param (string) $category $Category of Post used for gigs
 * 
 */

function gigwp_get_gigs($fromDate, $category)
{
    // https://developer.wordpress.org/reference/classes/WP_Query/parse_query/
    $query = [
        'category_name' => $category,
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
        $item = [
            'id' => $id,
            'link' => get_permalink(),
            'title' => get_the_title(),
            'content' => get_the_content(),
            'pic' => get_the_post_thumbnail_url(null, "full"),
            'meta' => array_map(function ($m) {
                return $m[0];
            }, get_post_meta($id))
        ];

        $gigs[] = $item;
    endwhile;
    wp_reset_postdata();
    //echo json_encode($gigs);
    usort($gigs, function ($a, $b) {

        return strcmp($a['meta']['dtstart'][0] ?? "", $b['meta']['dtstart'][0] ?? "");
    });
    return $gigs;
}

function gigwp_fdate($dt)
{
    return date_format(date_create($dt), "D jS M Y");
}

/**
 * The HTML template for a single poster. 
 * @param (bool) $isSignedIn - whether the current user can edit the list
 * @param(string) $layout Order of presentation of "title image dates" per gig
 * 
 */
function gigwp_gig_template($isSignedIn, $layout = "title image dates")
{
    ob_start();
?>
    <div class="gig" data-id="%gigid">
        <?php
        $parts = explode(" ", $layout);
        foreach ($parts as $part) {
            switch (substr($part, 0, 1)) {
                case "t":
        ?>
                    <div class="gig-title gig-field">%gigtitle</div>
                <?php
                    break;
                case "i":
                ?>
                    <img src="%gigpic" class="gigpic" />

                <?php
                    break;
                case "d":
                ?>
                    <div class="prop-show">
                        <span class="show-dates">%gigdates</span>
                        <span class="show-info">%gigdtinfo</span>
                    </div>
        <?php
                    break;
            }
        }
        if ($isSignedIn) {
        ?>
            <div class="prop-edit" style="display:none">
                <div>
                    <input class="gig-dtstart gig-field" type="date" value="%gigdtstart"
                        title="Start date" />
                    <span> <span class="datedash">&mdash;</span>
                        <input class="gig-dtend gig-field" type="date" value="%gigdtend"
                            title="End date" />
                    </span>
                </div>
                <div>
                    <input class="gig-dtinfo gig-field" type="text" placeholder="extra info" value="%gigdtinfo" />
                </div>
                <fieldset>
                    <legend>Automatic recurrence</legend>
                    Recurs on day of week:
                    <select class="gig-recursday">
                        %gigdayoptions
                    </select>
                    <br />
                    <fieldset class="gig-recursweek">
                        <legend>Recurs in weeks of month:</legend>
                        %gigweekoptions
                    </fieldset>
                </fieldset>
            </div>
            <div class="gig-controls unlessEditing">
                <button class="delete-button" onclick="deleteGig('%gigid')">Delete</button>
            </div>

        <?php
        }
        ?>
    </div>
<?php
    return ob_get_clean();
}

/**
 * Return the HTML for displaying the list of gigs.
 * 
 * @param (Array(Post)) $gigs Posts in the Gig category retrieved from WP DB
 * @param (int) $width Width of each gig poster on the displayed list
 * @param (string) $category The category to which gigs belong
 * @param (bool) $popImages If true, expand images on user click
 * @param (string) $layout Order in which to show the parts of each gig: "title image dates"
 * 
 */
function gigwp_gig_show($gigs, $width, $category, $popImages, $layout)
{
    global $gigwp_category_id;
    ob_start();
    // The JSON list of gigs is uploaded inline
    // The HTML template for each gig is also inline
    // On page load, JS elaborates the HTML
?>
    <script id="gig-json" type="application/json">
        <?= json_encode($gigs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK); ?>
    </script>
    <script id="gigtemplate" type="text/html">
        <?= gigwp_gig_template(current_user_can('edit_others_pages'), $layout) ?>
    </script>
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
        <?php }
        // On page load, list is inserted here.
        ?>
        <div class='gigs'>

        </div>
    </div>
    <script>
        jQuery(() => {
            fillGigList(jQuery("#gig-json").text(), jQuery("#gigtemplate").html());


            <?php
            if ($popImages) {
            ?>
                nevernExpandImages();
            <?php
            }
            ?>
        })
    </script>
<?php

    return ob_get_clean();
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
