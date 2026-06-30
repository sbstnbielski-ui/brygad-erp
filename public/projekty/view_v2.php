<?php

/**
 * BRYGAD ERP v3.1 - Widok Projektu v2 (Command Center)
 * 
 * ZMIANY vs view.php:
 * - Nowe KPI: Wartość umowy, Zafakturowano, Do zafakturowania, Koszty, Zysk rzeczywisty, Marża
 * - Sekcja Faktur sprzedażowych (z invoice_sale_allocations)
 * - Sekcja "Faktury kosztowe" zamiast "Faktury i inne wydatki" (bez "Dodaj fakturę")
 * - Sekcja "Wydatki drobne" zamiast "Wydatki Pracowników"
 * - Wizualne oddzielenie modułów (kolorowe border-top)
 * - Kolejność: Finansowanie → Faktury sprzedażowe → Faktury kosztowe → Wydatki drobne → Robocizna
 * 
 * GET Parameters: id, range, date_from, date_to, worker_id, expense_worker_id, inv_*
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();

$project_id = $_GET['id'] ?? null;
$isAdminUser = isAdmin();

if (!$project_id) {
    header("Location: " . url('projekty'));
    exit;
}

// Pobierz dane projektu z danymi inwestora
try {
    $stmt = $pdo->prepare("
        SELECT p.*, i.name as investor_name, i.nip as investor_nip, 
               i.address as investor_address, i.email as investor_email, 
               i.phone as investor_phone, i.contact_person as investor_contact_person
        FROM projects p
        LEFT JOIN investors i ON i.id = p.investor_id
        WHERE p.id = :id
    ");
    $stmt->execute(['id' => $project_id]);
    $project = $stmt->fetch();
} catch (Exception $e) {
    die("Błąd pobierania projektu");
}

if (!$project) {
    header("Location: " . url('projekty'));
    exit;
}

// =====================================================
// ETAPY KOSZTÓW
// =====================================================
try {
    $stmt = $pdo->prepare("SELECT id, name, parent_id FROM project_cost_nodes WHERE project_id = ? AND is_active = 1 ORDER BY sort_order ASC");
    $stmt->execute([$project_id]);
    $cost_stages = $stmt->fetchAll();
} catch (Exception $e) {
    $cost_stages = [];
}

// =====================================================
// DATE FILTER LOGIC
// =====================================================
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;
$date_range = $_GET['range'] ?? '';

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

$date_condition_sql = "";
$date_params = ['project_id' => $project_id];

if ($date_from) {
    $date_condition_sql .= " AND wl.date >= :date_from";
    $date_params['date_from'] = $date_from;
}
if ($date_to) {
    $date_condition_sql .= " AND wl.date <= :date_to";
    $date_params['date_to'] = $date_to;
}

$expense_date_condition = "";
$expense_worker_condition = "";
$expense_params = ['project_id' => $project_id];

if ($date_from) {
    $expense_date_condition .= " AND we.date >= :date_from";
    $expense_params['date_from'] = $date_from;
}
if ($date_to) {
    $expense_date_condition .= " AND we.date <= :date_to";
    $expense_params['date_to'] = $date_to;
}

$expense_worker_filter = $_GET['expense_worker_id'] ?? '';
if ($expense_worker_filter !== '') {
    $expense_worker_condition = " AND we.worker_id = :expense_worker_id";
    $expense_params['expense_worker_id'] = $expense_worker_filter;
}

// =====================================================
// REVENUE - Wartość umowy (contracts/annexes - always global)
// =====================================================
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_net), 0) as total_revenue
        FROM project_revenues
        WHERE project_id = :project_id
    ");
    $stmt->execute(['project_id' => $project_id]);
    $total_revenue = (float)($stmt->fetch()['total_revenue'] ?? 0);
} catch (Exception $e) {
    $total_revenue = 0;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM project_revenues WHERE project_id = :project_id ORDER BY signed_date DESC");
    $stmt->execute(['project_id' => $project_id]);
    $revenues = $stmt->fetchAll();
} catch (Exception $e) {
    $revenues = [];
}

// =====================================================
// FAKTURY SPRZEDAŻOWE - ile zafakturowaliśmy na tym projekcie
// =====================================================
try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(isa.amount_net), 0) as total_invoiced
        FROM invoice_sale_allocations isa
        JOIN invoices_sale inv ON inv.id = isa.invoice_id
        WHERE isa.project_id = :project_id
          AND inv.status IN ('issued', 'paid', 'partially_paid')
    ");
    $stmt->execute(['project_id' => $project_id]);
    $total_invoiced = (float)($stmt->fetch()['total_invoiced'] ?? 0);
} catch (Exception $e) {
    $total_invoiced = 0;
}

$to_invoice = $total_revenue - $total_invoiced;

try {
    $stmt = $pdo->prepare("
        SELECT 
            inv.id,
            inv.invoice_number,
            inv.issue_date,
            inv.status,
            inv.amount_net as invoice_total_net,
            inv.amount_gross as invoice_total_gross,
            i.name as client_name,
            SUM(isa.amount_net) as project_amount_net
        FROM invoice_sale_allocations isa
        JOIN invoices_sale inv ON inv.id = isa.invoice_id
        LEFT JOIN investors i ON i.id = inv.client_id
        WHERE isa.project_id = :project_id
          AND inv.status IN ('issued', 'paid', 'partially_paid')
        GROUP BY inv.id
        ORDER BY inv.issue_date DESC
    ");
    $stmt->execute(['project_id' => $project_id]);
    $sales_invoices_list = $stmt->fetchAll();
} catch (Exception $e) {
    $sales_invoices_list = [];
}

// =====================================================
// WPŁYNĘŁO NA KONTO — pro-rata alokacja na projekt
// Wpłata jest na całą FV; jeśli FV ma pozycje na wielu
// projektach, dzielimy proporcjonalnie do netto pozycji.
// =====================================================
$total_received = 0;
try {
    $stmt = $pdo->prepare("
        SELECT
            inv.id as invoice_id,
            inv.amount_net as invoice_net,
            isa.amount_net as project_net,
            COALESCE(pay.paid_net, 0) as paid_net
        FROM invoice_sale_allocations isa
        JOIN invoices_sale inv ON inv.id = isa.invoice_id
        LEFT JOIN (
            SELECT invoice_id, SUM(amount_net) as paid_net
            FROM invoice_sale_payments
            GROUP BY invoice_id
        ) pay ON pay.invoice_id = inv.id
        WHERE isa.project_id = :pid
          AND inv.status IN ('issued', 'paid', 'partially_paid')
    ");
    $stmt->execute(['pid' => $project_id]);
    foreach ($stmt->fetchAll() as $row) {
        $invNet = (float)$row['invoice_net'];
        $projNet = (float)$row['project_net'];
        $paidNet = (float)$row['paid_net'];
        if ($invNet > 0 && $paidNet > 0) {
            $ratio = $projNet / $invNet;
            $total_received += round($paidNet * $ratio, 2);
        }
    }
} catch (Exception $e) {
    $total_received = 0;
}

$to_pay = $total_invoiced - $total_received;

// =====================================================
// PZF — przychody pozafakturowe (sales_noninvoice_allocations)
// Guardrail: NIE modyfikuje project_revenues, invoice_sale_allocations
// =====================================================
$total_pzf_net     = 0;
$total_pzf_paid    = 0;
$total_pzf_unpaid  = 0;
$pzf_entries_list  = [];
try {
    $pzfSumStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(a.amount_net), 0) AS total_pzf_net,
            COALESCE(SUM(CASE WHEN e.payment_status = 'paid'   THEN a.amount_net ELSE 0 END), 0) AS paid_net,
            COALESCE(SUM(CASE WHEN e.payment_status = 'unpaid' THEN a.amount_net ELSE 0 END), 0) AS unpaid_net
        FROM sales_noninvoice_allocations a
        JOIN sales_noninvoice_entries e ON e.id = a.entry_id
        WHERE a.project_id = :pid
    ");
    $pzfSumStmt->execute(['pid' => $project_id]);
    $pzfSum         = $pzfSumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $total_pzf_net  = (float)($pzfSum['total_pzf_net'] ?? 0);
    $total_pzf_paid = (float)($pzfSum['paid_net']      ?? 0);
    $total_pzf_unpaid = (float)($pzfSum['unpaid_net']  ?? 0);
} catch (Exception $e) { /* tabela może nie istnieć w starszych środowiskach */ }

try {
    $pzfListStmt = $pdo->prepare("
        SELECT e.id, e.entry_number, e.title, e.issue_date, e.payment_status,
               e.currency, a.amount_net AS alloc_net,
               i.name AS client_name
        FROM sales_noninvoice_allocations a
        JOIN sales_noninvoice_entries e ON e.id = a.entry_id
        LEFT JOIN investors i ON i.id = e.client_id
        WHERE a.project_id = :pid
        ORDER BY e.issue_date DESC
    ");
    $pzfListStmt->execute(['pid' => $project_id]);
    $pzf_entries_list = $pzfListStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $pzf_entries_list = []; }

$total_sales_combined = $total_invoiced + $total_pzf_net;

// RETENCJE AKTYWNE — suma z FV przypiętych do projektu
// =====================================================
$total_retention_active = 0;
$total_retention_settled = 0;
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN r.status != 'settled' THEN r.retention_amount ELSE 0 END), 0) as active,
            COALESCE(SUM(CASE WHEN r.status = 'settled' THEN r.retention_amount ELSE 0 END), 0) as settled
        FROM invoice_sale_retentions r
        JOIN invoices_sale inv ON inv.id = r.invoice_id
        WHERE inv.status IN ('issued', 'paid', 'partially_paid')
          AND EXISTS (
              SELECT 1 FROM invoice_sale_allocations isa
              WHERE isa.invoice_id = inv.id AND isa.project_id = :project_id
          )
    ");
    $stmt->execute(['project_id' => $project_id]);
    $retRow = $stmt->fetch();
    $total_retention_active = (float)($retRow['active'] ?? 0);
    $total_retention_settled = (float)($retRow['settled'] ?? 0);
} catch (Exception $e) {}

