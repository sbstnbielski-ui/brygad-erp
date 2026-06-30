<?php
/**
 * BRYGAD ERP - Centrum rozliczeń - Edycja operacji
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
require_once dirname(__DIR__, 2) . '/includes/payroll_helper.php';
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

try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, is_active FROM workers ORDER BY last_name, first_name");
    $workers = $stmt->fetchAll();
} catch (PDOException $e) {
    $workers = [];
}

$item = null;
$fundingTopup = null;
$advanceTransferMeta = [];

if ($source === 'settlement') {
    $stmt = $pdo->prepare("\n        SELECT s.*, w.first_name, w.last_name\n        FROM settlements s\n        INNER JOIN workers w ON w.id = s.worker_id\n        WHERE s.id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
} else {
    $stmt = $pdo->prepare("\n        SELECT wa.*, w.first_name, w.last_name\n        FROM worker_advances wa\n        INNER JOIN workers w ON w.id = wa.worker_id\n        WHERE wa.id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();

    if ($item) {
        $stmt = $pdo->prepare("\n            SELECT id, source_kind, source_ref, note, movement_date\n            FROM worker_wallet_funding\n            WHERE advance_id = ? AND direction = 'OUT_TOPUP'\n            ORDER BY id ASC\n            LIMIT 1\n        ");
        $stmt->execute([$itemId]);
        $fundingTopup = $stmt->fetch();

        $advanceTransferMeta = walletTransferMetaFromDescription((string)($item['description'] ?? ''));
        if (($advanceTransferMeta['transfer_role'] ?? '') !== 'target') {
            $advanceTransferMeta = [];
        } else {
            $item['description'] = walletTransferVisibleNote((string)($item['description'] ?? ''));
        }
    }
}

if (!$item) {
    redirectWithFlash($returnTo, 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($source === 'settlement') {
        $type = trim((string)($_POST['type'] ?? ''));
        $amount = (float)str_replace(',', '.', trim((string)($_POST['amount'] ?? '0')));
        $date = trim((string)($_POST['date'] ?? ''));
        $periodRaw = trim((string)($_POST['period'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $advanceKind = trim((string)($_POST['advance_kind'] ?? ''));

        $validTypes = ['payout', 'advance', 'reimbursement', 'bonus', 'correction'];
        if (!in_array($type, $validTypes, true)) {
            $errors[] = 'Wybierz poprawny typ operacji.';
        }
        if ($amount <= 0) {
            $errors[] = 'Kwota musi być większa od 0.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = 'Data operacji jest nieprawidłowa.';
        }
        if ($periodRaw !== '' && !preg_match('/^\d{4}-\d{2}$/', $periodRaw)) {
            $errors[] = 'Okres rozliczeniowy jest nieprawidłowy.';
        }

        if ($type === 'advance') {
            if (!in_array($advanceKind, ['private', 'company'], true)) {
                $errors[] = 'Dla zaliczki wybierz rodzaj (prywatna/firmowa).';
            }
        } else {
            $advanceKind = '';
        }

        if (empty($errors)) {
            $periodDate = $periodRaw !== ''
                ? ($periodRaw . '-01')
                : date('Y-m-01', strtotime($date));

            try {
                $stmt = $pdo->prepare("\n                    UPDATE settlements\n                    SET type = ?,\n                        advance_kind = ?,\n                        amount = ?,\n                        date = ?,\n                        period = ?,\n                        description = ?\n                    WHERE id = ?\n                ");
                $stmt->execute([
                    $type,
                    $type === 'advance' ? $advanceKind : null,
                    $amount,
                    $date,
                    $periodDate,
                    $description !== '' ? $description : null,
                    $itemId,
                ]);

                logEvent("Centrum rozliczen: edytowano settlement ID {$itemId}", 'INFO');
                redirectWithFlash($returnTo, 'updated');
            } catch (PDOException $e) {
                $errors[] = 'Nie udało się zapisać zmian.';
                logEvent('Centrum rozliczen edit settlement error: ' . $e->getMessage(), 'ERROR');
            }
        }

        $stmt = $pdo->prepare("\n            SELECT s.*, w.first_name, w.last_name\n            FROM settlements s\n            INNER JOIN workers w ON w.id = s.worker_id\n            WHERE s.id = ?\n            LIMIT 1\n        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
    } else {
        $workerId = (int)($_POST['worker_id'] ?? 0);
        $type = strtoupper(trim((string)($_POST['type'] ?? '')));
        $amount = (float)str_replace(',', '.', trim((string)($_POST['amount'] ?? '0')));
        $issueDate = trim((string)($_POST['issue_date'] ?? ''));
        $salaryPeriodRaw = trim((string)($_POST['salary_period'] ?? ''));
        $salaryPeriod = payrollNormalizeMonth($salaryPeriodRaw, $issueDate);
        $description = trim((string)($_POST['description'] ?? ''));
        $sourceKind = trim((string)($_POST['source_kind'] ?? ''));
        $sourceRef = trim((string)($_POST['source_ref'] ?? ''));

        if ($workerId <= 0) {
            $errors[] = 'Wybierz pracownika.';
        }
        if (!in_array($type, ['COMPANY', 'PRIVATE'], true)) {
            $errors[] = 'Wybierz poprawny typ zaliczki.';
        }
        if ($amount <= 0) {
            $errors[] = 'Kwota musi być większa od 0.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
            $errors[] = 'Data operacji jest nieprawidłowa.';
        }
        if ($type === 'PRIVATE' && !payrollIsMonthInputValid($salaryPeriodRaw)) {
            $errors[] = 'Okres rozliczeniowy jest nieprawidłowy.';
        }
        if ($type === 'COMPANY' && $sourceKind !== '' && !in_array($sourceKind, ['cash', 'bank', 'other'], true)) {
            $errors[] = 'Nieprawidłowe źródło finansowania.';
        }

        if (!empty($advanceTransferMeta)) {
            if ($workerId !== (int)$item['worker_id']) {
                $errors[] = 'Transferu nie można przepisać na innego pracownika.';
            }
            if ($type !== (string)$item['type']) {
                $errors[] = 'Transferu nie można zmienić z firmowego na prywatny ani odwrotnie.';
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                if (!empty($advanceTransferMeta)) {
                    walletUpdateTransferByTargetAdvance($pdo, $itemId, $amount, $issueDate, $description, $_SESSION['user_id'] ?? null);
                    if ($type === 'PRIVATE') {
                        $stmt = $pdo->prepare("UPDATE worker_advances SET salary_period = ? WHERE id = ?");
                        $stmt->execute([$salaryPeriod, $itemId]);
                    }
                } else {
                    $stmt = $pdo->prepare("\n                    UPDATE worker_advances\n                    SET worker_id = ?,\n                        type = ?,\n                        amount = ?,\n                        issue_date = ?,\n                        salary_period = ?,\n                        description = ?\n                    WHERE id = ?\n                ");
                    $stmt->execute([
                        $workerId,
                        $type,
                        abs($amount),
                        $issueDate,
                        $type === 'PRIVATE' ? $salaryPeriod : null,
                        $description !== '' ? $description : null,
                        $itemId,
                    ]);

                    // Wszystkie wpisy ledger tej zaliczki należą do właściciela zaliczki.
                    $stmt = $pdo->prepare("\n                    UPDATE worker_ledger\n                    SET worker_id = ?\n                    WHERE advance_id = ?\n                ");
                    $stmt->execute([$workerId, $itemId]);

                    $ledgerDescription = 'Zaliczka ' . ($type === 'PRIVATE' ? 'prywatna' : 'firmowa');
                    if ($description !== '') {
                        $ledgerDescription .= ': ' . $description;
                    }
                    $stmt = $pdo->prepare("\n                    UPDATE worker_ledger\n                    SET amount = ?,\n                        description = ?\n                    WHERE advance_id = ? AND entry_type = 'ADVANCE'\n                ");
                    $stmt->execute([
                        -1 * abs($amount),
                        $ledgerDescription,
                        $itemId,
                    ]);

                    if ($type === 'COMPANY') {
                        $fundingNote = 'Zasilenie portfela' . ($description !== '' ? ': ' . $description : '');
                        $stmt = $pdo->prepare("\n                        SELECT id\n                        FROM worker_wallet_funding\n                        WHERE advance_id = ? AND direction = 'OUT_TOPUP'\n                        ORDER BY id ASC\n                        LIMIT 1\n                    ");
                        $stmt->execute([$itemId]);
                        $existingFunding = $stmt->fetch();

                        if ($existingFunding) {
                            $stmt = $pdo->prepare("\n                            UPDATE worker_wallet_funding\n                            SET worker_id = ?,\n                                amount = ?,\n                                source_kind = ?,\n                                source_ref = ?,\n                                note = ?,\n                                movement_date = ?\n                            WHERE id = ?\n                        ");
                            $stmt->execute([
                                $workerId,
                                abs($amount),
                                $sourceKind !== '' ? $sourceKind : null,
                                $sourceRef !== '' ? $sourceRef : null,
                                $fundingNote,
                                $issueDate,
                                (int)$existingFunding['id'],
                            ]);
                        }
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM worker_wallet_funding WHERE advance_id = ? AND direction = 'OUT_TOPUP'");
                        $stmt->execute([$itemId]);
                    }
                }

                $pdo->commit();
                logEvent("Centrum rozliczen: edytowano zaliczke ID {$itemId}", 'INFO');
                redirectWithFlash($returnTo, 'updated');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Nie udało się zapisać zmian.';
                logEvent('Centrum rozliczen edit advance error: ' . $e->getMessage(), 'ERROR');
            }
        }

        $stmt = $pdo->prepare("\n            SELECT wa.*, w.first_name, w.last_name\n            FROM worker_advances wa\n            INNER JOIN workers w ON w.id = wa.worker_id\n            WHERE wa.id = ?\n            LIMIT 1\n        ");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        $stmt = $pdo->prepare("\n            SELECT id, source_kind, source_ref, note, movement_date\n            FROM worker_wallet_funding\n            WHERE advance_id = ? AND direction = 'OUT_TOPUP'\n            ORDER BY id ASC\n            LIMIT 1\n        ");
        $stmt->execute([$itemId]);
        $fundingTopup = $stmt->fetch();

        $advanceTransferMeta = walletTransferMetaFromDescription((string)($item['description'] ?? ''));
        if (($advanceTransferMeta['transfer_role'] ?? '') !== 'target') {
            $advanceTransferMeta = [];
        } else {
            $item['description'] = walletTransferVisibleNote((string)($item['description'] ?? ''));
        }
    }
}

$title = $source === 'settlement' ? 'Edycja rozliczenia' : 'Edycja zaliczki';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo e($title); ?></title>
    <style>
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --danger: #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px; }
        .container { max-width: 1000px; margin: 0 auto; padding: 25px; }

        /* Override header */
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }

        /* Hero */
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 26px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; align-self: center; }
        .btn-hero-secondary {
            background: rgba(255,255,255,0.1); color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2); font-weight: 600;
            padding: 8px 16px; border-radius: 8px; text-decoration: none;
            font-size: 13px; display: inline-flex; align-items: center; transition: all 0.2s;
        }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        /* Card */
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); padding: 28px; }

        /* Alert */
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
        .alert-error { background: #fef2f2; border-color: #dc2626; color: #991b1b; }
        .alert ul { margin: 8px 0 0 18px; }

        /* Meta */
        .meta { background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; color: #4b5563; }
        .meta strong { color: var(--text-main); }

        /* Form */
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 14px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: 0.4px; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            border: 1px solid var(--border); border-radius: 6px;
            padding: 9px 12px; font-size: 13px; font-family: inherit;
            color: var(--text-main); background: white; transition: border-color 0.15s; width: 100%;
        }
        .form-group textarea { min-height: 90px; resize: vertical; }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 2px rgba(102,126,234,0.1); }

        /* Buttons */
        .form-actions { display: flex; gap: 10px; margin-top: 18px; padding-top: 18px; border-top: 1px solid #f3f4f6; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 9px 22px; border-radius: 7px; font-size: 13px; font-weight: 600;
            cursor: pointer; border: 1px solid transparent; text-decoration: none;
            transition: all 0.2s; font-family: inherit;
        }
        .btn-primary   { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: var(--border); }
        .btn-secondary:hover { background: #f9fafb; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo e($returnTo); ?>">Centrum rozliczeń / Historia operacji</a> /
                    <?php echo e($title); ?>
                </div>
                <h1><?php echo e($title); ?></h1>
                <p><?php echo $source === 'settlement' ? 'Edytujesz pozycję z tabeli settlements.' : 'Edytujesz pozycję z tabeli worker_advances.'; ?></p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo e($returnTo); ?>" class="btn-hero-secondary">← Wróć do historii</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Nie udało się zapisać zmian:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="meta">
                <strong>Pozycja #<?php echo (int)$itemId; ?></strong>
                dla pracownika
                <strong><?php echo e(($item['last_name'] ?? '') . ' ' . ($item['first_name'] ?? '')); ?></strong>
            </div>

            <?php if ($source === 'settlement'): ?>
                <?php
                $periodValue = '';
                if (!empty($item['period']) && $item['period'] !== '0000-00-00') {
                    $periodValue = date('Y-m', strtotime((string)$item['period']));
                } elseif (!empty($item['date'])) {
                    $periodValue = date('Y-m', strtotime((string)$item['date']));
                }
                ?>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Typ operacji</label>
                            <select name="type" id="settlement_type" onchange="toggleAdvanceKind()" required>
                                <?php $typeVal = (string)($item['type'] ?? ''); ?>
                                <option value="payout" <?php echo $typeVal === 'payout' ? 'selected' : ''; ?>>Wypłata</option>
                                <option value="advance" <?php echo $typeVal === 'advance' ? 'selected' : ''; ?>>Zaliczka</option>
                                <option value="reimbursement" <?php echo $typeVal === 'reimbursement' ? 'selected' : ''; ?>>Zwrot kosztów</option>
                                <option value="bonus" <?php echo $typeVal === 'bonus' ? 'selected' : ''; ?>>Premia</option>
                                <option value="correction" <?php echo $typeVal === 'correction' ? 'selected' : ''; ?>>Korekta</option>
                            </select>
                        </div>
                        <div class="form-group" id="advance_kind_group" style="display:none;">
                            <label>Rodzaj zaliczki</label>
                            <?php $advanceKindVal = (string)($item['advance_kind'] ?? 'private'); ?>
                            <select name="advance_kind">
                                <option value="private" <?php echo $advanceKindVal === 'private' ? 'selected' : ''; ?>>Prywatna</option>
                                <option value="company" <?php echo $advanceKindVal === 'company' ? 'selected' : ''; ?>>Firmowa</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Kwota</label>
                            <input type="number" name="amount" step="0.01" min="0.01" required
                                   value="<?php echo e((string)($item['amount'] ?? '')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Data operacji</label>
                            <input type="date" name="date" required value="<?php echo e((string)($item['date'] ?? '')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Okres rozliczeniowy</label>
                            <input type="month" name="period" value="<?php echo e($periodValue); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Opis</label>
                        <textarea name="description"><?php echo e((string)($item['description'] ?? '')); ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                        <a href="<?php echo e($returnTo); ?>" class="btn btn-secondary">Anuluj</a>
                    </div>
                </form>
            <?php else: ?>
                <?php
                $advanceType = strtoupper((string)($item['type'] ?? 'PRIVATE'));
                $topupSourceKind = (string)($fundingTopup['source_kind'] ?? '');
                $topupSourceRef = (string)($fundingTopup['source_ref'] ?? '');
                $salaryPeriodValue = payrollMonthInputValue($item['salary_period'] ?? null, $item['issue_date'] ?? null);
                ?>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Pracownik</label>
                            <select name="worker_id" required>
                                <option value="">Wybierz pracownika</option>
                                <?php foreach ($workers as $w): ?>
                                    <?php $wid = (int)($w['id'] ?? 0); ?>
                                    <option value="<?php echo $wid; ?>" <?php echo $wid === (int)$item['worker_id'] ? 'selected' : ''; ?>>
                                        <?php echo e(($w['last_name'] ?? '') . ' ' . ($w['first_name'] ?? '')); ?>
                                        <?php echo ((int)($w['is_active'] ?? 0) !== 1) ? ' [nieaktywny]' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Typ zaliczki</label>
                            <select name="type" id="advance_type" onchange="toggleFundingFields()" required>
                                <option value="COMPANY" <?php echo $advanceType === 'COMPANY' ? 'selected' : ''; ?>>Firmowa</option>
                                <option value="PRIVATE" <?php echo $advanceType === 'PRIVATE' ? 'selected' : ''; ?>>Prywatna</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Kwota</label>
                            <input type="number" name="amount" step="0.01" min="0.01" required
                                   value="<?php echo e((string)($item['amount'] ?? '')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Data operacji</label>
                            <input type="date" name="issue_date" required value="<?php echo e((string)($item['issue_date'] ?? '')); ?>">
                        </div>
                        <div class="form-group" id="salary_period_group">
                            <label>Okres rozliczeniowy</label>
                            <input type="month" name="salary_period" value="<?php echo e($salaryPeriodValue); ?>">
                        </div>
                    </div>
                    <div id="funding_fields" style="display:none;">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Źródło finansowania (opcjonalnie)</label>
                                <select name="source_kind">
                                    <option value="">-- brak --</option>
                                    <option value="cash" <?php echo $topupSourceKind === 'cash' ? 'selected' : ''; ?>>Gotówka</option>
                                    <option value="bank" <?php echo $topupSourceKind === 'bank' ? 'selected' : ''; ?>>Przelew bankowy</option>
                                    <option value="other" <?php echo $topupSourceKind === 'other' ? 'selected' : ''; ?>>Inne</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Referencja (opcjonalnie)</label>
                                <input type="text" name="source_ref" value="<?php echo e($topupSourceRef); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Opis</label>
                        <textarea name="description"><?php echo e((string)($item['description'] ?? '')); ?></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                        <a href="<?php echo e($returnTo); ?>" class="btn btn-secondary">Anuluj</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleAdvanceKind() {
            const type = document.getElementById('settlement_type');
            const group = document.getElementById('advance_kind_group');
            if (!type || !group) return;
            group.style.display = type.value === 'advance' ? '' : 'none';
        }

        function toggleFundingFields() {
            const type = document.getElementById('advance_type');
            const group = document.getElementById('funding_fields');
            const salaryPeriodGroup = document.getElementById('salary_period_group');
            if (!type || !group) return;
            group.style.display = type.value === 'COMPANY' ? '' : 'none';
            if (salaryPeriodGroup) {
                salaryPeriodGroup.style.display = type.value === 'PRIVATE' ? '' : 'none';
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            toggleAdvanceKind();
            toggleFundingFields();
        });
    </script>
</body>
</html>
