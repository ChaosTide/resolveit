# ResolveIT — UI Redesign: Logo, Sign-In Page, Color System, Ticket List

Second design pass on the front end. The first pass (teal/warm-paper palette,
split-screen sign-in layout) replaced the original generic blue SaaS look but
still read as too soft/generic for an IT help desk. This pass replaces the
logo, removes boilerplate copy, and re-themes the whole app around the thing
the tool is actually about: network infrastructure.

## 1. Logo

The brand mark was `icon('tickets')` — a ticket-stub glyph (rounded
rectangle with two notched edges and a perforation line) reused from the
ticket-list UI. It is still used there; it just isn't a logo.

First attempt: a hand-drawn `icon('network')` SVG added to the existing
stroke-icon set (a cable running into a connector with contact prongs).
That got replaced in the same pass — Jordan supplied the actual artwork to
use (a server rack + wifi router glyph on a rounded orange square) instead.
Since it's a real piece of artwork rather than a simple stroke icon, it's
handled differently from the rest of the icon system:

- Source image cropped to just the icon square (one-off local processing
  with Pillow, not committed as a script) and the surrounding banner
  background keyed out by color distance, leaving the badge's own flat
  orange fill, white line art, and cyan status-light dots untouched and
  intact — first attempt re-extracted just the white/cyan glyph onto this
  app's own copper gradient badge, which technically worked but wasn't
  what was asked for ("I want this only, not your slop"); this version
  uses the supplied square exactly as given, only with its rounded
  corners cut to transparent so it composites cleanly onto either the
  sidebar or the sign-in panel. Saved as
  [`public/assets/logo-mark.png`](../public/assets/logo-mark.png)
  (256x256, ~30KB).
- The existing `.logo` div still supplies sizing/shadow; its own gradient
  background is now irrelevant since the image is opaque everywhere
  except the transparent corner cutouts.
- New `logo_mark()` helper in [`app/ui.php`](../app/ui.php) emits the
  `<img>` tag, cache-busted with `filemtime()` the same way
  `style.css`/`app.js` already are in `header.php`.
- `icon('network')` was removed again since nothing calls it anymore.

Swapped into the two places that are actually "the logo" — the sign-in
page brand mark and the sidebar header — via
[`public/partials/header.php`](../public/partials/header.php). Left every
other `icon('tickets')` call (nav link, empty states) alone, since those are
correctly describing tickets, not the brand.

## 2. Sign-in page

