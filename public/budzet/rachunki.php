<?php
/**
 * BRYGAD ERP - Budżet Domowy - Rachunki
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

// Zapewnij istnienie rachunków
hb_ensure_bill_items($householdId, $period);

// Pobierz rachunki
try {
    $stmt = $pdo->prepare("
        SELECT 
            bi.*,
            b.name as bill_name,
            b.amount_type
        FROM hb_bill_items bi
        JOIN hb_bills b ON b.id = bi.bill_id
        WHERE bi.household_id = ? AND bi.period = ?
        ORDER BY bi.due_date ASC, b.name ASC
    ");
    $stmt->execute([$householdId, $period]);
    $bills = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Bills fetch error: " . $e->getMessage());
    $bills = [];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Rachunki</title>
    <style>
        <?php echo hb_module_layout_styles(); ?>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --bg-body: #f5f7fa;
            --bg-card: #ffffff;
            --border: #e5e7eb;
            --border-light: #f3f4f6;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
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
        .section h3 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #f3f4f6;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th {
            text-align: left;
            padding: 10px 14px;
            background: #f9fafb;
            font-weight: 600;
            font-size: 11px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        table td {
            padding: 10px 14px !important;
            border: 1px solid #e5e7eb !important;
            font-size: 13px !important;
            vertical-align: middle;
        }
        table th {
            border: 1px solid #e5e7eb !important;
        }
        /* Zebra-striping */
        tbody tr:nth-child(odd) {
            background: #ffffff !important;
        }
        tbody tr:nth-child(even) {
            background: #f8fafc !important;
        }
        tbody tr:hover {
            background: #e0f2fe !important;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-success { background: #d1fae5; color: #059669; }
        .btn {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
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
    <?php hb_module_shell_start([
        'active' => 'rachunki',
        'title' => 'Rachunki do zapłaty',
        'subtitle' => 'Zarządzanie rachunkami i płatnościami',
        'user_name' => $userName,
        'period_month' => substr($period, 0, 7),
    ]); ?>
        
        <?php if ($canEdit): ?>
        <div style="margin-bottom: 20px; text-align: right;">
            <a href="<?php echo url('budzet.rachunki.definicje'); ?>" style="padding: 10px 20px; background: #16a34a; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">
                Zarządzaj rachunkami
            </a>
        </div>
        <?php endif; ?>
        
        <form method="GET" class="period-selector">
            <label>Okres:</label>
            <input type="month" name="period" value="<?php echo e(substr($period, 0, 7)); ?>">
            <button type="submit">Pokaż</button>
        </form>
        
        <div class="section">
            <h3>Lista rachunków</h3>
            <?php if (empty($bills)): ?>
                <div class="empty-state">Brak zdefiniowanych rachunków</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Rachunek</th>
                            <th>Typ</th>
                            <th>Termin płatności</th>
                            <th>Do zapłaty</th>
                            <th>Zapłacono</th>
                            <th>Pozostało</th>
                            <th>Status</th>
                            <?php if ($canEdit): ?>
                            <th>Akcje</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bills as $bill): ?>
                        <?php
                            $remaining = $bill['amount_due'] - $bill['amount_paid'];
                        ?>
                        <tr>
                            <td><strong><?php echo e($bill['bill_name']); ?></strong></td>
                            <td><?php echo $bill['amount_type'] === 'fixed' ? 'Stała kwota' : 'Zmienna kwota'; ?></td>
                            <td><?php echo e(date('d.m.Y', strtotime($bill['due_date']))); ?></td>
                            <td><?php echo hb_format_money($bill['amount_due']); ?></td>
                            <td><?php echo hb_format_money($bill['amount_paid']); ?></td>
                            <td><strong><?php echo hb_format_money($remaining); ?></strong></td>
                            <td>
                                <span class="badge badge-<?php echo hb_bill_status_class($bill['status']); ?>">
                                    <?php echo hb_format_bill_status($bill['status']); ?>
                                </span>
                            </td>
                            <?php if ($canEdit): ?>
                            <td>
                                <?php if ($bill['amount_type'] === 'variable'): ?>
                                <a href="<?php echo url('budzet.rachunki.ustaw_kwote', ['id' => $bill['id']]); ?>" class="btn" style="background: #f59e0b; color: white; margin-right: 5px;">
                                    Ustaw kwotę
                                </a>
                                <?php endif; ?>
                                <?php if ($remaining > 0): ?>
                                <a href="<?php echo url('budzet.oplac', ['id' => $bill['id']]); ?>" class="btn btn-primary">
                                    Opłać
                                </a>
                                <?php endif; ?>
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
