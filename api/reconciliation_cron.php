<?php
// ============================================================
// api/reconciliation_cron.php
// Scheduled/cron reconciliation runner.
// Usage: php api/reconciliation_cron.php <sourceA> <sourceB>
// Example: php api/reconciliation_cron.php Internal BrokerA
// ============================================================

require_once __DIR__ . '/../lib/reconciliation_engine.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CLI execution only.']);
    exit(1);
}

$sourceA = $argv[1] ?? '';
$sourceB = $argv[2] ?? '';

if (empty($sourceA) || empty($sourceB)) {
    fwrite(STDERR, "Usage: php {$argv[0]} <sourceA> <sourceB>\n");
    fwrite(STDERR, "Example: php {$argv[0]} Internal BrokerA\n");
    exit(1);
}

$pdo = getDbConnection();

try {
    $runRecord = runReconciliation($pdo, $sourceA, $sourceB, 'SCHEDULED', 'SYSTEM');
    echo json_encode(['ok' => true, 'run' => $runRecord]) . "\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Reconciliation cron failed: " . $e->getMessage() . "\n");
    exit(1);
}
