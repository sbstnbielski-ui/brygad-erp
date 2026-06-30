<?php
/**
 * BRYGAD ERP - Budżet Domowy - Zarządzanie rachunkami (definicje)
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_hb.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit = HB_CAN_EDIT;

// Pobierz definicje rachunków
try {
    $stmt = $pdo->prepare("
        SELECT 
            b.*,
            c.name as category_name,
            a.name as account_name
        FROM hb_bills b
        LEFT JOIN hb_categories c ON c.id = b.category_id
        LEFT JOIN hb_accounts a ON a.id = b.default_account_id
        WHERE b.household_id = ?
        ORDER BY b.is_active DESC, b.name ASC
    ");
    $stmt->execute([$householdId]);
    $bills = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Bills definitions fetch error: " . $e->getMessage());
    $bills = [];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Zarządzanie rachunkami</title>
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
        .actions-bar {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-add {
            padding: 10px 20px;
            background: #16a34a;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
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
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-secondary { background: #e5e7eb; color: #6b7280; }
        .btn {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
            margin-right: 5px;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
    </style>
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
            <h2>Zarządzanie rachunkami</h2>
            <div class="subtitle">Definicje rachunków cyklicznych</div>
        </div>
        
        <div class="actions-bar">
            <div>
                <a href="<?php echo url('budzet.rachunki'); ?>" class="btn-back">Powrót do rachunków</a>
            </div>
            <?php if ($canEdit): ?>
            <a href="<?php echo url('budzet.rachunki.dodaj'); ?>" class="btn-add">
                Dodaj rachunek
            </a>
            <?php endif; ?>
        </div>
        
        <div class="section">
            <?php if (empty($bills)): ?>
                <div class="empty-state">
                    Brak rachunków. Dodaj pierwszy rachunek cykliczny.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nazwa</th>
                            <th>Kategoria</th>
                            <th>Typ kwoty</th>
                            <th>Kwota stała</th>
                            <th>Dzień płatności</th>
                            <th>Domyślne konto</th>
                            <th>Status</th>
                            <th>Utworzono</th>
                            <?php if ($canEdit): ?>
                            <th>Akcje</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bills as $bill): ?>
                        <tr>
                            <td><strong><?php echo e($bill['name']); ?></strong></td>
                            <td><?php echo e($bill['category_name'] ?? '-'); ?></td>
                            <td><?php echo $bill['amount_type'] === 'fixed' ? 'Stała' : 'Zmienna'; ?></td>
                            <td>
                                <?php if ($bill['amount_type'] === 'fixed'): ?>
                                    <?php echo hb_format_money($bill['fixed_amount']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo $bill['due_day']; ?>.</td>
                            <td><?php echo e($bill['account_name'] ?? '-'); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $bill['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $bill['is_active'] ? 'Aktywny' : 'Nieaktywny'; ?>
                                </span>
                            </td>
                            <td><?php echo e(date('d.m.Y', strtotime($bill['created_at']))); ?></td>
                            <?php if ($canEdit): ?>
                            <td>
                                <a href="<?php echo url('budzet.rachunki.edytuj', ['id' => $bill['id']]); ?>" class="btn btn-primary">
                                    Edytuj
                                </a>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

