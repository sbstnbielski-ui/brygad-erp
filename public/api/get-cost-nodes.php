<?php
require_once dirname(__DIR__) . '/config/autoload.php'; // 1 poziom w dół
startSecureSession();
requireLogin();

header('Content-Type: application/json');

$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    echo json_encode(['success' => true, 'nodes' => []]);
    exit;
}

try {
    $pdo = getDbConnection();
    
    $stmt = $pdo->prepare("
        SELECT id, parent_id, name, is_active
        FROM project_cost_nodes
        WHERE project_id = :project_id AND is_active = 1
        ORDER BY parent_id IS NULL DESC, sort_order, name
    ");
    $stmt->execute(['project_id' => $project_id]);
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'nodes' => $nodes]);
    
} catch (PDOException $e) {
    logEvent("Błąd pobierania etapów: " . $e->getMessage(), 'error');
    echo json_encode(['success' => false, 'nodes' => [], 'error' => 'Database error']);
}

