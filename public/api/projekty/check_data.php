<?php
/**
 * BRYGAD ERP - API sprawdzania danych projektu przed usunięciem
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json');

$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    echo json_encode(['error' => 'Brak ID projektu']);
    exit;
}

$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM work_logs WHERE project_id = :id) as work_logs,
            (SELECT COUNT(*) FROM worker_expenses WHERE project_id = :id) as expenses,
            (SELECT COUNT(*) FROM documents WHERE project_id = :id) as documents,
            (SELECT COUNT(*) FROM project_cost_nodes WHERE project_id = :id) as cost_nodes,
            (SELECT COUNT(*) FROM project_revenues WHERE project_id = :id) as revenues
    ");
    $stmt->execute(['id' => $project_id]);
    $data = $stmt->fetch();
    
    $has_data = ($data['work_logs'] > 0 || $data['expenses'] > 0 || 
                 $data['documents'] > 0 || $data['cost_nodes'] > 0 || 
                 $data['revenues'] > 0);
    
    echo json_encode([
        'success' => true,
        'has_data' => $has_data,
        'work_logs' => (int)$data['work_logs'],
        'expenses' => (int)$data['expenses'],
        'documents' => (int)$data['documents'],
        'cost_nodes' => (int)$data['cost_nodes'],
        'revenues' => (int)$data['revenues']
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Błąd bazy danych']);
}

