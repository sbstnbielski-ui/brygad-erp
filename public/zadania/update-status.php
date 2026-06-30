<?php
/**
 * SPRUTEX - Zmiana Statusu Zadania (Pracownik)
 */
require_once dirname(__DIR__) . '/config/autoload.php'; // 1 poziom w dół
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$newStatus = $_GET['status'] ?? '';
$workerId = $_SESSION['worker_id'] ?? 0;

if ($assignmentId > 0 && in_array($newStatus, ['todo', 'doing', 'done']) && $workerId > 0) {
    try {
        // Sprawdź czy to przypisanie pracownika
        $stmt = $pdo->prepare("SELECT worker_id FROM task_assignments WHERE id = ?");
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch();
        
        if ($assignment && $assignment['worker_id'] == $workerId) {
            // Zmień status
            if ($newStatus === 'done') {
                $stmt = $pdo->prepare("
                    UPDATE task_assignments 
                    SET status = 'done', completed_at = NOW()
                    WHERE id = ?
                ");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE task_assignments 
                    SET status = ?
                    WHERE id = ?
                ");
            }
            
            if ($newStatus === 'done') {
                $stmt->execute([$assignmentId]);
            } else {
                $stmt->execute([$newStatus, $assignmentId]);
            }
            
            logEvent("Zmiana statusu zadania ID $assignmentId na $newStatus", 'INFO');
        }
    } catch (PDOException $e) {
        logEvent("Błąd zmiany statusu: " . $e->getMessage(), 'ERROR');
    }
}

header('Location: zadania-moje.php');
exit;

