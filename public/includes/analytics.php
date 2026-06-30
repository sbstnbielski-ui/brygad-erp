<?php
/**
 * BRYGAD ERP – includes/analytics.php
 *
 * Wspoldzielone helpery analityki finansowej.
 * Zasady (patrz .claude/ROLE.md/financecontroller.md):
 *  - wszystko NETTO
 *  - kasa = wplaty FV (invoice_sale_payments) + PZF o statusie paid
 *  - faktury -> po issue_date; kasa -> po payment_date (lub issue_date dla PZF)
 *  - funkcje nigdy nie rzucaja wyjatkiem – w razie bledu zwracaja 0/domysle
 */

if (!defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    die('Direct access not allowed');
}

/* ==============================================================
 * Low-level safe query helpers
 * ============================================================ */

function analyticsQueryScalar(PDO $pdo, string $sql, array $params = [], float $default = 0.0): float
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null) ? $default : (float)$val;
    } catch (Throwable $e) {
        error_log('[analytics] scalar: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return $default;
    }
}

function analyticsQueryRow(PDO $pdo, string $sql, array $params = [], array $default = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : $default;
    } catch (Throwable $e) {
        error_log('[analytics] row: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return $default;
    }
}

function analyticsQueryAll(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[analytics] all: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return [];
    }
}

/* ==============================================================
 * Okresy i porownania
 * ============================================================ */

/**
 * Oblicza poprzedni okres o tej samej dlugosci (od:do wstecz).
 * @return array{from:string,to:string}
 */
function analyticsPreviousPeriod(string $from, string $to): array
{
    $diffDays = max(1, (int)((strtotime($to) - strtotime($from)) / 86400) + 1);
    return [
        'from' => date('Y-m-d', strtotime($from . ' -' . $diffDays . ' days')),
        'to'   => date('Y-m-d', strtotime($to   . ' -' . $diffDays . ' days')),
    ];
}

/**
 * Delta procentowa. Zwraca null gdy brak punktu odniesienia (prev = 0).
 */
function analyticsDeltaPct(float $current, float $previous): ?float
{
    if (abs($previous) < 0.0001) {
        return null;
    }
    return (($current - $previous) / abs($previous)) * 100.0;
}

/**
 * Generuje klase i tekst do badge delty (spojne w calym module).
 * @return array{class:string,text:string}
 */
function analyticsDeltaBadge(?float $deltaPct, string $noRefText = 'brak porównania'): array
{
    if ($deltaPct === null) {
        return ['class' => 'delta-neutral', 'text' => $noRefText];
    }
    $prefix = $deltaPct >= 0 ? '+' : '';
    return [
        'class' => $deltaPct >= 0 ? 'delta-pos' : 'delta-neg',
        'text'  => $prefix . number_format($deltaPct, 1, ',', ' ') . '%',
    ];
}

/* ==============================================================
 * SPRZEDAZ (FV + PZF, netto)
 * ============================================================ */

/**
 * Sprzedaz z faktur (FV) netto wg issue_date.
 * Pomija status 'cancelled' / 'draft' oraz flagę exclude_from_analytics.
 */
function analyticsRevenueFv(PDO $pdo, string $from, string $to, ?int $projectId = null): float
{
    if ($projectId !== null) {
        return analyticsQueryScalar(
            $pdo,
            "SELECT COALESCE(SUM(isa.amount_net), 0)
             FROM invoice_sale_allocations isa
             JOIN invoices_sale inv ON inv.id = isa.invoice_id
             WHERE isa.project_id = :pid
               AND inv.issue_date BETWEEN :from AND :to
               AND inv.status NOT IN ('cancelled','draft')
               AND inv.exclude_from_analytics = 0",
            [':pid' => $projectId, ':from' => $from, ':to' => $to]
        );
    }
    return analyticsQueryScalar(
        $pdo,
        "SELECT COALESCE(SUM(
             CASE
               WHEN financial_effect_kind = 'correction'
                 OR document_kind = 'sale_correction'
                 OR fakturownia_options_json LIKE '%\"kind\":\"correction\"%'
                 OR fakturownia_options_json LIKE '%\"kind\": \"correction\"%'
               THEN COALESCE(correction_effect_net, 0)
               ELSE amount_net
             END
         ), 0)
         FROM invoices_sale
         WHERE issue_date BETWEEN :from AND :to
           AND status NOT IN ('cancelled','draft')
           AND exclude_from_analytics = 0",
        [':from' => $from, ':to' => $to]
    );
}

