<?php
// Fork of timezone.php (2026-09-18, Craig: "just a selector to display the
// times accross zones. i want to add the world map ... and mouse click to
// add a zone to the selected zones" -> "instead of the add drop down") --
// same world-time-grid view, but the "Add Timezone" <select> dropdown is
// replaced by a clickable world map (worldmap.svg, a public-domain
// equirectangular map from Wikimedia Commons, viewBox 0 0 360 180 -- 1 SVG
// unit = 1 degree of longitude exactly, which is what makes the click-to-
// offset math below exact with no scaling fudge factor). Built as a NEW
// file rather than editing timezone.php in place -- that file is owned by
// a different local user (developer1:smbshare) and isn't in git, so it
// isn't safe/possible for Claude to modify directly; this is the same
// feature set as a sibling file instead.
//
// Map click -> timezone: rather than per-country hit-testing (one country
// can span several real timezones -- USA, Russia, etc. -- so "which
// country did you click" doesn't uniquely answer "which timezone"), this
// buckets the click into one of 24 standard 15-degree-wide UTC-offset
// bands (360 degrees / 24 hours = 15 degrees/hour, the same convention
// the NIST reference image and every other world time-zone map uses) and
// adds a representative real IANA zone for that offset -- see
// OFFSET_TO_TZ below. This intentionally can't reach a handful of real
// half/quarter-hour zones (India's +5:30, Nepal's +5:45, etc.) that a
// full dropdown could -- an accepted simplification for "just a selector"
// (Craig's own words), not an oversight.
$defaultBaseTz = 'America/Los_Angeles';
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$format24 = isset($_GET['format24']) && $_GET['format24'] === '1';

$presetTimezones = [
    'America/Los_Angeles' => ['label' => 'Wilsonville', 'region' => 'United States, Oregon', 'code' => 'PDT'],
    'America/New_York'    => ['label' => 'New York', 'region' => 'United States, New York', 'code' => 'EDT'],
    'America/Argentina/Buenos_Aires' => ['label' => 'Buenos Aires', 'region' => 'Argentina', 'code' => 'ART'],
    'Europe/London'       => ['label' => 'London', 'region' => 'United Kingdom', 'code' => 'BST'],
    'Europe/Paris'        => ['label' => 'Paris', 'region' => 'France', 'code' => 'CEST'],
    'Asia/Tokyo'          => ['label' => 'Tokyo', 'region' => 'Japan', 'code' => 'JST'],
    'Australia/Sydney'    => ['label' => 'Sydney', 'region' => 'Australia', 'code' => 'AEST']
];

$activeKeys = isset($_GET['tzs']) ? explode(',', $_GET['tzs']) : ['America/Los_Angeles', 'America/New_York', 'America/Argentina/Buenos_Aires'];

// Handle adding a timezone via query param BEFORE any HTML output --
// timezone.php's own version of this same block sits at the bottom of the
// file, after the whole page has already printed, so its header('Location')
// silently fails ("headers already sent") on every real add_tz request; it
// happens to still LOOK like it works because $activeKeys is already
// updated in-memory by then, so that one response renders the new zone
// correctly -- but the URL bar never updates, so a refresh loses it. Fixed
// here by handling it up front, before the <!DOCTYPE> below.
if (isset($_GET['add_tz']) && !empty($_GET['add_tz'])) {
    $newTz = $_GET['add_tz'];
    if (!in_array($newTz, $activeKeys, true)) {
        $activeKeys[] = $newTz;
        $queryString = http_build_query([
            'date' => $selectedDate,
            'format24' => $format24 ? '1' : '0',
            'tzs' => implode(',', $activeKeys),
        ]);
        header('Location: ?' . $queryString);
        exit;
    }
}

$baseTzObj = new DateTimeZone($defaultBaseTz);
$baseTime = new DateTime($selectedDate . ' 00:00:00', $baseTzObj);

function getOffsetStr(string $targetTzIdentifier, DateTime $baseDateTime): string {
    $targetTz = new DateTimeZone($targetTzIdentifier);
    $targetOffset = $targetTz->getOffset($baseDateTime);
    $baseOffset = $baseDateTime->getTimezone()->getOffset($baseDateTime);
    $diffSecs = $targetOffset - $baseOffset;
    $diffHours = $diffSecs / 3600;

    if ($diffHours == 0) return '+0';
    return ($diffHours > 0 ? '+' : '') . $diffHours;
}

