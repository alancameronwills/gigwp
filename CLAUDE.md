# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Gigiau Events Posters is a WordPress plugin that displays event posters on a listings page with automatic date ordering, expiry, and recurrence support. Events are stored as WordPress posts in the "gig" category with associated metadata.

## Architecture

### File Structure
- `gigio.php` - Main plugin file: shortcode registration, WordPress hooks, PHP rendering, database queries, REST API hooks, organizer accounts/approval
- `gigio.js` - Frontend display: renders gig list from JSON, image expansion, column layout, date formatting
- `gigio-edit.js` - Admin editing: CRUD operations via WP REST API, media popup, form handling, `approveGig` (only loaded for editors)
- `gigio-submit.js` - Front-end for the `[gigiau_submit]` organizer submission page (auth, submit form, "my events"); vanilla JS, talks to the `gigiau/v1/organizer/*` REST endpoints
- `gigio.css` - All styles, uses CSS custom properties and shadow DOM encapsulation
- `build-release.ps1` - Builds `build/gigiau-events-posters-<version>.zip` (reads Version from `gigio.php`); zip entries use forward slashes so Linux WordPress hosts accept them

### Data Flow
1. PHP queries posts in "gig" category with metadata (dtstart, dtend, recursday, etc.)
2. Recurrence logic updates dates for recurring events server-side
3. Gig data serialized to JSON inline in page
4. JavaScript parses JSON and renders HTML from inline template
5. Shadow DOM (`class="gigio-capsule"`) encapsulates styles from theme conflicts

### Key Patterns
- **Shortcodes**: `[gigiau]` (listings, parameters like `layout`, `width`, `height`, `strip`, `align`, `background`) and `[gigiau_submit]` (organizer submission page)
- **Post metadata fields**: `dtstart`, `dtend`, `dtinfo`, `venue`, `recursday`, `recursweeks`, `recursfortnight`, `booklabel`, `bookinglink`, `locallink`; plus moderation fields `gigio_approved` (`'0'` = pending, `'1'`/absent = approved), `gigio_organizer` (organizer row id), `gigio_organizer_email`
- **Post content**: Displays as truncated plain text (HTML stripped); in edit mode, clicking opens WordPress post editor
- **Recurrence**: Supports weekly (nth week of month) and fortnightly patterns via `recursday`/`recursweeks`/`recursfortnight` fields
- **Filename parsing**: Dates and info can be encoded in poster filename: `Title YYYY-MM-DD[-YYYY-MM-DD] Extra info.jpg`
- **Text meta encoding**: `title`, `venue`, and `dtinfo` are stored as numeric HTML entities (`gigio_encode_text`) so multi-byte characters survive utf8mb3/latin1 columns, and decoded on read (`gigio_decode_text`). Decode is a no-op on raw/legacy values.

### Event Submission & Approval (organizers)
- **`[gigiau_submit]` page**: lets event organizers submit events without WordPress accounts. They sign in with a simple email + password (custom `{prefix}gigio_organizers` table, bcrypt hashes, NOT WordPress users), submit poster/title/date/venue/extra-info, and view/edit only their own events.
- **Sessions**: random token in an HttpOnly `gigio_session` cookie, backed by a transient; a per-session CSRF token is required in the `X-Gigio-Csrf` header on writes. See `gigio_start_session` / `gigio_require_organizer`.
- **Approval gate**: submissions get `gigio_approved = '0'`. The `$includePending` param on `gigio_get_gigs_with_recurs` excludes pending events from the public listing and JSON export; only WP admins (`edit_others_pages`) see them, flagged red with an Approve button (`approveGig` sets `gigio_approved = '1'`). Editing an approved event resets it to pending.
- **Date rule** (organizer path only): a past start is allowed only if the end date is today or later (`gigio_check_event_dates`); the admin/media path still silently normalizes (`gigio_normalize_event_dates`).
- **Delete**: organizers can delete their own events via `DELETE /organizer/events/{id}` (`gigio_rest_organizer_delete`, ownership + CSRF checked, also removes the poster attachment); a Delete button sits next to Edit in "my events".
- **Moderator notifications**: submit/update queue a debounced email to `info@gigiau.uk`. Each change (re)schedules a single WP-Cron event 15 minutes out (`gigio_queue_submission_notification`), so a burst of edits collapses into one summary email (`gigio_send_submission_notification_email`). The email links to the `[gigiau]` listings page + `#approve` fragment (`gigio_listings_page_url`); `gigio.js` `gigioScrollToHash` scrolls that pending flag into view since it lives in the shadow root. Delivery depends on WP-Cron firing on traffic and the site's `wp_mail`/SMTP config.

### REST API Integration
- Uses WordPress Backbone.js client (`wp.api.models.Post`) for admin CRUD
- Custom `rest_insert_post` action enables metadata updates via API (also used by `approveGig`)
- Admin controls save changes on focus-out with thread flagging to prevent data loss
- Custom `gigiau/v1` namespace: `GET/POST /events` (public list + authenticated add), and `/organizer/{register,login,logout,session,events}` plus `/organizer/events/{id}` (POST = edit, DELETE = delete) for the submission page. Shared event creation lives in `gigio_create_event`.

### Rendering Modes
- **Default**: Flexbox wrap with various alignment options (top, bottom, base, cover, columns)
- **Strip mode**: Single horizontal row for front page teasers
- **Columns mode**: Pinterest-style masonry layout with resize handling

## Development Environment

This is a WordPress plugin running in a local UniServer environment. Changes take effect immediately on page refresh. The plugin uses cache-busting via file modification timestamps for CSS/JS.

## Releases

The plugin auto-updates from GitHub releases (Plugin Update Checker, release assets enabled). To cut a release: bump the `Version:`/`@version` headers in `gigio.php`, commit, run `build-release.ps1` to produce the zip, tag `v<version>`, push the tag, and create a GitHub release with the zip as an asset. Tags are `v2.8` style; the release asset is `gigiau-events-posters-<version>.zip` with `gigiau-events-posters/` as the top-level folder.

## Shortcode Parameters Reference

Key parameters for `[gigiau]`:
- `layout` - Order of elements: "shortdate image title dates venue"
- `align` - Layout mode: top|bottom|base|cover|columns
- `strip=1` - Horizontal single-row mode
- `width`/`height` - Poster dimensions in pixels
- `background` - CSS color value
- `venueinfilename` - Parse venue from filename instead of extra info

`[gigiau_submit]` takes no parameters; place it on a page for organizers to submit events.
