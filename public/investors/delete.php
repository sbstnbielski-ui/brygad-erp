<?php
/**
 * BRYGAD ERP v3.0 - Usuwanie Kontrahenta
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('investors'));
    exit;
}

$pdo = getDbConnection();
$investorId = (int)($_POST['id'] ?? 0);

if ($investorId <= 0) {
    header('Location: ' . url('investors') . '?error=' . urlencode('Nieprawidłowe ID kontrahenta'));
    exit;
}

try {
    // Sprawdź czy kontrahent istnieje
    $stmt = $pdo->prepare("SELECT name FROM investors WHERE id = ?");
    $stmt->execute([$investorId]);
    $investor = $stmt->fetch();
    
    if (!$investor) {
        header('Location: ' . url('investors') . '?error=' . urlencode('Kontrahent nie istnieje'));
        exit;
    }
    
    // Sprawdź czy są powiązane projekty
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM projects WHERE investor_id = ?");
    $stmt->execute([$investorId]);
    $projectsCount = $stmt->fetch()['count'];
    
    if ($projectsCount > 0) {
        header('Location: ' . url('investors') . '?error=' . urlencode('Nie można usunąć kontrahenta - jest powiązany z ' . $projectsCount . ' projektami. Najpierw usuń lub zmień przypisanie projektów.'));
        exit;
    }
    
    // Sprawdź czy są faktury sprzedażowe
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM invoices_sale WHERE client_id = ?");
    $stmt->execute([$investorId]);
    $invoicesCount = $stmt->fetch()['count'];
    
    if ($invoicesCount > 0) {
        header('Location: ' . url('investors') . '?error=' . urlencode('Nie można usunąć kontrahenta - ma wystawione ' . $invoicesCount . ' faktur. Nie można usuwać kontrahentów z historią sprzedaży.'));
        exit;
    }
    
    // Usuń kontrahenta
    $stmt = $pdo->prepare("DELETE FROM investors WHERE id = ?");
    $stmt->execute([$investorId]);
    
    logEvent("Usunięto kontrahenta: {$investor['name']} (ID: {$investorId})", 'INFO');
    
    header('Location: ' . url('investors') . '?success=' . urlencode('Kontrahent został usunięty'));
    exit;
    
} catch (PDOException $e) {
    error_log("Błąd usuwania kontrahenta: " . $e->getMessage());
    header('Location: ' . url('investors') . '?error=' . urlencode('Błąd usuwania kontrahenta: ' . $e->getMessage()));
    exit;
}

