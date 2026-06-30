<?php
/**
 * BRYGAD ERP – Analiza Finansowa (server-side, zgodne ze styl.md)
 *
 * Widok dla wlasciciela: w jednym miejscu wszystko co potrzebuje zeby
 * w 30 sekund zrozumiec stan finansow firmy. Wszystkie wartosci NETTO.
 *
 * Kolejnosc ekranu (wg financecontroller.md):
 *   1. Hero + filtr okresu.
 *   2. 6 KPI z delta vs poprzedni okres.
 *   3. Alerty kontrolera (tylko jesli sa).
 *   4. Trend 12 miesiecy (line chart).
 *   5. Struktura kosztow (donut) + struktura sprzedazy FV/PZF (donut).
 *   6. TOP klienci + TOP pracownicy (horizontal bar).
 *   7. Tabela projektow (sortowana, z eksportem CSV).
 *   8. Wskazniki platnicze (FV po terminie, PZF unpaid, retencja, DSO).
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/includes/analytics.php';

startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

/* -------------------------------------------------------------------------
 * 1. Okres z requestu (rok / miesiac / daty + presety)
 * ---------------------------------------------------------------------- */
$period = analyticsPeriodFromRequest($_GET, date('Y-m-01'), date('Y-m-t'));
$from   = $period['from'];
$to     = $period['to'];
$year   = $period['year'];
$month  = $period['month'];

// Jesli daty sa puste – oznacza to preset "Wszystko" (year=all). Bierzemy
// najwczesniejsza znaleziona date jako from, dzisiaj jako to.
if ($from === '' || $to === '') {
    if ($year === 0) {
        $rowE = analyticsQueryRow(
            $pdo,
            "SELECT LEAST(
                        COALESCE((SELECT MIN(issue_date)  FROM invoices_sale), CURDATE()),
                        COALESCE((SELECT MIN(issue_date)  FROM sales_noninvoice_entries), CURDATE()),
                        COALESCE((SELECT MIN(signed_date) FROM project_revenues), CURDATE()),
                        COALESCE((SELECT MIN(issue_date)  FROM fakturownia_cost_invoices), CURDATE())
                    ) AS earliest"
        );
        $from = !empty($rowE['earliest']) ? (string)$rowE['earliest'] : '2020-01-01';
        $to   = date('Y-m-d');
    } else {
        $from = date('Y-m-01');
        $to   = date('Y-m-t');
    }
}

$prev     = analyticsPreviousPeriod($from, $to);
$prevFrom = $prev['from'];
$prevTo   = $prev['to'];

/* -------------------------------------------------------------------------
 * 2. ZAKONTRAKTOWANO (umowy + aneksy w okresie)
 * ---------------------------------------------------------------------- */
$contracted = analyticsQueryScalar(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0)
     FROM project_revenues
     WHERE signed_date BETWEEN :from AND :to",
    [':from' => $from, ':to' => $to]
);
$contractedPrev = analyticsQueryScalar(
    $pdo,
    "SELECT COALESCE(SUM(amount_net), 0)
     FROM project_revenues
     WHERE signed_date BETWEEN :from AND :to",
    [':from' => $prevFrom, ':to' => $prevTo]
);
$contractedCount = (int)analyticsQueryScalar(
    $pdo,
    "SELECT COUNT(*) FROM project_revenues
     WHERE signed_date BETWEEN :from AND :to",
    [':from' => $from, ':to' => $to]
);

/* -------------------------------------------------------------------------
 * 3. SPRZEDAZ / KASA / KOSZTY / ZYSK (przez helpery)
 * ---------------------------------------------------------------------- */
$summary     = analyticsFinancialSummary($pdo, $from,     $to);
$summaryPrev = analyticsFinancialSummary($pdo, $prevFrom, $prevTo);

// Delty procentowe
$dContracted = analyticsDeltaPct($contracted,              $contractedPrev);
$dRevenue    = analyticsDeltaPct($summary['revenue_total'], $summaryPrev['revenue_total']);
$dCash       = analyticsDeltaPct($summary['cash_total'],    $summaryPrev['cash_total']);
$dCosts      = analyticsDeltaPct($summary['costs']['total'], $summaryPrev['costs']['total']);
$dProfit     = analyticsDeltaPct($summary['profit_doc'],    $summaryPrev['profit_doc']);
$dMargin     = ($summary['margin_doc'] !== null && $summaryPrev['margin_doc'] !== null)
    ? $summary['margin_doc'] - $summaryPrev['margin_doc']
    : null;

/* -------------------------------------------------------------------------
 * 4. TREND 12 MIESIECY (serie: kontraktacja, sprzedaz FV+PZF, kasa, koszty)
 * ---------------------------------------------------------------------- */
$trend12End   = date('Y-m-t', strtotime($to));
$trend12Start = date('Y-m-01', strtotime($trend12End . ' -11 months'));

$trend = [];
$cursor = new DateTime($trend12Start);
$end    = new DateTime($trend12End);
while ($cursor <= $end) {
    $mFrom = $cursor->format('Y-m-01');
    $mTo   = $cursor->format('Y-m-t');

    $mContract = analyticsQueryScalar(
        $pdo,
        "SELECT COALESCE(SUM(amount_net), 0) FROM project_revenues
         WHERE signed_date BETWEEN :from AND :to",
        [':from' => $mFrom, ':to' => $mTo]
    );
    $mRevenueFv  = analyticsRevenueFv($pdo, $mFrom, $mTo);
    $mRevenuePzf = analyticsRevenuePzf($pdo, $mFrom, $mTo);
    $mCashFv     = analyticsCashInFv($pdo, $mFrom, $mTo);
    $mCashPzf    = analyticsCashInPzf($pdo, $mFrom, $mTo);
    $mCosts      = analyticsCosts($pdo, $mFrom, $mTo);

    $monthShort = ['sty','lut','mar','kwi','maj','cze','lip','sie','wrz','paź','lis','gru'];
    $trend[] = [
        'label'    => $monthShort[(int)$cursor->format('n') - 1] . ' ' . $cursor->format('Y'),
        'short'    => $monthShort[(int)$cursor->format('n') - 1] . '.' . $cursor->format('y'),
        'contract' => (float)$mContract,
        'revenue'  => (float)($mRevenueFv + $mRevenuePzf),
        'cash'     => (float)($mCashFv + $mCashPzf),
        'costs'    => (float)$mCosts['total'],
        'profit'   => (float)(($mRevenueFv + $mRevenuePzf) - $mCosts['total']),
    ];

    $cursor->modify('+1 month');
}

