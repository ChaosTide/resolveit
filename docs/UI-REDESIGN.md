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
