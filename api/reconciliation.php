<?php
// ============================================================
// api/reconciliation.php
// Reconciliation API: POST run reconciliation, GET run history
// ============================================================

require_once __DIR__ . '/../db.php';

$user = requireAuthSession();
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Handle GET: list reconciliation run history
if ($method === 'GET') {
    $stmt = $pdo->query(
        "SELECT id, run_date, source_a, source_b, total_compared, matched_count, mismatched_count
         FROM reconciliation_runs
         ORDER BY run_date DESC, id DESC"
    );
    $runs = $stmt->fetchAll();

    foreach ($runs as &$r) {
        $r['total_compared']   = (int)$r['total_compared'];
        $r['matched_count']    = (int)$r['matched_count'];
        $r['mismatched_count'] = (int)$r['mismatched_count'];
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

    if (empty($sourceA) || empty($sourceB)) {
        jsonError('Both sourceA and sourceB are required.', 400);
    }
    if ($sourceA === $sourceB) {
        jsonError('Source A and Source B must be different.', 400);
    }

    // Fetch trades for sourceA
    $stmtA = $pdo->prepare("SELECT * FROM trades WHERE source_system = ?");
    $stmtA->execute([$sourceA]);
    $groupA = $stmtA->fetchAll();

    // Fetch trades for sourceB
    $stmtB = $pdo->prepare("SELECT * FROM trades WHERE source_system = ?");
    $stmtB->execute([$sourceB]);
    $groupB = $stmtB->fetchAll();

    // Index groupB by external_trade_id
    $byIdB = [];
    foreach ($groupB as $tb) {
        $tb['quantity'] = (int)$tb['quantity'];
        $tb['price']    = (float)$tb['price'];
        $byIdB[$tb['external_trade_id']] = $tb;
    }

    $matchedExternalIds = [];
    $newExceptions = [];
    $matchedCount = 0;
    $runId = 'run_' . bin2hex(random_bytes(4));
    $nowIso = date('c');

    foreach ($groupA as $ta) {
        $ta['quantity'] = (int)$ta['quantity'];
        $ta['price']    = (float)$ta['price'];

        $extId = $ta['external_trade_id'];
        if (!isset($byIdB[$extId])) {
            $newExceptions[] = [
                'id'             => 'exc_' . bin2hex(random_bytes(4)),
                'run_id'         => $runId,
                'trade_id_a'     => $ta['id'],
                'trade_id_b'     => null,
                'source_a'       => $sourceA,
                'source_b'       => $sourceB,
                'exception_type' => 'MISSING_IN_' . strtoupper($sourceB),
                'status'         => 'OPEN',
                'symbol'         => $ta['symbol'],
                'snapshot_a'     => $ta,
                'snapshot_b'     => null,
                'resolution_note'=> '',
                'created_at'     => $nowIso
            ];
            continue;
        }

        $matchedExternalIds[$extId] = true;
        $tb = $byIdB[$extId];

        $qtyMismatch = ($ta['quantity'] !== $tb['quantity']);
        $priceMismatch = (abs($ta['price'] - $tb['price']) > 0.001);

        if ($qtyMismatch) {
            $newExceptions[] = [
                'id'             => 'exc_' . bin2hex(random_bytes(4)),
                'run_id'         => $runId,
                'trade_id_a'     => $ta['id'],
                'trade_id_b'     => $tb['id'],
                'source_a'       => $sourceA,
                'source_b'       => $sourceB,
                'exception_type' => 'QTY_MISMATCH',
                'status'         => 'OPEN',
                'symbol'         => $ta['symbol'],
                'snapshot_a'     => $ta,
                'snapshot_b'     => $tb,
                'resolution_note'=> '',
                'created_at'     => $nowIso
            ];
        } else if ($priceMismatch) {
            $newExceptions[] = [
                'id'             => 'exc_' . bin2hex(random_bytes(4)),
                'run_id'         => $runId,
                'trade_id_a'     => $ta['id'],
                'trade_id_b'     => $tb['id'],
                'source_a'       => $sourceA,
                'source_b'       => $sourceB,
                'exception_type' => 'AMOUNT_MISMATCH',
                'status'         => 'OPEN',
                'symbol'         => $ta['symbol'],
                'snapshot_a'     => $ta,
                'snapshot_b'     => $tb,
                'resolution_note'=> '',
                'created_at'     => $nowIso
            ];
        } else {
            $matchedCount++;
        }
    }

    foreach ($groupB as $tb) {
        $tb['quantity'] = (int)$tb['quantity'];
        $tb['price']    = (float)$tb['price'];
        $extId = $tb['external_trade_id'];

        if (!isset($matchedExternalIds[$extId])) {
            $newExceptions[] = [
                'id'             => 'exc_' . bin2hex(random_bytes(4)),
                'run_id'         => $runId,
                'trade_id_a'     => null,
                'trade_id_b'     => $tb['id'],
                'source_a'       => $sourceA,
                'source_b'       => $sourceB,
                'exception_type' => 'MISSING_IN_' . strtoupper($sourceA),
                'status'         => 'OPEN',
                'symbol'         => $tb['symbol'],
                'snapshot_a'     => null,
                'snapshot_b'     => $tb,
                'resolution_note'=> '',
                'created_at'     => $nowIso
            ];
        }
    }

    $totalCompared = count($groupA) + count($groupB);
    $mismatchedCount = count($newExceptions);

    // Save reconciliation run
    $stmtRun = $pdo->prepare(
        "INSERT INTO reconciliation_runs (id, run_date, source_a, source_b, total_compared, matched_count, mismatched_count)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $runDate = date('Y-m-d H:i:s');
    $stmtRun->execute([$runId, $runDate, $sourceA, $sourceB, $totalCompared, $matchedCount, $mismatchedCount]);

    // Save exceptions
    $stmtExc = $pdo->prepare(
        "INSERT INTO reconciliation_exceptions
         (id, run_id, trade_id_a, trade_id_b, source_a, source_b, exception_type, status, symbol, snapshot_a, snapshot_b, resolution_note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($newExceptions as $exc) {
        $stmtExc->execute([
            $exc['id'],
            $exc['run_id'],
            $exc['trade_id_a'],
            $exc['trade_id_b'],
            $exc['source_a'],
            $exc['source_b'],
            $exc['exception_type'],
            $exc['status'],
            $exc['symbol'],
            $exc['snapshot_a'] ? json_encode($exc['snapshot_a']) : null,
            $exc['snapshot_b'] ? json_encode($exc['snapshot_b']) : null,
            $exc['resolution_note'],
            date('Y-m-d H:i:s')
        ]);
    }

    // Append audit log
    $details = "{$sourceA} vs {$sourceB}: {$matchedCount} matched, {$mismatchedCount} exceptions";
    appendAuditLog($pdo, $user['name'], 'RUN_RECONCILIATION', 'ReconciliationRun', $runId, $details);

    $runRecord = [
        'id'               => $runId,
        'run_date'         => date('c', strtotime($runDate)),
        'source_a'         => $sourceA,
        'source_b'         => $sourceB,
        'total_compared'   => $totalCompared,
        'matched_count'    => $matchedCount,
        'mismatched_count' => $mismatchedCount
    ];

    jsonResponse($runRecord, 201);
}

jsonError('Method not allowed', 450);
