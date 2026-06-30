<?php
/**
 * BRYGAD ERP v3.0 - Akcje notatek kontrahenta (POST only)
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$pdo = getDbConnection();
$action = $_POST['action'] ?? '';
$investorId = (int)($_POST['investor_id'] ?? 0);

if (!$investorId) {
    header("Location: " . url('investors'));
    exit;
}

$redirectBack = url('investors.show', ['id' => $investorId]) . '#notatki';

switch ($action) {
    case 'add':
        $note = trim($_POST['note'] ?? '');
        if ($note === '') {
            header("Location: " . $redirectBack . '&error=' . urlencode('Treść notatki nie może być pusta'));
            exit;
        }
        $stmt = $pdo->prepare("
            INSERT INTO investor_notes (investor_id, note, created_by)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$investorId, $note, $_SESSION['worker_id'] ?? 0]);
        logEvent("Dodano notatkę do kontrahenta ID {$investorId}", 'INFO');
        header("Location: " . $redirectBack . '&success=' . urlencode('Notatka dodana'));
        exit;

    case 'delete':
        $noteId = (int)($_POST['note_id'] ?? 0);
        if (!$noteId) {
            header("Location: " . $redirectBack);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM investor_notes WHERE id = ? AND investor_id = ?");
        $stmt->execute([$noteId, $investorId]);
        logEvent("Usunięto notatkę ID {$noteId} kontrahenta ID {$investorId}", 'INFO');
        header("Location: " . $redirectBack . '&success=' . urlencode('Notatka usunięta'));
        exit;

    default:
        header("Location: " . $redirectBack);
        exit;
}
