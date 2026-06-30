<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$docId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($docId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT worker_id FROM worker_documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        
        if ($doc) {
            $stmt = $pdo->prepare("
                UPDATE worker_documents 
                SET status = 'archived', archived_at = NOW(), archived_reason = 'Archiwizacja ręczna', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$docId]);
            
            logEvent("Archiwizowano dokument ID $docId", 'INFO');
            
            header("Location: dokumenty-pracownika.php?worker_id=" . $doc['worker_id']);
            exit;
        }
    } catch (PDOException $e) {
        logEvent("Błąd archiwizacji: " . $e->getMessage(), 'ERROR');
    }
}

header('Location: workers.php');
exit;


