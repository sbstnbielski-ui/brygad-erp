<?php
/**
 * BRYGAD ERP v3.0 - Usuwanie Faktury
 * 
 * Usuwa fakturę wraz z wszystkimi powiązaniami:
 * - Alokacje do projektów (cost_allocations)
 * - Plik faktury (jeśli istnieje)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$invoice_id = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;

if (!$invoice_id) {
    header("Location: " . url('finanse.faktury'));
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        header("Location: " . url('finanse.faktury', ['error' => 'not_found']));
        exit;
    }

    logEvent("Zablokowano twarde usunięcie faktury kosztowej: {$invoice['number']} (ID: {$invoice_id})", 'WARNING');
    $_SESSION['error'] = 'Twarde usuwanie dokumentów księgowych jest zablokowane. Użyj korekty/anulowania lub ręcznej procedury audytowej.';
    header("Location: " . url('finanse.faktury', ['error' => 'delete_blocked']));
    exit;

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logEvent("Błąd usuwania faktury ID {$invoice_id}: " . $e->getMessage(), 'ERROR');
    header("Location: " . url('finanse.faktury', ['error' => 'delete_failed']));
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logEvent("Błąd ogólny usuwania faktury ID {$invoice_id}: " . $e->getMessage(), 'ERROR');
    header("Location: " . url('finanse.faktury', ['error' => 'delete_failed']));
    exit;
}
