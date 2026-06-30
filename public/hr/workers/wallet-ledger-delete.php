<?php
/**
 * BRYGAD ERP - Usuwanie pojedynczego ruchu portfela firmowego
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

function walletLedgerDeleteFallbackUrl(): string
{
    return url('hr.workers.wallet');
}

function walletLedgerDeleteSafeReturnUrl($candidate): string
{
    if (!is_string($candidate) || $candidate === '') {
        return walletLedgerDeleteFallbackUrl();
    }

    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return walletLedgerDeleteFallbackUrl();
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return walletLedgerDeleteFallbackUrl();
    }

    $allowedPaths = [
        '/hr/workers/wallet.php',
        rtrim((string)parse_url(url('hr.workers.wallet'), PHP_URL_PATH), '/'),
    ];

    if (!in_array(rtrim($path, '/'), array_unique($allowedPaths), true)) {
        return walletLedgerDeleteFallbackUrl();
    }

    return $candidate;
}

function walletLedgerDeleteRedirectWithFlash(string $returnUrl, string $flash): void
{
    $sep = strpos($returnUrl, '?') === false ? '?' : '&';
    header('Location: ' . $returnUrl . $sep . 'wallet_flash=' . urlencode($flash));
    exit;
}

function walletLedgerDeleteRecalculateAdvanceStatus(PDO $pdo, int $advanceId): void
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

function walletLedgerDeleteLabel(array $entry): string
{
    return walletResolveEntryLabel($entry);
}

function walletLedgerDeleteReturnFunding(PDO $pdo, array $entry): void
{
    if (($entry['entry_type'] ?? '') !== 'CASH_RETURN') {
        return;
    }
    if (!str_starts_with((string)($entry['description'] ?? ''), 'Zwrot gotówki')) {
        return;
    }

    $stmt = $pdo->prepare("
        DELETE FROM worker_wallet_funding
        WHERE id = (
            SELECT id FROM (
                SELECT id
                FROM worker_wallet_funding
                WHERE advance_id = ?
                  AND direction = 'IN_RETURN'
                  AND ABS(amount - ?) < 0.01
                  AND movement_date = ?
                ORDER BY id DESC
                LIMIT 1
            ) AS funding_to_delete
        )
    ");
    $stmt->execute([
        (int)$entry['advance_id'],
        abs((float)$entry['amount']),
        (string)$entry['entry_date'],
    ]);
}

$returnUrl = walletLedgerDeleteSafeReturnUrl($_GET['return_url'] ?? $_POST['return_url'] ?? null);
$ledgerId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);

if ($ledgerId <= 0) {
    walletLedgerDeleteRedirectWithFlash($returnUrl, 'error');
}

$entry = null;
try {
    $stmt = $pdo->prepare("
        SELECT
            wl.*,
            wa.type AS advance_type,
            w.first_name,
            w.last_name
        FROM worker_ledger wl
        INNER JOIN worker_advances wa ON wa.id = wl.advance_id
        INNER JOIN workers w ON w.id = wl.worker_id
        WHERE wl.id = ?
          AND wa.type = 'COMPANY'
        LIMIT 1
    ");
    $stmt->execute([$ledgerId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logEvent('Wallet ledger delete fetch error: ' . $e->getMessage(), 'ERROR');
}

if (!$entry || (string)$entry['entry_type'] === 'ADVANCE') {
    walletLedgerDeleteRedirectWithFlash($returnUrl, 'error');
}

$transferMeta = walletTransferMetaFromDescription((string)($entry['description'] ?? ''));
if (($transferMeta['transfer_role'] ?? '') === 'source' && ($transferMeta['transfer_ref'] ?? '') !== '') {
    $targetAdvance = walletFindTransferTargetAdvance($pdo, (string)$transferMeta['transfer_ref']);
    if (!$targetAdvance) {
        walletLedgerDeleteRedirectWithFlash($returnUrl, 'error');
    }
    header('Location: ' . url('hr.settlements.delete', [
        'source' => 'advance',
        'id' => (int)$targetAdvance['id'],
        'return_to' => $returnUrl,
    ]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        throw new RuntimeException('Twarde usuwanie ruchów portfela jest zablokowane. Użyj korekty księgowej zamiast kasowania ledgerów.');

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT
                wl.*,
                wa.type AS advance_type
            FROM worker_ledger wl
            INNER JOIN worker_advances wa ON wa.id = wl.advance_id
            WHERE wl.id = ?
              AND wa.type = 'COMPANY'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$ledgerId]);
        $lockedEntry = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedEntry || (string)$lockedEntry['entry_type'] === 'ADVANCE') {
            $pdo->rollBack();
            walletLedgerDeleteRedirectWithFlash($returnUrl, 'error');
        }

        walletLedgerDeleteReturnFunding($pdo, $lockedEntry);

        if (!empty($lockedEntry['expense_id'])) {
            $stmt = $pdo->prepare("
                SELECT 1
            ");
            $stmt->execute([$ledgerId, (int)$lockedEntry['expense_id']]);

            $stmt = $pdo->prepare("DELETE FROM worker_expenses WHERE id = ?");
            $stmt->execute([(int)$lockedEntry['expense_id']]);
        } else {
            $stmt = $pdo->prepare("SELECT 1");
            $stmt->execute([$ledgerId]);
        }

        walletLedgerDeleteRecalculateAdvanceStatus($pdo, (int)$lockedEntry['advance_id']);
        $pdo->commit();

        logEvent("Wallet ledger: usunieto ruch ID {$ledgerId}", 'WARNING');
        walletLedgerDeleteRedirectWithFlash($returnUrl, 'deleted');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Nie udało się usunąć ruchu portfela.';
        logEvent('Wallet ledger delete error: ' . $e->getMessage(), 'ERROR');
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Usuń ruch portfela</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #1f2937; line-height: 1.5; }
        .container { max-width: 760px; margin: 0 auto; padding: 28px; }
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }
        .breadcrumb { margin-bottom: 14px; font-size: 13px; color: #9ca3af; }
        .breadcrumb a { color: #667eea; text-decoration: none; }
        .page-header { margin-bottom: 20px; }
        .page-header h1 { font-size: 30px; color: #b91c1c; margin-bottom: 6px; }
        .page-header p { color: #6b7280; font-size: 14px; }
        .card { background: white; border: 1px solid #fecaca; border-left: 4px solid #dc2626; border-radius: 10px; padding: 24px; }
        .warning { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; padding: 12px; margin-bottom: 14px; font-size: 13px; }
        .error { background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 14px; border-radius: 8px; margin-bottom: 14px; color: #991b1b; font-size: 13px; }
        .details { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; margin-bottom: 14px; font-size: 13px; }
        .details .row { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px solid #e5e7eb; }
        .details .row:last-child { border-bottom: none; }
        .details .label { color: #6b7280; font-weight: 600; }
        .details .value { color: #111827; font-weight: 700; text-align: right; }
        .actions { display: flex; gap: 10px; margin-top: 18px; }
        .btn { padding: 9px 18px; border-radius: 7px; font-weight: 700; font-size: 13px; cursor: pointer; border: 1px solid transparent; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; font-family: inherit; }
        .btn-danger { background: #dc2626; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: #d1d5db; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo e($returnUrl); ?>">Portfel firmowy</a> / Usuń ruch
        </div>
        <div class="page-header">
            <h1>Usuń ruch portfela</h1>
            <p>Potwierdź usunięcie operacji z portfela firmowego.</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo e($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="warning">
                Tej operacji nie da się cofnąć. Status pozycji portfela zostanie przeliczony po usunięciu.
                <?php if (!empty($entry['expense_id'])): ?>
                    Powiązany wydatek pracownika również zostanie usunięty.
                <?php endif; ?>
            </div>
            <div class="details">
                <div class="row">
                    <span class="label">Pracownik</span>
                    <span class="value"><?php echo e($entry['first_name'] . ' ' . $entry['last_name']); ?></span>
                </div>
                <div class="row">
                    <span class="label">Typ ruchu</span>
                    <span class="value"><?php echo e(walletLedgerDeleteLabel($entry)); ?></span>
                </div>
                <div class="row">
                    <span class="label">Data</span>
                    <span class="value"><?php echo formatDate($entry['entry_date']); ?></span>
                </div>
                <div class="row">
                    <span class="label">Kwota</span>
                    <span class="value"><?php echo formatMoney((float)$entry['amount']); ?></span>
                </div>
                <div class="row">
                    <span class="label">Opis</span>
                    <span class="value"><?php echo e((string)($entry['description'] ?? '')); ?></span>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo (int)$ledgerId; ?>">
                <input type="hidden" name="return_url" value="<?php echo e($returnUrl); ?>">
                <input type="hidden" name="confirm_delete" value="1">
                <div class="actions">
                    <button type="submit" class="btn btn-danger">Tak, usuń ruch</button>
                    <a href="<?php echo e($returnUrl); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
