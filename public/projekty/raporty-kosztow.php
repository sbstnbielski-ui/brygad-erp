
<?php
/**
 * BRYGAD ERP – Raporty kosztów (controllerski)
 *
 * Pełne zestawienie projektów z rozbiciem sprzedaży, kasy, kosztów (4 źródła),
 * wyniku dokumentowego i kasowego oraz marży. Eksport CSV.
 * Wszystkie wartości NETTO.
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/includes/analytics.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();

/* ----------------------------------------------------------------
 * Okres (analyticsPeriodFromRequest = spójnie z overview.php)
 * -------------------------------------------------------------- */
$period = analyticsPeriodFromRequest($_GET);
$from   = $period['from'];
$to     = $period['to'];
$year   = $period['year'];
$month  = $period['month'];

// Fallback — jeśli brak dat (np. year=all), weź od najwcześniejszej transakcji
if ($from === '' || $to === '') {
    $earliestRow = analyticsQueryRow(
        $pdo,
        "SELECT LEAST(
            COALESCE((SELECT MIN(issue_date) FROM invoices_sale WHERE exclude_from_analytics = 0), CURDATE()),
            COALESCE((SELECT MIN(issue_date) FROM sales_noninvoice_entries), CURDATE()),
            COALESCE((SELECT MIN(issue_date) FROM fakturownia_cost_invoices), CURDATE()),
            COALESCE((SELECT MIN(date) FROM work_logs WHERE status='approved'), CURDATE())
        ) AS earliest",
        [],
        ['earliest' => date('Y-01-01')]
    );
    $from = $earliestRow['earliest'] ?: date('Y-01-01');
    $to   = date('Y-m-d');
}
$periodLabel = date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to));

/* ----------------------------------------------------------------
 * Filtry dodatkowe
 * -------------------------------------------------------------- */
$projectFilter = isset($_GET['project_id']) && $_GET['project_id'] !== '' && $_GET['project_id'] !== 'all'
    ? (int)$_GET['project_id']
    : null;
$clientFilter = isset($_GET['investor_id']) && $_GET['investor_id'] !== '' && $_GET['investor_id'] !== 'all'
    ? (int)$_GET['investor_id']
    : null;
$sortBy = $_GET['sort'] ?? 'profit_doc';
$allowedSort = ['profit_doc','profit_cash','sales','costs','margin','name'];
if (!in_array($sortBy, $allowedSort, true)) $sortBy = 'profit_doc';

/* ----------------------------------------------------------------
 * Listy dla dropdownów
 * -------------------------------------------------------------- */
$projectsList = analyticsQueryAll(
    $pdo,
    "SELECT id, name FROM projects WHERE is_internal = 0 OR is_internal IS NULL ORDER BY name"
);
$investorsList = analyticsQueryAll(
    $pdo,
    "SELECT id, name FROM investors ORDER BY name"
);

/* ----------------------------------------------------------------
 * Zbuduj listę projektów do raportu (z aktywnością w okresie)
 * -------------------------------------------------------------- */
$sqlProjects = "SELECT p.id, p.name, p.investor_id, p.status,
                       i.name AS investor_name
                FROM projects p
                LEFT JOIN investors i ON i.id = p.investor_id
                WHERE (p.is_internal = 0 OR p.is_internal IS NULL)";
$projectsParams = [];
if ($projectFilter !== null) {
    $sqlProjects .= " AND p.id = :pid";
    $projectsParams[':pid'] = $projectFilter;
}
if ($clientFilter !== null) {
    $sqlProjects .= " AND p.investor_id = :iid";
    $projectsParams[':iid'] = $clientFilter;
}
$sqlProjects .= " ORDER BY p.name";
$allProjects = analyticsQueryAll($pdo, $sqlProjects, $projectsParams);

/* ----------------------------------------------------------------
 * Dla każdego projektu – pełne dane finansowe w okresie
 * -------------------------------------------------------------- */
