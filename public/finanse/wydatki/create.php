<?php
/**
 * BRYGAD ERP v3.0 - Dodawanie Wydatku
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
require_once dirname(__DIR__) . '/_company-cost-categories.php';
require_once dirname(__DIR__) . '/_company-cost-category-combo.php';
require_once dirname(__DIR__) . '/_project-select.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$errors = [];
$success = false;

$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;
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

$returnUrl = $resolveReturnUrl(
    $_GET['return_url'] ?? $_POST['return_url'] ?? $_GET['redirect_to'] ?? $_POST['redirect_to'] ?? null,
    $defaultReturnUrl
);

// Pobierz pracowników
// Admin widzi wszystkich, pracownik widzi tylko siebie
try {
    if ($isAdminUser) {
        $adminWorkerIdForExclude = $_SESSION['worker_id'] ?? null;
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $allWorkers = $stmt->fetchAll();
        $workers = array_filter($allWorkers, fn($w) => (int)$w['id'] !== (int)$adminWorkerIdForExclude);
    } elseif ($currentWorkerId) {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM workers WHERE id = ? AND is_active = 1");
        $stmt->execute([$currentWorkerId]);
        $workers = $stmt->fetchAll();
    } else {
        $workers = [];
    }
} catch (PDOException $e) {
    $workers = [];
}

// Pobierz projekty
try {
    $projects = spxFetchSelectableProjects($pdo);
} catch (PDOException $e) {
    $projects = [];
}

$companyCostDictionary = ksCompanyCostLoadDictionary($pdo);
$companyCategoryLabels = ksCompanyCostCategoryLabels($companyCostDictionary);
$companySubcategoriesByCategory = ksCompanyCostSubcategoriesByCategory($companyCostDictionary);
$companySubcategoryHints = ksCompanyCostSubcategoryNames($companyCostDictionary);
$defaultCompanyCategory = isset($companyCategoryLabels['inne']) ? 'inne' : (array_key_first($companyCategoryLabels) ?: 'inne');

$selectedProjectId = null;
if (!empty($_POST['project_id'])) {
    $selectedProjectId = (int)$_POST['project_id'];
} elseif (!empty($_GET['project_id'])) {
    $selectedProjectId = (int)$_GET['project_id'];
}
$selectedCostNodeId = null;
if (!empty($_POST['cost_node_id'])) {
    $selectedCostNodeId = (int)$_POST['cost_node_id'];
} elseif (!empty($_GET['cost_node_id'])) {
    $selectedCostNodeId = (int)$_GET['cost_node_id'];
}

$loadCostNodes = static function (PDO $pdo, ?int $projectId): array {
    if (!$projectId) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT id, parent_id, name
            FROM project_cost_nodes
            WHERE project_id = ? AND is_active = 1
            ORDER BY parent_id IS NULL DESC, sort_order, name
        ");
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
};
$cost_nodes = $loadCostNodes($pdo, $selectedProjectId);

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Jeśli pracownik (nie admin), wymuś jego worker_id
    $isCompanyExpense = false;
    if (!$isAdminUser && $currentWorkerId) {
        $workerId = $currentWorkerId;
    } else {
        $rawWorkerId = trim((string)($_POST['worker_id'] ?? ''));
        $isCompanyExpense = ($rawWorkerId === '' || $rawWorkerId === 'company');
        $workerId = $isCompanyExpense ? null : (int)$rawWorkerId;
    }
    
    $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    $costNodeId = !empty($_POST['cost_node_id']) ? (int)$_POST['cost_node_id'] : null;
    $selectedProjectId = $projectId;
    $selectedCostNodeId = $costNodeId;
    $cost_nodes = $loadCostNodes($pdo, $selectedProjectId);
    $expenseType = trim($_POST['expense_type'] ?? 'cash_other');
    $date = trim($_POST['date'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $companyCategory = trim($_POST['company_category'] ?? '');
    $companySubcategory = trim($_POST['company_subcategory'] ?? '');
    if ($companyCategory === '') {
        $companyCategory = $defaultCompanyCategory;
    }
    $paymentSource = $_POST['payment_source'] ?? (isset($_POST['paid_by_employee']) ? 'employee' : 'wallet');
    if (!in_array($paymentSource, ['wallet', 'employee'], true)) {
        $paymentSource = 'employee';
    }
    $paidByEmployee = ($paymentSource === 'employee') ? 1 : 0;
    
    // Walidacja
    if (!$isCompanyExpense && $workerId <= 0) {
        $errors[] = 'Wybierz pracownika.';
    }
    
    // Pracownik może dodawać tylko swoje wydatki
    if (!$isAdminUser && $workerId != $currentWorkerId) {
        $errors[] = 'Nie możesz dodawać wydatków dla innych pracowników.';
    }
    if (empty($date)) {
        $errors[] = 'Data jest wymagana.';
    }
    if (empty($amount) || !is_numeric($amount) || $amount <= 0) {
        $errors[] = 'Kwota musi być liczbą większą od 0.';
    }
    if (empty($description)) {
        $errors[] = 'Opis wydatku jest wymagany.';
    }

    $companyCategory = mb_substr($companyCategory, 0, 50);
    $companySubcategory = mb_substr($companySubcategory, 0, 120);
    
    // Walidacja expense_type
    $allowedExpenseTypes = ['cash_other', 'cash_purchase', 'invoice_candidate'];
    if (!in_array($expenseType, $allowedExpenseTypes)) {
        $expenseType = 'cash_other';
    }

    if ($costNodeId && !$projectId) {
        $errors[] = 'Aby wybrać etap, najpierw wybierz projekt.';
    }
    if ($projectId && $costNodeId) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM project_cost_nodes
            WHERE id = ? AND project_id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$costNodeId, $projectId]);
        if (!$stmt->fetchColumn()) {
            $errors[] = 'Wybrany etap nie należy do wybranego projektu.';
        }
    }
    
    // Upload paragonu (opcjonalnie)
    $receiptPath = null;
    if (!empty($_FILES['receipt']['name'])) {
        $file = $_FILES['receipt'];
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        
        if (!in_array($file['type'], $allowedTypes)) {
            $errors[] = 'Paragon musi być w formacie JPG, PNG lub PDF.';
        }
        
        if ($file['size'] > 5 * 1024 * 1024) { // 5MB
            $errors[] = 'Plik jest za duży (max 5MB).';
        }
        
        if (empty($errors)) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = 'receipt_' . time() . '_' . uniqid() . '.' . $ext;
            $uploadPath = RECEIPTS_PATH . '/' . $fileName;
            
            if (!is_dir(RECEIPTS_PATH)) {
                @mkdir(RECEIPTS_PATH, 0755, true);
            }
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $receiptPath = 'uploads/receipts/' . $fileName;
            } else {
                $errors[] = 'Błąd uploadu pliku.';
            }
        }
    }
    
    // Dodaj wydatek
    if (empty($errors)) {
        try {
            $period = date('Y-m-01', strtotime($date));
            $createdBy = $_SESSION['user_id'] ?? null;

            if ($isCompanyExpense) {
                $stmt = $pdo->prepare("
                    INSERT INTO finance_items
                        (item_type, category, subcategory, project_id, etap_id, company_name, title, description,
                         doc_number, issue_date, payment_date, amount_net, amount_gross, currency, file_path, status,
                         created_at, created_by)
                    VALUES
                        ('FIXED_COST', ?, ?, ?, ?, NULL, ?, ?, NULL, ?, ?, ?, ?, 'PLN', ?, 'approved', NOW(), ?)
                ");
                $stmt->execute([
                    $companyCategory !== '' ? $companyCategory : $defaultCompanyCategory,
                    $companySubcategory !== '' ? $companySubcategory : null,
                    $projectId,
                    $costNodeId ?: null,
                    mb_substr($description, 0, 255),
                    $description,
                    $date,
                    $date,
                    $amount,
                    $amount,
                    $receiptPath,
                    $createdBy,
                ]);
                $itemId = (int)$pdo->lastInsertId();
                logEvent("Dodano wydatek firmowy finance_items #{$itemId}, kwota {$amount} PLN", 'INFO');
                header("Location: " . $returnUrl);
                exit;
            }

            $expenseStatus = 'pending';
            $approvedBy = null;
            $approvedAt = null;

            // Admin auto-zatwierdza zawsze; pracownik auto-zatwierdza tylko przy portfelu firmowym.
            if ($isAdminUser || $paymentSource === 'wallet') {
                $expenseStatus = 'approved';
                $approvedBy = $createdBy;
                $approvedAt = date('Y-m-d H:i:s');
            }

            // Gdy wybrano portfel – waliduj dostępność całej puli firmowej przed INSERT.
            $walletAdvanceId = null;
            if ($paymentSource === 'wallet') {
                $walletAvailable = walletGetCompanyAvailableBalance($pdo, $workerId);
                if ($walletAvailable <= 0.01) {
                    $errors[] = 'Pracownik nie ma otwartej zaliczki firmowej z dostępnymi środkami.';
                } elseif ((float)$amount > $walletAvailable + 0.001) {
                    $errors[] = 'Kwota wydatku (' . number_format((float)$amount, 2, ',', ' ') . ' zł) przekracza dostępne środki w portfelu (' . number_format($walletAvailable, 2, ',', ' ') . ' zł).';
                }
            }

            if (empty($errors)) {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO worker_expenses
                    (worker_id, project_id, cost_node_id, expense_type, date, period, amount, description, company_category, company_subcategory,
                     receipt_path, status, paid_by_employee, payment_source, wallet_advance_id,
                     created_by_user_id, approved_by_user_id, approved_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $workerId, $projectId, $costNodeId, $expenseType,
                    $date, $period, $amount, $description,
                    $companyCategory !== '' ? $companyCategory : null,
                    $companySubcategory !== '' ? $companySubcategory : null,
                    $receiptPath,
                    $expenseStatus, $paidByEmployee,
                    ($paymentSource === 'wallet' ? 'wallet' : 'employee'),
                    $walletAdvanceId,
                    $createdBy, $approvedBy, $approvedAt,
                ]);
                $expenseId = (int)$pdo->lastInsertId();

                // Portfel firmowy – od razu rozbij wydatek FIFO na najstarsze otwarte zaliczki.
                if ($paymentSource === 'wallet') {
                    $allocationResult = walletAllocateExpenseToCompanyAdvances(
                        $pdo,
                        $workerId,
                        $expenseId,
                        (float)$amount,
                        $date,
                        $description,
                        $createdBy
                    );
                    $allocationSummary = implode(', ', array_map(static function ($allocation) {
                        return '#' . (int)$allocation['advance_id'] . ': ' . number_format((float)$allocation['amount'], 2, ',', ' ') . ' zł';
                    }, $allocationResult['allocations']));
                    logEvent("Dodano wydatek #{$expenseId} z portfela FIFO: pracownik ID {$workerId}, kwota {$amount} PLN, alokacje: {$allocationSummary}", 'INFO');
                } else {
                    logEvent("Admin dodał wydatek #{$expenseId}: pracownik ID $workerId, kwota $amount PLN", 'INFO');
                }

                $pdo->commit();
                header("Location: " . $returnUrl);
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Błąd dodawania wydatku. Spróbuj ponownie.';
            logEvent("Błąd dodawania wydatku: " . $e->getMessage(), 'ERROR');
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj Wydatek</title>
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
        .alert ul {
            margin: 10px 0 0 20px;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group .required {
            color: #dc3545;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border 0.2s;
            font-family: inherit;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        .form-group .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }
        .file-upload {
            border: 2px dashed #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background: #f8f9fa;
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
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
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
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
    </style>
<?php echo spxCompanyCostComboRenderAssets(); ?>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
                <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    <a href="<?php echo url('finanse.wydatki'); ?>">Wydatki</a> /
                    Dodaj Wydatek
                </div>
                <h1>Dodaj Wydatek</h1>
                <p>Nowy koszt firmowy albo wydatek pracownika</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo e($returnUrl); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        
        <div class="card">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Sukces!</strong> Wydatek został dodany i oczekuje na zatwierdzenie.
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Błąd!</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!$success): ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="return_url" value="<?php echo e($returnUrl); ?>">
                    <div class="form-row">
                        <?php if ($isAdminUser): ?>
                            <div class="form-group">
                                <label>Firma / pracownik</label>
                                <select name="worker_id" id="worker_select">
                                    <?php $selectedWorkerValue = (string)($_POST['worker_id'] ?? $_GET['worker_id'] ?? 'company'); ?>
                                    <option value="company" <?php echo ($selectedWorkerValue === '' || $selectedWorkerValue === 'company') ? 'selected' : ''; ?>>Firma</option>
                                    <?php foreach ($workers as $worker): ?>
                                        <option value="<?php echo $worker['id']; ?>" 
                                                <?php echo ($selectedWorkerValue == $worker['id']) ? 'selected' : ''; ?>>
                                            <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="help-text">Zostaw „Firma”, jeśli koszt nie wymaga rozliczenia konkretnego pracownika.</div>
                            </div>
                        <?php else: ?>
                            <!-- Pracownik - automatycznie wybrany -->
                            <input type="hidden" name="worker_id" value="<?php echo $currentWorkerId; ?>">
                            <div class="form-group">
                                <label>Pracownik</label>
                                <input type="text" 
                                       value="<?php echo e($_SESSION['worker_name'] ?? 'Ty'); ?>" 
                                       disabled
                                       style="background: #f0f0f0; color: #666;">
                                <div class="help-text">Dodajesz wydatek dla siebie.</div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label>
                                Data <span class="required">*</span>
                            </label>
                            <input type="date" 
                                   name="date" 
                                   value="<?php echo e($_POST['date'] ?? date('Y-m-d')); ?>"
                                   max="<?php echo date('Y-m-d'); ?>"
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                Kwota (PLN) <span class="required">*</span>
                            </label>
                            <input type="number" 
                                   name="amount" 
                                   step="0.01" 
                                   min="0.01"
                                   value="<?php echo e($_POST['amount'] ?? ''); ?>"
                                   placeholder="0.00"
                                   required>
                        </div>
                        
                        <div class="form-group js-worker-expense-field">
                            <label>
                                Typ wydatku <span class="required">*</span>
                            </label>
                            <select name="expense_type" required>
                                <option value="cash_other" <?php echo (($_POST['expense_type'] ?? 'cash_other') == 'cash_other') ? 'selected' : ''; ?>>
                                    Inne (np. 50 zł operatorowi za przewóz)
                                </option>
                                <option value="cash_purchase" <?php echo (($_POST['expense_type'] ?? '') == 'cash_purchase') ? 'selected' : ''; ?>>
                                    Zakup drobny / paragon
                                </option>
                                <option value="invoice_candidate" <?php echo (($_POST['expense_type'] ?? '') == 'invoice_candidate') ? 'selected' : ''; ?>>
                                    Zakup na fakturę (będzie rozliczony w Dokumentach)
                                </option>
                            </select>
                            <div class="help-text">Określ charakter wydatku</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Kategoria i podkategoria firmowa</label>
                            <?php
                            $postedCompanyCategory = $_POST['company_category'] ?? $defaultCompanyCategory;
                            $postedCompanySubcategory = $_POST['company_subcategory'] ?? '';
                            echo spxCompanyCostComboRender([
                                'id_prefix' => 'expense-company',
                                'category_name' => 'company_category',
                                'subcategory_name' => 'company_subcategory',
                                'category_select_id' => 'expense-company-category-select',
                                'subcategory_select_id' => 'expense-company-subcategory-select',
                                'selected_category' => $postedCompanyCategory,
                                'selected_subcategory' => $postedCompanySubcategory,
                                'category_labels' => $companyCategoryLabels,
                                'subcategories_by_category' => $companySubcategoriesByCategory,
                                'all_subcategory_hints' => $companySubcategoryHints,
                                'allow_empty_category' => false,
                                'empty_category_label' => '— wybierz kategorię —',
                                'empty_subcategory_label' => '— brak podkategorii —',
                                'placeholder_label' => 'Wybierz kategorię i podkategorię kosztu firmy',
                                'help_text' => '',
                            ]);
                            ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Projekt</label>
                            <select name="project_id" id="project_select">
                                <?php echo spxRenderProjectOptions($projects, $selectedProjectId, 'Bez projektu'); ?>
                            </select>
                            <div class="help-text">Opcjonalnie przypisz koszt do projektu.</div>
                        </div>
                        
                        <div class="form-group">
                            <label>Etap projektu</label>
                            <select
                                name="cost_node_id"
                                id="cost_node_select"
                                data-selected-value="<?php echo e((string)($selectedCostNodeId ?? '')); ?>"
                                <?php echo $selectedProjectId ? '' : 'disabled'; ?>
                            >
                                <?php if ($selectedProjectId): ?>
                                    <option value="">Brak (wydatek ogólny)</option>
                                <?php else: ?>
                                    <option value="">Wybierz najpierw projekt</option>
                                <?php endif; ?>
                                <?php foreach ($cost_nodes as $node): ?>
                                    <option value="<?php echo $node['id']; ?>" 
                                            <?php echo ((string)($selectedCostNodeId ?? '') === (string)$node['id']) ? 'selected' : ''; ?>>
                                        <?php echo e(($node['parent_id'] ? '↳ ' : '') . $node['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text">Etap jest dostępny tylko dla wybranego projektu.</div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            Opis Wydatku <span class="required">*</span>
                        </label>
                        <textarea name="description" 
                                  placeholder="Np. paliwo, noclegi, materiały budowlane..."
                                  required><?php echo e($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Paragon / Faktura</label>
                        <div class="file-upload">
                            <input type="file" 
                                   name="receipt" 
                                   accept="image/jpeg,image/jpg,image/png,application/pdf">
                            <div class="help-text" style="margin-top: 10px;">
                                Opcjonalnie. Formaty: JPG, PNG, PDF. Max 5MB.
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group js-worker-expense-field" style="border-top: 1px solid #e0e0e0; padding-top: 20px; margin-top: 20px;">
                        <label style="display:block; font-weight: 700; margin-bottom: 10px;">
                            Źródło rozliczenia
                        </label>
                        <?php $postedPaymentSource = $_POST['payment_source'] ?? 'wallet'; ?>
                        <label style="display: flex; align-items: flex-start; cursor: pointer; user-select: none; margin-bottom: 10px;">
                            <input type="radio"
                                   name="payment_source"
                                   value="wallet"
                                   <?php echo $postedPaymentSource === 'wallet' ? 'checked' : ''; ?>
                                   style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer; margin-top: 2px;">
                            <span>
                                <strong>Portfel firmowy (zaliczka firmowa)</strong><br>
                                <span class="help-text">Po zatwierdzeniu admin wybierze konkretną zaliczkę firmową do rozliczenia.</span>
                            </span>
                        </label>
                        <label style="display: flex; align-items: flex-start; cursor: pointer; user-select: none;">
                            <input type="radio"
                                   name="payment_source"
                                   value="employee"
                                   <?php echo $postedPaymentSource === 'employee' ? 'checked' : ''; ?>
                                   style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer; margin-top: 2px;">
                            <span>
                                <strong>Własne środki pracownika</strong><br>
                                <span class="help-text">Po zatwierdzeniu koszt może wejść do zwrotu.</span>
                            </span>
                        </label>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            Dodaj Wydatek
                        </button>
                        <a href="<?php echo e($returnUrl); ?>" class="btn btn-secondary">
                            Anuluj
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        function expenseUpdateCompanyCategoryRequirement() {
            const categorySelect = document.getElementById('expense-company-category-select');
            if (!categorySelect) return;

            categorySelect.required = true;
        }

        function expenseToggleWorkerFields() {
            const workerSelect = document.getElementById('worker_select');
            const isWorkerExpense = !workerSelect || (workerSelect.value !== '' && workerSelect.value !== 'company');
            document.querySelectorAll('.js-worker-expense-field').forEach(function(field) {
                field.style.display = isWorkerExpense ? '' : 'none';
            });
        }

        function loadCostNodes(projectId, selectedCostNodeId = '') {
            const costNodeSelect = document.getElementById('cost_node_select');

            if (!projectId) {
                costNodeSelect.innerHTML = '<option value="">Wybierz najpierw projekt</option>';
                costNodeSelect.disabled = true;
                return;
            }

            costNodeSelect.innerHTML = '<option value="">Ładowanie etapów...</option>';
            costNodeSelect.disabled = true;

            fetch(`../../api/get-cost-nodes.php?project_id=${encodeURIComponent(projectId)}`)
                .then(response => response.json())
                .then(data => {
                    costNodeSelect.innerHTML = '<option value="">Brak (wydatek ogólny)</option>';
                    if (data.success && Array.isArray(data.nodes)) {
                        data.nodes.forEach(node => {
                            const option = document.createElement('option');
                            option.value = node.id;
                            option.textContent = (node.parent_id ? '↳ ' : '') + node.name;
                            if (selectedCostNodeId && String(node.id) === String(selectedCostNodeId)) {
                                option.selected = true;
                            }
                            costNodeSelect.appendChild(option);
                        });
                    }
                    costNodeSelect.disabled = false;
                })
                .catch(() => {
                    costNodeSelect.innerHTML = '<option value="">Błąd ładowania etapów</option>';
                    costNodeSelect.disabled = true;
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const projectSelect = document.getElementById('project_select');
            const costNodeSelect = document.getElementById('cost_node_select');
            const initialProjectId = projectSelect ? projectSelect.value : '';
            const initialCostNodeId = costNodeSelect ? (costNodeSelect.dataset.selectedValue || '') : '';
            const workerSelect = document.getElementById('worker_select');

            loadCostNodes(initialProjectId, initialCostNodeId);
            expenseUpdateCompanyCategoryRequirement();
            expenseToggleWorkerFields();

            if (projectSelect) {
                projectSelect.addEventListener('change', function() {
                    loadCostNodes(this.value, '');
                    expenseUpdateCompanyCategoryRequirement();
                });
            }

            if (workerSelect) {
                workerSelect.addEventListener('change', expenseToggleWorkerFields);
            }
        });
    </script>
</body>
</html>
