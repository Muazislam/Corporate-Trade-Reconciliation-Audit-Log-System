# Architecture · Corporate Trade Reconciliation & Audit Log System

## Overview

This is a **client-side-only prototype** (HTML/CSS/JS, localStorage-backed) that simulates a trade reconciliation and audit system. All data is seeded on first load via `js/store.js` and persists in `localStorage`. The project is structured as 6 HTML pages sharing a common CSS file (`css/style.css`) and two JS modules (`js/app.js` for UI helpers, `js/store.js` for data/state).

Every authenticated page follows the same shell pattern: `.app-shell` grid (sidebar + main), with `initShell(pageName)` in `app.js` marking the active nav link, showing the user session, and wiring the sign-out button.

## File inventory

| File | Role |
|---|---|
| `index.html` | Login page (no sidebar, uses `.login-shell`) |
| `dashboard.html` | Overview with stat cards, match-rate sparkline, recent runs table, recent audit table |
| `trades.html` | Trade record table with add-trade modal, filtering, sortable columns, pagination |
| `reconciliation.html` | Run reconciliation form, result card, run history table, custom confirm modal |
| `exceptions.html` | Exception list with filters, resolve modal, pagination |
| `audit-log.html` | Audit log table with action/actor filters, chain-verify button, sortable columns, pagination |
| `css/style.css` | All styles — design tokens, layout, components, responsive breakpoints |
| `js/app.js` | Shared UI helpers: `initShell`, `toast`, `escapeHtml`, `formatDateTime`, `pillForExceptionType`, `pillForStatus`, `makeSortable`, `makePaginated` |
| `js/store.js` | Client-side data layer (localStorage) — `Store` object with seed data, auth, CRUD for trades, reconciliation engine, exception management, append-only audit log with hash chain |

---

## Feature 1: Custom confirm modal (replaces `confirm()`)

**Files:** `reconciliation.html`, `css/style.css`

**What it does:** When a reconciliation run raises exceptions, a styled modal (using the existing `.modal` / `.modal-backdrop` CSS classes from `style.css`) asks the user whether to navigate to the exceptions page. This replaces the native `confirm()` dialog.

**Why:** Native `confirm()` blocks the UI and cannot be styled. The existing modal classes were already defined in `style.css` (used by the add-trade and resolve-exception modals), so the confirm modal uses the same visual language.

**DOM elements:**
- `#confirmModal` — `.modal-backdrop` container; added to `reconciliation.html` just before the `</body>`
- `#confirmMsg` — `.modal-sub` paragraph that receives the exception count text
- `#confirmYes` — button that redirects to `exceptions.html`
- `#confirmNo` — button that closes the modal
- The backdrop itself has a click handler: clicking outside the modal closes it

**Dependencies:** Uses `.modal`, `.modal-backdrop`, `.modal-actions`, `.btn`, `.btn-primary`, `.btn-ghost` from `style.css`. No JS dependency beyond the existing `app.js` script loaded on the page.

**Edge cases:** If no exceptions are raised, the modal is never shown. The modal is hidden by default via the `.hidden` class.

---

## Feature 2: Inline SVG favicon

**Files:** All 6 HTML files (`<head>` section)

**What it does:** Adds a data-URI inline SVG favicon showing "LC" in `--accent` teal on the dark navy background (`#0A0F1C`), matching the brand mark from the sidebar.

**Why:** No external favicon file to deploy. The favicon matches the existing brand identity (brand-mark "LC" in the sidebar) and uses the same design-token colors.

**Dependencies:** None — purely a `<link rel="icon">` element with a `data:image/svg+xml` URI. Works in all modern browsers.

---

## Feature 3: Left accent bar on active nav link

**Files:** `css/style.css`

**What it does:** Adds a 3px-wide teal (`var(--accent)`) vertical bar to the left side of the currently active sidebar nav link, using `::before`. The bar has rounded top-right/bottom-right corners. Works alongside the existing background highlight and text color change.

**Why:** Provides a persistent visual indicator of which page is active, even when the sidebar is collapsed to icon-only mode (Feature 6). No HTML changes needed.

**CSS classes:** `.nav a.active` gets `position:relative` and a `::before` pseudo-element. No new classes introduced.

**Dependencies:** Relies on `data-page` attributes on nav links and `initShell()` in `app.js` which applies `.active`. Every sidebar HTML page benefits automatically.

