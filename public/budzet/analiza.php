<?php
/**
 * BRYGAD ERP - Budżet Domowy - Dashboard
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_hb.php';
require_once __DIR__ . '/_module_layout.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit = HB_CAN_EDIT;

// Pobierz zakres dat z rozszerzonej filtracji
$dateRange = hb_date_range_from_request();
$filterType = $dateRange['filter_type'];
$dateFrom = $dateRange['date_from'];
$dateTo = $dateRange['date_to'];
$label = $dateRange['label'];

// Dla kompatybilności z hb_ensure_bill_items (działa tylko dla miesięcy)
if ($filterType === 'month') {
    $period = substr($dateFrom, 0, 7) . '-01';
    hb_ensure_bill_items($householdId, $period);
}

// Kafle: Przychody, Wydatki, Saldo
$stats = [];

try {
    // Przychody
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM hb_transactions
        WHERE household_id = ? 
          AND date BETWEEN ? AND ?
          AND direction = 'income'
    ");
    $stmt->execute([$householdId, $dateFrom, $dateTo]);
    $stats['income'] = $stmt->fetch()['total'];
    
    // Wydatki
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM hb_transactions
        WHERE household_id = ? 
          AND date BETWEEN ? AND ?
          AND direction = 'expense'
    ");
    $stmt->execute([$householdId, $dateFrom, $dateTo]);
    $stats['expense'] = $stmt->fetch()['total'];
    
    $stats['balance'] = $stats['income'] - $stats['expense'];
    
    // Rachunki do zapłaty (tylko dla miesiąca)
    $billsDue = [];
    if ($filterType === 'month') {
        $stmt = $pdo->prepare("
            SELECT bi.*, b.name as bill_name
            FROM hb_bill_items bi
            JOIN hb_bills b ON b.id = bi.bill_id
            WHERE bi.household_id = ? AND bi.period = ? 
              AND bi.status IN ('unpaid', 'partial')
            ORDER BY bi.due_date ASC
        ");
        $stmt->execute([$householdId, $period]);
        $billsDue = $stmt->fetchAll();
    }
    
    // Top 5 kategorii wydatków
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.name,
            COALESCE(SUM(t.amount), 0) as total
        FROM hb_categories c
        LEFT JOIN hb_transactions t ON t.category_id = c.id 
            AND t.household_id = ? 
            AND t.date BETWEEN ? AND ?
            AND t.direction = 'expense'
        WHERE c.household_id = ? AND c.type = 'expense' AND c.is_active = 1
        GROUP BY c.id, c.name
        HAVING total > 0
        ORDER BY total DESC
        LIMIT 5
    ");
    $stmt->execute([$householdId, $dateFrom, $dateTo, $householdId]);
    $topCategories = $stmt->fetchAll();
    
    // === DANE DLA WYKRESÓW ===
    
    // 1) Przychody vs Wydatki w czasie (grupowanie zależne od filtru)
    $groupBy = '';
    $dateFormat = '';
    switch ($filterType) {
        case 'day':
            $groupBy = "DATE_FORMAT(date, '%Y-%m-%d %H:00')";
            $dateFormat = '%H:00';
            break;
        case 'month':
            $groupBy = "DATE(date)";
            $dateFormat = '%d';
            break;
        case 'year':
            $groupBy = "DATE_FORMAT(date, '%Y-%m-01')";
            $dateFormat = '%b';
            break;
        case 'range':
        default:
            // Dla zakresu: grupuj po dniach jeśli < 60 dni, inaczej po miesiącach
            $daysDiff = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
            if ($daysDiff <= 60) {
                $groupBy = "DATE(date)";
                $dateFormat = '%d.%m';
            } else {
                $groupBy = "DATE_FORMAT(date, '%Y-%m-01')";
                $dateFormat = '%m/%Y';
            }
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            $groupBy as period_group,
            direction,
            SUM(amount) as total
        FROM hb_transactions
        WHERE household_id = ? 
          AND date BETWEEN ? AND ?
          AND direction IN ('income', 'expense')
        GROUP BY period_group, direction
        ORDER BY period_group ASC
    ");
    $stmt->execute([$householdId, $dateFrom, $dateTo]);
    $chartData = $stmt->fetchAll();
    
    // Przekształć dane na format dla wykresu
    $incomeByPeriod = [];
    $expenseByPeriod = [];
    $periods = [];
    
    foreach ($chartData as $row) {
        $periodKey = $row['period_group'];
        if (!in_array($periodKey, $periods)) {
            $periods[] = $periodKey;
        }
        if ($row['direction'] === 'income') {
            $incomeByPeriod[$periodKey] = $row['total'];
        } else {
            $expenseByPeriod[$periodKey] = $row['total'];
        }
    }
    
    // Uzupełnij brakujące okresy zerowymi wartościami
    foreach ($periods as $p) {
        if (!isset($incomeByPeriod[$p])) $incomeByPeriod[$p] = 0;
        if (!isset($expenseByPeriod[$p])) $expenseByPeriod[$p] = 0;
    }
    
    // 2) Bilans kumulacyjny
    $stmt = $pdo->prepare("
        SELECT 
            DATE(date) as trans_date,
            SUM(CASE WHEN direction = 'income' THEN amount ELSE -amount END) as daily_balance
        FROM hb_transactions
        WHERE household_id = ? 
          AND date BETWEEN ? AND ?
          AND direction IN ('income', 'expense')
        GROUP BY trans_date
        ORDER BY trans_date ASC
    ");
    $stmt->execute([$householdId, $dateFrom, $dateTo]);
    $balanceData = $stmt->fetchAll();
    
    $cumulativeBalance = [];
    $runningBalance = 0;
    foreach ($balanceData as $row) {
        $runningBalance += $row['daily_balance'];
        $cumulativeBalance[] = [
            'date' => $row['trans_date'],
            'balance' => $runningBalance
        ];
    }
    
    // 3) Top 10 największych pojedynczych wydatków
    $stmt = $pdo->prepare("
        SELECT 
            t.description,
            t.amount,
            t.date as transaction_date,
            c.name as category_name
        FROM hb_transactions t
        LEFT JOIN hb_categories c ON c.id = t.category_id
        WHERE t.household_id = ? 
          AND t.date BETWEEN ? AND ?
          AND t.direction = 'expense'
        ORDER BY t.amount DESC
        LIMIT 10
    ");
    $stmt->execute([$householdId, $dateFrom, $dateTo]);
    $topExpenses = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Budget dashboard error: " . $e->getMessage());
    $stats = ['income' => 0, 'expense' => 0, 'balance' => 0];
    $billsDue = [];
    $topCategories = [];
    $periods = [];
    $incomeByPeriod = [];
    $expenseByPeriod = [];
    $cumulativeBalance = [];
    $topExpenses = [];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

// Helper dla wykresów
$maxValue = 0;
if (!empty($incomeByPeriod) || !empty($expenseByPeriod)) {
    $maxValue = max(array_merge(array_values($incomeByPeriod), array_values($expenseByPeriod)));
}
$chartHeight = 200;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Budżet Domowy - Analiza</title>
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
            flex-wrap: wrap;
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
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .btn-logout:hover, .btn-back:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
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
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        .page-header h2 {
            font-size: 28px;
            margin-bottom: 8px;
        }
        .page-header .subtitle {
            font-size: 14px;
            color: #94a3b8;
        }
        
        /* Rozszerzony filtr */
        .filter-box {
            margin-bottom: 30px;
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .filter-box h3 {
            font-size: 16px;
            margin-bottom: 15px;
            color: #333;
        }
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #f3f4f6;
            padding-bottom: 10px;
        }
        .filter-tab {
            padding: 8px 16px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px 6px 0 0;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #666;
            transition: all 0.2s;
        }
        .filter-tab.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .filter-content {
            display: none;
        }
        .filter-content.active {
            display: block;
        }
        .filter-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: #666;
        }
        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .filter-btn {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
        }
        .filter-btn:hover {
            background: #5568d3;
        }
        .current-filter {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 14px;
            color: #166534;
        }
        .current-filter strong {
            font-weight: 600;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #333;
        }
        .stat-value.positive { color: #16a34a; }
        .stat-value.negative { color: #dc2626; }
        .stat-value.neutral { color: #667eea; }
        .section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        .section h3 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #333;
            border-bottom: 2px solid #f3f4f6;
            padding-bottom: 10px;
        }
        
        /* Wykresy */
        .chart-container {
            margin-top: 20px;
        }
        .chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 10px;
            height: 200px;
            padding: 10px 0;
        }
        .chart-bar-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }
        .chart-bars-inner {
            display: flex;
            gap: 3px;
            align-items: flex-end;
            height: 100%;
        }
        .chart-bar {
            width: 20px;
            background: #667eea;
            border-radius: 4px 4px 0 0;
            position: relative;
            transition: all 0.2s;
        }
        .chart-bar.income {
            background: #16a34a;
        }
        .chart-bar.expense {
            background: #dc2626;
        }
        .chart-bar:hover {
            opacity: 0.8;
        }
        .chart-label {
            font-size: 11px;
            color: #666;
            text-align: center;
        }
        .chart-legend {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #f3f4f6;
        }
        .chart-legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #666;
        }
        .chart-legend-color {
            width: 16px;
            height: 16px;
            border-radius: 3px;
        }
        .chart-legend-color.income {
            background: #16a34a;
        }
        .chart-legend-color.expense {
            background: #dc2626;
        }
        
        /* SVG Wykres liniowy */
        .svg-chart {
            width: 100%;
            height: 220px;
            margin-top: 20px;
        }
        .svg-chart-line {
            fill: none;
            stroke: #667eea;
            stroke-width: 2;
        }
        .svg-chart-area {
            fill: rgba(102, 126, 234, 0.1);
        }
        .svg-chart-grid {
            stroke: #e5e7eb;
            stroke-width: 1;
        }
        .svg-chart-text {
            font-size: 11px;
            fill: #666;
        }
        
        /* Wykres poziomy (Top wydatki) */
        .horizontal-bar-chart {
            margin-top: 20px;
        }
        .h-bar-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .h-bar-label {
            flex: 0 0 180px;
            font-size: 13px;
            color: #333;
            font-weight: 500;
            text-align: right;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .h-bar-container {
            flex: 1;
            background: #f3f4f6;
            height: 24px;
            border-radius: 4px;
            position: relative;
            overflow: hidden;
        }
        .h-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #dc2626 0%, #f87171 100%);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        .h-bar-value {
            flex: 0 0 100px;
            font-size: 13px;
            color: #666;
            font-weight: 600;
            text-align: left;
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
        .nav-links {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
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
    </style>
</head>
<body>
    <?php hb_module_shell_start([
        'active' => 'analiza',
        'title' => 'Analiza Budżetu Domowego',
        'subtitle' => 'Podsumowanie i wykresy finansowe',
        'user_name' => $userName,
        'period_month' => date('Y-m'),
    ]); ?>
        
        <!-- ROZSZERZONY FILTR -->
        <div class="filter-box">
            <h3>Filtrowanie danych</h3>
            <div class="filter-tabs">
                <div class="filter-tab <?php echo $filterType === 'day' ? 'active' : ''; ?>" onclick="switchFilterTab('day')">Dzień</div>
                <div class="filter-tab <?php echo $filterType === 'month' ? 'active' : ''; ?>" onclick="switchFilterTab('month')">Miesiąc</div>
                <div class="filter-tab <?php echo $filterType === 'year' ? 'active' : ''; ?>" onclick="switchFilterTab('year')">Rok</div>
                <div class="filter-tab <?php echo $filterType === 'range' ? 'active' : ''; ?>" onclick="switchFilterTab('range')">Zakres</div>
            </div>
            
            <!-- Dzień -->
            <div id="filter-day" class="filter-content <?php echo $filterType === 'day' ? 'active' : ''; ?>">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="filter_type" value="day">
                    <div class="filter-group">
                        <label>Wybierz dzień:</label>
                        <input type="date" name="day" value="<?php echo $filterType === 'day' ? $dateFrom : date('Y-m-d'); ?>" required>
                    </div>
                    <button type="submit" class="filter-btn">Pokaż</button>
                </form>
            </div>
            
            <!-- Miesiąc -->
            <div id="filter-month" class="filter-content <?php echo $filterType === 'month' ? 'active' : ''; ?>">
                <form method="GET" class="filter-form" onsubmit="buildMonthParam(this)">
                    <input type="hidden" name="filter_type" value="month">
                    <input type="hidden" name="month" id="month-hidden" value="<?php echo $filterType === 'month' ? substr($dateFrom, 0, 7) : date('Y-m'); ?>">
                    <?php
                        $selYear  = $filterType === 'month' ? (int)substr($dateFrom, 0, 4) : (int)date('Y');
                        $selMonth = $filterType === 'month' ? (int)substr($dateFrom, 5, 2) : (int)date('m');
                        $plMonthNames = [
                            1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',
                            5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpień',
                            9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'
                        ];
                    ?>
                    <div class="filter-group">
                        <label>Miesiąc:</label>
                        <select id="sel-month" style="min-width:140px">
                            <?php foreach ($plMonthNames as $n => $name): ?>
                            <option value="<?php echo $n; ?>" <?php echo $n === $selMonth ? 'selected' : ''; ?>>
                                <?php echo $name; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Rok:</label>
                        <select id="sel-year" style="min-width:90px">
                            <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $selYear ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <button type="submit" class="filter-btn">Pokaż</button>
                </form>
            </div>
            
            <!-- Rok -->
            <div id="filter-year" class="filter-content <?php echo $filterType === 'year' ? 'active' : ''; ?>">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="filter_type" value="year">
                    <div class="filter-group">
                        <label>Wybierz rok:</label>
                        <select name="year" required>
                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($filterType === 'year' && substr($dateFrom, 0, 4) == $y) ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <button type="submit" class="filter-btn">Pokaż</button>
                </form>
            </div>
            
            <!-- Zakres -->
            <div id="filter-range" class="filter-content <?php echo $filterType === 'range' ? 'active' : ''; ?>">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="filter_type" value="range">
                    <div class="filter-group">
                        <label>Od:</label>
                        <input type="date" name="date_from" value="<?php echo $filterType === 'range' ? $dateFrom : date('Y-m-01'); ?>" required>
                    </div>
                    <div class="filter-group">
                        <label>Do:</label>
                        <input type="date" name="date_to" value="<?php echo $filterType === 'range' ? $dateTo : date('Y-m-d'); ?>" required>
                    </div>
                    <button type="submit" class="filter-btn">Pokaż</button>
                </form>
            </div>
            
            <div class="current-filter">
                <strong>Aktualny okres:</strong> <?php echo e($label); ?>
            </div>
        </div>
        
        <!-- KAFELKI STATYSTYK -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Przychody</h3>
                <div class="stat-value positive"><?php echo hb_format_money($stats['income']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Wydatki</h3>
                <div class="stat-value negative"><?php echo hb_format_money($stats['expense']); ?></div>
            </div>
            <div class="stat-card">
                <h3>Saldo</h3>
                <div class="stat-value <?php echo $stats['balance'] >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo hb_format_money($stats['balance']); ?>
                </div>
            </div>
        </div>
        
        <!-- WYKRES: Przychody vs Wydatki -->
        <?php if (!empty($periods)): ?>
        <div class="section">
            <h3>Przychody vs Wydatki w czasie</h3>
            <div class="chart-container">
                <div class="chart-bars">
                    <?php foreach ($periods as $p): ?>
                    <?php
                        $income = $incomeByPeriod[$p] ?? 0;
                        $expense = $expenseByPeriod[$p] ?? 0;
                        $incomeHeight = $maxValue > 0 ? ($income / $maxValue) * 180 : 0;
                        $expenseHeight = $maxValue > 0 ? ($expense / $maxValue) * 180 : 0;
                        
                        // Format label
                        if ($filterType === 'day') {
                            $labelText = date('H:i', strtotime($p));
                        } elseif ($filterType === 'month') {
                            $labelText = date('d', strtotime($p));
                        } elseif ($filterType === 'year') {
                            $plMonths = ['01'=>'Sty','02'=>'Lut','03'=>'Mar','04'=>'Kwi',
                                         '05'=>'Maj','06'=>'Cze','07'=>'Lip','08'=>'Sie',
                                         '09'=>'Wrz','10'=>'Paź','11'=>'Lis','12'=>'Gru'];
                            $labelText = $plMonths[date('m', strtotime($p))] ?? date('m', strtotime($p));
                        } else {
                            $daysDiff = (strtotime($dateTo) - strtotime($dateFrom)) / 86400;
                            $labelText = $daysDiff <= 60 ? date('d.m', strtotime($p)) : date('m/Y', strtotime($p));
                        }
                    ?>
                    <div class="chart-bar-group">
                        <div class="chart-bars-inner">
                            <div class="chart-bar income" style="height: <?php echo $incomeHeight; ?>px;" title="Przychody: <?php echo hb_format_money($income); ?>"></div>
                            <div class="chart-bar expense" style="height: <?php echo $expenseHeight; ?>px;" title="Wydatki: <?php echo hb_format_money($expense); ?>"></div>
                        </div>
                        <div class="chart-label"><?php echo e($labelText); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="chart-legend">
                    <div class="chart-legend-item">
                        <div class="chart-legend-color income"></div>
                        <span>Przychody</span>
                    </div>
                    <div class="chart-legend-item">
                        <div class="chart-legend-color expense"></div>
                        <span>Wydatki</span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- WYKRES: Bilans kumulacyjny (SVG) -->
        <?php if (!empty($cumulativeBalance)): ?>
        <div class="section">
            <h3>Bilans kumulacyjny</h3>
            <div class="chart-container">
                <?php
                    $svgWidth = 1000;
                    $svgHeight = 200;
                    $padding = 40;
                    $chartWidth = $svgWidth - 2 * $padding;
                    $chartHeight = $svgHeight - 2 * $padding;
                    
                    $minBalance = min(array_column($cumulativeBalance, 'balance'));
                    $maxBalance = max(array_column($cumulativeBalance, 'balance'));
                    $balanceRange = $maxBalance - $minBalance;
                    if ($balanceRange == 0) $balanceRange = 1;
                    
                    $points = [];
                    $areaPoints = [];
                    $count = count($cumulativeBalance);
                    
                    foreach ($cumulativeBalance as $i => $data) {
                        $x = $padding + ($i / max(1, $count - 1)) * $chartWidth;
                        $y = $padding + $chartHeight - (($data['balance'] - $minBalance) / $balanceRange) * $chartHeight;
                        $points[] = "$x,$y";
                    }
                    
                    $areaPoints = implode(' ', $points);
                    $areaPoints .= " " . ($svgWidth - $padding) . "," . ($svgHeight - $padding);
                    $areaPoints .= " $padding," . ($svgHeight - $padding);
                    
                    $linePoints = implode(' ', $points);
                ?>
                <svg class="svg-chart" viewBox="0 0 <?php echo $svgWidth; ?> <?php echo $svgHeight; ?>">
                    <!-- Grid -->
                    <?php for ($i = 0; $i <= 4; $i++): ?>
                        <line class="svg-chart-grid" 
                              x1="<?php echo $padding; ?>" 
                              y1="<?php echo $padding + ($chartHeight / 4) * $i; ?>" 
                              x2="<?php echo $svgWidth - $padding; ?>" 
                              y2="<?php echo $padding + ($chartHeight / 4) * $i; ?>" />
                    <?php endfor; ?>
                    
                    <!-- Area -->
                    <polygon class="svg-chart-area" points="<?php echo $areaPoints; ?>" />
                    
                    <!-- Line -->
                    <polyline class="svg-chart-line" points="<?php echo $linePoints; ?>" />
                    
                    <!-- Labels -->
                    <text class="svg-chart-text" x="<?php echo $padding - 5; ?>" y="<?php echo $padding; ?>" text-anchor="end">
                        <?php echo number_format($maxBalance, 0, ',', ' '); ?>
                    </text>
                    <text class="svg-chart-text" x="<?php echo $padding - 5; ?>" y="<?php echo $svgHeight - $padding; ?>" text-anchor="end">
                        <?php echo number_format($minBalance, 0, ',', ' '); ?>
                    </text>
                </svg>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- WYKRES: Top 10 wydatków -->
        <?php if (!empty($topExpenses)): ?>
        <div class="section">
            <h3>Top 10 największych wydatków</h3>
            <div class="horizontal-bar-chart">
                <?php 
                    $maxExpense = $topExpenses[0]['amount'];
                    foreach ($topExpenses as $exp): 
                        $percentage = ($exp['amount'] / $maxExpense) * 100;
                        $label = $exp['description'] ?: ($exp['category_name'] ?: 'Bez opisu');
                ?>
                <div class="h-bar-item">
                    <div class="h-bar-label" title="<?php echo e($label); ?>">
                        <?php echo e($label); ?>
                    </div>
                    <div class="h-bar-container">
                        <div class="h-bar-fill" style="width: <?php echo $percentage; ?>%;"></div>
                    </div>
                    <div class="h-bar-value"><?php echo hb_format_money($exp['amount']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Rachunki do zapłaty (tylko dla miesiąca) -->
        <?php if ($filterType === 'month' && !empty($billsDue)): ?>
        <div class="section">
            <h3>Do zapłaty w tym miesiącu</h3>
            <table>
                <thead>
                    <tr>
                        <th>Rachunek</th>
                        <th>Termin</th>
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
                    <?php foreach ($billsDue as $bill): ?>
                    <?php
                        $remaining = $bill['amount_due'] - $bill['amount_paid'];
                    ?>
                    <tr>
                        <td><?php echo e($bill['bill_name']); ?></td>
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
        </div>
        <?php endif; ?>
        
        <!-- Top 5 kategorii wydatków -->
        <div class="section">
            <h3>Top 5 kategorii wydatków</h3>
            <?php if (empty($topCategories)): ?>
                <div class="empty-state">Brak wydatków w tym okresie</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Kategoria</th>
                            <th>Suma wydatków</th>
                            <th>% całkowitych wydatków</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCategories as $cat): ?>
                        <?php
                            $percentage = $stats['expense'] > 0 ? ($cat['total'] / $stats['expense']) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo e($cat['name']); ?></td>
                            <td><strong><?php echo hb_format_money($cat['total']); ?></strong></td>
                            <td><?php echo number_format($percentage, 1); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php hb_module_shell_end(); ?>
    
    <script>
        function switchFilterTab(type) {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.filter-content').forEach(content => content.classList.remove('active'));
            document.querySelector(`.filter-tab:nth-child(${['day', 'month', 'year', 'range'].indexOf(type) + 1})`).classList.add('active');
            document.getElementById('filter-' + type).classList.add('active');
        }

        // Buduj wartość "YYYY-MM" z dwóch selectów przed submit
        function buildMonthParam(form) {
            const m = String(document.getElementById('sel-month').value).padStart(2, '0');
            const y = document.getElementById('sel-year').value;
            document.getElementById('month-hidden').value = y + '-' + m;
        }
    </script>
</body>
</html>
