<?php
/**
 * BRYGAD ERP v3.0 - Szczegóły Etapu (Cost Node View)
 * 
 * Layout identyczny jak view.php ale dane tylko dla wybranego etapu (cost_node_id)
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$node_id = $_GET['id'] ?? null;
$isAdminUser = isAdmin();

if (!$node_id) {
    header("Location: " . url('projekty'));
    exit;
}

// Pobierz dane etapu z danymi projektu
$stmt = $pdo->prepare("
    SELECT 
        pcn.*,
        p.id as project_id,
        p.name as project_name,
        p.status as project_status,
        p.is_internal,
        p.investor_id
    FROM project_cost_nodes pcn
    JOIN projects p ON pcn.project_id = p.id
    WHERE pcn.id = :id
");
$stmt->execute(['id' => $node_id]);
$node = $stmt->fetch();

if (!$node) {
    header("Location: " . url('projekty'));
    exit;
}

$project_id = $node['project_id'];

// Pobierz wszystkie etapy projektu (dla dropdowna)
$cost_stages_sql = "SELECT id, name, parent_id FROM project_cost_nodes WHERE project_id = ? AND is_active = 1 ORDER BY sort_order ASC";
$stmt = $pdo->prepare($cost_stages_sql);
$stmt->execute([$project_id]);
$cost_stages = $stmt->fetchAll();

// =====================================================
// ZBIERZ WSZYSTKIE POTOMNE NODE IDs (podetapy + ich dzieci rekurencyjnie)
// Etap powinien pokazywać dane własne + dane wszystkich podetapów
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

$all_node_ids = [(int)$node_id];
$descendant_ids = getDescendantNodeIds($pdo, (int)$node_id);
$all_node_ids = array_merge($all_node_ids, $descendant_ids);

// Buduj placeholder string dla IN clause: ?,?,?
$node_in_placeholders = implode(',', array_fill(0, count($all_node_ids), '?'));
// Buduj named placeholder: :nid0, :nid1, ...
$node_named_placeholders = [];
$node_named_params = [];
foreach ($all_node_ids as $idx => $nid) {
    $key = 'nid' . $idx;
    $node_named_placeholders[] = ':' . $key;
    $node_named_params[$key] = $nid;
}
$node_in_named = implode(',', $node_named_placeholders);

// =====================================================
// DATE FILTER LOGIC + QUICK PRESETS
// =====================================================
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;
$date_range = $_GET['range'] ?? '';

// Quick presets
if ($date_range && !$date_from && !$date_to) {
    $today = date('Y-m-d');
    switch ($date_range) {
        case 'today':
            $date_from = $today;
            $date_to = $today;
            break;
        case 'week':
            $date_from = date('Y-m-d', strtotime('monday this week'));
            $date_to = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'month':
            $date_from = date('Y-m-01');
            $date_to = date('Y-m-t');
            break;
    }
}

$has_date_filter = ($date_from || $date_to);

// Worker filters
$worker_filter = $_GET['worker_id'] ?? '';
$expense_worker_filter = $_GET['expense_worker_id'] ?? '';

// Invoice filters
$inv_search = $_GET['inv_search'] ?? '';
$inv_category = $_GET['inv_category'] ?? '';
$inv_vendor = $_GET['inv_vendor'] ?? '';

// =====================================================
// LABOR COSTS (robocizna: ten etap + wszystkie podetapy)
// =====================================================
$labor_date_condition = "";
$labor_worker_condition = "";
$labor_params = $node_named_params; // nid0, nid1, ...

if ($date_from) {
    $labor_date_condition .= " AND wl.date >= :date_from";
    $labor_params['date_from'] = $date_from;
}
if ($date_to) {
    $labor_date_condition .= " AND wl.date <= :date_to";
    $labor_params['date_to'] = $date_to;
}
if ($worker_filter !== '') {
    $labor_worker_condition = " AND wl.worker_id = :worker_id";
    $labor_params['worker_id'] = $worker_filter;
}

$labor_sql = "
    SELECT 
        COUNT(*) as logs_count,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' THEN wl.hours ELSE 0 END), 0) as total_hours,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' THEN wl.overtime_hours ELSE 0 END), 0) as total_overtime,
        COALESCE(SUM(COALESCE(wl.final_cost, wl.system_cost)), 0) as total_cost,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_saturday = 1 THEN wl.hours ELSE 0 END), 0) as sat_hours,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_sunday = 1 THEN wl.hours ELSE 0 END), 0) as sun_hours,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_night = 1 THEN wl.hours ELSE 0 END), 0) as night_hours,
        COALESCE(SUM(CASE WHEN wl.work_type = 'vacation' THEN CASE WHEN wl.absence_days IS NOT NULL AND wl.absence_days > 0 THEN wl.absence_days ELSE wl.vacation_hours / 8 END ELSE 0 END), 0) as vacation_days,
        COALESCE(SUM(CASE WHEN wl.work_type = 'sick' THEN CASE WHEN wl.absence_days IS NOT NULL AND wl.absence_days > 0 THEN wl.absence_days ELSE wl.sickleave_hours / 8 END ELSE 0 END), 0) as sick_days
    FROM work_logs wl
    WHERE wl.cost_node_id IN ({$node_in_named}) AND wl.status = 'approved'
    {$labor_date_condition}
    {$labor_worker_condition}
";
$stmt = $pdo->prepare($labor_sql);
$stmt->execute($labor_params);
$labor_summary = $stmt->fetch();

// Labor by worker
$labor_worker_sql = "
    SELECT 
        w.id,
        w.first_name,
        w.last_name,
        COUNT(wl.id) as logs_count,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' THEN wl.hours ELSE 0 END), 0) as total_hours,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' THEN wl.overtime_hours ELSE 0 END), 0) as total_overtime,
        COALESCE(SUM(COALESCE(wl.final_cost, wl.system_cost)), 0) as total_cost,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_saturday = 1 THEN wl.hours ELSE 0 END), 0) as sat_hours,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_sunday = 1 THEN wl.hours ELSE 0 END), 0) as sun_hours,
        COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_night = 1 THEN wl.hours ELSE 0 END), 0) as night_hours,
        COALESCE(SUM(CASE WHEN wl.work_type = 'vacation' THEN CASE WHEN wl.absence_days IS NOT NULL AND wl.absence_days > 0 THEN wl.absence_days ELSE wl.vacation_hours / 8 END ELSE 0 END), 0) as vacation_days,
        COALESCE(SUM(CASE WHEN wl.work_type = 'sick' THEN CASE WHEN wl.absence_days IS NOT NULL AND wl.absence_days > 0 THEN wl.absence_days ELSE wl.sickleave_hours / 8 END ELSE 0 END), 0) as sick_days
    FROM workers w
    JOIN work_logs wl ON wl.worker_id = w.id
    WHERE wl.cost_node_id IN ({$node_in_named}) AND wl.status = 'approved'
    {$labor_date_condition}
    {$labor_worker_condition}
    GROUP BY w.id
    ORDER BY total_cost DESC
";
$stmt = $pdo->prepare($labor_worker_sql);
$stmt->execute($labor_params);
$labor_by_worker = $stmt->fetchAll();

// Workers list for filter (ze wszystkich node'ów: etap + podetapy)
$workers_on_node_sql = "
    SELECT DISTINCT w.id, w.first_name, w.last_name
    FROM workers w
    JOIN work_logs wl ON wl.worker_id = w.id
    WHERE wl.cost_node_id IN ({$node_in_placeholders}) AND wl.status = 'approved'
    ORDER BY w.last_name, w.first_name
";
$stmt = $pdo->prepare($workers_on_node_sql);
$stmt->execute($all_node_ids);
$workers_on_project = $stmt->fetchAll();

// =====================================================
// WORKER EXPENSES (wydatki: ten etap + wszystkie podetapy)
// =====================================================
$expense_date_condition = "";
$company_expense_date_condition = "";
$expense_worker_condition = "";
$expense_params = $node_named_params; // nid0, nid1, ...

if ($date_from) {
    $expense_date_condition .= " AND we.date >= :date_from";
    $company_expense_date_condition .= " AND fi.issue_date >= :date_from";
    $expense_params['date_from'] = $date_from;
}
if ($date_to) {
    $expense_date_condition .= " AND we.date <= :date_to";
    $company_expense_date_condition .= " AND fi.issue_date <= :date_to";
    $expense_params['date_to'] = $date_to;
}
if ($expense_worker_filter !== '') {
    $expense_worker_condition = " AND we.worker_id = :expense_worker_id";
    $expense_params['expense_worker_id'] = $expense_worker_filter;
}

try {
$expenses_sql = "
    SELECT COUNT(*) as expenses_count, COALESCE(SUM(we.amount), 0) as total_amount
    FROM worker_expenses we
    WHERE we.cost_node_id IN ({$node_in_named}) AND we.status = 'approved'
    AND we.document_id IS NULL
    {$expense_date_condition}
    {$expense_worker_condition}
";
$stmt = $pdo->prepare($expenses_sql);
$stmt->execute($expense_params);
$expenses_summary = $stmt->fetch() ?: ['expenses_count' => 0, 'total_amount' => 0];

if ($expense_worker_filter === '') {
    $company_expense_params = $node_named_params;
    if ($date_from) {
        $company_expense_params['date_from'] = $date_from;
    }
    if ($date_to) {
        $company_expense_params['date_to'] = $date_to;
    }

    $company_expenses_sql = "
        SELECT COUNT(*) as expenses_count, COALESCE(SUM(fi.amount_net), 0) as total_amount
        FROM finance_items fi
        WHERE fi.etap_id IN ({$node_in_named})
          AND fi.item_type = 'FIXED_COST'
          AND fi.status = 'approved'
          {$company_expense_date_condition}
    ";
    $stmt = $pdo->prepare($company_expenses_sql);
    $stmt->execute($company_expense_params);
    $company_expenses_summary = $stmt->fetch() ?: ['expenses_count' => 0, 'total_amount' => 0];
    $expenses_summary['expenses_count'] = (int)$expenses_summary['expenses_count'] + (int)$company_expenses_summary['expenses_count'];
    $expenses_summary['total_amount'] = (float)$expenses_summary['total_amount'] + (float)$company_expenses_summary['total_amount'];
}

// Expenses list
$expenses_list_sql = "
    SELECT
        we.id,
        we.date,
        we.description,
        we.amount,
        we.expense_type,
        we.paid_by_employee,
        w.first_name,
        w.last_name,
        pcn.name as cost_node_name
    FROM worker_expenses we
    JOIN workers w ON we.worker_id = w.id
    LEFT JOIN project_cost_nodes pcn ON pcn.id = we.cost_node_id
    WHERE we.cost_node_id IN ({$node_in_named}) AND we.status IN ('pending', 'approved')
    AND we.document_id IS NULL
    {$expense_date_condition}
    {$expense_worker_condition}
    ORDER BY we.date DESC
";
$stmt = $pdo->prepare($expenses_list_sql);
$stmt->execute($expense_params);
$expenses_list = $stmt->fetchAll();

if ($expense_worker_filter === '') {
    $company_expense_params = $node_named_params;
    if ($date_from) {
        $company_expense_params['date_from'] = $date_from;
    }
    if ($date_to) {
        $company_expense_params['date_to'] = $date_to;
    }

    $company_expenses_list_sql = "
        SELECT
            fi.id,
            fi.issue_date as date,
            COALESCE(fi.description, fi.title, 'Koszt firmowy') as description,
            fi.amount_net as amount,
            'company' as expense_type,
            0 as paid_by_employee,
            'Firma' as first_name,
            '' as last_name,
            pcn.name as cost_node_name
        FROM finance_items fi
        LEFT JOIN project_cost_nodes pcn ON pcn.id = fi.etap_id
        WHERE fi.etap_id IN ({$node_in_named})
          AND fi.item_type = 'FIXED_COST'
          AND fi.status = 'approved'
          {$company_expense_date_condition}
        ORDER BY fi.issue_date DESC
    ";
    $stmt = $pdo->prepare($company_expenses_list_sql);
    $stmt->execute($company_expense_params);
    $expenses_list = array_merge($expenses_list, $stmt->fetchAll());
    usort($expenses_list, static function (array $a, array $b): int {
        return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
    });
}
} catch (Exception $e) {
    error_log('Etap expenses load error: ' . $e->getMessage());
    $expenses_summary = ['expenses_count' => 0, 'total_amount' => 0];
    $expenses_list = [];
}

// Workers with expenses for filter (ze wszystkich node'ów)
$workers_expenses_sql = "
    SELECT DISTINCT w.id, w.first_name, w.last_name
    FROM workers w
    JOIN worker_expenses we ON we.worker_id = w.id
    WHERE we.cost_node_id IN ({$node_in_placeholders}) AND we.status = 'approved'
    AND we.document_id IS NULL
    ORDER BY w.last_name, w.first_name
";
$stmt = $pdo->prepare($workers_expenses_sql);
$stmt->execute($all_node_ids);
$workers_with_expenses = $stmt->fetchAll();

// =====================================================
// INVOICES / MATERIALS (faktury: ten etap + wszystkie podetapy)
// OBA systemy alokacji: nowy (document_item_allocations) + legacy (document_allocations)
// =====================================================

// --- Parametry wspólne dla nowego i legacy systemu ---
$invoice_params = $node_named_params; // nid0, nid1, ...

$invoice_date_condition_new = "";
$invoice_date_condition_leg = "";
$invoice_date_condition_fci = "";
if ($date_from) {
    $invoice_date_condition_new .= " AND d.issue_date >= :inv_date_from_new";
    $invoice_date_condition_leg .= " AND d.issue_date >= :inv_date_from_leg";
    $invoice_date_condition_fci .= " AND fci.issue_date >= :inv_date_from_fci";
    $invoice_params['inv_date_from_new'] = $date_from;
    $invoice_params['inv_date_from_leg'] = $date_from;
    $invoice_params['inv_date_from_fci'] = $date_from;
}
if ($date_to) {
    $invoice_date_condition_new .= " AND d.issue_date <= :inv_date_to_new";
    $invoice_date_condition_leg .= " AND d.issue_date <= :inv_date_to_leg";
    $invoice_date_condition_fci .= " AND fci.issue_date <= :inv_date_to_fci";
    $invoice_params['inv_date_to_new'] = $date_to;
    $invoice_params['inv_date_to_leg'] = $date_to;
    $invoice_params['inv_date_to_fci'] = $date_to;
}

$invoice_search_new = "";
$invoice_search_leg = "";
if ($inv_search !== '') {
    $invoice_search_new = " AND (d.number LIKE :inv_search_new OR di.item_name LIKE :inv_search_new2 OR COALESCE(i.name, d.source_name) LIKE :inv_search_new3)";
    $invoice_search_leg = " AND (d.number LIKE :inv_search_leg OR da.description LIKE :inv_search_leg2 OR COALESCE(i.name, d.source_name) LIKE :inv_search_leg3)";
    $invoice_params['inv_search_new'] = '%' . $inv_search . '%';
    $invoice_params['inv_search_new2'] = '%' . $inv_search . '%';
    $invoice_params['inv_search_new3'] = '%' . $inv_search . '%';
    $invoice_params['inv_search_leg'] = '%' . $inv_search . '%';
    $invoice_params['inv_search_leg2'] = '%' . $inv_search . '%';
    $invoice_params['inv_search_leg3'] = '%' . $inv_search . '%';
}

$invoice_category_condition = "";
if ($inv_category !== '' && $inv_category !== 'material') {
    $invoice_category_condition = " AND da.category = :inv_category";
    $invoice_params['inv_category'] = $inv_category;
}

$invoice_vendor_new = "";
$invoice_vendor_leg = "";
if ($inv_vendor !== '') {
    $invoice_vendor_new = " AND (d.vendor_id = :inv_vendor_new OR d.source_name = :inv_vendor_name_new)";
    $invoice_vendor_leg = " AND (d.vendor_id = :inv_vendor_leg OR d.source_name = :inv_vendor_name_leg)";
    $invoice_params['inv_vendor_new'] = $inv_vendor;
    $invoice_params['inv_vendor_name_new'] = $inv_vendor;
    $invoice_params['inv_vendor_leg'] = $inv_vendor;
    $invoice_params['inv_vendor_name_leg'] = $inv_vendor;
}

// Duplikujemy named node placeholders dla drugiego UNION (legacy)
// Potrzebujemy osobne parametry dla każdego UNION member
$node_named_placeholders_leg = [];
foreach ($all_node_ids as $idx => $nid) {
    $key = 'lnid' . $idx;
    $node_named_placeholders_leg[] = ':' . $key;
    $invoice_params[$key] = $nid;
}
$node_in_named_leg = implode(',', $node_named_placeholders_leg);

$node_named_placeholders_fci = [];
foreach ($all_node_ids as $idx => $nid) {
    $key = 'fnid' . $idx;
    $node_named_placeholders_fci[] = ':' . $key;
    $invoice_params[$key] = $nid;
}
$node_in_named_fci = implode(',', $node_named_placeholders_fci);

$invoices_sql = "
    SELECT 
        COUNT(DISTINCT document_id) as invoices_count,
        COALESCE(SUM(amount), 0) as total_amount
    FROM (
        -- Nowy system: document_item_allocations
        SELECT 
            d.id as document_id,
            dia.amount as amount
        FROM document_item_allocations dia
        JOIN document_items di ON dia.document_item_id = di.id
        JOIN documents d ON di.document_id = d.id
        LEFT JOIN investors i ON i.id = d.vendor_id
        WHERE dia.cost_node_id IN ({$node_in_named})
          AND d.status = 'approved'
          AND d.type = 'invoice_cost'
          {$invoice_date_condition_new}
          {$invoice_search_new}
          {$invoice_vendor_new}
        
        UNION ALL
        
        -- Stary system: document_allocations (legacy)
        SELECT 
            d.id as document_id,
            da.amount_net as amount
        FROM document_allocations da
        JOIN documents d ON d.id = da.document_id
        LEFT JOIN investors i ON i.id = d.vendor_id
        WHERE da.cost_node_id IN ({$node_in_named_leg})
          AND d.status = 'approved'
          AND d.type = 'invoice_cost'
          AND da.is_legacy = 1
          {$invoice_date_condition_leg}
          {$invoice_search_leg}
          {$invoice_vendor_leg}
          {$invoice_category_condition}

        UNION ALL

        -- Fakturownia: fakturownia_cost_allocations
        SELECT 
            CONCAT('fci_', fci.id) as document_id,
            fca.amount_net as amount
        FROM fakturownia_cost_allocations fca
        JOIN fakturownia_cost_invoices fci ON fca.cost_invoice_id = fci.id
        WHERE fca.cost_node_id IN ({$node_in_named_fci})
          {$invoice_date_condition_fci}
    ) AS combined_allocations
";
$stmt = $pdo->prepare($invoices_sql);
$stmt->execute($invoice_params);
$invoices_summary = $stmt->fetch();

// Invoices list (OBA systemy)
$invoices_list_sql = "
    SELECT 
        document_id,
        number,
        issue_date,
        vendor_id,
        source_name,
        vendor_name,
        SUM(amount) as amount_net,
        GROUP_CONCAT(DISTINCT description SEPARATOR '; ') as allocation_desc,
        MAX(category) as category,
        MAX(cost_node_name) as cost_node_name
    FROM (
        -- Nowy system: document_item_allocations
        SELECT 
            d.id as document_id,
            d.number,
            d.issue_date,
            d.vendor_id,
            d.source_name,
            COALESCE(i.name, d.source_name) as vendor_name,
            dia.amount as amount,
            CONCAT(di.item_name, COALESCE(CONCAT(' - ', dia.notes), '')) as description,
            'material' as category,
            pcn.name as cost_node_name
        FROM document_item_allocations dia
        JOIN document_items di ON dia.document_item_id = di.id
        JOIN documents d ON di.document_id = d.id
        LEFT JOIN investors i ON i.id = d.vendor_id
        LEFT JOIN project_cost_nodes pcn ON pcn.id = dia.cost_node_id
        WHERE dia.cost_node_id IN ({$node_in_named})
          AND d.status = 'approved'
          AND d.type = 'invoice_cost'
          {$invoice_date_condition_new}
          {$invoice_search_new}
          {$invoice_vendor_new}
        
        UNION ALL
        
        -- Stary system: document_allocations (legacy)
        SELECT 
            d.id as document_id,
            d.number,
            d.issue_date,
            d.vendor_id,
            d.source_name,
            COALESCE(i.name, d.source_name) as vendor_name,
            da.amount_net as amount,
            da.description as description,
            da.category,
            pcn.name as cost_node_name
        FROM document_allocations da
        JOIN documents d ON d.id = da.document_id
        LEFT JOIN investors i ON i.id = d.vendor_id
        LEFT JOIN project_cost_nodes pcn ON pcn.id = da.cost_node_id
        WHERE da.cost_node_id IN ({$node_in_named_leg})
          AND d.status = 'approved'
          AND d.type = 'invoice_cost'
          AND da.is_legacy = 1
          {$invoice_date_condition_leg}
          {$invoice_search_leg}
          {$invoice_vendor_leg}
          {$invoice_category_condition}

        UNION ALL

        -- Fakturownia: fakturownia_cost_allocations
        SELECT 
            CONCAT('fci_', fci.id) as document_id,
            fci.invoice_number as number,
            fci.issue_date,
            NULL as vendor_id,
            fci.supplier_name as source_name,
            fci.supplier_name as vendor_name,
            fca.amount_net as amount,
            fca.description as description,
            'material' as category,
            pcn.name as cost_node_name
        FROM fakturownia_cost_allocations fca
        JOIN fakturownia_cost_invoices fci ON fca.cost_invoice_id = fci.id
        LEFT JOIN project_cost_nodes pcn ON pcn.id = fca.cost_node_id
        WHERE fca.cost_node_id IN ({$node_in_named_fci})
          {$invoice_date_condition_fci}
    ) AS combined_invoices
    GROUP BY document_id, number, issue_date, vendor_id, source_name, vendor_name
    ORDER BY issue_date DESC
";
$stmt = $pdo->prepare($invoices_list_sql);
$stmt->execute($invoice_params);
$invoices_list = $stmt->fetchAll();

// Categories and vendors for filters (OBA systemy, wszystkie node'y)
$inv_categories_sql = "
    SELECT DISTINCT category FROM (
        SELECT 'material' as category
        FROM document_item_allocations dia
        JOIN document_items di ON dia.document_item_id = di.id
        JOIN documents d ON di.document_id = d.id
        WHERE dia.cost_node_id IN ({$node_in_placeholders}) AND d.status = 'approved'
        
        UNION
        
        SELECT da.category
        FROM document_allocations da 
        JOIN documents d ON d.id = da.document_id 
        WHERE da.cost_node_id IN ({$node_in_placeholders}) AND d.status = 'approved' AND da.is_legacy = 1 AND da.category IS NOT NULL

        UNION

        SELECT 'material' as category
        FROM fakturownia_cost_allocations fca
        WHERE fca.cost_node_id IN ({$node_in_placeholders})
    ) AS categories
    WHERE category IS NOT NULL
    ORDER BY category
";
$stmt = $pdo->prepare($inv_categories_sql);
$stmt->execute(array_merge($all_node_ids, $all_node_ids, $all_node_ids));
$inv_categories_list = $stmt->fetchAll(PDO::FETCH_COLUMN);

$inv_vendors_sql = "
    SELECT DISTINCT vendor_name, vendor_key FROM (
        SELECT 
            COALESCE(i.name, d.source_name) as vendor_name, 
            COALESCE(d.vendor_id, d.source_name) as vendor_key
        FROM document_item_allocations dia
        JOIN document_items di ON dia.document_item_id = di.id
        JOIN documents d ON di.document_id = d.id
        LEFT JOIN investors i ON i.id = d.vendor_id
        WHERE dia.cost_node_id IN ({$node_in_placeholders}) AND d.status = 'approved'
        
        UNION
        
        SELECT 
            COALESCE(i.name, d.source_name) as vendor_name, 
            COALESCE(d.vendor_id, d.source_name) as vendor_key
        FROM document_allocations da 
        JOIN documents d ON d.id = da.document_id 
        LEFT JOIN investors i ON i.id = d.vendor_id
        WHERE da.cost_node_id IN ({$node_in_placeholders}) AND d.status = 'approved' AND da.is_legacy = 1

        UNION

        SELECT 
            fci.supplier_name as vendor_name,
            fci.supplier_name as vendor_key
        FROM fakturownia_cost_allocations fca
        JOIN fakturownia_cost_invoices fci ON fca.cost_invoice_id = fci.id
        WHERE fca.cost_node_id IN ({$node_in_placeholders})
    ) AS vendors
    ORDER BY vendor_name
";
$stmt = $pdo->prepare($inv_vendors_sql);
$stmt->execute(array_merge($all_node_ids, $all_node_ids, $all_node_ids));
$inv_vendors_list = $stmt->fetchAll();

// =====================================================
// FINANCIAL CALCULATIONS
// =====================================================
$labor_cost = (float)($labor_summary['total_cost'] ?? 0);
$expenses_cash = (float)($expenses_summary['total_amount'] ?? 0);
$invoices_materials = (float)($invoices_summary['total_amount'] ?? 0);

$total_costs = $labor_cost + $expenses_cash + $invoices_materials;

// Status labels
$status_labels = [
    'planned' => 'Planowany',
    'active' => 'Aktywny',
    'finished' => 'Zakończony'
];

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo e($node['name']); ?></title>
    <link rel="stylesheet" href="/projekty/assets/projekty.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        /* UNIFIED FILTER INPUTS */
        .filter-select,
        .filter-input,
        .filter-bar select,
        .filter-bar input[type="text"],
        .filter-bar input[type="date"],
        .filter-form select,
        .filter-form input[type="text"],
        .filter-form input[type="date"] {
            background: #fff !important;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            color: #1f2937;
            min-width: 180px;
            height: 42px;
            box-sizing: border-box;
        }
        
        .filter-select:focus,
        .filter-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .project-meta { margin-top: 0; }
        
        /* KPI Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .kpi-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            text-align: center;
        }
        
        .kpi-label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .kpi-value {
            font-size: 28px;
            font-weight: 700;
        }
        
        .kpi-card.costs { border-left: 4px solid #dc2626; }
        .kpi-card.costs .kpi-value { color: #dc2626; }
        
        .kpi-card.labor { border-left: 4px solid #6b7280; }
        .kpi-card.labor .kpi-value { color: #6b7280; }
        
        .kpi-card.invoices { border-left: 4px solid #ea580c; }
        .kpi-card.invoices .kpi-value { color: #ea580c; }
        
        /* Two columns for chart */
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            align-items: stretch;
            margin-bottom: 30px;
        }
        
        @media (max-width: 1024px) {
            .two-columns { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: 1fr; }
        }
        
        .chart-wrapper {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1f2937;
        }
        
        .chart-container {
            position: relative;
            height: 280px;
            flex: 1;
        }
        
        /* Quick preset buttons */
        .btn-outline {
            background: white;
            color: #1f2937;
            border: 2px solid #e5e7eb;
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
        }
        
        .btn-outline:hover {
            background: #1f2937;
            color: white;
            border-color: #1f2937;
        }
        
        .btn-outline.active {
            background: #1f2937;
            color: white;
            border-color: #1f2937;
        }
        
        .full-width-section { width: 100%; }
        
        /* Badge dla typów wydatków */
        .badge-info { 
            background: #cfe2ff; 
            color: #084298; 
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-warning { 
            background: #fff3cd; 
            color: #664d03; 
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-secondary { 
            background: #e2e3e5; 
            color: #41464b; 
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-success { 
            background: #d1fae5; 
            color: #065f46; 
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
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
                    <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>"><?php echo e($node['project_name']); ?></a> /
                    <?php echo e($node['name']); ?>
                </div>
                <h1><?php echo e($node['name']); ?></h1>
                <p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px;">
                    <span class="badge badge-stage" style="background:rgba(255,255,255,0.2);color:#e2e8f0;border-radius:12px;padding:3px 10px;font-size:11px;">Etap</span>
                    <?php if (count($all_node_ids) > 1): ?>
                        <span style="color:#94a3b8;font-size:13px;">+ <?php echo count($all_node_ids) - 1; ?> podetap<?php echo (count($all_node_ids) - 1) > 1 ? 'ów' : ''; ?></span>
                    <?php endif; ?>
                    <?php if (!empty($cost_stages)): ?>
                    <select onchange="if(this.value) window.location.href=this.value;" style="background:rgba(255,255,255,0.15);color:#e2e8f0;border:1px solid rgba(255,255,255,0.25);border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;">
                        <option value="">Zmień etap...</option>
                        <?php foreach ($cost_stages as $stage): ?>
                            <option value="<?php echo url('projekty.etap.view', ['id' => $stage['id']]); ?>" <?php echo $stage['id'] == $node_id ? 'selected' : ''; ?>>
                                <?php echo $stage['parent_id'] ? '└ ' : ''; ?><?php echo e($stage['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </p>
            </div>
            <div class="hero-actions">
                <?php if ($isAdminUser): ?>
                    <a href="<?php echo url('projekty.etapy.create', ['project_id' => $project_id]); ?>" class="btn-hero-secondary">+ Dodaj Etap</a>
                    <a href="<?php echo url('projekty.etapy.edit', ['id' => $node_id]); ?>" class="btn-hero-primary">Edytuj Etap</a>
                <?php endif; ?>
                <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>" class="btn-hero-secondary">← Projekt</a>
            </div>
        </div>
        
        <!-- KPI Badges -->
        <div class="kpi-grid">
            <div class="kpi-card costs">
                <div class="kpi-label">Koszty Etapu</div>
                <div class="kpi-value"><?php echo formatMoney($total_costs); ?></div>
            </div>
            <div class="kpi-card labor">
                <div class="kpi-label">Robocizna</div>
                <div class="kpi-value"><?php echo formatMoney($labor_cost); ?></div>
            </div>
            <div class="kpi-card invoices">
                <div class="kpi-label">Faktury/Materiały</div>
                <div class="kpi-value"><?php echo formatMoney($invoices_materials); ?></div>
            </div>
        </div>
        
        <!-- Two Columns: Chart + Cost Breakdown -->
        <div class="two-columns">
            <!-- Chart Section -->
            <div class="chart-wrapper">
                <h3 class="chart-title">Struktura Kosztów Etapu</h3>
                <?php if ($total_costs > 0): ?>
                    <div class="chart-container">
                        <canvas id="costPieChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="no-data">Brak kosztów do wyświetlenia na wykresie.</div>
                <?php endif; ?>
            </div>
            
            <!-- Cost Breakdown Card -->
            <div class="card">
                <div class="card-header">
                    <h3>Podział Kosztów</h3>
                </div>
                <div class="card-body">
                    <table>
                        <tr>
                            <td>Robocizna</td>
                            <td class="text-right font-bold"><?php echo formatMoney($labor_cost); ?></td>
                            <td class="text-right text-muted">
                                <?php echo ($total_costs > 0) ? number_format($labor_cost / $total_costs * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                        <tr>
                            <td>Wydatki gotówkowe</td>
                            <td class="text-right font-bold"><?php echo formatMoney($expenses_cash); ?></td>
                            <td class="text-right text-muted">
                                <?php echo ($total_costs > 0) ? number_format($expenses_cash / $total_costs * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                        <tr>
                            <td>Faktury / Materiały</td>
                            <td class="text-right font-bold"><?php echo formatMoney($invoices_materials); ?></td>
                            <td class="text-right text-muted">
                                <?php echo ($total_costs > 0) ? number_format($invoices_materials / $total_costs * 100, 1) : 0; ?>%
                            </td>
                        </tr>
                        <tr style="background: #f8f9fa; font-weight: bold;">
                            <td>RAZEM</td>
                            <td class="text-right"><?php echo formatMoney($total_costs); ?></td>
                            <td class="text-right text-muted">100%</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- ============================================= -->
        <!-- SEKCJA 1: FAKTURY / MATERIAŁY -->
        <!-- ============================================= -->
        <div class="card full-width-section" style="margin-bottom: 30px;" id="faktury">
            <div class="card-header">
                <h3>Faktury / Materiały (<?php echo count($invoices_list); ?> pozycji)</h3>
            </div>
            <div class="card-body">
                <!-- Quick presets -->
                <div style="display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap;">
                    <span style="color: #6b7280; font-size: 13px; padding: 6px 0;">Okres:</span>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id, 'range' => 'today']); ?>#faktury" 
                       class="btn btn-outline btn-sm <?php echo $date_range === 'today' ? 'active' : ''; ?>">Dzień</a>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id, 'range' => 'week']); ?>#faktury" 
                       class="btn btn-outline btn-sm <?php echo $date_range === 'week' ? 'active' : ''; ?>">Tydzień</a>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id, 'range' => 'month']); ?>#faktury" 
                       class="btn btn-outline btn-sm <?php echo $date_range === 'month' ? 'active' : ''; ?>">Miesiąc</a>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id]); ?>#faktury" 
                       class="btn btn-outline btn-sm <?php echo !$has_date_filter ? 'active' : ''; ?>">Wszystko</a>
                </div>
                
                <!-- Filtry faktur -->
                <form method="GET" action="" class="filter-form" style="background: #f9fafb; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                    <input type="hidden" name="id" value="<?php echo $node_id; ?>">
                    
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                        <div class="filter-group" style="flex: 2; min-width: 200px;">
                            <label>Szukaj (numer, opis, kontrahent)</label>
                            <input type="text" name="inv_search" class="filter-input" value="<?php echo e($inv_search); ?>" placeholder="Wpisz szukaną frazę...">
                        </div>
                        <div class="filter-group" style="flex: 1; min-width: 150px;">
                            <label>Kategoria</label>
                            <select name="inv_category" class="filter-select">
                                <option value="">Wszystkie</option>
                                <?php foreach ($inv_categories_list as $cat): ?>
                                    <option value="<?php echo e($cat); ?>" <?php echo $inv_category === $cat ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group" style="flex: 1; min-width: 150px;">
                            <label>Kontrahent</label>
                            <select name="inv_vendor" class="filter-select">
                                <option value="">Wszyscy</option>
                                <?php foreach ($inv_vendors_list as $v): ?>
                                    <option value="<?php echo e($v['vendor_key']); ?>" <?php echo $inv_vendor == $v['vendor_key'] ? 'selected' : ''; ?>><?php echo e($v['vendor_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-top: 10px;">
                        <div class="filter-group" style="min-width: 150px;">
                            <label>Data od</label>
                            <input type="date" name="date_from" class="filter-input" value="<?php echo e($date_from); ?>">
                        </div>
                        <div class="filter-group" style="min-width: 150px;">
                            <label>Data do</label>
                            <input type="date" name="date_to" class="filter-input" value="<?php echo e($date_to); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Filtruj</button>
                        <?php if ($inv_search || $inv_category || $inv_vendor || $date_from || $date_to): ?>
                            <a href="<?php echo url('projekty.etap.view', ['id' => $node_id]); ?>#faktury" class="btn btn-secondary btn-sm">Wyczyść</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php $has_children = count($all_node_ids) > 1; ?>
            <?php if (!empty($invoices_list)): ?>
            <div class="card-body" style="padding: 0;">
                <div style="max-height: 400px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Numer</th>
                                <th>Kontrahent</th>
                                <?php if ($has_children): ?><th>Etap/Podetap</th><?php endif; ?>
                                <th>Kategoria</th>
                                <th style="text-align: right;">Kwota netto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices_list as $inv): ?>
                                <tr>
                                    <td><?php echo formatDate($inv['issue_date']); ?></td>
                                    <td><strong><?php echo e($inv['number']); ?></strong></td>
                                    <td><?php echo e($inv['vendor_name']); ?></td>
                                    <?php if ($has_children): ?>
                                        <td><span class="badge-secondary"><?php echo e($inv['cost_node_name'] ?? $node['name']); ?></span></td>
                                    <?php endif; ?>
                                    <td><?php echo e($inv['category'] ?? '-'); ?></td>
                                    <td style="text-align: right; color: #ea580c; font-weight: 600;"><?php echo formatMoney($inv['amount_net']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background: #f9fafb; font-weight: bold;">
                                <td colspan="<?php echo $has_children ? 5 : 4; ?>">RAZEM</td>
                                <td style="text-align: right; color: #ea580c;"><?php echo formatMoney($invoices_materials); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body">
                <div class="no-data">Brak faktur dla tego etapu<?php echo $has_children ? ' i jego podetapów' : ''; ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ============================================= -->
        <!-- SEKCJA 2: ROBOCIZNA -->
        <!-- ============================================= -->
        <div class="card full-width-section" style="margin-bottom: 30px;" id="robocizna">
            <div class="card-header">
                <h3>Robocizna - Szczegóły (<?php echo $labor_summary['logs_count'] ?? 0; ?> wpisów)</h3>
            </div>
            <div class="card-body">
                <!-- Quick presets -->
                <div style="display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap;">
                    <span style="color: #6b7280; font-size: 13px; padding: 6px 0;">Okres:</span>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id, 'range' => 'today']); ?>#robocizna" 
                       class="btn btn-outline btn-sm <?php echo $date_range === 'today' ? 'active' : ''; ?>">Dzień</a>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id, 'range' => 'week']); ?>#robocizna" 
                       class="btn btn-outline btn-sm <?php echo $date_range === 'week' ? 'active' : ''; ?>">Tydzień</a>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id, 'range' => 'month']); ?>#robocizna" 
                       class="btn btn-outline btn-sm <?php echo $date_range === 'month' ? 'active' : ''; ?>">Miesiąc</a>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id]); ?>#robocizna" 
                       class="btn btn-outline btn-sm <?php echo !$has_date_filter ? 'active' : ''; ?>">Wszystko</a>
                </div>
                
                <!-- Filtry robocizny -->
                <form method="GET" action="" class="filter-form" style="background: #f9fafb; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                    <input type="hidden" name="id" value="<?php echo $node_id; ?>">
                    
                    <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                        <div class="filter-group" style="min-width: 180px;">
                            <label>Pracownik</label>
                            <select name="worker_id" class="filter-select">
                                <option value="">Wszyscy pracownicy</option>
                                <?php foreach ($workers_on_project as $w): ?>
                                    <option value="<?php echo $w['id']; ?>" <?php echo $worker_filter == $w['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group" style="min-width: 150px;">
                            <label>Data od</label>
                            <input type="date" name="date_from" class="filter-input" value="<?php echo e($date_from); ?>">
                        </div>
                        <div class="filter-group" style="min-width: 150px;">
                            <label>Data do</label>
                            <input type="date" name="date_to" class="filter-input" value="<?php echo e($date_to); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Filtruj</button>
                        <?php if ($worker_filter || $date_from || $date_to): ?>
                            <a href="<?php echo url('projekty.etap.view', ['id' => $node_id]); ?>#robocizna" class="btn btn-secondary btn-sm">Wyczyść</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (!empty($labor_by_worker)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Pracownik</th>
                                <th class="text-center">Godziny</th>
                                <th class="text-center">Sob (h)</th>
                                <th class="text-center">Niedz (h)</th>
                                <th class="text-center">Nocki (h)</th>
                                <th class="text-center">Nadgodziny</th>
                                <th class="text-right">Koszt</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($labor_by_worker as $worker): ?>
                                <tr>
                                    <td><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?></td>
                                    <td class="text-center"><?php echo number_format($worker['total_hours'], 1); ?> h</td>
                                    <td class="text-center">
                                        <?php if ($worker['sat_hours'] > 0): ?>
                                            <span class="hours-sat"><?php echo number_format($worker['sat_hours'], 1); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($worker['sun_hours'] > 0): ?>
                                            <span class="hours-sun"><?php echo number_format($worker['sun_hours'], 1); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($worker['night_hours'] > 0): ?>
                                            <span class="hours-night"><?php echo number_format($worker['night_hours'], 1); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo number_format($worker['total_overtime'], 1); ?> h</td>
                                    <td class="text-right font-bold"><?php echo formatMoney($worker['total_cost']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background: #f8f9fa; font-weight: bold;">
                                <td>RAZEM</td>
                                <td class="text-center"><?php echo number_format($labor_summary['total_hours'] ?? 0, 1); ?> h</td>
                                <td class="text-center"><?php echo ($labor_summary['sat_hours'] ?? 0) > 0 ? number_format($labor_summary['sat_hours'], 1) : '-'; ?></td>
                                <td class="text-center"><?php echo ($labor_summary['sun_hours'] ?? 0) > 0 ? number_format($labor_summary['sun_hours'], 1) : '-'; ?></td>
                                <td class="text-center"><?php echo ($labor_summary['night_hours'] ?? 0) > 0 ? number_format($labor_summary['night_hours'], 1) : '-'; ?></td>
                                <td class="text-center"><?php echo number_format($labor_summary['total_overtime'] ?? 0, 1); ?> h</td>
                                <td class="text-right"><?php echo formatMoney($labor_summary['total_cost'] ?? 0); ?></td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">Brak wpisów robocizny dla tego etapu</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ============================================= -->
        <!-- SEKCJA 3: WYDATKI PROJEKTU -->
        <!-- ============================================= -->
        <div class="card full-width-section" style="margin-bottom: 30px;" id="wydatki">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <h3>Wydatki projektu (<?php echo $expenses_summary['expenses_count'] ?? 0; ?> wydatków)</h3>
                <?php if ($isAdminUser): ?>
                    <a href="<?php echo url('finanse.wydatki.create', [
                        'project_id' => $project_id,
                        'cost_node_id' => $node_id,
                        'return_url' => url('projekty.etap.view', ['id' => $node_id, 'success' => 'expense_added']) . '#wydatki'
                    ]); ?>" class="btn btn-success btn-sm">+ Dodaj wydatek</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- Quick presets -->
                <div style="display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap;">
                    <span style="color: #6b7280; font-size: 13px; padding: 6px 0;">Okres:</span>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id, 'range' => 'today']); ?>#wydatki" 
                       class="btn btn-outline btn-sm <?php echo $date_range === 'today' ? 'active' : ''; ?>">Dzień</a>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id, 'range' => 'week']); ?>#wydatki" 
                       class="btn btn-outline btn-sm <?php echo $date_range === 'week' ? 'active' : ''; ?>">Tydzień</a>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id, 'range' => 'month']); ?>#wydatki" 
                       class="btn btn-outline btn-sm <?php echo $date_range === 'month' ? 'active' : ''; ?>">Miesiąc</a>
                    <a href="<?php echo url('projekty.etap.view', ['id' => $node_id]); ?>#wydatki" 
                       class="btn btn-outline btn-sm <?php echo !$has_date_filter ? 'active' : ''; ?>">Wszystko</a>
                </div>
                
                <!-- Filtry wydatków -->
                <form method="GET" action="" class="filter-form" style="background: #f9fafb; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                    <input type="hidden" name="id" value="<?php echo $node_id; ?>">
                    
                    <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                        <div class="filter-group" style="min-width: 180px;">
                            <label>Pracownik</label>
                            <select name="expense_worker_id" class="filter-select">
                                <option value="">Wszyscy pracownicy</option>
                                <?php foreach ($workers_with_expenses as $w): ?>
                                    <option value="<?php echo $w['id']; ?>" <?php echo $expense_worker_filter == $w['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group" style="min-width: 150px;">
                            <label>Data od</label>
                            <input type="date" name="date_from" class="filter-input" value="<?php echo e($date_from); ?>">
                        </div>
                        <div class="filter-group" style="min-width: 150px;">
                            <label>Data do</label>
                            <input type="date" name="date_to" class="filter-input" value="<?php echo e($date_to); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Filtruj</button>
                        <?php if ($expense_worker_filter || $date_from || $date_to): ?>
                            <a href="<?php echo url('projekty.etap.view', ['id' => $node_id]); ?>#wydatki" class="btn btn-secondary btn-sm">Wyczyść</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <?php if (!empty($expenses_list)): ?>
            <div class="card-body" style="padding: 0;">
                <div style="max-height: 400px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Opis</th>
                                <th>Pracownik</th>
                                <?php if ($has_children): ?><th>Etap/Podetap</th><?php endif; ?>
                                <th>Typ</th>
                                <th style="text-align: right;">Kwota</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expenses_list as $exp): 
                                // Określ typ wydatku
                                $expenseTypeLabel = '';
                                $expenseTypeBadge = '';
                                switch ($exp['expense_type'] ?? 'cash_other') {
                                    case 'company':
                                        $expenseTypeLabel = 'Firma';
                                        $expenseTypeBadge = 'badge-primary';
                                        break;
                                    case 'cash_purchase':
                                        $expenseTypeLabel = 'Zakup drobny';
                                        $expenseTypeBadge = 'badge-info';
                                        break;
                                    case 'cash_other':
                                    default:
                                        $expenseTypeLabel = 'Inne';
                                        $expenseTypeBadge = 'badge-secondary';
                                        break;
                                }
                            ?>
                                <tr>
                                    <td><?php echo formatDate($exp['date']); ?></td>
                                    <td>
                                        <?php 
                                        $etapDesc = trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', $exp['description']));
                                        echo e($etapDesc);
                                        ?>
                                        <?php if (!empty($exp['paid_by_employee']) || strpos($exp['description'], '[PAID_BY_EMPLOYEE]') !== false): ?>
                                            <br><span class="badge" style="background: #17a2b8; color: white; font-size: 10px; margin-top: 4px;">ROZLICZENIE PRACOWNIKA</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo e(trim($exp['first_name'] . ' ' . $exp['last_name'])); ?></td>
                                    <?php if ($has_children): ?>
                                        <td><span class="badge-secondary"><?php echo e($exp['cost_node_name'] ?? $node['name']); ?></span></td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="badge <?php echo $expenseTypeBadge; ?>"><?php echo $expenseTypeLabel; ?></span>
                                    </td>
                                    <td style="text-align: right; color: #dc2626; font-weight: 600;">-<?php echo formatMoney($exp['amount']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background: #f9fafb; font-weight: bold;">
                                <td colspan="<?php echo $has_children ? 5 : 4; ?>">RAZEM</td>
                                <td style="text-align: right; color: #dc2626;"><?php echo formatMoney($expenses_cash); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="card-body">
                <div class="no-data">Brak wydatków dla tego etapu<?php echo $has_children ? ' i jego podetapów' : ''; ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        <?php if ($total_costs > 0): ?>
        // Chart.js Pie Chart - Cost Structure
        const ctx = document.getElementById('costPieChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Robocizna', 'Wydatki gotówkowe', 'Faktury/Materiały'],
                datasets: [{
                    data: [
                        <?php echo $labor_cost; ?>,
                        <?php echo $expenses_cash; ?>,
                        <?php echo $invoices_materials; ?>
                    ],
                    backgroundColor: [
                        '#6b7280',
                        '#16a34a',
                        '#ea580c'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true,
                            font: { size: 13 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return context.label + ': ' + value.toLocaleString('pl-PL', {style: 'currency', currency: 'PLN'}) + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
