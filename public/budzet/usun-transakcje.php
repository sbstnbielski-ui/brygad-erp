<?php
/**
 * BRYGAD ERP - Budżet Domowy - Usuń transakcję
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';

if (!HB_CAN_EDIT) {
    die('Brak uprawnień.');
}

$pdo           = getDbConnection();
$householdId   = HB_HOUSEHOLD_ID;
$currentUserId = (int)$_SESSION['user_id'];
$isOwner       = defined('HB_IS_OWNER') && HB_IS_OWNER;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . url('budzet.transakcje'));
    exit;
}

$transactionId = (int)($_POST['id'] ?? 0);
$backPeriod    = preg_replace('/[^0-9\-]/', '', $_POST['back_period'] ?? '');

if ($transactionId <= 0) {
    header('Location: ' . url('budzet.transakcje', ['period' => $backPeriod]));
    exit;
}

// Pobierz transakcję i sprawdź przynależność
$stmt = $pdo->prepare("SELECT id, household_id, created_by, owner_user_id FROM hb_transactions WHERE id = ?");
$stmt->execute([$transactionId]);
$tx = $stmt->fetch();

if (!$tx || (int)$tx['household_id'] !== (int)$householdId) {
    header('Location: ' . url('budzet.transakcje', ['period' => $backPeriod]) . '&err=not_found');
    exit;
}

// Każdy z canEdit może usunąć transakcję swojego household
// (privacy jest już pilnowana przez to, że widzisz tylko swoje transakcje)

try {
    $stmt = $pdo->prepare("DELETE FROM hb_transactions WHERE id = ? AND household_id = ?");
    $stmt->execute([$transactionId, $householdId]);
    logEvent("Usunięto transakcję ID: $transactionId (household: $householdId, by user: $currentUserId)");
    header('Location: ' . url('budzet.transakcje', ['period' => $backPeriod]) . '&success=deleted');
} catch (PDOException $e) {
    error_log("Delete transaction error: " . $e->getMessage());
    header('Location: ' . url('budzet.transakcje', ['period' => $backPeriod]) . '&err=delete_failed');
}
exit;

