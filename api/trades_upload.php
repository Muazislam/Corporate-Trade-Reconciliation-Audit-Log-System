<?php
// ============================================================
// api/trades_upload.php
// CSV Bulk Upload API for trades
// Parses multipart/form-data CSV, validates rows, checks duplicates,
// inserts valid trades into MySQL DB, and logs single CSV_UPLOAD audit entry.
// ============================================================

require_once __DIR__ . '/../db.php';

$user = requireAuthSession();
$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 450);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonError('No CSV file uploaded or upload error occurred.', 400);
}

$tmpPath = $_FILES['file']['tmp_name'];
$handle = fopen($tmpPath, 'r');
if (!$handle) {
    jsonError('Failed to open uploaded CSV file.', 400);
}

// Remove UTF-8 BOM if present
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

// Read header row
$header = fgetcsv($handle);
if (!$header) {
    fclose($handle);
    jsonError('CSV file is empty.', 400);
}

// Clean and normalize header strings
$normalizedHeaders = array_map(function($h) {
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $h)));
}, $header);

// Default column index mapping
$colMap = [
    'external_trade_id' => array_search('external_trade_id', $normalizedHeaders),
    'source_system'     => array_search('source_system', $normalizedHeaders),
    'symbol'            => array_search('symbol', $normalizedHeaders),
    'side'              => array_search('side', $normalizedHeaders),
    'quantity'          => array_search('quantity', $normalizedHeaders),
    'price'             => array_search('price', $normalizedHeaders),
    'trade_date'        => array_search('trade_date', $normalizedHeaders),
];

// Fallback to positional indices if header names do not match expected standard
if ($colMap['external_trade_id'] === false) $colMap['external_trade_id'] = 0;
if ($colMap['source_system'] === false)     $colMap['source_system']     = 1;
if ($colMap['symbol'] === false)            $colMap['symbol']            = 2;
if ($colMap['side'] === false)              $colMap['side']              = 3;
if ($colMap['quantity'] === false)          $colMap['quantity']          = 4;
if ($colMap['price'] === false)             $colMap['price']             = 5;
if ($colMap['trade_date'] === false)        $colMap['trade_date']        = 6;

$allowedSources = ['internal' => 'Internal', 'brokera' => 'BrokerA'];
$allowedSides   = ['BUY', 'SELL'];

// Prepared statements for duplicate check & insertion
$stmtCheckDup = $pdo->prepare(
    "SELECT COUNT(*) FROM trades WHERE external_trade_id = ? AND source_system = ?"
);
$stmtInsert = $pdo->prepare(
    "INSERT INTO trades (id, external_trade_id, source_system, symbol, quantity, price, side, trade_date, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDING')"
);

$imported = 0;
$rejected = 0;
$errors   = [];
$batchSeen = [];
$rowNum = 1; // Row 1 is header

while (($data = fgetcsv($handle)) !== false) {
    $rowNum++;

    // Skip empty lines
    if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) {
        continue;
    }

    $extId        = isset($data[$colMap['external_trade_id']]) ? trim($data[$colMap['external_trade_id']]) : '';
    $rawSource    = isset($data[$colMap['source_system']]) ? trim($data[$colMap['source_system']]) : '';
    $symbol       = isset($data[$colMap['symbol']]) ? strtoupper(trim($data[$colMap['symbol']])) : '';
    $side         = isset($data[$colMap['side']]) ? strtoupper(trim($data[$colMap['side']])) : '';
    $rawQty       = isset($data[$colMap['quantity']]) ? trim($data[$colMap['quantity']]) : '';
    $rawPrice     = isset($data[$colMap['price']]) ? trim($data[$colMap['price']]) : '';
    $rawTradeDate = isset($data[$colMap['trade_date']]) ? trim($data[$colMap['trade_date']]) : '';

    // Field presence validation
    if ($extId === '') {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: missing external_trade_id"];
        continue;
    }
    if ($rawSource === '') {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: missing source_system"];
        continue;
    }
    if ($symbol === '') {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: missing symbol"];
        continue;
    }
    if ($side === '') {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: missing side"];
        continue;
    }
    if ($rawQty === '') {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: missing quantity"];
        continue;
    }
    if ($rawPrice === '') {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: missing price"];
        continue;
    }
    if ($rawTradeDate === '') {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: missing trade_date"];
        continue;
    }

    // Source system validation
    $sourceKey = strtolower($rawSource);
    if (!isset($allowedSources[$sourceKey])) {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: invalid source_system '{$rawSource}' (must be Internal or BrokerA)"];
        continue;
    }
    $sourceSystem = $allowedSources[$sourceKey];

    // Side validation
    if (!in_array($side, $allowedSides, true)) {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: invalid side '{$side}' (must be BUY or SELL)"];
        continue;
    }

    // Quantity validation
    if (!is_numeric($rawQty) || (int)$rawQty <= 0) {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: quantity must be a positive integer"];
        continue;
    }
    $quantity = (int)$rawQty;

    // Price validation
    if (!is_numeric($rawPrice) || (float)$rawPrice <= 0) {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: price must be a positive number"];
        continue;
    }
    $price = (float)$rawPrice;

    // Date validation (must be YYYY-MM-DD)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTradeDate)) {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: trade_date must be YYYY-MM-DD, got '{$rawTradeDate}'"];
        continue;
    }
    $parts = explode('-', $rawTradeDate);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Row {$rowNum}: invalid calendar date '{$rawTradeDate}'"];
        continue;
    }
    $tradeDate = $rawTradeDate;

    // Duplicate check in DB & batch (Requirement 3)
    $dupKey = "{$extId}|{$sourceSystem}";
    if (isset($batchSeen[$dupKey])) {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Duplicate trade_id for this source"];
        continue;
    }

    $stmtCheckDup->execute([$extId, $sourceSystem]);
    if ((int)$stmtCheckDup->fetchColumn() > 0) {
        $rejected++;
        $errors[] = ['row' => $rowNum, 'reason' => "Duplicate trade_id for this source"];
        continue;
    }

    // Insert trade
    $tradeId = 't_' . bin2hex(random_bytes(4));
    $stmtInsert->execute([$tradeId, $extId, $sourceSystem, $symbol, $quantity, $price, $side, $tradeDate]);

    $batchSeen[$dupKey] = true;
    $imported++;
}

fclose($handle);

// Audit logging: ONE audit entry for the entire batch (Requirement 4)
$batchId = 'batch_' . bin2hex(random_bytes(4));
$auditDetails = "Imported {$imported} trade(s), {$rejected} rejected";
appendAuditLog($pdo, $user['name'], 'CSV_UPLOAD', 'TradeBatch', $batchId, $auditDetails);

jsonResponse([
    'ok'       => true,
    'imported' => $imported,
    'rejected' => $rejected,
    'errors'   => $errors
]);
