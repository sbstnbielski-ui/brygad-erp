<?php
/**
 * BRYGAD ERP v3.0 - Moduł Projekty
 * Lista wszystkich projektów
 */

require_once dirname(__DIR__) . '/config/autoload.php'; // 1 poziom w dół
startSecureSession();
requireLogin();

$pdo = getDbConnection();

// Filtrowanie — domyślnie aktywne
$status_filter = $_GET['status'] ?? 'active';
$investor_filter = $_GET['investor_id'] ?? '';
$search = $_GET['search'] ?? '';
$project_type = $_GET['project_type'] ?? 'standard'; // domyślnie duże projekty

// Pobierz listę inwestorów do filtra
$stmt_investors = $pdo->query("SELECT id, name FROM investors WHERE is_active = 1 ORDER BY name ASC");
$investors_list = $stmt_investors->fetchAll();

// Pobierz listę aktywnych projektów dla Quick Jump (tylko dla wybranego typu)
$stmt_active = $pdo->prepare("SELECT id, name FROM projects WHERE status = 'active' AND is_internal = 0 AND project_type = ? ORDER BY name ASC");
$stmt_active->execute([$project_type]);
$active_projects = $stmt_active->fetchAll();

// Budowanie zapytania - obliczanie kosztów bezpośrednio z właściwych tabel (bez UNION - unikamy błędu collation)
$sql = "SELECT 
    p.id,
    p.name,
    p.investor_id,
    p.status,
    p.is_internal,
    p.start_date,
    p.end_date,
    p.created_at,
    i.name as investor_name,
    (SELECT COUNT(*) FROM project_cost_nodes pcn WHERE pcn.project_id = p.id) as nodes_count,
    -- Wartość umowy (project_revenues)
    (SELECT COALESCE(SUM(amount_net), 0) FROM project_revenues WHERE project_id = p.id) as total_revenue,
    -- Zafakturowano (z invoice_sale_allocations)
    (SELECT COALESCE(SUM(isa.amount_net), 0) FROM invoice_sale_allocations isa JOIN invoices_sale inv ON inv.id = isa.invoice_id WHERE isa.project_id = p.id AND inv.status IN ('issued','paid','partially_paid')) as invoiced_amount,
    -- Wpłynęło na konto (pro-rata z invoice_sale_payments)
    (SELECT COALESCE(SUM(
        pay_sum.paid_net * (isa.amount_net / NULLIF(inv.amount_net, 0))
    ), 0)
    FROM invoice_sale_allocations isa
    JOIN invoices_sale inv ON inv.id = isa.invoice_id
    LEFT JOIN (
        SELECT invoice_id, SUM(amount_net) as paid_net
        FROM invoice_sale_payments GROUP BY invoice_id
    ) pay_sum ON pay_sum.invoice_id = inv.id
    WHERE isa.project_id = p.id
    AND inv.status IN ('issued', 'paid', 'partially_paid')
    AND pay_sum.paid_net > 0
    ) as received_amount,
    -- Koszty pracy z work_logs
    (SELECT COALESCE(SUM(COALESCE(final_cost, system_cost, 0)), 0) FROM work_logs WHERE project_id = p.id AND status = 'approved') as labor_cost,
    -- Wydatki drobne: pracownicze + firmowe przypisane do projektu
    (
        (SELECT COALESCE(SUM(we.amount), 0)
         FROM worker_expenses we
         WHERE we.project_id = p.id AND we.status = 'approved' AND we.document_id IS NULL)
        +
        (SELECT COALESCE(SUM(fi.amount_net), 0)
         FROM finance_items fi
         WHERE fi.project_id = p.id AND fi.item_type = 'FIXED_COST' AND fi.status = 'approved')
    ) as cash_expenses,
    -- Materiały: ERP (nowy+stary) + Fakturownia
    (
        (SELECT COALESCE(SUM(dia.amount), 0)
         FROM document_item_allocations dia
         JOIN document_items di ON dia.document_item_id = di.id
         JOIN documents d ON di.document_id = d.id
         WHERE dia.project_id = p.id AND d.status = 'approved' AND d.type = 'invoice_cost')
        +
        (SELECT COALESCE(SUM(da.amount_net), 0)
         FROM document_allocations da
         JOIN documents d ON d.id = da.document_id
         WHERE da.project_id = p.id AND d.status = 'approved' AND d.type = 'invoice_cost' AND da.is_legacy = 1)
        +
        (SELECT COALESCE(SUM(fca.amount_net), 0)
         FROM fakturownia_cost_allocations fca
         WHERE fca.project_id = p.id)
    ) as material_cost
FROM projects p
LEFT JOIN investors i ON i.id = p.investor_id
WHERE 1=1";

$params = [];

// Filtr typu projektu (duże vs mikro)
$sql .= " AND p.project_type = :project_type";
$params['project_type'] = $project_type;

// Obsługa statusów
if ($status_filter === 'archived') {
    $sql .= " AND p.archived_at IS NOT NULL";
} elseif ($status_filter === 'all_finished') {
    // Bieżące (niezarchiwizowane) + projekty zakończone także po archiwum (status finished z archived_at)
    $sql .= " AND (p.archived_at IS NULL OR p.status = 'finished')";
} elseif ($status_filter === 'all') {
    // Aktywne + planowane bez zakończonych; z projektem wewnętrznym (is_internal)
    $sql .= " AND p.archived_at IS NULL AND p.status IN ('active','planned')";
} else {
    $sql .= " AND p.is_internal = 0 AND p.archived_at IS NULL AND p.status = :status";
    $params['status'] = $status_filter;
}

if ($investor_filter !== '') {
    $sql .= " AND p.investor_id = :investor_id";
    $params['investor_id'] = $investor_filter;
}

if ($search !== '') {
    $sql .= " AND (p.name LIKE :search 
                   OR EXISTS (
                       SELECT 1 FROM project_cost_nodes pcn 
                       WHERE pcn.project_id = p.id 
                       AND pcn.name LIKE :search
                   ))";
    $params['search'] = '%' . $search . '%';
}

