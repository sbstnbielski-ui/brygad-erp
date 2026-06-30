<?php
/**
 * BRYGAD ERP - API Budżet Domowy - Bills
 * 
 * Zwraca listę rachunków dla danego okresu
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
    
    // Pobierz rachunki
    $stmt = $pdo->prepare("
        SELECT 
            bi.*,
            b.name as bill_name,
            b.amount_type
        FROM hb_bill_items bi
        JOIN hb_bills b ON b.id = bi.bill_id
        WHERE bi.household_id = ? AND bi.period = ?
        ORDER BY bi.due_date ASC, b.name ASC
    ");
    $stmt->execute([$householdId, $period]);
    $bills = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'period' => $period,
        'bills' => $bills
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log("API bills error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd pobierania rachunków'
    ], JSON_UNESCAPED_UNICODE);
}

