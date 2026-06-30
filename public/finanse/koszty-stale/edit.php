<?php
/**
 * BRYGAD ERP v3.0 - Edycja kosztu firmowego (FIXED_COST)
 * TYLKO DLA ADMINA
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__) . '/_company-cost-categories.php';
require_once dirname(__DIR__) . '/_company-cost-category-combo.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success = false;

// Pobierz ID kosztu
$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($itemId <= 0) {
    $_SESSION['error'] = 'Nieprawidłowe ID kosztu.';
    header('Location: ' . url('finanse.koszty-stale'));
    exit;
}

// Pobierz dane kosztu
try {
    $stmt = $pdo->prepare("SELECT * FROM finance_items WHERE id = ? AND item_type = 'FIXED_COST'");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    
    if (!$item) {
        $_SESSION['error'] = 'Koszt firmowy nie został znaleziony.';
        header('Location: ' . url('finanse.koszty-stale'));
        exit;
    }
} catch (PDOException $e) {
    logEvent("Błąd pobierania kosztu ID $itemId: " . $e->getMessage(), 'ERROR');
    $_SESSION['error'] = 'Błąd pobierania kosztu.';
    header('Location: ' . url('finanse.koszty-stale'));
    exit;
}

$companyCostDictionary = ksCompanyCostLoadDictionary($pdo);
$categoryLabels = ksCompanyCostCategoryLabels($companyCostDictionary);
$categoryHints = array_keys($categoryLabels);
$subcategoriesByCategory = ksCompanyCostSubcategoriesByCategory($companyCostDictionary);
$subcategoryHints = ksCompanyCostSubcategoryNames($companyCostDictionary);

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount_net = trim($_POST['amount_net'] ?? '0');
    $amount_vat = trim($_POST['amount_vat'] ?? '0');
    $issue_date = $_POST['issue_date'] ?? '';
    $payment_date = $_POST['payment_date'] ?: null;
    $doc_number = trim($_POST['doc_number'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $rawCategory = trim($_POST['category'] ?? ($item['category'] ?? ''));
    $category = $rawCategory;
    $rawSubcategory = trim($_POST['subcategory'] ?? ($item['subcategory'] ?? ''));
    $subcategory = $rawSubcategory;
    $status = $_POST['status'] ?? 'approved';
    
    // Walidacja
    if (empty($title)) {
        $errors[] = 'Tytuł kosztu jest wymagany.';
    }
    if (!is_numeric($amount_net) || $amount_net <= 0) {
        $errors[] = 'Kwota netto musi być liczbą dodatnią.';
    }
    if (empty($issue_date)) {
        $errors[] = 'Data jest wymagana.';
    }

    $category = mb_substr($category, 0, 50);
    $subcategory = mb_substr($subcategory, 0, 120);
    
    // Oblicz kwotę brutto
    $amount_vat = is_numeric($amount_vat) ? (float)$amount_vat : 0;
    $amount_gross = (float)$amount_net + $amount_vat;
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE finance_items SET
                    title = ?,
                    description = ?,
                    amount_net = ?,
                    amount_gross = ?,
                    issue_date = ?,
                    payment_date = ?,
                    doc_number = ?,
                    company_name = ?,
                    category = ?,
                    subcategory = ?,
                    status = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $title,
                $description ?: null,
                $amount_net,
                $amount_gross,
                $issue_date,
                $payment_date,
                $doc_number ?: null,
                $company_name ?: null,
                $category !== '' ? $category : null,
                $subcategory !== '' ? $subcategory : null,
                $status,
                $itemId
            ]);
            
            logEvent("Zaktualizowano koszt firmowy ID {$itemId} ({$title}) przez user ID " . $_SESSION['user_id'], 'INFO');
            
            $_SESSION['success'] = 'Koszt firmowy został zaktualizowany.';
            header('Location: ' . url('finanse.koszty-stale'));
            exit;
            
        } catch (PDOException $e) {
            logEvent("Błąd aktualizacji kosztu firmowego ID {$itemId}: " . $e->getMessage(), 'ERROR');
            $errors[] = 'Błąd aktualizacji kosztu firmowego.';
        }
    }
}

// Oblicz VAT dla widoku
$current_vat = $item['amount_gross'] - $item['amount_net'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edycja kosztu firmowego - <?php echo e(APP_NAME); ?></title>
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
        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }
        input[type="text"],
        input[type="date"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: #ea580c;
        }
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #ea580c 0%, #dc2626 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.4);
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        .btn-secondary:hover {
            background: #4b5563;
        }
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #fee;
            border: 2px solid #fcc;
            color: #c00;
        }
        .alert-success {
            background: #efe;
            border: 2px solid #cfc;
            color: #060;
        }
        .required {
            color: #dc2626;
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
                    <a href="<?php echo url('finanse.koszty-stale'); ?>">Wydatki firmowe</a> /
                    Edytuj wydatek firmowy
                </div>
                <h1>Edytuj koszt firmowy</h1>
                <p>Edycja wpisu w kosztach firmowych</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.koszty-stale'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo e($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                Koszt firmowy został zaktualizowany.
            </div>
        <?php endif; ?>
        
        <div class="card">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">Tytuł kosztu <span class="required">*</span></label>
                    <input type="text" id="title" name="title" required value="<?php echo e($item['title']); ?>">
                </div>

                <div class="form-row">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Kategoria i podkategoria</label>
                        <?php
                        $editCatVal = $_POST['category'] ?? ($item['category'] ?? '');
                        $editSubcatVal = $_POST['subcategory'] ?? ($item['subcategory'] ?? '');
                        echo spxCompanyCostComboRender([
                            'id_prefix' => 'fixed-cost-edit',
                            'category_name' => 'category',
                            'subcategory_name' => 'subcategory',
                            'category_select_id' => 'edit-cat-select',
                            'subcategory_select_id' => 'edit-subcat-select',
                            'selected_category' => $editCatVal,
                            'selected_subcategory' => $editSubcatVal,
                            'category_labels' => $categoryLabels,
                            'subcategories_by_category' => $subcategoriesByCategory,
                            'all_subcategory_hints' => $subcategoryHints,
                            'allow_empty_category' => true,
                            'empty_category_label' => '— bez kategorii —',
                            'empty_subcategory_label' => '— brak podkategorii —',
                            'placeholder_label' => 'Wybierz kategorię i podkategorię kosztu',
                            'help_text' => '',
                        ]);
                        ?>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="company_name">Kontrahent</label>
                        <input type="text" id="company_name" name="company_name" value="<?php echo e($item['company_name']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="doc_number">Numer dokumentu</label>
                        <input type="text" id="doc_number" name="doc_number" value="<?php echo e($item['doc_number']); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description">Opis</label>
                    <textarea id="description" name="description"><?php echo e($item['description']); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="amount_net">Kwota netto (PLN) <span class="required">*</span></label>
                        <input type="number" id="amount_net" name="amount_net" step="0.01" required value="<?php echo $item['amount_net']; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="amount_vat">Kwota VAT (PLN)</label>
                        <input type="number" id="amount_vat" name="amount_vat" step="0.01" value="<?php echo $current_vat; ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="issue_date">Data wystawienia <span class="required">*</span></label>
                        <input type="date" id="issue_date" name="issue_date" required value="<?php echo $item['issue_date']; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="payment_date">Data płatności</label>
                        <input type="date" id="payment_date" name="payment_date" value="<?php echo $item['payment_date']; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="approved" <?php echo $item['status'] === 'approved' ? 'selected' : ''; ?>>Zatwierdzony</option>
                        <option value="pending" <?php echo $item['status'] === 'pending' ? 'selected' : ''; ?>>Oczekujący</option>
                    </select>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                    <a href="<?php echo url('finanse.koszty-stale'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
