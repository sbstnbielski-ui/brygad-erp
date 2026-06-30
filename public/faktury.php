<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();

// Filtrowanie
$status_filter = $_GET['status'] ?? 'all';
$scope_filter = $_GET['scope'] ?? 'all';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Budowanie zapytania
$sql = "SELECT 
    i.*,
    (SELECT COUNT(*) FROM cost_allocations WHERE invoice_id = i.id) as allocations_count,
    (SELECT COALESCE(SUM(amount), 0) FROM cost_allocations WHERE invoice_id = i.id) as allocated_amount
FROM invoices i
WHERE 1=1";

$params = [];

if ($status_filter !== 'all') {
    $sql .= " AND i.status = :status";
    $params['status'] = $status_filter;
}

if ($scope_filter !== 'all') {
    $sql .= " AND i.scope = :scope";
    $params['scope'] = $scope_filter;
}

if ($search !== '') {
    $sql .= " AND (i.number LIKE :search OR i.contractor LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($date_from !== '') {
    $sql .= " AND i.date >= :date_from";
    $params['date_from'] = $date_from;
}

if ($date_to !== '') {
    $sql .= " AND i.date <= :date_to";
    $params['date_to'] = $date_to;
}

$sql .= " ORDER BY i.date DESC, i.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Statystyki
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    COALESCE(SUM(amount_gross), 0) as total_amount,
    COALESCE(SUM(CASE WHEN scope = 'business' THEN amount_gross ELSE 0 END), 0) as business_amount,
    COALESCE(SUM(CASE WHEN scope = 'private' THEN amount_gross ELSE 0 END), 0) as private_amount
