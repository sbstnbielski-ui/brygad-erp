<?php
/**
 * BRYGAD ERP v3.0 - Dodaj Fakturę
 * 
 * PRZESTARZAŁE: Ten plik został zastąpiony przez nowy moduł Dokumenty Kosztowe.
 * Automatyczne przekierowanie do dokumenty/create.php
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

// ==========================================
// MIGRACJA: Przekierowanie do nowego modułu
// ==========================================
// Od teraz wszystkie nowe faktury kosztowe są dodawane przez moduł Dokumenty.
// Stare faktury pozostają w bazie jako archiwum (read-only).
// ==========================================

header("Location: " . url('dokumenty.create'), true, 302);
exit;

// ==========================================
// KOD PONIŻEJ NIE JEST JUŻ WYKONYWANY
// Pozostawiony dla referencji historycznej
// ==========================================

$pdo = getDbConnection();
$errors = [];

// Katalog na faktury
$invoices_dir = UPLOADS_PATH . '/invoices';
if (!is_dir($invoices_dir)) {
    mkdir($invoices_dir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $number = trim($_POST['number'] ?? '');
    $contractor = trim($_POST['contractor'] ?? '');
    $date = $_POST['date'] ?? '';
    $amount_gross = floatval($_POST['amount_gross'] ?? 0);
    $scope = $_POST['scope'] ?? 'business';
    $status = $_POST['status'] ?? 'draft';
    
    if (empty($number)) $errors[] = "Numer faktury jest wymagany";
    if (empty($contractor)) $errors[] = "Nazwa kontrahenta jest wymagana";
    if (empty($date)) $errors[] = "Data faktury jest wymagana";
    if ($amount_gross <= 0) $errors[] = "Kwota brutto musi być większa od 0";
    if (!in_array($scope, ['business', 'private'])) $errors[] = "Nieprawidłowy zakres faktury";
    if (!in_array($status, ['draft', 'approved'])) $errors[] = "Nieprawidłowy status";
    
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE number = ?");
        $stmt->execute([$number]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Faktura o tym numerze już istnieje";
        }
    }
    
    $file_path = null;
    if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['invoice_file'];
        if (!in_array($file['type'], ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'])) {
            $errors[] = "Dozwolone są tylko pliki PDF, JPG i PNG";
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            $errors[] = "Plik jest zbyt duży (max 10MB)";
        }
        if (empty($errors)) {
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $safe_filename = preg_replace('/[^a-z0-9_-]/i', '_', $number) . '_' . time() . '.' . $extension;
            $destination = $invoices_dir . '/' . $safe_filename;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $file_path = 'uploads/invoices/' . $safe_filename;
            } else {
                $errors[] = "Błąd podczas zapisywania pliku";
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO invoices (number, contractor, date, amount_gross, scope, status, file_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$number, $contractor, $date, $amount_gross, $scope, $status, $file_path]);
            $invoice_id = $pdo->lastInsertId();
            logEvent("Utworzono fakturę: {$number} ({$contractor}), kwota: {$amount_gross} PLN", 'INFO');
            header("Location: " . url('finanse.faktury.allocate', ['id' => $invoice_id, 'success' => 'created']));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd tworzenia faktury: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas tworzenia faktury";
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
    <title><?php echo e(APP_NAME); ?> - Dodaj Fakturę</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        /* Header - z include */
        .container { max-width: 800px; margin: 0 auto; padding: 30px; }
        .breadcrumb { margin-bottom: 20px; color: #666; font-size: 14px; }
        .breadcrumb a { color: #667eea; text-decoration: none; }
        .page-header { margin-bottom: 30px; }
        .page-header h2 { font-size: 32px; color: #333; }
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
        .alert ul { margin: 10px 0 0 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group .required { color: #dc3545; }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-group .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .file-input-wrapper input[type=file] { display: none; }
        .file-input-label {
            display: block;
            padding: 20px;
            background: #f8f9fa;
            border: 2px dashed #e0e0e0;
            border-radius: 6px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .file-input-label:hover {
            background: #e9ecef;
            border-color: #667eea;
        }
        .file-selected {
            margin-top: 10px;
            padding: 10px;
            background: #e7f3ff;
            border-radius: 6px;
            font-size: 13px;
            color: #004080;
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
        .btn-secondary { background: #6c757d; color: white; }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo url('finanse.faktury'); ?>">Faktury</a> / Dodaj
        </div>
        
        <div class="page-header">
            <h2>Dodaj Fakturę</h2>
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
            
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group">
                        <label>Numer faktury <span class="required">*</span></label>
                        <input type="text" name="number" value="<?php echo e($_POST['number'] ?? ''); ?>" 
                               placeholder="np. FV/2026/01/123" required>
                    </div>
                    <div class="form-group">
                        <label>Data faktury <span class="required">*</span></label>
                        <input type="date" name="date" value="<?php echo e($_POST['date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Kontrahent <span class="required">*</span></label>
                    <input type="text" name="contractor" value="<?php echo e($_POST['contractor'] ?? ''); ?>" 
                           placeholder="Nazwa firmy lub osoby" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Kwota brutto <span class="required">*</span></label>
                        <input type="number" name="amount_gross" value="<?php echo e($_POST['amount_gross'] ?? ''); ?>" 
                               min="0.01" step="0.01" placeholder="0.00" required>
                        <div class="help-text">Kwota w PLN</div>
                    </div>
                    <div class="form-group">
                        <label>Typ faktury</label>
                        <select name="scope">
                            <option value="business" <?php echo ($_POST['scope'] ?? 'business') === 'business' ? 'selected' : ''; ?>>Firmowa</option>
                            <option value="private" <?php echo ($_POST['scope'] ?? '') === 'private' ? 'selected' : ''; ?>>Prywatna</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="draft" <?php echo ($_POST['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>Szkic</option>
                        <option value="approved" <?php echo ($_POST['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>Zatwierdzona</option>
                    </select>
                    <div class="help-text">Faktury zatwierdzone wliczają się do kosztów projektu</div>
                </div>
                
                <div class="form-group">
                    <label>Plik faktury</label>
                    <div class="file-input-wrapper">
                        <input type="file" name="invoice_file" id="invoice_file" accept=".pdf,.jpg,.jpeg,.png" onchange="updateFileName(this)">
                        <label for="invoice_file" class="file-input-label">
                            Kliknij aby wybrac plik (PDF, JPG, PNG - max 10MB)
                        </label>
                    </div>
                    <div id="file-selected" class="file-selected" style="display: none;"></div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Utwórz Fakturę</button>
                    <a href="<?php echo url('finanse.faktury'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        function updateFileName(input) {
            const div = document.getElementById('file-selected');
            if (input.files && input.files[0]) {
                const name = input.files[0].name;
                const size = (input.files[0].size / 1024 / 1024).toFixed(2);
                div.innerHTML = `Wybrany plik: <strong>${name}</strong> (${size} MB)`;
                div.style.display = 'block';
            } else {
                div.style.display = 'none';
            }
        }
    </script>
</body>
</html>