$projectRows = [];
$totals = [
    'contracted'    => 0.0,
    'revenue_fv'    => 0.0,
    'revenue_pzf'   => 0.0,
    'revenue_total' => 0.0,
    'cash_total'    => 0.0,
    'cost_invoice'  => 0.0,
    'cost_labor'    => 0.0,
    'cost_cash'     => 0.0,
    'cost_fixed'    => 0.0,
    'cost_total'    => 0.0,
    'profit_doc'    => 0.0,
    'profit_cash'   => 0.0,
    'labor_hours'   => 0.0,
];

foreach ($allProjects as $p) {
    $pid = (int)$p['id'];
    $summary = analyticsFinancialSummary($pdo, $from, $to, $pid);

    // Zakontraktowano (wartość umów + aneksów podpisanych w okresie)
    $contracted = analyticsQueryScalar(
        $pdo,
        "SELECT COALESCE(SUM(amount_net), 0) FROM project_revenues
         WHERE project_id = :pid AND signed_date BETWEEN :from AND :to",
        [':pid' => $pid, ':from' => $from, ':to' => $to]
    );

    // Godziny pracy (dla wiersza robocizny)
    $laborHours = analyticsQueryScalar(
        $pdo,
        "SELECT COALESCE(SUM(hours + COALESCE(overtime_hours, 0)), 0)
         FROM work_logs
         WHERE project_id = :pid AND status = 'approved'
           AND date BETWEEN :from AND :to",
        [':pid' => $pid, ':from' => $from, ':to' => $to]
    );

    $hasActivity = $contracted > 0
        || $summary['revenue_total'] > 0
        || $summary['cash_total'] > 0
        || $summary['costs']['total'] > 0;

    if (!$hasActivity) continue;

    $row = [
        'project_id'    => $pid,
        'project_name'  => $p['name'],
        'investor_name' => $p['investor_name'] ?: '—',
        'status'        => $p['status'],
        'contracted'    => $contracted,
        'revenue_fv'    => $summary['revenue_fv'],
        'revenue_pzf'   => $summary['revenue_pzf'],
        'revenue_total' => $summary['revenue_total'],
        'cash_total'    => $summary['cash_total'],
        'cost_invoice'  => $summary['costs']['invoice'],
        'cost_fixed'    => $summary['costs']['fixed'],
        'cost_labor'    => $summary['costs']['labor'],
        'cost_cash'     => $summary['costs']['cash'],
        'cost_total'    => $summary['costs']['total'],
        'profit_doc'    => $summary['profit_doc'],
        'profit_cash'   => $summary['profit_cash'],
        'margin_doc'    => $summary['margin_doc'],
        'margin_cash'   => $summary['margin_cash'],
        'labor_hours'   => $laborHours,
    ];
    $projectRows[] = $row;

    $totals['contracted']    += $row['contracted'];
    $totals['revenue_fv']    += $row['revenue_fv'];
    $totals['revenue_pzf']   += $row['revenue_pzf'];
    $totals['revenue_total'] += $row['revenue_total'];
    $totals['cash_total']    += $row['cash_total'];
    $totals['cost_invoice']  += $row['cost_invoice'];
    $totals['cost_fixed']    += $row['cost_fixed'];
    $totals['cost_labor']    += $row['cost_labor'];
    $totals['cost_cash']     += $row['cost_cash'];
    $totals['cost_total']    += $row['cost_total'];
    $totals['profit_doc']    += $row['profit_doc'];
    $totals['profit_cash']   += $row['profit_cash'];
    $totals['labor_hours']   += $row['labor_hours'];
}

$totals['margin_doc']  = $totals['revenue_total'] > 0 ? ($totals['profit_doc']  / $totals['revenue_total']) * 100 : null;
$totals['margin_cash'] = $totals['cash_total']    > 0 ? ($totals['profit_cash'] / $totals['cash_total'])    * 100 : null;

/* ----------------------------------------------------------------
 * Sortowanie
 * -------------------------------------------------------------- */
