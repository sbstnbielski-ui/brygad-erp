<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$invoice_id = $_GET['id'] ?? null;

if (!$invoice_id) {
    header("Location: faktury.php");
    exit;
}

// Pobierz fakturę
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = :id");
$stmt->execute(['id' => $invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header("Location: faktury.php");
    exit;
}

// Sprawdź przypisania
$stmt = $pdo->prepare("SELECT COUNT(*) as allocations_count FROM cost_allocations WHERE invoice_id = :id");
$stmt->execute(['id' => $invoice_id]);
$allocations = $stmt->fetch();

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
    
    // Sprawdź duplikat numeru
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE number = :number AND id != :id");
        $stmt->execute(['number' => $number, 'id' => $invoice_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Faktura o tym numerze już istnieje";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE invoices 
                SET number = :number,
                    contractor = :contractor,
                    date = :date,
                    amount_gross = :amount_gross,
                    scope = :scope,
                    status = :status
                WHERE id = :id
            ");
            $stmt->execute([
                'number' => $number,
                'contractor' => $contractor,
                'date' => $date,
                'amount_gross' => $amount_gross,
                'scope' => $scope,
                'status' => $status,
                'id' => $invoice_id
            ]);
            
            logEvent("Zaktualizowano fakturę ID: {$invoice_id} ({$number})", 'info');
            
            header("Location: alokuj-fakture.php?id={$invoice_id}&success=updated");
            exit;
            
        } catch (PDOException $e) {
            logEvent("Błąd aktualizacji faktury: " . $e->getMessage(), 'error');
            $errors[] = "Błąd podczas aktualizacji faktury";
        }
    }
} else {
    $_POST = [
        'number' => $invoice['number'],
        'contractor' => $invoice['contractor'],
        'date' => $invoice['date'],
        'amount_gross' => $invoice['amount_gross'],
        'scope' => $invoice['scope'],
        'status' => $invoice['status']
    ];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Edytuj fakturę</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
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
        .header h1 { color: #333; font-size: 28px; }
        .header-links a {
            color: #667eea;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .header-links a:hover { background: #f0f0f0; }
        .container { max-width: 800px; margin: 0 auto; }
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        .form-group label .required { color: #dc3545; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .form-group input:focus, .form-group select:focus {
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
        .alert ul { margin: 0; padding-left: 20px; }
        .info-box {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
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
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Edytuj fakturę</h1>
        <div class="header-links">
            <a href="alokuj-fakture.php?id=<?php echo $invoice_id; ?>">← Powrót</a>
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
            
            <?php if ($allocations['allocations_count'] > 0): ?>
                <div class="info-box">
                    <strong>Uwaga:</strong> Ta faktura ma <?php echo $allocations['allocations_count']; ?> przypisań do projektów.
                    Zmiana kwoty może wymagać aktualizacji przypisań.
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Numer faktury <span class="required">*</span></label>
                        <input type="text" name="number" value="<?php echo e($_POST['number'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Data faktury <span class="required">*</span></label>
                        <input type="date" name="date" value="<?php echo e($_POST['date'] ?? ''); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Kontrahent <span class="required">*</span></label>
                    <input type="text" name="contractor" value="<?php echo e($_POST['contractor'] ?? ''); ?>" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Kwota brutto <span class="required">*</span></label>
                        <input type="number" name="amount_gross" value="<?php echo e($_POST['amount_gross'] ?? ''); ?>" 
                               required min="0.01" step="0.01">
                        <div class="hint">Kwota brutto w PLN</div>
                    </div>
                    <div class="form-group">
                        <label>Typ faktury</label>
                        <select name="scope">
                            <option value="business" <?php echo ($_POST['scope'] ?? '') === 'business' ? 'selected' : ''; ?>>Firmowa</option>
                            <option value="private" <?php echo ($_POST['scope'] ?? '') === 'private' ? 'selected' : ''; ?>>Prywatna</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="draft" <?php echo ($_POST['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Szkic</option>
                        <option value="approved" <?php echo ($_POST['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>Zatwierdzona</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                    <a href="alokuj-fakture.php?id=<?php echo $invoice_id; ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>


