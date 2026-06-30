<?php
/**
 * BRYGAD ERP v3.0 - Zatwierdzanie Wydatku
 */

require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success = false;

$expenseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($expenseId <= 0) {
    header('Location: wydatki.php');
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
        header('Location: wydatki.php');
        exit;
    }
    
    if ($expense['status'] !== 'pending') {
        $_SESSION['error'] = 'Ten wydatek został już rozpatrzony.';
        header('Location: wydatki.php');
        exit;
    }
} catch (PDOException $e) {
    logEvent("Błąd pobierania wydatku ID $expenseId: " . $e->getMessage(), 'ERROR');
    header('Location: wydatki.php');
    exit;
}

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve' || $action === 'reject') {
        try {
            $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
            
            $stmt = $pdo->prepare("
                UPDATE worker_expenses 
                SET status = ?,
                    approved_by_user_id = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $newStatus,
                $_SESSION['user_id'],
                $expenseId
            ]);
            
            $actionText = ($action === 'approve') ? 'zatwierdzono' : 'odrzucono';
            logEvent("Wydatek ID $expenseId został $actionText", 'INFO');
            
            $success = true;
            header("Refresh: 2; url=wydatki.php");
        } catch (PDOException $e) {
            $errors[] = 'Błąd przetwarzania wydatku. Spróbuj ponownie.';
            logEvent("Błąd zatwierdzania wydatku ID $expenseId: " . $e->getMessage(), 'ERROR');
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
    <title><?php echo e(APP_NAME); ?> - Zatwierdź Wydatek</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .header {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .logo-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .logo-section img {
            height: 50px;
            border-radius: 6px;
        }
        .logo-text h1 { font-size: 24px; color: #333; }
        .logo-text p { font-size: 13px; color: #666; }
        .nav-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .nav-section a {
            padding: 10px 20px;
            color: #333;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .nav-section a:hover {
            background: #f0f0f0;
        }
        .nav-section a.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .user-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .user-name { font-weight: 600; color: #333; }
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }
        .btn-logout {
            padding: 10px 20px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
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
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <img src="assets/logo-brygad-erp.png" alt="BRYGAD ERP">
                <div class="logo-text">
                    <h1><?php echo e(APP_NAME); ?></h1>
                    <p>System Zarządzania Operacyjnego</p>
                </div>
            </div>
            <nav class="nav-section">
                <a href="dashboard.php">Panel Główny</a>
                <a href="workers.php">Pracownicy</a>
                <a href="dziennik-pracy.php">Dziennik Pracy</a>
                <a href="wydatki.php" class="active">Wydatki</a>
                <a href="rozliczenia.php">Rozliczenia</a>
            </nav>
            <div class="user-section">
                <div>
                    <span class="user-name">
                        <?php echo e($userName); ?>
                        <?php if ($isAdminUser): ?>
                            <span class="role-badge">Administrator</span>
                        <?php endif; ?>
                    </span>
                </div>
                <a href="logout.php" class="btn-logout">Wyloguj</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="dashboard.php">Panel Główny</a> / 
            <a href="wydatki.php">Wydatki</a> / 
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
                        <span><?php echo e(str_replace('[PAID_BY_EMPLOYEE] ', '', $expense['description'])); ?></span>
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
                    <div class="form-actions">
                        <button type="submit" name="action" value="approve" class="btn btn-approve">
                            Zatwierdź Wydatek
                        </button>
                        <button type="submit" name="action" value="reject" class="btn btn-reject" 
                                onclick="return confirm('Czy na pewno chcesz odrzucić ten wydatek?')">
                            Odrzuć
                        </button>
                        <a href="wydatki.php" class="btn btn-secondary">
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
</body>
</html>

