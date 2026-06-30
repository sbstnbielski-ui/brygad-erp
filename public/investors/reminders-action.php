<?php
/**
 * BRYGAD ERP v3.0 - Akcje przypomnień kontrahenta (POST only)
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

$redirectBack = url('investors.show', ['id' => $investorId]) . '#przypomnienia';

switch ($action) {
    case 'add':
        $title = trim($_POST['title'] ?? '');
        $remindAt = trim($_POST['remind_at'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if ($title === '' || $remindAt === '') {
            header("Location: " . $redirectBack . '&error=' . urlencode('Tytuł i data przypomnienia są wymagane'));
            exit;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $remindAt)) {
            header("Location: " . $redirectBack . '&error=' . urlencode('Nieprawidłowy format daty'));
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO investor_reminders (investor_id, remind_at, title, note, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$investorId, $remindAt, $title, $note ?: null, $_SESSION['worker_id'] ?? 0]);
        logEvent("Dodano przypomnienie do kontrahenta ID {$investorId}: {$title}", 'INFO');
        header("Location: " . $redirectBack . '&success=' . urlencode('Przypomnienie dodane'));
        exit;

    case 'done':
        $remId = (int)($_POST['reminder_id'] ?? 0);
        if (!$remId) {
            header("Location: " . $redirectBack);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE investor_reminders SET is_done = 1, done_at = NOW() WHERE id = ? AND investor_id = ?");
        $stmt->execute([$remId, $investorId]);
        logEvent("Oznaczono przypomnienie ID {$remId} kontrahenta ID {$investorId} jako wykonane", 'INFO');
        header("Location: " . $redirectBack . '&success=' . urlencode('Przypomnienie oznaczone jako wykonane'));
        exit;

    case 'delete':
        $remId = (int)($_POST['reminder_id'] ?? 0);
        if (!$remId) {
            header("Location: " . $redirectBack);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM investor_reminders WHERE id = ? AND investor_id = ?");
        $stmt->execute([$remId, $investorId]);
        logEvent("Usunięto przypomnienie ID {$remId} kontrahenta ID {$investorId}", 'INFO');
        header("Location: " . $redirectBack . '&success=' . urlencode('Przypomnienie usunięte'));
        exit;

    default:
        header("Location: " . $redirectBack);
        exit;
}
