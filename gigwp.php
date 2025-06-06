<?php

/**
 * @package Gigiau Events Posters
 * @version 1.4
 * @wordpress-plugin
 * 
 * Plugin Name: Gigiau Events Posters
 * Description: Events listings based on posters. 
 * Author: Alan Cameron Wills
 * Version: 1.4
 * TODO: srcset; !scrunched editing prompts; upload - show progress; nth week, unmonthed
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
        wp_enqueue_script("gigwpeditjs", plugin_dir_url(__FILE__) . "gigwp-edit.js", ["jquery-core"]);
    }
    wp_enqueue_script("gigwpjs", plugin_dir_url(__FILE__) . 'gigwp.js', ["jquery-core"]);
    //wp_enqueue_style("gigwpcss", plugin_dir_url(__FILE__) . 'gigwp.css');
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
            'layout' => "image title dates venue", // order of appearance in each gig
            'width' => 340,  // px width of images,
            'height' => 0,   // px height of images - defaults to sqrt(2)*width
            'asIfDate' => null, // Display from this date - can also use ?asif=YYYY-MM-DD
            'category' => GIGWP_CATEGORY,
            'popImages' => true, // expand image on user click
            'venue' => "",
            'book' => "Book Tickets"
        ],
        $attributes
    ));

    if ($height == 0) {
        $height = floor(1.4214 * $width);
    }

    // If this is first time:
    $gigwp_category_id = wp_create_category($category);

    $fromDate = $_GET['asif'] ?? $asIfDate ?? date('Y-m-d');


    if (!preg_match("/^20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]/", $fromDate)) {
        $fromDate = date('Y-m-d');
    }


    return gigwp_gig_list($fromDate, $category, $width, $height, $popImages, $layout, $_GET['json'] ?? false, $venue, $book);
}



function gigwp_gig_list($fromDate, $category, $width, $height, $popImages, $layout, $json, $defaultVenue, $DefaultBookButtonLabel)
{
    $postDated = gigwp_get_gigs_with_recurs($fromDate, $category);
    if ($json == 2) {
        return "<pre id='gigiau'>\n" . json_encode($postDated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</pre>";
    }
    $postIds = array_map(function ($item) {
        return $item->ID;
    }, $postDated);
    $gigs = gigwp_get_gigs($fromDate, $category, $postIds);
    if ($json) {
        return "<pre id='gigiau'>\n" . json_encode($gigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</pre>";
    }

    return gigwp_gig_show($gigs, $width, $height, $category, $popImages, $layout, $defaultVenue, $fromDate, $DefaultBookButtonLabel);
}

function gigwp_get_gigs_with_recurs($fromDate, $category)
{
    global $wpdb;
    // dtend > now || recursday > 0 && dtend = dtstart
    $query = " 
        SELECT ID, post_title , 
            pm.meta_value AS 'dtend' , 
            pm2.meta_value AS 'dtstart',
            pm3.meta_value AS 'recursday'
        FROM $wpdb->posts p
            INNER JOIN $wpdb->postmeta pm ON pm.post_id = p.ID
            INNER JOIN $wpdb->postmeta pm2 ON pm2.post_id = p.ID
            INNER JOIN $wpdb->postmeta pm3 ON pm3.post_id = p.ID
        WHERE p.post_status = 'publish'
        AND p.post_type = 'post'
        AND pm.meta_key = 'dtend'
        AND pm2.meta_key = 'dtstart'
        AND pm3.meta_key = 'recursday'
        AND (
            pm.meta_value >= '$fromDate'
            OR (
                pm3.meta_value > 0
                AND pm.meta_value = pm2.meta_value
            )
        )
        ";

    return $wpdb->get_results($query);
}

/**
 * Return a sorted list of Posts representing Gigs
 * @param (Date) $fromDate Earliest event start date to retrieve
 * @param (string) $category of Post used for gigs
 * 
 */
function gigwp_get_gigs($fromDate, $category, $postIds = [])
{
    // https://developer.wordpress.org/reference/classes/WP_Query/parse_query/

    $qExpr = [
        'category_name' => $category,
        'suppress_filters' => true,
        'nopaging' => true
    ];
    if (count($postIds) > 0) {
        $qExpr['post__in'] = $postIds;
    } else {
        $qExpr['meta_query'] = [
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
        ];
    }


    $gigs = [];
    $query = new WP_QUERY($qExpr);
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

    // Reset start dates of recurrent gigs:
    for ($i = 0; $i < count($gigs); $i++) { // foreach creates a copy
        // if start date is past, and there is a recurrence
        $gm = &$gigs[$i]['meta']; // Must be reference, else we are writing to a copy
        if (strcmp($gm['dtstart'], $fromDate) < 0 && $gm['recursday']) {
            // Recurrence - set the start date
            $nextDate = gigwp_nthDayOfMonth($gm['recursday'], $gm['recursweeks'], new DateTime($fromDate));
            $nextDateString = date_format($nextDate, 'Y-m-d');
            $gm['dtsince'] = $gm['dtstart']; // keep old start date
            if ($gm['dtstart'] == $gm['dtend']) {
                // Preserve "recurs forever" flag i.e. dtstart==dtend
                $gm['dtend'] = $nextDateString;
            }
            $gm['dtstart'] = $nextDateString;
        }
        // Note that if user edits gig, they will permanently reset the start date
    }
    usort($gigs, function ($a, $b) {
        return strcmp($a['meta']['dtstart'] ?? "", $b['meta']['dtstart'] ?? "");
    });
    return $gigs;
}

