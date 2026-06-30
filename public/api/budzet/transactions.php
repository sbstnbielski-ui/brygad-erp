<?php
/**
 * BRYGAD ERP - API Budżet Domowy - Transactions
 * 
 * GET: Lista transakcji
 * POST: Dodaj transakcję
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/../../budzet/_guard.php';
require_once __DIR__ . '/../../budzet/_hb.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit = HB_CAN_EDIT;
$userId = $_SESSION['user_id'];

try {
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Lista transakcji
        $period = hb_period_from_request();
        
        $stmt = $pdo->prepare("
            SELECT 
                t.*,
                a.name as account_name,
                c.name as category_name,
                ta.name as transfer_account_name
            FROM hb_transactions t
            LEFT JOIN hb_accounts a ON a.id = t.account_id
            LEFT JOIN hb_categories c ON c.id = t.category_id
            LEFT JOIN hb_accounts ta ON ta.id = t.transfer_account_id
            WHERE t.household_id = ? AND t.period = ?
            ORDER BY t.date DESC, t.id DESC
        ");
        $stmt->execute([$householdId, $period]);
        $transactions = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'period' => $period,
            'transactions' => $transactions
        ], JSON_UNESCAPED_UNICODE);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Dodaj transakcję
        
        if (!$canEdit) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Brak uprawnień do edycji'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $direction = $input['direction'] ?? '';
        $accountId = (int)($input['account_id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0);
        $date = $input['date'] ?? '';
        $categoryId = !empty($input['category_id']) ? (int)$input['category_id'] : null;
        $transferAccountId = !empty($input['transfer_account_id']) ? (int)$input['transfer_account_id'] : null;
        $description = trim($input['description'] ?? '');
        
        // Walidacja
        if (!in_array($direction, ['income', 'expense', 'transfer'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ transakcji'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!$accountId || $amount <= 0 || !$date) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Brakujące dane'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $pdo->beginTransaction();
        
        // Oblicz period z daty
        $transactionPeriod = date('Y-m-01', strtotime($date));
        
        // Dla transferu: category_id musi być NULL
        if ($direction === 'transfer') {
            $categoryId = null;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO hb_transactions
            (household_id, account_id, transfer_account_id, direction, amount, 
             date, period, category_id, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $householdId,
            $accountId,
            $transferAccountId,
            $direction,
            $amount,
            $date,
            $transactionPeriod,
            $categoryId,
            $description,
            $userId
        ]);
        
        $transactionId = $pdo->lastInsertId();
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'transaction_id' => $transactionId
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Metoda niedozwolona'
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("API transactions error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd serwera'
    ], JSON_UNESCAPED_UNICODE);
}

