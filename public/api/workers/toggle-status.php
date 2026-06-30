<?php
/**
 * BRYGAD ERP v3.0 - API: Toggle Worker Status
 * Zmiana statusu pracownika (aktywacja/dezaktywacja)
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może zmieniać status

$pdo = getDbConnection();

// Pobierz parametry
$workerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';
$returnTo = isset($_GET['return']) ? $_GET['return'] : 'list';

// Walidacja
if ($workerId <= 0 || !in_array($action, ['activate', 'deactivate'])) {
    logEvent("Nieprawidłowe parametry toggle-status: ID=$workerId, action=$action", 'WARNING');
    header('Location: ../../workers.php');
    exit;
}

// Pobierz pracownika
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, is_active FROM workers WHERE id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
    
    if (!$worker) {
        logEvent("Próba zmiany statusu nieistniejącego pracownika ID: $workerId", 'WARNING');
        header('Location: ../../workers.php');
        exit;
    }
} catch (PDOException $e) {
    logEvent("Błąd pobierania pracownika ID $workerId: " . $e->getMessage(), 'ERROR');
    header('Location: ../../workers.php');
    exit;
}

// Zmień status
$newStatus = ($action === 'activate') ? 1 : 0;

try {
    $stmt = $pdo->prepare("
        UPDATE workers 
        SET is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([$newStatus, $workerId]);
    
    $actionText = ($action === 'activate') ? 'aktywowano' : 'dezaktywowano';
    $workerName = $worker['first_name'] . ' ' . $worker['last_name'];
    
    logEvent("Pracownik $workerName (ID: $workerId) został $actionText", 'INFO');
    
    // Przekieruj
    if ($returnTo === 'edit') {
        header("Location: ../../edytuj-pracownika.php?id=$workerId&status=success");
    } else {
        header("Location: ../../workers.php?status=success&action=$actionText");
    }
    exit;
    
} catch (PDOException $e) {
    logEvent("Błąd zmiany statusu pracownika ID $workerId: " . $e->getMessage(), 'ERROR');
    
    if ($returnTo === 'edit') {
        header("Location: ../../edytuj-pracownika.php?id=$workerId&status=error");
    } else {
        header("Location: ../../workers.php?status=error");
    }
    exit;
}

