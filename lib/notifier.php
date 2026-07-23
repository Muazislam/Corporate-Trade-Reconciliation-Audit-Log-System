<?php
// ============================================================
// lib/notifier.php
// Email notification for reconciliation exceptions.
// Called from lib/reconciliation_engine.php after a run
// that raised new exceptions.
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

/**
 * Send a summary email about new reconciliation exceptions.
 *
 * The reconciliation run is NOT affected by the outcome — failures
 * are logged to the audit trail and silently swallowed.
 *
 * @param PDO    $pdo
 * @param string $runId
 * @param string $sourceA
 * @param string $sourceB
 * @param array  $newExceptions  Array of exception records (must have 'symbol' and 'exception_type')
 * @return bool  True if the email was sent successfully, false otherwise (or skipped).
 */
function sendExceptionNotification($pdo, $runId, $sourceA, $sourceB, array $newExceptions) {
    if (!defined('NOTIFY_EMAIL') || empty(NOTIFY_EMAIL)) {
        appendAuditLog($pdo, 'SYSTEM', 'NOTIFICATION_SKIPPED_NO_CONFIG', 'ReconciliationRun', $runId,
            "No NOTIFY_EMAIL configured. {$sourceA} vs {$sourceB}: " . count($newExceptions) . " exceptions not notified.");
        return false;
    }

    $count = count($newExceptions);
    $dateStr = date('Y-m-d H:i:s');

    $tableRows = '';
    foreach ($newExceptions as $exc) {
        $tableRows .= sprintf("  %-10s %s\n", $exc['symbol'], $exc['exception_type']);
    }

    $subject = "[LedgerChain Recon] {$count} exception(s) — {$sourceA} vs {$sourceB}";
    $body = "Reconciliation Exception Summary\n"
          . str_repeat('-', 50) . "\n"
          . "Run ID:      {$runId}\n"
          . "Date:        {$dateStr}\n"
          . "Source A:    {$sourceA}\n"
          . "Source B:    {$sourceB}\n"
          . "New Exceptions: {$count}\n\n"
          . "Exception List:\n"
          . str_repeat('-', 50) . "\n"
          . "  Symbol     Type\n"
          . str_repeat('-', 50) . "\n"
          . $tableRows
          . str_repeat('-', 50) . "\n"
          . "This is an automated notification from LedgerChain Recon.\n";

    $headers = "From: noreply@ledgerchain.local\r\n"
             . "X-Mailer: PHP/" . phpversion() . "\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n";

    try {
        $sent = @mail(NOTIFY_EMAIL, $subject, $body, $headers);
        if ($sent) {
            appendAuditLog($pdo, 'SYSTEM', 'EXCEPTION_NOTIFICATION_SENT', 'ReconciliationRun', $runId,
                "{$count} exceptions notified to " . NOTIFY_EMAIL);
            return true;
        }
        appendAuditLog($pdo, 'SYSTEM', 'NOTIFICATION_FAILED', 'ReconciliationRun', $runId,
            "mail() returned false for " . NOTIFY_EMAIL . ": {$count} exceptions not notified.");
        return false;
    } catch (Exception $e) {
        appendAuditLog($pdo, 'SYSTEM', 'NOTIFICATION_FAILED', 'ReconciliationRun', $runId,
            "mail() exception for " . NOTIFY_EMAIL . ": " . $e->getMessage());
        return false;
    }
}
