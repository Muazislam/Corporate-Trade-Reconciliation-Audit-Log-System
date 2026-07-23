<?php
// ============================================================
// api/reports_csv.php
// Streams a CSV report for a specific reconciliation run,
// OR a filtered list of all exceptions (when no run_id given).
// Access: ADMIN and AUDITOR roles only.
// Audit: logs one REPORT_EXPORTED entry per request.
// ============================================================

require_once __DIR__ . '/../db.php';

$user = requireAuthSession();
$pdo  = getDbConnection();

// Only GET is accepted
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Method not allowed', 405);
}

$runId       = trim($_GET['run_id']       ?? '');
$statusF     = trim($_GET['status']       ?? '');
$typeF       = trim($_GET['type']         ?? '');
$exceptionsOnly = isset($_GET['exceptions_only']) && $_GET['exceptions_only'] === '1';

if ($runId !== '' && !$exceptionsOnly) {
    // ── Mode A: full run report (run metadata + its exceptions) ──────────────

    // Fetch run metadata
    $stmtRun = $pdo->prepare(
        "SELECT id, run_date, source_a, source_b, total_compared, matched_count, mismatched_count
         FROM reconciliation_runs WHERE id = ?"
    );
    $stmtRun->execute([$runId]);
    $run = $stmtRun->fetch();

    if (!$run) {
        jsonError('Reconciliation run not found.', 404);
    }

    // Fetch exceptions for this run
    $stmtExc = $pdo->prepare(
        "SELECT symbol, exception_type, source_a, source_b, snapshot_a, snapshot_b, status, resolution_note, created_at
         FROM reconciliation_exceptions
         WHERE run_id = ?
         ORDER BY created_at ASC, id ASC"
    );
    $stmtExc->execute([$runId]);
    $exceptions = $stmtExc->fetchAll();

    // Decode JSON snapshots
    foreach ($exceptions as &$e) {
        $e['snapshot_a'] = $e['snapshot_a'] ? json_decode($e['snapshot_a'], true) : null;
        $e['snapshot_b'] = $e['snapshot_b'] ? json_decode($e['snapshot_b'], true) : null;
    }
    unset($e);

    // Audit log
    appendAuditLog($pdo, $user['name'], 'REPORT_EXPORTED', 'ReconciliationRun', $runId,
        "Run {$runId} exported as CSV by {$user['name']}");

    // Stream CSV
    $safeId   = preg_replace('/[^a-zA-Z0-9_-]/', '_', $runId);
    $filename = "recon_report_{$safeId}.csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // BOM for Excel UTF-8 compatibility
    fwrite($out, "\xEF\xBB\xBF");

    // Section 1: Run Metadata
    fputcsv($out, ['Run Report — LedgerChain Recon']);
    fputcsv($out, []);
    fputcsv($out, ['Run ID',        $run['id']]);
    fputcsv($out, ['Run Date',      $run['run_date']]);
    fputcsv($out, ['Source A',      $run['source_a']]);
    fputcsv($out, ['Source B',      $run['source_b']]);
    fputcsv($out, ['Total Compared',$run['total_compared']]);
    fputcsv($out, ['Matched',       $run['matched_count']]);
    fputcsv($out, ['Exceptions',    $run['mismatched_count']]);

    $matchRate = $run['total_compared'] > 0
        ? round(($run['matched_count'] / $run['total_compared']) * 100, 1) . '%'
        : '0%';
    fputcsv($out, ['Match Rate', $matchRate]);
    fputcsv($out, []);

    // Section 2: Exceptions table
    fputcsv($out, ['Exceptions']);
    fputcsv($out, [
        'Symbol',
        'Exception Type',
        'Source A — Qty',
        'Source A — Price',
        'Source B — Qty',
        'Source B — Price',
        'Status',
        'Resolution Note',
        'Raised At',
    ]);

    foreach ($exceptions as $exc) {
        $snapA = $exc['snapshot_a'];
        $snapB = $exc['snapshot_b'];
        fputcsv($out, [
            $exc['symbol'],
            $exc['exception_type'],
            $snapA ? $snapA['quantity'] : 'N/A',
            $snapA ? number_format((float)$snapA['price'], 2, '.', '') : 'N/A',
            $snapB ? $snapB['quantity'] : 'N/A',
            $snapB ? number_format((float)$snapB['price'], 2, '.', '') : 'N/A',
            $exc['status'],
            $exc['resolution_note'] ?? '',
            $exc['created_at'],
        ]);
    }

    fclose($out);
    exit;

} else {
    // ── Mode B: all (filtered) exceptions export ─────────────────────────────

    $sql    = "SELECT symbol, exception_type, source_a, source_b, snapshot_a, snapshot_b, status, resolution_note, created_at
               FROM reconciliation_exceptions WHERE 1=1";
    $params = [];

    if ($runId !== '') {
        $sql      .= " AND run_id = ?";
        $params[]  = $runId;
    }
    if ($statusF !== '') {
        $sql      .= " AND status = ?";
        $params[]  = strtoupper($statusF);
    }
    if ($typeF !== '') {
        $sql      .= " AND exception_type = ?";
        $params[]  = strtoupper($typeF);
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exceptions = $stmt->fetchAll();

    foreach ($exceptions as &$e) {
        $e['snapshot_a'] = $e['snapshot_a'] ? json_decode($e['snapshot_a'], true) : null;
        $e['snapshot_b'] = $e['snapshot_b'] ? json_decode($e['snapshot_b'], true) : null;
    }
    unset($e);

    // Build audit detail string
    $filterDesc = [];
    if ($runId)   $filterDesc[] = "run_id={$runId}";
    if ($statusF) $filterDesc[] = "status={$statusF}";
    if ($typeF)   $filterDesc[] = "type={$typeF}";
    $filterStr = $filterDesc ? implode('; ', $filterDesc) : 'all';
    appendAuditLog($pdo, $user['name'], 'REPORT_EXPORTED', 'ExceptionList', 'exceptions_export', "format=CSV; filters={$filterStr}");

    $filename = 'exceptions_export_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Exceptions Export — LedgerChain Recon']);
    fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
    if ($filterStr !== 'all') {
        fputcsv($out, ['Filters', $filterStr]);
    }
    fputcsv($out, []);

    fputcsv($out, [
        'Symbol',
        'Exception Type',
        'Source A',
        'Source B',
        'Source A — Qty',
        'Source A — Price',
        'Source B — Qty',
        'Source B — Price',
        'Status',
        'Resolution Note',
        'Raised At',
    ]);

    foreach ($exceptions as $exc) {
        $snapA = $exc['snapshot_a'];
        $snapB = $exc['snapshot_b'];
        fputcsv($out, [
            $exc['symbol'],
            $exc['exception_type'],
            $exc['source_a'],
            $exc['source_b'],
            $snapA ? $snapA['quantity'] : 'N/A',
            $snapA ? number_format((float)$snapA['price'], 2, '.', '') : 'N/A',
            $snapB ? $snapB['quantity'] : 'N/A',
            $snapB ? number_format((float)$snapB['price'], 2, '.', '') : 'N/A',
            $exc['status'],
            $exc['resolution_note'] ?? '',
            $exc['created_at'],
        ]);
    }

    fclose($out);
    exit;
}
