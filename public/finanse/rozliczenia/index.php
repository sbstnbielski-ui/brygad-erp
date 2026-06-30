<?php
/**
 * BRYGAD ERP v3.0 - Rozliczenia Pracowników
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();

$pdo = getDbConnection();

// Filtry
$filterWorker = isset($_GET['worker']) ? (int)$_GET['worker'] : 0;
$filterType   = isset($_GET['type']) ? $_GET['type'] : '';

// Zakres dat (zamiast period - bo period w bazie może być 0000-00-00)
$defaultDateFrom = date('Y-m-01');
$defaultDateTo   = date('Y-m-t');
$filterDateFrom  = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo    = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : '';

// Widok: grupowanie po dniach (domyślnie) lub zwykła tabela
$viewMode = (isset($_GET['view']) && $_GET['view'] === 'table') ? 'table' : 'days';

// Pobierz pracowników
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
    $workers = $stmt->fetchAll();
} catch (PDOException $e) {
    $workers = [];
}

// Buduj query
$where = ["1=1"];
$params = [];

$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

// ZABEZPIECZENIE: Worker widzi tylko swoje rozliczenia
if (!$isAdminUser && $currentWorkerId) {
    $where[] = "s.worker_id = ?";
    $params[] = $currentWorkerId;
}

if ($filterWorker > 0) {
    $where[] = "s.worker_id = ?";
    $params[] = $filterWorker;
}

if (!empty($filterType)) {
    $where[] = "s.type = ?";
    $params[] = $filterType;
}

if (!empty($filterDateFrom)) {
    $where[] = "s.date >= ?";
    $params[] = $filterDateFrom;
}
if (!empty($filterDateTo)) {
    $where[] = "s.date <= ?";
    $params[] = $filterDateTo;
}

// Pobierz rozliczenia
try {
    $sql = "SELECT
                s.*,
                w.first_name,
                w.last_name,
                creator.login as created_by_login
            FROM settlements s
            INNER JOIN workers w ON s.worker_id = w.id
            LEFT JOIN users creator ON s.created_by_user_id = creator.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.date DESC, w.last_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $settlements = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("Błąd pobierania rozliczeń: " . $e->getMessage(), 'ERROR');
    $settlements = [];
}

// Statystyki
$stats = [
    'total_paid' => 0,
    'total_advances' => 0,
    'total_reimbursements' => 0,
    'count' => count($settlements)
];

foreach ($settlements as $s) {
    if ($s['type'] === 'payout') {
        $stats['total_paid'] += $s['amount'];
    } elseif ($s['type'] === 'advance') {
        $stats['total_advances'] += $s['amount'];
    } elseif ($s['type'] === 'reimbursement') {
        $stats['total_reimbursements'] += $s['amount'];
    }
}

// Grupowanie po dacie (jak w worklog)
$settlementsByDate = [];
foreach ($settlements as $settlement) {
    $dateKey = $settlement['date'];
    if (!isset($settlementsByDate[$dateKey])) {
        $settlementsByDate[$dateKey] = [
            'items' => [],
            'count' => 0,
            'total_amount' => 0
        ];
    }

    $settlementsByDate[$dateKey]['items'][] = $settlement;
    $settlementsByDate[$dateKey]['count']++;
    $settlementsByDate[$dateKey]['total_amount'] += (float)$settlement['amount'];
}

$daysViewQuery = $_GET;
$daysViewQuery['view'] = 'days';
$daysViewUrl = '?' . http_build_query($daysViewQuery);

$tableViewQuery = $_GET;
$tableViewQuery['view'] = 'table';
$tableViewUrl = '?' . http_build_query($tableViewQuery);

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// ========================================
// FUNKCJA: Generowanie koloru pracownika
// ========================================
function getWorkerColor($workerId)
{
    $goldenRatio = 0.618033988749895;
    $hue = fmod($workerId * $goldenRatio, 1.0) * 360;
    $saturation = 65;
    $lightness = 55;
    return [
        'hslLight' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.08)",
        'hslBorder' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.6)"
    ];
}

function getPolishDayName($date)
{
    $days = ['Nd', 'Pn', 'Wt', 'Sr', 'Cz', 'Pt', 'So'];
    $dayNum = date('w', strtotime($date));
    return $days[$dayNum] ?? '';
}

function getSettlementTypeName($type)
{
    $typeNames = [
        'payout' => 'Wypłata',
        'advance' => 'Zaliczka',
        'reimbursement' => 'Zwrot kosztów',
        'bonus' => 'Premia',
        'correction' => 'Korekta'
    ];

    return $typeNames[$type] ?? $type;
}

function getSettlementPeriodLabel(array $settlement)
{
    $periodVal = $settlement['period'] ?? '';
    if (!empty($periodVal) && $periodVal !== '0000-00-00') {
        return date('m/Y', strtotime($periodVal));
    }

    return date('m/Y', strtotime($settlement['date']));
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Rozliczenia</title>
    <style>
        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --bg-card:           #ffffff;
            --border:            #e5e7eb;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
            --success:           #22c55e;
            --danger:            #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px;
        }
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }

        /* Override header */
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }

        /* Hero */
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }

        .btn {
            padding: 10px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 13px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.35);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            padding: 16px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            margin-bottom: 24px;
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
        .spx-filter-group.fg-date    { flex: 1   1 0; }
        .spx-filter-group.fg-worker  { flex: 1.5 1 0; }
        .spx-filter-group.fg-type    { flex: 1.2 1 0; }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"] {
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
        .spx-filter-group input[type="date"]:focus {
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
            gap: 10px;
            flex-wrap: wrap;
        }
        .spx-controls-left,
        .spx-controls-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .spx-quick-btn {
            padding: 0 12px;
            height: 28px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
            color: #374151;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }
        .spx-quick-btn:hover {
            background: #f9fafb;
            border-color: var(--primary);
            color: var(--primary);
        }
        .spx-quick-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            font-weight: 600;
        }
        .spx-toggle-group {
            display: flex;
            gap: 2px;
            background: #e5e7eb;
            padding: 2px;
            border-radius: 6px;
        }
        .spx-btn-toggle {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .spx-btn-toggle:hover { background: white; color: var(--text-main); }

        .btn-toggle:hover {
            background: white;
            color: var(--text-main);
        }

        .btn-color-mode {
            width: 34px;
            height: 34px;
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

        .day-group {
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px;
            margin: 12px 16px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
        }

        .day-group.collapsed {
            margin-bottom: 8px;
            box-shadow: none;
        }

        .day-group.collapsed .day-content {
            display: none;
        }

        .day-header {
            background: #f8fafc;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
        }

        .day-header:hover {
            background: #f1f5f9;
        }

        .day-group.collapsed .day-header {
            border-bottom: none;
        }

        .dh-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dh-dayname {
            color: var(--text-muted);
            text-transform: uppercase;
            font-size: 11px;
            font-weight: 700;
            background: #e2e8f0;
            padding: 3px 8px;
            border-radius: 4px;
            min-width: 28px;
            text-align: center;
        }

        .dh-date {
            font-weight: 700;
            font-size: 15px;
            color: #334155;
        }

        .dh-right {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .dh-stats {
            display: flex;
            gap: 14px;
        }

        .dh-stat {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .dh-stat-value {
            font-weight: 600;
            color: var(--text-main);
        }

        .dh-arrow {
            font-size: 10px;
            color: var(--text-muted);
            transition: transform 0.2s;
            width: 16px;
            text-align: center;
        }

        .day-group.collapsed .dh-arrow {
            transform: rotate(-90deg);
        }

        .day-content {
            padding: 0;
        }

        .erp-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .erp-table thead {
            background: #f9fafb;
        }

        .erp-table th,
        .erp-table td {
            border: 1px solid #000000;
            padding: 9px 10px;
            font-size: 13px;
            vertical-align: middle;
        }

        .erp-table th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .erp-table .col-date { width: 110px; }
        .erp-table .col-worker { width: 180px; }
        .erp-table .col-type { width: 190px; }
        .erp-table .col-amount { width: 140px; text-align: right; font-variant-numeric: tabular-nums; }
        .erp-table .col-period { width: 90px; text-align: center; }
        .erp-table .col-created { width: 130px; }
        .erp-table .col-actions { width: 84px; text-align: center; }

        .erp-table td.col-date,
        .erp-table td.col-worker,
        .erp-table td.col-amount {
            font-weight: 600;
        }

        .erp-table td.col-description {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .erp-table td.col-created {
            color: #6b7280;
            font-size: 12px;
        }

        /* Kolory pracowników - tryb włączony */
        body:not(.no-colors) tbody tr {
            background: var(--worker-bg, transparent);
            border-left: 4px solid var(--worker-color, #667eea);
        }

        body:not(.no-colors) tbody tr:hover {
            filter: brightness(0.97);
        }

        /* Tryb wyłączony - zebra striping */
        body.no-colors tbody tr:nth-child(odd) {
            background: #ffffff !important;
            border-left: 4px solid transparent;
        }

        body.no-colors tbody tr:nth-child(even) {
            background: #f8fafc !important;
            border-left: 4px solid transparent;
        }

        body.no-colors tbody tr:hover {
            background: #e0f2fe !important;
        }

        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .type-payout {
            background: #d4edda;
            color: #155724;
        }

        .type-advance {
            background: #fff3cd;
            color: #856404;
        }

        .type-reimbursement {
            background: #d1ecf1;
            color: #0c5460;
        }

        .type-bonus {
            background: #e7f3ff;
            color: #004080;
        }

        .type-correction {
            background: #f8d7da;
            color: #721c24;
        }

        .type-extra {
            display: inline-block;
            margin-left: 6px;
            color: #6b7280;
            font-size: 12px;
            white-space: nowrap;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
            align-items: center;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 4px 9px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            border: 1.5px solid;
            background: white;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        }

        .action-btn-edit {
            color: #059669;
            border-color: #059669;
        }

        .action-btn-edit:hover {
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

        .success-message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        @media (max-width: 1100px) {
            .filter-bar {
                align-items: stretch;
            }

            .filter-group,
            .filter-group select,
            .filter-group input[type="date"] {
                width: 100%;
                min-width: 0;
            }

            .controls-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .controls-left,
            .controls-right {
                justify-content: space-between;
            }

            .erp-table th,
            .erp-table td {
                font-size: 12px;
                padding: 8px;
            }

            .erp-table .col-created,
            .erp-table .col-period {
                display: none;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    Rozliczenia
                </div>
                <h1>Rozliczenia Pracowników</h1>
                <p>Historia operacji finansowych i rozliczeń</p>
            </div>
            <?php if ($isAdminUser): ?>
                <div class="hero-actions">
                    <a href="<?php echo url('finanse.rozliczenia.create'); ?>" class="btn-hero-primary">+ Dodaj Rozliczenie</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <?php if ($_GET['success'] === 'edit'): ?>
                <div class="success-message">✓ Rozliczenie zostało zaktualizowane pomyślnie.</div>
            <?php elseif ($_GET['success'] === 'delete'): ?>
                <div class="success-message">✓ Rozliczenie zostało usunięte pomyślnie.</div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Wypłaty</div>
                <div class="stat-value"><?php echo formatMoney($stats['total_paid']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Zaliczki</div>
                <div class="stat-value"><?php echo formatMoney($stats['total_advances']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Zwroty Kosztów</div>
                <div class="stat-value"><?php echo formatMoney($stats['total_reimbursements']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Operacji</div>
                <div class="stat-value"><?php echo $stats['count']; ?></div>
            </div>
        </div>

        <div class="card">
            <?php
            $rozYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            if ($rozYear < 2020 || $rozYear > 2030) $rozYear = (int)date('Y');
            $rozMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
            if ($rozMonth >= 1 && $rozMonth <= 12 && !isset($_GET['date_from'])) {
                $filterDateFrom = sprintf('%04d-%02d-01', $rozYear, $rozMonth);
                $filterDateTo   = date('Y-m-t', strtotime($filterDateFrom));
            }
            $rozMonthNames = [1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'];
            $rozYearRange = range((int)date('Y') - 3, (int)date('Y'));
            $rozActiveMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $rozYear, $m);
                if ($filterDateFrom === $mS && $filterDateTo === date('Y-m-t', strtotime($mS))) { $rozActiveMonth = $m; break; }
            }
            $rozToday = date('Y-m-d');
            $rozWeekAgo = date('Y-m-d', strtotime('-7 days'));
            $rozMonthStart = date('Y-m-01');
            $rozMonthEnd = date('Y-m-t');
            ?>
            <form method="GET" action="" class="spx-filter-bar" id="rozFilterForm">
                <input type="hidden" name="view" value="<?php echo e($viewMode); ?>">
                <div class="spx-filter-group fg-month">
                    <label>Miesiac</label>
                    <select name="month" id="rozSelectMonth" onchange="rozOnMonthYearChange()">
                        <option value="0" <?php echo $rozActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($rozMonthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($rozActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="rozSelectYear" onchange="rozOnMonthYearChange()">
                        <?php foreach ($rozYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($rozYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="rozInputDateFrom" value="<?php echo e($filterDateFrom); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="rozInputDateTo" value="<?php echo e($filterDateTo); ?>">
                </div>
                <div class="spx-filter-group fg-worker">
                    <label>Pracownik</label>
                    <select name="worker">
                        <option value="0">Wszyscy</option>
                        <?php foreach ($workers as $w): ?>
                            <option value="<?php echo $w['id']; ?>" <?php echo ($filterWorker == $w['id']) ? 'selected' : ''; ?>>
                                <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-type">
                    <label>Typ</label>
                    <select name="type">
                        <option value="">Wszystkie typy</option>
                        <option value="payout" <?php echo ($filterType === 'payout') ? 'selected' : ''; ?>>Wyplata</option>
                        <option value="advance" <?php echo ($filterType === 'advance') ? 'selected' : ''; ?>>Zaliczka</option>
                        <option value="reimbursement" <?php echo ($filterType === 'reimbursement') ? 'selected' : ''; ?>>Zwrot kosztow</option>
                        <option value="bonus" <?php echo ($filterType === 'bonus') ? 'selected' : ''; ?>>Premia</option>
                        <option value="correction" <?php echo ($filterType === 'correction') ? 'selected' : ''; ?>>Korekta</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                <a href="<?php echo url('finanse.rozliczenia'); ?>" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center; flex-shrink: 0; white-space: nowrap;">Wyczysc</a>
            </form>
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <a href="?date_from=<?php echo $rozToday; ?>&date_to=<?php echo $rozToday; ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $rozToday && $filterDateTo === $rozToday) ? 'active' : ''; ?>">Dzis</a>
                    <a href="?date_from=<?php echo $rozWeekAgo; ?>&date_to=<?php echo $rozToday; ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $rozWeekAgo && $filterDateTo === $rozToday) ? 'active' : ''; ?>">7 dni</a>
                    <a href="?date_from=<?php echo $rozMonthStart; ?>&date_to=<?php echo $rozMonthEnd; ?>&year=<?php echo date('Y'); ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $rozMonthStart && $filterDateTo === $rozMonthEnd) ? 'active' : ''; ?>">Ten miesiac</a>
                </div>
                <?php if (!empty($settlements)): ?>
                <div class="spx-controls-right">
                    <a href="<?php echo e($daysViewUrl); ?>" class="spx-quick-btn <?php echo $viewMode === 'days' ? 'active' : ''; ?>">Widok dni</a>
                    <a href="<?php echo e($tableViewUrl); ?>" class="spx-quick-btn <?php echo $viewMode === 'table' ? 'active' : ''; ?>">Tabela</a>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($settlements) && ($isAdminUser || $viewMode === 'days')): ?>
                <div class="spx-controls-bar" style="border-top: none;">
                    <div class="spx-controls-left"></div>
                    <div class="spx-controls-right">
                        <?php if ($isAdminUser): ?>
                            <button type="button" class="btn-color-mode active" onclick="toggleColors()" title="Kolory pracownikow">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 2a10 10 0 0 1 0 20"/>
                                    <circle cx="12" cy="12" r="4"/>
                                </svg>
                            </button>
                        <?php endif; ?>
                        <?php if ($viewMode === 'days'): ?>
                            <div style="width: 1px; height: 22px; background: var(--border);"></div>
                            <div class="spx-toggle-group">
                                <button type="button" class="spx-btn-toggle" onclick="expandAll()">Rozwin</button>
                                <button type="button" class="spx-btn-toggle" onclick="collapseAll()">Zwin</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($settlements)): ?>
                <div class="no-data">
                    Brak rozliczeń dla wybranych filtrów.
                </div>
            <?php elseif ($viewMode === 'days'): ?>
                <?php foreach ($settlementsByDate as $date => $dayData): ?>
                    <?php
                        $dayName = getPolishDayName($date);
                        $dayCount = $dayData['count'];
                        $dayTotal = $dayData['total_amount'];
                    ?>
                    <div class="day-group" data-date="<?php echo e($date); ?>">
                        <div class="day-header" onclick="toggleDay(this)">
                            <div class="dh-left">
                                <span class="dh-dayname"><?php echo e($dayName); ?></span>
                                <span class="dh-date"><?php echo formatDate($date); ?></span>
                            </div>
                            <div class="dh-right">
                                <div class="dh-stats">
                                    <div class="dh-stat">
                                        <span class="dh-stat-value"><?php echo $dayCount; ?></span>
                                        <span>pozycje</span>
                                    </div>
                                    <div class="dh-stat">
                                        <span class="dh-stat-value" style="color: var(--primary);"><?php echo formatMoney($dayTotal); ?></span>
                                    </div>
                                </div>
                                <span class="dh-arrow">&#9660;</span>
                            </div>
                        </div>
                        <div class="day-content">
                            <table class="erp-table settlement-table">
                                <thead>
                                    <tr>
                                        <th class="col-date">Data</th>
                                        <th class="col-worker">Pracownik</th>
                                        <th class="col-type">Typ</th>
                                        <th class="col-amount">Kwota</th>
                                        <th class="col-description">Opis</th>
                                        <th class="col-period">Okres</th>
                                        <th class="col-created">Utworzył</th>
                                        <?php if ($isAdminUser): ?>
                                            <th class="col-actions">Akcje</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dayData['items'] as $s): ?>
                                        <?php
                                            $workerColor = getWorkerColor($s['worker_id']);
                                            $rowStyle = "--worker-color: {$workerColor['hslBorder']}; --worker-bg: {$workerColor['hslLight']};";
                                            $typeClass = 'type-' . $s['type'];
                                        ?>
                                        <tr style="<?php echo $rowStyle; ?>" data-worker-id="<?php echo $s['worker_id']; ?>">
                                            <td class="col-date"><?php echo formatDate($s['date']); ?></td>
                                            <td class="col-worker"><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></td>
                                            <td class="col-type">
                                                <span class="type-badge <?php echo e($typeClass); ?>">
                                                    <?php echo e(getSettlementTypeName($s['type'])); ?>
                                                </span>
                                                <?php if (!empty($s['advance_kind'])): ?>
                                                    <span class="type-extra">
                                                        (<?php echo $s['advance_kind'] === 'private' ? 'Prywatna' : 'Firmowa'; ?>)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-amount"><?php echo formatMoney($s['amount']); ?></td>
                                            <td class="col-description" title="<?php echo e($s['description']); ?>"><?php echo e($s['description']); ?></td>
                                            <td class="col-period"><?php echo e(getSettlementPeriodLabel($s)); ?></td>
                                            <td class="col-created"><?php echo e($s['created_by_login']); ?></td>
                                            <?php if ($isAdminUser): ?>
                                                <td class="col-actions">
                                                    <div class="action-buttons">
                                                        <a href="<?php echo url('finanse.rozliczenia.edit', ['id' => $s['id']]); ?>" class="action-btn action-btn-edit" title="Edytuj rozliczenie">Edytuj</a>
                                                        <a href="<?php echo url('finanse.rozliczenia.delete', ['id' => $s['id']]); ?>" class="action-btn action-btn-delete" title="Usuń rozliczenie" onclick="return confirm('Czy na pewno chcesz usunąć to rozliczenie?');">Usuń</a>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <table class="erp-table settlement-table">
                    <thead>
                        <tr>
                            <th class="col-date">Data</th>
                            <th class="col-worker">Pracownik</th>
                            <th class="col-type">Typ</th>
                            <th class="col-amount">Kwota</th>
                            <th class="col-description">Opis</th>
                            <th class="col-period">Okres</th>
                            <th class="col-created">Utworzył</th>
                            <?php if ($isAdminUser): ?>
                                <th class="col-actions">Akcje</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($settlements as $s): ?>
                            <?php
                                $workerColor = getWorkerColor($s['worker_id']);
                                $rowStyle = "--worker-color: {$workerColor['hslBorder']}; --worker-bg: {$workerColor['hslLight']};";
                                $typeClass = 'type-' . $s['type'];
                            ?>
                            <tr style="<?php echo $rowStyle; ?>" data-worker-id="<?php echo $s['worker_id']; ?>">
                                <td class="col-date"><?php echo formatDate($s['date']); ?></td>
                                <td class="col-worker"><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></td>
                                <td class="col-type">
                                    <span class="type-badge <?php echo e($typeClass); ?>">
                                        <?php echo e(getSettlementTypeName($s['type'])); ?>
                                    </span>
                                    <?php if (!empty($s['advance_kind'])): ?>
                                        <span class="type-extra">
                                            (<?php echo $s['advance_kind'] === 'private' ? 'Prywatna' : 'Firmowa'; ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="col-amount"><?php echo formatMoney($s['amount']); ?></td>
                                <td class="col-description" title="<?php echo e($s['description']); ?>"><?php echo e($s['description']); ?></td>
                                <td class="col-period"><?php echo e(getSettlementPeriodLabel($s)); ?></td>
                                <td class="col-created"><?php echo e($s['created_by_login']); ?></td>
                                <?php if ($isAdminUser): ?>
                                    <td class="col-actions">
                                        <div class="action-buttons">
                                            <a href="<?php echo url('finanse.rozliczenia.edit', ['id' => $s['id']]); ?>" class="action-btn action-btn-edit" title="Edytuj rozliczenie">Edytuj</a>
                                            <a href="<?php echo url('finanse.rozliczenia.delete', ['id' => $s['id']]); ?>" class="action-btn action-btn-delete" title="Usuń rozliczenie" onclick="return confirm('Czy na pewno chcesz usunąć to rozliczenie?');">Usuń</a>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>

    <script>
        function toggleColors() {
            document.body.classList.toggle('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) {
                btn.classList.toggle('active');
            }
            localStorage.setItem('worklog_colors', !document.body.classList.contains('no-colors') ? '1' : '0');
        }

        function toggleDay(header) {
            const group = header.parentElement;
            group.classList.toggle('collapsed');
        }

        function expandAll() {
            document.querySelectorAll('.day-group').forEach(group => group.classList.remove('collapsed'));
        }

        function collapseAll() {
            document.querySelectorAll('.day-group').forEach(group => group.classList.add('collapsed'));
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (localStorage.getItem('worklog_colors') === '0') {
                document.body.classList.add('no-colors');
                const btn = document.querySelector('.btn-color-mode');
                if (btn) {
                    btn.classList.remove('active');
                }
            }

            const isDaysView = <?php echo $viewMode === 'days' ? 'true' : 'false'; ?>;
            if (isDaysView) {
                collapseAll();
            }
        });

        function rozOnMonthYearChange() {
            const month = parseInt(document.getElementById('rozSelectMonth').value);
            const year  = parseInt(document.getElementById('rozSelectYear').value);
            if (!month) return;
            const lastDay = new Date(year, month, 0).getDate();
            const pad = n => String(n).padStart(2, '0');
            document.getElementById('rozInputDateFrom').value = year + '-' + pad(month) + '-01';
            document.getElementById('rozInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
            document.getElementById('rozFilterForm').submit();
        }
    </script>
</body>
</html>
