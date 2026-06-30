<?php
/**
 * BRYGAD ERP v3.0 - Usuwanie Kosztu Stałego
 * TYLKO DLA ADMINA
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$item_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$item_id) {
    $_SESSION['error'] = 'Nieprawidłowe ID kosztu.';
    header('Location: ' . url('finanse.koszty-stale'));
    exit;
}

try {
    // Pobierz dane kosztu
    $stmt = $pdo->prepare("SELECT * FROM finance_items WHERE id = ? AND item_type = 'FIXED_COST'");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        $_SESSION['error'] = 'Wydatek firmowy nie został znaleziony.';
        header('Location: ' . url('finanse.koszty-stale'));
        exit;
    }
    
    // Usuń koszt stały
    $stmt = $pdo->prepare("DELETE FROM finance_items WHERE id = ?");
    $stmt->execute([$item_id]);
    
    logEvent("Usunięto koszt stały ID {$item_id} ({$item['title']}, {$item['amount_gross']} PLN) przez user ID " . $_SESSION['user_id'], 'WARNING');
    
    $_SESSION['success'] = 'Wydatek firmowy został usunięty.';
    header('Location: ' . url('finanse.koszty-stale'));
    exit;
    
} catch (PDOException $e) {
    logEvent("Błąd usuwania kosztu stałego ID {$item_id}: " . $e->getMessage(), 'ERROR');
    $_SESSION['error'] = 'Błąd usuwania kosztu stałego.';
    header('Location: ' . url('finanse.koszty-stale'));
    exit;
}

