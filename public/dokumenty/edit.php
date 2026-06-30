<?php
/**
 * BRYGAD ERP - Edycja/Podgląd Dokumentu Kosztowego
 * 
 * Dla dokumentów roboczych: edycja + zarządzanie alokacjami
 * Dla zatwierdzonych/zarchiwizowanych: tylko podgląd
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success_message = '';

// Obsługa komunikatów z sesji (np. po przekierowaniu z create-document.php)
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $errors[] = $_SESSION['error'];
    unset($_SESSION['error']);
}

$document_id = $_GET['id'] ?? 0;

if (!$document_id) {
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents']));
    exit;
}

// Pobierz dokument
$stmt = $pdo->prepare("
    SELECT d.*, i.name as vendor_name, i.nip as vendor_nip,
           u.login as created_by_name
    FROM documents d
    LEFT JOIN investors i ON d.vendor_id = i.id
    LEFT JOIN users u ON d.created_by = u.id
    WHERE d.id = ? AND d.type = 'invoice_cost'
");
$stmt->execute([$document_id]);
$document = $stmt->fetch();

if (!$document) {
    header("Location: " . url('finanse.fakturownia-cost-inbox', ['source' => 'documents', 'error' => 'not_found']));
    exit;
}

$is_editable = ($document['status'] === 'draft');

// Obsługa UPDATE dokumentu (jeśli draft)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_document' && $is_editable) {
    $vendor_id = trim($_POST['vendor_id'] ?? '');
    $source_name = trim($_POST['source_name'] ?? '');
    $number = trim($_POST['number'] ?? '');
    $issue_date = trim($_POST['issue_date'] ?? '');
    $due_date = trim($_POST['due_date'] ?? '');
    $sale_date = trim($_POST['sale_date'] ?? '');
    $currency = trim($_POST['currency'] ?? 'PLN');
    $amount_net = trim($_POST['amount_net'] ?? '0');
    $amount_vat = trim($_POST['amount_vat'] ?? '0');
    $amount_gross = trim($_POST['amount_gross'] ?? '0');
    $description = trim($_POST['description'] ?? '');
    
    // Walidacja: albo kontrahent albo źródło
    if (empty($vendor_id) && empty($source_name)) {
        $errors[] = "Musisz wybrać kontrahenta lub podać źródło zakupu";
    }
    if (empty($number)) {
        $errors[] = "Numer dokumentu jest wymagany";
    }
    if (empty($issue_date)) {
        $errors[] = "Data wystawienia jest wymagana";
    }
    
    // Sprawdź unikalność numeru (tylko jeśli vendor_id jest podany)
    if (empty($errors) && !empty($vendor_id)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE vendor_id = ? AND number = ? AND id != ?");
        $stmt->execute([$vendor_id, $number, $document_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Dokument z tym numerem już istnieje dla tego kontrahenta";
        }
    }
    
    // Obsługa nowego pliku
    $file_path = $document['file_path'];
    if (!empty($_FILES['file']['name'])) {
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Dozwolone są tylko pliki PDF, JPG i PNG";
        } elseif ($_FILES['file']['size'] > 10 * 1024 * 1024) {
            $errors[] = "Plik nie może być większy niż 10MB";
        } else {
            $upload_dir = dirname(__DIR__) . '/uploads/documents';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = 'doc_' . time() . '_' . mt_rand(1000, 9999) . '.' . $file_extension;
            $destination = $upload_dir . '/' . $file_name;
            
            if (move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
                // Usuń stary plik jeśli istnieje
                if ($document['file_path']) {
                    $old_file = dirname(__DIR__) . '/' . $document['file_path'];
                    if (file_exists($old_file)) {
                        @unlink($old_file);
                    }
                }
                $file_path = 'uploads/documents/' . $file_name;
            } else {
                $errors[] = "Błąd podczas przesyłania pliku";
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE documents SET
                    vendor_id = ?, source_name = ?, number = ?, issue_date = ?, due_date = ?, 
                    sale_date = ?, currency = ?, amount_net = ?, amount_vat = ?,
                    amount_gross = ?, file_path = ?, description = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $vendor_id ?: null, $source_name ?: null, $number, $issue_date, 
                $due_date ?: null, $sale_date ?: null,
                $currency, $amount_net, $amount_vat, $amount_gross,
                $file_path, $description ?: null,
                $document_id
            ]);
            
            logEvent("Zaktualizowano dokument: {$number} (ID: {$document_id})", 'INFO');
            $success_message = "Dokument został zaktualizowany.";
            
            // Odśwież dane dokumentu
                $stmt = $pdo->prepare("
                SELECT d.*, i.name as vendor_name, i.nip as vendor_nip,
                       u.login as created_by_name
                FROM documents d
                LEFT JOIN investors i ON d.vendor_id = i.id
                LEFT JOIN users u ON d.created_by = u.id
                WHERE d.id = ?
            ");
            $stmt->execute([$document_id]);
            $document = $stmt->fetch();
        } catch (PDOException $e) {
            logEvent("Błąd aktualizacji dokumentu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas aktualizacji dokumentu";
        }
    }
}

// Obsługa ZATWIERDZENIA dokumentu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve' && $is_editable) {
    // Pobierz sumę alokacji NOWYCH (document_item_allocations)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(dia.amount), 0) 
        FROM document_item_allocations dia
        INNER JOIN document_items di ON dia.document_item_id = di.id
        WHERE di.document_id = ?
    ");
    $stmt->execute([$document_id]);
    $total_allocated_items = floatval($stmt->fetchColumn());
    
    // Pobierz sumę alokacji LEGACY (document_allocations)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_net), 0) FROM document_allocations WHERE document_id = ? AND is_legacy = 1");
    $stmt->execute([$document_id]);
    $total_allocated_legacy = floatval($stmt->fetchColumn());
    
    // Suma obu systemów alokacji
    $total_allocated = $total_allocated_items + $total_allocated_legacy;
    
    // Porównaj z amount_net dokumentu
    $doc_amount = floatval($document['amount_net']);
    $allocated_amount = $total_allocated;
    
    if (abs($doc_amount - $allocated_amount) > 0.01) {
        $errors[] = sprintf(
            "Nie można zatwierdzić dokumentu. Suma alokacji (%.2f) nie zgadza się z kwotą netto dokumentu (%.2f).",
            $allocated_amount,
            $doc_amount
        );
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE documents SET status = 'approved', approved_at = NOW() WHERE id = ?");
            $stmt->execute([$document_id]);
            
            logEvent("Zatwierdzono dokument: {$document['number']} (ID: {$document_id})", 'INFO');
            
            header("Location: " . url('finanse.fakturownia-cost-inbox', [
                'source' => 'documents',
                'success' => 'document_approved'
            ]));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd zatwierdzania dokumentu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas zatwierdzania dokumentu";
        }
    }
}

// Pobierz pozycje dokumentu
$stmt = $pdo->prepare("
    SELECT * FROM document_items
    WHERE document_id = ?
    ORDER BY sort_order ASC
");
$stmt->execute([$document_id]);
$document_items = $stmt->fetchAll();

// Pobierz alokacje POZYCJI (nowy system)
$stmt = $pdo->prepare("
    SELECT dia.*, di.item_name, p.name as project_name, pcn.name as cost_node_name
    FROM document_item_allocations dia
    INNER JOIN document_items di ON dia.document_item_id = di.id
    INNER JOIN projects p ON dia.project_id = p.id
    LEFT JOIN project_cost_nodes pcn ON dia.cost_node_id = pcn.id
    WHERE di.document_id = ?
    ORDER BY di.sort_order ASC, dia.created_at ASC
");
$stmt->execute([$document_id]);
$item_allocations = $stmt->fetchAll();

// Pobierz stare alokacje (legacy - całej faktury)
$stmt = $pdo->prepare("
    SELECT da.*, p.name as project_name, pcn.name as cost_node_name
    FROM document_allocations da
    INNER JOIN projects p ON da.project_id = p.id
    LEFT JOIN project_cost_nodes pcn ON da.cost_node_id = pcn.id
    WHERE da.document_id = ? AND da.is_legacy = 1
    ORDER BY da.created_at ASC
");
$stmt->execute([$document_id]);
$legacy_allocations = $stmt->fetchAll();

// Oblicz sumę alokacji (pozycje + legacy)
$total_allocated_items = array_sum(array_column($item_allocations, 'amount'));
$total_allocated_legacy = array_sum(array_column($legacy_allocations, 'amount_net'));
$total_allocated = $total_allocated_items + $total_allocated_legacy;

// Pobierz listę projektów i kontrahentów dla formularzy
$projects = $pdo->query("SELECT id, name FROM projects WHERE status IN ('planned', 'active') ORDER BY name ASC")->fetchAll();
$vendors = $pdo->query("SELECT id, name, nip FROM investors WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo $is_editable ? 'Edycja' : 'Podgląd'; ?> Dokumentu</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }
        .breadcrumb {
            margin-bottom: 20px;
            color: #666;
            font-size: 14px;
        }
        .breadcrumb a {
            color: #ea580c;
            text-decoration: none;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header h2 {
            font-size: 32px;
            color: #333;
        }
        .badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 16px;
            font-size: 13px;
            font-weight: 600;
        }
        .badge-draft {
            background: #fff3cd;
            color: #856404;
        }
        .badge-approved {
            background: #d4edda;
            color: #155724;
        }
        .badge-archived {
            background: #e2e3e5;
            color: #383d41;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
        }
        .card h3 {
            font-size: 20px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            color: #333;
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
        .alert-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        .alert ul {
            margin: 10px 0 0 20px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .info-label {
            font-size: 12px;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
        }
        .info-value {
            font-size: 16px;
            color: #333;
            font-weight: 500;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
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
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #ea580c;
        }
        .form-group .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
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
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }
        .delete-doc-form {
            display: inline;
            margin: 0 0 0 10px;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            flex-wrap: wrap;
        }
        .allocations-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .allocations-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
        }
        .allocations-table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        .allocations-table tr:hover {
            background: #f8f9fa;
        }
        .allocation-row {
            margin-bottom: 15px;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: #f8f9fa;
        }
        .allocation-form-grid {
            display: grid;
            grid-template-columns: 2fr 2fr 1.5fr 1fr 1fr auto;
            gap: 10px;
            align-items: end;
        }
        .total-row {
            background: #fff3cd;
            font-weight: 700;
            font-size: 16px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
        .file-link {
            color: #ea580c;
            text-decoration: none;
            font-weight: 600;
        }
        .file-link:hover {
            text-decoration: underline;
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
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo url('finanse.fakturownia-cost-inbox', ['source' => 'documents']); ?>">Centrum Faktur Kosztowych</a> / 
            <?php echo $is_editable ? 'Edycja' : 'Podgląd'; ?>
        </div>
        
        <div class="page-header">
                        <div>
                <h2>Dokument: <?php echo e($document['number']); ?></h2>
                <span class="badge badge-<?php echo $document['status']; ?>">
                    <?php 
                    $statusLabels = [
                        'draft' => 'Wersja robocza',
                        'approved' => 'Zatwierdzone',
                        'archived' => 'Zarchiwizowane'
                    ];
                    echo $statusLabels[$document['status']];
                    ?>
                            </span>
                        </div>
            <a href="<?php echo url('finanse.fakturownia-cost-inbox', ['source' => 'documents']); ?>" class="btn btn-secondary">Wróć do centrum</a>
                    </div>
        
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
        
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?php echo e($success_message); ?>
            </div>
        <?php endif; ?>
        
        <!-- SEKCJA 1: Dane dokumentu -->
        <div class="card">
            <h3>Dane dokumentu</h3>
            
            <?php if ($is_editable): ?>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_document">
                    
                    <div class="form-group">
                        <label>Kontrahent (opcjonalnie)</label>
                        <select name="vendor_id">
                            <option value="">-- Brak (wpisz źródło poniżej) --</option>
                            <?php foreach ($vendors as $vendor): ?>
                                <option value="<?php echo $vendor['id']; ?>" 
                                        <?php echo $document['vendor_id'] == $vendor['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($vendor['name']); ?>
                                    <?php if ($vendor['nip']): ?>
                                        (NIP: <?php echo e($vendor['nip']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">
                            Wybierz kontrahenta z listy LUB wpisz źródło zakupu poniżej
                            <span style="margin-left: 15px;">
                                <a href="<?php echo url('investors.create'); ?>" target="_blank" 
                                   style="color: #ea580c; font-weight: 600;">+ Dodaj nowego kontrahenta</a>
                            </span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Źródło zakupu (jeśli brak kontrahenta)</label>
                        <input type="text" name="source_name" value="<?php echo e($document['source_name']); ?>" 
                               placeholder="np. Solidtech - jednorazowo, Castorama - paragon">
                        <div class="help-text">Pole wymagane jeśli nie wybrano kontrahenta</div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Numer dokumentu <span class="required">*</span></label>
                            <input type="text" name="number" value="<?php echo e($document['number']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Waluta <span class="required">*</span></label>
                            <select name="currency" required>
                                <option value="PLN" <?php echo $document['currency'] === 'PLN' ? 'selected' : ''; ?>>PLN</option>
                                <option value="EUR" <?php echo $document['currency'] === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                                <option value="USD" <?php echo $document['currency'] === 'USD' ? 'selected' : ''; ?>>USD</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Data wystawienia <span class="required">*</span></label>
                            <input type="date" name="issue_date" value="<?php echo e($document['issue_date']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Termin płatności</label>
                            <input type="date" name="due_date" value="<?php echo e($document['due_date']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Data sprzedaży</label>
                        <input type="date" name="sale_date" value="<?php echo e($document['sale_date']); ?>">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Kwota netto <span class="required">*</span></label>
                            <input type="number" name="amount_net" value="<?php echo e($document['amount_net']); ?>" 
                                   step="0.01" min="0" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Kwota VAT <span class="required">*</span></label>
                            <input type="number" name="amount_vat" value="<?php echo e($document['amount_vat']); ?>" 
                                   step="0.01" min="0" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Kwota brutto <span class="required">*</span></label>
                        <input type="number" name="amount_gross" value="<?php echo e($document['amount_gross']); ?>" 
                               step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Plik dokumentu</label>
                        <?php if ($document['file_path']): ?>
                            <div style="margin-bottom: 10px;">
                                Obecny plik: <a href="/<?php echo e($document['file_path']); ?>" target="_blank" class="file-link">Pobierz</a>
                                
                                <!-- OCR Button dla obecnego pliku -->
                                <button type="button" id="ocr-scan-existing-btn" class="btn-ocr" style="margin-left: 15px;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="vertical-align: middle;">
                                        <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z" stroke-width="2"/>
                                        <path d="M12 8v4m0 4h.01" stroke-width="2" stroke-linecap="round"/>
                                    </svg>
                                    Skanuj obecny plik AI
                                </button>
                                <div id="ocr-existing-status" style="margin-top: 8px; font-size: 13px;"></div>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="file" id="file-input" accept=".pdf,.jpg,.jpeg,.png">
                        <div class="help-text">Dozwolone formaty: PDF, JPG, PNG (max 10MB)</div>
                        
                        <!-- OCR Button dla nowego pliku -->
                        <div id="ocr-container" style="margin-top: 12px; display: none;">
                            <button type="button" id="ocr-scan-btn" class="btn-ocr">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="vertical-align: middle;">
                                    <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z" stroke-width="2"/>
                                    <path d="M12 8v4m0 4h.01" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                                Skanuj nowy plik AI
                            </button>
                            <div id="ocr-status" style="margin-top: 8px; font-size: 13px;"></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Opis / Notatki</label>
                        <textarea name="description" rows="3"><?php echo e($document['description']); ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                    </div>
                </form>
            <?php else: ?>
                <!-- Podgląd read-only -->
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label"><?php echo $document['vendor_id'] ? 'Kontrahent' : 'Źródło zakupu'; ?></span>
                        <span class="info-value"><?php echo e($document['vendor_name'] ?: $document['source_name']); ?></span>
                    </div>
                    <?php if ($document['vendor_id']): ?>
                        <div class="info-item">
                            <span class="info-label">NIP</span>
                            <span class="info-value"><?php echo e($document['vendor_nip'] ?: '-'); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-label">Numer dokumentu</span>
                        <span class="info-value"><?php echo e($document['number']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Data wystawienia</span>
                        <span class="info-value"><?php echo formatDate($document['issue_date']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Termin płatności</span>
                        <span class="info-value"><?php echo $document['due_date'] ? formatDate($document['due_date']) : '-'; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Data sprzedaży</span>
                        <span class="info-value"><?php echo $document['sale_date'] ? formatDate($document['sale_date']) : '-'; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Kwota netto</span>
                        <span class="info-value"><?php echo number_format($document['amount_net'], 2, ',', ' '); ?> <?php echo e($document['currency']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Kwota VAT</span>
                        <span class="info-value"><?php echo number_format($document['amount_vat'], 2, ',', ' '); ?> <?php echo e($document['currency']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Kwota brutto</span>
                        <span class="info-value"><?php echo number_format($document['amount_gross'], 2, ',', ' '); ?> <?php echo e($document['currency']); ?></span>
                    </div>
                    <?php if ($document['file_path']): ?>
                        <div class="info-item">
                            <span class="info-label">Plik</span>
                            <span class="info-value">
                                <a href="/<?php echo e($document['file_path']); ?>" target="_blank" class="file-link">Pobierz dokument</a>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($document['description']): ?>
                    <div class="info-item" style="margin-top: 20px;">
                        <span class="info-label">Opis</span>
                        <span class="info-value"><?php echo nl2br(e($document['description'])); ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- SEKCJA 2: Alokacje kosztów -->
        <div class="card">
            <h3>Pozycje faktury</h3>
            
            <?php if (count($document_items) > 0): ?>
                <div style="overflow-x: auto; margin-bottom: 30px;">
                    <table class="allocations-table" style="margin-bottom: 0;">
                        <thead>
                            <tr>
                                <th style="width: 40%;">Nazwa</th>
                                <th style="width: 8%; text-align: center;">Ilość</th>
                                <th style="width: 8%; text-align: center;">Jedn.</th>
                                <th style="width: 10%; text-align: right;">Cena jedn.</th>
                                <th style="width: 8%; text-align: center;">VAT</th>
                                <th style="width: 10%; text-align: right;">Netto</th>
                                <th style="width: 10%; text-align: right;">VAT</th>
                                <th style="width: 10%; text-align: right;">Brutto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($document_items as $item): ?>
                                <tr style="background: #f9fafb;">
                                    <td><?php echo e($item['item_name']); ?></td>
                                    <td style="text-align: center;"><?php echo number_format($item['quantity'], 2); ?></td>
                                    <td style="text-align: center;"><?php echo e($item['unit']); ?></td>
                                    <td style="text-align: right;"><?php echo number_format($item['unit_price_net'], 2); ?></td>
                                    <td style="text-align: center;"><?php echo $item['vat_rate'] === 'zw' ? 'zw' : $item['vat_rate'] . '%'; ?></td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo number_format($item['amount_net'], 2); ?></td>
                                    <td style="text-align: right;"><?php echo number_format($item['amount_vat'], 2); ?></td>
                                    <td style="text-align: right; font-weight: 600;"><?php echo number_format($item['amount_gross'], 2); ?></td>
                                </tr>
                                <?php
                                // Pokaż alokacje tej pozycji
                                $item_allocs = array_filter($item_allocations, function($a) use ($item) {
                                    return $a['document_item_id'] == $item['id'];
                                });
                                if (count($item_allocs) > 0):
                                ?>
                                    <tr style="background: #e8f5e9;">
                                        <td colspan="8" style="padding: 10px 20px;">
                                            <strong style="color: #2e7d32;">Alokacje tej pozycji:</strong>
                                            <ul style="margin: 5px 0 0 20px; padding: 0;">
                                                <?php foreach ($item_allocs as $alloc): ?>
                                                    <li style="margin: 3px 0;">
                                                        <strong><?php echo e($alloc['project_name']); ?></strong>
                                                        <?php if ($alloc['cost_node_name']): ?>
                                                            → <?php echo e($alloc['cost_node_name']); ?>
                                                        <?php endif; ?>
                                                        : <strong><?php echo number_format($alloc['amount'], 2); ?> PLN</strong>
                                                        <?php if ($alloc['notes']): ?>
                                                            <span style="color: #666;">(<?php echo e($alloc['notes']); ?>)</span>
                                                        <?php endif; ?>
                                                        <?php if ($is_editable): ?>
                                                            <button onclick="deleteItemAllocation(<?php echo $alloc['id']; ?>)" 
                                                                    style="margin-left: 10px; background: #ef4444; color: white; border: none; border-radius: 4px; padding: 2px 8px; cursor: pointer; font-size: 11px;">
                                                                Usuń
                                                            </button>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                
                                <?php if ($is_editable): ?>
                                    <tr style="background: #fff3e0; border-bottom: 2px solid #ddd;">
                                        <td colspan="8" style="padding: 10px 20px;">
                                            <button onclick="showAllocationModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['item_name'], ENT_QUOTES); ?>', <?php echo $item['amount_net']; ?>)" 
                                                    class="btn btn-secondary btn-small">
                                                + Alokuj tę pozycję do projektu
                                            </button>
                                            <span style="margin-left: 15px; color: #666; font-size: 13px;">
                                                Dostępne: <strong><?php 
                                                    $allocated_sum = array_sum(array_column($item_allocs, 'amount'));
                                                    echo number_format($item['amount_net'] - $allocated_sum, 2); 
                                                ?> PLN</strong>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    Ten dokument nie ma pozycji. Został utworzony w starym systemie.
                </div>
            <?php endif; ?>
            
            <?php if (count($legacy_allocations) > 0): ?>
                <h3>Stare alokacje (całej faktury)</h3>
                <div class="alert alert-info" style="margin-bottom: 15px;">
                    Poniższe alokacje pochodzą ze starego systemu (przed wprowadzeniem pozycji). Nie można ich edytować.
                </div>
                <table class="allocations-table" style="margin-bottom: 30px;">
                    <thead>
                        <tr>
                            <th>Projekt</th>
                            <th>Etap</th>
                            <th>Kategoria</th>
                            <th>Kwota</th>
                            <th>Opis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($legacy_allocations as $alloc): ?>
                            <tr style="opacity: 0.7;">
                                <td><?php echo e($alloc['project_name']); ?></td>
                                <td><?php echo e($alloc['cost_node_name'] ?: '-'); ?></td>
                                <td><?php echo e($alloc['category'] ?? '-'); ?></td>
                                <td><?php echo number_format($alloc['amount'], 2); ?> PLN</td>
                                <td><?php echo e($alloc['notes'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <h3>Podsumowanie alokacji</h3>
            
            <?php if ($is_editable): ?>
                <div class="alert alert-warning">
                    Dokument może być zatwierdzony tylko wtedy, gdy wszystkie pozycje są w 100% zalokowane do projektów.
                </div>
            <?php endif; ?>
            
            <!-- Stara sekcja alokacji usunięta - teraz alokujemy pozycje, nie całe faktury -->
        </div>
        
        <!-- SEKCJA 3: Akcje -->
        <?php if ($is_editable): ?>
            <div class="card">
                <h3>Akcje dokumentu</h3>
                
                <div class="form-actions" style="margin-top: 0; padding-top: 0; border-top: none;">
                    <form method="POST" action="" onsubmit="return confirm('Czy na pewno chcesz zatwierdzić ten dokument? Po zatwierdzeniu nie będzie można go edytować.');">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success">Zatwierdź dokument</button>
                    </form>
                    
                    <form method="POST" action="<?php echo url('dokumenty.archive'); ?>" onsubmit="return confirm('Czy na pewno chcesz zarchiwizować ten dokument?');">
                        <input type="hidden" name="document_id" value="<?php echo $document_id; ?>">
                        <button type="submit" class="btn btn-secondary">Archiwizuj</button>
                    </form>
                    
                    <?php if ($isAdminUser): ?>
                        <form method="POST" action="<?php echo url('dokumenty.delete'); ?>" class="delete-doc-form" 
                              onsubmit="return confirm('Czy na pewno chcesz usunąć dokument <?php echo e($document['number']); ?>? Zostaną usunięte wszystkie alokacje i pozycje!');">
                            <input type="hidden" name="document_id" value="<?php echo $document_id; ?>">
                            <button type="submit" class="btn btn-danger">Usuń Dokument</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
    function loadCostNodes(projectId, targetSelectId) {
        const selectId = targetSelectId || 'cost_node_select';
        const costNodeSelect = document.getElementById(selectId);
        
        if (!costNodeSelect) {
            console.error('Select not found:', selectId);
            return;
        }
        
        costNodeSelect.innerHTML = '<option value="">-- Brak --</option>';
        
        if (!projectId) {
            return;
        }
        
        fetch('/api/get-cost-nodes.php?project_id=' + projectId)
            .then(response => response.json())
            .then(data => {
                if (data.success && Array.isArray(data.nodes)) {
                    data.nodes.forEach(node => {
                        const option = document.createElement('option');
                        option.value = node.id;
                        option.textContent = (node.parent_id ? '  ↳ ' : '') + node.name;
                        costNodeSelect.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading cost nodes:', error));
    }
    
    // ===== OCR FUNCTIONALITY =====
    (function() {
        const fileInput = document.getElementById('file-input');
        const ocrContainer = document.getElementById('ocr-container');
        const ocrBtn = document.getElementById('ocr-scan-btn');
        const ocrStatus = document.getElementById('ocr-status');
        const ocrExistingBtn = document.getElementById('ocr-scan-existing-btn');
        const ocrExistingStatus = document.getElementById('ocr-existing-status');
        
        // Pokaż przycisk OCR gdy nowy plik zostanie wybrany
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    ocrContainer.style.display = 'block';
                    ocrStatus.innerHTML = '';
                } else {
                    ocrContainer.style.display = 'none';
                }
            });
        }
        
        // Obsługa skanowania nowego pliku
        if (ocrBtn) {
            ocrBtn.addEventListener('click', async function() {
                const file = fileInput.files[0];
                if (!file) {
                    alert('Najpierw wybierz plik do zeskanowania');
                    return;
                }
                await scanFile(file, ocrBtn, ocrStatus);
            });
        }
        
        // Obsługa skanowania obecnego pliku
        if (ocrExistingBtn) {
            ocrExistingBtn.addEventListener('click', async function() {
                const fileUrl = this.previousElementSibling.href;
                await scanExistingFile(fileUrl, ocrExistingBtn, ocrExistingStatus);
            });
        }
        
        // Funkcja skanowania nowego pliku (z FileInput)
        async function scanFile(file, button, statusElement) {
            // Sprawdź typ pliku
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
            if (!allowedTypes.includes(file.type)) {
                alert('Obsługiwane formaty: PDF, JPG, PNG');
                return;
            }
            
            // Disable button
            button.disabled = true;
            button.innerHTML = '<span class="ocr-spinner"></span> Skanowanie AI...';
            statusElement.innerHTML = '<span class="ocr-status-loading">Gemini analizuje dokument... To może potrwać 5-10 sekund.</span>';
            
            try {
                // Przygotuj FormData
                const formData = new FormData();
                formData.append('file', file);
                
                // Wywołaj API
                const response = await fetch('/api/ocr/gemini-process.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success && result.data) {
                    fillFormFromOCR(result.data);
                    statusElement.innerHTML = '<span class="ocr-status-success">✓ Faktura zeskanowana! Sprawdź i popraw dane przed zapisem.</span>';
                    
                    if (result.data.confidence) {
                        const confidence = Math.round(result.data.confidence * 100);
                        statusElement.innerHTML += ` <span style="color: #666;">(Pewność: ${confidence}%)</span>`;
                    }
                } else {
                    throw new Error(result.error || 'Nieznany błąd');
                }
                
            } catch (error) {
                console.error('OCR Error:', error);
                statusElement.innerHTML = '<span class="ocr-status-error">✗ Błąd: ' + error.message + '</span>';
            } finally {
                button.disabled = false;
                button.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="vertical-align: middle;"><path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z" stroke-width="2"/><path d="M12 8v4m0 4h.01" stroke-width="2" stroke-linecap="round"/></svg> Skanuj ponownie';
            }
        }
        
        // Funkcja skanowania istniejącego pliku (z URL)
        async function scanExistingFile(fileUrl, button, statusElement) {
            button.disabled = true;
            button.innerHTML = '<span class="ocr-spinner"></span> Skanowanie AI...';
            statusElement.innerHTML = '<span class="ocr-status-loading">Gemini analizuje dokument... To może potrwać 5-10 sekund.</span>';
            
            try {
                // Pobierz plik z URL
                const fileResponse = await fetch(fileUrl);
                const blob = await fileResponse.blob();
                
                // Przygotuj FormData
                const formData = new FormData();
                formData.append('file', blob, 'document.pdf');
                
                // Wywołaj API
                const response = await fetch('/api/ocr/gemini-process.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success && result.data) {
                    fillFormFromOCR(result.data);
                    statusElement.innerHTML = '<span class="ocr-status-success">✓ Faktura zeskanowana! Sprawdź i popraw dane przed zapisem.</span>';
                    
                    if (result.data.confidence) {
                        const confidence = Math.round(result.data.confidence * 100);
                        statusElement.innerHTML += ` <span style="color: #666;">(Pewność: ${confidence}%)</span>`;
                    }
                } else {
                    throw new Error(result.error || 'Nieznany błąd');
                }
                
            } catch (error) {
                console.error('OCR Error:', error);
                statusElement.innerHTML = '<span class="ocr-status-error">✗ Błąd: ' + error.message + '</span>';
            } finally {
                button.disabled = false;
                button.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" style="vertical-align: middle;"><path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z" stroke-width="2"/><path d="M12 8v4m0 4h.01" stroke-width="2" stroke-linecap="round"/></svg> Skanuj obecny plik AI';
            }
        }
        
        // Funkcja wypełniająca formularz danymi z OCR
        function fillFormFromOCR(data) {
            // Numer faktury
            if (data.number) {
                const numberInput = document.querySelector('input[name="number"]');
                if (numberInput) numberInput.value = data.number;
            }
            
            // Daty
            if (data.issue_date) {
                const issueDateInput = document.querySelector('input[name="issue_date"]');
                if (issueDateInput) issueDateInput.value = data.issue_date;
            }
            if (data.due_date) {
                const dueDateInput = document.querySelector('input[name="due_date"]');
                if (dueDateInput) dueDateInput.value = data.due_date;
            }
            if (data.sale_date) {
                const saleDateInput = document.querySelector('input[name="sale_date"]');
                if (saleDateInput) saleDateInput.value = data.sale_date;
            }
            
            // Kwoty
            if (data.amount_net) {
                const amountNetInput = document.querySelector('input[name="amount_net"]');
                if (amountNetInput) amountNetInput.value = data.amount_net;
            }
            if (data.amount_vat) {
                const amountVatInput = document.querySelector('input[name="amount_vat"]');
                if (amountVatInput) amountVatInput.value = data.amount_vat;
            }
            if (data.amount_gross) {
                const amountGrossInput = document.querySelector('input[name="amount_gross"]');
                if (amountGrossInput) amountGrossInput.value = data.amount_gross;
            }
            
            // Kontrahent (jeśli nie wybrany z listy)
            if (data.vendor_name) {
                const vendorIdInput = document.querySelector('input[name="vendor_id"]');
                const sourceNameInput = document.querySelector('input[name="source_name"]');
                
                if (sourceNameInput && (!vendorIdInput || !vendorIdInput.value)) {
                    sourceNameInput.value = data.vendor_name;
                    if (data.vendor_nip) {
                        sourceNameInput.value += ' (NIP: ' + data.vendor_nip + ')';
                    }
                }
            }
            
            // Animacja (highlight wypełnionych pól)
            const filledInputs = document.querySelectorAll('input[name="number"], input[name="issue_date"], input[name="amount_net"], input[name="amount_vat"], input[name="amount_gross"]');
            filledInputs.forEach(input => {
                if (input && input.value) {
                    input.style.backgroundColor = '#d4edda';
                    setTimeout(() => {
                        input.style.backgroundColor = '';
                    }, 2000);
                }
            });
        }
    })();
    
    // ===== MODAL ALOKACJI POZYCJI =====
    function showAllocationModal(itemId, itemName, itemAmount) {
        const modal = document.getElementById('allocationModal');
        document.getElementById('modal-item-name').textContent = itemName;
        document.getElementById('modal-item-id').value = itemId;
        document.getElementById('modal-item-amount').textContent = itemAmount.toFixed(2);
        document.getElementById('alloc-amount').value = itemAmount.toFixed(2);
        modal.style.display = 'block';
        
        // Załaduj projekty do selecta (już są w PHP, ale dla pewności)
        loadProjects();
    }
    
    function closeAllocationModal() {
        document.getElementById('allocationModal').style.display = 'none';
    }
    
    function loadProjects() {
        // Projekty są już załadowane w PHP, więc ten krok jest opcjonalny
    }
    
    function submitAllocation() {
        const form = document.getElementById('allocationForm');
        const formData = new FormData(form);
        
        fetch('<?php echo url('api.document-items.allocate'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Alokacja zapisana!');
                location.reload();
            } else {
                alert('Błąd: ' + (data.error || 'Nieznany błąd'));
            }
        })
        .catch(err => {
            alert('Błąd: ' + err.message);
        });
        
        return false;
    }
    
    function deleteItemAllocation(allocId) {
        if (!confirm('Czy na pewno usunąć tę alokację?')) return;
        
        fetch('<?php echo url('api.document-items.delete-allocation'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({allocation_id: allocId})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Błąd: ' + (data.error || 'Nieznany błąd'));
            }
        })
        .catch(err => {
            alert('Błąd: ' + err.message);
        });
    }
    </script>
    
    <!-- Modal alokacji pozycji -->
    <div id="allocationModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="position: relative; background: white; margin: 5% auto; padding: 30px; width: 600px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <button onclick="closeAllocationModal()" style="position: absolute; top: 10px; right: 10px; background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
            <h3 style="margin-bottom: 20px;">Alokuj pozycję do projektu</h3>
            <p style="margin-bottom: 20px; color: #666;">
                Pozycja: <strong id="modal-item-name"></strong><br>
                Kwota netto: <strong id="modal-item-amount"></strong> PLN
            </p>
            
            <form id="allocationForm" onsubmit="return submitAllocation();">
                <input type="hidden" name="document_item_id" id="modal-item-id">
                
                <div style="margin-bottom: 15px;">
                    <label>Projekt *</label>
                    <select name="project_id" id="alloc-project" required onchange="loadCostNodes(this.value, 'alloc-cost-node')" style="width: 100%; padding: 8px;">
                        <option value="">-- Wybierz projekt --</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>"><?php echo e($proj['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label>Etap (opcjonalnie)</label>
                    <select name="cost_node_id" id="alloc-cost-node" style="width: 100%; padding: 8px;">
                        <option value="">-- Bez etapu --</option>
                    </select>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label>Kwota alokacji *</label>
                    <input type="number" name="amount" id="alloc-amount" step="0.01" min="0.01" required style="width: 100%; padding: 8px;">
                    <small style="color: #666;">Możesz przypisać całość lub część kwoty</small>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label>Notatki</label>
                    <textarea name="notes" style="width: 100%; padding: 8px; min-height: 60px;"></textarea>
                </div>
                
                <div style="text-align: right;">
                    <button type="button" onclick="closeAllocationModal()" class="btn btn-secondary" style="margin-right: 10px;">Anuluj</button>
                    <button type="submit" class="btn btn-primary">Zapisz alokację</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