/* -------------------------------------------------------------------------
 * 5. TOP 10 KLIENTOW (po przychodach FV w okresie)
 * ---------------------------------------------------------------------- */
$topClients = analyticsQueryAll(
    $pdo,
    "SELECT inv.client_id,
            COALESCE(i.name, 'Brak danych') AS client_name,
            COUNT(inv.id)                   AS invoices_count,
            SUM(CASE
                WHEN inv.financial_effect_kind = 'correction'
                  OR inv.document_kind = 'sale_correction'
                  OR inv.fakturownia_options_json LIKE '%\"kind\":\"correction\"%'
                  OR inv.fakturownia_options_json LIKE '%\"kind\": \"correction\"%'
                THEN COALESCE(inv.correction_effect_net, 0)
                ELSE inv.amount_net
            END)                            AS total_net,
            SUM(CASE WHEN inv.status = 'paid' THEN inv.amount_net ELSE 0 END) AS paid_net
     FROM invoices_sale inv
     LEFT JOIN investors i ON i.id = inv.client_id
     WHERE inv.issue_date BETWEEN :from AND :to
       AND inv.status NOT IN ('cancelled','draft')
       AND inv.exclude_from_analytics = 0
     GROUP BY inv.client_id, i.name
     ORDER BY total_net DESC
     LIMIT 10",
    [':from' => $from, ':to' => $to]
);
$topClientsMax = 0;
foreach ($topClients as $c) { if ((float)$c['total_net'] > $topClientsMax) $topClientsMax = (float)$c['total_net']; }

/* -------------------------------------------------------------------------
 * 6. TOP 10 PRACOWNIKOW (po koszcie robocizny w okresie)
 * ---------------------------------------------------------------------- */
$topWorkers = analyticsQueryAll(
    $pdo,
    "SELECT w.id,
            CONCAT(w.first_name, ' ', w.last_name) AS worker_name,
            SUM(COALESCE(wl.final_cost, wl.system_cost, 0)) AS labor_cost,
            SUM(wl.hours)                                   AS hours
     FROM work_logs wl
     JOIN workers   w ON w.id = wl.worker_id
     WHERE wl.status = 'approved'
       AND wl.date BETWEEN :from AND :to
     GROUP BY w.id, w.first_name, w.last_name
     HAVING labor_cost > 0
     ORDER BY labor_cost DESC
     LIMIT 10",
    [':from' => $from, ':to' => $to]
);
$topWorkersMax = 0;
foreach ($topWorkers as $w) { if ((float)$w['labor_cost'] > $topWorkersMax) $topWorkersMax = (float)$w['labor_cost']; }

/* -------------------------------------------------------------------------
 * 7. PROJEKTY – tabela ze wszystkimi aktywnymi + zakonczonymi w okresie
 * ---------------------------------------------------------------------- */
$projectsRaw = analyticsQueryAll(
    $pdo,
    "SELECT p.id,
            p.name,
            p.status,
            COALESCE(i.name, '—') AS client_name,
            p.contract_amount
     FROM projects p
     LEFT JOIN investors i ON i.id = p.investor_id
     WHERE p.status IN ('active','finished')
     ORDER BY p.status = 'active' DESC, p.name ASC"
);

$projects = [];
foreach ($projectsRaw as $p) {
    $pid = (int)$p['id'];
    $pSum = analyticsFinancialSummary($pdo, $from, $to, $pid);

    // Pomin projekty bez zadnych przeplywow w okresie – zebym nie zasmiecal tabeli.
    if ($pSum['revenue_total'] < 0.005 && $pSum['cash_total'] < 0.005 && $pSum['costs']['total'] < 0.005) {
        continue;
    }

    $projects[] = [
        'id'           => $pid,
        'name'         => (string)$p['name'],
        'status'       => (string)$p['status'],
        'client'       => (string)$p['client_name'],
        'contract'     => (float)($p['contract_amount'] ?? 0),
        'revenue'      => (float)$pSum['revenue_total'],
        'revenue_fv'   => (float)$pSum['revenue_fv'],
        'revenue_pzf'  => (float)$pSum['revenue_pzf'],
        'cash'         => (float)$pSum['cash_total'],
        'costs'        => (float)$pSum['costs']['total'],
        'profit_doc'   => (float)$pSum['profit_doc'],
        'profit_cash'  => (float)$pSum['profit_cash'],
        'margin_doc'   => $pSum['margin_doc'],
    ];
}

// Sortowanie dla tabeli: po zysku dokumentowym malejaco.
usort($projects, fn($a, $b) => $b['profit_doc'] <=> $a['profit_doc']);

/* -------------------------------------------------------------------------
 * 8. WSKAZNIKI PLATNICZE
 * ---------------------------------------------------------------------- */
$today = date('Y-m-d');

$overdueFv = analyticsQueryRow(
    $pdo,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_net),0) AS amount
     FROM invoices_sale
     WHERE status IN ('issued','partially_paid','overdue')
       AND exclude_from_analytics = 0
       AND due_date < :today"
    . ($from !== '' ? " AND issue_date BETWEEN :from AND :to" : ""),
    $from !== ''
        ? [':today' => $today, ':from' => $from, ':to' => $to]
        : [':today' => $today]
);

$unpaidPzf = analyticsQueryRow(
    $pdo,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_net),0) AS amount
     FROM sales_noninvoice_entries
     WHERE payment_status != 'paid'"
    . ($from !== '' ? " AND issue_date BETWEEN :from AND :to" : ""),
    $from !== '' ? [':from' => $from, ':to' => $to] : []
);

$activeRetention = analyticsQueryRow(
    $pdo,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(retention_amount),0) AS amount
     FROM invoice_sale_retentions
     WHERE status IN ('active','due_soon','overdue')"
);