$sql .= " ORDER BY 
    CASE p.status 
        WHEN 'active'   THEN 1 
        WHEN 'planned'  THEN 2 
        WHEN 'finished' THEN 3 
        ELSE 4
    END,
    p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// =====================================================
// Helper: Rekurencyjne zbieranie potomnych node IDs
// =====================================================
function getDescendantNodeIds(PDO $pdo, int $parentId): array {
    $ids = [];
    $stmt = $pdo->prepare("SELECT id FROM project_cost_nodes WHERE parent_id = ? AND is_active = 1");
    $stmt->execute([$parentId]);
    $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($children as $childId) {
        $ids[] = (int)$childId;
        $ids = array_merge($ids, getDescendantNodeIds($pdo, (int)$childId));
    }
    return $ids;
}

// Komunikaty sukcesu/błędu
$success_message = '';
if (isset($_GET['success'])) {
    $messages = [
        'deleted' => 'Projekt został usunięty pomyślnie'
    ];
    $success_message = $messages[$_GET['success']] ?? '';
}

// Statystyki (dla wybranego typu projektu) — łącznie z zarchiwizowanymi
$stats_sql = "SELECT 
    SUM(CASE WHEN archived_at IS NULL AND is_internal = 0 AND status IN ('active','planned') THEN 1 ELSE 0 END) as total,
    SUM(CASE WHEN archived_at IS NULL AND is_internal = 0 AND status = 'planned'  THEN 1 ELSE 0 END) as planned,
    SUM(CASE WHEN archived_at IS NULL AND is_internal = 0 AND status = 'active'   THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN archived_at IS NULL AND status = 'finished' THEN 1 ELSE 0 END) as finished,
    SUM(CASE WHEN (archived_at IS NULL OR status = 'finished') THEN 1 ELSE 0 END) as all_with_finished,
    SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END) as archived
FROM projects
WHERE project_type = ?";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([$project_type]);
$stats = $stats_stmt->fetch();

