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
