<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

// Utworzenie katalogu uploads/invoices jeśli nie istnieje
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
    
    // Walidacja
    if (empty($number)) {
        $errors[] = "Numer faktury jest wymagany";
    }
    
    if (empty($contractor)) {
        $errors[] = "Nazwa kontrahenta jest wymagana";
    }
    
    if (empty($date)) {
        $errors[] = "Data faktury jest wymagana";
    }
    
    if ($amount_gross <= 0) {
        $errors[] = "Kwota brutto musi być większa od 0";
    }
    
    if (!in_array($scope, ['business', 'private'])) {
        $errors[] = "Nieprawidłowy zakres faktury";
    }
    
    if (!in_array($status, ['draft', 'approved'])) {
        $errors[] = "Nieprawidłowy status";
    }
    
    // Sprawdź duplikat numeru
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE number = :number");
        $stmt->execute(['number' => $number]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Faktura o tym numerze już istnieje";
        }
    }
    
    // Upload pliku
    $file_path = null;
    if (isset($_FILES['invoice_file']) && $_FILES['invoice_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['invoice_file'];
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
        $max_size = 10 * 1024 * 1024; // 10MB
        
        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = "Dozwolone są tylko pliki PDF, JPG i PNG";
        }
        
        if ($file['size'] > $max_size) {
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
            $sql = "INSERT INTO invoices (number, contractor, date, amount_gross, scope, status, file_path) 
                    VALUES (:number, :contractor, :date, :amount_gross, :scope, :status, :file_path)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'number' => $number,
                'contractor' => $contractor,
                'date' => $date,
                'amount_gross' => $amount_gross,
                'scope' => $scope,
                'status' => $status,
                'file_path' => $file_path
            ]);
            
            $invoice_id = $pdo->lastInsertId();
            
            logEvent("Utworzono fakturę: {$number} ({$contractor}), kwota: {$amount_gross} PLN", 'info');
            
            header("Location: alokuj-fakture.php?id={$invoice_id}&success=created");
            exit;
            
        } catch (PDOException $e) {
            logEvent("Błąd tworzenia faktury: " . $e->getMessage(), 'error');
            $errors[] = "Błąd podczas tworzenia faktury";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj fakturę</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
        }
        
        .header-links a {
            color: #667eea;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .header-links a:hover {
            background: #f0f0f0;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group label .required {
            color: #dc3545;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group .hint {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }
        
        .file-input-wrapper input[type=file] {
            position: absolute;
            left: -9999px;
        }
        
        .file-input-label {
            display: block;
            padding: 12px;
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 5px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .file-input-label:hover {
            background: #e9ecef;
            border-color: #667eea;
        }
        
        .file-selected {
            margin-top: 10px;
            padding: 10px;
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            font-size: 13px;
            color: #004085;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Dodaj fakturę zewnętrzną</h1>
        <div class="header-links">
            <a href="faktury.php">← Powrót do listy</a>
        </div>
    </div>
    
    <div class="container">
        <div class="form-container">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group">
                        <label>
                            Numer faktury <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="number" 
                            value="<?php echo e($_POST['number'] ?? ''); ?>" 
                            required
                            placeholder="np. FV/2026/01/123"
                        >
                    </div>
                    
                    <div class="form-group">
                        <label>
                            Data faktury <span class="required">*</span>
                        </label>
                        <input 
                            type="date" 
                            name="date" 
                            value="<?php echo e($_POST['date'] ?? date('Y-m-d')); ?>" 
                            required
                        >
                    </div>
                </div>
                
                <div class="form-group">
                    <label>
                        Kontrahent <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="contractor" 
                        value="<?php echo e($_POST['contractor'] ?? ''); ?>" 
                        required
                        placeholder="Nazwa firmy lub osoby"
                    >
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>
                            Kwota brutto <span class="required">*</span>
                        </label>
                        <input 
                            type="number" 
                            name="amount_gross" 
                            value="<?php echo e($_POST['amount_gross'] ?? ''); ?>" 
                            required
                            min="0.01"
                            step="0.01"
                            placeholder="0.00"
                        >
                        <div class="hint">Kwota brutto w PLN</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Typ faktury</label>
                        <select name="scope">
                            <option value="business" <?php echo ($_POST['scope'] ?? 'business') === 'business' ? 'selected' : ''; ?>>
                                Firmowa
                            </option>
                            <option value="private" <?php echo ($_POST['scope'] ?? '') === 'private' ? 'selected' : ''; ?>>
                                Prywatna (rozliczana)
                            </option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="draft" <?php echo ($_POST['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>
                            Szkic (do sprawdzenia)
                        </option>
                        <option value="approved" <?php echo ($_POST['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>
                            Zatwierdzona
                        </option>
                    </select>
                    <div class="hint">Faktury zatwierdzone wliczają się do kosztów projektu</div>
                </div>
                
                <div class="form-group">
                    <label>Plik faktury (PDF lub zdjęcie)</label>
                    <div class="file-input-wrapper">
                        <input 
                            type="file" 
                            name="invoice_file" 
                            id="invoice_file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            onchange="updateFileName(this)"
                        >
                        <label for="invoice_file" class="file-input-label">
                            Kliknij aby wybrać plik (PDF, JPG, PNG - max 10MB)
                        </label>
                    </div>
                    <div id="file-selected" class="file-selected" style="display: none;"></div>
                    <div class="hint">Opcjonalnie - możesz dodać skan lub zdjęcie faktury</div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Utwórz fakturę</button>
                    <a href="faktury.php" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function updateFileName(input) {
            const fileSelectedDiv = document.getElementById('file-selected');
            if (input.files && input.files[0]) {
                const fileName = input.files[0].name;
                const fileSize = (input.files[0].size / 1024 / 1024).toFixed(2);
                fileSelectedDiv.innerHTML = `Wybrany plik: <strong>${fileName}</strong> (${fileSize} MB)`;
                fileSelectedDiv.style.display = 'block';
            } else {
                fileSelectedDiv.style.display = 'none';
            }
        }
    </script>
</body>
</html>


