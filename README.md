# Gigiau Event Poster Page
Got event poster files? Put them on an events listings page with automatic ordering, expiry, and recurrence.

## Benefits

Without an events plugin, you put the posters into a gallery. You have to arrange them into date order; and when they've expired you have to delete them.

This plugin:
* Arranges the posters into date order. (You have to type in the date of each event.)
* Removes each poster when it's out of date.
* Shows the next date for a recurring sequence, for example a regular club or class.

## Install
Download [the latest Zip file grom GitHub](https://gigiau.uk/gigio.zip).

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

**On your front page**, you could also put this to show just one row of the first few events:
```
      [gigiau strip=1]
```


## Add and Edit event listings

0 Sign in to WordPress and *view* your listings page - **don't** edit it.

1 Click **Add** at the bottom right.
  The Media Selector opens.

2. **Upload** and select your event poster images. You can use CTRL|CMD to select several at once.

2. Click **Edit** at bottom right to 

3. Edit the **title**, **date**, **extra info** and other fields, then click **Done**.

- Use the second date if your event extends over several days. The start date determines the ordering of the list; the expiry date determines when the event vanishes.
- Use **extra info** for things like time of day, price or restrictions. There's only space for one line - if your poster doesn't say enough, you can add more text using *Add description...* (see below)
- **Venue** might be a room or an external venue.

**Recurrence** - check the nth week of the month boxes to make the event automatically reappear after each occurrence, until the end date if there is one. For example, for a series of 6 weekly classes, check all 5 week boxes, and set the start and end dates of the course. For a club that meets every 2nd and 4th week of the month, check those boxes and set the end date = the start date.
Alternatively, set the **Every 14 days** option.

**Booking** or **More info** link - once the other page is set up - for example a Facebook page - enter the URL (https://...)
Set the Booking Label to be "Book Tickets" or "Read more".

**Link to Poster Page on this site** - check this if you want users to be able to click through to the gig's own page on your site. You'd use this if you want to show more pictures, or a lot more text. You'd also use it if you want to put a booking or enquiry form on your own site, rather than using an external tickets service.

**Add description...** - click this to add more text, pictures, etc in the gig's own page. When you've finished editing, click **Save**, and then close the editing window.

When people view your events page, they'll see some of this description at the bottom of each event. Tapping or clicking it will reveal more.

### Tip

To make creating events a bit quicker, you can code the title, date, and extra info in the poster filename:
```
  The Big Show 2026-07-11 - 2026-07-13 19-30 Tickets on the door.jpg
```
Just save with that filename from your artwork app.

You can also set the venue after the date and time, by including `=`
```
  The Big Show 2026-07-11 - 2026-07-13 19-30 Main Theatre = £15 or kids £5.jpg
  Parade 2026-07-11 14-00 Central Park = bring umbrellas.jpg
  Chill session 2026-07-12 17-00 Garden Room =.jpg
```

## Global Options

There are some options you can set in the `[gigiau]` shortcode. Click WordPress **Edit Page** to adjust them. For example:
```
    [gigiau layout="title image dates venue" width="320" ]
```

* `strip=1` - For use on your front page. Lists the first few events in a single row. You'll probably want to put a link to your events page underneath it, where people can see the full listing. 
The admin buttons don't appear with this option.
* `layout="shortdate image booking title dates venue"` - Re-order the words to change the layout of each event.
  * `shortdate` - Just the start day and date
  * `dates` - Start and end date, if the end date is different, followed by a description of the recurrence regime, if any; and then the extra info.
  * `image`
  * `title`
  * `venue`
  * Descriptive content is always at the bottom
* `align="columns"` - Layout of the list:
  * `top` - Events are in rows with tops aligned. Gaps below short posters.
  * `bottom` - Events are in rows with bottoms aligned. Gaps at tops.
  * `base` - Bottoms of posters are aligned
  * `cover` - Posters are expanded to fill a uniform space; but edges might be cut off.
  * `columns` - Fills the space, but posters are not aligned
* `width="340"` - Width in pixels of each poster. Scales down automatically for narrow screens such as phones.
* `height="420"` - in pixels. Posters are padded with empty space to that height. Set to 0 to prevent padding. Leave it out altogether if you have A4 posters, 1.412 x width.
* `border="false"` - to prevent a border round posters
* `background="whitesmoke"` - a colour, such as `lightgray, gray, white, aliceblue, black, blue, ...` or `#CCFFAA`
* `book="More info"` - The default label of the link button, such as "Book tickets". You can change it per event.
* `headercolor="black"` - colour of the header of the event boxes
* `venueinfilename=1` - If there is no `=` in an image filename, text after the date will set the venue instead of the extra info

## Event pages

Each event is recorded as a post in the WordPress database, with category `gig`. You can see them in the lists of posts in the Admin pages.
The picture associated with each event is set as the page's Feature Image.
Dates, venue and recurrence are recorded as metadata.

You can write content into an event's post, and link to it from the event listings.