- Removed the boilerplate line under "Sign in" ("The ResolveIT IT help
  desk. Use the email and password your administrator gave you.") —
  [`public/login.php`](../public/login.php).
- Added icon adornments to the Email and Password fields (new
  `icon('mail')` / `icon('lock')`), and a trailing `icon('arrow-right')` on
  the submit button.
- Added a 3px brand-color top border to the sign-in card (`.auth-card` in
  [`style.css`](../public/assets/style.css)) so the card reads as branded
  rather than a bare white box.

### Bug hit while wiring up the field icons

The password field's icon disappeared completely after the first pass,
while the identically-built email field's icon rendered fine. Root cause —
a CSS stacking-context collision, not a markup mistake:

1. `app.js`'s `enhance()` already wraps every `input[type="password"]` in
   its own `<div class="password-field">` at runtime, to host the
   show/hide toggle button. That wrapper is `position: relative`.
2. My new markup wrapped the *same* input a second time, in a
   `<div class="field-icon password-field">`, to host the left-aligned
   lock icon (`position: absolute`).
3. After `enhance()` ran, the DOM was two nested `.password-field`
   wrappers: outer (icon + inner wrapper), inner (input + toggle button).
   Both wrappers are `position: relative` — i.e. both are now in the
   browser's "positioned" paint layer, same as the absolutely-positioned
   icon. Per the CSS2.1 painting-order rules, positioned siblings with
   `z-index: auto` paint in DOM order, and the inner wrapper (containing
   the opaque white input) comes *after* the icon in the markup — so it
   painted on top of it, hiding it completely.
4. The email field never hit this because nothing else touches its
   markup at runtime: the input stays `position: static`, and static
   content always paints *before* the positioned layer regardless of DOM
   order — so the icon, positioned, naturally sat on top.

Fix: `z-index: 1` on `.field-icon > .icon` (one line, in `style.css`).
That forces the icon above *any* positioned sibling, including ones
injected by other scripts later, without needing to know what they'll do.

## 3. Color system

Full token re-theme in `:root` (`public/assets/style.css`), away from the
warm teal/paper palette toward something that reads as network/server
infrastructure: dark graphite chrome, a copper/bronze primary (copper
cabling), a cool cyan secondary (status-light accent), neutral cool grays
instead of warm paper. Semantic status colors (success/warning/danger/
info/violet — used for ticket priority and SLA badges) were left alone on
purpose: they're a working, accessible, conventional traffic-light system
unrelated to brand identity, and changing them risks confusing meanings
that are already correct.

| Token | Old | New | Used for |
|---|---|---|---|
| `--primary` | `#0c6b5c` (teal) | `#9a3412` (copper) | buttons, links, active states |
| `--primary-hover` | `#094f44` | `#7c2d12` | button hover |
| `--primary-soft` | `#e2f2ee` | `#fbe7da` | tinted backgrounds behind primary text/icons |
| `--bg` | `#f5f3ee` (warm paper) | `#eef0f3` (cool neutral) | page background |
| `--surface-muted` | `#efece3` | `#eceef1` | table headers, muted panels |
| `--border` / `--border-strong` | warm tan | cool gray | borders |
| `--text` / `--text-muted` | warm near-black/tan-gray | cool near-black/gray | body text |
| `--sidebar` | `#15130f` | `#121417` | sidebar chrome |
| `--sidebar-accent` | `#3fd6b8` (teal) | `#f0a846` (gold) | active-nav bar, auth feature icons |
| `--accent` | `#c75a1f` (burnt orange) | `#0e7490` (cyan) | sign-in page decorative glow |
| `--radius-sm/--radius/--radius-lg` | `10/16/22px` | `7/11/15px` | tightened — reads more technical, less "rounded consumer app" |

`--primary` contrast against white button text: 7.3:1 (WCAG AAA for normal
text, well past the 4.5:1 AA bar for button labels). `--text-muted` against
the new `--bg`: 4.77:1 (passes AA).

A handful of spots hardcode an rgba() mirror of a token instead of
referencing it (focus rings, hover shadows, the auth-page glow blob, the
`.logo` badge gradient) — pre-existing pattern from the first redesign
pass, not something this pass introduced. Updated every one of them to the
new values; grepped for every old hex/rgb literal afterward to make sure
none were missed.

## 4. Verification

| Check | Result |
|---|---|
| `phpcs` (PSR-12, `app/ui.php` + `public/login.php`) | 0 errors, 0 warnings |
| `tests/check-local.ps1` | 137 / 137 pass (confirms the pass is purely cosmetic — no behavior changed) |
| Manual pass | sign-in page, dashboard, ticket list + table checked in-browser at desktop width |

## 5. Files touched

| File | Change |
|---|---|
| [app/ui.php](../app/ui.php) | Added `mail`, `lock`, `arrow-right` icons and the `logo_mark()` helper |
| [public/assets/logo-mark.png](../public/assets/logo-mark.png) | New — the supplied server-rack/router artwork, background removed |
| [public/login.php](../public/login.php) | Removed tagline; added field icons and button arrow |
| [public/partials/header.php](../public/partials/header.php) | Logo now uses `logo_mark()` |
| [public/assets/style.css](../public/assets/style.css) | Full color re-theme, tightened radii, `.field-icon` rules, button gradient, `.auth-card` accent border |

## 6. Logo size, and decluttering the ticket list

Two follow-up rounds after Jordan actually looked at the result.

**Logo too small.** The badge was hard to read the glyph detail on. Sizes
roughly doubled: sidebar badge 34px → 52px (icon 20px → 44px), sign-in page
badge 42px → 76px (icon 24px → 64px), wordmark font-size scaled up to match
on both (`public/assets/style.css`).

**"All Tickets" page felt cluttered.** Before any tickets are even visible,
the page showed: 9 status chips (6 of them reading "0" at the moment),
a filter form with all 6 dropdown fields always expanded, and a bulk-action
bar with an "Apply to selected" button always visible even with nothing
selected. Three rounds of "always-on" chrome stacked above the actual data.
Fixes, all in [`public/tickets.php`](../public/tickets.php) +
[`style.css`](../public/assets/style.css), no behavior removed:

- **Bulk-action bar now hides until something is selected.** The JS
  (`app.js`) already toggled a `.has-selection` class on the bar as
  checkboxes changed — it just wasn't being used for visibility, only for
  color. One CSS rule (`.bulk-bar:not(.has-selection) { display: none; }`)
  was enough; no JS change needed.
- **Dropdown filters (Status/Priority/Category/Assigned to/Organization/Sort)
  collapsed into a native `<details>` ("More filters"), closed by default.**
  It auto-opens (`open` attribute, server-rendered) when one of those
  filters is already active via the URL, so an admin who arrives with
  `?status=3` set sees why the list is filtered instead of it being hidden.
  Only the free-text search field stays visible at all times, since it's the
  one filter without a `data-autosubmit` dropdown shortcut. Sort was moved
  in here too since it duplicates the ticket table's clickable column
  headers — it's a rarely-needed secondary control, not a primary one.
- **Zero-count quick-view chips (e.g. "Unassigned 0") visually recede**
  (`.chip-zero`: 50% opacity, dashed border, full opacity on hover) instead
  of competing equally with chips that actually have something to show.
  A chip that's currently selected never gets this treatment even at zero,
  since you're deliberately looking at that (empty) view.

No markup was deleted — every field and control is still reachable, just
not fighting for attention when it isn't relevant. Verified
`tests/check-local.ps1` still finds `id="bulk-form"` unconditionally in the
server-rendered HTML (visibility is CSS-only, the test checks raw HTML text)
and the Status/Priority dropdown `<select>` elements still submit via GET
query params the same way regardless of whether `<details>` is open, so
none of the existing filter/bulk-update tests needed changes.

| Check | Result |
|---|---|
| `phpcs` (`public/tickets.php`) | 0 errors, 0 warnings |
| `tests/check-local.ps1` | 137 / 137 pass |
| Manual pass | checkbox → bulk bar appears/disappears correctly; "More filters" expands; verified at desktop and mobile width |

## 7. Dashboard: breaking up the identical-tile wall

The staff dashboard's KPI grid was 8 cards, all the exact same shape (icon
chip top-right, big number, label, note), differing only by accent color —
a very recognizable "generated dashboard" pattern. The underlying 8 numbers
(`dashboard.php`'s staff query) didn't need to change, just how they're
grouped and weighted.

Added `stat_group()` to [`app/ui.php`](../app/ui.php) alongside the existing
`kpi_tile()` — one card that holds several related mini-stats (label, value,
tone, href each) instead of each needing a full identical tile. Regrouped
the 8 numbers by what they mean, not just rendered them in query order:

- **Open tickets** and **Unassigned** stay as the two full-size hero tiles —
  overall volume and the thing most needing triage.
- **"At risk"** — Overdue, Due in 4h, Past due date: three different flavors
  of "this is time-sensitive," now one card with three colored mini-stats
  instead of three separate same-shaped boxes.
- **"Your day"** — My queue, Resolved today, Waiting on requester: the more
  personal/ambient numbers, grouped the same way.

`.stat-group` spans two grid columns (`grid-column: span 2`) in the existing
`.grid`, so it naturally sits wider than the two hero tiles rather than
being force-fit into the same box size — that size difference is what
actually breaks the "wall of identical cards" look. Each mini-stat is still
its own link to the matching filtered ticket view (`tickets.php?view=...`),
so no functionality was lost, only the shape changed. Mobile (≤640px) stacks
each group's mini-stats into a single column with a top divider instead of
a cramped 2-up grid, after a first pass caused labels like "Resolved today"
to wrap mid-word.

The employee-facing dashboard (4 simple tiles: Open/Resolved/Closed/All) was
left alone — at that size it doesn't show the same repetitive-wall problem.

| Check | Result |
|---|---|
| `phpcs` (`app/ui.php`, `public/dashboard.php`) | 0 errors, 0 warnings |
| `tests/check-local.ps1` | 137 / 137 pass |
| Manual pass | desktop width (single row, 4 cards) and mobile width (stacked, no text wrapping) |

## 8. The rest of the app: ticket detail, reports, settings, knowledge base

Same ask, applied to the other most-visited pages rather than a from-scratch
redesign of all seventeen — Jordan wanted every page to stop looking
generic, and a template/framework wasn't an option (the CSP is
`default-src 'self'`, so there's no loading Bootstrap/Tailwind/an icon font
from a CDN; every page already shares one hand-rolled CSS system). Searched
for current ITSM/helpdesk UI patterns first instead of guessing — see
sources below — then applied the ones that actually fit this app.

