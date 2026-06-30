<?php
/**
 * BRYGAD ERP - Archiwizacja dokumentu
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents']));
    exit;
}

$document_id = $_POST['document_id'] ?? 0;

if (!$document_id) {
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents']));
    exit;
}

// Sprawdź czy dokument istnieje
$stmt = $pdo->prepare("SELECT id, number, status FROM documents WHERE id = ? AND type = 'invoice_cost'");
$stmt->execute([$document_id]);
$document = $stmt->fetch();

if (!$document) {
    $_SESSION['error_message'] = "Dokument nie został znaleziony";
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents', 'error' => 'not_found']));
    exit;
}

if ($document['status'] === 'archived') {
    $_SESSION['error_message'] = "Dokument jest już zarchiwizowany";
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents']));
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE documents SET status = 'archived' WHERE id = ?");
    $stmt->execute([$document_id]);
    
    logEvent("Zarchiwizowano dokument: {$document['number']} (ID: {$document_id})", 'INFO');
    
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents', 'success' => 'document_archived']));
    exit;
} catch (PDOException $e) {
    logEvent("Błąd archiwizacji dokumentu: " . $e->getMessage(), 'ERROR');
    $_SESSION['error_message'] = "Błąd podczas archiwizacji dokumentu";
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]));
    exit;
}
