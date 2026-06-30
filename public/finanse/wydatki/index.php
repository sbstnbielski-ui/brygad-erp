<?php
/**
 * BRYGAD ERP v3.0 - Wydatki Pracowników
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();

$pdo = getDbConnection();

// Filtry
$filterWorker = isset($_GET['worker']) ? (int)$_GET['worker'] : 0;
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$search       = $_GET['search'] ?? '';

// Data od/do — identyczny system jak w report.php
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';
if ($dateFrom !== '') $dateFrom = date('Y-m-d', strtotime($dateFrom));
if ($dateTo   !== '') $dateTo   = date('Y-m-d', strtotime($dateTo));
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) $dateFrom = $dateTo;

// Dropdown miesiąc/rok — identyczny jak w report.php
$filterYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($filterYear < 2020 || $filterYear > 2030) $filterYear = (int)date('Y');
$filterMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
if ($filterMonth >= 1 && $filterMonth <= 12 && !isset($_GET['date_from'])) {
    $dateFrom = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
    $dateTo   = date('Y-m-t', strtotime($dateFrom));
}

// Sortowanie
$sort = $_GET['sort'] ?? 'date';
$order = $_GET['order'] ?? 'DESC';
$currentListUrl = $_SERVER['REQUEST_URI'] ?? '';
if (!is_string($currentListUrl) || $currentListUrl === '' || $currentListUrl[0] !== '/') {
    $currentListUrl = url('finanse.wydatki');
}

// Walidacja sortowania
$allowed_sorts = ['date', 'worker_name', 'description', 'project_name', 'cost_node_name', 'amount', 'status'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'date';
}
$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

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

// ZABEZPIECZENIE: Worker widzi tylko swoje wydatki
if (!$isAdminUser && $currentWorkerId) {
    $where[] = "we.worker_id = ?";
    $params[] = $currentWorkerId;
}

if ($filterWorker > 0) {
    $where[] = "we.worker_id = ?";
    $params[] = $filterWorker;
}

if (!empty($filterStatus)) {
    $where[] = "we.status = ?";
    $params[] = $filterStatus;
}

if ($dateFrom !== '') { $where[] = "we.date >= ?"; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = "we.date <= ?"; $params[] = $dateTo; }

// Wyszukiwarka globalna
if (!empty($search)) {
    $where[] = "(we.description LIKE ? 
                OR CONCAT(w.first_name, ' ', w.last_name) LIKE ? 
                OR p.name LIKE ? 
                OR pcn.name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

// Pobierz wydatki
try {
    // Mapowanie kolumn sortowania
    $sortMap = [
        'date' => 'we.date',
        'worker_name' => 'w.last_name',
        'description' => 'we.description',
        'project_name' => 'p.name',
        'cost_node_name' => 'pcn.name',
        'amount' => 'we.amount',
        'status' => 'we.status'
    ];
    $orderBy = $sortMap[$sort] ?? 'we.date';
    
    $sql = "SELECT 
                we.*,
                w.first_name,
                w.last_name,
                CONCAT(w.first_name, ' ', w.last_name) as worker_name,
                p.name as project_name,
                pcn.name as cost_node_name,
                approver.login as approved_by_login
            FROM worker_expenses we
            INNER JOIN workers w ON we.worker_id = w.id
            LEFT JOIN projects p ON we.project_id = p.id
            LEFT JOIN project_cost_nodes pcn ON pcn.id = we.cost_node_id
            LEFT JOIN users approver ON we.approved_by_user_id = approver.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $orderBy $order, we.id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("Błąd pobierania wydatków: " . $e->getMessage(), 'ERROR');
    $expenses = [];
}

// Statystyki
$stats = [
    'total_amount' => 0,
    'pending_count' => 0,
    'pending_amount' => 0,
    'approved_count' => 0,
    'approved_amount' => 0,
    'reimbursed_count' => 0,
    'reimbursed_amount' => 0,
];

foreach ($expenses as $exp) {
    $stats['total_amount'] += $exp['amount'];
    if ($exp['status'] === 'pending') {
        $stats['pending_count']++;
        $stats['pending_amount'] += $exp['amount'];
    }
    if ($exp['status'] === 'approved') {
        $stats['approved_count']++;
        $stats['approved_amount'] += $exp['amount'];
    }
    if ($exp['status'] === 'reimbursed') {
        $stats['reimbursed_count']++;
        $stats['reimbursed_amount'] += $exp['amount'];
    }
}

// Nazwa wybranego pracownika (do nagłówka gdy filtr aktywny)
$filterWorkerName = '';
if ($filterWorker > 0) {
    foreach ($workers as $w) {
        if ($w['id'] == $filterWorker) {
            $filterWorkerName = $w['first_name'] . ' ' . $w['last_name'];
            break;
        }
    }
}

// Nazwy miesięcy — identyczne jak w report.php
$monthNames = [
    1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',
    7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'
];

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// ========================================
// FUNKCJA: Generowanie koloru pracownika
// ========================================
function getWorkerColor($workerId) {
    $goldenRatio = 0.618033988749895;
    $hue = fmod($workerId * $goldenRatio, 1.0) * 360;
    $saturation = 65;
    $lightness = 55;
    return [
        'hslLight' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.08)",
        'hslBorder' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.6)"
    ];
}

// Grupowanie po dniach dla widoku accordion
$expensesByDate = [];
foreach ($expenses as $exp) {
    $date = $exp['date'];
    if (!isset($expensesByDate[$date])) {
        $expensesByDate[$date] = [
            'expenses' => [],
            'total' => 0,
            'count' => 0
        ];
    }
    $expensesByDate[$date]['expenses'][] = $exp;
    $expensesByDate[$date]['total'] += $exp['amount'];
    $expensesByDate[$date]['count']++;
}
krsort($expensesByDate); // Sortuj daty malejąco (najnowsze najpierw)
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Wydatki Pracowników</title>
    <style>
        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --border:            #e5e7eb;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); padding-bottom: 40px; }
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
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; font-family: inherit; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        /* Layout — nowy dark sidebar */
        .dashboard-layout { display: flex; align-items: flex-start; gap: 0; }
        .dashboard-content { flex: 1; min-width: 0; padding-left: 16px; }
        .sidebar-wrapper.collapsed + .dashboard-content { padding-left: 0; }
        .sidebar-wrapper { position: relative; flex-shrink: 0; z-index: 10; }
        .sidebar-actions {
            background: linear-gradient(180deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
            border-radius: 12px 0 12px 12px; width: 240px;
            height: calc(100vh - 90px); position: sticky; top: 74px;
            overflow-y: auto; overflow-x: hidden;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(30, 58, 138, 0.2); color: white;
        }
        .sidebar-wrapper.collapsed .sidebar-actions { width: 0; }
        .toggle-sidebar-btn {
            position: absolute; top: 20px; right: -14px;
            width: 28px; height: 28px; background: white; border: 1px solid #e5e7eb; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 20; color: var(--primary-blue); transition: all 0.3s ease;
        }
        .toggle-sidebar-btn:hover { background: #f8fafc; transform: scale(1.1); }
        .sidebar-wrapper.collapsed .toggle-sidebar-btn { right: -32px; transform: rotate(180deg); }
        .sidebar-content-inner { width: 240px; }
        .sidebar-search { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-search input { width: 100%; padding: 8px 12px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: white; border-radius: 7px; font-size: 12px; }
        .sidebar-search input::placeholder { color: #93c5fd; }
        .sidebar-search input:focus { outline: none; background: rgba(255,255,255,0.15); border-color: #60a5fa; }
        .sidebar-actions-body { padding: 10px; }
        .sidebar-section { margin-bottom: 4px; border: 1px solid rgba(255,255,255,0.1); border-radius: 7px; overflow: hidden; }
        .sidebar-section-title { font-size: 11px; color: #93c5fd; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; list-style: none; margin: 0; padding: 8px 10px; cursor: pointer; user-select: none; display: flex; align-items: center; justify-content: space-between; background: rgba(255,255,255,0.04); }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after { content: '›'; font-size: 14px; color: rgba(255,255,255,0.4); transition: transform 0.2s ease; }
        .sidebar-section[open] .sidebar-section-title::after { transform: rotate(90deg); }
        .sidebar-section-links { padding: 4px 6px 6px; }
        .sidebar-actions a { display: block; padding: 7px 10px; margin-bottom: 2px; color: #e2e8f0; text-decoration: none; border-radius: 5px; transition: all 0.15s ease; font-size: 12px; font-weight: 500; }
        .sidebar-actions a:hover { background: rgba(255,255,255,0.12); color: white; padding-left: 14px; }

        /* Przyciski */
        .btn { display: inline-flex; align-items: center; padding: 9px 18px; border-radius: 6px; text-decoration: none; font-weight: 600; cursor: pointer; border: 1px solid transparent; font-size: 13px; transition: all 0.2s; font-family: inherit; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-secondary { background: white; color: #374151; border-color: var(--border); }
        .btn-secondary:hover { background: #f9fafb; border-color: #d1d5db; }
        .btn-small { padding: 6px 12px; font-size: 12px; }
        .btn-approve { background: #16a34a; color: white; }
        .btn-approve:hover { background: #15803d; }
        .btn-danger-small { background: #dc2626; color: white; padding: 4px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; transition: all 0.2s; }
        .btn-danger-small:hover { background: #b91c1c; }
        .delete-form { display: inline; margin: 0; }

        /* Statystyki */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; padding: 16px 20px; border-radius: 8px; border: 1px solid var(--border); }

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
        .stat-label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .stat-value { font-size: 22px; font-weight: 700; color: var(--primary); }
        .stat-value.danger { color: #dc2626; }
        .stat-value.success { color: #16a34a; }

        /* Baner filtra pracownika */
        .worker-filter-banner { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .worker-filter-banner span { font-size: 13px; color: #1d4ed8; font-weight: 500; }
        .worker-filter-banner a { font-size: 12px; color: #6b7280; text-decoration: none; }
        .worker-filter-banner a:hover { color: #374151; }

        /* Card */
        .card { background: white; border-radius: 8px; border: 1px solid var(--border); overflow: hidden; margin-bottom: 24px; }


        /* Pasek filtrów SPX — identyczny jak w report.php */
        .spx-filter-bar { padding: 12px 20px; background: white; border-bottom: 1px solid var(--border); display: flex; gap: 8px; align-items: flex-end; flex-wrap: nowrap; }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .spx-filter-group.fg-worker  { flex: 1.5 1 0; }
        .spx-filter-group.fg-status  { flex: 1   1 0; }
        .spx-filter-group.fg-month   { flex: 1.2 1 0; }
        .spx-filter-group.fg-year    { flex: 0.7 1 0; }
        .spx-filter-group.fg-date    { flex: 1   1 0; }
        .spx-filter-group.fg-search  { flex: 1.5 1 0; }
        .spx-filter-group label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .spx-filter-group select,
        .spx-filter-group input[type="date"],
        .spx-filter-group input[type="text"] { padding: 0 8px; height: 38px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; background: white; font-family: inherit; transition: border-color 0.15s; width: 100%; }
        .spx-filter-group select:focus,
        .spx-filter-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(102,126,234,0.12); }
        @media (max-width: 1024px) { .spx-filter-bar { flex-wrap: wrap; } .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; } }
        @media (max-width: 768px) { .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; } .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; } .spx-filter-group select, .spx-filter-group input[type="date"] { height: 44px; font-size: 14px; } }
        .spx-controls-bar { padding: 8px 20px; background: #f9fafb; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: white; border: 1px solid var(--border); border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; white-space: nowrap; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: var(--primary); color: var(--primary); }
        .spx-quick-btn.active { background: var(--primary); border-color: var(--primary); color: white; font-weight: 600; }

        /* Tabela */
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f9fafb; }
        th { padding: 10px 14px; text-align: left; font-weight: 600; color: var(--text-muted); border-bottom: 1px solid var(--border); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 10px 14px; border-bottom: 1px solid #f3f4f6; font-size: 13px; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }

        /* Kolory pracowników */
        body:not(.no-colors) tbody tr { background: var(--worker-bg, transparent); border-left: 4px solid var(--worker-color, var(--primary)); }
        body:not(.no-colors) tbody tr:hover { filter: brightness(0.97); }
        body.no-colors tbody tr:nth-child(odd) { background: #fff !important; border-left: 4px solid transparent; }
        body.no-colors tbody tr:nth-child(even) { background: #f8fafc !important; border-left: 4px solid transparent; }
        body.no-colors tbody tr:hover { background: #e0f2fe !important; }

        /* Odznaki */
        .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .badge-pending  { background: #fef9c3; color: #854d0e; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-rejected { background: #fee2e2; color: #991b1b; }
        .badge-reimbursed { background: #e0f2fe; color: #075985; }
        .badge-info     { background: #dbeafe; color: #1e40af; }
        .badge-secondary { background: #f3f4f6; color: #374151; }
        /* Alias dla starszego kodu */
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .status-pending   { background: #fef9c3; color: #854d0e; }
        .status-approved  { background: #dcfce7; color: #166534; }
        .status-rejected  { background: #fee2e2; color: #991b1b; }
        .status-reimbursed { background: #e0f2fe; color: #075985; }

        /* Akcje w wierszach */
        .action-links { display: flex; gap: 10px; align-items: center; }
        .action-links a { color: #667eea; text-decoration: none; font-size: 13px; font-weight: 500; }
        .action-links a:hover { text-decoration: underline; }
        .action-links a.approve { color: #16a34a; }
        .text-right { text-align: right !important; }

        /* Toggle kolorów */
        .btn-color-mode { width: 34px; height: 34px; border-radius: 6px; border: 1px solid var(--border); background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; padding: 0; }
        .btn-color-mode:hover { background: #f9fafb; border-color: var(--primary); }
        .btn-color-mode.active { background: linear-gradient(135deg, #fce7f3, #e0e7ff); border-color: #a78bfa; }
        .btn-color-mode svg { width: 16px; height: 16px; }
        /* Grupowanie */
        .btn-group-mode { width: 34px; height: 34px; border-radius: 6px; border: 1px solid var(--border); background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; padding: 0; }
        .btn-group-mode:hover { background: #f9fafb; border-color: var(--primary); }
        .btn-group-mode svg { width: 16px; height: 16px; }
        body.grouped-mode .btn-group-mode { background: linear-gradient(135deg, #667eea, #764ba2); border-color: var(--primary); }
        body.grouped-mode .btn-group-mode svg { stroke: white; }

        /* Accordion */
        .day-group { margin-bottom: 12px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); background: white; }
        .day-header { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; background: #f9fafb; cursor: pointer; transition: background 0.2s; }
        .day-header:hover { background: #f3f4f6; }
        .day-info { display: flex; align-items: center; gap: 12px; }
        .day-date { font-weight: 600; font-size: 13px; color: var(--text-main); }
        .day-count { font-size: 12px; color: var(--text-muted); background: white; padding: 2px 8px; border-radius: 10px; border: 1px solid var(--border); }
        .day-total { font-weight: 700; font-size: 14px; color: var(--primary); }
        .day-arrow { transition: transform 0.3s; color: var(--text-muted); }
        .day-group.collapsed .day-arrow { transform: rotate(-90deg); }
        .day-content { max-height: 3000px; overflow: hidden; transition: max-height 0.3s ease-out; }
        .day-group.collapsed .day-content { max-height: 0; }
        .day-content thead th { background: white !important; }

        /* Sortowanie */
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { background: #f3f4f6 !important; color: var(--primary) !important; }
        .sort-icon { opacity: 0.35; margin-left: 4px; }
        th.sortable:hover .sort-icon { opacity: 0.7; }
        th.sortable.asc .sort-icon::after { content: ' ↑'; opacity: 1; }
        th.sortable.desc .sort-icon::after { content: ' ↓'; opacity: 1; }

        /* Brak danych */
        .no-data { padding: 50px 20px; text-align: center; color: #9ca3af; font-size: 15px; }

        /* Footer */
        .footer { text-align: center; padding: 20px; color: #9ca3af; font-size: 13px; }

        /* Mobile */
        .mobile-expenses { display: none; }
        @media (max-width: 1024px) {
            .dashboard-layout { grid-template-columns: 1fr; }
            .sidebar-actions { position: static; }
        }
        @media (max-width: 768px) {
            .container { padding: 16px; }
            table { display: none; }
            .mobile-expenses { display: block; }
            .mobile-expense-item { background: white; border-radius: 8px; padding: 14px; margin-bottom: 10px; border-left: 4px solid #10b981; border: 1px solid var(--border); border-left: 4px solid #10b981; }
            .mobile-expense-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
            .mobile-expense-date { font-size: 15px; font-weight: 700; }
            .mobile-expense-amount { font-size: 17px; font-weight: 700; color: #dc2626; }
            .mobile-expense-body { display: flex; flex-direction: column; gap: 8px; }
            .mobile-expense-label { font-size: 10px; text-transform: uppercase; color: var(--text-muted); font-weight: 600; letter-spacing: 0.5px; }
            .mobile-expense-value { font-size: 14px; color: #333; }
            .mobile-expense-description { font-size: 13px; color: #4b5563; line-height: 1.5; }
            .mobile-expense-actions { margin-top: 10px; padding-top: 10px; border-top: 1px solid #f3f4f6; display: flex; gap: 8px; }
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
                    <a href="<?php echo url('hr.index'); ?>">Pracownicy</a> /
                    Wydatki
                </div>
                <h1>Wydatki pracowników<?php if ($filterWorkerName): ?> <span style="font-size:16px;font-weight:400;color:#94a3b8;">— <?php echo e($filterWorkerName); ?></span><?php endif; ?></h1>
                <p>Zarządzaj wydatkami gotówkowymi pracowników</p>
            </div>
            <div class="hero-actions">
                <button type="button" class="btn-hero-secondary" onclick="toggleColors()">Kolory</button>
                <button type="button" class="btn-hero-secondary" onclick="toggleGrouping()">Grupuj</button>
                <?php if ($isAdminUser): ?>
                    <a href="<?php echo url('finanse.koszty-projektowe.create'); ?>" class="btn-hero-secondary">+ Koszt projektowy</a>
                <?php endif; ?>
                <a href="<?php echo url('finanse.wydatki.create', ['return_url' => $currentListUrl]); ?>" class="btn-hero-primary">+ Dodaj wydatek</a>
            </div>
        </div>

        <div class="dashboard-layout">
            <!-- SIDEBAR LEWY — nowy dark sidebar, domyślnie zwinięty -->
            <div class="sidebar-wrapper collapsed" id="sidebarWrapper">
                <button class="toggle-sidebar-btn" onclick="toggleSidebar()" title="Zwiń/rozwiń panel">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M8 2L4 6L8 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="sidebar-actions">
                    <div class="sidebar-content-inner">
                        <div class="sidebar-search">
                            <input type="text" id="actionSearch" placeholder="Szukaj akcji..." autocomplete="off">
                        </div>
                        <div class="sidebar-actions-body">
                            <details class="sidebar-section" open>
                                <summary class="sidebar-section-title">Akcje</summary>
                                <div class="sidebar-section-links">
                                    <a href="<?php echo url('finanse.wydatki.create', ['return_url' => $currentListUrl]); ?>" data-keywords="wydatek dodaj">+ Dodaj wydatek</a>
                                    <?php if ($isAdminUser): ?>
                                        <a href="<?php echo url('finanse.koszty-projektowe.create'); ?>" data-keywords="koszt projektowy gotowka">+ Koszt projektowy</a>
                                    <?php endif; ?>
                                    <a href="<?php echo url('hr.worklog.create'); ?>" data-keywords="wpis czas pracy">+ Dodaj wpis czasu</a>
                                    <a href="<?php echo url('hr.workers.create'); ?>" data-keywords="pracownik dodaj nowy">+ Dodaj pracownika</a>
                                </div>
                            </details>
                            <details class="sidebar-section">
                                <summary class="sidebar-section-title">Nawigacja</summary>
                                <div class="sidebar-section-links">
                                    <a href="<?php echo url('hr.index'); ?>" data-keywords="pracownicy lista">Lista pracowników</a>
                                    <a href="<?php echo url('hr.worklog.index'); ?>" data-keywords="dziennik pracy">Dziennik pracy</a>
                                    <a href="<?php echo url('hr'); ?>?tab=history" data-keywords="historia operacje rozliczenia">Historia operacji</a>
                                    <a href="<?php echo url('hr.workers.report'); ?>" data-keywords="raport okresowy">Raport okresowy</a>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
            </div>
            <!-- END SIDEBAR -->

            <!-- Prawa kolumna z zawartością -->
            <div class="dashboard-content">

        <?php if ($filterWorker > 0 && $filterWorkerName): ?>
        <div class="worker-filter-banner">
            <span>Filtr: wydatki pracownika <strong><?php echo e($filterWorkerName); ?></strong></span>
            <a href="?<?php echo ($dateFrom ? 'date_from='.$dateFrom.'&date_to='.$dateTo : ''); ?><?php echo $filterStatus ? '&status='.e($filterStatus) : ''; ?>">Pokaż wszystkich</a>
        </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card" data-tooltip="Ile wydatków / paragonów widać po aktualnych filtrach.">
                <div class="stat-label">Pozycji</div>
                <div class="stat-value" style="color:#374151;"><?php echo count($expenses); ?></div>
            </div>
            <div class="stat-card" data-tooltip="Łączna wartość wydatków z listy.">
                <div class="stat-label">Suma wydatków</div>
                <div class="stat-value"><?php echo formatMoney($stats['total_amount']); ?></div>
            </div>
            <div class="stat-card" data-tooltip="Wydatki czekające na akceptację szefa. W nawiasie ich łączna wartość.">
                <div class="stat-label">Do zatwierdzenia</div>
                <div class="stat-value danger"><?php echo $stats['pending_count']; ?> <span style="font-size:13px;font-weight:400;">(<?php echo formatMoney($stats['pending_amount']); ?>)</span></div>
            </div>
            <div class="stat-card" data-tooltip="Wydatki zaakceptowane, ale jeszcze nie zwrócone pracownikowi. W nawiasie ich wartość.">
                <div class="stat-label">Zatwierdzone</div>
                <div class="stat-value success"><?php echo $stats['approved_count']; ?> <span style="font-size:13px;font-weight:400;">(<?php echo formatMoney($stats['approved_amount']); ?>)</span></div>
            </div>
            <div class="stat-card" data-tooltip="Wydatki już zwrócone pracownikowi lub rozliczone z zaliczki. W nawiasie ich wartość.">
                <div class="stat-label">Rozliczone</div>
                <div class="stat-value success"><?php echo $stats['reimbursed_count']; ?> <span style="font-size:13px;font-weight:400;">(<?php echo formatMoney($stats['reimbursed_amount']); ?>)</span></div>
            </div>
        </div>
        
        <div class="card">
            <?php
            // Oblicz pomocnicze wartości dla quick-btnów — jak w report.php
            $wToday      = date('Y-m-d');
            $wWeekAgo    = date('Y-m-d', strtotime('-7 days'));
            $wMonthStart = date('Y-m-01');
            $wMonthEnd   = date('Y-m-t');
            $wYearStart  = date('Y-01-01');

            // Wykryj aktywny miesiąc w dropdownie
            $wActiveMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $filterYear, $m);
                $mE = date('Y-m-t', strtotime($mS));
                if ($dateFrom === $mS && $dateTo === $mE) { $wActiveMonth = $m; break; }
            }

            $wIsDayActive   = ($dateFrom !== '' && $dateFrom === $wToday      && $dateTo === $wToday);
            $wIsWeekActive  = ($dateFrom !== '' && $dateFrom === $wWeekAgo    && $dateTo === $wToday);
            $wIsMonthActive = ($dateFrom !== '' && $dateFrom === $wMonthStart && $dateTo === $wMonthEnd);
            $wIsYearActive  = ($dateFrom !== '' && $dateFrom === $wYearStart  && $dateTo === $wToday);
            $wIsAllActive   = ($dateFrom === '' && $dateTo === '');

            $wYearRange = range((int)date('Y') - 3, (int)date('Y'));
            $wBaseQs    = ($filterWorker > 0 ? 'worker=' . $filterWorker : '')
                        . ($filterStatus    ? ($filterWorker > 0 ? '&' : '') . 'status=' . urlencode($filterStatus) : '');
            ?>
            <!-- Pasek filtrów — identyczny układ jak w report.php -->
            <form class="spx-filter-bar" method="get" id="expFilterForm">
                <?php if ($isAdminUser): ?>
                <div class="spx-filter-group fg-worker">
                    <label>Pracownik</label>
                    <select name="worker" onchange="this.form.submit()">
                        <option value="0">Wszyscy</option>
                        <?php foreach ($workers as $w): ?>
                            <option value="<?php echo $w['id']; ?>" <?php echo ($filterWorker == $w['id']) ? 'selected' : ''; ?>>
                                <?php echo e($w['last_name'] . ' ' . $w['first_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="spx-filter-group fg-status">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">Wszystkie</option>
                        <option value="pending"    <?php echo ($filterStatus === 'pending')    ? 'selected' : ''; ?>>Do zatwierdzenia</option>
                        <option value="approved"   <?php echo ($filterStatus === 'approved')   ? 'selected' : ''; ?>>Zatwierdzone</option>
                        <option value="rejected"   <?php echo ($filterStatus === 'rejected')   ? 'selected' : ''; ?>>Odrzucone</option>
                        <option value="reimbursed" <?php echo ($filterStatus === 'reimbursed') ? 'selected' : ''; ?>>Zwrócone</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-month">
                    <label>Miesiac</label>
                    <select name="month" id="wSelectMonth" onchange="wOnMonthYearChange()">
                        <option value="0" <?php echo $wActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($monthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($wActiveMonth === $mn) ? 'selected' : ''; ?>>
                                <?php echo $mName; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="wSelectYear" onchange="wOnMonthYearChange()">
                        <?php foreach ($wYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($filterYear == $yr) ? 'selected' : ''; ?>>
                                <?php echo $yr; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="wInputDateFrom" value="<?php echo e($dateFrom); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="wInputDateTo" value="<?php echo e($dateTo); ?>">
                </div>
                <div class="spx-filter-group fg-search">
                    <label>Szukaj</label>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="opis, projekt...">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                <?php if ($dateFrom !== '' || $dateTo !== '' || $filterWorker > 0 || $filterStatus || $search): ?>
                    <a href="?" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display:inline-flex; align-items:center; flex-shrink: 0; white-space: nowrap;">Resetuj</a>
                <?php endif; ?>
                <?php if ($sort !== 'date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
            </form>

            <!-- Quick-btns — identyczne jak w report.php -->
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <a href="?<?php echo $wBaseQs ? $wBaseQs.'&' : ''; ?>date_from=<?php echo $wToday; ?>&date_to=<?php echo $wToday; ?>"
                       class="spx-quick-btn <?php echo $wIsDayActive ? 'active' : ''; ?>">Dzis</a>
                    <a href="?<?php echo $wBaseQs ? $wBaseQs.'&' : ''; ?>date_from=<?php echo $wWeekAgo; ?>&date_to=<?php echo $wToday; ?>"
                       class="spx-quick-btn <?php echo $wIsWeekActive ? 'active' : ''; ?>">7 dni</a>
                    <a href="?<?php echo $wBaseQs ? $wBaseQs.'&' : ''; ?>date_from=<?php echo $wMonthStart; ?>&date_to=<?php echo $wMonthEnd; ?>"
                       class="spx-quick-btn <?php echo $wIsMonthActive ? 'active' : ''; ?>">Ten miesiac</a>
                    <a href="?<?php echo $wBaseQs ? $wBaseQs.'&' : ''; ?>date_from=<?php echo $wYearStart; ?>&date_to=<?php echo $wToday; ?>"
                       class="spx-quick-btn <?php echo $wIsYearActive ? 'active' : ''; ?>">Ten rok</a>
                    <a href="?<?php echo $wBaseQs ?: ''; ?>"
                       class="spx-quick-btn <?php echo $wIsAllActive ? 'active' : ''; ?>">Wszystko</a>
                </div>
                <div class="spx-controls-right">
                    <button class="spx-quick-btn" onclick="expandAll()">Rozwiń</button>
                    <button class="spx-quick-btn" onclick="collapseAll()">Zwiń</button>
                </div>
            </div>
            
            <?php if (empty($expenses)): ?>
                <div class="no-data">
                    Brak wydatków dla wybranych filtrów.
                </div>
            <?php else: ?>
                <!-- Tabela normalna (domyślnie ukryta) -->
                <table class="normal-view" style="display: none;">
                    <thead>
                        <tr>
                            <th data-sort="date" class="sortable">Data <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Pracownik <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Opis <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Projekt <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Etap <span class="sort-icon"></span></th>
                            <th>Typ</th>
                            <th data-sort="number" class="sortable text-right">Kwota <span class="sort-icon"></span></th>
                            <th>Paragon</th>
                            <th data-sort="string" class="sortable">Status <span class="sort-icon"></span></th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $exp): 
                            // Określ typ wydatku
                            $expenseTypeLabel = '';
                            $expenseTypeBadge = '';
                            switch ($exp['expense_type'] ?? 'cash_other') {
                                case 'cash_purchase':
                                    $expenseTypeLabel = 'Zakup';
                                    $expenseTypeBadge = 'badge-info';
                                    break;
                                case 'cash_other':
                                default:
                                    $expenseTypeLabel = 'Inne';
                                    $expenseTypeBadge = 'badge-secondary';
                                    break;
                            }
                            
                            // Generuj kolor pracownika
                            $workerColor = getWorkerColor($exp['worker_id']);
                            $rowStyle = "--worker-color: {$workerColor['hslBorder']}; --worker-bg: {$workerColor['hslLight']};";
                        ?>
                            <tr style="<?php echo $rowStyle; ?>" data-worker-id="<?php echo $exp['worker_id']; ?>">
                                <td style="font-weight: 600;"><?php echo formatDate($exp['date']); ?></td>
                                <td><?php echo e($exp['first_name'] . ' ' . $exp['last_name']); ?></td>
                                <td>
                                    <?php 
                                    $expDescription = trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', $exp['description']));
                                    echo e($expDescription);
                                    ?>
                                    <?php if (!empty($exp['paid_by_employee']) || strpos($exp['description'], '[PAID_BY_EMPLOYEE]') !== false): ?>
                                        <br><span class="status-badge" style="background: #17a2b8; color: white; font-size: 10px; margin-top: 2px;">ZAPŁACONE PRZEZ PRACOWNIKA</span>
                                    <?php endif; ?>

                                </td>
                                <td style="color: #666;">
                                    <?php echo $exp['project_name'] ? e($exp['project_name']) : '-'; ?>
                                </td>
                                <td style="color: #666; font-size: 13px;">
                                    <?php echo $exp['cost_node_name'] ? e($exp['cost_node_name']) : '-'; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $expenseTypeBadge; ?>"><?php echo $expenseTypeLabel; ?></span>
                                </td>
                                <td style="font-weight: 600; color: #dc3545;">
                                    <?php echo formatMoney($exp['amount']); ?>
                                </td>
                                <td>
                                    <?php if ($exp['receipt_path']): ?>
                                        <a href="<?php echo e($exp['receipt_path']); ?>" target="_blank" style="color: #ea580c;">
                                            Zobacz
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #999;">Brak</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($exp['status'] === 'pending'): ?>
                                        <span class="status-badge status-pending">Do zatwierdzenia</span>
                                    <?php elseif ($exp['status'] === 'approved'): ?>
                                        <span class="status-badge status-approved">Zatwierdzone</span>
                                    <?php elseif ($exp['status'] === 'rejected'): ?>
                                        <span class="status-badge status-rejected">Odrzucone</span>
                                    <?php else: ?>
                                        <span class="status-badge status-reimbursed">Zwrócone</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-links">
                                        <a href="<?php echo url('finanse.wydatki.edit', ['id' => $exp['id'], 'return_url' => $currentListUrl]); ?>">Edytuj</a>
                                        <?php if ($isAdminUser): ?>
                                            <?php if ($exp['status'] === 'pending'): ?>
                                                <a href="<?php echo url('finanse.wydatki.approve', ['id' => $exp['id'], 'return_url' => $currentListUrl]); ?>" style="color: #28a745;">Zatwierdź</a>
                                            <?php endif; ?>
                                            <form method="POST" action="<?php echo url('finanse.wydatki.delete'); ?>" class="delete-form" 
                                                  onsubmit="return confirm('Czy na pewno chcesz usunąć ten wydatek?');">
                                                <input type="hidden" name="id" value="<?php echo $exp['id']; ?>">
                                                <input type="hidden" name="return_url" value="<?php echo e($currentListUrl); ?>">
                                                <button type="submit" class="btn-danger-small">Usuń</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Tabela zgrupowana po dniach (domyślnie widoczna) -->
                <div class="grouped-view" style="display: block;">
                    <?php foreach ($expensesByDate as $date => $dayData): ?>
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
                                            <th>Pracownik</th>
                                            <th>Opis</th>
                                            <th>Projekt</th>
                                            <th>Etap</th>
                                            <th>Typ</th>
                                            <th class="text-right">Kwota</th>
                                            <th>Paragon</th>
                                            <th>Status</th>
                                            <th>Akcje</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dayData['expenses'] as $exp): 
                                            $expenseTypeLabel = '';
                                            $expenseTypeBadge = '';
                                            switch ($exp['expense_type'] ?? 'cash_other') {
                                                case 'cash_purchase':
                                                    $expenseTypeLabel = 'Zakup';
                                                    $expenseTypeBadge = 'badge-info';
                                                    break;
                                                case 'cash_other':
                                                default:
                                                    $expenseTypeLabel = 'Inne';
                                                    $expenseTypeBadge = 'badge-secondary';
                                                    break;
                                            }
                                            $workerColor = getWorkerColor($exp['worker_id']);
                                            $rowStyle = "--worker-color: {$workerColor['hslBorder']}; --worker-bg: {$workerColor['hslLight']};";
                                        ?>
                                            <tr style="<?php echo $rowStyle; ?>" data-worker-id="<?php echo $exp['worker_id']; ?>">
                                                <td><?php echo e($exp['first_name'] . ' ' . $exp['last_name']); ?></td>
                                                <td>
                                                    <?php 
                                                    $expDescription = trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', $exp['description']));
                                                    echo e($expDescription);
                                                    ?>
                                                    <?php if (!empty($exp['paid_by_employee']) || strpos($exp['description'], '[PAID_BY_EMPLOYEE]') !== false): ?>
                                                        <br><span class="status-badge" style="background: #17a2b8; color: white; font-size: 10px; margin-top: 2px;">ZAPŁACONE PRZEZ PRACOWNIKA</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="color: #666;">
                                                    <?php echo $exp['project_name'] ? e($exp['project_name']) : '-'; ?>
                                                </td>
                                                <td style="color: #666; font-size: 13px;">
                                                    <?php echo $exp['cost_node_name'] ? e($exp['cost_node_name']) : '-'; ?>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $expenseTypeBadge; ?>"><?php echo $expenseTypeLabel; ?></span>
                                                </td>
                                                <td style="font-weight: 600; color: #dc3545;">
                                                    <?php echo formatMoney($exp['amount']); ?>
                                                </td>
                                                <td>
                                                    <?php if ($exp['receipt_path']): ?>
                                                        <a href="<?php echo e($exp['receipt_path']); ?>" target="_blank" style="color: #ea580c;">
                                                            Zobacz
                                                        </a>
                                                    <?php else: ?>
                                                        <span style="color: #999;">Brak</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($exp['status'] === 'pending'): ?>
                                                        <span class="status-badge status-pending">Do zatwierdzenia</span>
                                                    <?php elseif ($exp['status'] === 'approved'): ?>
                                                        <span class="status-badge status-approved">Zatwierdzone</span>
                                                    <?php elseif ($exp['status'] === 'rejected'): ?>
                                                        <span class="status-badge status-rejected">Odrzucone</span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-reimbursed">Zwrócone</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-links">
                                                        <a href="<?php echo url('finanse.wydatki.edit', ['id' => $exp['id'], 'return_url' => $currentListUrl]); ?>">Edytuj</a>
                                                        <?php if ($isAdminUser): ?>
                                                            <?php if ($exp['status'] === 'pending'): ?>
                                                                <a href="<?php echo url('finanse.wydatki.approve', ['id' => $exp['id'], 'return_url' => $currentListUrl]); ?>" style="color: #28a745;">Zatwierdź</a>
                                                            <?php endif; ?>
                                                            <form method="POST" action="<?php echo url('finanse.wydatki.delete'); ?>" class="delete-form" 
                                                                  onsubmit="return confirm('Czy na pewno chcesz usunąć ten wydatek?');">
                                                                <input type="hidden" name="id" value="<?php echo $exp['id']; ?>">
                                                                <input type="hidden" name="return_url" value="<?php echo e($currentListUrl); ?>">
                                                                <button type="submit" class="btn-danger-small">Usuń</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Mobile: Karty -->
                <div class="mobile-expenses">
                    <?php foreach ($expenses as $exp): 
                        $expenseTypeLabel = '';
                        $expenseTypeBadge = '';
                        switch ($exp['expense_type'] ?? 'cash_other') {
                            case 'cash_purchase':
                                $expenseTypeLabel = 'Zakup';
                                $expenseTypeBadge = 'badge-info';
                                break;
                            case 'cash_other':
                            default:
                                $expenseTypeLabel = 'Inne';
                                $expenseTypeBadge = 'badge-secondary';
                                break;
                        }
                    ?>
                        <div class="mobile-expense-item">
                            <div class="mobile-expense-header">
                                <div>
                                    <div class="mobile-expense-date"><?php echo formatDate($exp['date']); ?></div>
                                    <?php if ($isAdminUser): ?>
                                    <div style="font-size: 14px; color: #6b7280; margin-top: 4px;">
                                        <?php echo e($exp['first_name'] . ' ' . $exp['last_name']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mobile-expense-amount"><?php echo formatMoney($exp['amount']); ?></div>
                            </div>
                            
                            <div class="mobile-expense-body">
                                <div class="mobile-expense-field">
                                    <div class="mobile-expense-label">Opis</div>
                                    <div class="mobile-expense-description">
                                        <?php 
                                        $expDescriptionMobile = trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', $exp['description']));
                                        echo e($expDescriptionMobile);
                                        ?>
                                        <?php if (!empty($exp['paid_by_employee']) || strpos($exp['description'], '[PAID_BY_EMPLOYEE]') !== false): ?>
                                            <br><span class="status-badge" style="background: #17a2b8; color: white; font-size: 10px; margin-top: 4px; display: inline-block;">ZAPŁACONE PRZEZ PRACOWNIKA</span>
                                        <?php endif; ?>

                                    </div>
                                </div>
                                
                                <?php if ($exp['project_name']): ?>
                                <div class="mobile-expense-field">
                                    <div class="mobile-expense-label">Projekt</div>
                                    <div class="mobile-expense-value"><?php echo e($exp['project_name']); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($exp['cost_node_name']): ?>
                                <div class="mobile-expense-field">
                                    <div class="mobile-expense-label">Etap</div>
                                    <div class="mobile-expense-value"><?php echo e($exp['cost_node_name']); ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mobile-expense-field">
                                    <div class="mobile-expense-label">Typ</div>
                                    <div>
                                        <span class="status-badge <?php echo $expenseTypeBadge; ?>"><?php echo $expenseTypeLabel; ?></span>
                                    </div>
                                </div>
                                
                                <div class="mobile-expense-field">
                                    <div class="mobile-expense-label">Status</div>
                                    <div>
                                        <?php if ($exp['status'] === 'pending'): ?>
                                            <span class="status-badge status-pending">Do zatwierdzenia</span>
                                        <?php elseif ($exp['status'] === 'approved'): ?>
                                            <span class="status-badge status-approved">Zatwierdzone</span>
                                        <?php elseif ($exp['status'] === 'rejected'): ?>
                                            <span class="status-badge status-rejected">Odrzucone</span>
                                        <?php else: ?>
                                            <span class="status-badge status-reimbursed">Zwrócone</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if ($exp['receipt_path']): ?>
                                <div class="mobile-expense-field">
                                    <div class="mobile-expense-label">Paragon</div>
                                    <div>
                                        <a href="<?php echo e($exp['receipt_path']); ?>" target="_blank" style="color: #ea580c; font-weight: 600;">
                                            Zobacz paragon
                                        </a>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($isAdminUser && $exp['status'] === 'pending'): ?>
                            <div class="mobile-expense-actions">
                                <a href="<?php echo url('finanse.wydatki.approve', ['id' => $exp['id'], 'return_url' => $currentListUrl]); ?>" 
                                   class="btn btn-small btn-approve" style="flex: 1;">
                                    Zatwierdź
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div><!-- /.card -->
        </div><!-- /.dashboard-content -->
        </div><!-- /.dashboard-layout -->
    </div><!-- /.container -->
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
    // Dropdown miesiąc+rok — identyczny jak rOnMonthYearChange w report.php
    function wOnMonthYearChange() {
        const month = parseInt(document.getElementById('wSelectMonth').value);
        const year  = parseInt(document.getElementById('wSelectYear').value);
        if (!month) return;
        const lastDay = new Date(year, month, 0).getDate();
        const pad = n => String(n).padStart(2, '0');
        document.getElementById('wInputDateFrom').value = year + '-' + pad(month) + '-01';
        document.getElementById('wInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
        document.getElementById('expFilterForm').submit();
    }

    function toggleColors() {
        document.body.classList.toggle('no-colors');
        const btn = document.querySelector('.btn-color-mode');
        btn.classList.toggle('active');
        const colorsEnabled = !document.body.classList.contains('no-colors');
        localStorage.setItem('worklog_colors', colorsEnabled ? '1' : '0');
    }
    
    function toggleGrouping() {
        document.body.classList.toggle('grouped-mode');
        const isGrouped = document.body.classList.contains('grouped-mode');
        localStorage.setItem('wydatkiGroupingEnabled', isGrouped ? '1' : '0');
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
    
    function toggleDay(header) {
        header.closest('.day-group').classList.toggle('collapsed');
    }
    
    function expandAll() {
        document.querySelectorAll('.day-group').forEach(g => g.classList.remove('collapsed'));
    }
    
    function collapseAll() {
        document.querySelectorAll('.day-group').forEach(g => g.classList.add('collapsed'));
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const colorsEnabled = localStorage.getItem('worklog_colors');
        if (colorsEnabled === '0') {
            document.body.classList.add('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) btn.classList.remove('active');
        }
        
        const groupingEnabled = localStorage.getItem('wydatkiGroupingEnabled');
        if (groupingEnabled === '0') {
            document.body.classList.remove('grouped-mode');
            document.querySelector('.normal-view').style.display = 'table';
            document.querySelector('.grouped-view').style.display = 'none';
        } else {
            document.body.classList.add('grouped-mode');
            // Domyślnie zwiń wszystkie dni
            collapseAll();
        }
        
        const table = document.querySelector('.normal-view');
        if (!table) return;
        const headers = table.querySelectorAll('th.sortable');
        const tbody = table.querySelector('tbody');
        headers.forEach(function(header, columnIndex) {
            header.addEventListener('click', function() {
                const sortType = this.dataset.sort;
                const isAsc = this.classList.contains('asc');
                headers.forEach(function(h) { h.classList.remove('asc', 'desc'); });
                this.classList.add(isAsc ? 'desc' : 'asc');
                const direction = isAsc ? -1 : 1;
                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort(function(a, b) {
                    let aVal = a.cells[columnIndex].textContent.trim();
                    let bVal = b.cells[columnIndex].textContent.trim();
                    if (sortType === 'number') {
                        aVal = parseFloat(aVal.replace(/[^\d,.\-]/g, '').replace(',', '.')) || 0;
                        bVal = parseFloat(bVal.replace(/[^\d,.\-]/g, '').replace(',', '.')) || 0;
                        return (aVal - bVal) * direction;
                    } else if (sortType === 'date') {
                        const parseDate = function(str) {
                            if (!str || str === '–' || str === '-') return 0;
                            const parts = str.split('.');
                            if (parts.length === 3) return new Date(parts[2], parts[1] - 1, parts[0]).getTime();
                            return 0;
                        };
                        return (parseDate(aVal) - parseDate(bVal)) * direction;
                    } else {
                        aVal = aVal.toLowerCase();
                        bVal = bVal.toLowerCase();
                        if (aVal < bVal) return -1 * direction;
                        if (aVal > bVal) return 1 * direction;
                        return 0;
                    }
                });
                rows.forEach(function(row) { tbody.appendChild(row); });
            });
        });
    });
        // Sidebar toggle
        function toggleSidebar() {
            const wrapper = document.getElementById('sidebarWrapper');
            if (!wrapper) return;
            wrapper.classList.toggle('collapsed');
            localStorage.setItem('sprutex_wydatki_sidebar_collapsed', wrapper.classList.contains('collapsed') ? 'true' : 'false');
        }
        (function() {
            const wrapper = document.getElementById('sidebarWrapper');
            if (wrapper) {
                const stored = localStorage.getItem('sprutex_wydatki_sidebar_collapsed');
                wrapper.classList.toggle('collapsed', stored === null ? true : stored === 'true');
            }
            const searchInput = document.getElementById('actionSearch');
            if (!searchInput) return;
            const sections = document.querySelectorAll('.sidebar-section');
            const links = document.querySelectorAll('.sidebar-actions a[data-keywords]');
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                if (query === '') { sections.forEach(s => { s.style.display = ''; }); links.forEach(l => l.style.display = ''); return; }
                const visibleSections = new Set();
                links.forEach(link => {
                    const match = link.textContent.toLowerCase().includes(query) || (link.getAttribute('data-keywords') || '').includes(query);
                    link.style.display = match ? '' : 'none';
                    if (match) { const s = link.closest('.sidebar-section'); if (s) { if (!s.open) { s.open = true; s._auto = true; } visibleSections.add(s); } }
                });
                sections.forEach(s => { s.style.display = visibleSections.has(s) ? '' : 'none'; });
            });
        })();
    </script>
</body>
</html>