/**
 * Sprzedaz pozafakturowa (PZF) netto wg issue_date.
 */
function analyticsRevenuePzf(PDO $pdo, string $from, string $to, ?int $projectId = null): float
{
    if ($projectId !== null) {
        return analyticsQueryScalar(
            $pdo,
            "SELECT COALESCE(SUM(a.amount_net), 0)
             FROM sales_noninvoice_allocations a
             JOIN sales_noninvoice_entries e ON e.id = a.entry_id
             WHERE a.project_id = :pid
               AND e.issue_date BETWEEN :from AND :to",
            [':pid' => $projectId, ':from' => $from, ':to' => $to]
        );
    }
    return analyticsQueryScalar(
        $pdo,
        "SELECT COALESCE(SUM(amount_net), 0)
         FROM sales_noninvoice_entries
         WHERE issue_date BETWEEN :from AND :to",
        [':from' => $from, ':to' => $to]
    );
}

/**
 * Pelna sprzedaz netto (FV + PZF).
 */
function analyticsRevenueTotal(PDO $pdo, string $from, string $to, ?int $projectId = null): float
{
    return analyticsRevenueFv($pdo, $from, $to, $projectId)
         + analyticsRevenuePzf($pdo, $from, $to, $projectId);
}

/* ==============================================================
 * KASA – realne wplyw (FV payments + PZF paid)
 * ============================================================ */

/**
 * Kasa z wplat FV (invoice_sale_payments) netto wg payment_date.
 * Dla projektu – pro-rata na podstawie alokacji FV.
 */
function analyticsCashInFv(PDO $pdo, string $from, string $to, ?int $projectId = null): float
{
    if ($projectId !== null) {
        // Pro-rata: dla kazdej FV zaalokowanej na projekt bierzemy
        // paid_net * (project_net / invoice_net) ograniczone do wplat w okresie.
        $rows = analyticsQueryAll(
            $pdo,
            "SELECT inv.id AS invoice_id,
                    inv.amount_net AS invoice_net,
                    isa.amount_net AS project_net,
                    COALESCE(pay.paid_net, 0) AS paid_net
             FROM invoice_sale_allocations isa
             JOIN invoices_sale inv ON inv.id = isa.invoice_id
             LEFT JOIN (
                 SELECT invoice_id, SUM(amount_net) AS paid_net
                 FROM invoice_sale_payments
                 WHERE payment_date BETWEEN :from AND :to
                 GROUP BY invoice_id
             ) pay ON pay.invoice_id = inv.id
             WHERE isa.project_id = :pid
               AND inv.exclude_from_analytics = 0",
            [':pid' => $projectId, ':from' => $from, ':to' => $to]
        );
        $total = 0.0;
        foreach ($rows as $r) {
            $invNet  = (float)$r['invoice_net'];
            $projNet = (float)$r['project_net'];
            $paidNet = (float)$r['paid_net'];
            if ($invNet > 0 && $paidNet > 0) {
                $total += round($paidNet * ($projNet / $invNet), 2);
            }
        }
        return $total;
    }
    return analyticsQueryScalar(
        $pdo,
        'SELECT COALESCE(SUM(isp.amount_net), 0)
         FROM invoice_sale_payments isp
         JOIN invoices_sale inv ON inv.id = isp.invoice_id
         WHERE isp.payment_date BETWEEN :from AND :to
           AND inv.exclude_from_analytics = 0',
        [':from' => $from, ':to' => $to]
    );
}

/**
 * Kasa z PZF (wpisy pozafakturowe o statusie paid). Datowanie: issue_date
 * (w PZF wpis = pieniadz). Mozna dodac kolumne paid_date pozniej.
 */
function analyticsCashInPzf(PDO $pdo, string $from, string $to, ?int $projectId = null): float
{
    if ($projectId !== null) {
        return analyticsQueryScalar(
            $pdo,
            "SELECT COALESCE(SUM(a.amount_net), 0)
             FROM sales_noninvoice_allocations a
             JOIN sales_noninvoice_entries e ON e.id = a.entry_id
             WHERE a.project_id = :pid
               AND e.payment_status = 'paid'
               AND e.issue_date BETWEEN :from AND :to",
            [':pid' => $projectId, ':from' => $from, ':to' => $to]
        );
    }
    return analyticsQueryScalar(
        $pdo,
        "SELECT COALESCE(SUM(amount_net), 0)
         FROM sales_noninvoice_entries
         WHERE payment_status = 'paid'
           AND issue_date BETWEEN :from AND :to",
        [':from' => $from, ':to' => $to]
    );
}