---

## Feature 4: Sortable columns

**Files:** `js/app.js`, `trades.html`, `audit-log.html`

**What it does:** Clicking a column header in the trades or audit-log table toggles ascending/descending sort on that column. A ▲ or ▼ indicator appears next to the sorted header. Only columns with `data-sort="propertyName"` on the `<th>` are sortable.

**Helper function:** `makeSortable(tableEl, sortState, renderFn)` in `js/app.js:70`
- `tableEl` — the `<table>` element (must have an id for lookup)
- `sortState` — a mutable object `{ col: null, asc: true }` that the page's render function reads to apply sorting
- `renderFn` — called after each sort toggle to re-render the table body

**Usage per page:**
```js
const sortState = { col: null, asc: true };
function render() {
  let data = getData();
  if (sortState.col) {
    data = data.slice().sort((a, b) => { /* compare a[sortState.col], b[sortState.col] */ });
  }
  // render ...
}
makeSortable(document.getElementById('myTable'), sortState, render);
```

**CSS classes:** `.sort-arrow` (span inside `<th>`) shows ↕ by default, `.sort-asc .sort-arrow` shows ▲, `.sort-desc .sort-arrow` shows ▼. Defined in `css/style.css`.

**Pages that use it:**
- `trades.html` — table `#tradesTable`, columns: external_trade_id, source_system, symbol, side, quantity, price, trade_date, status
- `audit-log.html` — table `#auditTable`, columns: timestamp, actor, action, entity_type, details (hash is not sortable)

**Edge cases:** No sort is applied when `sortState.col` is null (default rendering order is preserved). If two rows have equal values, their relative order depends on the browser's sort implementation (unstable sort).

---

## Feature 5: Pagination

**Files:** `js/app.js`, `trades.html`, `exceptions.html`, `audit-log.html`

**What it does:** Shows 10 rows per page with "← Prev" and "Next →" buttons plus a "Page X of Y" label. Filters reset pagination to page 1. Uses existing `.btn` and `.btn-sm` classes for consistent styling.

**Helper function:** `makePaginated(containerEl, pageSize, renderFn)` in `js/app.js:84`
- `containerEl` — element where the pagination controls will be rendered (e.g. `<div id="tradesPagination">`)
- `pageSize` — rows per page (10 for all current usages)
- `renderFn` — the page's render function, called after page changes

**Returns** an object with:
- `render(total)` — renders the pagination controls for the given total number of items
- `getPage()` — returns the current page number (1-indexed)
- `reset()` — resets to page 1 (call when filters change)

**Usage pattern per page:**
```js
const paginator = makePaginated(document.getElementById('paginationContainer'), 10, render);
function render() {
  let data = getFilteredData();
  const page = paginator.getPage();
  const pageSize = 10;
  const totalPages = Math.max(1, Math.ceil(data.length / pageSize));
  const start = (Math.min(page, totalPages) - 1) * pageSize;
  const pageItems = data.slice(start, start + pageSize);
  // render pageItems to table body...
  paginator.render(data.length);
}
// On filter change:
filterEl.addEventListener('change', () => { paginator.reset(); render(); });
```

**Pages that use it:**
- `trades.html` — container `#tradesPagination`
- `exceptions.html` — container `#excPagination`
- `audit-log.html` — container `#logPagination`

**Edge cases:** Page 1 of 0 total renders no table rows but shows "Page 1 of 1". Prev button disabled on page 1, Next disabled on last page. Resetting to page 1 when filters change prevents showing an empty page.

---

## Feature 6: Collapsible sidebar (below 900px)

**Files:** `css/style.css`, all 5 sidebar pages (dashboard, trades, reconciliation, exceptions, audit-log)

**What it does:** At viewport widths ≤ 900px, the sidebar collapses from 232px to ~64px (icon-only). Nav link text and labels are hidden; only the icon glyph remains visible. Hovering over an icon shows a tooltip with the page name via `data-label` attribute.

**CSS:** A `@media (max-width:900px)` block in `style.css` adjusts `.app-shell` grid, `.sidebar` padding, hides `.brand-text`, `.nav-label`, and text spans, centers nav icons, and adds a `::after` tooltip on hover.