usort($projectRows, function ($a, $b) use ($sortBy) {
    switch ($sortBy) {
        case 'profit_cash': return $b['profit_cash'] <=> $a['profit_cash'];
        case 'sales':       return $b['revenue_total'] <=> $a['revenue_total'];
        case 'costs':       return $b['cost_total'] <=> $a['cost_total'];
        case 'margin':      return ($b['margin_doc'] ?? -999) <=> ($a['margin_doc'] ?? -999);
        case 'name':        return strcmp($a['project_name'], $b['project_name']);
        case 'profit_doc':
        default:            return $b['profit_doc'] <=> $a['profit_doc'];
    }
});

/* ----------------------------------------------------------------
 * Eksport CSV (przed HTML, żeby nie psuć nagłówków)
 * -------------------------------------------------------------- */
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($projectRows as $r) {
        $csvRows[] = [
            'Projekt'             => $r['project_name'],
            'Klient'              => $r['investor_name'],
            'Zakontraktowano'     => number_format($r['contracted'], 2, ',', ''),
            'Sprzedaz FV'         => number_format($r['revenue_fv'], 2, ',', ''),
            'Sprzedaz PZF'        => number_format($r['revenue_pzf'], 2, ',', ''),
            'Sprzedaz razem'      => number_format($r['revenue_total'], 2, ',', ''),
            'Wplynelo (kasa)'     => number_format($r['cash_total'], 2, ',', ''),
            'Faktury kosztowe'    => number_format($r['cost_invoice'], 2, ',', ''),
            'Robocizna'           => number_format($r['cost_labor'], 2, ',', ''),
            'Godziny robocizny'   => number_format($r['labor_hours'], 1, ',', ''),
            'Wydatki pracownikow' => number_format($r['cost_cash'], 2, ',', ''),
            'Koszty stale'        => number_format($r['cost_fixed'], 2, ',', ''),
            'Koszty razem'        => number_format($r['cost_total'], 2, ',', ''),
            'Zysk (dokument)'     => number_format($r['profit_doc'], 2, ',', ''),
            'Zysk (kasa)'         => number_format($r['profit_cash'], 2, ',', ''),
            'Marza dokumentowa %' => $r['margin_doc']  !== null ? number_format($r['margin_doc'], 2, ',', '')  : '',
            'Marza kasowa %'      => $r['margin_cash'] !== null ? number_format($r['margin_cash'], 2, ',', '') : '',
        ];
    }
    analyticsExportCsv(
        $csvRows,
        ['Projekt','Klient','Zakontraktowano','Sprzedaz FV','Sprzedaz PZF','Sprzedaz razem',
         'Wplynelo (kasa)','Faktury kosztowe','Robocizna','Godziny robocizny','Wydatki pracownikow',
         'Koszty stale','Koszty razem','Zysk (dokument)','Zysk (kasa)',
         'Marza dokumentowa %','Marza kasowa %'],
        'raport-kosztow_' . $from . '_' . $to . '.csv'
    );
}

/* ----------------------------------------------------------------
 * Helpery do HTML
 * -------------------------------------------------------------- */
