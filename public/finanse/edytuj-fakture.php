<?php
/**
 * BRYGAD ERP v3.0 - Edytuj Fakturę
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// URL powrotu: priorytet — ostatnia lista cost-inbox, fallback — legacy lista faktur.
$backToListUrl = returnToListUrl('cost-inbox', url('finanse.faktury'));

if (!$invoice_id) {
    header("Location: " . $backToListUrl);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header("Location: " . $backToListUrl);
    exit;
}

$stmt = $pdo->prepare("SELECT COUNT(*) as allocations_count FROM cost_allocations WHERE invoice_id = ?");
$stmt->execute([$invoice_id]);
$allocations = $stmt->fetch();

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
    
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE number = ? AND id != ?");
        $stmt->execute([$number, $invoice_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Faktura o tym numerze już istnieje";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE invoices SET number=?, contractor=?, date=?, amount_gross=?, scope=?, status=? WHERE id=?");
            $stmt->execute([$number, $contractor, $date, $amount_gross, $scope, $status, $invoice_id]);
            logEvent("Zaktualizowano fakturę ID: {$invoice_id} ({$number})", 'INFO');
            header("Location: " . url('finanse.faktury.allocate', ['id' => $invoice_id, 'success' => 'updated']));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd aktualizacji faktury: " . $e->getMessage(), 'ERROR');
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

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Edytuj Fakturę</title>
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
        .alert-info {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            color: #004080;
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
            <a href="<?php echo e($backToListUrl); ?>">Faktury</a> / 
            <a href="<?php echo url('finanse.faktury.allocate', ['id' => $invoice_id]); ?>"><?php echo e($invoice['number']); ?></a> / 
            Edytuj
        </div>
        
        <div class="page-header">
            <h2>Edytuj Fakturę</h2>
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
            
            <?php if ($allocations['allocations_count'] > 0): ?>
                <div class="alert alert-info">
                    <strong>Uwaga!</strong> Ta faktura ma <?php echo $allocations['allocations_count']; ?> przypisań do projektów.
                    Zmiana kwoty może wymagać aktualizacji przypisań.
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
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
                               min="0.01" step="0.01" required>
                        <div class="help-text">Kwota w PLN</div>
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
                    <button type="submit" class="btn btn-primary">Zapisz Zmiany</button>
                    <a href="<?php echo url('finanse.faktury.allocate', ['id' => $invoice_id]); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
