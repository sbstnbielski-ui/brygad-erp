<?php
/**
 * BRYGAD ERP - API Budżet Domowy - Overview
 * 
 * Zwraca dane dla dashboardu: kafle, rachunki do zapłaty, top kategorie
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/../../budzet/_guard.php';
require_once __DIR__ . '/../../budzet/_hb.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;

// Pobierz okres
$period = hb_period_from_request();

try {
    // Zapewnij istnienie rachunków
    hb_ensure_bill_items($householdId, $period);
    
    // Kafle
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM hb_transactions
        WHERE household_id = ? AND period = ? AND direction = 'income'
    ");
    $stmt->execute([$householdId, $period]);
    $income = $stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM hb_transactions
        WHERE household_id = ? AND period = ? AND direction = 'expense'
    ");
    $stmt->execute([$householdId, $period]);
    $expense = $stmt->fetch()['total'];
    
    $balance = $income - $expense;
    
    // Rachunki do zapłaty
    $stmt = $pdo->prepare("
        SELECT bi.*, b.name as bill_name
        FROM hb_bill_items bi
        JOIN hb_bills b ON b.id = bi.bill_id
        WHERE bi.household_id = ? AND bi.period = ? 
          AND bi.status IN ('unpaid', 'partial')
        ORDER BY bi.due_date ASC
    ");
    $stmt->execute([$householdId, $period]);
    $billsDue = $stmt->fetchAll();
    
    // Top 5 kategorii wydatków
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            COALESCE(SUM(t.amount), 0) as total
        FROM hb_categories c
        LEFT JOIN hb_transactions t ON t.category_id = c.id 
            AND t.household_id = ? 
            AND t.period = ? 
            AND t.direction = 'expense'
        WHERE c.household_id = ? AND c.type = 'expense' AND c.is_active = 1
        GROUP BY c.id, c.name
        HAVING total > 0
        ORDER BY total DESC
        LIMIT 5
    ");
    $stmt->execute([$householdId, $period, $householdId]);
    $topCategories = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'period' => $period,
        'tiles' => [
            'income' => (float)$income,
            'expense' => (float)$expense,
            'balance' => (float)$balance
        ],
        'bills_due' => $billsDue,
        'top_categories' => $topCategories
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("API overview error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd pobierania danych'
    ], JSON_UNESCAPED_UNICODE);
}

