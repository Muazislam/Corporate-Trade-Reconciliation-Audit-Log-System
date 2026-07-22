<?php
// ============================================================
// api/audit_log.php
// Audit Log API: GET list (with action/actor filters), GET verify
// ============================================================

require_once __DIR__ . '/../db.php';

$user = requireAuthSession();
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Handle chain verification request
    $isVerify = isset($_GET['verify']) || (isset($_GET['action']) && $_GET['action'] === 'verify');

    if ($isVerify) {
        $stmt = $pdo->query(
            "SELECT log_id AS id, actor, action, entity_type, entity_id, details, timestamp, prev_hash, hash
             FROM audit_log
             ORDER BY audit_log.id ASC"
        );
        $logs = $stmt->fetchAll();

        $prevHash = 'GENESIS0';
        $results = [];
        $intact = true;

        foreach ($logs as $entry) {
            $payload = "{$prevHash}|{$entry['actor']}|{$entry['action']}|{$entry['entity_type']}|{$entry['entity_id']}|{$entry['timestamp']}|{$entry['details']}";
            $recomputed = hash('sha256', $payload);
            $isVerified = ($recomputed === $entry['hash']) && ($entry['prev_hash'] === $prevHash);

            if (!$isVerified) {
                $intact = false;
            }

            $entry['verified'] = $isVerified;
            $results[] = $entry;
            $prevHash = $entry['hash'];
        }

        jsonResponse(['intact' => $intact, 'results' => $results]);
    }

    // Handle normal GET list of audit entries
    $actionFilter = trim($_GET['action'] ?? '');
    $actorFilter  = trim($_GET['actor'] ?? '');

    $sql = "SELECT log_id AS id, actor, action, entity_type, entity_id, details, timestamp, prev_hash, hash
            FROM audit_log WHERE 1=1";
    $params = [];

    if (!empty($actionFilter) && $actionFilter !== 'verify') {
        $sql .= " AND action = ?";
        $params[] = $actionFilter;
    }

    if (!empty($actorFilter)) {
        $sql .= " AND LOWER(actor) LIKE ?";
        $params[] = '%' . strtolower($actorFilter) . '%';
    }

    $sql .= " ORDER BY audit_log.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    jsonResponse($logs);
}

jsonError('Method not allowed', 450);
