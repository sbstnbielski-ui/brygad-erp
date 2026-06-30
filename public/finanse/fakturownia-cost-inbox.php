<?php
/**
 * BRYGAD ERP - Centrum Faktur Kosztowych
 * Scalone faktury: Fakturownia kosztowe, Legacy, Dokumenty kosztowe, Sprzedażowe.
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/modules/fakturownia/FakturowniaClient.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$fakturowniaConfig = require dirname(__DIR__) . '/config/fakturownia.php';
$fakturowniaSubdomain = $fakturowniaConfig['subdomain'] ?? '';

// Zapamiętaj stan listy (filtry, sort, strona) żeby detale i failure-redirecty
// mogły do niego wrócić. No-op przy POST — zapisujemy tylko GET-owe wejścia.
rememberListUrl('cost-inbox');

function canUseExecForSync(): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

function costInboxListHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function syncCostPaymentToFakturownia(PDO $pdo, array $invoice, string $paymentStatus): void
{
    if ($paymentStatus !== 'paid') {
        return;
    }

    $fakturowniaId = (int)($invoice['fakturownia_id'] ?? 0);
    if ($fakturowniaId <= 0) {
        throw new RuntimeException('Brak ID Fakturowni dla faktury kosztowej.');
    }

    $amountGross = round((float)($invoice['amount_gross'] ?? 0), 2);
    if ($amountGross <= 0) {
        throw new RuntimeException('Brak poprawnej kwoty brutto do oznaczenia płatności.');
    }

    $client = new FakturowniaClient($pdo);
    $client->post('/banking/payments.json', [
        'banking_payment' => [
            'name'       => 'Płatność kosztu ERP',
            'price'      => $amountGross,
            'invoice_id' => $fakturowniaId,
            'paid'       => true,
            'kind'       => 'api',
            'provider'   => 'transfer',
        ],
    ]);

    $result = $client->changeInvoiceStatus($fakturowniaId, 'paid');
    logEvent(
        "FK cost payment sync invoice_id={$invoice['id']} FK_ID={$fakturowniaId} amount={$amountGross} HTTP={$result['http_status']}",
        'INFO'
    );
}

function runManualCostInboxSyncWithInclude(string $period, string $script): array
{
    $oldArgv = $GLOBALS['argv'] ?? null;
    $GLOBALS['argv'] = [basename($script), $period];

    ob_start();
    $runtimeError = null;

    try {
        include $script;
    } catch (Throwable $e) {
        $runtimeError = $e;
    }

    $output = trim((string)ob_get_clean());

    if ($oldArgv !== null) {
        $GLOBALS['argv'] = $oldArgv;
    } else {
        unset($GLOBALS['argv']);
    }

    if ($runtimeError !== null) {
        return [
            'success' => false,
            'error' => 'Sync (include mode) failed: ' . $runtimeError->getMessage(),
            'output' => $output,
            'reason' => 'include_exception',
        ];
    }

    if (strpos($output, 'CRITICAL AUTH') !== false) {
        return [
            'success' => false,
            'error' => 'Błąd autoryzacji API (401).',
            'output' => $output,
            'reason' => 'auth_failure',
        ];
    }

    if (preg_match('/\] ERROR:/', $output)) {
        return [
            'success' => false,
            'error' => 'Sync zakończony błędem.',
            'output' => $output,
            'reason' => 'include_error',
        ];
    }

    return [
        'success' => true,
        'error' => null,
        'output' => $output,
        'reason' => 'include_ok',
    ];
}

function runManualCostInboxSync(string $period): array
{
    $script = realpath(dirname(__DIR__) . '/cron/fakturownia_cost_inbox_sync.php');
    if ($script === false) {
        return ['success' => false, 'error' => 'Brak pliku crona fakturownia_cost_inbox_sync.php', 'output' => '', 'reason' => 'script_missing'];
    }

    if (!canUseExecForSync()) {
        return runManualCostInboxSyncWithInclude($period, $script);
    }

    $phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBinary)
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($period)
        . ' 2>&1';

    $outputLines = [];
    $exitCode = 1;
    exec($cmd, $outputLines, $exitCode);

    return [
        'success' => $exitCode === 0,
        'error' => $exitCode === 0 ? null : ('CLI sync zakończony kodem ' . $exitCode),
        'output' => implode("\n", $outputLines),
        'reason' => $exitCode === 0 ? null : 'cli_exit_' . $exitCode,
    ];
}

// --- POST handling (only for Fakturownia records - status/note/sync) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify()) {
        header('Location: ' . listSelfRedirectUrl(['error' => 'csrf']));
        exit;
    }

    $action    = $_POST['action'] ?? '';
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);

    if ($action === 'sync_now') {
        try {
            $sync = runManualCostInboxSync('last_30_days');
            if (!$sync['success']) {
                logEvent('Manual cost inbox sync failed: ' . ($sync['error'] ?? 'unknown') . ' | ' . ($sync['output'] ?? ''), 'ERROR');
                $p = [
                    'error' => 'sync_failed',
                    'sync_reason' => (string)($sync['reason'] ?? 'unknown'),
                ];
                header('Location: ' . listSelfRedirectUrl($p));
                exit;
            }

            $syncOutput = $sync['output'] ?? '';
            logEvent('Manual cost inbox sync OK: ' . $syncOutput, 'INFO');
            $_SESSION['_sync_debug_output'] = $syncOutput;
            $syncImported = 0;
            $syncUpdated = 0;
            $syncPages = 0;
            if (preg_match('/imported=(\d+)/', $syncOutput, $m)) {
                $syncImported = (int)$m[1];
            }
            if (preg_match('/updated=(\d+)/', $syncOutput, $m)) {
                $syncUpdated = (int)$m[1];
            }
            if (preg_match('/pages_fetched=(\d+)/', $syncOutput, $m)) {
                $syncPages = (int)$m[1];
            }
            $syncParams = [
                'success'       => 'synced',
                'sync_imported' => $syncImported,
                'sync_updated'  => $syncUpdated,
                'sync_pages'    => $syncPages,
                'sync_mode'     => $sync['reason'] ?? 'unknown',
            ];
            header('Location: ' . listSelfRedirectUrl($syncParams));
        } catch (Throwable $e) {
            error_log("Manual sync failed: " . $e->getMessage());
            $p = [
                'error' => 'sync_failed',
                'sync_reason' => 'exception',
            ];
            header('Location: ' . listSelfRedirectUrl($p));
        }
        exit;
    }

    if ($invoiceId > 0 && in_array($action, ['update_status', 'save_note', 'update_payment_status'])) {

        $current = $pdo->prepare("SELECT id, fakturownia_id, invoice_number, amount_gross, workflow_status, payment_status FROM fakturownia_cost_invoices WHERE id = ?");
        $current->execute([$invoiceId]);
        $row = $current->fetch();

        if ($row) {
            if ($action === 'update_status') {
                $newStatus = $_POST['workflow_status'] ?? '';
                $allowed   = ['new', 'assigned', 'accepted', 'rejected', 'archived'];

                if (in_array($newStatus, $allowed) && $newStatus !== $row['workflow_status']) {
                    $oldStatus = $row['workflow_status'];
                    $userId    = $_SESSION['user_id'] ?? null;
                    $note      = mb_substr(trim($_POST['owner_note'] ?? ''), 0, 500);

                    $isDecision     = in_array($newStatus, ['accepted', 'rejected', 'archived']);
                    $decidedClause  = $isDecision
                        ? "decided_by_user_id = :uid, decided_at = NOW(),"
                        : "";

                    try {
                        $pdo->beginTransaction();

                        $sql = "UPDATE fakturownia_cost_invoices
                                SET workflow_status = :status,
                                    $decidedClause
                                    owner_note = COALESCE(NULLIF(:note, ''), owner_note),
                                    updated_at = NOW()
                                WHERE id = :id";

                        $upd = $pdo->prepare($sql);
                        $bind = [
                            'status' => $newStatus,
                            'note'   => $note,
                            'id'     => $invoiceId,
                        ];
                        if ($isDecision) {
                            $bind['uid'] = $userId;
                        }
                        $upd->execute($bind);

                        $hist = $pdo->prepare(
                            "INSERT INTO fakturownia_cost_status_history
                             (cost_invoice_id, from_status, to_status, changed_by_user_id, change_note, changed_at)
                             VALUES (:invoice_id, :from, :to, :uid, :note, NOW())"
                        );
                        $hist->execute([
                            'invoice_id' => $invoiceId,
                            'from'       => $oldStatus,
                            'to'         => $newStatus,
                            'uid'        => $userId,
                            'note'       => $note ?: null,
                        ]);

                        $pdo->commit();

                        header('Location: ' . listSelfRedirectUrl(['success' => 'status_updated']));
                        exit;

                    } catch (\Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("Cost inbox status update failed: " . $e->getMessage());
                        header('Location: ' . listSelfRedirectUrl(['error' => 'action_failed']));
                        exit;
                    }
                }
            } elseif ($action === 'save_note') {
                $note = mb_substr(trim($_POST['owner_note'] ?? ''), 0, 500);

                $upd = $pdo->prepare(
                    "UPDATE fakturownia_cost_invoices
                     SET owner_note = :note, updated_at = NOW()
                     WHERE id = :id"
                );
                $upd->execute(['note' => $note ?: null, 'id' => $invoiceId]);

                header('Location: ' . listSelfRedirectUrl(['success' => 'note_saved']));
                exit;
            } elseif ($action === 'update_payment_status') {
                $newPaymentStatus = $_POST['payment_status'] ?? '';
                $allowedPaymentStatuses = ['unpaid', 'partial', 'paid', 'unknown'];

                if (in_array($newPaymentStatus, $allowedPaymentStatuses, true)
                    && $newPaymentStatus !== $row['payment_status']) {
                    try {
                        if ($newPaymentStatus === 'paid') {
                            syncCostPaymentToFakturownia($pdo, $row, $newPaymentStatus);
                        }

                        $upd = $pdo->prepare(
                            "UPDATE fakturownia_cost_invoices
                             SET payment_status = :payment_status, synced_at = NOW(), updated_at = NOW()
                             WHERE id = :id"
                        );
                        $upd->execute([
                            'payment_status' => $newPaymentStatus,
                            'id' => $invoiceId,
                        ]);

                        logEvent("Cost invoice ID:{$invoiceId} payment_status {$row['payment_status']} -> {$newPaymentStatus}", 'INFO');
                        header('Location: ' . listSelfRedirectUrl(['success' => 'payment_updated']));
                        exit;
                    } catch (Throwable $e) {
                        logEvent("Cost payment status sync failed invoice_id={$invoiceId}: " . $e->getMessage(), 'ERROR');
                        header('Location: ' . listSelfRedirectUrl(['error' => 'payment_sync_failed']));
                        exit;
                    }
                }
            }
        }
    }

    header('Location: ' . listSelfRedirectUrl(['error' => 'action_failed']));
    exit;
}

// --- GET filters ---
$workflow_filter = $_GET['workflow_status'] ?? 'all';
$date_from       = $_GET['date_from'] ?? '';
$date_to         = $_GET['date_to'] ?? '';
$search          = trim($_GET['search'] ?? '');
$supplier_filter = trim($_GET['supplier'] ?? '');
$alloc_filter    = $_GET['alloc'] ?? 'all';
$source_filter   = $_GET['source'] ?? 'all';          // all | fakturownia | legacy | documents
$payment_filter  = $_GET['payment_status'] ?? 'all';  // all | paid | partial | unpaid | unknown
if (!in_array($payment_filter, ['all', 'paid', 'partial', 'unpaid', 'unknown'], true)) {
    $payment_filter = 'all';
}

// Sorting
$sort  = $_GET['sort'] ?? 'issue_date';
$order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowed_sorts = [
    'invoice_number', 'supplier_name', 'issue_date', 'due_date',
    'amount_net', 'amount_gross', 'workflow_status', 'payment_status', 'allocated_net',
];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'issue_date';
}

// --- Month/Year helper ---
$fciYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($fciYear < 2020 || $fciYear > 2030) $fciYear = (int)date('Y');
$fciMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
if ($fciMonth >= 1 && $fciMonth <= 12 && !$date_from) {
    $date_from = sprintf('%04d-%02d-01', $fciYear, $fciMonth);
    $date_to   = date('Y-m-t', strtotime($date_from));
}

// =====================================================================
// UNIFIED QUERY: merge ALL invoice sources into one view
// =====================================================================
$CC = 'COLLATE utf8mb4_general_ci';
$costDocumentKindExpr = costInboxListHasColumn($pdo, 'fakturownia_cost_invoices', 'document_kind')
    ? 'ci.document_kind'
    : "'invoice'";

// 1) Fakturownia cost invoices
$sqlFakturownia = "
    SELECT
        CONCAT('FC-', ci.id) AS unified_id, ci.id AS raw_id,
        'fakturownia' $CC AS source, 'cost' $CC AS invoice_type,
        ci.invoice_number $CC AS invoice_number,
        ci.supplier_name $CC AS supplier_name,
        ci.supplier_nip $CC AS supplier_nip,
        ci.issue_date, ci.due_date,
        ci.amount_net, ci.amount_gross,
        ci.workflow_status $CC AS workflow_status,
        ci.payment_status $CC AS payment_status,
        {$costDocumentKindExpr} $CC AS document_kind,
        ci.owner_note $CC AS owner_note,
        ci.fakturownia_id,
        COALESCE(fa.allocated_net, 0) AS allocated_net,
        COALESCE(fa.allocations_count, 0) AS allocations_count
    FROM fakturownia_cost_invoices ci
    LEFT JOIN (
        SELECT cost_invoice_id, COUNT(*) AS allocations_count,
               COALESCE(SUM(amount_net), 0) AS allocated_net
        FROM fakturownia_cost_allocations GROUP BY cost_invoice_id
    ) fa ON fa.cost_invoice_id = ci.id
";

// 2) Legacy cost invoices
$sqlLegacy = "
    SELECT
        CONCAT('LC-', li.id) AS unified_id, li.id AS raw_id,
        'legacy' $CC AS source, 'cost' $CC AS invoice_type,
        li.number $CC AS invoice_number,
        li.contractor $CC AS supplier_name,
        NULL AS supplier_nip,
        li.date AS issue_date, NULL AS due_date,
        li.amount_gross AS amount_net, li.amount_gross,
        CASE li.status WHEN 'approved' THEN 'accepted' ELSE 'new' END $CC AS workflow_status,
        CAST(NULL AS CHAR) $CC AS payment_status,
        CAST(NULL AS CHAR) $CC AS document_kind,
        NULL AS owner_note, NULL AS fakturownia_id,
        COALESCE(ca.allocated_amount, 0) AS allocated_net,
        COALESCE(ca.allocations_count, 0) AS allocations_count
    FROM invoices li
    LEFT JOIN (
        SELECT invoice_id, COUNT(*) AS allocations_count,
               COALESCE(SUM(amount), 0) AS allocated_amount
        FROM cost_allocations GROUP BY invoice_id
    ) ca ON ca.invoice_id = li.id
";

// 3) Documents (cost invoices from document system)
$sqlDocuments = "
    SELECT
        CONCAT('DC-', d.id) AS unified_id, d.id AS raw_id,
        'documents' $CC AS source, 'cost' $CC AS invoice_type,
        d.number $CC AS invoice_number,
        COALESCE(iv.name, d.source_name, '') $CC AS supplier_name,
        iv.nip $CC AS supplier_nip,
        d.issue_date, d.due_date,
        d.amount_net, d.amount_gross,
        CASE d.status WHEN 'approved' THEN 'accepted' WHEN 'archived' THEN 'archived' ELSE 'new' END $CC AS workflow_status,
        CAST(NULL AS CHAR) $CC AS payment_status,
        CAST(NULL AS CHAR) $CC AS document_kind,
        d.description $CC AS owner_note, NULL AS fakturownia_id,
        COALESCE(da.allocated_net, 0) AS allocated_net,
        COALESCE(da.allocations_count, 0) AS allocations_count
    FROM documents d
    LEFT JOIN investors iv ON iv.id = d.vendor_id
    LEFT JOIN (
        SELECT document_id, COUNT(*) AS allocations_count,
               COALESCE(SUM(amount_net), 0) AS allocated_net
        FROM document_allocations GROUP BY document_id
    ) da ON da.document_id = d.id
    WHERE d.type = 'invoice_cost'
";

// Build parts array based on source filter
$unionParts = [];

if ($source_filter === 'all' || $source_filter === 'fakturownia') {
    $unionParts[] = $sqlFakturownia;
}
if ($source_filter === 'all' || $source_filter === 'legacy') {
    $unionParts[] = $sqlLegacy;
}
if ($source_filter === 'all' || $source_filter === 'documents') {
    $unionParts[] = $sqlDocuments;
}

if (empty($unionParts)) {
    $unionParts[] = $sqlFakturownia;
}
$sqlUnion = implode(" UNION ALL ", $unionParts);

// Build unified WHERE
$where  = " WHERE 1=1";
$params = [];

if ($workflow_filter !== 'all') {
    $where .= " AND u.workflow_status = :workflow_status";
    $params['workflow_status'] = $workflow_filter;
}

if ($payment_filter !== 'all') {
    $where .= " AND u.payment_status = :payment_status";
    $params['payment_status'] = $payment_filter;
}

if ($date_from !== '') {
    $where .= " AND u.issue_date >= :date_from";
    $params['date_from'] = $date_from;
}

if ($date_to !== '') {
    $where .= " AND u.issue_date <= :date_to";
    $params['date_to'] = $date_to;
}

if ($search !== '') {
    $where .= " AND (u.invoice_number LIKE :search OR u.supplier_name LIKE :search OR u.supplier_nip LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

if ($supplier_filter !== '') {
    $where .= " AND u.supplier_name LIKE :supplier_filter";
    $params['supplier_filter'] = '%' . $supplier_filter . '%';
}

if ($alloc_filter === 'assigned') {
    $where .= " AND u.allocated_net > 0";
} elseif ($alloc_filter === 'unassigned') {
    $where .= " AND u.allocated_net = 0";
}

// Sort map (columns from the UNION alias)
$sortMap = [
    'invoice_number'  => 'u.invoice_number',
    'supplier_name'   => 'u.supplier_name',
    'issue_date'      => 'u.issue_date',
    'due_date'        => 'u.due_date',
    'amount_net'      => 'u.amount_net',
    'amount_gross'    => 'u.amount_gross',
    'workflow_status' => 'u.workflow_status',
    'payment_status'  => "CASE u.payment_status WHEN 'unpaid' THEN 1 WHEN 'partial' THEN 2 WHEN 'unknown' THEN 3 WHEN 'paid' THEN 4 ELSE 5 END",
    'allocated_net'   => 'u.allocated_net',
];
$orderBy = $sortMap[$sort];

// Pagination
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));

$countSql = "SELECT COUNT(*) FROM ({$sqlUnion}) u {$where}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$mainSql = "SELECT u.* FROM ({$sqlUnion}) u
            {$where}
            ORDER BY {$orderBy} {$order}, u.unified_id DESC
            LIMIT {$perPage} OFFSET {$offset}";

$stmt = $pdo->prepare($mainSql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// --- KPI stats (filtered, not global) ---
$kpiSql = "SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN u.source = 'fakturownia' AND u.workflow_status = 'new' THEN 1 ELSE 0 END) AS count_new_fak,
    SUM(CASE WHEN u.allocated_net > 0 THEN 1 ELSE 0 END) AS count_assigned,
    SUM(CASE WHEN u.allocated_net = 0 THEN 1 ELSE 0 END) AS count_unassigned,
    COALESCE(SUM(u.amount_net), 0) AS total_value,
    COALESCE(SUM(CASE WHEN u.source = 'fakturownia' AND u.payment_status IN ('unpaid','partial','unknown') THEN u.amount_net ELSE 0 END), 0) AS unpaid_value
FROM ({$sqlUnion}) u
{$where}";

$kpiStmt = $pdo->prepare($kpiSql);
$kpiStmt->execute($params);
$stats = $kpiStmt->fetch();

$success   = $_GET['success'] ?? '';
$error     = $_GET['error'] ?? '';
$syncReason = trim((string)($_GET['sync_reason'] ?? ''));

$csrfToken = csrfToken();

// --- Group invoices by day for accordion view ---
$invoicesByDay = [];
foreach ($invoices as $inv) {
    $dayKey = $inv['issue_date'] ? $inv['issue_date'] : '0000-00-00';
    if (!isset($invoicesByDay[$dayKey])) {
        $invoicesByDay[$dayKey] = ['items' => [], 'count' => 0, 'total_value' => 0];
    }
    $invoicesByDay[$dayKey]['items'][] = $inv;
    $invoicesByDay[$dayKey]['count']++;
    $invoicesByDay[$dayKey]['total_value'] += (float)($inv['amount_net'] ?? 0);
}
krsort($invoicesByDay);

// --- Helpers ---
function sortLinkCost(string $column, string $currentSort, string $currentOrder, string $label): string
{
    $newOrder = ($currentSort === $column && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
    $p        = $_GET;
    $p['sort']  = $column;
    $p['order'] = $newOrder;
    unset($p['page']);
    $arrow = '';
    if ($currentSort === $column) {
        $arrow = $currentOrder === 'ASC' ? ' ↑' : ' ↓';
    }
    return '<a href="?' . http_build_query($p) . '" style="color:inherit;text-decoration:none;display:block;">'
        . $label . $arrow . '</a>';
}

function pageLink(int $targetPage): string
{
    $p = $_GET;
    $p['page'] = $targetPage;
    return '?' . http_build_query($p);
}

function getRowColorCost(int $index): array
{
    $palette = [
        ['#e8f4fd', '#5b9bd5'],
        ['#e8f5e9', '#66bb6a'],
        ['#f3e8fd', '#ab7bd4'],
        ['#fff8e1', '#f9a825'],
        ['#e0f7fa', '#26c6da'],
        ['#fce4ec', '#ef5350'],
        ['#e8eaf6', '#7986cb'],
        ['#f1f8e9', '#9ccc65'],
        ['#fff3e0', '#ffa726'],
        ['#e0f2f1', '#26a69a'],
        ['#f3e5f5', '#ba68c8'],
        ['#e1f5fe', '#29b6f6'],
    ];
    $c = $palette[$index % count($palette)];
    return ['hsl' => $c[0], 'border' => $c[1]];
}

function workflowLabel($status)
{
    $labels = [
        'new'      => 'Nowe',
        'assigned' => 'Przypisane',
        'accepted' => 'Zaakceptowane',
        'rejected' => 'Odrzucone',
        'archived' => 'Zarchiwizowane',
    ];
    return isset($labels[$status]) ? $labels[$status] : $status;
}

function paymentLabel($status)
{
    $labels = [
        'paid' => 'Opłacona',
        'partial' => 'Częściowo',
        'unpaid' => 'Nieopłacona',
        'unknown' => 'Nieznany',
    ];
    return isset($labels[$status]) ? $labels[$status] : $status;
}

function polishDayShort($dateStr)
{
    $map = ['Mon'=>'Pn','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Cz','Fri'=>'Pt','Sat'=>'So','Sun'=>'Nd'];
    $eng = date('D', strtotime($dateStr));
    return isset($map[$eng]) ? $map[$eng] : $eng;
}

function workflowBadgeClass($status)
{
    $map = [
        'new'      => 'badge-new',
        'assigned' => 'badge-assigned',
        'accepted' => 'badge-accepted',
        'rejected' => 'badge-rejected',
        'archived' => 'badge-archived',
    ];
    return $map[$status] ?? 'badge-new';
}

function paymentBadgeClass($status)
{
    $map = [
        'paid' => 'badge-paid',
        'partial' => 'badge-partial',
        'unpaid' => 'badge-unpaid',
        'unknown' => 'badge-unknown',
    ];
    return $map[$status] ?? 'badge-unknown';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Centrum Faktur Kosztowych</title>
    <style>
        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --bg-card:           #ffffff;
            --border:            #e5e7eb;
            --border-light:      #d1d5db;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
            --success:           #22c55e;
            --danger:            #ef4444;
            --warning:           #eab308;
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
        .hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; font-family: inherit; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }
        .source-switch { display: inline-flex; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 8px; overflow: hidden; margin-top: 10px; }
        .source-switch a { padding: 6px 14px; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.7); text-decoration: none; transition: all 0.15s; }
        .source-switch a:hover { background: rgba(255,255,255,0.15); color: #fff; }
        .source-switch a.active { background: rgba(255,255,255,0.22); color: #fff; }

        .btn {
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-block;
            white-space: nowrap;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-sm {
            padding: 8px 16px;
            font-size: 13px;
        }

        /* KPI */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            margin-bottom: 30px;
        }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 600px)  { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        .stat-card {
            background: white;
            padding: 18px 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
            text-decoration: none;
            display: block;
            color: inherit;
        }
        .stat-card:hover { border-color: var(--primary); transform: translateY(-2px); }
        .stat-card.active { border-color: var(--primary); background: #f0f4ff; }
        .stat-label { font-size: 12px; color: #666; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 26px; font-weight: 700; color: #667eea; }

        /* Tooltip na kafelkach - pojawia sie od razu po najechaniu */
        .stat-card[data-tooltip] { position: relative; overflow: visible; }
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

        /* Card */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* SPX FILTER SYSTEM */
        .spx-filter-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-wrap: nowrap;
        }
        .spx-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .spx-filter-group.fg-month   { flex: 1.2 1 0; }
        .spx-filter-group.fg-year    { flex: 0.7 1 0; }
        .spx-filter-group.fg-date    { flex: 1   1 0; }
        .spx-filter-group.fg-source  { flex: 0.95 1 0; }
        .spx-filter-group.fg-status  { flex: 1.05 1 0; }
        .spx-filter-group.fg-payment { flex: 1.05 1 0; }
        .spx-filter-group.fg-search  { flex: 2   1 0; }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"],
        .spx-filter-group input[type="text"] {
            padding: 0 8px;
            height: 38px;
            border: 1px solid var(--border-light);
            border-radius: 6px;
            font-size: 13px;
            background: white;
            font-family: inherit;
            transition: border-color 0.15s;
            width: 100%;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        @media (max-width: 1024px) { .spx-filter-bar { flex-wrap: wrap; } .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; } }
        @media (max-width: 768px) { .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; } .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; } .spx-filter-group select, .spx-filter-group input[type="date"] { height: 44px; font-size: 14px; } }
        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: white; border: 1px solid var(--border-light); border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; white-space: nowrap; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: var(--primary); color: var(--primary); }
        .spx-quick-btn.active { background: var(--primary); border-color: var(--primary); color: white; font-weight: 600; }

        /* Supplier autocomplete filter */
        .supplier-filter-bar {
            padding: 8px 20px;
            background: #f9fafb;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .supplier-filter-bar label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            white-space: nowrap;
        }
        .supplier-ac-wrap {
            position: relative;
            flex: 1;
            max-width: 400px;
        }
        .supplier-ac-wrap input[type="text"] {
            width: 100%;
            height: 34px;
            padding: 0 10px;
            border: 1px solid var(--border-light);
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
            transition: border-color 0.15s;
        }
        .supplier-ac-wrap input[type="text"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        .supplier-ac-list {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 240px;
            overflow-y: auto;
            background: white;
            border: 1px solid var(--primary);
            border-top: none;
            border-radius: 0 0 6px 6px;
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
            z-index: 100;
        }
        .supplier-ac-list .ac-item {
            padding: 8px 12px;
            font-size: 13px;
            cursor: pointer;
            transition: background 0.1s;
        }
        .supplier-ac-list .ac-item:hover,
        .supplier-ac-list .ac-item.ac-active {
            background: #e8f4fd;
            color: var(--primary);
        }
        .supplier-ac-list .ac-item strong {
            font-weight: 700;
        }
        .supplier-ac-list .ac-empty {
            padding: 10px 12px;
            font-size: 12px;
            color: var(--text-muted);
            font-style: italic;
        }
        .supplier-filter-bar .btn-clear-supplier {
            padding: 4px 10px;
            height: 34px;
            font-size: 12px;
            border-radius: 6px;
            border: 1px solid var(--border-light);
            background: white;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
        }
        .supplier-filter-bar .btn-clear-supplier:hover {
            border-color: var(--danger);
            color: var(--danger);
        }

        /* Table — ciemniejsze/czarne kreski (spójność z ERP) */
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f1f3f5; }
        th {
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            color: var(--text-muted);
            border: 1px solid var(--border);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        th a {
            cursor: pointer;
            user-select: none;
            transition: color 0.2s;
            color: inherit;
            text-decoration: none;
            display: block;
        }
        th a:hover { color: var(--primary); }
        td {
            padding: 10px 14px;
            border: 1px solid var(--border);
            font-size: 13px;
            vertical-align: middle;
        }

        body:not(.no-colors) tbody tr {
            background: var(--row-bg, #ffffff);
            border-left: 4px solid var(--row-border, transparent);
        }
        body:not(.no-colors) tbody tr:hover { filter: brightness(0.95); }
        body.no-colors tbody tr:nth-child(odd)  { background: #ffffff; border-left: 4px solid transparent; }
        body.no-colors tbody tr:nth-child(even) { background: #f8fafc; border-left: 4px solid transparent; }
        body.no-colors tbody tr:hover           { background: #e0f2fe; }

        .btn-color-mode {
            width: 38px; height: 38px; border-radius: 6px;
            border: 1px solid var(--border-light); background: white;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; padding: 0;
        }
        .btn-color-mode:hover { background: #f9fafb; border-color: var(--primary); }
        .btn-color-mode.active { background: linear-gradient(135deg, #fce7f3, #e0e7ff); border-color: #a78bfa; }
        .btn-color-mode svg { width: 18px; height: 18px; }

        /* Badge */
        .badge {
            display: inline-block; padding: 4px 12px; border-radius: 12px;
            font-size: 11px; font-weight: 600;
        }
        .badge-new      { background: #fff3cd; color: #856404; }
        .badge-assigned { background: #e7f3ff; color: #004080; }
        .badge-accepted { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .badge-archived { background: #e2e8f0; color: #475569; }
        .badge-source   { background: #f3f4f6; color: #6b7280; font-size: 10px; padding: 2px 8px; border-radius: 8px; }
        .badge-paid     { background: #d4edda; color: #155724; }
        .badge-partial  { background: #fff3cd; color: #856404; }
        .badge-unpaid   { background: #f8d7da; color: #721c24; }
        .badge-unknown  { background: #e2e8f0; color: #475569; }

        /* Action buttons & inline forms */
        .action-buttons { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; }
        .action-select {
            min-width: 180px; height: 28px;
            border: 1px solid var(--border-light); border-radius: 4px;
            background: white; color: var(--text-main);
            font-size: 12px; padding: 0 8px; cursor: pointer;
        }
        .action-select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.12); }
        .action-btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 4px 10px; height: 26px; border-radius: 4px;
            text-decoration: none; font-size: 11px; font-weight: 600;
            transition: all 0.2s; border: 1px solid; background: white;
            white-space: nowrap; cursor: pointer;
        }
        .action-btn:hover { transform: translateY(-1px); }
        .action-btn-save   { color: #667eea; border-color: #667eea; }
        .action-btn-save:hover   { background: #667eea; color: white; }
        .action-btn-note   { color: #059669; border-color: #059669; }
        .action-btn-note:hover   { background: #059669; color: white; }
        .action-btn-view   { color: #2563eb; border-color: #2563eb; }
        .action-btn-view:hover   { background: #2563eb; color: white; }

        .inline-form { margin: 0; }
        .inline-select {
            padding: 3px 6px; height: 26px;
            border: 1px solid var(--border-light); border-radius: 4px;
            font-size: 11px; background: white; cursor: pointer;
        }
        .inline-select:focus { outline: none; border-color: var(--primary); }

        .note-cell { min-width: 140px; }
        .note-text {
            font-size: 12px; color: var(--text-muted); cursor: pointer;
            padding: 2px 4px; border-radius: 3px; display: inline-block;
            max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .note-text:hover { background: #f3f4f6; }
        .note-edit-area { display: none; flex-direction: column; gap: 4px; }
        .note-textarea {
            width: 100%; min-width: 160px; padding: 4px 6px;
            border: 1px solid var(--primary); border-radius: 4px;
            font-size: 12px; resize: vertical; min-height: 48px; font-family: inherit;
        }
        .note-textarea:focus { outline: none; }

        .no-data { padding: 60px 20px; text-align: center; color: #999; font-size: 16px; }

        .alert {
            padding: 15px 20px; border-radius: 8px; margin-bottom: 20px;
            overflow-wrap: anywhere; word-break: break-word;
        }
        .alert-success { background: #d4edda; border-left: 4px solid #28a745; color: #155724; }
        .alert-error   { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }

        .pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 4px; padding: 16px 20px; border-top: 1px solid var(--border-light);
        }
        .pagination a,
        .pagination span {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 34px; height: 34px; padding: 0 10px;
            border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none;
            transition: all 0.2s;
        }
        .pagination a { color: var(--text-muted); border: 1px solid var(--border-light); background: white; }
        .pagination a:hover { border-color: var(--primary); color: var(--primary); }
        .pagination .current { background: var(--primary); color: white; border: 1px solid var(--primary); }
        .pagination .disabled { color: #d1d5db; border: 1px solid #e5e7eb; cursor: default; pointer-events: none; }
        .pagination .page-info { color: var(--text-muted); font-size: 12px; margin: 0 8px; border: none; min-width: auto; }

        .footer { text-align: center; padding: 20px; color: #999; font-size: 13px; }

        .source-tag { font-size: 10px; color: #9ca3af; display: block; margin-top: 2px; }
        .invoice-number-cell { max-width: 150px; width: 150px; }
        .supplier-cell { max-width: 210px; width: 210px; }
        .invoice-main,
        .supplier-main {
            display: block;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .money-cell { white-space: nowrap; }

        /* Toggle buttons in controls bar */
        .spx-controls-right { display: flex; gap: 8px; align-items: center; }
        .btn-group-mode {
            width: 38px; height: 38px; border-radius: 6px;
            border: 1px solid var(--border-light); background: white;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; padding: 0;
        }
        .btn-group-mode:hover { background: #f9fafb; border-color: var(--primary); }
        .btn-group-mode.active { background: linear-gradient(135deg, #667eea, #764ba2); border-color: #764ba2; }
        .btn-group-mode.active svg { stroke: white; }
        .btn-group-mode svg { width: 18px; height: 18px; }
        .spx-toggle-group { display: flex; gap: 2px; }
        .spx-btn-toggle {
            padding: 0 10px; height: 28px;
            background: white; border: 1px solid var(--border-light); border-radius: 5px;
            font-size: 11px; font-weight: 600; color: #374151;
            cursor: pointer; transition: all 0.15s;
        }
        .spx-btn-toggle:hover { border-color: var(--primary); color: var(--primary); }
        .spx-separator { width: 1px; height: 22px; background: var(--border-light); }

        /* Day groups (grouped view) */
        .grouped-view { display: none; padding: 16px 20px; }
        .day-group {
            background: white; border: 1px solid var(--border);
            border-radius: 10px; margin-bottom: 12px; overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04); transition: all 0.3s ease;
        }
        .day-group.collapsed { margin-bottom: 8px; box-shadow: none; }
        .day-group.collapsed .day-content { display: none; }
        .day-header {
            background: #f8fafc; padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            cursor: pointer; user-select: none; transition: background 0.2s;
        }
        .day-header:hover { background: #f1f5f9; }
        .day-group.collapsed .day-header { border-bottom: none; border-radius: 10px; }
        .dh-left { display: flex; align-items: center; gap: 12px; }
        .dh-dayname {
            color: var(--text-muted); text-transform: uppercase; font-size: 11px; font-weight: 700;
            background: #e2e8f0; padding: 3px 8px; border-radius: 4px; min-width: 28px; text-align: center;
        }
        .dh-date { font-weight: 700; font-size: 15px; color: #334155; }
        .dh-right { display: flex; align-items: center; gap: 16px; font-size: 13px; color: var(--text-muted); }
        .dh-stats { display: flex; gap: 16px; }
        .dh-stat { display: flex; align-items: center; gap: 4px; }
        .dh-stat-value { font-weight: 600; color: var(--text-main); }
        .dh-arrow { font-size: 10px; color: var(--text-muted); transition: transform 0.2s; width: 16px; text-align: center; }
        .day-group.collapsed .dh-arrow { transform: rotate(-90deg); }
        .day-content { overflow: hidden; }
        .day-content table { margin: 0; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

    <div class="container">

        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    Koszty firmowe
                </div>
                <h1>Koszty firmowe</h1>
                <p>Wydatki firmowe i faktury kosztowe w jednym miejscu</p>
                <div class="source-switch">
                    <a href="<?php echo url('finanse.koszty-stale'); ?>">Razem</a>
                    <a href="<?php echo url('finanse.fakturownia-cost-inbox'); ?>" class="active">Faktury</a>
                    <a href="<?php echo url('finanse.koszty-stale'); ?>?source=manual">Wydatki</a>
                    <a href="<?php echo url('finanse.koszty-stale'); ?>?source=labor">Pracownicy</a>
                </div>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('dokumenty.create'); ?>" class="btn-hero-primary">+ Dodaj dokument</a>
                <form method="POST" action="" style="margin:0;">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="sync_now">
                    <button type="submit" class="btn-hero-secondary" id="btn-sync"
                            onclick="this.disabled=true;this.closest('form').submit();return false;">
                        Aktualizuj z Fakturowni
                    </button>
                </form>
                <a href="<?php echo url('finanse.fakturownia-archive'); ?>" class="btn-hero-secondary">Archiwum</a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success === 'status_updated'): ?>
            <div class="alert alert-success">Status faktury został zaktualizowany.</div>
        <?php elseif ($success === 'note_saved'): ?>
            <div class="alert alert-success">Notatka została zapisana.</div>
        <?php elseif ($success === 'payment_updated'): ?>
            <div class="alert alert-success">Status płatności został zapisany i zsynchronizowany, jeśli wymagała tego Fakturownia.</div>
        <?php elseif ($success === 'document_created'): ?>
            <div class="alert alert-success">Dokument kosztowy został dodany do centrum faktur.</div>
        <?php elseif ($success === 'document_approved'): ?>
            <div class="alert alert-success">Dokument kosztowy został zatwierdzony.</div>
        <?php elseif ($success === 'document_archived'): ?>
            <div class="alert alert-success">Dokument kosztowy został zarchiwizowany.</div>
        <?php elseif ($success === 'document_deleted'): ?>
            <div class="alert alert-success">Dokument kosztowy został usunięty.</div>
        <?php elseif ($success === 'synced'): ?>
            <?php
            $syncImported = (int)($_GET['sync_imported'] ?? 0);
            $syncUpdated  = (int)($_GET['sync_updated'] ?? 0);
            $syncPages    = (int)($_GET['sync_pages'] ?? 0);
            $syncMode     = $_GET['sync_mode'] ?? '';
            ?>
            <div class="alert alert-success">
                Synchronizacja z Fakturownia zakończona pomyślnie.
                <?php if ($syncImported > 0 || $syncUpdated > 0): ?>
                    Nowych: <strong><?php echo $syncImported; ?></strong>, zaktualizowanych: <strong><?php echo $syncUpdated; ?></strong>.
                <?php elseif ($syncPages === 0): ?>
                    API Fakturowni zwróciło 0 faktur kosztowych w ostatnich 30 dniach. Sprawdź czy na koncie Fakturowni są faktury kosztowe (wydatki).
                <?php else: ?>
                    Wszystkie faktury z API (<?php echo $syncPages; ?> stron) były już zsynchronizowane. Brak zmian.
                <?php endif; ?>
                <?php if ($syncMode === 'include_ok'): ?>
                    <small style="color:#6b7280;">(tryb: include)</small>
                <?php endif; ?>
                <?php if (!empty($_SESSION['_sync_debug_output'])): ?>
                    <details style="margin-top:8px;">
                        <summary style="cursor:pointer;font-size:12px;color:#6b7280;">Szczegóły synchronizacji</summary>
                        <pre style="margin-top:6px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:11px;white-space:pre-wrap;max-height:300px;overflow-y:auto;"><?php echo e($_SESSION['_sync_debug_output']); ?></pre>
                    </details>
                    <?php unset($_SESSION['_sync_debug_output']); ?>
                <?php endif; ?>
            </div>
        <?php elseif ($error === 'action_failed'): ?>
            <div class="alert alert-error">Wystąpił błąd podczas zapisywania. Sprawdź logi.</div>
        <?php elseif ($error === 'payment_sync_failed'): ?>
            <div class="alert alert-error">Nie udało się oznaczyć płatności w Fakturowni. Status w ERP nie został zmieniony.</div>
        <?php elseif ($error === 'sync_failed'): ?>
            <div class="alert alert-error">
                <?php if ($syncReason === 'exec_disabled'): ?>
                    Synchronizacja ręczna jest niedostępna: serwer ma wyłączoną funkcję exec().
                <?php elseif ($syncReason === 'script_missing'): ?>
                    Synchronizacja ręczna nie może wystartować: brak pliku crona na serwerze.
                <?php elseif (strpos($syncReason, 'cli_exit_') === 0): ?>
                    Synchronizacja zakończona błędem procesu CLI (<?php echo e($syncReason); ?>). Sprawdź logi.
                <?php elseif ($syncReason === 'auth_failure'): ?>
                    Synchronizacja nieudana: błąd autoryzacji API (401). Sprawdź token Fakturownia.
                <?php elseif ($syncReason === 'include_exception' || $syncReason === 'include_error'): ?>
                    Synchronizacja zakończona błędem w trybie awaryjnym serwera. Sprawdź logi.
                <?php else: ?>
                    Błąd synchronizacji z Fakturownia. Sprawdź logi.
                <?php endif; ?>
            </div>
        <?php elseif ($error === 'csrf'): ?>
            <div class="alert alert-error">Sesja wygasła lub nieprawidłowy token. Spróbuj ponownie.</div>
        <?php elseif ($error === 'not_found'): ?>
            <div class="alert alert-error">Nie znaleziono wskazanej faktury kosztowej.</div>
        <?php endif; ?>

        <!-- KPI Tiles -->
        <?php
        $kpiBase = $_GET;
        unset($kpiBase['alloc'], $kpiBase['page']);
        $kpiAllUrl        = '?' . http_build_query(array_merge($kpiBase, ['alloc' => 'all']));
        $kpiAssignedUrl   = '?' . http_build_query(array_merge($kpiBase, ['alloc' => 'assigned']));
        $kpiUnassignedUrl = '?' . http_build_query(array_merge($kpiBase, ['alloc' => 'unassigned']));
        ?>
        <div class="stats-grid">
            <a href="<?php echo $kpiAllUrl; ?>"
               class="stat-card <?php echo $alloc_filter === 'all' ? 'active' : ''; ?>"
               data-tooltip="Wszystkie faktury kosztowe widoczne po aktualnych filtrach.">
                <div class="stat-label">Wszystkie</div>
                <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
            </a>
            <a href="<?php echo $kpiUnassignedUrl; ?>"
               class="stat-card <?php echo $alloc_filter === 'unassigned' ? 'active' : ''; ?>"
               data-tooltip="Faktury, których jeszcze nie przypisano do żadnego projektu. Wymagają decyzji.">
                <div class="stat-label">Do rozdzielenia</div>
                <div class="stat-value"><?php echo (int)$stats['count_unassigned']; ?></div>
            </a>
            <a href="<?php echo $kpiAssignedUrl; ?>"
               class="stat-card <?php echo $alloc_filter === 'assigned' ? 'active' : ''; ?>"
               data-tooltip="Faktury z co najmniej jednym przypisaniem do projektu (choćby częściowym).">
                <div class="stat-label">Przypisane</div>
                <div class="stat-value"><?php echo (int)$stats['count_assigned']; ?></div>
            </a>
            <div class="stat-card"
                 data-tooltip="Świeżo zaciągnięte z Fakturowni, jeszcze nie tknięte. Do obejrzenia i rozdysponowania.">
                <div class="stat-label">Nowe z Fakturowni</div>
                <div class="stat-value"><?php echo (int)$stats['count_new_fak']; ?></div>
            </div>
            <div class="stat-card"
                 data-tooltip="Łączna wartość faktur kosztowych z listy (netto).">
                <div class="stat-label">Suma faktur</div>
                <div class="stat-value"><?php echo formatMoney($stats['total_value']); ?></div>
            </div>
            <div class="stat-card"
                 data-tooltip="Ile z tych faktur jest jeszcze nieopłacone. Liczone tylko dla faktur z Fakturowni — tylko tam znamy status płatności.">
                <div class="stat-label">Do zapłaty</div>
                <div class="stat-value"><?php echo formatMoney($stats['unpaid_value']); ?></div>
            </div>
        </div>

        <!-- Table with filters -->
        <div class="card">
            <?php
            $fciMonthNames = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];
            $fciYearRange = range((int)date('Y') - 3, (int)date('Y'));
            $fciActiveMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $fciYear, $m);
                if ($date_from === $mS && $date_to === date('Y-m-t', strtotime($mS))) { $fciActiveMonth = $m; break; }
            }
            $fciToday = date('Y-m-d'); $fciWeekAgo = date('Y-m-d', strtotime('-7 days'));
            $fciMonthStart = date('Y-m-01'); $fciMonthEnd = date('Y-m-t');
            $fciYearStart = date('Y') . '-01-01';
            ?>
            <!-- Filter bar -->
            <form method="GET" action="" class="spx-filter-bar" id="fciFilterForm">
                <div class="spx-filter-group fg-month">
                    <label>Miesiąc</label>
                    <select name="month" id="fciSelectMonth" onchange="fciOnMonthYearChange()">
                        <option value="0" <?php echo $fciActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($fciMonthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($fciActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="fciSelectYear" onchange="fciOnMonthYearChange()">
                        <?php foreach ($fciYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($fciYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="fciInputDateFrom" value="<?php echo e($date_from); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="fciInputDateTo" value="<?php echo e($date_to); ?>">
                </div>
                <div class="spx-filter-group fg-source">
                    <label>Źródło</label>
                    <select name="source" onchange="this.form.submit()">
                        <option value="all"        <?php echo $source_filter === 'all'        ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="fakturownia" <?php echo $source_filter === 'fakturownia' ? 'selected' : ''; ?>>Fakturownia</option>
                        <option value="documents"   <?php echo $source_filter === 'documents'   ? 'selected' : ''; ?>>Dokumenty ERP</option>
                        <option value="legacy"      <?php echo $source_filter === 'legacy'      ? 'selected' : ''; ?>>Stare faktury</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-status">
                    <label>Status</label>
                    <select name="workflow_status" onchange="this.form.submit()">
                        <option value="all"      <?php echo $workflow_filter === 'all'      ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="new"      <?php echo $workflow_filter === 'new'      ? 'selected' : ''; ?>>Nowe</option>
                        <option value="assigned" <?php echo $workflow_filter === 'assigned' ? 'selected' : ''; ?>>Przypisane</option>
                        <option value="accepted" <?php echo $workflow_filter === 'accepted' ? 'selected' : ''; ?>>Zaakceptowane</option>
                        <option value="rejected" <?php echo $workflow_filter === 'rejected' ? 'selected' : ''; ?>>Odrzucone</option>
                        <option value="archived" <?php echo $workflow_filter === 'archived' ? 'selected' : ''; ?>>Zarchiwizowane</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-payment">
                    <label>Płatność</label>
                    <select name="payment_status" onchange="this.form.submit()">
                        <option value="all"     <?php echo $payment_filter === 'all'     ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="unpaid"  <?php echo $payment_filter === 'unpaid'  ? 'selected' : ''; ?>>Nieopłacone</option>
                        <option value="partial" <?php echo $payment_filter === 'partial' ? 'selected' : ''; ?>>Częściowo</option>
                        <option value="paid"    <?php echo $payment_filter === 'paid'    ? 'selected' : ''; ?>>Opłacone</option>
                        <option value="unknown" <?php echo $payment_filter === 'unknown' ? 'selected' : ''; ?>>Nieznane</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-search">
                    <label>Szukaj</label>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Nr faktury, NIP, kontrahent...">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                <?php if ($workflow_filter !== 'all' || $payment_filter !== 'all' || $date_from || $date_to || $search || $supplier_filter !== '' || $alloc_filter !== 'all' || $source_filter !== 'all'): ?>
                    <a href="<?php echo url('finanse.fakturownia-cost-inbox'); ?>" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center; flex-shrink: 0; white-space: nowrap;">Wyczyść</a>
                <?php endif; ?>
                <?php if ($sort !== 'issue_date'): ?>
                    <input type="hidden" name="sort"  value="<?php echo e($sort); ?>">
                <?php endif; ?>
                <?php if ($order !== 'DESC'): ?>
                    <input type="hidden" name="order" value="<?php echo e($order); ?>">
                <?php endif; ?>
                <?php if ($supplier_filter !== ''): ?>
                    <input type="hidden" name="supplier" value="<?php echo e($supplier_filter); ?>">
                <?php endif; ?>
                <?php if ($alloc_filter !== 'all'): ?>
                    <input type="hidden" name="alloc" value="<?php echo e($alloc_filter); ?>">
                <?php endif; ?>
            </form>

            <!-- Quick date buttons + controls right -->
            <?php
            $qbBase = [];
            if ($source_filter !== 'all')    $qbBase['source'] = $source_filter;
            if ($workflow_filter !== 'all')   $qbBase['workflow_status'] = $workflow_filter;
            if ($payment_filter !== 'all')    $qbBase['payment_status'] = $payment_filter;
            if ($supplier_filter !== '')      $qbBase['supplier'] = $supplier_filter;
            if ($alloc_filter !== 'all')      $qbBase['alloc'] = $alloc_filter;
            if ($search !== '')              $qbBase['search'] = $search;
            if ($sort !== 'issue_date')      $qbBase['sort'] = $sort;
            if ($order !== 'DESC')           $qbBase['order'] = $order;

            $qbToday     = '?' . http_build_query(array_merge($qbBase, ['date_from' => $fciToday, 'date_to' => $fciToday]));
            $qb7days     = '?' . http_build_query(array_merge($qbBase, ['date_from' => $fciWeekAgo, 'date_to' => $fciToday]));
            $qbMonth     = '?' . http_build_query(array_merge($qbBase, ['date_from' => $fciMonthStart, 'date_to' => $fciMonthEnd, 'year' => date('Y')]));
            $qbYear      = '?' . http_build_query(array_merge($qbBase, ['date_from' => $fciYearStart, 'date_to' => $fciToday, 'year' => date('Y')]));
            $qbAll       = '?' . http_build_query($qbBase);
            $isNoDateSet = ($date_from === '' && $date_to === '');
            ?>
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <a href="<?php echo $qbAll; ?>" class="spx-quick-btn <?php echo $isNoDateSet ? 'active' : ''; ?>">Wszystkie</a>
                    <a href="<?php echo $qbToday; ?>" class="spx-quick-btn <?php echo ($date_from === $fciToday && $date_to === $fciToday) ? 'active' : ''; ?>">Dziś</a>
                    <a href="<?php echo $qb7days; ?>" class="spx-quick-btn <?php echo ($date_from === $fciWeekAgo && $date_to === $fciToday) ? 'active' : ''; ?>">7 dni</a>
                    <a href="<?php echo $qbMonth; ?>" class="spx-quick-btn <?php echo ($date_from === $fciMonthStart && $date_to === $fciMonthEnd) ? 'active' : ''; ?>">Ten miesiąc</a>
                    <a href="<?php echo $qbYear; ?>" class="spx-quick-btn <?php echo ($date_from === $fciYearStart && $date_to === $fciToday) ? 'active' : ''; ?>">Ten rok</a>
                </div>
                <div class="spx-controls-right">
                    <button class="btn-color-mode active" onclick="toggleColors()" title="Kolory wierszy">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 2a10 10 0 0 1 0 20"/>
                            <circle cx="12" cy="12" r="4"/>
                        </svg>
                    </button>
                    <div class="spx-separator"></div>
                    <button class="btn-group-mode active" id="btnDayGroup" onclick="toggleDayGrouping()" title="Grupuj po dniach">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                    </button>
                    <div class="spx-toggle-group">
                        <button class="spx-btn-toggle" onclick="expandAllDays()">Rozwiń</button>
                        <button class="spx-btn-toggle" onclick="collapseAllDays()">Zwiń</button>
                    </div>
                </div>
            </div>

            <!-- Supplier autocomplete filter -->
            <?php
            $suppStmt = $pdo->query("
                SELECT supplier_name COLLATE utf8mb4_general_ci AS supplier_name
                FROM fakturownia_cost_invoices WHERE supplier_name IS NOT NULL AND supplier_name != ''
                UNION
                SELECT contractor COLLATE utf8mb4_general_ci FROM invoices WHERE contractor IS NOT NULL AND contractor != ''
                UNION
                SELECT COALESCE(iv.name, d.source_name) COLLATE utf8mb4_general_ci
                FROM documents d LEFT JOIN investors iv ON iv.id = d.vendor_id
                WHERE d.type = 'invoice_cost' AND COALESCE(iv.name, d.source_name) IS NOT NULL AND COALESCE(iv.name, d.source_name) != ''
                ORDER BY supplier_name
            ");
            $allSuppliers = $suppStmt->fetchAll(PDO::FETCH_COLUMN);
            $supplierParams = $_GET;
            unset($supplierParams['supplier'], $supplierParams['page']);
            ?>
            <div class="supplier-filter-bar">
                <label for="supplierInput">Dostawca:</label>
                <div class="supplier-ac-wrap">
                    <input type="text"
                           id="supplierInput"
                           placeholder="Zacznij wpisywać nazwę dostawcy..."
                           value="<?php echo e($supplier_filter); ?>"
                           autocomplete="off">
                    <div class="supplier-ac-list" id="supplierAcList"></div>
                </div>
                <?php if ($supplier_filter !== ''): ?>
                    <a href="?<?php echo http_build_query($supplierParams); ?>" class="btn-clear-supplier">✕ Wyczyść</a>
                <?php endif; ?>
            </div>
            <script>
                var _supplierData = <?php echo json_encode(array_values($allSuppliers), JSON_UNESCAPED_UNICODE); ?>;
            </script>

            <?php if (count($invoices) > 0): ?>

            <!-- ============ NORMAL TABLE VIEW ============ -->
            <div class="normal-view" style="display:none;">
              <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo sortLinkCost('invoice_number',  $sort, $order, 'Nr faktury'); ?></th>
                            <th><?php echo sortLinkCost('supplier_name',   $sort, $order, 'Dostawca'); ?></th>
                            <th><?php echo sortLinkCost('issue_date',      $sort, $order, 'Data wyst.'); ?></th>
                            <th><?php echo sortLinkCost('due_date',        $sort, $order, 'Termin pł.'); ?></th>
                            <th><?php echo sortLinkCost('amount_net',      $sort, $order, 'Netto'); ?></th>
                            <th><?php echo sortLinkCost('amount_gross',    $sort, $order, 'Brutto'); ?></th>
                            <th><?php echo sortLinkCost('allocated_net',   $sort, $order, 'Alokacja'); ?></th>
                            <th><?php echo sortLinkCost('payment_status',  $sort, $order, 'Płatność'); ?></th>
                            <th>Notatka</th>
                            <th><?php echo sortLinkCost('workflow_status', $sort, $order, 'Status / Akcje'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rowIndex = 0; foreach ($invoices as $inv): $colors = getRowColorCost($rowIndex++); $isFakturownia = ($inv['source'] === 'fakturownia'); $rawId = (int)$inv['raw_id']; ?>
                            <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>">
                                <?php include __DIR__ . '/_cost-inbox-row.php'; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
              </div>

                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?><a href="<?php echo pageLink($page - 1); ?>">Poprzednia</a><?php else: ?><span class="disabled">Poprzednia</span><?php endif; ?>
                    <?php $startPage = max(1, $page - 3); $endPage = min($totalPages, $page + 3);
                    if ($startPage > 1): ?><a href="<?php echo pageLink(1); ?>">1</a><?php if ($startPage > 2): ?><span class="page-info">...</span><?php endif; endif;
                    for ($i = $startPage; $i <= $endPage; $i++): if ($i === $page): ?><span class="current"><?php echo $i; ?></span><?php else: ?><a href="<?php echo pageLink($i); ?>"><?php echo $i; ?></a><?php endif; endfor;
                    if ($endPage < $totalPages): ?><?php if ($endPage < $totalPages - 1): ?><span class="page-info">...</span><?php endif; ?><a href="<?php echo pageLink($totalPages); ?>"><?php echo $totalPages; ?></a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a href="<?php echo pageLink($page + 1); ?>">Następna</a><?php else: ?><span class="disabled">Następna</span><?php endif; ?>
                    <span class="page-info">(<?php echo $totalRows; ?> rekordów)</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- ============ GROUPED BY DAY VIEW ============ -->
            <div class="grouped-view">
                <?php foreach ($invoicesByDay as $dayDate => $dayData):
                    $dayName = ($dayDate !== '0000-00-00') ? polishDayShort($dayDate) : '—';
                    $dayDateFmt = ($dayDate !== '0000-00-00') ? formatDate($dayDate) : 'Brak daty';
                    $dayCount = $dayData['count'];
                    $dayTotal = $dayData['total_value'];
                ?>
                <div class="day-group collapsed" data-date="<?php echo e($dayDate); ?>">
                    <div class="day-header" onclick="toggleDay(this)">
                        <div class="dh-left">
                            <span class="dh-dayname"><?php echo $dayName; ?></span>
                            <span class="dh-date"><?php echo $dayDateFmt; ?></span>
                        </div>
                        <div class="dh-right">
                            <div class="dh-stats">
                                <div class="dh-stat">
                                    <span class="dh-stat-value"><?php echo $dayCount; ?></span>
                                    <span>faktur<?php echo ($dayCount == 1) ? 'a' : (($dayCount > 1 && $dayCount < 5) ? 'y' : ''); ?></span>
                                </div>
                                <div class="dh-stat">
                                    <span class="dh-stat-value" style="color:var(--primary);"><?php echo formatMoney($dayTotal); ?></span>
                                    <span>wartość</span>
                                </div>
                            </div>
                            <span class="dh-arrow">&#9660;</span>
                        </div>
                    </div>
                    <div class="day-content">
                        <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nr faktury</th>
                                    <th>Dostawca</th>
                                    <th>Termin pł.</th>
                                    <th>Netto</th>
                                    <th>Brutto</th>
                                    <th>Alokacja</th>
                                    <th>Płatność</th>
                                    <th>Notatka</th>
                                    <th>Status / Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowIndex = 0; $__dayGroupMode = true; foreach ($dayData['items'] as $inv): $colors = getRowColorCost($rowIndex++); $isFakturownia = ($inv['source'] === 'fakturownia'); $rawId = (int)$inv['raw_id']; ?>
                                <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>">
                                    <?php include __DIR__ . '/_cost-inbox-row.php'; ?>
                                </tr>
                                <?php endforeach; unset($__dayGroupMode); ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if ($totalPages > 1): ?>
                <div class="pagination" style="background:white;border-radius:10px;margin-top:12px;">
                    <?php if ($page > 1): ?><a href="<?php echo pageLink($page - 1); ?>">Poprzednia</a><?php else: ?><span class="disabled">Poprzednia</span><?php endif; ?>
                    <?php $startPage = max(1, $page - 3); $endPage = min($totalPages, $page + 3);
                    if ($startPage > 1): ?><a href="<?php echo pageLink(1); ?>">1</a><?php if ($startPage > 2): ?><span class="page-info">...</span><?php endif; endif;
                    for ($i = $startPage; $i <= $endPage; $i++): if ($i === $page): ?><span class="current"><?php echo $i; ?></span><?php else: ?><a href="<?php echo pageLink($i); ?>"><?php echo $i; ?></a><?php endif; endfor;
                    if ($endPage < $totalPages): ?><?php if ($endPage < $totalPages - 1): ?><span class="page-info">...</span><?php endif; ?><a href="<?php echo pageLink($totalPages); ?>"><?php echo $totalPages; ?></a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a href="<?php echo pageLink($page + 1); ?>">Następna</a><?php else: ?><span class="disabled">Następna</span><?php endif; ?>
                    <span class="page-info">(<?php echo $totalRows; ?> rekordów)</span>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
                <div class="no-data">Brak faktur kosztowych do wyświetlenia.</div>
            <?php endif; ?>
        </div>

    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>

    <script>
        function markDirty(id) {
            document.getElementById('btn-save-' + id).style.display = 'inline-flex';
        }
        function markPaymentDirty(id) {
            document.getElementById('btn-payment-save-' + id).style.display = 'inline-flex';
        }
        function expandNote(id) {
            document.getElementById('note-display-' + id).style.display = 'none';
            var area = document.getElementById('note-edit-' + id);
            area.style.display = 'flex';
            area.querySelector('textarea').focus();
        }
        function collapseNote(id) {
            document.getElementById('note-display-' + id).style.display = 'block';
            document.getElementById('note-edit-' + id).style.display = 'none';
        }
        function handleInboxActionSelect(selectEl) {
            var target = (selectEl && selectEl.value) ? String(selectEl.value).trim() : '';
            if (!target) return;
            if (target.startsWith('ext:')) {
                window.open(target.substring(4), '_blank', 'noopener');
            } else {
                window.location.href = target;
            }
            selectEl.selectedIndex = 0;
        }

        /* --- Color toggle --- */
        function applyColors(isColored) {
            document.querySelectorAll('tbody tr[data-row-color]').forEach(function(tr) {
                if (isColored) {
                    tr.style.setProperty('--row-bg',     tr.dataset.rowColor);
                    tr.style.setProperty('--row-border', tr.dataset.rowBorder);
                } else {
                    tr.style.removeProperty('--row-bg');
                    tr.style.removeProperty('--row-border');
                }
            });
        }
        function toggleColors() {
            document.body.classList.toggle('no-colors');
            var btn = document.querySelector('.btn-color-mode');
            if (btn) btn.classList.toggle('active');
            var isColored = !document.body.classList.contains('no-colors');
            localStorage.setItem('costInboxColorsEnabled', isColored ? '1' : '0');
            applyColors(isColored);
        }

        /* --- Day grouping toggle --- */
        function setViewMode(isGrouped) {
            var normalView = document.querySelector('.normal-view');
            var groupedView = document.querySelector('.grouped-view');
            var btn = document.getElementById('btnDayGroup');
            if (isGrouped) {
                if (normalView) normalView.style.display = 'none';
                if (groupedView) groupedView.style.display = 'block';
                if (btn) btn.classList.add('active');
            } else {
                if (normalView) normalView.style.display = 'block';
                if (groupedView) groupedView.style.display = 'none';
                if (btn) btn.classList.remove('active');
            }
            localStorage.setItem('costInboxDayGrouping', isGrouped ? '1' : '0');
            var isColored = !document.body.classList.contains('no-colors');
            applyColors(isColored);
        }
        function dayStateKey() {
            var params = new URLSearchParams(window.location.search);
            ['success', 'error', 'sync_reason', 'sync_imported', 'sync_updated', 'sync_pages', 'sync_mode', 'msg'].forEach(function(key) {
                params.delete(key);
            });
            var query = params.toString();
            return 'costInboxExpandedDays:' + window.location.pathname + (query ? '?' + query : '');
        }
        function saveExpandedDays() {
            var expanded = [];
            document.querySelectorAll('.day-group[data-date]').forEach(function(group) {
                if (!group.classList.contains('collapsed')) {
                    expanded.push(group.getAttribute('data-date'));
                }
            });
            localStorage.setItem(dayStateKey(), JSON.stringify(expanded));
        }
        function restoreExpandedDays() {
            var raw = localStorage.getItem(dayStateKey());
            if (raw === null) return;
            var expanded = [];
            try {
                expanded = JSON.parse(raw);
            } catch (e) {
                expanded = [];
            }
            document.querySelectorAll('.day-group[data-date]').forEach(function(group) {
                var date = group.getAttribute('data-date');
                group.classList.toggle('collapsed', expanded.indexOf(date) === -1);
            });
        }
        function toggleDayGrouping() {
            var isGrouped = localStorage.getItem('costInboxDayGrouping') !== '0';
            setViewMode(!isGrouped);
        }
        function toggleDay(header) {
            header.parentElement.classList.toggle('collapsed');
            saveExpandedDays();
        }
        function expandAllDays() {
            document.querySelectorAll('.day-group').forEach(function(g) { g.classList.remove('collapsed'); });
            saveExpandedDays();
        }
        function collapseAllDays() {
            document.querySelectorAll('.day-group').forEach(function(g) { g.classList.add('collapsed'); });
            saveExpandedDays();
        }

        /* --- Supplier autocomplete --- */
        (function() {
            var input = document.getElementById('supplierInput');
            var list = document.getElementById('supplierAcList');
            if (!input || !list) return;
            var data = window._supplierData || [];
            var activeIdx = -1;

            function applyFilter(val) {
                var params = new URLSearchParams(window.location.search);
                if (val) { params.set('supplier', val); } else { params.delete('supplier'); }
                params.delete('page');
                window.location.href = '?' + params.toString();
            }

            function highlight(text, query) {
                var idx = text.toLowerCase().indexOf(query.toLowerCase());
                if (idx < 0) return escHtml(text);
                return escHtml(text.substring(0, idx)) + '<strong>' + escHtml(text.substring(idx, idx + query.length)) + '</strong>' + escHtml(text.substring(idx + query.length));
            }

            function escHtml(s) {
                var d = document.createElement('div');
                d.appendChild(document.createTextNode(s));
                return d.innerHTML;
            }

            function showList(query) {
                activeIdx = -1;
                if (!query || query.length < 1) { list.style.display = 'none'; return; }
                var q = query.toLowerCase();
                var matches = [];
                for (var i = 0; i < data.length; i++) {
                    if (data[i].toLowerCase().indexOf(q) >= 0) {
                        matches.push(data[i]);
                        if (matches.length >= 15) break;
                    }
                }
                if (matches.length === 0) {
                    list.innerHTML = '<div class="ac-empty">Brak wyników dla &quot;' + escHtml(query) + '&quot;</div>';
                    list.style.display = 'block';
                    return;
                }
                var html = '';
                for (var j = 0; j < matches.length; j++) {
                    html += '<div class="ac-item" data-val="' + escHtml(matches[j]) + '">' + highlight(matches[j], query) + '</div>';
                }
                list.innerHTML = html;
                list.style.display = 'block';
            }

            input.addEventListener('input', function() { showList(this.value.trim()); });
            input.addEventListener('focus', function() {
                if (this.value.trim().length >= 1) showList(this.value.trim());
            });

            list.addEventListener('mousedown', function(e) {
                var item = e.target.closest('.ac-item');
                if (item) {
                    e.preventDefault();
                    applyFilter(item.getAttribute('data-val'));
                }
            });

            input.addEventListener('keydown', function(e) {
                var items = list.querySelectorAll('.ac-item');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = Math.min(activeIdx + 1, items.length - 1);
                    updateActive(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = Math.max(activeIdx - 1, 0);
                    updateActive(items);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIdx >= 0 && items[activeIdx]) {
                        applyFilter(items[activeIdx].getAttribute('data-val'));
                    } else {
                        applyFilter(this.value.trim());
                    }
                } else if (e.key === 'Escape') {
                    list.style.display = 'none';
                }
            });

            function updateActive(items) {
                for (var i = 0; i < items.length; i++) {
                    items[i].classList.toggle('ac-active', i === activeIdx);
                }
                if (items[activeIdx]) items[activeIdx].scrollIntoView({block: 'nearest'});
            }

            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !list.contains(e.target)) {
                    list.style.display = 'none';
                }
            });
        })();

        function fciOnMonthYearChange() {
            var month = parseInt(document.getElementById('fciSelectMonth').value);
            var year  = parseInt(document.getElementById('fciSelectYear').value);
            if (month >= 1 && month <= 12) {
                var lastDay = new Date(year, month, 0).getDate();
                var pad = function(n) { return String(n).padStart(2, '0'); };
                document.getElementById('fciInputDateFrom').value = year + '-' + pad(month) + '-01';
                document.getElementById('fciInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
            } else {
                document.getElementById('fciInputDateFrom').value = year + '-01-01';
                document.getElementById('fciInputDateTo').value   = year + '-12-31';
            }
            document.getElementById('fciFilterForm').submit();
        }

        /* --- Init on load --- */
        document.addEventListener('DOMContentLoaded', function () {
            var colorsEnabled = localStorage.getItem('costInboxColorsEnabled');
            if (colorsEnabled === '0') {
                document.body.classList.add('no-colors');
                var btn = document.querySelector('.btn-color-mode');
                if (btn) btn.classList.remove('active');
            }

            var dayGrouping = localStorage.getItem('costInboxDayGrouping');
            var isGrouped = (dayGrouping !== '0');
            setViewMode(isGrouped);
            restoreExpandedDays();

            var isColored = !document.body.classList.contains('no-colors');
            applyColors(isColored);
        });
    </script>
</body>
</html>
