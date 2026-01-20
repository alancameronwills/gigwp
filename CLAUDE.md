# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Gigiau Events Posters is a WordPress plugin that displays event posters on a listings page with automatic date ordering, expiry, and recurrence support. Events are stored as WordPress posts in the "gig" category with associated metadata.

## Architecture

### File Structure
- `gigio.php` - Main plugin file: shortcode registration, WordPress hooks, PHP rendering, database queries, REST API hooks
- `gigio.js` - Frontend display: renders gig list from JSON, image expansion, column layout, date formatting
- `gigio-edit.js` - Admin editing: CRUD operations via WP REST API, media popup, form handling (only loaded for editors)
- `gigio.css` - All styles, uses CSS custom properties and shadow DOM encapsulation

### Data Flow
1. PHP queries posts in "gig" category with metadata (dtstart, dtend, recursday, etc.)
2. Recurrence logic updates dates for recurring events server-side
3. Gig data serialized to JSON inline in page
4. JavaScript parses JSON and renders HTML from inline template
5. Shadow DOM (`<gigio-capsule>`) encapsulates styles from theme conflicts

### Key Patterns
- **Shortcode**: `[gigiau]` with parameters like `layout`, `width`, `height`, `strip`, `align`, `background`
- **Post metadata fields**: `dtstart`, `dtend`, `dtinfo`, `venue`, `recursday`, `recursweeks`, `recursfortnight`, `booklabel`, `bookinglink`, `locallink`
- **Post content**: Displays as truncated plain text (HTML stripped); in edit mode, clicking opens WordPress post editor
- **Recurrence**: Supports weekly (nth week of month) and fortnightly patterns via `recursday`/`recursweeks`/`recursfortnight` fields
- **Filename parsing**: Dates and info can be encoded in poster filename: `Title YYYY-MM-DD[-YYYY-MM-DD] Extra info.jpg`

### REST API Integration
- Uses WordPress Backbone.js client (`wp.api.models.Post`) for CRUD
- Custom `rest_insert_post` action enables metadata updates via API
- Admin controls save changes on focus-out with thread flagging to prevent data loss

### Rendering Modes
- **Default**: Flexbox wrap with various alignment options (top, bottom, base, cover, columns)
- **Strip mode**: Single horizontal row for front page teasers
- **Columns mode**: Pinterest-style masonry layout with resize handling

## Development Environment

This is a WordPress plugin running in a local UniServer environment. Changes take effect immediately on page refresh. The plugin uses cache-busting via file modification timestamps for CSS/JS.

## Shortcode Parameters Reference

Key parameters for `[gigiau]`:
- `layout` - Order of elements: "shortdate image title dates venue"
- `align` - Layout mode: top|bottom|base|cover|columns
- `strip=1` - Horizontal single-row mode
- `width`/`height` - Poster dimensions in pixels
- `background` - CSS color value
- `venueinfilename` - Parse venue from filename instead of extra info