// =====================================================
// LABOR
// =====================================================
try {
    $labor_sql = "
        SELECT 
            COUNT(*) as logs_count,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' THEN wl.hours ELSE 0 END), 0) as total_hours,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' THEN wl.overtime_hours ELSE 0 END), 0) as total_overtime,
            COALESCE(SUM(wl.final_cost), 0) as total_cost,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_saturday = 1 THEN wl.hours ELSE 0 END), 0) as sat_hours,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_sunday = 1 THEN wl.hours ELSE 0 END), 0) as sun_hours,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_night = 1 THEN wl.hours ELSE 0 END), 0) as night_hours,
            COALESCE(SUM(CASE WHEN wl.work_type = 'vacation' THEN CASE WHEN wl.absence_days IS NOT NULL AND wl.absence_days > 0 THEN wl.absence_days ELSE wl.vacation_hours / 8 END ELSE 0 END), 0) as vacation_days,
            COALESCE(SUM(CASE WHEN wl.work_type = 'sick' THEN CASE WHEN wl.absence_days IS NOT NULL AND wl.absence_days > 0 THEN wl.absence_days ELSE wl.sickleave_hours / 8 END ELSE 0 END), 0) as sick_days
        FROM work_logs wl
        WHERE wl.project_id = :project_id AND wl.status = 'approved'
        {$date_condition_sql}
    ";
    $stmt = $pdo->prepare($labor_sql);
    $stmt->execute($date_params);
    $labor_summary = $stmt->fetch();
} catch (Exception $e) {
    $labor_summary = ['logs_count' => 0, 'total_hours' => 0, 'total_overtime' => 0, 'total_cost' => 0, 'sat_hours' => 0, 'sun_hours' => 0, 'night_hours' => 0, 'vacation_days' => 0, 'sick_days' => 0];
}

$worker_filter = $_GET['worker_id'] ?? '';
$worker_condition = '';
$labor_worker_params = $date_params;

if ($worker_filter !== '') {
    $worker_condition = " AND w.id = :worker_id";
    $labor_worker_params['worker_id'] = $worker_filter;
}

try {
    $labor_worker_sql = "
        SELECT 
            w.id, w.first_name, w.last_name,
            COUNT(wl.id) as logs_count,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' THEN wl.hours ELSE 0 END), 0) as total_hours,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' THEN wl.overtime_hours ELSE 0 END), 0) as total_overtime,
            COALESCE(SUM(wl.final_cost), 0) as total_cost,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_saturday = 1 THEN wl.hours ELSE 0 END), 0) as sat_hours,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_sunday = 1 THEN wl.hours ELSE 0 END), 0) as sun_hours,
            COALESCE(SUM(CASE WHEN COALESCE(wl.work_type, 'work') = 'work' AND wl.is_night = 1 THEN wl.hours ELSE 0 END), 0) as night_hours,
            COALESCE(SUM(CASE WHEN wl.work_type = 'vacation' THEN CASE WHEN wl.absence_days IS NOT NULL AND wl.absence_days > 0 THEN wl.absence_days ELSE wl.vacation_hours / 8 END ELSE 0 END), 0) as vacation_days,
            COALESCE(SUM(CASE WHEN wl.work_type = 'sick' THEN CASE WHEN wl.absence_days IS NOT NULL AND wl.absence_days > 0 THEN wl.absence_days ELSE wl.sickleave_hours / 8 END ELSE 0 END), 0) as sick_days
        FROM workers w
        JOIN work_logs wl ON wl.worker_id = w.id
        WHERE wl.project_id = :project_id AND wl.status = 'approved'
        {$date_condition_sql}
        {$worker_condition}
        GROUP BY w.id
        ORDER BY total_cost DESC
    ";
    $stmt = $pdo->prepare($labor_worker_sql);
    $stmt->execute($labor_worker_params);
    $labor_by_worker = $stmt->fetchAll();
} catch (Exception $e) {
    $labor_by_worker = [];
}

try {
    $stmt = $pdo->prepare("SELECT DISTINCT w.id, w.first_name, w.last_name FROM workers w JOIN work_logs wl ON wl.worker_id = w.id WHERE wl.project_id = :project_id AND wl.status = 'approved' ORDER BY w.last_name, w.first_name");
    $stmt->execute(['project_id' => $project_id]);
    $workers_on_project = $stmt->fetchAll();
} catch (Exception $e) {
    $workers_on_project = [];
}

try {
    $stmt = $pdo->prepare("SELECT DISTINCT w.id, w.first_name, w.last_name FROM workers w JOIN worker_expenses we ON we.worker_id = w.id WHERE we.project_id = :project_id AND we.status = 'approved' AND we.document_id IS NULL ORDER BY w.last_name, w.first_name");
    $stmt->execute(['project_id' => $project_id]);
    $workers_with_expenses = $stmt->fetchAll();
} catch (Exception $e) {
    $workers_with_expenses = [];
}

// =====================================================
// WORKER EXPENSES - CASH
// =====================================================
try {
    $expenses_sql = "
        SELECT COUNT(*) as expenses_count, COALESCE(SUM(we.amount), 0) as total_amount
        FROM worker_expenses we
        WHERE we.project_id = :project_id AND we.status = 'approved'
        AND we.document_id IS NULL
        {$expense_date_condition}
        {$expense_worker_condition}
    ";
    $stmt = $pdo->prepare($expenses_sql);
    $stmt->execute($expense_params);
    $expenses_summary = $stmt->fetch();
} catch (Exception $e) {
    $expenses_summary = ['expenses_count' => 0, 'total_amount' => 0];
}

// =====================================================
// COST INVOICES (document_allocations + fakturownia)
// =====================================================
$inv_search = $_GET['inv_search'] ?? '';
$inv_category = $_GET['inv_category'] ?? '';
$inv_vendor = $_GET['inv_vendor'] ?? '';
$inv_sort = $_GET['inv_sort'] ?? 'date_desc';

$invoice_params = ['project_id' => $project_id];

$invoice_date_condition = "";
$fci_date_condition = "";
if ($date_from) {
    $invoice_date_condition .= " AND d.issue_date >= :inv_date_from";
    $fci_date_condition .= " AND fci.issue_date >= :fci_date_from";
    $invoice_params['inv_date_from'] = $date_from;
    $invoice_params['fci_date_from'] = $date_from;
}
if ($date_to) {
    $invoice_date_condition .= " AND d.issue_date <= :inv_date_to";
    $fci_date_condition .= " AND fci.issue_date <= :fci_date_to";
    $invoice_params['inv_date_to'] = $date_to;
    $invoice_params['fci_date_to'] = $date_to;
}

$invoice_vendor_condition = "";
if ($inv_vendor !== '') {
    $invoice_vendor_condition = " AND (d.vendor_id = :inv_vendor OR d.source_name = :inv_vendor_name)";
    $invoice_params['inv_vendor'] = $inv_vendor;
    $invoice_params['inv_vendor_name'] = $inv_vendor;
}

