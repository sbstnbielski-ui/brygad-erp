<?php
/**
 * BRYGAD ERP - Centrum rozliczeń - Usuwanie operacji
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

$source = isset($_GET['source']) ? trim((string)$_GET['source']) : '';
$source = in_array($source, ['settlement', 'advance'], true) ? $source : '';
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$returnToRaw = isset($_GET['return_to']) ? trim((string)$_GET['return_to']) : '';

function settlementsFallbackUrl(): string
{
    return url('hr.settlements') . '?tab=history';
}

function sanitizeReturnTo(string $returnTo): string
{
    if ($returnTo === '' || $returnTo[0] !== '/') {
        return settlementsFallbackUrl();
    }

    $parsed = parse_url($returnTo);
    $path = rtrim((string)($parsed['path'] ?? ''), '/');
    if ($path === '') {
        return settlementsFallbackUrl();
    }

    $allowedPaths = [
        rtrim((string)parse_url(url('hr'), PHP_URL_PATH), '/'),
        rtrim((string)parse_url(url('hr.settlements'), PHP_URL_PATH), '/'),
        rtrim((string)parse_url(url('hr.workers.wallet'), PHP_URL_PATH), '/'),
        '/hr',
        '/hr/index.php',
        '/hr/settlements',
        '/hr/settlements/index.php',
        '/hr/workers/wallet.php',
    ];
    $allowedPaths = array_values(array_unique(array_filter($allowedPaths)));

    if (!in_array($path, $allowedPaths, true)) {
        return settlementsFallbackUrl();
    }

    parse_str((string)($parsed['query'] ?? ''), $queryParams);
    if ($path === '/hr/workers/wallet.php') {
        return ((int)($queryParams['worker_id'] ?? 0) > 0) ? $returnTo : settlementsFallbackUrl();
    }

    if (($queryParams['tab'] ?? '') !== 'history') {
        return settlementsFallbackUrl();
    }
    return $returnTo;
}

function redirectWithFlash(string $returnTo, string $flash): void
{
    $sep = (strpos($returnTo, '?') === false) ? '?' : '&';
    header('Location: ' . $returnTo . $sep . 'hist_flash=' . urlencode($flash));
    exit;
}

$returnTo = sanitizeReturnTo($returnToRaw);
if ($source === '' || $itemId <= 0) {
    redirectWithFlash($returnTo, 'error');
}

$item = null;
$operationLabel = '';
$advanceTransferMeta = [];

if ($source === 'settlement') {
    $stmt = $pdo->prepare("\n        SELECT s.*, w.first_name, w.last_name\n        FROM settlements s\n        INNER JOIN workers w ON w.id = s.worker_id\n        WHERE s.id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if ($item) {
        $labels = [
            'payout' => 'Wypłata',
            'advance' => 'Zaliczka',
            'reimbursement' => 'Zwrot kosztów',
            'bonus' => 'Premia',
            'correction' => 'Korekta',
        ];
        $operationLabel = $labels[$item['type'] ?? ''] ?? (string)($item['type'] ?? 'Rozliczenie');
    }
} else {
    $stmt = $pdo->prepare("\n        SELECT wa.*, w.first_name, w.last_name\n        FROM worker_advances wa\n        INNER JOIN workers w ON w.id = wa.worker_id\n        WHERE wa.id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if ($item) {
        $operationLabel = ((string)($item['type'] ?? '') === 'COMPANY') ? 'Zaliczka firmowa' : 'Zaliczka prywatna';
        $advanceTransferMeta = walletTransferMetaFromDescription((string)($item['description'] ?? ''));
        if (($advanceTransferMeta['transfer_role'] ?? '') !== 'target') {
            $advanceTransferMeta = [];
        }
    }
}

if (!$item) {
    redirectWithFlash($returnTo, 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        if ($source === 'settlement') {
            $stmt = $pdo->prepare("DELETE FROM settlements WHERE id = ?");
            $stmt->execute([$itemId]);

            logEvent("Centrum rozliczen: usunieto settlement ID {$itemId}", 'WARNING');
            redirectWithFlash($returnTo, 'deleted');
        }

        throw new RuntimeException('Twarde usuwanie zaliczek i ledgerów jest zablokowane. Użyj korekty księgowej zamiast kasowania historii portfela.');

        $pdo->beginTransaction();

        if (!empty($advanceTransferMeta)) {
            walletDeleteTransferByTargetAdvance($pdo, $itemId);
        } else {
            // Usunięcie wszystkich powiązań zaliczki w jednej transakcji.
            $stmt = $pdo->prepare("DELETE FROM worker_wallet_funding WHERE advance_id = ?");
            $stmt->execute([$itemId]);

            $stmt = $pdo->prepare("SELECT 1");
            $stmt->execute([$itemId]);

            $stmt = $pdo->prepare("DELETE FROM worker_advance_files WHERE advance_id = ?");
            $stmt->execute([$itemId]);

            $stmt = $pdo->prepare("DELETE FROM worker_advances WHERE id = ?");
            $stmt->execute([$itemId]);
        }

        $pdo->commit();

        logEvent("Centrum rozliczen: usunieto zaliczke ID {$itemId} z powiazaniami", 'WARNING');
        redirectWithFlash($returnTo, 'deleted');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Nie udało się usunąć operacji.';
        logEvent('Centrum rozliczen delete error: ' . $e->getMessage(), 'ERROR');
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Usuń operację</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #1f2937;
        }
        .container {
            max-width: 760px;
            margin: 0 auto;
            padding: 28px;
        }
        .breadcrumb {
            margin-bottom: 14px;
            font-size: 13px;
            color: #9ca3af;
        }
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        .page-header {
            margin-bottom: 20px;
        }
        .page-header h1 {
            font-size: 30px;
            color: #b91c1c;
            margin-bottom: 6px;
        }
        .page-header p {
            color: #6b7280;
            font-size: 14px;
        }
        .card {
            background: white;
            border: 1px solid #fecaca;
            border-left: 4px solid #dc2626;
            border-radius: 10px;
            padding: 24px;
        }
        .warning {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 14px;
            font-size: 13px;
        }
        .details {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 14px;
            font-size: 13px;
        }
        .details .row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 6px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .details .row:last-child {
            border-bottom: none;
        }
        .details .label {
            color: #6b7280;
            font-weight: 600;
        }
        .details .value {
            color: #111827;
            font-weight: 700;
            text-align: right;
        }
        .consequences {
            margin: 0 0 14px 18px;
            color: #7f1d1d;
            font-size: 13px;
        }
        .consequences li {
            margin-bottom: 4px;
        }
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 18px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
        }
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        .btn-danger:hover {
            background: #b91c1c;
        }
        .btn-secondary {
            background: white;
            color: #374151;
            border-color: #d1d5db;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            border-left: 3px solid;
            font-size: 13px;
        }
        .alert-error {
            background: #fee2e2;
            border-color: #dc2626;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo e($returnTo); ?>">Centrum rozliczeń / Historia operacji</a> / Usuń operację
        </div>

        <div class="page-header">
            <h1>Usuń operację</h1>
            <p>Operacja jest nieodwracalna.</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error"><?php echo e($errors[0]); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="warning">
                Tej akcji nie można cofnąć. Usunięta pozycja zniknie z historii operacji i raportów.
            </div>

            <div class="details">
                <div class="row">
                    <span class="label">Typ pozycji</span>
                    <span class="value"><?php echo e($source === 'settlement' ? 'Rozliczenie (settlements)' : 'Zaliczka (worker_advances)'); ?></span>
                </div>
                <div class="row">
                    <span class="label">Operacja</span>
                    <span class="value"><?php echo e($operationLabel); ?></span>
                </div>
                <div class="row">
                    <span class="label">Pracownik</span>
                    <span class="value"><?php echo e(($item['last_name'] ?? '') . ' ' . ($item['first_name'] ?? '')); ?></span>
                </div>
                <div class="row">
                    <span class="label">Kwota</span>
                    <span class="value"><?php echo number_format((float)($item['amount'] ?? 0), 2, ',', ' '); ?> zł</span>
                </div>
                <div class="row">
                    <span class="label">Data</span>
                    <span class="value"><?php echo e($source === 'settlement' ? (string)($item['date'] ?? '') : (string)($item['issue_date'] ?? '')); ?></span>
                </div>
            </div>

            <ul class="consequences">
                <?php if ($source === 'settlement'): ?>
                    <li>Zostanie usunięty rekord z tabeli <strong>settlements</strong>.</li>
                <?php else: ?>
                    <li>Zostanie usunięty rekord z tabeli <strong>worker_advances</strong>.</li>
                    <li>Zostaną usunięte wszystkie wpisy <strong>worker_ledger</strong> tej zaliczki.</li>
                    <li>Zostaną usunięte pliki z <strong>worker_advance_files</strong>.</li>
                    <li>Zostaną usunięte powiązania finansowania z <strong>worker_wallet_funding</strong>.</li>
                <?php endif; ?>
            </ul>

            <form method="POST">
                <div class="form-actions">
                    <button
                        type="submit"
                        name="confirm_delete"
                        value="1"
                        class="btn btn-danger"
                        onclick="return confirm('Czy na pewno chcesz usunąć tę operację?');"
                    >
                        Tak, usuń
                    </button>
                    <a href="<?php echo e($returnTo); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
