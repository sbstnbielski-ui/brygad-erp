<?php
/**
 * BRYGAD ERP - Wszystkie Koszty (widok zbiorczy)
 * Laczy: faktury, koszty stale, robocizne, wydatki
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__) . '/_company-cost-categories.php';
require_once dirname(__DIR__) . '/_company-cost-category-combo.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$companyCostDictionary = ksCompanyCostLoadDictionary($pdo);
$companyCategoryLabels = ksCompanyCostCategoryLabels($companyCostDictionary);
$companySubcategoriesByCategory = ksCompanyCostSubcategoriesByCategory($companyCostDictionary);
$companySubcategoryHints = ksCompanyCostSubcategoryNames($companyCostDictionary);

// Parametry filtrow
$filterProject = isset($_GET['project']) ? (int)$_GET['project'] : 0;
$filterEtap = isset($_GET['etap']) ? (int)$_GET['etap'] : 0;
$filterType = $_GET['type'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterRange = $_GET['range'] ?? 'month';
$filterCategory = trim((string)($_GET['category'] ?? ''));
$filterSubcategory = trim((string)($_GET['subcategory'] ?? ''));
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : '';
$search = trim((string)($_GET['search'] ?? ''));

// Sortowanie
$sort = $_GET['sort'] ?? 'date';
$order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$allowedSorts = ['date', 'type', 'project', 'category', 'subcategory', 'counterparty', 'description', 'amount'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'date';
}

// Projekty do filtra
$projects = $pdo->query("SELECT id, name FROM projects WHERE status IN ('active', 'planned') ORDER BY name")->fetchAll();

// Etapy do filtra (jesli wybrano projekt)
$etapy = [];
if ($filterProject > 0) {
    $stmt = $pdo->prepare("SELECT id, name FROM project_cost_nodes WHERE project_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$filterProject]);
    $etapy = $stmt->fetchAll();
}

$categoryHints = [];
$subcategoryHints = [];
try {
    $categoryHintStmt = $pdo->query("
        SELECT DISTINCT x.category_name
        FROM (
            SELECT fi.category AS category_name
            FROM finance_items fi
            WHERE fi.item_type = 'FIXED_COST'
              AND fi.category IS NOT NULL
              AND TRIM(fi.category) <> ''
            UNION
            SELECT we.company_category AS category_name
            FROM worker_expenses we
            WHERE we.company_category IS NOT NULL
              AND TRIM(we.company_category) <> ''
        ) x
        ORDER BY x.category_name
    ");
    $categoryHints = $categoryHintStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $subcategoryHintStmt = $pdo->query("
        SELECT DISTINCT x.subcategory_name
        FROM (
            SELECT fi.subcategory AS subcategory_name
            FROM finance_items fi
            WHERE fi.item_type = 'FIXED_COST'
              AND fi.subcategory IS NOT NULL
              AND TRIM(fi.subcategory) <> ''
            UNION
            SELECT we.company_subcategory AS subcategory_name
            FROM worker_expenses we
            WHERE we.company_subcategory IS NOT NULL
              AND TRIM(we.company_subcategory) <> ''
        ) x
        ORDER BY x.subcategory_name
    ");
    $subcategoryHints = $subcategoryHintStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (PDOException $e) {
    $categoryHints = [];
    $subcategoryHints = [];
}
$categoryHints = array_values(array_unique(array_merge(array_keys($companyCategoryLabels), $categoryHints)));
$subcategoryHints = array_values(array_unique(array_merge($companySubcategoryHints, $subcategoryHints)));
natcasesort($categoryHints);
natcasesort($subcategoryHints);
$categoryHints = array_values($categoryHints);
$subcategoryHints = array_values($subcategoryHints);

// Pobierz wszystkie koszty z view_finance_ledger
$where = ["1=1"];
$params = [];

if ($filterProject > 0) {
    $where[] = "vfl.project_id = :project_id";
    $params[':project_id'] = $filterProject;
}

if ($filterEtap > 0) {
    $where[] = "vfl.cost_node_id = :etap_id";
    $params[':etap_id'] = $filterEtap;
}

if ($filterType === 'invoice_cost') {
    $where[] = "vfl.ledger_source IN ('invoice_cost', 'invoice_cost_legacy', 'invoice')";
} elseif ($filterType !== 'all') {
    $where[] = "vfl.ledger_source = :type";
    $params[':type'] = $filterType;
}
if ($filterCategory !== '') {
    $where[] = "(
        (vfl.ledger_source = 'fixed' AND fi.category = :company_category)
        OR
        (vfl.ledger_source = 'cash' AND we.company_category = :company_category)
    )";
    $params[':company_category'] = $filterCategory;
}
if ($filterSubcategory !== '') {
    $where[] = "(
        (vfl.ledger_source = 'fixed' AND fi.subcategory = :company_subcategory)
        OR
        (vfl.ledger_source = 'cash' AND we.company_subcategory = :company_subcategory)
    )";
    $params[':company_subcategory'] = $filterSubcategory;
}

if (!empty($dateFrom)) {
    $where[] = "vfl.date >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if (!empty($dateTo)) {
    $where[] = "vfl.date <= :date_to";
    $params[':date_to'] = $dateTo;
}

// Wyszukiwarka globalna
if (!empty($search)) {
    $where[] = "(vfl.description LIKE :search 
                OR p.name LIKE :search 
                OR pcn.name LIKE :search
                OR vfl.counterparty_name LIKE :search
                OR fi.category LIKE :search
                OR fi.subcategory LIKE :search
                OR we.company_category LIKE :search
                OR we.company_subcategory LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$orderByMap = [
    'date' => 'vfl.date',
    'type' => 'vfl.ledger_source',
    'project' => 'COALESCE(p.name, \'\')',
    'category' => "COALESCE(CASE WHEN vfl.ledger_source = 'fixed' THEN fi.category WHEN vfl.ledger_source = 'cash' THEN we.company_category END, '')",
    'subcategory' => "COALESCE(CASE WHEN vfl.ledger_source = 'fixed' THEN fi.subcategory WHEN vfl.ledger_source = 'cash' THEN we.company_subcategory END, '')",
    'counterparty' => 'COALESCE(vfl.counterparty_name, \'\')',
    'description' => 'COALESCE(vfl.description, \'\')',
    'amount' => 'vfl.amount',
];
$orderBy = $orderByMap[$sort] ?? 'vfl.date';

$sql = "
    SELECT 
        vfl.*,
        p.name as project_name,
        pcn.name as cost_node_name,
        fi.category as fixed_category,
        fi.subcategory as fixed_subcategory,
        fi.amount_net as fixed_amount_net,
        we.company_category as cash_category,
        we.company_subcategory as cash_subcategory,
        CASE
            WHEN vfl.ledger_source = 'fixed' THEN fi.category
            WHEN vfl.ledger_source = 'cash' THEN we.company_category
            ELSE NULL
        END as company_category,
        CASE
            WHEN vfl.ledger_source = 'fixed' THEN fi.subcategory
            WHEN vfl.ledger_source = 'cash' THEN we.company_subcategory
            ELSE NULL
        END as company_subcategory
    FROM view_finance_ledger vfl
    LEFT JOIN projects p ON vfl.project_id = p.id
    LEFT JOIN project_cost_nodes pcn ON vfl.cost_node_id = pcn.id
    LEFT JOIN finance_items fi ON vfl.ledger_source = 'fixed' AND fi.id = vfl.source_id
    LEFT JOIN worker_expenses we ON vfl.ledger_source = 'cash' AND we.id = vfl.source_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY {$orderBy} {$order}, vfl.date DESC, vfl.source_id DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $costs = $stmt->fetchAll();
    foreach ($costs as &$cost) {
        $cost['company_category'] = trim((string)($cost['company_category'] ?? ''));
        $cost['company_subcategory'] = trim((string)($cost['company_subcategory'] ?? ''));
        if (($cost['ledger_source'] ?? '') === 'fixed' && $cost['fixed_amount_net'] !== null) {
            $cost['amount'] = $cost['fixed_amount_net'];
        }
    }
    unset($cost);
} catch (PDOException $e) {
    error_log("FINANSE: Blad pobierania kosztow: " . $e->getMessage());
    error_log("SQL: " . $sql);
    error_log("Params: " . print_r($params, true));
    $costs = [];
    $error_message = "Wystapil blad podczas pobierania danych. Sprawdz logi.";
}

// Grupowanie po typie dla podsumowania
$summary = [
    'invoice_cost' => ['label' => 'Faktury kosztowe', 'amount' => 0, 'count' => 0],
    'labor' => ['label' => 'Robocizna', 'amount' => 0, 'count' => 0],
    'cash' => ['label' => 'Wydatki/Paragony', 'amount' => 0, 'count' => 0],
    'fixed' => ['label' => 'Wydatki firmowe', 'amount' => 0, 'count' => 0]
];

foreach ($costs as $cost) {
    $source = in_array($cost['ledger_source'], ['invoice_cost_legacy', 'invoice'], true)
        ? 'invoice_cost'
        : $cost['ledger_source'];
    if (isset($summary[$source])) {
        $summary[$source]['amount'] += $cost['amount'];
        $summary[$source]['count']++;
    }
}

$total = array_sum(array_column($summary, 'amount'));

// Typy kosztow dla filtra
$costTypes = [
    'all' => 'Wszystkie typy',
    'invoice_cost' => 'Faktury kosztowe',
    'invoice_cost_legacy' => 'Faktury kosztowe',
    'invoice' => 'Faktury kosztowe',
    'labor' => 'Robocizna',
    'cash' => 'Wydatki/Paragony',
    'fixed' => 'Wydatki firmowe'
];

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

// Funkcja do generowania kolorów dla wierszy (bazowana na ID)
function getRowColor($index) {
    $hue = ($index * 137.508) % 360; // Złoty kąt dla równomiernego rozkładu
    return [
        'hsl' => "hsl($hue, 70%, 95%)",
        'border' => "hsl($hue, 60%, 65%)"
    ];
}

function kwDisplayOrDash(?string $value): string
{
    $trimmed = trim((string)$value);
    return $trimmed !== '' ? $trimmed : '-';
}

function kwCompanyCategoryDisplay(?string $value, array $labels): string
{
    $key = trim((string)$value);
    if ($key === '') {
        return '-';
    }
    return $labels[$key] ?? $key;
}

function kwTruncate(string $value, int $max = 30): string
{
    return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 2) . '…' : $value;
}

function kwCounterparty(array $cost): string
{
    $counterparty = trim((string)($cost['counterparty_name'] ?? ''));
    return $counterparty !== '' ? $counterparty : '-';
}

function kwSortLink(string $column, string $label, string $currentSort, string $currentOrder): string
{
    $params = $_GET;
    $nextOrder = ($currentSort === $column && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    $params['sort'] = $column;
    $params['order'] = $nextOrder;
    $url = '?' . http_build_query($params);

    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentOrder === 'ASC' ? ' ↑' : ' ↓';
    }

    return '<a href="' . e($url) . '">' . e($label . $arrow) . '</a>';
}

// Grupowanie po dniach dla widoku accordion
$costsByDate = [];
foreach ($costs as $cost) {
    $date = $cost['date'];
    if (!isset($costsByDate[$date])) {
        $costsByDate[$date] = [
            'costs' => [],
            'total' => 0,
            'count' => 0
        ];
    }
    $costsByDate[$date]['costs'][] = $cost;
    $costsByDate[$date]['total'] += $cost['amount'];
    $costsByDate[$date]['count']++;
}
if ($sort === 'date' && $order === 'ASC') {
    ksort($costsByDate);
} else {
    krsort($costsByDate); // Sortuj daty malejąco (najnowsze najpierw)
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo e(APP_NAME); ?> - Wszystkie koszty</title>
    <style>
        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --bg-card:           #ffffff;
            --border:            #e5e7eb;
            --border-light:      #f3f4f6;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
            --success:           #22c55e;
            --danger:            #ef4444;
            --warning:           #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body); color: var(--text-main); padding-bottom: 40px;
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
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; font-family: inherit; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }
        
        /* Summary cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #e5e7eb;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
        }
        
        .summary-card.invoice { border-left-color: #ea580c; }
        .summary-card.labor { border-left-color: #7c3aed; }
        .summary-card.cash { border-left-color: #16a34a; }
        .summary-card.fixed { border-left-color: #0891b2; }
        .summary-card.total { border-left-color: #dc2626; background: linear-gradient(135deg, #fef2f2 0%, #fff 100%); }

        /* Tooltip na kafelkach - pojawia sie od razu po najechaniu */
        .summary-card[data-tooltip] { position: relative; overflow: visible; }
        .summary-card[data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute; top: calc(100% + 10px); left: 50%; transform: translateX(-50%);
            background: #111827; color: #f9fafb; padding: 10px 14px; border-radius: 8px;
            font-size: 12.5px; font-weight: 400; line-height: 1.5; letter-spacing: normal; text-transform: none;
            white-space: normal; width: max-content; max-width: 280px; text-align: left;
            z-index: 1000; opacity: 0; visibility: hidden; pointer-events: none;
            box-shadow: 0 8px 20px rgba(0,0,0,0.22); transition: opacity 0.12s;
        }
        .summary-card[data-tooltip]::before {
            content: ""; position: absolute; top: calc(100% + 4px); left: 50%; transform: translateX(-50%);
            border: 6px solid transparent; border-bottom-color: #111827;
            z-index: 1001; opacity: 0; visibility: hidden; pointer-events: none; transition: opacity 0.12s;
        }
        .summary-card[data-tooltip]:hover::after, .summary-card[data-tooltip]:hover::before { opacity: 1; visibility: visible; }
        
        .summary-value {
            font-size: 24px;
            font-weight: 700;
            color: #333;
        }
        
        .summary-label {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
        }
        
        .summary-count {
            font-size: 13px;
            color: #999;
            margin-top: 8px;
        }
        
        /* Search Bar */
        .search-bar {
            padding: 20px;
            background: white;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .search-wrapper {
            position: relative;
            flex: 1;
            max-width: 500px;
        }
        .search-input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.2s;
        }
        .search-input:focus {
            outline: none;
            border-color: #ea580c;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
        }
        .search-input::placeholder {
            color: #999;
        }
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            pointer-events: none;
        }
        
        /* SPX FILTER SYSTEM */
        .spx-filter-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
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
        .spx-filter-group.fg-project  { flex: 2   1 0; }
        .spx-filter-group.fg-stage    { flex: 1.5 1 0; }
        .spx-filter-group.fg-type     { flex: 1.2 1 0; }
        .spx-filter-group.fg-category { flex: 1.3 1 0; }
        .spx-filter-group.fg-subcategory { flex: 1.3 1 0; }
        .spx-filter-group.fg-month    { flex: 1.2 1 0; }
        .spx-filter-group.fg-year     { flex: 0.7 1 0; }
        .spx-filter-group.fg-date     { flex: 1   1 0; }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"],
        .spx-filter-group input[type="text"] {
            padding: 0 8px;
            height: 38px;
            border: 1px solid #e0e0e0;
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
        @media (max-width: 1024px) { .spx-filter-bar { flex-wrap: wrap; } .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; } }
        @media (max-width: 768px) { .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; } .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; } .spx-filter-group select, .spx-filter-group input[type="date"] { height: 44px; font-size: 14px; } }
        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: white; border: 1px solid #e5e7eb; border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; white-space: nowrap; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: #667eea; color: #667eea; }
        .spx-quick-btn.active { background: #667eea; border-color: #667eea; color: white; font-weight: 600; }
        .btn-primary {
            background: linear-gradient(135deg, #ea580c 0%, #dc2626 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.4);
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        
        /* Table */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f8f9fa;
        }
        
        th {
            padding: 10px 14px !important;
            text-align: left;
            font-weight: 600;
            color: var(--text-muted) !important;
            border-bottom: 1px solid var(--border) !important;
            font-size: 11px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px;
            background: #f9fafb !important;
        }
        th.sortable {
            cursor: pointer;
            user-select: none;
            transition: all 0.2s;
        }
        th.sortable:hover {
            background: #e9ecef !important;
            color: #ea580c !important;
        }
        th a {
            color: inherit;
            text-decoration: none;
            display: block;
        }
        .sort-icon {
            opacity: 0.3;
            margin-left: 5px;
        }
        th.sortable:hover .sort-icon {
            opacity: 0.6;
        }
        th.sortable.asc .sort-icon::after,
        th.sortable.desc .sort-icon::after {
            opacity: 1;
            font-weight: bold;
        }
        th.sortable.asc .sort-icon::after {
            content: ' ↑';
        }
        th.sortable.desc .sort-icon::after {
            content: ' ↓';
        }
        
        td {
            padding: 10px 14px !important;
            border: 1px solid #000000 !important;
            font-size: 13px !important;
            vertical-align: middle;
        }
        th {
            border: 1px solid #000000 !important;
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
        
        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .type-invoice_cost, .type-invoice_cost_legacy, .type-invoice { background: #ffedd5; color: #9a3412; }
        .type-labor { background: #ede9fe; color: #5b21b6; }
        .type-cash { background: #d1fae5; color: #065f46; }
        .type-fixed { background: #cffafe; color: #155e75; }
        
        .amount {
            font-weight: 700;
            text-align: right;
        }
        .cell-ellipsis {
            display: inline-block;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: bottom;
        }

        /* Row action buttons */
        .action-buttons { display: flex; gap: 6px; justify-content: flex-start; flex-wrap: nowrap; }
        .action-btn { display: inline-flex; align-items: center; justify-content: center; padding: 4px 10px; height: 26px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: 600; transition: all 0.2s; border: 1px solid; background: white; white-space: nowrap; cursor: pointer; font-family: inherit; }
        .action-btn:hover { transform: translateY(-1px); }
        .action-btn-edit { color: #059669; border-color: #059669; }
        .action-btn-edit:hover { background: #059669; color: white; }
        .action-btn-more { color: #475569; border-color: #d1d5db; background: #f3f4f6; font-size: 13px; padding: 4px 8px; min-width: 28px; }
        .action-btn-more:hover { background: #e5e7eb; border-color: #9ca3af; transform: none; }

        /* Row dropdown */
        .row-dd-portal { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); min-width: 180px; padding: 6px 0; z-index: 9999; position: fixed; }
        .row-dd-portal a, .row-dd-portal button { display: block; width: 100%; text-align: left; padding: 8px 14px; font-size: 13px; color: #1f2937; text-decoration: none; background: none; border: none; cursor: pointer; font-family: inherit; white-space: nowrap; }
        .row-dd-portal a:hover, .row-dd-portal button:hover { background: #f1f5f9; }
        .row-dd-portal .row-dd-sep { height: 1px; background: #e2e8f0; margin: 4px 0; }
        .row-dd-portal .row-dd-danger { color: #dc2626 !important; }
        .row-dd-overlay { position: fixed; inset: 0; z-index: 9998; }

        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #999;
        }
        
        /* Header actions */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
        }
        .header-actions {
            display: flex;
            gap: 10px;
        }
        .btn-color-mode, .btn-group-mode {
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
        .btn-color-mode:hover, .btn-group-mode:hover {
            background: #f9fafb;
            border-color: var(--primary);
        }
        .btn-color-mode.active {
            background: linear-gradient(135deg, #fce7f3, #e0e7ff);
            border-color: #a78bfa;
        }
        .btn-color-mode svg, .btn-group-mode svg {
            width: 18px;
            height: 18px;
            transition: transform 0.2s;
        }
        /* Przycisk grupowania - aktywny gdy włączone */
        body.grouped-mode .btn-group-mode {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-color: var(--primary);
        }
        body.grouped-mode .btn-group-mode svg {
            stroke: white;
        }
        
        /* Accordion dla grupowania po dniach */
        .day-group {
            margin-bottom: 15px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border);
            background: white;
        }
        .day-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #f9fafb;
            cursor: pointer;
            transition: background 0.2s;
        }
        .day-header:hover {
            background: #f3f4f6;
        }
        .day-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .day-date {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-main);
        }
        .day-count {
            font-size: 12px;
            color: var(--text-muted);
            background: white;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .day-total {
            font-weight: 700;
            font-size: 15px;
            color: var(--primary);
        }
        .day-arrow {
            transition: transform 0.3s;
            color: var(--text-muted);
        }
        .day-group.collapsed .day-arrow {
            transform: rotate(-90deg);
        }
        .day-content {
            max-height: 2000px;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        .day-group.collapsed .day-content {
            max-height: 0;
        }
        .day-content table {
            margin: 0;
            border-radius: 0;
            box-shadow: none;
        }
        .day-content thead th {
            background: white !important;
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
<?php echo spxCompanyCostComboRenderAssets(); ?>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    Wszystkie koszty
                </div>
                <h1>Wszystkie koszty</h1>
                <p>Widok zbiorczy wszystkich kosztów z zaawansowanymi filtrami</p>
            </div>
            <div class="hero-actions">
                <button type="button" class="btn-hero-secondary" onclick="toggleColors()" title="Przełącz kolory">Kolory</button>
                <button type="button" class="btn-hero-secondary" onclick="toggleGrouping()" title="Grupuj po dniach">Grupuj</button>
                <button type="button" class="btn-hero-secondary" onclick="expandAll()">Rozwiń</button>
                <button type="button" class="btn-hero-secondary" onclick="collapseAll()">Zwiń</button>
            </div>
        </div>
        
        <!-- Summary -->
        <div class="summary-grid">
            <div class="summary-card invoice"
                 onclick="window.location.href='<?php echo url('dokumenty'); ?>'"
                 data-tooltip="Suma z faktur kosztowych przypisanych do projektów. Klik: przejdź do listy faktur.">
                <div class="summary-value"><?php echo formatMoney($summary['invoice_cost']['amount']); ?></div>
                <div class="summary-label">Faktury kosztowe</div>
                <div class="summary-count"><?php echo $summary['invoice_cost']['count']; ?> pozycji</div>
            </div>
            <div class="summary-card labor"
                 onclick="window.location.href='<?php echo url('finanse.koszty-pracownicze'); ?>'"
                 data-tooltip="Koszt pracy z zatwierdzonych godzin pracowników. Klik: przejdź do kosztów pracowniczych.">
                <div class="summary-value"><?php echo formatMoney($summary['labor']['amount']); ?></div>
                <div class="summary-label">Robocizna</div>
                <div class="summary-count"><?php echo $summary['labor']['count']; ?> pozycji</div>
            </div>
            <div class="summary-card cash"
                 onclick="window.location.href='<?php echo url('finanse.wydatki'); ?>'"
                 data-tooltip="Wydatki i paragony pracowników, które nie mają faktury. Klik: przejdź do listy wydatków.">
                <div class="summary-value"><?php echo formatMoney($summary['cash']['amount']); ?></div>
                <div class="summary-label">Wydatki / paragony</div>
                <div class="summary-count"><?php echo $summary['cash']['count']; ?> pozycji</div>
            </div>
            <div class="summary-card fixed"
                 onclick="window.location.href='<?php echo url('finanse.koszty-stale'); ?>'"
                 data-tooltip="Koszty stałe firmy: czynsze, subskrypcje, leasing. Klik: przejdź do listy kosztów stałych.">
                <div class="summary-value"><?php echo formatMoney($summary['fixed']['amount']); ?></div>
                <div class="summary-label">Koszty stałe</div>
                <div class="summary-count"><?php echo $summary['fixed']['count']; ?> pozycji</div>
            </div>
            <div class="summary-card total"
                 onclick="window.location.href='<?php echo url('finanse'); ?>'"
                 data-tooltip="Suma wszystkich kosztów z listy — faktury + robocizna + wydatki + koszty stałe.">
                <div class="summary-value" style="color: #dc2626;"><?php echo formatMoney($total); ?></div>
                <div class="summary-label">Razem</div>
                <div class="summary-count"><?php echo count($costs); ?> pozycji</div>
            </div>
        </div>
        
        <!-- Search Bar -->
        <div class="card">
            <form method="GET" action="" class="search-bar">
                <div class="search-wrapper">
                    <input type="text" 
                           name="search" 
                           class="search-input" 
                           placeholder="Szukaj po opisie, kontrahencie, projekcie, etapie..."
                           value="<?php echo e($search); ?>">
                    <span class="search-icon">🔍</span>
                </div>
                <button type="submit" class="btn-primary" style="white-space: nowrap;">Szukaj</button>
                <?php if ($search): ?>
                    <a href="?<?php echo http_build_query(array_diff_key($_GET, ['search' => ''])); ?>" 
                       class="btn-secondary" 
                       style="white-space: nowrap;">Wyczyść</a>
                <?php endif; ?>
                <!-- Zachowaj filtry -->
                <?php if ($filterProject > 0): ?><input type="hidden" name="project" value="<?php echo e($filterProject); ?>"><?php endif; ?>
                <?php if ($filterEtap > 0): ?><input type="hidden" name="etap" value="<?php echo e($filterEtap); ?>"><?php endif; ?>
                <?php if ($filterType !== 'all'): ?><input type="hidden" name="type" value="<?php echo e($filterType); ?>"><?php endif; ?>
                <?php if ($filterCategory !== ''): ?><input type="hidden" name="category" value="<?php echo e($filterCategory); ?>"><?php endif; ?>
                <?php if ($filterSubcategory !== ''): ?><input type="hidden" name="subcategory" value="<?php echo e($filterSubcategory); ?>"><?php endif; ?>
                <?php if ($dateFrom): ?><input type="hidden" name="date_from" value="<?php echo e($dateFrom); ?>"><?php endif; ?>
                <?php if ($dateTo): ?><input type="hidden" name="date_to" value="<?php echo e($dateTo); ?>"><?php endif; ?>
                <?php if ($sort !== 'date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
            </form>
        </div>
        
        <!-- Filters -->
        <div class="card" style="margin-bottom: 0; border-radius: 0; box-shadow: none;">
            <?php
            $kwYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            if ($kwYear < 2020 || $kwYear > 2030) $kwYear = (int)date('Y');
            $kwMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
            if ($kwMonth >= 1 && $kwMonth <= 12 && !isset($_GET['date_from'])) {
                $dateFrom = sprintf('%04d-%02d-01', $kwYear, $kwMonth);
                $dateTo   = date('Y-m-t', strtotime($dateFrom));
            }
            $kwMonthNames = [1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'];
            $kwYearRange = range((int)date('Y') - 3, (int)date('Y'));
            $kwActiveMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $kwYear, $m);
                if ($dateFrom === $mS && $dateTo === date('Y-m-t', strtotime($mS))) { $kwActiveMonth = $m; break; }
            }
            $kwToday = date('Y-m-d'); $kwWeekAgo = date('Y-m-d', strtotime('-7 days'));
            $kwMonthStart = date('Y-m-01'); $kwMonthEnd = date('Y-m-t');
            ?>
            <form method="GET" class="spx-filter-bar" id="kwFilterForm">
                <div class="spx-filter-group fg-project">
                    <label>Projekt</label>
                    <select name="project" id="project-select" onchange="this.form.submit()">
                        <option value="0">Wszystkie</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo $filterProject === (int)$p['id'] ? 'selected' : ''; ?>>
                                <?php echo e($p['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-stage">
                    <label>Etap</label>
                    <select name="etap">
                        <option value="0">Wszystkie</option>
                        <?php foreach ($etapy as $e): ?>
                            <option value="<?php echo $e['id']; ?>" <?php echo $filterEtap === (int)$e['id'] ? 'selected' : ''; ?>>
                                <?php echo e($e['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-type">
                    <label>Typ kosztu</label>
                    <select name="type">
                        <?php foreach ($costTypes as $key => $label): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $filterType === $key ? 'selected' : ''; ?>>
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-category" style="flex: 1.8 1 0;">
                    <label>Kategoria / podkategoria</label>
                    <?php
                    $kwCategoryFilterOptions = $companyCategoryLabels;
                    foreach ($categoryHints as $hint) {
                        if (!isset($kwCategoryFilterOptions[$hint])) {
                            $kwCategoryFilterOptions[$hint] = $hint;
                        }
                    }
                    echo spxCompanyCostComboRender([
                        'id_prefix' => 'kw-filter-company',
                        'category_name' => 'category',
                        'subcategory_name' => 'subcategory',
                        'category_select_id' => 'kw-category-select',
                        'subcategory_select_id' => 'kw-subcategory-select',
                        'selected_category' => $filterCategory,
                        'selected_subcategory' => $filterSubcategory,
                        'category_labels' => $kwCategoryFilterOptions,
                        'subcategories_by_category' => $companySubcategoriesByCategory,
                        'all_subcategory_hints' => $subcategoryHints,
                        'allow_empty_category' => true,
                        'empty_category_label' => 'Wszystkie kategorie',
                        'empty_subcategory_label' => 'Wszystkie podkategorie',
                        'placeholder_label' => 'Wszystkie kategorie i podkategorie',
                        'help_text' => '',
                    ]);
                    ?>
                </div>
                <div class="spx-filter-group fg-month">
                    <label>Miesiac</label>
                    <select name="month" id="kwSelectMonth" onchange="kwOnMonthYearChange()">
                        <option value="0" <?php echo $kwActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($kwMonthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($kwActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="kwSelectYear" onchange="kwOnMonthYearChange()">
                        <?php foreach ($kwYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($kwYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="kwInputDateFrom" value="<?php echo e($dateFrom); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="kwInputDateTo" value="<?php echo e($dateTo); ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                <?php
                $kwResetParams = [];
                if ($search !== '') $kwResetParams['search'] = $search;
                if ($sort !== 'date') $kwResetParams['sort'] = $sort;
                if ($order !== 'DESC') $kwResetParams['order'] = $order;
                $kwResetUrl = '?' . http_build_query($kwResetParams);
                ?>
                <a href="<?php echo $kwResetUrl; ?>" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center; flex-shrink: 0; white-space: nowrap;">Resetuj</a>
                <?php if ($search): ?><input type="hidden" name="search" value="<?php echo e($search); ?>"><?php endif; ?>
                <?php if ($sort !== 'date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
            </form>
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <?php
                    $kwBaseParams = [];
                    if ($filterProject > 0) $kwBaseParams['project'] = $filterProject;
                    if ($filterEtap > 0) $kwBaseParams['etap'] = $filterEtap;
                    if ($filterType !== 'all') $kwBaseParams['type'] = $filterType;
                    if ($filterCategory !== '') $kwBaseParams['category'] = $filterCategory;
                    if ($filterSubcategory !== '') $kwBaseParams['subcategory'] = $filterSubcategory;
                    if ($search !== '') $kwBaseParams['search'] = $search;
                    if ($sort !== 'date') $kwBaseParams['sort'] = $sort;
                    if ($order !== 'DESC') $kwBaseParams['order'] = $order;

                    $kwTodayUrl = '?' . http_build_query(array_merge($kwBaseParams, ['date_from' => $kwToday, 'date_to' => $kwToday]));
                    $kwWeekUrl = '?' . http_build_query(array_merge($kwBaseParams, ['date_from' => $kwWeekAgo, 'date_to' => $kwToday]));
                    $kwMonthUrl = '?' . http_build_query(array_merge($kwBaseParams, ['date_from' => $kwMonthStart, 'date_to' => $kwMonthEnd, 'year' => date('Y')]));
                    ?>
                    <a href="<?php echo $kwTodayUrl; ?>" class="spx-quick-btn <?php echo ($dateFrom === $kwToday && $dateTo === $kwToday) ? 'active' : ''; ?>">Dzis</a>
                    <a href="<?php echo $kwWeekUrl; ?>" class="spx-quick-btn <?php echo ($dateFrom === $kwWeekAgo && $dateTo === $kwToday) ? 'active' : ''; ?>">7 dni</a>
                    <a href="<?php echo $kwMonthUrl; ?>" class="spx-quick-btn <?php echo ($dateFrom === $kwMonthStart && $dateTo === $kwMonthEnd) ? 'active' : ''; ?>">Ten miesiac</a>
                </div>
            </div>
        </div>
        
        <!-- Table -->
        <div class="card">
            <?php if (isset($error_message)): ?>
                <div class="no-data" style="color: #dc2626; padding: 40px;">
                    <strong>Błąd:</strong> Wystąpił problem z pobraniem danych.
                    <br><br>
                    <small>Skontaktuj się z administratorem systemu.</small>
                </div>
            <?php elseif (empty($costs)): ?>
                <div class="no-data">
                    Brak kosztow spelniajacych kryteria filtrowania.
                </div>
            <?php else: ?>
                <!-- Tabela normalna (domyślnie ukryta) -->
                <table class="normal-view" style="display: none;">
                    <thead>
                        <tr>
                            <th class="sortable"><?php echo kwSortLink('date', 'Data', $sort, $order); ?></th>
                            <th class="sortable"><?php echo kwSortLink('type', 'Typ', $sort, $order); ?></th>
                            <th class="sortable"><?php echo kwSortLink('project', 'Projekt / Etap', $sort, $order); ?></th>
                            <th class="sortable"><?php echo kwSortLink('category', 'Kategoria', $sort, $order); ?></th>
                            <th class="sortable"><?php echo kwSortLink('subcategory', 'Podkategoria', $sort, $order); ?></th>
                            <th class="sortable"><?php echo kwSortLink('counterparty', 'Kontrahent', $sort, $order); ?></th>
                            <th class="sortable"><?php echo kwSortLink('description', 'Opis', $sort, $order); ?></th>
                            <th class="amount sortable"><?php echo kwSortLink('amount', 'Kwota', $sort, $order); ?></th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowIndex = 0;
                        foreach ($costs as $cost): 
                            $typeClass = 'type-' . $cost['ledger_source'];
                            $typeLabel = $costTypes[$cost['ledger_source']] ?? $cost['ledger_source'];
                            $companyCategory = kwCompanyCategoryDisplay($cost['company_category'] ?? null, $companyCategoryLabels);
                            $companySubcategory = kwDisplayOrDash($cost['company_subcategory'] ?? null);
                            $counterparty = kwCounterparty($cost);
                            $counterpartyShort = $counterparty === '-' ? '-' : kwTruncate($counterparty);
                            $descriptionText = kwDisplayOrDash($cost['description'] ?? null);
                            $colors = getRowColor($rowIndex++);
                            $kwViewUrl = null;
                            if ($cost['ledger_source'] === 'fixed' && !empty($cost['source_id'])) {
                                $kwViewUrl = url('finanse.koszty-stale.edit', ['id' => $cost['source_id']]);
                            } elseif ($cost['ledger_source'] === 'cash' && !empty($cost['source_id'])) {
                                $kwViewUrl = url('finanse.wydatki.edit', ['id' => $cost['source_id']]);
                            }
                        ?>
                            <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>">
                                <td><?php echo formatDate($cost['date']); ?></td>
                                <td>
                                    <span class="type-badge <?php echo $typeClass; ?>"><?php echo e($typeLabel); ?></span>
                                </td>
                                <td>
                                    <?php if ($cost['project_name']): ?>
                                        <span class="cell-ellipsis" title="<?php echo e($cost['project_name']); ?>"><?php echo e(kwTruncate($cost['project_name'], 44)); ?></span>
                                        <?php if ($cost['cost_node_name']): ?>
                                            <br><small style="color: #666;" title="<?php echo e($cost['cost_node_name']); ?>"><?php echo e(kwTruncate($cost['cost_node_name'], 44)); ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="cell-ellipsis" title="<?php echo e($companyCategory); ?>"><?php echo e($companyCategory); ?></span>
                                </td>
                                <td><span class="cell-ellipsis" title="<?php echo e($companySubcategory); ?>"><?php echo e($companySubcategory); ?></span></td>
                                <td><span class="cell-ellipsis" title="<?php echo e($counterparty); ?>"><?php echo e($counterpartyShort); ?></span></td>
                                <td><span class="cell-ellipsis" title="<?php echo e($descriptionText); ?>"><?php echo e(kwTruncate($descriptionText, 70)); ?></span></td>
                                <td class="amount"><?php echo formatMoney($cost['amount']); ?></td>
                                <td>
                                    <?php if ($kwViewUrl): ?>
                                    <div class="action-buttons">
                                        <a href="<?php echo $kwViewUrl; ?>" class="action-btn action-btn-edit">Zobacz</a>
                                        <button type="button" class="action-btn action-btn-more" onclick="openRowDropdown(event, this)">&#9660;</button>
                                        <template class="row-dd-tpl">
                                            <div class="row-dd-portal">
                                                <a href="<?php echo $kwViewUrl; ?>">Zobacz / Edytuj</a>
                                            </div>
                                        </template>
                                    </div>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Tabela zgrupowana po dniach (domyślnie widoczna) -->
                <div class="grouped-view" style="display: block;">
                    <?php foreach ($costsByDate as $date => $dayData): ?>
                        <div class="day-group">
                            <div class="day-header" onclick="toggleDay(this)">
                                <div class="day-info">
                                    <span class="day-date"><?php echo formatDate($date); ?></span>
                                    <span class="day-count"><?php echo $dayData['count']; ?> pozycji</span>
                                </div>
                                <div class="day-total"><?php echo formatMoney($dayData['total']); ?></div>
                                <svg class="day-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </div>
                            <div class="day-content">
                                <table>
                                    <thead>
                                        <tr>
                                            <th class="sortable"><?php echo kwSortLink('type', 'Typ', $sort, $order); ?></th>
                                            <th class="sortable"><?php echo kwSortLink('project', 'Projekt / Etap', $sort, $order); ?></th>
                                            <th class="sortable"><?php echo kwSortLink('category', 'Kategoria', $sort, $order); ?></th>
                                            <th class="sortable"><?php echo kwSortLink('subcategory', 'Podkategoria', $sort, $order); ?></th>
                                            <th class="sortable"><?php echo kwSortLink('counterparty', 'Kontrahent', $sort, $order); ?></th>
                                            <th class="sortable"><?php echo kwSortLink('description', 'Opis', $sort, $order); ?></th>
                                            <th class="amount sortable"><?php echo kwSortLink('amount', 'Kwota', $sort, $order); ?></th>
                                            <th>Akcje</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $rowIndex = 0;
                                        foreach ($dayData['costs'] as $cost): 
                                            $typeClass = 'type-' . $cost['ledger_source'];
                                            $typeLabel = $costTypes[$cost['ledger_source']] ?? $cost['ledger_source'];
                                            $companyCategory = kwCompanyCategoryDisplay($cost['company_category'] ?? null, $companyCategoryLabels);
                                            $companySubcategory = kwDisplayOrDash($cost['company_subcategory'] ?? null);
                                            $counterparty = kwCounterparty($cost);
                                            $counterpartyShort = $counterparty === '-' ? '-' : kwTruncate($counterparty);
                                            $descriptionText = kwDisplayOrDash($cost['description'] ?? null);
                                            $colors = getRowColor($rowIndex++);
                                            $kwViewUrl = null;
                                            if ($cost['ledger_source'] === 'fixed' && !empty($cost['source_id'])) {
                                                $kwViewUrl = url('finanse.koszty-stale.edit', ['id' => $cost['source_id']]);
                                            } elseif ($cost['ledger_source'] === 'cash' && !empty($cost['source_id'])) {
                                                $kwViewUrl = url('finanse.wydatki.edit', ['id' => $cost['source_id']]);
                                            }
                                        ?>
                                            <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>">
                                                <td>
                                                    <span class="type-badge <?php echo $typeClass; ?>"><?php echo e($typeLabel); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($cost['project_name']): ?>
                                                        <span class="cell-ellipsis" title="<?php echo e($cost['project_name']); ?>"><?php echo e(kwTruncate($cost['project_name'], 44)); ?></span>
                                                        <?php if ($cost['cost_node_name']): ?>
                                                            <br><small style="color: #666;" title="<?php echo e($cost['cost_node_name']); ?>"><?php echo e(kwTruncate($cost['cost_node_name'], 44)); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span style="color: #999;">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="cell-ellipsis" title="<?php echo e($companyCategory); ?>"><?php echo e($companyCategory); ?></span></td>
                                                <td><span class="cell-ellipsis" title="<?php echo e($companySubcategory); ?>"><?php echo e($companySubcategory); ?></span></td>
                                                <td><span class="cell-ellipsis" title="<?php echo e($counterparty); ?>"><?php echo e($counterpartyShort); ?></span></td>
                                                <td><span class="cell-ellipsis" title="<?php echo e($descriptionText); ?>"><?php echo e(kwTruncate($descriptionText, 70)); ?></span></td>
                                                <td class="amount"><?php echo formatMoney($cost['amount']); ?></td>
                                                <td>
                                                    <?php if ($kwViewUrl): ?>
                                                    <div class="action-buttons">
                                                        <a href="<?php echo $kwViewUrl; ?>" class="action-btn action-btn-edit">Zobacz</a>
                                                        <button type="button" class="action-btn action-btn-more" onclick="openRowDropdown(event, this)">&#9660;</button>
                                                        <template class="row-dd-tpl">
                                                            <div class="row-dd-portal">
                                                                <a href="<?php echo $kwViewUrl; ?>">Zobacz / Edytuj</a>
                                                            </div>
                                                        </template>
                                                    </div>
                                                    <?php else: ?>
                                                        <span style="color:var(--text-muted);font-size:12px;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Przełączanie kolorów
        function toggleColors() {
            document.body.classList.toggle('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) btn.classList.toggle('active');
            
            const isColored = !document.body.classList.contains('no-colors');
            localStorage.setItem('costsColorsEnabled', isColored ? '1' : '0');
            
            // Zastosuj/usuń kolory
            document.querySelectorAll('tbody tr[data-row-color]').forEach(tr => {
                if (isColored) {
                    tr.style.setProperty('--row-bg', tr.dataset.rowColor);
                    tr.style.setProperty('--row-border', tr.dataset.rowBorder);
                } else {
                    tr.style.removeProperty('--row-bg');
                    tr.style.removeProperty('--row-border');
                }
            });
        }
        
        // Przełączanie grupowania po dniach
        function toggleGrouping() {
            document.body.classList.toggle('grouped-mode');
            const isGrouped = document.body.classList.contains('grouped-mode');
            localStorage.setItem('costsGroupingEnabled', isGrouped ? '1' : '0');
            
            const normalView = document.querySelector('.normal-view');
            const groupedView = document.querySelector('.grouped-view');
            
            if (isGrouped) {
                normalView.style.display = 'none';
                groupedView.style.display = 'block';
            } else {
                normalView.style.display = 'table';
                groupedView.style.display = 'none';
            }
        }
        
        // Zwijanie/rozwijanie dnia
        function toggleDay(header) {
            header.closest('.day-group').classList.toggle('collapsed');
        }
        
        function expandAll() {
            document.querySelectorAll('.day-group').forEach(g => g.classList.remove('collapsed'));
        }
        
        function collapseAll() {
            document.querySelectorAll('.day-group').forEach(g => g.classList.add('collapsed'));
        }
        
        // Inicjalizacja - DOMYŚLNIE KOLORY I GRUPOWANIE WŁĄCZONE
        document.addEventListener('DOMContentLoaded', function() {
            // Kolory
            const colorsEnabled = localStorage.getItem('costsColorsEnabled');
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
            
            // Grupowanie - DOMYŚLNIE WŁĄCZONE
            const groupingEnabled = localStorage.getItem('costsGroupingEnabled');
            if (groupingEnabled === '0') {
                // Użytkownik ręcznie wyłączył - przełącz na widok normalny
                document.body.classList.remove('grouped-mode');
                document.querySelector('.normal-view').style.display = 'table';
                document.querySelector('.grouped-view').style.display = 'none';
            } else {
                // Domyślnie lub '1' - zostaw grupowanie włączone
                document.body.classList.add('grouped-mode');
                // Domyślnie zwiń wszystkie dni
                collapseAll();
            }
            
        });

        function kwOnMonthYearChange() {
            const month = parseInt(document.getElementById('kwSelectMonth').value);
            const year  = parseInt(document.getElementById('kwSelectYear').value);
            if (!month) return;
            const lastDay = new Date(year, month, 0).getDate();
            const pad = n => String(n).padStart(2, '0');
            document.getElementById('kwInputDateFrom').value = year + '-' + pad(month) + '-01';
            document.getElementById('kwInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
            document.getElementById('kwFilterForm').submit();
        }

        /* Row dropdown — portal pattern */
        var activePortal = null;
        var activePortalBtn = null;

        function openRowDropdown(e, btn) {
            e.preventDefault();
            e.stopPropagation();

            if (activePortal && activePortalBtn === btn) {
                closeRowDropdown();
                return;
            }
            closeRowDropdown();

            var tpl = btn.closest('.action-buttons').querySelector('.row-dd-tpl');
            if (!tpl) return;

            var clone = tpl.content.cloneNode(true);
            var portal = clone.firstElementChild;
            document.body.appendChild(portal);

            var rect = btn.getBoundingClientRect();
            portal.style.top = (rect.bottom + 4) + 'px';
            portal.style.left = Math.max(8, rect.right - 230) + 'px';

            requestAnimationFrame(function() {
                var pr = portal.getBoundingClientRect();
                if (pr.bottom > window.innerHeight - 8) {
                    portal.style.top = Math.max(8, rect.top - pr.height - 4) + 'px';
                }
                if (pr.right > window.innerWidth - 8) {
                    portal.style.left = Math.max(8, window.innerWidth - pr.width - 8) + 'px';
                }
            });

            activePortal = portal;
            activePortalBtn = btn;

            portal.addEventListener('click', function(ev) {
                var link = ev.target.closest('a');
                var button = ev.target.closest('button[type="submit"]');
                if (link || button) {
                    setTimeout(closeRowDropdown, 50);
                }
            });
        }

        function closeRowDropdown() {
            if (activePortal) {
                activePortal.remove();
                activePortal = null;
                activePortalBtn = null;
            }
        }

        document.addEventListener('click', function(e) {
            if (activePortal && !activePortal.contains(e.target) && !e.target.closest('.action-btn-more')) {
                closeRowDropdown();
            }
        });
    </script>
</body>
</html>
