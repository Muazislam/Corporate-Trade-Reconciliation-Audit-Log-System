/* ============================================================
   app.js — Shared helpers used across every authenticated page.
   New additions (Part B):
     · toast()          — now stacks vertically, no overlap
     · makeSortable()   — reusable column-sort for any table
     · makePaginated()  — reusable Prev/Next pagination
   Store.* functions are untouched.
   ============================================================ */

/* ── Shell init ────────────────────────────────────────────── */
function initShell(activePage) {
  const session = Store.requireAuth();
  if (!session) return null;

  document.querySelectorAll('.nav a').forEach(a => {
    if (a.dataset.page === activePage) a.classList.add('active');
  });

  const nameEl = document.getElementById('sessionName');
  const roleEl = document.getElementById('sessionRole');
  const chipEl = document.getElementById('sessionChip');
  if (nameEl) nameEl.textContent = session.name;
  if (roleEl) roleEl.textContent = session.role;
  if (chipEl) chipEl.textContent = session.name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();

  const logoutBtn = document.getElementById('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', e => {
      e.preventDefault();
      Store.logout();
      window.location.href = 'index.html';
    });
  }

  return session;
}

/* ── Toast — stacking (Part B.9) ───────────────────────────── */
// Track live toasts so each new one is offset above the previous.
const _activeToasts = [];
const TOAST_HEIGHT  = 52;   // px per toast slot (height + gap)
const TOAST_BASE    = 22;   // px from bottom for the first toast

function _repositionToasts() {
  _activeToasts.forEach((el, idx) => {
    el.style.bottom = (TOAST_BASE + idx * TOAST_HEIGHT) + 'px';
  });
}

function toast(message, type) {
  const el = document.createElement('div');
  el.className = 'toast' + (type === 'error' ? ' error' : '');
  el.textContent = message;

  _activeToasts.unshift(el);          // new toast at index 0 (bottom)
  _repositionToasts();

  document.body.appendChild(el);

  setTimeout(() => {
    const idx = _activeToasts.indexOf(el);
    if (idx !== -1) _activeToasts.splice(idx, 1);
    el.remove();
    _repositionToasts();              // shift remaining toasts down
  }, 3200);
}

/* ── HTML escape ────────────────────────────────────────────── */
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}

/* ── Date format ────────────────────────────────────────────── */
function formatDateTime(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleDateString(undefined, { month: 'short', day: '2-digit', year: 'numeric' }) +
    ' · ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

/* ── Pill helpers ───────────────────────────────────────────── */
function pillForExceptionType(type) {
  if (type.startsWith('MISSING')) return `<span class="pill pill-missing">${escapeHtml(type.replaceAll('_',' '))}</span>`;
  if (type === 'AMOUNT_MISMATCH' || type === 'QTY_MISMATCH') return `<span class="pill pill-mismatch">${escapeHtml(type.replaceAll('_',' '))}</span>`;
  return `<span class="pill pill-pending">${escapeHtml(type)}</span>`;
}

function pillForStatus(status) {
  const map = {
    OPEN: 'pill-open', RESOLVED: 'pill-resolved', IGNORED: 'pill-ignored',
    MATCHED: 'pill-matched', PENDING: 'pill-pending'
  };
  const cls = map[status] || 'pill-pending';
  return `<span class="pill ${cls}">${escapeHtml(status)}</span>`;
}

/* ── makeSortable — reusable column sort (Part B.5) ─────────
 *
 *  @param {HTMLTableElement} tableEl   - The <table> element.
 *  @param {Array}            dataArr   - Live reference; gets sorted in-place.
 *  @param {Function}         renderFn  - Called with the sorted array to
 *                                        re-render tbody rows.
 *  @param {Array<string>}    colKeys   - Field name for each <th> column,
 *                                        in the same order as the headers.
 *                                        Pass null for unsortable columns.
 *
 *  Usage:
 *    makeSortable(tableEl, trades, renderRows, ['external_trade_id','source_system','symbol','side','quantity','price','trade_date','status']);
 * ─────────────────────────────────────────────────────────── */
function makeSortable(tableEl, dataArr, renderFn, colKeys) {
  const headers = tableEl.querySelectorAll('thead th');
  let sortCol   = null;
  let sortAsc   = true;

  headers.forEach((th, idx) => {
    const key = colKeys[idx];
    if (!key) return;

    th.classList.add('sortable');
    th.addEventListener('click', () => {
      if (sortCol === key) {
        sortAsc = !sortAsc;
      } else {
        sortCol = key;
        sortAsc = true;
      }

      // Clear all th sort classes
      headers.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
      th.classList.add(sortAsc ? 'sort-asc' : 'sort-desc');

      dataArr.sort((a, b) => {
        let va = a[key], vb = b[key];
        // Numeric comparison
        if (!isNaN(Number(va)) && !isNaN(Number(vb))) {
          va = Number(va); vb = Number(vb);
        } else {
          va = String(va ?? '').toLowerCase();
          vb = String(vb ?? '').toLowerCase();
        }
        if (va < vb) return sortAsc ? -1 : 1;
        if (va > vb) return sortAsc ?  1 : -1;
        return 0;
      });

      renderFn(dataArr);
    });
  });
}

/* ── makePaginated — reusable pagination (Part B.6) ─────────
 *
 *  @param {Object} opts
 *    .container  {HTMLElement}  - Element that holds tbody + pagination.
 *    .getData    {Function}     - Returns the current (possibly filtered)
 *                                 data array each time a page renders.
 *    .renderRows {Function}     - Accepts (pageSlice) and renders tbody.
 *    .pageSize   {number}       - Rows per page (default 10).
 *
 *  Returns: { refresh() } — call after filter changes to reset to page 1.
 * ─────────────────────────────────────────────────────────── */
function makePaginated({ container, getData, renderRows, pageSize = 10 }) {
  let currentPage = 1;

  // Build pagination bar once
  const bar = document.createElement('div');
  bar.className = 'pagination';
  bar.innerHTML = `
    <button class="btn btn-sm btn-ghost" data-pg="prev">← Prev</button>
    <span class="page-info" data-pg="info"></span>
    <button class="btn btn-sm btn-ghost" data-pg="next">Next →</button>
  `;
  container.appendChild(bar);

  const prevBtn  = bar.querySelector('[data-pg="prev"]');
  const nextBtn  = bar.querySelector('[data-pg="next"]');
  const infoSpan = bar.querySelector('[data-pg="info"]');

  function render() {
    const data      = getData();
    const totalPages = Math.max(1, Math.ceil(data.length / pageSize));
    currentPage     = Math.min(currentPage, totalPages);
    const start     = (currentPage - 1) * pageSize;
    const slice     = data.slice(start, start + pageSize);

    renderRows(slice);

    infoSpan.textContent = `Page ${currentPage} of ${totalPages}`;
    prevBtn.disabled = currentPage <= 1;
    nextBtn.disabled = currentPage >= totalPages;
  }

  prevBtn.addEventListener('click', () => { currentPage--; render(); });
  nextBtn.addEventListener('click', () => { currentPage++; render(); });

  render(); // initial render

  return {
    refresh() { currentPage = 1; render(); }
  };
}