**Data attributes:** Each `<a>` in the sidebar nav now includes `data-label="Page Name"` (e.g. `data-label="Dashboard"`). The CSS `content:attr(data-label)` reads this for the hover tooltip.

**Footer adjustments:** The `.sidebar-foot` switches to a column layout, hides the name/role, and transforms the sign-out link to a power-off icon (⏻).

**Dependencies:** Works with existing `.app-shell` grid layout. No JS changes needed — `initShell()` still adds `.active` correctly.

**Edge cases:** Does not break index.html (login page has no sidebar). Stat-grid also has a 900px breakpoint (2-column layout) which already existed and is unaffected.

---

## Feature 7: Dashboard sparkline (match rate trend)

**Files:** `dashboard.html`, `css/style.css`

**What it does:** Shows the last 5 reconciliation runs as vertical bars in a `.card` on the dashboard. Each bar's height represents the match rate percentage (0-100). Bars are teal (`--accent`) when match rate ≥ 90%, amber (`--warn`) otherwise. Below each bar are the percentage and the run date.

**CSS classes (new):**
- `.sparkline` — flex container for the bars
- `.spark-bar` — each individual bar column (flex column, centered)
- `.spark-bar-fill` — the actual colored bar (border-radius top, min-height:4px)
- `.spark-bar-fill.good` — teal fill
- `.spark-bar-fill.warn` — amber fill
- `.spark-label` — percentage text below bar
- `.spark-date` — date text below label

**Data source:** `Store.getRuns()` (from `store.js`) — the same data used to populate the "Recent reconciliation runs" table. Match rate is computed as `matched / (matched + exceptions) * 100`.

**DOM elements:** The card is placed between the stat-grid and the runs table. `#sparklineWrap` is populated by an inline script in `dashboard.html`.

**Edge cases:** If there are 0 runs, shows the empty state glyph and message. If there are 1-4 runs, only those are shown. The fill uses `min-height:4px` so even a 0% rate is visible as a tiny bar.

---

## Feature 8: Stacking toast notifications

**Files:** `js/app.js`, `css/style.css`

**What it does:** When multiple toasts appear in quick succession, they stack vertically instead of overlapping. Each new toast is positioned 60px above the previous one (bottom offset increases). When a toast is dismissed (auto-close after 3.2s), remaining toasts smoothly slide down to fill the gap.

**Implementation:**
- `_toasts` array tracks active toast DOM elements
- `_repositionToasts()` iterates the array and sets each toast's `bottom` style: `24 + index * 60` pixels
- On creation: push to array, reposition (triggering CSS transition on `bottom`)
- On removal: set `opacity: 0`, wait 250ms for fade-out, remove from DOM and array, reposition remaining toasts

**CSS changes:** `.toast` removed the hardcoded `bottom:24px` (now set via inline JS style). Added `transition: bottom .25s ease, opacity .25s ease` for smooth animation.

**Constants in `app.js`:**
- `TOAST_GAP = 60` — vertical offset between stacked toasts
- `TOAST_BASE = 24` — bottom offset of the first (bottom-most) toast

**Edge cases:** Toasts wrap at `max-width:320px`. If the viewport is very short, stacking too many toasts could push them off-screen (no upper limit is enforced). The opacity transition prevents visual popping when removing.

---

## Cross-file dependency graph

```
index.html ──> js/store.js
                 │
                 ├── dashboard.html ──> css/style.css, js/app.js (initShell, toast, formatDateTime, escapeHtml)
                 ├── trades.html ────> css/style.css, js/app.js (initShell, toast, escapeHtml, pillForStatus, makeSortable, makePaginated)
                 ├── reconciliation.html ──> css/style.css, js/app.js (initShell, toast, formatDateTime, escapeHtml)
                 ├── exceptions.html ──> css/style.css, js/app.js (initShell, toast, escapeHtml, pillForExceptionType, pillForStatus, makePaginated)
                 └── audit-log.html ──> css/style.css, js/app.js (initShell, toast, escapeHtml, formatDateTime, makeSortable, makePaginated)
```

All authenticated pages call `initShell(pageName)` on load, which calls `Store.requireAuth()` automatically. Any page can call `Store.getSession()`, `Store.logout()`, etc.

---

## Theming System

