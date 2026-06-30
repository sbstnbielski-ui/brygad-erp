<?php
/**
 * BRYGAD ERP - Zaliczki pracownicze - Usuwanie
 * 
 * Usuwa zaliczkę wraz z WSZYSTKIMI rozliczeniami w ledger (nawet zamknięte)
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: " . url('finanse.zaliczki'));
    exit;
}

$advanceId = isset($_POST['advance_id']) ? (int)$_POST['advance_id'] : 0;

if ($advanceId <= 0) {
    header("Location: " . url('finanse.zaliczki') . '?error=invalid_id');
    exit;
}

try {
    // Sprawdź czy zaliczka istnieje
    $stmt = $pdo->prepare("SELECT * FROM worker_advances WHERE id = ?");
    $stmt->execute([$advanceId]);
    $advance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$advance) {
        header("Location: " . url('finanse.zaliczki') . '?error=not_found');
        exit;
    }

    $advanceTransferMeta = walletTransferMetaFromDescription((string)($advance['description'] ?? ''));
    if (($advanceTransferMeta['transfer_role'] ?? '') !== 'target') {
        $advanceTransferMeta = [];
    }
    
    // Usuń zaliczkę wraz z WSZYSTKIMI powiązaniami
    throw new RuntimeException('Twarde usuwanie zaliczek i ledgerów jest zablokowane. Użyj korekty księgowej zamiast kasowania historii portfela.');

    $pdo->beginTransaction();

    if (!empty($advanceTransferMeta)) {
        walletDeleteTransferByTargetAdvance($pdo, $advanceId);
        $deletedFundingEntries = 0;
        $deletedLedgerEntries = 0;
    } else {
        // Usuń finansowanie portfela (na wypadek braku FK CASCADE)
        $stmt = $pdo->prepare("DELETE FROM worker_wallet_funding WHERE advance_id = ?");
        $stmt->execute([$advanceId]);
        $deletedFundingEntries = $stmt->rowCount();

        // Najpierw usuń wpisy z worker_ledger (na wypadek gdyby CASCADE nie działał)
        $stmt = $pdo->prepare("SELECT 1");
        $stmt->execute([$advanceId]);
        $deletedLedgerEntries = $stmt->rowCount();
        
        // Usuń pliki zaliczki
        $stmt = $pdo->prepare("DELETE FROM worker_advance_files WHERE advance_id = ?");
        $stmt->execute([$advanceId]);
        
        // Usuń samą zaliczkę
        $stmt = $pdo->prepare("DELETE FROM worker_advances WHERE id = ?");
        $stmt->execute([$advanceId]);
    }
    
    $pdo->commit();
    
    logEvent("Usunięto zaliczkę ID $advanceId wraz z $deletedLedgerEntries wpisami w ledger i $deletedFundingEntries wpisami finansowania", 'INFO');
    
    header("Location: " . url('finanse.zaliczki') . '?success=deleted');
    exit;
    
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error deleting advance: " . $e->getMessage());
    logEvent("Błąd usuwania zaliczki ID $advanceId: " . $e->getMessage(), 'ERROR');
    header("Location: " . url('finanse.zaliczki') . '?error=delete_failed');
    exit;
}
