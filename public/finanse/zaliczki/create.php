<?php
/**
 * BRYGAD ERP - Zaliczki pracownicze - Jeden formularz operacji
 *
 * Operacje:
 * - TOPUP_COMPANY: zasilenie portfela firmowego
 * - PRIVATE_ADVANCE: zaliczka prywatna
 * - TRANSFER_COMPANY_TO_COMPANY: transfer A->B (firmowa -> firmowa)
 * - TRANSFER_COMPANY_TO_PRIVATE: transfer A->B (firmowa -> prywatna)
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
require_once dirname(__DIR__, 2) . '/includes/payroll_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

const ADVANCE_OP_TOPUP_COMPANY = 'TOPUP_COMPANY';
const ADVANCE_OP_PRIVATE = 'PRIVATE_ADVANCE';
const ADVANCE_OP_TRANSFER_CC = 'TRANSFER_COMPANY_TO_COMPANY';
const ADVANCE_OP_TRANSFER_CP = 'TRANSFER_COMPANY_TO_PRIVATE';

$operationOptions = [
    ADVANCE_OP_TOPUP_COMPANY => 'Portfel firmowy (zasilenie)',
    ADVANCE_OP_PRIVATE => 'Zaliczka prywatna',
    ADVANCE_OP_TRANSFER_CC => 'Transfer A->B (firmowa -> firmowa)',
    ADVANCE_OP_TRANSFER_CP => 'Transfer A->B (firmowa -> prywatna)',
];

$preselectedWorkerId = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
$preselectedType = strtoupper(trim($_GET['type'] ?? ''));
$preselectedOperation = strtoupper(trim($_GET['operation'] ?? ''));
$preselectedFromWorkerId = isset($_GET['from_worker_id']) ? (int)$_GET['from_worker_id'] : 0;
$preselectedSourceAdvanceId = isset($_GET['source_advance_id']) ? (int)$_GET['source_advance_id'] : 0;
if ($preselectedFromWorkerId <= 0 && $preselectedSourceAdvanceId > 0) {
    $preselectedFromWorkerId = walletResolveCompanyAdvanceWorkerId($pdo, $preselectedSourceAdvanceId);
}

if (!isset($operationOptions[$preselectedOperation])) {
    if ($preselectedType === 'COMPANY') {
        $preselectedOperation = ADVANCE_OP_TOPUP_COMPANY;
    } elseif ($preselectedType === 'PRIVATE') {
        $preselectedOperation = ADVANCE_OP_PRIVATE;
    } else {
        $preselectedOperation = ADVANCE_OP_TOPUP_COMPANY;
    }
}

function normalizeAmount(string $value): float
{
    return (float)str_replace(',', '.', str_replace(' ', '', trim($value)));
}

function isValidDate(string $value): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

function createAdvance(
    PDO $pdo,
    int $workerId,
    string $type,
    float $amount,
    string $issueDate,
    string $description,
    ?int $createdBy,
    bool $withFunding = false,
    ?string $sourceKind = null,
    ?string $sourceRef = null,
    ?string $ledgerDescription = null,
    ?string $salaryPeriod = null
): int {
    if (payrollWorkerAdvancesHasSalaryPeriod($pdo)) {
        $stmt = $pdo->prepare("
            INSERT INTO worker_advances
            (worker_id, type, amount, issue_date, salary_period, description, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'open', ?, NOW())
        ");
        $stmt->execute([
            $workerId,
            $type,
            abs($amount),
            $issueDate,
            $type === 'PRIVATE' ? $salaryPeriod : null,
            $description,
            $createdBy,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO worker_advances
            (worker_id, type, amount, issue_date, description, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, 'open', ?, NOW())
        ");
        $stmt->execute([
            $workerId,
            $type,
            abs($amount),
            $issueDate,
            $description,
            $createdBy,
        ]);
    }

    $advanceId = (int)$pdo->lastInsertId();

    $ledgerText = $ledgerDescription;
    if ($ledgerText === null || trim($ledgerText) === '') {
        $ledgerText = 'Zaliczka ' . ($type === 'PRIVATE' ? 'prywatna' : 'firmowa');
        if (trim($description) !== '') {
            $ledgerText .= ': ' . trim($description);
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO worker_ledger
        (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at)
        VALUES (?, 'ADVANCE', ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $workerId,
        -1 * abs($amount),
        $issueDate,
        $advanceId,
        $ledgerText,
        $createdBy,
    ]);

    if ($withFunding && $type === 'COMPANY') {
        $stmt = $pdo->prepare("
            INSERT INTO worker_wallet_funding
            (worker_id, advance_id, direction, amount, source_kind, source_ref, note, movement_date, created_by, created_at)
            VALUES (?, ?, 'OUT_TOPUP', ?, ?, ?, ?, ?, ?, NOW())
        ");

        $fundingNote = trim($description) !== ''
            ? 'Zasilenie portfela: ' . trim($description)
            : 'Zasilenie portfela';

        $stmt->execute([
            $workerId,
            $advanceId,
            abs($amount),
            $sourceKind,
            $sourceRef !== '' ? $sourceRef : null,
            $fundingNote,
            $issueDate,
            $createdBy,
        ]);
    }

    return $advanceId;
}

try {
    $stmt = $pdo->query("
        SELECT id, first_name, last_name
        FROM workers
        WHERE is_active = 1
        ORDER BY last_name, first_name
    ");
    $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $workers = [];
    $errors[] = 'Błąd pobierania listy pracowników.';
}

$selectedOperation = strtoupper(trim($_POST['operation_type'] ?? $preselectedOperation));
if (!isset($operationOptions[$selectedOperation])) {
    $selectedOperation = ADVANCE_OP_TOPUP_COMPANY;
}

$selectedFromWorkerId = (int)($_POST['from_worker_id'] ?? $preselectedFromWorkerId);
$selectedTargetWorkerId = (int)($_POST['to_worker_id'] ?? 0);

try {
    $workerFilter = $selectedFromWorkerId > 0 ? [$selectedFromWorkerId] : [];
    $sourceWorkers = walletGetCompanySourceWorkers($pdo, $workerFilter);
} catch (PDOException $e) {
    $sourceWorkers = [];
    $errors[] = 'Nie udało się pobrać portfeli źródłowych.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operationType = strtoupper(trim($_POST['operation_type'] ?? ''));
    $workerId = (int)($_POST['worker_id'] ?? 0);
    $toWorkerId = (int)($_POST['to_worker_id'] ?? 0);
    $fromWorkerId = (int)($_POST['from_worker_id'] ?? 0);
    $amount = normalizeAmount((string)($_POST['amount'] ?? '0'));
    $issueDate = trim($_POST['issue_date'] ?? date('Y-m-d'));
    $salaryPeriodRaw = trim($_POST['salary_period'] ?? '');
    $salaryPeriod = payrollNormalizeMonth($salaryPeriodRaw, $issueDate);
    $description = trim($_POST['description'] ?? '');
    $sourceKind = strtolower(trim($_POST['source_kind'] ?? ''));
    $sourceRef = trim($_POST['source_ref'] ?? '');

    if (!isset($operationOptions[$operationType])) {
        $errors[] = 'Wybierz prawidłowy typ operacji.';
    }
    if ($amount <= 0) {
        $errors[] = 'Kwota musi być większa od 0.';
    }
    if (!isValidDate($issueDate)) {
        $errors[] = 'Data operacji jest nieprawidłowa.';
    }
    if (in_array($operationType, [ADVANCE_OP_PRIVATE, ADVANCE_OP_TRANSFER_CP], true) && !payrollIsMonthInputValid($salaryPeriodRaw)) {
        $errors[] = 'Okres rozliczeniowy jest nieprawidłowy.';
    }

    if ($operationType === ADVANCE_OP_TOPUP_COMPANY || $operationType === ADVANCE_OP_PRIVATE) {
        if ($workerId <= 0) {
            $errors[] = 'Wybierz pracownika.';
        }
    }

    if ($operationType === ADVANCE_OP_TOPUP_COMPANY) {
        if ($sourceKind === '' || !in_array($sourceKind, ['cash', 'bank', 'other'], true)) {
            $errors[] = 'Dla zasilenia portfela wybierz źródło finansowania.';
        }
    }

    if ($operationType === ADVANCE_OP_TRANSFER_CC || $operationType === ADVANCE_OP_TRANSFER_CP) {
        if ($fromWorkerId <= 0) {
            $errors[] = 'Wybierz pracownika źródłowego.';
        }
        if ($toWorkerId <= 0) {
            $errors[] = 'Wybierz pracownika docelowego.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            $redirectAdvanceId = 0;

            if ($operationType === ADVANCE_OP_TOPUP_COMPANY) {
                $redirectAdvanceId = createAdvance(
                    $pdo,
                    $workerId,
                    'COMPANY',
                    $amount,
                    $issueDate,
                    $description,
                    $createdBy,
                    true,
                    $sourceKind,
                    $sourceRef
                );
            } elseif ($operationType === ADVANCE_OP_PRIVATE) {
                $redirectAdvanceId = createAdvance(
                    $pdo,
                    $workerId,
                    'PRIVATE',
                    $amount,
                    $issueDate,
                    $description,
                    $createdBy,
                    false,
                    null,
                    null,
                    null,
                    $salaryPeriod
                );
            } elseif ($operationType === ADVANCE_OP_TRANSFER_CC || $operationType === ADVANCE_OP_TRANSFER_CP) {
                $stmt = $pdo->prepare("
                    SELECT id, first_name, last_name, is_active
                    FROM workers
                    WHERE id = ?
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmt->execute([$toWorkerId]);
                $targetWorker = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$targetWorker) {
                    throw new RuntimeException('Nie znaleziono pracownika docelowego.');
                }
                if ((int)$targetWorker['is_active'] !== 1) {
                    throw new RuntimeException('Pracownik docelowy jest nieaktywny.');
                }
                if ($fromWorkerId === $toWorkerId) {
                    throw new RuntimeException('Pracownik źródłowy i docelowy muszą być różni.');
                }

                if ($operationType === ADVANCE_OP_TRANSFER_CP) {
                    $transfer = walletCreateTransfer(
                        $pdo,
                        $fromWorkerId,
                        $toWorkerId,
                        $amount,
                        $issueDate,
                        $description,
                        $createdBy,
                        'PRIVATE',
                        $salaryPeriod
                    );
                } else {
                    $transfer = walletCreateTransfer(
                        $pdo,
                        $fromWorkerId,
                        $toWorkerId,
                        $amount,
                        $issueDate,
                        $description,
                        $createdBy,
                        'COMPANY'
                    );
                }
                $redirectAdvanceId = (int)$transfer['target_advance_id'];
            }

            $pdo->commit();
            header('Location: ' . url('finanse.zaliczki.view', ['id' => $redirectAdvanceId]));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Zaliczki/create error: ' . $e->getMessage());
            $errors[] = 'Błąd zapisu operacji: ' . $e->getMessage();
        }
    }
}

$selectedWorkerId = (int)($_POST['worker_id'] ?? $preselectedWorkerId);

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Operacje zaliczek</title>
    <style>

        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --bg-card:           #ffffff;
            --border:            #e5e7eb;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
            --success:           #22c55e;
            --danger:            #ef4444;
            --warning:           #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px;
        }
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
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }


        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }
        .alert ul { margin: 10px 0 0 20px; }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
        }

        .section-title {
            font-size: 14px;
            color: #374151;
            font-weight: 700;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .form-group { margin-bottom: 18px; }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-label .required { color: #dc2626; }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
            background: #fff;
        }
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .form-textarea {
            resize: vertical;
            min-height: 96px;
        }

        .form-hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 6px;
            line-height: 1.5;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .op-box {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 13px;
            color: #1e3a8a;
            line-height: 1.5;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(30, 64, 175, 0.25);
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(30, 64, 175, 0.32);
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-secondary:hover { background: #d1d5db; }

        @media (max-width: 820px) {
            .container { padding: 20px; }
            .form-grid { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>

    <div class="container">
                <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    <a href="<?php echo url('finanse.zaliczki'); ?>">Zaliczki</a> /
                    Nowa Zaliczka
                </div>
                <h1>Nowa Zaliczka</h1>
                <p>Wystaw nową zaliczkę</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.zaliczki'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Popraw formularz:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" id="advanceForm">
                <div class="section-title">1) Typ operacji</div>

                <div class="form-group">
                    <label class="form-label" for="operationType">Typ operacji <span class="required">*</span></label>
                    <select name="operation_type" id="operationType" class="form-select" required>
                        <?php foreach ($operationOptions as $opKey => $opLabel): ?>
                            <option value="<?php echo e($opKey); ?>" <?php echo $selectedOperation === $opKey ? 'selected' : ''; ?>>
                                <?php echo e($opLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="op-box" id="operationHint"></div>

                <div id="singleWorkerGroup" style="display:none;">
                    <div class="section-title">2) Pracownik</div>
                    <div class="form-group">
                        <label class="form-label" for="workerId">Pracownik <span class="required">*</span></label>
                        <select name="worker_id" id="workerId" class="form-select">
                            <option value="">-- Wybierz pracownika --</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo (int)$worker['id']; ?>" <?php echo $selectedWorkerId === (int)$worker['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($worker['last_name'] . ' ' . $worker['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="transferGroup" style="display:none;">
                    <div class="section-title">2) Transfer A->B</div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="fromWorkerId">Pracownik źródłowy (A) <span class="required">*</span></label>
                            <select name="from_worker_id" id="fromWorkerId" class="form-select">
                                <option value="">-- Wybierz portfel źródłowy --</option>
                                <?php foreach ($sourceWorkers as $worker): ?>
                                    <option value="<?php echo (int)$worker['worker_id']; ?>" <?php echo $selectedFromWorkerId === (int)$worker['worker_id'] ? 'selected' : ''; ?>>
                                        <?php echo e($worker['worker_name']); ?> | saldo: <?php echo formatMoney((float)$worker['wallet_balance']); ?> | otwarte pozycje: <?php echo (int)$worker['open_count']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">System sam rozpisze kwotę FIFO po ukrytych pozycjach portfela tego pracownika.</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="toWorkerId">Pracownik docelowy (B) <span class="required">*</span></label>
                        <select name="to_worker_id" id="toWorkerId" class="form-select">
                            <option value="">-- Wybierz pracownika docelowego --</option>
                            <?php foreach ($workers as $worker): ?>
                                <option value="<?php echo (int)$worker['id']; ?>" <?php echo $selectedTargetWorkerId === (int)$worker['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($worker['last_name'] . ' ' . $worker['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="fundingBlock" style="display:none;">
                    <div class="section-title">3) Źródło finansowania</div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="sourceKind">Źródło finansowania <span class="required">*</span></label>
                            <select name="source_kind" id="sourceKind" class="form-select">
                                <option value="">-- Wybierz źródło --</option>
                                <option value="cash" <?php echo (($_POST['source_kind'] ?? '') === 'cash') ? 'selected' : ''; ?>>Kasa / gotówka</option>
                                <option value="bank" <?php echo (($_POST['source_kind'] ?? '') === 'bank') ? 'selected' : ''; ?>>Konto bankowe</option>
                                <option value="other" <?php echo (($_POST['source_kind'] ?? '') === 'other') ? 'selected' : ''; ?>>Inne</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="sourceRef">Opis źródła (opcjonalnie)</label>
                            <input type="text" name="source_ref" id="sourceRef" class="form-input" maxlength="120" placeholder="Np. Kasa główna" value="<?php echo e($_POST['source_ref'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="section-title" id="amountSectionTitle">4) Szczegóły finansowe</div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" id="amountLabel" for="amount">Kwota (PLN) <span class="required">*</span></label>
                        <input type="number" name="amount" id="amount" step="0.01" min="0.01" class="form-input" value="<?php echo e($_POST['amount'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" id="dateLabel" for="issueDate">Data operacji <span class="required">*</span></label>
                        <input type="date" name="issue_date" id="issueDate" class="form-input" value="<?php echo e($_POST['issue_date'] ?? date('Y-m-d')); ?>" required>
                    </div>

                    <div class="form-group" id="salaryPeriodGroup">
                        <label class="form-label" for="salaryPeriod">Okres rozliczeniowy <span class="required">*</span></label>
                        <input type="month" name="salary_period" id="salaryPeriod" class="form-input" value="<?php echo e($_POST['salary_period'] ?? date('Y-m')); ?>">
                        <div class="form-hint">Miesiąc wynagrodzenia, z którego ta zaliczka ma zostać potrącona.</div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" id="descriptionLabel" for="description">Opis / Uzasadnienie</label>
                    <textarea name="description" id="description" class="form-textarea" placeholder="Opis operacji"><?php echo e($_POST['description'] ?? ''); ?></textarea>
                    <div class="form-hint" id="descriptionHint"></div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" id="submitBtn">Zapisz operację</button>
                    <a href="<?php echo url('finanse.zaliczki'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const operationType = document.getElementById('operationType');
            const singleWorkerGroup = document.getElementById('singleWorkerGroup');
            const transferGroup = document.getElementById('transferGroup');
            const fundingBlock = document.getElementById('fundingBlock');

            const workerId = document.getElementById('workerId');
            const fromWorkerId = document.getElementById('fromWorkerId');
            const toWorkerId = document.getElementById('toWorkerId');
            const sourceKind = document.getElementById('sourceKind');
            const salaryPeriodGroup = document.getElementById('salaryPeriodGroup');
            const salaryPeriod = document.getElementById('salaryPeriod');

            const operationHint = document.getElementById('operationHint');
            const description = document.getElementById('description');
            const descriptionHint = document.getElementById('descriptionHint');
            const submitBtn = document.getElementById('submitBtn');

            const config = {
                TOPUP_COMPANY: {
                    hint: 'Portfel firmowy: środki operacyjne dla pracownika. Nie wpływa na saldo wypłaty.',
                    showSingle: true,
                    showTransfer: false,
                    showFunding: true,
                    showSalaryPeriod: false,
                    submit: 'Zasil portfel firmowy',
                    placeholder: 'Np. zakup materiałów do realizacji',
                    descHint: 'To zasilenie portfela firmowego. Kwota będzie widoczna jako środki do wydania.',
                },
                PRIVATE_ADVANCE: {
                    hint: 'Zaliczka prywatna: dług pracownika do potrącenia z wypłaty.',
                    showSingle: true,
                    showTransfer: false,
                    showFunding: false,
                    showSalaryPeriod: true,
                    submit: 'Daj zaliczkę prywatną',
                    placeholder: 'Np. zaliczka prywatna na wyjazd',
                    descHint: 'Ta operacja wpływa na saldo wypłaty pracownika.',
                },
                TRANSFER_COMPANY_TO_COMPANY: {
                    hint: 'Transfer A->B (firmowa -> firmowa): środki schodzą z portfela A i zasilają portfel B.',
                    showSingle: false,
                    showTransfer: true,
                    showFunding: false,
                    showSalaryPeriod: false,
                    submit: 'Wykonaj transfer firmowa -> firmowa',
                    placeholder: 'Np. przeniesienie środków na wspólne zakupy',
                    descHint: 'System zapisze 2 ruchy: rozchód z A i nową zaliczkę firmową u B.',
                },
                TRANSFER_COMPANY_TO_PRIVATE: {
                    hint: 'Transfer A->B (firmowa -> prywatna): środki schodzą z portfela A i tworzą prywatną zaliczkę u B.',
                    showSingle: false,
                    showTransfer: true,
                    showFunding: false,
                    showSalaryPeriod: true,
                    submit: 'Wykonaj transfer firmowa -> prywatna',
                    placeholder: 'Np. przekazanie na potrzeby prywatne pracownika B',
                    descHint: 'System zapisze 2 ruchy: rozchód z A oraz nową zaliczkę prywatną u B.',
                },
            };

            function applyMode() {
                const mode = operationType ? operationType.value : 'TOPUP_COMPANY';
                const cfg = config[mode] || config.TOPUP_COMPANY;

                singleWorkerGroup.style.display = cfg.showSingle ? 'block' : 'none';
                transferGroup.style.display = cfg.showTransfer ? 'block' : 'none';
                fundingBlock.style.display = cfg.showFunding ? 'block' : 'none';
                salaryPeriodGroup.style.display = cfg.showSalaryPeriod ? 'block' : 'none';

                if (workerId) {
                    workerId.required = !!cfg.showSingle;
                }
                if (fromWorkerId) {
                    fromWorkerId.required = !!cfg.showTransfer;
                }
                if (toWorkerId) {
                    toWorkerId.required = !!cfg.showTransfer;
                }
                if (sourceKind) {
                    sourceKind.required = !!cfg.showFunding;
                    if (!cfg.showFunding) {
                        sourceKind.value = '';
                    }
                }
                if (salaryPeriod) {
                    salaryPeriod.required = !!cfg.showSalaryPeriod;
                }

                if (operationHint) operationHint.textContent = cfg.hint;
                if (submitBtn) submitBtn.textContent = cfg.submit;
                if (description) description.placeholder = cfg.placeholder;
                if (descriptionHint) descriptionHint.textContent = cfg.descHint;
            }

            if (operationType) {
                operationType.addEventListener('change', applyMode);
            }
            applyMode();
        })();
    </script>
</body>
</html>
