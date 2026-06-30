<?php
/**
 * BRYGAD ERP - API Finanse Właściciela
 * 
 * Endpoint zwracający dane KPI właściciela dla wybranego okresu
 * 
 * LOGIKA KPI:
 * - CompanyNet = CompanyIncome - CompanyCosts
 * - HomeNet = HomeIncome - HomeExpenses
 * - OwnerNet = CompanyNet + HomeNet
 * 
 * ŹRÓDŁA DANYCH:
 * - Koszty firmy: view_finance_ledger (filtr po date)
 * - Przychody firmy: project_revenues (amount_net), filtr po signed_date
 * - Budżet domowy: hb_transactions (direction income/expense), filtr po date
 * 
 * @param date_from YYYY-MM-DD (data początkowa)
 * @param date_to YYYY-MM-DD (data końcowa)
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// Parametry wejściowe: date_from i date_to
$dateFrom = $_GET['date_from'] ?? null;
$dateTo = $_GET['date_to'] ?? null;

if (!$dateFrom || !$dateTo) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Wymagane parametry: date_from, date_to (format: YYYY-MM-DD)'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Walidacja formatu dat
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Nieprawidłowy format daty. Wymagany: YYYY-MM-DD'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ===================================================================
    // 1. KOSZTY FIRMY (Company Costs)
    // Źródło: view_finance_ledger, filtr po date (zakres)
    // ===================================================================
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM view_finance_ledger
        WHERE date BETWEEN :from AND :to
    ");
    $stmt->execute([
        ':from' => $dateFrom,
        ':to' => $dateTo
    ]);
    $companyCosts = (float)$stmt->fetchColumn();
    
    // ===================================================================
    // 2. PRZYCHODY FIRMY (Company Income)
    // Źródło: project_revenues (amount_net), filtr po signed_date w miesiącu
    // ===================================================================
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_net), 0) AS total
        FROM project_revenues
        WHERE signed_date BETWEEN :from AND :to
    ");
    $stmt->execute([
        ':from' => $dateFrom,
        ':to' => $dateTo
    ]);
    $companyIncome = (float)$stmt->fetchColumn();
    
    // Oblicz wynik firmy
    $companyNet = $companyIncome - $companyCosts;
    
    // ===================================================================
    // 3. BUDŻET DOMOWY (Home Budget)
    // Źródło: hb_transactions, filtr po date (zakres)
    // Zakładamy household_id = 1 (można rozszerzyć o pobieranie z sesji)
    // ===================================================================
    $householdId = 1; // TODO: Pobierać z sesji/konfiguracji jeśli potrzeba
    
    // Przychody domowe
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM hb_transactions
        WHERE household_id = :household_id
          AND date BETWEEN :from AND :to
          AND direction = 'income'
    ");
    $stmt->execute([
        ':household_id' => $householdId,
        ':from' => $dateFrom,
        ':to' => $dateTo
    ]);
    $homeIncome = (float)$stmt->fetchColumn();
    
    // Wydatki domowe
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM hb_transactions
        WHERE household_id = :household_id
          AND date BETWEEN :from AND :to
          AND direction = 'expense'
    ");
    $stmt->execute([
        ':household_id' => $householdId,
        ':from' => $dateFrom,
        ':to' => $dateTo
    ]);
    $homeExpenses = (float)$stmt->fetchColumn();
    
    // Oblicz wynik domu
    $homeNet = $homeIncome - $homeExpenses;
    
    // ===================================================================
    // 4. WYNIK ŁĄCZNY WŁAŚCICIELA (Owner Net)
    // ===================================================================
    $ownerNet = $companyNet + $homeNet;
    
    // ===================================================================
    // ODPOWIEDŹ JSON
    // ===================================================================
    $response = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'company' => [
            'income' => $companyIncome,
            'costs' => $companyCosts,
            'net' => $companyNet
        ],
        'home' => [
            'income' => $homeIncome,
            'expenses' => $homeExpenses,
            'net' => $homeNet
        ],
        'owner' => [
            'net' => $ownerNet
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    error_log("API Finanse Właściciela error: " . $e->getMessage());
    error_log("API Finanse Właściciela SQL state: " . $e->getCode());
    error_log("API Finanse Właściciela trace: " . $e->getTraceAsString());
    http_response_code(500);
    
    echo json_encode([
        'error' => 'Błąd bazy danych',
        'debug_message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

