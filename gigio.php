<?php

    /**
     * @package Gigiau Events Posters
     * @version 1.7.4
     * @wordpress-plugin
     * 
     * Plugin Name: Gigiau Events Posters
     * Plugin URI: https://gigiau.uk/gigio.zip
     * Description: Events listings based on posters. 
     * Author: Alan Cameron Wills
     * Developer: Alan Cameron Wills
     * Developer URI: https://gigiau.uk
     * Version: 1.7.4
     */

    /*
 Place shortcode [gigiau] in a page. 

 While signed in, open the page and click "Add" (bottom right).
 Select one or more pictures; optionally set titles and put dates & info in the caption.
 One or two dates with month in the middle and 4-digit year. E.g.:
     Carol concert 31/1/2026 - 2026-02-14 £4 book by text

 Click Edit to adjust titles and dates.

 Posters will automatically disappear after their end date.
 Use Recur fields to make date automatically reset after end.
 */

    // Requisites for category creation
    require_once(ABSPATH . 'wp-config.php');
require_once(ABSPATH . 'wp-includes/class-wpdb.php');
require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');


define("GIGIO_CATEGORY", "gig");
$GIGIO_CATEGORY_id;

// Our .js and .css files
function gigio_nqscripts()
{
    if (current_user_can('edit_others_pages')) {
        wp_enqueue_script("gigioeditjs", plugin_dir_url(__FILE__) . "gigio-edit.js", ["jquery-core"]);
    }
    wp_enqueue_script("gigiojs", plugin_dir_url(__FILE__) . 'gigio.js', ["jquery-core"]);
    //wp_enqueue_style("gigiocss", plugin_dir_url(__FILE__) . 'gigio.css');
}
add_action('wp_enqueue_scripts', 'gigio_nqscripts');


add_action('wp_enqueue_scripts', function ($hook_suffix) {
    wp_enqueue_media();
});

function gigio_install() {}
function gigio_deactivate() {}
function gigio_uninstall() {}
register_activation_hook(__FILE__, 'gigio_install');
register_deactivation_hook(__FILE__, 'gigio_deactivate');
register_uninstall_hook(__FILE__, 'gigio_uninstall');


// ******* Shortcode **********

add_shortcode("gigiau", "gigio_events_list_shortcode");

function gigio_events_list_shortcode($attributes = [])
{
    global $GIGIO_CATEGORY_id;

    extract(shortcode_atts(
        [
            'layout' => "shortdate image title dates venue", // order of appearance in each gig
            'width' => 0,  // px width of images,
            'height' => 0,   // px height of images - defaults to sqrt(2)*width
            'asIfDate' => null, // Display from this date - can also use URL ?asif=YYYY-MM-DD
            'category' => GIGIO_CATEGORY,
            'popImages' => true, // expand image on user click
            'venue' => "",
            'book' => "Book Tickets",
            'align' => "base", //bottom | top | base | cover | columns 
            'strip' => false, // true -> single horizontal sliding row; false -> rows with wraparound
            'max' => 0, // max count of items; typically use with strip
            'background' => "whitesmoke",
            'headercolor' => "#303030",
            'venueinfilename' => false // Poster filename format: Title YYYY-MM-DD[-YYYY-MM-DD] [Extra info | Venue]
        ],
        $attributes
    ));

    $valid_width = ($width && $width > 30 && $width < 1000 ? $width : ($strip ? 270 : 340));
    $valid_height = ($height && $height > 30 && $height < 2000 ? $height : floor(1.42 * $valid_width));

    $p = [
        'layout' => validate_param($layout, "/[a-z ]{3,40}/", "shortdate image title dates venue"),
        'width' => $valid_width,
        'height' => $valid_height,
        'fromDate' => validate_param($_GET['asif'] ?? $asIfDate, "/^20[0-9][0-9]-[0-9][0-9]-[0-9][0-9]/", date('Y-m-d')),
        'category' => validate_param($category, "/^[a-z]+$/", GIGIO_CATEGORY),
        'popimages' => $popImages,
        'venue' => $venue,
        'book' => $book,
        'strip' => $strip,
        'max' => ($max && $max > 0 ? $max : ($strip ? 10 : 0)),
        'align' => validate_param($_GET['align'] ?? get_option("gigioalignment",  $align), "/[-a-z]{1,20}/", "base"),
        'background' => validate_param($background, "/^#[0-9a-fA-F]{6,8}$|^[-a-z]+$|^[a-z]+?\([0-9,]+\)$/", "whitesmoke"),
        'headercolor' => validate_param($headercolor, "/^#?[a-zA-Z0-9]{3,24}$/", "#303030"),
        'popImages' => $popImages,
        'json' => $_GET['json'] ?? false,
        'venueinfilename' => $venueinfilename
    ];

    // If this is first time:
    $GIGIO_CATEGORY_id = wp_create_category($p['category']);

    if (current_user_can('edit_others_pages')) {
        if ($_GET['align'] ?? false) {
            update_option("gigioalignment", $align_valid);
        }
    }

    return gigio_gig_list($p);
}

