<?php
/**
 * BRYGAD ERP v3.0 - Zatwierdzanie Wydatku
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success = false;
$defaultReturnUrl = url('finanse.wydatki');

$resolveReturnUrl = static function ($candidate, string $fallback): string {
    if (!is_string($candidate) || $candidate === '') {
        return $fallback;
    }
    $parts = parse_url($candidate);
    if ($parts === false) {
        return $fallback;
    }
    if (isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }
    $path = $parts['path'] ?? '';
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return $fallback;
    }
    return $candidate;
};

$returnUrl = $resolveReturnUrl($_GET['return_url'] ?? $_POST['return_url'] ?? null, $defaultReturnUrl);

$expenseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($expenseId <= 0) {
    header('Location: ' . $returnUrl);
    exit;
}

// Pobierz wydatek
try {
    $stmt = $pdo->prepare("
        SELECT 
            we.*,
            w.first_name,
            w.last_name
        FROM worker_expenses we
        INNER JOIN workers w ON we.worker_id = w.id
        WHERE we.id = ?
    ");
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch();
    
    if (!$expense) {
        header('Location: ' . $returnUrl);
        exit;
    }
    
    if ($expense['status'] !== 'pending') {
        $_SESSION['error'] = 'Ten wydatek został już rozpatrzony.';
        header('Location: ' . $returnUrl);
        exit;
    }
} catch (PDOException $e) {
    logEvent("Błąd pobierania wydatku ID $expenseId: " . $e->getMessage(), 'ERROR');
    header('Location: ' . $returnUrl);
    exit;
}

// Sprawdź czy wydatek był zapłacony przez pracownika (nowa kolumna lub legacy tekst)
$isPaidByEmployee = !empty($expense['paid_by_employee']) || strpos($expense['description'], '[PAID_BY_EMPLOYEE]') !== false;
$cleanDescription = trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', (string)$expense['description']));

// Otwarte zaliczki firmowe pracownika (do opcji "Portfel firmowy")
$walletAdvances = [];
$walletAvailable = 0.0;
try {
    $walletAdvances = walletGetCompanyAdvanceBalances($pdo, (int)$expense['worker_id']);
    $walletAvailable = walletGetCompanyAvailableBalance($pdo, (int)$expense['worker_id']);
} catch (PDOException $e) {
    logEvent("Błąd pobierania portfela (approve expense ID $expenseId): " . $e->getMessage(), 'ERROR');
}

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve' || $action === 'reject') {
        $paymentSource = $_POST['payment_source'] ?? 'employee';
        if (!in_array($paymentSource, ['employee', 'wallet'], true)) {
            $paymentSource = 'employee';
        }
        $confirmReimbursement = isset($_POST['confirm_reimbursement']) && $_POST['confirm_reimbursement'] == '1';

        try {
            $pdo->beginTransaction();
            
            $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
            $actionText = ($action === 'approve') ? 'zatwierdzono' : 'odrzucono';

            if ($action === 'reject') {
                $stmt = $pdo->prepare("
                    UPDATE worker_expenses 
                    SET status = ?,
                        approved_by_user_id = ?,
                        approved_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newStatus, $_SESSION['user_id'], $expenseId]);
                logEvent("Wydatek ID $expenseId został $actionText", 'INFO');
                $pdo->commit();
                $success = true;
                header("Location: " . $returnUrl);
                exit;
            }

            // ============================================
            // APPROVE: źródło płatności = wallet
            // ============================================
            if ($paymentSource === 'wallet') {
                $availableBefore = walletGetCompanyAvailableBalance($pdo, (int)$expense['worker_id']);
                if ((float)$expense['amount'] > $availableBefore + 0.0001) {
                    throw new Exception('Kwota wydatku przekracza dostępne środki w portfelu firmowym.');
                }

                $stmt = $pdo->prepare("
                    UPDATE worker_expenses
                    SET status = 'approved',
                        approved_by_user_id = ?,
                        approved_at = NOW(),
                        paid_by_employee = 0,
                        payment_source = 'wallet',
                        wallet_advance_id = NULL,
                        wallet_ledger_id = NULL,
                        description = REPLACE(REPLACE(description, '[PAID_BY_EMPLOYEE] ', ''), '[PAID_BY_EMPLOYEE]', '')
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $expenseId]);

                $allocationResult = walletAllocateExpenseToCompanyAdvances(
                    $pdo,
                    (int)$expense['worker_id'],
                    $expenseId,
                    (float)$expense['amount'],
                    (string)$expense['date'],
                    $cleanDescription,
                    $_SESSION['user_id'] ?? null
                );
                $allocationSummary = implode(', ', array_map(static function ($allocation) {
                    return '#' . (int)$allocation['advance_id'] . ': ' . number_format((float)$allocation['amount'], 2, ',', ' ') . ' zł';
                }, $allocationResult['allocations']));

                logEvent("Wydatek ID {$expenseId} zatwierdzony z portfela FIFO. Alokacje: {$allocationSummary}", 'INFO');
            } else {
                // ============================================
                // APPROVE: źródło płatności = employee
                // ============================================
                $stmt = $pdo->prepare("
                    UPDATE worker_expenses 
                    SET status = 'approved',
                        approved_by_user_id = ?,
                        approved_at = NOW(),
                        paid_by_employee = 1,
                        payment_source = 'employee',
                        wallet_advance_id = NULL,
                        wallet_ledger_id = NULL,
                        description = REPLACE(REPLACE(description, '[PAID_BY_EMPLOYEE] ', ''), '[PAID_BY_EMPLOYEE]', '')
                    WHERE id = ?
                ");
                $stmt->execute([$_SESSION['user_id'], $expenseId]);

                // Opcjonalny zwrot do settlements
                if ($confirmReimbursement) {
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM settlements 
                        WHERE worker_id = ? 
                        AND description LIKE CONCAT('%wydatek #', ?, '%')
                        LIMIT 1
                    ");
                    $checkStmt->execute([$expense['worker_id'], $expenseId]);
                    $existingSettlement = $checkStmt->fetch();
                    
                    if (!$existingSettlement) {
                        $period = date('Y-m-01', strtotime($expense['date']));
                        $settlementDesc = "Zwrot za wydatek #{$expenseId}";
                        if ($expense['project_id']) {
                            $settlementDesc .= " (projekt #{$expense['project_id']})";
                        }
                        
                        $stmtSettlement = $pdo->prepare("
                            INSERT INTO settlements 
                            (worker_id, type, advance_kind, amount, date, period, description, created_by_user_id, created_at)
                            VALUES (?, 'reimbursement', NULL, ?, ?, ?, ?, ?, NOW())
                        ");
                        
                        $stmtSettlement->execute([
                            $expense['worker_id'],
                            $expense['amount'],
                            $expense['date'],
                            $period,
                            $settlementDesc,
                            $_SESSION['user_id']
                        ]);
                        
                        logEvent("Wydatek ID $expenseId zatwierdzony (employee) + wpis reimbursement.", 'INFO');
                    } else {
                        logEvent("Wydatek ID $expenseId: reimbursement już istniał (pominięto duplikat).", 'WARNING');
                    }
                } else {
                    logEvent("Wydatek ID $expenseId zatwierdzony (employee) bez wpisu reimbursement.", 'INFO');
                }
            }
            
            $pdo->commit();
            $success = true;
            header("Location: " . $returnUrl);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
            logEvent("Błąd biznesowy zatwierdzania wydatku ID $expenseId: " . $e->getMessage(), 'ERROR');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Błąd przetwarzania wydatku. Spróbuj ponownie.';
            logEvent("Błąd zatwierdzania wydatku ID $expenseId: " . $e->getMessage(), 'ERROR');
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// Wyczyść opis z ewentualnego legacy znacznika dla wyświetlenia
$displayDescription = trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', $expense['description']));
$selectedPaymentSource = $_POST['payment_source'] ?? (
    $isPaidByEmployee ? 'employee' : (!empty($walletAdvances) ? 'wallet' : 'employee')
);
if (!in_array($selectedPaymentSource, ['employee', 'wallet'], true)) {
    $selectedPaymentSource = 'employee';
}
if (empty($walletAdvances) && $selectedPaymentSource === 'wallet') {
    $selectedPaymentSource = 'employee';
}
$confirmReimbursementChecked = isset($_POST['confirm_reimbursement'])
    ? $_POST['confirm_reimbursement'] === '1'
    : $isPaidByEmployee;
if ($selectedPaymentSource === 'wallet') {
    $confirmReimbursementChecked = false;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Zatwierdź Wydatek</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        /* Header - z include */
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px;
        }
        .breadcrumb {
            margin-bottom: 20px;
            color: #666;
            font-size: 14px;
        }
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        .page-header {
            margin-bottom: 30px;
        }
        .page-header h2 {
            font-size: 32px;
            color: #333;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 40px;
            margin-bottom: 20px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 8px;
        }
        .info-box h3 {
            margin-bottom: 15px;
            color: #004080;
        }
        .info-row {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .info-label {
            font-weight: 600;
            color: #555;
        }
        .amount-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: center;
        }
        .amount-box h3 {
            margin-bottom: 15px;
            font-size: 18px;
        }
        .amount-value {
            font-size: 48px;
            font-weight: 700;
        }
        .receipt-box {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        .receipt-box img {
            max-width: 100%;
            max-height: 400px;
            border-radius: 6px;
        }
        .btn {
            padding: 12px 32px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-approve {
            background: #28a745;
            color: white;
        }
        .btn-reject {
            background: #dc3545;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        .badge-paid-employee {
            display: inline-block;
            margin-left: 10px;
            padding: 4px 10px;
            background: #17a2b8;
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
        .source-box {
            background: #eef7ff;
            border: 2px solid #66a6ff;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        .source-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
        }
        .source-option:last-child {
            margin-bottom: 0;
        }
        .source-option input[type="radio"] {
            margin-top: 2px;
        }
        .wallet-select-wrap {
            margin-top: 15px;
        }
        .wallet-select-wrap label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .wallet-select-wrap select {
            width: 100%;
            padding: 10px;
            border: 1px solid #c7d7ea;
            border-radius: 6px;
            background: #fff;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo e($returnUrl); ?>">Wydatki</a> / 
            Zatwierdź Wydatek
        </div>
        
        <div class="page-header">
            <h2>Zatwierdź Wydatek</h2>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Sukces!</strong> Wydatek został rozpatrzony.
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Błąd!</strong>
                <?php foreach ($errors as $error): ?>
                    <br><?php echo e($error); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$success): ?>
            <div class="card">
                <div class="amount-box">
                    <h3>Kwota do Zatwierdzenia</h3>
                    <div class="amount-value"><?php echo formatMoney($expense['amount']); ?></div>
                </div>
                
                <div class="info-box">
                    <h3>Szczegóły Wydatku</h3>
                    <div class="info-row">
                        <span class="info-label">Pracownik:</span>
                        <span><?php echo e($expense['first_name'] . ' ' . $expense['last_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Data:</span>
                        <span><?php echo formatDate($expense['date']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Opis:</span>
                        <span>
                            <?php echo e($displayDescription); ?>
                            <?php if ($isPaidByEmployee): ?>
                                <span class="badge-paid-employee">Zapłacone przez pracownika</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Kwota:</span>
                        <span style="font-weight: 700; font-size: 18px;"><?php echo formatMoney($expense['amount']); ?></span>
                    </div>
                </div>
                
                <?php if ($expense['receipt_path']): ?>
                    <div class="receipt-box">
                        <h4 style="margin-bottom: 15px;">Paragon / Faktura</h4>
                        <?php 
                            $ext = strtolower(pathinfo($expense['receipt_path'], PATHINFO_EXTENSION));
                            if (in_array($ext, ['jpg', 'jpeg', 'png'])): 
                        ?>
                            <img src="<?php echo e($expense['receipt_path']); ?>" alt="Paragon">
                        <?php else: ?>
                            <a href="<?php echo e($expense['receipt_path']); ?>" target="_blank" class="btn btn-secondary">
                                Otwórz PDF
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <input type="hidden" name="return_url" value="<?php echo e($returnUrl); ?>">
                    <div class="source-box">
                        <h4 style="margin-bottom: 12px; color: #0f4d8a;">Źródło finansowania wydatku</h4>
                        <div class="source-option">
                            <input
                                type="radio"
                                name="payment_source"
                                id="payment_source_employee"
                                value="employee"
                                <?php echo $selectedPaymentSource === 'employee' ? 'checked' : ''; ?>
                            >
                            <label for="payment_source_employee">
                                <strong>Własne środki pracownika</strong><br>
                                <span style="color: #3c5b77; font-size: 13px;">
                                    Doda zwrot do rozliczeń (tylko jeśli zaznaczysz opcję zwrotu poniżej).
                                </span>
                            </label>
                        </div>
                        <div class="source-option">
                            <input
                                type="radio"
                                name="payment_source"
                                id="payment_source_wallet"
                                value="wallet"
                                <?php echo $selectedPaymentSource === 'wallet' ? 'checked' : ''; ?>
                                <?php echo empty($walletAdvances) ? 'disabled' : ''; ?>
                            >
                            <label for="payment_source_wallet">
                                <strong>Portfel firmowy (zaliczka firmowa)</strong><br>
                                <span style="color: #3c5b77; font-size: 13px;">
                                    Kwota wydatku zostanie rozbita FIFO na najstarsze otwarte zaliczki firmowe. Nie tworzy zwrotu do rozliczeń.
                                </span>
                            </label>
                        </div>

                        <div id="wallet_select_wrap" class="wallet-select-wrap" style="<?php echo $selectedPaymentSource === 'wallet' ? '' : 'display: none;'; ?>">
                            <?php if (!empty($walletAdvances)): ?>
                                <div style="font-weight:700;color:#0f4d8a;margin-bottom:8px;">
                                    Dostępne środki łącznie: <?php echo formatMoney($walletAvailable); ?>
                                </div>
                                <div style="font-size:13px;color:#3c5b77;line-height:1.5;">
                                    Kolejność FIFO:
                                    <?php foreach ($walletAdvances as $walletAdvance): ?>
                                        <?php
                                            $walletAdvanceId = (int)$walletAdvance['advance_id'];
                                            $walletLabel = '#' . $walletAdvanceId
                                                . ' | data: ' . formatDate($walletAdvance['issue_date'])
                                                . ' | dostępne: ' . formatMoney((float)$walletAdvance['amount_remaining']);
                                            if (!empty($walletAdvance['description'])) {
                                                $walletLabel .= ' | ' . mb_substr((string)$walletAdvance['description'], 0, 80);
                                            }
                                        ?>
                                        <div><?php echo e($walletLabel); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div style="color: #8a6d3b;">Brak otwartych zaliczek firmowych z dostępnymi środkami.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="reimbursement_box" style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 20px; margin-top: 20px; <?php echo $selectedPaymentSource === 'wallet' ? 'display: none;' : ''; ?>">
                        <h4 style="margin-bottom: 15px; color: #856404;">Zwrot kosztów</h4>
                        <?php if ($isPaidByEmployee): ?>
                            <p style="margin-bottom: 15px; color: #856404;">
                                Pracownik zadeklarował, że <strong>zapłacił z własnej kieszeni</strong> i chce zwrot kosztów.
                            </p>
                        <?php else: ?>
                            <p style="margin-bottom: 15px; color: #856404;">
                                Czy pracownik zapłacił z własnej kieszeni? Zaznacz poniżej, aby po zatwierdzeniu dodać zwrot do salda.
                            </p>
                        <?php endif; ?>
                        <label style="display: flex; align-items: center; cursor: pointer; user-select: none; color: #856404;">
                            <input
                                type="checkbox"
                                name="confirm_reimbursement_preview"
                                id="confirm_reimbursement_preview"
                                <?php echo $confirmReimbursementChecked ? 'checked' : ''; ?>
                                style="margin-right: 10px; width: 20px; height: 20px; cursor: pointer;"
                            >
                            <span>
                                <strong>Zapłacone przez pracownika</strong> - po zatwierdzeniu zwiększy saldo pracownika
                            </span>
                        </label>
                    </div>

                    <input
                        type="hidden"
                        name="confirm_reimbursement"
                        value="<?php echo $confirmReimbursementChecked ? '1' : '0'; ?>"
                        id="confirm_reimbursement_hidden"
                    >
                    
                    <div class="form-actions">
                        <button type="submit" name="action" value="approve" class="btn btn-approve">
                            Zatwierdź Wydatek
                        </button>
                        <button type="submit" name="action" value="reject" class="btn btn-reject" 
                                formnovalidate
                                onclick="return confirm('Czy na pewno chcesz odrzucić ten wydatek?')">
                            Odrzuć
                        </button>
                        <a href="<?php echo e($returnUrl); ?>" class="btn btn-secondary">
                            Anuluj
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        (function () {
            const reimbursementPreview = document.getElementById('confirm_reimbursement_preview');
            const reimbursementHidden = document.getElementById('confirm_reimbursement_hidden');
            const paymentEmployee = document.getElementById('payment_source_employee');
            const paymentWallet = document.getElementById('payment_source_wallet');
            const reimbursementBox = document.getElementById('reimbursement_box');
            const walletSelectWrap = document.getElementById('wallet_select_wrap');

            function syncReimbursementHidden() {
                reimbursementHidden.value = reimbursementPreview && reimbursementPreview.checked ? '1' : '0';
            }

            function syncUiByPaymentSource() {
                const useWallet = paymentWallet && paymentWallet.checked;
                if (walletSelectWrap) {
                    walletSelectWrap.style.display = useWallet ? '' : 'none';
                }
                if (reimbursementBox) {
                    reimbursementBox.style.display = useWallet ? 'none' : '';
                }
                if (useWallet && reimbursementPreview) {
                    reimbursementPreview.checked = false;
                }
                syncReimbursementHidden();
            }

            if (reimbursementPreview) {
                reimbursementPreview.addEventListener('change', syncReimbursementHidden);
            }
            if (paymentEmployee) {
                paymentEmployee.addEventListener('change', syncUiByPaymentSource);
            }
            if (paymentWallet) {
                paymentWallet.addEventListener('change', syncUiByPaymentSource);
            }
            syncUiByPaymentSource();
        })();
    </script>
</body>
</html>
