<?php
/**
 * BRYGAD ERP - Szczegóły / alokacja dokumentu kosztowego (source: documents)
 * Widok nowego flow z Centrum Faktur Kosztowych.
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once __DIR__ . '/_company-cost-categories.php';
require_once __DIR__ . '/_company-cost-category-combo.php';
require_once __DIR__ . '/_project-select.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$documentId = (int)($_GET['id'] ?? $_POST['document_id'] ?? 0);
$companyCostDictionary = ksCompanyCostLoadDictionary($pdo);
$companyCategoryLabels = ksCompanyCostCategoryLabels($companyCostDictionary);
$companySubcategoriesByCategory = ksCompanyCostSubcategoriesByCategory($companyCostDictionary);
$companySubcategoryHints = ksCompanyCostSubcategoryNames($companyCostDictionary);
$defaultCompanyCostCategoryKey = isset($companyCategoryLabels['inne'])
    ? 'inne'
    : (array_key_first($companyCategoryLabels) ?: '');
$hasFinanceItemsSubcategory = costInboxDocumentTableHasColumn($pdo, 'finance_items', 'subcategory');

function costInboxDocumentTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function safeCostInboxReturnUrl($candidate, string $fallback): string
{
    if (!is_string($candidate) || $candidate === '' || strpos($candidate, '//') === 0) {
        return $fallback;
    }

    $parts = parse_url($candidate);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }

    $path = $parts['path'] ?? '';
    if ($path !== '/finanse/fakturownia-cost-inbox.php') {
        return $fallback;
    }

    return $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
}

// URL powrotu do listy z zachowanymi filtrami (fallback: inbox z ?source=documents).
$inboxFallbackUrl = url('finanse.fakturownia-cost-inbox', ['source' => 'documents']);
$backToInboxUrl   = safeCostInboxReturnUrl(
    $_GET['return_to'] ?? '',
    returnToListUrl('cost-inbox', $inboxFallbackUrl)
);
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_SESSION['list_state']) || !is_array($_SESSION['list_state'])) {
        $_SESSION['list_state'] = [];
    }
    $_SESSION['list_state']['cost-inbox'] = $backToInboxUrl;
}

if ($documentId <= 0) {
    header('Location: ' . returnToListUrlWithParams('cost-inbox', $inboxFallbackUrl, ['error' => 'not_found']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'error' => 'csrf']));
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $document = fetchDocument($pdo, $documentId);
    if (!$document) {
        header('Location: ' . returnToListUrlWithParams('cost-inbox', $inboxFallbackUrl, ['error' => 'not_found']));
        exit;
    }

    $isEditable = ((string)$document['status'] === 'draft');
    if (!$isEditable) {
        header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', [
            'id' => $documentId,
            'error' => 'locked',
        ]));
        exit;
    }

    if ($action === 'add_allocation') {
        $allocationTarget = trim((string)($_POST['allocation_target'] ?? 'project'));
        $projectId = (int)($_POST['project_id'] ?? 0);
        $costNodeId = (int)($_POST['cost_node_id'] ?? 0);
        $mode = trim((string)($_POST['allocation_mode'] ?? 'amount'));
        $value = (float)($_POST['allocation_value'] ?? 0);
        $category = trim((string)($_POST['category'] ?? 'other'));
        $companyCostCategory = trim((string)($_POST['company_cost_category'] ?? $defaultCompanyCostCategoryKey));
        $companyCostSubcategory = mb_substr(trim((string)($_POST['company_cost_subcategory'] ?? '')), 0, 120);
        $description = trim((string)($_POST['description'] ?? ''));

        $allowedProjectCategories = ['material', 'equipment', 'subcontracting', 'transport', 'other'];

        if ($allocationTarget === 'project') {
            if ($projectId <= 0 || $value <= 0 || !in_array($mode, ['amount', 'percent'], true) || !in_array($category, $allowedProjectCategories, true)) {
                header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', [
                    'id' => $documentId, 'error' => 'validation',
                ]) . '#allocation-form');
                exit;
            }
            $project = fetchProject($pdo, $projectId);
            if (!$project) {
                header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', [
                    'id' => $documentId, 'error' => 'validation',
                ]) . '#allocation-form');
                exit;
            }
            if ($costNodeId > 0) {
                $node = fetchCostNodeForProject($pdo, $costNodeId, $projectId);
                if (!$node) {
                    header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', [
                        'id' => $documentId, 'error' => 'validation',
                    ]) . '#allocation-form');
                    exit;
                }
            }
        } elseif ($allocationTarget === 'company') {
            $allowedSubcategories = $companySubcategoriesByCategory[$companyCostCategory] ?? [];
            if (
                $value <= 0
                || !in_array($mode, ['amount', 'percent'], true)
                || $companyCostCategory === ''
                || !isset($companyCategoryLabels[$companyCostCategory])
                || ($companyCostSubcategory !== '' && !in_array($companyCostSubcategory, $allowedSubcategories, true))
            ) {
                header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', [
                    'id' => $documentId, 'error' => 'validation',
                ]) . '#allocation-form');
                exit;
            }
        } else {
            header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', [
                'id' => $documentId, 'error' => 'validation',
            ]) . '#allocation-form');
            exit;
        }

        $documentNet = (float)$document['amount_net'];
        $totals = fetchAllocationTotals($pdo, $documentId);
        $allocatedNet = (float)$totals['allocated_net'];
        $remainingNet = round($documentNet - $allocatedNet, 2);

        if ($mode === 'percent') {
            if ($value > 100 || $documentNet <= 0) {
                header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', [
                    'id' => $documentId, 'error' => 'validation',
                ]) . '#allocation-form');
                exit;
            }
            $amountNet = round($documentNet * ($value / 100), 2);
        } else {
            $amountNet = round($value, 2);
        }

        if ($amountNet <= 0 || $amountNet > ($remainingNet + 0.01)) {
            header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', [
                'id' => $documentId, 'error' => 'overalloc',
            ]) . '#allocation-form');
            exit;
        }

        if ($allocationTarget === 'company') {
            try {
                $docNumber = $document['doc_number'] ?? $document['number'] ?? null;
                $companyName = $document['supplier_name'] ?? null;
                $issueDate = $document['issue_date'] ?? $document['date'] ?? date('Y-m-d');
                $financeSubcategoryColumn = $hasFinanceItemsSubcategory ? ', subcategory' : '';
                $financeSubcategoryValue = $hasFinanceItemsSubcategory ? ', ?' : '';
                $stmt = $pdo->prepare("
                    INSERT INTO finance_items (
                        item_type, category{$financeSubcategoryColumn}, project_id, etap_id,
                        company_name, title, description, doc_number,
                        issue_date, amount_net, amount_gross,
                        currency, status, created_by, created_at
                    ) VALUES (
                        'FIXED_COST', ?{$financeSubcategoryValue}, NULL, NULL,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        'PLN', 'approved', ?, NOW()
                    )
                ");
                $title = '[Faktura] ' . ($companyName ?: $docNumber ?: 'Koszt firmy');
                $desc = 'Alokacja z dokumentu #' . $documentId . ($description !== '' ? ' | ' . $description : '');
                $currentUserId = $_SESSION['user_id'] ?? 1;
                $financeParams = [
                    $companyCostCategory,
                    $companyName,
                    $title,
                    $desc,
                    $docNumber,
                    $issueDate,
                    $amountNet,
                    $amountNet,
                    $currentUserId,
                ];
                if ($hasFinanceItemsSubcategory) {
                    array_splice($financeParams, 1, 0, [$companyCostSubcategory !== '' ? $companyCostSubcategory : null]);
                }
                $stmt->execute($financeParams);

                $companyCostLabel = $companyCategoryLabels[$companyCostCategory] ?? $companyCostCategory;
                $companyAllocationDescription = 'Koszt firmy: ' . $companyCostLabel
                    . ($companyCostSubcategory !== '' ? ' / ' . $companyCostSubcategory : '')
                    . ($description !== '' ? ' | ' . $description : '');

                if (documentAllocationsHasLegacyFlag($pdo)) {
                    $stmtDa = $pdo->prepare(
                        "INSERT INTO document_allocations
                        (document_id, project_id, cost_node_id, category, amount_net, description, is_legacy, created_at)
                        VALUES (:document_id, NULL, NULL, :category, :amount_net, :description, 1, NOW())"
                    );
                } else {
                    $stmtDa = $pdo->prepare(
                        "INSERT INTO document_allocations
                        (document_id, project_id, cost_node_id, category, amount_net, description, created_at)
                        VALUES (:document_id, NULL, NULL, :category, :amount_net, :description, NOW())"
                    );
                }
                $stmtDa->execute([
                    ':document_id' => $documentId,
                    ':category' => $companyCostCategory,
                    ':amount_net' => $amountNet,
                    ':description' => $companyAllocationDescription,
                ]);

                logEvent('Cost inbox: alokacja do kosztów firmy (document_id=' . $documentId . ', amount_net=' . $amountNet . ', category=' . $companyCostCategory . ', subcategory=' . $companyCostSubcategory . ')', 'INFO');
                header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'success' => 'allocation_added']));
                exit;
            } catch (Throwable $e) {
                logEvent('Cost inbox: company allocation failed: ' . $e->getMessage(), 'ERROR');
                header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'error' => 'action_failed']));
                exit;
            }
        }

        try {
            if (documentAllocationsHasLegacyFlag($pdo)) {
                $stmt = $pdo->prepare(
                    "INSERT INTO document_allocations
                    (document_id, project_id, cost_node_id, category, amount_net, description, is_legacy, created_at)
                    VALUES
                    (:document_id, :project_id, :cost_node_id, :category, :amount_net, :description, 1, NOW())"
                );
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO document_allocations
                    (document_id, project_id, cost_node_id, category, amount_net, description, created_at)
                    VALUES
                    (:document_id, :project_id, :cost_node_id, :category, :amount_net, :description, NOW())"
                );
            }

            $stmt->execute([
                ':document_id' => $documentId,
                ':project_id' => $projectId,
                ':cost_node_id' => $costNodeId > 0 ? $costNodeId : null,
                ':category' => $category,
                ':amount_net' => $amountNet,
                ':description' => $description !== '' ? $description : null,
            ]);

            logEvent('Cost inbox documents: dodano alokację (document_id=' . $documentId . ', amount_net=' . $amountNet . ')', 'INFO');
            header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'success' => 'allocation_added']));
            exit;
        } catch (Throwable $e) {
            logEvent('Cost inbox documents: add allocation failed: ' . $e->getMessage(), 'ERROR');
            header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'error' => 'action_failed']));
            exit;
        }
    }

    if ($action === 'delete_allocation') {
        $allocationId = (int)($_POST['allocation_id'] ?? 0);
        if ($allocationId <= 0) {
            header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'error' => 'validation']));
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM document_allocations WHERE id = :id AND document_id = :document_id");
            $stmt->execute([
                ':id' => $allocationId,
                ':document_id' => $documentId,
            ]);

            if ($stmt->rowCount() === 0) {
                header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'error' => 'not_found']));
                exit;
            }

            logEvent('Cost inbox documents: usunięto alokację (document_id=' . $documentId . ', allocation_id=' . $allocationId . ')', 'INFO');
            header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'success' => 'allocation_deleted']));
            exit;
        } catch (Throwable $e) {
            logEvent('Cost inbox documents: delete allocation failed: ' . $e->getMessage(), 'ERROR');
            header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'error' => 'action_failed']));
            exit;
        }
    }

    header('Location: ' . url('finanse.fakturownia-cost-inbox.document-view', ['id' => $documentId, 'error' => 'action_failed']));
    exit;
}

$document = fetchDocument($pdo, $documentId);
if (!$document) {
    header('Location: ' . returnToListUrlWithParams('cost-inbox', $inboxFallbackUrl, ['error' => 'not_found']));
    exit;
}

$allocations = fetchAllocations($pdo, $documentId);
$totals = fetchAllocationTotals($pdo, $documentId);
$projects = fetchProjects($pdo);
$isEditable = ((string)$document['status'] === 'draft');
$prefillCompany = trim((string)($_GET['target'] ?? '')) === 'company';

$documentNet = (float)$document['amount_net'];
$documentGross = (float)$document['amount_gross'];
$allocatedNet = (float)$totals['allocated_net'];
$remainingNet = round($documentNet - $allocatedNet, 2);

$success = trim((string)($_GET['success'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

function fetchDocument(PDO $pdo, int $documentId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            d.id,
            d.number,
            d.status,
            d.issue_date,
            d.due_date,
            d.sale_date,
            d.amount_net,
            d.amount_vat,
            d.amount_gross,
            d.currency,
            d.description,
            d.file_path,
            COALESCE(i.name, d.source_name, '') AS supplier_name,
            i.nip AS supplier_nip
         FROM documents d
         LEFT JOIN investors i ON i.id = d.vendor_id
         WHERE d.id = :id AND d.type = 'invoice_cost'
         LIMIT 1"
    );
    $stmt->execute([':id' => $documentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchProject(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchCostNodeForProject(PDO $pdo, int $costNodeId, int $projectId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, name
         FROM project_cost_nodes
         WHERE id = :id AND project_id = :project_id
         LIMIT 1"
    );
    $stmt->execute([
        ':id' => $costNodeId,
        ':project_id' => $projectId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchAllocations(PDO $pdo, int $documentId): array
{
    $hasLegacyFlag = documentAllocationsHasLegacyFlag($pdo);
    $legacySelect = $hasLegacyFlag ? ", da.is_legacy" : ", 1 AS is_legacy";

    $stmt = $pdo->prepare(
        "SELECT
            da.id,
            da.project_id,
            da.cost_node_id,
            da.category,
            da.amount_net,
            da.description,
            da.created_at
            {$legacySelect},
            p.name AS project_name,
            pcn.name AS cost_node_name
         FROM document_allocations da
         LEFT JOIN projects p ON p.id = da.project_id
         LEFT JOIN project_cost_nodes pcn ON pcn.id = da.cost_node_id
         WHERE da.document_id = :document_id
         ORDER BY da.created_at DESC, da.id DESC"
    );
    $stmt->execute([':document_id' => $documentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchAllocationTotals(PDO $pdo, int $documentId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS allocations_count,
            COALESCE(SUM(amount_net), 0) AS allocated_net
         FROM document_allocations
         WHERE document_id = :document_id"
    );
    $stmt->execute([':document_id' => $documentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['allocations_count' => 0, 'allocated_net' => 0];
    }
    return $row;
}

function fetchProjects(PDO $pdo): array
{
    return spxFetchSelectableProjects($pdo);
}

function documentAllocationsHasLegacyFlag(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM document_allocations LIKE 'is_legacy'");
    $cache = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    return $cache;
}

function documentStatusLabel(string $status): string
{
    $map = [
        'draft' => 'Roboczy',
        'approved' => 'Zatwierdzony',
        'archived' => 'Zarchiwizowany',
    ];
    return $map[$status] ?? $status;
}

function documentStatusClass(string $status): string
{
    $map = [
        'draft' => 'tag-new',
        'approved' => 'tag-accepted',
        'archived' => 'tag-archived',
    ];
    return $map[$status] ?? 'tag-new';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Alokacja dokumentu kosztowego</title>
    <style>
        :root { --primary: #667eea; --blue: #1e3a8a; --bg: #f5f7fa; --muted: #6b7280; --border: #e5e7eb; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: #1f2937; }
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
            background: linear-gradient(135deg, var(--blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 24px; font-weight: 700; letter-spacing: -0.3px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-right { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
        .hero-nav { display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
        .hero-nav a { padding: 7px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; color: #93c5fd; text-decoration: none; border: 1px solid rgba(255,255,255,0.15); background: rgba(255,255,255,0.06); transition: all 0.2s; white-space: nowrap; }
        .hero-nav a:hover { background: rgba(255,255,255,0.14); color: #ffffff; }
        .hero-nav a.active { background: #ffffff; color: #1e3a8a; border-color: #ffffff; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-secondary,
        .btn-hero-primary {
            font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
        }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.12); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.25); }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.2); color: #fff; }
        .grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); overflow: hidden; margin-bottom: 20px; }
        .card-header { padding: 14px 16px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 15px; }
        .card-body { padding: 16px; }
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
        .meta-label { font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        .meta-value { font-size: 15px; font-weight: 600; color: #111827; }
        .kpi-grid { display: grid; grid-template-columns: repeat(2, minmax(140px, 1fr)); gap: 12px; }
        .kpi { border: 1px solid var(--border); border-radius: 8px; padding: 12px; }
        .kpi-label { font-size: 12px; color: var(--muted); margin-bottom: 4px; }
        .kpi-value { font-size: 20px; font-weight: 700; }
        .alert { border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #22c55e; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .alert-info { background: #e0f2fe; color: #0c4a6e; border-left: 4px solid #0ea5e9; }
        .form-grid { display: grid; grid-template-columns: repeat(6, minmax(120px, 1fr)); gap: 12px; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 11px; color: #374151; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .form-group input, .form-group select {
            height: 40px; min-height: 40px; box-sizing: border-box;
            border: 1px solid #d1d5db; border-radius: 8px; padding: 10px 12px; font-size: 14px; width: 100%; line-height: 1.2;
        }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        #allocation-form { overflow: visible; }
        #allocation-form .card-body,
        #allocation-form .form-grid,
        #allocation-form .form-group,
        #allocation-form #fg-category-company {
            overflow: visible;
        }
        #allocation-form .spx-company-cost-combo {
            z-index: 20;
        }
        #allocation-form .spx-company-cost-combo-trigger {
            height: 40px;
            min-height: 40px;
            box-sizing: border-box;
            appearance: none;
            -webkit-appearance: none;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            font-weight: 400;
            background: #fff;
            color: #111827;
            line-height: 1.2;
        }
        #allocation-form .spx-company-cost-combo-trigger:hover {
            border-color: #2563eb;
            background: #fff;
            color: #111827;
        }
        #allocation-form .spx-company-cost-combo-trigger:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
        }
        #allocation-form .spx-company-cost-combo-panel {
            left: auto;
            right: 0;
            width: min(560px, calc(100vw - 40px));
            z-index: 80;
        }
        .btn { border: none; border-radius: 8px; padding: 10px 16px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid var(--border); padding: 10px; font-size: 13px; text-align: left; vertical-align: middle; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; color: var(--muted); background: #f9fafb; }
        .tag { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .tag-new { background: #fef3c7; color: #92400e; }
        .tag-accepted { background: #dcfce7; color: #166534; }
        .tag-archived { background: #e5e7eb; color: #374151; }
        .muted { color: var(--muted); font-size: 12px; }
        .no-data { padding: 18px; color: var(--muted); text-align: center; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 13px; }
        @media (max-width: 1100px) {
            .grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: repeat(2, minmax(130px, 1fr)); }
        }
        @media (max-width: 700px) {
            .container { padding: 16px; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
<?php echo spxCompanyCostComboRenderAssets(); ?>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

<div class="container">
    <div class="hero">
        <div>
            <div class="hero-breadcrumb">
                <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                <a href="<?php echo e($backToInboxUrl); ?>">Centrum Faktur Kosztowych</a> /
                Szczegóły
            </div>
            <h1>Dokument: <?php echo e($document['number'] ?: ('#' . $document['id'])); ?></h1>
            <p>Nowy widok alokacji dokumentu kosztowego</p>
        </div>
        <div class="hero-right">
            <div class="hero-nav">
                <a href="<?php echo url('finanse.koszty-stale'); ?>">Wydatki firmowe</a>
                <a href="<?php echo e($backToInboxUrl); ?>" class="active">Faktury kosztowe</a>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('dokumenty.edit', ['id' => $documentId]); ?>" class="btn-hero-primary">Stary widok dokumentu</a>
                <a href="<?php echo e($backToInboxUrl); ?>" class="btn-hero-secondary">← Wróć do inboxu</a>
            </div>
        </div>
    </div>

    <?php if ($success === 'allocation_added'): ?>
        <div class="alert alert-success">Alokacja została dodana.</div>
    <?php elseif ($success === 'allocation_deleted'): ?>
        <div class="alert alert-success">Alokacja została usunięta.</div>
    <?php elseif ($error === 'csrf'): ?>
        <div class="alert alert-error">Sesja wygasła. Odśwież stronę i spróbuj ponownie.</div>
    <?php elseif ($error === 'locked'): ?>
        <div class="alert alert-error">Dokument nie jest w statusie roboczym. Edycja alokacji jest zablokowana.</div>
    <?php elseif ($error === 'overalloc'): ?>
        <div class="alert alert-error">Kwota alokacji przekracza pozostałą kwotę netto dokumentu.</div>
    <?php elseif ($error === 'validation'): ?>
        <div class="alert alert-error">Uzupełnij poprawnie wymagane pola alokacji.</div>
    <?php elseif ($error === 'action_failed'): ?>
        <div class="alert alert-error">Operacja nie powiodła się. Sprawdź logi systemowe.</div>
    <?php endif; ?>

    <?php if (!$isEditable): ?>
        <div class="alert alert-info">Dokument ma status: <strong><?php echo e(documentStatusLabel((string)$document['status'])); ?></strong>. Dodawanie/usuwanie alokacji jest wyłączone.</div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <div class="card-header">Dane dokumentu</div>
            <div class="card-body">
                <div class="meta-grid">
                    <div>
                        <div class="meta-label">Dostawca</div>
                        <div class="meta-value"><?php echo e($document['supplier_name'] ?: '-'); ?></div>
                        <div class="muted">NIP: <?php echo e($document['supplier_nip'] ?: '-'); ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Status</div>
                        <div class="meta-value"><span class="tag <?php echo e(documentStatusClass((string)$document['status'])); ?>"><?php echo e(documentStatusLabel((string)$document['status'])); ?></span></div>
                    </div>
                    <div>
                        <div class="meta-label">Data wystawienia</div>
                        <div class="meta-value"><?php echo $document['issue_date'] ? formatDate($document['issue_date']) : '-'; ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Termin płatności</div>
                        <div class="meta-value"><?php echo $document['due_date'] ? formatDate($document['due_date']) : '-'; ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Netto</div>
                        <div class="meta-value"><?php echo number_format($documentNet, 2, ',', ' '); ?> <?php echo e($document['currency'] ?: 'PLN'); ?></div>
                    </div>
                    <div>
                        <div class="meta-label">Brutto</div>
                        <div class="meta-value"><?php echo number_format($documentGross, 2, ',', ' '); ?> <?php echo e($document['currency'] ?: 'PLN'); ?></div>
                    </div>
                </div>
                <?php if (!empty($document['description'])): ?>
                    <div class="muted" style="margin-top:14px;">Opis: <?php echo e($document['description']); ?></div>
                <?php endif; ?>
                <?php if (!empty($document['file_path'])): ?>
                    <div style="margin-top:10px;">
                        <a href="/<?php echo e(ltrim((string)$document['file_path'], '/')); ?>" target="_blank" rel="noopener" class="btn-hero-secondary" style="background:#f8fafc;color:#1e3a8a;border:1px solid #cbd5e1;">Pobierz plik dokumentu</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Podsumowanie alokacji</div>
            <div class="card-body">
                <div class="kpi-grid">
                    <div class="kpi">
                        <div class="kpi-label">Pozycji alokacji</div>
                        <div class="kpi-value"><?php echo (int)$totals['allocations_count']; ?></div>
                    </div>
                    <div class="kpi">
                        <div class="kpi-label">Zalokowane netto</div>
                        <div class="kpi-value"><?php echo number_format($allocatedNet, 2, ',', ' '); ?></div>
                    </div>
                    <div class="kpi">
                        <div class="kpi-label">Pozostało netto</div>
                        <div class="kpi-value" style="color: <?php echo $remainingNet <= 0.01 ? '#16a34a' : '#b45309'; ?>;"><?php echo number_format($remainingNet, 2, ',', ' '); ?></div>
                    </div>
                    <div class="kpi">
                        <div class="kpi-label">Pokrycie</div>
                        <div class="kpi-value">
                            <?php
                            $coverage = $documentNet > 0 ? min(100, round(($allocatedNet / $documentNet) * 100, 2)) : 0;
                            echo number_format($coverage, 2, ',', ' ') . '%';
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card" id="allocation-form">
        <div class="card-header">Dodaj alokację</div>
        <div class="card-body">
            <?php if ($isEditable): ?>
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_allocation">
                    <input type="hidden" name="document_id" value="<?php echo (int)$documentId; ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Cel alokacji</label>
                            <select name="allocation_target" id="allocation_target" onchange="toggleAllocationTarget(this.value)" required>
                                <option value="project" <?php echo !$prefillCompany ? 'selected' : ''; ?>>Do projektu</option>
                                <option value="company" <?php echo $prefillCompany ? 'selected' : ''; ?>>Koszt firmy</option>
                            </select>
                        </div>
                        <div class="form-group" id="fg-project" style="<?php echo $prefillCompany ? 'display:none;' : ''; ?>">
                            <label>Projekt</label>
                            <select name="project_id" id="project_id" <?php echo !$prefillCompany ? 'required' : ''; ?>>
                                <?php echo spxRenderProjectOptions($projects, null, 'Wybierz...'); ?>
                            </select>
                        </div>
                        <div class="form-group" id="fg-cost-node" style="<?php echo $prefillCompany ? 'display:none;' : ''; ?>">
                            <label>Etap</label>
                            <select name="cost_node_id" id="cost_node_id">
                                <option value="">Brak</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tryb</label>
                            <select name="allocation_mode" id="allocation_mode" required>
                                <option value="amount">Kwota</option>
                                <option value="percent">Procent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Wartość</label>
                            <input type="number" step="0.01" min="0.01" name="allocation_value" required>
                        </div>
                        <div class="form-group" id="fg-category-project" style="<?php echo $prefillCompany ? 'display:none;' : ''; ?>">
                            <label>Kategoria</label>
                            <select name="category" required>
                                <option value="material">Materiał</option>
                                <option value="equipment">Sprzęt</option>
                                <option value="subcontracting">Podwykonawstwo</option>
                                <option value="transport">Transport</option>
                                <option value="other" selected>Inne</option>
                            </select>
                        </div>
                        <div class="form-group" id="fg-category-company" style="<?php echo $prefillCompany ? '' : 'display:none;'; ?>">
                            <label>Kategoria i podkategoria kosztu firmy</label>
                            <?php
                            echo spxCompanyCostComboRender([
                                'id_prefix' => 'document-company-cost',
                                'category_name' => 'company_cost_category',
                                'subcategory_name' => 'company_cost_subcategory',
                                'category_select_id' => 'company_cost_category',
                                'subcategory_select_id' => 'company_cost_subcategory',
                                'selected_category' => $defaultCompanyCostCategoryKey,
                                'selected_subcategory' => '',
                                'category_labels' => $companyCategoryLabels,
                                'subcategories_by_category' => $companySubcategoriesByCategory,
                                'all_subcategory_hints' => $companySubcategoryHints,
                                'empty_subcategory_label' => 'Brak',
                                'placeholder_label' => 'Wybierz kategorię i podkategorię kosztu firmy',
                                'help_text' => '',
                            ]);
                            ?>
                        </div>
                        <div class="form-group">
                            <label>Opis</label>
                            <input type="text" name="description" maxlength="255" placeholder="Opcjonalny opis">
                        </div>
                    </div>
                    <div style="margin-top: 12px; display:flex; gap:8px; align-items:center;">
                        <button type="submit" class="btn btn-primary">Dodaj alokację</button>
                        <span class="muted">Pozostało do alokacji: <strong><?php echo number_format($remainingNet, 2, ',', ' '); ?> <?php echo e($document['currency'] ?: 'PLN'); ?></strong></span>
                    </div>
                </form>
            <?php else: ?>
                <div class="no-data">Dokument nie jest w statusie roboczym. Formularz alokacji jest zablokowany.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Aktualne alokacje</div>
        <div class="card-body" style="padding:0;">
            <?php if (!empty($allocations)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Projekt</th>
                            <th>Etap</th>
                            <th>Kategoria</th>
                            <th>Kwota netto</th>
                            <th>Opis</th>
                            <th>Data</th>
                            <th>Akcja</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allocations as $allocation): ?>
                            <?php
                            $allocationCategory = trim((string)($allocation['category'] ?? ''));
                            $allocationCategoryLabel = (empty($allocation['project_id']) && isset($companyCategoryLabels[$allocationCategory]))
                                ? $companyCategoryLabels[$allocationCategory]
                                : ($allocationCategory !== '' ? $allocationCategory : 'other');
                            ?>
                            <tr>
                                <td><?php echo e($allocation['project_name'] ?: (empty($allocation['project_id']) ? 'Koszt firmy' : ('#' . (int)$allocation['project_id']))); ?></td>
                                <td><?php echo e($allocation['cost_node_name'] ?: '—'); ?></td>
                                <td><?php echo e($allocationCategoryLabel); ?></td>
                                <td><strong><?php echo number_format((float)$allocation['amount_net'], 2, ',', ' '); ?> <?php echo e($document['currency'] ?: 'PLN'); ?></strong></td>
                                <td><?php echo e($allocation['description'] ?: '—'); ?></td>
                                <td><?php echo !empty($allocation['created_at']) ? e(date('d.m.Y H:i', strtotime($allocation['created_at']))) : '—'; ?></td>
                                <td>
                                    <?php if ($isEditable): ?>
                                        <form method="POST" action="" onsubmit="return confirm('Usunąć tę alokację?');" style="display:inline;">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_allocation">
                                            <input type="hidden" name="document_id" value="<?php echo (int)$documentId; ?>">
                                            <input type="hidden" name="allocation_id" value="<?php echo (int)$allocation['id']; ?>">
                                            <button type="submit" class="btn btn-danger" style="padding:6px 10px;font-size:12px;">Usuń</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">Brak alokacji dla tego dokumentu.</div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</div>

<script>
    (function () {
        var projectSelect = document.getElementById('project_id');
        var costNodeSelect = document.getElementById('cost_node_id');
        if (!projectSelect || !costNodeSelect) return;

        projectSelect.addEventListener('change', function () {
            var projectId = this.value || '';
            costNodeSelect.innerHTML = '<option value="">Brak</option>';
            if (!projectId) return;

            fetch('/api/get-cost-nodes.php?project_id=' + encodeURIComponent(projectId))
                .then(function (res) { return res.json(); })
                .then(function (rows) {
                    if (!Array.isArray(rows)) return;
                    rows.forEach(function (node) {
                        var option = document.createElement('option');
                        option.value = node.id;
                        option.textContent = node.name;
                        costNodeSelect.appendChild(option);
                    });
                })
                .catch(function () {});
        });
    })();

    function toggleAllocationTarget(val) {
        var fgProject = document.getElementById('fg-project');
        var fgCostNode = document.getElementById('fg-cost-node');
        var fgCatProject = document.getElementById('fg-category-project');
        var fgCatCompany = document.getElementById('fg-category-company');
        var projectSelect = document.getElementById('project_id');
        if (val === 'company') {
            fgProject.style.display = 'none';
            fgCostNode.style.display = 'none';
            fgCatProject.style.display = 'none';
            fgCatCompany.style.display = '';
            projectSelect.removeAttribute('required');
        } else {
            fgProject.style.display = '';
            fgCostNode.style.display = '';
            fgCatProject.style.display = '';
            fgCatCompany.style.display = 'none';
            projectSelect.setAttribute('required', '');
        }
    }
</script>
</body>
</html>
