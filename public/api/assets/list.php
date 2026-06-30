<?php
/**
 * BRYGAD ERP - API Assets List
 * GET /api/assets/list
 * 
 * Zwraca listę zasobów (maszyn i aut) z podstawowymi danymi + najbliższy termin
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDbConnection();
    
    // Pobierz listę zasobów + najbliższy termin (planned event)
    $sql = "
        SELECT 
            a.id,
            a.type,
            a.name,
            a.reg_number,
            a.serial_number,
            a.usage_unit,
            a.current_usage,
            a.is_active,
            a.production_year,
            a.notes,
            (
                SELECT MIN(ae.due_date)
                FROM asset_events ae
                WHERE ae.asset_id = a.id 
                AND ae.status = 'planned'
                AND ae.due_date >= CURDATE()
            ) as next_due_date,
            (
                SELECT COUNT(*)
                FROM v_asset_alerts vaa
                WHERE vaa.asset_id = a.id 
                AND vaa.alert_level > 0
            ) as alerts_count
        FROM assets a
        WHERE 1=1
    ";
    
    $params = [];
    
    // Filtr statusu (GET ?status=active|inactive|all)
    $filterStatus = $_GET['status'] ?? 'active';
    if ($filterStatus === 'active') {
        $sql .= " AND a.is_active = 1";
    } elseif ($filterStatus === 'inactive') {
        $sql .= " AND a.is_active = 0";
    }
    
    // Filtr typu (GET ?type=car_passenger|excavator|...)
    if (!empty($_GET['type'])) {
        $sql .= " AND a.type = ?";
        $params[] = $_GET['type'];
    }
    
    // Wyszukiwanie po nazwie lub nr rejestracyjnym
    if (!empty($_GET['q'])) {
        $sql .= " AND (a.name LIKE ? OR a.reg_number LIKE ? OR a.serial_number LIKE ?)";
        $searchParam = '%' . $_GET['q'] . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY a.name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $assets,
        'count' => count($assets)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    logEvent("API assets/list error: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Błąd pobierania listy zasobów'
    ], JSON_UNESCAPED_UNICODE);
}

