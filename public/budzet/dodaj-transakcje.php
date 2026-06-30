<?php
/**
 * BRYGAD ERP - Budżet Domowy - Dodaj transakcję
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

// Pobierz okres z URL
$period = hb_period_from_request();

// Pobierz dane do formularza
try {
    $stmtAccounts = $pdo->prepare("
        SELECT id, name, current_balance
        FROM hb_accounts
        WHERE household_id = ? AND is_active = 1
        ORDER BY sort_order, name
    ");
    $stmtAccounts->execute([$householdId]);
    $accounts = $stmtAccounts->fetchAll();
    
    $stmtCategories = $pdo->prepare("
        SELECT id, name, type
        FROM hb_categories
        WHERE household_id = ? AND is_active = 1
        ORDER BY type, name
    ");
    $stmtCategories->execute([$householdId]);
    $allCategories = $stmtCategories->fetchAll();
    
    // Rozdziel kategorie na przychody i wydatki
    $incomeCategories = array_filter($allCategories, fn($c) => $c['type'] === 'income');
    $expenseCategories = array_filter($allCategories, fn($c) => $c['type'] === 'expense');
    
} catch (PDOException $e) {
    error_log("Form data fetch error: " . $e->getMessage());
    die('Błąd pobierania danych.');
}

// Obsługa POST
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $direction         = $_POST['direction']         ?? '';
    $accountId         = (int)($_POST['account_id']  ?? 0);
    $amount            = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
    $date              = $_POST['date']              ?? '';
    $categoryId        = !empty($_POST['category_id'])        ? (int)$_POST['category_id']        : null;
    $transferAccountId = !empty($_POST['transfer_account_id']) ? (int)$_POST['transfer_account_id'] : null;
    $description       = trim($_POST['description'] ?? '');
    $visibility        = in_array($_POST['visibility'] ?? '', ['shared', 'private']) ? $_POST['visibility'] : 'shared';
    $includeInTotal    = ($visibility === 'shared') ? 1 : (isset($_POST['include_in_household_total']) ? 1 : 0);
    $ownerUserId       = ($visibility === 'private') ? $userId : null;
    
    if (!in_array($direction, ['income', 'expense', 'transfer'])) {
        $error = 'Wybierz typ transakcji.';
    } elseif (!$accountId) {
        $error = 'Wybierz konto.';
    } elseif ($amount <= 0) {
        $error = 'Kwota musi być większa od 0.';
    } elseif (!$date) {
        $error = 'Podaj datę.';
    } elseif ($direction === 'transfer' && !$transferAccountId) {
        $error = 'Dla transferu wybierz konto docelowe.';
    } elseif ($direction === 'transfer' && $transferAccountId === $accountId) {
        $error = 'Konto źródłowe i docelowe nie mogą być takie same.';
    } elseif (in_array($direction, ['income', 'expense']) && !$categoryId) {
        $error = 'Wybierz kategorię.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Oblicz period z daty
            $transactionPeriod = date('Y-m-01', strtotime($date));
            
            // Dla transferu: category_id i bill_item_id muszą być NULL
            if ($direction === 'transfer') {
                $categoryId = null;
            }
            
            // Zapisz transakcję
            $stmt = $pdo->prepare("
                INSERT INTO hb_transactions
                (household_id, account_id, transfer_account_id, direction, amount, 
                 date, period, category_id, description, created_by,
                 visibility, owner_user_id, include_in_household_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $householdId,
                $accountId,
                $transferAccountId,
                $direction,
                $amount,
                $date,
                $transactionPeriod,
                $categoryId,
                $description,
                $userId,
                $visibility,
                $ownerUserId,
                $includeInTotal,
            ]);
            
            $pdo->commit();
            
            // Redirect do listy transakcji
            header("Location: " . url('budzet.transakcje', ['period' => substr($transactionPeriod, 0, 7)]));
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Transaction save error: " . $e->getMessage());
            $error = 'Błąd podczas zapisywania transakcji.';
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
    <title><?php echo e(APP_NAME); ?> - Dodaj transakcję</title>
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
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
        function toggleFields() {
            const direction = document.getElementById('direction').value;
            const categoryRow = document.getElementById('category-row');
            const transferAccountRow = document.getElementById('transfer-account-row');
            
            if (direction === 'transfer') {
                categoryRow.classList.add('hidden');
                transferAccountRow.classList.remove('hidden');
            } else {
                categoryRow.classList.remove('hidden');
                transferAccountRow.classList.add('hidden');
            }
            
            // Zmień dostępne kategorie
            const categorySelect = document.getElementById('category_id');
            if (direction === 'income') {
                updateCategoryOptions('income');
            } else if (direction === 'expense') {
                updateCategoryOptions('expense');
            }
        }
        
        function updateCategoryOptions(type) {
            const categorySelect = document.getElementById('category_id');
            const options = categorySelect.querySelectorAll('option');
            
            options.forEach(opt => {
                if (opt.value === '') return; // Skip placeholder
                const optType = opt.getAttribute('data-type');
                if (optType === type) {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                }
            });
            
            // Reset selection if current is not valid
            const selectedOpt = categorySelect.selectedOptions[0];
            if (selectedOpt && selectedOpt.getAttribute('data-type') !== type && selectedOpt.value !== '') {
                categorySelect.value = '';
            }
        }
        function togglePrivacyOptions() {
            var vis = document.getElementById('visibility_select').value;
            var row = document.getElementById('include-total-row');
            row.classList.toggle('hidden', vis !== 'private');
        }
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
            <h2>Dodaj transakcję</h2>
            <div class="subtitle">Rejestracja przychodu, wydatku lub transferu</div>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        
        <div class="form-card">
            <form method="POST">
                <div class="form-group">
                    <label>Typ transakcji <span style="color: #dc2626;">*</span></label>
                    <select name="direction" id="direction" required onchange="toggleFields()">
                        <option value="">-- Wybierz typ --</option>
                        <option value="income">Przychód</option>
                        <option value="expense">Wydatek</option>
                        <option value="transfer">Transfer</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Konto źródłowe <span style="color: #dc2626;">*</span></label>
                    <select name="account_id" required>
                        <option value="">-- Wybierz konto --</option>
                        <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>">
                            <?php echo e($acc['name']); ?> 
                            (Saldo: <?php echo hb_format_money($acc['current_balance']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group hidden" id="transfer-account-row">
                    <label>Konto docelowe <span style="color: #dc2626;">*</span></label>
                    <select name="transfer_account_id">
                        <option value="">-- Wybierz konto --</option>
                        <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>">
                            <?php echo e($acc['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group hidden" id="category-row">
                    <label>Kategoria <span style="color: #dc2626;">*</span></label>
                    <select name="category_id" id="category_id">
                        <option value="">-- Wybierz kategorię --</option>
                        <?php foreach ($allCategories as $cat): ?>
                        <option 
                            value="<?php echo $cat['id']; ?>" 
                            data-type="<?php echo $cat['type']; ?>"
                        >
                            <?php echo e($cat['name']); ?> (<?php echo $cat['type'] === 'income' ? 'Przychód' : 'Wydatek'; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Kwota <span style="color: #dc2626;">*</span></label>
                    <input 
                        type="number" 
                        name="amount" 
                        step="0.01" 
                        min="0.01"
                        required
                    >
                </div>
                
                <div class="form-group">
                    <label>Data <span style="color: #dc2626;">*</span></label>
                    <input 
                        type="date" 
                        name="date" 
                        value="<?php echo date('Y-m-d'); ?>"
                        required
                    >
                </div>
                
                <div class="form-group">
                    <label>Opis (opcjonalnie)</label>
                    <textarea name="description" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label>Widoczność</label>
                    <select name="visibility" id="visibility_select" onchange="togglePrivacyOptions()">
                        <option value="shared">Wspólna (widoczna dla wszystkich domowników)</option>
                        <option value="private">Prywatna (tylko Ty widzisz)</option>
                    </select>
                </div>

                <div class="form-group hidden" id="include-total-row">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:normal;">
                        <input type="checkbox" name="include_in_household_total" value="1">
                        <span>Uwzględnij w sumach domowych (np. w zestawieniach)</span>
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz transakcję</button>
                    <a href="<?php echo url('budzet.transakcje', ['period' => substr($period, 0, 7)]); ?>" class="btn btn-secondary">
                        Anuluj
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

