<?php
/**
 * BRYGAD ERP - Budżet Domowy - Budżety
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_hb.php';
require_once __DIR__ . '/_module_layout.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit = HB_CAN_EDIT;

// Pobierz okres
$period = hb_period_from_request();

// Obsługa dodawania/edycji budżetu
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $limitAmount = (float)($_POST['limit_amount'] ?? 0);
        
        if (!$categoryId || $limitAmount <= 0) {
            $error = 'Wybierz kategorię i podaj kwotę limitu większą od 0.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO hb_budgets (household_id, category_id, period, limit_amount)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE limit_amount = ?
                ");
                $stmt->execute([$householdId, $categoryId, $period, $limitAmount, $limitAmount]);
                $success = 'Budżet został zapisany.';
            } catch (PDOException $e) {
                error_log("Budget save error: " . $e->getMessage());
                $error = 'Błąd podczas zapisywania budżetu.';
            }
        }
    } elseif ($action === 'delete') {
        $budgetId = (int)($_POST['budget_id'] ?? 0);
        if ($budgetId) {
            try {
                $stmt = $pdo->prepare("DELETE FROM hb_budgets WHERE id = ? AND household_id = ?");
                $stmt->execute([$budgetId, $householdId]);
                $success = 'Budżet został usunięty.';
            } catch (PDOException $e) {
                error_log("Budget delete error: " . $e->getMessage());
                $error = 'Błąd podczas usuwania budżetu.';
            }
        }
    }
}

// Pobierz kategorie wydatków
try {
    $stmtCat = $pdo->prepare("
        SELECT id, name FROM hb_categories 
        WHERE household_id = ? AND type = 'expense' AND is_active = 1
        ORDER BY name
    ");
    $stmtCat->execute([$householdId]);
    $categories = $stmtCat->fetchAll();
} catch (PDOException $e) {
    error_log("Categories fetch error: " . $e->getMessage());
    $categories = [];
}

// Pobierz budżety z wykonaniem
try {
    $stmt = $pdo->prepare("
        SELECT 
            b.id,
            b.category_id,
            b.limit_amount,
            c.name as category_name,
            COALESCE(SUM(t.amount), 0) as spent
        FROM hb_budgets b
        JOIN hb_categories c ON c.id = b.category_id
        LEFT JOIN hb_transactions t ON t.category_id = b.category_id 
            AND t.household_id = ? 
            AND t.period = ?
            AND t.direction = 'expense'
        WHERE b.household_id = ? AND b.period = ?
        GROUP BY b.id, b.category_id, b.limit_amount, c.name
        ORDER BY c.name
    ");
    $stmt->execute([$householdId, $period, $householdId, $period]);
    $budgets = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Budgets fetch error: " . $e->getMessage());
    $budgets = [];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Budżety</title>
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
        .dashboard-layout {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 30px;
            align-items: start;
        }
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
        .period-selector {
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .period-selector label {
            font-weight: 600;
            margin-right: 10px;
        }
        .period-selector input {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
        }
        .period-selector button {
            padding: 8px 16px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            margin-left: 10px;
        }
        .section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
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
        .progress-bar {
            background: #e5e7eb;
            height: 24px;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%);
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
        }
        .progress-fill.warning {
            background: linear-gradient(90deg, #f59e0b 0%, #fbbf24 100%);
        }
        .progress-fill.danger {
            background: linear-gradient(90deg, #dc2626 0%, #ef4444 100%);
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
    </style>
</head>
<body>
    <?php hb_module_shell_start([
        'active' => 'budzety',
        'title' => 'Budżety kategorii',
        'subtitle' => 'Limity wydatków i kontrola budżetu',
        'user_name' => $userName,
        'period_month' => substr($period, 0, 7),
    ]); ?>
        
        <form method="GET" class="period-selector">
            <label>Okres:</label>
            <input type="month" name="period" value="<?php echo e(substr($period, 0, 7)); ?>">
            <button type="submit">Pokaż</button>
        </form>
        
        <?php if ($error): ?>
        <div style="padding: 15px 20px; background: #fee2e2; color: #dc2626; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
            <?php echo e($error); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div style="padding: 15px 20px; background: #d1fae5; color: #059669; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
            <?php echo e($success); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($canEdit): ?>
        <div class="section" style="margin-bottom: 20px;">
            <h3 style="margin-bottom: 15px; font-size: 16px;">Dodaj limit budżetu</h3>
            <form method="POST" style="display: flex; gap: 15px; align-items: flex-end;">
                <input type="hidden" name="action" value="add">
                <div style="flex: 1;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 13px;">Kategoria</label>
                    <select name="category_id" style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;" required>
                        <option value="">-- Wybierz kategorię --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo e($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600; font-size: 13px;">Limit (PLN)</label>
                    <input 
                        type="number" 
                        name="limit_amount" 
                        step="0.01" 
                        min="0.01"
                        placeholder="0.00"
                        style="width: 100%; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 14px;"
                        required
                    >
                </div>
                <button type="submit" style="padding: 10px 24px; background: #16a34a; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">
                    Zapisz
                </button>
            </form>
            <p style="margin-top: 10px; font-size: 12px; color: #666;">
                Jeśli limit dla kategorii już istnieje, zostanie zaktualizowany.
            </p>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <?php if (empty($budgets)): ?>
                <div class="empty-state">
                    Brak ustawionych budżetów na ten okres. Dodaj limit powyżej.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Kategoria</th>
                            <th>Limit</th>
                            <th>Wydano</th>
                            <th>Pozostało</th>
                            <th>Wykonanie</th>
                            <?php if ($canEdit): ?>
                            <th>Akcje</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($budgets as $b): ?>
                        <?php
                            $remaining = $b['limit_amount'] - $b['spent'];
                            $percentage = $b['limit_amount'] > 0 ? ($b['spent'] / $b['limit_amount']) * 100 : 0;
                            $progressClass = '';
                            if ($percentage >= 100) {
                                $progressClass = 'danger';
                            } elseif ($percentage >= 80) {
                                $progressClass = 'warning';
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo e($b['category_name']); ?></strong></td>
                            <td><?php echo hb_format_money($b['limit_amount']); ?></td>
                            <td style="color: #dc2626; font-weight: 600;">
                                <?php echo hb_format_money($b['spent']); ?>
                            </td>
                            <td>
                                <span style="color: <?php echo $remaining >= 0 ? '#16a34a' : '#dc2626'; ?>; font-weight: 600;">
                                    <?php echo hb_format_money($remaining); ?>
                                </span>
                            </td>
                            <td style="width: 200px;">
                                <div class="progress-bar">
                                    <div class="progress-fill <?php echo $progressClass; ?>" 
                                         style="width: <?php echo min($percentage, 100); ?>%;">
                                        <?php echo number_format($percentage, 0); ?>%
                                    </div>
                                </div>
                            </td>
                            <?php if ($canEdit): ?>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Czy na pewno usunąć ten budżet?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="budget_id" value="<?php echo $b['id']; ?>">
                                    <button type="submit" style="padding: 6px 14px; background: #dc2626; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;">
                                        Usuń
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