/**
 * Kasa calkowita (FV payments + PZF paid) netto w okresie.
 */
function analyticsCashInTotal(PDO $pdo, string $from, string $to, ?int $projectId = null): float
{
    return analyticsCashInFv($pdo, $from, $to, $projectId)
         + analyticsCashInPzf($pdo, $from, $to, $projectId);
}

/* ==============================================================
 * KOSZTY (netto)
 * ============================================================ */

/**
 * Zbiorcza struktura kosztow w okresie.
 * @return array{invoice:float,fixed:float,labor:float,cash:float,total:float}
 */
function analyticsCosts(PDO $pdo, string $from, string $to, ?int $projectId = null): array
{
    // 1) Faktury kosztowe (netto)
    if ($projectId !== null) {
        $invoice = analyticsQueryScalar(
            $pdo,
            "SELECT COALESCE(SUM(fca.amount_net), 0)
             FROM fakturownia_cost_allocations fca
             JOIN fakturownia_cost_invoices fci ON fci.id = fca.cost_invoice_id
             WHERE fca.project_id = :pid
               AND fci.issue_date BETWEEN :from AND :to",
            [':pid' => $projectId, ':from' => $from, ':to' => $to]
        );
    } else {
        $invoice = analyticsQueryScalar(
            $pdo,
            "SELECT COALESCE(SUM(amount_net), 0)
             FROM fakturownia_cost_invoices
             WHERE issue_date BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        );
    }

    // 2) Koszty stale (finance_items FIXED_COST, approved, netto)
    $fixed = analyticsQueryScalar(
        $pdo,
        "SELECT COALESCE(SUM(amount_net), 0)
         FROM finance_items
         WHERE item_type = 'FIXED_COST'
           AND status = 'approved'
           AND issue_date BETWEEN :from AND :to"
           . ($projectId !== null ? " AND project_id = :pid" : ""),
        $projectId !== null
            ? [':pid' => $projectId, ':from' => $from, ':to' => $to]
            : [':from' => $from, ':to' => $to]
    );

    // 3) Robocizna (work_logs approved, final_cost)
    $labor = analyticsQueryScalar(
        $pdo,
        "SELECT COALESCE(SUM(COALESCE(final_cost, system_cost, 0)), 0)
         FROM work_logs
         WHERE status = 'approved'
           AND date BETWEEN :from AND :to"
           . ($projectId !== null ? " AND project_id = :pid" : ""),
        $projectId !== null
            ? [':pid' => $projectId, ':from' => $from, ':to' => $to]
            : [':from' => $from, ':to' => $to]
    );

    // 4) Wydatki drobne (worker_expenses approved)
    if ($projectId !== null) {
        $cash = analyticsQueryScalar(
            $pdo,
            "SELECT COALESCE(SUM(we.amount), 0)
             FROM worker_expenses we
             WHERE we.status = 'approved'
               AND we.project_id = :pid
               AND we.date BETWEEN :from AND :to",
            [':pid' => $projectId, ':from' => $from, ':to' => $to]
        );
    } else {
        $cash = analyticsQueryScalar(
            $pdo,
            "SELECT COALESCE(SUM(amount), 0)
             FROM worker_expenses
             WHERE status = 'approved'
               AND date BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        );
    }

    return [
        'invoice' => (float)$invoice,
        'fixed'   => (float)$fixed,
        'labor'   => (float)$labor,
        'cash'    => (float)$cash,
        'total'   => (float)($invoice + $fixed + $labor + $cash),
    ];
}

/* ==============================================================
 * ZYSK / MARZA
 * ============================================================ */

/**
 * Zysk i marza w trzech perspektywach.
 * @return array{
 *   revenue_total:float, revenue_fv:float, revenue_pzf:float,
 *   cash_total:float, cash_fv:float, cash_pzf:float,
 *   costs:array<string,float>,
 *   profit_doc:float, profit_cash:float,
 *   margin_doc:?float, margin_cash:?float
 * }
 */