$invoice_search_new = "";
$invoice_search_legacy = "";
if ($inv_search !== '') {
    $invoice_search_new = " AND (d.number LIKE :inv_search_new OR di.item_name LIKE :inv_search_new2 OR COALESCE(i.name, d.source_name) LIKE :inv_search_new3)";
    $invoice_search_legacy = " AND (d.number LIKE :inv_search_leg OR da.description LIKE :inv_search_leg2 OR COALESCE(i.name, d.source_name) LIKE :inv_search_leg3)";
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

try {
    $invoices_sql = "
        SELECT 
            COUNT(DISTINCT document_id) as invoices_count,
            COALESCE(SUM(amount), 0) as total_amount
        FROM (
            SELECT d.id as document_id, dia.amount as amount
            FROM document_item_allocations dia
            JOIN document_items di ON dia.document_item_id = di.id
            JOIN documents d ON di.document_id = d.id
            LEFT JOIN investors i ON i.id = d.vendor_id
            WHERE dia.project_id = :project_id AND d.status = 'approved' AND d.type = 'invoice_cost'
              {$invoice_date_condition} {$invoice_vendor_condition} {$invoice_search_new}
            UNION ALL
            SELECT d.id as document_id, da.amount_net as amount
            FROM document_allocations da
            JOIN documents d ON d.id = da.document_id
            LEFT JOIN investors i ON i.id = d.vendor_id
            WHERE da.project_id = :project_id AND d.status = 'approved' AND d.type = 'invoice_cost' AND da.is_legacy = 1
              {$invoice_date_condition} {$invoice_vendor_condition} {$invoice_search_legacy} {$invoice_category_condition}
            UNION ALL
            SELECT CONCAT('fci_', fci.id) as document_id, fca.amount_net as amount
            FROM fakturownia_cost_allocations fca
            JOIN fakturownia_cost_invoices fci ON fca.cost_invoice_id = fci.id
            WHERE fca.project_id = :project_id {$fci_date_condition}
        ) AS combined_allocations
    ";
    $stmt = $pdo->prepare($invoices_sql);
    $stmt->execute($invoice_params);
    $invoices_summary_data = $stmt->fetch();
} catch (Exception $e) {
    $invoices_summary_data = ['invoices_count' => 0, 'total_amount' => 0];
}

try {
    $invoices_list_sql = "
        SELECT 
            document_id, number, issue_date, vendor_id, source_name, vendor_name,
            SUM(amount) as amount_net,
            GROUP_CONCAT(DISTINCT description SEPARATOR '; ') as description,
            MAX(category) as category
        FROM (
            SELECT d.id as document_id, d.number, d.issue_date, d.vendor_id, d.source_name,
                COALESCE(i.name, d.source_name) as vendor_name, dia.amount as amount,
                CONCAT(di.item_name, COALESCE(CONCAT(' - ', dia.notes), '')) as description, 'material' as category
            FROM document_item_allocations dia
            JOIN document_items di ON dia.document_item_id = di.id
            JOIN documents d ON di.document_id = d.id
            LEFT JOIN investors i ON i.id = d.vendor_id
            WHERE dia.project_id = :project_id AND d.status = 'approved' AND d.type = 'invoice_cost'
              {$invoice_date_condition} {$invoice_vendor_condition} {$invoice_search_new}
            UNION ALL
            SELECT d.id as document_id, d.number, d.issue_date, d.vendor_id, d.source_name,
                COALESCE(i.name, d.source_name) as vendor_name, da.amount_net as amount, da.description, da.category
            FROM document_allocations da
            JOIN documents d ON d.id = da.document_id
            LEFT JOIN investors i ON i.id = d.vendor_id
            WHERE da.project_id = :project_id AND d.status = 'approved' AND d.type = 'invoice_cost' AND da.is_legacy = 1
              {$invoice_date_condition} {$invoice_vendor_condition} {$invoice_search_legacy} {$invoice_category_condition}
            UNION ALL
            SELECT CONCAT('fci_', fci.id) as document_id, fci.invoice_number as number, fci.issue_date,
                NULL as vendor_id, fci.supplier_name as source_name, fci.supplier_name as vendor_name,
                fca.amount_net as amount, fca.description, 'material' as category
            FROM fakturownia_cost_allocations fca
            JOIN fakturownia_cost_invoices fci ON fca.cost_invoice_id = fci.id
            WHERE fca.project_id = :project_id {$fci_date_condition}
        ) AS combined_invoices
        GROUP BY document_id, number, issue_date, vendor_id, source_name, vendor_name
    ";
    
    $orderBy = "issue_date DESC";
    switch ($inv_sort) {
        case 'date_asc': $orderBy = "issue_date ASC"; break;
        case 'date_desc': $orderBy = "issue_date DESC"; break;
        case 'vendor_asc': $orderBy = "vendor_name ASC, issue_date DESC"; break;
        case 'vendor_desc': $orderBy = "vendor_name DESC, issue_date DESC"; break;
        case 'amount_asc': $orderBy = "amount_net ASC"; break;
        case 'amount_desc': $orderBy = "amount_net DESC"; break;
    }
    $invoices_list_sql .= " ORDER BY $orderBy";
    $stmt = $pdo->prepare($invoices_list_sql);
    $stmt->execute($invoice_params);
    $invoices_list = $stmt->fetchAll();
} catch (Exception $e) {
    $invoices_list = [];
}

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT vendor_name, vendor_key FROM (
            SELECT COALESCE(i.name, d.source_name) as vendor_name, COALESCE(d.vendor_id, d.source_name) as vendor_key
            FROM document_item_allocations dia JOIN document_items di ON dia.document_item_id = di.id JOIN documents d ON di.document_id = d.id LEFT JOIN investors i ON i.id = d.vendor_id
            WHERE dia.project_id = ? AND d.status = 'approved'
            UNION
            SELECT COALESCE(i.name, d.source_name) as vendor_name, COALESCE(d.vendor_id, d.source_name) as vendor_key
            FROM document_allocations da JOIN documents d ON d.id = da.document_id LEFT JOIN investors i ON i.id = d.vendor_id
            WHERE da.project_id = ? AND d.status = 'approved' AND da.is_legacy = 1
            UNION
            SELECT fci.supplier_name as vendor_name, fci.supplier_name as vendor_key
            FROM fakturownia_cost_allocations fca JOIN fakturownia_cost_invoices fci ON fca.cost_invoice_id = fci.id
            WHERE fca.project_id = ?
        ) AS vendors ORDER BY vendor_name
    ");
    $stmt->execute([$project_id, $project_id, $project_id]);
    $inv_vendors_list = $stmt->fetchAll();
} catch (Exception $e) {
    $inv_vendors_list = [];
}

// Expenses list
try {
    $expenses_list_sql = "
        SELECT we.*, w.first_name, w.last_name, pcn.name as cost_node_name
        FROM worker_expenses we
        JOIN workers w ON we.worker_id = w.id
        LEFT JOIN project_cost_nodes pcn ON pcn.id = we.cost_node_id
        WHERE we.project_id = :project_id AND we.status IN ('pending', 'approved')
        AND we.document_id IS NULL
        {$expense_date_condition} {$expense_worker_condition}
        ORDER BY we.date DESC
        LIMIT 50
    ";
    $stmt = $pdo->prepare($expenses_list_sql);
    $stmt->execute($expense_params);
    $expenses_list = $stmt->fetchAll();
} catch (Exception $e) {
    $expenses_list = [];
}

// =====================================================
// FINANCIAL CALCULATIONS
// =====================================================
$labor_cost = (float)($labor_summary['total_cost'] ?? 0);
$expenses_cash = (float)($expenses_summary['total_amount'] ?? 0);
$invoices_materials = (float)($invoices_summary_data['total_amount'] ?? 0);

$total_costs = $labor_cost + $expenses_cash + $invoices_materials;

// Zysk umowny (stary sposób - informacyjny)
$profit_contract = $total_revenue - $total_costs;
$margin_contract = ($total_revenue > 0) ? ($profit_contract / $total_revenue * 100) : 0;

// Zysk rzeczywisty (na podstawie faktur sprzedażowych)
$profit_real = $total_invoiced - $total_costs;
$margin_real = ($total_invoiced > 0) ? ($profit_real / $total_invoiced * 100) : 0;

// Zysk kasowy (na podstawie wpłat)
$profit_cash = $total_received - $total_costs;
$margin_cash = ($total_received > 0) ? ($profit_cash / $total_received * 100) : 0;

// CSS Classes
$profit_class = $profit_real > 0 ? 'kpi-positive' : ($profit_real < 0 ? 'kpi-negative' : 'kpi-neutral');
$profit_cash_class = $profit_cash > 0 ? 'kpi-positive' : ($profit_cash < 0 ? 'kpi-negative' : 'kpi-neutral');
$margin_class = $margin_real > 20 ? 'kpi-positive' : ($margin_real > 0 ? 'kpi-warning' : 'kpi-negative');

// =====================================================
// SUCCESS/ERROR MESSAGES + LABELS
// =====================================================
$status_labels = ['planned' => 'Planowany', 'active' => 'Aktywny', 'finished' => 'Zakończony'];
$revenue_types = ['contract' => 'Umowa', 'annex' => 'Aneks', 'bonus' => 'Bonus'];

$success_message = '';
if (isset($_GET['success'])) {
    $messages = ['created' => 'Projekt został utworzony pomyślnie', 'updated' => 'Projekt został zaktualizowany pomyślnie', 'revenue_added' => 'Przychód został dodany', 'expense_added' => 'Wydatek został dodany'];
    $success_message = $messages[$_GET['success']] ?? '';
}

