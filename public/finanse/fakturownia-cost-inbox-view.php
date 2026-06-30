<?php
/**
 * BRYGAD ERP - Szczegóły faktury kosztowej (Fakturownia/KSeF)
 * Widok: alokacje kosztu do projektów/etapów + historia statusów.
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once __DIR__ . '/_company-cost-categories.php';
require_once __DIR__ . '/_company-cost-category-combo.php';
require_once __DIR__ . '/_project-select.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$userId = $_SESSION['user_id'] ?? null;
$fakturowniaConfig = require dirname(__DIR__) . '/config/fakturownia.php';
$fakturowniaSubdomain = $fakturowniaConfig['subdomain'] ?? '';

$invoiceId = (int)($_GET['id'] ?? $_POST['invoice_id'] ?? 0);

function costInboxTableHasColumn(PDO $pdo, string $table, string $column): bool
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

$companyCostDictionary = ksCompanyCostLoadDictionary($pdo);
$companyCategoryLabels = ksCompanyCostCategoryLabels($companyCostDictionary);
$companySubcategoriesByCategory = ksCompanyCostSubcategoriesByCategory($companyCostDictionary);
$companySubcategoryHints = ksCompanyCostSubcategoryNames($companyCostDictionary);
$defaultCompanyCostCategoryKey = isset($companyCategoryLabels['inne'])
    ? 'inne'
    : (array_key_first($companyCategoryLabels) ?: '');
$hasAllocationCompanySubcategory = costInboxTableHasColumn($pdo, 'fakturownia_cost_allocations', 'company_cost_subcategory');
$hasFinanceItemsSubcategory = costInboxTableHasColumn($pdo, 'finance_items', 'subcategory');

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

// URL powrotu do listy z zachowanymi filtrami (fallback: goły inbox).
$backToInboxUrl = safeCostInboxReturnUrl(
    $_GET['return_to'] ?? '',
    returnToListUrl('cost-inbox', url('finanse.fakturownia-cost-inbox'))
);
if (session_status() === PHP_SESSION_ACTIVE) {
    if (!isset($_SESSION['list_state']) || !is_array($_SESSION['list_state'])) {
        $_SESSION['list_state'] = [];
    }
    $_SESSION['list_state']['cost-inbox'] = $backToInboxUrl;
}

if ($invoiceId <= 0) {
    header('Location: ' . returnToListUrlWithParams('cost-inbox', url('finanse.fakturownia-cost-inbox'), ['error' => 'not_found']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        header('Location: ' . url('finanse.fakturownia-cost-inbox.view', ['id' => $invoiceId, 'error' => 'csrf']));
        exit;
    }

    $action = $_POST['action'] ?? '';
    $invoice = fetchCostInvoice($pdo, $invoiceId);
    if (!$invoice) {
        header('Location: ' . returnToListUrlWithParams('cost-inbox', url('finanse.fakturownia-cost-inbox'), ['error' => 'not_found']));
        exit;
    }

    if ($action === 'add_allocation' || $action === 'add_allocations_batch') {
        if (in_array((string)$invoice['workflow_status'], ['accepted', 'rejected', 'archived'], true)) {
            header('Location: ' . url('finanse.fakturownia-cost-inbox.view', [
                'id' => $invoiceId,
                'error' => 'allocation',
                'msg' => 'Nie można modyfikować alokacji dla statusu końcowego.',
            ]));
            exit;
        }

        $rows = isset($_POST['rows']) && is_array($_POST['rows']) ? $_POST['rows'] : [];
        if (empty($rows)) {
            $rows = [[
                'project_id' => $_POST['project_id'] ?? 0,
                'cost_node_id' => $_POST['cost_node_id'] ?? 0,
                'allocation_mode' => $_POST['allocation_mode'] ?? 'amount',
                'allocation_value' => $_POST['allocation_value'] ?? 0,
                'description' => $_POST['description'] ?? '',
                'source_position_id' => isset($_POST['source_position_id']) ? $_POST['source_position_id'] : '',
            ]];
        }

        $totals = fetchAllocationTotals($pdo, $invoiceId);
        $isCostCorrection = (($invoice['document_kind'] ?? '') === 'correction');
        $invoiceGross = $isCostCorrection ? (float)($invoice['correction_effect_gross'] ?? 0) : (float)$invoice['amount_gross'];
        $invoiceNet = $isCostCorrection ? (float)($invoice['correction_effect_net'] ?? 0) : (float)$invoice['amount_net'];
        $allocatedNet = (float)$totals['allocated_net'];

        $parsedRows = [];
        $batchNetTotal = 0;
        $firstError = '';

        foreach ($rows as $ri => $row) {
            $allocationTarget = trim((string)($row['allocation_target'] ?? 'project'));
            $projectId = (int)($row['project_id'] ?? 0);
            $costNodeId = (int)($row['cost_node_id'] ?? 0);
            $companyCostCategory = trim((string)($row['company_cost_category'] ?? $defaultCompanyCostCategoryKey));
            $companyCostSubcategory = mb_substr(trim((string)($row['company_cost_subcategory'] ?? '')), 0, 120);
            $mode = trim((string)($row['allocation_mode'] ?? 'amount'));
            $value = (float)($row['allocation_value'] ?? 0);
            $description = trim((string)($row['description'] ?? ''));
            $sourcePositionId = (isset($row['source_position_id']) && $row['source_position_id'] !== '')
                ? (int)$row['source_position_id'] : null;

            if (!in_array($mode, ['amount', 'percent'], true)) { $firstError = 'Wiersz ' . ($ri+1) . ': nieprawidłowy tryb.'; break; }
            if ($mode === 'percent' && ($value <= 0 || $value > 100)) { $firstError = 'Wiersz ' . ($ri+1) . ': procent musi być w zakresie 0-100.'; break; }
            if ($mode === 'amount' && !$isCostCorrection && $value <= 0) { $firstError = 'Wiersz ' . ($ri+1) . ': wartość musi być > 0.'; break; }
            if ($mode === 'amount' && $isCostCorrection && abs($value) < 0.01) { $firstError = 'Wiersz ' . ($ri+1) . ': wpisz kwotę korekty różną od 0.'; break; }

            if ($allocationTarget === 'project') {
                if ($projectId <= 0) { $firstError = 'Wiersz ' . ($ri+1) . ': wybierz projekt.'; break; }
                $project = fetchProject($pdo, $projectId);
                if (!$project) { $firstError = 'Wiersz ' . ($ri+1) . ': projekt nie istnieje.'; break; }
                if ($costNodeId > 0) {
                    $node = fetchCostNodeForProject($pdo, $costNodeId, $projectId);
                    if (!$node) { $firstError = 'Wiersz ' . ($ri+1) . ': etap nie należy do projektu.'; break; }
                }
            } elseif ($allocationTarget === 'company') {
                if ($companyCostCategory === '' || !isset($companyCategoryLabels[$companyCostCategory])) {
                    $firstError = 'Wiersz ' . ($ri+1) . ': wybierz kategorię kosztu firmy.';
                    break;
                }
                $allowedSubcategories = $companySubcategoriesByCategory[$companyCostCategory] ?? [];
                if ($companyCostSubcategory !== '' && !in_array($companyCostSubcategory, $allowedSubcategories, true)) {
                    $firstError = 'Wiersz ' . ($ri+1) . ': podkategoria nie należy do wybranej kategorii.';
                    break;
                }
            } else {
                $firstError = 'Wiersz ' . ($ri+1) . ': nieprawidłowy cel alokacji.';
                break;
            }

            $allocationPercent = null;
            $amountNet = 0.0;
            if ($mode === 'percent') {
                $allocationPercent = round($value, 2);
                $amountNet = round($invoiceNet * ($allocationPercent / 100), 2);
            } else {
                $amountNet = round($value, 2);
                if (abs($invoiceNet) > 0.01) {
                    $allocationPercent = round(($amountNet / $invoiceNet) * 100, 2);
                }
            }

            if (!$isCostCorrection && $amountNet <= 0) { $firstError = 'Wiersz ' . ($ri+1) . ': wyliczona kwota = 0.'; break; }
            if ($isCostCorrection && abs($amountNet) < 0.01) { $firstError = 'Wiersz ' . ($ri+1) . ': wyliczona kwota korekty = 0.'; break; }

            $factor = (abs($invoiceNet) > 0.01) ? ($amountNet / $invoiceNet) : ((float)$allocationPercent / 100);
            $amountGross = round($invoiceGross * $factor, 2);
            $batchNetTotal += $amountNet;

            $parsedRows[] = [
                'allocation_target' => $allocationTarget,
                'project_id' => ($allocationTarget === 'company') ? null : $projectId,
                'cost_node_id' => ($allocationTarget === 'company') ? null : $costNodeId,
                'company_cost_category' => ($allocationTarget === 'company') ? $companyCostCategory : null,
                'company_cost_subcategory' => ($allocationTarget === 'company' && $companyCostSubcategory !== '') ? $companyCostSubcategory : null,
                'allocation_percent' => $allocationPercent,
                'amount_net' => $amountNet,
                'amount_gross' => $amountGross,
                'description' => $description,
                'source_position_id' => $sourcePositionId,
            ];
        }

        if ($firstError !== '') {
            header('Location: ' . url('finanse.fakturownia-cost-inbox.view', [
                'id' => $invoiceId, 'error' => 'allocation', 'msg' => $firstError,
            ]));
            exit;
        }

        try {
            $pdo->beginTransaction();

            $allocationSubcategoryColumn = $hasAllocationCompanySubcategory ? ', company_cost_subcategory' : '';
            $allocationSubcategoryValue = $hasAllocationCompanySubcategory ? ', :company_cost_subcategory' : '';
            $ins = $pdo->prepare(
                "INSERT INTO fakturownia_cost_allocations
                (cost_invoice_id, project_id, cost_node_id, company_cost_category{$allocationSubcategoryColumn}, allocation_percent, amount_net, amount_gross, description, source_position_id, created_by_user_id, created_at, updated_at)
                VALUES
                (:cost_invoice_id, :project_id, :cost_node_id, :company_cost_category{$allocationSubcategoryValue}, :allocation_percent, :amount_net, :amount_gross, :description, :source_position_id, :created_by_user_id, NOW(), NOW())"
            );

            $financeSubcategoryColumn = $hasFinanceItemsSubcategory ? ', subcategory' : '';
            $financeSubcategoryValue = $hasFinanceItemsSubcategory ? ', :subcategory' : '';
            $finIns = $pdo->prepare(
                "INSERT INTO finance_items
                (item_type, project_id, etap_id, company_name, title, description, doc_number, issue_date, amount_net, amount_gross, currency, category{$financeSubcategoryColumn}, status, created_by, created_at)
                VALUES
                ('FIXED_COST', NULL, NULL, :company_name, :title, :description, :doc_number, :issue_date, :amount_net, :amount_gross, 'PLN', :category{$financeSubcategoryValue}, 'approved', :created_by, NOW())"
            );

            foreach ($parsedRows as $pr) {
                $allocationParams = [
                    ':cost_invoice_id' => $invoiceId,
                    ':project_id' => $pr['project_id'],
                    ':cost_node_id' => ($pr['cost_node_id'] && $pr['cost_node_id'] > 0) ? $pr['cost_node_id'] : null,
                    ':company_cost_category' => $pr['company_cost_category'],
                    ':allocation_percent' => $pr['allocation_percent'],
                    ':amount_net' => $pr['amount_net'],
                    ':amount_gross' => $pr['amount_gross'],
                    ':description' => ($pr['description'] !== '' ? $pr['description'] : null),
                    ':source_position_id' => $pr['source_position_id'],
                    ':created_by_user_id' => $userId,
                ];
                if ($hasAllocationCompanySubcategory) {
                    $allocationParams[':company_cost_subcategory'] = $pr['company_cost_subcategory'];
                }
                $ins->execute($allocationParams);

                if ($pr['allocation_target'] === 'company') {
                    $invTitle = trim(($invoice['vendor_name'] ?? '') . ' — ' . ($invoice['invoice_number'] ?? ''));
                    $financeParams = [
                        ':company_name' => $invoice['vendor_name'] ?? null,
                        ':title' => mb_substr('[Faktura] ' . $invTitle, 0, 255),
                        ':description' => $pr['description'] ?: ('Alokacja z faktury #' . $invoiceId),
                        ':doc_number' => $invoice['invoice_number'] ?? null,
                        ':issue_date' => $invoice['issue_date'] ?? date('Y-m-d'),
                        ':amount_net' => $pr['amount_net'],
                        ':amount_gross' => $pr['amount_gross'],
                        ':category' => $pr['company_cost_category'],
                        ':created_by' => $userId,
                    ];
                    if ($hasFinanceItemsSubcategory) {
                        $financeParams[':subcategory'] = $pr['company_cost_subcategory'];
                    }
                    $finIns->execute($financeParams);
                }
            }

            if (($invoice['workflow_status'] ?? '') === 'new') {
                $upd = $pdo->prepare(
                    "UPDATE fakturownia_cost_invoices
                     SET workflow_status = 'assigned', updated_at = NOW()
                     WHERE id = :id"
                );
                $upd->execute([':id' => $invoiceId]);

                $hist = $pdo->prepare(
                    "INSERT INTO fakturownia_cost_status_history
                     (cost_invoice_id, from_status, to_status, changed_by_user_id, change_note, changed_at)
                     VALUES (:invoice_id, 'new', 'assigned', :uid, :note, NOW())"
                );
                $hist->execute([
                    ':invoice_id' => $invoiceId,
                    ':uid' => $userId,
                    ':note' => 'Automatycznie po dodaniu alokacji',
                ]);
            }

            $pdo->commit();

            logEvent(
                'Cost inbox: dodano ' . count($parsedRows) . ' alokacji (invoice_id=' . $invoiceId . ')',
                'INFO'
            );

            header('Location: ' . url('finanse.fakturownia-cost-inbox.view', ['id' => $invoiceId, 'success' => 'allocation_added']));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logEvent('Cost inbox allocation batch failed: ' . $e->getMessage(), 'ERROR');
            header('Location: ' . url('finanse.fakturownia-cost-inbox.view', ['id' => $invoiceId, 'error' => 'action_failed']));
            exit;
        }
    }

    if ($action === 'delete_allocation') {
        if (in_array((string)$invoice['workflow_status'], ['accepted', 'rejected', 'archived'], true)) {
            header('Location: ' . url('finanse.fakturownia-cost-inbox.view', [
                'id' => $invoiceId,
                'error' => 'allocation',
                'msg' => 'Nie można modyfikować alokacji dla statusu końcowego.',
            ]));
            exit;
        }

        $allocationId = (int)($_POST['allocation_id'] ?? 0);
        if ($allocationId <= 0) {
            header('Location: ' . url('finanse.fakturownia-cost-inbox.view', ['id' => $invoiceId, 'error' => 'action_failed']));
            exit;
        }

        try {
            $pdo->beginTransaction();

            $del = $pdo->prepare(
                "DELETE FROM fakturownia_cost_allocations
                 WHERE id = :id AND cost_invoice_id = :cost_invoice_id"
            );
            $del->execute([
                ':id' => $allocationId,
                ':cost_invoice_id' => $invoiceId,
            ]);

            if ($del->rowCount() === 0) {
                throw new RuntimeException('Nie znaleziono alokacji do usunięcia.');
            }

            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM fakturownia_cost_allocations WHERE cost_invoice_id = :cost_invoice_id"
            );
            $countStmt->execute([':cost_invoice_id' => $invoiceId]);
            $remainingCount = (int)$countStmt->fetchColumn();

            // Gdy usunięto ostatnią alokację i status był "assigned", cofamy do "new".
            if ($remainingCount === 0 && ($invoice['workflow_status'] ?? '') === 'assigned') {
                $upd = $pdo->prepare(
                    "UPDATE fakturownia_cost_invoices
                     SET workflow_status = 'new', updated_at = NOW()
                     WHERE id = :id"
                );
                $upd->execute([':id' => $invoiceId]);

                $hist = $pdo->prepare(
                    "INSERT INTO fakturownia_cost_status_history
                     (cost_invoice_id, from_status, to_status, changed_by_user_id, change_note, changed_at)
                     VALUES (:invoice_id, 'assigned', 'new', :uid, :note, NOW())"
                );
                $hist->execute([
                    ':invoice_id' => $invoiceId,
                    ':uid' => $userId,
                    ':note' => 'Automatycznie po usunięciu ostatniej alokacji',
                ]);
            }

            $pdo->commit();

            logEvent(
                'Cost inbox: usunięto alokację (invoice_id=' . $invoiceId
                . ', allocation_id=' . $allocationId . ')',
                'INFO'
            );

            header('Location: ' . url('finanse.fakturownia-cost-inbox.view', ['id' => $invoiceId, 'success' => 'allocation_deleted']));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logEvent('Cost inbox allocation delete failed: ' . $e->getMessage(), 'ERROR');
            header('Location: ' . url('finanse.fakturownia-cost-inbox.view', ['id' => $invoiceId, 'error' => 'action_failed']));
            exit;
        }
    }

    header('Location: ' . url('finanse.fakturownia-cost-inbox.view', ['id' => $invoiceId, 'error' => 'action_failed']));
    exit;
}

try {
    $invoice = fetchCostInvoice($pdo, $invoiceId);
} catch (Throwable $e) {
    error_log('Cost inbox view: fetchCostInvoice failed: ' . $e->getMessage());
    $invoice = null;
}
if (!$invoice) {
    header('Location: ' . returnToListUrlWithParams('cost-inbox', url('finanse.fakturownia-cost-inbox'), ['error' => 'not_found']));
    exit;
}

try {
    $allocations = fetchAllocations($pdo, $invoiceId);
    $allocationTotals = fetchAllocationTotals($pdo, $invoiceId);
    $history = fetchStatusHistory($pdo, $invoiceId);
    $projects = fetchProjects($pdo);
    $defaultInternalProject = fetchDefaultInternalProject($pdo);
} catch (Throwable $e) {
    error_log('Cost inbox view: data load failed: ' . $e->getMessage());
    $allocations = [];
    $allocationTotals = ['allocated_net' => 0, 'allocated_gross' => 0, 'allocated_percent' => 0, 'allocations_count' => 0];
    $history = [];
    $projects = isset($projects) ? $projects : [];
    $defaultInternalProject = isset($defaultInternalProject) ? $defaultInternalProject : null;
}

$invoicePositions = [];
try {
    $invoicePositions = fetchInvoicePositions($pdo, $invoice);
} catch (Throwable $e) {
    error_log('Cost inbox view: fetchInvoicePositions failed: ' . $e->getMessage());
}

$requestedPreset = trim((string)($_GET['preset'] ?? ''));
$requestedFocus = trim((string)($_GET['focus'] ?? ''));
$requestedMode = trim((string)($_GET['mode'] ?? ''));
$requestedTarget = trim((string)($_GET['target'] ?? ''));

$prefillProjectId = (int)($_GET['project_id'] ?? 0);
$allocationPresetMessage = '';
$prefillCompany = ($requestedTarget === 'company' || $requestedPreset === 'internal');

if ($prefillCompany) {
    $requestedFocus = 'allocation';
}

$isCostCorrection = (($invoice['document_kind'] ?? '') === 'correction');
$invoiceGross = $isCostCorrection ? (float)($invoice['correction_effect_gross'] ?? 0) : (float)$invoice['amount_gross'];
$invoiceNet = $isCostCorrection ? (float)($invoice['correction_effect_net'] ?? 0) : (float)$invoice['amount_net'];
$allocatedGross = (float)$allocationTotals['allocated_gross'];
$allocatedNet = (float)$allocationTotals['allocated_net'];
$remainingGross = round($invoiceGross - $allocatedGross, 2);
$remainingNet = round($invoiceNet - $allocatedNet, 2);
$allocatedPercent = abs($invoiceNet) > 0.01 ? round(($allocatedNet / $invoiceNet) * 100, 2) : 0;
$isWorkflowLocked = in_array((string)$invoice['workflow_status'], ['accepted', 'rejected', 'archived'], true);

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
$errorMsg = trim((string)($_GET['msg'] ?? ''));

function fetchCostInvoice(PDO $pdo, int $invoiceId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            ci.*,
            u.login AS decided_by_login,
            CONCAT(u.first_name, ' ', u.last_name) AS decided_by_worker_name
         FROM fakturownia_cost_invoices ci
         LEFT JOIN users u ON u.id = ci.decided_by_user_id
         WHERE ci.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchProject(PDO $pdo, int $projectId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, name, status
         FROM projects
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $projectId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchCostNodeForProject(PDO $pdo, int $costNodeId, int $projectId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, project_id, name
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

function fetchAllocationTotals(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(amount_net), 0) AS allocated_net,
            COALESCE(SUM(amount_gross), 0) AS allocated_gross,
            COALESCE(SUM(allocation_percent), 0) AS allocated_percent,
            COUNT(*) AS allocations_count
         FROM fakturownia_cost_allocations
         WHERE cost_invoice_id = :invoice_id"
    );
    $stmt->execute([':invoice_id' => $invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'allocated_net' => 0,
            'allocated_gross' => 0,
            'allocated_percent' => 0,
            'allocations_count' => 0,
        ];
    }
    return $row;
}

function fetchAllocations(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            a.*,
            p.name AS project_name,
            n.name AS cost_node_name,
            u.login AS created_by_login,
            CONCAT(u.first_name, ' ', u.last_name) AS created_by_worker_name
         FROM fakturownia_cost_allocations a
         LEFT JOIN projects p ON p.id = a.project_id
         LEFT JOIN project_cost_nodes n ON n.id = a.cost_node_id
         LEFT JOIN users u ON u.id = a.created_by_user_id
         WHERE a.cost_invoice_id = :invoice_id
         ORDER BY a.id ASC"
    );
    $stmt->execute([':invoice_id' => $invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchStatusHistory(PDO $pdo, int $invoiceId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            h.*,
            u.login AS changed_by_login,
            CONCAT(u.first_name, ' ', u.last_name) AS changed_by_worker_name
         FROM fakturownia_cost_status_history h
         LEFT JOIN users u ON u.id = h.changed_by_user_id
         WHERE h.cost_invoice_id = :invoice_id
         ORDER BY h.changed_at DESC, h.id DESC"
    );
    $stmt->execute([':invoice_id' => $invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetchProjects(PDO $pdo): array
{
    return spxFetchSelectableProjects($pdo);
}

function fetchDefaultInternalProject(PDO $pdo): ?array
{
    // Priorytet biznesowy: projekt id=1 (firmowy) jest domyslnym celem
    // alokacji "projekt wewnetrzny" — niezaleznie od statusu.
    $stmt = $pdo->query(
        "SELECT id, name, status, is_internal
         FROM projects
         WHERE id = 1
         LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    // Fallback: projekt oznaczony jako wewnetrzny.
    $stmt = $pdo->query(
        "SELECT id, name, status, is_internal
         FROM projects
         WHERE is_internal = 1
         ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, id ASC
         LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetchInvoicePositions(PDO $pdo, array $invoice)
{
    $payload = null;
    if (!empty($invoice['source_payload_json'])) {
        $payload = json_decode($invoice['source_payload_json'], true);
    }

    if (is_array($payload) && isset($payload['positions']) && is_array($payload['positions'])) {
        return $payload['positions'];
    }

    $fakturowniaId = isset($invoice['fakturownia_id']) ? (int)$invoice['fakturownia_id'] : 0;
    if ($fakturowniaId <= 0) {
        return [];
    }

    try {
        require_once dirname(__DIR__) . '/modules/fakturownia/FakturowniaClient.php';
        $client = new FakturowniaClient($pdo);
        $response = $client->get('/invoices/' . $fakturowniaId . '.json');
        $data = isset($response['data']) ? $response['data'] : [];

        if (!is_array($data) || !isset($data['positions']) || !is_array($data['positions'])) {
            return [];
        }

        $positions = $data['positions'];

        if (is_array($payload)) {
            $payload['positions'] = $positions;
        } else {
            $payload = $data;
        }
        unset($payload['api_token'], $payload['token']);
        $newJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $upd = $pdo->prepare(
            "UPDATE fakturownia_cost_invoices
             SET source_payload_json = :json, updated_at = NOW()
             WHERE id = :id"
        );
        $upd->execute([
            ':json' => $newJson,
            ':id' => (int)$invoice['id'],
        ]);

        return $positions;
    } catch (Throwable $e) {
        if (function_exists('logEvent')) {
            logEvent('fetchInvoicePositions error (invoice_id=' . $invoice['id'] . '): ' . $e->getMessage(), 'ERROR');
        }
        return [];
    }
}

function workflowLabelCostView($status)
{
    $labels = [
        'new' => 'Nowe',
        'assigned' => 'Przypisane',
        'accepted' => 'Zaakceptowane',
        'rejected' => 'Odrzucone',
        'archived' => 'Zarchiwizowane',
    ];
    return isset($labels[$status]) ? $labels[$status] : $status;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Alokacja faktury kosztowej</title>
    <style>
        :root {
            --primary:           #667eea;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --border:            #e5e7eb;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body); color: var(--text-main); padding-bottom: 40px;
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

        /* Hero */
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
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
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-primary {
            background: #2563eb;
            color: #fff;
        }
        .btn-primary:hover {
            background: #1d4ed8;
        }
        .btn-secondary {
            background: #6b7280;
            color: #fff;
        }
        .btn-secondary:hover {
            background: #4b5563;
        }
        .btn-danger {
            background: #dc2626;
            color: #fff;
        }
        .btn-danger:hover {
            background: #b91c1c;
        }
        .alert {
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-success {
            background: #dcfce7;
            color: #166534;
            border-left: 4px solid #22c55e;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        .alert-info {
            background: #e0f2fe;
            color: #0c4a6e;
            border-left: 4px solid #0ea5e9;
        }
        .grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 700;
            font-size: 15px;
        }
        .card-body {
            padding: 16px;
        }
        .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
        }
        .meta-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .meta-value {
            font-size: 15px;
            font-weight: 600;
            color: #111827;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(140px, 1fr));
            gap: 12px;
        }
        .kpi {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
        }
        .kpi-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .kpi-value {
            font-size: 18px;
            font-weight: 700;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(140px, 1fr));
            gap: 12px;
            align-items: end;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group label {
            font-size: 12px;
            color: #374151;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .form-group input,
        .form-group select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 14px;
            width: 100%;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border-bottom: 1px solid #e5e7eb;
            padding: 10px;
            font-size: 13px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6b7280;
            background: #f9fafb;
        }
        .tag {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }
        .tag-new { background: #fef3c7; color: #92400e; }
        .tag-assigned { background: #dbeafe; color: #1e40af; }
        .tag-accepted { background: #dcfce7; color: #166534; }
        .tag-rejected { background: #fee2e2; color: #991b1b; }
        .tag-archived { background: #e5e7eb; color: #374151; }
        .text-muted {
            color: #6b7280;
            font-size: 12px;
        }
        .no-data {
            padding: 20px;
            color: #6b7280;
            text-align: center;
        }
        @media (max-width: 1100px) {
            .grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr 1fr; }
        }
        .allocation-row .form-group select,
        .allocation-row .form-group input {
            height: 35px;
            min-height: 35px;
            padding: 8px 10px;
            font-size: 13px;
            line-height: 1.2;
            box-sizing: border-box;
        }
        .allocation-row .form-group label {
            font-size: 11px;
            margin-bottom: 2px;
        }
        #allocation-form {
            overflow: visible;
        }
        #allocation-form .card-body,
        #allocation-form #allocation-rows,
        #allocation-form .allocation-row,
        #allocation-form .row-company-combo-group {
            overflow: visible;
        }
        #allocation-form .spx-company-cost-combo {
            z-index: 20;
        }
        #allocation-form .spx-company-cost-combo-trigger {
            height: 35px;
            min-height: 35px;
            box-sizing: border-box;
            appearance: none;
            -webkit-appearance: none;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
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
        .btn-remove-row:hover { color: #991b1b !important; }
        .btn-quick-fill:hover { background: #f0f4ff !important; border-color: #667eea !important; color: #667eea !important; }
        @media (max-width: 1100px) {
            .allocation-row { grid-template-columns: 1fr 1fr 1fr !important; }
        }
        @media (max-width: 700px) {
            .container { padding: 16px; }
            .form-grid { grid-template-columns: 1fr; }
            .allocation-row { grid-template-columns: 1fr !important; }
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
                    <a href="<?php echo e($backToInboxUrl); ?>">Inbox kosztów</a> /
                    Szczegóły
                </div>
                <h1>Faktura: <?php echo e($invoice['invoice_number'] ?: ('#' . $invoice['id'])); ?></h1>
                <p>Szczegóły faktury kosztowej i alokacje</p>
            </div>
            <div class="hero-right">
                <div class="hero-nav">
                    <a href="<?php echo url('finanse.koszty-stale'); ?>">Wydatki firmowe</a>
                    <a href="<?php echo e($backToInboxUrl); ?>" class="active">Faktury kosztowe</a>
                </div>
                <div class="hero-actions">
                    <?php if (!empty($invoice['fakturownia_id']) && $fakturowniaSubdomain !== ''): ?>
                        <a href="https://<?php echo e($fakturowniaSubdomain); ?>.fakturownia.pl/invoices/<?php echo (int)$invoice['fakturownia_id']; ?>"
                           target="_blank" rel="noopener" class="btn-hero-primary">Otwórz w Fakturowni ↗</a>
                    <?php endif; ?>
                    <a href="<?php echo e($backToInboxUrl); ?>" class="btn-hero-secondary">← Wróć do inboxu</a>
                </div>
            </div>
        </div>

        <?php if ($success === 'allocation_added'): ?>
            <div class="alert alert-success">Alokacja została dodana.</div>
        <?php elseif ($success === 'allocation_deleted'): ?>
            <div class="alert alert-success">Alokacja została usunięta.</div>
        <?php elseif ($error === 'allocation' && $errorMsg !== ''): ?>
            <div class="alert alert-error"><?php echo e($errorMsg); ?></div>
        <?php elseif ($error === 'csrf'): ?>
            <div class="alert alert-error">Sesja wygasła lub token CSRF jest nieprawidłowy.</div>
        <?php elseif ($error === 'action_failed'): ?>
            <div class="alert alert-error">Operacja nie powiodła się. Sprawdź logi.</div>
        <?php endif; ?>

        <?php if ($allocationPresetMessage !== ''): ?>
            <div class="alert alert-info"><?php echo e($allocationPresetMessage); ?></div>
        <?php endif; ?>

        <?php if ($isWorkflowLocked): ?>
            <div class="alert alert-error">Ta faktura ma status końcowy. Alokacje są zablokowane do edycji.</div>
        <?php endif; ?>

        <div class="grid">
            <div class="card">
                <div class="card-header">Dane dokumentu</div>
                <div class="card-body">
                    <div class="meta-grid">
                        <div>
                            <div class="meta-label">Dostawca</div>
                            <div class="meta-value"><?php echo e($invoice['supplier_name']); ?></div>
                            <div class="text-muted">NIP: <?php echo e($invoice['supplier_nip'] ?: '-'); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">KSeF</div>
                            <div class="meta-value"><?php echo e($invoice['ksef_number'] ?: '-'); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Data wystawienia</div>
                            <div class="meta-value"><?php echo $invoice['issue_date'] ? formatDate($invoice['issue_date']) : '-'; ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Termin płatności</div>
                            <div class="meta-value"><?php echo $invoice['due_date'] ? formatDate($invoice['due_date']) : '-'; ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Status workflow</div>
                            <div class="meta-value">
                                <span class="tag tag-<?php echo e($invoice['workflow_status']); ?>"><?php echo e(workflowLabelCostView($invoice['workflow_status'])); ?></span>
                            </div>
                            <div class="text-muted">
                                Decyzja: <?php
                                    $decidedBy = $invoice['decided_by_worker_name'] ?: $invoice['decided_by_login'];
                                    echo e($decidedBy ?: '-');
                                ?>
                                <?php echo $invoice['decided_at'] ? ' • ' . e(date('d.m.Y H:i', strtotime($invoice['decided_at']))) : ''; ?>
                            </div>
                        </div>
                        <div>
                            <div class="meta-label">Kwota netto</div>
                            <div class="meta-value"><?php echo formatMoney($invoice['amount_net']); ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Kwota brutto</div>
                            <div class="meta-value"><?php echo formatMoney($invoice['amount_gross']); ?></div>
                        </div>
                        <?php if ($isCostCorrection): ?>
                            <div>
                                <div class="meta-label">Typ dokumentu</div>
                                <div class="meta-value"><span class="tag" style="background:#fef3c7;color:#92400e;">Korekta</span></div>
                                <div class="text-muted">
                                    <?php if (!empty($invoice['correction_of_fakturownia_id'])): ?>
                                        Do Fakturowni ID: <?php echo (int)$invoice['correction_of_fakturownia_id']; ?>
                                    <?php elseif (!empty($invoice['correction_of_cost_invoice_id'])): ?>
                                        Do lokalnej faktury ID: <?php echo (int)$invoice['correction_of_cost_invoice_id']; ?>
                                    <?php else: ?>
                                        Brak powiazania z faktura pierwotna
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="meta-label">Efekt korekty netto</div>
                                <div class="meta-value"><?php echo formatMoney($invoiceNet); ?></div>
                                <?php if (!empty($invoice['correction_reason_text'])): ?>
                                    <div class="text-muted"><?php echo e($invoice['correction_reason_text']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Podsumowanie alokacji</div>
                <div class="card-body">
                    <div class="kpi-grid">
                        <div class="kpi">
                            <div class="kpi-label">Przydzielono netto</div>
                            <div class="kpi-value"><?php echo formatMoney($allocatedNet); ?></div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">Pozostało netto</div>
                            <div class="kpi-value"><?php echo formatMoney($remainingNet); ?></div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">Przydzielono brutto</div>
                            <div class="kpi-value" style="font-size:15px;color:#6b7280;"><?php echo formatMoney($allocatedGross); ?></div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">Pozostało brutto</div>
                            <div class="kpi-value" style="font-size:15px;color:#6b7280;"><?php echo formatMoney($remainingGross); ?></div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">Pokrycie</div>
                            <div class="kpi-value"><?php echo number_format($allocatedPercent, 2, ',', ' '); ?>%</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">Liczba pozycji</div>
                            <div class="kpi-value"><?php echo (int)$allocationTotals['allocations_count']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($invoicePositions)): ?>
        <div class="card" id="positions-card">
            <div class="card-header">Pozycje faktury (<?php echo count($invoicePositions); ?>)</div>
            <div class="card-body" style="padding: 0; overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nazwa</th>
                            <th>Ilość</th>
                            <th>J.m.</th>
                            <th>Cena netto</th>
                            <th>VAT</th>
                            <th>Netto</th>
                            <th>Brutto</th>
                            <th>Alokowano</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoicePositions as $posIdx => $pos):
                            $posName = isset($pos['name']) ? trim((string)$pos['name']) : '-';
                            $posQty = isset($pos['quantity']) ? (float)$pos['quantity'] : 0;
                            $posUnit = isset($pos['quantity_unit']) ? trim((string)$pos['quantity_unit']) : 'szt';
                            $posPriceNet = isset($pos['total_price_net']) ? (float)$pos['total_price_net'] : 0;
                            $posPriceGross = isset($pos['total_price_gross']) ? (float)$pos['total_price_gross'] : 0;
                            $posUnitPrice = isset($pos['price_net']) ? (float)$pos['price_net'] : 0;
                            $posTax = isset($pos['tax']) ? $pos['tax'] : '-';

                            $posAllocatedNet = 0;
                            foreach ($allocations as $a) {
                                if (isset($a['source_position_id']) && (int)$a['source_position_id'] === $posIdx) {
                                    $posAllocatedNet += (float)$a['amount_net'];
                                }
                            }
                            $posRemaining = round($posPriceNet - $posAllocatedNet, 2);
                            $posFullyAllocated = ($posRemaining <= 0.01 && $posAllocatedNet > 0);
                        ?>
                        <tr style="<?php echo $posFullyAllocated ? 'background:#f0fdf4;' : ''; ?>">
                            <td><?php echo $posIdx + 1; ?></td>
                            <td><strong><?php echo e($posName); ?></strong></td>
                            <td style="text-align:right;"><?php echo number_format($posQty, 2, ',', ' '); ?></td>
                            <td><?php echo e($posUnit); ?></td>
                            <td style="text-align:right;"><?php echo formatMoney($posUnitPrice); ?></td>
                            <td><?php echo e($posTax); ?>%</td>
                            <td style="text-align:right;"><strong><?php echo formatMoney($posPriceNet); ?></strong></td>
                            <td style="text-align:right;color:#6b7280;"><?php echo formatMoney($posPriceGross); ?></td>
                            <td style="text-align:right;">
                                <?php if ($posAllocatedNet > 0): ?>
                                    <span style="color:<?php echo $posFullyAllocated ? '#16a34a' : '#d97706'; ?>; font-weight:600;">
                                        <?php echo formatMoney($posAllocatedNet); ?>
                                        <?php if ($posFullyAllocated): ?> ✓<?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="card" id="allocation-form">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <span>Dodaj alokacje</span>
            </div>
            <div class="card-body">

                <!-- Live progress bar -->
                <div id="alloc-progress-wrap" style="margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;">
                        <span style="font-size:13px;font-weight:600;color:#374151;">Pokrycie faktury</span>
                        <span id="alloc-progress-label" style="font-size:13px;font-weight:700;color:#667eea;">0%</span>
                    </div>
                    <div style="background:#e5e7eb;border-radius:6px;height:10px;overflow:hidden;position:relative;">
                        <div id="alloc-progress-bar-existing" style="position:absolute;left:0;top:0;height:100%;background:#22c55e;border-radius:6px;transition:width 0.3s;width:0;"></div>
                        <div id="alloc-progress-bar-new" style="position:absolute;top:0;height:100%;background:#667eea;border-radius:0 6px 6px 0;transition:left 0.3s, width 0.3s;width:0;left:0;opacity:0.7;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:12px;color:#6b7280;">
                        <span>Już alokowano: <strong style="color:#16a34a;" id="alloc-existing-amount"><?php echo formatMoney($allocatedNet); ?></strong></span>
                        <span>Nowe wiersze: <strong style="color:#667eea;" id="alloc-new-amount">0,00 zł</strong></span>
                        <span>Pozostało: <strong id="alloc-remaining-amount" style="color:#374151;"><?php echo formatMoney($remainingNet); ?></strong></span>
                    </div>
                </div>

                <form method="POST" action="" id="multi-allocation-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_allocations_batch">
                    <input type="hidden" name="invoice_id" value="<?php echo (int)$invoiceId; ?>">

                    <div id="allocation-rows">
                        <div class="allocation-row" data-row="0" style="display:grid;grid-template-columns:<?php echo !empty($invoicePositions) ? '1.2fr ' : ''; ?>0.8fr 1.2fr 0.8fr 1.35fr 0.7fr 0.8fr 1fr 40px;gap:8px;align-items:start;margin-bottom:10px;padding:12px;background:#f9fafb;border-radius:8px;border:2px solid transparent;" data-row-idx="0">
                            <?php if (!empty($invoicePositions)): ?>
                            <div class="form-group" style="gap:4px;">
                                <label style="font-size:11px;">Pozycja FV</label>
                                <select name="rows[0][source_position_id]" class="row-position" <?php echo $isWorkflowLocked ? 'disabled' : ''; ?>>
                                    <option value="">-- Cała FV --</option>
                                    <?php foreach ($invoicePositions as $posIdx => $pos):
                                        $posNetVal = isset($pos['total_price_net']) ? (float)$pos['total_price_net'] : 0;
                                        $posAllocVal = 0;
                                        foreach ($allocations as $a) {
                                            if (isset($a['source_position_id']) && (int)$a['source_position_id'] === $posIdx) {
                                                $posAllocVal += (float)$a['amount_net'];
                                            }
                                        }
                                        $posRemainingVal = round($posNetVal - $posAllocVal, 2);
                                        $posLabel = ($posIdx + 1) . '. '
                                            . mb_substr(trim((string)(isset($pos['name']) ? $pos['name'] : '-')), 0, 25)
                                            . ' | ' . formatMoney($posNetVal)
                                            . ($posAllocVal > 0 ? ' (wolne: ' . formatMoney($posRemainingVal) . ')' : '');
                                    ?>
                                        <option value="<?php echo $posIdx; ?>"
                                                data-net="<?php echo $posNetVal; ?>"
                                                data-remaining="<?php echo $posRemainingVal; ?>"
                                                data-name="<?php echo e(trim((string)(isset($pos['name']) ? $pos['name'] : ''))); ?>">
                                            <?php echo e($posLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="form-group" style="gap:4px;">
                                <label style="font-size:11px;">Cel</label>
                                <select name="rows[0][allocation_target]" class="row-target" onchange="toggleRowTarget(this)" <?php echo $isWorkflowLocked ? 'disabled' : ''; ?>>
                                    <option value="project" <?php echo !$prefillCompany ? 'selected' : ''; ?>>Do projektu</option>
                                    <option value="company" <?php echo $prefillCompany ? 'selected' : ''; ?>>Koszt firmy</option>
                                </select>
                            </div>
                            <div class="form-group row-project-group" style="gap:4px;<?php echo $prefillCompany ? 'display:none;' : ''; ?>">
                                <label style="font-size:11px;">Projekt</label>
                                <select name="rows[0][project_id]" class="row-project" <?php echo $isWorkflowLocked ? 'disabled' : ''; ?>>
                                    <?php echo spxRenderProjectOptions($projects, $prefillProjectId, '-- Projekt --'); ?>
                                </select>
                            </div>
                            <div class="form-group row-node-group" style="gap:4px;<?php echo $prefillCompany ? 'display:none;' : ''; ?>">
                                <label style="font-size:11px;">Etap</label>
                                <select name="rows[0][cost_node_id]" class="row-node" <?php echo $isWorkflowLocked ? 'disabled' : ''; ?>>
                                    <option value="">-- Bez --</option>
                                </select>
                            </div>
                            <div class="form-group row-company-combo-group" style="gap:4px;<?php echo $prefillCompany ? '' : 'display:none;'; ?>">
                                <label style="font-size:11px;">Kategoria firmy</label>
                                <?php
                                echo spxCompanyCostComboRender([
                                    'id_prefix' => 'allocation-row-0-company',
                                    'category_name' => 'rows[0][company_cost_category]',
                                    'subcategory_name' => 'rows[0][company_cost_subcategory]',
                                    'selected_category' => $defaultCompanyCostCategoryKey,
                                    'selected_subcategory' => '',
                                    'category_labels' => $companyCategoryLabels,
                                    'subcategories_by_category' => $companySubcategoriesByCategory,
                                    'all_subcategory_hints' => $companySubcategoryHints,
                                    'empty_subcategory_label' => '-- Brak --',
                                    'placeholder_label' => 'Wybierz kategorię i podkategorię',
                                    'help_text' => '',
                                    'disabled' => $isWorkflowLocked,
                                ]);
                                ?>
                            </div>
                            <div class="form-group" style="gap:4px;">
                                <label style="font-size:11px;">Tryb</label>
                                <select name="rows[0][allocation_mode]" class="row-mode" <?php echo $isWorkflowLocked ? 'disabled' : ''; ?>>
                                    <option value="amount">Kwota netto</option>
                                    <option value="percent">% faktury</option>
                                </select>
                            </div>
                            <div class="form-group" style="gap:4px;position:relative;">
                                <label style="font-size:11px;">Kwota / %</label>
                                <input type="number" name="rows[0][allocation_value]" class="row-value" <?php echo $isCostCorrection ? '' : 'min="0.01"'; ?> step="0.01" required <?php echo $isWorkflowLocked ? 'disabled' : ''; ?>>
                                <div class="row-value-hint" style="font-size:11px;color:#6b7280;margin-top:2px;min-height:15px;"></div>
                                <div class="row-quick-actions" style="display:flex;gap:4px;margin-top:2px;flex-wrap:wrap;">
                                    <button type="button" class="btn-quick-fill btn-fill-rest" style="font-size:10px;padding:2px 6px;border-radius:4px;border:1px solid #d1d5db;background:#fff;color:#374151;cursor:pointer;transition:all 0.15s;white-space:nowrap;" title="Wpisz całą pozostałą kwotę">Alokuj resztę</button>
                                    <button type="button" class="btn-quick-fill btn-fill-position" style="font-size:10px;padding:2px 6px;border-radius:4px;border:1px solid #d1d5db;background:#fff;color:#374151;cursor:pointer;transition:all 0.15s;display:none;white-space:nowrap;" title="Wpisz wolną kwotę z wybranej pozycji FV">Cała pozycja</button>
                                    <button type="button" class="btn-quick-fill btn-fill-half" style="font-size:10px;padding:2px 6px;border-radius:4px;border:1px solid #d1d5db;background:#fff;color:#374151;cursor:pointer;transition:all 0.15s;white-space:nowrap;" title="Wpisz 50% pozostałej kwoty">50%</button>
                                </div>
                            </div>
                            <div class="form-group" style="gap:4px;">
                                <label style="font-size:11px;">Opis</label>
                                <input type="text" name="rows[0][description]" class="row-desc" maxlength="255" placeholder="np. śrubki" <?php echo $isWorkflowLocked ? 'disabled' : ''; ?>>
                            </div>
                            <div style="padding-top:20px;">
                                <button type="button" class="btn-remove-row" title="Usuń wiersz" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:18px;padding:4px;" onclick="removeRow(this)">&times;</button>
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;gap:8px;margin-top:10px;align-items:center;flex-wrap:wrap;">
                        <button type="button" id="btn-add-row" class="btn btn-secondary" style="font-size:13px;padding:8px 14px;" <?php echo $isWorkflowLocked ? 'disabled' : ''; ?>>
                            + Dodaj wiersz
                        </button>
                        <button type="submit" id="btn-submit-alloc" class="btn btn-primary" style="font-size:13px;padding:8px 14px;" <?php echo $isWorkflowLocked ? 'disabled style="opacity:0.6;cursor:not-allowed;"' : ''; ?>>
                            Zapisz alokacje
                        </button>
                        <span class="text-muted" id="allocation_hint" style="margin-left:auto;">
                            Suma nowych wierszy: <strong id="rows_total">0,00 zł</strong>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Aktualne alokacje</div>
            <div class="card-body" style="padding: 0;">
                <?php if (count($allocations) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Pozycja FV</th>
                                <th>Projekt</th>
                                <th>Etap</th>
                                <th>%</th>
                                <th>Netto</th>
                                <th>Brutto</th>
                                <th>Opis</th>
                                <th>Utworzył</th>
                                <th>Data</th>
                                <th>Akcja</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allocations as $allocation): ?>
                                <tr>
                                    <td><?php
                                        if (isset($allocation['source_position_id']) && $allocation['source_position_id'] !== null) {
                                            $pi = (int)$allocation['source_position_id'];
                                            if (isset($invoicePositions[$pi]['name'])) {
                                                echo '<span title="' . e(trim((string)$invoicePositions[$pi]['name'])) . '">'
                                                    . ($pi + 1) . '. '
                                                    . e(mb_substr(trim((string)$invoicePositions[$pi]['name']), 0, 25))
                                                    . '</span>';
                                            } else {
                                                echo 'Poz. ' . ($pi + 1);
                                            }
                                        } else {
                                            echo '<span style="color:#9ca3af;">Cała FV</span>';
                                        }
                                    ?></td>
                                    <td><?php
                                        if (empty($allocation['project_id']) && !empty($allocation['company_cost_category'])) {
                                            echo '<span style="color:#667eea;font-weight:600;">Koszt firmy</span>';
                                        } else {
                                            echo e($allocation['project_name'] ? $allocation['project_name'] : ('#' . $allocation['project_id']));
                                        }
                                    ?></td>
                                    <td><?php
                                        if (empty($allocation['project_id']) && !empty($allocation['company_cost_category'])) {
                                            $categoryKey = (string)$allocation['company_cost_category'];
                                            $categoryLabel = $companyCategoryLabels[$categoryKey] ?? ucfirst(str_replace('_', ' ', $categoryKey));
                                            echo e($categoryLabel);
                                            if (!empty($allocation['company_cost_subcategory'])) {
                                                echo '<div style="font-size:11px;color:#6b7280;margin-top:2px;">' . e($allocation['company_cost_subcategory']) . '</div>';
                                            }
                                        } else {
                                            echo e($allocation['cost_node_name'] ? $allocation['cost_node_name'] : '-');
                                        }
                                    ?></td>
                                    <td><?php echo $allocation['allocation_percent'] !== null ? number_format((float)$allocation['allocation_percent'], 2, ',', ' ') . '%' : '-'; ?></td>
                                    <td><strong><?php echo formatMoney($allocation['amount_net']); ?></strong></td>
                                    <td style="color:#6b7280;"><?php echo formatMoney($allocation['amount_gross']); ?></td>
                                    <td><?php echo e($allocation['description'] ?: '-'); ?></td>
                                    <td><?php
                                        $creator = $allocation['created_by_worker_name'] ?: $allocation['created_by_login'];
                                        echo e($creator ?: '-');
                                    ?></td>
                                    <td><?php echo $allocation['created_at'] ? e(date('d.m.Y H:i', strtotime($allocation['created_at']))) : '-'; ?></td>
                                    <td>
                                        <form method="POST" action="" onsubmit="return confirm('Usunąć tę alokację?')">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_allocation">
                                            <input type="hidden" name="invoice_id" value="<?php echo (int)$invoiceId; ?>">
                                            <input type="hidden" name="allocation_id" value="<?php echo (int)$allocation['id']; ?>">
                                            <button type="submit" class="btn btn-danger" style="padding:6px 10px;font-size:12px;" <?php echo $isWorkflowLocked ? 'disabled' : ''; ?>>Usuń</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">Brak alokacji dla tej faktury.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Historia statusów workflow</div>
            <div class="card-body" style="padding: 0;">
                <?php if (count($history) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Zmiana</th>
                                <th>Użytkownik</th>
                                <th>Notatka</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $row): ?>
                                <tr>
                                    <td><?php echo $row['changed_at'] ? e(date('d.m.Y H:i', strtotime($row['changed_at']))) : '-'; ?></td>
                                    <td>
                                        <?php echo e(workflowLabelCostView((string)($row['from_status'] ?? ''))); ?>
                                        →
                                        <?php echo e(workflowLabelCostView((string)($row['to_status'] ?? ''))); ?>
                                    </td>
                                    <td><?php
                                        $changer = $row['changed_by_worker_name'] ?: $row['changed_by_login'];
                                        echo e($changer ?: '-');
                                    ?></td>
                                    <td><?php echo e($row['change_note'] ?: '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">Brak historii zmian statusów.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var INVOICE_NET = <?php echo json_encode((float)$invoiceNet); ?>;
        var INVOICE_GROSS = <?php echo json_encode((float)$invoiceGross); ?>;
        var IS_COST_CORRECTION = <?php echo $isCostCorrection ? 'true' : 'false'; ?>;
        var ALREADY_ALLOCATED_NET = <?php echo json_encode((float)$allocatedNet); ?>;
        var GLOBAL_REMAINING = <?php echo json_encode((float)$remainingNet); ?>;
        var rowCounter = 1;
        var nodeCache = {};

        function fmt(n) {
            return n.toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' zł';
        }
        function pct(n) {
            return n.toLocaleString('pl-PL', {minimumFractionDigits: 1, maximumFractionDigits: 1}) + '%';
        }

        function getRowNetValue(row) {
            var mode = row.querySelector('.row-mode').value;
            var val = parseFloat(row.querySelector('.row-value').value) || 0;
            if (mode === 'percent') {
                return Math.round(INVOICE_NET * (val / 100) * 100) / 100;
            }
            return Math.round(val * 100) / 100;
        }

        function getNewRowsTotal() {
            var total = 0;
            document.querySelectorAll('.allocation-row').forEach(function(row) {
                total += getRowNetValue(row);
            });
            return Math.round(total * 100) / 100;
        }

        function getRemainingForRow(row) {
            var newTotal = 0;
            document.querySelectorAll('.allocation-row').forEach(function(r) {
                if (r !== row) newTotal += getRowNetValue(r);
            });
            return Math.round((GLOBAL_REMAINING - newTotal) * 100) / 100;
        }

        function updateProgressBar() {
            var newTotal = getNewRowsTotal();
            var totalAllocated = ALREADY_ALLOCATED_NET + newTotal;
            var remaining = Math.round((INVOICE_NET - totalAllocated) * 100) / 100;
            var invoiceBase = Math.abs(INVOICE_NET);
            var existingPct = invoiceBase > 0 ? Math.min((Math.abs(ALREADY_ALLOCATED_NET) / invoiceBase) * 100, 100) : 0;
            var newPct = invoiceBase > 0 ? Math.min((Math.abs(newTotal) / invoiceBase) * 100, 100 - existingPct) : 0;
            var totalPct = Math.min(existingPct + newPct, 100);
            var isOver = IS_COST_CORRECTION
                ? (Math.abs(totalAllocated) - invoiceBase > 0.01)
                : (remaining < -0.01);

            document.getElementById('alloc-progress-bar-existing').style.width = existingPct.toFixed(1) + '%';
            document.getElementById('alloc-progress-bar-new').style.left = existingPct.toFixed(1) + '%';
            document.getElementById('alloc-progress-bar-new').style.width = Math.max(0, newPct).toFixed(1) + '%';
            document.getElementById('alloc-progress-bar-new').style.background = '#667eea';

            document.getElementById('alloc-progress-label').textContent = pct(totalPct);
            document.getElementById('alloc-progress-label').style.color = '#667eea';

            document.getElementById('alloc-new-amount').textContent = fmt(newTotal);

            var remainLabel = document.getElementById('alloc-remaining-amount');
            if (isOver) {
                var overAmount = IS_COST_CORRECTION
                    ? Math.abs(Math.abs(totalAllocated) - invoiceBase)
                    : Math.abs(remaining);
                remainLabel.textContent = '+' + fmt(overAmount) + ' ponad FV';
                remainLabel.style.color = '#f59e0b';
            } else if (Math.abs(remaining) < 0.01) {
                remainLabel.textContent = '0,00 zł (100%)';
                remainLabel.style.color = '#16a34a';
            } else {
                remainLabel.textContent = fmt(remaining);
                remainLabel.style.color = '#374151';
            }

            document.getElementById('rows_total').textContent = fmt(newTotal);
        }

        function updateRowHint(row) {
            var hint = row.querySelector('.row-value-hint');
            if (!hint) return;
            var mode = row.querySelector('.row-mode').value;
            var val = parseFloat(row.querySelector('.row-value').value) || 0;
            var netAmount = getRowNetValue(row);
            var remainForRow = getRemainingForRow(row);
            if ((mode === 'amount' && !IS_COST_CORRECTION && val <= 0)
                || (mode === 'amount' && IS_COST_CORRECTION && Math.abs(val) < 0.01)
                || (mode === 'percent' && val <= 0)) {
                hint.innerHTML = '';
                return;
            }

            var parts = [];
            if (mode === 'amount') {
                var percentOfInvoice = Math.abs(INVOICE_NET) > 0 ? (netAmount / INVOICE_NET) * 100 : 0;
                parts.push(pct(percentOfInvoice) + ' faktury');
            } else {
                parts.push(fmt(netAmount));
            }
            var afterThis = Math.round((remainForRow - netAmount) * 100) / 100;
            if (afterThis >= 0) {
                parts.push('zostanie ' + fmt(afterThis));
            } else {
                parts.push('<span style="color:#f59e0b;">' + fmt(Math.abs(afterThis)) + ' ponad FV</span>');
            }

            hint.innerHTML = parts.join(' · ');
        }

        function updateAllHints() {
            document.querySelectorAll('.allocation-row').forEach(updateRowHint);
            updateProgressBar();
        }

        function updatePositionButton(row) {
            var posSel = row.querySelector('.row-position');
            var btnPos = row.querySelector('.btn-fill-position');
            if (!btnPos) return;
            if (posSel && posSel.value !== '') {
                btnPos.style.display = 'inline-block';
            } else {
                btnPos.style.display = 'none';
            }
        }

        async function loadNodesForRow(projectSelect) {
            var row = projectSelect.closest('.allocation-row');
            var nodeSelect = row.querySelector('.row-node');
            nodeSelect.innerHTML = '<option value="">-- Bez --</option>';
            var pid = projectSelect.value;
            if (!pid) return;

            if (nodeCache[pid]) {
                nodeCache[pid].forEach(function(n) {
                    var opt = document.createElement('option');
                    opt.value = n.id;
                    opt.textContent = n.name;
                    nodeSelect.appendChild(opt);
                });
                return;
            }

            try {
                var res = await fetch('/api/get-cost-nodes.php?project_id=' + encodeURIComponent(pid));
                var data = await res.json();
                if (data && data.success && Array.isArray(data.nodes)) {
                    nodeCache[pid] = data.nodes;
                    data.nodes.forEach(function(n) {
                        var opt = document.createElement('option');
                        opt.value = n.id;
                        opt.textContent = n.name;
                        nodeSelect.appendChild(opt);
                    });
                }
            } catch (e) {}
        }

        function onPositionChangeRow(posSelect) {
            var row = posSelect.closest('.allocation-row');
            var valueInput = row.querySelector('.row-value');
            var descInput = row.querySelector('.row-desc');
            var modeSelect = row.querySelector('.row-mode');
            var opt = posSelect.options[posSelect.selectedIndex];

            updatePositionButton(row);

            if (!opt || opt.value === '') return;

            var posRemaining = parseFloat(opt.getAttribute('data-remaining')) || 0;
            var posName = opt.getAttribute('data-name') || '';

            if (modeSelect.value === 'amount' && posRemaining > 0) {
                valueInput.value = posRemaining.toFixed(2);
            }
            if (descInput && descInput.value === '') {
                descInput.value = posName;
            }
            updateAllHints();
        }

        function removeRow(btn) {
            var rows = document.querySelectorAll('.allocation-row');
            if (rows.length <= 1) return;
            btn.closest('.allocation-row').remove();
            updateAllHints();
        }

        function initRowEvents(row) {
            var projectSel = row.querySelector('.row-project');
            var posSel = row.querySelector('.row-position');
            var valInput = row.querySelector('.row-value');
            var modeSel = row.querySelector('.row-mode');
            var btnRest = row.querySelector('.btn-fill-rest');
            var btnPos = row.querySelector('.btn-fill-position');
            var btnHalf = row.querySelector('.btn-fill-half');
            if (projectSel) {
                projectSel.addEventListener('change', function() { loadNodesForRow(this); });
                if (projectSel.value) loadNodesForRow(projectSel);
            }
            if (window.spxCompanyCostCombo) {
                window.spxCompanyCostCombo.initAll(row);
            }
            if (posSel) {
                posSel.addEventListener('change', function() { onPositionChangeRow(this); });
                updatePositionButton(row);
            }
            if (valInput) {
                valInput.addEventListener('input', function() { updateAllHints(); });
            }
            if (modeSel) {
                modeSel.addEventListener('change', function() { updateAllHints(); });
            }

            if (btnRest) {
                btnRest.addEventListener('click', function() {
                    var remaining = getRemainingForRow(row);
                    if (remaining > 0) {
                        var modeVal = row.querySelector('.row-mode').value;
                        if (modeVal === 'percent') {
                            var pctVal = INVOICE_NET > 0 ? (remaining / INVOICE_NET) * 100 : 0;
                            row.querySelector('.row-value').value = pctVal.toFixed(2);
                        } else {
                            row.querySelector('.row-value').value = remaining.toFixed(2);
                        }
                        updateAllHints();
                    }
                });
            }

            if (btnPos) {
                btnPos.addEventListener('click', function() {
                    var ps = row.querySelector('.row-position');
                    if (!ps || ps.value === '') return;
                    var opt = ps.options[ps.selectedIndex];
                    var posRemaining = parseFloat(opt.getAttribute('data-remaining')) || 0;
                    if (posRemaining > 0) {
                        row.querySelector('.row-mode').value = 'amount';
                        row.querySelector('.row-value').value = posRemaining.toFixed(2);
                        updateAllHints();
                    }
                });
            }

            if (btnHalf) {
                btnHalf.addEventListener('click', function() {
                    var remaining = getRemainingForRow(row);
                    var half = Math.round((remaining / 2) * 100) / 100;
                    if (half > 0) {
                        row.querySelector('.row-mode').value = 'amount';
                        row.querySelector('.row-value').value = half.toFixed(2);
                        updateAllHints();
                    }
                });
            }
        }

        document.querySelectorAll('.allocation-row').forEach(function(row) {
            initRowEvents(row);
        });

        document.getElementById('btn-add-row').addEventListener('click', function() {
            var container = document.getElementById('allocation-rows');
            var firstRow = container.querySelector('.allocation-row');
            var newRow = firstRow.cloneNode(true);
            var idx = rowCounter++;

            newRow.setAttribute('data-row', idx);
            newRow.classList.remove('row-over-budget');
            newRow.querySelectorAll('[name]').forEach(function(el) {
                el.name = el.name.replace(/rows\[\d+\]/, 'rows[' + idx + ']');
                if (el.tagName === 'INPUT') el.value = '';
                if (el.tagName === 'SELECT' && el.classList.contains('row-node')) {
                    el.innerHTML = '<option value="">-- Bez --</option>';
                }
                if (el.tagName === 'SELECT' && el.classList.contains('row-position')) {
                    el.selectedIndex = 0;
                }
                if (el.tagName === 'SELECT' && el.classList.contains('row-target')) {
                    el.value = 'project';
                    el.setAttribute('onchange', 'toggleRowTarget(this)');
                }
                el.classList.remove('input-over');
            });
            var pg = newRow.querySelector('.row-project-group');
            var ng = newRow.querySelector('.row-node-group');
            var cg = newRow.querySelector('.row-company-combo-group');
            if (pg) pg.style.display = '';
            if (ng) ng.style.display = '';
            if (cg) cg.style.display = 'none';
            var hint = newRow.querySelector('.row-value-hint');
            if (hint) hint.innerHTML = '';
            var companyCategorySelect = newRow.querySelector('.js-company-cost-combo-category');
            var companySubcategorySelect = newRow.querySelector('.js-company-cost-combo-subcategory');
            var companyCombo = newRow.querySelector('.js-company-cost-combo');
            if (companyCategorySelect) {
                companyCategorySelect.selectedIndex = 0;
            }
            if (companySubcategorySelect) {
                companySubcategorySelect.value = '';
            }
            if (companyCombo) {
                companyCombo.classList.remove('open');
                companyCombo.dataset.comboBound = '';
            }

            container.appendChild(newRow);
            initRowEvents(newRow);
            updateAllHints();
        });

        document.getElementById('multi-allocation-form').addEventListener('submit', function(e) {
            var newTotal = getNewRowsTotal();
            if ((!IS_COST_CORRECTION && newTotal <= 0) || (IS_COST_CORRECTION && Math.abs(newTotal) < 0.01)) {
                e.preventDefault();
                alert(IS_COST_CORRECTION ? 'Wpisz kwotę alokacji korekty.' : 'Wpisz kwotę alokacji.');
                return false;
            }
        });

        updateProgressBar();

        var requestedFocus = <?php echo json_encode($requestedFocus); ?>;
        var requestedMode = <?php echo json_encode($requestedMode); ?>;
        if (requestedFocus === 'allocation' || requestedMode === 'allocate') {
            var allocationForm = document.getElementById('allocation-form');
            if (allocationForm) {
                allocationForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
        window.toggleRowTarget = function(sel) {
            var row = sel.closest('.allocation-row') || sel.closest('div');
            if (!row) row = sel.parentElement.parentElement;
            var pg = row.querySelector('.row-project-group');
            var ng = row.querySelector('.row-node-group');
            var cg = row.querySelector('.row-company-combo-group');
            var ps = row.querySelector('.row-project');
            if (sel.value === 'company') {
                if (pg) pg.style.display = 'none';
                if (ng) ng.style.display = 'none';
                if (cg) cg.style.display = '';
                if (ps) ps.removeAttribute('required');
            } else {
                if (pg) pg.style.display = '';
                if (ng) ng.style.display = '';
                if (cg) cg.style.display = 'none';
                if (ps) ps.setAttribute('required', '');
            }
        };
    })();
    </script>
</body>
</html>
