# World Time

A single-file PHP world-time converter and scheduler, with a clickable world
map for adding timezones instead of a plain dropdown.

**Live**: https://tickermetrix.com/other/timezone_map.php

**License**: [Unlicense](LICENSE) — public domain, use/modify for anything, no restrictions.

## Features

- **Time grid** — compare any number of timezones side by side, hour-by-hour,
  for a chosen date. Tiles are shaded night / day / working-hours. Click a
  column to pin a highlight across every row at that hour; hover a row or
  column for a lighter, transient highlight.
- **Clickable map picker** — click anywhere on the world map to add that
  longitude's timezone to the grid, instead of picking from a `<select>`.
  Hovering shows a live tooltip with the UTC offset, abbreviation, and city.
- **Half-hour offsets, not just whole hours** — the map is built from a
  sorted list of every standard UTC offset actually in use (including
  India +5:30, Newfoundland −3:30, Afghanistan +4:30, and a few others), and
  each zone's band width is computed from its real neighbors — a half-hour
  zone comes out visibly narrower than a full-hour one, automatically.
- **Zone abbreviations** (PST, IST, JST, AEST, ...) shown both on the map
  and next to each row in the grid, wherever the tz database actually has
  one — a bare numeric offset is shown instead of faking an abbreviation
  for the handful of zones that don't have one (Dubai, Dhaka, Bangkok...).
- **Grid ↔ map cross-highlighting** — hovering a grid row highlights that
  zone's own band on the map; hovering the map previews what clicking there
  would add.
- **Remove zones** with a trash-can button per row.
- **localStorage persistence** — a bare visit (no URL query string at all)
  restores your last setup instead of resetting to the 3 hardcoded
  defaults. Shareable/bookmarkable links still work exactly the same way —
  the URL's own `?date=&format24=&tzs=` always wins when present;
  localStorage is only a fallback for when there isn't one.

## Files

| File | What it is |
|---|---|
| `timezone_map.php` | The whole app — PHP renders the grid + computes each zone's real UTC offset/DST for the picked date; vanilla JS drives the map interactions and localStorage. No build step, no dependencies. |
| `worldmap.svg` | Public-domain equirectangular world map ([BlankMap-Equirectangular](https://commons.wikimedia.org/wiki/File:BlankMap-Equirectangular.svg), Wikimedia Commons). `viewBox="0 0 360 180"` — 1 SVG unit = 1° of longitude, which is what makes the click-to-offset math exact. |
| `.htaccess` | Blocks direct access to `.ini`/`.csv`/`.txt` in this directory (matches the rest of the site's convention) — `.php`/`.svg` stay accessible. |

## How zone bands work

Each entry in `$offsetToTz` (in `timezone_map.php`) is a **standard, non-DST**
UTC offset in minutes mapped to one representative IANA timezone. A band's
left/right edge is the midpoint to its nearest neighbor on that side (map
edges for the first/last entry) — so a half-hour zone sandwiched between two
full-hour zones naturally gets a narrower band, with no special-casing.

Band **position** is fixed regardless of the selected date (a zone's
longitude doesn't move twice a year for DST). The grid's actual displayed
times, and each zone's shown abbreviation, still fully reflect the real DST
state for whatever date is picked.

Deliberately out of scope: quarter-hour zones (Nepal +5:45, Chatham Islands
+12:45) — rare enough that they weren't worth pulling in without being asked.

## Credits

Built by [Claude](https://claude.com/claude-code), Anthropic's AI coding assistant, working with human guidance.

The world map (`worldmap.svg`) is [BlankMap-Equirectangular](https://commons.wikimedia.org/wiki/File:BlankMap-Equirectangular.svg)
from Wikimedia Commons, public domain.
