<?php
/**
 * BRYGAD ERP - Dodawanie alokacji kosztu
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . url('dokumenty'));
    exit;
}

$document_id = $_POST['document_id'] ?? 0;
$project_id = $_POST['project_id'] ?? 0;
$cost_node_id = $_POST['cost_node_id'] ?? null;
$category = $_POST['category'] ?? '';
$amount_net = $_POST['amount_net'] ?? 0;
$description = trim($_POST['description'] ?? '');

// Walidacja
if (!$document_id || !$project_id || !$category || !$amount_net) {
    $_SESSION['error_message'] = "Wszystkie wymagane pola muszą być wypełnione";
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]));
    exit;
}

// Sprawdź czy dokument istnieje i jest w statusie draft
$stmt = $pdo->prepare("SELECT status FROM documents WHERE id = ? AND type = 'invoice_cost'");
$stmt->execute([$document_id]);
$document = $stmt->fetch();

if (!$document) {
    $_SESSION['error_message'] = "Dokument nie został znaleziony";
    header("Location: " . url('dokumenty'));
    exit;
}

if ($document['status'] !== 'draft') {
    $_SESSION['error_message'] = "Nie można dodać alokacji do zatwierdzonego lub zarchiwizowanego dokumentu";
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]));
    exit;
}

// Walidacja kwoty
if (!is_numeric($amount_net) || $amount_net <= 0) {
    $_SESSION['error_message'] = "Kwota musi być liczbą dodatnią";
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]));
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO document_allocations (
            document_id, project_id, cost_node_id, category, amount_net, description
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $document_id,
        $project_id,
        $cost_node_id ?: null,
        $category,
        $amount_net,
        $description ?: null
    ]);
    
    logEvent("Dodano alokację do dokumentu ID: {$document_id}, projekt: {$project_id}, kwota: {$amount_net}", 'INFO');
    
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]) . '#allocations-container');
    exit;
} catch (PDOException $e) {
    logEvent("Błąd dodawania alokacji: " . $e->getMessage(), 'ERROR');
    $_SESSION['error_message'] = "Błąd podczas dodawania alokacji";
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]));
    exit;
}