// DSO – srednia dni od issue_date do payment_date dla FV paid w okresie.
$dsoRow = analyticsQueryRow(
    $pdo,
    "SELECT AVG(DATEDIFF(payment_date, issue_date)) AS avg_days,
            COUNT(*) AS cnt
     FROM invoices_sale
     WHERE status = 'paid'
       AND payment_date IS NOT NULL
       AND exclude_from_analytics = 0
       AND payment_date BETWEEN :from AND :to",
    [':from' => $from, ':to' => $to]
);
$dsoDays  = $dsoRow['avg_days'] !== null ? (float)$dsoRow['avg_days'] : null;
$dsoCount = (int)($dsoRow['cnt'] ?? 0);

/* -------------------------------------------------------------------------
 * 9. KONTROLLER – szybkie alerty (max 5)
 * ---------------------------------------------------------------------- */
$alerts = [];
if ($summary['margin_doc'] !== null && $summary['margin_doc'] < 0) {
    $alerts[] = [
        'type'    => 'danger',
        'title'   => 'Ujemny wynik firmy',
        'message' => sprintf('Koszty (%s) przewyzszaja sprzedaz (%s). Sprawdz strukture kosztow i tempo fakturowania.',
            formatMoney($summary['costs']['total']), formatMoney($summary['revenue_total'])),
    ];
} elseif ($summary['margin_doc'] !== null && $summary['margin_doc'] < 10) {
    $alerts[] = [
        'type'    => 'warning',
        'title'   => 'Niska marza dokumentowa',
        'message' => sprintf('Marza %.1f%% – ponizej progu bezpieczenstwa (10%%). Zweryfikuj ceny i koszty.', $summary['margin_doc']),
    ];
}
if ((float)$overdueFv['amount'] > 0) {
    $alerts[] = [
        'type'    => 'warning',
        'title'   => 'Faktury po terminie',
        'message' => sprintf('%d FV po terminie na lacznie %s netto. Zacznij od najstarszych.',
            (int)$overdueFv['cnt'], formatMoney((float)$overdueFv['amount'])),
    ];
}
if ((float)$unpaidPzf['amount'] > 0) {
    $alerts[] = [
        'type'    => 'info',
        'title'   => 'Nieoplacone wpisy pozafakturowe',
        'message' => sprintf('%d PZF bez statusu paid na %s netto. Odznacz oplacone lub zaksieguj.',
            (int)$unpaidPzf['cnt'], formatMoney((float)$unpaidPzf['amount'])),
    ];
}
if ($dCosts !== null && $dCosts > 25) {
    $alerts[] = [
        'type'    => 'warning',
        'title'   => 'Wzrost kosztow',
        'message' => sprintf('Koszty wzrosly o %.1f%% vs poprzedni okres. Zobacz strukture kosztow.', $dCosts),
    ];
}

/* -------------------------------------------------------------------------
 * 10. EKSPORT CSV (projekty w okresie)
 * ---------------------------------------------------------------------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = [];
    foreach ($projects as $p) {
        $rows[] = [
            'Projekt'        => $p['name'],
            'Status'         => $p['status'] === 'active' ? 'Aktywny' : 'Zakonczony',
            'Klient'         => $p['client'],
            'Umowa'          => number_format($p['contract'], 2, ',', ' '),
            'Sprzedaz FV'    => number_format($p['revenue_fv'], 2, ',', ' '),
            'Sprzedaz PZF'   => number_format($p['revenue_pzf'], 2, ',', ' '),
            'Sprzedaz razem' => number_format($p['revenue'], 2, ',', ' '),
            'Wplynelo'       => number_format($p['cash'], 2, ',', ' '),
            'Koszty'         => number_format($p['costs'], 2, ',', ' '),
            'Zysk (dokument)'=> number_format($p['profit_doc'], 2, ',', ' '),
            'Zysk (kasa)'    => number_format($p['profit_cash'], 2, ',', ' '),
            'Marza %'        => $p['margin_doc'] !== null ? number_format($p['margin_doc'], 1, ',', ' ') : '',
        ];
    }
    analyticsExportCsv(
        $rows,
        ['Projekt','Status','Klient','Umowa','Sprzedaz FV','Sprzedaz PZF','Sprzedaz razem','Wplynelo','Koszty','Zysk (dokument)','Zysk (kasa)','Marza %'],
        'analiza-finansowa-projekty-' . $from . '_' . $to . '.csv'
    );
}

/* -------------------------------------------------------------------------
 * 11. URL helper dla zakladek/presetow
 * ---------------------------------------------------------------------- */
$currentParams = [
    'year'      => $_GET['year']      ?? null,
    'month'     => $_GET['month']     ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to'   => $_GET['date_to']   ?? null,
];
$buildUrl = function (array $override) use ($currentParams) {
    $merged = array_merge($currentParams, $override);
    $clean  = array_filter($merged, fn($v) => $v !== null && $v !== '');
    return '?' . http_build_query($clean);
};

