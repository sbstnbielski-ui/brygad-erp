<?php
/**
 * API: Usuń pozycję z faktury sprzedażowej
 * POST: item_id
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

try {
    $pdo = getDbConnection();
    
    $item_id = $_POST['item_id'] ?? null;
    
    if (!$item_id) {
        echo json_encode(['success' => false, 'error' => 'Brak ID pozycji']);
        exit;
    }
    
    // Pobierz invoice_id i sprawdź status
    $stmt = $pdo->prepare("
        SELECT isi.invoice_id, inv.status
        FROM invoice_sale_items isi
        JOIN invoices_sale inv ON inv.id = isi.invoice_id
        WHERE isi.id = ?
    ");
    $stmt->execute([$item_id]);
    $row = $stmt->fetch();
    
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Pozycja nie istnieje']);
        exit;
    }
    
    if ($row['status'] !== 'draft') {
        echo json_encode(['success' => false, 'error' => 'Można edytować tylko faktury w statusie szkic']);
        exit;
    }
    
    $invoice_id = $row['invoice_id'];
    
    // Usuń pozycję
    $stmt = $pdo->prepare("DELETE FROM invoice_sale_items WHERE id = ?");
    $stmt->execute([$item_id]);
    
    // Przelicz sumy faktury
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(amount_net), 0) as total_net,
            COALESCE(SUM(amount_vat), 0) as total_vat,
            COALESCE(SUM(amount_gross), 0) as total_gross
        FROM invoice_sale_items
        WHERE invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $totals = $stmt->fetch();
    
    // Aktualizuj nagłówek faktury
    $stmt = $pdo->prepare("
        UPDATE invoices_sale 
        SET amount_net = ?, amount_vat = ?, amount_gross = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $totals['total_net'],
        $totals['total_vat'],
        $totals['total_gross'],
        $invoice_id
    ]);
    
    logEvent("Usunięto pozycję z faktury sprzedażowej ID: {$invoice_id}, item_id: {$item_id}", 'INFO');
    
    echo json_encode([
        'success' => true,
        'totals' => [
            'net' => $totals['total_net'],
            'vat' => $totals['total_vat'],
            'gross' => $totals['total_gross']
        ]
    ]);
    
} catch (PDOException $e) {
    logEvent("Błąd usuwania pozycji faktury: " . $e->getMessage(), 'ERROR');
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}

