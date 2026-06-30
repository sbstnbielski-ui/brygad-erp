<?php
/**
 * BRYGAD ERP - Koszty Pracownicze
 * Wynagrodzenia, nadgodziny, zaliczki, zwroty kosztow
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/absence_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Filtry
$filterWorker   = isset($_GET['worker']) ? (int)$_GET['worker'] : 0;
$filterType     = $_GET['type'] ?? 'all';
$filterProject  = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$filterCostNode = isset($_GET['cost_node_id']) ? (int)$_GET['cost_node_id'] : 0;
$search         = $_GET['search'] ?? '';

// Daty — nowy system (jak w report.php): miesiac/rok skrót + od/do bezpośrednio
$cpFilterYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($cpFilterYear < 2020 || $cpFilterYear > 2030) $cpFilterYear = (int)date('Y');
$cpFilterMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;

$cpDateFrom = $_GET['date_from'] ?? '';
$cpDateTo   = $_GET['date_to']   ?? '';

// Jeśli brak bezpośrednich dat ale wybrany miesiąc — ustaw daty z miesiąca
if ($cpFilterMonth >= 1 && $cpFilterMonth <= 12 && $cpDateFrom === '') {
    $cpDateFrom = sprintf('%04d-%02d-01', $cpFilterYear, $cpFilterMonth);
    $cpDateTo   = date('Y-m-t', strtotime($cpDateFrom));
}

// Domyślnie (brak filtra dat) — bieżący miesiąc
if ($cpDateFrom === '' && $cpDateTo === '' && !isset($_GET['all'])) {
    $cpDateFrom = date('Y-m-01');
    $cpDateTo   = date('Y-m-t');
}

// Walidacja
if ($cpDateFrom !== '') {
    $cpDateFrom = date('Y-m-d', strtotime($cpDateFrom));
}
if ($cpDateTo !== '') {
    $cpDateTo = date('Y-m-d', strtotime($cpDateTo));
    if ($cpDateTo > date('Y-m-d')) $cpDateTo = date('Y-m-d');
}
if ($cpDateFrom !== '' && $cpDateTo !== '' && $cpDateFrom > $cpDateTo) {
    $cpDateFrom = $cpDateTo;
}

// Zachowaj kompatybilność — period nadal wyprowadzany dla quick-buttons
$filterPeriod = ($cpDateFrom !== '') ? substr($cpDateFrom, 0, 7) : date('Y-m');

// Sortowanie
$sort = $_GET['sort'] ?? 'date';
$order = $_GET['order'] ?? 'DESC';

// Walidacja sortowania
$allowed_sorts = ['date', 'worker_name', 'description', 'project_name', 'cost_node_name', 'cost_type', 'amount', 'status'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'date';
}
$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Pobierz pracownikow
$workers = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name")->fetchAll();

// Pobierz projekty
$projects = $pdo->query("SELECT id, name FROM projects WHERE archived_at IS NULL ORDER BY name ASC")->fetchAll();

// Pobierz etapy (cost nodes) - jeśli wybrany projekt
$costNodes = [];
if ($filterProject > 0) {
    $stmt = $pdo->prepare("SELECT id, name FROM project_cost_nodes WHERE project_id = :project_id AND is_active = 1 ORDER BY sort_order, name");
    $stmt->execute([':project_id' => $filterProject]);
    $costNodes = $stmt->fetchAll();
}

// Pobierz koszty pracownicze (z work_logs i settlements)
$laborCosts = [];
$settlementCosts = [];

try {
    // Robocizna z work_logs
    if ($filterType === 'all' || $filterType === 'labor') {
        $whereLabor = ["wl.status = 'approved'"];
        $paramsLabor = [];
        
        if ($filterWorker > 0) {
            $whereLabor[] = "wl.worker_id = :worker_id";
            $paramsLabor[':worker_id'] = $filterWorker;
        }
        
        if ($filterProject > 0) {
            $whereLabor[] = "wl.project_id = :project_id";
            $paramsLabor[':project_id'] = $filterProject;
        }
        
        if ($filterCostNode > 0) {
            $whereLabor[] = "wl.cost_node_id = :cost_node_id";
            $paramsLabor[':cost_node_id'] = $filterCostNode;
        }
        
        // Wyszukiwarka globalna
        if (!empty($search)) {
            $whereLabor[] = "(wl.description LIKE :search 
                            OR p.name LIKE :search 
                            OR pcn.name LIKE :search 
                            OR CONCAT(w.first_name, ' ', w.last_name) LIKE :search)";
            $paramsLabor[':search'] = '%' . $search . '%';
        }
        
        if ($cpDateFrom !== '') { $whereLabor[] = "wl.date >= :date_from"; $paramsLabor[':date_from'] = $cpDateFrom; }
        if ($cpDateTo   !== '') { $whereLabor[] = "wl.date <= :date_to";   $paramsLabor[':date_to']   = $cpDateTo; }

        $sqlLabor = "
            SELECT 
                'labor' as cost_type,
                wl.id,
                wl.date,
                wl.work_type,
                wl.hours,
                wl.overtime_hours,
                wl.absence_days,
                wl.final_cost as amount,
                wl.description,
                wl.status,
                w.id as worker_id,
                w.first_name,
                w.last_name,
                CONCAT(w.first_name, ' ', w.last_name) as worker_name,
                p.name as project_name,
                pcn.name as cost_node_name,
                DATE_FORMAT(wl.date, '%Y-%m') as period
            FROM work_logs wl
            JOIN workers w ON wl.worker_id = w.id
            LEFT JOIN projects p ON wl.project_id = p.id
            LEFT JOIN project_cost_nodes pcn ON wl.cost_node_id = pcn.id
            WHERE " . implode(' AND ', $whereLabor) . "
        ";
        
        $stmt = $pdo->prepare($sqlLabor);
        $stmt->execute($paramsLabor);
        $laborCosts = $stmt->fetchAll();
    }
    
    // Rozliczenia (zaliczki, wyplaty)
    if ($filterType !== 'labor' && $filterProject === 0 && $filterCostNode === 0) {
        $whereSett = ["1=1"];
        $paramsSett = [];
        
        if ($filterWorker > 0) {
            $whereSett[] = "s.worker_id = :worker_id";
            $paramsSett[':worker_id'] = $filterWorker;
        }
        
        if ($filterType !== 'all') {
            $whereSett[] = "s.type = :type";
            $paramsSett[':type'] = $filterType;
        }
        
        // Wyszukiwarka globalna dla rozliczeń
        if (!empty($search)) {
            $whereSett[] = "(s.description LIKE :search 
                            OR CONCAT(w.first_name, ' ', w.last_name) LIKE :search)";
            $paramsSett[':search'] = '%' . $search . '%';
        }
        
        if ($cpDateFrom !== '') { $whereSett[] = "s.date >= :date_from"; $paramsSett[':date_from'] = $cpDateFrom; }
        if ($cpDateTo   !== '') { $whereSett[] = "s.date <= :date_to";   $paramsSett[':date_to']   = $cpDateTo; }

        $sqlSett = "
            SELECT 
                'settlement' as cost_type,
                s.id,
                s.date,
                0 as hours,
                0 as overtime_hours,
                s.amount,
                s.description,
                s.type as settlement_type,
                'approved' as status,
                w.id as worker_id,
                w.first_name,
                w.last_name,
                CONCAT(w.first_name, ' ', w.last_name) as worker_name,
                NULL as project_name,
                NULL as cost_node_name,
                DATE_FORMAT(s.date, '%Y-%m') as period
            FROM settlements s
            JOIN workers w ON s.worker_id = w.id
            WHERE " . implode(' AND ', $whereSett) . "
        ";
        
        $stmt = $pdo->prepare($sqlSett);
        $stmt->execute($paramsSett);
        $settlementCosts = $stmt->fetchAll();
    }
    
} catch (PDOException $e) {
    error_log("Koszty pracownicze error: " . $e->getMessage());
}

// Polacz i posortuj
$allCosts = array_merge($laborCosts, $settlementCosts);

// Grupowanie po dniach dla widoku accordion
$costsByDate = [];
foreach ($allCosts as $cost) {
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
krsort($costsByDate); // Sortuj daty malejąco (najnowsze najpierw)

// Sortowanie według wybranej kolumny
usort($allCosts, function($a, $b) use ($sort, $order) {
    $valA = $a[$sort] ?? '';
    $valB = $b[$sort] ?? '';
    
    // Dla kwot i dat
    if ($sort === 'amount') {
        $valA = (float)$valA;
        $valB = (float)$valB;
    } elseif ($sort === 'date') {
        $valA = strtotime($valA);
        $valB = strtotime($valB);
    }
    
    if ($order === 'ASC') {
        return $valA <=> $valB;
    } else {
        return $valB <=> $valA;
    }
});

// Statystyki (stare, do licznika "Pozycji")
$totalLabor = array_sum(array_column($laborCosts, 'amount'));
$totalSettlements = array_sum(array_column($settlementCosts, 'amount'));
$total = $totalLabor + $totalSettlements;

// ------------------------------------------------------------------
// KAFELKI — osobne zapytania, niezależne od pętli budującej listę.
// Każdy kafelek albo liczy swoją kategorię, albo pokazuje "—" (null),
// gdy aktualny filtr "Typ" wyklucza tę kategorię z listy.
// ------------------------------------------------------------------
$kpiLabor        = null; // Robocizna (work_logs)
$kpiPayout       = null; // Wypłaty (settlements.type='payout')
$kpiOther        = null; // Inne rozliczenia (bonus, reimbursement, correction)
$kpiAdvancesOpen = null; // Zaliczki otwarte — saldo "na dziś"

// Robocizna — aktywna dla filtra "wszystkie" i "labor"
if ($filterType === 'all' || $filterType === 'labor') {
    $w = ["wl.status = 'approved'"];
    $p = [];
    if ($filterWorker > 0)    { $w[] = "wl.worker_id = :worker_id";       $p[':worker_id']    = $filterWorker; }
    if ($filterProject > 0)   { $w[] = "wl.project_id = :project_id";     $p[':project_id']   = $filterProject; }
    if ($filterCostNode > 0)  { $w[] = "wl.cost_node_id = :cost_node_id"; $p[':cost_node_id'] = $filterCostNode; }
    if ($cpDateFrom !== '')   { $w[] = "wl.date >= :date_from";           $p[':date_from']    = $cpDateFrom; }
    if ($cpDateTo   !== '')   { $w[] = "wl.date <= :date_to";             $p[':date_to']      = $cpDateTo; }
    $sql = "SELECT COALESCE(SUM(final_cost), 0) FROM work_logs wl WHERE " . implode(' AND ', $w);
    try { $stmt = $pdo->prepare($sql); $stmt->execute($p); $kpiLabor = (float)$stmt->fetchColumn(); }
    catch (PDOException $e) { $kpiLabor = 0.0; }
}

// Wypłaty — aktywne dla "wszystkie" i "payout"; projekt/etap wykluczają ten kafelek
if (($filterType === 'all' || $filterType === 'payout') && $filterProject === 0 && $filterCostNode === 0) {
    $w = ["s.type = 'payout'"];
    $p = [];
    if ($filterWorker > 0)  { $w[] = "s.worker_id = :worker_id"; $p[':worker_id'] = $filterWorker; }
    if ($cpDateFrom !== '') { $w[] = "s.date >= :date_from";     $p[':date_from'] = $cpDateFrom; }
    if ($cpDateTo   !== '') { $w[] = "s.date <= :date_to";       $p[':date_to']   = $cpDateTo; }
    $sql = "SELECT COALESCE(SUM(s.amount), 0) FROM settlements s WHERE " . implode(' AND ', $w);
    try { $stmt = $pdo->prepare($sql); $stmt->execute($p); $kpiPayout = (float)$stmt->fetchColumn(); }
    catch (PDOException $e) { $kpiPayout = 0.0; }
}

// Inne rozliczenia — premie, zwroty kosztów, korekty
if (in_array($filterType, ['all', 'bonus', 'reimbursement', 'correction'], true) && $filterProject === 0 && $filterCostNode === 0) {
    if ($filterType === 'all') {
        $w = ["s.type IN ('bonus','reimbursement','correction')"];
        $p = [];
    } else {
        $w = ["s.type = :type"];
        $p = [':type' => $filterType];
    }
    if ($filterWorker > 0)  { $w[] = "s.worker_id = :worker_id"; $p[':worker_id'] = $filterWorker; }
    if ($cpDateFrom !== '') { $w[] = "s.date >= :date_from";     $p[':date_from'] = $cpDateFrom; }
    if ($cpDateTo   !== '') { $w[] = "s.date <= :date_to";       $p[':date_to']   = $cpDateTo; }
    $sql = "SELECT COALESCE(SUM(s.amount), 0) FROM settlements s WHERE " . implode(' AND ', $w);
    try { $stmt = $pdo->prepare($sql); $stmt->execute($p); $kpiOther = (float)$stmt->fetchColumn(); }
    catch (PDOException $e) { $kpiOther = 0.0; }
}

// Zaliczki otwarte — stan "na dziś", NIE reaguje na filtr dat ani na filtr typu.
// Respektujemy tylko filtr pracownika.
try {
    $wa = ["status = 'open'"];
    $pa = [];
    if ($filterWorker > 0) { $wa[] = "worker_id = :worker_id"; $pa[':worker_id'] = $filterWorker; }
    $sql = "SELECT COALESCE(SUM(amount_remaining), 0) FROM v_worker_advances_details WHERE " . implode(' AND ', $wa);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($pa);
    $kpiAdvancesOpen = (float)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Fallback — gdyby widoku nie było, weź sam amount z worker_advances
    try {
        $wa = ["status = 'open'"];
        $pa = [];
        if ($filterWorker > 0) { $wa[] = "worker_id = :worker_id"; $pa[':worker_id'] = $filterWorker; }
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM worker_advances WHERE " . implode(' AND ', $wa);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($pa);
        $kpiAdvancesOpen = (float)$stmt->fetchColumn();
    } catch (PDOException $e2) {
        $kpiAdvancesOpen = 0.0;
    }
}

// Typy rozliczen
$settlementTypes = [
    'payout' => 'Wyplata',
    'advance' => 'Zaliczka',
    'reimbursement' => 'Zwrot kosztow',
    'bonus' => 'Premia'
];

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

// (Funkcja sortLink usunięta - sortowanie teraz po stronie JS)

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
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Koszty pracownicze</title>
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
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .breadcrumb {
            margin-bottom: 20px;
            color: #666;
            font-size: 14px;
        }
        .breadcrumb a {
            color: #ea580c;
            text-decoration: none;
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
            font-size: 32px;
            color: #333;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
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
            background: linear-gradient(135deg, #ea580c 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.4);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
            transform: translateY(-1px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

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
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #ea580c;
        }
        
        .stat-label {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
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
        .spx-filter-group.fg-worker   { flex: 1.5 1 0; }
        .spx-filter-group.fg-type     { flex: 1.5 1 0; }
        .spx-filter-group.fg-project  { flex: 2   1 0; }
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
        .spx-filter-group input[type="month"],
        .spx-filter-group input[type="date"] {
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
        .search-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
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
            padding: 10px 45px 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 13px;
            transition: all 0.2s;
        }
        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        .search-input::placeholder { color: #999; }
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            pointer-events: none;
        }
        
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
        }
        
        th a:hover {
            color: #ea580c;
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
        
        .btn-group-mode {
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
        .btn-group-mode:hover {
            background: #f9fafb;
            border-color: var(--primary);
        }
        .btn-group-mode svg {
            width: 18px;
            height: 18px;
        }
        body.grouped-mode .btn-group-mode {
            background: var(--primary);
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
        
        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .type-labor { background: #dbeafe; color: #1e40af; }
        .type-payout { background: #d1fae5; color: #065f46; }
        .type-advance { background: #fef3c7; color: #92400e; }
        .type-reimbursement { background: #e0e7ff; color: #3730a3; }
        .type-bonus { background: #fce7f3; color: #9d174d; }
        
        .amount {
            font-weight: 700;
            text-align: right;
            color: #ea580c;
        }
        
        .hours {
            font-size: 12px;
            color: #666;
        }
        
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #999;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-locked { background: #d1ecf1; color: #0c5460; }
        
        @media (max-width: 1024px) {
            .filters-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo url('finanse'); ?>">Finanse</a> / 
            Koszty pracownicze
        </div>
        
        <div class="page-header">
            <h1>Koszty pracownicze</h1>
            <div class="actions">
                <button type="button" class="btn-color-mode active" onclick="toggleColors()" title="Kolory pracowników">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 2a10 10 0 0 1 0 20"/>
                        <circle cx="12" cy="12" r="4"/>
                    </svg>
                </button>
                <button type="button" class="btn-group-mode" onclick="toggleGrouping()" title="Grupuj po dniach">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </button>
                <div style="width: 1px; height: 24px; background: var(--border); margin: 0 4px;"></div>
                <button class="btn btn-ghost" onclick="expandAll()" style="padding: 10px 16px;">Rozwiń</button>
                <button class="btn btn-ghost" onclick="collapseAll()" style="padding: 10px 16px;">Zwiń</button>
                <div style="width: 1px; height: 24px; background: var(--border); margin: 0 4px;"></div>
                <a href="<?php echo url('finanse.rozliczenia.create'); ?>" class="btn btn-primary">
                    + Dodaj rozliczenie
                </a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card" data-tooltip="Ile pozycji widać na liście po aktualnych filtrach.">
                <div class="stat-value"><?php echo count($allCosts); ?></div>
                <div class="stat-label">Pozycji</div>
            </div>
            <div class="stat-card" data-tooltip="Koszt pracy pracowników z zatwierdzonych godzin. Myślnik oznacza, że bieżący filtr typu wyklucza robociznę.">
                <div class="stat-value"><?php echo $kpiLabor === null ? '—' : formatMoney($kpiLabor); ?></div>
                <div class="stat-label">Robocizna</div>
            </div>
            <div class="stat-card" data-tooltip="Suma wypłaconych wynagrodzeń w wybranym okresie. Myślnik oznacza, że bieżący filtr wyklucza wypłaty.">
                <div class="stat-value"><?php echo $kpiPayout === null ? '—' : formatMoney($kpiPayout); ?></div>
                <div class="stat-label">Wypłaty</div>
            </div>
            <div class="stat-card" data-tooltip="Saldo otwartych zaliczek — ile pracownicy mają jeszcze do rozliczenia. Stan na dziś, nie zależy od filtra daty.">
                <div class="stat-value" style="color:#eab308;"><?php echo $kpiAdvancesOpen === null ? '—' : formatMoney($kpiAdvancesOpen); ?></div>
                <div class="stat-label">Zaliczki otwarte</div>
            </div>
            <div class="stat-card" data-tooltip="Premie, zwroty kosztów i korekty. Myślnik oznacza, że bieżący filtr typu wyklucza tę kategorię.">
                <div class="stat-value"><?php echo $kpiOther === null ? '—' : formatMoney($kpiOther); ?></div>
                <div class="stat-label">Inne rozliczenia</div>
            </div>
        </div>
        
        <div class="card">
            <!-- Wyszukiwarka globalna -->
            <form method="GET" action="" class="search-bar">
                <div class="search-wrapper">
                    <input type="text" 
                           name="search" 
                           class="search-input" 
                           placeholder="Szukaj po pracowniku, opisie, projekcie, etapie..."
                           value="<?php echo e($search); ?>">
                    <span class="search-icon">🔍</span>
                </div>
                <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Szukaj</button>
                <?php if ($search): ?>
                    <a href="?<?php echo http_build_query(array_diff_key($_GET, ['search' => ''])); ?>" 
                       class="btn btn-secondary" 
                       style="white-space: nowrap;">Wyczyść</a>
                <?php endif; ?>
                <!-- Zachowaj filtry przy wyszukiwaniu -->
                <?php if ($filterWorker > 0): ?><input type="hidden" name="worker" value="<?php echo e($filterWorker); ?>"><?php endif; ?>
                <?php if ($filterType !== 'all'): ?><input type="hidden" name="type" value="<?php echo e($filterType); ?>"><?php endif; ?>
                <?php if ($cpDateFrom !== ''): ?><input type="hidden" name="date_from" value="<?php echo e($cpDateFrom); ?>"><?php endif; ?>
                <?php if ($cpDateTo   !== ''): ?><input type="hidden" name="date_to"   value="<?php echo e($cpDateTo); ?>"><?php endif; ?>
                <?php if ($filterProject > 0): ?><input type="hidden" name="project_id" value="<?php echo e($filterProject); ?>"><?php endif; ?>
                <?php if ($filterCostNode > 0): ?><input type="hidden" name="cost_node_id" value="<?php echo e($filterCostNode); ?>"><?php endif; ?>
                <?php if ($sort !== 'date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
            </form>
        </div>
        
        <?php
        // Quick-button states
        $cpToday      = date('Y-m-d');
        $cpWeekAgo    = date('Y-m-d', strtotime('-7 days'));
        $cpMonthStart = date('Y-m-01');
        $cpMonthEnd   = date('Y-m-t');
        $cpYearStart  = date('Y-01-01');

        $cpMonthNames = [1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'];
        $cpYearRange  = range((int)date('Y') - 3, (int)date('Y'));
        $cpActiveMonth = 0;
        for ($m = 1; $m <= 12; $m++) {
            $mS = sprintf('%04d-%02d-01', $cpFilterYear, $m);
            $mE = date('Y-m-t', strtotime($mS));
            if ($cpDateFrom === $mS && $cpDateTo === $mE) { $cpActiveMonth = $m; break; }
        }

        $cpIsDayActive   = ($cpDateFrom !== '' && $cpDateFrom === $cpToday   && $cpDateTo === $cpToday);
        $cpIsWeekActive  = ($cpDateFrom !== '' && $cpDateFrom === $cpWeekAgo  && $cpDateTo === $cpToday);
        $cpIsMonthActive = ($cpDateFrom !== '' && $cpDateFrom === $cpMonthStart && $cpDateTo === $cpMonthEnd);
        $cpIsYearActive  = ($cpDateFrom !== '' && $cpDateFrom === $cpYearStart  && $cpDateTo === $cpToday);
        $cpIsAllActive   = (isset($_GET['all']));

        $cpBaseQs = http_build_query(array_filter([
            'worker'      => $filterWorker > 0 ? $filterWorker : '',
            'type'        => $filterType !== 'all' ? $filterType : '',
            'project_id'  => $filterProject > 0 ? $filterProject : '',
            'cost_node_id'=> $filterCostNode > 0 ? $filterCostNode : '',
        ]));
        ?>
        <div class="card" style="margin-bottom: 0; border-radius: 0; box-shadow: none;">
            <form method="GET" class="spx-filter-bar" id="cpFilterForm">
                <div class="spx-filter-group fg-worker">
                    <label>Pracownik</label>
                    <select name="worker">
                        <option value="0">Wszyscy</option>
                        <?php foreach ($workers as $w): ?>
                            <option value="<?php echo $w['id']; ?>" <?php echo $filterWorker === (int)$w['id'] ? 'selected' : ''; ?>>
                                <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-type">
                    <label>Typ kosztu</label>
                    <select name="type">
                        <option value="all" <?php echo $filterType === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="labor" <?php echo $filterType === 'labor' ? 'selected' : ''; ?>>Robocizna</option>
                        <?php foreach ($settlementTypes as $key => $label): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $filterType === $key ? 'selected' : ''; ?>>
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-project">
                    <label>Projekt</label>
                    <select name="project_id" onchange="this.form.submit()">
                        <option value="0">Wszystkie</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" <?php echo $filterProject === (int)$proj['id'] ? 'selected' : ''; ?>>
                                <?php echo e($proj['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-month">
                    <label>Miesiac</label>
                    <select name="month" id="cpSelectMonth" onchange="cpOnMonthYearChange()">
                        <option value="0" <?php echo $cpActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($cpMonthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($cpActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="cpSelectYear" onchange="cpOnMonthYearChange()">
                        <?php foreach ($cpYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($cpFilterYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="cpInputDateFrom" value="<?php echo e($cpDateFrom); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="cpInputDateTo" value="<?php echo e($cpDateTo); ?>">
                </div>
                <button type="submit" style="padding: 0 16px; height: 38px; align-self: flex-end; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; transition: opacity 0.2s; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                <?php if ($cpDateFrom !== '' || $cpDateTo !== '' || $filterWorker > 0 || $filterProject > 0 || $filterType !== 'all'): ?>
                    <a href="?" style="padding: 0 14px; height: 38px; align-self: flex-end; display: inline-flex; align-items: center; background: #6c757d; color: white; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600; flex-shrink: 0; white-space: nowrap;">Resetuj</a>
                <?php endif; ?>
                <?php if ($search): ?><input type="hidden" name="search" value="<?php echo e($search); ?>"><?php endif; ?>
                <?php if ($sort !== 'date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
                <?php if ($filterProject > 0 && count($costNodes) > 0): ?>
                    <input type="hidden" name="cost_node_id" value="<?php echo e($filterCostNode); ?>">
                <?php endif; ?>
            </form>
            <?php if ($filterProject > 0 && count($costNodes) > 0): ?>
            <form method="GET" style="padding: 6px 20px; background: white; border-bottom: 1px solid #e0e0e0; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                <div class="spx-filter-group">
                    <label>Etap</label>
                    <select name="cost_node_id" onchange="this.form.submit()">
                        <option value="0">Wszystkie etapy</option>
                        <?php foreach ($costNodes as $node): ?>
                            <option value="<?php echo $node['id']; ?>" <?php echo $filterCostNode === (int)$node['id'] ? 'selected' : ''; ?>>
                                <?php echo e($node['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($filterWorker > 0): ?><input type="hidden" name="worker" value="<?php echo e($filterWorker); ?>"><?php endif; ?>
                <?php if ($filterType !== 'all'): ?><input type="hidden" name="type" value="<?php echo e($filterType); ?>"><?php endif; ?>
                <input type="hidden" name="project_id" value="<?php echo e($filterProject); ?>">
                <?php if ($cpDateFrom !== ''): ?><input type="hidden" name="date_from" value="<?php echo e($cpDateFrom); ?>"><?php endif; ?>
                <?php if ($cpDateTo   !== ''): ?><input type="hidden" name="date_to"   value="<?php echo e($cpDateTo); ?>"><?php endif; ?>
                <?php if ($search): ?><input type="hidden" name="search" value="<?php echo e($search); ?>"><?php endif; ?>
            </form>
            <?php endif; ?>
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <a href="?<?php echo $cpBaseQs ? $cpBaseQs.'&' : ''; ?>date_from=<?php echo $cpToday; ?>&date_to=<?php echo $cpToday; ?>"
                       class="spx-quick-btn <?php echo $cpIsDayActive ? 'active' : ''; ?>">Dzis</a>
                    <a href="?<?php echo $cpBaseQs ? $cpBaseQs.'&' : ''; ?>date_from=<?php echo $cpWeekAgo; ?>&date_to=<?php echo $cpToday; ?>"
                       class="spx-quick-btn <?php echo $cpIsWeekActive ? 'active' : ''; ?>">7 dni</a>
                    <a href="?<?php echo $cpBaseQs ? $cpBaseQs.'&' : ''; ?>date_from=<?php echo $cpMonthStart; ?>&date_to=<?php echo $cpMonthEnd; ?>&year=<?php echo date('Y'); ?>"
                       class="spx-quick-btn <?php echo $cpIsMonthActive ? 'active' : ''; ?>">Ten miesiac</a>
                    <a href="?<?php echo $cpBaseQs ? $cpBaseQs.'&' : ''; ?>date_from=<?php echo $cpYearStart; ?>&date_to=<?php echo $cpToday; ?>"
                       class="spx-quick-btn <?php echo $cpIsYearActive ? 'active' : ''; ?>">Ten rok</a>
                    <a href="?all=1<?php echo $cpBaseQs ? '&'.$cpBaseQs : ''; ?>"
                       class="spx-quick-btn <?php echo $cpIsAllActive ? 'active' : ''; ?>">Wszystko</a>
                </div>
            </div>
        </div>
        
        <div class="card">
            <?php if (empty($allCosts)): ?>
                <div class="no-data">
                    Brak kosztow pracowniczych w wybranym okresie.
                </div>
            <?php else: ?>
                <!-- Tabela normalna (domyślnie widoczna) -->
                <table class="normal-view">
                    <thead>
                        <tr>
                            <th data-sort="date" class="sortable">Data <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Pracownik <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Typ <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Opis <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Projekt <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Etap <span class="sort-icon"></span></th>
                            <th>Czas</th>
                            <th data-sort="number" class="sortable text-right">Kwota <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Status <span class="sort-icon"></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCosts as $cost): 
                            $typeClass = 'type-labor';
                            $typeLabel = 'Robocizna';
                            
                            $workerColor = getWorkerColor($cost['worker_id']);
                            $rowStyle = "--worker-color: {$workerColor['hslBorder']}; --worker-bg: {$workerColor['hslLight']};";
                            
                            if ($cost['cost_type'] === 'settlement') {
                                $typeClass = 'type-' . $cost['settlement_type'];
                                $typeLabel = $settlementTypes[$cost['settlement_type']] ?? $cost['settlement_type'];
                            }
                            $isAbsenceCost = $cost['cost_type'] === 'labor' && isAbsenceLog($cost);
                            $absenceDays = $isAbsenceCost ? normalizeAbsenceDays($cost) : 0.0;
                        ?>
                            <tr style="<?php echo $rowStyle; ?>" data-worker-id="<?php echo $cost['worker_id']; ?>">
                                <td><?php echo formatDate($cost['date']); ?></td>
                                <td><?php echo e($cost['first_name'] . ' ' . $cost['last_name']); ?></td>
                                <td>
                                    <span class="type-badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span>
                                </td>
                                <td><?php echo e($cost['description'] ?: '-'); ?></td>
                                <td><?php echo e($cost['project_name'] ?: '-'); ?></td>
                                <td><?php echo e($cost['cost_node_name'] ?: '-'); ?></td>
                                <td class="hours">
                                    <?php if ($isAbsenceCost): ?>
                                        <?php echo number_format($absenceDays, 1, ',', ''); ?> dni
                                    <?php elseif ($cost['hours'] > 0 || $cost['overtime_hours'] > 0): ?>
                                        <?php echo $cost['hours']; ?>h
                                        <?php if ($cost['overtime_hours'] > 0): ?>
                                            + <?php echo $cost['overtime_hours']; ?>h nadgodz.
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="amount"><?php echo formatMoney($cost['amount']); ?></td>
                                <td>
                                    <?php 
                                    $statusLabels = ['pending' => 'Oczekujące', 'approved' => 'Zatwierdzone', 'locked' => 'Zablokowane'];
                                    $statusClass = 'status-' . $cost['status'];
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo $statusLabels[$cost['status']] ?? $cost['status']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Tabela zgrupowana po dniach (domyślnie ukryta) -->
                <div class="grouped-view" style="display: none;">
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
                                            <th>Pracownik</th>
                                            <th>Typ</th>
                                            <th>Opis</th>
                                            <th>Projekt</th>
                                            <th>Etap</th>
                                            <th>Czas</th>
                                            <th class="amount">Kwota</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dayData['costs'] as $cost): 
                                            $typeClass = 'type-labor';
                                            $typeLabel = 'Robocizna';
                                            
                                            $workerColor = getWorkerColor($cost['worker_id']);
                                            $rowStyle = "--worker-color: {$workerColor['hslBorder']}; --worker-bg: {$workerColor['hslLight']};";
                                            
                                            if ($cost['cost_type'] === 'settlement') {
                                                $typeClass = 'type-' . $cost['settlement_type'];
                                                $typeLabel = $settlementTypes[$cost['settlement_type']] ?? $cost['settlement_type'];
                                            }
                                            $isAbsenceCost = $cost['cost_type'] === 'labor' && isAbsenceLog($cost);
                                            $absenceDays = $isAbsenceCost ? normalizeAbsenceDays($cost) : 0.0;
                                        ?>
                                            <tr style="<?php echo $rowStyle; ?>" data-worker-id="<?php echo $cost['worker_id']; ?>">
                                                <td><?php echo e($cost['first_name'] . ' ' . $cost['last_name']); ?></td>
                                                <td>
                                                    <span class="type-badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span>
                                                </td>
                                                <td><?php echo e($cost['description'] ?: '-'); ?></td>
                                                <td><?php echo e($cost['project_name'] ?: '-'); ?></td>
                                                <td><?php echo e($cost['cost_node_name'] ?: '-'); ?></td>
                                                <td class="hours">
                                                    <?php if ($isAbsenceCost): ?>
                                                        <?php echo number_format($absenceDays, 1, ',', ''); ?> dni
                                                    <?php elseif ($cost['hours'] > 0 || $cost['overtime_hours'] > 0): ?>
                                                        <?php echo $cost['hours']; ?>h
                                                        <?php if ($cost['overtime_hours'] > 0): ?>
                                                            + <?php echo $cost['overtime_hours']; ?>h nadgodz.
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="amount"><?php echo formatMoney($cost['amount']); ?></td>
                                                <td>
                                                    <?php 
                                                    $statusLabels = ['pending' => 'Oczekujące', 'approved' => 'Zatwierdzone', 'locked' => 'Zablokowane'];
                                                    $statusClass = 'status-' . $cost['status'];
                                                    ?>
                                                    <span class="status-badge <?php echo $statusClass; ?>">
                                                        <?php echo $statusLabels[$cost['status']] ?? $cost['status']; ?>
                                                    </span>
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
    function cpOnMonthYearChange() {
        const month = parseInt(document.getElementById('cpSelectMonth').value);
        const year  = parseInt(document.getElementById('cpSelectYear').value);
        if (!month) return;
        const lastDay = new Date(year, month, 0).getDate();
        const pad = n => String(n).padStart(2, '0');
        document.getElementById('cpInputDateFrom').value = year + '-' + pad(month) + '-01';
        document.getElementById('cpInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
        document.getElementById('cpFilterForm').submit();
    }

    function toggleColors() {
        document.body.classList.toggle('no-colors');
        const btn = document.querySelector('.btn-color-mode');
        btn.classList.toggle('active');
        localStorage.setItem('worklog_colors', !document.body.classList.contains('no-colors') ? '1' : '0');
    }
    
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
        if (localStorage.getItem('worklog_colors') === '0') {
            document.body.classList.add('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) btn.classList.remove('active');
        }
        
        const groupingEnabled = localStorage.getItem('costsGroupingEnabled') === '1';
        if (groupingEnabled) {
            document.body.classList.add('grouped-mode');
            toggleGrouping();
            // Domyślnie zwiń wszystkie dni
            collapseAll();
        }
        
        // Sortowanie JS
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
    </script>
</body>
</html>
