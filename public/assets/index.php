<?php
/**
 * BRYGAD ERP - Moduł Maszyny i Auta
 * Lista zasobów (ASSETS)
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// Filtry GET
$filterQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'active';
$filterType = isset($_GET['type']) ? $_GET['type'] : '';

// Walidacja statusu
if (!in_array($filterStatus, ['active', 'inactive', 'all'])) {
    $filterStatus = 'active';
}

// Pobierz zasoby
$assets = [];
try {
    $sql = "SELECT 
                a.id,
                a.type,
                a.name,
                a.reg_number,
                a.serial_number,
                a.usage_unit,
                a.current_usage,
                a.is_active,
                a.production_year,
                (
                    SELECT MIN(ae.due_date)
                    FROM asset_events ae
                    WHERE ae.asset_id = a.id 
                    AND ae.status = 'planned'
                    AND ae.due_date >= CURDATE()
                ) as next_due_date,
                (
                    SELECT COUNT(*)
                    FROM v_asset_alerts vaa
                    WHERE vaa.asset_id = a.id 
                    AND vaa.alert_level > 0
                ) as alerts_count
            FROM assets a
            WHERE 1=1";
    
    $params = [];
    
    // Filtr statusu
    if ($filterStatus === 'active') {
        $sql .= " AND a.is_active = 1";
    } elseif ($filterStatus === 'inactive') {
        $sql .= " AND a.is_active = 0";
    }
    
    // Filtr typu
    if (!empty($filterType)) {
        $sql .= " AND a.type = ?";
        $params[] = $filterType;
    }
    
    // Wyszukiwanie
    if (!empty($filterQuery)) {
        $sql .= " AND (a.name LIKE ? OR a.reg_number LIKE ? OR a.serial_number LIKE ?)";
        $searchParam = '%' . $filterQuery . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY a.name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("Błąd pobierania zasobów: " . $e->getMessage(), 'ERROR');
    $assets = [];
}

// Typy do filtra
$assetTypes = [
    'car_passenger' => 'Auto osobowe',
    'car_delivery' => 'Auto dostawcze',
    'truck' => 'Ciężarówka',
    'excavator' => 'Koparka',
    'lift' => 'Podnośnik',
    'tool' => 'Narzędzie',
    'other' => 'Inne'
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Maszyny i Auta</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        /* Layout: sidebar + content */
        .dashboard-layout {
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 30px;
            align-items: start;
        }
        
        .sidebar-actions {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            position: sticky;
            top: 20px;
        }
        
        .sidebar-actions a {
            display: block;
            padding: 10px 14px;
            margin-bottom: 6px;
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
        
        .sidebar-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 12px 0;
        }
        
        .dashboard-content {
            min-width: 0;
        }
        
        /* Page header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            font-size: 32px;
            color: #333;
        }
        
        /* Card */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        /* Filtry */
        .filter-bar {
            padding: 16px 20px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-bar input[type="text"],
        .filter-bar select {
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            background: white;
            height: 42px;
        }
        
        .filter-bar input[type="text"] {
            width: 280px;
        }
        
        .filter-bar select {
            width: 160px;
        }
        
        .filter-bar input[type="text"]:focus,
        .filter-bar select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .filter-bar .btn-filter {
            padding: 10px 20px;
            height: 42px;
            font-size: 14px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-primary-filter {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-secondary-filter {
            background: #6c757d;
            color: white;
        }
        
        .filter-count {
            color: #6b7280;
            font-size: 13px;
            margin-left: auto;
        }
        
        /* Tabela */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f9fafb;
        }
        
        th {
            padding: 12px 20px;
            text-align: left;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 16px 20px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }
        
        tbody tr:hover {
            background: #f9fafb;
        }
        
        .asset-name {
            font-weight: 600;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .asset-name:hover {
            color: #667eea;
            text-decoration: underline;
        }
        
        .asset-details {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 4px;
        }
        
        /* Badge */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-alert {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #d97706;
        }
        
        .badge-success {
            background: #dcfce7;
            color: #16a34a;
        }
        
        /* Status dot */
        .status-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        .status-dot.active {
            background: #22c55e;
            box-shadow: 0 0 0 2px #22c55e33;
        }
        
        .status-dot.inactive {
            background: #9ca3af;
            box-shadow: 0 0 0 2px #9ca3af33;
        }
        
        /* Buttony */
        .btn {
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid;
            transition: all 0.2s;
            display: inline-block;
            text-align: center;
        }
        
        .btn-small {
            padding: 5px 10px;
            font-size: 11px;
        }
        
        .btn-edit {
            background: white;
            color: #059669;
            border-color: #059669;
        }
        
        .btn-edit:hover {
            background: #059669;
            color: white;
        }
        
        .actions-group {
            display: flex;
            gap: 6px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        .no-data {
            padding: 80px 20px;
            text-align: center;
            color: #9ca3af;
            font-size: 15px;
        }
        
        @media (max-width: 1024px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            .sidebar-actions {
                position: static;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1>Maszyny i Auta</h1>
        </div>

        <div class="dashboard-layout">
            <!-- Sidebar -->
            <aside class="sidebar-actions">
                <a href="<?php echo url('assets.create'); ?>">Dodaj zasób</a>
                <a href="<?php echo url('assets.calendar'); ?>">Kalendarz</a>
                
                <div class="sidebar-divider"></div>
                
                <a href="<?php echo url('dashboard'); ?>">← Powrót</a>
            </aside>

            <!-- Content -->
            <div class="dashboard-content">
                <div class="card">
                    <!-- Filtry -->
                    <div class="filter-bar">
                        <form method="GET" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; width: 100%;">
                            <input type="text" 
                                   name="q" 
                                   placeholder="Szukaj (nazwa, nr rej.)" 
                                   value="<?php echo e($filterQuery); ?>">
                            
                            <select name="type">
                                <option value="">Wszystkie typy</option>
                                <?php foreach ($assetTypes as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filterType === $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <select name="status">
                                <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Aktywne</option>
                                <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Nieaktywne</option>
                                <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                            </select>
                            
                            <button type="submit" class="btn-filter btn-primary-filter">Filtruj</button>
                            
                            <?php if (!empty($filterQuery) || !empty($filterType) || $filterStatus !== 'active'): ?>
                                <a href="?" class="btn-filter btn-secondary-filter" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center;">Resetuj</a>
                            <?php endif; ?>
                            
                            <span class="filter-count">
                                Znaleziono: <?php echo count($assets); ?> zasobów
                            </span>
                        </form>
                    </div>
                    
                    <?php if (empty($assets)): ?>
                        <div class="no-data">
                            <p>Brak zasobów spełniających kryteria.</p>
                            <a href="<?php echo url('assets.create'); ?>" class="btn btn-edit" style="display: inline-block; margin-top: 15px;">Dodaj pierwszy zasób</a>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Nazwa</th>
                                    <th>Typ</th>
                                    <th>Stan licznika</th>
                                    <th>Najbliższy termin</th>
                                    <th>Alerty</th>
                                    <th style="text-align: center;">Status</th>
                                    <th style="text-align: right;">Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assets as $asset): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo url('assets.view', ['id' => $asset['id']]); ?>" 
                                               class="asset-name">
                                                <?php echo e($asset['name']); ?>
                                            </a>
                                            <div class="asset-details">
                                                <?php if ($asset['reg_number']): ?>
                                                    <?php echo e($asset['reg_number']); ?>
                                                <?php elseif ($asset['serial_number']): ?>
                                                    SN: <?php echo e($asset['serial_number']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-size: 13px; color: #6b7280;">
                                                <?php echo $assetTypes[$asset['type']] ?? $asset['type']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($asset['usage_unit'] !== 'none'): ?>
                                                <strong><?php echo number_format($asset['current_usage'], 0, ',', ' '); ?></strong>
                                                <?php echo $asset['usage_unit']; ?>
                                            <?php else: ?>
                                                <span style="color: #d1d5db;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($asset['next_due_date']): ?>
                                                <?php 
                                                $daysLeft = (int)((strtotime($asset['next_due_date']) - time()) / 86400);
                                                $isUrgent = $daysLeft <= 14;
                                                ?>
                                                <span style="color: <?php echo $isUrgent ? '#dc2626' : '#6b7280'; ?>; font-size: 13px;">
                                                    <?php echo formatDate($asset['next_due_date']); ?>
                                                    <?php if ($isUrgent): ?>
                                                        (za <?php echo $daysLeft; ?> dni)
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #d1d5db; font-size: 12px;">Brak</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($asset['alerts_count'] > 0): ?>
                                                <span class="badge badge-alert">
                                                    <?php echo $asset['alerts_count']; ?> alert<?php echo $asset['alerts_count'] > 1 ? 'y' : ''; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-success">OK</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($asset['is_active']): ?>
                                                <span class="status-dot active" title="Aktywny"></span>
                                            <?php else: ?>
                                                <span class="status-dot inactive" title="Nieaktywny"></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="actions-group">
                                                <a href="<?php echo url('assets.view', ['id' => $asset['id']]); ?>" 
                                                   class="btn btn-small btn-edit">
                                                    Szczegóły
                                                </a>
                                                <a href="<?php echo url('assets.edit', ['id' => $asset['id']]); ?>" 
                                                   class="btn btn-small btn-edit">
                                                    Edytuj
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

