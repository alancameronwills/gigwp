<?php

/**
 * @package Gigiau Events Posters
 * @version 2.9.12
 * @wordpress-plugin
 * Description: Got event poster files? Put them on an events listings page with automatic ordering, expiry, and recurrence.
 * Plugin Name: Gigiau Events Posters
 * Plugin URI: https://gigiau.uk/gigio.zip
 * Description: Events listings based on posters.
 * Author: Alan Cameron Wills
 * Developer: Alan Cameron Wills
 * Developer URI: https://gigiau.uk
 * Version: 2.9.12
 */

/*
 Place shortcode [gigiau] in a page. 

 While signed in, open the page and click "Add" (bottom right).
 Select one or more pictures; optionally set titles and put dates & info in the caption.
 One or two dates with month in the middle and 4-digit year, followed by time and other info. E.g.:
     Carol concerts 31-1-2026 2026-02-14 19-30 Nevern Church = £4 book by text

 Click Edit to adjust titles and dates.

 Posters will automatically disappear after their end date.
 Use Recur fields to make date automatically reset after end.
 */

// Auto-update from GitHub releases
require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
$gigioUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/alancameronwills/gigwp/',
    __FILE__,
    'gigiau-events-posters'
);
$gigioUpdateChecker->setBranch('main');
$gigioUpdateChecker->getVcsApi()->enableReleaseAssets();

// Requisites for category creation
require_once(ABSPATH . 'wp-config.php');
require_once(ABSPATH . 'wp-includes/class-wpdb.php');
require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');


define("GIGIO_CATEGORY", "gig");
$GIGIO_CATEGORY_id;

// Our .js and .css files
function gigio_nqscripts()
{
    $jsEditFile = plugin_dir_path(__FILE__) . "gigio-edit.js";
    $jsEditModTime = filemtime($jsEditFile);
    $jsFile = plugin_dir_path(__FILE__) . "gigio.js";
    $jsModTime = filemtime($jsFile);
    if (current_user_can('edit_others_pages')) {
        wp_enqueue_script("gigioeditjs", plugin_dir_url(__FILE__) . "gigio-edit.js", ["jquery-core"], $jsEditModTime);
    }
    wp_enqueue_script("gigiojs", plugin_dir_url(__FILE__) . 'gigio.js', ["jquery-core"], $jsModTime);
    //wp_enqueue_style("gigiocss", plugin_dir_url(__FILE__) . 'gigio.css');
}
add_action('wp_enqueue_scripts', 'gigio_nqscripts');


add_action('wp_enqueue_scripts', function ($hook_suffix) {
    wp_enqueue_media();
});

function gigio_install()
{
    gigio_ensure_tables();
}
function gigio_deactivate()
{
    wp_clear_scheduled_hook('gigio_send_submission_notification');
}
function gigio_uninstall() {}
register_activation_hook(__FILE__, 'gigio_install');
register_deactivation_hook(__FILE__, 'gigio_deactivate');
register_uninstall_hook(__FILE__, 'gigio_uninstall');

/**
 * Name of the custom table holding event-organizer accounts.
 * These are NOT WordPress users: they sign in with a simple email + password
 * on the [gigiau_submit] page and can only submit/edit their own events.
 */
function gigio_organizers_table()
{
    global $wpdb;
    return $wpdb->prefix . 'gigio_organizers';
}

/**
 * Create/upgrade the organizers table. Safe to call repeatedly (dbDelta only
 * applies differences). Called on activation and lazily on first REST/shortcode
 * use so the table exists even if the plugin was updated without re-activation.
 */
