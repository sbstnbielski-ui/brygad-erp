<?php
/**
 * BRYGAD ERP v3.0 - Edycja Wydatku Pracownika
 * Umożliwia edycję wydatku (kwota, data, opis, projekt, status)
 * TYLKO DLA ADMINA
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__) . '/_company-cost-categories.php';
require_once dirname(__DIR__) . '/_company-cost-category-combo.php';
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może edytować wydatki

$pdo = getDbConnection();
$errors = [];
$success = false;
$defaultReturnUrl = url('finanse.wydatki');

$companyCostDictionary = ksCompanyCostLoadDictionary($pdo);
$companyCategoryLabels = ksCompanyCostCategoryLabels($companyCostDictionary);
$companySubcategoriesByCategory = ksCompanyCostSubcategoriesByCategory($companyCostDictionary);
$companySubcategoryHints = ksCompanyCostSubcategoryNames($companyCostDictionary);

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

// Pobierz ID wydatku
$expenseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($expenseId <= 0) {
    header('Location: ' . $returnUrl);
    exit;
}

// Pobierz dane wydatku
try {
    $stmt = $pdo->prepare("
        SELECT 
            we.*,
            w.first_name,
            w.last_name,
            p.name as project_name
        FROM worker_expenses we
        JOIN workers w ON we.worker_id = w.id
        LEFT JOIN projects p ON we.project_id = p.id
        WHERE we.id = ?
    ");
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch();
    
    if (!$expense) {
        $_SESSION['error'] = 'Wydatek nie został znaleziony.';
        header('Location: ' . $returnUrl);
        exit;
    }
} catch (PDOException $e) {
    logEvent("Błąd pobierania wydatku ID $expenseId: " . $e->getMessage(), 'ERROR');
    $_SESSION['error'] = 'Błąd pobierania wydatku.';
    header('Location: ' . $returnUrl);
    exit;
}

$isWalletLinkedExpense = !empty($expense['wallet_advance_id']) || !empty($expense['wallet_ledger_id']);

// Pobierz listę projektów dla selecta
try {
    $projectsStmt = $pdo->query("
        SELECT id, name 
        FROM projects 
        WHERE archived_at IS NULL 
        ORDER BY name
    ");
    $projects = $projectsStmt->fetchAll();
} catch (PDOException $e) {
    $projects = [];
}

$loadCostNodes = static function (PDO $pdo, ?int $projectId): array {
    if (!$projectId) {
        return [];
    }
    try {
        $nodesStmt = $pdo->prepare("
            SELECT id, name, parent_id
            FROM project_cost_nodes
            WHERE project_id = ? AND is_active = 1
            ORDER BY parent_id IS NULL DESC, sort_order, name
        ");
        $nodesStmt->execute([$projectId]);
        return $nodesStmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
};

$selectedProjectId = !empty($expense['project_id']) ? (int)$expense['project_id'] : null;
$selectedCostNodeId = !empty($expense['cost_node_id']) ? (int)$expense['cost_node_id'] : null;
$costNodes = $loadCostNodes($pdo, $selectedProjectId);

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'] ?? '';
    $amount = $_POST['amount'] ?? '';
    $description = $_POST['description'] ?? '';
    $projectId = isset($_POST['project_id']) && $_POST['project_id'] !== '' ? (int)$_POST['project_id'] : null;
    $costNodeId = isset($_POST['cost_node_id']) && $_POST['cost_node_id'] !== '' ? (int)$_POST['cost_node_id'] : null;
    $selectedProjectId = $projectId;
    $selectedCostNodeId = $costNodeId;
    $costNodes = $loadCostNodes($pdo, $selectedProjectId);
    $status = $_POST['status'] ?? 'pending';
    $expenseType = $_POST['expense_type'] ?? 'cash_other';
    $companyCategory = mb_substr(trim((string)($_POST['company_category'] ?? '')), 0, 50);
    $companySubcategory = mb_substr(trim((string)($_POST['company_subcategory'] ?? '')), 0, 120);

    if ($isWalletLinkedExpense) {
        // Dla wydatków spiętych z portfelem blokujemy zmianę pól finansowych,
        // aby nie rozjechać salda zaliczek.
        $date = $expense['date'];
        $amount = $expense['amount'];
        $status = 'approved';
    }

    // Walidacja
    if (!$isWalletLinkedExpense && empty($date)) {
        $errors[] = 'Data jest wymagana.';
    }
    if (!$isWalletLinkedExpense && (empty($amount) || !is_numeric($amount) || $amount <= 0)) {
        $errors[] = 'Kwota musi być liczbą dodatnią.';
    }
    if (empty($description)) {
        $errors[] = 'Opis jest wymagany.';
    }
    if (!$isWalletLinkedExpense && !in_array($status, ['pending', 'approved', 'rejected', 'reimbursed'])) {
        $errors[] = 'Nieprawidłowy status.';
    }
    if ($costNodeId && !$projectId) {
        $errors[] = 'Aby wybrać etap, najpierw wybierz projekt.';
    }
    if (!$projectId && $companyCategory === '') {
        $errors[] = 'Dla wydatku bez projektu wybierz kategorię firmową.';
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
    
    if (empty($errors)) {
        try {
            // Oblicz okres (pierwszy dzień miesiąca)
            $period = date('Y-m-01', strtotime($date));
            
            $stmt = $pdo->prepare("
                UPDATE worker_expenses SET
                    date = ?,
                    period = ?,
                    amount = ?,
                    description = ?,
                    project_id = ?,
                    cost_node_id = ?,
                    company_category = ?,
                    company_subcategory = ?,
                    status = ?,
                    expense_type = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $date,
                $period,
                $amount,
                $description,
                $projectId,
                $costNodeId,
                $companyCategory !== '' ? $companyCategory : null,
                $companySubcategory !== '' ? $companySubcategory : null,
                $status,
                $expenseType,
                $expenseId
            ]);
            
            logEvent("Zaktualizowano wydatek ID $expenseId przez user ID " . $_SESSION['user_id'], 'INFO');
            
            $_SESSION['success'] = 'Wydatek został zaktualizowany.';
            header('Location: ' . $returnUrl);
            exit;
            
        } catch (PDOException $e) {
            logEvent("Błąd aktualizacji wydatku ID $expenseId: " . $e->getMessage(), 'ERROR');
            $errors[] = 'Błąd aktualizacji wydatku: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edycja Wydatku';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - BRYGAD ERP</title>
    <link rel="stylesheet" href="/assets/css/style.css">
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
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: var(--text-main); }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 6px;
            font-size: 13px; font-family: inherit; color: var(--text-main); background: white; transition: border-color 0.15s;
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .help-text { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
        .btn-group { display: flex; gap: 10px; margin-top: 20px; }
        .btn { padding: 9px 22px; border-radius: 7px; font-weight: 600; font-size: 13px; cursor: pointer; border: 1px solid transparent; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; font-family: inherit; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: var(--border); }
        .btn-secondary:hover { background: #f9fafb; }
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); padding: 32px; margin-bottom: 16px; }
        .error { background: #fef2f2; border-left: 4px solid #dc2626; padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; color: #991b1b; }
        .warning { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; color: #92400e; }
    </style>
<?php echo spxCompanyCostComboRenderAssets(); ?>
</head>
<body>
    <?php include __DIR__ . '/../../includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    <a href="<?php echo url('finanse.wydatki'); ?>">Wydatki</a> /
                    Edytuj Wydatek
                </div>
                <h1>Edytuj Wydatek</h1>
                <p>Pracownik: <?php echo e($expense['first_name'] . ' ' . $expense['last_name']); ?></p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.wydatki'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo e($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($isWalletLinkedExpense): ?>
            <div class="warning">
                Ten wydatek jest rozliczony z portfela pracownika (zaliczka #<?php echo (int)$expense['wallet_advance_id']; ?>).
                Edycja pól finansowych (data, kwota, status) jest zablokowana dla spójności salda.
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="return_url" value="<?php echo e($returnUrl); ?>">
            <div class="form-group">
                <label for="date">Data wydatku*</label>
                <input type="date" name="date" id="date" value="<?php echo e($expense['date']); ?>" <?php echo $isWalletLinkedExpense ? 'disabled' : 'required'; ?>>
            </div>
            
            <div class="form-group">
                <label for="amount">Kwota (PLN)*</label>
                <input type="number" name="amount" id="amount" step="0.01" value="<?php echo e($expense['amount']); ?>" <?php echo $isWalletLinkedExpense ? 'disabled' : 'required'; ?>>
            </div>
            
            <div class="form-group">
                <label for="description">Opis*</label>
                <textarea name="description" id="description" required><?php echo e($expense['description']); ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="expense_type">Typ wydatku</label>
                <select name="expense_type" id="expense_type">
                    <option value="cash_other" <?php echo $expense['expense_type'] === 'cash_other' ? 'selected' : ''; ?>>Inne (gotówka)</option>
                    <option value="fuel" <?php echo $expense['expense_type'] === 'fuel' ? 'selected' : ''; ?>>Paliwo</option>
                    <option value="material" <?php echo $expense['expense_type'] === 'material' ? 'selected' : ''; ?>>Materiały</option>
                    <option value="parking" <?php echo $expense['expense_type'] === 'parking' ? 'selected' : ''; ?>>Parking</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="project_id">Projekt</label>
                <select name="project_id" id="project_id">
                    <option value="">-- Bez projektu (koszt firmy) --</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>" <?php echo ((string)($selectedProjectId ?? '') === (string)$project['id']) ? 'selected' : ''; ?>>
                            <?php echo e($project['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="help-text">Bez projektu oznacza koszt firmy i wymaga kategorii firmowej.</div>
            </div>
            
            <div class="form-group">
                <label for="cost_node_id">Etap</label>
                <select
                    name="cost_node_id"
                    id="cost_node_id"
                    data-selected-value="<?php echo e((string)($selectedCostNodeId ?? '')); ?>"
                    <?php echo $selectedProjectId ? '' : 'disabled'; ?>
                >
                    <?php if ($selectedProjectId): ?>
                        <option value="">-- Brak przypisania --</option>
                    <?php else: ?>
                        <option value="">-- Wybierz najpierw projekt --</option>
                    <?php endif; ?>
                    <?php foreach ($costNodes as $node): ?>
                        <option value="<?php echo $node['id']; ?>" <?php echo ((string)($selectedCostNodeId ?? '') === (string)$node['id']) ? 'selected' : ''; ?>>
                            <?php echo e(($node['parent_id'] ? '↳ ' : '') . $node['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Kategoria i podkategoria firmowa</label>
                <?php
                $currentCompanyCategory = $_POST['company_category'] ?? ($expense['company_category'] ?? '');
                $currentCompanySubcategory = $_POST['company_subcategory'] ?? ($expense['company_subcategory'] ?? '');
                echo spxCompanyCostComboRender([
                    'id_prefix' => 'expense-edit-company',
                    'category_name' => 'company_category',
                    'subcategory_name' => 'company_subcategory',
                    'category_select_id' => 'expense_company_category',
                    'subcategory_select_id' => 'expense_company_subcategory',
                    'selected_category' => $currentCompanyCategory,
                    'selected_subcategory' => $currentCompanySubcategory,
                    'category_labels' => $companyCategoryLabels,
                    'subcategories_by_category' => $companySubcategoriesByCategory,
                    'all_subcategory_hints' => $companySubcategoryHints,
                    'allow_empty_category' => true,
                    'empty_category_label' => '— wybierz, jeśli to koszt firmy —',
                    'empty_subcategory_label' => '— brak podkategorii —',
                    'placeholder_label' => 'Wybierz kategorię i podkategorię kosztu firmy',
                    'help_text' => '',
                ]);
                ?>
            </div>
            
            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" id="status" <?php echo $isWalletLinkedExpense ? 'disabled' : ''; ?>>
                    <option value="pending" <?php echo $expense['status'] === 'pending' ? 'selected' : ''; ?>>Oczekuje</option>
                    <option value="approved" <?php echo $expense['status'] === 'approved' ? 'selected' : ''; ?>>Zatwierdzony</option>
                    <option value="reimbursed" <?php echo $expense['status'] === 'reimbursed' ? 'selected' : ''; ?>>Zwrócony</option>
                    <option value="rejected" <?php echo $expense['status'] === 'rejected' ? 'selected' : ''; ?>>Odrzucony</option>
                </select>
            </div>
            
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                <a href="<?php echo e($returnUrl); ?>" class="btn btn-secondary">Anuluj</a>
            </div>
        </form>
    </div>
    <script>
        function expenseEditUpdateCompanyCategoryRequirement() {
            const projectSelect = document.getElementById('project_id');
            const categorySelect = document.getElementById('expense_company_category');
            if (!projectSelect || !categorySelect) {
                return;
            }

            categorySelect.required = projectSelect.value === '';
        }

        function loadCostNodes(projectId, selectedCostNodeId = '') {
            const costNodeSelect = document.getElementById('cost_node_id');
            if (!costNodeSelect) {
                return;
            }

            if (!projectId) {
                costNodeSelect.innerHTML = '<option value="">-- Wybierz najpierw projekt --</option>';
                costNodeSelect.disabled = true;
                return;
            }

            costNodeSelect.innerHTML = '<option value="">Ładowanie etapów...</option>';
            costNodeSelect.disabled = true;

            fetch(`../../api/get-cost-nodes.php?project_id=${encodeURIComponent(projectId)}`)
                .then(response => response.json())
                .then(data => {
                    costNodeSelect.innerHTML = '<option value="">-- Brak przypisania --</option>';
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
                    costNodeSelect.innerHTML = '<option value="">-- Błąd ładowania --</option>';
                    costNodeSelect.disabled = true;
                });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const projectSelect = document.getElementById('project_id');
            const costNodeSelect = document.getElementById('cost_node_id');
            const initialProjectId = projectSelect ? projectSelect.value : '';
            const initialCostNodeId = costNodeSelect ? (costNodeSelect.dataset.selectedValue || '') : '';

            loadCostNodes(initialProjectId, initialCostNodeId);
            expenseEditUpdateCompanyCategoryRequirement();

            if (projectSelect) {
                projectSelect.addEventListener('change', function () {
                    loadCostNodes(this.value, '');
                    expenseEditUpdateCompanyCategoryRequirement();
                });
            }
        });
    </script>
</body>
</html>
