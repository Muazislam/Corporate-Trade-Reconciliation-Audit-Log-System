<?php
// ============================================================
// lib/reconciliation_engine.php
// Shared reconciliation logic used by api/reconciliation.php
// (manual) and api/reconciliation_cron.php (scheduled/cron).
// ============================================================

require_once __DIR__ . '/../db.php';

/**
 * Run a reconciliation between two source systems.
 *
 * @param PDO    $pdo         Database connection
 * @param string $sourceA     First source system name
 * @param string $sourceB     Second source system name
 * @param string $triggerType  'MANUAL' or 'SCHEDULED'
 * @param string $actor       Username for the audit log entry
 * @return array              Run record (id, run_date, source_a, source_b, total_compared, matched_count, mismatched_count, trigger_type)
 */
function runReconciliation($pdo, $sourceA, $sourceB, $triggerType, $actor) {
    if (empty($sourceA) || empty($sourceB)) {
        jsonError('Both sourceA and sourceB are required.', 400);
    }
    if ($sourceA === $sourceB) {
        jsonError('Source A and Source B must be different.', 400);
    }

    $stmtA = $pdo->prepare("SELECT * FROM trades WHERE source_system = ?");
    $stmtA->execute([$sourceA]);
    $groupA = $stmtA->fetchAll();

    $stmtB = $pdo->prepare("SELECT * FROM trades WHERE source_system = ?");
    $stmtB->execute([$sourceB]);
    $groupB = $stmtB->fetchAll();

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

    $stmtRun = $pdo->prepare(
        "INSERT INTO reconciliation_runs (id, run_date, source_a, source_b, total_compared, matched_count, mismatched_count, trigger_type)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $runDate = date('Y-m-d H:i:s');
    $stmtRun->execute([$runId, $runDate, $sourceA, $sourceB, $totalCompared, $matchedCount, $mismatchedCount, $triggerType]);

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

    // Send email notification if exceptions were raised
    if ($mismatchedCount > 0) {
        try {
            require_once __DIR__ . '/notifier.php';
            $notified = sendExceptionNotification($pdo, $runId, $sourceA, $sourceB, $newExceptions);
            if ($notified) {
                $stmtUpd = $pdo->prepare("UPDATE reconciliation_runs SET notification_sent = 1 WHERE id = ?");
                $stmtUpd->execute([$runId]);
            }
        } catch (\Exception $e) {
            appendAuditLog($pdo, 'SYSTEM', 'NOTIFICATION_FAILED', 'ReconciliationRun', $runId,
                "Notifier threw: " . $e->getMessage());
        }
    }

    $details = "{$sourceA} vs {$sourceB}: {$matchedCount} matched, {$mismatchedCount} exceptions";
    $action = ($triggerType === 'SCHEDULED') ? 'RECONCILIATION_RUN_SCHEDULED' : 'RUN_RECONCILIATION';
    appendAuditLog($pdo, $actor, $action, 'ReconciliationRun', $runId, $details);

    return [
        'id'               => $runId,
        'run_date'         => date('c', strtotime($runDate)),
        'source_a'         => $sourceA,
        'source_b'         => $sourceB,
        'total_compared'   => $totalCompared,
        'matched_count'    => $matchedCount,
        'mismatched_count' => $mismatchedCount,
        'trigger_type'     => $triggerType
    ];
}
