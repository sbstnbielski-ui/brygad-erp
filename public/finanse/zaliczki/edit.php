<?php
/**
 * BRYGAD ERP - Zaliczki pracownicze - Edycja
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
require_once dirname(__DIR__, 2) . '/includes/payroll_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success = false;
$advanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$advanceTransferMeta = [];

if ($advanceId <= 0) {
    header("Location: " . url('finanse.zaliczki'));
    exit;
}

// Pobierz zaliczkę
try {
    $stmt = $pdo->prepare("SELECT * FROM worker_advances WHERE id = ?");
    $stmt->execute([$advanceId]);
    $advance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$advance) {
        die("Nie znaleziono zaliczki.");
    }

    $advanceTransferMeta = walletTransferMetaFromDescription((string)($advance['description'] ?? ''));
    if (($advanceTransferMeta['transfer_role'] ?? '') !== 'target') {
        $advanceTransferMeta = [];
    } else {
        $advance['description'] = walletTransferVisibleNote((string)($advance['description'] ?? ''));
    }
    
    // Pobierz pracowników
    $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
    $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching advance: " . $e->getMessage());
    die("Błąd pobierania danych: " . $e->getMessage());
}

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $workerId = (int)($_POST['worker_id'] ?? 0);
    $type = trim($_POST['type'] ?? '');
    $amount = floatval(str_replace(',', '.', str_replace(' ', '', trim($_POST['amount'] ?? '0'))));
    $issueDate = trim($_POST['issue_date'] ?? '');
    $salaryPeriodRaw = trim($_POST['salary_period'] ?? '');
    $salaryPeriod = payrollNormalizeMonth($salaryPeriodRaw, $issueDate);
    $description = trim($_POST['description'] ?? '');
    
    // Walidacja
    if ($workerId <= 0) {
        $errors[] = 'Wybierz pracownika.';
    }
    if (!in_array($type, ['COMPANY', 'PRIVATE'])) {
        $errors[] = 'Wybierz prawidłowy typ zaliczki.';
    }
    if ($amount <= 0) {
        $errors[] = 'Kwota musi być większa od 0.';
    }
    if (empty($issueDate)) {
        $errors[] = 'Data wydania jest wymagana.';
    }
    if ($type === 'PRIVATE' && !payrollIsMonthInputValid($salaryPeriodRaw)) {
        $errors[] = 'Okres rozliczeniowy jest nieprawidłowy.';
    }
    if (!empty($advanceTransferMeta)) {
        if ($workerId !== (int)$advance['worker_id']) {
            $errors[] = 'Transferu nie można przepisać na innego pracownika.';
        }
        if ($type !== (string)$advance['type']) {
            $errors[] = 'Transferu nie można zmienić z firmowego na prywatny ani odwrotnie.';
        }
    }
    
    // Aktualizuj zaliczkę
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            if (!empty($advanceTransferMeta)) {
                walletUpdateTransferByTargetAdvance($pdo, $advanceId, $amount, $issueDate, $description, $_SESSION['user_id'] ?? null);
                if ($type === 'PRIVATE') {
                    $stmt = $pdo->prepare("UPDATE worker_advances SET salary_period = ? WHERE id = ?");
                    $stmt->execute([$salaryPeriod, $advanceId]);
                }
            } else {
                // Aktualizuj worker_advances
                $stmt = $pdo->prepare("
                    UPDATE worker_advances 
                    SET worker_id = ?, type = ?, amount = ?, issue_date = ?, salary_period = ?, description = ?
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $workerId,
                    $type,
                    $amount,
                    $issueDate,
                    $type === 'PRIVATE' ? $salaryPeriod : null,
                    $description,
                    $advanceId
                ]);

                // Wszystkie wpisy ledger tej zaliczki należą do właściciela zaliczki.
                $stmt = $pdo->prepare("
                    UPDATE worker_ledger
                    SET worker_id = ?
                    WHERE advance_id = ?
                ");
                $stmt->execute([$workerId, $advanceId]);
                
                // Aktualizuj wpis w ledger (jeśli istnieje wpis ADVANCE dla tej zaliczki)
                $stmt = $pdo->prepare("
                    UPDATE worker_ledger 
                    SET worker_id = ?, amount = ?, entry_date = ?, 
                        description = ?
                    WHERE advance_id = ? AND entry_type = 'ADVANCE'
                ");
                
                $ledgerAmount = -1 * abs($amount);
                $stmt->execute([
                    $workerId,
                    $ledgerAmount,
                    $issueDate,
                    "Zaliczka " . ($type === 'PRIVATE' ? 'prywatna' : 'firmowa') . ($description ? ": " . $description : ''),
                    $advanceId
                ]);

                if ($type === 'COMPANY') {
                    $fundingNote = 'Zasilenie portfela' . ($description ? ': ' . $description : '');

                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM worker_wallet_funding
                        WHERE advance_id = ? AND direction = 'OUT_TOPUP'
                        ORDER BY id ASC
                        LIMIT 1
                    ");
                    $stmt->execute([$advanceId]);
                    $fundingRow = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($fundingRow) {
                        $stmt = $pdo->prepare("
                            UPDATE worker_wallet_funding
                            SET worker_id = ?, amount = ?, movement_date = ?, note = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $workerId,
                            abs($amount),
                            $issueDate,
                            $fundingNote,
                            (int)$fundingRow['id'],
                        ]);
                    }
                } else {
                    $stmt = $pdo->prepare("DELETE FROM worker_wallet_funding WHERE advance_id = ? AND direction = 'OUT_TOPUP'");
                    $stmt->execute([$advanceId]);
                }
            }
            
            $pdo->commit();
            
            $success = true;
            header("Location: " . url('finanse.zaliczki.view', ['id' => $advanceId]) . '?success=edited');
            exit;
            
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log("Error updating advance: " . $e->getMessage());
            $errors[] = 'Błąd podczas aktualizacji: ' . $e->getMessage();
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
    <title><?php echo e(APP_NAME); ?> - Edytuj zaliczkę #<?php echo $advanceId; ?></title>
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
        .alert ul {
            margin: 10px 0 0 20px;
        }
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-label .required {
            color: #dc2626;
        }
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
        }
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        .form-hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(102, 126, 234, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            .form-actions {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                text-align: center;
            }
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
                    Edytuj Zaliczkę
                </div>
                <h1>Edytuj Zaliczkę</h1>
                <p>Edycja zaliczki</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.zaliczki'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Wystąpiły błędy:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="form-card">
            <form method="POST">
                <div class="form-group">
                    <label class="form-label">
                        Pracownik <span class="required">*</span>
                    </label>
                    <select name="worker_id" class="form-select" required>
                        <option value="">-- Wybierz pracownika --</option>
                        <?php foreach ($workers as $worker): ?>
                            <option value="<?php echo $worker['id']; ?>" <?php echo ($advance['worker_id'] == $worker['id']) ? 'selected' : ''; ?>>
                                <?php echo e($worker['last_name'] . ' ' . $worker['first_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        Typ zaliczki <span class="required">*</span>
                    </label>
                    <select name="type" class="form-select" required>
                        <option value="">-- Wybierz typ --</option>
                        <option value="COMPANY" <?php echo ($advance['type'] === 'COMPANY') ? 'selected' : ''; ?>>Firmowa (rozliczana fakturą/kosztem)</option>
                        <option value="PRIVATE" <?php echo ($advance['type'] === 'PRIVATE') ? 'selected' : ''; ?>>Prywatna (potrącana z wynagrodzenia)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        Kwota (PLN) <span class="required">*</span>
                    </label>
                    <input type="number" name="amount" step="0.01" min="0.01" class="form-input" 
                           value="<?php echo e($advance['amount']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        Data wydania <span class="required">*</span>
                    </label>
                    <input type="date" name="issue_date" class="form-input" 
                           value="<?php echo e($advance['issue_date']); ?>" required>
                </div>

                <div class="form-group" id="salaryPeriodGroup">
                    <label class="form-label">
                        Okres rozliczeniowy
                    </label>
                    <input type="month" name="salary_period" class="form-input"
                           value="<?php echo e(payrollMonthInputValue($advance['salary_period'] ?? null, $advance['issue_date'] ?? null)); ?>">
                    <div class="form-hint">Dotyczy zaliczek prywatnych: miesiąc wynagrodzenia, z którego zaliczka schodzi.</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        Opis / Uzasadnienie
                    </label>
                    <textarea name="description" class="form-textarea"><?php echo e($advance['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                    <a href="<?php echo url('finanse.zaliczki.view', ['id' => $advanceId]); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const type = document.querySelector('select[name="type"]');
            const group = document.getElementById('salaryPeriodGroup');
            if (!type || !group) return;

            function toggleSalaryPeriod() {
                group.style.display = type.value === 'PRIVATE' ? '' : 'none';
            }

            type.addEventListener('change', toggleSalaryPeriod);
            toggleSalaryPeriod();
        })();
    </script>
</body>
</html>
