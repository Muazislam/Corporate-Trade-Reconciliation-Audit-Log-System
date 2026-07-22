<?php
// ============================================================
// api/trades.php
// Trades API: GET list (with optional filters), POST create
// ============================================================

require_once __DIR__ . '/../db.php';

$user = requireAuthSession();
$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Handle GET: list trades
if ($method === 'GET') {
    $sourceSystem = trim($_GET['source_system'] ?? '');
    $symbol = trim($_GET['symbol'] ?? '');

    $sql = "SELECT id, external_trade_id, source_system, symbol, quantity, price, side, trade_date, status FROM trades WHERE 1=1";
    $params = [];

    if (!empty($sourceSystem)) {
        $sql .= " AND source_system = ?";
        $params[] = $sourceSystem;
    }

    if (!empty($symbol)) {
        $sql .= " AND UPPER(symbol) LIKE ?";
        $params[] = '%' . strtoupper($symbol) . '%';
    }

    $sql .= " ORDER BY external_trade_id ASC, id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $trades = $stmt->fetchAll();

    // Format numbers
    foreach ($trades as &$t) {
        $t['quantity'] = (int)$t['quantity'];
        $t['price'] = (float)$t['price'];
    }

    jsonResponse($trades);
}

// Handle POST: create trade
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    $externalTradeId = trim($input['external_trade_id'] ?? '');
    $sourceSystem = trim($input['source_system'] ?? '');
    $symbol = strtoupper(trim($input['symbol'] ?? ''));
    $side = strtoupper(trim($input['side'] ?? ''));
    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
    $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $tradeDate = trim($input['trade_date'] ?? '');

    if (empty($externalTradeId)) {
        jsonError('External trade ID is required.', 400);
    }
    if (empty($sourceSystem)) {
        jsonError('Source system is required.', 400);
    }
    if (empty($symbol)) {
        jsonError('Symbol is required.', 400);
    }
    if (!in_array($side, ['BUY', 'SELL'], true)) {
        jsonError('Side must be BUY or SELL.', 400);
    }
    if ($quantity === false || $quantity <= 0) {
        jsonError('Quantity must be a positive integer.', 400);
    }
    if ($price === false || $price <= 0) {
        jsonError('Price must be a positive number.', 400);
    }
    if (empty($tradeDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tradeDate)) {
        jsonError('Trade date must be in YYYY-MM-DD format.', 400);
    }

    $tradeId = 't_' . bin2hex(random_bytes(4));
    $status = 'PENDING';

    $stmt = $pdo->prepare(
        "INSERT INTO trades (id, external_trade_id, source_system, symbol, quantity, price, side, trade_date, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$tradeId, $externalTradeId, $sourceSystem, $symbol, $quantity, $price, $side, $tradeDate, $status]);

    $tradeRecord = [
        'id'                => $tradeId,
        'external_trade_id' => $externalTradeId,
        'source_system'     => $sourceSystem,
        'symbol'            => $symbol,
        'quantity'          => $quantity,
        'price'             => $price,
        'side'              => $side,
        'trade_date'        => $tradeDate,
        'status'            => $status
    ];

    $details = "{$externalTradeId} ({$sourceSystem}) {$symbol} qty={$quantity} @ {$price}";
    appendAuditLog($pdo, $user['name'], 'CREATE_TRADE', 'Trade', $tradeId, $details);

    jsonResponse($tradeRecord, 201);
}

jsonError('Method not allowed', 450);