// One representative real IANA zone per whole-hour UTC-offset band -- see
// this file's own header for why whole-hour bands (not per-country hit
// testing) is the deliberate approach. PHP computes the REAL offset for
// each (including that zone's own DST rules on $selectedDate), same as
// every other row on this page -- the band only decides WHICH zone gets
// added, not what its displayed offset is afterward.
//
// Craig: "are there short names for each zone like pst or pdt ? if so can
// we add them as a title ?" -- yes for most (PDT/EDT/JST/AEST/etc, real tz
// database abbreviations via DateTime::format('T')), but a handful of
// these representative zones don't have one (Dubai, Dhaka, Bangkok,
// Buenos Aires, Azores, ...) -- format('T') falls back to a bare numeric
// offset string ("+04") for those, which would look redundant/silly next
// to the offset already shown ("UTC+4 (+04)"), so $abbr is left null in
// that case and the tooltip just skips the parenthetical instead of
// showing a fake-looking non-abbreviation. Computed once here (not
// hardcoded a second time in JS, the way this file's first draft
// duplicated it) and exported below via json_encode -- single source of
// truth, same "don't let two copies of the same table drift apart"
// lesson this project's own db_config.ini divergence taught earlier
// tonight (see data_grab_1m/session.txt).
// Craig: "can we accomodate the half hour offsets in the display and
// highlighting ?" -- yes: extended from 24 fixed whole-hour bands to a
// sorted list of every standard UTC offset actually in use, half-hour
// ones included (India +5:30, Newfoundland -3:30, Iran +3:30,
// Afghanistan +4:30, Myanmar +6:30, the Northern Territory +9:30, Lord
// Howe Island +10:30, the Marquesas -9:30).
//
// Craig: "add a quarter hour zone ? are there quarter hour zones ?" --
// yes, exactly two real ones: Nepal (+5:45) and the Chatham Islands, NZ
// (+12:45). Nepal is added below -- it fits the existing model fine,
// slotting between India (+5:30) and Bangladesh (+6:00) same as any other
// entry. Chatham Islands is NOT added (Craig's own choice, asked first):
// this whole band system treats offset-minutes as directly proportional
// to map longitude (1 degree = 4 minutes), and the map only spans -12:00
// to +12:00 (a flat 360-degree-wide equirectangular projection) --
// +12:45 computes to 191.25 degrees, past the map's right edge entirely.
// Real-world offsets go up to +14:00 (Kiribati) for date-line-convenience
// reasons that don't correspond to real geographic longitude, which this
// offset=longitude model can't represent without a bigger change (placing
// a zone like that by its REAL longitude instead, wrapping around near
// the left edge next to UTC-12 -- not attempted here).
//
// KEY IS THE STANDARD (non-DST) OFFSET IN MINUTES, not hours -- and it's
// used ONLY to decide where a zone's band SITS on the map, which must
// stay fixed regardless of the picked date (Newfoundland/Lord Howe both
// observe DST, but their real geographic position obviously doesn't move
// twice a year). This is completely separate from the grid's own
// per-row times below, which still fully reflect $selectedDate's real
// DST state via $baseTime, same as always -- band position and
// displayed time are deliberately different questions answered by
// different code.
$offsetToTz = [
    -720 => 'Etc/GMT+12', -660 => 'Pacific/Pago_Pago', -600 => 'Pacific/Honolulu',
    -570 => 'Pacific/Marquesas', -540 => 'America/Anchorage', -480 => 'America/Los_Angeles',
    -420 => 'America/Denver', -360 => 'America/Chicago', -300 => 'America/New_York',
    -240 => 'America/Halifax', -210 => 'America/St_Johns', -180 => 'America/Argentina/Buenos_Aires',
    -120 => 'Atlantic/South_Georgia', -60 => 'Atlantic/Azores', 0 => 'Europe/London',
    60 => 'Europe/Paris', 120 => 'Africa/Cairo', 180 => 'Europe/Moscow',
    210 => 'Asia/Tehran', 240 => 'Asia/Dubai', 270 => 'Asia/Kabul',
    300 => 'Asia/Karachi', 330 => 'Asia/Kolkata', 345 => 'Asia/Kathmandu', 360 => 'Asia/Dhaka',
    390 => 'Asia/Yangon', 420 => 'Asia/Bangkok', 480 => 'Asia/Shanghai',
    540 => 'Asia/Tokyo', 570 => 'Australia/Darwin', 600 => 'Australia/Sydney',
    630 => 'Australia/Lord_Howe', 660 => 'Pacific/Noumea', 720 => 'Pacific/Auckland',
];

