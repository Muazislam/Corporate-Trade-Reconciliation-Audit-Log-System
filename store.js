/* ============================================================
   store.js
   ------------------------------------------------------------
   Temporary client-side data layer.

   IMPORTANT: This file exists ONLY because there is no PHP/MySQL
   backend yet. Every function below is written to mirror what a
   future PHP API endpoint should return, so that swapping this
   out later is mostly a find/replace job.

   e.g. Store.getTrades()  will eventually become:
        fetch('api/trades.php').then(r => r.json())

   Data is persisted in localStorage purely so the demo survives
   a page refresh. None of this is secure and none of it should
   be mistaken for the real auth/data layer.
   ============================================================ */

const Store = (() => {

  const KEYS = {
    users: 'trc_users',
    trades: 'trc_trades',
    exceptions: 'trc_exceptions',
    auditLog: 'trc_audit_log',
    session: 'trc_session',
    seeded: 'trc_seeded_v1'
  };

  // ---------- tiny synchronous hash (placeholder for sha256) ----------
  // NOTE: this is NOT cryptographically secure. It exists only to
  // demonstrate the "chained hash" concept in the UI. The real PHP
  // backend should use hash('sha256', ...) instead.
  function simpleHash(str) {
    let h = 5381;
    for (let i = 0; i < str.length; i++) {
      h = ((h << 5) + h) + str.charCodeAt(i);
      h = h & h;
    }
    return (h >>> 0).toString(16).padStart(8, '0');
  }

  function nowIso() { return new Date().toISOString(); }
  function uid(prefix) { return prefix + '_' + Math.random().toString(36).slice(2, 9); }

  function read(key, fallback) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : fallback;
    } catch (e) { return fallback; }
  }
  function write(key, value) {
    localStorage.setItem(key, JSON.stringify(value));
  }

  // ---------------------------------------------------------------
  // Seed data (first run only)
  // ---------------------------------------------------------------
  function seed() {
    if (read(KEYS.seeded, false)) return;

    write(KEYS.users, [
      { id: 'u_admin',   name: 'Amir Khan',    email: 'admin@corp.test',   password: 'admin123',   role: 'ADMIN' },
      { id: 'u_auditor', name: 'Sara Ahmed',   email: 'auditor@corp.test', password: 'audit123',   role: 'AUDITOR' }
    ]);

    const trades = [
      { id: uid('t'), external_trade_id: 'TRX-1001', source_system: 'Internal', symbol: 'AAPL', quantity: 100, price: 189.50, side: 'BUY',  trade_date: '2026-07-18', status: 'PENDING' },
      { id: uid('t'), external_trade_id: 'TRX-1001', source_system: 'BrokerA',  symbol: 'AAPL', quantity: 100, price: 189.50, side: 'BUY',  trade_date: '2026-07-18', status: 'PENDING' },
      { id: uid('t'), external_trade_id: 'TRX-1002', source_system: 'Internal', symbol: 'MSFT', quantity: 50,  price: 412.10, side: 'SELL', trade_date: '2026-07-18', status: 'PENDING' },
      { id: uid('t'), external_trade_id: 'TRX-1002', source_system: 'BrokerA',  symbol: 'MSFT', quantity: 50,  price: 409.75, side: 'SELL', trade_date: '2026-07-18', status: 'PENDING' },
      { id: uid('t'), external_trade_id: 'TRX-1003', source_system: 'Internal', symbol: 'TSLA', quantity: 25,  price: 245.00, side: 'BUY',  trade_date: '2026-07-19', status: 'PENDING' },
      { id: uid('t'), external_trade_id: 'TRX-1004', source_system: 'BrokerA',  symbol: 'NVDA', quantity: 10,  price: 118.30, side: 'BUY',  trade_date: '2026-07-19', status: 'PENDING' },
      { id: uid('t'), external_trade_id: 'TRX-1005', source_system: 'Internal', symbol: 'GOOG', quantity: 40,  price: 179.60, side: 'SELL', trade_date: '2026-07-19', status: 'PENDING' },
      { id: uid('t'), external_trade_id: 'TRX-1005', source_system: 'BrokerA',  symbol: 'GOOG', quantity: 45,  price: 179.60, side: 'SELL', trade_date: '2026-07-19', status: 'PENDING' }
    ];
    write(KEYS.trades, trades);
    write(KEYS.exceptions, []);
    write(KEYS.auditLog, []);
    write(KEYS.seeded, true);
  }

  // ---------------------------------------------------------------
  // Auth (mock — real version = PHP session + password_hash)
  // ---------------------------------------------------------------
  function login(email, password) {
    const users = read(KEYS.users, []);
    const user = users.find(u => u.email === email && u.password === password);
    if (!user) {
      appendAudit({ actor: email || '(unknown)', action: 'LOGIN_FAILED', entity_type: 'Session', entity_id: '-', details: 'Invalid credentials' });
      return { ok: false, error: 'Invalid email or password.' };
    }
    write(KEYS.session, { id: user.id, name: user.name, role: user.role, email: user.email });
    appendAudit({ actor: user.name, action: 'LOGIN', entity_type: 'Session', entity_id: user.id, details: 'Successful login' });
    return { ok: true, user };
  }

  function logout() {
    const session = getSession();
    if (session) appendAudit({ actor: session.name, action: 'LOGOUT', entity_type: 'Session', entity_id: session.id, details: '' });
    localStorage.removeItem(KEYS.session);
  }

  function getSession() { return read(KEYS.session, null); }

  function requireAuth() {
    const s = getSession();
    if (!s) { window.location.href = 'index.html'; }
    return s;
  }

  // ---------------------------------------------------------------
  // Trades
  // ---------------------------------------------------------------
  function getTrades() { return read(KEYS.trades, []); }

  function addTrade(trade) {
    const trades = getTrades();
    const record = {
      id: uid('t'),
      status: 'PENDING',
      ...trade
    };
    trades.push(record);
    write(KEYS.trades, trades);

    const session = getSession();
    appendAudit({
      actor: session ? session.name : 'system',
      action: 'CREATE_TRADE',
      entity_type: 'Trade',
      entity_id: record.id,
      details: `${record.external_trade_id} (${record.source_system}) ${record.symbol} qty=${record.quantity} @ ${record.price}`
    });
    return record;
  }

  // ---------------------------------------------------------------
  // Reconciliation engine (simplified exact/tolerance match)
  // ---------------------------------------------------------------
  function runReconciliation(sourceA, sourceB) {
    const trades = getTrades();
    const groupA = trades.filter(t => t.source_system === sourceA);
    const groupB = trades.filter(t => t.source_system === sourceB);

    const byIdB = {};
    groupB.forEach(t => { byIdB[t.external_trade_id] = t; });

    const matchedIds = new Set();
    const newExceptions = [];
    let matchedCount = 0;

    groupA.forEach(a => {
      const b = byIdB[a.external_trade_id];
      if (!b) {
        newExceptions.push(makeException(sourceA, sourceB, a.id, null, 'MISSING_IN_' + sourceB.toUpperCase(), a, null));
        return;
      }
      matchedIds.add(a.external_trade_id);
      const qtyMismatch = Number(a.quantity) !== Number(b.quantity);
      const priceMismatch = Math.abs(Number(a.price) - Number(b.price)) > 0.001;

      if (qtyMismatch) {
        newExceptions.push(makeException(sourceA, sourceB, a.id, b.id, 'QTY_MISMATCH', a, b));
      } else if (priceMismatch) {
        newExceptions.push(makeException(sourceA, sourceB, a.id, b.id, 'AMOUNT_MISMATCH', a, b));
      } else {
        matchedCount++;
      }
    });

    groupB.forEach(b => {
      if (!matchedIds.has(b.external_trade_id)) {
        newExceptions.push(makeException(sourceA, sourceB, null, b.id, 'MISSING_IN_' + sourceA.toUpperCase(), null, b));
      }
    });

    const existing = read(KEYS.exceptions, []);
    write(KEYS.exceptions, existing.concat(newExceptions));

    const run = {
      id: uid('run'),
      run_date: nowIso(),
      source_a: sourceA,
      source_b: sourceB,
      total_compared: groupA.length + groupB.length,
      matched_count: matchedCount,
      mismatched_count: newExceptions.length
    };

    const session = getSession();
    appendAudit({
      actor: session ? session.name : 'system',
      action: 'RUN_RECONCILIATION',
      entity_type: 'ReconciliationRun',
      entity_id: run.id,
      details: `${sourceA} vs ${sourceB}: ${matchedCount} matched, ${newExceptions.length} exceptions`
    });

    const runs = read('trc_runs', []);
    runs.unshift(run);
    write('trc_runs', runs);

    return run;
  }

  function makeException(sourceA, sourceB, idA, idB, type, tradeA, tradeB) {
    return {
      id: uid('exc'),
      trade_id_a: idA,
      trade_id_b: idB,
      source_a: sourceA,
      source_b: sourceB,
      exception_type: type,
      status: 'OPEN',
      symbol: (tradeA || tradeB || {}).symbol || '—',
      snapshot_a: tradeA,
      snapshot_b: tradeB,
      resolution_note: '',
      created_at: nowIso()
    };
  }

  function getExceptions() { return read(KEYS.exceptions, []); }
  function getRuns() { return read('trc_runs', []); }

  function resolveException(id, note, newStatus) {
    const list = getExceptions();
    const idx = list.findIndex(e => e.id === id);
    if (idx === -1) return { ok: false };
    list[idx].status = newStatus;
    list[idx].resolution_note = note;
    list[idx].resolved_at = nowIso();
    write(KEYS.exceptions, list);

    const session = getSession();
    appendAudit({
      actor: session ? session.name : 'system',
      action: newStatus === 'RESOLVED' ? 'RESOLVE_EXCEPTION' : 'IGNORE_EXCEPTION',
      entity_type: 'ReconciliationException',
      entity_id: id,
      details: note || '(no note provided)'
    });
    return { ok: true };
  }

  // ---------------------------------------------------------------
  // Audit log (append-only + naive hash chain)
  // ---------------------------------------------------------------
  function appendAudit({ actor, action, entity_type, entity_id, details }) {
    const log = read(KEYS.auditLog, []);
    const prevHash = log.length ? log[log.length - 1].hash : 'GENESIS0';
    const timestamp = nowIso();
    const payload = `${prevHash}|${actor}|${action}|${entity_type}|${entity_id}|${timestamp}|${details}`;
    const entry = {
      id: uid('log'),
      actor, action, entity_type, entity_id, details, timestamp,
      prev_hash: prevHash,
      hash: simpleHash(payload)
    };
    log.push(entry);
    write(KEYS.auditLog, log);
    return entry;
  }

  function getAuditLog() { return read(KEYS.auditLog, []); }

  // Recomputes the chain from scratch and checks every hash still matches.
  function verifyAuditChain() {
    const log = getAuditLog();
    let prevHash = 'GENESIS0';
    const results = [];
    let intact = true;

    for (const entry of log) {
      const payload = `${prevHash}|${entry.actor}|${entry.action}|${entry.entity_type}|${entry.entity_id}|${entry.timestamp}|${entry.details}`;
      const recomputed = simpleHash(payload);
      const ok = recomputed === entry.hash && entry.prev_hash === prevHash;
      if (!ok) intact = false;
      results.push({ ...entry, verified: ok });
      prevHash = entry.hash;
    }
    return { intact, results };
  }

  return {
    seed, login, logout, getSession, requireAuth,
    getTrades, addTrade,
    runReconciliation, getRuns,
    getExceptions, resolveException,
    getAuditLog, verifyAuditChain,
    appendAudit
  };
})();

Store.seed();
