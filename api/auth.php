<?php
// ============================================================
// api/auth.php
// Auth API: POST login, POST logout, GET session
// ============================================================

require_once __DIR__ . '/../db.php';

startSessionIfNeeded();
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Handle GET: retrieve current session
if ($method === 'GET') {
    $user = getAuthenticatedUser();
    jsonResponse(['ok' => true, 'user' => $user]);
}

// Handle POST: login or logout
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;
    $action = $input['action'] ?? '';

    // Logout
    if ($action === 'logout') {
        $user = getAuthenticatedUser();
        if ($user) {
            appendAuditLog($pdo, $user['name'], 'LOGOUT', 'Session', $user['id'], '');
        }
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        jsonResponse(['ok' => true]);
    }

    // Login
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        appendAuditLog($pdo, $email ?: '(unknown)', 'LOGIN_FAILED', 'Session', '-', 'Missing email or password');
        jsonResponse(['ok' => false, 'error' => 'Invalid email or password.'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        appendAuditLog($pdo, $email ?: '(unknown)', 'LOGIN_FAILED', 'Session', '-', 'Invalid credentials');
        jsonResponse(['ok' => false, 'error' => 'Invalid email or password.'], 401);
    }

    $sessionData = [
        'id'    => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role']
    ];
    $_SESSION['user'] = $sessionData;

    appendAuditLog($pdo, $user['name'], 'LOGIN', 'Session', $user['id'], 'Successful login');
    jsonResponse(['ok' => true, 'user' => $sessionData]);
}

jsonError('Method not allowed', 450);
