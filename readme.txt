=== Plugin Name ===
Contributors: alancameronwills
Tags: events
Requires at least: 4.7
Tested up to: 6.8.1
Stable tag: 4.3
Requires PHP: 7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Got event poster files? Put them on an events listings page with automatic ordering, expiry, and recurrence.

== Description ==

# Gigiau Event Poster Page
Got event poster files? Put them on an events listings page with automatic ordering, expiry, and recurrence.

## Benefits

Without an events plugin, you put the posters into a gallery. You have to arrange them into date order; and when they've expired you have to delete them.

This plugin:
* Arranges the posters into date order. (You have to type in the date of each event.)
* Removes each poster when it's out of date.
* Shows the next date for a recurring sequence, for example a regular club or class.

## Install
Download [the latest Zip file grom GitHub](https://github.com/alancameronwills/gigwp/archive/refs/heads/main.zip).

Sign in to WordPress, then go to **Plugins > Add > Upload from file**

To update to the latest version, repeat the above.

## Your event listings page

Put this shortcode on the page where you want your listings to appear:
```
    [gigiau]
```
You can type that into a text block, or use the specific Shortcode template block.

Create whatever header and footer material you want to appear around the listing.

**Publish** or **Save** the page and then **View Page**.


## Add and Edit event listings

1 Click **Add** at the bottom right while
  - Signed into WordPress
  - Viewing your listings page (not editing it)
  The Media Selector opens.

2. **Upload** and select your event poster images. Use CTRL|CMD to select several at once.

3. Edit the **title**, **date**, **extra info** and other fields, then click **Done**.

- Use the second date if your event extends over several days. The start date determines the ordering of the list; the expiry date determines when the event vanishes.
- Use **extra info** for things like time of day, price or restrictions. There's only space for one line - leave the poster or linked page to say the rest.
- **Location** might be a room or an external venue.

**Recurrence** - check the nth week of the month boxes to make the event automatically reappear after each occurrence, until the end date if there is one. For example, for a series of 6 weekly classes, check all 5 week boxes, and set the start and end dates of the course. For a club that meets every 2nd and 4th week of the month, check those boxes and set the end date = the start date.

**Booking** or **More info** link - once the other page is set up - for example a Facebook page - enter the URL. 
Set the Booking Label to be "Book Tickets" or "Read more".



## Global Options

There are some options you can set in the `[gigiau]` shortcode. Click WordPress **Edit Page** to adjust them. For example:
```
    [gigiau layout="title image dates venue" width="320" align="stretch" ]
```

* `layout="image booking title dates venue"` - Re-order the words to change the layout of each event
* `width="340"` - in pixels of each event. Scales down automatically for narrow screens such as phones.
* `height="420"` - in pixels. Posters are padded with empty space to that height. Set to 0 to prevent padding. Leave it out altogether if you have A4 posters, 1.412 x width.
* `border="false"` - to prevent a border round posters
* `align="stretch"` - 
  * `stretch`= items a uniform size, but edges lost
  * `top` = items may be different sizes, no loss of edges, aligned at top 
  * `bottom` = aligned at bottom of item including text
  * `base` = aligned at bottom of poster
* `background="whitesmoke"` - a colour, such as `lightgray, gray, white, aliceblue, black, blue, ...` or `#CCFFAA`
* `book="More info"` - The default label of the link button, such as "Book tickets". You can change it per event.

## Event pages

Each event is recorded as a post in the WordPress database, with category `gig`. You can see them in the lists of posts in the Admin pages.
The picture associated with each event is set as the page's Feature Image.

You can write content into an event's post, and link to it from the event listings.
