<?php
// ============================================================
// api/exceptions.php
// Exceptions API: GET list (with status & type filters), PATCH/POST resolve
// ============================================================

require_once __DIR__ . '/../db.php';

$user = requireAuthSession();
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

/* ── Helper: resolve/ignore an exception ───────────────────── */
function _resolveException($pdo, $user, $id, $note, $newStatus) {
    if (empty($id)) {
        jsonError('Exception ID is required.', 400);
    }
    if (empty($note)) {
        jsonError('A resolution note is required before closing an exception.', 400);
    }
    if (!in_array($newStatus, ['RESOLVED', 'IGNORED'], true)) {
        jsonError('Status must be RESOLVED or IGNORED.', 400);
    }

    $stmtCheck = $pdo->prepare("SELECT * FROM reconciliation_exceptions WHERE id = ?");
    $stmtCheck->execute([$id]);
    $exc = $stmtCheck->fetch();

    if (!$exc) {
        jsonError('Exception record not found.', 404);
    }

    $resolvedAt = date('Y-m-d H:i:s');
    $stmtUpdate = $pdo->prepare(
        "UPDATE reconciliation_exceptions SET status = ?, resolution_note = ?, resolved_at = ? WHERE id = ?"
    );
    $stmtUpdate->execute([$newStatus, $note, $resolvedAt, $id]);

    $action = ($newStatus === 'RESOLVED') ? 'RESOLVE_EXCEPTION' : 'IGNORE_EXCEPTION';
    appendAuditLog($pdo, $user['name'], $action, 'ReconciliationException', $id, $note);

    jsonResponse(['ok' => true, 'id' => $id, 'status' => $newStatus, 'resolution_note' => $note]);
}

// Support PATCH or POST for resolve action
if ($method === 'PATCH' || ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'resolve')) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $id = trim($_GET['id'] ?? $input['id'] ?? '');
    $note = trim($input['resolution_note'] ?? $input['note'] ?? '');
    $newStatus = strtoupper(trim($input['status'] ?? ''));
    _resolveException($pdo, $user, $id, $note, $newStatus);
}

// Handle GET: list exceptions with optional filters
if ($method === 'GET') {
    $statusFilter = trim($_GET['status'] ?? '');
    $typeFilter = trim($_GET['exception_type'] ?? $_GET['type'] ?? '');

    $sql = "SELECT id, run_id, trade_id_a, trade_id_b, source_a, source_b, exception_type, status, symbol, snapshot_a, snapshot_b, resolution_note, created_at, resolved_at
            FROM reconciliation_exceptions WHERE 1=1";
    $params = [];

    if (!empty($statusFilter)) {
        $sql .= " AND status = ?";
        $params[] = $statusFilter;
    }

    if (!empty($typeFilter)) {
        $sql .= " AND exception_type = ?";
        $params[] = $typeFilter;
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exceptions = $stmt->fetchAll();

    foreach ($exceptions as &$e) {
        $e['snapshot_a'] = $e['snapshot_a'] ? json_decode($e['snapshot_a'], true) : null;
        $e['snapshot_b'] = $e['snapshot_b'] ? json_decode($e['snapshot_b'], true) : null;
        if ($e['created_at']) {
            $e['created_at'] = date('c', strtotime($e['created_at']));
        }
        if ($e['resolved_at']) {
            $e['resolved_at'] = date('c', strtotime($e['resolved_at']));
        }
    }

    jsonResponse($exceptions);
}

// POST without ?action=resolve — also treat as resolve for convenience
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $id = trim($_GET['id'] ?? $input['id'] ?? '');
    $note = trim($input['resolution_note'] ?? $input['note'] ?? '');
    $newStatus = strtoupper(trim($input['status'] ?? ''));
    _resolveException($pdo, $user, $id, $note, $newStatus);
}

jsonError('Method not allowed', 450);
