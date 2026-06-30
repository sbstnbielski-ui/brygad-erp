<?php
/**
 * BRYGAD ERP - Centrum Analiz Finansowych (kokpit)
 *
 * Cel: szybki widok decyzyjny (1-2 min), bez dublowania analizy szczegolowej.
 * Szczegoly i wykresy pozostaja w /finanse/overview.php.
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/includes/analytics.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$yearStart  = date('Y-01-01');
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$weekEnd    = date('Y-m-d', strtotime('sunday this week'));

// Month/year select helper — identycznie jak faktury-sprzedazowe
$filterYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($filterYear < 2020 || $filterYear > 2030) $filterYear = (int)date('Y');
$filterMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
if ($filterMonth < 1 || $filterMonth > 12) $filterMonth = (int)date('n');

$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : '';

// Jeśli nie podano Od/Do, zbuduj z miesiąc+rok
if ($dateFrom === '' && $dateTo === '') {
    $dateFrom = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
    $dateTo   = date('Y-m-t', strtotime($dateFrom));
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $monthStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $monthEnd;

// Aktywny miesiąc/rok (do zaznaczenia w selectach)
$activeMonth = (int)date('n', strtotime($dateFrom));
$activeYear  = (int)date('Y', strtotime($dateFrom));
// Jeśli zakres nie jest dokładnie jednym miesiącem, odznacz miesiąc
if ($dateFrom !== sprintf('%04d-%02d-01', $activeYear, $activeMonth) ||
    $dateTo   !== date('Y-m-t', strtotime($dateFrom))) {
    $activeMonth = 0;
}

$monthNames = [1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',
               7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'];
$yearRange  = range((int)date('Y'), (int)date('Y') - 4);

$periodLabel = date('d.m.Y', strtotime($dateFrom)) . ' – ' . date('d.m.Y', strtotime($dateTo));

// Poprzedni okres (ten sam zakres dni wstecz)
$diffDays = max(1, (int)((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1);
$prevFrom = date('Y-m-d', strtotime($dateFrom . ' -' . $diffDays . ' days'));
$prevTo   = date('Y-m-d', strtotime($dateTo   . ' -' . $diffDays . ' days'));

$period        = substr($dateFrom, 0, 7);
$currentPeriod = date('Y-m');

function monthLabelPl(string $periodYm): string
{
    static $months = [
        '01' => 'Styczen', '02' => 'Luty', '03' => 'Marzec', '04' => 'Kwiecien',
        '05' => 'Maj', '06' => 'Czerwiec', '07' => 'Lipiec', '08' => 'Sierpien',
        '09' => 'Wrzesien', '10' => 'Pazdziernik', '11' => 'Listopad', '12' => 'Grudzien',
    ];

    $year = substr($periodYm, 0, 4);
    $month = substr($periodYm, 5, 2);
    return ($months[$month] ?? $periodYm) . ' ' . $year;
}

function queryScalarSafe(PDO $pdo, string $sql, array $params = [], float $default = 0.0, ?bool &$ok = null): float
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        $ok = true;
        return $val === false || $val === null ? $default : (float)$val;
    } catch (Throwable $e) {
        $ok = false;
        error_log('Finanse cockpit scalar query failed: ' . $e->getMessage());
        return $default;
    }
}

function queryAssocSafe(PDO $pdo, string $sql, array $params = [], array $default = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : $default;
    } catch (Throwable $e) {
        error_log('Finanse cockpit assoc query failed: ' . $e->getMessage());
        return $default;
    }
}

function queryAllSafe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Finanse cockpit list query failed: ' . $e->getMessage());
        return [];
    }
}

function changePct(float $current, float $previous): ?float
{
    if (abs($previous) < 0.0001) {
        return null;
    }
    return (($current - $previous) / abs($previous)) * 100;
}

function pctBadgeClass(?float $value): string
{
    if ($value === null) {
        return 'delta-neutral';
    }
    return $value >= 0 ? 'delta-pos' : 'delta-neg';
}

function pctBadgeText(?float $value): string
{
    if ($value === null) {
        return 'brak porownania';
    }
    $prefix = $value >= 0 ? '+' : '';
    return $prefix . number_format($value, 1, ',', ' ') . '% vs poprzedni miesiac';
}

// ---------------------------------------------------------------------
// KPI bazowe (okres biezacy + porownanie m/m)
// ---------------------------------------------------------------------

// Sprzedaż: FV
$revenueFv = analyticsRevenueFv($pdo, $dateFrom, $dateTo);
$revenueFvPrev = analyticsRevenueFv($pdo, $prevFrom, $prevTo);

// Sprzedaż: PZF (pozafakturowe)
$revenuePzf = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0) FROM sales_noninvoice_entries WHERE issue_date BETWEEN :from AND :to",
    [':from' => $dateFrom, ':to' => $dateTo]
);
$revenuePzfPrev = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0) FROM sales_noninvoice_entries WHERE issue_date BETWEEN :from AND :to",
    [':from' => $prevFrom, ':to' => $prevTo]
);

// Łączna sprzedaż (do KPI i delty)
$revenue     = $revenueFv + $revenuePzf;
$revenuePrev = $revenueFvPrev + $revenuePzfPrev;

// Kasa: wpłaty FV
$receivedFv = queryScalarSafe(
    $pdo,
    'SELECT COALESCE(SUM(isp.amount_net), 0) FROM invoice_sale_payments isp JOIN invoices_sale inv ON inv.id = isp.invoice_id WHERE isp.payment_date BETWEEN :from AND :to AND inv.exclude_from_analytics = 0',
    [':from' => $dateFrom, ':to' => $dateTo]
);
$receivedFvPrev = queryScalarSafe(
    $pdo,
    'SELECT COALESCE(SUM(isp.amount_net), 0) FROM invoice_sale_payments isp JOIN invoices_sale inv ON inv.id = isp.invoice_id WHERE isp.payment_date BETWEEN :from AND :to AND inv.exclude_from_analytics = 0',
    [':from' => $prevFrom, ':to' => $prevTo]
);

// Kasa: PZF opłacone (w okresie issue_date)
$receivedPzf = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0) FROM sales_noninvoice_entries WHERE payment_status = 'paid' AND issue_date BETWEEN :from AND :to",
    [':from' => $dateFrom, ':to' => $dateTo]
);
$receivedPzfPrev = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0) FROM sales_noninvoice_entries WHERE payment_status = 'paid' AND issue_date BETWEEN :from AND :to",
    [':from' => $prevFrom, ':to' => $prevTo]
);

// Kasa łącznie (FV payments + PZF paid)
$received     = $receivedFv + $receivedPzf;
$receivedPrev = $receivedFvPrev + $receivedPzfPrev;

$ledgerOkCurrent = false;
$ledgerCostCurrent = queryScalarSafe(
    $pdo,
    'SELECT COALESCE(SUM(amount), 0) FROM view_finance_ledger WHERE date BETWEEN :from AND :to',
    [':from' => $dateFrom, ':to' => $dateTo],
    0.0,
    $ledgerOkCurrent
);

$ledgerOkPrev = false;
$ledgerCostPrev = queryScalarSafe(
    $pdo,
    'SELECT COALESCE(SUM(amount), 0) FROM view_finance_ledger WHERE date BETWEEN :from AND :to',
    [':from' => $prevFrom, ':to' => $prevTo],
    0.0,
    $ledgerOkPrev
);

$invoiceCostCurrent = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0) FROM documents WHERE type = 'invoice_cost' AND issue_date BETWEEN :from AND :to",
    [':from' => $dateFrom, ':to' => $dateTo]
);
$fixedCostCurrent = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0) FROM finance_items WHERE item_type = 'FIXED_COST' AND status = 'approved' AND issue_date BETWEEN :from AND :to",
    [':from' => $dateFrom, ':to' => $dateTo]
);
$laborCostCurrent = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(final_cost), 0) FROM work_logs WHERE status = 'approved' AND date BETWEEN :from AND :to",
    [':from' => $dateFrom, ':to' => $dateTo]
);
$cashCostCurrent = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount), 0) FROM worker_expenses WHERE status = 'approved' AND date BETWEEN :from AND :to",
    [':from' => $dateFrom, ':to' => $dateTo]
);

$invoiceCostPrev = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0) FROM documents WHERE type = 'invoice_cost' AND issue_date BETWEEN :from AND :to",
    [':from' => $prevFrom, ':to' => $prevTo]
);
$fixedCostPrev = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0) FROM finance_items WHERE item_type = 'FIXED_COST' AND status = 'approved' AND issue_date BETWEEN :from AND :to",
    [':from' => $prevFrom, ':to' => $prevTo]
);
$laborCostPrev = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(final_cost), 0) FROM work_logs WHERE status = 'approved' AND date BETWEEN :from AND :to",
    [':from' => $prevFrom, ':to' => $prevTo]
);
$cashCostPrev = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount), 0) FROM worker_expenses WHERE status = 'approved' AND date BETWEEN :from AND :to",
    [':from' => $prevFrom, ':to' => $prevTo]
);

$fallbackCostCurrent = $invoiceCostCurrent + $fixedCostCurrent + $laborCostCurrent + $cashCostCurrent;
$fallbackCostPrev = $invoiceCostPrev + $fixedCostPrev + $laborCostPrev + $cashCostPrev;

$cost = $ledgerOkCurrent ? $ledgerCostCurrent : $fallbackCostCurrent;
$costPrev = $ledgerOkPrev ? $ledgerCostPrev : $fallbackCostPrev;

$profit = $revenue - $cost;
$profitPrev = $revenuePrev - $costPrev;

$margin = $revenue > 0 ? ($profit / $revenue) * 100 : null;
$marginPrev = $revenuePrev > 0 ? ($profitPrev / $revenuePrev) * 100 : null;

$revenueChange = changePct($revenue, $revenuePrev);
$costChange = changePct($cost, $costPrev);
$profitChange = changePct($profit, $profitPrev);
$marginChange = ($margin !== null && $marginPrev !== null) ? ($margin - $marginPrev) : null;

// ---------------------------------------------------------------------
// Zakontraktowano: umowy + aneksy podpisane w okresie (project_revenues)
// ---------------------------------------------------------------------
$contractedRow = queryAssocSafe(
    $pdo,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_net), 0) AS amount_net
     FROM project_revenues
     WHERE signed_date BETWEEN :from AND :to",
    [':from' => $dateFrom, ':to' => $dateTo],
    ['cnt' => 0, 'amount_net' => 0]
);
$contracted      = (float)$contractedRow['amount_net'];
$contractedCount = (int)$contractedRow['cnt'];
$contractedPrev  = queryScalarSafe(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0) FROM project_revenues WHERE signed_date BETWEEN :from AND :to",
    [':from' => $prevFrom, ':to' => $prevTo]
);
$contractedChange = changePct($contracted, $contractedPrev);

// ---------------------------------------------------------------------
// Trend 12 miesięcy (wstecz od dateTo) — sprzedaż, kasa, koszty, wynik
// ---------------------------------------------------------------------
$trendLabels   = [];
$trendRevenue  = [];
$trendCash     = [];
$trendCosts    = [];
$trendProfit   = [];
$monthShort    = ['sty','lut','mar','kwi','maj','cze','lip','sie','wrz','paź','lis','gru'];

$trendCursor = (new DateTime($dateTo))->modify('first day of this month')->modify('-11 months');
$trendEnd    = (new DateTime($dateTo))->modify('first day of this month');
while ($trendCursor <= $trendEnd) {
    $mFrom = $trendCursor->format('Y-m-01');
    $mTo   = $trendCursor->format('Y-m-t');
    $rev   = analyticsRevenueTotal($pdo, $mFrom, $mTo);
    $cash  = analyticsCashInTotal($pdo, $mFrom, $mTo);
    $costs = analyticsCosts($pdo, $mFrom, $mTo);
    $profit_ = $rev - $costs['total'];
    $trendLabels[]  = $monthShort[(int)$trendCursor->format('n') - 1] . ' ' . $trendCursor->format('y');
    $trendRevenue[] = round($rev, 2);
    $trendCash[]    = round($cash, 2);
    $trendCosts[]   = round($costs['total'], 2);
    $trendProfit[]  = round($profit_, 2);
    $trendCursor->modify('+1 month');
}

// ---------------------------------------------------------------------
// Operacyjne KPI: naleznosci / zobowiazania / workflow
// ---------------------------------------------------------------------

// Należności otwarte (FV draft/issued) — kwoty NETTO
$openSales = queryAssocSafe(
    $pdo,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_net), 0) AS amount
     FROM invoices_sale
     WHERE status IN ('draft', 'issued') AND exclude_from_analytics = 0",
    [],
    ['cnt' => 0, 'amount' => 0]
);

// Zaległe FV sprzedaży (issued + po terminie) — count i NETTO
$overdueSalesRow = queryAssocSafe(
    $pdo,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_net), 0) AS amount
     FROM invoices_sale
     WHERE status = 'issued' AND due_date IS NOT NULL AND due_date < CURDATE()
       AND exclude_from_analytics = 0",
    [],
    ['cnt' => 0, 'amount' => 0]
);
$overdueSales       = (int)$overdueSalesRow['cnt'];
$overdueSalesAmount = (float)$overdueSalesRow['amount'];

// Zobowiązania otwarte (FV koszt nieopłacone) — NETTO
$openCosts = queryAssocSafe(
    $pdo,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_net), 0) AS amount
     FROM fakturownia_cost_invoices
     WHERE COALESCE(payment_status, '') NOT IN ('paid', 'settled')",
    [],
    ['cnt' => 0, 'amount' => 0]
);

// Zaległe koszty (po terminie, nieopłacone) — count i NETTO
$overdueCostsRow = queryAssocSafe(
    $pdo,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_net), 0) AS amount
     FROM fakturownia_cost_invoices
     WHERE due_date IS NOT NULL
       AND due_date < CURDATE()
       AND COALESCE(payment_status, '') NOT IN ('paid', 'settled')",
    [],
    ['cnt' => 0, 'amount' => 0]
);
$overdueCosts       = (int)$overdueCostsRow['cnt'];
$overdueCostsAmount = (float)$overdueCostsRow['amount'];

$workflow = queryAssocSafe(
    $pdo,
    "SELECT
        SUM(CASE WHEN workflow_status = 'new' THEN 1 ELSE 0 END) AS wf_new,
        SUM(CASE WHEN workflow_status = 'assigned' THEN 1 ELSE 0 END) AS wf_assigned,
        SUM(CASE WHEN workflow_status = 'accepted' THEN 1 ELSE 0 END) AS wf_accepted
     FROM fakturownia_cost_invoices",
    [],
    ['wf_new' => 0, 'wf_assigned' => 0, 'wf_accepted' => 0]
);

$acceptedWithoutAlloc = queryScalarSafe(
    $pdo,
    "SELECT COUNT(*)
     FROM fakturownia_cost_invoices ci
     LEFT JOIN (
        SELECT cost_invoice_id, COALESCE(SUM(amount_gross), 0) AS ag
        FROM fakturownia_cost_allocations
        GROUP BY cost_invoice_id
     ) a ON a.cost_invoice_id = ci.id
     WHERE ci.workflow_status IN ('accepted', 'assigned')
       AND COALESCE(a.ag, 0) < 0.01",
    []
);

$ksefPendingOld = queryScalarSafe(
    $pdo,
    "SELECT COUNT(*)
     FROM fakturownia_invoices
     WHERE gov_status = 'pending'
       AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)",
    []
);

// ---------------------------------------------------------------------
// Top projekty (skrot)
// ---------------------------------------------------------------------

$topProjects = queryAllSafe(
    $pdo,
    "SELECT
        project_id,
        project_name,
        status,
        total_revenue,
        current_profit,
        CASE WHEN total_revenue > 0 THEN (current_profit / total_revenue) * 100 ELSE NULL END AS margin_percent
     FROM view_project_finances
     WHERE status IN ('active', 'finished')
       AND (total_revenue > 0 OR current_profit != 0)
     ORDER BY current_profit DESC
     LIMIT 5"
);

$alerts = [];
if ($margin !== null && $margin < 20) {
    $alerts[] = ['level' => 'warn', 'title' => 'Niska marza', 'text' => 'Marza miesieczna jest ponizej 20%.'];
}
if ($profit < 0 && $revenue > 0) {
    $alerts[] = ['level' => 'danger', 'title' => 'Ujemny wynik', 'text' => 'Koszty przewyzszaja przychody w wybranym okresie.'];
}
if ($overdueSales > 0) {
    $alerts[] = ['level' => 'warn', 'title' => 'Zalegle naleznosci', 'text' => (int)$overdueSales . ' faktur sprzedazowych jest po terminie.'];
}
if ($acceptedWithoutAlloc > 0) {
    $alerts[] = ['level' => 'warn', 'title' => 'Brak alokacji kosztow', 'text' => (int)$acceptedWithoutAlloc . ' kosztow accepted/assigned bez alokacji.'];
}
if ($ksefPendingOld > 0) {
    $alerts[] = ['level' => 'warn', 'title' => 'KSeF pending > 48h', 'text' => (int)$ksefPendingOld . ' faktur sprzedazowych czeka zbyt dlugo.'];
}
if (empty($alerts)) {
    $alerts[] = ['level' => 'ok', 'title' => 'Brak krytycznych alertow', 'text' => 'Na ten moment nie ma sygnalow wymagajacych pilnej reakcji.'];
}

$sidebarQuickActions = [
    'Analiza' => [
        ['label' => 'Analiza szczegolowa', 'url' => url('finanse.overview', ['date_from' => $dateFrom, 'date_to' => $dateTo]), 'keywords' => 'analiza szczegolowa trend kpi marza'],
        ['label' => 'Raport kosztow projektow', 'url' => url('projekty.raporty', ['date_from' => $dateFrom, 'date_to' => $dateTo]), 'keywords' => 'raport projekty koszty sprzedaz zysk marza controlling eksport csv'],
        ['label' => 'Finanse wlasciciela', 'url' => url('finanse.wlasciciel', ['filter_type' => 'month', 'month' => $period]), 'keywords' => 'wlasciciel wynik firmy dom'],
        ['label' => 'Eksport danych', 'url' => url('finanse.export'), 'keywords' => 'eksport json dane'],
    ],
    'Faktury' => [
        ['label' => 'Centrum faktur sprzedazowych', 'url' => url('finanse.faktury-sprzedazowe'), 'keywords' => 'sprzedazowe invoice sale retention'],
        ['label' => 'Centrum faktur kosztowych', 'url' => url('finanse.fakturownia-cost-inbox'), 'keywords' => 'kosztowe inbox workflow alokacje'],
        ['label' => 'Rekonsyliacja Fakturowni', 'url' => url('finanse.fakturownia-reconciliation'), 'keywords' => 'rekonsyliacja mapowania ksef'],
    ],
    'Koszty' => [
        ['label' => 'Wszystkie koszty', 'url' => url('finanse.koszty-wszystkie'), 'keywords' => 'wszystkie koszty zbiorczy'],
        ['label' => 'Wydatki firmowe', 'url' => url('finanse.koszty-stale'), 'keywords' => 'wydatki firmowe koszty stale'],
    ],
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Centrum Analiz Finansowych</title>
    <style>
        :root {
            --bg: #f5f7fa;
            --panel: #ffffff;
            --ink: #111827;
            --muted: #6b7280;
            --line: #e5e7eb;
            --blue: #1e3a8a;
            --blue-2: #2563eb;
            --green: #15803d;
            --red: #b91c1c;
            --amber: #b45309;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }
        .dashboard-layout {
            display: flex;
            align-items: flex-start;
            gap: 0;
        }
        .dashboard-content {
            flex: 1;
            min-width: 0;
            padding-left: 16px;
        }
        .sidebar-wrapper.collapsed + .dashboard-content {
            padding-left: 0;
        }

        .sidebar-wrapper {
            position: relative;
            flex-shrink: 0;
            z-index: 10;
        }
        .sidebar-actions {
            background: linear-gradient(180deg, var(--blue) 0%, #0f172a 100%);
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
            position: absolute;
            top: 20px;
            right: -14px;
            width: 28px; height: 28px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 20;
            color: var(--blue);
            transition: all 0.3s ease;
        }
        .toggle-sidebar-btn:hover { background: #f8fafc; transform: scale(1.1); }
        .sidebar-wrapper.collapsed .toggle-sidebar-btn { right: -32px; transform: rotate(180deg); }

        .sidebar-content-inner { width: 240px; }
        .sidebar-search { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-search input {
            width: 100%;
            padding: 8px 12px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            border-radius: 7px;
            font-size: 12px;
            transition: all 0.2s;
        }
        .sidebar-search input::placeholder { color: #93c5fd; }
        .sidebar-search input:focus { outline: none; background: rgba(255,255,255,0.15); border-color: #60a5fa; }
        .sidebar-actions-body { padding: 10px; }
        .sidebar-section {
            margin-bottom: 4px;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 7px;
            overflow: hidden;
        }
        .sidebar-section-title {
            font-size: 11px;
            color: #93c5fd;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            list-style: none;
            margin: 0;
            padding: 8px 10px;
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.04);
        }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after {
            content: '›';
            font-size: 14px;
            color: rgba(255,255,255,0.4);
            transition: transform 0.2s ease;
            line-height: 1;
        }
        .sidebar-section[open] .sidebar-section-title::after { transform: rotate(90deg); }
        .sidebar-section-links { padding: 4px 6px 6px; }
        .sidebar-actions a {
            display: block;
            padding: 7px 10px;
            margin-bottom: 2px;
            color: #e2e8f0;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.15s ease;
            font-size: 12px;
            font-weight: 500;
        }
        .sidebar-actions a:hover {
            background: rgba(255,255,255,0.12);
            color: white;
            padding-left: 14px;
        }

        /* Lokalny override: header ma byc nizszy jak na dashboard.php */
        .header {
            box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
        }
        .header-content {
            max-width: 1600px !important;
            padding: 15px 30px !important;
            justify-content: space-between !important;
            align-items: center !important;
            flex-wrap: nowrap !important;
        }
        .logo-section,
        .logo-link {
            gap: 15px !important;
            align-items: center !important;
        }
        .logo-section img {
            height: 40px !important;
        }
        .logo-text h1 {
            font-size: 20px !important;
            letter-spacing: -0.5px !important;
            margin: 0 !important;
            color: #1f2937 !important;
        }
        .logo-text p {
            font-size: 12px !important;
            margin: 0 !important;
            color: #6b7280 !important;
        }
        .user-section {
            display: flex !important;
            align-items: center !important;
            gap: 20px !important;
            flex-wrap: nowrap !important;
        }
        .user-name {
            font-weight: 600 !important;
            font-size: 14px !important;
            color: #333 !important;
        }
        /* Dashboard.php nie pokazuje dzwonka w headerze */
        .alerts-dropdown {
            display: none !important;
        }

        .hero {
            background: linear-gradient(135deg, var(--blue) 0%, #0f172a 100%);
            color: #fff;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .hero h1 {
            margin: 0 0 6px;
            font-size: 30px;
        }
        .hero p {
            margin: 0;
            color: #cbd5e1;
            font-size: 14px;
        }
        .hero-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-self: flex-start;
        }
        .hero-links a {
            text-decoration: none;
            color: #fff;
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            background: rgba(255,255,255,0.06);
        }
        .hero-links a:hover { background: rgba(255,255,255,0.12); }

        .filter-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid var(--line); overflow: hidden; margin-bottom: 16px; }
        .spx-filter-bar { padding: 12px 20px; background: white; border-bottom: 1px solid var(--line); display: flex; gap: 8px; align-items: flex-end; flex-wrap: nowrap; }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .spx-filter-group.fg-month { flex: 1.2 1 0; }
        .spx-filter-group.fg-year  { flex: 0.7 1 0; }
        .spx-filter-group.fg-date  { flex: 1   1 0; }
        .spx-filter-group label { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .spx-filter-group select,
        .spx-filter-group input[type="date"] { padding: 0 8px; height: 38px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; background: white; font-family: inherit; transition: border-color 0.15s; width: 100%; }
        .spx-filter-group select:focus,
        .spx-filter-group input[type="date"]:focus { outline: none; border-color: var(--blue-2); box-shadow: 0 0 0 2px rgba(37,99,235,0.1); }
        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: white; border: 1px solid var(--line); border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; white-space: nowrap; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: var(--blue-2); color: var(--blue-2); }
        .spx-quick-btn.active { background: var(--blue-2); border-color: var(--blue-2); color: white; font-weight: 600; }
        .btn { height: 38px; border-radius: 6px; border: 1px solid var(--line); background: white; color: var(--ink); padding: 0 14px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; font-family: inherit; transition: all 0.15s; }
        .btn:hover { background: #f9fafb; border-color: #cbd5e1; }
        .btn-primary { background: var(--blue-2); color: #fff; border-color: var(--blue-2); }
        .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .btn-secondary { background: white; color: var(--muted); border-color: var(--line); }
        .btn-secondary:hover { background: #f9fafb; border-color: #cbd5e1; }

        /* =================================================
         * ANALIZA FINANSOWA — 4 grupy KPI (spójne ze styl.md)
         * ================================================= */
        .fin-analysis-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }
        .fin-analysis-header h2 {
            font-size: 18px; font-weight: 700; color: #1f2937; letter-spacing: -0.2px; margin: 0;
        }
        .fin-analysis-header .fin-legend {
            font-size: 12px; color: #6b7280;
        }
        .fin-group {
            display: flex; flex-direction: column; gap: 10px;
            margin-bottom: 20px;
        }
        .fin-group-title {
            font-size: 11px; text-transform: uppercase; font-weight: 700;
            color: #6b7280; letter-spacing: 0.5px; padding: 0 2px;
        }
        .fin-group-grid {
            display: grid; gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .fin-kpi {
            position: relative;
            background: #fff;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 2px solid transparent;
            transition: border-color 0.15s;
        }
        .fin-kpi:hover { border-color: #667eea; }
        .fin-kpi-label {
            font-size: 12px; color: #666; text-transform: uppercase;
            letter-spacing: 0.5px; font-weight: 600; margin-bottom: 8px;
        }
        .fin-kpi-value {
            font-size: 24px; font-weight: 700; color: #667eea; line-height: 1.2;
        }
        .fin-kpi-sub {
            font-size: 11px; color: #999; margin-top: 6px; line-height: 1.4;
        }
        .fin-kpi-delta {
            display: inline-block; margin-top: 8px;
            font-size: 11px; font-weight: 600;
            padding: 3px 8px; border-radius: 999px;
        }
        .fin-kpi-delta.pos { background: #dcfce7; color: #166534; }
        .fin-kpi-delta.neg { background: #fee2e2; color: #991b1b; }
        .fin-kpi-delta.neu { background: #f3f4f6; color: #4b5563; }
        .fin-kpi.sales .fin-kpi-value     { color: #16a34a; }
        .fin-kpi.cash  .fin-kpi-value     { color: #059669; }
        .fin-kpi.overdue .fin-kpi-value   { color: #dc2626; }
        .fin-kpi.receivables .fin-kpi-value { color: #0891b2; }
        .fin-kpi.payables .fin-kpi-value  { color: #b45309; }
        .fin-kpi.costs .fin-kpi-value     { color: #dc2626; }
        .fin-kpi.profit.pos .fin-kpi-value { color: #16a34a; }
        .fin-kpi.profit.neg .fin-kpi-value { color: #dc2626; }
        .fin-kpi.margin .fin-kpi-value    { color: #0891b2; }

        /* Tooltip bez ikony, natychmiastowy */
        .fin-kpi[data-tooltip] { cursor: help; }
        .fin-kpi[data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute; bottom: 100%; left: 50%;
            transform: translateX(-50%) translateY(-8px);
            background: #1f2937; color: #f9fafb;
            padding: 10px 14px; border-radius: 8px;
            font-size: 12px; font-weight: 500; line-height: 1.5;
            letter-spacing: normal; text-transform: none; text-align: left;
            white-space: normal; width: 260px;
            z-index: 100; box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        }
        .fin-kpi[data-tooltip]:hover::before {
            content: ''; position: absolute; bottom: 100%; left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent; border-top-color: #1f2937;
            z-index: 100;
        }

        /* Alerty kontrolera */
        .fin-alerts {
            display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            margin-bottom: 22px;
        }
        .fin-alert {
            display: flex; gap: 10px; align-items: flex-start;
            background: #fff; border-radius: 8px; padding: 12px 14px;
            border-left: 3px solid #9ca3af;
        }
        .fin-alert.warning { border-left-color: #f59e0b; background: #fffbeb; }
        .fin-alert.danger  { border-left-color: #dc2626; background: #fef2f2; }
        .fin-alert.ok      { border-left-color: #16a34a; background: #f0fdf4; }
        .fin-alert-body    { flex: 1; }
        .fin-alert-title   { font-size: 13px; font-weight: 700; color: #1f2937; margin-bottom: 2px; }
        .fin-alert-text    { font-size: 12px; color: #475569; line-height: 1.5; }

        /* Trend 12m + donut wrappers */
        .fin-chart-wrap {
            background: #fff; border-radius: 12px; padding: 22px 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 22px;
        }
        .fin-chart-head {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
        }
        .fin-chart-head h3 {
            font-size: 16px; font-weight: 700; color: #1f2937; margin: 0; letter-spacing: -0.2px;
        }
        .fin-chart-legend { display: flex; gap: 14px; font-size: 12px; color: #6b7280; flex-wrap: wrap; }
        .fin-chart-legend span { display: inline-flex; align-items: center; gap: 6px; }
        .fin-chart-legend i {
            width: 10px; height: 10px; border-radius: 2px; display: inline-block;
        }
        .fin-trend-chart   { height: 260px; position: relative; }
        .fin-donut-wrap    { position: relative; height: 260px; display: flex; align-items: center; justify-content: center; }
        .fin-donut-wrap canvas { max-height: 240px; }

        .fin-two-col {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 22px;
        }
        @media (max-width: 1100px) { .fin-two-col { grid-template-columns: 1fr; } }

        .fin-top-list {
            list-style: none; margin: 0; padding: 0; display: grid; gap: 10px;
        }
        .fin-top-item {
            display: grid; grid-template-columns: 22px 1fr auto;
            align-items: center; gap: 12px;
            padding: 8px 10px; border-radius: 8px; background: #f9fafb;
            border: 1px solid #eef2f7;
        }
        .fin-top-rank {
            font-size: 11px; font-weight: 700; color: #94a3b8;
            text-align: center;
        }
        .fin-top-body { min-width: 0; }
        .fin-top-name {
            font-size: 13px; font-weight: 600; color: #1f2937;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .fin-top-bar-track {
            height: 4px; background: #e5e7eb; border-radius: 3px; margin-top: 6px; overflow: hidden;
        }
        .fin-top-bar-fill {
            height: 100%; border-radius: 3px;
        }
        .fin-top-value {
            font-size: 13px; font-weight: 700; white-space: nowrap; font-variant-numeric: tabular-nums;
        }
        .fin-top-value.pos { color: #16a34a; }
        .fin-top-value.neg { color: #dc2626; }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .kpi {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
            position: relative;
        }
        .kpi[data-tooltip] { cursor: help; }
        .kpi[data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute; top: calc(100% + 10px); left: 50%; transform: translateX(-50%);
            background: #111827; color: #f9fafb; padding: 10px 14px; border-radius: 8px;
            font-size: 12.5px; font-weight: 400; line-height: 1.5;
            letter-spacing: normal; text-transform: none;
            white-space: normal; width: max-content; max-width: 280px;
            text-align: left; z-index: 1000;
            opacity: 0; visibility: hidden; pointer-events: none;
            box-shadow: 0 8px 20px rgba(0,0,0,0.22); transition: opacity 0.12s;
        }
        .kpi[data-tooltip]::before {
            content: ""; position: absolute; top: calc(100% + 4px); left: 50%; transform: translateX(-50%);
            border: 6px solid transparent; border-bottom-color: #111827;
            z-index: 1001; opacity: 0; visibility: hidden; pointer-events: none; transition: opacity 0.12s;
        }
        .kpi[data-tooltip]:hover::after,
        .kpi[data-tooltip]:hover::before { opacity: 1; visibility: visible; }
        .kpi-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
            margin-bottom: 6px;
            font-weight: 700;
        }
        .kpi-value {
            font-size: 28px;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 6px;
        }
        .kpi-value.green { color: var(--green); }
        .kpi-value.red { color: var(--red); }
        .kpi-value.blue { color: var(--blue-2); }
        .kpi-sub {
            font-size: 12px;
            color: var(--muted);
        }

        .delta {
            display: inline-block;
            margin-top: 6px;
            border-radius: 999px;
            padding: 4px 8px;
            font-size: 11px;
            font-weight: 700;
        }
        .delta-pos { background: #dcfce7; color: #166534; }
        .delta-neg { background: #fee2e2; color: #991b1b; }
        .delta-neutral { background: #f3f4f6; color: #4b5563; }

        .grid-3 {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
        }
        .card h2 {
            margin: 0 0 10px;
            font-size: 16px;
        }
        .card .hint {
            margin: -4px 0 10px;
            color: var(--muted);
            font-size: 12px;
        }
        .line-item {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            padding: 8px 0;
            border-top: 1px solid #f1f5f9;
            font-size: 14px;
        }
        .line-item:first-of-type { border-top: 0; }
        .line-item strong { font-variant-numeric: tabular-nums; }

        .alert-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }
        .alert-item {
            border-radius: 8px;
            padding: 10px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        .alert-item.ok { background: #ecfdf5; border-color: #bbf7d0; }
        .alert-item.warn { background: #fff7ed; border-color: #fed7aa; }
        .alert-item.danger { background: #fef2f2; border-color: #fecaca; }
        .alert-title { font-weight: 700; margin-bottom: 3px; font-size: 13px; }
        .alert-text { font-size: 12px; color: #374151; }

        .project-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }
        .project-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px;
            background: #fbfdff;
        }
        .project-name {
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .project-meta {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            font-size: 12px;
            color: #4b5563;
        }
        .project-profit.pos { color: var(--green); font-weight: 700; }
        .project-profit.neg { color: var(--red); font-weight: 700; }

        @media (max-width: 1140px) {
            .grid-3 { grid-template-columns: 1fr; }
        }
        @media (max-width: 900px) {
            .dashboard-layout { flex-direction: column; }
            .sidebar-wrapper { width: 100%; margin-bottom: 14px; }
            .sidebar-actions { width: 100%; height: auto; position: static; max-height: 260px; border-radius: 12px; }
            .toggle-sidebar-btn { display: none; }
            .sidebar-wrapper.collapsed .sidebar-actions { width: 100%; height: 0; padding: 0; }
            .dashboard-content { padding-left: 0 !important; }
            .hero { border-radius: 10px; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

<div class="container">
    <div class="dashboard-layout">
        <div class="sidebar-wrapper collapsed" id="sidebarWrapper">
            <div class="toggle-sidebar-btn" onclick="toggleSidebar()" title="Zwin/Rozwin panel">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
            </div>
            <aside class="sidebar-actions">
                <div class="sidebar-content-inner">
                    <div class="sidebar-search">
                        <input type="text" id="actionSearch" placeholder="Szukaj szybkich akcji..." autocomplete="off">
                    </div>
                    <div class="sidebar-actions-body">
                        <?php foreach ($sidebarQuickActions as $sectionName => $sectionLinks): ?>
                            <details class="sidebar-section">
                                <summary class="sidebar-section-title"><?php echo e($sectionName); ?></summary>
                                <div class="sidebar-section-links">
                                    <?php foreach ($sectionLinks as $link): ?>
                                        <a href="<?php echo e($link['url']); ?>" data-keywords="<?php echo e($link['keywords']); ?>">
                                            <?php echo e($link['label']); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </div>
            </aside>
        </div>

        <div class="dashboard-content">
            <section class="hero">
                <div>
                    <h1>Centrum Analiz Finansowych</h1>
                    <p>Okres: <strong><?php echo e($periodLabel); ?></strong> | Uzytkownik: <?php echo e($userName); ?></p>
                </div>
                <div class="hero-links">
                    <a href="<?php echo url('finanse.overview', ['date_from' => $dateFrom, 'date_to' => $dateTo]); ?>">Analiza szczegółowa</a>
                    <a href="<?php echo url('projekty.raporty', ['date_from' => $dateFrom, 'date_to' => $dateTo]); ?>" title="Pełna tabela projekt-po-projekcie: sprzedaż, koszty, zysk, marża + eksport CSV">Raport kosztów projektów</a>
                    <a href="<?php echo url('finanse.wlasciciel', ['filter_type' => 'month', 'month' => $period]); ?>">Finanse właściciela</a>
                </div>
            </section>

            <?php
            $isToday     = ($dateFrom === $today      && $dateTo === $today);
            $isThisWeek  = ($dateFrom === $weekStart  && $dateTo === $weekEnd);
            $isThisMonth = ($dateFrom === $monthStart && $dateTo === $monthEnd);
            $isThisYear  = ($dateFrom === $yearStart  && $dateTo === $today);
            ?>
            <?php
            $isNoDateSet = false;
            $isToday     = ($dateFrom === $today      && $dateTo === $today);
            $isThisWeek  = ($dateFrom === $weekStart  && $dateTo === $weekEnd);
            $isThisMonth = ($dateFrom === $monthStart && $dateTo === $monthEnd);
            $isThisYear  = ($dateFrom === $yearStart  && $dateTo === $today);
            $qbToday  = '?date_from=' . $today      . '&date_to=' . $today;
            $qbWeek   = '?date_from=' . $weekStart  . '&date_to=' . $weekEnd;
            $qbMonth  = '?date_from=' . $monthStart . '&date_to=' . $monthEnd;
            $qbYear   = '?date_from=' . $yearStart  . '&date_to=' . $today;
            ?>
            <div class="filter-card">
                <form method="GET" action="<?php echo url('finanse'); ?>" class="spx-filter-bar" id="finFilterForm">
                    <div class="spx-filter-group fg-month">
                        <label>Miesiac</label>
                        <select name="month" id="finSelectMonth" onchange="finOnMonthYearChange()">
                            <option value="0" <?php echo $activeMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                            <?php foreach ($monthNames as $mn => $mName): ?>
                                <option value="<?php echo $mn; ?>" <?php echo ($activeMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="spx-filter-group fg-year">
                        <label>Rok</label>
                        <select name="year" id="finSelectYear" onchange="finOnMonthYearChange()">
                            <?php foreach ($yearRange as $yr): ?>
                                <option value="<?php echo $yr; ?>" <?php echo ($activeYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="spx-filter-group fg-date">
                        <label>Od</label>
                        <input type="date" name="date_from" id="finInputDateFrom" value="<?php echo e($dateFrom); ?>">
                    </div>
                    <div class="spx-filter-group fg-date">
                        <label>Do</label>
                        <input type="date" name="date_to" id="finInputDateTo" value="<?php echo e($dateTo); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary" style="height:38px;align-self:flex-end;flex-shrink:0;white-space:nowrap;">Filtruj</button>
                    <?php if (!$isThisMonth): ?>
                        <a href="<?php echo url('finanse'); ?>" class="btn btn-secondary" style="height:38px;align-self:flex-end;flex-shrink:0;white-space:nowrap;">Resetuj</a>
                    <?php endif; ?>
                </form>

                <div class="spx-controls-bar">
                    <div class="spx-controls-left">
                        <a href="<?php echo $qbToday; ?>"  class="spx-quick-btn <?php echo $isToday    ? 'active' : ''; ?>">Dzis</a>
                        <a href="<?php echo $qbWeek; ?>"   class="spx-quick-btn <?php echo $isThisWeek ? 'active' : ''; ?>">Ten tydzien</a>
                        <a href="<?php echo $qbMonth; ?>"  class="spx-quick-btn <?php echo $isThisMonth? 'active' : ''; ?>">Ten miesiac</a>
                        <a href="<?php echo $qbYear; ?>"   class="spx-quick-btn <?php echo $isThisYear ? 'active' : ''; ?>">Ten rok</a>
                    </div>
                    <div class="spx-controls-right" style="font-size:12px;color:#6b7280;">
                        Okres: <strong style="color:#111827;margin-left:4px;"><?php echo e($periodLabel); ?></strong>
                    </div>
                </div>
            </div>

            <?php
            $renderDelta = function (?float $pct, string $noRefText = 'brak porównania', bool $isPp = false) {
                if ($pct === null) {
                    return '<span class="fin-kpi-delta neu">' . $noRefText . '</span>';
                }
                $cls    = $pct >= 0 ? 'pos' : 'neg';
                $prefix = $pct >= 0 ? '+' : '';
                $suffix = $isPp ? ' p.p.' : '%';
                return '<span class="fin-kpi-delta ' . $cls . '">'
                    . $prefix . number_format($pct, 1, ',', ' ') . $suffix
                    . ' vs poprz.</span>';
            };
            ?>

            <div class="fin-analysis-header">
                <h2>Przegląd finansów</h2>
                <div class="fin-legend">Wszystkie wartości netto · najedź myszką na kafelek, żeby zobaczyć, co wchodzi w skład</div>
            </div>

            <?php /* ---------- GRUPA 1: SPRZEDAŻ I KONTRAKTY ---------- */ ?>
            <div class="fin-group">
                <div class="fin-group-title">Sprzedaż i kontrakty</div>
                <div class="fin-group-grid">
                    <div class="fin-kpi sales" data-tooltip="Suma umów i aneksów podpisanych w wybranym okresie (project_revenues po signed_date). Pipeline — to co firma ma do zrealizowania.">
                        <div class="fin-kpi-label">Zakontraktowano</div>
                        <div class="fin-kpi-value"><?php echo formatMoney($contracted); ?></div>
                        <div class="fin-kpi-sub"><?php echo $contractedCount; ?> umów/aneksów</div>
                        <?php echo $renderDelta($contractedChange); ?>
                    </div>
                    <div class="fin-kpi sales" data-tooltip="Faktury sprzedażowe netto wg daty wystawienia + wpisy pozafakturowe (PZF). Pomija FV anulowane i wersje robocze.">
                        <div class="fin-kpi-label">Sprzedaż (FV + PZF)</div>
                        <div class="fin-kpi-value"><?php echo formatMoney($revenue); ?></div>
                        <div class="fin-kpi-sub">
                            FV: <?php echo formatMoney($revenueFv); ?>
                            <?php if ($revenuePzf > 0): ?>
                                · PZF: <?php echo formatMoney($revenuePzf); ?>
                            <?php endif; ?>
                        </div>
                        <?php echo $renderDelta($revenueChange); ?>
                    </div>
                    <div class="fin-kpi overdue" data-tooltip="Faktury sprzedażowe wystawione, których termin płatności już minął. Stan na dziś — niezależny od filtra okresu. Do pilnego kontaktu z klientami.">
                        <div class="fin-kpi-label">Zaległe FV sprzedaży</div>
                        <div class="fin-kpi-value"><?php echo $overdueSales > 0 ? formatMoney($overdueSalesAmount) : '—'; ?></div>
                        <div class="fin-kpi-sub">
                            <?php echo $overdueSales > 0 ? ($overdueSales . ' faktur po terminie') : 'Wszystko w terminie'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php /* ---------- GRUPA 2: KASA I NALEŻNOŚCI ---------- */ ?>
            <div class="fin-group">
                <div class="fin-group-title">Kasa i należności</div>
                <div class="fin-group-grid">
                    <div class="fin-kpi cash" data-tooltip="Pieniądze, które w okresie faktycznie wpłynęły na konto: zapłacone FV (wg daty wpłaty) + PZF ze statusem opłacony.">
                        <div class="fin-kpi-label">Wpłynęło na konto</div>
                        <div class="fin-kpi-value"><?php echo formatMoney($received); ?></div>
                        <div class="fin-kpi-sub">
                            FV: <?php echo formatMoney($receivedFv); ?>
                            <?php if ($receivedPzf > 0): ?>
                                · PZF: <?php echo formatMoney($receivedPzf); ?>
                            <?php endif; ?>
                        </div>
                        <?php echo $renderDelta(changePct($received, $receivedPrev)); ?>
                    </div>
                    <div class="fin-kpi receivables" data-tooltip="Wystawione faktury sprzedażowe (draft + issued) jeszcze nieopłacone — stan na dziś, niezależny od okresu. To pieniądze, które powinny przyjść.">
                        <div class="fin-kpi-label">Należności otwarte</div>
                        <div class="fin-kpi-value"><?php echo formatMoney((float)($openSales['amount'] ?? 0)); ?></div>
                        <div class="fin-kpi-sub"><?php echo (int)($openSales['cnt'] ?? 0); ?> faktur draft/issued</div>
                    </div>
                </div>
            </div>

            <?php /* ---------- GRUPA 3: KOSZTY I ZOBOWIĄZANIA ---------- */ ?>
            <div class="fin-group">
                <div class="fin-group-title">Koszty i zobowiązania</div>
                <div class="fin-group-grid">
                    <div class="fin-kpi costs" data-tooltip="Łączne koszty netto w okresie: faktury kosztowe + wydatki firmowe (koszty stałe) + robocizna (zatwierdzone work-logi) + wydatki pracowników.">
                        <div class="fin-kpi-label">Koszty razem</div>
                        <div class="fin-kpi-value"><?php echo formatMoney($cost); ?></div>
                        <div class="fin-kpi-sub"><?php echo $ledgerOkCurrent ? 'Źródło: view_finance_ledger' : 'Sumy modułowe (fallback)'; ?></div>
                        <?php echo $renderDelta($costChange); ?>
                    </div>
                    <div class="fin-kpi payables" data-tooltip="Faktury kosztowe jeszcze nieopłacone (status inny niż paid/settled) — stan na dziś. To pieniądze, które firma powinna zapłacić dostawcom.">
                        <div class="fin-kpi-label">Zobowiązania otwarte</div>
                        <div class="fin-kpi-value"><?php echo formatMoney((float)($openCosts['amount'] ?? 0)); ?></div>
                        <div class="fin-kpi-sub">
                            <?php echo (int)($openCosts['cnt'] ?? 0); ?> kosztów nieopłaconych
                            <?php if ($overdueCosts > 0): ?>
                                · po terminie: <?php echo $overdueCosts; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="fin-kpi overdue" data-tooltip="Faktury kosztowe po terminie płatności — kwota netto. Stan na dziś, niezależny od okresu.">
                        <div class="fin-kpi-label">Koszty zaległe</div>
                        <div class="fin-kpi-value"><?php echo $overdueCosts > 0 ? formatMoney($overdueCostsAmount) : '—'; ?></div>
                        <div class="fin-kpi-sub">
                            <?php echo $overdueCosts > 0 ? ($overdueCosts . ' faktur po terminie') : 'Brak zaległości'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php /* ---------- GRUPA 4: ZYSK I MARŻA ---------- */ ?>
            <div class="fin-group">
                <div class="fin-group-title">Zysk i marża</div>
                <div class="fin-group-grid">
                    <div class="fin-kpi profit <?php echo $profit >= 0 ? 'pos' : 'neg'; ?>" data-tooltip="Wynik dokumentowy: sprzedaż (FV + PZF) − koszty razem, netto. Teoretyczny zysk wg dokumentów, niezależnie od tego, co faktycznie wpłynęło.">
                        <div class="fin-kpi-label">Wynik netto</div>
                        <div class="fin-kpi-value"><?php echo formatMoney($profit); ?></div>
                        <div class="fin-kpi-sub">Sprzedaż − koszty</div>
                        <?php echo $renderDelta($profitChange); ?>
                    </div>
                    <div class="fin-kpi margin" data-tooltip="Marża dokumentowa = wynik netto / sprzedaż. Pokazuje, ile procent ze sprzedaży zostaje firmie po opłaceniu kosztów. Poniżej 20% — sygnał ostrzegawczy.">
                        <div class="fin-kpi-label">Marża</div>
                        <div class="fin-kpi-value"><?php echo $margin !== null ? number_format($margin, 1, ',', ' ') . '%' : '—'; ?></div>
                        <div class="fin-kpi-sub">Wynik / sprzedaż</div>
                        <?php echo $renderDelta($marginChange, 'brak porównania', true); ?>
                    </div>
                </div>
            </div>

            <?php /* ---------- ALERTY KONTROLERA ---------- */ ?>
            <div class="fin-alerts">
                <?php foreach ($alerts as $a):
                    $level = $a['level'] === 'warn' ? 'warning' : $a['level'];
                ?>
                    <div class="fin-alert <?php echo e($level); ?>">
                        <div class="fin-alert-body">
                            <div class="fin-alert-title"><?php echo e($a['title']); ?></div>
                            <div class="fin-alert-text"><?php echo e($a['text']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php
                $wfNew = (int)($workflow['wf_new'] ?? 0);
                $wfAssigned = (int)($workflow['wf_assigned'] ?? 0);
                $wfAccepted = (int)($workflow['wf_accepted'] ?? 0);
                $wfTotal = $wfNew + $wfAssigned + $wfAccepted;
                if ($wfTotal > 0):
                ?>
                    <div class="fin-alert">
                        <div class="fin-alert-body">
                            <div class="fin-alert-title">Workflow kosztów</div>
                            <div class="fin-alert-text">
                                nowe: <?php echo $wfNew; ?>
                                · przypisane: <?php echo $wfAssigned; ?>
                                · zaakceptowane: <?php echo $wfAccepted; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php /* ---------- TREND 12 MIESIĘCY ---------- */ ?>
            <div class="fin-chart-wrap">
                <div class="fin-chart-head">
                    <h3>Trend 12 miesięcy</h3>
                    <div class="fin-chart-legend">
                        <span><i style="background:#0891b2;"></i> Sprzedaż (FV+PZF)</span>
                        <span><i style="background:#059669;"></i> Wpłynęło (kasa)</span>
                        <span><i style="background:#dc2626;"></i> Koszty</span>
                        <span><i style="background:#7c3aed;"></i> Wynik</span>
                    </div>
                </div>
                <div class="fin-trend-chart"><canvas id="finCockpitTrend"></canvas></div>
            </div>

            <?php /* ---------- DONUT KOSZTÓW + TOP PROJEKTY ---------- */ ?>
            <div class="fin-two-col">
                <div class="fin-chart-wrap">
                    <div class="fin-chart-head">
                        <h3>Struktura kosztów w okresie</h3>
                        <div class="fin-chart-legend">Razem: <strong style="color:#1f2937;"><?php echo formatMoney($cost); ?></strong></div>
                    </div>
                    <?php
                    $costsBreakdown = [
                        ['label' => 'Faktury kosztowe', 'value' => $invoiceCostCurrent, 'color' => '#1d4ed8'],
                        ['label' => 'Wydatki firmowe', 'value' => $fixedCostCurrent,   'color' => '#b45309'],
                        ['label' => 'Robocizna',       'value' => $laborCostCurrent,   'color' => '#c026d3'],
                        ['label' => 'Wydatki pracowników', 'value' => $cashCostCurrent, 'color' => '#7c3aed'],
                    ];
                    $costsTotal = array_sum(array_column($costsBreakdown, 'value'));
                    ?>
                    <?php if ($costsTotal > 0): ?>
                        <div class="fin-donut-wrap"><canvas id="finCockpitDonut"></canvas></div>
                        <ul class="fin-top-list" style="margin-top:12px;">
                            <?php foreach ($costsBreakdown as $cb):
                                $pct = $costsTotal > 0 ? ($cb['value'] / $costsTotal * 100) : 0;
                            ?>
                                <li class="fin-top-item" style="background:#fff;border-color:#eef2f7;">
                                    <div class="fin-top-rank" style="color:<?php echo $cb['color']; ?>;">●</div>
                                    <div class="fin-top-body">
                                        <div class="fin-top-name"><?php echo e($cb['label']); ?></div>
                                        <div class="fin-top-bar-track"><div class="fin-top-bar-fill" style="width:<?php echo max(2, $pct); ?>%;background:<?php echo $cb['color']; ?>;"></div></div>
                                    </div>
                                    <div class="fin-top-value" style="color:#1f2937;"><?php echo formatMoney($cb['value']); ?> <span style="color:#94a3b8;font-weight:500;">(<?php echo number_format($pct, 0); ?>%)</span></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div style="padding:40px 0;text-align:center;color:#94a3b8;font-size:13px;">
                            Brak kosztów w wybranym okresie.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="fin-chart-wrap">
                    <div class="fin-chart-head">
                        <h3>Top 5 projektów wg wyniku</h3>
                        <a href="<?php echo url('finanse.overview', ['date_from' => $dateFrom, 'date_to' => $dateTo]); ?>" style="font-size:12px;color:#667eea;text-decoration:none;font-weight:600;">Analiza szczegółowa →</a>
                    </div>
                    <?php if (empty($topProjects)): ?>
                        <div style="padding:30px 0;text-align:center;color:#94a3b8;font-size:13px;">
                            Brak danych projektowych do wyświetlenia.
                        </div>
                    <?php else: ?>
                        <?php $maxProfit = 0; foreach ($topProjects as $p) { $maxProfit = max($maxProfit, abs((float)($p['current_profit'] ?? 0))); } ?>
                        <ul class="fin-top-list">
                            <?php foreach ($topProjects as $i => $project):
                                $pr = (float)($project['current_profit'] ?? 0);
                                $mg = $project['margin_percent'];
                                $pct = $maxProfit > 0 ? (abs($pr) / $maxProfit * 100) : 0;
                                $isPos = $pr >= 0;
                            ?>
                                <li class="fin-top-item">
                                    <div class="fin-top-rank">#<?php echo $i + 1; ?></div>
                                    <div class="fin-top-body">
                                        <div class="fin-top-name">
                                            <a href="<?php echo url('projekty.view', ['id' => (int)$project['project_id']]); ?>" style="color:inherit;text-decoration:none;"><?php echo e($project['project_name'] ?? ('Projekt #' . (int)($project['project_id'] ?? 0))); ?></a>
                                        </div>
                                        <div class="fin-top-bar-track"><div class="fin-top-bar-fill" style="width:<?php echo max(2, $pct); ?>%;background:<?php echo $isPos ? '#16a34a' : '#dc2626'; ?>;"></div></div>
                                        <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
                                            Marża: <?php echo $mg !== null ? number_format((float)$mg, 1, ',', ' ') . '%' : '—'; ?>
                                        </div>
                                    </div>
                                    <div class="fin-top-value <?php echo $isPos ? 'pos' : 'neg'; ?>"><?php echo formatMoney($pr); ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const wrapper = document.getElementById('sidebarWrapper');
        if (wrapper) {
            const key = 'sprutex_finanse_sidebar_collapsed';
            const stored = localStorage.getItem(key);
            const isCollapsed = stored === null ? true : stored === 'true';
            if (isCollapsed) {
                wrapper.classList.add('collapsed');
            } else {
                wrapper.classList.remove('collapsed');
            }
        }
    });

    function toggleSidebar() {
        const wrapper = document.getElementById('sidebarWrapper');
        if (!wrapper) return;
        wrapper.classList.toggle('collapsed');
        const isCollapsed = wrapper.classList.contains('collapsed');
        localStorage.setItem('sprutex_finanse_sidebar_collapsed', isCollapsed ? 'true' : 'false');
    }

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
                    if (section) {
                        if (!section.open) {
                            section.open = true;
                            section._autoOpened = true;
                        }
                        visibleSections.add(section);
                    }
                } else {
                    link.style.display = 'none';
                }
            });
            sections.forEach(s => { s.style.display = visibleSections.has(s) ? '' : 'none'; });
        });
    })();

    function finOnMonthYearChange() {
        var month = parseInt(document.getElementById('finSelectMonth').value);
        var year  = parseInt(document.getElementById('finSelectYear').value);
        var pad   = function(n) { return String(n).padStart(2, '0'); };
        if (month >= 1 && month <= 12) {
            var lastDay = new Date(year, month, 0).getDate();
            document.getElementById('finInputDateFrom').value = year + '-' + pad(month) + '-01';
            document.getElementById('finInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
        } else {
            document.getElementById('finInputDateFrom').value = year + '-01-01';
            document.getElementById('finInputDateTo').value   = year + '-12-31';
        }
        document.getElementById('finFilterForm').submit();
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function() {
    if (typeof Chart === 'undefined') return;

    var trendLabels  = <?php echo json_encode($trendLabels, JSON_UNESCAPED_UNICODE); ?>;
    var trendRevenue = <?php echo json_encode($trendRevenue); ?>;
    var trendCash    = <?php echo json_encode($trendCash); ?>;
    var trendCosts   = <?php echo json_encode($trendCosts); ?>;
    var trendProfit  = <?php echo json_encode($trendProfit); ?>;

    var fmt = function(v) {
        return new Intl.NumberFormat('pl-PL', { maximumFractionDigits: 0 }).format(v) + ' zł';
    };

    var trendCanvas = document.getElementById('finCockpitTrend');
    if (trendCanvas) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    { label: 'Sprzedaż (FV+PZF)', data: trendRevenue, borderColor: '#0891b2', backgroundColor: 'rgba(8,145,178,0.08)', tension: 0.3, fill: false, pointRadius: 3 },
                    { label: 'Wpłynęło',          data: trendCash,    borderColor: '#059669', backgroundColor: 'rgba(5,150,105,0.08)',  tension: 0.3, fill: false, pointRadius: 3 },
                    { label: 'Koszty',            data: trendCosts,   borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,0.08)',  tension: 0.3, fill: false, pointRadius: 3 },
                    { label: 'Wynik',             data: trendProfit,  borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.08)', tension: 0.3, fill: false, pointRadius: 3 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: { label: function(ctx) { return ctx.dataset.label + ': ' + fmt(ctx.parsed.y); } }
                    }
                },
                scales: {
                    y: { ticks: { callback: function(v) { return fmt(v); }, font: { size: 11 } }, grid: { color: '#f1f5f9' } },
                    x: { ticks: { font: { size: 11 } }, grid: { display: false } }
                }
            }
        });
    }

    var donutCanvas = document.getElementById('finCockpitDonut');
    if (donutCanvas) {
        new Chart(donutCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Faktury kosztowe', 'Wydatki firmowe', 'Robocizna', 'Wydatki pracowników'],
                datasets: [{
                    data: [
                        <?php echo (float)$invoiceCostCurrent; ?>,
                        <?php echo (float)$fixedCostCurrent; ?>,
                        <?php echo (float)$laborCostCurrent; ?>,
                        <?php echo (float)$cashCostCurrent; ?>
                    ],
                    backgroundColor: ['#1d4ed8', '#b45309', '#c026d3', '#7c3aed'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + fmt(ctx.parsed); } } }
                }
            }
        });
    }
})();
</script>
</body>
</html>
