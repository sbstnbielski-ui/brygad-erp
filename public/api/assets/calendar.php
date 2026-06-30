<?php
/**
 * BRYGAD ERP - API Assets Calendar (Hybrid)
 * GET /api/assets/calendar?from=YYYY-MM-DD&to=YYYY-MM-DD
 * 
 * Zwraca eventy hybrydowe (UNION):
 * - asset_events (Maintenance)
 * - asset_bookings (Work)
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDbConnection();
    
    // Parametry z GET
    $from = $_GET['from'] ?? date('Y-m-01'); // Domyślnie: 1. dzień miesiąca
    $to = $_GET['to'] ?? date('Y-m-t');       // Domyślnie: ostatni dzień miesiąca
    
    // Walidacja dat
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        throw new Exception('Nieprawidłowy format dat');
    }
    
    // UNION: asset_events + asset_bookings
    $sql = "
        SELECT 
            ae.id,
            ae.asset_id,
            a.name as asset_name,
            a.type as asset_type,
            ae.title,
            ae.due_date as start,
            ae.due_date as end,
            'maintenance' as kind,
            ae.status,
            ae.event_category,
            NULL as worker_name,
            NULL as project_name
        FROM asset_events ae
        JOIN assets a ON a.id = ae.asset_id
        WHERE ae.due_date BETWEEN ? AND ?
        
        UNION ALL
        
        SELECT 
            ab.id,
            ab.asset_id,
            a.name as asset_name,
            a.type as asset_type,
            COALESCE(ab.description, CONCAT('Rezerwacja: ', ab.customer_name)) as title,
            DATE(ab.start_date) as start,
            DATE(ab.end_date) as end,
            'work' as kind,
            ab.status,
            NULL as event_category,
            CONCAT(w.first_name, ' ', w.last_name) as worker_name,
            p.name as project_name
        FROM asset_bookings ab
        JOIN assets a ON a.id = ab.asset_id
        LEFT JOIN workers w ON w.id = ab.worker_id
        LEFT JOIN projects p ON p.id = ab.project_id
        WHERE DATE(ab.start_date) <= ? AND DATE(ab.end_date) >= ?
        
        ORDER BY start ASC, kind DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$from, $to, $to, $from]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $events,
        'count' => count($events),
        'period' => ['from' => $from, 'to' => $to]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    logEvent("API assets/calendar error: " . $e->getMessage(), 'ERROR');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

