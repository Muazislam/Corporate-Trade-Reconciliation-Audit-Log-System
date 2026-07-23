<?php
// ============================================================
// api/reconciliation.php
// Reconciliation API: POST run reconciliation, GET run history
// ============================================================

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/reconciliation_engine.php';

$user = requireAuthSession();
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Handle GET: list reconciliation run history
if ($method === 'GET') {
    $stmt = $pdo->query(
        "SELECT id, run_date, source_a, source_b, total_compared, matched_count, mismatched_count, trigger_type, notification_sent
         FROM reconciliation_runs
         ORDER BY run_date DESC, id DESC"
    );
    $runs = $stmt->fetchAll();

    foreach ($runs as &$r) {
        $r['total_compared']   = (int)$r['total_compared'];
        $r['matched_count']    = (int)$r['matched_count'];
        $r['mismatched_count'] = (int)$r['mismatched_count'];
        $r['trigger_type']     = $r['trigger_type'] ?? 'MANUAL';
        $r['notification_sent'] = (int)($r['notification_sent'] ?? 0);
        // Ensure ISO format for run_date if needed by frontend
        $r['run_date'] = date('c', strtotime($r['run_date']));
    }

    jsonResponse($runs);
}

// Handle POST: run reconciliation between sourceA and sourceB
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    $sourceA = trim($input['sourceA'] ?? $input['source_a'] ?? '');
    $sourceB = trim($input['sourceB'] ?? $input['source_b'] ?? '');

    try {
        $runRecord = runReconciliation($pdo, $sourceA, $sourceB, 'MANUAL', $user['name']);
        jsonResponse($runRecord, 201);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
    }
}

jsonError('Method not allowed', 450);