$currentParams = [
    'year'        => $_GET['year']        ?? null,
    'month'       => $_GET['month']       ?? null,
    'date_from'   => $_GET['date_from']   ?? null,
    'date_to'     => $_GET['date_to']     ?? null,
    'project_id'  => $_GET['project_id']  ?? null,
    'investor_id' => $_GET['investor_id'] ?? null,
    'sort'        => $_GET['sort']        ?? null,
];
$buildUrl = function (array $override) use ($currentParams) {
    $merged = array_merge($currentParams, $override);
    $clean  = array_filter($merged, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($clean);
};

$monthNames = [
    1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',
    7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'
];
$yearRange = range((int)date('Y'), (int)date('Y') - 4);

$isMonthThis = ($year === (int)date('Y') && $month === (int)date('n'));
$isYearThis  = ($year === (int)date('Y') && $month === 0);
$isLast12m   = false; // dla jasności

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> – Raporty kosztów</title>
    <link rel="stylesheet" href="/projekty/assets/projekty.css">
    <style>
        :root {
            --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb;
            --blue-2: #2563eb; --accent: #667eea;
        }
        .container { max-width: 1600px; margin: 0 auto; padding: 24px; }

        /* Filter bar – spójne z overview.php / styl.md */
        .filter-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid var(--line); overflow: hidden; margin-bottom: 16px; }
        .spx-filter-bar { padding: 12px 20px; display: flex; gap: 8px; align-items: flex-end; flex-wrap: nowrap; }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1 1 0; }
        .spx-filter-group label { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .spx-filter-group select,
        .spx-filter-group input[type="date"] { padding: 0 8px; height: 38px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; background: #fff; font-family: inherit; width: 100%; }
        .spx-filter-group select:focus,
        .spx-filter-group input[type="date"]:focus { outline: none; border-color: var(--blue-2); box-shadow: 0 0 0 2px rgba(37,99,235,0.1); }
        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-top: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .spx-controls-left { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: #fff; border: 1px solid var(--line); border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; display: inline-flex; align-items: center; white-space: nowrap; transition: all 0.15s; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: var(--blue-2); color: var(--blue-2); }
        .spx-quick-btn.active { background: var(--blue-2); border-color: var(--blue-2); color: #fff; font-weight: 600; }
        .btn { height: 38px; border-radius: 6px; border: 1px solid var(--line); background: #fff; color: var(--ink); padding: 0 14px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; transition: all 0.15s; }
        .btn:hover { background: #f9fafb; border-color: #cbd5e1; }
        .btn-primary { background: var(--blue-2); color: #fff; border-color: var(--blue-2); }
        .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .btn-export { background: #16a34a; color: #fff; border-color: #16a34a; }
        .btn-export:hover { background: #15803d; border-color: #15803d; }

        /* KPI */
        .kpi-grid {
            display: grid; gap: 14px; margin-bottom: 20px;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        }
        .kpi-card {
            background: #fff; border-radius: 12px; padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 2px solid transparent; transition: border-color 0.15s;
        }
        .kpi-card:hover { border-color: var(--accent); }
        .kpi-card .kpi-label { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 8px; }
        .kpi-card .kpi-value { font-size: 24px; font-weight: 700; color: var(--accent); line-height: 1.2; }
        .kpi-card .kpi-sub   { font-size: 11px; color: #999; margin-top: 6px; }
        .kpi-card.sales  .kpi-value { color: #16a34a; }
        .kpi-card.cash   .kpi-value { color: #059669; }
        .kpi-card.costs  .kpi-value { color: #dc2626; }
        .kpi-card.profit.pos .kpi-value { color: #16a34a; }
        .kpi-card.profit.neg .kpi-value { color: #dc2626; }
        .kpi-card.margin .kpi-value { color: #0891b2; }

        /* Tabela */
        .table-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        .table-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .table-head h2 { margin: 0; font-size: 16px; font-weight: 700; color: var(--ink); letter-spacing: -0.2px; }
        .table-head .hint { font-size: 12px; color: var(--muted); }

        .table-scroll {
            overflow-x: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
        }
        table.reports-table {
            width: 100%; border-collapse: collapse;
            font-size: 12.5px; font-variant-numeric: tabular-nums;
            min-width: 1500px;
        }
        table.reports-table th,
        table.reports-table td {
            padding: 9px 10px; text-align: right; border-bottom: 1px solid #f1f5f9;
            white-space: nowrap;
        }
        table.reports-table th {
            background: #f8fafc;
            font-size: 10.5px; font-weight: 700;
            color: var(--muted); text-transform: uppercase; letter-spacing: 0.4px;
            position: sticky; top: 0; z-index: 1;
            border-bottom: 2px solid var(--line);
        }
        table.reports-table th a { color: inherit; text-decoration: none; }
        table.reports-table th.sortable a::after { content: ' ↕'; opacity: 0.4; font-size: 10px; }
        table.reports-table th.sort-active a { color: var(--blue-2); }
        table.reports-table th.sort-active a::after { content: ' ▼'; opacity: 1; }
        table.reports-table td.text, table.reports-table th.text { text-align: left; }
        table.reports-table tbody tr:hover { background: #f9fafb; }
        table.reports-table td.project-link a { color: var(--ink); font-weight: 600; text-decoration: none; }
        table.reports-table td.project-link a:hover { color: var(--blue-2); }
        table.reports-table td .client-sub { display: block; font-size: 11px; color: var(--muted); font-weight: normal; }

        table.reports-table .pos { color: #16a34a; font-weight: 700; }
        table.reports-table .neg { color: #dc2626; font-weight: 700; }
        table.reports-table .neu { color: var(--muted); }

        table.reports-table tfoot td {
            background: #f8fafc; font-weight: 700; color: var(--ink);
            border-top: 2px solid var(--line); border-bottom: none;
        }
        table.reports-table .cost-col  { color: #b91c1c; }
        table.reports-table .sales-col { color: #166534; }

        /* Group headers w tabeli */
        table.reports-table th.grp-sales    { background: #ecfdf5; color: #166534; border-left: 2px solid #bbf7d0; }
        table.reports-table th.grp-cash     { background: #f0fdfa; color: #047857; border-left: 2px solid #99f6e4; }
        table.reports-table th.grp-costs    { background: #fef2f2; color: #991b1b; border-left: 2px solid #fecaca; }
        table.reports-table th.grp-profit   { background: #eef2ff; color: #3730a3; border-left: 2px solid #c7d2fe; }
        table.reports-table td.grp-sales    { border-left: 2px solid #f0fdf4; }
        table.reports-table td.grp-cash     { border-left: 2px solid #f0fdfa; }
        table.reports-table td.grp-costs    { border-left: 2px solid #fef2f2; }
        table.reports-table td.grp-profit   { border-left: 2px solid #eef2ff; }

        .no-data { padding: 40px 20px; text-align: center; color: var(--muted); font-size: 14px; }
    </style>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

<div class="container">

    <div class="hero">
        <div>
            <div class="hero-breadcrumb">
                <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                <a href="<?php echo url('projekty'); ?>">Projekty</a> /
                Raport kosztów
            </div>
            <h1>Raport kosztów i rentowności projektów</h1>
            <p>Okres: <strong><?php echo e($periodLabel); ?></strong> · Wszystkie wartości netto · Użytkownik: <?php echo e($userName); ?></p>
        </div>
        <div class="hero-actions">
            <a href="<?php echo e($buildUrl(['export' => 'csv'])); ?>" class="btn btn-export">📥 Eksport CSV</a>
            <a href="<?php echo url('finanse.overview', ['date_from' => $from, 'date_to' => $to]); ?>" class="btn-hero-secondary">Analiza szczegółowa</a>
            <a href="<?php echo url('projekty'); ?>" class="btn-hero-secondary">← Projekty</a>
        </div>
    </div>

    <div class="filter-card">
        <form method="GET" action="" class="spx-filter-bar" id="repFilterForm">
            <div class="spx-filter-group">
                <label>Miesiąc</label>
                <select name="month" id="repSelectMonth" onchange="repOnMonthYearChange()">
                    <option value="0" <?php echo $month === 0 ? 'selected' : ''; ?>>— Cały rok —</option>
                    <?php foreach ($monthNames as $mn => $mName): ?>
                        <option value="<?php echo $mn; ?>" <?php echo $month === $mn ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spx-filter-group">
                <label>Rok</label>
                <select name="year" id="repSelectYear" onchange="repOnMonthYearChange()">
                    <option value="all" <?php echo $year === 0 ? 'selected' : ''; ?>>Wszystkie</option>
                    <?php foreach ($yearRange as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo $year === $yr ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spx-filter-group">
                <label>Od</label>
                <input type="date" name="date_from" id="repInputDateFrom" value="<?php echo e($from); ?>">
            </div>
            <div class="spx-filter-group">
                <label>Do</label>
                <input type="date" name="date_to" id="repInputDateTo" value="<?php echo e($to); ?>">
            </div>
            <div class="spx-filter-group">
                <label>Projekt</label>
                <select name="project_id">
                    <option value="all">Wszystkie</option>
                    <?php foreach ($projectsList as $pr): ?>
                        <option value="<?php echo (int)$pr['id']; ?>" <?php echo $projectFilter === (int)$pr['id'] ? 'selected' : ''; ?>>
                            <?php echo e($pr['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spx-filter-group">
                <label>Klient</label>
                <select name="investor_id">
                    <option value="all">Wszyscy</option>
                    <?php foreach ($investorsList as $inv): ?>
                        <option value="<?php echo (int)$inv['id']; ?>" <?php echo $clientFilter === (int)$inv['id'] ? 'selected' : ''; ?>>
                            <?php echo e($inv['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="sort" value="<?php echo e($sortBy); ?>">
            <button type="submit" class="btn btn-primary">Filtruj</button>
        </form>

        <div class="spx-controls-bar">
            <div class="spx-controls-left">
                <?php
                $qbMonth = $buildUrl(['year' => (int)date('Y'), 'month' => (int)date('n'), 'date_from' => null, 'date_to' => null]);
                $qbYear  = $buildUrl(['year' => (int)date('Y'), 'month' => 0, 'date_from' => null, 'date_to' => null]);
                $qbAll   = $buildUrl(['year' => 'all', 'month' => null, 'date_from' => null, 'date_to' => null]);
                ?>
                <a href="<?php echo e($qbMonth); ?>"  class="spx-quick-btn <?php echo $isMonthThis ? 'active' : ''; ?>">Ten miesiąc</a>
                <a href="<?php echo e($qbYear); ?>"   class="spx-quick-btn <?php echo $isYearThis  ? 'active' : ''; ?>">Ten rok</a>
                <a href="<?php echo e($qbAll); ?>"   class="spx-quick-btn <?php echo $year === 0 ? 'active' : ''; ?>">Wszystko</a>
            </div>
            <div style="font-size:12px;color:var(--muted);">
                <strong style="color:var(--ink);"><?php echo count($projectRows); ?></strong> projektów z aktywnością · sortuj: 
                <strong style="color:var(--ink);">
                    <?php
                    echo [
                        'profit_doc'  => 'zysk dokumentowy',
                        'profit_cash' => 'zysk kasowy',
                        'sales'       => 'sprzedaż',
                        'costs'       => 'koszty',
                        'margin'      => 'marża',
                        'name'        => 'nazwa',
                    ][$sortBy]; ?>
                </strong>
            </div>
        </div>
    </div>

    <?php /* KPI sumaryczne */ ?>
    <div class="kpi-grid">
        <div class="kpi-card sales">
            <div class="kpi-label">Sprzedaż (FV + PZF)</div>
            <div class="kpi-value"><?php echo formatMoney($totals['revenue_total']); ?></div>
            <div class="kpi-sub">FV: <?php echo formatMoney($totals['revenue_fv']); ?><?php if ($totals['revenue_pzf'] > 0): ?> · PZF: <?php echo formatMoney($totals['revenue_pzf']); ?><?php endif; ?></div>
        </div>
        <div class="kpi-card cash">
            <div class="kpi-label">Wpłynęło</div>
            <div class="kpi-value"><?php echo formatMoney($totals['cash_total']); ?></div>
            <div class="kpi-sub">FV payments + PZF paid</div>
        </div>
        <div class="kpi-card costs">
            <div class="kpi-label">Koszty razem</div>
            <div class="kpi-value"><?php echo formatMoney($totals['cost_total']); ?></div>
            <div class="kpi-sub">Faktury + robocizna + wydatki + stałe</div>
        </div>
        <div class="kpi-card profit <?php echo $totals['profit_doc'] >= 0 ? 'pos' : 'neg'; ?>">
            <div class="kpi-label">Zysk dokumentowy</div>
            <div class="kpi-value"><?php echo formatMoney($totals['profit_doc']); ?></div>
            <div class="kpi-sub">Sprzedaż − koszty</div>
        </div>
        <div class="kpi-card profit <?php echo $totals['profit_cash'] >= 0 ? 'pos' : 'neg'; ?>">
            <div class="kpi-label">Zysk kasowy</div>
            <div class="kpi-value"><?php echo formatMoney($totals['profit_cash']); ?></div>
            <div class="kpi-sub">Wpłynęło − koszty</div>
        </div>
        <div class="kpi-card margin">
            <div class="kpi-label">Marża dokumentowa</div>
            <div class="kpi-value"><?php echo $totals['margin_doc'] !== null ? number_format($totals['margin_doc'], 1, ',', ' ') . '%' : '—'; ?></div>
            <div class="kpi-sub">Wynik / sprzedaż</div>
        </div>
    </div>

    <div class="table-card">
        <div class="table-head">
            <h2>Zestawienie projektów</h2>
            <div class="hint"><?php echo count($projectRows); ?> projektów · tylko z aktywnością w okresie</div>
        </div>

        <?php if (empty($projectRows)): ?>
            <div class="no-data">Brak projektów z aktywnością finansową w wybranym okresie i filtrach.</div>
        <?php else: ?>
        <div class="table-scroll">
        <table class="reports-table">
            <thead>
                <tr>
                    <th class="text sortable <?php echo $sortBy === 'name' ? 'sort-active' : ''; ?>">
                        <a href="<?php echo e($buildUrl(['sort' => 'name'])); ?>">Projekt / klient</a>
                    </th>
                    <th class="grp-sales">Zakontraktowano</th>
                    <th class="grp-sales">FV</th>
                    <th class="grp-sales">PZF</th>
                    <th class="grp-sales sortable <?php echo $sortBy === 'sales' ? 'sort-active' : ''; ?>">
                        <a href="<?php echo e($buildUrl(['sort' => 'sales'])); ?>">Sprzedaż razem</a>
                    </th>
                    <th class="grp-cash">Wpłynęło (kasa)</th>
                    <th class="grp-costs">Faktury kosztowe</th>
                    <th class="grp-costs">Robocizna</th>
                    <th class="grp-costs">Wydatki prac.</th>
                    <th class="grp-costs">Stałe</th>
                    <th class="grp-costs sortable <?php echo $sortBy === 'costs' ? 'sort-active' : ''; ?>">
                        <a href="<?php echo e($buildUrl(['sort' => 'costs'])); ?>">Koszty razem</a>
                    </th>
                    <th class="grp-profit sortable <?php echo $sortBy === 'profit_doc' ? 'sort-active' : ''; ?>">
                        <a href="<?php echo e($buildUrl(['sort' => 'profit_doc'])); ?>">Zysk dok.</a>
                    </th>
                    <th class="grp-profit sortable <?php echo $sortBy === 'profit_cash' ? 'sort-active' : ''; ?>">
                        <a href="<?php echo e($buildUrl(['sort' => 'profit_cash'])); ?>">Zysk kasa</a>
                    </th>
                    <th class="grp-profit sortable <?php echo $sortBy === 'margin' ? 'sort-active' : ''; ?>">
                        <a href="<?php echo e($buildUrl(['sort' => 'margin'])); ?>">Marża dok.</a>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projectRows as $r): ?>
                    <tr>
                        <td class="text project-link">
                            <a href="<?php echo url('projekty.view', ['id' => $r['project_id']]); ?>" title="Otwórz projekt">
                                <?php echo e($r['project_name']); ?>
                            </a>
                            <span class="client-sub"><?php echo e($r['investor_name']); ?></span>
                        </td>
                        <td class="grp-sales"><?php echo $r['contracted']    > 0 ? formatMoney($r['contracted'])    : '<span class="neu">—</span>'; ?></td>
                        <td class="grp-sales"><?php echo $r['revenue_fv']    > 0 ? formatMoney($r['revenue_fv'])    : '<span class="neu">—</span>'; ?></td>
                        <td class="grp-sales"><?php echo $r['revenue_pzf']   > 0 ? formatMoney($r['revenue_pzf'])   : '<span class="neu">—</span>'; ?></td>
                        <td class="grp-sales sales-col"><strong><?php echo formatMoney($r['revenue_total']); ?></strong></td>
                        <td class="grp-cash"><?php echo $r['cash_total'] > 0 ? formatMoney($r['cash_total']) : '<span class="neu">—</span>'; ?></td>
                        <td class="grp-costs cost-col"><?php echo $r['cost_invoice'] > 0 ? formatMoney($r['cost_invoice']) : '<span class="neu">—</span>'; ?></td>
                        <td class="grp-costs cost-col">
                            <?php if ($r['cost_labor'] > 0): ?>
                                <?php echo formatMoney($r['cost_labor']); ?>
                                <span style="display:block;font-size:10.5px;color:#94a3b8;font-weight:normal;"><?php echo number_format($r['labor_hours'], 1, ',', ' '); ?> h</span>
                            <?php else: ?><span class="neu">—</span><?php endif; ?>
                        </td>
                        <td class="grp-costs cost-col"><?php echo $r['cost_cash']  > 0 ? formatMoney($r['cost_cash'])  : '<span class="neu">—</span>'; ?></td>
                        <td class="grp-costs cost-col"><?php echo $r['cost_fixed'] > 0 ? formatMoney($r['cost_fixed']) : '<span class="neu">—</span>'; ?></td>
                        <td class="grp-costs cost-col"><strong><?php echo formatMoney($r['cost_total']); ?></strong></td>
                        <td class="grp-profit <?php echo $r['profit_doc']  >= 0 ? 'pos' : 'neg'; ?>"><?php echo formatMoney($r['profit_doc']); ?></td>
                        <td class="grp-profit <?php echo $r['profit_cash'] >= 0 ? 'pos' : 'neg'; ?>"><?php echo formatMoney($r['profit_cash']); ?></td>
                        <td class="grp-profit"><?php echo $r['margin_doc'] !== null ? number_format($r['margin_doc'], 1, ',', ' ') . '%' : '<span class="neu">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="text">RAZEM</td>
                    <td><?php echo formatMoney($totals['contracted']); ?></td>
                    <td><?php echo formatMoney($totals['revenue_fv']); ?></td>
                    <td><?php echo formatMoney($totals['revenue_pzf']); ?></td>
                    <td class="sales-col"><?php echo formatMoney($totals['revenue_total']); ?></td>
                    <td><?php echo formatMoney($totals['cash_total']); ?></td>
                    <td class="cost-col"><?php echo formatMoney($totals['cost_invoice']); ?></td>
                    <td class="cost-col">
                        <?php echo formatMoney($totals['cost_labor']); ?>
                        <span style="display:block;font-size:10.5px;color:#94a3b8;font-weight:normal;"><?php echo number_format($totals['labor_hours'], 1, ',', ' '); ?> h</span>
                    </td>
                    <td class="cost-col"><?php echo formatMoney($totals['cost_cash']); ?></td>
                    <td class="cost-col"><?php echo formatMoney($totals['cost_fixed']); ?></td>
                    <td class="cost-col"><?php echo formatMoney($totals['cost_total']); ?></td>
                    <td class="<?php echo $totals['profit_doc']  >= 0 ? 'pos' : 'neg'; ?>"><?php echo formatMoney($totals['profit_doc']); ?></td>
                    <td class="<?php echo $totals['profit_cash'] >= 0 ? 'pos' : 'neg'; ?>"><?php echo formatMoney($totals['profit_cash']); ?></td>
                    <td><?php echo $totals['margin_doc'] !== null ? number_format($totals['margin_doc'], 1, ',', ' ') . '%' : '—'; ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
</footer>

<script>
function repOnMonthYearChange() {
    var m = document.getElementById('repSelectMonth').value;
    var y = document.getElementById('repSelectYear').value;
    var pad = function(n){ return String(n).padStart(2,'0'); };
    if (y === 'all') {
        document.getElementById('repInputDateFrom').value = '';
        document.getElementById('repInputDateTo').value   = '';
    } else {
        var year = parseInt(y);
        var month = parseInt(m);
        if (month >= 1 && month <= 12) {
            var lastDay = new Date(year, month, 0).getDate();
            document.getElementById('repInputDateFrom').value = year + '-' + pad(month) + '-01';
            document.getElementById('repInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
        } else {
            document.getElementById('repInputDateFrom').value = year + '-01-01';
            document.getElementById('repInputDateTo').value   = year + '-12-31';
        }
    }
    document.getElementById('repFilterForm').submit();
}
</script>
</body>
</html>
