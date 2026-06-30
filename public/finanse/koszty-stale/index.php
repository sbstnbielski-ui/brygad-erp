<?php
/**
 * BRYGAD ERP - Koszty Stale
 * ZUS, US, leasing, subskrypcje, czynsz, media
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__) . '/_company-cost-categories.php';
require_once dirname(__DIR__) . '/_company-cost-category-combo.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$categoryNotice = $_GET['category_notice'] ?? '';
$categoryError = $_GET['category_error'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ks_category_action'])) {
    $redirectParams = $_GET;
    $redirectParams['categories'] = '1';
    unset($redirectParams['category_notice'], $redirectParams['category_error']);
    $redirectToCategories = static function (array $params): void {
        header('Location: ?' . http_build_query($params));
        exit;
    };

    $action = (string)$_POST['ks_category_action'];
    $dictionaryForAction = ksCompanyCostLoadDictionary($pdo);
    if (!$dictionaryForAction['schema_ready']) {
        $redirectParams['category_error'] = 'schema';
        $redirectToCategories($redirectParams);
    }

    try {
        if ($action === 'add_category') {
            $name = trim((string)($_POST['category_name'] ?? ''));
            if ($name === '') {
                $redirectParams['category_error'] = 'empty_category';
                $redirectToCategories($redirectParams);
            }
            $key = ksCompanyCostNormalizeKey($name);
            $stmt = $pdo->prepare("
                INSERT INTO company_cost_categories (category_key, name, is_system, is_active, sort_order, created_at, updated_at)
                VALUES (?, ?, 0, 1, 100, NOW(), NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1, updated_at = NOW()
            ");
            $stmt->execute([$key, mb_substr($name, 0, 100)]);
            $redirectParams['category_notice'] = 'category_added';
            $redirectToCategories($redirectParams);
        }

        if ($action === 'add_subcategory') {
            $categoryKey = trim((string)($_POST['category_key'] ?? ''));
            $name = trim((string)($_POST['subcategory_name'] ?? ''));
            if ($categoryKey === '' || $name === '') {
                $redirectParams['category_error'] = 'empty_subcategory';
                $redirectToCategories($redirectParams);
            }
            $stmt = $pdo->prepare("
                INSERT INTO company_cost_subcategories (category_key, name, sort_order, created_at)
                VALUES (?, ?, 100, NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name)
            ");
            $stmt->execute([$categoryKey, mb_substr($name, 0, 120)]);
            $redirectParams['category_notice'] = 'subcategory_added';
            $redirectToCategories($redirectParams);
        }

        if ($action === 'delete_category') {
            $categoryKey = trim((string)($_POST['category_key'] ?? ''));
            if ($categoryKey === '') {
                $redirectParams['category_error'] = 'missing_category';
                $redirectToCategories($redirectParams);
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO company_cost_categories (category_key, name, is_system, is_active, sort_order, created_at, updated_at)
                VALUES (?, ?, 0, 0, 100, NOW(), NOW())
                ON DUPLICATE KEY UPDATE is_active = 0, updated_at = NOW()
            ");
            $stmt->execute([$categoryKey, ksCompanyCostHumanizeKey($categoryKey)]);
            $pdo->prepare("DELETE FROM company_cost_subcategories WHERE category_key = ?")->execute([$categoryKey]);
            $pdo->prepare("UPDATE finance_items SET category = NULL, subcategory = NULL WHERE item_type = 'FIXED_COST' AND category = ?")->execute([$categoryKey]);
            ksCompanyCostExecSafe($pdo, "UPDATE worker_expenses SET company_category = NULL, company_subcategory = NULL WHERE company_category = ?", [$categoryKey]);
            ksCompanyCostExecSafe($pdo, "UPDATE fakturownia_cost_allocations SET company_cost_category = NULL, company_cost_subcategory = NULL WHERE company_cost_category = ?", [$categoryKey]);
            ksCompanyCostExecSafe($pdo, "UPDATE fakturownia_cost_allocations SET company_cost_category = NULL WHERE company_cost_category = ?", [$categoryKey]);
            $pdo->commit();
            $redirectParams['category_notice'] = 'category_deleted';
            $redirectToCategories($redirectParams);
        }

        if ($action === 'delete_subcategory') {
            $categoryKey = trim((string)($_POST['category_key'] ?? ''));
            $subcategoryName = trim((string)($_POST['subcategory_name'] ?? ''));
            if ($categoryKey === '' || $subcategoryName === '') {
                $redirectParams['category_error'] = 'missing_subcategory';
                $redirectToCategories($redirectParams);
            }
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM company_cost_subcategories WHERE category_key = ? AND name = ?")->execute([$categoryKey, $subcategoryName]);
            $pdo->prepare("UPDATE finance_items SET subcategory = NULL WHERE item_type = 'FIXED_COST' AND category = ? AND subcategory = ?")->execute([$categoryKey, $subcategoryName]);
            ksCompanyCostExecSafe($pdo, "UPDATE worker_expenses SET company_subcategory = NULL WHERE company_category = ? AND company_subcategory = ?", [$categoryKey, $subcategoryName]);
            ksCompanyCostExecSafe($pdo, "UPDATE fakturownia_cost_allocations SET company_cost_subcategory = NULL WHERE company_cost_category = ? AND company_cost_subcategory = ?", [$categoryKey, $subcategoryName]);
            $pdo->commit();
            $redirectParams['category_notice'] = 'subcategory_deleted';
            $redirectToCategories($redirectParams);
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('FINANSE: Blad akcji slownika kategorii: ' . $e->getMessage());
        $redirectParams['category_error'] = 'save_failed';
        $redirectToCategories($redirectParams);
    }

    $redirectParams['category_error'] = 'unknown_action';
    $redirectToCategories($redirectParams);
}

// Source switch: all = Razem, manual = tylko ręczne wydatki, labor = tylko pracownicze
$source = in_array($_GET['source'] ?? 'all', ['all', 'manual', 'labor'], true)
    ? ($_GET['source'] ?? 'all')
    : 'all';

// Filtry
$filterStatus = $_GET['status'] ?? 'all';
$filterCategory = trim((string)($_GET['category'] ?? ''));
$filterSubcategory = trim((string)($_GET['subcategory'] ?? ''));
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo   = isset($_GET['date_to'])   && $_GET['date_to']   !== '' ? $_GET['date_to']   : '';
$search = trim((string)($_GET['search'] ?? ''));

// Sortowanie
$sort = $_GET['sort'] ?? 'issue_date';
$order = $_GET['order'] ?? 'DESC';

// Walidacja sortowania
$allowed_sorts = ['issue_date', 'category', 'subcategory', 'title', 'doc_number', 'company_name', 'status', 'amount_net', 'amount_gross'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'issue_date';
}
$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Kategorie kosztow firmowych
$companyCostDictionary = ksCompanyCostLoadDictionary($pdo);
$categoryLabels = ksCompanyCostCategoryLabels($companyCostDictionary);
$subcategoriesByCategory = ksCompanyCostSubcategoriesByCategory($companyCostDictionary);
$categoryFilterOptions = ['pracownicy' => 'Pracownicy'] + $categoryLabels;
$subcategoryFilterOptions = ksCompanyCostSubcategoryNames(
    $companyCostDictionary,
    ($filterCategory !== '' && $filterCategory !== 'pracownicy') ? $filterCategory : null
);

function getRowColor($index)
{
    $hue = ($index * 137.508) % 360;
    return [
        'hsl' => "hsl($hue, 70%, 95%)",
        'border' => "hsl($hue, 60%, 65%)"
    ];
}

function ksDisplayOrDash(?string $value): string
{
    $trimmed = trim((string)$value);
    return $trimmed !== '' ? $trimmed : '-';
}

function ksTruncate(string $value, int $max = 30): string
{
    return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 2) . '…' : $value;
}

function ksDisplayTitle(array $item): string
{
    $raw = (string)($item['title'] ?? '');
    if (($item['item_type'] ?? '') === 'LABOR_COST') {
        return 'Koszty pracownicze';
    }
    if (str_starts_with($raw, '[Faktura] ')) {
        $raw = mb_substr($raw, 10);
    }
    return $raw;
}

function ksResolveCategoryLabel(?string $categoryKey, array $categoryLabels): string
{
    $categoryKey = trim((string)$categoryKey);
    if ($categoryKey === '') {
        return 'Brak';
    }
    if ($categoryKey === 'pracownicy') {
        return 'Pracownicy';
    }
    return $categoryLabels[$categoryKey]
        ?? mb_convert_case(str_replace('_', ' ', $categoryKey), MB_CASE_TITLE, 'UTF-8');
}

function ksResolveCounterparty(array $item): string
{
    $counterparty = trim((string)($item['counterparty_name'] ?? $item['company_name'] ?? ''));
    return $counterparty !== '' ? $counterparty : '-';
}

function ksSortValue(array $item, string $sort)
{
    switch ($sort) {
        case 'issue_date':
            return (string)($item['issue_date'] ?? '');
        case 'title':
            return mb_strtolower((string)($item['title'] ?? ''));
        case 'doc_number':
            return mb_strtolower((string)($item['doc_number'] ?? ''));
        case 'company_name':
            $counterparty = ksResolveCounterparty($item);
            return $counterparty === '-' ? '' : mb_strtolower($counterparty);
        case 'status':
            return mb_strtolower((string)($item['status'] ?? ''));
        case 'amount_net':
            return (float)($item['amount_net'] ?? 0);
        case 'amount_gross':
            return (float)($item['amount_gross'] ?? 0);
        case 'subcategory':
            return mb_strtolower((string)($item['subcategory'] ?? ''));
        case 'category':
            return mb_strtolower((string)($item['category'] ?? ''));
        default:
            return (string)($item['issue_date'] ?? '');
    }
}

function ksSortItems(array &$items, string $sort, string $order): void
{
    usort($items, function (array $a, array $b) use ($sort, $order) {
        $valueA = ksSortValue($a, $sort);
        $valueB = ksSortValue($b, $sort);

        if (is_float($valueA) || is_int($valueA) || is_float($valueB) || is_int($valueB)) {
            $cmp = (float)$valueA <=> (float)$valueB;
        } else {
            $cmp = strnatcasecmp((string)$valueA, (string)$valueB);
        }

        if ($cmp === 0) {
            $cmp = strcmp((string)($b['issue_date'] ?? ''), (string)($a['issue_date'] ?? ''));
        }
        if ($cmp === 0) {
            $cmp = (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        }

        return $order === 'ASC' ? $cmp : -$cmp;
    });
}

function ksSortLink(string $column, string $label, string $currentSort, string $currentOrder): string
{
    $params = $_GET;
    $nextOrder = ($currentSort === $column && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    $params['sort'] = $column;
    $params['order'] = $nextOrder;
    $url = '?' . http_build_query($params);

    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentOrder === 'ASC' ? ' ↑' : ' ↓';
    }

    return '<a href="' . e($url) . '">' . e($label . $arrow) . '</a>';
}

// Pobierz koszty stale
$items = [];
$totalNet = 0;
$totalGross = 0;
$totalLaborCost = 0;
$error_message = null;

// source decyduje co ładujemy — category filter jest dodatkiem w ramach source
$includeFixedCosts = ($source === 'all' || $source === 'manual')
    && ($filterCategory === '' || $filterCategory !== 'pracownicy');
$includeLaborCosts = ($source === 'all' || $source === 'labor')
    && ($filterCategory === '' || $filterCategory === 'pracownicy')
    && $filterSubcategory === '';

if ($includeFixedCosts) {
    $where = ["fi.item_type = 'FIXED_COST'"];
    // W widoku "Wydatki" pokazuj tylko ręczne wpisy, bez faktur z Fakturowni
    if ($source === 'manual') {
        $where[] = "(fi.title NOT LIKE '[Faktura]%' OR fi.title IS NULL)";
    }
    $params = [];

    if ($filterStatus !== 'all') {
        $where[] = "fi.status = :status";
        $params[':status'] = $filterStatus;
    }
    if ($filterCategory !== '' && $filterCategory !== 'pracownicy') {
        $where[] = "fi.category = :category";
        $params[':category'] = $filterCategory;
    }
    if ($filterSubcategory !== '') {
        $where[] = "fi.subcategory = :subcategory";
        $params[':subcategory'] = $filterSubcategory;
    }
    if ($dateFrom !== '') {
        $where[] = "fi.issue_date >= :date_from";
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = "fi.issue_date <= :date_to";
        $params[':date_to'] = $dateTo;
    }
    if ($search !== '') {
        $where[] = "(fi.title LIKE :search
                    OR fi.doc_number LIKE :search
                    OR fi.company_name LIKE :search
                    OR fi.subcategory LIKE :search
                    OR fi.category LIKE :search
                    OR fi.description LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $sql = "
        SELECT
            fi.*,
            p.name as project_name,
            COALESCE(CONCAT(w.first_name, ' ', w.last_name), u.login, 'Nieznany') as creator_name,
            fci.id as fci_id,
            fci.supplier_name as fci_vendor_name
        FROM finance_items fi
        LEFT JOIN projects p ON fi.project_id = p.id
        LEFT JOIN users u ON fi.created_by = u.id
        LEFT JOIN workers w ON u.worker_id = w.id
        LEFT JOIN fakturownia_cost_invoices fci
            ON fci.invoice_number = fi.doc_number
            AND fi.doc_number IS NOT NULL
            AND fi.doc_number <> ''
            AND fi.title LIKE '[Faktura]%'
        WHERE " . implode(' AND ', $where) . "
        ORDER BY fi.issue_date DESC, fi.id DESC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $fixedItems = $stmt->fetchAll();
        foreach ($fixedItems as $fixedItem) {
            $fixedItem['labor_breakdown'] = [];
            // Dla faktur Fakturowni priorytet: vendor_name z fci, fallback: company_name z fi
            $fixedItem['counterparty_name'] = trim((string)(
                $fixedItem['fci_vendor_name'] ?? $fixedItem['company_name'] ?? ''
            ));
            $items[] = $fixedItem;
        }
        $totalNet = array_sum(array_column($fixedItems, 'amount_net'));
        $totalGross = array_sum(array_column($fixedItems, 'amount_gross'));
    } catch (PDOException $e) {
        error_log("FINANSE: Blad pobierania kosztow stalych: " . $e->getMessage());
        error_log("SQL: " . $sql);
        $error_message = "Wystapil blad podczas pobierania danych. Sprawdz logi.";
    }

    $workerWhere = ["we.document_id IS NULL"];
    $workerParams = [];
    if ($filterStatus !== 'all') {
        $workerWhere[] = "we.status = :we_status";
        $workerParams[':we_status'] = $filterStatus;
    } else {
        $workerWhere[] = "we.status <> 'reimbursed'";
    }
    if ($filterCategory !== '' && $filterCategory !== 'pracownicy') {
        $workerWhere[] = "we.company_category = :we_category";
        $workerParams[':we_category'] = $filterCategory;
    }
    if ($filterSubcategory !== '') {
        $workerWhere[] = "we.company_subcategory = :we_subcategory";
        $workerParams[':we_subcategory'] = $filterSubcategory;
    }
    if ($dateFrom !== '') {
        $workerWhere[] = "we.date >= :we_date_from";
        $workerParams[':we_date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $workerWhere[] = "we.date <= :we_date_to";
        $workerParams[':we_date_to'] = $dateTo;
    }
    if ($search !== '') {
        $workerWhere[] = "(we.description LIKE :we_search
                    OR we.company_category LIKE :we_search
                    OR we.company_subcategory LIKE :we_search
                    OR p.name LIKE :we_search
                    OR CONCAT(w.first_name, ' ', w.last_name) LIKE :we_search)";
        $workerParams[':we_search'] = '%' . $search . '%';
    }

    $workerSql = "
        SELECT
            we.id,
            'WORKER_EXPENSE' as item_type,
            we.company_category as category,
            we.company_subcategory as subcategory,
            we.project_id,
            we.cost_node_id as etap_id,
            NULL as company_id,
            CONCAT(w.first_name, ' ', w.last_name) as company_name,
            CONCAT('Wydatek pracownika: ', we.description) as title,
            we.description,
            NULL as doc_number,
            we.date as issue_date,
            we.date as payment_date,
            we.amount as amount_net,
            we.amount as amount_gross,
            'PLN' as currency,
            we.receipt_path as file_path,
            we.status,
            we.created_at,
            we.created_by_user_id as created_by,
            p.name as project_name,
            CONCAT(w.first_name, ' ', w.last_name) as creator_name,
            NULL as fci_id,
            NULL as fci_vendor_name
        FROM worker_expenses we
        JOIN workers w ON w.id = we.worker_id
        LEFT JOIN projects p ON p.id = we.project_id
        WHERE " . implode(' AND ', $workerWhere) . "
        ORDER BY we.date DESC, we.id DESC
    ";

    try {
        $stmt = $pdo->prepare($workerSql);
        $stmt->execute($workerParams);
        $workerItems = $stmt->fetchAll();
        foreach ($workerItems as $workerItem) {
            $workerItem['labor_breakdown'] = [];
            $workerItem['counterparty_name'] = trim((string)($workerItem['company_name'] ?? ''));
            $items[] = $workerItem;
        }
        $totalNet += array_sum(array_column($workerItems, 'amount_net'));
        $totalGross += array_sum(array_column($workerItems, 'amount_gross'));
    } catch (PDOException $e) {
        error_log("FINANSE: Blad pobierania wydatkow pracowniczych w kosztach firmy: " . $e->getMessage());
        error_log("SQL: " . $workerSql);
        $error_message = "Wystapil blad podczas pobierania danych. Sprawdz logi.";
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

if ($includeLaborCosts && ($filterStatus === 'all' || $filterStatus === 'approved') && $error_message === null) {
    try {
        $laborFromClause = "
            FROM work_logs wl
            JOIN projects p ON p.id = wl.project_id
            LEFT JOIN workers w ON w.id = wl.worker_id
            WHERE p.is_internal = 1
              AND wl.status = 'approved'
        ";
        $laborParams = [];
        if ($dateFrom !== '') {
            $laborFromClause .= " AND wl.date >= :ldf";
            $laborParams[':ldf'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $laborFromClause .= " AND wl.date <= :ldt";
            $laborParams[':ldt'] = $dateTo;
        }

        $laborDetailsStmt = $pdo->prepare("
            SELECT
                wl.date as work_date,
                COALESCE(NULLIF(CONCAT(w.first_name, ' ', w.last_name), ' '), CONCAT('Pracownik #', wl.worker_id)) as worker_name,
                SUM(COALESCE(wl.final_cost, wl.system_cost, 0)) as worker_cost
            {$laborFromClause}
            GROUP BY wl.date, wl.worker_id, w.first_name, w.last_name
            ORDER BY wl.date DESC, worker_name ASC
        ");
        $laborDetailsStmt->execute($laborParams);
        $laborDetailsRows = $laborDetailsStmt->fetchAll();
        $laborBreakdownByDate = [];
        foreach ($laborDetailsRows as $laborDetail) {
            $workDate = (string)$laborDetail['work_date'];
            if (!isset($laborBreakdownByDate[$workDate])) {
                $laborBreakdownByDate[$workDate] = [];
            }
            $laborBreakdownByDate[$workDate][] = [
                'worker_name' => trim((string)$laborDetail['worker_name']) !== '' ? trim((string)$laborDetail['worker_name']) : '-',
                'amount' => (float)$laborDetail['worker_cost'],
            ];
        }

        $laborStmt = $pdo->prepare("
            SELECT
                wl.date as work_date,
                SUM(COALESCE(wl.final_cost, wl.system_cost, 0)) as daily_cost,
                COUNT(DISTINCT wl.worker_id) as worker_count
            {$laborFromClause}
            GROUP BY wl.date
            ORDER BY wl.date DESC
        ");
        $laborStmt->execute($laborParams);
        $laborRows = $laborStmt->fetchAll();

        foreach ($laborRows as $laborRow) {
            $workDate = (string)$laborRow['work_date'];
            $laborBreakdown = $laborBreakdownByDate[$workDate] ?? [];
            if ($search !== '') {
                $searchNeedle = mb_strtolower($search);
                $searchMatched = str_contains('koszty pracownicze', $searchNeedle);
                if (!$searchMatched) {
                    foreach ($laborBreakdown as $workerItem) {
                        if (str_contains(mb_strtolower((string)$workerItem['worker_name']), $searchNeedle)) {
                            $searchMatched = true;
                            break;
                        }
                    }
                }
                if (!$searchMatched) {
                    continue;
                }
            }

            $dailyCost = (float)$laborRow['daily_cost'];
            $totalLaborCost += $dailyCost;
            $items[] = [
                'id' => null,
                'item_type' => 'LABOR_COST',
                'issue_date' => $workDate,
                'category' => 'pracownicy',
                'subcategory' => null,
                'title' => 'Koszty pracownicze',
                'doc_number' => null,
                'company_name' => null,
                'counterparty_name' => null,
                'status' => 'approved',
                'amount_net' => $dailyCost,
                'amount_gross' => $dailyCost,
                'creator_name' => 'System',
                'project_name' => null,
                'labor_breakdown' => $laborBreakdown,
                'worker_count' => (int)$laborRow['worker_count'],
            ];
        }
    } catch (PDOException $e) {
        error_log("FINANSE: Blad pobierania kosztow pracowniczych: " . $e->getMessage());
    }
}

ksSortItems($items, $sort, $order);

// Grupowanie po dniach dla widoku accordion
$itemsByDate = [];
foreach ($items as $item) {
    $date = $item['issue_date'] ?? '0000-00-00';
    if (!isset($itemsByDate[$date])) {
        $itemsByDate[$date] = [
            'items' => [],
            'total' => 0,
            'count' => 0
        ];
    }
    $itemsByDate[$date]['items'][] = $item;
    $itemsByDate[$date]['total'] += (float)$item['amount_net'];
    $itemsByDate[$date]['count']++;
}
if ($sort === 'issue_date' && $order === 'ASC') {
    ksort($itemsByDate);
} else {
    krsort($itemsByDate);
}

$grandTotal = $totalNet + $totalLaborCost;

$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo e(APP_NAME); ?> - Koszty Firmy</title>
    <style>
        :root {
            --primary: #667eea; --primary-dark: #5a67d8; --primary-blue: #1e3a8a; --primary-blue-dark: #172554;
            --bg-body: #f5f7fa; --bg-card: #ffffff; --border: #e5e7eb; --border-light: #d1d5db;
            --text-main: #1f2937; --text-muted: #6b7280; --success: #22c55e; --danger: #ef4444; --warning: #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); padding-bottom: 40px; }
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }

        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }

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
        .btn-hero-secondary.active { background: rgba(255,255,255,0.22); color: #fff; border-color: rgba(255,255,255,0.38); }
        .source-switch { display: inline-flex; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 8px; overflow: hidden; margin-top: 10px; }
        .source-switch a { padding: 6px 14px; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.7); text-decoration: none; transition: all 0.15s; }
        .source-switch a:hover { background: rgba(255,255,255,0.15); color: #fff; }
        .source-switch a.active { background: rgba(255,255,255,0.22); color: #fff; }

        .btn { padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600; cursor: pointer; border: none; font-size: 14px; transition: all 0.2s; display: inline-block; white-space: nowrap; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 30px; }
        @media (max-width: 900px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        .stat-card { background: white; padding: 18px 20px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 2px solid transparent; }
        .stat-label { font-size: 12px; color: #666; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 26px; font-weight: 700; color: var(--primary); }

        /* Tooltip na kafelkach - pojawia sie od razu po najechaniu */
        .stat-card[data-tooltip] { position: relative; overflow: visible; cursor: help; }
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

        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 30px; }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        .spx-filter-bar { padding: 12px 20px; background: white; border-bottom: 1px solid var(--border-light); display: flex; gap: 8px; align-items: flex-end; flex-wrap: nowrap; }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .spx-filter-group.fg-status   { flex: 1   1 0; }
        .spx-filter-group.fg-category { flex: 1.5 1 0; }
        .spx-filter-group.fg-subcategory { flex: 1.5 1 0; }
        .spx-filter-group.fg-month    { flex: 1.2 1 0; }
        .spx-filter-group.fg-year     { flex: 0.7 1 0; }
        .spx-filter-group.fg-date     { flex: 1   1 0; }
        .spx-filter-group.fg-search   { flex: 2   1 0; }
        .spx-filter-group label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .spx-filter-group select,
        .spx-filter-group input[type="date"],
        .spx-filter-group input[type="text"] { padding: 0 8px; height: 38px; border: 1px solid var(--border-light); border-radius: 6px; font-size: 13px; background: white; font-family: inherit; transition: border-color 0.15s; width: 100%; }
        .spx-filter-group select:focus, .spx-filter-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1); }
        @media (max-width: 1024px) { .spx-filter-bar { flex-wrap: wrap; } .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; } }
        @media (max-width: 768px) { .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; } .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; } }

        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: white; border: 1px solid var(--border-light); border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; white-space: nowrap; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: var(--primary); color: var(--primary); }
        .spx-quick-btn.active { background: var(--primary); border-color: var(--primary); color: white; font-weight: 600; }

        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { background: #f1f3f5; }
        th { padding: 10px 12px; text-align: left; font-weight: 600; color: #1f2937; border: 1px solid #2d3748; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        th a { cursor: pointer; user-select: none; transition: color 0.2s; color: inherit; text-decoration: none; display: block; }
        th a:hover { color: var(--primary); }
        td { padding: 10px 12px; border: 1px solid #2d3748; font-size: 13px; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }
        .text-right { text-align: right; }
        .cell-ellipsis { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
        .labor-breakdown { display: grid; gap: 4px; }
        .labor-breakdown-row { display: flex; justify-content: space-between; gap: 10px; font-size: 12px; }
        .labor-breakdown-worker { color: #1f2937; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .labor-breakdown-amount { color: #0f766e; font-weight: 700; white-space: nowrap; }

        /* Labor expand w widoku normalnym */
        .labor-toggle { display: inline-flex; align-items: center; gap: 5px; cursor: pointer; font-size: 12px; color: #1e40af; background: none; border: none; padding: 0; font-family: inherit; font-weight: 600; }
        .labor-toggle:hover { text-decoration: underline; }
        .labor-toggle-arrow { font-size: 10px; transition: transform 0.2s; }
        .labor-toggle.open .labor-toggle-arrow { transform: rotate(180deg); }
        .labor-details { display: none; margin-top: 6px; padding: 6px 8px; background: #f0f9ff; border-radius: 6px; border-left: 3px solid #3b82f6; }
        .labor-details.open { display: block; }
        .labor-details-row { display: flex; justify-content: space-between; gap: 12px; font-size: 12px; padding: 2px 0; }
        .labor-details-name { color: #1e293b; }
        .labor-details-amt { color: #0f766e; font-weight: 700; white-space: nowrap; }

        body:not(.no-colors) tbody tr { background: var(--row-bg, #ffffff); border-left: 4px solid var(--row-border, transparent); }
        body:not(.no-colors) tbody tr:hover { filter: brightness(0.95); }
        body.no-colors tbody tr:nth-child(odd)  { background: #ffffff; border-left: 4px solid transparent; }
        body.no-colors tbody tr:nth-child(even) { background: #f8fafc; border-left: 4px solid transparent; }
        body.no-colors tbody tr:hover { background: #e0f2fe; }

        .btn-color-mode { width: 38px; height: 38px; border-radius: 6px; border: 1px solid var(--border-light); background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; padding: 0; }
        .btn-color-mode:hover { background: #f9fafb; border-color: var(--primary); }
        .btn-color-mode.active { background: linear-gradient(135deg, #fce7f3, #e0e7ff); border-color: #a78bfa; }
        .btn-color-mode svg { width: 18px; height: 18px; }
        .btn-group-mode { width: 38px; height: 38px; border-radius: 6px; border: 1px solid var(--border-light); background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; padding: 0; }
        .btn-group-mode:hover { background: #f9fafb; border-color: var(--primary); }
        .btn-group-mode.active { background: linear-gradient(135deg, #667eea, #764ba2); border-color: #764ba2; }
        .btn-group-mode.active svg { stroke: white; }
        .btn-group-mode svg { width: 18px; height: 18px; }
        .spx-separator { width: 1px; height: 22px; background: var(--border-light); }
        .spx-toggle-group { display: flex; gap: 2px; }
        .spx-btn-toggle { padding: 0 10px; height: 28px; background: white; border: 1px solid var(--border-light); border-radius: 5px; font-size: 11px; font-weight: 600; color: #374151; cursor: pointer; transition: all 0.15s; }
        .spx-btn-toggle:hover { border-color: var(--primary); color: var(--primary); }

        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        th.col-status, td.col-status { text-align: center; }
        .badge-approved  { background: #d4edda; color: #155724; }
        .badge-pending   { background: #fff3cd; color: #856404; }
        .badge-rejected  { background: #f8d7da; color: #721c24; }
        .badge-pracownicy { background: #dbeafe; color: #1e40af; }
        .badge-faktura   { background: #ede9fe; color: #6d28d9; }
        .badge-cat       { background: #e7f3ff; color: #004080; }

        .grouped-view { display: none; padding: 16px 20px; }
        .day-group { background: white; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.04); transition: all 0.3s ease; }
        .day-group.collapsed { margin-bottom: 8px; box-shadow: none; }
        .day-group.collapsed .day-content { display: none; }
        .day-header { background: #f8fafc; padding: 12px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none; transition: background 0.2s; }
        .day-header:hover { background: #f1f5f9; }
        .day-group.collapsed .day-header { border-bottom: none; border-radius: 10px; }
        .dh-left { display: flex; align-items: center; gap: 12px; }
        .dh-dayname { color: var(--text-muted); text-transform: uppercase; font-size: 11px; font-weight: 700; background: #e2e8f0; padding: 3px 8px; border-radius: 4px; min-width: 28px; text-align: center; }
        .dh-date { font-weight: 700; font-size: 15px; color: #334155; }
        .dh-right { display: flex; align-items: center; gap: 16px; font-size: 13px; color: var(--text-muted); }
        .dh-stats { display: flex; gap: 16px; }
        .dh-stat { display: flex; align-items: center; gap: 4px; }
        .dh-stat-value { font-weight: 600; color: var(--text-main); }
        .dh-arrow { font-size: 10px; color: var(--text-muted); transition: transform 0.2s; width: 16px; text-align: center; }
        .day-group.collapsed .dh-arrow { transform: rotate(-90deg); }
        .day-content table { margin: 0; }

        .action-buttons { display: flex; gap: 5px; justify-content: flex-start; flex-wrap: nowrap; align-items: center; }
        .action-btn { display: inline-flex; align-items: center; justify-content: center; padding: 4px 10px; height: 26px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: 600; transition: all 0.15s; border: 1px solid; background: white; white-space: nowrap; cursor: pointer; font-family: inherit; }
        .action-btn:hover { transform: translateY(-1px); }
        .action-btn-view  { color: #2563eb; border-color: #2563eb; }
        .action-btn-view:hover  { background: #2563eb; color: white; }
        .action-btn-edit  { color: #059669; border-color: #059669; }
        .action-btn-edit:hover  { background: #059669; color: white; }
        .action-btn-more  { color: #475569; border-color: #d1d5db; background: #f3f4f6; padding: 4px 7px; }
        .action-btn-more:hover  { background: #e5e7eb; border-color: #9ca3af; transform: none; }
        .action-btn-delete { color: #dc2626; border-color: #dc2626; }
        .action-btn-delete:hover { background: #dc2626; color: white; }
        .delete-form { display: inline; margin: 0; }
        .no-data { padding: 60px 20px; text-align: center; color: #999; font-size: 16px; }
        .amount { font-weight: 600; text-align: right; }
        /* Tytuł — obcinanie z popoverem przy hover */
        th.col-title, td.col-title { width: 220px; overflow: hidden; }
        .title-cell { position: relative; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .title-link { color: var(--primary); text-decoration: none; font-weight: 600; }
        .title-link:hover { text-decoration: underline; }
        .title-cell:hover .title-pop { display: block; }
        .title-pop {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            z-index: 8000;
            background: #1e293b;
            color: #f8fafc;
            font-size: 12px;
            line-height: 1.5;
            padding: 6px 10px;
            border-radius: 6px;
            white-space: normal;
            max-width: 340px;
            min-width: 120px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.18);
            pointer-events: none;
            word-break: break-word;
        }
        .title-pop::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 12px;
            border: 5px solid transparent;
            border-bottom-color: #1e293b;
        }

        /* Row dropdown */
        .row-dd-portal { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); min-width: 180px; padding: 6px 0; z-index: 9999; position: fixed; }
        .row-dd-portal a, .row-dd-portal button { display: block; width: 100%; text-align: left; padding: 8px 14px; font-size: 13px; color: #1f2937; text-decoration: none; background: none; border: none; cursor: pointer; font-family: inherit; white-space: nowrap; }
        .row-dd-portal a:hover, .row-dd-portal button:hover { background: #f1f5f9; }
        .row-dd-portal .row-dd-sep { height: 1px; background: #e2e8f0; margin: 4px 0; }
        .row-dd-portal .row-dd-danger { color: #dc2626 !important; }
        .row-dd-overlay { position: fixed; inset: 0; z-index: 9998; }

        .category-manager { overflow: hidden; }
        .category-manager-head { padding: 16px 20px; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
        .category-manager-title { font-size: 18px; font-weight: 700; letter-spacing: -0.2px; color: #1f2937; }
        .category-manager-meta { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .category-manager-body { padding: 18px 20px 20px; display: grid; grid-template-columns: minmax(260px, 360px) 1fr; gap: 18px; align-items: start; }
        .category-form-panel { border: 1px solid var(--border); border-radius: 10px; padding: 14px; background: #f9fafb; }
        .category-form-panel h3 { font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .category-form-panel form { display: grid; gap: 8px; }
        .category-form-panel input,
        .category-form-panel select { height: 38px; border: 1px solid var(--border-light); border-radius: 6px; padding: 0 10px; font: inherit; font-size: 13px; background: #fff; min-width: 0; }
        .category-form-panel input:focus,
        .category-form-panel select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(102,126,234,0.1); }
        .category-form-panel .btn { height: 38px; padding: 0 14px; display: inline-flex; align-items: center; justify-content: center; }
        .category-list { display: grid; gap: 10px; }
        .category-row { border: 1px solid var(--border); border-radius: 10px; background: #fff; overflow: hidden; }
        .category-row-main { padding: 12px 14px; display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; border-left: 4px solid var(--primary); }
        .category-name { font-size: 14px; font-weight: 700; color: #1f2937; }
        .category-key { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
        .category-usage { display: inline-flex; align-items: center; height: 24px; padding: 0 9px; border-radius: 999px; background: #eef2ff; color: #3730a3; font-size: 11px; font-weight: 700; white-space: nowrap; }
        .category-row-actions { display: flex; align-items: center; gap: 8px; }
        .category-delete-btn { height: 28px; padding: 0 10px; border-radius: 5px; border: 1px solid #dc2626; color: #dc2626; background: #fff; font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
        .category-delete-btn:hover { background: #dc2626; color: #fff; }
        .subcategory-list { padding: 0 14px 12px 18px; display: flex; flex-wrap: wrap; gap: 6px; }
        .subcategory-chip { height: 26px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; border-radius: 999px; padding: 0 8px 0 10px; background: #f8fafc; color: #374151; font-size: 12px; font-weight: 600; }
        .subcategory-chip button { border: 0; background: transparent; color: #dc2626; cursor: pointer; font-size: 14px; line-height: 1; padding: 0 1px; }
        .category-empty { padding: 22px; text-align: center; color: var(--text-muted); font-size: 13px; border: 1px dashed var(--border-light); border-radius: 10px; background: #f9fafb; }
        .category-alert { margin: 0 20px 14px; padding: 10px 12px; border-radius: 8px; font-size: 13px; font-weight: 600; }
        .category-alert-success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .category-alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        @media (max-width: 980px) { .category-manager-body { grid-template-columns: 1fr; } }
    </style>
<?php echo spxCompanyCostComboRenderAssets(); ?>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    Koszty firmowe
                </div>
                <h1>Koszty firmowe</h1>
                <p>Wydatki firmowe, faktury kosztowe i koszty pracownicze w jednym miejscu</p>
                <?php
                $swBase = $_GET;
                unset($swBase['source'], $swBase['page']);
                $swAll    = '?' . http_build_query(array_merge($swBase, ['source' => 'all']));
                $swManual = '?' . http_build_query(array_merge($swBase, ['source' => 'manual']));
                $swLabor  = '?' . http_build_query(array_merge($swBase, ['source' => 'labor']));
                ?>
                <div class="source-switch" style="margin-top:10px;">
                    <a href="<?php echo $swAll; ?>"    class="<?php echo $source === 'all'    ? 'active' : ''; ?>">Razem</a>
                    <a href="<?php echo url('finanse.fakturownia-cost-inbox'); ?>">Faktury</a>
                    <a href="<?php echo $swManual; ?>" class="<?php echo $source === 'manual' ? 'active' : ''; ?>">Wydatki</a>
                    <a href="<?php echo $swLabor; ?>"  class="<?php echo $source === 'labor'  ? 'active' : ''; ?>">Pracownicy</a>
                </div>
            </div>
            <div class="hero-actions">
                <?php
                $catManageParams = $_GET;
                $catManageParams['categories'] = '1';
                $catManageUrl = '?' . http_build_query($catManageParams);
                $catManageOpen = ($_GET['categories'] ?? '') === '1';
                ?>
                <a href="<?php echo e($catManageUrl); ?>" class="btn-hero-secondary <?php echo $catManageOpen ? 'active' : ''; ?>">Kategorie +</a>
                <?php if ($source !== 'labor'): ?>
                <a href="<?php echo url('finanse.koszty-stale.create'); ?>?return_url=<?php echo urlencode(url('finanse.koszty-stale') . '?source=' . $source); ?>" class="btn-hero-primary">+ Dodaj wydatek</a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card" data-tooltip="<?php echo $includeFixedCosts ? 'Suma faktur kosztowych i wydatków firmowych z listy po zastosowaniu filtrów. Wartości netto.' : 'Myślnik: bieżący filtr (zakładka „Pracownicy") wyklucza wydatki i faktury. Przełącz na „Razem" lub „Wydatki", żeby zobaczyć wartość.'; ?>">
                <div class="stat-value"><?php echo $includeFixedCosts ? formatMoney($totalNet) : '—'; ?></div>
                <div class="stat-label">Wydatki i faktury</div>
            </div>
            <div class="stat-card" data-tooltip="<?php echo $includeLaborCosts ? 'Koszt pracy pracowników (zatwierdzone godziny na projektach wewnętrznych) w wybranym okresie.' : 'Myślnik: bieżący filtr (zakładka „Wydatki") wyklucza koszty pracownicze. Przełącz na „Razem" lub „Pracownicy", żeby zobaczyć wartość.'; ?>">
                <div class="stat-value" style="color: #2563eb;"><?php echo $includeLaborCosts ? formatMoney($totalLaborCost) : '—'; ?></div>
                <div class="stat-label">Koszty pracownicze</div>
            </div>
            <div class="stat-card" data-tooltip="Łączny koszt firmy z pozycji w tabeli: wydatki i faktury + koszty pracownicze (tylko to, co bieżący filtr dopuszcza). Wartości netto.">
                <div class="stat-value" style="color: #dc2626;"><?php echo formatMoney($grandTotal); ?></div>
                <div class="stat-label">Razem koszty firmy</div>
            </div>
            <div class="stat-card" data-tooltip="Liczba pozycji widocznych na liście po zastosowaniu filtrów i przełącznika Razem/Wydatki/Pracownicy.">
                <div class="stat-value"><?php echo count($items); ?></div>
                <div class="stat-label">Pozycji w tabeli</div>
            </div>
        </div>

        <?php if ($catManageOpen): ?>
        <div class="card category-manager">
            <div class="category-manager-head">
                <div>
                    <div class="category-manager-title">Kategorie kosztów firmowych</div>
                    <div class="category-manager-meta">Słownik dla formularza dodawania wydatku, filtrów i istniejących kosztów.</div>
                </div>
                <a href="<?php echo e('?' . http_build_query(array_diff_key($_GET, ['categories' => true, 'category_notice' => true, 'category_error' => true]))); ?>" class="spx-quick-btn">Zamknij</a>
            </div>
            <?php
            $noticeLabels = [
                'category_added' => 'Kategoria została dodana.',
                'subcategory_added' => 'Podkategoria została dodana.',
                'category_deleted' => 'Kategoria została usunięta, a przypisania wyczyszczone.',
                'subcategory_deleted' => 'Podkategoria została usunięta, a przypisania wyczyszczone.',
            ];
            $errorLabels = [
                'schema' => 'Nie udało się przygotować słownika kategorii w bazie.',
                'empty_category' => 'Podaj nazwę kategorii.',
                'empty_subcategory' => 'Wybierz kategorię i podaj nazwę podkategorii.',
                'missing_category' => 'Brakuje identyfikatora kategorii.',
                'missing_subcategory' => 'Brakuje identyfikatora podkategorii.',
                'save_failed' => 'Nie udało się zapisać zmian w słowniku.',
                'unknown_action' => 'Nieznana akcja słownika.',
            ];
            ?>
            <?php if (isset($noticeLabels[$categoryNotice])): ?>
                <div class="category-alert category-alert-success"><?php echo e($noticeLabels[$categoryNotice]); ?></div>
            <?php endif; ?>
            <?php if (isset($errorLabels[$categoryError])): ?>
                <div class="category-alert category-alert-error"><?php echo e($errorLabels[$categoryError]); ?></div>
            <?php endif; ?>
            <div class="category-manager-body">
                <div class="category-form-panel">
                    <h3>Dodaj kategorię</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="ks_category_action" value="add_category">
                        <input type="text" name="category_name" maxlength="100" placeholder="Nazwa kategorii" required>
                        <button type="submit" class="btn btn-primary">Dodaj kategorię</button>
                    </form>
                    <div style="height:14px;"></div>
                    <h3>Dodaj podkategorię</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="ks_category_action" value="add_subcategory">
                        <select name="category_key" required>
                            <option value="">Wybierz kategorię</option>
                            <?php foreach ($companyCostDictionary['categories'] as $categoryKey => $categoryData): ?>
                                <option value="<?php echo e($categoryKey); ?>"><?php echo e($categoryData['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="subcategory_name" maxlength="120" placeholder="Nazwa podkategorii" required>
                        <button type="submit" class="btn btn-primary">Dodaj podkategorię</button>
                    </form>
                </div>
                <div class="category-list">
                    <?php if (empty($companyCostDictionary['categories'])): ?>
                        <div class="category-empty">Brak kategorii w słowniku.</div>
                    <?php endif; ?>
                    <?php foreach ($companyCostDictionary['categories'] as $categoryKey => $categoryData): ?>
                        <div class="category-row">
                            <div class="category-row-main">
                                <div>
                                    <div class="category-name"><?php echo e($categoryData['name']); ?></div>
                                    <div class="category-key"><?php echo e($categoryKey); ?></div>
                                </div>
                                <div class="category-row-actions">
                                    <span class="category-usage"><?php echo (int)$categoryData['usage']; ?> przyp.</span>
                                    <form method="POST" action="" onsubmit="return confirm('Usunąć kategorię <?php echo e(addslashes($categoryData['name'])); ?>? Jeśli jest używana, przypisanie kategorii i podkategorii zostanie wyczyszczone we wszystkich powiązanych pozycjach.');">
                                        <input type="hidden" name="ks_category_action" value="delete_category">
                                        <input type="hidden" name="category_key" value="<?php echo e($categoryKey); ?>">
                                        <button type="submit" class="category-delete-btn">Usuń</button>
                                    </form>
                                </div>
                            </div>
                            <div class="subcategory-list">
                                <?php if (empty($categoryData['subcategories'])): ?>
                                    <span class="subcategory-chip" style="color:var(--text-muted);font-weight:500;">Brak podkategorii</span>
                                <?php endif; ?>
                                <?php foreach ($categoryData['subcategories'] as $subcategoryData): ?>
                                    <span class="subcategory-chip">
                                        <?php echo e($subcategoryData['name']); ?>
                                        <small><?php echo (int)$subcategoryData['usage']; ?></small>
                                        <form method="POST" action="" style="display:inline;margin:0;" onsubmit="return confirm('Usunąć podkategorię <?php echo e(addslashes($subcategoryData['name'])); ?>? Jeśli jest używana, przypisanie tej podkategorii zostanie wyczyszczone we wszystkich powiązanych pozycjach.');">
                                            <input type="hidden" name="ks_category_action" value="delete_subcategory">
                                            <input type="hidden" name="category_key" value="<?php echo e($categoryKey); ?>">
                                            <input type="hidden" name="subcategory_name" value="<?php echo e($subcategoryData['name']); ?>">
                                            <button type="submit" title="Usuń podkategorię">×</button>
                                        </form>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <?php
            $ksYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            if ($ksYear < 2020 || $ksYear > 2030) $ksYear = (int)date('Y');
            $ksMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
            if ($ksMonth >= 1 && $ksMonth <= 12 && !isset($_GET['date_from'])) {
                $dateFrom = sprintf('%04d-%02d-01', $ksYear, $ksMonth);
                $dateTo   = date('Y-m-t', strtotime($dateFrom));
            }
            $ksMonthNames = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];
            $ksYearRange = range((int)date('Y') - 3, (int)date('Y'));
            $ksActiveMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $ksYear, $m);
                if ($dateFrom === $mS && $dateTo === date('Y-m-t', strtotime($mS))) { $ksActiveMonth = $m; break; }
            }
            $ksToday = date('Y-m-d'); $ksWeekAgo = date('Y-m-d', strtotime('-7 days'));
            $ksMonthStart = date('Y-m-01'); $ksMonthEnd = date('Y-m-t');
            $ksYearStart = date('Y') . '-01-01';
            $isNoDateSet = ($dateFrom === '' && $dateTo === '');
            ?>
            <form method="GET" action="" class="spx-filter-bar" id="ksFilterForm">
                <div class="spx-filter-group fg-month">
                    <label>Miesiąc</label>
                    <select name="month" id="ksSelectMonth" onchange="ksOnMonthYearChange()">
                        <option value="0" <?php echo $ksActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($ksMonthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($ksActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="ksSelectYear" onchange="ksOnMonthYearChange()">
                        <?php foreach ($ksYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($ksYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="ksInputDateFrom" value="<?php echo e($dateFrom); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="ksInputDateTo" value="<?php echo e($dateTo); ?>">
                </div>
                <div class="spx-filter-group fg-category" style="flex: 1.8 1 0;">
                    <label>Kategoria / podkategoria</label>
                    <?php
                    echo spxCompanyCostComboRender([
                        'id_prefix' => 'ks-filter-company',
                        'category_name' => 'category',
                        'subcategory_name' => 'subcategory',
                        'category_select_id' => 'ks-category-select',
                        'subcategory_select_id' => 'ks-subcategory-select',
                        'selected_category' => $filterCategory,
                        'selected_subcategory' => $filterSubcategory,
                        'category_labels' => $categoryFilterOptions,
                        'subcategories_by_category' => $subcategoriesByCategory,
                        'all_subcategory_hints' => $subcategoryFilterOptions,
                        'allow_empty_category' => true,
                        'empty_category_label' => 'Wszystkie kategorie',
                        'empty_subcategory_label' => 'Wszystkie podkategorie',
                        'placeholder_label' => 'Wszystkie kategorie i podkategorie',
                        'help_text' => '',
                        'submit_on_change' => true,
                    ]);
                    ?>
                </div>
                <div class="spx-filter-group fg-status">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Zatwierdzone</option>
                        <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Oczekujące</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-search">
                    <label>Szukaj</label>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Tytuł, numer, kontrahent...">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end; flex-shrink: 0;">Filtruj</button>
                <?php if ($filterStatus !== 'all' || $filterCategory || $filterSubcategory || $dateFrom || $dateTo || $search): ?>
                    <a href="<?php echo url('finanse.koszty-stale'); ?>" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center; flex-shrink: 0;">Wyczyść</a>
                <?php endif; ?>
                <?php if ($sort !== 'issue_date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
            </form>
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <?php
                    $ksBaseParams = [];
                    if ($filterCategory !== '') $ksBaseParams['category'] = $filterCategory;
                    if ($filterSubcategory !== '') $ksBaseParams['subcategory'] = $filterSubcategory;
                    if ($filterStatus !== 'all') $ksBaseParams['status'] = $filterStatus;
                    if ($search !== '') $ksBaseParams['search'] = $search;
                    if ($sort !== 'issue_date') $ksBaseParams['sort'] = $sort;
                    if ($order !== 'DESC') $ksBaseParams['order'] = $order;

                    $ksAllUrl = '?' . http_build_query($ksBaseParams);
                    $ksTodayUrl = '?' . http_build_query(array_merge($ksBaseParams, ['date_from' => $ksToday, 'date_to' => $ksToday]));
                    $ksWeekUrl = '?' . http_build_query(array_merge($ksBaseParams, ['date_from' => $ksWeekAgo, 'date_to' => $ksToday]));
                    $ksMonthUrl = '?' . http_build_query(array_merge($ksBaseParams, ['date_from' => $ksMonthStart, 'date_to' => $ksMonthEnd, 'year' => date('Y')]));
                    $ksYearUrl = '?' . http_build_query(array_merge($ksBaseParams, ['date_from' => $ksYearStart, 'date_to' => $ksToday, 'year' => date('Y')]));
                    ?>
                    <a href="<?php echo $ksAllUrl; ?>" class="spx-quick-btn <?php echo $isNoDateSet ? 'active' : ''; ?>">Wszystkie</a>
                    <a href="<?php echo $ksTodayUrl; ?>" class="spx-quick-btn <?php echo ($dateFrom === $ksToday && $dateTo === $ksToday) ? 'active' : ''; ?>">Dziś</a>
                    <a href="<?php echo $ksWeekUrl; ?>" class="spx-quick-btn <?php echo ($dateFrom === $ksWeekAgo && $dateTo === $ksToday) ? 'active' : ''; ?>">7 dni</a>
                    <a href="<?php echo $ksMonthUrl; ?>" class="spx-quick-btn <?php echo ($dateFrom === $ksMonthStart && $dateTo === $ksMonthEnd) ? 'active' : ''; ?>">Ten miesiąc</a>
                    <a href="<?php echo $ksYearUrl; ?>" class="spx-quick-btn <?php echo ($dateFrom === $ksYearStart && $dateTo === $ksToday) ? 'active' : ''; ?>">Ten rok</a>
                </div>
                <div class="spx-controls-right">
                    <button class="btn-color-mode active" onclick="toggleColors()" title="Kolory wierszy">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 1 0 20"/><circle cx="12" cy="12" r="4"/></svg>
                    </button>
                    <div class="spx-separator"></div>
                    <button class="btn-group-mode active" id="btnDayGroup" onclick="toggleDayGrouping()" title="Grupuj po dniach">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    </button>
                    <div class="spx-toggle-group">
                        <button class="spx-btn-toggle" onclick="expandAllDays()">Rozwiń</button>
                        <button class="spx-btn-toggle" onclick="collapseAllDays()">Zwiń</button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <?php if (isset($error_message)): ?>
                <div class="no-data" style="color: #dc2626; padding: 40px;">
                    <strong>Błąd:</strong> Wystąpił problem z pobraniem danych.
                    <br><br>
                    <small>Skontaktuj się z administratorem systemu.</small>
                </div>
            <?php elseif (empty($items)): ?>
                <div class="no-data">
                    Brak kosztow stalych w wybranym okresie.
                    <br><br>
                    <a href="<?php echo url('finanse.koszty-stale.create'); ?>?return_url=<?php echo urlencode(url('finanse.koszty-stale')); ?>" class="btn btn-primary">
                        Dodaj pierwszy koszt
                    </a>
                </div>
            <?php else: ?>
                <!-- Tabela normalna (domyślnie ukryta) -->
                <table class="normal-view" style="display: none;">
                    <thead>
                        <tr>
                            <th class="sortable" style="width:80px"><?php echo ksSortLink('issue_date', 'Data', $sort, $order); ?></th>
                            <th class="sortable" style="width:100px"><?php echo ksSortLink('category', 'Kategoria', $sort, $order); ?></th>
                            <th class="sortable col-title"><?php echo ksSortLink('title', 'Tytuł / Opis', $sort, $order); ?></th>
                            <th class="sortable" style="width:120px"><?php echo ksSortLink('doc_number', 'Numer', $sort, $order); ?></th>
                            <th class="sortable" style="width:140px"><?php echo ksSortLink('company_name', 'Kontrahent', $sort, $order); ?></th>
                            <th class="sortable col-status" style="width:110px"><?php echo ksSortLink('status', 'Status', $sort, $order); ?></th>
                            <th class="sortable text-right" style="width:85px"><?php echo ksSortLink('amount_net', 'Netto', $sort, $order); ?></th>
                            <th class="sortable text-right" style="width:85px"><?php echo ksSortLink('amount_gross', 'Brutto', $sort, $order); ?></th>
                            <?php if ($isAdminUser): ?><th style="width:36px"></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowIndex = 0;
                        foreach ($items as $item): 
                            $category = ksResolveCategoryLabel($item['category'] ?? '', $categoryLabels);
                            $counterparty = ksResolveCounterparty($item);
                            $counterpartyShort = $counterparty === '-' ? '-' : ksTruncate($counterparty);
                            $subcategory = ksDisplayOrDash($item['subcategory'] ?? null);
                            $colors = getRowColor($rowIndex++);
                        ?>
                            <?php
                            $ksIsFaktura = str_starts_with($item['title'] ?? '', '[Faktura]') && !empty($item['fci_id']);
                            $ksIsWorkerExpense = (($item['item_type'] ?? '') === 'WORKER_EXPENSE');
                            $ksUrl = $ksIsFaktura
                                ? url('finanse.fakturownia-cost-inbox.view', ['id' => (int)$item['fci_id']])
                                : ($ksIsWorkerExpense && !empty($item['id'])
                                    ? url('finanse.wydatki.edit', ['id' => $item['id'], 'return_url' => $_SERVER['REQUEST_URI'] ?? url('finanse.koszty-stale')])
                                    : (!empty($item['id']) ? url('finanse.koszty-stale.edit', ['id' => $item['id']]) : null));
                            ?>
                            <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>">
                                <td style="white-space:nowrap"><?php echo formatDate($item['issue_date']); ?></td>
                                <td>
                                    <?php
                                        $badgeClass = 'badge-cat';
                                        if (($item['item_type'] ?? '') === 'LABOR_COST') $badgeClass = 'badge-pracownicy';
                                        elseif ($ksIsFaktura) $badgeClass = 'badge-faktura';
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo e($category); ?></span>
                                </td>
                                <td class="col-title">
                                    <?php if (($item['item_type'] ?? '') === 'LABOR_COST' && !empty($item['labor_breakdown'])): ?>
                                        <button type="button" class="labor-toggle" onclick="this.classList.toggle('open');this.closest('td').querySelector('.labor-details').classList.toggle('open')">
                                            Koszty pracownicze
                                            <span class="labor-toggle-arrow">▾</span>
                                        </button>
                                        <div class="labor-details">
                                            <?php foreach ($item['labor_breakdown'] as $lb): ?>
                                            <div class="labor-details-row">
                                                <span class="labor-details-name"><?php echo e($lb['worker_name']); ?></span>
                                                <span class="labor-details-amt"><?php echo formatMoney($lb['amount']); ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php elseif (($item['item_type'] ?? '') === 'LABOR_COST'): ?>
                                        <span style="color:var(--text-muted);">Koszty pracownicze</span>
                                    <?php else: ?>
                                        <?php $displayTitle = ksDisplayTitle($item); ?>
                                        <span class="title-cell">
                                            <?php if ($ksUrl): ?>
                                            <a href="<?php echo $ksUrl; ?>" class="title-link" title="<?php echo e($item['title']); ?>"><?php echo e($displayTitle); ?></a>
                                            <?php else: ?>
                                            <span title="<?php echo e($item['title']); ?>"><?php echo e($displayTitle); ?></span>
                                            <?php endif; ?>
                                            <?php if (mb_strlen($displayTitle) > 28): ?>
                                            <span class="title-pop"><?php echo e($item['title']); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($item['subcategory'])): ?>
                                    <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?php echo e($item['subcategory']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="cell-ellipsis" title="<?php echo e(ksDisplayOrDash($item['doc_number'] ?? null)); ?>"><?php echo e(ksDisplayOrDash($item['doc_number'] ?? null)); ?></span></td>
                                <td><span class="cell-ellipsis" title="<?php echo e($counterparty); ?>"><?php echo e($counterpartyShort); ?></span></td>
                                <td class="col-status">
                                    <?php if (($item['item_type'] ?? '') === 'LABOR_COST'): ?>
                                        <span style="color:var(--text-muted);">—</span>
                                    <?php else: ?>
                                        <span class="badge badge-<?php echo e($item['status']); ?>">
                                            <?php 
                                            $statusLabels = ['approved' => 'Zatwierdzone', 'pending' => 'Oczekujące', 'rejected' => 'Odrzucone'];
                                            echo $statusLabels[$item['status']] ?? $item['status'];
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="amount"><?php echo formatMoney($item['amount_net']); ?></td>
                                <td class="amount"><?php echo formatMoney($item['amount_gross']); ?></td>
                                <?php if ($isAdminUser): ?>
                                <td style="text-align:center;padding:4px;">
                                    <?php if (!empty($item['id']) && ($item['item_type'] ?? '') !== 'LABOR_COST'): ?>
                                    <button type="button" class="action-btn action-btn-more" onclick="openRowDropdown(event, this)" data-item-id="<?php echo $item['id']; ?>">&#9660;</button>
                                    <template class="row-dd-tpl">
                                        <div class="row-dd-portal">
                                            <?php if ($ksUrl): ?>
                                            <a href="<?php echo $ksUrl; ?>"><?php echo $ksIsFaktura ? 'Otwórz fakturę kosztową' : ($ksIsWorkerExpense ? 'Edytuj wydatek pracownika' : 'Edytuj koszt'); ?></a>
                                            <div class="row-dd-sep"></div>
                                            <?php endif; ?>
                                            <form method="POST" action="<?php echo $ksIsWorkerExpense ? url('finanse.wydatki.delete') : url('finanse.koszty-stale.delete'); ?>" style="margin:0;" onsubmit="return confirm('Usunąć ten koszt?');">
                                                <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                <?php if ($ksIsWorkerExpense): ?>
                                                <input type="hidden" name="return_url" value="<?php echo e($_SERVER['REQUEST_URI'] ?? url('finanse.koszty-stale')); ?>">
                                                <?php endif; ?>
                                                <button type="submit" class="row-dd-danger">Usuń koszt</button>
                                            </form>
                                        </div>
                                    </template>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="grouped-view">
                    <?php
                    $dayNames = ['Nd','Pn','Wt','Śr','Cz','Pt','So'];
                    foreach ($itemsByDate as $date => $dayData):
                        $ts = strtotime($date);
                        $dayNameShort = $dayNames[(int)date('w', $ts)] ?? '';
                    ?>
                        <div class="day-group collapsed">
                            <div class="day-header" onclick="this.closest('.day-group').classList.toggle('collapsed')">
                                <div class="dh-left">
                                    <span class="dh-dayname"><?php echo $dayNameShort; ?></span>
                                    <span class="dh-date"><?php echo formatDate($date); ?></span>
                                </div>
                                <div class="dh-right">
                                    <div class="dh-stats">
                                        <div class="dh-stat"><span><?php echo $dayData['count']; ?> poz.</span></div>
                                        <div class="dh-stat"><span class="dh-stat-value"><?php echo formatMoney($dayData['total']); ?></span></div>
                                    </div>
                                    <span class="dh-arrow">▾</span>
                                </div>
                            </div>
                            <div class="day-content">
                                <table>
                                    <thead>
                                        <tr>
                                            <th class="sortable" style="width:100px"><?php echo ksSortLink('category', 'Kategoria', $sort, $order); ?></th>
                                            <th class="sortable col-title"><?php echo ksSortLink('title', 'Tytuł / Opis', $sort, $order); ?></th>
                                            <th class="sortable" style="width:120px"><?php echo ksSortLink('doc_number', 'Numer', $sort, $order); ?></th>
                                            <th class="sortable" style="width:140px"><?php echo ksSortLink('company_name', 'Kontrahent', $sort, $order); ?></th>
                                            <th class="sortable col-status" style="width:110px"><?php echo ksSortLink('status', 'Status', $sort, $order); ?></th>
                                            <th class="sortable amount" style="width:85px"><?php echo ksSortLink('amount_net', 'Netto', $sort, $order); ?></th>
                                            <th class="sortable amount" style="width:85px"><?php echo ksSortLink('amount_gross', 'Brutto', $sort, $order); ?></th>
                                            <?php if ($isAdminUser): ?><th style="width:36px"></th><?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $rowIndex = 0;
                                        foreach ($dayData['items'] as $item): 
                                            $category = ksResolveCategoryLabel($item['category'] ?? '', $categoryLabels);
                                            $counterparty = ksResolveCounterparty($item);
                                            $counterpartyShort = $counterparty === '-' ? '-' : ksTruncate($counterparty);
                                            $subcategory = ksDisplayOrDash($item['subcategory'] ?? null);
                                            $colors = getRowColor($rowIndex++);
                                        ?>
                                            <?php
                                            $ksIsFaktura = str_starts_with($item['title'] ?? '', '[Faktura]') && !empty($item['fci_id']);
                                            $ksIsWorkerExpense = (($item['item_type'] ?? '') === 'WORKER_EXPENSE');
                                            $ksUrl = $ksIsFaktura
                                                ? url('finanse.fakturownia-cost-inbox.view', ['id' => (int)$item['fci_id']])
                                                : ($ksIsWorkerExpense && !empty($item['id'])
                                                    ? url('finanse.wydatki.edit', ['id' => $item['id'], 'return_url' => $_SERVER['REQUEST_URI'] ?? url('finanse.koszty-stale')])
                                                    : (!empty($item['id']) ? url('finanse.koszty-stale.edit', ['id' => $item['id']]) : null));
                                            ?>
                                            <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>">
                                                <td>
                                                    <?php
                                                        $badgeClass = 'badge-cat';
                                                        if (($item['item_type'] ?? '') === 'LABOR_COST') $badgeClass = 'badge-pracownicy';
                                                        elseif ($ksIsFaktura) $badgeClass = 'badge-faktura';
                                                    ?>
                                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo e($category); ?></span>
                                                </td>
                                                <td class="col-title">
                                                    <?php if (($item['item_type'] ?? '') === 'LABOR_COST'): ?>
                                                        <button type="button" class="labor-toggle" onclick="this.classList.toggle('open');this.closest('td').querySelector('.labor-details').classList.toggle('open')">
                                                            Koszty pracownicze <span class="labor-toggle-arrow">▾</span>
                                                        </button>
                                                        <div class="labor-details">
                                                            <?php foreach (($item['labor_breakdown'] ?? []) as $lb): ?>
                                                                <div class="labor-details-row">
                                                                    <span class="labor-details-name"><?php echo e($lb['worker_name']); ?></span>
                                                                    <span class="labor-details-amt"><?php echo formatMoney($lb['amount']); ?></span>
                                                                </div>
                                                            <?php endforeach; ?>
                                                            <?php if (empty($item['labor_breakdown'])): ?>
                                                                <span style="color:var(--text-muted);">—</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <?php $displayTitleG = ksDisplayTitle($item); ?>
                                                        <span class="title-cell">
                                                            <?php if ($ksUrl): ?>
                                                            <a href="<?php echo $ksUrl; ?>" class="title-link" title="<?php echo e($item['title']); ?>"><?php echo e($displayTitleG); ?></a>
                                                            <?php else: ?>
                                                            <span title="<?php echo e($item['title']); ?>"><?php echo e($displayTitleG); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (mb_strlen($displayTitleG) > 28): ?>
                                                            <span class="title-pop"><?php echo e($item['title']); ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                        <?php if (!empty($item['subcategory'])): ?>
                                                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?php echo e($item['subcategory']); ?></div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="cell-ellipsis" title="<?php echo e(ksDisplayOrDash($item['doc_number'] ?? null)); ?>"><?php echo e(ksDisplayOrDash($item['doc_number'] ?? null)); ?></span></td>
                                                <td><span class="cell-ellipsis" title="<?php echo e($counterparty); ?>"><?php echo e($counterpartyShort); ?></span></td>
                                                <td class="col-status">
                                                    <?php if (($item['item_type'] ?? '') === 'LABOR_COST'): ?>
                                                        <span style="color:var(--text-muted);">—</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-<?php echo e($item['status']); ?>">
                                                            <?php 
                                                            $statusLabels = ['approved' => 'Zatwierdzone', 'pending' => 'Oczekujące', 'rejected' => 'Odrzucone'];
                                                            echo $statusLabels[$item['status']] ?? $item['status'];
                                                            ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="amount"><?php echo formatMoney($item['amount_net']); ?></td>
                                                <td class="amount"><?php echo formatMoney($item['amount_gross']); ?></td>
                                                <?php if ($isAdminUser): ?>
                                                <td style="text-align:center;padding:4px;">
                                                    <?php if (!empty($item['id']) && ($item['item_type'] ?? '') !== 'LABOR_COST'): ?>
                                                    <button type="button" class="action-btn action-btn-more" onclick="openRowDropdown(event, this)" data-item-id="<?php echo $item['id']; ?>">&#9660;</button>
                                                    <template class="row-dd-tpl">
                                                        <div class="row-dd-portal">
                                                            <?php if ($ksUrl): ?>
                                                            <a href="<?php echo $ksUrl; ?>"><?php echo $ksIsFaktura ? 'Otwórz fakturę kosztową' : ($ksIsWorkerExpense ? 'Edytuj wydatek pracownika' : 'Edytuj koszt'); ?></a>
                                                            <div class="row-dd-sep"></div>
                                                            <?php endif; ?>
                                                            <form method="POST" action="<?php echo $ksIsWorkerExpense ? url('finanse.wydatki.delete') : url('finanse.koszty-stale.delete'); ?>" style="margin:0;" onsubmit="return confirm('Usunąć ten koszt?');">
                                                                <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                                <?php if ($ksIsWorkerExpense): ?>
                                                                <input type="hidden" name="return_url" value="<?php echo e($_SERVER['REQUEST_URI'] ?? url('finanse.koszty-stale')); ?>">
                                                                <?php endif; ?>
                                                                <button type="submit" class="row-dd-danger">Usuń koszt</button>
                                                            </form>
                                                        </div>
                                                    </template>
                                                    <?php endif; ?>
                                                </td>
                                                <?php endif; ?>
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
    function toggleColors() {
        document.body.classList.toggle('no-colors');
        var btn = document.querySelector('.btn-color-mode');
        if (btn) btn.classList.toggle('active');
        var isColored = !document.body.classList.contains('no-colors');
        localStorage.setItem('ksColors', isColored ? '1' : '0');
        document.querySelectorAll('tbody tr[data-row-color]').forEach(function(tr) {
            if (isColored) { tr.style.setProperty('--row-bg', tr.dataset.rowColor); tr.style.setProperty('--row-border', tr.dataset.rowBorder); }
            else { tr.style.removeProperty('--row-bg'); tr.style.removeProperty('--row-border'); }
        });
    }

    function toggleDayGrouping() {
        var nv = document.querySelector('.normal-view');
        var gv = document.querySelector('.grouped-view');
        var btn = document.getElementById('btnDayGroup');
        if (gv.style.display === 'block') {
            gv.style.display = 'none';
            if (nv) nv.style.display = 'table';
            btn.classList.remove('active');
            localStorage.setItem('ksGrouped', '0');
        } else {
            gv.style.display = 'block';
            if (nv) nv.style.display = 'none';
            btn.classList.add('active');
            localStorage.setItem('ksGrouped', '1');
        }
    }

    function expandAllDays() { document.querySelectorAll('.day-group').forEach(function(g) { g.classList.remove('collapsed'); }); }
    function collapseAllDays() { document.querySelectorAll('.day-group').forEach(function(g) { g.classList.add('collapsed'); }); }

    document.addEventListener('DOMContentLoaded', function() {
        var colorsOn = localStorage.getItem('ksColors');
        if (colorsOn === '0') { document.body.classList.add('no-colors'); var b = document.querySelector('.btn-color-mode'); if (b) b.classList.remove('active'); }
        else { document.querySelectorAll('tbody tr[data-row-color]').forEach(function(tr) { tr.style.setProperty('--row-bg', tr.dataset.rowColor); tr.style.setProperty('--row-border', tr.dataset.rowBorder); }); }

        var grouped = localStorage.getItem('ksGrouped');
        var nv = document.querySelector('.normal-view');
        var gv = document.querySelector('.grouped-view');
        var btn = document.getElementById('btnDayGroup');
        if (grouped === '0') {
            if (gv) gv.style.display = 'none';
            if (nv) nv.style.display = 'table';
            if (btn) btn.classList.remove('active');
        } else {
            if (gv) gv.style.display = 'block';
            if (nv) nv.style.display = 'none';
            if (btn) btn.classList.add('active');
        }
    });

    function ksOnMonthYearChange() {
        var month = parseInt(document.getElementById('ksSelectMonth').value);
        var year  = parseInt(document.getElementById('ksSelectYear').value);
        if (!month) return;
        var lastDay = new Date(year, month, 0).getDate();
        var pad = function(n) { return String(n).padStart(2, '0'); };
        document.getElementById('ksInputDateFrom').value = year + '-' + pad(month) + '-01';
        document.getElementById('ksInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
        document.getElementById('ksFilterForm').submit();
    }

    /* Row dropdown — portal pattern */
    var activePortal = null;
    var activePortalBtn = null;

    function openRowDropdown(e, btn) {
        e.preventDefault();
        e.stopPropagation();

        if (activePortal && activePortalBtn === btn) {
            closeRowDropdown();
            return;
        }
        closeRowDropdown();

        // Szukaj template w rodzicu (td) lub obok przycisku
        var tpl = btn.parentElement.querySelector('.row-dd-tpl');
        if (!tpl) return;

        var clone = tpl.content.cloneNode(true);
        var portal = clone.firstElementChild;
        document.body.appendChild(portal);

        var rect = btn.getBoundingClientRect();
        portal.style.top = (rect.bottom + 4) + 'px';
        portal.style.left = Math.max(8, rect.right - 230) + 'px';

        requestAnimationFrame(function() {
            var pr = portal.getBoundingClientRect();
            if (pr.bottom > window.innerHeight - 8) {
                portal.style.top = Math.max(8, rect.top - pr.height - 4) + 'px';
            }
            if (pr.right > window.innerWidth - 8) {
                portal.style.left = Math.max(8, window.innerWidth - pr.width - 8) + 'px';
            }
        });

        activePortal = portal;
        activePortalBtn = btn;

        portal.addEventListener('click', function(ev) {
            var link = ev.target.closest('a');
            var button = ev.target.closest('button[type="submit"]');
            if (link || button) {
                setTimeout(closeRowDropdown, 50);
            }
        });
    }

    function closeRowDropdown() {
        if (activePortal) {
            activePortal.remove();
            activePortal = null;
            activePortalBtn = null;
        }
    }

    document.addEventListener('click', function(e) {
        if (activePortal && !activePortal.contains(e.target) && !e.target.closest('.action-btn-more')) {
            closeRowDropdown();
        }
    });
    </script>
</body>
</html>