**[`ticket.php`](../public/ticket.php) — the single most-used page after the
list:**
- The status breadcrumb ("Open › Assigned › In Progress…") was plain text
  pills joined by a literal `›` character. Replaced with an actual
  connected-dot stepper (`<ol class="workflow">`, a `::before` line between
  each `<li>`), the pattern used by essentially every real ticketing tool
  (Jira, Zendesk, Linear). Simplified the PHP at the same time — the old
  version tracked a loop index just to decide whether to print an arrow;
  the new one doesn't need to.
- Conversation replies were a flat list separated by a hairline, with no
  visual distinction between a staff reply and a requester reply beyond a
  small text tag. Each reply is now its own rounded, padded "bubble" with a
  background tint keyed to who wrote it (`role-staff` / `role-requester`).

**[`reports.php`](../public/reports.php):** had the exact same
identical-tile problem the dashboard did — 6 plain stat boxes in a row.
Reused `stat_group()` (built for the dashboard, not reports-specific) to
group "Created"/"Resolved" into one "This period" card and
"Unresolved"/"Overdue"/"Unassigned" into one "Current backlog" card, with
"Met SLA" staying as its own hero number since it's the page's headline
metric. Also found and fixed a real leftover bug while in here: the report
bar-chart fill was still `linear-gradient(90deg, #4fb7a1, ...)` — `#4fb7a1`
is a teal from the *original* palette, before either redesign pass, that
never got caught when the colors moved to copper. Now uses the current
`--sidebar-accent` → `--primary` tokens. Removed `.alert-stat`, which this
change made fully dead CSS (zero remaining references).

**[`settings.php`](../public/settings.php):** every table row had its own
full bold primary-colored "Rename"/"Save" button — five-plus identical loud
orange buttons stacked down a column, each pulling equal visual weight
against the one real primary action ("Add Category"). Changed per-row
buttons to `secondary small`; left `Add Category`/`Add Organization` as the
only bold buttons on the page, since those are the actual primary actions.

**[`public/users.php`](../public/users.php):** already fine as built — quiet
"Edit" next to a deliberately loud red "Deactivate" is the right contrast,
left alone.

**[`kb.php`](../public/kb.php):** regression, not a redesign — reusing the
`.filters` class from the tickets-page rework (restructured in section 6 to
stack vertically for the "More filters" `<details>`) broke this page's
filter form, which never got the new `.filter-row` wrapper. The Search
button rendered full-width and oversized (a flex column's default
`align-items: stretch` stretching a lone `<button>` child to fill the
card). Fixed by wrapping its two fields the same way `tickets.php` already
does. Checked `search.php` too since it's the other filter-heavy page — it
uses its own markup, untouched by the `.filters` change, no fix needed.

**One test had to change.** `tests/check-local.ps1`'s date-range check
string-matched the *exact* old reports.php markup
(`'Created in period</span><strong>0<'`). That label doesn't exist anymore
— it's `Created` inside the new "This period" group. Updated the assertion
to match the new (but equivalently specific) string
(`'<strong>0</strong><span>Created</span>'`); the behavior being tested
(date-range filtering actually filters) didn't change, just the markup
shape it was pinned to. Same kind of test update the original PSR-12 pass
already made per [ENGINEERING-NOTES.md](ENGINEERING-NOTES.md) section 4.

| Check | Result |
|---|---|
| `phpcs` (all files above) | 0 errors, 0 warnings |
| `tests/check-local.ps1` | 137 / 137 pass (one assertion updated to match intentionally changed markup, not loosened) |
| Manual pass | every page above checked in-browser after each change |

