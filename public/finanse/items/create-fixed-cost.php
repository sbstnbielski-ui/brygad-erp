<?php
/**
 * BRYGAD ERP - Dodawanie Kosztu Stalego
 * 
 * Dostępne dla: tylko admin
 * Koszt stały = ZUS, podatki, abonamenty, ubezpieczenia itp.
 * KOSZTY STALE SA WYLACZNIE GLOBALNE - NIE MAJA project_id!
 * Nie wplywaja na rentownosc projektow.
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(__DIR__) . '/_company-cost-categories.php';
startSecureSession();
requireLogin();
requireAdmin(); // TYLKO ADMIN

$pdo = getDbConnection();
$errors = [];
$success_message = '';
$currentUserId = $_SESSION['user_id'] ?? null;

// Parametry
$return_url = $_GET['return_url'] ?? $_POST['return_url'] ?? null;

// KOSZTY STALE NIE MAJA PROJECT_ID - sa tylko globalne firmowe

// Kategorie kosztów stałych
$companyCostDictionary = ksCompanyCostLoadDictionary($pdo);
$categories = ksCompanyCostCategoryLabels($companyCostDictionary);
$categoryHints = array_keys($categories);
$subcategoriesByCategory = ksCompanyCostSubcategoriesByCategory($companyCostDictionary);
$subcategoryHints = ksCompanyCostSubcategoryNames($companyCostDictionary);
$defaultCostCategoryKey = isset($categories['inne']) ? 'inne' : (array_key_first($categories) ?: 'inne');

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // KOSZTY STALE NIGDY NIE MAJA PROJECT_ID
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount_net = trim($_POST['amount_net'] ?? '0');
    $amount_vat = trim($_POST['amount_vat'] ?? '0');
    $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
    $payment_date = $_POST['payment_date'] ?: null;
    $rawCat = trim($_POST['cost_category'] ?? $defaultCostCategoryKey);
    $cost_category = $rawCat;
    if ($cost_category === '') $cost_category = $defaultCostCategoryKey;
    $rawSubcat = trim($_POST['cost_subcategory'] ?? '');
    $cost_subcategory = $rawSubcat;
    $doc_number = trim($_POST['doc_number'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    
    // Walidacja
    if (empty($title)) {
        $errors[] = "Tytuł kosztu jest wymagany";
    }
    if (!is_numeric($amount_net) || $amount_net <= 0) {
        $errors[] = "Kwota netto musi być liczbą większą od 0";
    }
    if (empty($issue_date)) {
        $errors[] = "Data jest wymagana";
    }
    if ($cost_category === '') {
        $errors[] = "Kategoria kosztu jest wymagana";
    }

    $cost_category = mb_substr($cost_category, 0, 50);
    $cost_subcategory = mb_substr($cost_subcategory, 0, 120);
    
    // Oblicz kwotę brutto
    $amount_vat = is_numeric($amount_vat) ? (float)$amount_vat : 0;
    $amount_gross = (float)$amount_net + $amount_vat;
    
    // Obsługa uploadu pliku (opcjonalne)
    $file_path = null;
    if (!empty($_FILES['file']['name'])) {
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Dozwolone są tylko pliki: PDF, JPG, PNG";
        } elseif ($_FILES['file']['size'] > 10 * 1024 * 1024) {
            $errors[] = "Plik nie może być większy niż 10MB";
        } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Błąd podczas przesyłania pliku";
        } else {
            $upload_dir = dirname(dirname(__DIR__)) . '/uploads/fixed_costs';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = 'fixed_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $file_extension;
            $destination = $upload_dir . '/' . $file_name;
            
            if (move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
                $file_path = 'uploads/fixed_costs/' . $file_name;
            } else {
                $errors[] = "Błąd podczas zapisywania pliku";
            }
        }
    }
    
    if (empty($errors)) {
        try {
            // KOSZTY STALE NIGDY NIE MAJA PROJECT_ID - project_id = NULL
            // Tabela finance_items NIE MA kolumn: cost_category, amount_vat
            $stmt = $pdo->prepare("
                INSERT INTO finance_items (
                    item_type, category, subcategory, project_id, 
                    company_name, title, description, doc_number,
                    issue_date, payment_date,
                    amount_net, amount_gross,
                    currency, file_path,
                    status, created_by, created_at
                ) VALUES (
                    'FIXED_COST', ?, ?, NULL,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    'PLN', ?,
                    'approved', ?, NOW()
                )
            ");
            $stmt->execute([
                $cost_category,
                $cost_subcategory ?: null,
                $company_name ?: null,
                $title,
                $description ?: null,
                $doc_number ?: null,
                $issue_date,
                $payment_date,
                $amount_net,
                $amount_gross,
                $file_path,
                $currentUserId
            ]);
            
            $item_id = $pdo->lastInsertId();
            logEvent("Dodano koszt staly ID: {$item_id}, kategoria: {$cost_category}, kwota: {$amount_net} PLN", 'INFO');
            
            // Przekierowanie po sukcesie - zawsze do modulu Finanse (koszty stale sa globalne)
            if ($return_url) {
                header("Location: " . $return_url . (strpos($return_url, '?') !== false ? '&' : '?') . "success=fixed_cost_added");
            } else {
                header("Location: " . url('finanse') . "?success=fixed_cost_added");
            }
            exit;
        } catch (PDOException $e) {
            logEvent("Blad dodawania kosztu stalego: " . $e->getMessage(), 'ERROR');
            logEvent("SQL Error Code: " . $e->getCode(), 'ERROR');
            
            // Sprawdź typ błędu
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                $errors[] = "BLAD STRUKTURY BAZY: Tabela finance_items nie ma wymaganych kolumn. Skontaktuj sie z administratorem.";
            } elseif (strpos($e->getMessage(), "doesn't exist") !== false) {
                $errors[] = "BLAD BAZY: Tabela finance_items nie istnieje. Skontaktuj sie z administratorem.";
            } else {
                $errors[] = "Blad podczas zapisywania kosztu stalego: " . $e->getMessage();
            }
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
    <title><?php echo e(APP_NAME); ?> - Dodaj wydatek firmowy</title>
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

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 35px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            color: #991b1b;
        }
        .alert-info {
            background: #e0e7ff;
            border-left: 4px solid #4f46e5;
            color: #3730a3;
        }
        .alert ul {
            margin: 10px 0 0 20px;
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
            color: #dc2626;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #6b7280;
        }
        .form-group .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }
        .form-hidden-control {
            display: none !important;
        }
        .category-combo {
            position: relative;
            margin-top: 0;
        }
        .category-combo-trigger {
            width: 100%;
            min-height: 42px;
            border: 1px solid #d1d5db;
            background: #f8fafc;
            color: #1f2937;
            border-radius: 6px;
            padding: 10px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            text-align: left;
        }
        .category-combo-trigger:hover {
            border-color: #667eea;
            background: #eef2ff;
        }
        .category-combo-panel {
            display: none;
            position: absolute;
            z-index: 40;
            top: calc(100% + 6px);
            left: 0;
            width: min(720px, calc(100vw - 60px));
            min-height: 260px;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            box-shadow: 0 18px 40px rgba(15,23,42,0.18);
            overflow: hidden;
        }
        .category-combo.open .category-combo-panel {
            display: grid;
            grid-template-columns: minmax(220px, 0.9fr) minmax(280px, 1.1fr);
        }
        .category-combo-list,
        .category-combo-subcats {
            max-height: 320px;
            overflow-y: auto;
            padding: 8px;
        }
        .category-combo-list {
            border-right: 1px solid #e5e7eb;
            background: #f8fafc;
        }
        .category-combo-item,
        .category-combo-subitem {
            width: 100%;
            border: 0;
            background: transparent;
            color: #1f2937;
            border-radius: 6px;
            padding: 9px 10px;
            cursor: pointer;
            text-align: left;
            font: inherit;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        .category-combo-item:hover,
        .category-combo-item.active,
        .category-combo-subitem:hover {
            background: #eef2ff;
            color: #3730a3;
        }
        .category-combo-subitem.empty {
            color: #6b7280;
        }
        .category-combo-arrow {
            color: #9ca3af;
            font-size: 15px;
        }
        .category-combo-mobile-note {
            display: none;
            font-size: 12px;
            color: #6b7280;
            padding: 8px 10px 0;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        .btn {
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(75, 85, 99, 0.4);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .btn-danger {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
        }
        .btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
        }
        /* OCR Styles */
        .btn-ocr {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-ocr:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-ocr:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .ocr-status-loading {
            color: #667eea;
            font-weight: 500;
        }
        .ocr-status-success {
            color: #10b981;
            font-weight: 500;
        }
        .ocr-status-error {
            color: #ef4444;
            font-weight: 500;
        }
        .ocr-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        .project-badge {
            display: inline-block;
            padding: 8px 16px;
            background: #e0e7ff;
            color: #3730a3;
            border-radius: 8px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
        @media (max-width: 768px) {
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
            .category-combo-panel {
                position: static;
                width: 100%;
                margin-top: 6px;
            }
            .category-combo.open .category-combo-panel {
                grid-template-columns: 1fr;
            }
            .category-combo-list {
                border-right: 0;
                border-bottom: 1px solid #e5e7eb;
            }
            .category-combo-mobile-note {
                display: block;
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
                    <a href="<?php echo url('finanse.koszty-stale'); ?>">Wydatki firmowe</a> /
                    Dodaj wydatek firmowy
                </div>
                <h1>Dodaj wydatek firmowy</h1>
                <p>Nowy wydatek firmowy</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.koszty-stale'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        
        <div class="card">
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
            
            <div class="alert alert-info">
                <strong>Koszt firmowy</strong> — wydatek niezwiązany z fakturą zakupową, np. składki ZUS, podatki, abonamenty, ubezpieczenia. 
                Koszty firmowe nie wpływają na rentowność projektów.
            </div>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <?php if ($return_url): ?>
                    <input type="hidden" name="return_url" value="<?php echo e($return_url); ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Kategoria i podkategoria kosztu <span class="required">*</span></label>
                        <?php
                        $fcPostedCat = $_POST['cost_category'] ?? $defaultCostCategoryKey;
                        $fcKnownCats = array_keys($categories);
                        $fcIsCustom = !in_array($fcPostedCat, $fcKnownCats, true);
                        $fcPostedSubcat = $_POST['cost_subcategory'] ?? '';
                        $fcIsCustomSubcat = $fcPostedSubcat !== '' && !in_array($fcPostedSubcat, $subcategoryHints, true);
                        ?>
                        <select id="fc-cat-select" name="cost_category" required onchange="fcOnCatChange(this)" class="form-hidden-control" tabindex="-1" aria-hidden="true">
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?php echo e($key); ?>" <?php echo ($fcPostedCat === $key && !$fcIsCustom) ? 'selected' : ''; ?>>
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($fcIsCustom): ?>
                                <option value="<?php echo e($fcPostedCat); ?>" selected><?php echo e($fcPostedCat); ?></option>
                            <?php endif; ?>
                        </select>
                        <select id="fc-subcat-select" name="cost_subcategory" class="form-hidden-control" tabindex="-1" aria-hidden="true">
                            <option value="">— brak podkategorii —</option>
                            <?php foreach ($subcategoryHints as $hint): ?>
                                <option value="<?php echo e($hint); ?>" <?php echo ($fcPostedSubcat === $hint && !$fcIsCustomSubcat) ? 'selected' : ''; ?>>
                                    <?php echo e($hint); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($fcIsCustomSubcat): ?>
                                <option value="<?php echo e($fcPostedSubcat); ?>" selected><?php echo e($fcPostedSubcat); ?></option>
                            <?php endif; ?>
                        </select>

                        <div class="category-combo" id="fc-category-combo">
                            <button type="button" class="category-combo-trigger" id="fc-category-combo-trigger">
                                <span id="fc-category-combo-label">Wybierz kategorię i podkategorię w jednym oknie</span>
                                <span>▾</span>
                            </button>
                            <div class="category-combo-panel" id="fc-category-combo-panel">
                                <div class="category-combo-mobile-note">Kliknij kategorię, a niżej wybierz podkategorię.</div>
                                <div class="category-combo-list">
                                    <?php foreach ($categories as $key => $label): ?>
                                        <?php $subCount = count($subcategoriesByCategory[$key] ?? []); ?>
                                        <button type="button"
                                                class="category-combo-item"
                                                data-category-key="<?php echo e($key); ?>"
                                                data-category-label="<?php echo e($label); ?>">
                                            <span><?php echo e($label); ?></span>
                                            <span class="category-combo-arrow"><?php echo $subCount > 0 ? '›' : '✓'; ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="category-combo-subcats" id="fc-category-combo-subcats"></div>
                            </div>
                        </div>
                        <div class="help-text">Najedź na kategorię po lewej i wybierz podkategorię po prawej.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Data / Okres <span class="required">*</span></label>
                        <input type="date" name="issue_date" 
                               value="<?php echo e($_POST['issue_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Tytuł / Opis kosztu <span class="required">*</span></label>
                    <input type="text" name="title" 
                           value="<?php echo e($_POST['title'] ?? ''); ?>" 
                           placeholder="np. ZUS za styczeń 2026, Ubezpieczenie OC samochodu..." required>
                </div>
                
                <div class="form-row-3">
                    <div class="form-group">
                        <label>Kwota netto (PLN) <span class="required">*</span></label>
                        <input type="number" name="amount_net" step="0.01" min="0.01" 
                               value="<?php echo e($_POST['amount_net'] ?? ''); ?>" 
                               placeholder="0.00" required>
                    </div>
                    
                    <div class="form-group">
                        <label>VAT (PLN)</label>
                        <input type="number" name="amount_vat" step="0.01" min="0" 
                               value="<?php echo e($_POST['amount_vat'] ?? '0'); ?>" 
                               placeholder="0.00">
                        <div class="help-text">0 jeśli brak VAT</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Data zapłaty</label>
                        <input type="date" name="payment_date" 
                               value="<?php echo e($_POST['payment_date'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Numer dokumentu / Ref.</label>
                    <input type="text" name="doc_number" 
                           value="<?php echo e($_POST['doc_number'] ?? ''); ?>" 
                           placeholder="np. DRA-01/2026">
                </div>
                
                <div class="form-group">
                    <label>Kontrahent</label>
                    <input type="text" name="company_name" 
                           value="<?php echo e($_POST['company_name'] ?? ''); ?>" 
                           placeholder="np. ZUS, Urząd Skarbowy, PZU...">
                </div>
                
                <div class="form-group">
                    <label>Plik (opcjonalnie)</label>
                    <input type="file" name="file" id="file-input" accept=".pdf,.jpg,.jpeg,.png">
                    <div class="help-text">PDF, JPG lub PNG (max 10MB)</div>
                    
                    <!-- OCR Button -->
                    <div id="ocr-container" style="margin-top: 12px; display: none;">
                        <button type="button" id="ocr-scan-btn" class="btn-ocr">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="vertical-align: middle;">
                                <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z" stroke-width="2"/>
                                <path d="M12 8v4m0 4h.01" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            Skanuj fakturę AI (Gemini)
                        </button>
                        <div id="ocr-status" style="margin-top: 8px; font-size: 13px;"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Dodatkowy opis (opcjonalnie)</label>
                    <textarea name="description" rows="2" 
                              placeholder="Dodatkowe informacje..."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Dodaj koszt firmowy</button>
                    <button type="button" id="clear-btn" class="btn btn-danger">Wyczyść wszystko</button>
                    <a href="<?php echo url('finanse'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
    // ===== KATEGORIA / PODKATEGORIA INLINE =====
    const fcSubcategoriesByCategory = <?php echo json_encode($subcategoriesByCategory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const fcCategoryLabels = <?php echo json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function fcRefreshSubcategories(categoryValue, selectedValue) {
        var subcatSel = document.getElementById('fc-subcat-select');
        if (!subcatSel) return;
        var current = selectedValue !== undefined ? selectedValue : subcatSel.value;
        var options = fcSubcategoriesByCategory[categoryValue] || [];
        subcatSel.innerHTML = '';

        var emptyOpt = document.createElement('option');
        emptyOpt.value = '';
        emptyOpt.textContent = '— brak podkategorii —';
        subcatSel.appendChild(emptyOpt);

        options.forEach(function(name) {
            var opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            if (current === name) opt.selected = true;
            subcatSel.appendChild(opt);
        });

        if (current && options.indexOf(current) === -1) {
            var existingOpt = document.createElement('option');
            existingOpt.value = current;
            existingOpt.textContent = current;
            existingOpt.selected = true;
            subcatSel.appendChild(existingOpt);
        }
    }

    function fcOnCatChange(sel) {
        fcRefreshSubcategories(sel.value, '');
        fcRenderCategoryComboSubcats(sel.value);
        fcUpdateCategoryComboLabel();
    }

    function fcSetCategoryAndSubcategory(categoryValue, subcategoryValue) {
        var catSel = document.getElementById('fc-cat-select');
        var subcatSel = document.getElementById('fc-subcat-select');
        if (!catSel || !subcatSel) return;

        catSel.value = categoryValue;
        fcRefreshSubcategories(categoryValue, subcategoryValue || '');
        subcatSel.value = subcategoryValue || '';
        fcRenderCategoryComboSubcats(categoryValue);
        fcUpdateCategoryComboLabel();
    }

    function fcUpdateCategoryComboLabel() {
        var catSel = document.getElementById('fc-cat-select');
        var subcatSel = document.getElementById('fc-subcat-select');
        var label = document.getElementById('fc-category-combo-label');
        if (!catSel || !subcatSel || !label) return;

        var catLabel = fcCategoryLabels[catSel.value] || catSel.value || 'Kategoria';
        label.textContent = subcatSel.value ? (catLabel + ' / ' + subcatSel.value) : catLabel;
    }

    function fcRenderCategoryComboSubcats(categoryValue) {
        var box = document.getElementById('fc-category-combo-subcats');
        if (!box) return;

        var activeItems = document.querySelectorAll('.category-combo-item');
        activeItems.forEach(function(item) {
            item.classList.toggle('active', item.dataset.categoryKey === categoryValue);
        });

        var options = fcSubcategoriesByCategory[categoryValue] || [];
        box.innerHTML = '';

        if (options.length === 0) {
            var emptyBtn = document.createElement('button');
            emptyBtn.type = 'button';
            emptyBtn.className = 'category-combo-subitem empty';
            emptyBtn.textContent = 'Wybierz samą kategorię';
            emptyBtn.addEventListener('click', function() {
                fcSetCategoryAndSubcategory(categoryValue, '');
                document.getElementById('fc-category-combo').classList.remove('open');
            });
            box.appendChild(emptyBtn);
            return;
        }

        options.forEach(function(name) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'category-combo-subitem';
            btn.textContent = name;
            btn.addEventListener('click', function() {
                fcSetCategoryAndSubcategory(categoryValue, name);
                document.getElementById('fc-category-combo').classList.remove('open');
            });
            box.appendChild(btn);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var initialCat = document.getElementById('fc-cat-select');
        var initialSubcat = document.getElementById('fc-subcat-select');
        if (initialCat && initialSubcat) {
            fcRefreshSubcategories(initialCat.value, initialSubcat.value);
        }

        var combo = document.getElementById('fc-category-combo');
        var trigger = document.getElementById('fc-category-combo-trigger');
        if (combo && trigger && initialCat) {
            trigger.addEventListener('click', function() {
                combo.classList.toggle('open');
                fcRenderCategoryComboSubcats(initialCat.value);
                fcUpdateCategoryComboLabel();
            });

            document.querySelectorAll('.category-combo-item').forEach(function(item) {
                item.addEventListener('mouseenter', function() {
                    fcRenderCategoryComboSubcats(item.dataset.categoryKey);
                });
                item.addEventListener('focus', function() {
                    fcRenderCategoryComboSubcats(item.dataset.categoryKey);
                });
                item.addEventListener('click', function() {
                    fcSetCategoryAndSubcategory(item.dataset.categoryKey, '');
                });
            });

            document.addEventListener('click', function(event) {
                if (!combo.contains(event.target)) {
                    combo.classList.remove('open');
                }
            });

            fcRenderCategoryComboSubcats(initialCat.value);
            fcUpdateCategoryComboLabel();
        }
    });

    // ===== AUTO-OBLICZANIE BRUTTO =====
    const amountNet = document.querySelector('input[name="amount_net"]');
    const amountVat = document.querySelector('input[name="amount_vat"]');
    
    function calculateGross() {
        const net = parseFloat(amountNet.value) || 0;
        const vat = parseFloat(amountVat.value) || 0;
        const gross = net + vat;
        
        // Pokaż info o kwocie brutto
        let infoDiv = document.getElementById('gross-info');
        if (!infoDiv) {
            infoDiv = document.createElement('div');
            infoDiv.id = 'gross-info';
            infoDiv.style.cssText = 'margin-top: 10px; padding: 10px; background: #f0f9ff; border-left: 3px solid #0891b2; border-radius: 4px; font-size: 14px; color: #0c4a6e;';
            amountVat.closest('.form-group').appendChild(infoDiv);
        }
        
        if (gross > 0) {
            infoDiv.innerHTML = '<strong>Kwota brutto:</strong> ' + gross.toFixed(2) + ' PLN';
        } else {
            infoDiv.innerHTML = '';
        }
    }
    
    if (amountNet && amountVat) {
        amountNet.addEventListener('input', calculateGross);
        amountVat.addEventListener('input', calculateGross);
        calculateGross(); // Oblicz przy załadowaniu
    }
    
    // ===== PRZYCISK WYCZYŚĆ WSZYSTKO =====
    document.getElementById('clear-btn').addEventListener('click', function() {
        if (confirm('Czy na pewno chcesz wyczyścić wszystkie pola formularza?')) {
            // Resetuj formularz
            const form = this.closest('form');
            form.reset();
            
            // Wyczyść OCR status
            const ocrStatus = document.getElementById('ocr-status');
            const ocrContainer = document.getElementById('ocr-container');
            if (ocrStatus) ocrStatus.innerHTML = '';
            if (ocrContainer) ocrContainer.style.display = 'none';
            
            // Resetuj datę do dzisiejszej
            const issueDateInput = document.querySelector('input[name="issue_date"]');
            if (issueDateInput) {
                issueDateInput.value = '<?php echo date('Y-m-d'); ?>';
            }
            
            // Wyczyść info o brutto
            const grossInfo = document.getElementById('gross-info');
            if (grossInfo) grossInfo.innerHTML = '';
            
            // Focus na pierwszym polu
            const firstCategoryInput = form.querySelector('input[name="cost_category"]');
            if (firstCategoryInput) firstCategoryInput.focus();
        }
    });
    
    // ===== OCR FUNCTIONALITY =====
    (function() {
        const fileInput = document.getElementById('file-input');
        const ocrContainer = document.getElementById('ocr-container');
        const ocrBtn = document.getElementById('ocr-scan-btn');
        const ocrStatus = document.getElementById('ocr-status');
        
        if (!fileInput || !ocrContainer || !ocrBtn || !ocrStatus) return;
        
        // Pokaż przycisk OCR gdy plik zostanie wybrany
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                ocrContainer.style.display = 'block';
                ocrStatus.innerHTML = '';
            } else {
                ocrContainer.style.display = 'none';
            }
        });
        
        // Obsługa kliknięcia w przycisk OCR
        ocrBtn.addEventListener('click', async function() {
            const file = fileInput.files[0];
            if (!file) {
                alert('Najpierw wybierz plik do zeskanowania');
                return;
            }
            
            // Sprawdź typ pliku
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
            if (!allowedTypes.includes(file.type)) {
                alert('Obsługiwane formaty: PDF, JPG, PNG');
                return;
            }
            
            // Disable button
            ocrBtn.disabled = true;
            ocrBtn.innerHTML = '<span class="ocr-spinner"></span> Skanowanie AI...';
            ocrStatus.innerHTML = '<span class="ocr-status-loading">Gemini analizuje dokument... To może potrwać 5-10 sekund.</span>';
            
            try {
                // Przygotuj FormData
                const formData = new FormData();
                formData.append('file', file);
                formData.append('invoice_type', 'cost');
                
                // Wywołaj API
                const response = await fetch('/api/ocr/gemini-process.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success && result.data) {
                    // Wypełnij pola formularza
                    fillFormFromOCR(result.data);
                    
                    const confidence = Math.round((result.data.confidence || 0.85) * 100);
                    ocrStatus.innerHTML = '<span class="ocr-status-success">✓ Faktura zeskanowana pomyślnie!</span> <span style="color: #666;">(Pewność: ' + confidence + '%)</span>';
                } else {
                    throw new Error(result.error || 'Nieznany błąd');
                }
                
            } catch (error) {
                console.error('OCR Error:', error);
                ocrStatus.innerHTML = '<span class="ocr-status-error">✗ Błąd: ' + error.message + '</span>';
            } finally {
                // Enable button
                ocrBtn.disabled = false;
                ocrBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="vertical-align: middle;"><path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z" stroke-width="2"/><path d="M12 8v4m0 4h.01" stroke-width="2" stroke-linecap="round"/></svg> Skanuj ponownie';
            }
        });
        
        // Funkcja wypełniająca formularz danymi z OCR
        function fillFormFromOCR(data) {
            // Numer dokumentu
            if (data.number) {
                const docNumberInput = document.querySelector('input[name="doc_number"]');
                if (docNumberInput) docNumberInput.value = data.number;
            }
            
            // Daty
            if (data.issue_date) {
                const issueDateInput = document.querySelector('input[name="issue_date"]');
                if (issueDateInput) issueDateInput.value = data.issue_date;
            }
            if (data.payment_date || data.due_date) {
                const paymentDateInput = document.querySelector('input[name="payment_date"]');
                if (paymentDateInput) paymentDateInput.value = data.payment_date || data.due_date;
            }
            
            // Kontrahent
            if (data.vendor_name) {
                const companyNameInput = document.querySelector('input[name="company_name"]');
                if (companyNameInput) {
                    companyNameInput.value = data.vendor_name;
                    if (data.vendor_nip) {
                        companyNameInput.value += ' (NIP: ' + data.vendor_nip + ')';
                    }
                }
            }
            
            // Kwoty
            if (data.total_net || data.amount_net) {
                const netInput = document.querySelector('input[name="amount_net"]');
                if (netInput) netInput.value = (data.total_net || data.amount_net || 0).toFixed(2);
            }
            if (data.total_vat || data.amount_vat) {
                const vatInput = document.querySelector('input[name="amount_vat"]');
                if (vatInput) vatInput.value = (data.total_vat || data.amount_vat || 0).toFixed(2);
            }
            
            // Przelicz brutto
            calculateGross();
            
            // Tytuł (jeśli pusty)
            const titleInput = document.querySelector('input[name="title"]');
            if (titleInput && !titleInput.value && data.vendor_name) {
                const month = new Date().toLocaleString('pl-PL', { month: 'long', year: 'numeric' });
                titleInput.value = data.vendor_name + ' - ' + month;
            }
            
            // Animacja (highlight wypełnionych pól)
            const filledInputs = document.querySelectorAll('input[name="doc_number"], input[name="issue_date"], input[name="amount_net"], input[name="amount_vat"]');
            filledInputs.forEach(input => {
                if (input.value) {
                    input.style.backgroundColor = '#d4edda';
                    setTimeout(() => {
                        input.style.backgroundColor = '';
                    }, 2000);
                }
            });
        }
    })();
    </script>
</body>
</html>
