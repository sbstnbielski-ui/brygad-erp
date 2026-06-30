<?php
/**
 * BRYGAD ERP v3.0 - Usuwanie Dokumentu Kosztowego
 * 
 * Usuwa dokument wraz z wszystkimi powiązaniami:
 * - Pozycje dokumentu (document_items)
 * - Alokacje pozycji (document_item_allocations)
 * - Legacy alokacje (document_allocations)
 * - Plik dokumentu (jeśli istnieje)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$document_id = isset($_POST['document_id']) ? (int)$_POST['document_id'] : null;

if (!$document_id) {
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents']));
    exit;
}

try {
    // Pobierz dane dokumentu
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND type = 'invoice_cost'");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents', 'error' => 'not_found']));
        exit;
    }
    
    // Sprawdź powiązania
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM document_items WHERE document_id = ?) as items_count,
            (SELECT COUNT(*) FROM document_item_allocations dia 
             JOIN document_items di ON dia.document_item_id = di.id 
             WHERE di.document_id = ?) as item_allocations_count,
            (SELECT COUNT(*) FROM document_allocations WHERE document_id = ?) as legacy_allocations_count
    ");
    $stmt->execute([$document_id, $document_id, $document_id]);
    $usage = $stmt->fetch();
    
    $pdo->beginTransaction();
    
    // Usuń alokacje pozycji (przez document_items)
    if ($usage['item_allocations_count'] > 0) {
        $stmt = $pdo->prepare("
            DELETE dia FROM document_item_allocations dia
            INNER JOIN document_items di ON dia.document_item_id = di.id
            WHERE di.document_id = ?
        ");
        $stmt->execute([$document_id]);
        logEvent("Usunięto {$usage['item_allocations_count']} alokacji pozycji dla dokumentu {$document['number']}", 'WARNING');
    }
    
    // Usuń pozycje dokumentu
    if ($usage['items_count'] > 0) {
        $stmt = $pdo->prepare("DELETE FROM document_items WHERE document_id = ?");
        $stmt->execute([$document_id]);
        logEvent("Usunięto {$usage['items_count']} pozycji dla dokumentu {$document['number']}", 'WARNING');
    }
    
    // Usuń legacy alokacje
    if ($usage['legacy_allocations_count'] > 0) {
        $stmt = $pdo->prepare("DELETE FROM document_allocations WHERE document_id = ?");
        $stmt->execute([$document_id]);
        logEvent("Usunięto {$usage['legacy_allocations_count']} starych alokacji dla dokumentu {$document['number']}", 'WARNING');
    }
    
    // Usuń plik fizyczny jeśli istnieje
    if ($document['file_path'] && !empty($document['file_path'])) {
        $fullPath = dirname(__DIR__) . '/' . $document['file_path'];
        if (file_exists($fullPath)) {
            @unlink($fullPath);
            logEvent("Usunięto plik dokumentu: {$document['file_path']}", 'INFO');
        }
    }
    
    // Usuń dokument z bazy
    $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    
    $pdo->commit();
    
    $vendor_display = $document['vendor_id'] ? "Kontrahent ID: {$document['vendor_id']}" : "Źródło: {$document['source_name']}";
    logEvent("Usunięto dokument: {$document['number']} (ID: {$document_id}, {$vendor_display})", 'WARNING');
    
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents', 'success' => 'document_deleted']));
    exit;
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logEvent("Błąd usuwania dokumentu ID {$document_id}: " . $e->getMessage(), 'ERROR');
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents', 'error' => 'action_failed']));
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logEvent("Błąd ogólny usuwania dokumentu ID {$document_id}: " . $e->getMessage(), 'ERROR');
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents', 'error' => 'action_failed']));
    exit;
}
