<?php
/**
 * API: Alokacja pozycji dokumentu kosztowego do projektu/etapu
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

$document_item_id = intval($_POST['document_item_id'] ?? 0);
$project_id = intval($_POST['project_id'] ?? 0);
$cost_node_id = !empty($_POST['cost_node_id']) ? intval($_POST['cost_node_id']) : null;
$amount = floatval($_POST['amount'] ?? 0);
$notes = trim($_POST['notes'] ?? '');

// Walidacja
if ($document_item_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy ID pozycji']);
    exit;
}
if ($project_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Wybierz projekt']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Kwota musi być większa od 0']);
    exit;
}

try {
    // Sprawdź czy pozycja istnieje i pobierz jej kwotę
    $stmt = $pdo->prepare("SELECT di.*, d.number as document_number 
                           FROM document_items di
                           JOIN documents d ON di.document_id = d.id
                           WHERE di.id = ?");
    $stmt->execute([$document_item_id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Pozycja nie istnieje']);
        exit;
    }
    
    // Sprawdź ile już zalokowano
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as allocated 
                           FROM document_item_allocations 
                           WHERE document_item_id = ?");
    $stmt->execute([$document_item_id]);
    $allocated = $stmt->fetchColumn();
    
    $available = $item['amount_net'] - $allocated;
    
    if ($amount > $available) {
        echo json_encode([
            'success' => false, 
            'error' => 'Kwota przekracza dostępną (' . number_format($available, 2) . ' PLN)'
        ]);
        exit;
    }
    
    // Zapisz alokację
    $stmt = $pdo->prepare("
        INSERT INTO document_item_allocations 
        (document_item_id, project_id, cost_node_id, amount, notes, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $document_item_id,
        $project_id,
        $cost_node_id,
        $amount,
        $notes ?: null,
        $_SESSION['user_id']
    ]);
    
    logEvent("Zalokowano pozycję faktury kosztowej (ID: {$document_item_id}, dokument: {$item['document_number']}) do projektu ID: {$project_id}, kwota: {$amount} PLN", 'INFO');
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    logEvent("Błąd alokacji pozycji: " . $e->getMessage(), 'ERROR');
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}