FROM invoices";
$stats = $pdo->query($stats_sql)->fetch();

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Faktury zewnętrzne</title>
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
            padding: 15px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-left {
            display: flex;
            align-items: center;
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
        }
        
        .header-links {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .header-links a {
            color: #667eea;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 5px;
            transition: background 0.3s;
            font-size: 14px;
        }
        
        .header-links a:hover {
            background: #f0f0f0;
        }
        
        .header-links a.active {
            background: #667eea;
            color: white;
            font-weight: 600;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            font-weight: normal;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            color: #333;
            font-size: 32px;
            font-weight: bold;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .filters form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            margin-bottom: 5px;
            color: #666;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .btn {
            padding: 10px 20px;
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
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            color: #666;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-draft {
            background: #ffc107;
            color: #000;
        }
        
        .badge-approved {
            background: #28a745;
            color: white;
        }
        
        .badge-business {
            background: #667eea;
            color: white;
        }
        
        .badge-private {
            background: #17a2b8;
            color: white;
        }
        
        .invoice-number {
            font-weight: 600;
            color: #333;
        }
        
        .action-links {
            display: flex;
            gap: 10px;
        }
        
        .action-links a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }
        
        .action-links a:hover {
            text-decoration: underline;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .allocation-status {
            font-size: 12px;
            color: #666;
        }
        
        .allocation-full {
            color: #28a745;
            font-weight: 600;
        }
        
        .allocation-partial {
            color: #ffc107;
            font-weight: 600;
        }
        
        .allocation-none {
            color: #dc3545;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <img src="assets/logo-brygad-erp.png" alt="BRYGAD ERP" style="height: 40px; border-radius: 5px;">
            <div style="margin-left: 15px;">
                <h1 style="font-size: 20px; margin: 0;">BRYGAD ERP</h1>
                <p style="font-size: 12px; color: #666; margin: 0;">System Zarządzania Operacyjnego</p>
            </div>
        </div>
        <div class="header-links">
            <a href="dashboard.php">Panel Główny</a>
            <a href="workers.php">Pracownicy</a>
            <a href="projekty.php">Projekty</a>
            <a href="faktury.php" class="active">Finanse</a>
            <a href="raporty-kosztow.php">Raporty</a>
            <span style="color: #666; margin: 0 10px;">|</span>
            <span style="color: #333; font-weight: 600;"><?php echo e($_SESSION['login'] ?? 'Użytkownik'); ?></span>
            <?php if (isAdmin()): ?>
                <span style="font-size: 11px; color: #667eea;">(Administrator)</span>
            <?php endif; ?>
            <a href="logout.php" style="color: #dc3545;">Wyloguj</a>
        </div>
    </div>
    
    <div class="container">
        <div class="stats">
            <div class="stat-card">
                <h3>Wszystkie faktury</h3>
                <div class="value"><?php echo $stats['total']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Wartość całkowita</h3>
                <div class="value"><?php echo formatMoney($stats['total_amount']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Faktury firmowe</h3>
                <div class="value"><?php echo formatMoney($stats['business_amount']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Faktury prywatne</h3>
                <div class="value"><?php echo formatMoney($stats['private_amount']); ?></div>
            </div>
        </div>
        
        <div class="filters">
            <form method="GET">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Szkic</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Zatwierdzone</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Zakres</label>
                    <select name="scope">
                        <option value="all" <?php echo $scope_filter === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="business" <?php echo $scope_filter === 'business' ? 'selected' : ''; ?>>Firmowe</option>
                        <option value="private" <?php echo $scope_filter === 'private' ? 'selected' : ''; ?>>Prywatne</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Szukaj</label>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Numer lub kontrahent...">
                </div>
                <div class="form-group">
                    <label>Data od</label>
                    <input type="date" name="date_from" value="<?php echo e($date_from); ?>">
                </div>
                <div class="form-group">
                    <label>Data do</label>
                    <input type="date" name="date_to" value="<?php echo e($date_to); ?>">
                </div>
                <button type="submit" class="btn btn-primary">Filtruj</button>
                <?php if ($status_filter !== 'all' || $scope_filter !== 'all' || $search !== '' || $date_from !== '' || $date_to !== ''): ?>
                    <a href="faktury.php" class="btn btn-secondary">Wyczyść</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="actions">
            <a href="dodaj-fakture.php" class="btn btn-success">+ Dodaj fakturę</a>
        </div>
        
        <div class="table-container">
            <?php if (count($invoices) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Numer</th>
                            <th>Kontrahent</th>
                            <th>Data</th>
                            <th>Kwota brutto</th>
                            <th>Przypisano</th>
                            <th>Typ</th>
                            <th>Status</th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                            <?php
                            $allocation_percent = $invoice['amount_gross'] > 0 
                                ? ($invoice['allocated_amount'] / $invoice['amount_gross']) * 100 
                                : 0;
                            
                            $allocation_class = 'allocation-none';
                            if ($allocation_percent >= 100) {
                                $allocation_class = 'allocation-full';
                            } elseif ($allocation_percent > 0) {
                                $allocation_class = 'allocation-partial';
                            }
                            ?>
                            <tr>
                                <td>
                                    <span class="invoice-number"><?php echo e($invoice['number']); ?></span>
                                </td>
                                <td><?php echo e($invoice['contractor']); ?></td>
                                <td><?php echo formatDate($invoice['date']); ?></td>
                                <td><strong><?php echo formatMoney($invoice['amount_gross']); ?></strong></td>
                                <td>
                                    <div class="allocation-status <?php echo $allocation_class; ?>">
                                        <?php echo formatMoney($invoice['allocated_amount']); ?>
                                        (<?php echo number_format($allocation_percent, 0); ?>%)
                                    </div>
                                    <?php if ($invoice['allocations_count'] > 0): ?>
                                        <div style="font-size: 11px; color: #999;">
                                            <?php echo $invoice['allocations_count']; ?> projekt(ów)
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $invoice['scope']; ?>">
                                        <?php echo $invoice['scope'] === 'business' ? 'Firmowa' : 'Prywatna'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $invoice['status']; ?>">
                                        <?php echo $invoice['status'] === 'draft' ? 'Szkic' : 'Zatwierdzona'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-links">
                                        <a href="alokuj-fakture.php?id=<?php echo $invoice['id']; ?>">Przypisz</a>
                                        <?php if (isAdmin()): ?>
                                            <a href="edytuj-fakture.php?id=<?php echo $invoice['id']; ?>">Edytuj</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <p>Brak faktur do wyświetlenia</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