/** "+5:30" / "-8:00" / "+0" style label from a signed minute count. */
function formatOffsetMinutes(int $mins): string {
    $sign = $mins < 0 ? '-' : '+';
    $abs = abs($mins);
    $h = intdiv($abs, 60);
    $m = $abs % 60;
    return $mins === 0 ? '+0' : $sign . $h . ($m > 0 ? ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
}

// Band boundaries: each zone's left/right edge is the MIDPOINT to its
// nearest neighbor on that side (map edges for the first/last entry) --
// this is what makes a half-hour zone's band come out correctly NARROWER
// than its full-hour neighbors automatically, with no special-casing,
// rather than every band being a fixed 15 degrees wide regardless of
// what's actually next to it.
$offsetKeys = array_keys($offsetToTz);
$offsetInfo = [];
$i = 0;
foreach ($offsetToTz as $offsetMin => $tz) {
    $prevOffset = $i > 0 ? $offsetKeys[$i - 1] : -720;
    $nextOffset = $i < count($offsetKeys) - 1 ? $offsetKeys[$i + 1] : 720;
    $leftMin = $i === 0 ? -720 : ($prevOffset + $offsetMin) / 2;
    $rightMin = $i === count($offsetKeys) - 1 ? 720 : ($offsetMin + $nextOffset) / 2;

    $tzObj2 = new DateTimeZone($tz);
    $dt = (clone $baseTime)->setTimezone($tzObj2);
    $abbr = $dt->format('T');
    $city = str_replace('_', ' ', substr($tz, strrpos($tz, '/') + 1));

    $offsetInfo[(string) $offsetMin] = [
        'tz' => $tz,
        'city' => $city,
        'abbr' => preg_match('/^[A-Za-z]+$/', $abbr) ? $abbr : null, // null, not the raw numeric fallback -- see comment above
        'offsetLabel' => formatOffsetMinutes($offsetMin),
        'centerPct' => ($offsetMin / 4 + 180) / 360 * 100, // 1 degree = 4 minutes of UTC offset
        'leftPct' => ($leftMin / 4 + 180) / 360 * 100,
        'rightPct' => ($rightMin / 4 + 180) / 360 * 100,
    ];
    $i++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>World Time Converter & Scheduler</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>%F0%9F%8C%8D</text></svg>">
    <script>
        // Craig: "does the grid persist through browser restarts ?" -> "no
        // -- state only lives in the URL" -> "yes, add localStorage
        // persistence". Runs BEFORE the page renders (same early-execution
        // spot the site's own theme-loading script elsewhere uses) so a
        // bare visit (no ?tzs=... at all -- a fresh tab, a plain bookmark
        // of the bare URL, browser restart without session restore) redirects
        // straight to whatever was last saved, instead of rendering the 3
        // hardcoded defaults first and only fixing itself a moment later.
        // A visit that ALREADY has a query string (an explicit link, or a
        // session-restored tab with its own URL intact) is left alone --
        // the URL is always the more specific/authoritative source when
        // both exist. Wrapped in try/catch -- localStorage can throw in a
        // private window or with site data blocked; a saved-state miss or
        // an error both just fall through to the normal default render.
        (function () {
            if (window.location.search) return;
            try {
                var saved = localStorage.getItem('timezoneMapState');
                if (!saved) return;
                var state = JSON.parse(saved);
                if (!state || !state.tzs) return;
                var params = new URLSearchParams({
                    date: state.date || '',
                    format24: state.format24 || '0',
                    tzs: state.tzs
                });
                window.location.replace('?' + params.toString());
            } catch (e) { /* localStorage unavailable -- render normal defaults */ }
        })();
    </script>
    <style>
        :root {
            --bg-main: #eef2f5;
            --panel-bg: #ffffff;
            --border-color: #dbe0e6;
            --primary: #2b6cb0;
            --primary-hover: #1e4e8c;
            --day-bg: #e6fcf5;
            --day-text: #0ca678;
            --work-bg: #d3f9d8;
            --work-text: #2b8a3e;
            --night-bg: #f8f9fa;
            --night-text: #868e96;
            --selected-col: #ffe066;
            --selected-border: #f59f00;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: var(--bg-main); color: #212529; padding: 20px; }

        .container { max-width: 1280px; margin: 0 auto; background: var(--panel-bg); border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; }

        header { background: #2c3e50; color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        header h1 { font-size: 20px; font-weight: 600; display: flex; align-items: center; gap: 8px; }

        .toolbar { display: flex; align-items: center; gap: 12px; }
        .toolbar label { font-size: 13px; color: #ecf0f1; }
        .toolbar input[type="date"], .toolbar select { padding: 6px 10px; border-radius: 4px; border: 1px solid #cbd5e0; font-size: 13px; }

        .time-grid-wrapper { overflow-x: auto; position: relative; }
        .time-grid { display: table; width: 100%; border-collapse: collapse; min-width: 900px; }

        .tz-row { display: table-row; border-bottom: 1px solid var(--border-color); }
        .tz-row:hover { background-color: #f8fafc; }

        .tz-info { display: table-cell; width: 220px; padding: 12px 16px; vertical-align: middle; background: #fff; border-right: 2px solid var(--border-color); position: sticky; left: 0; z-index: 5; box-shadow: 2px 0 5px rgba(0,0,0,0.02); position: relative; }
        .tz-remove-btn { position: absolute; top: 8px; right: 8px; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; border: none; background: transparent; color: #a0aec0; cursor: pointer; border-radius: 4px; padding: 0; }
        .tz-remove-btn:hover { color: #e53e3e; background: #fff5f5; }
        .tz-name { font-weight: 700; font-size: 15px; color: #2d3748; padding-right: 26px; }
        .tz-sub { font-size: 11px; color: #718096; margin-top: 2px; }
        .tz-meta { display: flex; justify-content: space-between; margin-top: 6px; font-size: 12px; }
        .tz-offset { font-weight: 600; color: #4a5568; background: #edf2f7; padding: 2px 6px; border-radius: 4px; }

        .hour-tile { display: table-cell; width: calc((100% - 220px) / 24); text-align: center; vertical-align: middle; padding: 10px 0; border-right: 1px solid #edf2f7; cursor: pointer; user-select: none; transition: background-color 0.15s ease; position: relative; }
        .hour-tile .num { font-weight: 600; font-size: 13px; }
        .hour-tile .ampm { font-size: 9px; text-transform: uppercase; margin-top: 2px; opacity: 0.8; }
        .hour-tile .date-tag { font-size: 8px; font-weight: bold; background: #4a5568; color: #fff; padding: 1px 3px; border-radius: 2px; margin-bottom: 2px; }

        .hour-tile.night { background-color: var(--night-bg); color: var(--night-text); }
        .hour-tile.day { background-color: var(--day-bg); color: var(--day-text); }
        .hour-tile.work { background-color: var(--work-bg); color: var(--work-text); }

        .hour-tile.selected-column { background-color: var(--selected-col) !important; color: #000 !important; font-weight: bold; }
        .hour-tile.selected-column::after { content: ''; position: absolute; left: 0; right: 0; top: 0; bottom: 0; border-left: 2px solid var(--selected-border); border-right: 2px solid var(--selected-border); pointer-events: none; }

        /* Vertical hover highlight across the whole column -- lighter/
           transient (mouseenter/mouseleave), distinct from the persistent
           click-to-select yellow above so the two stay visually separate
           when both are active on different columns at once. */
        .hour-tile.hovered-column { background-color: rgba(43,108,176,0.14); }
        .hour-tile.hovered-column::after { content: ''; position: absolute; left: 0; right: 0; top: 0; bottom: 0; border-left: 1px dashed var(--primary); border-right: 1px dashed var(--primary); pointer-events: none; }

        .selection-bar { background: #edf2f7; padding: 12px 24px; border-top: 2px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; min-height: 50px; font-size: 14px; }
        .selection-bar .times-summary { font-weight: 600; color: #2d3748; display: flex; gap: 16px; flex-wrap: wrap; }
        .btn-clear { background: #e53e3e; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 600; }
        .btn-clear:hover { background: #c53030; }

        /* Map-based zone picker -- replaces the old <select> "Add Timezone" form. */
        .map-picker { padding: 16px 24px; background: #f7fafc; border-top: 1px solid var(--border-color); }
        .map-picker-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; flex-wrap: wrap; gap: 8px; }
        .map-picker-header h2 { font-size: 14px; font-weight: 700; color: #2d3748; }
        .map-picker-hint { font-size: 12px; color: #718096; }
        .map-wrap { position: relative; max-width: 900px; margin: 0 auto; border: 1px solid var(--border-color); border-radius: 6px; overflow: hidden; background: #dbeafe; }
        .map-wrap img { display: block; width: 100%; height: auto; aspect-ratio: 2 / 1; cursor: crosshair; -webkit-user-drag: none; user-select: none; }
        /* Semi-opaque color stripe per zone, one per band, cycling hue
           left-to-right -- replaces the source map's flat dark #444
           country fill with clearly zone-separated color, per Craig:
           "instead of a black area map lets add some semi opaque colors
           seperating the zones". Sits ABOVE the <img> (tints it, doesn't
           hide it -- plain alpha blending, same approach the hover-band
           already used successfully, so the map's own coastlines/country
           outlines stay visible through the color) but BELOW the
           band-lines/labels/hover overlay, and ignores clicks so it never
           blocks those. */
        .map-zone-colors { position: absolute; inset: 0; pointer-events: none; }
        .map-zone-color { position: absolute; top: 0; bottom: 0; }
        .map-band-lines { position: absolute; inset: 0; pointer-events: none; }
        .map-band-line { position: absolute; top: 0; bottom: 0; width: 1px; background: rgba(43,108,176,0.25); }
        .map-band-label { position: absolute; top: 2px; font-size: 9px; font-weight: 700; color: #2d3748; background: rgba(255,255,255,0.85); padding: 0 3px; border-radius: 2px; border: 1px solid rgba(43,108,176,0.3); transform: translateX(-50%); pointer-events: auto; cursor: default; white-space: nowrap; }
        .map-band-label-alt { top: auto; bottom: 2px; }
        .map-hover-band { position: absolute; top: 0; bottom: 0; background: rgba(245,159,0,0.28); border-left: 2px solid var(--selected-border); border-right: 2px solid var(--selected-border); pointer-events: none; display: none; }
        /* Separate from map-hover-band (that one tracks the mouse over the
           MAP itself) -- this tracks hovering a GRID row instead, so the
           two never fight over the same element/state if both could be
           relevant. Green so it reads as "the grid told me to highlight
           this", distinct from the orange "mouse is over the map here". */
        .map-grid-highlight { position: absolute; top: 0; bottom: 0; background: rgba(43,138,62,0.28); border-left: 2px solid #2b8a3e; border-right: 2px solid #2b8a3e; pointer-events: none; display: none; }
        .map-tooltip { position: absolute; background: #2c3e50; color: #fff; font-size: 12px; padding: 4px 8px; border-radius: 4px; pointer-events: none; display: none; white-space: nowrap; transform: translate(-50%, -130%); z-index: 10; }
        .map-already-added { position: absolute; width: 8px; height: 8px; border-radius: 50%; background: #2b8a3e; border: 1px solid #fff; transform: translate(-50%, -50%); pointer-events: none; }
        .map-flash-msg { font-size: 12px; color: #2b8a3e; font-weight: 600; margin-top: 6px; min-height: 16px; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>World Time Converter</h1>
        <form id="controlForm" method="GET" class="toolbar">
            <input type="hidden" name="tzs" value="<?= htmlspecialchars(implode(',', $activeKeys)) ?>">
            <label for="date">Date:</label>
            <input type="date" id="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" onchange="document.getElementById('controlForm').submit()">

            <label style="margin-left: 10px;">
                <input type="checkbox" name="format24" value="1" <?= $format24 ? 'checked' : '' ?> onchange="document.getElementById('controlForm').submit()"> 24-Hour Format
            </label>
        </form>
    </header>

    <div class="time-grid-wrapper">
        <div class="time-grid">
            <?php foreach ($activeKeys as $tzKey): ?>
                <?php
                    $tzObj = new DateTimeZone($tzKey);
                    $localDate = clone $baseTime;
                    $localDate->setTimezone($tzObj);

                    // (str_replace/substr instead of end(explode(...)) --
                    // that inherited pattern from timezone.php throws a
                    // "only variables should be passed by reference" notice
                    // on modern PHP; fixed here the same way $offsetInfo's
                    // $city was computed above.)
                    $label = isset($presetTimezones[$tzKey]) ? $presetTimezones[$tzKey]['label'] : str_replace('_', ' ', substr($tzKey, strrpos($tzKey, '/') + 1));
                    $region = isset($presetTimezones[$tzKey]) ? $presetTimezones[$tzKey]['region'] : $tzKey;
                    // Same "only show a REAL letter abbreviation" rule as
                    // $offsetInfo above -- a zone added via the map (not one
                    // of the 7 hardcoded presets) falls back to
                    // DateTime::format('T'), which is a bare numeric offset
                    // ("+04") for zones with no common tz-database
                    // abbreviation; showing that next to the offset already
                    // displayed in .tz-offset below would just repeat it, so
                    // it's left blank instead.
                    $rawCode = $localDate->format('T');
                    $code = isset($presetTimezones[$tzKey]) ? $presetTimezones[$tzKey]['code'] : (preg_match('/^[A-Za-z]+$/', $rawCode) ? $rawCode : '');
                    $offsetStr = getOffsetStr($tzKey, $baseTime);
                ?>
                <div class="tz-row" data-tz="<?= htmlspecialchars($tzKey) ?>" data-tz-name="<?= htmlspecialchars($label) ?>">
                    <div class="tz-info">
                        <button type="button" class="tz-remove-btn"
                                data-tz="<?= htmlspecialchars($tzKey) ?>"
                                title="Remove <?= htmlspecialchars($label) ?> from the grid"
                                aria-label="Remove <?= htmlspecialchars($label) ?> from the grid">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                <path d="M10 11v6"></path>
                                <path d="M14 11v6"></path>
                                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
                            </svg>
                        </button>
                        <div class="tz-name"><?= htmlspecialchars($label) ?><?php if ($code !== ''): ?> <small style="font-weight:normal; font-size:11px;" title="<?= htmlspecialchars($code) ?> -- <?= htmlspecialchars($tzKey) ?>"><?= htmlspecialchars($code) ?></small><?php endif; ?></div>
                        <div class="tz-sub"><?= htmlspecialchars($region) ?></div>
                        <div class="tz-meta">
                            <span class="tz-offset"><?= $offsetStr ?> hrs</span>
                            <span style="color:#718096; font-size:11px;"><?= $localDate->format('M j, Y') ?></span>
                        </div>
                    </div>

                    <?php
                    for ($hourIndex = 0; $hourIndex < 24; $hourIndex++):
                        $tileTime = clone $baseTime;
                        $tileTime->modify("+$hourIndex hours");
                        $tileTime->setTimezone($tzObj);

                        $h = (int)$tileTime->format('H');
                        $dayChange = $tileTime->format('Y-m-d') !== $localDate->format('Y-m-d');

                        $tileClass = 'night';
                        if ($h >= 9 && $h <= 17) {
                            $tileClass = 'work';
                        } elseif ($h >= 7 && $h <= 22) {
                            $tileClass = 'day';
                        }

                        $formattedHour = $format24 ? $tileTime->format('H:00') : $tileTime->format('g');
                        $ampm = $format24 ? '' : $tileTime->format('a');
                        $fullTimeStr = $tileTime->format($format24 ? 'H:00' : 'g:i a');
                        $fullDateStr = $tileTime->format('D, M j');
                    ?>
                        <div class="hour-tile <?= $tileClass ?>"
                             data-col-index="<?= $hourIndex ?>"
                             data-time-str="<?= htmlspecialchars($fullTimeStr) ?>"
                             data-date-str="<?= htmlspecialchars($fullDateStr) ?>"
                             title="<?= htmlspecialchars($label . ': ' . $fullTimeStr . ' (' . $fullDateStr . ')') ?>">

                            <?php if ($dayChange && $h === 0): ?>
                                <div class="date-tag"><?= $tileTime->format('M j') ?></div>
                            <?php endif; ?>

                            <div class="num"><?= $formattedHour ?></div>
                            <?php if ($ampm): ?><div class="ampm"><?= $ampm ?></div><?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Active Selection Summary Bar -->
    <div class="selection-bar" id="selectionBar" style="display: none;">
        <div class="times-summary" id="summaryText">
            <!-- Selected column summary populated via JS -->
        </div>
        <button class="btn-clear" onclick="clearSelection()">Clear Selection</button>
    </div>

    <!-- Map-based zone picker (replaces the dropdown "Add Timezone" form) -->
    <div class="map-picker">
        <div class="map-picker-header">
            <h2>Click the map to add a timezone</h2>
            <span class="map-picker-hint">Hover to preview a UTC offset band &middot; click to add its representative zone</span>
        </div>
        <div class="map-wrap" id="mapWrap">
            <img id="worldMap" src="worldmap.svg" alt="World map -- click to add a timezone" draggable="false">
            <div class="map-zone-colors" id="mapZoneColors"></div>
            <div class="map-band-lines" id="mapBandLines"></div>
            <div class="map-hover-band" id="mapHoverBand"></div>
            <div class="map-grid-highlight" id="mapGridHighlight"></div>
            <div class="map-tooltip" id="mapTooltip"></div>
        </div>
        <div class="map-flash-msg" id="mapFlashMsg"></div>
    </div>
</div>

<script>
    let selectedColumnIndex = null;

    document.addEventListener('DOMContentLoaded', () => {
        const tiles = document.querySelectorAll('.hour-tile');
        tiles.forEach(tile => {
            tile.addEventListener('click', (e) => {
                const colIndex = tile.getAttribute('data-col-index');
                highlightColumn(colIndex);
            });

            // Vertical hover highlight across the column, per "lets add a
            // vertical highlite to the grid for each time hovered over" --
            // separate from the click-to-select column above (that one
            // persists until cleared/re-clicked; this one only lasts as
            // long as the cursor is actually over a tile in that column).
            tile.addEventListener('mouseenter', () => {
                const colIndex = tile.getAttribute('data-col-index');
                document.querySelectorAll(`.hour-tile[data-col-index="${colIndex}"]`).forEach(el => {
                    el.classList.add('hovered-column');
                });
            });
            tile.addEventListener('mouseleave', () => {
                const colIndex = tile.getAttribute('data-col-index');
                document.querySelectorAll(`.hour-tile[data-col-index="${colIndex}"]`).forEach(el => {
                    el.classList.remove('hovered-column');
                });
            });
        });
    });

    function highlightColumn(index) {
        document.querySelectorAll('.hour-tile.selected-column').forEach(el => {
            el.classList.remove('selected-column');
        });

        if (selectedColumnIndex === index) {
            selectedColumnIndex = null;
            document.getElementById('selectionBar').style.display = 'none';
            return;
        }

        selectedColumnIndex = index;

        const targetTiles = document.querySelectorAll(`.hour-tile[data-col-index="${index}"]`);
        targetTiles.forEach(tile => {
            tile.classList.add('selected-column');
        });

        updateSummary(index);
    }

    function updateSummary(colIndex) {
        const summaryContainer = document.getElementById('summaryText');
        summaryContainer.innerHTML = '';

        const rows = document.querySelectorAll('.tz-row');
        rows.forEach(row => {
            const tzName = row.getAttribute('data-tz-name');
            const targetTile = row.querySelector(`.hour-tile[data-col-index="${colIndex}"]`);

            if (targetTile) {
                const timeStr = targetTile.getAttribute('data-time-str');
                const dateStr = targetTile.getAttribute('data-date-str');

                const item = document.createElement('div');
                item.innerHTML = `<span style="color:#2b6cb0;">${tzName}:</span> ${timeStr} <small style="color:#718096;">(${dateStr})</small>`;
                summaryContainer.appendChild(item);
            }
        });

        document.getElementById('selectionBar').style.display = 'flex';
    }

    function clearSelection() {
        selectedColumnIndex = null;
        document.querySelectorAll('.hour-tile.selected-column').forEach(el => {
            el.classList.remove('selected-column');
        });
        document.getElementById('selectionBar').style.display = 'none';
    }

    // --- Map-based timezone picker -------------------------------------
    // A sorted list of every standard UTC offset in real use, half-hour
    // ones included (see the PHP $offsetToTz block's own comment for why
    // -- Craig: "can we accomodate the half hour offsets in the display
    // and highlighting ?"). Each entry already carries its own leftPct/
    // centerPct/rightPct, computed server-side from the midpoint to its
    // neighbors -- a half-hour zone naturally gets a narrower band than
    // its full-hour neighbors with no special-casing here. Computed ONCE
    // in PHP (includes each band's real tz-database abbreviation where
    // one exists) and exported rather than a second hardcoded copy -- the
    // actual add still goes through the server (?add_tz=...) so PHP's own
    // DateTimeZone stays the single source of truth for what each zone's
    // real current offset is; this is only for the hover preview/tooltip.
    const OFFSET_INFO = <?= json_encode($offsetInfo) ?>;
    const OFFSET_ENTRIES = Object.keys(OFFSET_INFO).map(Number).sort((a, b) => a - b);
    // Reverse lookup (tz id -> its offset-minutes key) so a grid row can
    // find its own band on the map. Safe to assume every real grid row's
    // tz IS one of these representative zones -- the ONLY way a zone ever
    // gets onto the grid is by clicking the map, which always adds one of
    // these; the 3 hardcoded default zones (America/Los_Angeles, /New_York,
    // /Argentina/Buenos_Aires) are deliberately also members of this same
    // list for exactly this reason.
    const TZ_TO_OFFSET_KEY = {};
    OFFSET_ENTRIES.forEach(mins => { TZ_TO_OFFSET_KEY[OFFSET_INFO[String(mins)].tz] = mins; });
    const CURRENT_TZS = <?= json_encode($activeKeys) ?>;
    const CURRENT_DATE = <?= json_encode($selectedDate) ?>;
    const CURRENT_FORMAT24 = <?= json_encode($format24 ? '1' : '0') ?>;

    // Save whatever state this page actually rendered with -- on EVERY
    // load, not just when something changes. Every real state change here
    // (add/remove a zone, change date, toggle 24h) already does a full
    // navigation to a new URL, so "save on load" naturally covers every
    // case with one line, no need to hook each individual action
    // separately. try/catch for the same reason the head script has one.
    try {
        localStorage.setItem('timezoneMapState', JSON.stringify({
            date: CURRENT_DATE,
            format24: CURRENT_FORMAT24,
            tzs: CURRENT_TZS.join(',')
        }));
    } catch (e) { /* localStorage unavailable -- URL is still the source of truth for this load */ }

    /** Which offset-minutes band (a key into OFFSET_INFO) contains this x position, by its actual left/right boundaries -- not a fixed-width round(). */
    function offsetMinutesFromClientX(wrapEl, clientX) {
        const rect = wrapEl.getBoundingClientRect();
        const fraction = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
        const pct = fraction * 100;
        for (const mins of OFFSET_ENTRIES) {
            const info = OFFSET_INFO[String(mins)];
            if (pct >= info.leftPct && pct < info.rightPct) return mins;
        }
        return OFFSET_ENTRIES[OFFSET_ENTRIES.length - 1]; // right map edge -> last entry
    }

    document.addEventListener('DOMContentLoaded', () => {
        const wrap = document.getElementById('mapWrap');
        const hoverBand = document.getElementById('mapHoverBand');
        const gridHighlight = document.getElementById('mapGridHighlight');
        const tooltip = document.getElementById('mapTooltip');
        const bandLines = document.getElementById('mapBandLines');
        const zoneColors = document.getElementById('mapZoneColors');
        const flashMsg = document.getElementById('mapFlashMsg');

        // Per "when hovering over the grid can we also highlite the grid
        // zones in the map zones ?" -- hovering ANYWHERE in a tz-row (the
        // info cell or any of its 24 hour-tiles) highlights that same
        // zone's own band on the map, using its real leftPct/rightPct (so
        // a half-hour zone like India highlights its own correctly-narrow
        // band, not a generic fixed width).
        document.querySelectorAll('.tz-row').forEach(row => {
            const tz = row.getAttribute('data-tz');
            const key = TZ_TO_OFFSET_KEY[tz];
            if (key === undefined) return; // shouldn't happen -- see TZ_TO_OFFSET_KEY's own comment
            const info = OFFSET_INFO[String(key)];
            row.addEventListener('mouseenter', () => {
                gridHighlight.style.left = info.leftPct + '%';
                gridHighlight.style.width = (info.rightPct - info.leftPct) + '%';
                gridHighlight.style.display = 'block';
            });
            row.addEventListener('mouseleave', () => {
                gridHighlight.style.display = 'none';
            });
        });

        // One semi-opaque color stripe per band, cycling hue evenly across
        // all of them left-to-right -- replaces the source map's flat dark
        // #444 country fill with clear zone-to-zone visual separation
        // (Craig: "instead of a black area map lets add some semi opaque
        // colors seperating the zones"). hsla (not a fixed palette array)
        // so this scales automatically if OFFSET_ENTRIES ever grows (e.g.
        // adding the quarter-hour zones this file's own header already
        // notes were left out) without needing more colors hand-picked.
        OFFSET_ENTRIES.forEach((mins, idx) => {
            const info = OFFSET_INFO[String(mins)];
            const hue = Math.round((idx / OFFSET_ENTRIES.length) * 360);
            const stripe = document.createElement('div');
            stripe.className = 'map-zone-color';
            stripe.style.left = info.leftPct + '%';
            stripe.style.width = (info.rightPct - info.leftPct) + '%';
            stripe.style.background = `hsla(${hue}, 70%, 60%, 0.35)`;
            zoneColors.appendChild(stripe);
        });

        // Draw a faint vertical guideline at each band's center, same
        // visual language as the NIST-style reference Craig pointed at --
        // plus, per "lets add the zones with abbreviatons as titles on the
        // map", an always-visible label per band (not just on hover): its
        // real abbreviation (PST/EDT/JST/IST/...) where OFFSET_INFO has
        // one, the bare UTC offset otherwise. Alternates two rows (top/
        // bottom) so adjacent labels don't collide -- more useful here
        // than ever now that the half-hour entries sit close to their
        // full-hour neighbors. Each also carries a native title=""
        // attribute -- a real browser tooltip with the full detail,
        // independent of the custom mousemove-tracked one.
        OFFSET_ENTRIES.forEach((mins, idx) => {
            const info = OFFSET_INFO[String(mins)];

            const line = document.createElement('div');
            line.className = 'map-band-line';
            line.style.left = info.centerPct + '%';
            bandLines.appendChild(line);

            const label = document.createElement('div');
            label.className = 'map-band-label' + (idx % 2 === 0 ? '' : ' map-band-label-alt');
            label.style.left = info.centerPct + '%';
            label.textContent = info.abbr || info.offsetLabel;
            label.title = `UTC${info.offsetLabel} — ${info.city}${info.abbr ? ' (' + info.abbr + ')' : ''}`;
            bandLines.appendChild(label);
        });

        // Highlight the zone's OWN real band width (variable now -- a
        // half-hour zone like India is genuinely narrower than a full-hour
        // one), not a fixed 15-degree box.
        wrap.addEventListener('mousemove', (e) => {
            const mins = offsetMinutesFromClientX(wrap, e.clientX);
            const info = OFFSET_INFO[String(mins)];
            const rect = wrap.getBoundingClientRect();
            hoverBand.style.left = info.leftPct + '%';
            hoverBand.style.width = (info.rightPct - info.leftPct) + '%';
            hoverBand.style.display = 'block';

            const abbrPart = info.abbr ? ` (${info.abbr})` : '';
            tooltip.textContent = `UTC${info.offsetLabel}${abbrPart} — click to add ${info.city}`;
            tooltip.style.left = ((e.clientX - rect.left)) + 'px';
            tooltip.style.top = (e.clientY - rect.top) + 'px';
            tooltip.style.display = 'block';
        });

        wrap.addEventListener('mouseleave', () => {
            hoverBand.style.display = 'none';
            tooltip.style.display = 'none';
        });

        wrap.addEventListener('click', (e) => {
            const mins = offsetMinutesFromClientX(wrap, e.clientX);
            const info = OFFSET_INFO[String(mins)];
            if (!info) return;
            const tz = info.tz;

            if (CURRENT_TZS.includes(tz)) {
                flashMsg.style.color = '#718096';
                flashMsg.textContent = `${info.city} (UTC${info.offsetLabel}${info.abbr ? ' ' + info.abbr : ''}) is already on the grid.`;
                return;
            }

            const params = new URLSearchParams({
                date: CURRENT_DATE,
                format24: CURRENT_FORMAT24,
                tzs: CURRENT_TZS.join(','),
                add_tz: tz
            });
            window.location.href = '?' + params.toString();
        });
    });

    // Remove-from-grid button (trash-can icon) -- one per tz-row, only
    // rendered for zones currently on the grid. Craig: "instead of a check
    // box for the delete lets use a garbage can icon" (was a checkbox,
    // unchecking = remove; same underlying GET-param mechanism as adding,
    // just filtering instead of appending -- only the control itself
    // changed).
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.tz-remove-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const removeTz = btn.getAttribute('data-tz');
                const remaining = CURRENT_TZS.filter(tz => tz !== removeTz);
                const params = new URLSearchParams({
                    date: CURRENT_DATE,
                    format24: CURRENT_FORMAT24,
                    tzs: remaining.join(',')
                });
                window.location.href = '?' + params.toString();
            });
        });
    });
</script>

</body>
</html>
