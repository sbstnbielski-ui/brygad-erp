<?php
/**
 * API: Pobierz pozycje faktury sprzedażowej
 * GET: invoice_id
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDbConnection();
    
    $invoice_id = $_GET['invoice_id'] ?? null;
    
    if (!$invoice_id) {
        echo json_encode(['success' => false, 'error' => 'Brak ID faktury']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT isi.* FROM invoice_sale_items isi WHERE isi.invoice_id = ? ORDER BY isi.sort_order, isi.id");
    $stmt->execute([$invoice_id]);
    $items = $stmt->fetchAll();

    $allocStmt = $pdo->prepare("
        SELECT a.id, a.project_id, p.name AS project_name, a.cost_node_id,
               pcn.name AS node_name, a.amount_net, a.description
        FROM invoice_sale_allocations a
        JOIN projects p ON p.id = a.project_id
        LEFT JOIN project_cost_nodes pcn ON pcn.id = a.cost_node_id
        WHERE a.invoice_id = ? ORDER BY a.id
    ");
    $allocStmt->execute([$invoice_id]);
    $allocations = $allocStmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'items' => $items,
        'allocations' => $allocations
    ]);
    
} catch (PDOException $e) {
    logEvent("Błąd pobierania pozycji faktury: " . $e->getMessage(), 'ERROR');
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}

