<?php
/**
 * BRYGAD ERP - Edycja pojedynczego ruchu portfela firmowego
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

function walletLedgerFallbackUrl(): string
{
    return url('hr.workers.wallet');
}

function walletLedgerSafeReturnUrl($candidate): string
{
    if (!is_string($candidate) || $candidate === '') {
        return walletLedgerFallbackUrl();
    }

    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return walletLedgerFallbackUrl();
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return walletLedgerFallbackUrl();
    }

    $allowedPaths = [
        '/hr/workers/wallet.php',
        rtrim((string)parse_url(url('hr.workers.wallet'), PHP_URL_PATH), '/'),
    ];

    if (!in_array(rtrim($path, '/'), array_unique($allowedPaths), true)) {
        return walletLedgerFallbackUrl();
    }

    return $candidate;
}

function walletLedgerRedirectWithFlash(string $returnUrl, string $flash): void
{
    $sep = strpos($returnUrl, '?') === false ? '?' : '&';
    header('Location: ' . $returnUrl . $sep . 'wallet_flash=' . urlencode($flash));
    exit;
}

function walletLedgerRecalculateAdvanceStatus(PDO $pdo, int $advanceId): void
{
    $stmt = $pdo->prepare("SELECT id, amount FROM worker_advances WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$advanceId]);
    $advance = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$advance) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0)
        FROM worker_ledger
        WHERE advance_id = ?
    ");
    $stmt->execute([$advanceId]);
    $settled = (float)$stmt->fetchColumn();
    $newStatus = ((float)$advance['amount'] - $settled) <= 0.01 ? 'closed' : 'open';

    $stmt = $pdo->prepare("UPDATE worker_advances SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $advanceId]);
}

function walletLedgerLabel(string $entryType): string
{
    return [
        'EXPENSE_DOC' => 'Wydatek z dokumentu',
        'MANUAL_COST' => 'Wydatek ręczny',
        'CASH_RETURN' => 'Zwrot / transfer',
        'SETTLEMENT_DEDUCTION' => 'Potrącenie',
    ][$entryType] ?? $entryType;
}

function walletLedgerSyncReturnFunding(PDO $pdo, array $entry, float $amount, string $entryDate, string $description): void
{
    if (($entry['entry_type'] ?? '') !== 'CASH_RETURN') {
        return;
    }
    if (!str_starts_with((string)($entry['description'] ?? ''), 'Zwrot gotówki')) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM worker_wallet_funding
        WHERE advance_id = ?
          AND direction = 'IN_RETURN'
          AND ABS(amount - ?) < 0.01
          AND movement_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        (int)$entry['advance_id'],
        abs((float)$entry['amount']),
        (string)$entry['entry_date'],
    ]);
    $fundingId = (int)($stmt->fetchColumn() ?: 0);
    if ($fundingId <= 0) {
        return;
    }

    $note = $description !== '' ? $description : 'Zwrot gotówki z portfela';
    $stmt = $pdo->prepare("
        UPDATE worker_wallet_funding
        SET amount = ?,
            movement_date = ?,
            note = ?
        WHERE id = ?
    ");
    $stmt->execute([abs($amount), $entryDate, $note, $fundingId]);
}

$returnUrl = walletLedgerSafeReturnUrl($_GET['return_url'] ?? $_POST['return_url'] ?? null);
$ledgerId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);

if ($ledgerId <= 0) {
    walletLedgerRedirectWithFlash($returnUrl, 'error');
}

$entry = null;
try {
    $stmt = $pdo->prepare("
        SELECT
            wl.*,
            wa.type AS advance_type,
            wa.amount AS advance_amount,
            w.first_name,
            w.last_name,
            we.id AS linked_expense_id,
            we.project_id AS expense_project_id
        FROM worker_ledger wl
        INNER JOIN worker_advances wa ON wa.id = wl.advance_id
        INNER JOIN workers w ON w.id = wl.worker_id
        LEFT JOIN worker_expenses we ON we.id = wl.expense_id
        WHERE wl.id = ?
          AND wa.type = 'COMPANY'
        LIMIT 1
    ");
    $stmt->execute([$ledgerId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logEvent('Wallet ledger edit fetch error: ' . $e->getMessage(), 'ERROR');
}

if (!$entry || (string)$entry['entry_type'] === 'ADVANCE') {
    walletLedgerRedirectWithFlash($returnUrl, 'error');
}

$transferMeta = walletTransferMetaFromDescription((string)($entry['description'] ?? ''));
if (($transferMeta['transfer_role'] ?? '') === 'source' && ($transferMeta['transfer_ref'] ?? '') !== '') {
    $targetAdvance = walletFindTransferTargetAdvance($pdo, (string)$transferMeta['transfer_ref']);
    if (!$targetAdvance) {
        walletLedgerRedirectWithFlash($returnUrl, 'error');
    }
    header('Location: ' . url('hr.settlements.edit', [
        'source' => 'advance',
        'id' => (int)$targetAdvance['id'],
        'return_to' => $returnUrl,
    ]));
    exit;
}

$amount = (float)$entry['amount'];
$entryDate = (string)$entry['entry_date'];
$description = walletStripMeta((string)($entry['description'] ?? ''));
$workerNameIds = walletExtractWorkerIdsFromDescriptions([$description]);
$workerNamesById = !empty($workerNameIds) ? walletGetWorkerDisplayNames($pdo, $workerNameIds) : [];
$description = walletHumanizeDescription($description, $workerNamesById);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)str_replace(',', '.', str_replace(' ', '', trim((string)($_POST['amount'] ?? '0'))));
    $entryDate = trim((string)($_POST['entry_date'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));

    if ($amount <= 0) {
        $errors[] = 'Kwota musi być większa od 0.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
        $errors[] = 'Data ruchu jest nieprawidłowa.';
    }
    if ($description === '') {
        $errors[] = 'Opis jest wymagany.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT amount FROM worker_advances WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([(int)$entry['advance_id']]);
            $lockedAdvanceAmount = (float)($stmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN id <> ? AND amount > 0 THEN amount ELSE 0 END), 0)
                FROM worker_ledger
                WHERE advance_id = ?
            ");
            $stmt->execute([$ledgerId, (int)$entry['advance_id']]);
            $otherSettled = (float)$stmt->fetchColumn();
            if ($lockedAdvanceAmount <= 0 || $otherSettled + abs($amount) > $lockedAdvanceAmount + 0.01) {
                $errors[] = 'Kwota przekracza dostępne środki tej pozycji portfela.';
                $pdo->rollBack();
            } else {
                $stmt = $pdo->prepare("
                    UPDATE worker_ledger
                    SET amount = ?,
                        entry_date = ?,
                        description = ?
                    WHERE id = ?
                ");
                $stmt->execute([abs($amount), $entryDate, $description, $ledgerId]);

                if (!empty($entry['expense_id'])) {
                    $period = date('Y-m-01', strtotime($entryDate));
                    $stmt = $pdo->prepare("
                        UPDATE worker_expenses
                        SET amount = ?,
                            date = ?,
                            period = ?,
                            description = ?,
                            status = 'approved'
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        abs($amount),
                        $entryDate,
                        $period,
                        $description,
                        (int)$entry['expense_id'],
                    ]);
                }

                walletLedgerSyncReturnFunding($pdo, $entry, abs($amount), $entryDate, $description);
                walletLedgerRecalculateAdvanceStatus($pdo, (int)$entry['advance_id']);

                $pdo->commit();
                logEvent("Wallet ledger: edytowano ruch ID {$ledgerId}", 'INFO');
                walletLedgerRedirectWithFlash($returnUrl, 'updated');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Nie udało się zapisać zmian.';
            logEvent('Wallet ledger edit error: ' . $e->getMessage(), 'ERROR');
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Edytuj ruch portfela</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px; }
        .container { max-width: 900px; margin: 0 auto; padding: 25px; }
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }
        .hero { background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%); color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .hero h1 { margin: 0 0 4px; font-size: 26px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 30px; }
        .error { background: #fef2f2; border-left: 4px solid #dc2626; padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; color: #991b1b; }
        .hint { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px; font-size: 13px; color: #475569; }
        .form-group { margin-bottom: 18px; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 700; font-size: 12px; color: #374151; text-transform: uppercase; letter-spacing: 0.4px; }
        .form-input, .form-textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit; }
        .form-textarea { min-height: 90px; resize: vertical; }
        .btn-row { display: flex; gap: 10px; margin-top: 22px; }
        .btn { padding: 9px 18px; border-radius: 7px; font-weight: 700; font-size: 13px; cursor: pointer; border: 1px solid transparent; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; font-family: inherit; }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: #d1d5db; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo e($returnUrl); ?>">Portfel firmowy</a> - Edycja ruchu
                </div>
                <h1>Edytuj ruch portfela</h1>
                <p><?php echo e($entry['first_name'] . ' ' . $entry['last_name']); ?> - <?php echo e(walletLedgerLabel((string)$entry['entry_type'])); ?></p>
            </div>
            <a href="<?php echo e($returnUrl); ?>" class="btn-hero-secondary">Wróć</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo e($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <?php if (!empty($entry['expense_id'])): ?>
                <div class="hint">Ten ruch jest powiązany z wydatkiem pracownika. Zmiana kwoty, daty i opisu zaktualizuje także powiązany wydatek.</div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo (int)$ledgerId; ?>">
                <input type="hidden" name="return_url" value="<?php echo e($returnUrl); ?>">

                <div class="form-group">
                    <label class="form-label" for="amount">Kwota rozliczenia</label>
                    <input class="form-input" type="number" step="0.01" min="0.01" name="amount" id="amount" value="<?php echo e(number_format((float)$amount, 2, '.', '')); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="entry_date">Data ruchu</label>
                    <input class="form-input" type="date" name="entry_date" id="entry_date" value="<?php echo e($entryDate); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Opis</label>
                    <textarea class="form-textarea" name="description" id="description" required><?php echo e($description); ?></textarea>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                    <a href="<?php echo e($returnUrl); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
