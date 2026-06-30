<?php
/**
 * BRYGAD ERP - Budżet Domowy - Konta (CRUD)
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_hb.php';
require_once __DIR__ . '/_module_layout.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit = HB_CAN_EDIT;
$userId = $_SESSION['user_id'];

// Obsługa POST (dodawanie/edycja/toggle aktywności)
$error = null;
$success = null;
$editAccount = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $accountId = $action === 'edit' ? (int)($_POST['account_id'] ?? 0) : null;
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? 'bank';
        $currency = strtoupper(trim($_POST['currency'] ?? 'PLN'));
        $currentBalance = !empty($_POST['current_balance']) ? (float)$_POST['current_balance'] : 0.00;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        
        // Walidacja
        if (empty($name)) {
            $error = 'Podaj nazwę konta.';
        } elseif (!in_array($type, ['bank', 'cash', 'savings', 'other'])) {
            $error = 'Nieprawidłowy typ konta.';
        } elseif (strlen($currency) !== 3) {
            $error = 'Waluta musi mieć 3 znaki (np. PLN, EUR).';
        } else {
            try {
                if ($action === 'add') {
                    $stmt = $pdo->prepare("
                        INSERT INTO hb_accounts 
                        (household_id, name, type, currency, current_balance, is_active, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$householdId, $name, $type, $currency, $currentBalance, $isActive, $sortOrder]);
                    $success = 'Konto zostało dodane.';
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE hb_accounts SET
                            name = ?,
                            type = ?,
                            currency = ?,
                            current_balance = ?,
                            is_active = ?,
                            sort_order = ?
                        WHERE id = ? AND household_id = ?
                    ");
                    $stmt->execute([$name, $type, $currency, $currentBalance, $isActive, $sortOrder, $accountId, $householdId]);
                    $success = 'Konto zostało zaktualizowane.';
                }
            } catch (PDOException $e) {
                error_log("Account save error: " . $e->getMessage());
                $error = 'Błąd podczas zapisywania konta.';
            }
        }
    } elseif ($action === 'toggle') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        if ($accountId) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE hb_accounts SET is_active = NOT is_active
                    WHERE id = ? AND household_id = ?
                ");
                $stmt->execute([$accountId, $householdId]);
                $success = 'Status konta został zmieniony.';
            } catch (PDOException $e) {
                error_log("Account toggle error: " . $e->getMessage());
                $error = 'Błąd podczas zmiany statusu.';
            }
        }
    }
}

// Tryb edycji
$editId = $_GET['edit'] ?? null;
if ($editId && $canEdit) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM hb_accounts WHERE id = ? AND household_id = ?");
        $stmt->execute([$editId, $householdId]);
        $editAccount = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Account fetch error: " . $e->getMessage());
    }
}

// Pobierz konta
try {
    $stmt = $pdo->prepare("
        SELECT id, name, type, currency, current_balance, is_active, sort_order
        FROM hb_accounts
        WHERE household_id = ?
        ORDER BY is_active DESC, sort_order, name
    ");
    $stmt->execute([$householdId]);
    $accounts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Accounts fetch error: " . $e->getMessage());
    $accounts = [];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

// Czy pokazać formularz dodawania
$showForm = isset($_GET['action']) && $_GET['action'] === 'add' && $canEdit;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Konta</title>
    <style>
        <?php echo hb_module_layout_styles(); ?>
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        .dashboard-layout {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
            align-items: start;
        }
        
        /* Sidebar styles */
        .sidebar-actions {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0;
            position: sticky;
            top: 92px;
        }
        .sidebar-actions-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .sidebar-actions-header h3 {
            font-size: 11px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0;
            font-weight: 600;
        }
        .sidebar-actions-body {
            padding: 8px;
        }
        .sidebar-section {
            margin-bottom: 20px;
        }
        .sidebar-section:last-child {
            margin-bottom: 8px;
        }
        .sidebar-section-title {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 12px 12px 8px 12px;
            font-weight: 600;
        }
        .sidebar-section:first-child .sidebar-section-title {
            margin-top: 4px;
        }
        .sidebar-actions a {
            display: block;
            padding: 10px 12px;
            margin-bottom: 4px;
            color: #374151;
            text-decoration: none;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            transition: all 0.2s ease;
            font-size: 13px;
            font-weight: 500;
        }
        .sidebar-actions a:hover {
            background: #f9fafb;
            border-color: #d1d5db;
            color: #111827;
        }
        .dashboard-content {
            min-width: 0;
        }
        
        @media (max-width: 1024px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            .sidebar-actions {
                position: static;
            }
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
        .nav-links {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .nav-links a {
            padding: 10px 20px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            text-decoration: none;
            color: #333;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .nav-links a:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        .nav-links a.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .section h3 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #f3f4f6;
            padding-bottom: 10px;
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
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input:focus,
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
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
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
        .btn-sm {
            padding: 6px 14px;
            font-size: 13px;
        }
        .btn-success {
            background: #16a34a;
            color: white;
        }
        .btn-warning {
            background: #f59e0b;
            color: white;
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
        .alert-success {
            background: #d1fae5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th {
            text-align: left;
            padding: 12px;
            background: #f9fafb;
            font-weight: 600;
            font-size: 13px;
            color: #666;
            border-bottom: 2px solid #e5e7eb;
        }
        table td {
            padding: 12px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        table tr:hover {
            background: #f9fafb;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-secondary { background: #e5e7eb; color: #6b7280; }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
    </style>
</head>
<body>
    <?php hb_module_shell_start([
        'active' => 'konta',
        'title' => 'Konta',
        'subtitle' => 'Zarządzanie kontami bankowymi i gotówką',
        'user_name' => $userName,
        'period_month' => date('Y-m'),
    ]); ?>
                
                <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
                <?php endif; ?>
                
                <?php if ($canEdit && ($showForm || $editAccount)): ?>
                <div class="section">
                    <h3><?php echo $editAccount ? 'Edytuj konto' : 'Dodaj nowe konto'; ?></h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="<?php echo $editAccount ? 'edit' : 'add'; ?>">
                        <?php if ($editAccount): ?>
                        <input type="hidden" name="account_id" value="<?php echo $editAccount['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label>Nazwa konta <span style="color: #dc2626;">*</span></label>
                            <input 
                                type="text" 
                                name="name" 
                                value="<?php echo e($editAccount['name'] ?? ''); ?>"
                                placeholder="np. mBank, Portfel, Skarbonka"
                                required
                            >
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Typ <span style="color: #dc2626;">*</span></label>
                                <select name="type" required>
                                    <option value="bank" <?php if (($editAccount['type'] ?? 'bank') === 'bank') echo 'selected'; ?>>Konto bankowe</option>
                                    <option value="cash" <?php if (($editAccount['type'] ?? '') === 'cash') echo 'selected'; ?>>Gotówka</option>
                                    <option value="savings" <?php if (($editAccount['type'] ?? '') === 'savings') echo 'selected'; ?>>Oszczędności</option>
                                    <option value="other" <?php if (($editAccount['type'] ?? '') === 'other') echo 'selected'; ?>>Inne</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Waluta <span style="color: #dc2626;">*</span></label>
                                <input 
                                    type="text" 
                                    name="currency" 
                                    value="<?php echo e($editAccount['currency'] ?? 'PLN'); ?>"
                                    maxlength="3"
                                    placeholder="PLN"
                                    required
                                >
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Saldo początkowe (opcjonalne)</label>
                                <input 
                                    type="number" 
                                    name="current_balance" 
                                    value="<?php echo $editAccount['current_balance'] ?? '0.00'; ?>"
                                    step="0.01"
                                    placeholder="0.00"
                                >
                            </div>
                            
                            <div class="form-group">
                                <label>Kolejność sortowania</label>
                                <input 
                                    type="number" 
                                    name="sort_order" 
                                    value="<?php echo $editAccount['sort_order'] ?? '0'; ?>"
                                    placeholder="0"
                                >
                            </div>
                        </div>
                        
                        <div class="form-group inline">
                            <input 
                                type="checkbox" 
                                name="is_active" 
                                id="is_active"
                                <?php if (!$editAccount || $editAccount['is_active']) echo 'checked'; ?>
                            >
                            <label for="is_active">Aktywne</label>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $editAccount ? 'Zapisz zmiany' : 'Dodaj konto'; ?>
                            </button>
                            <a href="<?php echo url('budzet.konta'); ?>" class="btn btn-secondary">
                                Anuluj
                            </a>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
                
                <div class="section">
                    <h3>Lista kont</h3>
                    
                    <?php if (empty($accounts)): ?>
                        <div class="empty-state">
                            Brak zdefiniowanych kont. Dodaj pierwsze konto powyżej.
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Nazwa</th>
                                    <th>Typ</th>
                                    <th>Waluta</th>
                                    <th>Saldo</th>
                                    <th>Status</th>
                                    <th>Utworzono</th>
                                    <?php if ($canEdit): ?>
                                    <th>Akcje</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($accounts as $acc): ?>
                                <tr>
                                    <td><strong><?php echo e($acc['name']); ?></strong></td>
                                    <td>
                                        <?php
                                        $typeLabels = [
                                            'bank' => 'Konto bankowe',
                                            'cash' => 'Gotówka',
                                            'savings' => 'Oszczędności',
                                            'other' => 'Inne'
                                        ];
                                        echo $typeLabels[$acc['type']] ?? $acc['type'];
                                        ?>
                                    </td>
                                    <td><?php echo e($acc['currency']); ?></td>
                                    <td><?php echo hb_format_money($acc['current_balance'], $acc['currency']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $acc['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $acc['is_active'] ? 'Aktywne' : 'Nieaktywne'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo e(date('d.m.Y', strtotime($acc['created_at']))); ?></td>
                                    <?php if ($canEdit): ?>
                                    <td>
                                        <a href="?edit=<?php echo $acc['id']; ?>" class="btn btn-primary btn-sm">
                                            Edytuj
                                        </a>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                                            <button type="submit" class="btn <?php echo $acc['is_active'] ? 'btn-warning' : 'btn-success'; ?> btn-sm">
                                                <?php echo $acc['is_active'] ? 'Dezaktywuj' : 'Aktywuj'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
    <?php hb_module_shell_end(); ?>
</body>
</html>