**Files:** `css/style.css` (all theme variables & font imports), `js/app.js` (`initThemeToggle`), all 6 HTML files (`<head>` inline script + toggle button), `ARCHITECTURE.md` (this section).

### What it does

Provides a light mode / dark mode toggle that switches every theme-dependent neutral color on the page instantly. The toggle is a pill-shaped switch with a sliding thumb and inline SVG sun/moon icons, located in the sidebar footer (`#themeToggle`). The chosen theme persists in `localStorage` under `trc_theme` and survives page navigation.

### Palette philosophy

Neither theme uses pure black or pure white — dark mode uses a soft charcoal (`#15181D`) rather than `#000000`, and light mode uses a warm linen (`#F6F5F2`) rather than `#FFFFFF`. This reduces perceived glare and eye strain during extended use. The accent color is a desaturated sage teal (`#6FB7A8` dark / `#4F8C7D` light) that provides comfortable contrast without the harshness of a high-saturation primary. A secondary accent (`--accent-secondary`, a muted periwinkle/indigo) is available for charts, data series, and secondary highlights.

### Color system architecture

All theme-dependent colors are CSS custom properties on `:root` (default dark mode), overridden under `[data-theme="light"]`. The **semantic status colors** (`--accent`, `--warn`, `--danger`, `--info` and their `-dim` variants) are *switchable per theme* for their `-dim` backgrounds only — the core hue (`--accent`, `--warn`, etc.) is defined in each theme block because `--accent` also serves as the primary interactive color and must contrast appropriately with both dark and light surfaces. The `-dim` backgrounds are adjusted per theme so pills look harmonious in both modes while retaining their semantic meaning.

**Palette reference:**