function gigwp_nthDayOfMonth($dayOfWeek, $weeksInMonth, $today)
{
    if (!$today) {
        $today = new DateTime('NOW');
    }
    $result = NULL;
    $current_month = $today->format("n") + 0;
    $current_date = $today->format("d") + 0;

    // First day of current month
    $diff = $current_date - 1;
    $dt = clone $today;
    $dt->sub(new DateInterval("P{$diff}D"));
    //echo "First of current month: {$dt->format("l Y M d")}\n";

    // First required day of current month
    $focm = $dt->format("N") + 0;
    //echo "First day of current month is $focm\n";
    $freqocm = ($dayOfWeek - $focm + 7) % 7;
    //echo "First required day of current month is {$freqocm}\n";
    $dt->add(new DateInterval("P{$freqocm}D"));

    $monthCount = 0;
    $weekCount = 0;
    $currentMonth = 0;
    $checkWeek = 0;
    $result = NULL;
    for ($i = 0; $i < 10; $i++) {
        $weekCount++;
        $newMonth = $dt->format("n") + 0;
        if ($currentMonth != $newMonth) {
            $monthCount++;
            $currentMonth = $newMonth;
            $weekCount = 1;
            $checkWeek = 0;
        }
        $later = $dt >= $today;
        $found = substr($weeksInMonth, $checkWeek, 1) == $weekCount;
        if (!$found && substr($weeksInMonth, $checkWeek, 1) == 5 && $weekCount == 4) {
            $nextWeek = clone $dt;
            $nextWeek->add(new DateInterval("P7D"));
            $nextWeekMonth = $nextWeek->format("n") + 0;
            $found = $nextWeekMonth != $currentMonth;
        }

        //echo "$weekCount = {$dt->format("l Y M d")} $later  $found\n";
        if ($result == NULL && $later && $found) {
            $result = clone $dt;
            //return $result;
        }

        if (
            substr($weeksInMonth, $checkWeek, 1) <= $weekCount
            && $checkWeek < strlen($weeksInMonth) - 1
        ) $checkWeek++;
        $dt->add(new DateInterval("P7D"));
    }
    return $result;
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
function gigwp_gig_template($isSignedIn, $layout = "venue image title dates", $defaultVenue = "")
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
                    <div>
                        %bookbutton
                        <div class="gig-title gig-field">%gigtitle
                        </div>
                    </div>
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
                case "v":
                ?>
                    <div class="venue">
                        %venue
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
                    <span class="gig-dtend-group"> <span class="datedash">&mdash;</span>
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
                <fieldset class="venuebooking">
                    <legend>Venue and booking or more info link</legend>
                    <div>
                        <label>Venue (optional):
                            <input class="gig-venue" name="gig-venue" class="gig-field" placeholder="<?= $defaultVenue ?>" value="%venue" />
                        </label>
                    </div>
                    <div>
                        <label>Button label:
                            <input class="gig-booklabel" placeholder="Book" class="gig-field" value="%booklabel" />
                        </label>
                    </div>
                    <div>
                        <label class="gig-bookinglink-group">Button link:
                            <input class="gig-bookinglink" type="url" class="gig-field" placeholder="https://..." value="%bookinglink" />
                        </label>
                    </div>
                    <div>
                        <label>Or link to poster page on this site:
                            <input type="checkbox" class="gig-local-link" %locallink />
                        </label>
                    </div>
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
function gigwp_gig_show($gigs, $width, $height, $category, $popImages, $layout, $defaultVenue, $fromDate, $DefaultBookButtonLabel)
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
        <?= gigwp_gig_template(current_user_can('edit_others_pages'), $layout, $defaultVenue) ?>
    </script>
    <script>
        customElements.define("gigwp-capsule", class extends HTMLElement {
            constructor() {
                super();
                this.attachShadow({
                    mode: "open"
                });
            }
            plus(element) {
                this.shadowRoot.appendChild(element);
            }
            createShadow() {
                // Move explicit subtree into shadow
                Array.from(this.children).forEach(element => this.plus(element));
                return this.shadowRoot;
            }
        });
    </script>
    <gigwp-capsule>
        <link rel="stylesheet" href="<?= plugin_dir_url(__FILE__) ?>gigwp.css" > 
        <div id="giglist" class="giglist">
            <style>
                .gig>div {
                    width: <?= $width ?>px;
                }

                .gigpic {
                    width: <?= $width ?>px;
                    height: <?= $height ?>px;
                    object-fit: contain;
                }
            </style>
            <?php if (current_user_can('edit_others_pages')) {  ?>
                <script>
                    window.gigiauCategoryId = "<?= $gigwp_category_id ?>";
                    window.gigiauCategory = "<?= $category ?>";
                    window.gigiauDefaultBookButtonLabel = "<?= str_replace('"', '', $DefaultBookButtonLabel) ?>";
                </script>
                <div class='controls'>
                    <label>Show as if on: <input type="date" value="<?= $fromDate ?>" oninput="setFromDate(this.value)" /></label>
                    <button id="addButton" title="add event posters" onclick='addGig(event)'>Add</button>
                    <button id="editButton" title="edit the event details" onclick='editGig(event)'>Edit</button>
                    <button id="helpButton" title="help" onclick='helpGigs(event)'>?</button>
                </div>
            <?php }
            ?>
            <div class='gigs'>

            </div>
        </div>
    </gigwp-capsule>
    <script>
        function gigwp(selector) {
            return selector ? window.gigwpCapsuleRoot.querySelector(selector) : window.gigwpCapsuleRoot;
        }
        function gigwpa(selector) {
            return window.gigwpCapsuleRoot.querySelectorAll(selector);
        }
        jQuery(() => {
            window.gigwpCapsuleRoot = document.querySelector("gigwp-capsule").createShadow();
            fillGigList(jQuery("#gig-json").text(), jQuery("#gigtemplate").html());
            <?php
            if ($popImages) {
            ?>
                gigwpExpandImages();
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
