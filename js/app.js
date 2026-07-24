/* Shared helpers used across every authenticated page. */

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
    logoutBtn.addEventListener('click', (e) => {
      e.preventDefault();
      Store.logout();
      window.location.href = 'index.html';
    });
  }

  initThemeToggle();
  return session;
}

/* ---------- Theme toggle ---------- */
function initThemeToggle() {
  const toggle = document.getElementById('themeToggle');
  if (!toggle) return;
  toggle.addEventListener('click', () => {
    const html = document.documentElement;
    const next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    html.setAttribute('data-theme', next);
    localStorage.setItem('trc_theme', next);
  });
}

const _toasts = [];
const TOAST_GAP = 60;
const TOAST_BASE = 24;

function _repositionToasts() {
  _toasts.forEach((el, i) => { el.style.bottom = (TOAST_BASE + i * TOAST_GAP) + 'px'; });
}

function toast(message, type) {
  const el = document.createElement('div');
  el.className = 'toast' + (type === 'error' ? ' error' : '');
  el.textContent = message;
  el.style.bottom = '0px';
  document.body.appendChild(el);
  _toasts.push(el);
  _repositionToasts();

  setTimeout(() => {
    el.style.opacity = '0';
    setTimeout(() => {
      const idx = _toasts.indexOf(el);
      if (idx > -1) _toasts.splice(idx, 1);
      el.remove();
      _repositionToasts();
    }, 250);
  }, 3200);
}

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}

function formatDateTime(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleDateString(undefined, { month: 'short', day: '2-digit', year: 'numeric' }) +
    ' · ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}

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

function makeSortable(tableEl, sortState, renderFn) {
  const thead = tableEl.querySelector('thead');
  if (!thead) return;
  thead.addEventListener('click', (e) => {
    const th = e.target.closest('th[data-sort]');
    if (!th) return;
    const col = th.dataset.sort;
    if (sortState.col === col) sortState.asc = !sortState.asc;
    else { sortState.col = col; sortState.asc = true; }
    thead.querySelectorAll('th[data-sort]').forEach(t => {
      t.classList.toggle('sort-asc', t.dataset.sort === sortState.col && sortState.asc);
      t.classList.toggle('sort-desc', t.dataset.sort === sortState.col && !sortState.asc);
    });
    renderFn();
  });
}

function makePaginated(containerEl, pageSize, renderPageFn) {
  let page = 1;
  function render(total) {
    const totalPages = Math.max(1, Math.ceil(total / pageSize));
    if (page > totalPages) page = totalPages;
    containerEl.innerHTML = `
      <div class="pagination" style="display:flex;align-items:center;gap:10px;margin-top:12px;">
        <button class="btn btn-sm" data-action="prev" ${page <= 1 ? 'disabled' : ''}>← Prev</button>
        <span class="page-info" style="font-size:12px;color:var(--text-dim);font-family:var(--font-mono);">Page ${page} of ${totalPages}</span>
        <button class="btn btn-sm" data-action="next" ${page >= totalPages ? 'disabled' : ''}>Next →</button>
      </div>`;
    containerEl.querySelector('[data-action="prev"]')?.addEventListener('click', () => { page--; renderPageFn(); });
    containerEl.querySelector('[data-action="next"]')?.addEventListener('click', () => { page++; renderPageFn(); });
  }
  return { render, getPage: () => page, reset: () => { page = 1; } };
}