function validate_param($param, $pattern, $default)
{
    $matches = [];
    if (is_string($param) && preg_match($pattern, $param, $matches)) {
        return $matches[0];
    } else {
        return $default;
    }
}



function gigio_gig_list($p)
{
    $postDated = gigio_get_gigs_with_recurs($p['fromDate'], $p['category']);
    if ($p['json'] == 2) {
        return "<pre id='gigiau'>\n" . json_encode($postDated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</pre>";
    }
    $postIds = array_map(function ($item) {
        return $item->ID;
    }, $postDated);
    $gigs = gigio_get_gigs($p['fromDate'], $p['category'], $postIds);
    if ($p['json']) {
        return "<pre id='gigiau'>\n" . json_encode($gigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</pre>";
    }

    if ($p['max'] > 0) {
        $gigs = array_slice($gigs, 0, $p['max']);
    }

    return gigio_gig_show($gigs, $p);
}

function gigio_get_gigs_with_recurs($fromDate, $category)
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
function gigio_get_gigs($fromDate, $category, $postIds = [])
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
            'smallpic' => get_the_post_thumbnail_url(null, "medium"),
            'pic' => get_the_post_thumbnail_url(null, "full"),
            'meta' => array_map(function ($m) {
                return $m[0];
            }, get_post_meta($id))
        ];

        $gigs[] = $item;
    endwhile;
    wp_reset_postdata();

    try {
        // Reset start dates of recurrent gigs:
        for ($i = 0; $i < count($gigs); $i++) { // foreach creates a copy
            // if start date is past, and there is a recurrence
            $gm = &$gigs[$i]['meta']; // Must be reference, else we are writing to a copy
            if (strcmp($gm['dtstart'], $fromDate) < 0 && $gm['recursday']) {
                // Recurrence - set the start date
                $nextDate = gigio_nthDayOfMonth($gm['recursday'], $gm['recursweeks'], new DateTime($fromDate), $gm['recursfortnight'] ? new DateTime($gm['dtstart']) : false);
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
    } catch (Exception $e) {
    }
    usort($gigs, function ($a, $b) {
        return strcmp($a['meta']['dtstart'] ?? "", $b['meta']['dtstart'] ?? "");
    });
    return $gigs;
}

function gigio_nthDayOfMonth($dayOfWeek, $weeksInMonth, $today, $fortnightFrom)
{
    if (!$today) {
        $today = new DateTime('NOW');
    }
    if ($fortnightFrom != false) { // Every two weeks
        $diff = $today->diff($fortnightFrom)->format("%a") * 1;
        $dt = clone $fortnightFrom;
        if ($diff > 0) {
            $increment = (floor($diff / 14) + 1) * 14;
            $dt->add(new DateInterval("P{$increment}D"));
        }
        return $dt;
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


function gigio_fdate($dt)
{
    return date_format(date_create($dt), "D jS M Y");
}

/**
 * The HTML template for a single poster. 
 * @param (bool) $isSignedIn - whether the current user can edit the list
 * @param(string) $layout Order of presentation of "title image dates" per gig
 * 
 */
function gigio_gig_template($isSignedIn, $layout = "venue image title dates", $defaultVenue = "")
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
                    %gigimg
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
                case "s":
                ?>
                    <div class="prop-show">
                        <span class="show-dates">%gigshortdate</span>
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
                    Every 14 days:
                    %gigfortnightoption
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
                            <input class="gig-venue gig-field" name="gig-venue" placeholder="<?= $defaultVenue ?>" value="%venue" />
                        </label>
                    </div>
                    <div>
                        <label>Button label:
                            <input class="gig-booklabel gig-field" placeholder="Book" value="%booklabel" />
                        </label>
                    </div>
                    <div>
                        <label class="gig-bookinglink-group">Button link:
                            <input class="gig-bookinglink gig-field" type="url" placeholder="https://..." value="%bookinglink" />
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
function gigio_gig_show($gigs, $p)
{
    global $GIGIO_CATEGORY_id;
    $alignClass = "align-" . $p['align'];
    ob_start();
    // The JSON list of gigs is uploaded inline
    // The HTML template for each gig is also inline
    // On page load, JS elaborates the HTML
?>
    <script id="gig-json" type="application/json">
        <?= json_encode($gigs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK); ?>
    </script>
    <script id="gigtemplate" type="text/html">
        <?= gigio_gig_template(current_user_can('edit_others_pages'), $p['layout'], $p['venue']) ?>
    </script>
    <script>
        if (!customElements.get("gigio-capsule")) {
            customElements.define("gigio-capsule", class extends HTMLElement {
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
        }
    </script>
    <gigio-capsule>
        <link rel="stylesheet" href="<?= plugin_dir_url(__FILE__) ?>gigio.css">
        <div id="giglist" class="giglist <?= $alignClass ?> <?= $p['strip'] ? "strip" : "" ?>">
            <style>
                #giglist {
                    --pic-width: <?= $p['width'] ?>px;
                    --pic-height: <?= $p['height'] ?>px;
                    --background: <?= $p['background'] ?>;
                    --header-color: <?= $p['headercolor'] ?>;
                }
            </style>
            <?php if (current_user_can('edit_others_pages')) {  ?>
                <script>
                    window.gigWidth = <?= $p['width'] ?>;
                    window.gigiauCategoryId = "<?= $GIGIO_CATEGORY_id ?>";
                    window.gigiauCategory = "<?= $p['category'] ?>";
                    window.gigiauDefaultBookButtonLabel = "<?= str_replace('"', '', $p['book']) ?>";
                    window.gigiauVenueInFilename = "<?= !!$p['venueinfilename'] ?>";
                </script>
                <div class='controls'>
                    <label class="alignment-control">
                        Alignment:
                        <select onchange="setAlignment(this.value)">
                            <option value="">(default)</option>
                            <?php
                            foreach (["columns", "cover", "top", "base", "bottom"] as $option) {
                            ?>
                                <option value='<?= $option ?>' <?= ($option == $p['align'] ? "selected" : "") ?>><?= $option ?></option>
                            <?php
                            }
                            ?>
                        </select>
                    </label>
                    <label>Show as if on: <input type="date" value="<?= $p['fromDate'] ?>" oninput="setFromDate(this.value)" /></label>
                    <button id="addButton" title="add event posters" onclick='addGig(event)'>Add</button>
                    <button id="editButton" title="edit the event details" onclick='editGig(event)'>Edit</button>
                    <button id="helpButton" title="help" onclick='helpGigs(event)'>?</button>
                </div>
            <?php }
            ?>
            <div class='gigs'>
            </div>
            <?php if (false && $p['strip']) { // No scroll controls now
            ?>
                <div class="sa_scrollButton sa_scrollerLeft">&nbsp;❱</div>
                <div class="sa_scrollButton sa_scrollerRight">❰&nbsp;</div>
            <?php }
            ?>
        </div>
    </gigio-capsule>
    <script>
        function gigio(selector) {
            return selector ? window.gigioCapsuleRoot.querySelector(selector) : window.gigioCapsuleRoot;
        }

        function gigioa(selector) {
            return window.gigioCapsuleRoot.querySelectorAll(selector);
        }
        jQuery(() => {
            window.gigioCapsuleRoot = document.querySelector("gigio-capsule").createShadow();
            fillGigList(jQuery("#gig-json").text(), jQuery("#gigtemplate").html(), <?= $p['strip'] ?>);
            <?php
            if ($p['popImages']) {
            ?>
                gigioExpandImages();
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