function gigio_ensure_tables()
{
    global $wpdb;
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $table = gigio_organizers_table();
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        name VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}


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
            'headercolor' => "#101060",
            'venueinfilename' => false, // Poster filename format: Title YYYY-MM-DD[-YYYY-MM-DD] [Extra info | Venue]
            'notadmin' => "" // If set, redirect non-logged-in visitors to this URL (replacing history)
        ],
        $attributes
    ));

    // Gate the whole listing behind a WordPress login: visitors who are not logged
    // in get bounced to the given URL. location.replace() means the current page is
    // not left in the browser history, so Back won't return here. The ?json feed is
    // exempt so external/non-logged-in consumers can still read the listing as JSON.
    if ($notadmin && !is_user_logged_in() && !isset($_GET['json'])) {
        $redirect = esc_url_raw($notadmin);
        return "<script>window.location.replace(" . json_encode($redirect) . ");</script>";
    }

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
        'venueinfilename' => $venueinfilename,
        // Only WordPress editors see events still awaiting approval, flagged red.
        'includePending' => current_user_can('edit_others_pages')
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
    // The JSON export must never leak unapproved events, even for an admin; only
    // the rendered on-page listing shows pending events (so the admin can moderate).
    $includePending = empty($p['json']) && !empty($p['includePending']);
    $postDated = gigio_get_gigs_with_recurs($p['fromDate'], $p['category'], $includePending);
    if ($p['json'] == 2) {
        return "<pre id='gigiau'>\n" . json_encode($postDated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</pre>";
    }
    $postIds = array_map(function ($item) {
        return $item->ID;
    }, $postDated);
    $gigs = gigio_get_gigs($p['fromDate'], $p['category'], $postIds);
    if ($p['json']) {
        // "::" and "|" are both language separators in text fields (e.g.
        // "English::Welsh" / "English|Welsh"); downstream readers split on "|" to
        // pick the reader's preferred language. Organizers may type "::" since it's
        // easier than "|" on some mobile keyboards, so normalize it to "|" here.
        array_walk_recursive($gigs, function (&$value) {
            if (is_string($value)) {
                $value = str_replace('::', '|', $value);
            }
        });
        return "<pre id='gigiau'>\n" . json_encode($gigs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</pre>";
    }

    if ($p['max'] > 0) {
        $gigs = array_slice($gigs, 0, $p['max']);
    }

    return gigio_gig_show($gigs, $p);
}

/**
 * @param (bool) $includePending When false (public/JSON), exclude events awaiting
 *        approval (meta gigio_approved = '0') and rejected events ('2'). Only the
 *        admin listing passes true.
 */
function gigio_get_gigs_with_recurs($fromDate, $category, $includePending = false)
{
    global $wpdb;

    // Events submitted through [gigiau_submit] carry gigio_approved = '0' (pending)
    // until an admin approves ('1') or rejects ('2') them. Pending AND rejected
    // events are kept out of the public listing and JSON export; everything else
    // (no meta, or '1') is public. The admin listing ($includePending) sees all.
    $approvalGate = $includePending ? "" : "
        AND NOT EXISTS (
            SELECT 1 FROM $wpdb->postmeta pmx
            WHERE pmx.post_id = p.ID
            AND pmx.meta_key = 'gigio_approved'
            AND pmx.meta_value IN ('0', '2')
        )";

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
        $approvalGate
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
            // Moderation state (submitted via [gigiau_submit]). The public query
            // already filters pending/rejected out; the admin listing keeps them so
            // it can show a red flag + Approve/Reject buttons. The organizer's email
            // is only exposed to admins (never in public/JSON output).
            'pending' => (get_post_meta($id, 'gigio_approved', true) === '0'),
            'rejected' => (get_post_meta($id, 'gigio_approved', true) === '2'),
            'organizer' => current_user_can('edit_others_pages')
                ? (get_post_meta($id, 'gigio_organizer_email', true) ?: '')
                : '',
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
        <?php if ($isSignedIn) { ?>%pendingflag<?php } ?>
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
                    <input class="gig-dtstart gig-field" type="%gigdtype" value="%gigdtstart"
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
                            <input class="gig-bookinglink gig-field" type="text" inputmode="url" pattern="\s*(https?://.+|mailto:.+)\s*" placeholder="https://... or mailto:..." value="%bookinglink" />
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
        <div class="gig-content-wrapper">
            <div class="gig-content" onclick="handleContentClick(this, event)" data-editlink="%gigeditlink" data-locallink="%giglocallink" data-link="%giglink">%gigcontenttext</div>
            <div class="gig-content-full" onclick="handleFullContentClick(this, event)" data-locallink="%giglocallink" data-link="%giglink">%gigcontenttext</div>
        </div>
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

    <div class="gigio-capsule">
        <?php
        $cssFile = plugin_dir_path(__FILE__) . "gigio.css";
        $cssModTime = filemtime($cssFile);
        ?>
        <link rel="stylesheet" href="<?= plugin_dir_url(__FILE__) ?>gigio.css?ver=<?= $cssModTime ?>">
        <div id="giglist" class="giglist <?= $alignClass ?> <?= $p['strip'] ? "strip" : "" ?>" />
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

    <script>
        function gigio(selector) {
            return selector ? window.gigioCapsuleRoot.querySelector(selector) : window.gigioCapsuleRoot;
        }

        function gigioa(selector) {
            return window.gigioCapsuleRoot.querySelectorAll(selector);
        }
        jQuery(() => {
            setTimeout(() => {
                let capsule = document.querySelector(".gigio-capsule");
                capsule.attachShadow({
                    mode: "open"
                });
                let a = Array.from(capsule.children);
                console.log("capsule " + a.length);
                a.forEach(element => capsule.shadowRoot.appendChild(element));
                window.gigioCapsuleRoot = capsule.shadowRoot;
                fillGigList(jQuery("#gig-json").text(), jQuery("#gigtemplate").html(), <?= $p['strip'] ?>);
                setupContentClickOutside();
                <?php
                if ($p['popImages']) {
                ?>
                    gigioExpandImages();
                <?php
                }
                ?>
            }, 100);
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


// ************ Public Events REST API ***********
//
// A small, purpose-built API in the `gigiau/v1` namespace, separate from the
// admin editing that goes through WordPress's built-in /wp/v2/posts endpoints.
//
//   GET  /wp-json/gigiau/v1/events
//        Public. Lists title and start date-time of every event from today
//        onwards (recurring events show their next occurrence).
//
//   POST /wp-json/gigiau/v1/events
//        Requires a signed-in user who can create posts. Adds one event from
//        a title, start/end dates, venue, extra date info (dtinfo), a booking
//        link (bookinglink), and an uploaded poster image (multipart/form-data
//        field `picture`). The linked description page is not set here.

add_action('rest_api_init', function () {
    register_rest_route('gigiau/v1', '/events', [
        [
            'methods'             => 'GET',
            'callback'            => 'gigio_rest_list_events',
            'permission_callback' => '__return_true', // events are already shown publicly
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'gigio_rest_add_event',
            'permission_callback' => function () {
                return current_user_can('edit_others_posts');
            },
            'args' => [
                'title' => [
                    'type'     => 'string',
                    'required' => true,
                ],
                'dtstart' => [
                    'type'        => 'string',
                    'required'    => false,
                    'description' => 'Start date YYYY-MM-DD, optionally with time (YYYY-MM-DD HH:MM). Defaults to today.',
                ],
                'dtend' => [
                    'type'        => 'string',
                    'required'    => false,
                    'description' => 'End (expiry) date YYYY-MM-DD. Defaults to the start date.',
                ],
                'venue' => [
                    'type'     => 'string',
                    'required' => false,
                ],
                'dtinfo' => [
                    'type'        => 'string',
                    'required'    => false,
                    'description' => 'Extra free-text date/time info shown on the poster.',
                ],
                'bookinglink' => [
                    'type'        => 'string',
                    'required'    => false,
                    'description' => 'URL for booking/tickets.',
                ],
            ],
        ],
    ]);
});

/**
 * Convert a stored dtstart/dtend value to an ISO 8601 string.
 * Handles the variants found in the data: "YYYY-MM-DDTHH:MM", "YYYY-MM-DD HH:MM",
 * and date-only "YYYY-MM-DD". Dates are interpreted in the site's timezone.
 * A date-only value stays date-only; a value with a time becomes a full
 * offset datetime (e.g. 2026-07-03T19:30:00+01:00). Unparseable input is
 * returned unchanged.
 */
function gigio_iso_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    try {
        $dt = new DateTime($value, wp_timezone());
    } catch (Exception $e) {
        return $value;
    }
    // No time component in the source -> keep it as an ISO date.
    if (!preg_match('/\d{1,2}:\d{2}/', $value)) {
        return $dt->format('Y-m-d');
    }
    return $dt->format('c');
}

/**
 * Encode any above-ASCII characters to numeric HTML entities (e.g. "£" ->
 * "&#163;") before storing text meta. The site database may store these columns
 * as utf8mb3/latin1, which would drop raw multi-byte input; entities are pure
 * ASCII and survive. Mirrors how event titles are stored. Pair with
 * gigio_decode_text() when reading the value back as plain text. Safe on empty
 * or already-ASCII strings (returned unchanged).
 */
function gigio_encode_text($s)
{
    $s = (string) $s;
    if ($s === '') {
        return '';
    }
    return mb_encode_numericentity($s, [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
}

/**
 * Inverse of gigio_encode_text(): turn stored numeric entities back into plain
 * UTF-8 for JSON/API/text contexts. A raw (un-encoded, e.g. pre-existing) value
 * has no entities and is returned unchanged, so this is safe on mixed old/new
 * data.
 */
function gigio_decode_text($s)
{
    return html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * GET /wp-json/gigiau/v1/events
 * Return the title and start date-time of every current/future event,
 * sorted by start date, with recurring events resolved to their next date.
 */
function gigio_rest_list_events($request)
{
    $fromDate = date('Y-m-d');
    $category = GIGIO_CATEGORY;

    // Make sure the category exists (mirrors the shortcode's first-run behaviour).
    wp_create_category($category);

    $postDated = gigio_get_gigs_with_recurs($fromDate, $category);
    $postIds = array_map(function ($item) {
        return $item->ID;
    }, $postDated);

    if (count($postIds) == 0) {
        return rest_ensure_response([]);
    }

    // gigio_get_gigs applies recurrence date-shifting and sorts by start date.
    $gigs = gigio_get_gigs($fromDate, $category, $postIds);

    $events = array_map(function ($gig) {
        return [
            'id'    => $gig['id'],
            'title' => html_entity_decode($gig['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'start' => gigio_iso_datetime($gig['meta']['dtstart'] ?? ''),
        ];
    }, $gigs);

    return rest_ensure_response(array_values($events));
}

/**
 * POST /wp-json/gigiau/v1/events
 * Create one event post from title, dates, venue, and an uploaded poster.
 */
function gigio_rest_add_event($request)
{
    $post_id = gigio_create_event([
        'title'       => $request->get_param('title'),
        'dtstart'     => $request->get_param('dtstart'),
        'dtend'       => $request->get_param('dtend'),
        'venue'       => $request->get_param('venue'),
        'dtinfo'      => $request->get_param('dtinfo'),
        'bookinglink' => $request->get_param('bookinglink'),
    ], $request->get_file_params());

    if (is_wp_error($post_id)) {
        return $post_id;
    }
    return gigio_event_response($post_id, 201);
}

/**
 * Shared event-post creation, used by both the admin REST endpoint and the
 * organizer submission endpoint. Normalises dates the same way the admin JS
 * (newPost) does, stores the title safely, sets meta, and attaches an uploaded
 * poster as the featured image.
 *
 * @param array      $fields    title, dtstart, dtend, venue, dtinfo, bookinglink
 * @param array      $files     $request->get_file_params() (poster in field `picture`)
 * @param array|null $organizer null for an admin add (auto-approved); otherwise
 *                              ['id' => int, 'email' => string], which flags the
 *                              event pending approval and records ownership.
 * @param bool       $posterRequired  reject the submission if no poster is supplied
 * @param bool       $normalizeDates  true (admin) coerces a past/empty start to
 *                              today; false (organizer, already validated) keeps
 *                              the given start so still-running events can begin
 *                              before today.
 * @return int|WP_Error  new post ID, or error
 */
function gigio_create_event($fields, $files, $organizer = null, $posterRequired = false, $normalizeDates = true)
{
    $title = trim((string) ($fields['title'] ?? ''));
    if ($title === '') {
        return new WP_Error('gigio_missing_title', 'A title is required.', ['status' => 400]);
    }
    if ($posterRequired && empty($files['picture']['tmp_name'])) {
        return new WP_Error('gigio_missing_poster', 'A poster image is required.', ['status' => 400]);
    }

    list($dtstart, $dtend) = $normalizeDates
        ? gigio_normalize_event_dates($fields['dtstart'] ?? '', $fields['dtend'] ?? '')
        : gigio_resolve_event_dates($fields['dtstart'] ?? '', $fields['dtend'] ?? '');

    // Store text meta as numeric HTML entities so multi-byte characters survive
    // even on utf8mb3/latin1 columns; read paths decode it back to plain text.
    $title_stored = gigio_encode_text($title);

    $category_id = wp_create_category(GIGIO_CATEGORY);

    $post_id = wp_insert_post([
        'post_title'    => $title_stored,
        'post_content'  => '',
        'post_status'   => 'publish',
        'post_type'     => 'post',
        'post_category' => [$category_id],
    ], true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    update_post_meta($post_id, 'dtstart', $dtstart);
    update_post_meta($post_id, 'dtend', $dtend);
    update_post_meta($post_id, 'venue', gigio_encode_text(trim((string) ($fields['venue'] ?? ''))));
    update_post_meta($post_id, 'dtinfo', gigio_encode_text(trim((string) ($fields['dtinfo'] ?? ''))));
    update_post_meta($post_id, 'bookinglink', trim((string) ($fields['bookinglink'] ?? '')));
    update_post_meta($post_id, 'recursday', 0);

    // Organizer submissions start unapproved and record who submitted them.
    if ($organizer) {
        update_post_meta($post_id, 'gigio_approved', '0');
        update_post_meta($post_id, 'gigio_organizer', (int) $organizer['id']);
        update_post_meta($post_id, 'gigio_organizer_email', (string) $organizer['email']);
    }

    $poster = gigio_attach_poster($post_id, $files);
    if (is_wp_error($poster)) {
        // Roll back the post so we don't leave a picture-less event behind.
        wp_delete_post($post_id, true);
        return $poster;
    }

    return $post_id;
}

/**
 * Attach an uploaded poster (multipart/form-data field `picture`) as the
 * featured image. Returns the attachment id, false if no file was supplied,
 * or a WP_Error on failure.
 */
function gigio_attach_poster($post_id, $files, $field = 'picture')
{
    if (empty($files[$field]) || empty($files[$field]['tmp_name'])) {
        return false;
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attachment_id = media_handle_upload($field, $post_id);
    if (is_wp_error($attachment_id)) {
        return new WP_Error(
            'gigio_upload_failed',
            'Poster upload failed: ' . $attachment_id->get_error_message(),
            ['status' => 400]
        );
    }
    set_post_thumbnail($post_id, $attachment_id);
    return $attachment_id;
}

/**
 * Build the standard REST response describing one event post.
 */
function gigio_event_response($post_id, $status = 200)
{
    $response = rest_ensure_response([
        'id'          => $post_id,
        'title'       => gigio_decode_text(get_the_title($post_id)),
        'start'       => gigio_iso_datetime(get_post_meta($post_id, 'dtstart', true)),
        'end'         => get_post_meta($post_id, 'dtend', true),
        'venue'       => gigio_decode_text(get_post_meta($post_id, 'venue', true)),
        'dtinfo'      => gigio_decode_text(get_post_meta($post_id, 'dtinfo', true)),
        'bookinglink' => get_post_meta($post_id, 'bookinglink', true),
        'approved'    => !in_array(get_post_meta($post_id, 'gigio_approved', true), ['0', '2'], true),
        'rejected'    => get_post_meta($post_id, 'gigio_approved', true) === '2',
        'picture'     => get_the_post_thumbnail_url($post_id, 'full') ?: null,
        'link'        => get_permalink($post_id),
    ]);
    $response->set_status($status);
    return $response;
}

/**
 * Normalise a start/end date pair the same way the admin JS (newPost) does:
 * start defaults to today if missing or in the past; end defaults to start.
 * Times on the start value are preserved.
 * @return array [dtstart, dtend]
 */
function gigio_normalize_event_dates($dtstart, $dtend)
{
    $today = date('Y-m-d');
    $dtstart = trim((string) $dtstart);
    if ($dtstart === '' || strcmp(substr($dtstart, 0, 10), $today) < 0) {
        $dtstart = $today;
    }
    $dtend = trim((string) $dtend);
    if ($dtend === '' || strcmp(substr($dtend, 0, 10), substr($dtstart, 0, 10)) < 0) {
        $dtend = substr($dtstart, 0, 10);
    }
    return [$dtstart, $dtend];
}

/**
 * Validate organizer-supplied dates and return a clear WP_Error for anything
 * nonsensical, rather than silently coercing it (the admin path still coerces).
 * Checks: a start is supplied and parseable; if an end is given it parses and is
 * not before the start; and the event is not entirely in the past. A past start
 * IS allowed for an event still running — i.e. one whose end date is today or
 * later. Comparisons are by date only, matching the rest of the plugin's date
 * logic, and use the site timezone.
 *
 * @return true|WP_Error
 */
function gigio_check_event_dates($dtstart, $dtend)
{
    $dtstart = trim((string) $dtstart);
    if ($dtstart === '') {
        return new WP_Error('gigio_missing_date', 'Please choose a date and time for the event.', ['status' => 400]);
    }
    try {
        $start = new DateTime(str_replace('T', ' ', $dtstart), wp_timezone());
    } catch (Exception $e) {
        return new WP_Error('gigio_bad_date', "The event's date and time couldn't be understood.", ['status' => 400]);
    }

    $today    = (new DateTime('today', wp_timezone()))->format('Y-m-d');
    $startDay = $start->format('Y-m-d');

    $dtend  = trim((string) $dtend);
    $endDay = '';
    if ($dtend !== '') {
        try {
            $end = new DateTime(str_replace('T', ' ', $dtend), wp_timezone());
        } catch (Exception $e) {
            return new WP_Error('gigio_bad_date', "The end date couldn't be understood.", ['status' => 400]);
        }
        $endDay = $end->format('Y-m-d');
        if ($endDay < $startDay) {
            return new WP_Error('gigio_end_before_start', "The end date can't be before the start date.", ['status' => 400]);
        }
    }

    // A past start is fine for an event still running (end today or later);
    // otherwise the whole event is over and shouldn't be listed.
    if ($startDay < $today && ($endDay === '' || $endDay < $today)) {
        return new WP_Error(
            'gigio_past_event',
            "That start date has passed. If the event is still running, set an end date of today or later; otherwise choose a current date.",
            ['status' => 400]
        );
    }
    return true;
}

/**
 * Resolve organizer dates for storage WITHOUT coercing a past start to today
 * (unlike gigio_normalize_event_dates, used by the admin path). The start is
 * kept as given; an empty or earlier end defaults to the start date. Assumes
 * the pair has already passed gigio_check_event_dates().
 *
 * @return array [dtstart, dtend]
 */
function gigio_resolve_event_dates($dtstart, $dtend)
{
    $dtstart = trim((string) $dtstart);
    $dtend   = trim((string) $dtend);
    if ($dtend === '' || strcmp(substr($dtend, 0, 10), substr($dtstart, 0, 10)) < 0) {
        $dtend = substr($dtstart, 0, 10);
    }
    return [$dtstart, $dtend];
}

/**
 * Distinct venue names already used by events, for the submission dropdown
 * (so organizers reuse names we already know rather than inventing variants).
 */
function gigio_known_venues()
{
    global $wpdb;
    $rows = $wpdb->get_col(
        "SELECT DISTINCT meta_value FROM $wpdb->postmeta
         WHERE meta_key = 'venue' AND meta_value <> ''"
    );
    // Decode stored entities to plain text so the dropdown reads normally, and
    // collapse old (raw) and new (entity-encoded) spellings of the same name to
    // a single option.
    $venues = array_map(function ($v) {
        return trim(gigio_decode_text($v));
    }, (array) $rows);
    $venues = array_values(array_unique(array_filter($venues)));
    sort($venues, SORT_FLAG_CASE | SORT_STRING);
    return $venues;
}


// ************ Organizer accounts (custom email + password) ***********
//
// Event organizers are NOT WordPress users. They register and sign in on the
// [gigiau_submit] page with an email + password (stored bcrypt-hashed in the
// gigio_organizers table) and may submit and edit only their own events. Every
// submission is held for admin approval (meta gigio_approved = '0') before it
// appears on the public listing or in the JSON export.

define('GIGIO_SESSION_COOKIE', 'gigio_session');
define('GIGIO_SESSION_TTL', 30 * DAY_IN_SECONDS);
// How long a "forgot password" one-time sign-in link stays valid.
define('GIGIO_LOGIN_LINK_TTL', 2 * HOUR_IN_SECONDS);

function gigio_session_transient_key($token)
{
    return 'gigio_sess_' . hash('sha256', $token);
}

/** Transient key holding the organizer id for a one-time sign-in link token. */
function gigio_login_link_key($token)
{
    return 'gigio_pwlink_' . hash('sha256', $token);
}

/**
 * Start a session for an organizer: issue a random token, remember it
 * server-side (transient -> organizer id + CSRF token) and set an HttpOnly
 * cookie. Returns the CSRF token the client must send back in the
 * X-Gigio-Csrf header on write requests.
 */
function gigio_start_session($organizer_id)
{
    $token = bin2hex(random_bytes(32));
    $csrf  = bin2hex(random_bytes(16));
    set_transient(gigio_session_transient_key($token), [
        'organizer' => (int) $organizer_id,
        'csrf'      => $csrf,
    ], GIGIO_SESSION_TTL);
    gigio_set_session_cookie($token, time() + GIGIO_SESSION_TTL);
    $_COOKIE[GIGIO_SESSION_COOKIE] = $token; // usable within this same request
    return $csrf;
}

function gigio_set_session_cookie($value, $expires)
{
    setcookie(GIGIO_SESSION_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function gigio_end_session()
{
    $token = $_COOKIE[GIGIO_SESSION_COOKIE] ?? '';
    if ($token) {
        delete_transient(gigio_session_transient_key($token));
    }
    gigio_set_session_cookie('', time() - 3600);
    unset($_COOKIE[GIGIO_SESSION_COOKIE]);
}

/** Current session array ['organizer'=>id,'csrf'=>...] or null. */
function gigio_current_session()
{
    $token = $_COOKIE[GIGIO_SESSION_COOKIE] ?? '';
    if (!$token) {
        return null;
    }
    $data = get_transient(gigio_session_transient_key($token));
    return is_array($data) ? $data : null;
}

/** Current signed-in organizer row (id,email,name) or null. */
function gigio_current_organizer()
{
    $session = gigio_current_session();
    if (!$session) {
        return null;
    }
    global $wpdb;
    $table = gigio_organizers_table();
    return $wpdb->get_row(
        $wpdb->prepare("SELECT id, email, name FROM $table WHERE id = %d", $session['organizer'])
    );
}

/**
 * Guard for write endpoints: require a valid session AND a matching CSRF token
 * (double-submit) in the X-Gigio-Csrf header. Returns the organizer row or a
 * WP_Error.
 */
function gigio_require_organizer($request)
{
    $session = gigio_current_session();
    if (!$session) {
        return new WP_Error('gigio_not_signed_in', 'Please sign in first.', ['status' => 401]);
    }
    $header = (string) $request->get_header('X-Gigio-Csrf');
    if ($header === '' || !hash_equals((string) $session['csrf'], $header)) {
        return new WP_Error('gigio_bad_csrf', 'Your session has expired. Please sign in again.', ['status' => 403]);
    }
    $organizer = gigio_current_organizer();
    if (!$organizer) {
        return new WP_Error('gigio_not_signed_in', 'Please sign in first.', ['status' => 401]);
    }
    return $organizer;
}

/** The events belonging to one organizer, newest first, with approval status. */
function gigio_organizer_events($organizer_id)
{
    $query = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => 'gigio_organizer',
        'meta_value'     => (int) $organizer_id,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'suppress_filters' => true,
    ]);
    $events = [];
    foreach ($query->posts as $post) {
        $id = $post->ID;
        $events[] = [
            'id'          => $id,
            'title'       => gigio_decode_text(get_the_title($id)),
            'dtstart'     => get_post_meta($id, 'dtstart', true),
            'dtend'       => get_post_meta($id, 'dtend', true),
            'venue'       => gigio_decode_text(get_post_meta($id, 'venue', true)),
            'dtinfo'      => gigio_decode_text(get_post_meta($id, 'dtinfo', true)),
            'bookinglink' => get_post_meta($id, 'bookinglink', true),
            'approved'    => !in_array(get_post_meta($id, 'gigio_approved', true), ['0', '2'], true),
            'rejected'    => get_post_meta($id, 'gigio_approved', true) === '2',
            'picture'     => get_the_post_thumbnail_url($id, 'medium') ?: null,
        ];
    }
    wp_reset_postdata();
    return $events;
}

/**
 * The payload returned after a successful register/login and by GET /session:
 * who is signed in, the CSRF token, their events, and the known venue list.
 */
function gigio_session_payload($organizer_id, $csrf)
{
    global $wpdb;
    $table = gigio_organizers_table();
    $organizer = $wpdb->get_row(
        $wpdb->prepare("SELECT id, email, name FROM $table WHERE id = %d", $organizer_id)
    );
    return rest_ensure_response([
        'signedIn'  => true,
        'csrf'      => $csrf,
        'organizer' => [
            'id'    => (int) $organizer->id,
            'email' => $organizer->email,
            'name'  => $organizer->name,
        ],
        'events' => gigio_organizer_events($organizer_id),
        'venues' => gigio_known_venues(),
    ]);
}

add_action('rest_api_init', function () {
    gigio_ensure_tables();

    $public = ['permission_callback' => '__return_true'];

    register_rest_route('gigiau/v1', '/organizer/register', array_merge($public, [
        'methods'  => 'POST',
        'callback' => 'gigio_rest_organizer_register',
    ]));
    register_rest_route('gigiau/v1', '/organizer/login', array_merge($public, [
        'methods'  => 'POST',
        'callback' => 'gigio_rest_organizer_login',
    ]));
    register_rest_route('gigiau/v1', '/organizer/logout', array_merge($public, [
        'methods'  => 'POST',
        'callback' => 'gigio_rest_organizer_logout',
    ]));
    register_rest_route('gigiau/v1', '/organizer/forgot', array_merge($public, [
        'methods'  => 'POST',
        'callback' => 'gigio_rest_organizer_forgot',
    ]));
    register_rest_route('gigiau/v1', '/organizer/magic-login', array_merge($public, [
        'methods'  => 'POST',
        'callback' => 'gigio_rest_organizer_magic_login',
    ]));
    register_rest_route('gigiau/v1', '/organizer/password', array_merge($public, [
        'methods'  => 'POST',
        'callback' => 'gigio_rest_organizer_password',
    ]));
    register_rest_route('gigiau/v1', '/organizer/session', array_merge($public, [
        'methods'  => 'GET',
        'callback' => 'gigio_rest_organizer_session',
    ]));
    register_rest_route('gigiau/v1', '/organizer/events', array_merge($public, [
        'methods'  => 'POST',
        'callback' => 'gigio_rest_organizer_submit',
    ]));
    register_rest_route('gigiau/v1', '/organizer/events/(?P<id>\d+)', [
        array_merge($public, [
            'methods'  => 'POST',
            'callback' => 'gigio_rest_organizer_update',
        ]),
        array_merge($public, [
            'methods'  => 'DELETE',
            'callback' => 'gigio_rest_organizer_delete',
        ]),
    ]);
});

function gigio_rest_organizer_register($request)
{
    gigio_ensure_tables();
    $email    = strtolower(trim((string) $request->get_param('email')));
    $password = (string) $request->get_param('password');
    $name     = trim((string) $request->get_param('name'));

    if (!is_email($email)) {
        return new WP_Error('gigio_bad_email', 'Please enter a valid email address.', ['status' => 400]);
    }
    if (strlen($password) < 8) {
        return new WP_Error('gigio_weak_password', 'Password must be at least 8 characters.', ['status' => 400]);
    }

    global $wpdb;
    $table = gigio_organizers_table();
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE email = %s", $email));
    if ($exists) {
        return new WP_Error('gigio_email_taken', 'We already know that email — try signing in.', ['status' => 409]);
    }

    $ok = $wpdb->insert($table, [
        'email'         => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'name'          => $name,
        'created_at'    => current_time('mysql'),
    ]);
    if (!$ok) {
        return new WP_Error('gigio_register_failed', 'Could not create the account.', ['status' => 500]);
    }

    $id   = (int) $wpdb->insert_id;
    $csrf = gigio_start_session($id);
    return gigio_session_payload($id, $csrf);
}

function gigio_rest_organizer_login($request)
{
    gigio_ensure_tables();
    $email    = strtolower(trim((string) $request->get_param('email')));
    $password = (string) $request->get_param('password');

    global $wpdb;
    $table = gigio_organizers_table();
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT id, password_hash FROM $table WHERE email = %s", $email)
    );
    if (!$row || !password_verify($password, $row->password_hash)) {
        return new WP_Error('gigio_bad_credentials', 'Email or password not recognised.', ['status' => 401]);
    }

    $csrf = gigio_start_session((int) $row->id);
    return gigio_session_payload((int) $row->id, $csrf);
}

function gigio_rest_organizer_logout($request)
{
    gigio_end_session();
    return rest_ensure_response(['signedIn' => false]);
}

/**
 * "Forgot password": email the organizer a one-time sign-in link. We always
 * respond the same way whether or not the address is on file, so the form
 * doesn't reveal who has an account. Security here is deliberately light — the
 * link is a short-lived, single-use bearer token (see GIGIO_LOGIN_LINK_TTL).
 */
function gigio_rest_organizer_forgot($request)
{
    gigio_ensure_tables();
    $email = strtolower(trim((string) $request->get_param('email')));
    if (!is_email($email)) {
        return new WP_Error('gigio_bad_email', 'Please enter a valid email address.', ['status' => 400]);
    }

    global $wpdb;
    $table = gigio_organizers_table();
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT id, email, name FROM $table WHERE email = %s", $email)
    );

    if ($row) {
        $token = bin2hex(random_bytes(32));
        set_transient(gigio_login_link_key($token), (int) $row->id, GIGIO_LOGIN_LINK_TTL);
        gigio_send_login_link_email($row, gigio_login_link_url($request->get_param('page'), $token));
    }

    return rest_ensure_response(['ok' => true]);
}

/**
 * Consume a one-time sign-in link token: if it's still valid, delete it (so it
 * can't be reused) and open a session for that organizer.
 */
function gigio_rest_organizer_magic_login($request)
{
    gigio_ensure_tables();
    $token = (string) $request->get_param('token');
    $expired = new WP_Error(
        'gigio_link_expired',
        'This sign-in link has expired or already been used. Please request a new one.',
        ['status' => 400]
    );
    if ($token === '') {
        return $expired;
    }

    $key = gigio_login_link_key($token);
    $organizer_id = get_transient($key);
    if (!$organizer_id) {
        return $expired;
    }
    delete_transient($key); // one-time use

    global $wpdb;
    $table = gigio_organizers_table();
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", (int) $organizer_id));
    if (!$exists) {
        return $expired;
    }

    $csrf = gigio_start_session((int) $organizer_id);
    return gigio_session_payload((int) $organizer_id, $csrf);
}

/**
 * Change the signed-in organizer's password. Requires a valid session + CSRF
 * (via gigio_require_organizer); no current password is asked for, since the
 * organizer may have arrived via a one-time sign-in link. The existing session
 * stays valid.
 */
function gigio_rest_organizer_password($request)
{
    $organizer = gigio_require_organizer($request);
    if (is_wp_error($organizer)) {
        return $organizer;
    }

    $password = (string) $request->get_param('password');
    if (strlen($password) < 8) {
        return new WP_Error('gigio_weak_password', 'Password must be at least 8 characters.', ['status' => 400]);
    }

    global $wpdb;
    $table = gigio_organizers_table();
    $ok = $wpdb->update(
        $table,
        ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
        ['id' => (int) $organizer->id]
    );
    if ($ok === false) {
        return new WP_Error('gigio_password_failed', 'Could not update your password.', ['status' => 500]);
    }

    return rest_ensure_response(['ok' => true]);
}

/**
 * Build the URL for a one-time sign-in link. Prefer the submit page the request
 * came from (client-supplied), but only trust it if it's on this site; otherwise
 * fall back to finding the [gigiau_submit] page. Token goes in ?gigio_login=.
 */
function gigio_login_link_url($page, $token)
{
    $base      = esc_url_raw((string) $page);
    $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
    $page_host = $base ? wp_parse_url($base, PHP_URL_HOST) : '';
    if (!$base || $page_host !== $home_host) {
        $base = gigio_submit_page_url();
    }
    return add_query_arg('gigio_login', $token, $base);
}

/** Best guess at the public URL of the page carrying the [gigiau_submit] shortcode. */
function gigio_submit_page_url()
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT ID FROM $wpdb->posts
         WHERE post_status = 'publish'
         AND post_type IN ('page','post')
         AND post_content LIKE '%[gigiau_submit%'
         ORDER BY (post_type = 'page') DESC, ID ASC"
    );
    foreach ($ids as $id) {
        if (has_shortcode(get_post_field('post_content', $id), 'gigiau_submit')) {
            return $url = get_permalink($id);
        }
    }
    return $url = get_home_url(null, '/');
}

function gigio_send_login_link_email($organizer, $url)
{
    $name = trim((string) $organizer->name) !== '' ? $organizer->name : 'there';
    $subject = 'Your Gigiau sign-in link';
    $body = "Hi {$name},\n\n"
        . "Someone (hopefully you) asked for a sign-in link for Gigiau.\n"
        . "Click below to sign in — the link works once and expires in two hours:\n\n"
        . $url . "\n\n"
        . "If you didn't ask for this, just ignore this email; your account is unchanged.\n";
    wp_mail($organizer->email, $subject, $body);
}

function gigio_rest_organizer_session($request)
{
    $organizer = gigio_current_organizer();
    if (!$organizer) {
        return rest_ensure_response(['signedIn' => false, 'venues' => gigio_known_venues()]);
    }
    $session = gigio_current_session();
    return gigio_session_payload((int) $organizer->id, $session['csrf'] ?? '');
}

function gigio_rest_organizer_submit($request)
{
    $organizer = gigio_require_organizer($request);
    if (is_wp_error($organizer)) {
        return $organizer;
    }

    $dateError = gigio_check_event_dates($request->get_param('dtstart'), $request->get_param('dtend'));
    if (is_wp_error($dateError)) {
        return $dateError;
    }

    $post_id = gigio_create_event([
        'title'       => $request->get_param('title'),
        'dtstart'     => $request->get_param('dtstart'),
        'dtend'       => $request->get_param('dtend'),
        'venue'       => $request->get_param('venue'),
        'dtinfo'      => mb_substr(trim((string) $request->get_param('dtinfo')), 0, 80),
        'bookinglink' => $request->get_param('bookinglink'),
    ], $request->get_file_params(), [
        'id'    => $organizer->id,
        'email' => $organizer->email,
    ], true, false);

    if (is_wp_error($post_id)) {
        return $post_id;
    }
    gigio_queue_submission_notification($post_id, 'New submission');
    return gigio_event_response($post_id, 201);
}

function gigio_rest_organizer_update($request)
{
    $organizer = gigio_require_organizer($request);
    if (is_wp_error($organizer)) {
        return $organizer;
    }

    $post_id = (int) $request->get_param('id');
    $owner   = (int) get_post_meta($post_id, 'gigio_organizer', true);
    if (!$post_id || $owner !== (int) $organizer->id) {
        return new WP_Error('gigio_forbidden', 'You can only edit events you submitted.', ['status' => 403]);
    }

    $dateError = gigio_check_event_dates($request->get_param('dtstart'), $request->get_param('dtend'));
    if (is_wp_error($dateError)) {
        return $dateError;
    }

    $title = trim((string) $request->get_param('title'));
    if ($title !== '') {
        wp_update_post(['ID' => $post_id, 'post_title' => gigio_encode_text($title)]);
    }

    list($dtstart, $dtend) = gigio_resolve_event_dates(
        $request->get_param('dtstart'),
        $request->get_param('dtend')
    );
    update_post_meta($post_id, 'dtstart', $dtstart);
    update_post_meta($post_id, 'dtend', $dtend);
    update_post_meta($post_id, 'venue', gigio_encode_text(trim((string) $request->get_param('venue'))));
    update_post_meta($post_id, 'dtinfo', gigio_encode_text(mb_substr(trim((string) $request->get_param('dtinfo')), 0, 80)));
    update_post_meta($post_id, 'bookinglink', trim((string) $request->get_param('bookinglink')));

    // A replacement poster is optional on edit.
    $poster = gigio_attach_poster($post_id, $request->get_file_params());
    if (is_wp_error($poster)) {
        return $poster;
    }

    // Editing sends the event back to pending so the admin re-checks it.
    update_post_meta($post_id, 'gigio_approved', '0');

    gigio_queue_submission_notification($post_id, 'Update');
    return gigio_event_response($post_id, 200);
}

/**
 * DELETE /gigiau/v1/organizer/events/{id}
 * Delete one of the organizer's own events, along with its poster attachment(s).
 */
function gigio_rest_organizer_delete($request)
{
    $organizer = gigio_require_organizer($request);
    if (is_wp_error($organizer)) {
        return $organizer;
    }

    $post_id = (int) $request->get_param('id');
    $owner   = (int) get_post_meta($post_id, 'gigio_organizer', true);
    if (!$post_id || $owner !== (int) $organizer->id) {
        return new WP_Error('gigio_forbidden', 'You can only delete events you submitted.', ['status' => 403]);
    }

    // Remove the poster (featured image) and any other attachments first.
    $thumb = get_post_thumbnail_id($post_id);
    if ($thumb) {
        wp_delete_attachment($thumb, true);
    }
    foreach (get_children(['post_parent' => $post_id, 'post_type' => 'attachment', 'numberposts' => -1, 'fields' => 'ids']) as $aid) {
        wp_delete_attachment($aid, true);
    }

    if (!wp_delete_post($post_id, true)) {
        return new WP_Error('gigio_delete_failed', 'Could not delete the event.', ['status' => 500]);
    }

    return rest_ensure_response(['deleted' => true, 'id' => $post_id]);
}


// ************ Submission notifications ***********
//
// Email a moderator whenever an organizer submits or edits an event. To avoid a
// spate of messages when someone makes a series of edits, the send is batched:
// each change (re)schedules a single WP-Cron event 15 minutes out and records
// the affected event, so one summary email goes out once the organizer has been
// quiet for 15 minutes. WP-Cron fires on site traffic (or a real system cron),
// so delivery depends on the site being visited after the delay. Actual sending
// also depends on the site's wp_mail/SMTP configuration.

define('GIGIO_NOTIFY_EMAIL', 'info@gigiau.uk');
define('GIGIO_NOTIFY_HOOK', 'gigio_send_submission_notification');
define('GIGIO_NOTIFY_OPTION', 'gigio_pending_notify');

add_action(GIGIO_NOTIFY_HOOK, 'gigio_send_submission_notification_email');

/**
 * Record that one event was submitted/updated and (re)schedule the batched send
 * 15 minutes from now. Rescheduling on every change debounces a burst of edits
 * into a single email.
 *
 * @param int    $post_id
 * @param string $action  'New submission' or 'Update' (a new event stays "New
 *                        submission" even if edited again within the batch).
 */
function gigio_queue_submission_notification($post_id, $action)
{
    $queue = get_option(GIGIO_NOTIFY_OPTION, []);
    if (!is_array($queue)) {
        $queue = [];
    }
    $existing = $queue[$post_id] ?? '';
    $queue[$post_id] = ($existing === 'New submission') ? 'New submission' : $action;
    update_option(GIGIO_NOTIFY_OPTION, $queue, false);

    // Debounce: drop any pending send and schedule a fresh one 15 minutes out.
    wp_clear_scheduled_hook(GIGIO_NOTIFY_HOOK);
    wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, GIGIO_NOTIFY_HOOK);
}

/**
 * WP-Cron callback: email a summary of the batch of events submitted or updated,
 * then clear the queue. The queue is cleared before sending so a cron double-run
 * can't send the same batch twice; anything that arrives mid-send starts a fresh
 * batch.
 */
/**
 * URL of the page showing the [gigiau] listing, with the #approve fragment so an
 * admin lands on the first pending flag. Prefers a Page over a post; falls back
 * to the site home if no [gigiau] page is found. `has_shortcode(..., 'gigiau')`
 * matches the exact tag, so a page holding only [gigiau_submit] is skipped.
 * Cached per request.
 */
function gigio_listings_page_url()
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT ID FROM $wpdb->posts
         WHERE post_status = 'publish'
         AND post_type IN ('page','post')
         AND post_content LIKE '%[gigiau%'
         ORDER BY (post_type = 'page') DESC, ID ASC"
    );
    foreach ($ids as $id) {
        if (has_shortcode(get_post_field('post_content', $id), 'gigiau')) {
            return $url = get_permalink($id) . '#approve';
        }
    }
    return $url = get_home_url(null, '/#approve');
}

function gigio_send_submission_notification_email()
{
    $queue = get_option(GIGIO_NOTIFY_OPTION, []);
    if (empty($queue) || !is_array($queue)) {
        return;
    }
    delete_option(GIGIO_NOTIFY_OPTION);

    $lines = [];
    foreach ($queue as $post_id => $action) {
        if (!get_post($post_id)) {
            continue; // deleted since it was queued
        }
        $title  = gigio_decode_text(get_the_title($post_id));
        $venue  = gigio_decode_text(get_post_meta($post_id, 'venue', true));
        $start  = get_post_meta($post_id, 'dtstart', true);
        $who    = get_post_meta($post_id, 'gigio_organizer_email', true) ?: 'unknown';
        $status = get_post_meta($post_id, 'gigio_approved', true) === '0' ? 'awaiting approval' : 'approved';

        $lines[] = "* {$action}: \"{$title}\""
            . ($start ? " \u{2014} {$start}" : '')
            . ($venue ? " @ {$venue}" : '') . "\n"
            . "    by {$who}  ({$status})";
    }

    $count = count($lines);
    if ($count === 0) {
        return;
    }

    $subject = sprintf('[Gigiau] %d event%s submitted or updated', $count, $count === 1 ? '' : 's');
    $body = 'The following event' . ($count === 1 ? ' was' : 's were')
        . ' submitted or updated on Gigiau and ' . ($count === 1 ? 'is' : 'are') . " awaiting approval:\n\n"
        . implode("\n\n", $lines)
        . "\n\nReview and approve on the events page (red flag \u{2192} Approve):\n"
        . gigio_listings_page_url() . "\n";

    wp_mail(GIGIO_NOTIFY_EMAIL, $subject, $body);
}


// ************ Submission page shortcode ***********
//
// Place [gigiau_submit] on a page. Organizers see sign-in / sign-up when logged
// out; when signed in they get the submission form (with a venue dropdown of
// known names) and a list of their own events with approval status and Edit.

add_shortcode('gigiau_submit', 'gigio_submit_shortcode');

function gigio_submit_shortcode($attributes = [])
{
    gigio_ensure_tables();

    $jsFile = plugin_dir_path(__FILE__) . 'gigio-submit.js';
    $jsModTime = file_exists($jsFile) ? filemtime($jsFile) : null;
    wp_enqueue_script('gigiosubmitjs', plugin_dir_url(__FILE__) . 'gigio-submit.js', [], $jsModTime, true);

    $cssFile = plugin_dir_path(__FILE__) . 'gigio.css';
    $cssModTime = filemtime($cssFile);

    $config = [
        'restBase' => esc_url_raw(rest_url('gigiau/v1')),
        'venues'   => gigio_known_venues(),
    ];

    ob_start();
?>
    <link rel="stylesheet" href="<?= plugin_dir_url(__FILE__) ?>gigio.css?ver=<?= $cssModTime ?>">
    <div class="gigio-submit" data-config="<?= esc_attr(wp_json_encode($config)) ?>">
        <div class="gigio-submit-status" role="status" aria-live="polite"></div>
        <div class="gigio-submit-body">Loading&hellip;</div>
    </div>
<?php
    return ob_get_clean();
}
