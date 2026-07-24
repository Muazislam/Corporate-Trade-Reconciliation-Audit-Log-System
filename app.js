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

  return session;
}

function toast(message, type) {
  const el = document.createElement('div');
  el.className = 'toast' + (type === 'error' ? ' error' : '');
  el.textContent = message;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3200);
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