$error_message = '';
if (isset($_GET['error'])) {
    $error_messages = ['internal' => 'Nie można edytować projektu wewnętrznego', 'delete_failed' => 'Błąd podczas usuwania projektu. Spróbuj ponownie.'];
    $error_message = $error_messages[$_GET['error']] ?? '';
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo e($project['name']); ?></title>
    <link rel="stylesheet" href="/projekty/assets/projekty.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        .filter-select, .filter-input,
        .filter-bar select, .filter-bar input[type="text"], .filter-bar input[type="date"],
        .filter-form select, .filter-form input[type="text"], .filter-form input[type="date"] {
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
        .filter-select:focus, .filter-input:focus,
        .filter-bar select:focus, .filter-bar input:focus,
        .filter-form select:focus, .filter-form input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .filter-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 20px;
            margin-bottom: 30px;
        }
        .filter-toolbar {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        .filter-toolbar .btn { padding: 8px 16px; font-size: 13px; }
        .filter-toolbar .btn-outline, .btn-outline {
            background: white; color: #1f2937; border: 2px solid #e5e7eb;
            padding: 6px 12px; font-size: 13px; border-radius: 6px;
            text-decoration: none; display: inline-block; cursor: pointer;
        }
        .filter-toolbar .btn-outline:hover, .btn-outline:hover,
        .filter-toolbar .btn-outline.active, .btn-outline.active {
            background: #1f2937; color: white; border-color: #1f2937;
        }
        .filter-form { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }

        /* KPI */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        .kpi-card {
            background: white; padding: 20px; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center;
            border-left: 4px solid #e5e7eb;
        }
        .kpi-card.revenue { border-left-color: #16a34a; }
        .kpi-card.invoiced { border-left-color: #2563eb; }
        .kpi-card.to-invoice { border-left-color: #8b5cf6; }
        .kpi-card.costs { border-left-color: #dc2626; }
        .kpi-card.profit { border-left-color: #ea580c; }
        .kpi-card.margin { border-left-color: #0891b2; }
        .kpi-label {
            font-size: 11px; color: #6b7280; text-transform: uppercase;
            letter-spacing: 0.5px; margin-bottom: 8px; font-weight: 600;
        }
        .kpi-value { font-size: 26px; font-weight: 700; color: #1f2937; }
        .kpi-sub { font-size: 11px; color: #9ca3af; margin-top: 4px; }
        .kpi-positive { color: #16a34a !important; }
        .kpi-negative { color: #dc2626 !important; }
        .kpi-warning { color: #ea580c !important; }
        .kpi-neutral { color: #6b7280 !important; }

        .two-columns {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 30px; align-items: stretch; margin-bottom: 30px;
        }
        .two-columns > .card, .two-columns > .chart-wrapper {
            height: 100%; display: flex; flex-direction: column;
        }
        .two-columns > .card .card-body { flex: 1; }
        @media (max-width: 1024px) { .two-columns { grid-template-columns: 1fr; } }

        .full-width-section { width: 100%; }
        .chart-container { position: relative; height: 300px; margin-bottom: 30px; }
        .chart-wrapper {
            background: white; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 20px;
        }
        .chart-title { font-size: 18px; font-weight: 600; margin-bottom: 20px; color: #1f2937; }

        /* MODULE SECTIONS - wizualne oddzielenie */
        .module-section {
            margin-bottom: 32px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .module-section .module-accent {
            height: 4px;
        }
        .module-section .card {
            margin-bottom: 0;
            border-radius: 0;
            box-shadow: none;
        }
        .module-accent-green { background: #16a34a; }
        .module-accent-blue { background: #2563eb; }
        .module-accent-orange { background: #ea580c; }
        .module-accent-red { background: #dc2626; }
        .module-accent-gray { background: #6b7280; }

        /* Investor */
        .investor-card {
            background: #1f2937; color: white; padding: 25px;
            border-radius: 12px; margin-bottom: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .investor-card h3 { font-size: 18px; margin-bottom: 15px; opacity: 0.9; font-weight: 600; }
        .investor-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .investor-field { background: rgba(255,255,255,0.1); padding: 12px 15px; border-radius: 8px; }
        .investor-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.8; margin-bottom: 4px; }
        .investor-value { font-size: 15px; font-weight: 600; }
        .no-investor {
            background: #f9fafb; border: 2px dashed #e5e7eb; color: #9ca3af;
            padding: 20px; border-radius: 12px; text-align: center; margin-bottom: 30px;
        }

        /* Modal */
        .modal-backdrop {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;
        }
        .modal-backdrop.active { display: flex; justify-content: center; align-items: center; }
        .modal {
            background: white; border-radius: 12px; padding: 30px;
            max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h3 { font-size: 20px; color: #1f2937; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #9ca3af; }
        .modal-close:hover { color: #1f2937; }

        .badge-contract { background: #d1fae5; color: #065f46; }
        .badge-annex { background: #fef3c7; color: #92400e; }
        .badge-bonus { background: #dbeafe; color: #1e40af; }
        .badge-info { background: #cfe2ff; color: #084298; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge-secondary { background: #e2e3e5; color: #41464b; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge-success { background: #d1fae5; color: #065f46; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }

        .badge-issued { background: #dbeafe; color: #1e40af; }
        .badge-paid { background: #d1fae5; color: #065f46; }
        .badge-draft { background: #f3f4f6; color: #6b7280; }

        /* Filter toolbar form */
        .filter-toolbar-form {
            background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; margin-bottom: 16px;
        }
        .filter-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .filter-label { color: #6b7280; font-size: 13px; font-weight: 500; padding: 8px 4px; }
        .filter-toolbar-form .filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 150px; }
        .filter-toolbar-form .filter-group label { font-size: 12px; color: #6b7280; font-weight: 500; }
        .filter-toolbar-form .filter-select, .filter-toolbar-form .filter-input {
            background: #fff !important; border: 1px solid #d1d5db; border-radius: 8px;
            padding: 8px 12px; font-size: 14px; color: #1f2937; height: 40px; min-width: 140px; box-sizing: border-box;
        }
        .filter-actions { display: flex; flex-direction: row !important; gap: 8px; align-items: flex-end; padding-top: 18px; }
        .filter-row .btn-outline.active, .filter-row .btn-outline:hover {
            background: #3b82f6; color: #fff; border-color: #3b82f6;
        }

        .sortable-th { cursor: pointer; user-select: none; position: relative; padding-right: 20px !important; transition: background 0.15s ease; }
        .sortable-th:hover { background: #e2e8f0 !important; }
        .sortable-th .sort-arrow { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); font-size: 10px; color: #94a3b8; opacity: 0.4; }
        .sortable-th .sort-arrow::after { content: "▼▲"; font-size: 8px; letter-spacing: -2px; }
        .sortable-th.asc .sort-arrow { opacity: 1; color: #2563eb; }
        .sortable-th.asc .sort-arrow::after { content: "▲"; }
        .sortable-th.desc .sort-arrow { opacity: 1; color: #2563eb; }
        .sortable-th.desc .sort-arrow::after { content: "▼"; }
        .total-row { background: #f9fafb; font-weight: 700; }
        .total-row td { border-top: 2px solid #e5e7eb; }

        .section-note {
            padding: 12px 20px; background: #f0f9ff; border-left: 3px solid #3b82f6;
            font-size: 13px; color: #1e40af; margin: 0;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo e($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert alert-error"><?php echo e($error_message); ?></div>
        <?php endif; ?>

        <!-- HERO -->
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('projekty', ['project_type' => $project['project_type'] ?? 'standard']); ?>">Projekty</a> /
                    <?php echo e($project['name']); ?>
                </div>
                <h1><?php echo e($project['name']); ?></h1>
                <p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px;">
                    <span class="badge badge-<?php echo $project['status']; ?>"><?php echo $status_labels[$project['status']]; ?></span>
                    <?php if ($project['is_internal']): ?><span class="badge badge-internal">Wewnętrzny</span><?php endif; ?>
                    <?php if ($project['start_date']): ?><span style="color:#94a3b8;font-size:13px;">Start: <?php echo formatDate($project['start_date']); ?></span><?php endif; ?>
                    <?php if ($project['end_date']): ?><span style="color:#94a3b8;font-size:13px;">Koniec: <?php echo formatDate($project['end_date']); ?></span><?php endif; ?>
                    <?php if (!empty($cost_stages)): ?>
                    <select onchange="if(this.value) window.location.href=this.value;" style="background:rgba(255,255,255,0.15);color:#e2e8f0;border:1px solid rgba(255,255,255,0.25);border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;">
                        <option value="">Przejdź do etapu...</option>
                        <?php foreach ($cost_stages as $stage): ?>
                            <option value="<?php echo url('projekty.etap.view', ['id' => $stage['id']]); ?>">
                                <?php echo $stage['parent_id'] ? '└ ' : ''; ?><?php echo e($stage['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </p>
            </div>
            <div class="hero-actions">
                <?php if ($isAdminUser): ?>
                    <a href="<?php echo url('projekty.edit', ['id' => $project_id]); ?>" class="btn-hero-primary">Edytuj</a>
                    <?php if (empty($project['archived_at'])): ?>
                        <button type="button" class="btn-hero-secondary" onclick="archiveProject(<?php echo $project_id; ?>)">Archiwizuj</button>
                    <?php else: ?>
                        <button type="button" class="btn-hero-secondary" onclick="restoreProject(<?php echo $project_id; ?>)">Przywróć</button>
                    <?php endif; ?>
                    <button type="button" class="btn-hero-secondary" onclick="deleteProject(<?php echo $project_id; ?>)" style="color:#fca5a5;border-color:rgba(252,165,165,0.4);">Usuń</button>
                <?php endif; ?>
                <a href="<?php echo url('projekty', ['project_type' => $project['project_type'] ?? 'standard']); ?>" class="btn-hero-secondary">← Powrót</a>
            </div>
        </div>
        
        <!-- Investor Card -->
        <?php if ($project['investor_name']): ?>
            <div class="investor-card">
                <h3>Dane Inwestora / Klienta</h3>
                <div class="investor-info">
                    <div class="investor-field">
                        <div class="investor-label">Nazwa</div>
                        <div class="investor-value"><?php echo e($project['investor_name']); ?></div>
                    </div>
                    <?php if ($project['investor_nip']): ?>
                        <div class="investor-field"><div class="investor-label">NIP</div><div class="investor-value"><?php echo e($project['investor_nip']); ?></div></div>
                    <?php endif; ?>
                    <?php if ($project['investor_contact_person']): ?>
                        <div class="investor-field"><div class="investor-label">Osoba kontaktowa</div><div class="investor-value"><?php echo e($project['investor_contact_person']); ?></div></div>
                    <?php endif; ?>
                    <?php if ($project['investor_phone']): ?>
                        <div class="investor-field"><div class="investor-label">Telefon</div><div class="investor-value"><a href="tel:<?php echo e($project['investor_phone']); ?>" style="color:white;text-decoration:none;"><?php echo e($project['investor_phone']); ?></a></div></div>
                    <?php endif; ?>
                    <?php if ($project['investor_email']): ?>
                        <div class="investor-field"><div class="investor-label">Email</div><div class="investor-value"><a href="mailto:<?php echo e($project['investor_email']); ?>" style="color:white;text-decoration:none;"><?php echo e($project['investor_email']); ?></a></div></div>
                    <?php endif; ?>
                    <?php if ($project['investor_address']): ?>
                        <div class="investor-field"><div class="investor-label">Adres</div><div class="investor-value"><?php echo e($project['investor_address']); ?></div></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="no-investor">
                <p>Brak przypisanego inwestora do tego projektu.</p>
                <?php if ($isAdminUser): ?>
                    <p style="margin-top:10px;"><a href="<?php echo url('projekty.edit', ['id' => $project_id]); ?>" style="color:#1f2937;text-decoration:underline;">Dodaj inwestora w edycji projektu →</a></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Date Filter -->
        <div class="filter-section">
            <div class="filter-toolbar">
                <a href="<?php echo url('projekty.view', ['id' => $project_id, 'range' => 'today']); ?>" class="btn btn-outline <?php echo $date_range === 'today' ? 'active' : ''; ?>">Dzień</a>
                <a href="<?php echo url('projekty.view', ['id' => $project_id, 'range' => 'week']); ?>" class="btn btn-outline <?php echo $date_range === 'week' ? 'active' : ''; ?>">Tydzień</a>
                <a href="<?php echo url('projekty.view', ['id' => $project_id, 'range' => 'month']); ?>" class="btn btn-outline <?php echo $date_range === 'month' ? 'active' : ''; ?>">Miesiąc</a>
                <?php if ($has_date_filter): ?>
                    <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>" class="btn btn-secondary">Wyczyść</a>
                <?php endif; ?>
            </div>
            <form method="GET" action="" class="filter-form">
                <input type="hidden" name="id" value="<?php echo $project_id; ?>">
                <div class="filter-group"><label>Data od</label><input type="date" name="date_from" value="<?php echo e($date_from ?? ''); ?>"></div>
                <div class="filter-group"><label>Data do</label><input type="date" name="date_to" value="<?php echo e($date_to ?? ''); ?>"></div>
                <button type="submit" class="btn btn-primary">Filtruj koszty</button>
            </form>
            <?php if ($has_date_filter): ?>
                <div class="alert alert-info" style="margin-top:15px;margin-bottom:0;">
                    <strong>Filtr aktywny:</strong> Koszty za okres <?php echo $date_from ? formatDate($date_from) : 'od początku'; ?> - <?php echo $date_to ? formatDate($date_to) : 'do dziś'; ?>.
                    <br><small>Przychody (umowy/aneksy) i faktury sprzedażowe są zawsze globalne.</small>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- ============================================= -->
        <!-- KPI BADGES - rozszerzone                      -->
        <!-- ============================================= -->
        <div class="kpi-grid">
            <div class="kpi-card revenue">
                <div class="kpi-label">Wartość umowy</div>
                <div class="kpi-value" style="color:#16a34a;"><?php echo formatMoney($total_revenue); ?></div>
                <div class="kpi-sub">Umowy + aneksy</div>
            </div>
            <div class="kpi-card invoiced">
                <div class="kpi-label">Zafakturowano (FV)</div>
                <div class="kpi-value" style="color:#2563eb;"><?php echo formatMoney($total_invoiced); ?></div>
                <div class="kpi-sub"><?php echo count($sales_invoices_list); ?> faktur</div>
            </div>
            <?php if ($total_pzf_net > 0 || !empty($pzf_entries_list)): ?>
            <div class="kpi-card" style="border-top:3px solid #7c3aed;">
                <div class="kpi-label" style="color:#7c3aed;">Pozafakturowo (PZF)</div>
                <div class="kpi-value" style="color:#7c3aed;"><?php echo formatMoney($total_pzf_net); ?></div>
                <div class="kpi-sub"><?php echo count($pzf_entries_list); ?> wpisów · wpłynęło: <?php echo formatMoney($total_pzf_paid); ?></div>
            </div>
            <div class="kpi-card" style="border-top:3px solid #0891b2;">
                <div class="kpi-label" style="color:#0891b2;">Sprzedaż razem</div>
                <div class="kpi-value" style="color:#0891b2;"><?php echo formatMoney($total_sales_combined); ?></div>
                <div class="kpi-sub">FV + PZF łącznie</div>
            </div>
            <?php endif; ?>
            <div class="kpi-card">
                <div class="kpi-label">Wpłynęło na konto</div>
                <div class="kpi-value" style="color:#059669;"><?php echo formatMoney($total_received); ?></div>
                <div class="kpi-sub">
                    <?php if ($total_invoiced > 0): ?>
                        <?php echo number_format($total_received / $total_invoiced * 100, 0); ?>% zafakturowanego
                    <?php else: ?>-<?php endif; ?>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Do zapłaty</div>
                <div class="kpi-value" style="color:<?php echo $to_pay > 0 ? '#dc2626' : '#6b7280'; ?>;"><?php echo formatMoney($to_pay); ?></div>
                <div class="kpi-sub">Zafakturowano − wpłynęło</div>
            </div>
            <?php if ($total_retention_active > 0 || $total_retention_settled > 0): ?>
            <div class="kpi-card">
                <div class="kpi-label">Retencja</div>
                <div class="kpi-value" style="color:#b45309;"><?php echo formatMoney($total_retention_active); ?></div>
                <div class="kpi-sub">
                    Aktywna (u klienta)
                    <?php if ($total_retention_settled > 0): ?>
                        | Rozliczona: <?php echo formatMoney($total_retention_settled); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="kpi-card costs">
                <div class="kpi-label">Koszty</div>
                <div class="kpi-value kpi-negative"><?php echo formatMoney($total_costs); ?></div>
                <div class="kpi-sub">Faktury + wydatki + robocizna</div>
            </div>
            <div class="kpi-card profit">
                <div class="kpi-label">Zysk (dokument)</div>
                <div class="kpi-value <?php echo $profit_class; ?>"><?php echo formatMoney($profit_real); ?></div>
                <div class="kpi-sub">Zafakturowano − koszty</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Zysk (kasa)</div>
                <div class="kpi-value <?php echo $profit_cash_class; ?>"><?php echo formatMoney($profit_cash); ?></div>
                <div class="kpi-sub">Wpłynęło − koszty</div>
            </div>
            <div class="kpi-card margin">
                <div class="kpi-label">Marża</div>
                <div class="kpi-value <?php echo $margin_class; ?>"><?php echo number_format($margin_real, 1); ?>%</div>
                <div class="kpi-sub">Umowna: <?php echo number_format($margin_contract, 1); ?>%</div>
            </div>
        </div>
        
        <!-- Two Columns: Chart + Cost Breakdown -->
        <div class="two-columns">
            <div class="chart-wrapper">
                <h3 class="chart-title">Struktura Kosztów</h3>
                <?php if ($total_costs > 0): ?>
                    <div class="chart-container"><canvas id="costPieChart"></canvas></div>
                <?php else: ?>
                    <div class="no-data">Brak kosztów do wyświetlenia na wykresie.</div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="card-header"><h3>Podział Kosztów</h3></div>
                <div class="card-body">
                    <table>
                        <tr>
                            <td>Faktury kosztowe</td>
                            <td class="text-right font-bold"><?php echo formatMoney($invoices_materials); ?></td>
                            <td class="text-right text-muted"><?php echo ($total_costs > 0) ? number_format($invoices_materials / $total_costs * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td>Wydatki drobne</td>
                            <td class="text-right font-bold"><?php echo formatMoney($expenses_cash); ?></td>
                            <td class="text-right text-muted"><?php echo ($total_costs > 0) ? number_format($expenses_cash / $total_costs * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr>
                            <td>Robocizna</td>
                            <td class="text-right font-bold"><?php echo formatMoney($labor_cost); ?></td>
                            <td class="text-right text-muted"><?php echo ($total_costs > 0) ? number_format($labor_cost / $total_costs * 100, 1) : 0; ?>%</td>
                        </tr>
                        <tr style="background:#f8f9fa;font-weight:bold;">
                            <td>RAZEM</td>
                            <td class="text-right"><?php echo formatMoney($total_costs); ?></td>
                            <td class="text-right text-muted">100%</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- ============================================= -->
        <!-- MODUŁ 1: FINANSOWANIE                         -->
        <!-- ============================================= -->
        <div class="module-section" id="finansowanie">
            <div class="module-accent module-accent-green"></div>
            <div class="card">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h3>Finansowanie (Umowy / Aneksy)</h3>
                    <?php if ($isAdminUser && !$project['is_internal']): ?>
                        <button type="button" class="btn btn-success btn-sm" onclick="openRevenueModal()">+ Dodaj Przychód</button>
                    <?php endif; ?>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (!empty($revenues)): ?>
                        <table>
                            <thead><tr><th>Typ</th><th>Nazwa</th><th>Data podpisania</th><th style="text-align:right;">Kwota netto</th></tr></thead>
                            <tbody>
                                <?php foreach ($revenues as $rev): ?>
                                    <tr>
                                        <td><span class="badge badge-<?php echo $rev['type']; ?>"><?php echo $revenue_types[$rev['type']] ?? $rev['type']; ?></span></td>
                                        <td><strong><?php echo e($rev['name']); ?></strong></td>
                                        <td><?php echo formatDate($rev['signed_date']); ?></td>
                                        <td style="text-align:right;color:#16a34a;font-weight:600;">+<?php echo formatMoney($rev['amount_net']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="background:#f9fafb;font-weight:bold;">
                                    <td colspan="3">RAZEM</td>
                                    <td style="text-align:right;color:#16a34a;"><?php echo formatMoney($total_revenue); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data" style="padding:20px;">Brak zdefiniowanych przychodów</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- MODUŁ 2: FAKTURY SPRZEDAŻOWE (NOWE!)          -->
        <!-- ============================================= -->
        <div class="module-section" id="faktury-sprzedazowe">
            <div class="module-accent module-accent-blue"></div>
            <div class="card">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h3>Faktury sprzedażowe (<?php echo count($sales_invoices_list); ?>)</h3>
                    <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>" class="btn btn-primary btn-sm" style="font-size:12px;">Moduł faktur →</a>
                </div>
                <?php if (!empty($sales_invoices_list)): ?>
                <div class="card-body" style="padding:0;">
                    <table>
                        <thead><tr>
                            <th>Numer</th><th>Data</th><th>Klient</th><th>Status</th>
                            <th style="text-align:right;">Kwota na projekcie</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($sales_invoices_list as $si): ?>
                                <tr style="cursor:pointer;" onclick="window.location.href='<?php echo url('finanse.faktury-sprzedazowe.edit', ['id' => $si['id']]); ?>'">
                                    <td><strong><?php echo e($si['invoice_number']); ?></strong></td>
                                    <td><?php echo formatDate($si['issue_date']); ?></td>
                                    <td><?php echo e(mb_substr($si['client_name'] ?? '-', 0, 25)); ?><?php echo mb_strlen($si['client_name'] ?? '') > 25 ? '...' : ''; ?></td>
                                    <td><span class="badge badge-<?php echo $si['status']; ?>"><?php echo $si['status'] === 'paid' ? 'Opłacona' : 'Wystawiona'; ?></span></td>
                                    <td style="text-align:right;color:#2563eb;font-weight:600;">+<?php echo formatMoney($si['project_amount_net']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f9fafb;font-weight:bold;">
                                <td colspan="4">RAZEM ZAFAKTUROWANO</td>
                                <td style="text-align:right;color:#2563eb;"><?php echo formatMoney($total_invoiced); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="section-note">Faktury przypisane do projektu przez pozycje. Wystawianie i alokacja w module Faktur Sprzedażowych.</p>
                </div>
                <?php else: ?>
                <div class="card-body">
                    <div class="no-data">Brak faktur sprzedażowych przypisanych do tego projektu</div>
                    <p class="section-note">Przypisz pozycje faktur do projektu w module Faktur Sprzedażowych.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- MODUŁ PZF: Przychody pozafakturowe            -->
        <!-- ============================================= -->
        <?php if (!empty($pzf_entries_list) || $total_pzf_net > 0): ?>
        <div class="module-section" id="przychody-pzf">
            <div class="module-accent" style="background:#7c3aed;"></div>
            <div class="card">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="color:#5b21b6;">Przychody pozafakturowe PZF (<?php echo count($pzf_entries_list); ?>)</h3>
                    <a href="<?php echo url('finanse.faktury-sprzedazowe') . '?source=noninvoice'; ?>" class="btn btn-primary btn-sm" style="font-size:12px;background:#7c3aed;">Centrum PZF →</a>
                </div>
                <?php if (!empty($pzf_entries_list)): ?>
                <div class="card-body" style="padding:0;">
                    <table>
                        <thead><tr>
                            <th>Numer PZF</th><th>Tytuł</th><th>Data</th><th>Kontrahent</th><th>Status</th>
                            <th style="text-align:right;">Kwota na projekcie</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($pzf_entries_list as $pzf): ?>
                            <tr style="cursor:pointer;" onclick="window.location.href='<?php echo url('finanse.sprzedaz-pozafakturowa.edit', ['id' => $pzf['id']]); ?>'">
                                <td><strong><?php echo e($pzf['entry_number']); ?></strong></td>
                                <td><?php echo e(mb_substr($pzf['title'] ?? '', 0, 30)); ?><?php echo mb_strlen($pzf['title'] ?? '') > 30 ? '...' : ''; ?></td>
                                <td><?php echo e($pzf['issue_date']); ?></td>
                                <td><?php echo e(mb_substr($pzf['client_name'] ?? '-', 0, 20)); ?></td>
                                <td>
                                    <?php if ($pzf['payment_status'] === 'paid'): ?>
                                        <span class="badge" style="background:#dcfce7;color:#166534;">Opłacona</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fef9c3;color:#854d0e;">Nieopłacona</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;color:#7c3aed;font-weight:600;">+<?php echo formatMoney($pzf['alloc_net']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f5f3ff;font-weight:bold;">
                                <td colspan="5" style="color:#5b21b6;">RAZEM PZF</td>
                                <td style="text-align:right;color:#7c3aed;"><?php echo formatMoney($total_pzf_net); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="card-body">
                    <div class="no-data">Brak przychodów pozafakturowych przypisanych do tego projektu.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================= -->
        <!-- MODUŁ 3: FAKTURY KOSZTOWE (z KSeF)            -->
        <!-- ============================================= -->
        <div class="module-section" id="faktury-kosztowe">
            <div class="module-accent module-accent-orange"></div>
            <div class="card">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h3>Faktury kosztowe (<?php echo count($invoices_list); ?>)</h3>
                    <a href="<?php echo url('finanse.fakturownia-cost-inbox'); ?>" class="btn btn-secondary btn-sm" style="font-size:12px;">Skrzynka kosztowa →</a>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="filter-toolbar-form" id="fakturyForm">
                        <input type="hidden" name="id" value="<?php echo $project_id; ?>">
                        <?php if ($worker_filter): ?><input type="hidden" name="worker_id" value="<?php echo e($worker_filter); ?>"><?php endif; ?>
                        <div class="filter-row" style="margin-bottom:12px;">
                            <span class="filter-label">Okres:</span>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('fakturyForm','today')">Dzień</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('fakturyForm','week')">Tydzień</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('fakturyForm','month')">Miesiąc</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('fakturyForm','all')">Wszystko</button>
                        </div>
                        <div class="filter-row">
                            <div class="filter-group" style="flex:2;min-width:200px;">
                                <label>Szukaj</label>
                                <input type="text" name="inv_search" class="filter-input" value="<?php echo e($inv_search); ?>" placeholder="Numer, opis, kontrahent...">
                            </div>
                            <div class="filter-group">
                                <label>Kontrahent</label>
                                <select name="inv_vendor" class="filter-select">
                                    <option value="">Wszyscy</option>
                                    <?php foreach ($inv_vendors_list as $v): ?>
                                        <option value="<?php echo e($v['vendor_key']); ?>" <?php echo $inv_vendor == $v['vendor_key'] ? 'selected' : ''; ?>><?php echo e($v['vendor_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label>Sortuj</label>
                                <select name="inv_sort" class="filter-select">
                                    <option value="date_desc" <?php echo $inv_sort === 'date_desc' ? 'selected' : ''; ?>>Data ↓</option>
                                    <option value="date_asc" <?php echo $inv_sort === 'date_asc' ? 'selected' : ''; ?>>Data ↑</option>
                                    <option value="amount_desc" <?php echo $inv_sort === 'amount_desc' ? 'selected' : ''; ?>>Kwota ↓</option>
                                    <option value="amount_asc" <?php echo $inv_sort === 'amount_asc' ? 'selected' : ''; ?>>Kwota ↑</option>
                                </select>
                            </div>
                            <div class="filter-group"><label>Data od</label><input type="date" name="date_from" class="filter-input" value="<?php echo e($date_from); ?>"></div>
                            <div class="filter-group"><label>Data do</label><input type="date" name="date_to" class="filter-input" value="<?php echo e($date_to); ?>"></div>
                            <div class="filter-group filter-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Filtruj</button>
                                <?php if ($inv_search || $inv_vendor || $inv_sort !== 'date_desc' || $date_from || $date_to): ?>
                                    <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>#faktury-kosztowe" class="btn btn-secondary btn-sm">Wyczyść</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
                <?php if (!empty($invoices_list)): ?>
                <div class="card-body" style="padding:0;">
                    <div style="max-height:400px;overflow-y:auto;">
                        <table>
                            <thead><tr>
                                <th>Data</th><th>Numer</th><th>Kontrahent</th><th>Opis</th>
                                <th style="text-align:right;">Kwota netto</th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($invoices_list as $inv): ?>
                                    <tr style="cursor:pointer;" onclick="<?php
                                        if (str_starts_with($inv['document_id'], 'fci_')) {
                                            $fci_id = str_replace('fci_', '', $inv['document_id']);
                                            echo "window.location.href='/finanse/fakturownia-cost-inbox-view.php?id=" . $fci_id . "'";
                                        } else {
                                            echo "window.location.href='/dokumenty/edit.php?id=" . $inv['document_id'] . "'";
                                        }
                                    ?>">
                                        <td><?php echo formatDate($inv['issue_date']); ?></td>
                                        <td><strong><?php echo e($inv['number']); ?></strong></td>
                                        <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($inv['vendor_name']); ?>"><?php echo e(mb_substr($inv['vendor_name'] ?? '-', 0, 20)); ?><?php echo mb_strlen($inv['vendor_name'] ?? '') > 20 ? '...' : ''; ?></td>
                                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($inv['description'] ?? ''); ?>"><?php echo e(mb_substr($inv['description'] ?? '-', 0, 30)); ?><?php echo mb_strlen($inv['description'] ?? '') > 30 ? '...' : ''; ?></td>
                                        <td style="text-align:right;color:#ea580c;font-weight:600;"><?php echo formatMoney($inv['amount_net']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="background:#f9fafb;font-weight:bold;">
                                    <td colspan="4">RAZEM</td>
                                    <td style="text-align:right;color:#ea580c;"><?php echo formatMoney($invoices_materials); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="section-note">Faktury kosztowe alokowane do projektu z KSeF / Fakturownia i modułu Dokumentów.</p>
                </div>
                <?php else: ?>
                <div class="card-body">
                    <div class="no-data">Brak faktur kosztowych dla tego projektu<?php echo ($inv_search || $inv_vendor) ? ' (spróbuj zmienić filtry)' : ''; ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- MODUŁ 4: WYDATKI DROBNE                       -->
        <!-- ============================================= -->
        <div class="module-section" id="wydatki">
            <div class="module-accent module-accent-red"></div>
            <div class="card">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    <h3>Wydatki drobne (<?php echo $expenses_summary['expenses_count'] ?? 0; ?>)</h3>
                    <?php if ($isAdminUser): ?>
                        <button type="button" class="btn btn-success btn-sm" onclick="openExpenseModal()">+ Dodaj wydatek</button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="filter-toolbar-form" id="wydatkiForm">
                        <input type="hidden" name="id" value="<?php echo $project_id; ?>">
                        <?php if ($worker_filter): ?><input type="hidden" name="worker_id" value="<?php echo e($worker_filter); ?>"><?php endif; ?>
                        <div class="filter-row" style="margin-bottom:12px;">
                            <span class="filter-label">Okres:</span>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('wydatkiForm','today')">Dzień</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('wydatkiForm','week')">Tydzień</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('wydatkiForm','month')">Miesiąc</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('wydatkiForm','all')">Wszystko</button>
                        </div>
                        <div class="filter-row">
                            <div class="filter-group">
                                <label>Pracownik</label>
                                <select name="expense_worker_id" class="filter-select">
                                    <option value="">Wszyscy</option>
                                    <?php foreach ($workers_with_expenses as $w): ?>
                                        <option value="<?php echo $w['id']; ?>" <?php echo $expense_worker_filter == $w['id'] ? 'selected' : ''; ?>><?php echo e($w['first_name'] . ' ' . $w['last_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group"><label>Data od</label><input type="date" name="date_from" class="filter-input" value="<?php echo e($date_from); ?>"></div>
                            <div class="filter-group"><label>Data do</label><input type="date" name="date_to" class="filter-input" value="<?php echo e($date_to); ?>"></div>
                            <div class="filter-group filter-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Filtruj</button>
                                <?php if ($expense_worker_filter || $date_from || $date_to): ?>
                                    <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>#wydatki" class="btn btn-secondary btn-sm">Wyczyść</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
                <?php if (!empty($expenses_list)): ?>
                <div class="card-body" style="padding:0;">
                    <div style="max-height:400px;overflow-y:auto;">
                        <table>
                            <thead><tr><th>Data</th><th>Opis</th><th>Pracownik</th><th>Typ</th><th>Etap</th><th style="text-align:right;">Kwota</th></tr></thead>
                            <tbody>
                                <?php foreach ($expenses_list as $exp):
                                    $expenseTypeLabel = ($exp['expense_type'] ?? 'cash_other') === 'cash_purchase' ? 'Zakup drobny' : 'Inne';
                                    $expenseTypeBadge = ($exp['expense_type'] ?? 'cash_other') === 'cash_purchase' ? 'badge-info' : 'badge-secondary';
                                ?>
                                    <tr>
                                        <td><?php echo formatDate($exp['date']); ?></td>
                                        <td>
                                            <?php echo e(trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', $exp['description']))); ?>
                                            <?php if (!empty($exp['paid_by_employee']) || strpos($exp['description'], '[PAID_BY_EMPLOYEE]') !== false): ?>
                                                <br><span class="badge" style="background:#17a2b8;color:white;font-size:10px;margin-top:4px;">ZAPŁACONE PRZEZ PRACOWNIKA</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo e($exp['first_name'] . ' ' . $exp['last_name']); ?></td>
                                        <td><span class="badge <?php echo $expenseTypeBadge; ?>"><?php echo $expenseTypeLabel; ?></span></td>
                                        <td><?php echo !empty($exp['cost_node_name']) ? '<span style="color:#6b7280;font-size:13px;">' . e($exp['cost_node_name']) . '</span>' : '<span style="color:#9ca3af;">-</span>'; ?></td>
                                        <td style="text-align:right;color:#dc2626;font-weight:600;">-<?php echo formatMoney($exp['amount']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr style="background:#f9fafb;font-weight:bold;">
                                    <td colspan="5">RAZEM</td>
                                    <td style="text-align:right;color:#dc2626;"><?php echo formatMoney($expenses_cash); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="card-body">
                    <div class="no-data">Brak wydatków<?php echo ($expense_worker_filter || $date_from || $date_to) ? ' (spróbuj zmienić filtry)' : ''; ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================= -->
        <!-- MODUŁ 5: ROBOCIZNA                            -->
        <!-- ============================================= -->
        <div class="module-section" id="robocizna">
            <div class="module-accent module-accent-gray"></div>
            <div class="card">
                <div class="card-header"><h3>Robocizna (<?php echo $labor_summary['logs_count'] ?? 0; ?> wpisów)</h3></div>
                <div class="card-body">
                    <form method="GET" action="" class="filter-toolbar-form" id="robociznaForm">
                        <input type="hidden" name="id" value="<?php echo $project_id; ?>">
                        <div class="filter-row" style="margin-bottom:12px;">
                            <span class="filter-label">Okres:</span>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('robociznaForm','today')">Dzień</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('robociznaForm','week')">Tydzień</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('robociznaForm','month')">Miesiąc</button>
                            <button type="button" class="btn btn-outline btn-sm" onclick="setDatePreset('robociznaForm','all')">Wszystko</button>
                        </div>
                        <div class="filter-row">
                            <div class="filter-group">
                                <label>Pracownik</label>
                                <select name="worker_id" class="filter-select">
                                    <option value="">Wszyscy pracownicy</option>
                                    <?php foreach ($workers_on_project as $w): ?>
                                        <option value="<?php echo $w['id']; ?>" <?php echo $worker_filter == $w['id'] ? 'selected' : ''; ?>><?php echo e($w['first_name'] . ' ' . $w['last_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-group"><label>Data od</label><input type="date" name="date_from" class="filter-input" value="<?php echo e($date_from); ?>"></div>
                            <div class="filter-group"><label>Data do</label><input type="date" name="date_to" class="filter-input" value="<?php echo e($date_to); ?>"></div>
                            <div class="filter-group filter-actions">
                                <button type="submit" class="btn btn-primary btn-sm">Filtruj</button>
                                <?php if ($worker_filter || $date_from || $date_to): ?>
                                    <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>#robocizna" class="btn btn-secondary btn-sm">Wyczyść</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (!empty($labor_by_worker)): ?>
                        <table id="laborTable" class="sortable-table">
                            <thead><tr>
                                <th data-sort="string" class="sortable-th">Pracownik <span class="sort-arrow"></span></th>
                                <th data-sort="number" class="sortable-th text-center">Godziny <span class="sort-arrow"></span></th>
                                <th data-sort="number" class="sortable-th text-center">Sob (h) <span class="sort-arrow"></span></th>
                                <th data-sort="number" class="sortable-th text-center">Niedz (h) <span class="sort-arrow"></span></th>
                                <th data-sort="number" class="sortable-th text-center">Nocki (h) <span class="sort-arrow"></span></th>
                                <th data-sort="number" class="sortable-th text-center">Nadgodziny <span class="sort-arrow"></span></th>
                                <th data-sort="number" class="sortable-th text-right">Koszt <span class="sort-arrow"></span></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($labor_by_worker as $worker): ?>
                                    <tr>
                                        <td><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?></td>
                                        <td class="text-center"><?php echo number_format($worker['total_hours'], 1); ?> h</td>
                                        <td class="text-center"><?php echo $worker['sat_hours'] > 0 ? '<span class="hours-sat">' . number_format($worker['sat_hours'], 1) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                                        <td class="text-center"><?php echo $worker['sun_hours'] > 0 ? '<span class="hours-sun">' . number_format($worker['sun_hours'], 1) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                                        <td class="text-center"><?php echo $worker['night_hours'] > 0 ? '<span class="hours-night">' . number_format($worker['night_hours'], 1) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                                        <td class="text-center"><?php echo number_format($worker['total_overtime'], 1); ?> h</td>
                                        <td class="text-right font-bold"><?php echo formatMoney($worker['total_cost']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="total-row" style="background:#f8f9fa;font-weight:bold;">
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
                        <div class="no-data">Brak wpisów robocizny dla tego projektu</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Modal: Dodaj Przychód -->
    <div id="revenueModal" class="modal-backdrop">
        <div class="modal">
            <div class="modal-header">
                <h3>Dodaj Przychód</h3>
                <button type="button" class="modal-close" onclick="closeRevenueModal()">&times;</button>
            </div>
            <form method="POST" action="<?php echo url('projekty.edit', ['id' => $project_id]); ?>">
                <input type="hidden" name="action" value="add_revenue">
                <div class="form-group">
                    <label>Typ dokumentu <span class="required">*</span></label>
                    <select name="rev_type" required>
                        <option value="contract">Umowa</option>
                        <option value="annex">Aneks</option>
                        <option value="bonus">Premia / Bonus</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nazwa <span class="required">*</span></label>
                    <input type="text" name="rev_name" placeholder="np. Umowa główna, Aneks nr 1..." required>
                </div>
                <div class="form-group">
                    <label>Kwota netto (PLN) <span class="required">*</span></label>
                    <input type="number" name="rev_amount" step="0.01" min="0" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label>Data podpisania <span class="required">*</span></label>
                    <input type="date" name="rev_signed_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label>Opis (opcjonalnie)</label>
                    <textarea name="rev_description" rows="2" placeholder="Dodatkowe informacje..."></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Dodaj Przychód</button>
                    <button type="button" class="btn btn-secondary" onclick="closeRevenueModal()">Anuluj</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal: Dodaj Wydatek -->
    <div id="expenseModal" class="modal-backdrop">
        <div class="modal">
            <div class="modal-header">
                <h3>Dodaj Wydatek</h3>
                <button type="button" class="modal-close" onclick="closeExpenseModal()">&times;</button>
            </div>
            <form method="POST" action="<?php echo url('finanse.wydatki.create'); ?>">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                <input type="hidden" name="redirect_to" value="<?php echo url('projekty.view', ['id' => $project_id, 'success' => 'expense_added']); ?>">
                <div class="form-group">
                    <label>Data <span class="required">*</span></label>
                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label>Kwota (PLN) <span class="required">*</span></label>
                    <input type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label>Opis wydatku <span class="required">*</span></label>
                    <textarea name="description" rows="2" placeholder="Np. paliwo, materiały budowlane..." required></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Dodaj Wydatek</button>
                    <button type="button" class="btn btn-secondary" onclick="closeExpenseModal()">Anuluj</button>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        function openRevenueModal() { document.getElementById('revenueModal').classList.add('active'); }
        function closeRevenueModal() { document.getElementById('revenueModal').classList.remove('active'); }
        function openExpenseModal() { document.getElementById('expenseModal').classList.add('active'); }
        function closeExpenseModal() { document.getElementById('expenseModal').classList.remove('active'); }
        
        document.querySelectorAll('.modal-backdrop').forEach(modal => {
            modal.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('active'));
        });
        
        <?php if ($total_costs > 0): ?>
        const ctx = document.getElementById('costPieChart').getContext('2d');
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Faktury kosztowe', 'Wydatki drobne', 'Robocizna'],
                datasets: [{
                    data: [<?php echo $invoices_materials; ?>, <?php echo $expenses_cash; ?>, <?php echo $labor_cost; ?>],
                    backgroundColor: ['#ea580c', '#dc2626', '#6b7280'],
                    borderWidth: 2, borderColor: '#fff'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true, font: { size: 13 } } },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = ((value / total) * 100).toFixed(1);
                                return context.label + ': ' + value.toLocaleString('pl-PL', {style:'currency',currency:'PLN'}) + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        function setDatePreset(formId, preset) {
            const form = document.getElementById(formId);
            if (!form) return;
            const dateFrom = form.querySelector('input[name="date_from"]');
            const dateTo = form.querySelector('input[name="date_to"]');
            if (!dateFrom || !dateTo) return;
            const today = new Date();
            let fromDate, toDate;
            switch (preset) {
                case 'today': fromDate = toDate = formatDateISO(today); break;
                case 'week':
                    const wa = new Date(today); wa.setDate(today.getDate() - 7);
                    fromDate = formatDateISO(wa); toDate = formatDateISO(today); break;
                case 'month':
                    const ma = new Date(today); ma.setMonth(today.getMonth() - 1);
                    fromDate = formatDateISO(ma); toDate = formatDateISO(today); break;
                case 'all': default: fromDate = ''; toDate = ''; break;
            }
            dateFrom.value = fromDate; dateTo.value = toDate;
            const buttons = form.querySelectorAll('.filter-row .btn-outline');
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
        }
        
        function formatDateISO(date) {
            return date.getFullYear() + '-' + String(date.getMonth()+1).padStart(2,'0') + '-' + String(date.getDate()).padStart(2,'0');
        }
        
        async function archiveProject(projectId) {
            if (!confirm('Zarchiwizować ten projekt?')) return;
            try {
                const fd = new FormData(); fd.append('project_id', projectId); fd.append('action', 'archive');
                const r = await fetch('<?php echo url('projekty.archive'); ?>', {method:'POST',body:fd});
                const d = await r.json();
                if (d.success) { alert('Zarchiwizowano.'); window.location.href = '<?php echo url('projekty.history'); ?>'; }
                else alert('Błąd: ' + (d.error || 'Nieznany'));
            } catch(e) { alert('Błąd: ' + e.message); }
        }
        
        async function restoreProject(projectId) {
            if (!confirm('Przywrócić ten projekt?')) return;
            try {
                const fd = new FormData(); fd.append('project_id', projectId); fd.append('action', 'restore');
                const r = await fetch('<?php echo url('projekty.archive'); ?>', {method:'POST',body:fd});
                const d = await r.json();
                if (d.success) { alert('Przywrócono.'); window.location.href = '<?php echo url('projekty.view', ['id' => $project_id]); ?>'; }
                else alert('Błąd: ' + (d.error || 'Nieznany'));
            } catch(e) { alert('Błąd: ' + e.message); }
        }
        
        async function deleteProject(projectId) {
            const r = await fetch('/api/projekty/check_data.php?project_id=' + projectId);
            const data = await r.json();
            let msg = 'UWAGA! Usunąć ten projekt?\n\n';
            if (data.has_data) {
                msg += 'Projekt ma przypisane dane:\n';
                if (data.work_logs > 0) msg += '- Godziny: ' + data.work_logs + '\n';
                if (data.expenses > 0) msg += '- Wydatki: ' + data.expenses + '\n';
                if (data.documents > 0) msg += '- Dokumenty: ' + data.documents + '\n';
                msg += '\nZalecamy ARCHIWIZACJĘ zamiast usunięcia.\nUsunięcie jest NIEODWRACALNE!';
            } else {
                msg += 'Projekt jest pusty. Operacja NIEODWRACALNA!';
            }
            if (!confirm(msg)) return;
            const form = document.createElement('form'); form.method = 'POST'; form.action = '/projekty/delete.php';
            const inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'project_id'; inp.value = projectId;
            form.appendChild(inp); document.body.appendChild(form); form.submit();
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.sortable-table').forEach(function(table) {
                const headers = table.querySelectorAll('th.sortable-th');
                const tbody = table.querySelector('tbody');
                headers.forEach((header, columnIndex) => {
                    header.addEventListener('click', function() {
                        const sortType = this.dataset.sort;
                        const isAsc = this.classList.contains('asc');
                        headers.forEach(h => h.classList.remove('asc','desc'));
                        this.classList.add(isAsc ? 'desc' : 'asc');
                        const direction = isAsc ? -1 : 1;
                        const rows = Array.from(tbody.querySelectorAll('tr:not(.total-row)'));
                        const totalRow = tbody.querySelector('tr.total-row');
                        rows.sort((a, b) => {
                            let aVal = a.cells[columnIndex].textContent.trim();
                            let bVal = b.cells[columnIndex].textContent.trim();
                            if (sortType === 'number') {
                                aVal = parseFloat(aVal.replace(/[^\d,.-]/g,'').replace(',','.')) || 0;
                                bVal = parseFloat(bVal.replace(/[^\d,.-]/g,'').replace(',','.')) || 0;
                                if (a.cells[columnIndex].textContent.trim() === '-') aVal = 0;
                                if (b.cells[columnIndex].textContent.trim() === '-') bVal = 0;
                                return (aVal - bVal) * direction;
                            } else {
                                return aVal.toLowerCase().localeCompare(bVal.toLowerCase()) * direction;
                            }
                        });
                        rows.forEach(row => tbody.appendChild(row));
                        if (totalRow) tbody.appendChild(totalRow);
                    });
                });
            });
        });
    </script>
</body>
</html>
