# ResolveIT — UI Redesign: Logo, Sign-In Page, Color System

Second design pass on the front end. The first pass (teal/warm-paper palette,
split-screen sign-in layout) replaced the original generic blue SaaS look but
still read as too soft/generic for an IT help desk. This pass replaces the
logo, removes boilerplate copy, and re-themes the whole app around the thing
the tool is actually about: network infrastructure.

## 1. Logo

The brand mark was `icon('tickets')` — a ticket-stub glyph (rounded
rectangle with two notched edges and a perforation line) reused from the
ticket-list UI. It is still used there; it just isn't a logo.

Added a dedicated `icon('network')` to the icon set in
[`app/ui.php`](../app/ui.php) — a short cable running into a connector body
with three contact prongs, drawn in the same 24x24 stroke style as every
other icon in the set (no icon library; every glyph in this app is a
hand-written SVG path). First attempt drew the retention clip as a rounded
tab, which at 20-24px read as a camera lens, not a connector — simplified to
a plain plug-with-prongs silhouette, which is unambiguous at icon size:

```
'network' => cable stub + rounded-rect body + 3 horizontal prongs
    exiting the body's right edge, no latch detail (cut for legibility
    at small sizes)
```

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
| [app/ui.php](../app/ui.php) | Added `network`, `mail`, `lock`, `arrow-right` icons |
| [public/login.php](../public/login.php) | Removed tagline; added field icons and button arrow |
| [public/partials/header.php](../public/partials/header.php) | Logo now uses `icon('network')` |
| [public/assets/style.css](../public/assets/style.css) | Full color re-theme, tightened radii, `.field-icon` rules, button gradient, `.auth-card` accent border |
