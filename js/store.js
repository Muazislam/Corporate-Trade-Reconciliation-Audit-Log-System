/* ============================================================
   store.js
   ------------------------------------------------------------
   Real API Data Layer replacing client-side localStorage.
   Communicates with PHP + MySQL API endpoints under /api.
   ============================================================ */

const Store = (() => {

  function apiRequest(url, method = 'GET', body = null) {
    const xhr = new XMLHttpRequest();
    xhr.open(method, url, false); // Synchronous API call for unmodified inline page scripts
    if (body) {
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify(body));
    } else {
      xhr.send();
    }
    if (xhr.status >= 200 && xhr.status < 500) {
      try {
        return JSON.parse(xhr.responseText);
      } catch (e) {
        return null;
      }
    }
    return null;
  }

  // ---------------------------------------------------------------
  // Auth API
  // ---------------------------------------------------------------
  function login(email, password) {
    const res = apiRequest('api/auth.php', 'POST', { email, password });
    if (!res || !res.ok) {
      return { ok: false, error: (res && res.error) ? res.error : 'Invalid email or password.' };
    }
    return res;
  }

  function logout() {
    apiRequest('api/auth.php', 'POST', { action: 'logout' });
  }

  function getSession() {
    const res = apiRequest('api/auth.php', 'GET');
    return (res && res.ok && res.user) ? res.user : null;
  }

  function requireAuth() {
    const s = getSession();
    if (!s) {
      window.location.href = 'index.html';
    }
    return s;
  }

  // ---------------------------------------------------------------
  // Trades API
  // ---------------------------------------------------------------
  function getTrades() {
    const res = apiRequest('api/trades.php', 'GET');
    return Array.isArray(res) ? res : [];
  }

  function addTrade(trade) {
    return apiRequest('api/trades.php', 'POST', trade);
  }

  function uploadTradesCsv(file) {
    const formData = new FormData();
    formData.append('file', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/trades_upload.php', false); // Synchronous API call matching Store style
    xhr.send(formData);

    if (xhr.status >= 200 && xhr.status < 500) {
      try {
        return JSON.parse(xhr.responseText);
      } catch (e) {
        return { ok: false, error: 'Invalid response from server.' };
      }
    }
    return { ok: false, error: 'Upload failed with status ' + xhr.status };
  }

  // ---------------------------------------------------------------
  // Reconciliation API
  // ---------------------------------------------------------------
  function runReconciliation(sourceA, sourceB) {
    return apiRequest('api/reconciliation.php', 'POST', { sourceA, sourceB });
  }

  function getRuns() {
    const res = apiRequest('api/reconciliation.php', 'GET');
    return Array.isArray(res) ? res : [];
  }

  // ---------------------------------------------------------------
  // Exceptions API
  // ---------------------------------------------------------------
  function getExceptions() {
    const res = apiRequest('api/exceptions.php', 'GET');
    return Array.isArray(res) ? res : [];
  }

  function resolveException(id, note, newStatus) {
    const res = apiRequest('api/exceptions.php?id=' + encodeURIComponent(id), 'POST', {
      resolution_note: note,
      status: newStatus
    });
    return res || { ok: false };
  }

  // ---------------------------------------------------------------
  // Audit Log API
  // ---------------------------------------------------------------
  function getAuditLog() {
    const res = apiRequest('api/audit_log.php', 'GET');
    return Array.isArray(res) ? res : [];
  }

  function verifyAuditChain() {
    const res = apiRequest('api/audit_log.php?action=verify', 'GET');
    return res || { intact: false, results: [] };
  }

  function seed() {
    // No-op for backwards compatibility. DB is seeded via schema.sql.
  }

  return {
    seed,
    login,
    logout,
    getSession,
    requireAuth,
    getTrades,
    addTrade,
    uploadTradesCsv,
    runReconciliation,
    getRuns,
    getExceptions,
    resolveException,
    getAuditLog,
    verifyAuditChain
  };
})();