// =====================================================
// SUMY FINANSOWE dla przefiltrowanych projektów (KPI)
// =====================================================
$kpi_revenue    = 0.0;
$kpi_invoiced   = 0.0;
$kpi_received   = 0.0;
$kpi_labor      = 0.0;
$kpi_expenses   = 0.0;
$kpi_materials  = 0.0;
foreach ($projects as $p) {
    $kpi_revenue   += (float)$p['total_revenue'];
    $kpi_invoiced  += (float)$p['invoiced_amount'];
    $kpi_received  += (float)$p['received_amount'];
    $kpi_labor     += (float)$p['labor_cost'];
    $kpi_expenses  += (float)$p['cash_expenses'];
    $kpi_materials += (float)$p['material_cost'];
}
$kpi_to_pay      = $kpi_invoiced - $kpi_received;
$kpi_total_costs = $kpi_labor + $kpi_materials + $kpi_expenses;
$kpi_profit_doc  = $kpi_invoiced - $kpi_total_costs;
$kpi_profit_cash = $kpi_received - $kpi_total_costs;
$kpi_margin      = $kpi_revenue > 0 ? (($kpi_revenue - $kpi_total_costs) / $kpi_revenue) * 100 : null;

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// ========================================
// FUNKCJA: Generowanie koloru PROJEKTU
// ========================================
function getProjectColor($projectId) {
    $goldenRatio = 0.618033988749895;
    $hue = fmod($projectId * $goldenRatio, 1.0) * 360;
    
    // Bazowy kolor projektu (intensywny)
    $saturation = 70;
    $lightness = 50;
    
    return [
        'hue' => round($hue),
        'sat' => $saturation,
        'light' => $lightness,
        // Projekt (główny wiersz) - intensywne tło
        'projectBg' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.12)",
        'projectBorder' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.8)",
        // Etap (stage) - średnie tło
        'stageBg' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.08)",
        'stageBorder' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.6)",
        // Podetap (sub-stage) - najjaśniejsze tło
        'subStageBg' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.04)",
        'subStageBorder' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.4)"
    ];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Projekty</title>
    <link rel="stylesheet" href="/projekty/assets/projekty.css?v=<?php echo (int)filemtime(__DIR__ . '/assets/projekty.css'); ?>">
    <style>
        /* Czarne kontury - nadpisanie */
        table th {
            border: 1px solid #000000 !important;
        }
        table td {
            border: 1px solid #000000 !important;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo e($success_message); ?></div>
        <?php endif; ?>
        
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    Projekty<?php if ($project_type === 'micro'): ?> / Mikroprojekty<?php endif; ?>
                </div>
                <h1>Projekty<?php if ($project_type === 'micro'): ?> <span style="font-size:16px;font-weight:400;color:#94a3b8;">— Mikroprojekty</span><?php endif; ?></h1>
                <p>Zarządzaj projektami i strukturą kosztów</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('projekty', ['project_type' => ($project_type === 'standard' ? 'micro' : 'standard')]); ?>"
                   class="btn-hero-secondary" title="Przełącz na <?php echo $project_type === 'standard' ? 'Mikroprojekty' : 'Duże Projekty'; ?>">
                    <?php echo $project_type === 'standard' ? 'Mikroprojekty' : 'Duże projekty'; ?>
                </a>
                <a href="<?php echo url('projekty.raporty'); ?>" class="btn-hero-secondary" title="Pełne zestawienie sprzedaż / koszty / zysk per projekt z eksportem CSV">Raport kosztów</a>
                <a href="<?php echo url('projekty.history'); ?>" class="btn-hero-secondary">Historia</a>
                <a href="<?php echo url('projekty.create', ['type' => $project_type]); ?>" class="btn-hero-primary">
                    + Dodaj <?php echo $project_type === 'micro' ? 'Mikroprojekt' : 'Projekt'; ?>
                </a>
            </div>
        </div>
        
        <!-- Quick Jump -->
        <?php if (count($active_projects) > 0): ?>
            <div class="quick-jump">
                <label>Szybki wybór projektu:</label>
                <select id="quickJump" onchange="if(this.value) window.location.href='<?php echo url('projekty.view'); ?>?id='+this.value">
                    <option value="">-- Wybierz aktywny projekt --</option>
                    <?php foreach ($active_projects as $proj): ?>
                        <option value="<?php echo $proj['id']; ?>"><?php echo e($proj['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        
        <!-- Jedna sekcja: filtry statusów + KPI finansowe w jednym wierszu -->
        <?php
        $filter_label = match($status_filter) {
            'planned'      => 'planowane',
            'active'       => 'aktywne',
            'finished'     => 'zakończone',
            'archived'     => 'zarchiwizowane',
            'all_finished' => 'wszystkie + zakończone',
            default        => 'wszystkie',
        };
        $cnt = count($projects);
        $cnt_label = $cnt === 1 ? 'projekt' : ($cnt < 5 ? 'projekty' : 'projektów');
        ?>
        <div class="dashboard-row">

            <!-- Lewa kolumna: kafelki-filtry statusów -->
            <div class="status-filter-group">
                <div class="sfg-label">Filtr statusu</div>
                <div class="sfg-cards">
                    <a href="<?php echo url('projekty', ['status' => 'all', 'project_type' => $project_type]); ?>"
                       class="sfg-card <?php echo $status_filter === 'all' ? 'sfg-active' : ''; ?>">
                        <div class="sfg-value"><?php echo $stats['total']; ?></div>
                        <div class="sfg-name">Wszystkie</div>
                    </a>
                    <a href="<?php echo url('projekty', ['status' => 'planned', 'project_type' => $project_type]); ?>"
                       class="sfg-card sfg-planned <?php echo $status_filter === 'planned' ? 'sfg-active' : ''; ?>">
                        <div class="sfg-value"><?php echo $stats['planned']; ?></div>
                        <div class="sfg-name">Planowane</div>
                    </a>
                    <a href="<?php echo url('projekty', ['status' => 'active', 'project_type' => $project_type]); ?>"
                       class="sfg-card sfg-active-status <?php echo $status_filter === 'active' ? 'sfg-active' : ''; ?>">
                        <div class="sfg-value"><?php echo $stats['active']; ?></div>
                        <div class="sfg-name">Aktywne</div>
                    </a>
                    <a href="<?php echo url('projekty', ['status' => 'all_finished', 'project_type' => $project_type]); ?>"
                       class="sfg-card sfg-finished <?php echo $status_filter === 'all_finished' ? 'sfg-active' : ''; ?>">
                        <div class="sfg-value"><?php echo (int)($stats['all_with_finished'] ?? 0); ?></div>
                        <div class="sfg-name">+ Zakończone</div>
                    </a>
                    <?php if ($stats['archived'] > 0): ?>
                    <a href="<?php echo url('projekty', ['status' => 'archived', 'project_type' => $project_type]); ?>"
                       class="sfg-card sfg-archived <?php echo $status_filter === 'archived' ? 'sfg-active' : ''; ?>">
                        <div class="sfg-value"><?php echo $stats['archived']; ?></div>
                        <div class="sfg-name">Zarchiwizowane</div>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Separator pionowy -->
            <div class="dashboard-divider"></div>

            <!-- Prawa kolumna: kafelki KPI finansowe -->
            <div class="kpi-panel">
                <div class="kpi-panel-label">
                    Podsumowanie finansowe
                    <span class="kpi-panel-badge"><?php echo $filter_label; ?> · <?php echo $cnt; ?> <?php echo $cnt_label; ?></span>
                </div>
                <div class="kpi-panel-grid">
                    <div class="kpi-sum-card kpi-sum-revenue">
                        <div class="kpi-sum-label">Wartość umowy</div>
                        <div class="kpi-sum-value"><?php echo formatMoney($kpi_revenue); ?></div>
                        <div class="kpi-sum-sub">Umowy + aneksy</div>
                    </div>
                    <div class="kpi-sum-card kpi-sum-invoiced">
                        <div class="kpi-sum-label">Zafakturowano</div>
                        <div class="kpi-sum-value"><?php echo formatMoney($kpi_invoiced); ?></div>
                        <div class="kpi-sum-sub">Faktury sprzedażowe</div>
                    </div>
                    <div class="kpi-sum-card kpi-sum-received">
                        <div class="kpi-sum-label">Wpłynęło na konto</div>
                        <div class="kpi-sum-value"><?php echo formatMoney($kpi_received); ?></div>
                        <div class="kpi-sum-sub">&nbsp;</div>
                    </div>
                    <div class="kpi-sum-card kpi-sum-topay">
                        <div class="kpi-sum-label">Do zapłaty</div>
                        <div class="kpi-sum-value <?php echo $kpi_to_pay > 0 ? 'kpi-danger' : ''; ?>"><?php echo formatMoney($kpi_to_pay); ?></div>
                        <div class="kpi-sum-sub">Zafakturowano − wpłynęło</div>
                    </div>
                    <div class="kpi-sum-card kpi-sum-costs">
                        <div class="kpi-sum-label">Koszty</div>
                        <div class="kpi-sum-value"><?php echo formatMoney($kpi_total_costs); ?></div>
                        <div class="kpi-sum-sub">Faktury + wydatki + rob.</div>
                    </div>
                    <div class="kpi-sum-card kpi-sum-profit-doc">
                        <div class="kpi-sum-label">Zysk (dokument)</div>
                        <div class="kpi-sum-value <?php echo $kpi_profit_doc >= 0 ? 'kpi-positive' : 'kpi-danger'; ?>"><?php echo formatMoney($kpi_profit_doc); ?></div>
                        <div class="kpi-sum-sub">Zafakturowano − koszty</div>
                    </div>
                    <div class="kpi-sum-card kpi-sum-profit-cash">
                        <div class="kpi-sum-label">Zysk (kasa)</div>
                        <div class="kpi-sum-value <?php echo $kpi_profit_cash >= 0 ? 'kpi-positive' : 'kpi-danger'; ?>"><?php echo formatMoney($kpi_profit_cash); ?></div>
                        <div class="kpi-sum-sub">Wpłynęło − koszty</div>
                    </div>
                    <div class="kpi-sum-card kpi-sum-margin">
                        <div class="kpi-sum-label">Marża</div>
                        <div class="kpi-sum-value <?php echo $kpi_margin !== null ? ($kpi_margin >= 0 ? 'kpi-positive' : 'kpi-danger') : ''; ?>">
                            <?php echo $kpi_margin !== null ? number_format($kpi_margin, 1) . '%' : '–'; ?>
                        </div>
                        <div class="kpi-sum-sub">Na podst. wartości umów</div>
                    </div>
                </div>
            </div>

        </div>
        
        <div class="card">
            <form method="GET" action="" class="filter-bar">
                <div class="filter-group fg-status">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="active"       <?php echo $status_filter === 'active'       ? 'selected' : ''; ?>>Aktywne</option>
                        <option value="all"          <?php echo $status_filter === 'all'          ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="planned"      <?php echo $status_filter === 'planned'      ? 'selected' : ''; ?>>Planowane</option>
                        <option value="all_finished" <?php echo $status_filter === 'all_finished' ? 'selected' : ''; ?>>Wszystkie + zakończone</option>
                        <option value="archived"     <?php echo $status_filter === 'archived'     ? 'selected' : ''; ?>>Zarchiwizowane</option>
                    </select>
                </div>
                <div class="filter-group fg-investor">
                    <label>Inwestor</label>
                    <select name="investor_id" onchange="this.form.submit()">
                        <option value="">Wszyscy</option>
                        <?php foreach ($investors_list as $inv): ?>
                            <option value="<?php echo $inv['id']; ?>" <?php echo $investor_filter == $inv['id'] ? 'selected' : ''; ?>>
                                <?php echo e($inv['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group fg-search">
                    <label>Szukaj</label>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Nazwa projektu lub etap...">
                </div>
                <button type="submit" class="btn btn-primary">Filtruj</button>
                <?php if ($status_filter !== 'active' || $investor_filter !== '' || $search !== ''): ?>
                    <a href="<?php echo url('projekty'); ?>" class="btn btn-secondary">Wyczyść</a>
                <?php endif; ?>
            </form>

            <div class="spx-controls-bar">
                <div class="spx-controls-left"></div>
                <div class="spx-controls-right">
                    <button type="button" class="btn-color-mode active" onclick="toggleColors()" title="Kolory wierszy">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <path d="M12 2a10 10 0 0 1 0 20"></path>
                            <circle cx="12" cy="12" r="4"></circle>
                        </svg>
                    </button>
                    <div class="spx-separator"></div>
                    <div class="spx-toggle-group">
                        <button type="button" class="spx-btn-toggle" onclick="expandAllProjects()">Rozwiń</button>
                        <button type="button" class="spx-btn-toggle" onclick="collapseAllProjects()">Zwiń</button>
                    </div>
                </div>
            </div>
            
            <?php if (count($projects) > 0): ?>
                <div class="table-scroll-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th data-sort="string" class="sortable">Nazwa projektu <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Inwestor <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Status <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Umowa <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Zafakturowano <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Wpłynęło <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Do zapłaty <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Robocizna <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Materiały <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Wyd. drobne <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Zysk (dok.) <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Zysk (kasa) <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Marża <span class="sort-icon"></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $project_index = 0;
                        foreach ($projects as $project): 
                            $project_index++;
                            $group_class = ($project_index % 2 === 0) ? 'even-group' : 'odd-group';
                            
                            // Generuj kolor projektu
                            $projectColor = getProjectColor($project['id']);
                            $projectStyle = "--project-bg: {$projectColor['projectBg']}; --project-border: {$projectColor['projectBorder']};";
                            ?>
                            <?php
                            $total_revenue = (float)$project['total_revenue'];
                            $invoiced_amount = (float)$project['invoiced_amount'];
                            $received_amount = (float)$project['received_amount'];
                            $to_pay = $invoiced_amount - $received_amount;
                            $labor_cost = (float)$project['labor_cost'];
                            $cash_expenses = (float)$project['cash_expenses'];
                            $material_cost = (float)$project['material_cost'];
                            $total_costs = $labor_cost + $material_cost + $cash_expenses;
                            $current_profit = $total_revenue - $total_costs;
                            $profit_cash = $received_amount - $total_costs;
                            
                            $status_label = [
                                'planned' => 'Planowany',
                                'active' => 'Aktywny',
                                'finished' => 'Zakończony'
                            ][$project['status']];
                            
                            if ($current_profit > 0) {
                                $profit_class = 'profit-positive';
                            } elseif ($current_profit < 0) {
                                $profit_class = 'profit-negative';
                            } else {
                                $profit_class = 'profit-zero';
                            }

                            if ($profit_cash > 0) {
                                $profit_cash_class = 'profit-positive';
                            } elseif ($profit_cash < 0) {
                                $profit_cash_class = 'profit-negative';
                            } else {
                                $profit_cash_class = 'profit-zero';
                            }
                            
                            if ($total_revenue > 0) {
                                $margin_pct = (($total_revenue - $total_costs) / $total_revenue) * 100;
                                $margin_class = $margin_pct > 0 ? 'margin-positive' : ($margin_pct < 0 ? 'margin-negative' : 'margin-zero');
                            } else {
                                $margin_pct = null;
                                $margin_class = 'margin-zero';
                            }
                            
                            $has_nodes = ($project['nodes_count'] > 0);
                            ?>
                            <!-- === PROJECT ROW === -->
                            <tr class="project-row <?php echo $group_class; ?>" style="<?php echo $projectStyle; ?>" data-project-id="<?php echo $project['id']; ?>">
                                <td>
                                    <div class="tree-row-main">
                                        <?php if ($has_nodes): ?>
                                            <button type="button" class="toggle-stages expanded row-toggle-overlay" data-project-id="<?php echo $project['id']; ?>" title="Zwiń/Rozwiń etapy" aria-label="Zwiń/Rozwiń etapy">▾</button>
                                        <?php endif; ?>
                                        <a href="<?php echo url('projekty.view', ['id' => $project['id']]); ?>" class="project-name-link">
                                            <?php echo e($project['name']); ?>
                                        </a>
                                        <?php if ($has_nodes): ?>
                                            <span class="badge badge-stage"><?php echo $project['nodes_count']; ?> etap<?php echo $project['nodes_count'] == 1 ? '' : ($project['nodes_count'] < 5 ? 'y' : 'ów'); ?></span>
                                            <span class="stage-sort-btn" data-project-id="<?php echo $project['id']; ?>" data-sort-dir="asc" title="Sortuj etapy wg daty"><span class="sort-arrow">↑</span> etapy</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="investor-cell" title="<?php echo e($project['investor_name'] ?? ''); ?>">
                                    <?php if ($project['investor_name']): ?>
                                        <?php echo e($project['investor_name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-dot <?php echo $project['status']; ?>" title="<?php echo $status_label; ?>"></span>
                                </td>
                                <td class="revenue-value text-right"><?php echo formatMoney($total_revenue); ?></td>
                                <td class="revenue-value text-right"><?php echo $invoiced_amount > 0 ? formatMoney($invoiced_amount) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="received-value text-right"><?php echo $received_amount > 0 ? formatMoney($received_amount) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="text-right" style="color:<?php echo $to_pay > 0 ? '#dc2626' : '#6b7280'; ?>; font-weight:600;"><?php echo $to_pay > 0 ? formatMoney($to_pay) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="cost-value text-right"><?php echo formatMoney($labor_cost); ?></td>
                                <td class="cost-value text-right"><?php echo formatMoney($material_cost); ?></td>
                                <td class="cost-value text-right"><?php echo $cash_expenses > 0 ? formatMoney($cash_expenses) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="text-right <?php echo $profit_class; ?>">
                                    <?php echo formatMoney($current_profit); ?>
                                </td>
                                <td class="text-right <?php echo $profit_cash_class; ?>">
                                    <?php echo $received_amount > 0 ? formatMoney($profit_cash) : '<span class="text-muted">–</span>'; ?>
                                </td>
                                <td class="text-right <?php echo $margin_class; ?>">
                                    <?php if ($margin_pct !== null): ?>
                                        <?php echo number_format($margin_pct, 1); ?>%
                                    <?php else: ?>
                                        <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            
                            <?php 
                            // =====================================================
                            // STAGES (Cost Nodes) + SUB-STAGES (Podetapy)
                            // =====================================================
                            if ($has_nodes):
                                $nodes_stmt = $pdo->prepare("
                                    SELECT 
                                        pcn.id,
                                        pcn.name,
                                        pcn.description,
                                        pcn.sort_order
                                    FROM project_cost_nodes pcn
                                    WHERE pcn.project_id = :project_id 
                                      AND pcn.parent_id IS NULL
                                      AND pcn.is_active = 1
                                    ORDER BY pcn.sort_order ASC, pcn.id ASC
                                ");
                                $nodes_stmt->execute(['project_id' => $project['id']]);
                                $nodes = $nodes_stmt->fetchAll();
                                
                                $stage_index = 0;
                                foreach ($nodes as $node):
                                    $stage_index++;
                                    $node_color = ($stage_index % 2 === 1) ? 'node-blue' : 'node-orange';
                                    $stageHue = round(fmod(((float)$projectColor['hue']) + ($stage_index * 9), 360));
                                    $stageSat = max(50, min(80, (int)$projectColor['sat']));
                                    $stageLightBg = max(42, min(62, (int)$projectColor['light'] + (($stage_index % 4) - 1) * 3));
                                    $stageLightBorder = max(36, min(58, $stageLightBg - 4));
                                    $stageStyle = "--stage-bg: hsla({$stageHue}, {$stageSat}%, {$stageLightBg}%, 0.09); --stage-border: hsla({$stageHue}, {$stageSat}%, {$stageLightBorder}%, 0.65);";
                                    
                                    // Zbierz potomne IDs
                                    $node_all_ids = [(int)$node['id']];
                                    $node_descendants = getDescendantNodeIds($pdo, (int)$node['id']);
                                    $node_all_ids = array_merge($node_all_ids, $node_descendants);
                                    $node_placeholders = implode(',', array_fill(0, count($node_all_ids), '?'));
                                    $has_children = count($node_all_ids) > 1;
                                    
                                    // Fetch child nodes for this stage
                                    $children_nodes = [];
                                    if ($has_children) {
                                        $children_stmt = $pdo->prepare("
                                            SELECT id, name, sort_order
                                            FROM project_cost_nodes
                                            WHERE parent_id = ? AND is_active = 1
                                            ORDER BY sort_order ASC, id ASC
                                        ");
                                        $children_stmt->execute([(int)$node['id']]);
                                        $children_nodes = $children_stmt->fetchAll();
                                    }
                                    
                                    // === STAGE-LEVEL COSTS (aggregate) ===
                                    $node_labor_stmt = $pdo->prepare("
                                        SELECT COALESCE(SUM(COALESCE(final_cost, system_cost, 0)), 0) as labor_cost
                                        FROM work_logs 
                                        WHERE cost_node_id IN ({$node_placeholders}) AND status = 'approved'
                                    ");
                                    $node_labor_stmt->execute($node_all_ids);
                                    $node_labor = (float)$node_labor_stmt->fetchColumn();
                                    
                                    $node_expense_stmt = $pdo->prepare("
                                        SELECT COALESCE(SUM(expense_cost), 0) as expense_cost
                                        FROM (
                                            SELECT COALESCE(SUM(amount), 0) as expense_cost
                                            FROM worker_expenses
                                            WHERE cost_node_id IN ({$node_placeholders}) AND status = 'approved'
                                            AND document_id IS NULL
                                            UNION ALL
                                            SELECT COALESCE(SUM(amount_net), 0) as expense_cost
                                            FROM finance_items
                                            WHERE etap_id IN ({$node_placeholders}) AND item_type = 'FIXED_COST'
                                            AND status = 'approved'
                                        ) expenses
                                    ");
                                    $node_expense_stmt->execute(array_merge($node_all_ids, $node_all_ids));
                                    $node_expenses = (float)$node_expense_stmt->fetchColumn();
                                    
                                    $node_alloc_new_stmt = $pdo->prepare("
                                        SELECT COALESCE(SUM(dia.amount), 0) as alloc_cost
                                        FROM document_item_allocations dia
                                        JOIN document_items di ON dia.document_item_id = di.id
                                        JOIN documents d ON di.document_id = d.id
                                        WHERE dia.cost_node_id IN ({$node_placeholders})
                                          AND d.status = 'approved'
                                          AND d.type = 'invoice_cost'
                                    ");
                                    $node_alloc_new_stmt->execute($node_all_ids);
                                    $node_materials_new = (float)$node_alloc_new_stmt->fetchColumn();
                                    
                                    $node_alloc_leg_stmt = $pdo->prepare("
                                        SELECT COALESCE(SUM(da.amount_net), 0) as alloc_cost
                                        FROM document_allocations da
                                        JOIN documents d ON d.id = da.document_id
                                        WHERE da.cost_node_id IN ({$node_placeholders})
                                          AND d.status = 'approved'
                                          AND d.type = 'invoice_cost'
                                          AND da.is_legacy = 1
                                    ");
                                    $node_alloc_leg_stmt->execute($node_all_ids);
                                    $node_materials_leg = (float)$node_alloc_leg_stmt->fetchColumn();
                                    
                                    $node_materials = $node_materials_new + $node_materials_leg;
                                    $node_total_costs = $node_labor + $node_expenses + $node_materials;
                                    
                                    if ($node_total_costs > 0) {
                                        $node_profit_class = 'profit-negative';
                                    } else {
                                        $node_profit_class = 'profit-zero';
                                    }
                            ?>
                            <!-- === STAGE ROW === -->
                            <tr class="node-row <?php echo $node_color; ?> <?php echo $group_class; ?>" style="<?php echo $stageStyle; ?>" data-parent-project="<?php echo $project['id']; ?>" data-stage-id="<?php echo $node['id']; ?>" data-sort-id="<?php echo $node['id']; ?>">
                                <td>
                                    <div class="tree-row-main">
                                        <?php if ($has_children): ?>
                                            <button type="button" class="toggle-stages toggle-substages expanded row-toggle-overlay" data-stage-id="<?php echo $node['id']; ?>" title="Zwiń/Rozwiń podetapy" aria-label="Zwiń/Rozwiń podetapy">▾</button>
                                        <?php endif; ?>
                                        <a href="<?php echo url('projekty.etap.view', ['id' => $node['id']]); ?>" class="node-name-link">
                                            <?php echo e($node['name']); ?>
                                        </a>
                                        <?php if ($has_children): ?>
                                            <span class="badge-substages">+<?php echo count($node_all_ids) - 1; ?> podet.</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="text-muted">–</span></td>
                                <td><span class="text-muted">–</span></td>
                                <td class="cost-value text-right"><span class="text-muted">–</span></td>
                                <td class="cost-value text-right"><span class="text-muted">–</span></td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                                <td class="cost-value text-right"><?php echo $node_labor > 0 ? formatMoney($node_labor) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="cost-value text-right"><?php echo $node_materials > 0 ? formatMoney($node_materials) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="cost-value text-right"><?php echo $node_expenses > 0 ? formatMoney($node_expenses) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="text-right <?php echo $node_total_costs > 0 ? $node_profit_class : ''; ?>">
                                    <?php if ($node_total_costs > 0): ?>
                                        <?php echo formatMoney(-$node_total_costs); ?>
                                    <?php else: ?>
                                        <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                            </tr>
                            <?php
                                    // === SUB-STAGE (PODETAP) ROWS ===
                                    if ($has_children):
                                        $child_index = 0;
                                        foreach ($children_nodes as $child):
                                            $child_index++;
                                            $subHue = round(fmod((float)$stageHue + ($child_index * 7), 360));
                                            $subSat = max(48, $stageSat - 4);
                                            $subLightBg = max(48, min(68, $stageLightBg + 5 + (($child_index % 3) * 2)));
                                            $subLightBorder = max(42, min(64, $subLightBg - 5));
                                            $subStageStyle = "--sub-stage-bg: hsla({$subHue}, {$subSat}%, {$subLightBg}%, 0.07); --sub-stage-border: hsla({$subHue}, {$subSat}%, {$subLightBorder}%, 0.45);";
                                            // Costs for individual child
                                            $child_all_ids = [(int)$child['id']];
                                            $child_descendants = getDescendantNodeIds($pdo, (int)$child['id']);
                                            $child_all_ids = array_merge($child_all_ids, $child_descendants);
                                            $child_ph = implode(',', array_fill(0, count($child_all_ids), '?'));
                                            
                                            $cl_stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(final_cost, system_cost, 0)), 0) FROM work_logs WHERE cost_node_id IN ({$child_ph}) AND status = 'approved'");
                                            $cl_stmt->execute($child_all_ids);
                                            $child_labor = (float)$cl_stmt->fetchColumn();
                                            
                                            $ce_stmt = $pdo->prepare("
                                                SELECT COALESCE(SUM(expense_cost), 0)
                                                FROM (
                                                    SELECT COALESCE(SUM(amount), 0) as expense_cost
                                                    FROM worker_expenses
                                                    WHERE cost_node_id IN ({$child_ph}) AND status = 'approved' AND document_id IS NULL
                                                    UNION ALL
                                                    SELECT COALESCE(SUM(amount_net), 0) as expense_cost
                                                    FROM finance_items
                                                    WHERE etap_id IN ({$child_ph}) AND item_type = 'FIXED_COST' AND status = 'approved'
                                                ) expenses
                                            ");
                                            $ce_stmt->execute(array_merge($child_all_ids, $child_all_ids));
                                            $child_expenses = (float)$ce_stmt->fetchColumn();
                                            
                                            $cm_new_stmt = $pdo->prepare("SELECT COALESCE(SUM(dia.amount), 0) FROM document_item_allocations dia JOIN document_items di ON dia.document_item_id = di.id JOIN documents d ON di.document_id = d.id WHERE dia.cost_node_id IN ({$child_ph}) AND d.status = 'approved' AND d.type = 'invoice_cost'");
                                            $cm_new_stmt->execute($child_all_ids);
                                            $child_mat_new = (float)$cm_new_stmt->fetchColumn();
                                            
                                            $cm_leg_stmt = $pdo->prepare("SELECT COALESCE(SUM(da.amount_net), 0) FROM document_allocations da JOIN documents d ON d.id = da.document_id WHERE da.cost_node_id IN ({$child_ph}) AND d.status = 'approved' AND d.type = 'invoice_cost' AND da.is_legacy = 1");
                                            $cm_leg_stmt->execute($child_all_ids);
                                            $child_mat_leg = (float)$cm_leg_stmt->fetchColumn();
                                            
                                            $child_materials = $child_mat_new + $child_mat_leg;
                                            $child_total = $child_labor + $child_expenses + $child_materials;
                                            $child_profit_class = $child_total > 0 ? 'profit-negative' : 'profit-zero';
                            ?>
                            <!-- === SUB-STAGE ROW === -->
                            <tr class="subnode-row <?php echo $node_color; ?> <?php echo $group_class; ?>" style="<?php echo $subStageStyle; ?>" data-parent-project="<?php echo $project['id']; ?>" data-parent-stage="<?php echo $node['id']; ?>">
                                <td>
                                    <a href="<?php echo url('projekty.etap.view', ['id' => $child['id']]); ?>" class="subnode-name-link">
                                        ↳ <?php echo e($child['name']); ?>
                                    </a>
                                </td>
                                <td><span class="text-muted">–</span></td>
                                <td><span class="text-muted">–</span></td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                                <td class="cost-value text-right"><?php echo $child_labor > 0 ? formatMoney($child_labor) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="cost-value text-right"><?php echo $child_materials > 0 ? formatMoney($child_materials) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="cost-value text-right"><?php echo $child_expenses > 0 ? formatMoney($child_expenses) : '<span class="text-muted">–</span>'; ?></td>
                                <td class="text-right <?php echo $child_total > 0 ? $child_profit_class : ''; ?>">
                                    <?php if ($child_total > 0): ?>
                                        <?php echo formatMoney(-$child_total); ?>
                                    <?php else: ?>
                                        <span class="text-muted">–</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                                <td class="text-right"><span class="text-muted">–</span></td>
                            </tr>
                            <?php 
                                endforeach;
                                    endif; // has_children
                                endforeach; // nodes
                            endif; // has_nodes
                            ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    Brak projektów do wyświetlenia.
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const table = document.querySelector('table');
        if (!table) return;
        
        const headers = table.querySelectorAll('th.sortable');
        const tbody = table.querySelector('tbody');
        
        // ================================================
        // 1. EXPAND / COLLAPSE - Projects ↔ Stages
        // ================================================
        function initToggle() {
            document.querySelectorAll('.toggle-stages').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const projectId = this.dataset.projectId;
                    const stageId = this.dataset.stageId;
                    const isExpanded = this.classList.contains('expanded');
                    
                    if (projectId) {
                        // Toggle stages under project
                        const stages = tbody.querySelectorAll('tr.node-row[data-parent-project="' + projectId + '"]');
                        const substages = tbody.querySelectorAll('tr.subnode-row[data-parent-project="' + projectId + '"]');
                        stages.forEach(function(r) { r.classList.toggle('collapsed', isExpanded); });
                        substages.forEach(function(r) { r.classList.toggle('collapsed', isExpanded); });
                        // Update child stage toggles
                        stages.forEach(function(r) {
                            const childToggle = r.querySelector('.toggle-stages');
                            if (childToggle) {
                                if (isExpanded) {
                                    childToggle.classList.remove('expanded');
                                    childToggle.textContent = '▸';
                                } else {
                                    childToggle.classList.add('expanded');
                                    childToggle.textContent = '▾';
                                }
                            }
                        });
                    } else if (stageId) {
                        // Toggle sub-stages under stage
                        const substages = tbody.querySelectorAll('tr.subnode-row[data-parent-stage="' + stageId + '"]');
                        substages.forEach(function(r) { r.classList.toggle('collapsed', isExpanded); });
                    }
                    
                    if (isExpanded) {
                        this.classList.remove('expanded');
                        this.textContent = '▸';
                    } else {
                        this.classList.add('expanded');
                        this.textContent = '▾';
                    }
                });
            });
        }
        initToggle();
        
        // ================================================
        // EXPAND ALL / COLLAPSE ALL PROJECTS
        // ================================================
        window.expandAllProjects = function() {
            tbody.querySelectorAll('tr.node-row, tr.subnode-row').forEach(function(row) {
                row.classList.remove('collapsed');
            });
            document.querySelectorAll('.toggle-stages').forEach(function(btn) {
                btn.classList.add('expanded');
                btn.textContent = '▾';
            });
        };
        
        window.collapseAllProjects = function() {
            tbody.querySelectorAll('tr.node-row, tr.subnode-row').forEach(function(row) {
                row.classList.add('collapsed');
            });
            document.querySelectorAll('.toggle-stages').forEach(function(btn) {
                btn.classList.remove('expanded');
                btn.textContent = '▸';
            });
        };
        
        // ================================================
        // 2. TABLE COLUMN SORTING
        // ================================================
        headers.forEach(function(header, columnIndex) {
            header.addEventListener('click', function() {
                const sortType = this.dataset.sort;
                const isAsc = this.classList.contains('asc');
                
                headers.forEach(function(h) { h.classList.remove('asc', 'desc'); });
                this.classList.add(isAsc ? 'desc' : 'asc');
                const direction = isAsc ? -1 : 1;
                
                const projectRows = Array.from(tbody.querySelectorAll('tr.project-row'));
                
                projectRows.sort(function(a, b) {
                    let aVal = a.cells[columnIndex].textContent.trim();
                    let bVal = b.cells[columnIndex].textContent.trim();
                    
                    if (sortType === 'number') {
                        aVal = parseFloat(aVal.replace(/[^\d,.\-]/g, '').replace(',', '.')) || 0;
                        bVal = parseFloat(bVal.replace(/[^\d,.\-]/g, '').replace(',', '.')) || 0;
                        return (aVal - bVal) * direction;
                    } else if (sortType === 'date') {
                        var parseDate = function(str) {
                            if (!str || str === '–' || str === '-') return 0;
                            var parts = str.split(' – ')[0].split('.');
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
                
                rebuildTable(projectRows);
            });
        });
        
        // ================================================
        // 3. REBUILD TABLE - preserves parent→child order
        // ================================================
        function rebuildTable(projectRows) {
            projectRows.forEach(function(projectRow, idx) {
                var projectId = projectRow.dataset.projectId;
                var groupClass = (idx % 2 === 0) ? 'odd-group' : 'even-group';
                
                    projectRow.classList.remove('odd-group', 'even-group');
                    projectRow.classList.add(groupClass);
                    tbody.appendChild(projectRow);
                    
                // Append node + subnode rows in order
                var nodeRows = Array.from(tbody.querySelectorAll('tr.node-row[data-parent-project="' + projectId + '"]'));
                nodeRows.forEach(function(nodeRow) {
                        nodeRow.classList.remove('odd-group', 'even-group');
                        nodeRow.classList.add(groupClass);
                        tbody.appendChild(nodeRow);
                    
                    var stageId = nodeRow.dataset.stageId;
                    if (stageId) {
                        var subnodeRows = Array.from(tbody.querySelectorAll('tr.subnode-row[data-parent-stage="' + stageId + '"]'));
                        subnodeRows.forEach(function(sub) {
                            sub.classList.remove('odd-group', 'even-group');
                            sub.classList.add(groupClass);
                            tbody.appendChild(sub);
                        });
                    }
                });
            });
        }
        
        // ================================================
        // 4. STAGE SORT within each project (by ID = creation order)
        // ================================================
        // Attach event to sort buttons that will be rendered per-project
        document.querySelectorAll('.stage-sort-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var projectId = this.dataset.projectId;
                var currentDir = this.dataset.sortDir || 'asc';
                var newDir = currentDir === 'asc' ? 'desc' : 'asc';
                this.dataset.sortDir = newDir;
                this.classList.add('active');
                this.querySelector('.sort-arrow').textContent = newDir === 'asc' ? '↑' : '↓';
                
                // Get stage rows for this project
                var stageRows = Array.from(tbody.querySelectorAll('tr.node-row[data-parent-project="' + projectId + '"]'));
                
                stageRows.sort(function(a, b) {
                    var aId = parseInt(a.dataset.sortId) || 0;
                    var bId = parseInt(b.dataset.sortId) || 0;
                    if (newDir === 'asc') return aId - bId;
                    return bId - aId;
                });
                
                // Find the project row
                var projectRow = tbody.querySelector('tr.project-row[data-project-id="' + projectId + '"]');
                var insertAfter = projectRow;
                
                stageRows.forEach(function(stageRow) {
                    var stageId = stageRow.dataset.stageId;
                    insertAfter.parentNode.insertBefore(stageRow, insertAfter.nextSibling);
                    insertAfter = stageRow;
                    
                    // Move sub-stages after their parent stage
                    if (stageId) {
                        var subnodeRows = Array.from(tbody.querySelectorAll('tr.subnode-row[data-parent-stage="' + stageId + '"]'));
                        subnodeRows.forEach(function(sub) {
                            insertAfter.parentNode.insertBefore(sub, insertAfter.nextSibling);
                            insertAfter = sub;
                        });
                    }
                });
            });
        });
        
        // ========================================
        // TOGGLE KOLORÓW PROJEKTÓW
        // ========================================
        window.toggleColors = function() {
            document.body.classList.toggle('no-colors');
            const active = !document.body.classList.contains('no-colors');
            document.querySelectorAll('.btn-color-mode').forEach(function(btn) {
                btn.classList.toggle('active', active);
            });
            
            // Zapisz stan w localStorage
            localStorage.setItem('projectsTreeColorsEnabled', active ? '1' : '0');
            
            console.log('Toggle kolory:', active ? 'WŁĄCZONE' : 'WYŁĄCZONE (zebra)');
        };
        
        // Przywróć stan kolorów z localStorage
        const colorsEnabled = localStorage.getItem('projectsTreeColorsEnabled');
        if (colorsEnabled === '0') {
            document.body.classList.add('no-colors');
            document.querySelectorAll('.btn-color-mode').forEach(function(btn) {
                btn.classList.remove('active');
            });
        } else {
            document.querySelectorAll('.btn-color-mode').forEach(function(btn) {
                btn.classList.add('active');
            });
        }
        
        // Domyślnie zwiń wszystkie projekty (etapy i podetapy)
        collapseAllProjects();
    });
    </script>
</body>
</html>
