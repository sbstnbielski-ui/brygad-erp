<?php
/**
 * SPRUTEX - Archiwizacja Zadania (Admin)
 */
require_once dirname(__DIR__) . '/config/autoload.php'; // 1 poziom w dół
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($taskId > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE tasks SET is_active = 0 WHERE id = ?");
        $stmt->execute([$taskId]);
        
        logEvent("Zarchiwizowano zadanie ID $taskId", 'INFO');
    } catch (PDOException $e) {
        logEvent("Błąd archiwizacji: " . $e->getMessage(), 'ERROR');
    }
}

header('Location: zadania-admin.php');
exit;

