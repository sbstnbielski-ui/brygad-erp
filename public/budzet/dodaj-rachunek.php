<?php
/**
 * BRYGAD ERP - Budżet Domowy - Dodaj rachunek
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_hb.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit = HB_CAN_EDIT;
$userId = $_SESSION['user_id'];

if (!$canEdit) {
    die('Brak uprawnień do edycji.');
}

// Pobierz kategorie wydatków i konta
try {
    $stmtCategories = $pdo->prepare("
        SELECT id, name FROM hb_categories 
        WHERE household_id = ? AND type = 'expense' AND is_active = 1
        ORDER BY name
    ");
    $stmtCategories->execute([$householdId]);
    $categories = $stmtCategories->fetchAll();
    
    $stmtAccounts = $pdo->prepare("
        SELECT id, name FROM hb_accounts 
        WHERE household_id = ? AND is_active = 1
        ORDER BY sort_order, name
    ");
    $stmtAccounts->execute([$householdId]);
    $accounts = $stmtAccounts->fetchAll();
} catch (PDOException $e) {
    error_log("Form data fetch error: " . $e->getMessage());
    die('Błąd pobierania danych.');
}

// Obsługa POST
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $accountId = !empty($_POST['default_account_id']) ? (int)$_POST['default_account_id'] : null;
    $amountType = $_POST['amount_type'] ?? '';
    $fixedAmount = !empty($_POST['fixed_amount']) ? (float)$_POST['fixed_amount'] : null;
    $dueDay = (int)($_POST['due_day'] ?? 10);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    // Walidacja
    if (empty($name)) {
        $error = 'Podaj nazwę rachunku.';
    } elseif (!$categoryId) {
        $error = 'Wybierz kategorię.';
    } elseif (!in_array($amountType, ['fixed', 'variable'])) {
        $error = 'Wybierz typ kwoty.';
    } elseif ($amountType === 'fixed' && (!$fixedAmount || $fixedAmount <= 0)) {
        $error = 'Dla stałej kwoty podaj wartość większą od 0.';
    } elseif ($dueDay < 1 || $dueDay > 31) {
        $error = 'Dzień płatności musi być w zakresie 1-31.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO hb_bills 
                (household_id, category_id, default_account_id, name, amount_type, fixed_amount, due_day, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $householdId,
                $categoryId,
                $accountId,
                $name,
                $amountType,
                $fixedAmount,
                $dueDay,
                $isActive
            ]);
            
            // Redirect do listy definicji
            header("Location: " . url('budzet.rachunki.definicje'));
            exit;
            
        } catch (PDOException $e) {
            error_log("Bill save error: " . $e->getMessage());
            $error = 'Błąd podczas zapisywania rachunku.';
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
    <title><?php echo e(APP_NAME); ?> - Dodaj rachunek</title>
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
        }
        .logo-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .logo-section img { height: 50px; border-radius: 6px; }
        .logo-text h1 { font-size: 24px; color: #333; }
        .logo-text p { font-size: 13px; color: #666; }
        .user-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .user-name { font-weight: 600; color: #333; }
        .btn-logout, .btn-back {
            padding: 8px 16px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px;
        }
        .page-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            color: white;
            padding: 40px 50px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        .page-header h2 { font-size: 28px; margin-bottom: 8px; }
        .page-header .subtitle { font-size: 14px; color: #94a3b8; }
        .form-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #333;
        }
        .form-group input:not([type="checkbox"]),
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .form-group input:not([type="checkbox"]):focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-group.inline {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .form-group.inline label {
            margin-bottom: 0;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #333;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        .hidden {
            display: none;
        }
    </style>
    <script>
        function toggleFixedAmount() {
            const amountType = document.querySelector('input[name="amount_type"]:checked')?.value;
            const fixedAmountGroup = document.getElementById('fixed-amount-group');
            
            if (amountType === 'fixed') {
                fixedAmountGroup.classList.remove('hidden');
                document.getElementById('fixed_amount').required = true;
            } else {
                fixedAmountGroup.classList.add('hidden');
                document.getElementById('fixed_amount').required = false;
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            toggleFixedAmount();
        });
    </script>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <img src="<?php echo asset('logo-brygad-erp.png'); ?>" alt="BRYGAD ERP">
                <div class="logo-text">
                    <h1><?php echo e(APP_NAME); ?></h1>
                    <p>Budżet Domowy</p>
                </div>
            </div>
            <div class="user-section">
                <span class="user-name"><?php echo e($userName); ?></span>
                <a href="<?php echo HB_HOME_URL; ?>" class="btn-back"><?php echo HB_HOME_LABEL; ?></a>
                <a href="<?php echo url('logout'); ?>" class="btn-logout">Wyloguj</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="page-header">
            <h2>Dodaj rachunek cykliczny</h2>
            <div class="subtitle">Definicja rachunku do automatycznego generowania</div>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        
        <div class="form-card">
            <form method="POST">
                <div class="form-group">
                    <label>Nazwa rachunku <span style="color: #dc2626;">*</span></label>
                    <input 
                        type="text" 
                        name="name" 
                        placeholder="np. Czynsz, Netflix, Prąd"
                        required
                    >
                </div>
                
                <div class="form-group">
                    <label>Kategoria <span style="color: #dc2626;">*</span></label>
                    <select name="category_id" required>
                        <option value="">-- Wybierz kategorię wydatku --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>">
                            <?php echo e($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Domyślne konto płatności (opcjonalnie)</label>
                    <select name="default_account_id">
                        <option value="">-- Brak --</option>
                        <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>">
                            <?php echo e($acc['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Typ kwoty <span style="color: #dc2626;">*</span></label>
                    <div style="display: flex; gap: 20px;">
                        <label style="font-weight: normal;">
                            <input 
                                type="radio" 
                                name="amount_type" 
                                value="fixed" 
                                checked
                                onchange="toggleFixedAmount()"
                            > Stała kwota
                        </label>
                        <label style="font-weight: normal;">
                            <input 
                                type="radio" 
                                name="amount_type" 
                                value="variable"
                                onchange="toggleFixedAmount()"
                            > Zmienna kwota (ustawiana co miesiąc)
                        </label>
                    </div>
                </div>
                
                <div class="form-group" id="fixed-amount-group">
                    <label>Kwota stała <span style="color: #dc2626;">*</span></label>
                    <input 
                        type="number" 
                        name="fixed_amount" 
                        id="fixed_amount"
                        step="0.01" 
                        min="0.01"
                        placeholder="0.00"
                    >
                </div>
                
                <div class="form-group">
                    <label>Dzień płatności (1-31) <span style="color: #dc2626;">*</span></label>
                    <input 
                        type="number" 
                        name="due_day" 
                        value="10"
                        min="1"
                        max="31"
                        required
                    >
                    <small style="color: #666; font-size: 12px;">
                        Jeśli dzień przekracza ilość dni w miesiącu, zostanie użyty ostatni dzień miesiąca.
                    </small>
                </div>
                
                <div class="form-group inline">
                    <input 
                        type="checkbox" 
                        name="is_active" 
                        id="is_active"
                        checked
                    >
                    <label for="is_active">Aktywny (generuj pozycje co miesiąc)</label>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz rachunek</button>
                    <a href="<?php echo url('budzet.rachunki.definicje'); ?>" class="btn btn-secondary">
                        Anuluj
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