Sources consulted for current ITSM/helpdesk UI conventions (vendor
redesigns, not templates — nothing here was copied, CSP forbids external
assets regardless): [ManageEngine ServiceDesk Plus Cloud redesign](https://www.manageengine.com/products/service-desk/blog/new-design-layout-and-features-the-all-new-ui-of-servicedesk-plus-cloud-is-here.html),
[Kaseya BMS Service Desk dashboard](https://help.bms.kaseya.com/help/Content/Service%20Desk/bms-service-desk-new-service-desk-dashboard.html),
[Frontline help desk status-band pattern](https://inventoryandhelpdeskmanagement-help.frontlineeducation.com/hc/en-us/articles/4410201806611).

### Not yet touched

`ticket-create.php`, `ticket-edit.php`, `user-edit.php`, `search.php`,
`kb-article.php`, `kb-edit.php` are plain forms/detail pages without the
specific problems fixed above (identical-tile walls, repeated loud buttons,
a stale-color bug). They weren't reviewed as closely as the pages above —
worth a pass if more of the same "looks generic" feedback is about one of
them specifically.

## 9. The sign-in page, properly this time

Three earlier passes on this page (sections 1–2, and the size bump) kept
the same split-screen shape — a dark panel with marketing copy on the
left, a light panel with the form on the right — and only ever changed its
color and content. After a fourth "looks like AI slop," the shape itself
was the problem, not the execution of it: that split-screen-plus-tagline
layout is what most generated SaaS auth pages look like, regardless of
palette. Went and looked at real references (Dribbble "login page"
results) instead of guessing again, and picked the pattern a few of the
strongest real shots use: **one centered card, nothing else.**

It's also a better fit for what this page is. "Log a ticket in seconds!
SLA-backed response times!" is copy written to convince a stranger to sign
up — this is a daily sign-in for people who already use the tool and don't
need to be sold on it. Cut entirely, not just restyled.

**What changed** (`public/partials/header.php`, `footer.php`,
`style.css`): the `.auth-brand` side panel, `.auth-tagline`, and
`.auth-features` are gone. The dot-grid texture and copper→cyan glow
(both kept — they were good) now cover the *whole* page instead of just
the left half. Logo + wordmark moved to a small header lockup sitting
above the card instead of a hero inside a separate panel.

**Two real bugs, not just follow-up polish** — caught by actually looking
at the result instead of assuming the refactor was safe:

1. The new full-page background set `color: #fff` for the brand lockup
   and footer text. That's correct for them, but color inherits, and it
   cascaded straight into the white card underneath — "Sign in" and the
   field labels rendered white-on-white, invisible. One line fixes it:
   `.auth-main { color: var(--text); }` resets it back for everything
   inside the card.
2. At mobile width the card was cut off on the right with no way to
   scroll to it. The glow blob's `right: -160px` was overflowing the
   page by exactly 160px, and `overflow: hidden` on `<body>` (`.auth-layout`
   is the `<body>` tag) didn't stop `document.documentElement.scrollWidth`
   from growing past `clientWidth` — confirmed via a direct JS check, not
   a guess. Fixed with `html { overflow-x: hidden }`; re-checked that
   `scrollWidth === clientWidth` afterward.

| Check | Result |
|---|---|
| `phpcs` (`header.php`, `footer.php`) | 0 errors, 0 warnings |
| `tests/check-local.ps1` | 137 / 137 pass |
| Manual pass | desktop (full page, no scroll); mobile 375px (confirmed `scrollWidth === clientWidth` via JS, not just visual check) |

## 10. Navigation: left sidebar → right sidebar → top nav

Three positions for the same navigation in close succession. Jordan didn't
want it on the left; moved it to the right first (section 9's sequel,
smallest possible change — same vertical list, same collapse behavior, just
mirrored); then asked for the top specifically. A horizontal top nav isn't
a smaller version of the same change — vertical space is comparatively
abundant, horizontal space is tight, and cramming a logo plus 7 nav items
(4 general, a divider, 3 admin-only) into one row needed an actual decision
about what gets cut or wrapped, not just a mirrored transform.

**What it became:** the app shell is a plain top-to-bottom stack now —
light `.topbar` (search, avatar, the collapse toggle), dark `.top-nav`
(logo + every nav link, in the same copper/graphite palette the old
sidebar used), then page content, then a plain-text footer. No more CSS
Grid two-column layout; `.app-main` as a wrapper div is gone entirely,
since there's no longer a side column for it to sit opposite.

**The collapse mechanism changed, not just its trigger.** The sidebar
version (sections 6, 9) animated a CSS Grid column's width — which is how
the sticky-topbar repaint bug happened in the first place, and needed real
investigation to fix (not blind trial and error — isolated it to the mere
*presence* of a `transition` on `grid-template-columns`, confirmed by
directly reading `getBoundingClientRect` rather than trusting screenshots,
since those turned out to be unreliable while the browser pane itself is
backgrounded). A horizontal row doesn't have that option — collapsing it
means animating `max-height`/`opacity` on the row itself instead of a
grid track on its container. Different property, but the same lesson
carried over anyway: the transition is added via a `.nav-animating` class
only for the ~220ms a toggle click is actually running, never left on
permanently, same reasoning as before. `app.js` dropped roughly half its
sidebar-toggle code in the process — no more narrow-vs-wide branching for
*how* it collapses (only for the *default* state it starts in), since
max-height/opacity works identically at every width, where the old
version needed one mechanism for a fixed-position mobile overlay and a
completely different one for a desktop grid-column collapse.

**Real bug caught before shipping, not after:** the first version of the
mobile layout (`flex-direction: column` for narrow screens) silently
inherited `flex-wrap: wrap` and `max-height: 200px` from the desktop rule,
so instead of one stacked list it wrapped into two side-by-side columns
once it ran out of vertical room. Fixed with an explicit `flex-wrap: nowrap`
and a viewport-relative `max-height: 70vh` (with `overflow-y: auto` as a
safety net) for the mobile case specifically — caught by actually looking
at the rendered mobile layout, not assumed to work from the desktop version
looking right.

| Check | Result |
|---|---|
| `phpcs` (`header.php`, `footer.php`) | 0 errors, 0 warnings |
| `tests/check-local.ps1` | 137 / 137 pass |
| Manual pass | desktop expand/collapse round-trip; sticky topbar re-verified correct after scroll (`getBoundingClientRect`, not just a screenshot); mobile expand/collapse; a second page (tickets.php) checked, not just dashboard |

## 11. Reports page: 8 tables at once → a closed-by-default accordion

Jordan's complaint, verbatim: "this page looks like crap there is sto much
going on here make it more asseable here and easy to see read and
sunstertansd" — read as too much going on, make it more accessible and
easy to see/read/understand. The screenshot showed exactly that: all 8
report tables (status, priority, category, workload, resolution, daily,
SLA, due-time) rendered simultaneously below the 3 KPI cards, two of them
(SLA compliance's 8 columns, the due-time ticket list) wide enough to need
a hard-coded `if ($key === 'sla') { echo '</div>'; }` in the loop just to
break them out of the 2-column `.report-grid` and give them room.

**What it became:** every report is now a `<details class="card
report-card">` — closed by default, so the page loads as a plain scannable
list of 8 titles (same visual weight as a table of contents), each one
opening full-width on click instead of being squeezed into a 420px grid
column. That made the old `sla`-key special case unnecessary — deleted
instead of carried forward, since nothing is ever mid-row anymore. Markup
otherwise follows the `.filter-more` "More filters" disclosure already
established on tickets.php (same triangle marker, same hover/open styling)
rather than inventing a second accordion convention.

**Real bug caught by testing the actual interaction, not just the
markup:** the dashboard/reports KPI tiles link to anchors like
`#report-status`, which used to just scroll to a `<section>`. Against a
`<details>`, landing on a closed one is useless, so `app.js` opens the
matching `<details>` on load if `location.hash` names one. First version
only ran that check inside the existing `DOMContentLoaded` handler — looked
right in isolation (`document.getElementById('report-sla').open` was
`true`) until tested the way a user actually hits it: clicking a KPI tile
*from the already-loaded reports page*. That's a same-document fragment
navigation, which doesn't re-fire `DOMContentLoaded` in any browser, so the
open-logic silently never ran for the one path that matters. Fixed by
pulling the check into a named function and also binding it to
`window.addEventListener('hashchange', ...)`, then re-verified both paths
separately: a fresh load straight to `reports.php#report-sla`, and a click
on the "Created" KPI tile from an already-open reports.php.

| Check | Result |
|---|---|
| `phpcs` (`reports.php`) | 0 errors, 0 warnings |
| `tests/check-local.ps1` | 137 / 137 pass (one assertion updated: `<h2>$_.` → `<span class="report-number">$_</span>`, matching the new markup — the behavior it checks, that all 8 numbered reports render, is unchanged) |
| Manual pass | expand/collapse on click; fresh-load deep link opens the right report; same-page KPI-tile click opens the right report (the actual hashchange bug, not just the easy case); mobile width has no horizontal overflow (`document.documentElement.scrollWidth` checked directly, not just eyeballed); widest table (SLA compliance, 8 columns) renders full-width when expanded |

## 12. Sign-in page, one more pass — bigger masthead, atmosphere instead of flat gradient

Section 9 cut the sign-in page down to one centered card on a flat dark radial gradient,
which fixed the "generic SaaS split-screen" problem but read as flat/empty on its own —
Jordan's words, "boring," pointing at a WordPress blog theme ("Ashe," a free theme off
WordPress.org — `ashe.2.275.zip`) as the kind of look wanted instead: a big logotype
sitting over an atmospheric full-bleed scene.

**Not reused as-is, on purpose.** Ashe is a full WordPress theme — PHP template files,
`functions.php`, a WooCommerce stylesheet, none of it compatible with this plain-PHP app —
and its screenshot is the theme's own bundled stock photography, not licensed for reuse
outside that theme. Extracted it to a scratchpad folder to look at `screenshot.jpg` only
(the WP theme-chooser preview image), then rebuilt the *idea* — big wordmark over a rich
background scene — with this project's own assets:

- **Background:** the old single `::after` (a flat teal circle blob) is now two soft
  `radial-gradient` glows (copper top-left, cyan bottom-right, both existing brand tokens)
  layered under the existing dot-grid texture. Still zero images or fonts loaded over the
  network — this page's CSP is `default-src 'self'` (see the file header comment in
  `style.css`), same constraint noted in section 8.
- **In place of a hero photo:** `.auth-layout::after` now renders a huge (640px),
  barely-there (8% opacity) copy of the project's own `logo-mark.png`, rotated and bled off
  the right edge — gives the page depth/a "scene" the way Ashe's photo does, using artwork
  this project already owns instead of a stranger's stock photography.
- **Brand mark:** was a small single-line `[icon] ResolveIT` lockup. Now a proper masthead —
  bigger badge (58px), "ResolveIT" set large in the serif (2.15rem) with a small tracked-caps
  kicker underneath ("IT SERVICE DESK"), echoing Ashe's big script wordmark + subtitle
  without copying its script typeface (this app loads system fonts only, same CSP reason).

New rules: `.auth-brand-text`, `.auth-wordmark`, `.auth-kicker` in
[`style.css`](../public/assets/style.css); markup split into a wrapped `<span>` structure in
[`header.php`](../public/partials/header.php) so the wordmark and kicker can be styled
independently instead of being one text node next to the icon.

| Check | Result |
|---|---|
| `phpcs` (`header.php`) | 0 errors, 0 warnings |
| Manual pass | signed in end-to-end after the change (confirms the markup split didn't break the form); desktop width (1440px) and mobile width (375px) both checked in-browser |

Sources consulted: the Ashe WordPress theme screenshot, for the general shape (big
logotype over an atmospheric scene) only — no code, fonts, or images from it were used;
see above for why.

## 13. Manage Users: filter by role and organization, three sort orders

Noah's feedback (relayed by Jordan): no way to narrow the user list down, comparable to
how AD groups accounts by OU or applies policy by GPO — mainly a problem once the
employee pool is seeded larger to mimic a real company. In scope for Task 6 (this is the
user/role-access page); out of scope was a literal "company column" sort, since the
project already has a real `organization_id` per user, just nothing on this page to
filter or sort by it.

**What it became** (`public/users.php`): a `.filters` card — same pattern as
`tickets.php`'s "More filters" row (`select[data-autosubmit]`, no new JS, `app.js`
already submits the form on change) — sitting between "Create user" and the table.

- **Role** — any / employee / technician / administrator. Exact match on `roles.name`.
- **Organization** — any / no organization / each organization, including inactive ones.
  Deliberately pulled straight from `organizations` rather than reusing
  `organization_options()` (the Create-user dropdown above it) — that helper only lists
  *active* organizations, right for assigning someone new but wrong for a filter, since
  someone already in a now-retired organization should still be findable. Same
  active-only-vs-all distinction `tickets.php` already makes for its own organization
  filter.
- **Sort** — Name (default, unchanged), Role (administrator → technician → employee,
  via a `CASE` rank, then name), Organization (alphabetical, no-organization accounts
  last), Newest first. All four are a plain whitelisted-key → SQL-expression array, the
  same shape `ticket_order_sql()` already uses in `app/tickets.php` — not pulled into a
  shared helper since, unlike tickets, this is the only page that needs it.

Result count line added above the table ("8 users" / "3 users match your filters"),
matching the wording `tickets.php` uses for the same thing.

**Verified against real data, not just the one role already in the room:** while testing,
found an 8th account (`John Smith`, Employee, organization TestCompany) already live in
the dev database that hadn't shown up in earlier screenshots — not something this change
added or touched, just confirms the filter/sort was checked against actual mixed data
(two organizations, two roles represented) rather than only the all-administrator roster
visible before.

| Check | Result |
|---|---|
| `phpcs` (`public/users.php`) | 0 errors, 0 warnings |
| Manual pass | Role=Administrator filter (7/8 correct); Organization=No organization filter (correctly excludes the TestCompany account, not just anyone missing an org visually); Sort=Organization (TestCompany before UWF IT HELPDESK TEAM, alphabetical; no-organization accounts last); Sort=Role (all 7 administrators grouped before the one employee); Clear link resets all three |

**Not built: an in-app chat feature.** Also raised by Noah, who immediately questioned
it himself (real teams mostly already use Teams/Slack for that). This app already has a
communication channel scoped to where it actually belongs — ticket conversation replies
(section on `ticket.php`'s reply "bubbles," added earlier in this doc) — a separate
general user-to-user chat would be a materially bigger, differently-scoped feature with
no spec yet (ticket-scoped? company-wide? DMs?). Worth a one-paragraph change request if
the team actually wants it, same process `docs/STATUS-UPDATE-NOAH.md` already describes
for anything touching "frozen" Tasks 5/6 — not something to guess the shape of and build
unasked.

## 14. Sign-in page, real photo this time

Section 12's stand-in (giant faint logo mark bleeding off the edge, CSS-only, because no
licensed photo existed for the page) got replaced outright — Jordan supplied an actual
banner, [`auth-hero.webp`](../public/assets/auth-hero.webp) (1672x941, custom artwork with
"RESOLVE / INFORMATION TECHNOLOGY" already baked into it), with the explicit ask to put it
behind the sign-in page. Unlike the Ashe theme in section 12, this one is Jordan's own
asset for this project, so it's used directly rather than taken as inspiration only.

**Two things had to change, not just drop in a background-image:**

1. **The live text wordmark had to go.** The old `.auth-brand-mark` block
   (icon + "ResolveIT" + "IT Service Desk" kicker, added in section 12) would have sat
   directly on top of the image's own baked-in "RESOLVE" title — two brand treatments
   stacked on each other. Removed the whole block from
   [`header.php`](../public/partials/header.php) and the now-orphaned
   `.auth-brand-mark`/`.auth-brand-text`/`.auth-wordmark`/`.auth-kicker` rules from
   `style.css` (same "delete dead CSS, don't leave it" rule section 8's reports-page
   cleanup already followed). The photo carries the branding on this page now; the page
   `<title>` ("Sign In · ResolveIT") still carries the accessible name.
2. **The card had to move.** First pass just swapped the background and left
   `.auth-center` centering its content — the card landed mid-image, directly over
   "RESOLVE" and a soldier's face, with title text peeking out on both sides. The photo
   itself has a deliberately empty, darkening band along the bottom (visible in the
   source image), clearly meant to hold UI. Changed `.auth-center` from
   `justify-content: center` to `flex-end` so the card anchors to that band instead, and
   extended the darkening scrim (a `linear-gradient` layered *over* the photo in the same
   `background` shorthand — gradients listed first in a multi-layer `background` paint on
   top) further up the page so the transition from photo to card stays legible instead of
   cutting the subtitle text off abruptly.

`auth-hero.webp` is a local file under `public/assets/`, loaded the same CSP-compliant
way every other asset on this page already is (no external request).

| Check | Result |
|---|---|
| `phpcs` (`header.php`) | 0 errors, 0 warnings |
| Manual pass | signed in end-to-end after the change; desktop (1440x900) — card sits over the dark band, no text directly behind it; mobile (375x812) — image crops to the mountains/title, card still fully legible; `document.documentElement.scrollWidth === clientWidth` checked directly on mobile (no horizontal overflow) |

## 15. Sign-in card: frosted tactical panel, airforce.com as a real reference

Jordan's follow-up on section 14's result: the opaque white card was still sitting
squarely over the banner's own title text (confirmed from a screenshot, not guessed), and
the ask was to either move it or redesign around it, plus make the page read more
military — named [airforce.com](https://www.airforce.com/) as the reference.

**Looked at the actual site instead of guessing "military" from stereotype.** Pulled
real computed styles off airforce.com rather than eyeballing it: base color
`rgb(14, 23, 45)` (a near-black navy), headline font-weight 700 uppercase (their own
licensed display faces, `Stratum`/`FFDINWeb` — not usable here, CSP + no custom webfonts
anywhere in this project), and button/panel corner radii of 0-2px across the board — a
hard, sharp-edged UI, not the soft rounded corners most SaaS sites use. That's a
specific, checkable set of signals, not a vibe.

**Didn't adopt their navy.** This app already has a deliberate, documented two-color
brand system (copper `--primary`, cyan `--accent` — section 3's color table, with
contrast ratios). Introducing a third brand hue for one page would undercut that rather
than extend it. Took the *shape* language instead — sharp corners, high-contrast dark
panel, uppercase tracked display text — and applied it with this app's own colors.

**What changed** (`style.css`, `.auth-main .card` / `.auth-card`):
- Card background: opaque white → `rgba(18, 20, 23, .74)` with `backdrop-filter:
  blur(16px)`. This is what actually answers "move it so we can see the image" — the
  photo (and the banner's own title) stays visible, softened, *through* the panel,
  instead of needing to find a spot on the image with nothing behind the card. Works at
  any scroll position or viewport size, where hand-tuning a fixed offset only works for
  one.
- Border radius `var(--radius-lg)` (15px) → `6px`. Border color/top accent: swapped the
  soft drop shadow look for a 1px hairline border plus the existing 2px copper top
  border (was 3px; thinned to read as instrumentation, not a stripe).
- Added two HUD-style corner brackets (`::before`/`::after`, two border sides each, top-left
  and bottom-right) in `--accent` (cyan) — the second brand color, previously only used
  for the sign-in glow and status lights, now doing double duty as instrument detailing.
- `h1` on this page only: uppercase, letter-spaced. Still the site-wide serif (section 3's
  "every heading shares one typeface" rule, unchanged) — reads like an engraved plaque
  rather than breaking from the established type system to chase the reference site's
  own (unavailable) display font.
- `.auth-main`'s text color flipped dark-on-light → white, since the panel itself is now
  dark. Didn't touch input styling — fields stay solid white, which is what already gave
  the strongest contrast against both old and new card backgrounds.

**One false alarm, not a real bug:** a mobile screenshot immediately after load appeared
to show the backdrop blur not applying (banner text fully sharp behind the glass). Checked
computed style before changing anything — `backdropFilter: "blur(16px)"`,
`CSS.supports('backdrop-filter', ...) === true` — confirmed correct and supported; a
second screenshot one beat later showed the blur rendering fine. Stale/mid-paint capture
while the pane isn't focused, the same class of issue noted elsewhere in this doc and in
`ENGINEERING-NOTES.md` — not a CSS defect, so nothing was "fixed."

| Check | Result |
|---|---|
| Manual pass | signed in end-to-end after the change; desktop (1440x900) — both corner brackets render, banner text visible-but-softened through the panel; mobile (375x812) — rechecked after the stale-screenshot false alarm, blur confirmed rendering; `document.documentElement.scrollWidth === clientWidth` on mobile (no horizontal overflow) |

Sources consulted: [airforce.com](https://www.airforce.com/), for real computed colors,
type weight/case, and corner-radius values (see above) — no assets, fonts, or copy taken
from it; this project's CSP and typography system rule those out regardless.

## 16. The whole app, not just sign-in: dark theme

Jordan's ask, from a Dashboard screenshot: match the rest of the app to the sign-in
page's new look — "just change the colors." The color-token part of that really is
simple (this app has run on one `:root` token system since the first redesign pass,
section 3) — the part that isn't simple is that roughly a dozen rules don't reference
those tokens for their *background*, because they were written assuming a permanently
light page. Flipping `--text` light without checking those would have made several
real things unreadable, not just looked different. Went through the whole stylesheet
once rather than the one screenshot's worth of surface.

**New token values** (`:root`), contrast-checked, not eyeballed:

| Token | Old | New | vs. | Ratio |
|---|---|---|---|---|
| `--bg` | `#eef0f3` | `#1b1d21` | — | — |
| `--surface` | `#ffffff` | `#232629` | — | — |
| `--surface-muted` | `#eceef1` | `#2a2d32` | — | — |
| `--border` | `#dde1e6` | `#30343a` | — | — |
| `--border-strong` | `#c1c7d0` | `#6c7380` | vs `--surface` | 3.19:1 (passes WCAG non-text 3:1) |
| `--text` | `#15181c` | `#f1f2f4` | vs `--bg` / `--surface` | 15.07:1 / 13.58:1 |
| `--text-muted` | `#636a75` | `#9aa1ab` | vs `--bg` / `--surface` / `--surface-muted` | 6.48:1 / 5.84:1 / 5.30:1 |

Two new tokens, not a replacement for anything: `--text-on-light` (`#15181c`, the *old*
`--text` value) and `--highlight` (`rgba(154, 52, 18, .18)`).

**Left alone, same reasoning as the original re-theme:** every semantic status/priority/
SLA color (`--success`, `--warning`, `--danger`, `--info`, `--violet` and their `-soft`
pairs), `--primary`, `--accent`, and the sidebar/top-nav tokens (already dark since the
first pass). Changing a working, correct traffic-light system risks confusing meanings
that already work — the same line from section 3, still true here.

**The actual bug class, and the two different fixes:**

1. **Components with a background that was never a token** — `input`/`select`/`textarea`,
   `.button.secondary`, `.chip` all hardcode `background: #fff` on purpose (clear, legible
   form fields and filter pills, independent of page theme — the sign-in page already
   established this exact pattern with its own white inputs on a dark glass card). Their
   *text* color, though, was `var(--text)` — fine when that token was dark, broken (white
   text on their own white background) once it flipped light. Fix: new `--text-on-light`
   token, swapped in wherever a component's background stays light on purpose. Same fix
   for the secondary-button loading spinner's `border-top-color`, same bug, easy to miss
   since it only shows while a form is submitting.
2. **Full-block highlights that used `--primary-soft` as a background** — row hover,
   row/selected state, the sorted-column header, staff reply bubbles, the bulk-action bar's
   "selected" state. Different root cause: these don't have their own fixed background,
   they sit on the *same* `--primary-soft` light-pastel token everywhere else in the app
   (icon chips, badges) already uses correctly — correctly, because those spots color
   their icon/text explicitly. These didn't; their text (ticket titles, requester names,
   comment text) inherits the page's text color, which is exactly what should follow
   the theme. Recoloring each nested element individually (ticket title, ticket id,
   requester name, meta text, due date…) would be fragile — easy to miss one, and a
   prior pass already noted a similar one-off (`-moz` style) gets forgotten. Fix:
   `--highlight`, a translucent copper wash instead of an opaque light pastel. The text
   underneath stays whatever color it already correctly is; the wash is just visible
   *through* it, which also means it keeps working regardless of what's nested inside,
   not just the elements checked today. `.resolution-box` had the same shape of problem
   with `--success-soft`; same fix, inline (one spot, didn't template it as a third
   token).
3. **The topbar** (search/avatar/sign-out row) was still hardcoded
   `rgba(255, 255, 255, .92)` — a bright bar sitting directly above the already-dark
   `.top-nav`. Changed to a dark frosted equivalent (`rgba(18, 20, 23, .85)`, same
   `backdrop-filter: blur(8px)` as before) so the two nav rows read as one shell instead
   of light-then-dark. Its focus-within search state also hardcoded `background: #fff`;
   changed to `var(--surface)` so focusing the search box doesn't flash back to light
   mode for one element.

**What this deliberately did not touch:** `--shadow`/`--shadow-lg` (tuned for a light
page; barely visible now, but not broken — cards still separate from the page via the
surface/bg contrast and a 1px border) and print styles (`@media print` was never
theme-aware either way; dark-on-dark printing is a pre-existing, unrelated gap).

| Check | Result |
|---|---|
| Manual pass | Dashboard, Tickets (quick-filter chips, row hover — the highest-risk spot, confirmed text stays legible over the new `--highlight` wash, not just the badges which were never at risk), Ticket detail (resolution box, staff vs. requester comment bubbles), Reports (date inputs, the secondary "Export" button — would have been invisible text without the `--text-on-light` fix, caught before shipping by checking this page specifically, not assumed from the dashboard alone) — all checked signed in, in-browser |
| Signed in end-to-end | after the change, to confirm nothing in the auth flow itself regressed |

Sources: none external this time — this one was entirely about the existing token
system's own coverage, not a new design reference.

## 17. Uppercase, tracked headings everywhere

Jordan pointed at the sign-in page's "SIGN IN" treatment (section 15's `.auth-main h1`
override: uppercase, `.03em` letter-spacing) and asked for it on every page, not just
that one. Moved it from a one-page override into the shared rule every heading already
uses: `h1, h2, h3 { ... font-family: var(--font-serif); }` (section 3's "one consistent
text style across heading levels" rule) now also carries `text-transform: uppercase;
letter-spacing: .03em;`. `.auth-main h1` keeps only its font-size override (1.55rem,
sized for the narrow sign-in card); the uppercase/tracking part is no longer duplicated
now that the shared rule covers it.

Table headers, stat labels, and nav links were already uppercase on their own (separate,
older rules — `th`, `.stat .meta`, `.top-nav a`) before this change; this just brings
page titles and card headers (`<h1>`, `<h2>` — "Welcome back", "Manage Users", card
headers like "Create user") in line with what was already true everywhere else, so it
now reads as one system instead of titles being the one lower-case holdout.

| Check | Result |
|---|---|
| Manual pass | Dashboard, Knowledge Base, Manage Users — desktop and mobile (375px). Checked specifically for wrapping on the longer titles/card headers at mobile width; none found. Signed in throughout. |