function analyticsFinancialSummary(PDO $pdo, string $from, string $to, ?int $projectId = null): array
{
    $revenueFv  = analyticsRevenueFv($pdo, $from, $to, $projectId);
    $revenuePzf = analyticsRevenuePzf($pdo, $from, $to, $projectId);
    $revenue    = $revenueFv + $revenuePzf;

    $cashFv  = analyticsCashInFv($pdo, $from, $to, $projectId);
    $cashPzf = analyticsCashInPzf($pdo, $from, $to, $projectId);
    $cash    = $cashFv + $cashPzf;

    $costs      = analyticsCosts($pdo, $from, $to, $projectId);
    $profitDoc  = $revenue - $costs['total'];
    $profitCash = $cash    - $costs['total'];

    $marginDoc  = $revenue > 0 ? ($profitDoc / $revenue) * 100 : null;
    $marginCash = $cash    > 0 ? ($profitCash / $cash)    * 100 : null;

    return [
        'revenue_total' => $revenue,
        'revenue_fv'    => $revenueFv,
        'revenue_pzf'   => $revenuePzf,
        'cash_total'    => $cash,
        'cash_fv'       => $cashFv,
        'cash_pzf'      => $cashPzf,
        'costs'         => $costs,
        'profit_doc'    => $profitDoc,
        'profit_cash'   => $profitCash,
        'margin_doc'    => $marginDoc,
        'margin_cash'   => $marginCash,
    ];
}

/* ==============================================================
 * Okresy do filtrów (używane globalnie przez ekrany analityczne)
 * ============================================================ */

/**
 * Standaryzuje parametry okresu z $_GET.
 * Domyslnie: biezacy rok. 'all' w year -> bez filtra daty.
 * @return array{from:string,to:string,year:int,month:int,label:string}
 */
function analyticsPeriodFromRequest(array $request, ?string $defaultFrom = null, ?string $defaultTo = null): array
{
    $yearRaw  = $request['year']  ?? null;
    $monthRaw = $request['month'] ?? null;
    $dateFrom = isset($request['date_from']) && $request['date_from'] !== '' ? $request['date_from'] : '';
    $dateTo   = isset($request['date_to'])   && $request['date_to']   !== '' ? $request['date_to']   : '';

    $year  = 0;
    $month = 0;

    if ($yearRaw === 'all') {
        $year = 0;
    } elseif ($yearRaw === null || $yearRaw === '') {
        $year = (int)date('Y');
    } else {
        $year = (int)$yearRaw;
        if ($year < 2020 || $year > 2035) $year = (int)date('Y');
    }

    if ($monthRaw !== null && $monthRaw !== '') {
        $month = (int)$monthRaw;
        if ($month < 1 || $month > 12) $month = 0;
    }

    if ($dateFrom === '' && $dateTo === '' && $year > 0) {
        if ($month >= 1 && $month <= 12) {
            $dateFrom = sprintf('%04d-%02d-01', $year, $month);
            $dateTo   = date('Y-m-t', strtotime($dateFrom));
        } else {
            $dateFrom = sprintf('%04d-01-01', $year);
            $dateTo   = sprintf('%04d-12-31', $year);
        }
    }

    if ($dateFrom === '' && $defaultFrom !== null) $dateFrom = $defaultFrom;
    if ($dateTo   === '' && $defaultTo   !== null) $dateTo   = $defaultTo;

    $label = '';
    if ($dateFrom !== '' && $dateTo !== '') {
        $label = date('d.m.Y', strtotime($dateFrom)) . ' – ' . date('d.m.Y', strtotime($dateTo));
    } elseif ($year === 0) {
        $label = 'Wszystkie okresy';
    }

    return [
        'from'  => $dateFrom,
        'to'    => $dateTo,
        'year'  => $year,
        'month' => $month,
        'label' => $label,
    ];
}

/* ==============================================================
 * Eksport CSV
 * ============================================================ */

/**
 * Zwraca dane jako CSV (UTF-8 z BOM, srednik jako separator – dla Excela PL).
 * Ustawia naglowki HTTP i konczy dzialanie skryptu.
 * @param array<int,array<string,scalar|null>> $rows
 * @param array<int,string> $headers Nazwy kolumn w kolejnosci wyswietlania
 */
function analyticsExportCsv(array $rows, array $headers, string $filename): void
{
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM dla Excela
    $out = fopen('php://output', 'w');

    fputcsv($out, $headers, ';');
    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $h) {
            $line[] = $row[$h] ?? '';
        }
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
}