$userName = $_SESSION['worker_name'] ?? ($_SESSION['login'] ?? 'Uzytkownik');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?php echo e(APP_NAME); ?> – Analiza finansowa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        /* ------------------------------------------------------------------
           Baza zgodna ze styl.md (1500px, f5f7fa, akcent 667eea)
           ---------------------------------------------------------------- */
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --border-strong: #d1d5db;
            --grid-strong: #2d3748;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --good: #16a34a;
            --warn: #f59e0b;
            --bad: #dc2626;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            padding-bottom: 40px;
        }
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }

        /* Hero */
        .hero {
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
        .hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .btn-hero-primary, .btn-hero-secondary {
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            border: 1px solid #fff;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-hero-primary {
            background: #fff;
            color: var(--primary-blue);
        }
        .btn-hero-primary:hover { background: #f1f5f9; }
        .btn-hero-secondary {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-color: rgba(255,255,255,0.25);
            font-weight: 600;
        }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.2); }

        /* Karta filtrow */
        .filter-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 22px;
            overflow: hidden;
        }
        .spx-filter-bar {
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-strong);
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .spx-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
            flex: 1 1 0;
        }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"] {
            height: 38px;
            padding: 0 8px;
            border: 1px solid var(--border-strong);
            border-radius: 6px;
            font-size: 13px;
            background: #fff;
            font-family: inherit;
            width: 100%;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input[type="date"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(102,126,234,0.1);
        }
        .spx-btn {
            height: 38px;
            padding: 0 18px;
            border-radius: 6px;
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .spx-btn-primary { background: var(--primary); color: #fff; }
        .spx-btn-primary:hover { background: #5568d3; }
        .spx-btn-secondary { background: #fff; color: var(--text-main); border-color: var(--border-strong); }
        .spx-btn-secondary:hover { background: #f9fafb; border-color: var(--primary); color: var(--primary); }
        .spx-controls-bar {
            padding: 10px 20px;
            background: #f9fafb;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border-strong);
        }
        .spx-quick-btn {
            padding: 0 12px;
            height: 28px;
            background: #fff;
            border: 1px solid var(--border-strong);
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
            color: #374151;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
            transition: all 0.15s;
        }
        .spx-quick-btn:hover { border-color: var(--primary); color: var(--primary); }
        .spx-quick-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            font-weight: 600;
        }
        .spx-controls-bar .period-label {
            margin-left: auto;
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 600;
        }
        .spx-controls-bar .period-label strong { color: var(--primary-blue); }

        /* KPI grid – klasy zgodne ze styl.md */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            margin-bottom: 22px;
        }
        @media (max-width: 1200px) { .kpi-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 600px)  { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        .kpi-card {
            background: #fff;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 2px solid transparent;
            transition: all 0.15s;
            position: relative;
        }
        .kpi-card:hover { border-color: var(--primary); }
        .kpi-card .kpi-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .kpi-card .kpi-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            line-height: 1.2;
        }
        .kpi-card .kpi-value.good    { color: var(--good); }
        .kpi-card .kpi-value.warn    { color: var(--warn); }
        .kpi-card .kpi-value.bad     { color: var(--bad); }
        .kpi-card .kpi-value.neutral { color: var(--text-main); }
        .kpi-card .kpi-sub {
            margin-top: 6px;
            font-size: 11px;
            color: #999;
            line-height: 1.4;
        }
        .kpi-card .kpi-delta {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 8px;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            letter-spacing: 0.3px;
        }
        .delta-pos     { background: #d1fae5; color: #065f46; }
        .delta-neg     { background: #fee2e2; color: #991b1b; }
        .delta-neutral { background: #f3f4f6; color: #6b7280; }
        /* Tooltip po najechaniu na KPI */
        .kpi-card[data-tooltip] { cursor: help; }
        .kpi-card[data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(-8px);
            background: #1f2937;
            color: #fff;
            padding: 10px 14px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 8px;
            white-space: normal;
            width: 260px;
            line-height: 1.5;
            z-index: 100;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            letter-spacing: 0;
            text-transform: none;
            text-align: left;
        }
        .kpi-card[data-tooltip]:hover::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #1f2937;
            z-index: 100;
        }

        /* Sekcja (biala karta) */
        .section {
            background: #fff;
            border-radius: 12px;
            padding: 22px 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 22px;
        }
        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            gap: 16px;
            flex-wrap: wrap;
        }
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1f2937;
            letter-spacing: -0.2px;
        }
        .section-subtitle {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Alerty */
        .alerts-section { border-left: 4px solid var(--warn); }
        .alert {
            padding: 12px 14px;
            border-radius: 8px;
            margin-bottom: 8px;
            border-left: 3px solid;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .alert:last-child { margin-bottom: 0; }
        .alert.danger  { background: #fef2f2; border-left-color: var(--bad);  color: #7f1d1d; }
        .alert.warning { background: #fffbeb; border-left-color: var(--warn); color: #78350f; }
        .alert.info    { background: #eff6ff; border-left-color: #3b82f6;     color: #1e40af; }
        .alert-title { font-weight: 700; font-size: 13px; margin-bottom: 2px; }
        .alert-msg   { font-size: 12px; line-height: 1.5; }

        /* Chart container */
        .chart-wrap { position: relative; height: 320px; }
        .chart-wrap.tall { height: 360px; }
        .chart-wrap.short { height: 260px; }

        /* Grid 2 kolumny */
        .grid-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; margin-bottom: 22px; }
        @media (max-width: 1024px) { .grid-2col { grid-template-columns: 1fr; } }

        /* TOP bar list (klienci/pracownicy) */
        .top-list { list-style: none; padding: 0; margin: 0; }
        .top-list li {
            display: grid;
            grid-template-columns: 28px 1fr auto;
            gap: 12px;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
        }
        .top-list li:last-child { border-bottom: none; }
        .top-rank {
            background: #eef2ff;
            color: var(--primary-blue);
            font-size: 12px;
            font-weight: 700;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .top-name {
            font-size: 13px;
            font-weight: 600;
            color: #1f2937;
        }
        .top-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .top-bar {
            position: relative;
            height: 6px;
            background: #f1f3f5;
            border-radius: 3px;
            margin-top: 4px;
            overflow: hidden;
        }
        .top-bar-fill {
            position: absolute;
            left: 0; top: 0; bottom: 0;
            background: linear-gradient(90deg, #667eea, #4f46e5);
            border-radius: 3px;
        }
        .top-amount {
            font-size: 13px;
            font-weight: 700;
            color: #1f2937;
            text-align: right;
            white-space: nowrap;
        }
        .top-empty {
            text-align: center;
            padding: 30px 10px;
            color: var(--text-muted);
            font-size: 13px;
        }

        /* Tabela projektow */
        .projects-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }
        .projects-table th {
            padding: 10px 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #374151;
            background: #f1f3f5;
            border: 1px solid var(--grid-strong);
            text-align: right;
            white-space: nowrap;
        }
        .projects-table th.left { text-align: left; }
        .projects-table td {
            padding: 10px 12px;
            font-size: 13px;
            border: 1px solid var(--grid-strong);
            text-align: right;
            white-space: nowrap;
        }
        .projects-table td.left { text-align: left; white-space: normal; }
        .projects-table tr:hover td { background: #f9fafb; }
        .projects-table td a { color: var(--primary-blue); font-weight: 700; text-decoration: none; }
        .projects-table td a:hover { text-decoration: underline; }
        .status-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-left: 6px;
        }
        .status-pill.active   { background: #d1fae5; color: #065f46; }
        .status-pill.finished { background: #e0e7ff; color: #3730a3; }
        .money-good { color: var(--good); font-weight: 600; }
        .money-bad  { color: var(--bad);  font-weight: 600; }
        .money-muted { color: var(--text-muted); }

        /* Wallet grid (wskazniki platnicze) */
        .wallet-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-top: 4px;
        }
        @media (max-width: 1024px) { .wallet-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px)  { .wallet-grid { grid-template-columns: 1fr; } }
        .wallet-card {
            background: #f9fafb;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px 18px;
        }
        .wallet-card-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .wallet-card-value {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
        }
        .wallet-card-value.good { color: var(--good); }
        .wallet-card-value.warn { color: var(--warn); }
        .wallet-card-value.bad  { color: var(--bad); }
        .wallet-card-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 6px;
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

<div class="container">

    <!-- HERO -->
    <div class="hero">
        <div>
            <div class="hero-breadcrumb">
                <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                Analiza finansowa
            </div>
            <h1>Analiza finansowa</h1>
            <p>
                Stan kontraktacji, sprzedaży, kasy, kosztów i zysku firmy.
                <strong style="color:#fff;">Wszystkie wartości netto.</strong>
                Okres: <strong style="color:#fff;"><?php echo e($period['label'] ?: 'biezacy miesiac'); ?></strong>.
            </p>
        </div>
        <div class="hero-actions">
            <a class="btn-hero-primary"
               href="<?php echo e($buildUrl(['export' => 'csv'])); ?>">
                Eksport projektów (CSV)
            </a>
            <a class="btn-hero-secondary" href="<?php echo e(url('finanse')); ?>">← Centrum finansów</a>
        </div>
    </div>

    <!-- FILTR -->
    <form class="filter-card" method="get" action="">
        <div class="spx-filter-bar">
            <div class="spx-filter-group" style="flex: 0.7;">
                <label for="year">Rok</label>
                <select name="year" id="year" onchange="this.form.submit()">
                    <option value="all" <?php echo $year === 0 ? 'selected' : ''; ?>>Wszystkie</option>
                    <?php for ($y = (int)date('Y'); $y >= 2023; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $year === $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="spx-filter-group" style="flex: 1.2;">
                <label for="month">Miesiąc</label>
                <select name="month" id="month" onchange="this.form.submit()">
                    <option value="">Wszystkie</option>
                    <?php
                    $months = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec',
                               'Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
                    foreach ($months as $i => $name):
                        $mNum = $i + 1;
                    ?>
                        <option value="<?php echo $mNum; ?>" <?php echo $month === $mNum ? 'selected' : ''; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spx-filter-group">
                <label for="date_from">Od</label>
                <input type="date" name="date_from" id="date_from" value="<?php echo e($from); ?>">
            </div>
            <div class="spx-filter-group">
                <label for="date_to">Do</label>
                <input type="date" name="date_to" id="date_to" value="<?php echo e($to); ?>">
            </div>
            <button class="spx-btn spx-btn-primary" type="submit">Filtruj</button>
            <a class="spx-btn spx-btn-secondary" href="?">Reset</a>
        </div>
        <div class="spx-controls-bar">
            <?php
            $today0 = new DateTime('today');
            $curMonth       = (int)$today0->format('n');
            $curYear        = (int)$today0->format('Y');
            $qStartMonth    = (int)(floor(($curMonth - 1) / 3) * 3 + 1);
            $qStartDate     = sprintf('%04d-%02d-01', $curYear, $qStartMonth);
            $qEndDate       = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $curYear, $qStartMonth + 2)));
            $presets = [
                'm'    => ['Ten miesiąc',  $today0->format('Y-m-01'), $today0->format('Y-m-t')],
                'q'    => ['Ten kwartał',  $qStartDate, $qEndDate],
                'y'    => ['Ten rok',      $today0->format('Y') . '-01-01', $today0->format('Y') . '-12-31'],
                '12m'  => ['Ostatnie 12m', date('Y-m-01', strtotime('-11 months')), $today0->format('Y-m-t')],
                'all'  => ['Wszystko',     '', ''],
            ];
            foreach ($presets as $key => $p):
                [$label, $pFrom, $pTo] = $p;
                $active = ($from === $pFrom && $to === $pTo) ||
                          ($key === 'all' && $year === 0);
                $href = $key === 'all'
                    ? $buildUrl(['year' => 'all', 'month' => '', 'date_from' => '', 'date_to' => ''])
                    : $buildUrl(['year' => '', 'month' => '', 'date_from' => $pFrom, 'date_to' => $pTo]);
            ?>
                <a class="spx-quick-btn <?php echo $active ? 'active' : ''; ?>" href="<?php echo e($href); ?>">
                    <?php echo e($label); ?>
                </a>
            <?php endforeach; ?>
            <span class="period-label">
                Porównuję z okresem:
                <strong><?php echo e(date('d.m.Y', strtotime($prevFrom))); ?> – <?php echo e(date('d.m.Y', strtotime($prevTo))); ?></strong>
            </span>
        </div>
    </form>

    <!-- KPI (6 kafelkow, wg styl.md) -->
    <?php
    // Helper do renderowania delty
    $renderDelta = function(?float $d, bool $inverse = false, string $suffix = '%') {
        $badge = analyticsDeltaBadge($d);
        if ($inverse && $d !== null) {
            // dla kosztow: wzrost to zle
            $badge['class'] = $d > 0 ? 'delta-neg' : ($d < 0 ? 'delta-pos' : 'delta-neutral');
        }
        if ($d === null) {
            $text = 'brak porównania';
        } else {
            $arrow = $d > 0.05 ? '↑' : ($d < -0.05 ? '↓' : '=');
            $text = $arrow . ' ' . number_format(abs($d), 1, ',', ' ') . $suffix . ' vs poprz.';
        }
        return '<span class="kpi-delta ' . $badge['class'] . '">' . $text . '</span>';
    };
    ?>
    <div class="kpi-grid">
        <!-- 1. Zakontraktowano -->
        <div class="kpi-card"
             data-tooltip="Suma umów i aneksów podpisanych w wybranym okresie (project_revenues po signed_date). Pipeline — to co firma ma do zrealizowania.">
            <div class="kpi-label">Zakontraktowano</div>
            <div class="kpi-value neutral"><?php echo formatMoney($contracted); ?></div>
            <div class="kpi-sub"><?php echo $contractedCount; ?> umów/aneksów</div>
            <?php echo $renderDelta($dContracted); ?>
        </div>

        <!-- 2. Sprzedaz (FV + PZF) -->
        <div class="kpi-card"
             data-tooltip="Faktury sprzedaży (FV) i wpisy pozafakturowe (PZF) wystawione w okresie — to co firma faktycznie zarobiła w księgach. Liczone po issue_date, bez wersji anulowanych.">
            <div class="kpi-label">Sprzedaż (FV+PZF)</div>
            <div class="kpi-value"><?php echo formatMoney($summary['revenue_total']); ?></div>
            <div class="kpi-sub">
                FV <?php echo formatMoney($summary['revenue_fv']); ?>
                · PZF <?php echo formatMoney($summary['revenue_pzf']); ?>
            </div>
            <?php echo $renderDelta($dRevenue); ?>
        </div>

        <!-- 3. Wplynelo (kasa) -->
        <div class="kpi-card"
             data-tooltip="Realne wpływy pieniędzy w okresie: wpłaty na FV (invoice_sale_payments) + PZF o statusie opłacona. To co naprawdę weszło na konto.">
            <div class="kpi-label">Wpłynęło (kasa)</div>
            <div class="kpi-value good"><?php echo formatMoney($summary['cash_total']); ?></div>
            <div class="kpi-sub">
                FV <?php echo formatMoney($summary['cash_fv']); ?>
                · PZF <?php echo formatMoney($summary['cash_pzf']); ?>
            </div>
            <?php echo $renderDelta($dCash); ?>
        </div>

        <!-- 4. Koszty -->
        <div class="kpi-card"
             data-tooltip="Faktury kosztowe + koszty stałe + robocizna + wydatki drobne pracowników w okresie. Wszystko netto, zaakceptowane.">
            <div class="kpi-label">Koszty razem</div>
            <div class="kpi-value bad"><?php echo formatMoney($summary['costs']['total']); ?></div>
            <div class="kpi-sub">
                Faktury <?php echo formatMoney($summary['costs']['invoice']); ?>
                · Robocizna <?php echo formatMoney($summary['costs']['labor']); ?>
            </div>
            <?php echo $renderDelta($dCosts, true); ?>
        </div>

        <!-- 5. Zysk (dokument) -->
        <?php
        $profitClass = $summary['profit_doc'] >= 0 ? 'good' : 'bad';
        ?>
        <div class="kpi-card"
             data-tooltip="Sprzedaż (FV+PZF) minus koszty. Zysk księgowy za okres — niezależnie od tego, ile z tego już wpłynęło.">
            <div class="kpi-label">Zysk (dokument)</div>
            <div class="kpi-value <?php echo $profitClass; ?>"><?php echo formatMoney($summary['profit_doc']); ?></div>
            <div class="kpi-sub">sprzedaż − koszty</div>
            <?php echo $renderDelta($dProfit); ?>
        </div>

        <!-- 6. Marza dokumentowa -->
        <?php
        $marginDoc = $summary['margin_doc'];
        $marginClass = 'neutral';
        if ($marginDoc !== null) {
            if ($marginDoc < 0)        $marginClass = 'bad';
            elseif ($marginDoc < 10)   $marginClass = 'warn';
            else                       $marginClass = 'good';
        }
        ?>
        <div class="kpi-card"
             data-tooltip="Marża dokumentowa = zysk / sprzedaż × 100%. Próg ostrzegawczy: 10%. Poniżej 0% = firma dopłaca do projektów.">
            <div class="kpi-label">Marża dokumentowa</div>
            <div class="kpi-value <?php echo $marginClass; ?>">
                <?php echo $marginDoc !== null ? number_format($marginDoc, 1, ',', ' ') . '%' : '—'; ?>
            </div>
            <div class="kpi-sub">poprz.: <?php echo $summaryPrev['margin_doc'] !== null ? number_format($summaryPrev['margin_doc'], 1, ',', ' ') . '%' : '—'; ?></div>
            <?php echo $renderDelta($dMargin, false, ' pp'); ?>
        </div>
    </div>

    <!-- ALERTY -->
    <?php if (!empty($alerts)): ?>
    <div class="section alerts-section">
        <div class="section-head">
            <div>
                <div class="section-title">Uwagi kontrolera</div>
                <div class="section-subtitle">Automatyczna analiza wyników za wybrany okres.</div>
            </div>
        </div>
        <?php foreach ($alerts as $a): ?>
            <div class="alert <?php echo e($a['type']); ?>">
                <div>
                    <div class="alert-title"><?php echo e($a['title']); ?></div>
                    <div class="alert-msg"><?php echo e($a['message']); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- TREND 12m -->
    <div class="section">
        <div class="section-head">
            <div>
                <div class="section-title">Trend 12 miesięcy</div>
                <div class="section-subtitle">Sprzedaż (FV+PZF), kasa, koszty i zysk miesiąc po miesiącu — aktualna seria kończy się na <?php echo e(date('m.Y', strtotime($trend12End))); ?>.</div>
            </div>
        </div>
        <div class="chart-wrap tall">
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <!-- DONUTS -->
    <div class="grid-2col">
        <div class="section">
            <div class="section-head">
                <div>
                    <div class="section-title">Struktura kosztów</div>
                    <div class="section-subtitle">Na co idą pieniądze w wybranym okresie.</div>
                </div>
            </div>
            <div class="chart-wrap short">
                <canvas id="costDonut"></canvas>
            </div>
        </div>
        <div class="section">
            <div class="section-head">
                <div>
                    <div class="section-title">Struktura sprzedaży</div>
                    <div class="section-subtitle">Ile ze sprzedaży firma zarabia z faktur, a ile z pozafakturowych.</div>
                </div>
            </div>
            <div class="chart-wrap short">
                <canvas id="salesDonut"></canvas>
            </div>
        </div>
    </div>

    <!-- TOP KLIENCI + TOP PRACOWNICY -->
    <div class="grid-2col">
        <!-- TOP KLIENCI -->
        <div class="section">
            <div class="section-head">
                <div>
                    <div class="section-title">Top 10 klientów</div>
                    <div class="section-subtitle">Po sprzedaży (FV) w okresie. Bez wpisów anulowanych.</div>
                </div>
            </div>
            <?php if (empty($topClients)): ?>
                <div class="top-empty">Brak wystawionych faktur w tym okresie.</div>
            <?php else: ?>
                <ul class="top-list">
                    <?php foreach ($topClients as $i => $c):
                        $pct = $topClientsMax > 0 ? (float)$c['total_net'] / $topClientsMax * 100 : 0;
                    ?>
                        <li>
                            <span class="top-rank"><?php echo $i + 1; ?></span>
                            <div>
                                <div class="top-name"><?php echo e($c['client_name']); ?></div>
                                <div class="top-meta">
                                    <?php echo (int)$c['invoices_count']; ?> faktur ·
                                    opłacone: <?php echo formatMoney((float)$c['paid_net']); ?>
                                </div>
                                <div class="top-bar">
                                    <div class="top-bar-fill" style="width: <?php echo number_format($pct, 1, '.', ''); ?>%;"></div>
                                </div>
                            </div>
                            <div class="top-amount"><?php echo formatMoney((float)$c['total_net']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- TOP PRACOWNICY -->
        <div class="section">
            <div class="section-head">
                <div>
                    <div class="section-title">Top 10 pracowników – koszt robocizny</div>
                    <div class="section-subtitle">Zaakceptowane wpisy w dzienniku pracy w okresie (suma godzin × stawka).</div>
                </div>
            </div>
            <?php if (empty($topWorkers)): ?>
                <div class="top-empty">Brak zaakceptowanych godzin pracy w okresie.</div>
            <?php else: ?>
                <ul class="top-list">
                    <?php foreach ($topWorkers as $i => $w):
                        $pct = $topWorkersMax > 0 ? (float)$w['labor_cost'] / $topWorkersMax * 100 : 0;
                    ?>
                        <li>
                            <span class="top-rank"><?php echo $i + 1; ?></span>
                            <div>
                                <div class="top-name"><?php echo e($w['worker_name']); ?></div>
                                <div class="top-meta">
                                    <?php echo number_format((float)$w['hours'], 1, ',', ' '); ?> h
                                </div>
                                <div class="top-bar">
                                    <div class="top-bar-fill" style="width: <?php echo number_format($pct, 1, '.', ''); ?>%;"></div>
                                </div>
                            </div>
                            <div class="top-amount"><?php echo formatMoney((float)$w['labor_cost']); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- PROJEKTY -->
    <div class="section">
        <div class="section-head">
            <div>
                <div class="section-title">Projekty w okresie</div>
                <div class="section-subtitle">Wszystkie projekty, które miały jakikolwiek przepływ pieniężny w okresie. Sortowane po zysku dokumentowym.</div>
            </div>
            <a class="spx-btn spx-btn-secondary" href="<?php echo e($buildUrl(['export' => 'csv'])); ?>">
                Eksport CSV
            </a>
        </div>
        <?php if (empty($projects)): ?>
            <div class="top-empty">Brak projektów z przepływami w tym okresie.</div>
        <?php else: ?>
            <div style="overflow-x: auto;">
            <table class="projects-table">
                <thead>
                    <tr>
                        <th class="left">Projekt</th>
                        <th class="left">Klient</th>
                        <th>Umowa</th>
                        <th>Sprzedaż FV+PZF</th>
                        <th>Wpłynęło</th>
                        <th>Koszty</th>
                        <th>Zysk (dok.)</th>
                        <th>Zysk (kasa)</th>
                        <th>Marża</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($projects as $p):
                    $profitClass = $p['profit_doc'] >= 0 ? 'money-good' : 'money-bad';
                    $cashClass   = $p['profit_cash'] >= 0 ? 'money-good' : 'money-bad';
                ?>
                    <tr>
                        <td class="left">
                            <a href="<?php echo e(url('projekty.view', ['id' => $p['id']])); ?>">
                                <?php echo e($p['name']); ?>
                            </a>
                            <span class="status-pill <?php echo e($p['status']); ?>">
                                <?php echo $p['status'] === 'active' ? 'Aktywny' : 'Zakończony'; ?>
                            </span>
                        </td>
                        <td class="left"><?php echo e($p['client']); ?></td>
                        <td><?php echo $p['contract'] > 0 ? formatMoney($p['contract']) : '<span class="money-muted">—</span>'; ?></td>
                        <td>
                            <?php echo formatMoney($p['revenue']); ?>
                            <?php if ($p['revenue_pzf'] > 0 && $p['revenue_fv'] > 0): ?>
                                <div style="font-size:10px;color:#9ca3af;">
                                    FV <?php echo formatMoney($p['revenue_fv']); ?> ·
                                    PZF <?php echo formatMoney($p['revenue_pzf']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatMoney($p['cash']); ?></td>
                        <td><?php echo formatMoney($p['costs']); ?></td>
                        <td class="<?php echo $profitClass; ?>"><?php echo formatMoney($p['profit_doc']); ?></td>
                        <td class="<?php echo $cashClass; ?>"><?php echo formatMoney($p['profit_cash']); ?></td>
                        <td>
                            <?php echo $p['margin_doc'] !== null
                                ? number_format($p['margin_doc'], 1, ',', ' ') . '%'
                                : '<span class="money-muted">—</span>'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- WSKAZNIKI PLATNICZE -->
    <div class="section">
        <div class="section-head">
            <div>
                <div class="section-title">Wskaźniki płatnicze</div>
                <div class="section-subtitle">Zdrowie finansowe firmy: co jest po terminie, co jest zablokowane, ile średnio czekamy na pieniądze.</div>
            </div>
        </div>
        <div class="wallet-grid">
            <div class="wallet-card">
                <div class="wallet-card-label">FV po terminie</div>
                <div class="wallet-card-value <?php echo (float)$overdueFv['amount'] > 0 ? 'bad' : 'good'; ?>">
                    <?php echo formatMoney((float)$overdueFv['amount']); ?>
                </div>
                <div class="wallet-card-meta">
                    <?php echo (int)$overdueFv['cnt']; ?> faktur po terminie płatności
                </div>
            </div>

            <div class="wallet-card">
                <div class="wallet-card-label">PZF nieopłacone</div>
                <div class="wallet-card-value <?php echo (float)$unpaidPzf['amount'] > 0 ? 'warn' : 'good'; ?>">
                    <?php echo formatMoney((float)$unpaidPzf['amount']); ?>
                </div>
                <div class="wallet-card-meta">
                    <?php echo (int)$unpaidPzf['cnt']; ?> wpisów pozafakturowych bez statusu „opłacona”
                </div>
            </div>

            <div class="wallet-card">
                <div class="wallet-card-label">Retencja aktywna</div>
                <div class="wallet-card-value warn">
                    <?php echo formatMoney((float)$activeRetention['amount']); ?>
                </div>
                <div class="wallet-card-meta">
                    <?php echo (int)$activeRetention['cnt']; ?> retencji zablokowanych u klientów
                </div>
            </div>

            <div class="wallet-card">
                <div class="wallet-card-label">DSO (średni czas zapłaty)</div>
                <div class="wallet-card-value">
                    <?php echo $dsoDays !== null ? number_format($dsoDays, 1, ',', ' ') . ' dni' : '—'; ?>
                </div>
                <div class="wallet-card-meta">
                    <?php echo $dsoCount; ?> FV opłaconych w okresie
                </div>
            </div>
        </div>
    </div>

</div>

<script>
const PLN = v => new Intl.NumberFormat('pl-PL', {style:'currency', currency:'PLN', minimumFractionDigits: 0, maximumFractionDigits: 0}).format(v);

// ---------- TREND 12m ----------
const trendData = <?php echo json_encode([
    'labels'   => array_map(fn($t) => $t['short'], $trend),
    'contract' => array_map(fn($t) => $t['contract'], $trend),
    'revenue'  => array_map(fn($t) => $t['revenue'],  $trend),
    'cash'     => array_map(fn($t) => $t['cash'],     $trend),
    'costs'    => array_map(fn($t) => $t['costs'],    $trend),
    'profit'   => array_map(fn($t) => $t['profit'],   $trend),
]); ?>;

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: trendData.labels,
        datasets: [
            { label: 'Sprzedaż (FV+PZF)', data: trendData.revenue, borderColor: '#667eea', backgroundColor: 'rgba(102,126,234,0.08)', borderWidth: 2.5, tension: 0.3, fill: true },
            { label: 'Wpłynęło (kasa)',   data: trendData.cash,    borderColor: '#16a34a', borderWidth: 2.5, tension: 0.3, fill: false },
            { label: 'Koszty',            data: trendData.costs,   borderColor: '#dc2626', borderWidth: 2.5, tension: 0.3, fill: false },
            { label: 'Zysk (dok.)',       data: trendData.profit,  borderColor: '#f59e0b', borderWidth: 2,   tension: 0.3, fill: false, borderDash: [5,5] },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { boxWidth: 14, font: { size: 12 } } },
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + PLN(ctx.parsed.y) } }
        },
        scales: {
            y: { ticks: { callback: v => (v/1000).toFixed(0) + 'k' } }
        }
    }
});

// ---------- COST DONUT ----------
const costs = <?php echo json_encode($summary['costs']); ?>;
const costSeries = [
    { label: 'Faktury kosztowe',        value: costs.invoice, color: '#667eea' },
    { label: 'Robocizna',               value: costs.labor,   color: '#16a34a' },
    { label: 'Wydatki pracowników',     value: costs.cash,    color: '#f59e0b' },
    { label: 'Koszty stałe',            value: costs.fixed,   color: '#8b5cf6' },
].filter(s => s.value > 0);

if (costSeries.length > 0) {
    new Chart(document.getElementById('costDonut'), {
        type: 'doughnut',
        data: {
            labels: costSeries.map(s => s.label),
            datasets: [{ data: costSeries.map(s => s.value), backgroundColor: costSeries.map(s => s.color), borderWidth: 2, borderColor: '#fff' }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 12 }, padding: 12 } },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = costSeries.reduce((a,s) => a + s.value, 0);
                            const pct = total > 0 ? (ctx.parsed / total * 100).toFixed(1) : 0;
                            return ctx.label + ': ' + PLN(ctx.parsed) + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
} else {
    const ctx = document.getElementById('costDonut').getContext('2d');
    ctx.font = '14px sans-serif'; ctx.fillStyle = '#9ca3af'; ctx.textAlign = 'center';
    ctx.fillText('Brak kosztów w okresie', ctx.canvas.width/2, ctx.canvas.height/2);
}

// ---------- SALES DONUT ----------
const salesSeries = [
    { label: 'Faktury sprzedaży (FV)',  value: <?php echo (float)$summary['revenue_fv']; ?>,  color: '#1e3a8a' },
    { label: 'Pozafakturowe (PZF)',     value: <?php echo (float)$summary['revenue_pzf']; ?>, color: '#667eea' },
].filter(s => s.value > 0);

if (salesSeries.length > 0) {
    new Chart(document.getElementById('salesDonut'), {
        type: 'doughnut',
        data: {
            labels: salesSeries.map(s => s.label),
            datasets: [{ data: salesSeries.map(s => s.value), backgroundColor: salesSeries.map(s => s.color), borderWidth: 2, borderColor: '#fff' }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 12 }, padding: 12 } },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = salesSeries.reduce((a,s) => a + s.value, 0);
                            const pct = total > 0 ? (ctx.parsed / total * 100).toFixed(1) : 0;
                            return ctx.label + ': ' + PLN(ctx.parsed) + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
} else {
    const ctx = document.getElementById('salesDonut').getContext('2d');
    ctx.font = '14px sans-serif'; ctx.fillStyle = '#9ca3af'; ctx.textAlign = 'center';
    ctx.fillText('Brak sprzedaży w okresie', ctx.canvas.width/2, ctx.canvas.height/2);
}
</script>

</body>
</html>