| Role | `:root` (dark) | `[data-theme="light"]` |
|---|---|---|
| Main bg (`--bg`) | `#15181D` Soft Charcoal | `#F6F5F2` Warm Linen |
| Card/sidebar bg (`--surface`) | `#1D2127` Slate Panel | `#FFFFFF` Paper White |
| Elevated surface (`--surface-2`) | `#262B33` Slate Raised | `#EFEDE8` Linen Shade |
| Secondary surface (`--surface-3`) | `#323841` Graphite Line | `#DEDAD2` Soft Taupe |
| Border (`--border`) | `#323841` Graphite Line | `#DEDAD2` Soft Taupe |
| Primary text (`--text`) | `#E4E6EA` Warm Fog | `#2A2D31` Charcoal Ink |
| Secondary text (`--text-dim`) | `#9BA3AF` Muted Steel | `#5B6067` Graphite |
| Muted text (`--text-faint`) | `#656E7A` Dim Steel | `#8A8F96` Muted Stone |
| Interactive accent (`--accent`) | `#6FB7A8` Sage Teal | `#4F8C7D` Forest Teal |
| Accent dim (pill bg) | `#2A5045` | `#CEE5DF` |
| Secondary accent (`--accent-secondary`) | `#7C93C7` Dusk Periwinkle | `#5D74A6` Muted Indigo |
| Warn (core) | `#F0A93A` | `#F0A93A` |
| Warn dim (pill bg) | `#6B4E1D` | `#F0DDB8` |
| Danger (core) | `#E5484D` | `#E5484D` |
| Danger dim (pill bg) | `#5E2224` | `#F5D0D0` |
| Info (core) | `#5B8DEF` | `#5B8DEF` |
| Primary btn bg | `--accent` (#6FB7A8) | `--accent` (#4F8C7D) |
| Primary btn text | `#0F1E1B` | `#1A2E28` |
| Brand mark gradient | Sage Teal → Dusk Periwinkle | Forest Teal → Muted Indigo |

### Font stack

Three font families are loaded via Google Fonts at the top of `style.css`:

| Font | Weights | Usage |
|---|---|---|
| **Inter** | 400, 500, 600, 700 | All body text, labels, buttons, form inputs, and general UI chrome (`--font-ui`) |
| **Manrope** | 600, 700, 800 | Page titles (`.page-title`), section headings (`.section-title`), the sidebar brand name (`.brand-text strong`), stat-card numbers (`.stat-value`), login title (`.login-title`), modal headings (`h3`). Gives headings a warmer, slightly rounded, human feel (`--font-heading`). |
| **IBM Plex Mono** | 400, 500, 600 | Tabular/data values — trade IDs, quantities, prices, hashes, timestamps, code-like labels, status pills. Fixed-width alignment is functionally important here (`--font-mono`). Unchanged from the original design. |

### Derived theme-aware variables

- `--btn-primary-bg` / `--btn-primary-color` / `--btn-primary-hover` — used by `.btn-primary`; hover is a slightly darker variant of the theme's accent
- `--brand-mark-bg` / `--brand-mark-color` — used by `.brand-mark`; gradient uses `--accent` + `--accent-secondary`
- `--accent-bar` — used by `.nav a.active::before` and `.nav a.active` text color
- `--modal-backdrop` — semi-transparent overlay (dark in dark mode, lighter in light mode)
- `--toast-shadow` — deeper shadow in dark mode, subtler in light mode
- `--row-hover` — subtle highlight on table row hover

### How to add a new themed color

1. Define the dark-mode value in the `:root` block as `--custom-name`.
2. Add the light-mode override in the `[data-theme="light"]` block.
3. Reference `var(--custom-name)` in the relevant CSS selector(s).
4. Never hardcode a theme-dependent hex — always go through a variable so the toggle switches instantly.
5. For semantic status colors (warn, danger, info), keep the core hue the same in both themes; only the `-dim` background variant should differ per theme for visual harmony.

### Toggle implementation

**`initThemeToggle()`** in `js/app.js`:
- Called automatically from `initShell()` so all authenticated pages get it.
- Listens for clicks on `#themeToggle` (a `<button>` in `.sidebar-foot`).
- On click, reads the current `data-theme` attribute from `<html>`, flips it, saves to `localStorage.setItem('trc_theme', next)`.

**Flash-free initialization** (all 6 HTML files):
- An inline `<script>` at the end of `<head>` runs synchronously *before* any page content renders.
- Reads `localStorage.getItem('trc_theme')`; if absent, falls back to `window.matchMedia('(prefers-color-scheme: dark)').matches`.
- Sets `document.documentElement.setAttribute('data-theme', ...)` immediately — no flash of the wrong theme.

### Toggle switch design

The toggle is a `<button>` with `aria-label="Toggle theme"`. Inside it:
- `.theme-toggle-track` — 38×20px pill track
- `.theme-toggle-thumb` — 14×14px circular thumb that slides 18px right in light mode
- Moon SVG (left) and sun SVG (right); moon is highlighted in dark mode, sun in light mode, via their `color` cascading from the theme variables

At viewport widths ≤ 900px (collapsed sidebar), the track shrinks to 32×18px and the thumb to 12×12px.

### CSS selectors that use theme variables (instead of hardcoded values)

| Selector | What changed |
|---|---|
| `.btn-primary` | `background:var(--btn-primary-bg); color:var(--btn-primary-color)` |
| `.btn-primary:hover` | `background:var(--btn-primary-hover)` |
| `tbody tr:hover` | `background:var(--row-hover)` |
| `.modal-backdrop` | `background:var(--modal-backdrop)` |
| `.toast` | `box-shadow:var(--toast-shadow)` |
| `.nav a.active` / `::before` | `color` / `background` use `--accent-bar` |
| `.brand-mark` | `background:var(--brand-mark-bg); color:var(--brand-mark-color)` |
| `:focus-visible` | `box-shadow: ... var(--accent)` |

### Edge cases

- **Login page (`index.html`):** No sidebar/toggle button, but the flash-free `<head>` script still applies the saved/OS-preferred theme so it's correct when the user lands on the dashboard.
- **No saved preference:** Falls back to `prefers-color-scheme`; if neither is set, defaults to dark mode.
- **Collapsed sidebar (≤900px):** The toggle shrinks and remains visible in the sidebar footer column.
- **Pill contrast:** The status pill colors (`--accent`: Sage Teal / Forest Teal, `--warn`: Amber, `--danger`: Red, `--info`: Blue) maintain ≥4.5:1 contrast against their own `-dim` backgrounds in both themes, because each pill has a self-contained background and foreground — the page surface color never affects pill readability.
- **Body text contrast:** `--text` on `--bg` in dark mode (#E4E6EA on #15181D) exceeds 12:1; in light mode (#2A2D31 on #F6F5F2) exceeds 9:1 — well above WCAG AA minimums, but not harsh (deliberately avoiding 21:1 ratios that cause eye strain).
