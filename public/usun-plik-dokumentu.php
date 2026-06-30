<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$docId = isset($_GET['doc_id']) ? (int)$_GET['doc_id'] : 0;

if ($fileId > 0 && $docId > 0) {
    try {
        // Pobierz ścieżkę pliku
        $stmt = $pdo->prepare("SELECT file_path FROM worker_document_files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        if ($file) {
            // Usuń plik fizyczny
            $fullPath = PUBLIC_PATH . '/' . $file['file_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            
            // Usuń rekord z bazy
            $stmt = $pdo->prepare("DELETE FROM worker_document_files WHERE id = ?");
            $stmt->execute([$fileId]);
            
            logEvent("Usunięto plik dokumentu ID $fileId", 'INFO');
        }
    } catch (PDOException $e) {
        logEvent("Błąd usuwania pliku: " . $e->getMessage(), 'ERROR');
    }
}

header("Location: edytuj-dokument.php?id=$docId");
exit;


