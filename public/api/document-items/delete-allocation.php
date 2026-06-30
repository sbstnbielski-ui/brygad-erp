<?php
/**
 * API: Usuwanie alokacji pozycji dokumentu kosztowego
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona']);
    exit;
}

$pdo = getDbConnection();

$input = json_decode(file_get_contents('php://input'), true);
$allocation_id = intval($input['allocation_id'] ?? 0);

if ($allocation_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy ID alokacji']);
    exit;
}

try {
    // Pobierz informacje o alokacji przed usunięciem (dla loga)
    $stmt = $pdo->prepare("
        SELECT dia.*, di.item_name, d.number as document_number
        FROM document_item_allocations dia
        JOIN document_items di ON dia.document_item_id = di.id
        JOIN documents d ON di.document_id = d.id
        WHERE dia.id = ?
    ");
    $stmt->execute([$allocation_id]);
    $alloc = $stmt->fetch();
    
    if (!$alloc) {
        echo json_encode(['success' => false, 'error' => 'Alokacja nie istnieje']);
        exit;
    }
    
    // Usuń alokację
    $stmt = $pdo->prepare("DELETE FROM document_item_allocations WHERE id = ?");
    $stmt->execute([$allocation_id]);
    
    logEvent("Usunięto alokację pozycji faktury (ID: {$allocation_id}, pozycja: {$alloc['item_name']}, dokument: {$alloc['document_number']})", 'INFO');
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    logEvent("Błąd usuwania alokacji: " . $e->getMessage(), 'ERROR');
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}

