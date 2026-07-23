<?php
// ============================================================
// db.php
// Database connection and helper functions using PDO.
// ============================================================

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'ledgerchain_recon');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDbConnection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
            exit(1);
        }
    }
    return $pdo;
}

function startSessionIfNeeded() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function jsonError($message, $statusCode = 400) {
    jsonResponse(['ok' => false, 'error' => $message], $statusCode);
}

function getAuthenticatedUser() {
    startSessionIfNeeded();
    return $_SESSION['user'] ?? null;
}

function requireAuthSession() {
    $user = getAuthenticatedUser();
    if (!$user) {
        jsonError('Unauthorized access. Please log in.', 401);
    }
    return $user;
}

/**
 * Append entry to tamper-evident audit log table.
 */
function appendAuditLog($pdo, $actor, $action, $entityType, $entityId, $details) {
    $stmt = $pdo->query("SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1");
    $lastRow = $stmt->fetch();
    $prevHash = $lastRow ? $lastRow['hash'] : 'GENESIS0';

    $timestamp = date('c');
    $payload = "{$prevHash}|{$actor}|{$action}|{$entityType}|{$entityId}|{$timestamp}|{$details}";
    $hash = hash('sha256', $payload);
    $logId = 'log_' . bin2hex(random_bytes(4));

    $insertStmt = $pdo->prepare("INSERT INTO audit_log (log_id, actor, action, entity_type, entity_id, details, timestamp, prev_hash, hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insertStmt->execute([
        $logId,
        $actor,
        $action,
        $entityType,
        $entityId,
        $details,
        $timestamp,
        $prevHash,
        $hash
    ]);

    return [
        'id'          => $logId,
        'actor'       => $actor,
        'action'      => $action,
        'entity_type' => $entityType,
        'entity_id'   => $entityId,
        'details'     => $details,
        'timestamp'   => $timestamp,
        'prev_hash'   => $prevHash,
        'hash'        => $hash
    ];
}
