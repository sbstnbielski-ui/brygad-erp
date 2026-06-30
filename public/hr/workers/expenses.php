<?php
/**
 * BRYGAD ERP - Wydatki Pracownika
 * Lista wydatków konkretnego pracownika
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

// Pobierz ID pracownika z GET
$workerId = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;

// ZABEZPIECZENIE: Worker może widzieć tylko swoje wydatki
if (!$isAdminUser) {
    if (!$currentWorkerId || $workerId != $currentWorkerId) {
        $workerId = $currentWorkerId;
    }
}

if ($workerId <= 0) {
    header('Location: ' . url('hr.workers'));
    exit;
}

// Pobierz dane pracownika
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM workers WHERE id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
    
    if (!$worker) {
        header('Location: ' . url('hr.workers'));
        exit;
    }
} catch (PDOException $e) {
    die("Błąd: " . $e->getMessage());
}

// Filtry
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterDateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$filterDateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : '';

// Pobierz wydatki pracownika
$where = ["we.worker_id = ?"];
$params = [$workerId];

if (!empty($filterStatus)) {
    $where[] = "we.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterDateFrom)) {
    $where[] = "we.date >= ?";
    $params[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $where[] = "we.date <= ?";
    $params[] = $filterDateTo;
}

try {
    $sql = "SELECT 
                we.*,
                p.name as project_name,
                pcn.name as cost_node_name
            FROM worker_expenses we
            LEFT JOIN projects p ON we.project_id = p.id
            LEFT JOIN project_cost_nodes pcn ON we.cost_node_id = pcn.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY we.date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("Błąd pobierania wydatków pracownika: " . $e->getMessage(), 'ERROR');
    $expenses = [];
}

// Statystyki
$stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'count' => count($expenses)
];

foreach ($expenses as $exp) {
    $stats['total'] += $exp['amount'];
    if ($exp['status'] === 'pending') $stats['pending'] += $exp['amount'];
    if ($exp['status'] === 'approved') $stats['approved'] += $exp['amount'];
    if ($exp['status'] === 'rejected') $stats['rejected'] += $exp['amount'];
}

$workerName = $worker['first_name'] . ' ' . $worker['last_name'];
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Wydatki: <?php echo e($workerName); ?></title>
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
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header h1 {
            font-size: 28px;
            color: #333;
        }
        .page-header p {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: white;
            color: #374151;
            border-color: #d1d5db;
        }
        .btn-secondary:hover {
            background: #f9fafb;
        }
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }
        .stat-label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        /* SPX FILTER SYSTEM */
        .spx-filter-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid var(--border, #e5e7eb);
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-wrap: nowrap;
        }
        .spx-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .spx-filter-group.fg-month    { flex: 1.2 1 0; }
        .spx-filter-group.fg-year     { flex: 0.7 1 0; }
        .spx-filter-group.fg-date     { flex: 1   1 0; }
        .spx-filter-group.fg-status   { flex: 1   1 0; }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"],
        .spx-filter-group input[type="text"] {
            padding: 0 8px;
            height: 38px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            font-family: inherit;
            transition: border-color 0.15s;
            width: 100%;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        @media (max-width: 1024px) {
            .spx-filter-bar { flex-wrap: wrap; }
            .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; }
        }
        @media (max-width: 768px) {
            .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; }
            .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; }
            .spx-filter-group select, .spx-filter-group input[type="date"] { height: 44px; font-size: 14px; }
        }
        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: white; border: 1px solid #e5e7eb; border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; white-space: nowrap; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: #667eea; color: #667eea; }
        .spx-quick-btn.active { background: #667eea; border-color: #667eea; color: white; font-weight: 600; }
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
        tbody tr {
            transition: background 0.15s ease;
        }
        tbody tr:hover {
            background: #f9fafb;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }
        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }
        .no-data {
            padding: 80px 20px;
            text-align: center;
            color: #9ca3af;
            font-size: 15px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Wydatki: <?php echo e($workerName); ?></h1>
                <p>Wydatki pracownika bez faktury</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="<?php echo url('hr.workers.profile', ['id' => $workerId]); ?>" 
                   class="btn btn-secondary">Powrót do profilu</a>
                <?php if ($isAdminUser): ?>
                    <a href="<?php echo url('hr.workers.expense_create', ['worker_id' => $workerId]); ?>" 
                       class="btn btn-primary">+ Dodaj wydatek</a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Statystyki -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($expenses); ?></div>
                <div class="stat-label">Łącznie wydatków</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #d97706;"><?php echo number_format($stats['pending'], 2, ',', ' '); ?> PLN</div>
                <div class="stat-label">Oczekujące</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #059669;"><?php echo number_format($stats['approved'], 2, ',', ' '); ?> PLN</div>
                <div class="stat-label">Zatwierdzone</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['total'], 2, ',', ' '); ?> PLN</div>
                <div class="stat-label">Suma</div>
            </div>
        </div>
        
        <!-- Lista wydatków -->
        <div class="card">
            <?php
            $expFilterYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            if ($expFilterYear < 2020 || $expFilterYear > 2030) $expFilterYear = (int)date('Y');
            $expFilterMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
            if ($expFilterMonth >= 1 && $expFilterMonth <= 12 && !isset($_GET['date_from'])) {
                $filterDateFrom = sprintf('%04d-%02d-01', $expFilterYear, $expFilterMonth);
                $filterDateTo   = date('Y-m-t', strtotime($filterDateFrom));
            }
            $expMonthNames = [1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'];
            $expYearRange = range((int)date('Y') - 3, (int)date('Y'));
            $expActiveMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $expFilterYear, $m);
                if ($filterDateFrom === $mS && $filterDateTo === date('Y-m-t', strtotime($mS))) { $expActiveMonth = $m; break; }
            }
            $expToday      = date('Y-m-d');
            $expWeekAgo    = date('Y-m-d', strtotime('-7 days'));
            $expMonthStart = date('Y-m-01');
            $expMonthEnd   = date('Y-m-t');
            $expYearStart  = date('Y-01-01');
            $expBaseUrl    = '?worker_id=' . $workerId;
            $expIsAllActive = (empty($filterDateFrom) && empty($filterDateTo) && empty($filterStatus));
            ?>
            <form method="GET" class="spx-filter-bar" id="expFilterForm">
                <input type="hidden" name="worker_id" value="<?php echo $workerId; ?>">
                <div class="spx-filter-group fg-month">
                    <label>Miesiac</label>
                    <select name="month" id="expSelectMonth" onchange="expOnMonthYearChange()">
                        <option value="0" <?php echo $expActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($expMonthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($expActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="expSelectYear" onchange="expOnMonthYearChange()">
                        <?php foreach ($expYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($expFilterYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="expInputDateFrom" value="<?php echo e($filterDateFrom); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="expInputDateTo" value="<?php echo e($filterDateTo); ?>">
                </div>
                <div class="spx-filter-group fg-status">
                    <label>Status</label>
                    <select name="status">
                        <option value="">Wszystkie</option>
                        <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Oczekujace</option>
                        <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Zatwierdzone</option>
                        <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Odrzucone</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                <a href="?worker_id=<?php echo $workerId; ?>" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center; flex-shrink: 0; white-space: nowrap;">Resetuj</a>
            </form>
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <a href="<?php echo $expBaseUrl; ?>&date_from=<?php echo $expToday; ?>&date_to=<?php echo $expToday; ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $expToday && $filterDateTo === $expToday) ? 'active' : ''; ?>">Dzis</a>
                    <a href="<?php echo $expBaseUrl; ?>&date_from=<?php echo $expWeekAgo; ?>&date_to=<?php echo $expToday; ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $expWeekAgo && $filterDateTo === $expToday) ? 'active' : ''; ?>">7 dni</a>
                    <a href="<?php echo $expBaseUrl; ?>&date_from=<?php echo $expMonthStart; ?>&date_to=<?php echo $expMonthEnd; ?>&year=<?php echo date('Y'); ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $expMonthStart && $filterDateTo === $expMonthEnd) ? 'active' : ''; ?>">Ten miesiac</a>
                    <a href="<?php echo $expBaseUrl; ?>&date_from=<?php echo $expYearStart; ?>&date_to=<?php echo $expToday; ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $expYearStart && $filterDateTo === $expToday) ? 'active' : ''; ?>">Ten rok</a>
                    <a href="<?php echo $expBaseUrl; ?>"
                       class="spx-quick-btn <?php echo $expIsAllActive ? 'active' : ''; ?>">Wszystko</a>
                </div>
            </div>
            
            <?php if (empty($expenses)): ?>
                <div class="no-data">
                    Brak wydatków dla tego pracownika.
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Projekt / Etap</th>
                            <th>Opis</th>
                            <th style="text-align: right;">Kwota</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): ?>
                            <tr>
                                <td><?php echo date('Y-m-d', strtotime($exp['date'])); ?></td>
                                <td>
                                    <?php if ($exp['project_name']): ?>
                                        <strong><?php echo e($exp['project_name']); ?></strong><br>
                                        <span style="font-size: 12px; color: #9ca3af;">
                                            <?php echo e($exp['cost_node_name'] ?? 'Brak etapu'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #d1d5db;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size: 13px;">
                                        <?php 
                                        $expDesc = trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', $exp['description'] ?: '-'));
                                        echo e($expDesc);
                                        ?>
                                        <?php if (!empty($exp['paid_by_employee']) || strpos($exp['description'], '[PAID_BY_EMPLOYEE]') !== false): ?>
                                            <span style="display: inline-block; margin-left: 6px; padding: 2px 8px; background: #17a2b8; color: white; border-radius: 10px; font-size: 10px; font-weight: 600;">PRACOWNIK</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php echo number_format($exp['amount'], 2, ',', ' '); ?> PLN
                                </td>
                                <td>
                                    <?php
                                    $statusClass = 'status-' . $exp['status'];
                                    $statusLabels = [
                                        'pending' => 'Oczekujący',
                                        'approved' => 'Zatwierdzony',
                                        'rejected' => 'Odrzucony'
                                    ];
                                    $statusLabel = $statusLabels[$exp['status']] ?? $exp['status'];
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo $statusLabel; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
    function expOnMonthYearChange() {
        const month = parseInt(document.getElementById('expSelectMonth').value);
        const year  = parseInt(document.getElementById('expSelectYear').value);
        if (!month) return;
        const lastDay = new Date(year, month, 0).getDate();
        const pad = n => String(n).padStart(2, '0');
        document.getElementById('expInputDateFrom').value = year + '-' + pad(month) + '-01';
        document.getElementById('expInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
        document.getElementById('expFilterForm').submit();
    }
    </script>
</body>
</html>

