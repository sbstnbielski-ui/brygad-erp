<?php
/**
 * BRYGAD ERP - Moduł HR (Pracownicy)
 * Zakładki: Lista pracowników | Historia operacji | Nowa operacja
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/includes/wallet_helper.php';
require_once dirname(__DIR__) . '/includes/payroll_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$isAdminUser = isAdmin();
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

// ── Zakładka aktywna ──
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'workers';
if (!in_array($tab, ['workers', 'history', 'new'])) {
    $tab = 'workers';
}

// ── Funkcje pomocnicze ──
function settlementTypeLabel(string $type, string $advanceKind = ''): string
{
    if ($type === 'advance') {
        return $advanceKind === 'company' ? 'Zaliczka firmowa' : 'Zaliczka prywatna';
    }
    return [
        'payout'        => 'Wypłata',
        'reimbursement' => 'Zwrot kosztów',
        'bonus'         => 'Premia',
        'correction'    => 'Korekta',
    ][$type] ?? $type;
}

function operationTypeLabel(string $opType): string
{
    return [
        'payout'           => 'Wypłata',
        'advance_private'  => 'Zaliczka prywatna',
        'advance_company'  => 'Zasilenie portfela',
        'reimbursement'    => 'Zwrot kosztów',
        'bonus'            => 'Premia',
        'correction'       => 'Korekta',
        'transfer'         => 'Transfer firmowy A→B',
        'transfer_to_priv' => 'Przekazanie gotówki (firmowa→prywatna)',
    ][$opType] ?? $opType;
}

function workerSortDefaultDirection(string $sort): string
{
    return $sort === 'name' ? 'asc' : 'desc';
}

function compareWorkers(array $a, array $b, string $sort, string $direction): int
{
    $cmp = 0;
    if ($sort === 'name') {
        $nameA = mb_strtolower(trim((string)($a['last_name'] ?? '') . ' ' . (string)($a['first_name'] ?? '')));
        $nameB = mb_strtolower(trim((string)($b['last_name'] ?? '') . ' ' . (string)($b['first_name'] ?? '')));
        $cmp = strcmp($nameA, $nameB);
    } else {
        $valueA = 0.0;
        $valueB = 0.0;
        if ($sort === 'balance') {
            $valueA = (float)($a['current_balance'] ?? 0);
            $valueB = (float)($b['current_balance'] ?? 0);
        } elseif ($sort === 'wallet') {
            $valueA = (float)($a['wallet_balance'] ?? 0);
            $valueB = (float)($b['wallet_balance'] ?? 0);
        } elseif ($sort === 'hours') {
            $valueA = (float)($a['cur_hours'] ?? 0);
            $valueB = (float)($b['cur_hours'] ?? 0);
        } elseif ($sort === 'cost') {
            $valueA = (float)($a['cur_cost'] ?? 0);
            $valueB = (float)($b['cur_cost'] ?? 0);
        }
        $cmp = $valueA <=> $valueB;
        if ($cmp === 0) {
            $nameA = mb_strtolower(trim((string)($a['last_name'] ?? '') . ' ' . (string)($a['first_name'] ?? '')));
            $nameB = mb_strtolower(trim((string)($b['last_name'] ?? '') . ' ' . (string)($b['first_name'] ?? '')));
            $cmp = strcmp($nameA, $nameB);
        }
    }

    if ($direction === 'desc') {
        $cmp *= -1;
    }
    return $cmp;
}

// ── Suma sald (hero — zawsze) ──
$totalBalance = 0;
try {
    $stmtSum = $pdo->query("
        SELECT SUM(bal) AS total FROM (
            SELECT
                (
                    COALESCE((SELECT SUM(final_cost) FROM work_logs wl WHERE wl.worker_id = w.id AND wl.status = 'approved' AND wl.final_cost IS NOT NULL), 0)
                    + COALESCE((SELECT SUM(we.amount) FROM worker_expenses we WHERE we.worker_id = w.id AND we.status IN ('approved','reimbursed') AND (we.paid_by_employee = 1 OR we.description LIKE '%[PAID_BY_EMPLOYEE]%')), 0)
                    + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'bonus'), 0)
                    + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'correction'), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'payout'), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'advance' AND (s.advance_kind = 'private' OR s.advance_kind IS NULL)), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'reimbursement'), 0)
                    - COALESCE((SELECT SUM(wa.amount) FROM worker_advances wa WHERE wa.worker_id = w.id AND wa.type = 'PRIVATE'), 0)
                ) AS bal
            FROM workers w
            WHERE w.is_active = 1
        ) sub
    ");
    $totalBalance = (float)($stmtSum->fetchColumn() ?: 0);
} catch (PDOException $e) {
    logEvent('HR index - suma sald error: ' . $e->getMessage(), 'ERROR');
}

// ── KPI: dane za bieżący i poprzedni miesiąc ──
$monthNames = ['','styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];

$defaultWorkersMonth = (int)date('n');
$defaultWorkersYear = (int)date('Y');
$workersMonth = isset($_GET['month']) ? (int)$_GET['month'] : $defaultWorkersMonth;
$workersYear = isset($_GET['year']) ? (int)$_GET['year'] : $defaultWorkersYear;
if ($workersMonth < 1 || $workersMonth > 12) {
    $workersMonth = $defaultWorkersMonth;
}
if ($workersYear < 2020 || $workersYear > 2035) {
    $workersYear = $defaultWorkersYear;
}
$workersYearOptions = range($defaultWorkersYear - 3, $defaultWorkersYear + 1);
if (!in_array($workersYear, $workersYearOptions, true)) {
    $workersYearOptions[] = $workersYear;
    sort($workersYearOptions);
}

$curMonth = $workersMonth; $curYear = $workersYear;
$curFrom  = sprintf('%04d-%02d-01', $curYear, $curMonth);
$curTo    = date('Y-m-t', strtotime($curFrom));
$curLabel = $monthNames[$curMonth] . ' ' . $curYear;

$prevMonth = $curMonth - 1; $prevYear = $curYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$prevFrom  = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
$prevTo    = date('Y-m-t', strtotime($prevFrom));
$prevLabel = $monthNames[$prevMonth] . ' ' . $prevYear;

$kpiCur = ['labor' => 0, 'paid' => 0, 'advances' => 0, 'bonuses' => 0];
$kpiPrev = ['labor' => 0, 'paid' => 0, 'advances' => 0, 'bonuses' => 0];

$kpiSettlementsSql = "
    SELECT 
        COALESCE(SUM(CASE WHEN s.type IN ('payout','reimbursement') THEN s.amount ELSE 0 END), 0) AS paid_out,
        COALESCE(SUM(CASE WHEN s.type = 'bonus' THEN s.amount ELSE 0 END), 0) AS bonuses,
        COALESCE(SUM(CASE WHEN s.type = 'advance' AND (s.advance_kind = 'private' OR s.advance_kind IS NULL) THEN s.amount ELSE 0 END), 0) AS advances_priv
    FROM settlements s
    JOIN workers w ON w.id = s.worker_id AND w.is_active = 1
    WHERE COALESCE(NULLIF(s.period, '0000-00-00'), s.date) >= ?
      AND COALESCE(NULLIF(s.period, '0000-00-00'), s.date) <= ?
";
$kpiLaborSql = "
    SELECT COALESCE(SUM(wl.final_cost), 0)
    FROM work_logs wl
    JOIN workers w ON w.id = wl.worker_id AND w.is_active = 1
    WHERE wl.status = 'approved' AND wl.final_cost IS NOT NULL
      AND wl.date >= ? AND wl.date <= ?
";
$kpiAdvWaPeriodExpr = payrollAdvancePeriodSql($pdo, 'wa');
$kpiAdvWaSql = "
    SELECT COALESCE(SUM(wa.amount), 0)
    FROM worker_advances wa
    JOIN workers w ON w.id = wa.worker_id AND w.is_active = 1
    WHERE wa.type = 'PRIVATE'
      AND {$kpiAdvWaPeriodExpr} >= ? AND {$kpiAdvWaPeriodExpr} <= ?
";

try {
    foreach ([['cur', $curFrom, $curTo], ['prev', $prevFrom, $prevTo]] as [$key, $from, $to]) {
        $st = $pdo->prepare($kpiLaborSql); $st->execute([$from, $to]);
        ${"kpi" . ucfirst($key)}['labor'] = (float)$st->fetchColumn();

        $st = $pdo->prepare($kpiSettlementsSql); $st->execute([$from, $to]);
        $r = $st->fetch();
        ${"kpi" . ucfirst($key)}['paid'] = (float)($r['paid_out'] ?? 0);
        ${"kpi" . ucfirst($key)}['bonuses'] = (float)($r['bonuses'] ?? 0);
        ${"kpi" . ucfirst($key)}['advances'] = (float)($r['advances_priv'] ?? 0);

        $st = $pdo->prepare($kpiAdvWaSql); $st->execute([$from, $to]);
        ${"kpi" . ucfirst($key)}['advances'] += (float)$st->fetchColumn();
    }
} catch (PDOException $e) {
    logEvent('HR index - KPI error: ' . $e->getMessage(), 'ERROR');
}

foreach (['kpiCur', 'kpiPrev'] as $k) {
    ${$k}['total_out'] = ${$k}['paid'] + ${$k}['advances'];
    ${$k}['balance'] = ${$k}['labor'] - ${$k}['total_out'];
    ${$k}['cash_needed'] = ${$k}['balance'] + ${$k}['bonuses'];
}

// ── Filtry GET (lista pracowników) ──
$filterQuery = isset($_GET['q']) ? trim($_GET['q']) : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : 'active';
if (!in_array($filterStatus, ['active', 'inactive', 'all'])) {
    $filterStatus = 'active';
}
$filterSort = isset($_GET['sort']) ? trim((string)$_GET['sort']) : 'name';
if (!in_array($filterSort, ['name', 'balance', 'wallet', 'hours', 'cost'], true)) {
    $filterSort = 'name';
}
$filterDir = strtolower(trim((string)($_GET['dir'] ?? workerSortDefaultDirection($filterSort))));
if (!in_array($filterDir, ['asc', 'desc'], true)) {
    $filterDir = workerSortDefaultDirection($filterSort);
}
$isWorkersFilterDirty = !empty($filterQuery)
    || $filterStatus !== 'active'
    || $filterSort !== 'name'
    || $filterDir !== workerSortDefaultDirection('name')
    || $workersMonth !== $defaultWorkersMonth
    || $workersYear !== $defaultWorkersYear;

// ── DANE: Lista pracowników ──
$workers = [];
$workerStatusCards = ['all' => 0, 'active' => 0, 'inactive' => 0];
if ($tab === 'workers') {
    try {
        $statsSql = "SELECT 
                COUNT(*) AS all_count,
                SUM(CASE WHEN w.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN w.is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
            FROM workers w
            WHERE 1=1";
        $statsParams = [];
        if (!empty($filterQuery)) {
            $statsSql .= " AND (w.first_name LIKE ? OR w.last_name LIKE ?)";
            $searchParam = '%' . $filterQuery . '%';
            $statsParams[] = $searchParam;
            $statsParams[] = $searchParam;
        }
        $stmtStats = $pdo->prepare($statsSql);
        $stmtStats->execute($statsParams);
        $statusRow = $stmtStats->fetch() ?: [];
        $workerStatusCards['all'] = (int)($statusRow['all_count'] ?? 0);
        $workerStatusCards['active'] = (int)($statusRow['active_count'] ?? 0);
        $workerStatusCards['inactive'] = (int)($statusRow['inactive_count'] ?? 0);
    } catch (PDOException $e) {
        logEvent("Błąd pobierania statystyk pracowników: " . $e->getMessage(), 'ERROR');
    }

    try {
        $sql = "SELECT 
                    w.id, w.first_name, w.last_name, w.phone, w.email,
                    w.worker_type, w.is_active, w.created_at,
                    u.login as user_login,
                    (
                        COALESCE((SELECT SUM(final_cost) FROM work_logs wl WHERE wl.worker_id = w.id AND wl.status = 'approved' AND wl.final_cost IS NOT NULL), 0)
                        + COALESCE((SELECT SUM(we.amount) FROM worker_expenses we WHERE we.worker_id = w.id AND we.status IN ('approved','reimbursed') AND (we.paid_by_employee = 1 OR we.description LIKE '%[PAID_BY_EMPLOYEE]%')), 0)
                        + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'bonus'), 0)
                        + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'correction'), 0)
                        - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'payout'), 0)
                        - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'advance' AND (s.advance_kind = 'private' OR s.advance_kind = 'company_private' OR s.advance_kind IS NULL)), 0)
                        - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'reimbursement'), 0)
                        - COALESCE((SELECT SUM(wa.amount) FROM worker_advances wa WHERE wa.worker_id = w.id AND wa.type = 'PRIVATE'), 0)
                    ) AS current_balance,
                    COALESCE((SELECT SUM(wl3.hours) FROM work_logs wl3 WHERE wl3.worker_id = w.id AND wl3.status = 'approved' AND wl3.date >= '{$curFrom}' AND wl3.date <= '{$curTo}'), 0) AS cur_hours,
                    COALESCE((SELECT SUM(wl4.final_cost) FROM work_logs wl4 WHERE wl4.worker_id = w.id AND wl4.status = 'approved' AND wl4.final_cost IS NOT NULL AND wl4.date >= '{$curFrom}' AND wl4.date <= '{$curTo}'), 0) AS cur_cost
                FROM workers w
                LEFT JOIN users u ON u.worker_id = w.id
                WHERE 1=1";
        $params = [];
        if ($filterStatus === 'active') { $sql .= " AND w.is_active = 1"; }
        elseif ($filterStatus === 'inactive') { $sql .= " AND w.is_active = 0"; }
        if (!empty($filterQuery)) {
            $sql .= " AND (w.first_name LIKE ? OR w.last_name LIKE ?)";
            $searchParam = '%' . $filterQuery . '%';
            $params[] = $searchParam;
            $params[] = $searchParam;
        }
        $sql .= " ORDER BY w.last_name ASC, w.first_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $workers = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent("Błąd pobierania pracowników: " . $e->getMessage(), 'ERROR');
    }

    // Portfele firmowe -- osobne zapytanie
    if (!empty($workers)) {
        $walletBalances = [];
        try {
            $stmtWallet = $pdo->query("
                SELECT wa.worker_id,
                    SUM(wa.amount) - COALESCE((
                        SELECT SUM(CASE WHEN wl.amount > 0 THEN wl.amount ELSE 0 END)
                        FROM worker_ledger wl WHERE wl.advance_id IN (
                            SELECT wa_inner.id FROM worker_advances wa_inner 
                            WHERE wa_inner.worker_id = wa.worker_id AND wa_inner.type = 'COMPANY' AND wa_inner.status = 'open'
                        )
                    ), 0) AS balance
                FROM worker_advances wa
                WHERE wa.type = 'COMPANY' AND wa.status = 'open'
                GROUP BY wa.worker_id
            ");
            foreach ($stmtWallet->fetchAll() as $wr) {
                $walletBalances[(int)$wr['worker_id']] = (float)$wr['balance'];
            }
        } catch (PDOException $e) {}
        foreach ($workers as &$w) {
            $w['wallet_balance'] = $walletBalances[(int)$w['id']] ?? 0;
        }
        unset($w);
    }

    if (!empty($workers)) {
        usort($workers, function(array $a, array $b) use ($filterSort, $filterDir): int {
            return compareWorkers($a, $b, $filterSort, $filterDir);
        });
    }
}

// ── DANE: Historia operacji ──
$history = [];
$historyTotal = 0;
$histWorkers = [];
$historyReturnUrl = url('hr') . '?tab=history';
$historyFlash = isset($_GET['hist_flash']) ? trim((string)$_GET['hist_flash']) : '';
$todayDate  = date('Y-m-d');
$weekAgo    = date('Y-m-d', strtotime('-7 days'));
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$yearStart  = date('Y-01-01');
$historyByDate = [];

if ($tab === 'history') {
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers ORDER BY last_name, first_name");
        $histWorkers = $stmt->fetchAll();
    } catch (PDOException $e) { $histWorkers = []; }

    $fWorker  = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
    $fType    = isset($_GET['type']) ? trim($_GET['type']) : '';
    $fFrom    = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : '';
    $fTo      = isset($_GET['date_to'])   && !empty($_GET['date_to'])  ? $_GET['date_to']   : '';

    // Miesiac/Rok - takie same jak na dzienniku pracy, ustawiaja date_from/date_to
    $histMonthRaw = isset($_GET['month']) ? (int)$_GET['month'] : (isset($_GET['hist_month']) ? (int)$_GET['hist_month'] : 0);
    if ($histMonthRaw < 0 || $histMonthRaw > 12) { $histMonthRaw = 0; }
    $histYearRaw = isset($_GET['year']) ? (int)$_GET['year'] : (isset($_GET['hist_year']) ? (int)$_GET['hist_year'] : 0);
    if ($histYearRaw < 2020 || $histYearRaw > 2035) { $histYearRaw = 0; }
    $histYearOptions = range((int)date('Y') - 3, (int)date('Y') + 1);
    if ($histYearRaw > 0 && !in_array($histYearRaw, $histYearOptions, true)) {
        $histYearOptions[] = $histYearRaw;
        sort($histYearOptions);
    }
    if ($histMonthRaw >= 1 && $histMonthRaw <= 12 && !isset($_GET['date_from'])) {
        $fFrom = sprintf('%04d-%02d-01', $histYearRaw > 0 ? $histYearRaw : (int)date('Y'), $histMonthRaw);
        $fTo   = date('Y-m-t', strtotime($fFrom));
    }
    // Aktywny miesiac wykrywany z zakresu dat (jak na worklog)
    $histActiveMonth = 0;
    $histActiveYear  = $histYearRaw > 0 ? $histYearRaw : (int)date('Y');
    if (!empty($fFrom) && !empty($fTo)) {
        for ($m = 1; $m <= 12; $m++) {
            $mStart = sprintf('%04d-%02d-01', $histActiveYear, $m);
            $mEnd   = date('Y-m-t', strtotime($mStart));
            if ($fFrom === $mStart && $fTo === $mEnd) { $histActiveMonth = $m; break; }
        }
    }

    $historyReturnParams = ['tab' => 'history'];
    if ($fWorker > 0)  { $historyReturnParams['worker_id'] = $fWorker; }
    if ($fType !== '') { $historyReturnParams['type'] = $fType; }
    if ($fFrom !== '') { $historyReturnParams['date_from'] = $fFrom; }
    if ($fTo !== '')   { $historyReturnParams['date_to'] = $fTo; }
    $historyReturnUrl = url('hr') . '?' . http_build_query($historyReturnParams);

    $sWhere  = ['1=1'];
    $sParams = [];
    if ($fWorker > 0)   { $sWhere[] = 's.worker_id = ?'; $sParams[] = $fWorker; }
    if (!empty($fFrom)) { $sWhere[] = 's.date >= ?';     $sParams[] = $fFrom; }
    if (!empty($fTo))   { $sWhere[] = 's.date <= ?';     $sParams[] = $fTo; }

    $typeToSettlementType = [
        'payout'          => ['s.type = ?', 'payout'],
        'advance_private' => ['s.type = ? AND (s.advance_kind = ? OR s.advance_kind IS NULL)', 'advance', 'private'],
        'reimbursement'   => ['s.type = ?', 'reimbursement'],
        'bonus'           => ['s.type = ?', 'bonus'],
        'correction'      => ['s.type = ?', 'correction'],
    ];

    $settlementsQuery = null;
    $settlementsParams = $sParams;

    if (!empty($fType) && isset($typeToSettlementType[$fType])) {
        $filterDef = $typeToSettlementType[$fType];
        $sWhere[] = $filterDef[0];
        for ($i = 1; $i < count($filterDef); $i++) { $settlementsParams[] = $filterDef[$i]; }
        $settlementsQuery = "SELECT s.id, s.worker_id, w.first_name, w.last_name, s.date, s.type AS raw_type, s.advance_kind, s.amount, s.description, 'settlement' AS source
            FROM settlements s INNER JOIN workers w ON w.id = s.worker_id
            WHERE " . implode(' AND ', $sWhere) . " ORDER BY s.date DESC, s.id DESC";
    } elseif (empty($fType) || ($fType !== 'advance_company' && $fType !== 'transfer')) {
        $settlementsQuery = "SELECT s.id, s.worker_id, w.first_name, w.last_name, s.date, s.type AS raw_type, s.advance_kind, s.amount, s.description, 'settlement' AS source
            FROM settlements s INNER JOIN workers w ON w.id = s.worker_id
            WHERE " . implode(' AND ', $sWhere) . " ORDER BY s.date DESC, s.id DESC";
        $settlementsParams = $sParams;
    }

    $historySettlements = [];
    if ($settlementsQuery) {
        try {
            $stmt = $pdo->prepare($settlementsQuery);
            $stmt->execute($settlementsParams);
            $historySettlements = $stmt->fetchAll();
        } catch (PDOException $e) { logEvent('HR history settlements error: ' . $e->getMessage(), 'ERROR'); }
    }

    $waWhere  = ['1=1'];
    $waParams = [];
    if ($fWorker > 0)   { $waWhere[] = 'wa.worker_id = ?'; $waParams[] = $fWorker; }
    if (!empty($fFrom)) { $waWhere[] = 'wa.issue_date >= ?'; $waParams[] = $fFrom; }
    if (!empty($fTo))   { $waWhere[] = 'wa.issue_date <= ?'; $waParams[] = $fTo; }
    if ($fType === 'advance_private') { $waWhere[] = "wa.type = 'PRIVATE'"; }
    elseif ($fType === 'advance_company') { $waWhere[] = "wa.type = 'COMPANY'"; }
    elseif (in_array($fType, ['transfer', 'transfer_to_priv'])) { $waWhere[] = "1=0"; }
    elseif (!empty($fType) && !in_array($fType, ['advance_private', 'advance_company'])) { $waWhere[] = "1=0"; }

    $historyAdvances = [];
    try {
        $salaryPeriodSelect = payrollSalaryPeriodSelectSql($pdo, 'wa');
        $stmt = $pdo->prepare("SELECT wa.id, wa.worker_id, w.first_name, w.last_name, wa.issue_date AS date, {$salaryPeriodSelect} AS salary_period, wa.type AS raw_type, NULL AS advance_kind, wa.amount, wa.description, 'advance' AS source
            FROM worker_advances wa INNER JOIN workers w ON w.id = wa.worker_id
            WHERE " . implode(' AND ', $waWhere) . " ORDER BY wa.issue_date DESC, wa.id DESC");
        $stmt->execute($waParams);
        $historyAdvances = $stmt->fetchAll();
    } catch (PDOException $e) { logEvent('HR history advances error: ' . $e->getMessage(), 'ERROR'); }

    $rawHistory = array_merge($historySettlements, $historyAdvances);
    usort($rawHistory, fn($a, $b) => strcmp($b['date'], $a['date']));
    $history = $rawHistory;
    $historyTotal = count($history);

    foreach ($history as $h) {
        $d = $h['date'];
        if (!isset($historyByDate[$d])) { $historyByDate[$d] = ['rows' => [], 'total_amount' => 0, 'count' => 0]; }
        $historyByDate[$d]['rows'][] = $h;
        $historyByDate[$d]['total_amount'] += (float)$h['amount'];
        $historyByDate[$d]['count']++;
    }
}

// ── DANE: Formularz nowej operacji ──
$formWorkers = [];
$preWorker = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
$preSourceWorker = isset($_GET['from_worker_id']) ? (int)$_GET['from_worker_id'] : 0;
$preSourceAdvance = isset($_GET['from_advance_id']) ? (int)$_GET['from_advance_id'] : 0;
if ($preSourceWorker <= 0 && $preSourceAdvance > 0) {
    $preSourceWorker = walletResolveCompanyAdvanceWorkerId($pdo, $preSourceAdvance);
}
$savedMsg  = isset($_GET['saved']) && $_GET['saved'] === '1';
$formErrors  = [];
$formSuccess = false;
$formSuccessMsg = '';

if ($tab === 'new') {
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $formWorkers = $stmt->fetchAll();
    } catch (PDOException $e) { $formWorkers = []; }
    try {
        $workerFilter = $preSourceWorker > 0 ? [$preSourceWorker] : [];
        $walletSourceWorkers = walletGetCompanySourceWorkers($pdo, $workerFilter);
    } catch (PDOException $e) { $walletSourceWorkers = []; }
}

// ── POST: Nowa operacja ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'new_operation') {
    $tab = 'new';
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $formWorkers = $stmt->fetchAll();
    } catch (PDOException $e) { $formWorkers = []; }
    try {
        $postedSourceWorker = (int)($_POST['from_worker_id'] ?? $preSourceWorker);
        $workerFilter = $postedSourceWorker > 0 ? [$postedSourceWorker] : [];
        $walletSourceWorkers = walletGetCompanySourceWorkers($pdo, $workerFilter);
    } catch (PDOException $e) { $walletSourceWorkers = []; }

    $opType        = trim($_POST['op_type'] ?? '');
    $workerId      = (int)($_POST['worker_id'] ?? 0);
    $toWorkerId    = (int)($_POST['to_worker_id'] ?? 0);
    $amount        = (float)str_replace(',', '.', trim($_POST['amount'] ?? '0'));
    $opDate        = trim($_POST['op_date'] ?? date('Y-m-d'));
    $periodRaw     = trim($_POST['period'] ?? '');
    $period        = payrollNormalizeMonth($periodRaw, $opDate);
    $description   = trim($_POST['description'] ?? '');
    $sourceKind    = trim($_POST['source_kind'] ?? '');
    $sourceRef     = trim($_POST['source_ref'] ?? '');
    $fromWorkerId = (int)($_POST['from_worker_id'] ?? $preSourceWorker);
    $ttpFromWorkerId = (int)($_POST['ttp_from_worker_id'] ?? $fromWorkerId);
    $ttpToWorkerId    = (int)($_POST['ttp_to_worker_id'] ?? 0);

    $validOpTypes = ['payout', 'advance_private', 'advance_company', 'reimbursement', 'bonus', 'correction', 'transfer', 'transfer_to_priv'];
    if (!in_array($opType, $validOpTypes)) { $formErrors[] = 'Wybierz typ operacji.'; }
    if ($amount <= 0) { $formErrors[] = 'Kwota musi być większa od 0.'; }
    if (empty($opDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $opDate)) { $formErrors[] = 'Data operacji jest nieprawidłowa.'; }
    if (in_array($opType, ['payout', 'reimbursement', 'bonus', 'correction', 'advance_private', 'transfer_to_priv'], true) && !payrollIsMonthInputValid($periodRaw)) { $formErrors[] = 'Okres rozliczeniowy jest nieprawidłowy.'; }
    if ($opType !== 'transfer' && $opType !== 'transfer_to_priv' && $workerId <= 0) { $formErrors[] = 'Wybierz pracownika.'; }
    if ($opType === 'transfer' && ($fromWorkerId <= 0 || $toWorkerId <= 0)) { $formErrors[] = 'Dla transferu: wybierz pracownika źródłowego i pracownika docelowego.'; }
    if ($opType === 'transfer_to_priv' && ($ttpFromWorkerId <= 0 || $ttpToWorkerId <= 0)) { $formErrors[] = 'Dla przekazania gotówki: wybierz portfel firmowy źródłowy i pracownika otrzymującego.'; }
    if ($opType === 'advance_company' && empty($sourceKind)) { $formErrors[] = 'Dla zasilenia portfela wybierz źródło finansowania.'; }

    if (empty($formErrors)) {
        try {
            $pdo->beginTransaction();
            $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

            if (in_array($opType, ['payout', 'reimbursement', 'bonus', 'correction'])) {
                $stmt = $pdo->prepare("INSERT INTO settlements (worker_id, type, advance_kind, amount, date, period, description, created_by_user_id) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)");
                $stmt->execute([$workerId, $opType, $amount, $opDate, $period, $description, $createdBy]);
                $formSuccessMsg = operationTypeLabel($opType) . ' została zapisana.';

            } elseif ($opType === 'advance_private') {
                if (payrollWorkerAdvancesHasSalaryPeriod($pdo)) {
                    $stmt = $pdo->prepare("INSERT INTO worker_advances (worker_id, type, amount, issue_date, salary_period, description, status, created_by, created_at) VALUES (?, 'PRIVATE', ?, ?, ?, ?, 'open', ?, NOW())");
                    $stmt->execute([$workerId, $amount, $opDate, $period, $description, $createdBy]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO worker_advances (worker_id, type, amount, issue_date, description, status, created_by, created_at) VALUES (?, 'PRIVATE', ?, ?, ?, 'open', ?, NOW())");
                    $stmt->execute([$workerId, $amount, $opDate, $description, $createdBy]);
                }
                $advanceId = (int)$pdo->lastInsertId();
                $ledgerText = 'Zaliczka prywatna' . (!empty($description) ? ': ' . $description : '');
                $stmt = $pdo->prepare("INSERT INTO worker_ledger (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at) VALUES (?, 'ADVANCE', ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$workerId, -1 * $amount, $opDate, $advanceId, $ledgerText, $createdBy]);
                $formSuccessMsg = 'Zaliczka prywatna została zapisana.';

            } elseif ($opType === 'advance_company') {
                $stmt = $pdo->prepare("INSERT INTO worker_advances (worker_id, type, amount, issue_date, description, status, created_by, created_at) VALUES (?, 'COMPANY', ?, ?, ?, 'open', ?, NOW())");
                $stmt->execute([$workerId, $amount, $opDate, $description, $createdBy]);
                $advanceId = (int)$pdo->lastInsertId();
                $ledgerText = 'Zasilenie portfela firmowego' . (!empty($description) ? ': ' . $description : '');
                $stmt = $pdo->prepare("INSERT INTO worker_ledger (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at) VALUES (?, 'ADVANCE', ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$workerId, -1 * $amount, $opDate, $advanceId, $ledgerText, $createdBy]);
                $fundingNote = 'Zasilenie portfela' . (!empty($description) ? ': ' . $description : '');
                $stmt = $pdo->prepare("INSERT INTO worker_wallet_funding (worker_id, advance_id, direction, amount, source_kind, source_ref, note, movement_date, created_by, created_at) VALUES (?, ?, 'OUT_TOPUP', ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$workerId, $advanceId, $amount, $sourceKind, !empty($sourceRef) ? $sourceRef : null, $fundingNote, $opDate, $createdBy]);
                $formSuccessMsg = 'Zasilenie portfela firmowego zostało zapisane.';

            } elseif ($opType === 'advance_company_private') {
                $stmt = $pdo->prepare("INSERT INTO settlements (worker_id, type, advance_kind, amount, date, period, description, created_by_user_id) VALUES (?, 'advance', 'company_private', ?, ?, ?, ?, ?)");
                $stmt->execute([$workerId, $amount, $opDate, $period, $description, $createdBy]);
                $formSuccessMsg = 'Zaliczka firmowa (do wypłaty) została zapisana.';

            } elseif ($opType === 'transfer_to_priv') {
                if ($ttpFromWorkerId === $ttpToWorkerId) {
                    throw new RuntimeException('Pracownik źródłowy i docelowy muszą być różni.');
                }
                walletCreateTransfer(
                    $pdo,
                    $ttpFromWorkerId,
                    $ttpToWorkerId,
                    $amount,
                    $opDate,
                    $description,
                    $createdBy,
                    'PRIVATE',
                    $period
                );
                $formSuccessMsg = 'Przekazanie gotówki zostało zapisane.';

            } elseif ($opType === 'transfer') {
                if ($fromWorkerId === $toWorkerId) {
                    throw new RuntimeException('Pracownik źródłowy i docelowy muszą być różni.');
                }
                walletCreateTransfer(
                    $pdo,
                    $fromWorkerId,
                    $toWorkerId,
                    $amount,
                    $opDate,
                    $description,
                    $createdBy,
                    'COMPANY'
                );
                $formSuccessMsg = 'Transfer A→B został zapisany.';
            }

            $pdo->commit();
            $formSuccess = true;
            logEvent("HR: nowa operacja '{$opType}' worker_id={$workerId} kwota={$amount}", 'INFO');

        } catch (Exception $e) {
            $pdo->rollBack();
            $formErrors[] = 'Błąd zapisu: ' . $e->getMessage();
            logEvent('HR POST error: ' . $e->getMessage(), 'ERROR');
        }
    }

    if ($formSuccess) {
        $redir = url('hr') . '?tab=new&saved=1';
        if ($preWorker > 0) $redir .= '&worker_id=' . $preWorker;
        header('Location: ' . $redir);
        exit;
    }
}

if (!isset($walletSourceWorkers)) {
    try {
        $walletSourceWorkers = walletGetCompanySourceWorkers($pdo);
    } catch (PDOException $e) {
        $walletSourceWorkers = [];
    }
}

// ========================================
// FUNKCJA: Generowanie koloru pracownika na podstawie ID
// ========================================
function getWorkerColor($workerId) {
    // Deterministyczny kolor na podstawie ID - używamy złotego podziału dla równomiernego rozkładu
    $goldenRatio = 0.618033988749895;
    $hue = fmod($workerId * $goldenRatio, 1.0) * 360;
    
    // Saturation i Lightness dla czytelnych, przyjemnych kolorów
    $saturation = 65;
    $lightness = 55;
    
    return [
        'hue' => round($hue),
        'sat' => $saturation,
        'light' => $lightness,
        'hsl' => "hsl({$hue}, {$saturation}%, {$lightness}%)",
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
    <title><?php echo e(APP_NAME); ?> - Moduł Pracownicy</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
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
            line-height: 1.5;
            padding-bottom: 40px;
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

        /* Layout */
        .dashboard-layout { display: flex; align-items: flex-start; gap: 0; }
        .dashboard-content { flex: 1; min-width: 0; padding-left: 16px; }
        .sidebar-wrapper.collapsed + .dashboard-content { padding-left: 0; }
        .sidebar-wrapper { position: relative; flex-shrink: 0; z-index: 10; }
        .sidebar-actions {
            background: linear-gradient(180deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
            border-radius: 12px 0 12px 12px;
            width: 240px;
            height: calc(100vh - 90px);
            position: sticky;
            top: 74px;
            overflow-y: auto;
            overflow-x: hidden;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(30, 58, 138, 0.2);
            color: white;
        }
        .sidebar-wrapper.collapsed .sidebar-actions { width: 0; }
        .toggle-sidebar-btn {
            position: absolute; top: 20px; right: -14px;
            width: 28px; height: 28px;
            background: white; border: 1px solid #e5e7eb; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 20; color: var(--primary-blue); transition: all 0.3s ease;
        }
        .toggle-sidebar-btn:hover { background: #f8fafc; transform: scale(1.1); }
        .sidebar-wrapper.collapsed .toggle-sidebar-btn { right: -32px; transform: rotate(180deg); }
        .sidebar-content-inner { width: 240px; }
        .sidebar-search { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-search input {
            width: 100%; padding: 8px 12px;
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
            color: white; border-radius: 7px; font-size: 12px; transition: all 0.2s;
        }
        .sidebar-search input::placeholder { color: #93c5fd; }
        .sidebar-search input:focus { outline: none; background: rgba(255,255,255,0.15); border-color: #60a5fa; }
        .sidebar-actions-body { padding: 10px; }
        .sidebar-section { margin-bottom: 4px; border: 1px solid rgba(255,255,255,0.1); border-radius: 7px; overflow: hidden; }
        .sidebar-section-title {
            font-size: 11px; color: #93c5fd; text-transform: uppercase;
            letter-spacing: 0.8px; font-weight: 700;
            list-style: none; margin: 0; padding: 8px 10px;
            cursor: pointer; user-select: none;
            display: flex; align-items: center; justify-content: space-between;
            background: rgba(255,255,255,0.04);
        }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after { content: '›'; font-size: 14px; color: rgba(255,255,255,0.4); transition: transform 0.2s ease; line-height: 1; }
        .sidebar-section[open] .sidebar-section-title::after { transform: rotate(90deg); }
        .sidebar-section-links { padding: 4px 6px 6px; }
        .sidebar-actions a {
            display: block; padding: 7px 10px; margin-bottom: 2px;
            color: #e2e8f0; text-decoration: none; border-radius: 5px;
            transition: all 0.15s ease; font-size: 12px; font-weight: 500;
        }
        .sidebar-actions a:hover { background: rgba(255,255,255,0.12); color: white; padding-left: 14px; }

        /* Hero HR */
        .hero-hr {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }
        .hero-hr h1 { margin: 0 0 6px; font-size: 30px; letter-spacing: -0.4px; }
        .hero-hr .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-hr .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-hr .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero-hr p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-right { display: flex; flex-direction: column; gap: 10px; align-items: flex-end; }
        .hero-nav { display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
        .hero-nav a {
            padding: 7px 12px; border-radius: 8px; text-decoration: none;
            font-size: 12px; font-weight: 600; color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.06);
            transition: all 0.2s ease;
        }
        .hero-nav a:hover { background: rgba(255,255,255,0.14); color: #ffffff; }
        .hero-nav a.active { background: #ffffff; color: #1e3a8a; border-color: #ffffff; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .btn-hero-primary { background: #ffffff; color: #1e3a8a; border: 1px solid #ffffff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; transform: translateY(-1px); }
        .btn-hero-light { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25); color: #ffffff; padding: 8px 14px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-light:hover { background: rgba(255,255,255,0.2); transform: translateY(-1px); }

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 22px;
        }
        .stat-card { background: white; padding: 18px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); border: 1px solid var(--border); }
        .stat-label { font-size: 12px; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px; }
        .stat-value { font-size: 24px; font-weight: 700; color: var(--primary); }

        /* Card */
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 2px rgba(15,23,42,0.05); border: 1px solid #e5e7eb; overflow: visible; margin-bottom: 25px; }

        /* SPX Filter System */
        .spx-filter-bar {
            padding: 14px 16px; background: white;
            border-bottom: 1px solid #eef2f7;
            display: flex; gap: 10px; align-items: flex-end; flex-wrap: nowrap;
        }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .spx-filter-group.fg-search { flex: 2 1 0; }
        .spx-filter-group.fg-status { flex: 1 1 0; }
        .spx-filter-group.fg-sort { flex: 1.05 1 0; }
        .spx-filter-group.fg-dir { flex: 0.9 1 0; }
        .spx-filter-group.fg-month { flex: 1 1 0; }
        .spx-filter-group.fg-year { flex: 0.8 1 0; }
        .spx-filter-group.fg-date { flex: 1 1 0; }
        .spx-filter-group label { font-size: 10px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px; white-space: nowrap; }
        .spx-filter-group select,
        .spx-filter-group input[type="text"],
        .spx-filter-group input[type="date"] {
            padding: 0 10px; height: 38px; border: 1px solid #dbe3ef; border-radius: 8px;
            font-size: 13px; background: #f8fafc; color: var(--text-main);
            font-family: inherit; transition: all 0.15s; width: 100%;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input:focus { outline: none; background: #ffffff; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59,130,246,0.12); }
        .spx-filter-bar .btn-primary {
            background: #2563eb; color: #ffffff; border: 1px solid #2563eb; box-shadow: none;
            padding: 0 16px; height: 38px; border-radius: 8px; font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            white-space: nowrap; flex-shrink: 0;
        }
        .spx-filter-bar .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .spx-filter-bar .btn-secondary {
            background: #f8fafc; color: #334155; border: 1px solid #dbe3ef;
            padding: 0 14px; height: 38px; border-radius: 8px; font-size: 13px; font-weight: 500;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; white-space: nowrap; flex-shrink: 0;
        }
        .spx-filter-bar .btn-secondary:hover { background: #eef2f7; border-color: #cbd5e1; }

        /* Tabela */
        table { width: 100%; border-collapse: collapse; }
        thead { background: white; }
        th {
            padding: 10px 14px !important; text-align: left;
            font-weight: 600; color: var(--text-muted) !important;
            border: 1px solid #000000 !important;
            font-size: 11px !important; text-transform: uppercase !important;
            letter-spacing: 0.5px; background: #f9fafb !important;
        }
        th.sortable-col {
            padding: 0 !important;
        }
        .th-sort-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 10px 14px;
            text-decoration: none;
            color: inherit;
            font-weight: 600;
        }
        .th-sort-link:hover {
            background: #eff6ff;
            color: #1d4ed8;
        }
        .th-sort-link.active {
            color: #1d4ed8;
            background: #eff6ff;
        }
        .th-sort-ind {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            min-width: 12px;
            text-align: center;
            flex-shrink: 0;
        }
        .th-sort-link.active .th-sort-ind {
            color: #1d4ed8;
        }
        th.col-status { width: 80px; text-align: center; }
        td { padding: 10px 14px !important; border: 1px solid #000000 !important; font-size: 13px !important; vertical-align: middle; transition: background 0.2s; }
        td.col-status { text-align: center; width: 80px; }
        tbody tr { transition: background 0.15s ease; }
        tbody tr:hover { background: #f8fafc; }

        /* Kolory pracowników */
        body:not(.no-colors) tbody tr {
            background: var(--worker-bg, transparent);
            border-left: 4px solid var(--worker-color, #667eea);
        }
        body:not(.no-colors) tbody tr:hover { filter: brightness(0.97); }
        body.no-colors tbody tr:nth-child(odd) { background: #ffffff; border-left: 4px solid transparent; }
        body.no-colors tbody tr:nth-child(even) { background: #f8fafc; border-left: 4px solid transparent; }
        body.no-colors tbody tr:hover { background: #e0f2fe !important; }

        .btn-color-mode {
            width: 32px; height: 32px; border-radius: 6px; border: 1px solid #dbe3ef;
            background: #ffffff; cursor: pointer; display: flex; align-items: center;
            justify-content: center; transition: all 0.15s; flex-shrink: 0;
        }
        .btn-color-mode:hover { background: #eff6ff; border-color: #93c5fd; }
        .btn-color-mode.active { background: linear-gradient(135deg, #fce7f3, #e0e7ff); border-color: #a78bfa; }
        .btn-color-mode svg { width: 16px; height: 16px; }

        /* Worker */
        .worker-name { font-weight: 600; color: var(--text-main); text-decoration: none; transition: color 0.2s; }
        .worker-name:hover { color: var(--primary); text-decoration: underline; }
        .worker-details { font-size: 12px; color: #9ca3af; margin-top: 4px; }

        /* Status */
        .status-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; cursor: help; }
        .status-dot.active { background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,0.2); }
        .status-dot.inactive { background: #9ca3af; box-shadow: 0 0 0 2px rgba(156,163,175,0.2); }

        /* Saldo */
        .balance-cell { font-weight: 600; font-size: 13px; white-space: nowrap; }
        .balance-positive { color: #059669; }
        .balance-negative { color: #dc2626; }
        .balance-zero     { color: #9ca3af; }

        /* Przyciski */
        .btn { padding: 5px 10px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 500; cursor: pointer; border: 1px solid; transition: all 0.2s; display: inline-flex; align-items: center; text-align: center; }
        .btn-small { padding: 4px 9px; font-size: 11px; }
        .btn-settle { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: transparent; font-weight: 600; }
        .btn-settle:hover { opacity: 0.9; color: white; }
        .btn-edit { background: white; color: #059669; border-color: #059669; }
        .btn-edit:hover { background: #059669; color: white; }

        .actions-group { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }

        /* Dropdown portal */
        .dropdown { display: inline-block; }
        #dropdown-portal { position: fixed; z-index: 99999; display: none; background: white; border: 1px solid #e5e7eb; border-radius: 6px; box-shadow: 0 4px 16px rgba(0,0,0,0.14); min-width: 160px; overflow: hidden; }
        #dropdown-portal.open { display: block; }
        #dropdown-portal a, #dropdown-portal button { display: block; width: 100%; padding: 9px 14px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; background: white; border: none; cursor: pointer; text-align: left; transition: background 0.15s; font-family: inherit; white-space: nowrap; }
        #dropdown-portal a:hover, #dropdown-portal button:hover { background: #f9fafb; color: #111827; }
        #dropdown-portal .dropdown-divider { height: 1px; background: #e5e7eb; margin: 4px 0; }
        #dropdown-portal .danger:hover { background: #fef2f2; color: #dc2626; }

        /* Alert */
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; border-left: 3px solid; }
        .alert-success { background: #d1fae5; border-color: #059669; color: #065f46; }

        /* Empty state */
        .no-data { padding: 60px 20px; text-align: center; color: #9ca3af; font-size: 15px; }
        .no-data p { margin-bottom: 20px; }

        /* Filter count */
        .filter-count { color: var(--text-muted); font-size: 12px; padding: 0 4px; white-space: nowrap; }

        /* KPI kafelki pod hero */
        .hr-kpi-section { margin-bottom:18px; }
        .hr-kpi-section-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.7px; color:var(--text-muted); margin-bottom:8px; display:flex; align-items:center; gap:8px; }
        .hr-kpi-section-label span { background:var(--border-light); padding:2px 8px; border-radius:4px; font-weight:600; letter-spacing:0; text-transform:none; }
        .hr-kpi-strip { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; }
        .hr-kpi-card { background:white; border-radius:10px; padding:14px 16px; border:1px solid var(--border); border-top:3px solid transparent; transition:box-shadow 0.15s, transform 0.15s; }
        .hr-kpi-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); transform:translateY(-2px); }
        .hr-kpi-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .hr-kpi-value { font-size:18px; font-weight:800; color:var(--text-main); line-height:1.2; font-variant-numeric:tabular-nums; }
        .hr-kpi-sub { font-size:11px; color:var(--text-muted); margin-top:3px; line-height:1.3; }
        .hr-kpi-card.kpi-balance { border-top-color:#2563eb; }
        .hr-kpi-card.kpi-balance .hr-kpi-value { color:#2563eb; }
        .hr-kpi-card.kpi-labor { border-top-color:#7c3aed; }
        .hr-kpi-card.kpi-labor .hr-kpi-value { color:#7c3aed; }
        .hr-kpi-card.kpi-paid { border-top-color:#059669; }
        .hr-kpi-card.kpi-paid .hr-kpi-value { color:#059669; }
        .hr-kpi-card.kpi-bonus { border-top-color:#d97706; }
        .hr-kpi-card.kpi-bonus .hr-kpi-value { color:#d97706; }
        .hr-kpi-card.kpi-cash { border-top-color:#dc2626; }
        .hr-kpi-val-pos { color:#16a34a !important; }
        .hr-kpi-val-neg { color:#dc2626 !important; }
        @media (max-width:1200px) {
            .spx-filter-bar { flex-wrap: wrap; }
            .spx-filter-group.fg-search { flex: 1 1 280px; }
            .spx-filter-group.fg-status,
            .spx-filter-group.fg-sort,
            .spx-filter-group.fg-dir,
            .spx-filter-group.fg-month,
            .spx-filter-group.fg-year { flex: 1 1 170px; }
        }
        @media (max-width:1024px) { .hr-kpi-strip { grid-template-columns:repeat(2, 1fr); } }
        @media (max-width:640px) {
            .hr-kpi-value { font-size:15px; }
            .spx-filter-bar { align-items: stretch; }
            .spx-filter-bar .btn-primary,
            .spx-filter-bar .btn-secondary { width: 100%; justify-content: center; }
        }

        /* Op badge */
        .op-badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:600; }
        .op-payout { background:#fee2e2; color:#dc2626; }
        .op-advance_private { background:#fff7ed; color:#c2410c; }
        .op-advance_company { background:#eff6ff; color:#1d4ed8; }
        .op-reimbursement { background:#eff6ff; color:#0369a1; }
        .op-bonus { background:#dcfce7; color:#15803d; }
        .op-correction { background:#f5f3ff; color:#6d28d9; }
        .op-transfer { background:#fdf4ff; color:#7c3aed; }
        .op-transfer_to_priv { background:#fef9c3; color:#92400e; }
        .op-private { background:#fff7ed; color:#c2410c; }
        .op-company { background:#eff6ff; color:#1d4ed8; }

        /* Day groups (historia) */
        .day-group { border:1px solid #e5e7eb; border-radius:8px; margin-bottom:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
        .day-group.collapsed .day-content { display:none; }
        .day-header { background:#f8fafc; padding:10px 18px; display:flex; justify-content:space-between; align-items:center; cursor:pointer; user-select:none; border-bottom:1px solid #e5e7eb; transition:background 0.15s; }
        .day-group.collapsed .day-header { border-bottom:none; }
        .day-header:hover { background:#f1f5f9; }
        .dh-arrow { font-size:10px; color:#9ca3af; transition:transform 0.2s; width:14px; text-align:center; }
        .day-group.collapsed .dh-arrow { transform:rotate(-90deg); }
        .day-content { background:white; }

        /* Quick buttons */
        .spx-quick-btn { padding:0 12px; height:28px; background:white; border:1px solid #e5e7eb; border-radius:5px; font-size:12px; font-weight:500; color:#374151; text-decoration:none; cursor:pointer; transition:all 0.15s; display:inline-flex; align-items:center; white-space:nowrap; }
        .spx-quick-btn:hover { background:#f9fafb; border-color:#667eea; color:#667eea; }
        .spx-quick-btn.active { background:#667eea; border-color:#667eea; color:white; font-weight:600; }

        /* Form (nowa operacja) */
        .form-section-title { font-size:12px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.6px; margin:24px 0 14px; padding-bottom:6px; border-bottom:1px solid #f3f4f6; }
        .form-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:16px; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .form-group label { font-size:12px; font-weight:600; color:#374151; }
        .form-group .required { color:#dc2626; margin-left:2px; }
        .form-group input, .form-group select, .form-group textarea {
            padding:9px 12px; border:1px solid #e5e7eb; border-radius:6px; font-size:14px; font-family:inherit; color:#1f2937; background:white; transition:border-color 0.2s; width:100%; box-sizing:border-box; height:40px;
        }
        .form-group textarea { height:auto; min-height:90px; resize:vertical; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:none; border-color:#667eea; box-shadow:0 0 0 2px rgba(102,126,234,0.1); }
        .op-fields { display:none; }
        .op-fields.visible { display:contents; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="dashboard-layout">

            <!-- SIDEBAR LEWY — domyślnie zwinięty -->
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
                                <summary class="sidebar-section-title">Akcje HR</summary>
                                <div class="sidebar-section-links">
                                    <a href="<?php echo url('hr.workers.create'); ?>" data-keywords="nowy pracownik dodaj">+ Dodaj pracownika</a>
                                    <a href="<?php echo url('hr.worklog.create'); ?>" data-keywords="wpis czas pracy dodaj">+ Dodaj wpis czasu</a>
                                    <a href="<?php echo url('finanse.wydatki.create'); ?>" data-keywords="wydatek dodaj">+ Dodaj wydatek</a>
                                    <a href="<?php echo url('hr.workers.documents'); ?>" data-keywords="dokument dodaj">+ Dodaj dokument</a>
                                </div>
                            </details>
                            <details class="sidebar-section">
                                <summary class="sidebar-section-title">Portfele firmowe</summary>
                                <div class="sidebar-section-links">
                                    <a href="<?php echo url('hr.workers.wallet'); ?>" data-keywords="portfel firmowy pracownik">Portfel pracownika</a>
                                    <a href="?tab=new&op_type=advance_company" data-keywords="zasil portfel zaliczka firmowa">+ Zasil portfel</a>
                                </div>
                            </details>
                            <details class="sidebar-section">
                                <summary class="sidebar-section-title">Raporty</summary>
                                <div class="sidebar-section-links">
                                    <a href="<?php echo url('hr.workers.report'); ?>" data-keywords="raport okresowy">Raport okresowy</a>
                                    <a href="<?php echo url('hr.workers.rates_table'); ?>" data-keywords="stawki tabela">Stawki — tabela</a>
                                    <a href="<?php echo url('hr.workers.bulk_rates'); ?>" data-keywords="stawki masowe ogolne">Stawki ogólne</a>
                                </div>
                            </details>
                            <details class="sidebar-section">
                                <summary class="sidebar-section-title">Nawigacja</summary>
                                <div class="sidebar-section-links">
                                    <a href="<?php echo url('hr.worklog.index'); ?>" data-keywords="dziennik pracy worklog">Dziennik pracy</a>
                                    <a href="?tab=history" data-keywords="historia operacje rozliczenia">Historia operacji</a>
                                    <a href="?tab=new" data-keywords="nowa operacja rozlicz">Nowa operacja</a>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
            </div>
            <!-- END SIDEBAR -->

            <div class="dashboard-content">

                <!-- HERO -->
                <div class="hero-hr">
                    <div>
                        <div class="hero-breadcrumb"><a href="<?php echo url('dashboard'); ?>">Panel główny</a> › HR</div>
                        <h1>Moduł Pracownicy</h1>
                        <p>Zarządzanie pracownikami, rozliczeniami i stawkami</p>
                    </div>
                    <div class="hero-right">
                        <div class="hero-nav">
                            <a href="?tab=workers" class="<?php echo $tab === 'workers' ? 'active' : ''; ?>">Lista pracowników</a>
                            <a href="<?php echo url('hr.worklog.index'); ?>">Dziennik pracy</a>
                            <a href="?tab=history" class="<?php echo $tab === 'history' ? 'active' : ''; ?>">Historia operacji</a>
                            <a href="?tab=new" class="<?php echo $tab === 'new' ? 'active' : ''; ?>">Nowa operacja</a>
                        </div>
                        <div class="hero-actions">
                            <a href="<?php echo url('hr.workers.create'); ?>" class="btn-hero-primary">+ Dodaj pracownika</a>
                        </div>
                    </div>
                </div>
                <!-- END HERO -->

                <?php if ($isAdminUser): ?>
                <div class="hr-kpi-section">
                    <div class="hr-kpi-section-label">Wybrany miesiąc <span><?php echo $curLabel; ?></span></div>
                    <div class="hr-kpi-strip">
                        <div class="hr-kpi-card kpi-balance">
                            <div class="hr-kpi-label">Suma sald</div>
                            <div class="hr-kpi-value <?php echo $totalBalance > 0 ? 'hr-kpi-val-pos' : ($totalBalance < 0 ? 'hr-kpi-val-neg' : ''); ?>">
                                <?php echo ($totalBalance > 0 ? '+' : '') . number_format($totalBalance, 2, ',', ' '); ?> zł
                            </div>
                            <div class="hr-kpi-sub">Zarobek − wypłaty − zaliczki (od początku)</div>
                        </div>
                        <div class="hr-kpi-card kpi-labor">
                            <div class="hr-kpi-label">Koszt pracy</div>
                            <div class="hr-kpi-value"><?php echo number_format($kpiCur['labor'], 2, ',', ' '); ?> zł</div>
                            <div class="hr-kpi-sub">Zatwierdzone godziny × stawki</div>
                        </div>
                        <div class="hr-kpi-card kpi-paid">
                            <div class="hr-kpi-label">Wydano gotówki</div>
                            <div class="hr-kpi-value"><?php echo number_format($kpiCur['total_out'], 2, ',', ' '); ?> zł</div>
                            <div class="hr-kpi-sub">Wypłaty + zwroty + zaliczki prywatne</div>
                        </div>
                        <div class="hr-kpi-card kpi-bonus">
                            <div class="hr-kpi-label">Premie</div>
                            <div class="hr-kpi-value"><?php echo number_format($kpiCur['bonuses'], 2, ',', ' '); ?> zł</div>
                            <div class="hr-kpi-sub">Zaksięgowane w tym miesiącu</div>
                        </div>
                    </div>
                </div>
                <div class="hr-kpi-section">
                    <div class="hr-kpi-section-label">Poprzedni względem wyboru <span><?php echo $prevLabel; ?></span></div>
                    <div class="hr-kpi-strip">
                        <div class="hr-kpi-card kpi-labor">
                            <div class="hr-kpi-label">Koszt pracy</div>
                            <div class="hr-kpi-value"><?php echo number_format($kpiPrev['labor'], 2, ',', ' '); ?> zł</div>
                            <div class="hr-kpi-sub">Zatwierdzone godziny × stawki</div>
                        </div>
                        <div class="hr-kpi-card kpi-paid">
                            <div class="hr-kpi-label">Wydano gotówki</div>
                            <div class="hr-kpi-value"><?php echo number_format($kpiPrev['total_out'], 2, ',', ' '); ?> zł</div>
                            <div class="hr-kpi-sub">Wypłaty + zwroty + zaliczki prywatne</div>
                        </div>
                        <div class="hr-kpi-card kpi-bonus">
                            <div class="hr-kpi-label">Premie</div>
                            <div class="hr-kpi-value"><?php echo number_format($kpiPrev['bonuses'], 2, ',', ' '); ?> zł</div>
                            <div class="hr-kpi-sub">Zaksięgowane premie za okres</div>
                        </div>
                        <div class="hr-kpi-card kpi-cash">
                            <div class="hr-kpi-label">Do wypłaty</div>
                            <div class="hr-kpi-value <?php echo $kpiPrev['cash_needed'] > 0 ? 'hr-kpi-val-neg' : 'hr-kpi-val-pos'; ?>">
                                <?php echo number_format($kpiPrev['cash_needed'], 2, ',', ' '); ?> zł
                            </div>
                            <div class="hr-kpi-sub">Saldo okresu + premie = gotówka do ręki</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($_GET['success']) && $_GET['success'] === 'bulk_rates'): ?>
                    <div class="alert alert-success">
                        <strong>Sukces!</strong> Masowo przypisano stawki dla <?php echo (int)($_GET['count'] ?? 0); ?> pracowników.
                    </div>
                <?php endif; ?>

                <!-- WIDOK: Lista pracowników -->
                <?php if ($tab === 'workers'): ?>
                    <div class="card">
                        <?php
                        $buildWorkersSortUrl = function(string $sortKey, string $defaultDir = 'desc') use ($filterQuery, $filterStatus, $workersMonth, $workersYear, $filterSort, $filterDir): string {
                            $nextDir = ($filterSort === $sortKey)
                                ? ($filterDir === 'asc' ? 'desc' : 'asc')
                                : $defaultDir;
                            $params = [
                                'tab' => 'workers',
                                'status' => $filterStatus,
                                'sort' => $sortKey,
                                'dir' => $nextDir,
                                'month' => $workersMonth,
                                'year' => $workersYear,
                            ];
                            if ($filterQuery !== '') {
                                $params['q'] = $filterQuery;
                            }
                            return '?' . http_build_query($params);
                        };
                        $workersSortIndicator = function(string $sortKey) use ($filterSort, $filterDir): string {
                            if ($filterSort !== $sortKey) {
                                return '↕';
                            }
                            return $filterDir === 'asc' ? '↑' : '↓';
                        };
                        ?>
                        <!-- SPX filtry -->
                        <form method="GET" id="filterForm">
                            <input type="hidden" name="tab" value="workers">
                            <input type="hidden" name="sort" value="<?php echo e($filterSort); ?>">
                            <input type="hidden" name="dir" value="<?php echo e($filterDir); ?>">
                            <div class="spx-filter-bar">
                                <div class="spx-filter-group fg-search">
                                    <label for="q">Szukaj pracownika</label>
                                    <input type="text" id="q" name="q" placeholder="Imię lub nazwisko..." value="<?php echo e($filterQuery); ?>">
                                </div>
                                <div class="spx-filter-group fg-status">
                                    <label for="status">Status</label>
                                    <select id="status" name="status">
                                        <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Aktywni</option>
                                        <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Nieaktywni</option>
                                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>Wszyscy</option>
                                    </select>
                                </div>
                                <div class="spx-filter-group fg-month">
                                    <label for="month">Miesiąc</label>
                                    <select id="month" name="month">
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                            <option value="<?php echo $m; ?>" <?php echo $workersMonth === $m ? 'selected' : ''; ?>>
                                                <?php echo e($monthNames[$m]); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="spx-filter-group fg-year">
                                    <label for="year">Rok</label>
                                    <select id="year" name="year">
                                        <?php foreach ($workersYearOptions as $yearOpt): ?>
                                            <option value="<?php echo (int)$yearOpt; ?>" <?php echo $workersYear === (int)$yearOpt ? 'selected' : ''; ?>>
                                                <?php echo (int)$yearOpt; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn-primary">Filtruj</button>
                                <?php if ($isWorkersFilterDirty): ?>
                                    <a href="?tab=workers" class="btn-secondary">Resetuj</a>
                                <?php endif; ?>
                                <span class="filter-count"><?php echo count($workers); ?> / <?php echo (int)$workerStatusCards['all']; ?> prac.</span>
                                <!-- Toggle kolorów -->
                                <button type="button" class="btn-color-mode active" onclick="toggleColors()" title="Kolory pracowników">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 2a10 10 0 0 1 0 20"/>
                                        <circle cx="12" cy="12" r="4"/>
                                    </svg>
                                </button>
                            </div>
                        </form>
                        
                        <?php if (empty($workers)): ?>
                            <div class="no-data">
                                <p>Brak pracowników spełniających kryteria.</p>
                                    <a href="?tab=workers" class="btn btn-edit" style="display: inline-block; margin-top: 15px;">Resetuj filtry</a>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th class="sortable-col">
                                            <a href="<?php echo e($buildWorkersSortUrl('name', 'asc')); ?>" class="th-sort-link <?php echo $filterSort === 'name' ? 'active' : ''; ?>">
                                                <span>Pracownik</span>
                                                <span class="th-sort-ind"><?php echo e($workersSortIndicator('name')); ?></span>
                                            </a>
                                        </th>
                                        <th class="sortable-col">
                                            <a href="<?php echo e($buildWorkersSortUrl('balance', 'desc')); ?>" class="th-sort-link <?php echo $filterSort === 'balance' ? 'active' : ''; ?>">
                                                <span>Saldo</span>
                                                <span class="th-sort-ind"><?php echo e($workersSortIndicator('balance')); ?></span>
                                            </a>
                                        </th>
                                        <th class="sortable-col">
                                            <a href="<?php echo e($buildWorkersSortUrl('wallet', 'desc')); ?>" class="th-sort-link <?php echo $filterSort === 'wallet' ? 'active' : ''; ?>">
                                                <span>Portfel firmowy</span>
                                                <span class="th-sort-ind"><?php echo e($workersSortIndicator('wallet')); ?></span>
                                            </a>
                                        </th>
                                        <th class="sortable-col" style="text-align:right;">
                                            <a href="<?php echo e($buildWorkersSortUrl('hours', 'desc')); ?>" class="th-sort-link <?php echo $filterSort === 'hours' ? 'active' : ''; ?>">
                                                <span>Godziny (<?php echo e($monthNames[$curMonth]); ?>)</span>
                                                <span class="th-sort-ind"><?php echo e($workersSortIndicator('hours')); ?></span>
                                            </a>
                                        </th>
                                        <th class="sortable-col" style="text-align:right;">
                                            <a href="<?php echo e($buildWorkersSortUrl('cost', 'desc')); ?>" class="th-sort-link <?php echo $filterSort === 'cost' ? 'active' : ''; ?>">
                                                <span>Koszt (<?php echo e($monthNames[$curMonth]); ?>)</span>
                                                <span class="th-sort-ind"><?php echo e($workersSortIndicator('cost')); ?></span>
                                            </a>
                                        </th>
                                        <th class="col-status">Status</th>
                                        <th style="text-align: right;">Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($workers as $worker): 
                                        $workerColor = getWorkerColor($worker['id']);
                                        $rowStyle = "--worker-color: {$workerColor['hslBorder']}; --worker-bg: {$workerColor['hslLight']};";
                                    ?>
                                        <tr style="<?php echo $rowStyle; ?>" data-worker-id="<?php echo $worker['id']; ?>">
                                            <td>
                                                <?php
                                                $reportUrl = url('hr.workers.report', ['worker_id' => $worker['id']]) 
                                                    . '&month=' . $workersMonth . '&year=' . $workersYear;
                                                ?>
                                                <a href="<?php echo $reportUrl; ?>" 
                                                   class="worker-name">
                                                    <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                                                </a>
                                            </td>
                                            <td class="balance-cell">
                                                <?php
                                                $bal = (float)$worker['current_balance'];
                                                $balClass = $bal > 0 ? 'balance-positive' : ($bal < 0 ? 'balance-negative' : 'balance-zero');
                                                $balSign  = $bal > 0 ? '+' : '';
                                                ?>
                                                <span class="<?php echo $balClass; ?>">
                                                    <?php echo $balSign . number_format($bal, 2, ',', ' '); ?> zł
                                                </span>
                                            </td>
                                            <td>
                                                <?php $wb = (float)$worker['wallet_balance']; ?>
                                                <?php if ($wb > 0.01): ?>
                                                    <a href="<?php echo url('hr.workers.wallet', ['worker_id' => $worker['id']]); ?>" style="font-size:13px; font-weight:600; color:#2563eb; text-decoration:none;">
                                                        <?php echo number_format($wb, 2, ',', ' '); ?> zł
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color:#d1d5db; font-size:13px;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <?php $ph = (float)$worker['cur_hours']; ?>
                                                <?php if ($ph > 0): ?>
                                                    <span style="font-size:13px; font-weight:600;"><?php echo number_format($ph, 1, ',', ' '); ?> h</span>
                                                <?php else: ?>
                                                    <span style="color:#d1d5db; font-size:13px;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <?php $pc = (float)$worker['cur_cost']; ?>
                                                <?php if ($pc > 0): ?>
                                                    <span style="font-size:13px; font-weight:600;"><?php echo number_format($pc, 0, ',', ' '); ?> zł</span>
                                                <?php else: ?>
                                                    <span style="color:#d1d5db; font-size:13px;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="col-status">
                                                <?php if ($worker['is_active']): ?>
                                                    <span class="status-dot active" title="Aktywny"></span>
                                                <?php else: ?>
                                                    <span class="status-dot inactive" title="Nieaktywny"></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="actions-group">
                                                    <a href="<?php echo $reportUrl; ?>" 
                                                       class="btn btn-small btn-settle">
                                                        Raport
                                                    </a>
                                                    <div class="dropdown">
                                                        <button class="btn btn-small" style="background:white;color:#374151;border-color:#e5e7eb;"
                                                                onclick="toggleDropdown(<?php echo $worker['id']; ?>, this)">
                                                            Więcej
                                                        </button>
                                                        <template id="dd-tpl-<?php echo $worker['id']; ?>">
                                                            <a href="?tab=new&worker_id=<?php echo $worker['id']; ?>">Rozlicz</a>
                                                            <a href="<?php echo url('hr.workers.wallet', ['worker_id' => $worker['id']]); ?>">Portfel firmowy</a>
                                                            <a href="<?php echo url('hr.workers.balance', ['worker_id' => $worker['id']]); ?>">Bilans</a>
                                                            <a href="<?php echo url('finanse.wydatki'); ?>?worker=<?php echo $worker['id']; ?>">Wydatki</a>
                                                            <a href="<?php echo url('hr.workers.edit', ['id' => $worker['id']]); ?>">Edytuj</a>
                                                            <a href="<?php echo url('hr.workers.rates', ['id' => $worker['id']]); ?>">Zarządzaj stawkami</a>
                                                            <?php if ($isAdminUser): ?>
                                                                <div class="dropdown-divider"></div>
                                                                <?php if ($worker['is_active']): ?>
                                                                    <button class="danger" onclick="confirmDeactivate(<?php echo $worker['id']; ?>, '<?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>'); closeDropdown();">
                                                                        Dezaktywuj
                                                                    </button>
                                                                <?php else: ?>
                                                                    <button onclick="confirmActivate(<?php echo $worker['id']; ?>, '<?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>'); closeDropdown();">
                                                                        Aktywuj
                                                                    </button>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        </template>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php /* ─────────────────── ZAKŁADKA: HISTORIA OPERACJI ─────────────────── */ ?>
                <?php if ($tab === 'history'): ?>
                    <?php
                    $histBaseQs = http_build_query(array_filter([
                        'tab'       => 'history',
                        'worker_id' => $fWorker ?: null,
                        'type'      => $fType   ?: null,
                    ]));
                    $isHistAllActive  = (empty($fFrom) && empty($fTo));
                    $isHistYearActive = ($fFrom === $yearStart && $fTo === $todayDate);
                    $polishDays = ['Nd','Pn','Wt','Sr','Cz','Pt','So'];
                    ?>
                    <div class="card">
                        <form method="GET" action="" id="histFilterForm">
                            <input type="hidden" name="tab" value="history">
                            <div class="spx-filter-bar">
                                <div class="spx-filter-group fg-search">
                                    <label>Pracownik</label>
                                    <select name="worker_id">
                                        <option value="">Wszyscy</option>
                                        <?php foreach ($histWorkers as $hw): ?>
                                            <option value="<?php echo $hw['id']; ?>" <?php echo $fWorker === (int)$hw['id'] ? 'selected' : ''; ?>>
                                                <?php echo e($hw['last_name'] . ' ' . $hw['first_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="spx-filter-group fg-status">
                                    <label>Typ operacji</label>
                                    <select name="type">
                                        <option value="">Wszystkie</option>
                                        <option value="payout"          <?php echo $fType === 'payout'          ? 'selected' : ''; ?>>Wypłata</option>
                                        <option value="advance_private" <?php echo $fType === 'advance_private' ? 'selected' : ''; ?>>Zaliczka prywatna</option>
                                        <option value="advance_company" <?php echo $fType === 'advance_company' ? 'selected' : ''; ?>>Zasilenie portfela</option>
                                        <option value="reimbursement"   <?php echo $fType === 'reimbursement'   ? 'selected' : ''; ?>>Zwrot kosztów</option>
                                        <option value="bonus"           <?php echo $fType === 'bonus'           ? 'selected' : ''; ?>>Premia</option>
                                        <option value="correction"      <?php echo $fType === 'correction'      ? 'selected' : ''; ?>>Korekta</option>
                                        <optgroup label="Transfery">
                                            <option value="transfer"         <?php echo $fType === 'transfer'         ? 'selected' : ''; ?>>Firmowy → Firmowy</option>
                                            <option value="transfer_to_priv" <?php echo $fType === 'transfer_to_priv' ? 'selected' : ''; ?>>Firmowy → Prywatna</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="spx-filter-group fg-month">
                                    <label>Miesiąc</label>
                                    <select name="month" id="histSelectMonth" onchange="histOnMonthYearChange()">
                                        <option value="0" <?php echo $histActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                                        <?php for ($mn = 1; $mn <= 12; $mn++): ?>
                                            <option value="<?php echo $mn; ?>" <?php echo $histActiveMonth === $mn ? 'selected' : ''; ?>>
                                                <?php echo e($monthNames[$mn]); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="spx-filter-group fg-year">
                                    <label>Rok</label>
                                    <select name="year" id="histSelectYear" onchange="histOnMonthYearChange()">
                                        <?php foreach ($histYearOptions as $yr): ?>
                                            <option value="<?php echo (int)$yr; ?>" <?php echo $histActiveYear === (int)$yr ? 'selected' : ''; ?>>
                                                <?php echo (int)$yr; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="spx-filter-group fg-date">
                                    <label>Od</label>
                                    <input type="date" name="date_from" id="histInputDateFrom" value="<?php echo e($fFrom); ?>">
                                </div>
                                <div class="spx-filter-group fg-date">
                                    <label>Do</label>
                                    <input type="date" name="date_to" id="histInputDateTo" value="<?php echo e($fTo); ?>">
                                </div>
                                <button type="submit" class="btn-primary">Filtruj</button>
                                <?php if ($fWorker || $fType || $fFrom || $fTo): ?>
                                    <a href="?tab=history" class="btn-secondary">Resetuj</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <div style="padding:10px 16px; background:#f9fafb; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <a href="?<?php echo $histBaseQs; ?>&date_from=<?php echo $todayDate; ?>&date_to=<?php echo $todayDate; ?>"
                                   class="spx-quick-btn <?php echo ($fFrom === $todayDate && $fTo === $todayDate) ? 'active' : ''; ?>">Dziś</a>
                                <a href="?<?php echo $histBaseQs; ?>&date_from=<?php echo $weekAgo; ?>&date_to=<?php echo $todayDate; ?>"
                                   class="spx-quick-btn <?php echo ($fFrom === $weekAgo && $fTo === $todayDate) ? 'active' : ''; ?>">7 dni</a>
                                <a href="?<?php echo $histBaseQs; ?>&date_from=<?php echo $monthStart; ?>&date_to=<?php echo $monthEnd; ?>"
                                   class="spx-quick-btn <?php echo ($fFrom === $monthStart && $fTo === $monthEnd) ? 'active' : ''; ?>">Ten miesiąc</a>
                                <a href="?<?php echo $histBaseQs; ?>&date_from=<?php echo $yearStart; ?>&date_to=<?php echo $todayDate; ?>"
                                   class="spx-quick-btn <?php echo $isHistYearActive ? 'active' : ''; ?>">Ten rok</a>
                                <a href="?tab=history<?php echo $fWorker ? '&worker_id='.$fWorker : ''; ?><?php echo $fType ? '&type='.$fType : ''; ?>"
                                   class="spx-quick-btn <?php echo $isHistAllActive ? 'active' : ''; ?>">Wszystko</a>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span style="font-size:12px; color:#6b7280;">Wyników: <?php echo $historyTotal; ?></span>
                                <?php if (!empty($historyByDate)): ?>
                                <div style="display:flex; gap:2px; background:#e5e7eb; padding:2px; border-radius:6px;">
                                    <button type="button" style="background:transparent;border:none;color:#6b7280;padding:4px 10px;height:24px;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;" onclick="histExpandAll()">Rozwiń</button>
                                    <button type="button" style="background:transparent;border:none;color:#6b7280;padding:4px 10px;height:24px;border-radius:4px;font-size:12px;font-weight:600;cursor:pointer;" onclick="histCollapseAll()">Zwiń</button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($historyFlash === 'updated'): ?>
                            <div class="alert alert-success" style="margin:16px;">Operacja została zaktualizowana.</div>
                        <?php elseif ($historyFlash === 'deleted'): ?>
                            <div class="alert alert-success" style="margin:16px;">Operacja została usunięta.</div>
                        <?php endif; ?>

                        <?php if (empty($historyByDate)): ?>
                            <div class="no-data">Brak operacji dla podanych kryteriów.</div>
                        <?php else: ?>
                            <div style="padding:16px;">
                            <?php foreach ($historyByDate as $hDate => $dayData): ?>
                                <?php
                                $dayNum  = date('w', strtotime($hDate));
                                $dayName = $polishDays[$dayNum];
                                ?>
                                <div class="day-group" data-hdate="<?php echo $hDate; ?>">
                                    <div class="day-header" onclick="this.parentElement.classList.toggle('collapsed')">
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <span style="font-size:11px;font-weight:700;color:#6b7280;background:#e2e8f0;padding:2px 7px;border-radius:4px;text-transform:uppercase;"><?php echo $dayName; ?></span>
                                            <span style="font-weight:700;font-size:14px;color:#334155;"><?php echo date('d.m.Y', strtotime($hDate)); ?></span>
                                        </div>
                                        <div style="display:flex;align-items:center;gap:14px;font-size:12px;color:#6b7280;">
                                            <span style="font-weight:600;color:#374151;"><?php echo $dayData['count']; ?> operacji</span>
                                            <span style="font-weight:700;color:#667eea;"><?php echo number_format($dayData['total_amount'], 2, ',', ' '); ?> zł</span>
                                            <span class="dh-arrow">&#9660;</span>
                                        </div>
                                    </div>
                                    <div class="day-content">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th style="width:220px;">Pracownik</th>
                                                    <th style="width:180px;">Typ</th>
                                                    <th style="width:130px;text-align:right;">Kwota</th>
                                                    <th>Opis</th>
                                                    <th style="width:120px;text-align:center;">Akcje</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($dayData['rows'] as $h):
                                                    $c = getWorkerColor($h['worker_id']);
                                                    if ($h['source'] === 'advance') {
                                                        $uiType = $h['raw_type'] === 'PRIVATE' ? 'advance_private' : 'advance_company';
                                                    } else {
                                                        $ak = strtolower((string)($h['advance_kind'] ?? ''));
                                                        $uiType = ($h['raw_type'] === 'advance') ? (($ak === 'company') ? 'advance_company' : 'advance_private') : $h['raw_type'];
                                                    }
                                                    $typeLabel = operationTypeLabel($uiType);
                                                    $sourceType = $h['source'] === 'settlement' ? 'settlement' : 'advance';
                                                    $editUrl = url('hr.settlements.edit', ['source' => $sourceType, 'id' => (int)$h['id'], 'return_to' => $historyReturnUrl]);
                                                    $deleteUrl = url('hr.settlements.delete', ['source' => $sourceType, 'id' => (int)$h['id'], 'return_to' => $historyReturnUrl]);
                                                    $reportUrl = url('hr.workers.report', ['worker_id' => $h['worker_id']]) . '&month=' . $workersMonth . '&year=' . $workersYear;
                                                ?>
                                                    <tr style="background:<?php echo $c['hslLight']; ?>; border-left:4px solid <?php echo $c['hslBorder']; ?>;">
                                                        <td>
                                                            <a href="<?php echo $reportUrl; ?>" class="worker-name"><?php echo e($h['last_name'] . ' ' . $h['first_name']); ?></a>
                                                        </td>
                                                        <td><span class="op-badge op-<?php echo $uiType; ?>"><?php echo $typeLabel; ?></span></td>
                                                        <td style="text-align:right;font-weight:600;font-variant-numeric:tabular-nums;"><?php echo number_format((float)$h['amount'], 2, ',', ' '); ?> zł</td>
                                                        <td style="color:#6b7280;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                            <?php echo e($h['description'] ?? ''); ?>
                                                            <?php if (($h['source'] ?? '') === 'advance' && ($h['raw_type'] ?? '') === 'PRIVATE' && !empty($h['salary_period'])): ?>
                                                                <div style="font-size:10px;color:#9ca3af;">Za miesiąc: <?php echo e(payrollMonthLabel((string)$h['salary_period'])); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td style="text-align:center;">
                                                            <div style="display:flex;gap:6px;justify-content:center;">
                                                                <a href="<?php echo $editUrl; ?>" class="btn btn-small btn-edit">Edytuj</a>
                                                                <a href="<?php echo $deleteUrl; ?>" class="btn btn-small" style="background:white;color:#dc2626;border-color:#dc2626;">Usuń</a>
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
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php /* ─────────────────── ZAKŁADKA: NOWA OPERACJA ─────────────────── */ ?>
                <?php if ($tab === 'new'): ?>
                    <div class="card">
                        <?php if ($savedMsg): ?>
                            <div class="alert alert-success" style="margin:16px;">Operacja została zapisana.</div>
                        <?php endif; ?>
                        <?php if (!empty($formErrors)): ?>
                            <div class="alert" style="background:#fee2e2;border-color:#dc2626;color:#991b1b;margin:16px;padding:12px 16px;border-radius:6px;border-left:3px solid;">
                                <strong>Błąd:</strong>
                                <ul style="margin:6px 0 0 18px;">
                                    <?php foreach ($formErrors as $err): ?>
                                        <li><?php echo e($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div style="padding:28px 32px; max-width:680px;">
                            <form method="POST" action="" id="newOpForm">
                                <input type="hidden" name="_action" value="new_operation">

                                <div class="form-section-title">Typ operacji</div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Typ operacji<span class="required">*</span></label>
                                        <select name="op_type" id="op_type" required onchange="updateOpFields()">
                                            <option value="">Wybierz typ</option>
                                            <?php
                                            $preOpType = $_POST['op_type'] ?? $_GET['op_type'] ?? '';
                                            $validOpTypes = ['payout','advance_private','advance_company','reimbursement','bonus','correction','transfer','transfer_to_priv'];
                                            if (!in_array($preOpType, $validOpTypes)) $preOpType = '';
                                            ?>
                                            <option value="payout"          <?php echo $preOpType === 'payout'          ? 'selected' : ''; ?>>Wypłata</option>
                                            <option value="advance_private" <?php echo $preOpType === 'advance_private' ? 'selected' : ''; ?>>Zaliczka prywatna</option>
                                            <option value="advance_company" <?php echo $preOpType === 'advance_company' ? 'selected' : ''; ?>>Zasilenie portfela firmowego</option>
                                            <option value="reimbursement"   <?php echo $preOpType === 'reimbursement'   ? 'selected' : ''; ?>>Zwrot kosztów</option>
                                            <option value="bonus"           <?php echo $preOpType === 'bonus'           ? 'selected' : ''; ?>>Premia</option>
                                            <option value="correction"      <?php echo $preOpType === 'correction'      ? 'selected' : ''; ?>>Korekta</option>
                                            <optgroup label="Transfery między pracownikami">
                                                <option value="transfer"        <?php echo $preOpType === 'transfer'        ? 'selected' : ''; ?>>Firmowy → Firmowy (portfel → portfel)</option>
                                                <option value="transfer_to_priv"<?php echo $preOpType === 'transfer_to_priv'? 'selected' : ''; ?>>Firmowy → Prywatna (gotówka z portfela)</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                </div>

                                <div id="fields-single" class="op-fields">
                                    <div class="form-section-title">Dane operacji</div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Pracownik<span class="required">*</span></label>
                                            <select name="worker_id" id="op_worker_id">
                                                <option value="">Wybierz pracownika</option>
                                                <?php foreach ($formWorkers as $fw): ?>
                                                    <option value="<?php echo $fw['id']; ?>"
                                                        <?php echo ((int)($_POST['worker_id'] ?? $preWorker)) === (int)$fw['id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($fw['last_name'] . ' ' . $fw['first_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div id="source-kind-group" style="display:none;">
                                        <div class="form-section-title">Źródło finansowania</div>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label>Skąd pochodzi kwota<span class="required">*</span></label>
                                                <select name="source_kind" id="source_kind">
                                                    <option value="">Wybierz</option>
                                                    <option value="cash"  <?php echo ($_POST['source_kind'] ?? '') === 'cash'  ? 'selected' : ''; ?>>Gotówka</option>
                                                    <option value="bank"  <?php echo ($_POST['source_kind'] ?? '') === 'bank'  ? 'selected' : ''; ?>>Przelew bankowy</option>
                                                    <option value="other" <?php echo ($_POST['source_kind'] ?? '') === 'other' ? 'selected' : ''; ?>>Inne</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>Numer referencyjny (opcjonalnie)</label>
                                                <input type="text" name="source_ref" value="<?php echo e($_POST['source_ref'] ?? ''); ?>" placeholder="np. numer przelewu">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div id="fields-transfer" class="op-fields">
                                    <div class="form-section-title">Transfer portfela firmowego</div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Pracownik źródłowy (portfel firmowy)<span class="required">*</span></label>
                                            <select name="from_worker_id" id="from_worker_id">
                                                <option value="">Wybierz portfel źródłowy</option>
                                                <?php foreach ($walletSourceWorkers as $walletWorker): ?>
                                                    <option value="<?php echo (int)$walletWorker['worker_id']; ?>"
                                                        <?php echo ((int)($_POST['from_worker_id'] ?? $preSourceWorker)) === (int)$walletWorker['worker_id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($walletWorker['worker_name']); ?> — saldo: <?php echo number_format((float)$walletWorker['wallet_balance'], 2, ',', ' '); ?> zł — otwarte pozycje: <?php echo (int)$walletWorker['open_count']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Do pracownika<span class="required">*</span></label>
                                            <select name="to_worker_id">
                                                <option value="">Wybierz pracownika</option>
                                                <?php foreach ($formWorkers as $fw): ?>
                                                    <option value="<?php echo $fw['id']; ?>" <?php echo ((int)($_POST['to_worker_id'] ?? 0)) === (int)$fw['id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($fw['last_name'] . ' ' . $fw['first_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div id="fields-transfer-to-priv" class="op-fields">
                                    <div class="form-section-title">Przekazanie gotówki z portfela firmowego</div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Pracownik źródłowy<span class="required">*</span></label>
                                            <select name="ttp_from_worker_id" id="ttp_from_worker_id">
                                                <option value="">Wybierz portfel źródłowy</option>
                                                <?php foreach ($walletSourceWorkers as $walletWorker): ?>
                                                    <option value="<?php echo (int)$walletWorker['worker_id']; ?>"
                                                        <?php echo ((int)($_POST['ttp_from_worker_id'] ?? $_POST['from_worker_id'] ?? $preSourceWorker)) === (int)$walletWorker['worker_id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($walletWorker['worker_name']); ?> — saldo: <?php echo number_format((float)$walletWorker['wallet_balance'], 2, ',', ' '); ?> zł — otwarte pozycje: <?php echo (int)$walletWorker['open_count']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Pracownik otrzymujący (zaliczka prywatna)<span class="required">*</span></label>
                                            <select name="ttp_to_worker_id" id="ttp_to_worker_id">
                                                <option value="">Wybierz pracownika</option>
                                                <?php foreach ($formWorkers as $fw): ?>
                                                    <option value="<?php echo $fw['id']; ?>" <?php echo ((int)($_POST['ttp_to_worker_id'] ?? 0)) === (int)$fw['id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($fw['last_name'] . ' ' . $fw['first_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div id="fields-common" style="display:none;">
                                    <div class="form-section-title" id="common-section-title">Kwota i data</div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label id="amount-label">Kwota (PLN)<span class="required">*</span></label>
                                            <input type="number" name="amount" id="amount" step="0.01" min="0.01" value="<?php echo e($_POST['amount'] ?? ''); ?>" placeholder="0.00">
                                        </div>
                                        <div class="form-group">
                                            <label>Data operacji<span class="required">*</span></label>
                                            <input type="date" name="op_date" value="<?php echo e($_POST['op_date'] ?? date('Y-m-d')); ?>">
                                        </div>
                                    </div>
                                    <div id="period-group-common">
                                        <div class="form-group" style="margin-bottom:16px;">
                                            <label>Okres rozliczeniowy<span class="required">*</span></label>
                                            <input type="month" name="period" value="<?php echo e($_POST['period'] ?? date('Y-m')); ?>">
                                            <span style="font-size:12px;color:#9ca3af;">Miesiąc, którego dotyczy operacja</span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Opis (opcjonalnie)</label>
                                        <textarea name="description" placeholder="Dodatkowe informacje..." style="min-height:90px;resize:vertical;"><?php echo e($_POST['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <div style="display:flex;gap:12px;margin-top:28px;padding-top:20px;border-top:1px solid #f3f4f6;">
                                    <button type="submit" class="btn btn-settle" id="submitBtn" style="padding:8px 20px;height:auto;font-size:14px;">Zapisz operację</button>
                                    <a href="?tab=workers" class="btn" style="background:white;color:#374151;border-color:#e5e7eb;padding:8px 20px;font-size:14px;">Anuluj</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            </div><!-- /dashboard-content -->
        </div><!-- /dashboard-layout -->
    </div><!-- /container -->
    
    <!-- Portal dla dropdownu — renderowany poza tabelą, position:fixed unika stacking context td -->
    <div id="dropdown-portal"></div>

    <script>
        // ========================================
        // DROPDOWN "Więcej" — portal poza tabelą
        // ========================================
        let _activeBtn = null;

        function toggleDropdown(workerId, btn) {
            const portal = document.getElementById('dropdown-portal');
            const tpl    = document.getElementById('dd-tpl-' + workerId);

            // Kliknięcie tego samego przycisku = zamknij
            if (_activeBtn === btn && portal.classList.contains('open')) {
                closeDropdown();
                return;
            }

            // Wstaw zawartość z <template> (klonowanie content)
            portal.innerHTML = '';
            portal.appendChild(tpl.content.cloneNode(true));
            portal.classList.add('open');
            _activeBtn = btn;

            // Pozycjonuj pod przyciskiem
            const rect = btn.getBoundingClientRect();
            const pw   = portal.offsetWidth;
            let left   = rect.right - pw;
            let top    = rect.bottom + 4;

            // Zabezpieczenie przed wyjściem poza ekran
            if (left < 6) left = 6;
            if (top + portal.offsetHeight > window.innerHeight - 6) {
                top = rect.top - portal.offsetHeight - 4;
            }

            portal.style.left = left + 'px';
            portal.style.top  = top  + 'px';
        }

        function closeDropdown() {
            const portal = document.getElementById('dropdown-portal');
            portal.classList.remove('open');
            portal.innerHTML = '';
            _activeBtn = null;
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('#dropdown-portal') && !e.target.closest('.dropdown')) {
                closeDropdown();
            }
        });

        // Zamknij przy scrollu
        window.addEventListener('scroll', closeDropdown, { passive: true });

        function confirmDeactivate(workerId, workerName) {
            if (confirm('Czy na pewno chcesz dezaktywować pracownika: ' + workerName + '?\n\nPracownik nie zostanie usunięty, tylko oznaczony jako nieaktywny.')) {
                window.location.href = '/api/workers/toggle-status.php?id=' + workerId + '&action=deactivate';
            }
        }
        
        function confirmActivate(workerId, workerName) {
            if (confirm('Czy na pewno chcesz ponownie aktywować pracownika: ' + workerName + '?')) {
                window.location.href = '/api/workers/toggle-status.php?id=' + workerId + '&action=activate';
            }
        }
        
        // ========================================
        // TOGGLE KOLORÓW PRACOWNIKÓW
        // ========================================
        function toggleColors() {
            document.body.classList.toggle('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            btn.classList.toggle('active');
            
            // Zapisz stan w localStorage (wspólny z worklog)
            const colorsEnabled = !document.body.classList.contains('no-colors');
            localStorage.setItem('worklog_colors', colorsEnabled ? '1' : '0');
        }
        
        // Sidebar toggle
        function toggleSidebar() {
            const wrapper = document.getElementById('sidebarWrapper');
            if (!wrapper) return;
            wrapper.classList.toggle('collapsed');
            const isCollapsed = wrapper.classList.contains('collapsed');
            localStorage.setItem('brygad_hr_sidebar_collapsed', isCollapsed ? 'true' : 'false');
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Przywróć stan sidebara
            const wrapper = document.getElementById('sidebarWrapper');
            if (wrapper) {
                const stored = localStorage.getItem('brygad_hr_sidebar_collapsed');
                const isCollapsed = stored === null ? true : stored === 'true';
                wrapper.classList.toggle('collapsed', isCollapsed);
            }

            // Przywróć stan kolorów
            const colorsEnabled = localStorage.getItem('worklog_colors');
            if (colorsEnabled === '0') {
                document.body.classList.add('no-colors');
                const btn = document.querySelector('.btn-color-mode');
                if (btn) btn.classList.remove('active');
            }

            // Sidebar search
            (function() {
                const searchInput = document.getElementById('actionSearch');
                if (!searchInput) return;
                const sections = document.querySelectorAll('.sidebar-section');
                const links = document.querySelectorAll('.sidebar-actions a[data-keywords]');
                searchInput.addEventListener('input', function() {
                    const query = this.value.toLowerCase().trim();
                    if (query === '') {
                        sections.forEach(s => { s.style.display = ''; if (s._autoOpened) { s.open = false; s._autoOpened = false; } });
                        links.forEach(l => l.style.display = '');
                        return;
                    }
                    const visibleSections = new Set();
                    links.forEach(link => {
                        const keywords = link.getAttribute('data-keywords') || '';
                        const text = link.textContent.toLowerCase();
                        if (text.includes(query) || keywords.includes(query)) {
                            link.style.display = '';
                            const section = link.closest('.sidebar-section');
                            if (section) { if (!section.open) { section.open = true; section._autoOpened = true; } visibleSections.add(section); }
                        } else { link.style.display = 'none'; }
                    });
                    sections.forEach(s => { s.style.display = visibleSections.has(s) ? '' : 'none'; });
                });
            })();

            // Historia: zachowaj stan rozwinięcia po zmianie filtrów.
            if (localStorage.getItem('brygad_hr_history_table_state') === 'expanded') {
                histExpandAll(false);
            } else {
                histCollapseAll(false);
            }

            // Nowa operacja: inicjalizacja
            if (typeof updateOpFields === 'function') updateOpFields();

            <?php if ($preWorker > 0): ?>
            const workerSel = document.getElementById('op_worker_id');
            if (workerSel && !workerSel.value) workerSel.value = '<?php echo $preWorker; ?>';
            <?php endif; ?>
        });

        // ── Historia: zwijanie/rozwijanie ──
        function histExpandAll(remember = true) {
            document.querySelectorAll('.day-group').forEach(g => g.classList.remove('collapsed'));
            if (remember) localStorage.setItem('brygad_hr_history_table_state', 'expanded');
        }
        function histCollapseAll(remember = true) {
            document.querySelectorAll('.day-group').forEach(g => g.classList.add('collapsed'));
            if (remember) localStorage.setItem('brygad_hr_history_table_state', 'collapsed');
        }

        // ── Historia: Miesiąc/Rok auto-submit (spójnie z dziennikiem pracy) ──
        function histOnMonthYearChange() {
            var monthEl = document.getElementById('histSelectMonth');
            var yearEl  = document.getElementById('histSelectYear');
            var dfEl    = document.getElementById('histInputDateFrom');
            var dtEl    = document.getElementById('histInputDateTo');
            var form    = document.getElementById('histFilterForm');
            if (!monthEl || !yearEl || !dfEl || !dtEl || !form) return;
            var month = parseInt(monthEl.value, 10);
            var year  = parseInt(yearEl.value, 10);
            if (!month) return;
            var lastDay = new Date(year, month, 0).getDate();
            var pad = function(n) { return String(n).padStart(2, '0'); };
            dfEl.value = year + '-' + pad(month) + '-01';
            dtEl.value = year + '-' + pad(month) + '-' + pad(lastDay);
            form.submit();
        }

        // ── Nowa operacja: dynamiczne pola ──
        function updateOpFields() {
            const opType = document.getElementById('op_type');
            if (!opType) return;
            const val = opType.value;
            const single         = document.getElementById('fields-single');
            const transfer       = document.getElementById('fields-transfer');
            const transferToPriv = document.getElementById('fields-transfer-to-priv');
            const common         = document.getElementById('fields-common');
            const srcKindGroup   = document.getElementById('source-kind-group');
            const periodGroup    = document.getElementById('period-group-common');
            const submitBtn      = document.getElementById('submitBtn');
            const sectionTitle   = document.getElementById('common-section-title');
            const amountLabel    = document.getElementById('amount-label');

            if (!single) return;

            single.classList.remove('visible');
            transfer.classList.remove('visible');
            transferToPriv.classList.remove('visible');
            common.style.display = 'none';
            srcKindGroup.style.display = 'none';

            if (!val) { if (submitBtn) submitBtn.disabled = true; return; }

            if (submitBtn) submitBtn.disabled = false;
            common.style.display = '';

            const labels = {
                'payout':'Kwota wypłaty (PLN)', 'advance_private':'Kwota zaliczki (PLN)',
                'advance_company':'Kwota zasilenia (PLN)', 'reimbursement':'Kwota zwrotu (PLN)',
                'bonus':'Kwota premii (PLN)', 'correction':'Kwota korekty (PLN)',
                'transfer':'Kwota transferu (PLN)', 'transfer_to_priv':'Kwota przekazanej gotówki (PLN)',
            };
            if (amountLabel) amountLabel.innerHTML = (labels[val] || 'Kwota (PLN)') + '<span class="required">*</span>';

            const noPeriod = ['advance_company', 'transfer'];
            if (periodGroup) periodGroup.style.display = noPeriod.includes(val) ? 'none' : '';

            if (val === 'transfer') {
                transfer.classList.add('visible');
                if (sectionTitle) sectionTitle.textContent = 'Kwota i data transferu';
            } else if (val === 'transfer_to_priv') {
                transferToPriv.classList.add('visible');
                if (sectionTitle) sectionTitle.textContent = 'Kwota i data';
            } else {
                single.classList.add('visible');
                if (sectionTitle) sectionTitle.textContent = 'Kwota i data';
                if (val === 'advance_company') srcKindGroup.style.display = '';
            }
        }
    </script>
</body>
</html>
