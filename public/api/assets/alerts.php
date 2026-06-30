<?php
/**
 * BRYGAD ERP - API Assets Alerts
 * GET /api/assets/alerts
 * 
 * Zwraca listę alertów z widoku v_asset_alerts (tylko alert_level > 0)
 * Logika alertów jest w DB, nie w PHP!
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDbConnection();
    
    // Pobierz alerty bezpośrednio z widoku SQL
    // Widok v_asset_alerts już liczy alert_level (2=po terminie, 1=nadchodzi, 0=ok)
    $sql = "
        SELECT 
            event_id,
            asset_id,
            asset_name,
            asset_type,
            event_category,
            title,
            due_date,
            status,
            days_left,
            alert_level
        FROM v_asset_alerts
        WHERE alert_level > 0
        ORDER BY alert_level DESC, due_date ASC
    ";
    
    $stmt = $pdo->query($sql);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $alerts,
        'count' => count($alerts)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    logEvent("API assets/alerts error: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd pobierania alertów'
    ], JSON_UNESCAPED_UNICODE);
}

