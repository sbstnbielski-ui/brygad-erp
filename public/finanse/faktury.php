<?php
/**
 * BRYGAD ERP v3.0 - Faktury Zewnętrzne
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();

// Filtrowanie
$status_filter = $_GET['status'] ?? 'all';
$scope_filter = $_GET['scope'] ?? 'all';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Sortowanie
$sort = $_GET['sort'] ?? 'date';
$order = $_GET['order'] ?? 'DESC';

// Walidacja sortowania
$allowed_sorts = ['date', 'number', 'contractor', 'amount_gross', 'status'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'date';
}
$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Budowanie zapytania
$sql = "SELECT i.*,
    (SELECT COUNT(*) FROM cost_allocations WHERE invoice_id = i.id) as allocations_count,
    (SELECT COALESCE(SUM(amount), 0) FROM cost_allocations WHERE invoice_id = i.id) as allocated_amount
FROM invoices i WHERE 1=1";

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

// Mapowanie kolumn sortowania
$sortMap = [
    'date' => 'i.date',
    'number' => 'i.number',
    'contractor' => 'i.contractor',
    'amount_gross' => 'i.amount_gross',
    'status' => 'i.status'
];
$orderBy = $sortMap[$sort] ?? 'i.date';

$sql .= " ORDER BY $orderBy $order, i.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Funkcja do generowania kolorów dla wierszy (bazowana na ID)
function getRowColor($index) {
    $hue = ($index * 137.508) % 360; // Złoty kąt dla równomiernego rozkładu
    return [
        'hsl' => "hsl($hue, 70%, 95%)",
        'border' => "hsl($hue, 60%, 65%)"
    ];
}

// Statystyki liczone z tej samej listy i tych samych filtrow.
$stats = [
    'total' => count($invoices),
    'draft' => 0,
    'approved' => 0,
    'total_amount' => 0,
    'business_amount' => 0,
    'private_amount' => 0,
];
foreach ($invoices as $invoice) {
    $amount = (float)($invoice['amount_gross'] ?? 0);
    if (($invoice['status'] ?? '') === 'draft') {
        $stats['draft']++;
    }
    if (($invoice['status'] ?? '') === 'approved') {
        $stats['approved']++;
    }
    $stats['total_amount'] += $amount;
    if (($invoice['scope'] ?? '') === 'business') {
        $stats['business_amount'] += $amount;
    }
    if (($invoice['scope'] ?? '') === 'private') {
        $stats['private_amount'] += $amount;
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// Komunikaty
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Funkcja helper dla linków sortowania
function sortLink($column, $currentSort, $currentOrder, $label) {
    $newOrder = ($currentSort === $column && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    $query = http_build_query($params);
    
    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentOrder === 'ASC' ? ' ↑' : ' ↓';
    }
    
    return '<a href="?' . $query . '" style="color: inherit; text-decoration: none; display: block;">' . $label . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Faktury</title>
    <style>
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
        /* Header - z include */
        .container { max-width: 1600px; margin: 0 auto; padding: 30px; }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header h2 { font-size: 32px; color: #333; }
        .btn {
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary { background: #6c757d; color: white; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .stat-label { font-size: 13px; color: #666; margin-bottom: 8px; }
        .stat-value { font-size: 28px; font-weight: 700; color: #667eea; }

        /* Tooltip na kafelkach - pojawia sie od razu po najechaniu */
        .stat-card[data-tooltip] { position: relative; overflow: visible; }
        .stat-card[data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute; top: calc(100% + 10px); left: 50%; transform: translateX(-50%);
            background: #111827; color: #f9fafb; padding: 10px 14px; border-radius: 8px;
            font-size: 12.5px; font-weight: 400; line-height: 1.5; letter-spacing: normal; text-transform: none;
            white-space: normal; width: max-content; max-width: 280px; text-align: left;
            z-index: 1000; opacity: 0; visibility: hidden; pointer-events: none;
            box-shadow: 0 8px 20px rgba(0,0,0,0.22); transition: opacity 0.12s;
        }
        .stat-card[data-tooltip]::before {
            content: ""; position: absolute; top: calc(100% + 4px); left: 50%; transform: translateX(-50%);
            border: 6px solid transparent; border-bottom-color: #111827;
            z-index: 1001; opacity: 0; visibility: hidden; pointer-events: none; transition: opacity 0.12s;
        }
        .stat-card[data-tooltip]:hover::after, .stat-card[data-tooltip]:hover::before { opacity: 1; visibility: visible; }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }
        /* SPX FILTER SYSTEM */
        .spx-filter-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid var(--border);
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
        .spx-filter-group.fg-month   { flex: 1.2 1 0; }
        .spx-filter-group.fg-year    { flex: 0.7 1 0; }
        .spx-filter-group.fg-status  { flex: 1   1 0; }
        .spx-filter-group.fg-scope   { flex: 1.2 1 0; }
        .spx-filter-group.fg-search  { flex: 1.5 1 0; }
        .spx-filter-group.fg-date    { flex: 1   1 0; }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"],
        .spx-filter-group input[type="text"] {
            padding: 0 8px;
            height: 38px;
            border: 1px solid var(--border);
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
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        @media (max-width: 1024px) { .spx-filter-bar { flex-wrap: wrap; } .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; } }
        @media (max-width: 768px) { .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; } .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; } .spx-filter-group select, .spx-filter-group input[type="date"] { height: 44px; font-size: 14px; } }
        .spx-controls-bar {
            padding: 10px 20px;
            background: #f9fafb;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn {
            padding: 0 12px; height: 28px; background: white;
            border: 1px solid var(--border); border-radius: 5px;
            font-size: 12px; font-weight: 500; color: #374151;
            text-decoration: none; cursor: pointer; transition: all 0.15s;
            display: inline-flex; align-items: center; white-space: nowrap;
        }
        .spx-quick-btn:hover { background: #f9fafb; border-color: var(--primary); color: var(--primary); }
        .spx-quick-btn.active { background: var(--primary); border-color: var(--primary); color: white; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f9fafb; }
        th {
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        th a {
            cursor: pointer;
            user-select: none;
            transition: color 0.2s;
            color: inherit;
            text-decoration: none;
            display: block;
        }
        th a:hover {
            color: var(--primary);
        }
        td {
            padding: 10px 14px !important;
            border: 1px solid #e5e7eb !important;
            font-size: 13px !important;
            vertical-align: middle;
        }
        th {
            border: 1px solid #e5e7eb !important;
        }
        /* Tryb WYŁĄCZONY - Zebra-striping */
        body.no-colors tbody tr:nth-child(odd) {
            background: #ffffff !important;
            border-left: 4px solid transparent !important;
        }
        body.no-colors tbody tr:nth-child(even) {
            background: #f8fafc !important;
            border-left: 4px solid transparent !important;
        }
        body.no-colors tbody tr:hover {
            background: #e0f2fe !important;
        }
        
        /* Tryb WŁĄCZONY - Kolorowe wiersze */
        body:not(.no-colors) tbody tr {
            background: var(--row-bg, #ffffff) !important;
            border-left: 4px solid var(--row-border, transparent) !important;
        }
        body:not(.no-colors) tbody tr:hover {
            filter: brightness(0.95) !important;
        }
        .invoice-number { font-weight: 600; color: #333; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-draft { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-business { background: #e7f3ff; color: #004080; }
        .badge-private { background: #d1ecf1; color: #0c5460; }
        .allocation-full { color: #28a745; font-weight: 600; }
        .allocation-partial { color: #ffc107; font-weight: 600; }
        .allocation-none { color: #dc3545; font-weight: 600; }
        .action-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
        }
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            height: 26px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            transition: all 0.2s;
            border: 1px solid;
            background: white;
            white-space: nowrap;
            cursor: pointer;
        }
        .action-btn:hover {
            transform: translateY(-1px);
        }
        .action-btn-edit, .action-btn-assign {
            color: #059669;
            border-color: #059669;
        }
        .action-btn-edit:hover, .action-btn-assign:hover {
            background: #059669;
            color: white;
        }
        .action-btn-delete {
            color: #dc2626;
            border-color: #dc2626;
        }
        .action-btn-delete:hover {
            background: #dc2626;
            color: white;
        }
        .delete-form {
            display: inline;
            margin: 0;
        }
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #999;
            font-size: 16px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        /* Header actions */
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn-color-mode {
            width: 38px;
            height: 38px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            padding: 0;
        }
        .btn-color-mode:hover {
            background: #f9fafb;
            border-color: var(--primary);
        }
        .btn-color-mode.active {
            background: linear-gradient(135deg, #fce7f3, #e0e7ff);
            border-color: #a78bfa;
        }
        .btn-color-mode svg {
            width: 18px;
            height: 18px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h2>Faktury Zewnętrzne</h2>
            <div class="header-actions">
                <button type="button" class="btn-color-mode active" onclick="toggleColors()" title="Przełącz kolory">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 2a10 10 0 0 1 0 20"/>
                        <circle cx="12" cy="12" r="4"/>
                    </svg>
                </button>
                <a href="<?php echo url('finanse.faktury.create'); ?>" class="btn btn-primary">+ Dodaj Fakturę</a>
            </div>
        </div>
        
        <?php if ($success === 'deleted'): ?>
            <div class="alert alert-success">
                Faktura została pomyślnie usunięta.
            </div>
        <?php elseif ($error === 'delete_failed'): ?>
            <div class="alert alert-error">
                Wystąpił błąd podczas usuwania faktury. Sprawdź logi.
            </div>
        <?php elseif ($error === 'not_found'): ?>
            <div class="alert alert-error">
                Nie znaleziono faktury do usunięcia.
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card" data-tooltip="Ile starych faktur kosztowych widać po aktualnych filtrach.">
                <div class="stat-label">Wszystkie</div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
            </div>
            <div class="stat-card" data-tooltip="Łączna wartość faktur z listy. Starsza tabela nie trzyma rozbicia na netto — docelowo zmigrujemy.">
                <div class="stat-label">Suma</div>
                <div class="stat-value"><?php echo formatMoney($stats['total_amount']); ?></div>
            </div>
            <div class="stat-card" data-tooltip="Faktury oznaczone jako zatwierdzone.">
                <div class="stat-label">Zatwierdzone</div>
                <div class="stat-value"><?php echo $stats['approved']; ?></div>
            </div>
            <div class="stat-card" data-tooltip="Faktury w statusie szkicu, czekające na zatwierdzenie.">
                <div class="stat-label">Do zatwierdzenia</div>
                <div class="stat-value"><?php echo $stats['draft']; ?></div>
            </div>
        </div>
        
        <div class="card">
            <?php
            $fakYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            if ($fakYear < 2020 || $fakYear > 2030) $fakYear = (int)date('Y');
            $fakMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
            if ($fakMonth >= 1 && $fakMonth <= 12 && !$date_from) {
                $date_from = sprintf('%04d-%02d-01', $fakYear, $fakMonth);
                $date_to   = date('Y-m-t', strtotime($date_from));
            }
            $fakMonthNames = [1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'];
            $fakYearRange = range((int)date('Y') - 3, (int)date('Y'));
            $fakActiveMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $fakYear, $m);
                if ($date_from === $mS && $date_to === date('Y-m-t', strtotime($mS))) { $fakActiveMonth = $m; break; }
            }
            $fakToday = date('Y-m-d'); $fakWeekAgo = date('Y-m-d', strtotime('-7 days'));
            $fakMonthStart = date('Y-m-01'); $fakMonthEnd = date('Y-m-t');
            ?>
            <form method="GET" action="" class="spx-filter-bar" id="fakFilterForm">
                <div class="spx-filter-group fg-month">
                    <label>Miesiac</label>
                    <select name="month" id="fakSelectMonth" onchange="fakOnMonthYearChange()">
                        <option value="0" <?php echo $fakActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($fakMonthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($fakActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="fakSelectYear" onchange="fakOnMonthYearChange()">
                        <?php foreach ($fakYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($fakYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-status">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Szkic</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Zatwierdzone</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-scope">
                    <label>Zakres</label>
                    <select name="scope" onchange="this.form.submit()">
                        <option value="all" <?php echo $scope_filter === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="business" <?php echo $scope_filter === 'business' ? 'selected' : ''; ?>>Firmowe</option>
                        <option value="private" <?php echo $scope_filter === 'private' ? 'selected' : ''; ?>>Prywatne</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-search">
                    <label>Szukaj</label>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Numer lub kontrahent...">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="fakInputDateFrom" value="<?php echo e($date_from); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="fakInputDateTo" value="<?php echo e($date_to); ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                <?php if ($status_filter !== 'all' || $scope_filter !== 'all' || $search || $date_from || $date_to): ?>
                    <a href="<?php echo url('finanse.faktury'); ?>" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center; flex-shrink: 0; white-space: nowrap;">Wyczysc</a>
                <?php endif; ?>
                <?php if ($sort !== 'date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
            </form>
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <a href="?date_from=<?php echo $fakToday; ?>&date_to=<?php echo $fakToday; ?>" class="spx-quick-btn <?php echo ($date_from === $fakToday && $date_to === $fakToday) ? 'active' : ''; ?>">Dzis</a>
                    <a href="?date_from=<?php echo $fakWeekAgo; ?>&date_to=<?php echo $fakToday; ?>" class="spx-quick-btn <?php echo ($date_from === $fakWeekAgo && $date_to === $fakToday) ? 'active' : ''; ?>">7 dni</a>
                    <a href="?date_from=<?php echo $fakMonthStart; ?>&date_to=<?php echo $fakMonthEnd; ?>&year=<?php echo date('Y'); ?>" class="spx-quick-btn <?php echo ($date_from === $fakMonthStart && $date_to === $fakMonthEnd) ? 'active' : ''; ?>">Ten miesiac</a>
                </div>
            </div>
            
            <?php if (count($invoices) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th><?php echo sortLink('number', $sort, $order, 'Numer'); ?></th>
                            <th><?php echo sortLink('contractor', $sort, $order, 'Kontrahent'); ?></th>
                            <th><?php echo sortLink('date', $sort, $order, 'Data'); ?></th>
                            <th><?php echo sortLink('amount_gross', $sort, $order, 'Kwota'); ?></th>
                            <th>Przypisano</th>
                            <th>Typ</th>
                            <th><?php echo sortLink('status', $sort, $order, 'Status'); ?></th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowIndex = 0;
                        foreach ($invoices as $invoice): ?>
                            <?php
                            $invoiceAmount = (float)($invoice['amount_gross'] ?? 0);
                            $allocation_percent = $invoiceAmount > 0
                                ? ($invoice['allocated_amount'] / $invoiceAmount) * 100 : 0;
                            $allocation_class = $allocation_percent >= 100 ? 'allocation-full' : 
                                ($allocation_percent > 0 ? 'allocation-partial' : 'allocation-none');
                            $colors = getRowColor($rowIndex++);
                            ?>
                            <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>">
                                <td><span class="invoice-number"><?php echo e($invoice['number']); ?></span></td>
                                <td><?php echo e($invoice['contractor']); ?></td>
                                <td><?php echo formatDate($invoice['date']); ?></td>
                                <td><strong><?php echo formatMoney($invoiceAmount); ?></strong></td>
                                <td>
                                    <div class="<?php echo $allocation_class; ?>">
                                        <?php echo formatMoney($invoice['allocated_amount']); ?> (<?php echo number_format($allocation_percent, 0); ?>%)
                                    </div>
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
                                    <div class="action-buttons">
                                        <a href="<?php echo url('finanse.faktury.allocate', ['id' => $invoice['id']]); ?>" class="action-btn action-btn-assign">Przypisz</a>
                                        <?php if ($isAdminUser): ?>
                                            <a href="<?php echo url('finanse.faktury.edit', ['id' => $invoice['id']]); ?>" class="action-btn action-btn-edit">Edytuj</a>
                                            <form method="POST" action="<?php echo url('finanse.faktury.delete'); ?>" class="delete-form" 
                                                  onsubmit="return confirm('Czy na pewno chcesz usunąć fakturę <?php echo e($invoice['number']); ?>? Zostaną usunięte wszystkie przypisania do projektów!');">
                                                <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                                                <button type="submit" class="action-btn action-btn-delete">Usuń</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">Brak faktur do wyświetlenia.</div>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>

    <script>
        // Przełączanie kolorów
        function toggleColors() {
            document.body.classList.toggle('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) btn.classList.toggle('active');
            
            const isColored = !document.body.classList.contains('no-colors');
            localStorage.setItem('fakturyColorsEnabled', isColored ? '1' : '0');
            
            // Zastosuj/usuń kolory
            document.querySelectorAll('tbody tr[data-row-color]').forEach(tr => {
                if (isColored) {
                    tr.style.setProperty('--row-bg', tr.dataset.rowColor);
                    tr.style.setProperty('--row-border', tr.dataset.rowBorder);
                } else {
                    tr.style.removeProperty('--row-border');
                    tr.style.removeProperty('--row-border');
                }
            });
        }
        
        // Inicjalizacja - DOMYŚLNIE KOLORY WŁĄCZONE
        document.addEventListener('DOMContentLoaded', function() {
            const colorsEnabled = localStorage.getItem('fakturyColorsEnabled');
            if (colorsEnabled === '0') {
                document.body.classList.add('no-colors');
                const btn = document.querySelector('.btn-color-mode');
                if (btn) btn.classList.remove('active');
            } else {
                // Zastosuj kolory
                document.querySelectorAll('tbody tr[data-row-color]').forEach(tr => {
                    tr.style.setProperty('--row-bg', tr.dataset.rowColor);
                    tr.style.setProperty('--row-border', tr.dataset.rowBorder);
                });
            }
        });

        function fakOnMonthYearChange() {
            const month = parseInt(document.getElementById('fakSelectMonth').value);
            const year  = parseInt(document.getElementById('fakSelectYear').value);
            if (!month) return;
            const lastDay = new Date(year, month, 0).getDate();
            const pad = n => String(n).padStart(2, '0');
            document.getElementById('fakInputDateFrom').value = year + '-' + pad(month) + '-01';
            document.getElementById('fakInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
            document.getElementById('fakFilterForm').submit();
        }
    </script>
</body>
</html>
