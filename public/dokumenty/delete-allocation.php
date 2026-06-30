<?php
/**
 * BRYGAD ERP - Usuwanie alokacji kosztu
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

$allocation_id = $_POST['allocation_id'] ?? 0;
$document_id = $_POST['document_id'] ?? 0;

if (!$allocation_id || !$document_id) {
    header("Location: " . url('dokumenty'));
    exit;
}

// Sprawdź czy dokument jest w statusie draft
$stmt = $pdo->prepare("
    SELECT d.status 
    FROM documents d
    INNER JOIN document_allocations da ON d.id = da.document_id
    WHERE da.id = ? AND d.id = ?
");
$stmt->execute([$allocation_id, $document_id]);
$result = $stmt->fetch();

if (!$result) {
    $_SESSION['error_message'] = "Alokacja nie została znaleziona";
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]));
    exit;
}

if ($result['status'] !== 'draft') {
    $_SESSION['error_message'] = "Nie można usunąć alokacji z zatwierdzonego dokumentu";
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]));
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM document_allocations WHERE id = ?");
    $stmt->execute([$allocation_id]);
    
    logEvent("Usunięto alokację ID: {$allocation_id} z dokumentu ID: {$document_id}", 'INFO');
    
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]) . '#allocations-container');
    exit;
} catch (PDOException $e) {
    logEvent("Błąd usuwania alokacji: " . $e->getMessage(), 'ERROR');
    $_SESSION['error_message'] = "Błąd podczas usuwania alokacji";
    header("Location: " . url('dokumenty.edit', ['id' => $document_id]));
    exit;
}

