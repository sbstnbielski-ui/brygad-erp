<?php
/**
 * BRYGAD ERP - API Finanse Overview (CFO Dashboard)
 * 
 * Zwraca pełne dane finansowe dla wybranego okresu:
 * - KPI z deltą vs poprzedni okres
 * - Trend 12 miesięcy (przychody, koszty, wynik)
 * - Struktura kosztów
 * - TOP projekty (zyski i straty) z marżą
 * - CFO alerts
 * 
 * @param date_from YYYY-MM-DD
 * @param date_to   YYYY-MM-DD
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// Parametry wejściowe
$dateFrom = $_GET['date_from'] ?? null;
$dateTo = $_GET['date_to'] ?? null;

if (!$dateFrom || !$dateTo) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Wymagane parametry: date_from, date_to (YYYY-MM-DD)'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Walidacja dat
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Nieprawidłowy format daty. Wymagany: YYYY-MM-DD'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Oblicz zakres poprzedniego okresu (dla delty)
    $dateFromObj = new DateTime($dateFrom);
    $dateToObj = new DateTime($dateTo);
    $intervalDays = $dateFromObj->diff($dateToObj)->days + 1;
    
    $prevDateToObj = clone $dateFromObj;
    $prevDateToObj->sub(new DateInterval('P1D'));
    $prevDateTo = $prevDateToObj->format('Y-m-d');
    
    $prevDateFromObj = clone $prevDateToObj;
    $prevDateFromObj->sub(new DateInterval('P' . ($intervalDays - 1) . 'D'));
    $prevDateFrom = $prevDateFromObj->format('Y-m-d');
    
    // ===================================================================
    // 1. KPI (tiles) - suma kosztów i przychodów z deltą vs poprzedni okres
    // ===================================================================
    
    // Koszty bieżące
    $stmt = $pdo->prepare("
        SELECT
            SUM(amount) AS total_cost,
            SUM(CASE WHEN ledger_source='labor' THEN amount ELSE 0 END) AS labor_cost,
            SUM(CASE WHEN ledger_source='cash' THEN amount ELSE 0 END) AS cash_cost,
            SUM(CASE WHEN ledger_source='invoice_cost' THEN amount ELSE 0 END) AS invoice_cost,
            SUM(CASE WHEN ledger_source='fixed' THEN amount ELSE 0 END) AS fixed_cost
        FROM view_finance_ledger
        WHERE date BETWEEN :from AND :to
    ");
    $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
    $currentCosts = $stmt->fetch();
    
    // Koszty poprzednie
    $stmt->execute([':from' => $prevDateFrom, ':to' => $prevDateTo]);
    $prevCosts = $stmt->fetch();
    
    // Przychody bieżące (kontraktacja)
    $stmt = $pdo->prepare("
        SELECT SUM(amount_net) as total_revenue
        FROM project_revenues
        WHERE signed_date BETWEEN :from AND :to
    ");
    $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
    $currentRevenue = (float)($stmt->fetch()['total_revenue'] ?? 0);
    
    // Przychody poprzednie
    $stmt->execute([':from' => $prevDateFrom, ':to' => $prevDateTo]);
    $prevRevenue = (float)($stmt->fetch()['total_revenue'] ?? 0);
    
    $totalCost = (float)($currentCosts['total_cost'] ?? 0);
    $prevTotalCost = (float)($prevCosts['total_cost'] ?? 0);
    
    $profit = $currentRevenue - $totalCost;
    $prevProfit = $prevRevenue - $prevTotalCost;
    
    $marginPercent = $currentRevenue > 0 ? ($profit / $currentRevenue) * 100 : null;
    $prevMarginPercent = $prevRevenue > 0 ? ($prevProfit / $prevRevenue) * 100 : null;
    
    // Oblicz delty (zmiana vs poprzedni okres)
    $revenueChange = $prevRevenue > 0 ? (($currentRevenue - $prevRevenue) / $prevRevenue) * 100 : null;
    $costChange = $prevTotalCost > 0 ? (($totalCost - $prevTotalCost) / $prevTotalCost) * 100 : null;
    $profitChange = $prevProfit != 0 ? (($profit - $prevProfit) / abs($prevProfit)) * 100 : null;
    $marginChange = $prevMarginPercent !== null && $marginPercent !== null 
        ? $marginPercent - $prevMarginPercent 
        : null;
    
    $kpi = [
        'revenue' => $currentRevenue,
        'revenue_prev' => $prevRevenue,
        'revenue_change' => $revenueChange !== null ? round($revenueChange, 1) : null,
        
        'cost' => $totalCost,
        'cost_prev' => $prevTotalCost,
        'cost_change' => $costChange !== null ? round($costChange, 1) : null,
        
        'profit' => $profit,
        'profit_prev' => $prevProfit,
        'profit_change' => $profitChange !== null ? round($profitChange, 1) : null,
        
        'margin_percent' => $marginPercent !== null ? round($marginPercent, 2) : null,
        'margin_prev' => $prevMarginPercent !== null ? round($prevMarginPercent, 2) : null,
        'margin_change' => $marginChange !== null ? round($marginChange, 1) : null
    ];
    
    // ===================================================================
    // 2. TREND 12 MIESIĘCY (wykres liniowy: przychody, koszty, wynik)
    // ===================================================================
    
    // Koszty per miesiąc (ostatnie 12 miesięcy wstecz od dateTo)
    $trend12Start = (clone $dateToObj)->sub(new DateInterval('P11M'))->format('Y-m-01');
    $trend12End = (clone $dateToObj)->format('Y-m-t');
    
    $stmt = $pdo->prepare("
        SELECT 
            period, 
            SUM(amount) AS total_cost
        FROM view_finance_ledger
        WHERE period BETWEEN :start AND :end
        GROUP BY period
        ORDER BY period ASC
    ");
    $stmt->execute([':start' => $trend12Start, ':end' => $trend12End]);
    $costsByMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $costsByMonth[$row['period']] = (float)$row['total_cost'];
    }
    
    // Przychody per miesiąc (ostatnie 12 miesięcy)
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(signed_date, '%Y-%m-01') as period,
            SUM(amount_net) AS total_revenue
        FROM project_revenues
        WHERE signed_date BETWEEN :start AND :end
        GROUP BY period
        ORDER BY period ASC
    ");
    $stmt->execute([':start' => $trend12Start, ':end' => $trend12End]);
    $revenuesByMonth = [];
    foreach ($stmt->fetchAll() as $row) {
        $revenuesByMonth[$row['period']] = (float)$row['total_revenue'];
    }
    
    // Połącz w jeden array (wszystkie 12 miesięcy, nawet jeśli brak danych)
    $trend12 = [];
    $currentMonth = new DateTime($trend12Start);
    $endMonth = new DateTime($trend12End);
    
    while ($currentMonth <= $endMonth) {
        $periodKey = $currentMonth->format('Y-m-01');
        $revenue = $revenuesByMonth[$periodKey] ?? 0;
        $cost = $costsByMonth[$periodKey] ?? 0;
        $result = $revenue - $cost;
        
        $trend12[] = [
            'period' => $periodKey,
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $result
        ];
        
        $currentMonth->add(new DateInterval('P1M'));
    }
    
    // ===================================================================
    // 3. STRUKTURA KOSZTÓW (donut chart)
    // ===================================================================
    $stmt = $pdo->prepare("
        SELECT 
            ledger_source, 
            SUM(amount) AS total
        FROM view_finance_ledger
        WHERE date BETWEEN :from AND :to
        GROUP BY ledger_source
        ORDER BY total DESC
    ");
    $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
    $structureRaw = $stmt->fetchAll();
    
    $sourceLabels = [
        'labor' => 'Robocizna',
        'cash' => 'Wydatki pracowników',
        'invoice_cost' => 'Faktury kosztowe',
        'fixed' => 'Koszty stałe'
    ];
    
    $structure = [];
    foreach ($structureRaw as $row) {
        $amount = (float)$row['total'];
        $percent = $totalCost > 0 ? ($amount / $totalCost) * 100 : 0;
        
        $structure[] = [
            'source' => $row['ledger_source'],
            'label' => $sourceLabels[$row['ledger_source']] ?? $row['ledger_source'],
            'total' => $amount,
            'percent' => round($percent, 2)
        ];
    }
    
    // ===================================================================
    // 4. PORTFELE FIRMOWE - przepływy finansowania (zasilenia/zwroty)
    // ===================================================================
    $walletFunding = [
        'topups' => 0.0,          // OUT_TOPUP
        'returns' => 0.0,         // IN_RETURN
        'net_out' => 0.0,         // topups - returns
        'movements_count' => 0,   // liczba ruchów
        'topups_by_source' => []  // podział zasileń: cash/bank/other
    ];
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                direction,
                SUM(amount) AS total_amount,
                COUNT(*) AS movements_count
            FROM worker_wallet_funding
            WHERE movement_date BETWEEN :from AND :to
            GROUP BY direction
        ");
        $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        
        foreach ($stmt->fetchAll() as $row) {
            $direction = strtoupper((string)$row['direction']);
            $amount = (float)($row['total_amount'] ?? 0);
            $movements = (int)($row['movements_count'] ?? 0);
            
            if ($direction === 'OUT_TOPUP') {
                $walletFunding['topups'] = $amount;
            } elseif ($direction === 'IN_RETURN') {
                $walletFunding['returns'] = $amount;
            }
            
            $walletFunding['movements_count'] += $movements;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(source_kind, 'other') AS source_kind,
                SUM(amount) AS total_amount
            FROM worker_wallet_funding
            WHERE movement_date BETWEEN :from AND :to
              AND direction = 'OUT_TOPUP'
            GROUP BY COALESCE(source_kind, 'other')
            ORDER BY total_amount DESC
        ");
        $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        
        $fundingSourceLabels = [
            'cash' => 'Kasa',
            'bank' => 'Konto bankowe',
            'other' => 'Inne'
        ];
        
        foreach ($stmt->fetchAll() as $row) {
            $sourceKind = (string)$row['source_kind'];
            $walletFunding['topups_by_source'][] = [
                'source_kind' => $sourceKind,
                'label' => $fundingSourceLabels[$sourceKind] ?? $sourceKind,
                'total' => (float)($row['total_amount'] ?? 0)
            ];
        }
        
        $walletFunding['net_out'] = $walletFunding['topups'] - $walletFunding['returns'];
        
    } catch (PDOException $e) {
        // Nie przerywaj API dashboardu, jeśli tabela finansowania nie istnieje lub jest pusta.
        error_log("API Finanse Overview wallet_funding warning: " . $e->getMessage());
    }
    
    // ===================================================================
    // 5. TOP PROJEKTY (5 najlepszych i 5 najgorszych pod względem wyniku)
    // ===================================================================
    
    // Użyj widoku view_project_finances (zawiera revenue, costs, profit, margin)
    $stmt = $pdo->prepare("
        SELECT 
            pf.project_id,
            pf.project_name,
            pf.status,
            pf.total_revenue as revenue,
            (pf.total_labor_cost + pf.total_material_cost) as cost,
            pf.current_profit as profit,
            CASE 
                WHEN pf.total_revenue > 0 
                THEN (pf.current_profit / pf.total_revenue) * 100 
                ELSE NULL 
            END as margin_percent
        FROM view_project_finances pf
        WHERE pf.status IN ('active', 'finished')
          AND (pf.total_revenue > 0 OR (pf.total_labor_cost + pf.total_material_cost) > 0)
        ORDER BY profit DESC
    ");
    $stmt->execute();
    $allProjects = $stmt->fetchAll();
    
    $topWinners = array_slice($allProjects, 0, 5);
    $topLosers = array_slice(array_reverse($allProjects), 0, 5);
    
    $projectsTop = [
        'winners' => array_map(function($p) {
            return [
                'project_id' => $p['project_id'],
                'project_name' => $p['project_name'],
                'status' => $p['status'],
                'revenue' => (float)$p['revenue'],
                'cost' => (float)$p['cost'],
                'profit' => (float)$p['profit'],
                'margin_percent' => $p['margin_percent'] !== null ? round((float)$p['margin_percent'], 2) : null
            ];
        }, $topWinners),
        'losers' => array_map(function($p) {
            return [
                'project_id' => $p['project_id'],
                'project_name' => $p['project_name'],
                'status' => $p['status'],
                'revenue' => (float)$p['revenue'],
                'cost' => (float)$p['cost'],
                'profit' => (float)$p['profit'],
                'margin_percent' => $p['margin_percent'] !== null ? round((float)$p['margin_percent'], 2) : null
            ];
        }, $topLosers)
    ];
    
    // ===================================================================
    // 6. CFO ALERTS (max 3 najważniejsze)
    // ===================================================================
    $alerts = [];
    
    // Alert 1: Marża poniżej 20%
    if ($marginPercent !== null && $marginPercent < 20) {
        $alerts[] = [
            'type' => 'warning',
            'title' => 'Niska marża',
            'message' => sprintf('Marża wynosi %.1f%%, co jest poniżej progu 20%%', $marginPercent),
            'priority' => 'high'
        ];
    }
    
    // Alert 2: Koszty > Przychody
    if ($totalCost > $currentRevenue && $currentRevenue > 0) {
        $alerts[] = [
            'type' => 'danger',
            'title' => 'Ujemny wynik',
            'message' => sprintf('Koszty (%.0f zł) przewyższają przychody (%.0f zł)', $totalCost, $currentRevenue),
            'priority' => 'critical'
        ];
    }
    
    // Alert 3: Znaczny wzrost kosztów (>20% vs poprzedni okres)
    if ($costChange !== null && $costChange > 20) {
        $alerts[] = [
            'type' => 'info',
            'title' => 'Wzrost kosztów',
            'message' => sprintf('Koszty wzrosły o %.1f%% względem poprzedniego okresu', $costChange),
            'priority' => 'medium'
        ];
    }
    
    // Ogranicz do 3 alertów (najwyższy priorytet)
    usort($alerts, function($a, $b) {
        $priorities = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
        return ($priorities[$a['priority']] ?? 5) <=> ($priorities[$b['priority']] ?? 5);
    });
    $alerts = array_slice($alerts, 0, 3);
    
    // ===================================================================
    // ODPOWIEDŹ JSON
    // ===================================================================
    $response = [
        'range' => [
            'from' => $dateFrom,
            'to' => $dateTo,
            'prev_from' => $prevDateFrom,
            'prev_to' => $prevDateTo
        ],
        'kpi' => $kpi,
        'wallet_funding' => $walletFunding,
        'trend_12m' => $trend12,
        'cost_structure' => $structure,
        'projects_top' => $projectsTop,
        'cfo_alerts' => $alerts
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("API Finanse Overview error: " . $e->getMessage());
    error_log("API Finanse Overview trace: " . $e->getTraceAsString());
    http_response_code(500);
    
    echo json_encode([
        'error' => 'Błąd serwera',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
