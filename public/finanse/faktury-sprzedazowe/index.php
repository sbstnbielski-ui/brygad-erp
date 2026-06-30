<?php
/**
 * BRYGAD ERP - Centrum Faktur Sprzedażowych
 * Bliźniak wizualny Centrum Faktur Kosztowych.
 * Dane: invoices_sale + Fakturownia sync (przyszłość) + retencje.
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
require_once dirname(dirname(__DIR__)) . '/modules/fakturownia/FakturowniaClient.php';
require_once dirname(dirname(__DIR__)) . '/includes/sales_invoice_correction_helper.php';

function syncStatusToFk(PDO $pdo, int $invoiceId, string $erpStatus, float $paymentAmount = 0): void {
    try {
        $fkStmt = $pdo->prepare('SELECT fakturownia_id FROM fakturownia_invoices WHERE erp_invoice_sale_id = ? LIMIT 1');
        $fkStmt->execute([$invoiceId]);
        $fkId = (int)$fkStmt->fetchColumn();
        if ($fkId <= 0) {
            logEvent("FK sync skip: brak mapowania FK dla ERP ID:{$invoiceId}", 'DEBUG');
            return;
        }

        $client = new FakturowniaClient($pdo);

        if ($erpStatus === 'partially_paid') {
            // FK change_status nie akceptuje "partial" bez kwoty.
            // Używamy PUT na fakturze z status + paid (suma dotychczasowych wpłat).
            $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_net), 0) FROM invoice_sale_payments WHERE invoice_id = ?');
            $sumStmt->execute([$invoiceId]);
            $totalPaidNet = (float)$sumStmt->fetchColumn();

            // FK pole "paid" to kwota brutto — przeliczamy z netto
            $invStmt = $pdo->prepare('
                SELECT amount_net, amount_gross, financial_effect_kind, correction_effect_net,
                       correction_effect_gross, fakturownia_options_json, document_kind
                FROM invoices_sale WHERE id = ?
            ');
            $invStmt->execute([$invoiceId]);
            $inv = $invStmt->fetch(PDO::FETCH_ASSOC);
            $netTotal = sprutexEffectiveInvoiceSaleNet($inv ?: []);
            $grossTotal = sprutexEffectiveInvoiceSaleGross($inv ?: []);
            $paidGross = ($netTotal > 0) ? round($totalPaidNet * ($grossTotal / $netTotal), 2) : $totalPaidNet;

            $result = $client->put('/invoices/' . $fkId . '.json', [
                'invoice' => [
                    'status' => 'partial',
                    'paid'   => number_format($paidGross, 2, '.', ''),
                ]
            ]);
            logEvent("FK PUT partial FV ID:{$invoiceId} FK_ID:{$fkId} paid_gross:{$paidGross} → HTTP {$result['http_status']}", 'INFO');
        } else {
            if ($paymentAmount > 0) {
                $client->post('/banking/payments.json', [
                    'banking_payment' => [
                        'name'       => 'Wpłata ERP',
                        'price'      => $paymentAmount,
                        'invoice_id' => $fkId,
                        'paid'       => true,
                        'kind'       => 'api',
                        'provider'   => 'transfer',
                    ]
                ]);
                logEvent("FK banking_payment FV ID:{$invoiceId} FK_ID:{$fkId} amount:{$paymentAmount}", 'INFO');
            }

            $fkStatusMap = ['paid' => 'paid', 'issued' => 'issued', 'cancelled' => 'rejected', 'draft' => 'issued'];
            $fkStatus = $fkStatusMap[$erpStatus] ?? null;
            if ($fkStatus) {
                $result = $client->changeInvoiceStatus($fkId, $fkStatus);
                logEvent("FK change_status FV ID:{$invoiceId} FK_ID:{$fkId} status:{$fkStatus} → HTTP {$result['http_status']}", 'INFO');
            }
        }
    } catch (Throwable $e) {
        logEvent("FK sync error FV ID:{$invoiceId}: " . $e->getMessage(), 'WARNING');
    }
}

function recalcInvoiceStatus(PDO $pdo, int $invoiceId): string {
    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_net), 0) FROM invoice_sale_payments WHERE invoice_id = ?');
    $sumStmt->execute([$invoiceId]);
    $totalPaid = (float)$sumStmt->fetchColumn();

    $invStmt = $pdo->prepare('
        SELECT amount_net, status, financial_effect_kind, correction_effect_net,
               fakturownia_options_json, document_kind
        FROM invoices_sale WHERE id = ?
    ');
    $invStmt->execute([$invoiceId]);
    $row = $invStmt->fetch(PDO::FETCH_ASSOC);
    $invTotal = sprutexEffectiveInvoiceSaleNet($row ?: []);
    $currentStatus = $row['status'] ?? 'issued';

    if ($totalPaid >= $invTotal && $invTotal > 0) {
        $status = 'paid';
    } elseif ($totalPaid > 0) {
        $status = 'partially_paid';
    } else {
        return $currentStatus;
    }

    $pdo->prepare("UPDATE invoices_sale SET status = ?, payment_date = ? WHERE id = ?")
        ->execute([$status, $status === 'paid' ? date('Y-m-d') : null, $invoiceId]);

    return $status;
}

// --- POST handling ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        header('Location: ' . url('finanse.faktury-sprzedazowe') . '?error=csrf');
        exit;
    }

    $action    = $_POST['action'] ?? '';
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);

    // Retention: add
    if ($action === 'add_retention' && $invoiceId > 0) {
        $inv = $pdo->prepare("SELECT id, amount_gross FROM invoices_sale WHERE id = ?");
        $inv->execute([$invoiceId]);
        $invRow = $inv->fetch();
        if ($invRow) {
            $pct    = max(0, min(100, (float)($_POST['retention_percent'] ?? 5)));
            $amt    = (float)($_POST['retention_amount'] ?? 0);
            if ($amt <= 0) {
                $amt = round((float)$invRow['amount_gross'] * $pct / 100, 2);
            }
            $returnDate   = $_POST['return_date'] ?? null;
            $reminderDate = $_POST['reminder_date'] ?? null;
            $notes        = mb_substr(trim($_POST['retention_notes'] ?? ''), 0, 500);
            $userId       = $_SESSION['user_id'] ?? null;

            try {
                $ins = $pdo->prepare("
                    INSERT INTO invoice_sale_retentions
                        (invoice_id, retention_percent, retention_amount, return_date, reminder_date, notes, created_by_user_id)
                    VALUES (:iid, :pct, :amt, :rd, :rem, :notes, :uid)
                    ON DUPLICATE KEY UPDATE
                        retention_percent = VALUES(retention_percent),
                        retention_amount  = VALUES(retention_amount),
                        return_date       = VALUES(return_date),
                        reminder_date     = VALUES(reminder_date),
                        notes             = VALUES(notes)
                ");
                $ins->execute([
                    'iid' => $invoiceId, 'pct' => $pct, 'amt' => $amt,
                    'rd' => $returnDate ?: null, 'rem' => $reminderDate ?: null,
                    'notes' => $notes ?: null, 'uid' => $userId,
                ]);
                header('Location: ' . url('finanse.faktury-sprzedazowe') . '?success=retention_saved');
                exit;
            } catch (\Throwable $e) {
                error_log("Retention save failed: " . $e->getMessage());
                header('Location: ' . url('finanse.faktury-sprzedazowe') . '?error=retention_failed');
                exit;
            }
        }
    }

    // Retention: settle + wstaw wpłatę
    if ($action === 'settle_retention' && $invoiceId > 0) {
        $userId = $_SESSION['user_id'] ?? null;
        $retStmt = $pdo->prepare("SELECT retention_amount FROM invoice_sale_retentions WHERE invoice_id = :iid AND status != 'settled' LIMIT 1");
        $retStmt->execute(['iid' => $invoiceId]);
        $retentionAmount = (float)$retStmt->fetchColumn();

        $pdo->prepare("UPDATE invoice_sale_retentions SET status = 'settled', settled_at = NOW(), settled_by_user_id = :uid WHERE invoice_id = :iid AND status != 'settled'")->execute(['uid' => $userId, 'iid' => $invoiceId]);

        if ($retentionAmount > 0) {
            $invDataStmt = $pdo->prepare('SELECT amount_net, amount_gross FROM invoices_sale WHERE id = ?');
            $invDataStmt->execute([$invoiceId]);
            $invData = $invDataStmt->fetch(PDO::FETCH_ASSOC);
            $gross = (float)($invData['amount_gross'] ?? 0);
            $net = (float)($invData['amount_net'] ?? 0);
            $retentionNet = ($gross > 0) ? round($retentionAmount * ($net / $gross), 2) : $retentionAmount;

            $pdo->prepare("INSERT INTO invoice_sale_payments (invoice_id, amount_net, payment_date, notes, source, created_by) VALUES (?, ?, ?, 'Rozliczenie retencji', 'retention_settled', ?)")
                ->execute([$invoiceId, $retentionNet, date('Y-m-d'), $userId]);

            $autoStatus = recalcInvoiceStatus($pdo, $invoiceId);
            syncStatusToFk($pdo, $invoiceId, $autoStatus, $retentionNet);
        }

        header('Location: ' . url('finanse.faktury-sprzedazowe') . '?success=retention_settled');
        exit;
    }

    // Retention: remove
    if ($action === 'remove_retention' && $invoiceId > 0) {
        $pdo->prepare("DELETE FROM invoice_sale_retentions WHERE invoice_id = ?")->execute([$invoiceId]);
        header('Location: ' . url('finanse.faktury-sprzedazowe') . '?success=retention_removed');
        exit;
    }

    // Wyklucz z analiz / cofnij wykluczenie
    if ($action === 'toggle_exclude' && $invoiceId > 0) {
        $row = $pdo->prepare("SELECT status, exclude_from_analytics FROM invoices_sale WHERE id = ?");
        $row->execute([$invoiceId]);
        $invRow = $row->fetch(PDO::FETCH_ASSOC);
        if ($invRow) {
            $currentlyExcluded = (int)$invRow['exclude_from_analytics'];
            if ($currentlyExcluded) {
                // Cofnij wykluczenie — nie ruszamy statusu ani Fakturowni
                $pdo->prepare("UPDATE invoices_sale SET exclude_from_analytics = 0 WHERE id = ?")->execute([$invoiceId]);
                logEvent("FV ID:{$invoiceId} — cofnięto wykluczenie z analiz", 'INFO');
            } else {
                // Wyklucz — ustaw paid + wyślij do FK
                $pdo->prepare("UPDATE invoices_sale SET exclude_from_analytics = 1, status = 'paid', payment_date = COALESCE(payment_date, ?) WHERE id = ?")
                    ->execute([date('Y-m-d'), $invoiceId]);
                syncStatusToFk($pdo, $invoiceId, 'paid');
                logEvent("FV ID:{$invoiceId} — wykluczona z analiz, status → paid, FK zsync", 'INFO');
            }
            header('Location: ' . url('finanse.faktury-sprzedazowe') . '?success=exclude_toggled');
            exit;
        }
    }

    // Change status inline
    if ($action === 'change_status' && $invoiceId > 0) {
        $newStatus = $_POST['new_status'] ?? '';
        $allowedStatuses = ['draft', 'issued', 'paid', 'partially_paid', 'cancelled'];
        if (in_array($newStatus, $allowedStatuses, true)) {
            $paymentDate = $newStatus === 'paid' ? ($_POST['payment_date'] ?? date('Y-m-d')) : null;
            $pdo->prepare("UPDATE invoices_sale SET status = ?, payment_date = COALESCE(?, payment_date) WHERE id = ?")
                ->execute([$newStatus, $paymentDate, $invoiceId]);
            logEvent("Zmiana statusu faktury ID:{$invoiceId} na {$newStatus}", 'INFO');
            syncStatusToFk($pdo, $invoiceId, $newStatus);
            header('Location: ' . url('finanse.faktury-sprzedazowe') . '?success=status_changed');
            exit;
        }
    }

    // Zmiana statusu wpisu PZF (pozafakturowego)
    $pzfEntryId = (int)($_POST['pzf_entry_id'] ?? 0);
    if ($action === 'change_pzf_status' && $pzfEntryId > 0) {
        $newPzfStatus = $_POST['new_status'] ?? '';
        if (in_array($newPzfStatus, ['unpaid', 'paid'], true)) {
            $paymentDate = $newPzfStatus === 'paid' ? ($_POST['payment_date'] ?? date('Y-m-d')) : null;
            $pdo->prepare("UPDATE sales_noninvoice_entries SET payment_status = ?, payment_date = ? WHERE id = ?")
                ->execute([$newPzfStatus, $paymentDate, $pzfEntryId]);
            logEvent("PZF ID:{$pzfEntryId} — zmiana statusu na {$newPzfStatus}", 'INFO');
            $returnSource = in_array($_POST['source'] ?? '', ['invoice', 'noninvoice', 'all'], true)
                ? $_POST['source']
                : 'noninvoice';
            header('Location: ' . url('finanse.faktury-sprzedazowe') . '?source=' . $returnSource . '&success=pzf_status_changed');
            exit;
        }
    }

    // Reset do wystawionej — usuwa WSZYSTKIE wpłaty i cofa status
    if ($action === 'reset_to_issued' && $invoiceId > 0) {
        $pdo->prepare("DELETE FROM invoice_sale_payments WHERE invoice_id = ?")->execute([$invoiceId]);
        $pdo->prepare("UPDATE invoices_sale SET status = 'issued', payment_date = NULL WHERE id = ?")->execute([$invoiceId]);
        syncStatusToFk($pdo, $invoiceId, 'issued');
        logEvent("Reset FV ID:{$invoiceId} do 'wystawiona' — usunięto wszystkie wpłaty", 'INFO');
        header('Location: ' . url('finanse.faktury-sprzedazowe') . '?success=reset_to_issued');
        exit;
    }

    // Dodaj wpłatę do faktury
    if ($action === 'add_payment' && $invoiceId > 0) {
        $paymentAmount = round((float)($_POST['payment_amount_net'] ?? 0), 2);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $paymentNotes = trim((string)($_POST['payment_notes'] ?? ''));
        if ($paymentAmount > 0) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $pdo->prepare("INSERT INTO invoice_sale_payments (invoice_id, amount_net, payment_date, notes, source, created_by) VALUES (?, ?, ?, ?, 'manual', ?)")
                ->execute([$invoiceId, $paymentAmount, $paymentDate, $paymentNotes ?: null, $userId ?: null]);

            $autoStatus = recalcInvoiceStatus($pdo, $invoiceId);
            syncStatusToFk($pdo, $invoiceId, $autoStatus, $paymentAmount);
            logEvent("Dodano wpłatę {$paymentAmount} PLN do FV ID:{$invoiceId}, nowy status: {$autoStatus}", 'INFO');
            header('Location: ' . url('finanse.faktury-sprzedazowe') . '?success=payment_added');
            exit;
        }
    }

    header('Location: ' . url('finanse.faktury-sprzedazowe') . '?error=action_failed');
    exit;
}

// --- SOURCE SWITCH (PZF guardrail: invoice/noninvoice/all) ---
$source = $_GET['source'] ?? 'invoice';
if (!in_array($source, ['invoice', 'noninvoice', 'all'], true)) {
    $source = 'invoice';
}

// --- GET filters ---
$status_filter    = $_GET['status'] ?? 'all';
$date_from        = $_GET['date_from'] ?? '';
$date_to          = $_GET['date_to'] ?? '';
$search           = trim($_GET['search'] ?? '');
$client_filter    = $_GET['client_id'] ?? '';
$client_name_filter = trim($_GET['client_name'] ?? '');
$retention_filter = $_GET['retention'] ?? 'all';
$kind_filter      = $_GET['kind'] ?? 'all';

// Sorting
$sort  = $_GET['sort'] ?? 'issue_date';
$order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowed_sorts = [
    'invoice_number', 'client_name', 'issue_date', 'due_date',
    'amount_net', 'amount_gross', 'status',
];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'issue_date';
}

// Month/Year helper
// Rok: domyslnie biezacy. 'all' = bez filtra roku (przycisk Wszystkie).
$fsYearRaw = $_GET['year'] ?? null;
if ($fsYearRaw === 'all') {
    $fsYear = 0;
} elseif ($fsYearRaw === null) {
    $fsYear = (int)date('Y');
} else {
    $fsYear = (int)$fsYearRaw;
    if ($fsYear < 2020 || $fsYear > 2030) $fsYear = (int)date('Y');
}
$fsMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;

// Gdy uzytkownik nie wpisal recznie date_from/date_to - uzyj roku (ew. konkretnego miesiaca) jako filtra
if ($date_from === '' && $date_to === '' && $fsYear > 0) {
    if ($fsMonth >= 1 && $fsMonth <= 12) {
        $date_from = sprintf('%04d-%02d-01', $fsYear, $fsMonth);
        $date_to   = date('Y-m-t', strtotime($date_from));
    } else {
        $date_from = sprintf('%04d-01-01', $fsYear);
        $date_to   = sprintf('%04d-12-31', $fsYear);
    }
}

// Sort map
$sortMap = [
    'invoice_number' => 'inv.invoice_number',
    'client_name'    => 'i.name',
    'issue_date'     => 'inv.issue_date',
    'due_date'       => 'inv.due_date',
    'amount_net'     => 'inv.amount_net',
    'amount_gross'   => 'inv.amount_gross',
    'status'         => 'inv.status',
];
$orderByCol = $sortMap[$sort];

// Build WHERE
$where  = " WHERE 1=1 AND (inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00')";
$params = [];

if ($status_filter !== 'all' && $status_filter !== '') {
    $where .= " AND inv.status = :status";
    $params['status'] = $status_filter;
}
if ($date_from !== '') {
    $where .= " AND inv.issue_date >= :date_from";
    $params['date_from'] = $date_from;
}
if ($date_to !== '') {
    $where .= " AND inv.issue_date <= :date_to";
    $params['date_to'] = $date_to;
}
if ($search !== '') {
    $where .= " AND (inv.invoice_number LIKE :search OR i.name LIKE :search OR inv.notes LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($client_filter !== '') {
    $where .= " AND inv.client_id = :client_id";
    $params['client_id'] = $client_filter;
}
if ($client_name_filter !== '') {
    $where .= " AND i.name LIKE :client_name_filter";
    $params['client_name_filter'] = '%' . $client_name_filter . '%';
}
if ($kind_filter !== 'all' && $kind_filter !== '') {
    $where .= " AND JSON_UNQUOTE(JSON_EXTRACT(inv.fakturownia_options_json, '$.kind')) = :kind_filter";
    $params['kind_filter'] = $kind_filter;
}

// Retention filter
$retentionJoinWhere = '';
if ($retention_filter === 'active') {
    $retentionJoinWhere = " AND r.id IS NOT NULL AND r.status != 'settled'";
} elseif ($retention_filter === 'due_soon') {
    $retentionJoinWhere = " AND r.id IS NOT NULL AND r.status != 'settled' AND r.return_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND r.return_date >= CURDATE()";
} elseif ($retention_filter === 'overdue') {
    $retentionJoinWhere = " AND r.id IS NOT NULL AND r.status != 'settled' AND r.return_date < CURDATE()";
} elseif ($retention_filter === 'settled') {
    $retentionJoinWhere = " AND r.id IS NOT NULL AND r.status = 'settled'";
}

$fromClause = "
    FROM invoices_sale inv
    LEFT JOIN investors i ON inv.client_id = i.id
    LEFT JOIN invoice_sale_retentions r ON r.invoice_id = inv.id
";

// Pagination
$perPage       = 50;
$requestedPage = max(1, (int)($_GET['page'] ?? 1));
$page          = $requestedPage;
$offset        = 0;

$countSql  = "SELECT COUNT(*) {$fromClause} {$where} {$retentionJoinWhere}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($source === 'invoice') {
    $page   = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
}

$mainSql = "SELECT inv.*,
    i.name AS client_name,
    i.nip AS client_nip,
    JSON_UNQUOTE(JSON_EXTRACT(inv.fakturownia_options_json, '$.kind')) AS invoice_kind,
    CASE
        WHEN inv.financial_effect_kind = 'correction'
          OR inv.document_kind = 'sale_correction'
          OR inv.fakturownia_options_json LIKE '%\"kind\":\"correction\"%'
          OR inv.fakturownia_options_json LIKE '%\"kind\": \"correction\"%'
        THEN COALESCE(inv.correction_effect_net, 0)
        ELSE inv.amount_net
    END AS effective_amount_net,
    DATEDIFF(inv.due_date, CURDATE()) AS days_until_due,
    (SELECT GROUP_CONCAT(DISTINCT CONCAT(p.name, ' (', FORMAT(isa.amount_net, 2, 'pl_PL'), ')') ORDER BY p.name SEPARATOR ', ')
     FROM invoice_sale_allocations isa
     JOIN projects p ON p.id = isa.project_id
     WHERE isa.invoice_id = inv.id
    ) AS assigned_projects,
    r.id AS retention_id,
    r.retention_percent,
    r.retention_amount,
    r.return_date AS retention_return_date,
    r.reminder_date AS retention_reminder_date,
    r.status AS retention_status,
    r.settled_at AS retention_settled_at,
    r.notes AS retention_notes,
    CASE
        WHEN r.id IS NULL THEN NULL
        WHEN r.status = 'settled' THEN 'settled'
        WHEN r.return_date IS NOT NULL AND r.return_date < CURDATE() THEN 'overdue'
        WHEN r.return_date IS NOT NULL AND r.return_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'due_soon'
        ELSE 'active'
    END AS retention_computed
{$fromClause}
{$where}
{$retentionJoinWhere}
ORDER BY {$orderByCol} {$order}, inv.id DESC";

if ($source === 'invoice') {
    $mainSql .= " LIMIT {$perPage} OFFSET {$offset}";
}

$stmt = $pdo->prepare($mainSql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// KPI stats (filtered)
$kpiSql = "SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN inv.status = 'draft' THEN 1 ELSE 0 END) AS count_draft,
    SUM(CASE WHEN inv.status = 'issued' THEN 1 ELSE 0 END) AS count_issued,
    SUM(CASE WHEN inv.status = 'paid' THEN 1 ELSE 0 END) AS count_paid,
    COALESCE(SUM(CASE
        WHEN inv.financial_effect_kind = 'correction'
          OR inv.document_kind = 'sale_correction'
          OR inv.fakturownia_options_json LIKE '%\"kind\":\"correction\"%'
          OR inv.fakturownia_options_json LIKE '%\"kind\": \"correction\"%'
        THEN COALESCE(inv.correction_effect_net, 0)
        ELSE inv.amount_net
    END), 0) AS total_net,
    SUM(CASE WHEN r.id IS NOT NULL AND r.status != 'settled' THEN 1 ELSE 0 END) AS count_retention
{$fromClause}
{$where}";
$kpiStmt = $pdo->prepare($kpiSql);
$kpiStmt->execute($params);
$stats = $kpiStmt->fetch();

// ── PZF: query dla widoku noninvoice / all ────────────────────────────────
$pzf_entries  = [];
$pzf_stats    = ['total' => 0, 'count_unpaid' => 0, 'count_paid' => 0,
                 'sales_total_net' => 0, 'received_net' => 0, 'outstanding_net' => 0];

if ($source === 'noninvoice' || $source === 'all') {
    // Budujemy WHERE dla PZF na tych samych filtrach daty/wyszukiwania co FV
    $pzfWhere  = " WHERE 1=1";
    $pzfParams = [];

    if ($date_from !== '') {
        $pzfWhere .= " AND e.issue_date >= :pzf_date_from";
        $pzfParams['pzf_date_from'] = $date_from;
    }
    if ($date_to !== '') {
        $pzfWhere .= " AND e.issue_date <= :pzf_date_to";
        $pzfParams['pzf_date_to'] = $date_to;
    }
    if ($search !== '') {
        $pzfWhere .= " AND (e.entry_number LIKE :pzf_search
                        OR i.name LIKE :pzf_search
                        OR e.counterparty_name_manual LIKE :pzf_search
                        OR e.title LIKE :pzf_search
                        OR e.notes LIKE :pzf_search)";
        $pzfParams['pzf_search'] = '%' . $search . '%';
    }
    if ($client_filter !== '') {
        $pzfWhere .= " AND e.client_id = :pzf_client_id";
        $pzfParams['pzf_client_id'] = $client_filter;
    }
    if ($client_name_filter !== '') {
        $pzfWhere .= " AND (i.name LIKE :pzf_client_name OR e.counterparty_name_manual LIKE :pzf_client_name)";
        $pzfParams['pzf_client_name'] = '%' . $client_name_filter . '%';
    }
    if ($status_filter === 'paid') {
        $pzfWhere .= " AND e.payment_status = 'paid'";
    } elseif ($status_filter === 'issued' || $status_filter === 'draft') {
        $pzfWhere .= " AND e.payment_status = 'unpaid'";
    } elseif (in_array($status_filter, ['partially_paid', 'cancelled'], true)) {
        $pzfWhere .= " AND 1 = 0";
    }
    if ($kind_filter !== 'all' || $retention_filter !== 'all') {
        $pzfWhere .= " AND 1 = 0";
    }

    $pzfFromClause = "
        FROM sales_noninvoice_entries e
        LEFT JOIN investors i ON i.id = e.client_id
    ";

    // KPI PZF
    $pzfKpiSql = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN e.payment_status = 'unpaid' THEN 1 ELSE 0 END) AS count_unpaid,
        SUM(CASE WHEN e.payment_status = 'paid'   THEN 1 ELSE 0 END) AS count_paid,
        COALESCE(SUM(e.amount_net), 0) AS sales_total_net,
        COALESCE(SUM(CASE WHEN e.payment_status = 'paid'   THEN e.amount_net ELSE 0 END), 0) AS received_net,
        COALESCE(SUM(CASE WHEN e.payment_status = 'unpaid' THEN e.amount_net ELSE 0 END), 0) AS outstanding_net
    {$pzfFromClause} {$pzfWhere}";
    $pzfKpiStmt = $pdo->prepare($pzfKpiSql);
    $pzfKpiStmt->execute($pzfParams);
    $pzf_stats = $pzfKpiStmt->fetch(PDO::FETCH_ASSOC) ?: $pzf_stats;

    // Lista PZF
    $pzfListSql = "SELECT e.*,
        COALESCE(i.name, e.counterparty_name_manual) AS client_name, i.nip AS client_nip,
        (SELECT GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ')
         FROM sales_noninvoice_allocations a
         JOIN projects p ON p.id = a.project_id
         WHERE a.entry_id = e.id) AS assigned_projects
    {$pzfFromClause} {$pzfWhere}
    ORDER BY e.issue_date DESC, e.id DESC";
    $pzfListStmt = $pdo->prepare($pzfListSql);
    $pzfListStmt->execute($pzfParams);
    $pzf_entries = $pzfListStmt->fetchAll(PDO::FETCH_ASSOC);
}

// KPI "Razem" (invoice + PZF)
$kpi_combined = [
    'total'            => (int)$stats['total'] + (int)$pzf_stats['total'],
    'count_unpaid'     => (int)$stats['count_issued'] + (int)$pzf_stats['count_unpaid'],
    'count_paid'       => (int)$stats['count_paid'] + (int)$pzf_stats['count_paid'],
    'sales_total_net'  => (float)$stats['total_net'] + (float)$pzf_stats['sales_total_net'],
];

// Clients for filter dropdown
$clientsStmt = $pdo->query("
    SELECT DISTINCT i.id, i.name FROM investors i
    JOIN invoices_sale inv ON inv.client_id = i.id ORDER BY i.name ASC
");
$clients = $clientsStmt->fetchAll();

// Active projects for "Assign to project" modal
$projectsForAssign = $pdo->query("
    SELECT id, name, COALESCE(project_type, 'standard') AS project_type
    FROM projects
    WHERE archived_at IS NULL
    ORDER BY
        CASE WHEN COALESCE(project_type, 'standard') = 'micro' THEN 1 ELSE 0 END,
        name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$standardProjectsForAssign = [];
$microProjectsForAssign = [];
foreach ($projectsForAssign as $assignProjectRow) {
    $ptype = (string)($assignProjectRow['project_type'] ?? 'standard');
    if ($ptype === 'micro') {
        $microProjectsForAssign[] = $assignProjectRow;
    } else {
        $standardProjectsForAssign[] = $assignProjectRow;
    }
}

ob_start();
?>
<option value="">— Wybierz projekt —</option>
<?php if (!empty($standardProjectsForAssign)): ?>
<optgroup label="Projekty">
    <?php foreach ($standardProjectsForAssign as $prj): ?>
        <option value="<?php echo (int)$prj['id']; ?>"><?php echo e($prj['name']); ?></option>
    <?php endforeach; ?>
</optgroup>
<?php endif; ?>
<?php if (!empty($microProjectsForAssign)): ?>
<optgroup label="Mikroprojekty">
    <?php foreach ($microProjectsForAssign as $prj): ?>
        <option value="<?php echo (int)$prj['id']; ?>"><?php echo e($prj['name']); ?></option>
    <?php endforeach; ?>
</optgroup>
<?php endif; ?>
<?php
$projectAssignOptionsHtml = trim((string)ob_get_clean());

$success    = $_GET['success'] ?? '';
$error      = $_GET['error'] ?? '';
$csrfToken  = csrfToken();
$isAdminUser = isAdmin();
$openPzfAllocId = (int)($_GET['open_pzf_alloc'] ?? 0);

// --- Helpers (identical to cost inbox) ---
function sortLinkSale(string $column, string $currentSort, string $currentOrder, string $label): string
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

function pageLinkSale(int $targetPage): string
{
    $p = $_GET;
    $p['page'] = $targetPage;
    return '?' . http_build_query($p);
}

function getRowColorSale(int $index): array
{
    $palette = [
        ['#e8f4fd', '#5b9bd5'], ['#e8f5e9', '#66bb6a'], ['#f3e8fd', '#ab7bd4'],
        ['#fff8e1', '#f9a825'], ['#e0f7fa', '#26c6da'], ['#fce4ec', '#ef5350'],
        ['#e8eaf6', '#7986cb'], ['#f1f8e9', '#9ccc65'], ['#fff3e0', '#ffa726'],
        ['#e0f2f1', '#26a69a'], ['#f3e5f5', '#ba68c8'], ['#e1f5fe', '#29b6f6'],
    ];
    $c = $palette[$index % count($palette)];
    return ['hsl' => $c[0], 'border' => $c[1]];
}

function statusLabel($status)
{
    $labels = [
        'draft'          => 'Szkic',
        'issued'         => 'Wystawiona',
        'paid'           => 'Opłacona',
        'partially_paid' => 'Częśc. opłacona',
        'overdue'        => 'Zaległość',
        'cancelled'      => 'Anulowana',
    ];
    return $labels[$status] ?? $status;
}

function statusBadgeClass($status)
{
    $map = [
        'draft'          => 'badge-draft',
        'issued'         => 'badge-issued',
        'paid'           => 'badge-paid',
        'partially_paid' => 'badge-partially_paid',
        'overdue'        => 'badge-overdue',
        'cancelled'      => 'badge-cancelled',
    ];
    return $map[$status] ?? 'badge-draft';
}

function retentionBadgeClass($computed)
{
    $map = [
        'active'   => 'badge-ret-active',
        'due_soon' => 'badge-ret-due',
        'overdue'  => 'badge-ret-overdue',
        'settled'  => 'badge-ret-settled',
    ];
    return $map[$computed] ?? '';
}

function retentionLabel($computed)
{
    $map = [
        'active'   => 'Retencja',
        'due_soon' => 'Retencja <30d',
        'overdue'  => 'Retencja po terminie',
        'settled'  => 'Retencja rozliczona',
    ];
    return $map[$computed] ?? '';
}

function polishDayShort($dateStr)
{
    $map = ['Mon'=>'Pn','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Cz','Fri'=>'Pt','Sat'=>'So','Sun'=>'Nd'];
    $eng = date('D', strtotime($dateStr));
    return $map[$eng] ?? $eng;
}

function saleStatusSortRank(string $status): int
{
    $map = [
        'draft' => 10,
        'unpaid' => 20,
        'issued' => 30,
        'partially_paid' => 40,
        'paid' => 50,
        'cancelled' => 60,
    ];

    return $map[$status] ?? 999;
}

function normalizeSaleRowInvoice(array $invoice): array
{
    $invoice['source_type'] = 'invoice';
    $invoice['effective_amount_net'] = (float)($invoice['effective_amount_net'] ?? $invoice['amount_net'] ?? 0);
    return $invoice;
}

function normalizeSaleRowPzf(array $entry): array
{
    $dueDate = (string)($entry['due_date'] ?? '');
    $status = (($entry['payment_status'] ?? 'unpaid') === 'paid') ? 'paid' : 'unpaid';

    $entry['source_type'] = 'noninvoice';
    $entry['invoice_number'] = $entry['entry_number'] ?? ('PZF#' . (int)($entry['id'] ?? 0));
    $entry['status'] = $status;
    $entry['days_until_due'] = $dueDate !== ''
        ? (int)floor((strtotime($dueDate . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400)
        : 0;
    $entry['retention_id'] = null;
    $entry['retention_percent'] = null;
    $entry['retention_amount'] = null;
    $entry['retention_return_date'] = null;
    $entry['retention_reminder_date'] = null;
    $entry['retention_status'] = null;
    $entry['retention_settled_at'] = null;
    $entry['retention_notes'] = null;
    $entry['retention_computed'] = null;
    $entry['exclude_from_analytics'] = 0;

    return $entry;
}

function saleRowSortValue(array $row, string $sort)
{
    switch ($sort) {
        case 'invoice_number':
            return mb_strtolower((string)($row['invoice_number'] ?? ''));
        case 'client_name':
            return mb_strtolower((string)($row['client_name'] ?? ''));
        case 'issue_date':
            return (string)($row['issue_date'] ?? '');
        case 'due_date':
            return (string)($row['due_date'] ?? '');
        case 'amount_net':
            return (float)($row['amount_net'] ?? 0);
        case 'amount_gross':
            return (float)($row['amount_gross'] ?? 0);
        case 'status':
            return saleStatusSortRank((string)($row['status'] ?? ''));
        default:
            return (string)($row['issue_date'] ?? '');
    }
}

function sortUnifiedSaleRows(array &$rows, string $sort, string $order): void
{
    usort($rows, function (array $a, array $b) use ($sort, $order) {
        $valueA = saleRowSortValue($a, $sort);
        $valueB = saleRowSortValue($b, $sort);

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

function groupUnifiedSaleRowsByDay(array $rows): array
{
    $rowsByDay = [];
    foreach ($rows as $row) {
        $dayKey = $row['issue_date'] ?: '0000-00-00';
        if (!isset($rowsByDay[$dayKey])) {
            $rowsByDay[$dayKey] = ['items' => [], 'count' => 0, 'total_net' => 0, 'total_gross' => 0];
        }

        $rowsByDay[$dayKey]['items'][] = $row;
        $rowsByDay[$dayKey]['count']++;
        $rowsByDay[$dayKey]['total_net'] += (float)($row['effective_amount_net'] ?? $row['amount_net'] ?? 0);
        $rowsByDay[$dayKey]['total_gross'] += (float)($row['amount_gross'] ?? 0);
    }

    krsort($rowsByDay);
    return $rowsByDay;
}

$invoiceRows = array_map('normalizeSaleRowInvoice', $invoices);
$pzfRows = array_map('normalizeSaleRowPzf', $pzf_entries);

if ($source === 'invoice') {
    $listRows = $invoiceRows;
} elseif ($source === 'noninvoice') {
    $listRows = $pzfRows;
    sortUnifiedSaleRows($listRows, $sort, $order);
    $totalRows = count($listRows);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($requestedPage, $totalPages);
    $offset = ($page - 1) * $perPage;
    $listRows = array_slice($listRows, $offset, $perPage);
} else {
    $listRows = array_merge($invoiceRows, $pzfRows);
    sortUnifiedSaleRows($listRows, $sort, $order);
    $totalRows = count($listRows);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($requestedPage, $totalPages);
    $offset = ($page - 1) * $perPage;
    $listRows = array_slice($listRows, $offset, $perPage);
}

$rowsByDay = groupUnifiedSaleRowsByDay($listRows);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Centrum sprzedaży</title>
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
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .btn {
            padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: 600;
            cursor: pointer; border: none; font-size: 14px; transition: all 0.2s;
            display: inline-block; white-space: nowrap;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-sm { padding: 8px 16px; font-size: 13px; }

        /* KPI */
        .stats-grid {
            display: grid; grid-template-columns: repeat(6, 1fr);
            gap: 16px; margin-bottom: 30px;
        }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 600px)  { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        .stat-card {
            background: white; padding: 18px 20px; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); cursor: pointer; transition: all 0.2s;
            border: 2px solid transparent; text-decoration: none; display: block; color: inherit;
        }
        .stat-card:hover { border-color: var(--primary); transform: translateY(-2px); }
        .stat-card.active { border-color: var(--primary); background: #f0f4ff; }
        .stat-label { font-size: 12px; color: #666; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 26px; font-weight: 700; color: #667eea; }
        .stat-count { font-size: 11px; color: #999; margin-top: 4px; }

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
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 30px; }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        /* SPX FILTER SYSTEM — pixel-identical to cost inbox */
        .spx-filter-bar {
            padding: 12px 20px; background: white; border-bottom: 1px solid var(--border-light);
            display: flex; gap: 8px; align-items: flex-end; flex-wrap: nowrap;
        }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .spx-filter-group.fg-month   { flex: 1.2 1 0; }
        .spx-filter-group.fg-year    { flex: 0.7 1 0; }
        .spx-filter-group.fg-date    { flex: 1   1 0; }
        .spx-filter-group.fg-status  { flex: 1.3 1 0; }
        .spx-filter-group.fg-client  { flex: 1.8 1 0; }
        .spx-filter-group.fg-search  { flex: 2   1 0; }
        .spx-filter-group label {
            font-size: 11px; font-weight: 600; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"],
        .spx-filter-group input[type="text"] {
            padding: 0 8px; height: 38px; border: 1px solid var(--border-light); border-radius: 6px;
            font-size: 13px; background: white; font-family: inherit; transition: border-color 0.15s; width: 100%;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input:focus {
            outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        @media (max-width: 1024px) { .spx-filter-bar { flex-wrap: wrap; } .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; } }
        @media (max-width: 768px) { .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; } .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; } .spx-filter-group select, .spx-filter-group input[type="date"] { height: 44px; font-size: 14px; } }
        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: white; border: 1px solid var(--border-light); border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; white-space: nowrap; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: var(--primary); color: var(--primary); }
        .spx-quick-btn.active { background: var(--primary); border-color: var(--primary); color: white; font-weight: 600; }

        /* Client autocomplete filter */
        .client-filter-bar {
            padding: 8px 20px; background: #f9fafb; border-bottom: 1px solid var(--border-light);
            display: flex; gap: 8px; align-items: center;
        }
        .client-filter-bar label { font-size: 12px; font-weight: 600; color: var(--text-muted); white-space: nowrap; }
        .client-ac-wrap { position: relative; flex: 1; max-width: 400px; }
        .client-ac-wrap input[type="text"] {
            width: 100%; height: 34px; padding: 0 10px; border: 1px solid var(--border-light);
            border-radius: 6px; font-size: 13px; font-family: inherit; transition: border-color 0.15s;
        }
        .client-ac-wrap input[type="text"]:focus {
            outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        .client-ac-list {
            display: none; position: absolute; top: 100%; left: 0; right: 0; max-height: 240px;
            overflow-y: auto; background: white; border: 1px solid var(--primary); border-top: none;
            border-radius: 0 0 6px 6px; box-shadow: 0 6px 16px rgba(0,0,0,0.12); z-index: 100;
        }
        .client-ac-list .ac-item { padding: 8px 12px; font-size: 13px; cursor: pointer; transition: background 0.1s; }
        .client-ac-list .ac-item:hover, .client-ac-list .ac-item.ac-active { background: #e8f4fd; color: var(--primary); }
        .client-ac-list .ac-item strong { font-weight: 700; }
        .client-ac-list .ac-empty { padding: 10px 12px; font-size: 12px; color: var(--text-muted); font-style: italic; }
        .client-filter-bar .btn-clear-client {
            padding: 4px 10px; height: 34px; font-size: 12px; border-radius: 6px;
            border: 1px solid var(--border-light); background: white; color: var(--text-muted);
            cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center;
        }
        .client-filter-bar .btn-clear-client:hover { border-color: var(--danger); color: var(--danger); }

        /* Table — czarne kontury */
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { background: #f1f3f5; }
        th {
            padding: 10px 12px; text-align: left; font-weight: 600; color: #1f2937;
            border: 1px solid #2d3748; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        th a { cursor: pointer; user-select: none; transition: color 0.2s; color: inherit; text-decoration: none; display: block; }
        th a:hover { color: var(--primary); }
        td { padding: 10px 12px; border: 1px solid #2d3748; font-size: 13px; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; }

        /* Column widths for normal table */
        .normal-view th:nth-child(1), .normal-view td:nth-child(1) { width: 14%; }
        .normal-view th:nth-child(2), .normal-view td:nth-child(2) { width: 16%; }
        .normal-view th:nth-child(3), .normal-view td:nth-child(3) { width: 8%; }
        .normal-view th:nth-child(4), .normal-view td:nth-child(4) { width: 9%; }
        .normal-view th:nth-child(5), .normal-view td:nth-child(5) { width: 8%; }
        .normal-view th:nth-child(6), .normal-view td:nth-child(6) { width: 8%; }
        .normal-view th:nth-child(7), .normal-view td:nth-child(7) { width: 10%; }
        .normal-view th:nth-child(8), .normal-view td:nth-child(8) { width: 11%; }
        .normal-view th:nth-child(9), .normal-view td:nth-child(9) { width: 16%; }

        /* Column widths for grouped (day) view — no "Data wyst." column */
        .day-content th:nth-child(1), .day-content td:nth-child(1) { width: 16%; }
        .day-content th:nth-child(2), .day-content td:nth-child(2) { width: 18%; }
        .day-content th:nth-child(3), .day-content td:nth-child(3) { width: 9%; }
        .day-content th:nth-child(4), .day-content td:nth-child(4) { width: 9%; }
        .day-content th:nth-child(5), .day-content td:nth-child(5) { width: 9%; }
        .day-content th:nth-child(6), .day-content td:nth-child(6) { width: 11%; }
        .day-content th:nth-child(7), .day-content td:nth-child(7) { width: 12%; }
        .day-content th:nth-child(8), .day-content td:nth-child(8) { width: 16%; }

        body:not(.no-colors) tbody tr { background: var(--row-bg, #ffffff); border-left: 4px solid var(--row-border, transparent); }
        body:not(.no-colors) tbody tr:hover { filter: brightness(0.95); }
        body.no-colors tbody tr:nth-child(odd)  { background: #ffffff; border-left: 4px solid transparent; }
        body.no-colors tbody tr:nth-child(even) { background: #f8fafc; border-left: 4px solid transparent; }
        body.no-colors tbody tr:hover           { background: #e0f2fe; }

        .btn-color-mode {
            width: 38px; height: 38px; border-radius: 6px; border: 1px solid var(--border-light);
            background: white; cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; padding: 0;
        }
        .btn-color-mode:hover { background: #f9fafb; border-color: var(--primary); }
        .btn-color-mode.active { background: linear-gradient(135deg, #fce7f3, #e0e7ff); border-color: #a78bfa; }
        .btn-color-mode svg { width: 18px; height: 18px; }

        /* Badges — invoice status */
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge-draft     { background: #fff3cd; color: #856404; }
        .badge-issued    { background: #e7f3ff; color: #004080; }
        .badge-paid      { background: #d4edda; color: #155724; }
        .badge-overdue   { background: #f8d7da; color: #721c24; }
        .badge-cancelled { background: #e2e8f0; color: #475569; }
        .badge-partially_paid { background: #fef3c7; color: #92400e; }

        /* Status badge button + panel */
        .status-badge-btn {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600;
            border: 1px solid transparent; cursor: pointer; transition: opacity 0.15s;
        }
        .status-badge-btn:hover { opacity: 0.8; }
        .status-badge-btn.badge-draft { background: #fff3cd; color: #856404; }
        .status-badge-btn.badge-issued { background: #e7f3ff; color: #004080; }
        .status-badge-btn.badge-paid { background: #d4edda; color: #155724; }
        .status-badge-btn.badge-partially_paid { background: #fef3c7; color: #92400e; }
        .status-badge-btn.badge-cancelled { background: #e2e8f0; color: #475569; }
        .paid-hint { font-weight: 400; opacity: 0.75; }

        .status-portal {
            position: fixed; z-index: 9999;
            background: #fff; border: 1px solid var(--border-light); border-radius: 10px;
            padding: 14px; box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            min-width: 320px; max-width: 380px;
        }
        .status-panel-header { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; }
        .status-panel-options { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 10px; }
        .sp-status-opt {
            padding: 4px 10px; border-radius: 8px; font-size: 11px; font-weight: 600;
            border: 1px solid var(--border-light); background: #f8fafc; color: #475569; cursor: pointer;
            transition: background 0.15s;
        }
        .sp-status-opt:hover { background: #e2e8f0; }
        .sp-status-active { background: var(--primary); color: #fff; border-color: var(--primary); cursor: default; }
        .sp-status-active:hover { background: var(--primary); }

        .status-panel-progress { margin-bottom: 10px; }
        .progress-bar-bg { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
        .progress-bar-fill { height: 100%; background: #10b981; border-radius: 3px; transition: width 0.3s; }
        .progress-label { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

        .sp-pay-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 8px; }
        .sp-pay-grid label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 2px; }
        .sp-pay-grid input { width: 100%; padding: 5px 8px; border: 1px solid var(--border-light); border-radius: 6px; font-size: 13px; }
        .sp-pay-submit {
            padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 600;
            background: var(--primary); color: #fff; border: none; cursor: pointer;
        }
        .sp-pay-submit:hover { opacity: 0.9; }

        /* Badges — retention */
        .badge-ret-active  { background: #e0f7fa; color: #00695c; }
        .badge-ret-due     { background: #fff3cd; color: #856404; }
        .badge-ret-overdue { background: #f8d7da; color: #721c24; }
        .badge-ret-settled { background: #d4edda; color: #155724; }

        /* Action buttons */
        .action-buttons { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; }
        .action-btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 4px 10px; height: 26px; border-radius: 4px; text-decoration: none;
            font-size: 11px; font-weight: 600; transition: all 0.2s; border: 1px solid;
            background: white; white-space: nowrap; cursor: pointer;
        }
        .action-btn:hover { transform: translateY(-1px); }
        .action-btn-edit   { color: #059669; border-color: #059669; }
        .action-btn-edit:hover   { background: #059669; color: white; }
        .action-btn-delete { color: #dc2626; border-color: #dc2626; }
        .action-btn-delete:hover { background: #dc2626; color: white; }
        .action-btn-ret    { color: #0891b2; border-color: #0891b2; }
        .action-btn-ret:hover    { background: #0891b2; color: white; }
        .action-btn-settle { color: #16a34a; border-color: #16a34a; }
        .action-btn-settle:hover { background: #16a34a; color: white; }
        .action-btn-exclude { color: #7c3aed; border-color: #7c3aed; }
        .action-btn-exclude:hover { background: #7c3aed; color: white; }
        tr.row-excluded td { opacity: 0.55; }
        tr.row-excluded { background: repeating-linear-gradient(135deg, #faf5ff, #faf5ff 8px, #f3e8ff 8px, #f3e8ff 16px) !important; }

        .delete-form { display: inline; margin: 0; }

        /* Row action: dropdown trigger button */
        .action-btn-more {
            color: #475569; border-color: #d1d5db; background: #f3f4f6;
            gap: 2px; font-size: 13px; padding: 4px 8px; min-width: 28px;
        }
        .action-btn-more:hover { background: #e5e7eb; border-color: #9ca3af; transform: none; }

        /* Portal dropdown (appended to body) */
        .row-dd-portal {
            position: fixed;
            min-width: 220px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,.18);
            z-index: 99999;
            padding: 4px 0;
        }
        .row-dd-portal a,
        .row-dd-portal button {
            display: flex; align-items: center; gap: 8px;
            width: 100%; text-align: left;
            padding: 9px 16px; font-size: 13px; font-weight: 500;
            color: #334155; background: none; border: none;
            cursor: pointer; text-decoration: none;
            transition: background .12s;
        }
        .row-dd-portal a:hover,
        .row-dd-portal button:hover { background: #f0fdf4; color: #059669; }
        .row-dd-sep { height: 1px; background: #e2e8f0; margin: 4px 0; }
        .row-dd-danger { color: #dc2626 !important; }
        .row-dd-danger:hover { background: #fef2f2 !important; color: #dc2626 !important; }

        /* Toggle buttons */
        .spx-controls-right { display: flex; gap: 8px; align-items: center; }
        .btn-group-mode {
            width: 38px; height: 38px; border-radius: 6px; border: 1px solid var(--border-light);
            background: white; cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; padding: 0;
        }
        .btn-group-mode:hover { background: #f9fafb; border-color: var(--primary); }
        .btn-group-mode.active { background: linear-gradient(135deg, #667eea, #764ba2); border-color: #764ba2; }
        .btn-group-mode.active svg { stroke: white; }
        .btn-group-mode svg { width: 18px; height: 18px; }
        .spx-toggle-group { display: flex; gap: 2px; }
        .spx-btn-toggle {
            padding: 0 10px; height: 28px; background: white; border: 1px solid var(--border-light);
            border-radius: 5px; font-size: 11px; font-weight: 600; color: #374151;
            cursor: pointer; transition: all 0.15s;
        }
        .spx-btn-toggle:hover { border-color: var(--primary); color: var(--primary); }
        .spx-separator { width: 1px; height: 22px; background: var(--border-light); }

        /* Day groups (grouped view) */
        .grouped-view { display: none; padding: 16px 20px; }
        .day-group {
            background: white; border: 1px solid var(--border); border-radius: 10px;
            margin-bottom: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            transition: all 0.3s ease;
        }
        .day-group.collapsed { margin-bottom: 8px; box-shadow: none; }
        .day-group.collapsed .day-content { display: none; }
        .day-header {
            background: #f8fafc; padding: 12px 20px; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            cursor: pointer; user-select: none; transition: background 0.2s;
        }
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

        .no-data { padding: 60px 20px; text-align: center; color: #999; font-size: 16px; }
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; overflow-wrap: anywhere; word-break: break-word; }
        .alert-success { background: #d4edda; border-left: 4px solid #28a745; color: #155724; }
        .alert-error   { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }
        .pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 4px; padding: 16px 20px; border-top: 1px solid var(--border-light);
        }
        .pagination a, .pagination span {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 34px; height: 34px; padding: 0 10px; border-radius: 6px;
            font-size: 13px; font-weight: 600; text-decoration: none; transition: all 0.2s;
        }
        .pagination a { color: var(--text-muted); border: 1px solid var(--border-light); background: white; }
        .pagination a:hover { border-color: var(--primary); color: var(--primary); }
        .pagination .current { background: var(--primary); color: white; border: 1px solid var(--primary); }
        .pagination .disabled { color: #d1d5db; border: 1px solid #e5e7eb; cursor: default; pointer-events: none; }
        .pagination .page-info { color: var(--text-muted); font-size: 12px; margin: 0 8px; border: none; min-width: auto; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 13px; }

        /* Retention inline form */
        .ret-form-inline { display: none; padding: 12px; background: #f8fafc; border-top: 1px solid var(--border-light); }
        .ret-form-inline.show { display: block; }
        .ret-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; margin-bottom: 8px; }
        .ret-grid label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 2px; }
        .ret-grid input, .ret-grid textarea { width: 100%; padding: 6px 8px; border: 1px solid var(--border-light); border-radius: 4px; font-size: 13px; font-family: inherit; }
        .ret-grid input:focus, .ret-grid textarea:focus { outline: none; border-color: var(--primary); }
        .retention-link {
            border: 0;
            background: transparent;
            cursor: pointer;
            text-align: left;
            padding: 0;
            display: inline-flex;
            flex-direction: column;
            gap: 2px;
            align-items: flex-start;
        }
        .retention-link:hover .badge {
            filter: brightness(0.96);
        }
        .retention-link-meta {
            font-size: 11px;
            color: var(--text-muted);
            line-height: 1.35;
        }
        .overdue-warning { color: #dc2626; font-weight: 600; font-size: 12px; }

        /* SOURCE SWITCH */
        .source-switch { display: inline-flex; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); border-radius: 8px; overflow: hidden; }
        .source-switch a { padding: 6px 14px; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.7); text-decoration: none; transition: all 0.15s; }
        .source-switch a:hover { background: rgba(255,255,255,0.15); color: #fff; }
        .source-switch a.active { background: rgba(255,255,255,0.22); color: #fff; }
        .row-source-meta {
            display: inline-flex;
            align-items: center;
            margin-top: 4px;
            padding: 2px 7px;
            border-radius: 999px;
            background: #e5e7eb;
            color: #475569;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>

    <div class="container">

        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    Centrum sprzedaży
                </div>
                <h1>Centrum sprzedaży</h1>
                <p>Faktury sprzedażowe i przychody pozafakturowe</p>
                <?php
                $swBase = $_GET;
                unset($swBase['source'], $swBase['page']);
                $swInvoice = '?' . http_build_query(array_merge($swBase, ['source' => 'invoice']));
                $swPzf     = '?' . http_build_query(array_merge($swBase, ['source' => 'noninvoice']));
                $swAll     = '?' . http_build_query(array_merge($swBase, ['source' => 'all']));
                ?>
                <div style="margin-top:10px;">
                    <div class="source-switch">
                        <a href="<?php echo $swInvoice; ?>" class="<?php echo $source === 'invoice' ? 'active' : ''; ?>">Faktury</a>
                        <a href="<?php echo $swPzf; ?>"     class="<?php echo $source === 'noninvoice' ? 'active' : ''; ?>">Pozafakturowe</a>
                        <a href="<?php echo $swAll; ?>"     class="<?php echo $source === 'all' ? 'active' : ''; ?>">Razem</a>
                    </div>
                </div>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.towary'); ?>" class="btn-hero-secondary">Towary i usługi</a>
                <?php if ($source === 'invoice' || $source === 'all'): ?>
                <button type="button" onclick="syncFromFakturownia()" class="btn-hero-secondary" id="sync-btn">Synchronizuj z Fakturowni</button>
                <a href="<?php echo url('finanse.fakturownia-reconcile-sales'); ?>" class="btn-hero-secondary">Rekonsyliacja</a>
                <a href="<?php echo url('finanse.faktury-sprzedazowe.create'); ?>" class="btn-hero-primary">+ Wystaw fakturę</a>
                <a href="<?php echo url('finanse.faktury-sprzedazowe.create-jst'); ?>" class="btn-hero-secondary" style="background:rgba(124,58,237,0.15);border-color:rgba(167,139,250,0.4);color:#ddd6fe;">+ Faktura JST</a>
                <?php endif; ?>
                <?php if ($source === 'noninvoice' || $source === 'all'): ?>
                <a href="<?php echo url('finanse.sprzedaz-pozafakturowa.create') . '?source=' . $source; ?>" class="btn-hero-secondary">+ Dodaj przychód pozafakturowy</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success === 'created'): ?>
            <div class="alert alert-success">Faktura została utworzona pomyślnie.</div>
        <?php elseif ($success === 'updated'): ?>
            <div class="alert alert-success">Faktura została zaktualizowana.</div>
        <?php elseif ($success === 'issued'): ?>
            <div class="alert alert-success">Faktura została wystawiona.</div>
        <?php elseif ($success === 'paid'): ?>
            <div class="alert alert-success">Faktura została oznaczona jako opłacona.</div>
        <?php elseif ($success === 'deleted'): ?>
            <div class="alert alert-success">Faktura została pomyślnie usunięta.</div>
        <?php elseif ($success === 'retention_saved'): ?>
            <div class="alert alert-success">Retencja została zapisana.</div>
        <?php elseif ($success === 'retention_settled'): ?>
            <div class="alert alert-success">Retencja została rozliczona.</div>
        <?php elseif ($success === 'retention_removed'): ?>
            <div class="alert alert-success">Retencja została usunięta.</div>
        <?php elseif ($success === 'status_changed'): ?>
            <div class="alert alert-success">Status faktury został zmieniony.</div>
        <?php elseif ($success === 'exclude_toggled'): ?>
            <div class="alert alert-success">Zaktualizowano wykluczenie faktury z analiz.</div>
        <?php endif; ?>
        <?php if ($error === 'delete_failed'): ?>
            <div class="alert alert-error">Wystąpił błąd podczas usuwania faktury.</div>
        <?php elseif ($error === 'not_found'): ?>
            <div class="alert alert-error">Nie znaleziono faktury.</div>
        <?php elseif ($error === 'pzf_not_found'): ?>
            <div class="alert alert-error">Nie znaleziono wpisu pozafakturowego.</div>
        <?php elseif ($error === 'csrf'): ?>
            <div class="alert alert-error">Sesja wygasła lub nieprawidłowy token. Spróbuj ponownie.</div>
        <?php elseif ($error === 'retention_failed'): ?>
            <div class="alert alert-error">Nie udało się zapisać retencji. Sprawdź logi.</div>
        <?php elseif ($error === 'action_failed'): ?>
            <div class="alert alert-error">Wystąpił błąd. Sprawdź logi.</div>
        <?php endif; ?>
        <?php if (($_GET['deleted_pzf'] ?? '') === '1'): ?>
            <div class="alert alert-success">Wpis pozafakturowy został usunięty.</div>
        <?php endif; ?>

        <!-- KPI Tiles (dynamiczne wg source) -->
        <?php
        $kpiBase = $_GET;
        unset($kpiBase['status'], $kpiBase['retention'], $kpiBase['page']);
        $kpiAllUrl    = '?' . http_build_query(array_merge($kpiBase, ['status' => 'all']));
        $kpiDraftUrl  = '?' . http_build_query(array_merge($kpiBase, ['status' => 'draft']));
        $kpiIssuedUrl = '?' . http_build_query(array_merge($kpiBase, ['status' => 'issued']));
        $kpiPaidUrl   = '?' . http_build_query(array_merge($kpiBase, ['status' => 'paid']));
        $kpiRetUrl    = '?' . http_build_query(array_merge($kpiBase, ['retention' => 'active']));
        ?>
        <?php if ($source === 'invoice'): ?>
        <!-- KPI: tylko faktury (oryginalne kafelki) -->
        <div class="stats-grid">
            <a href="<?php echo $kpiAllUrl; ?>" class="stat-card <?php echo $status_filter === 'all' && $retention_filter === 'all' ? 'active' : ''; ?>"
               data-tooltip="Wszystkie faktury sprzedażowe widoczne po aktualnych filtrach (status + okres).">
                <div class="stat-label">Wszystkie</div>
                <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
            </a>
            <a href="<?php echo $kpiDraftUrl; ?>" class="stat-card <?php echo $status_filter === 'draft' ? 'active' : ''; ?>"
               data-tooltip="Faktury w przygotowaniu — jeszcze nie wystawione i nie wysłane do klienta.">
                <div class="stat-label">Szkice</div>
                <div class="stat-value"><?php echo (int)$stats['count_draft']; ?></div>
            </a>
            <a href="<?php echo $kpiIssuedUrl; ?>" class="stat-card <?php echo $status_filter === 'issued' ? 'active' : ''; ?>"
               data-tooltip="Faktury wystawione, ale jeszcze nieopłacone przez klienta.">
                <div class="stat-label">Wystawione</div>
                <div class="stat-value"><?php echo (int)$stats['count_issued']; ?></div>
            </a>
            <a href="<?php echo $kpiPaidUrl; ?>" class="stat-card <?php echo $status_filter === 'paid' ? 'active' : ''; ?>"
               data-tooltip="Faktury, za które klient już zapłacił.">
                <div class="stat-label">Opłacone</div>
                <div class="stat-value"><?php echo (int)$stats['count_paid']; ?></div>
            </a>
            <div class="stat-card" data-tooltip="Łączna wartość sprzedaży z listy faktur (netto).">
                <div class="stat-label">Wartość sprzedaży</div>
                <div class="stat-value"><?php echo formatMoney($stats['total_net']); ?></div>
            </div>
            <a href="<?php echo $kpiRetUrl; ?>" class="stat-card <?php echo $retention_filter === 'active' ? 'active' : ''; ?>"
               data-tooltip="Faktury z aktywnym zatrzymaniem (retencja) — część kwoty klient zapłaci po zakończeniu projektu.">
                <div class="stat-label">Z retencją</div>
                <div class="stat-value"><?php echo (int)$stats['count_retention']; ?></div>
            </a>
        </div>

        <?php elseif ($source === 'noninvoice'): ?>
        <!-- KPI: tylko PZF -->
        <div class="stats-grid">
            <a href="<?php echo $kpiAllUrl; ?>" class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>"
               data-tooltip="Wszystkie zlecenia sprzedaży pozafakturowej widoczne po aktualnych filtrach.">
                <div class="stat-label">Wszystkie PZF</div>
                <div class="stat-value"><?php echo (int)$pzf_stats['total']; ?></div>
            </a>
            <a href="<?php echo $kpiIssuedUrl; ?>" class="stat-card <?php echo $status_filter === 'issued' ? 'active' : ''; ?>"
               data-tooltip="Zlecenia bez faktury, za które klient jeszcze nie zapłacił w całości.">
                <div class="stat-label">Nieopłacone</div>
                <div class="stat-value"><?php echo (int)$pzf_stats['count_unpaid']; ?></div>
            </a>
            <a href="<?php echo $kpiPaidUrl; ?>" class="stat-card <?php echo $status_filter === 'paid' ? 'active' : ''; ?>"
               data-tooltip="Zlecenia bez faktury, w pełni opłacone przez klienta.">
                <div class="stat-label">Opłacone</div>
                <div class="stat-value"><?php echo (int)$pzf_stats['count_paid']; ?></div>
            </a>
            <div class="stat-card" data-tooltip="Łączna wartość sprzedaży bez faktur (netto) z listy PZF.">
                <div class="stat-label">Wartość sprzedaży</div>
                <div class="stat-value"><?php echo formatMoney($pzf_stats['sales_total_net']); ?></div>
            </div>
            <div class="stat-card" data-tooltip="Ile już wpłynęło od klientów na konto z tych zleceń (netto).">
                <div class="stat-label">Wpłynęło</div>
                <div class="stat-value" style="color:#16a34a;"><?php echo formatMoney($pzf_stats['received_net']); ?></div>
            </div>
            <div class="stat-card" data-tooltip="Ile jeszcze brakuje do pełnej zapłaty (wartość sprzedaży − wpłynęło).">
                <div class="stat-label">Do wpływu</div>
                <div class="stat-value" style="color:#d97706;"><?php echo formatMoney($pzf_stats['outstanding_net']); ?></div>
            </div>
        </div>

        <?php else: /* source = all */ ?>
        <!-- KPI: razem FV + PZF -->
        <div class="stats-grid">
            <div class="stat-card" data-tooltip="Łącznie faktury sprzedażowe + sprzedaż pozafakturowa. W dolnym wierszu rozbicie na FV i PZF.">
                <div class="stat-label">Wszystkie</div>
                <div class="stat-value"><?php echo (int)$kpi_combined['total']; ?></div>
                <div class="stat-count">FV: <?php echo (int)$stats['total']; ?> · PZF: <?php echo (int)$pzf_stats['total']; ?></div>
            </div>
            <div class="stat-card" data-tooltip="Wystawione faktury czekające na zapłatę + zlecenia PZF jeszcze nieopłacone.">
                <div class="stat-label">Nieopłacone / Wystawione</div>
                <div class="stat-value"><?php echo (int)$kpi_combined['count_unpaid']; ?></div>
                <div class="stat-count">FV: <?php echo (int)$stats['count_issued']; ?> · PZF: <?php echo (int)$pzf_stats['count_unpaid']; ?></div>
            </div>
            <div class="stat-card" data-tooltip="Zapłacone faktury + opłacone zlecenia PZF.">
                <div class="stat-label">Opłacone</div>
                <div class="stat-value"><?php echo (int)$kpi_combined['count_paid']; ?></div>
                <div class="stat-count">FV: <?php echo (int)$stats['count_paid']; ?> · PZF: <?php echo (int)$pzf_stats['count_paid']; ?></div>
            </div>
            <div class="stat-card" data-tooltip="Łączna wartość sprzedaży firmy w tym okresie (netto) — faktury + pozafakturowo.">
                <div class="stat-label">Wartość sprzedaży razem</div>
                <div class="stat-value"><?php echo formatMoney($kpi_combined['sales_total_net']); ?></div>
                <div class="stat-count">FV: <?php echo formatMoney($stats['total_net']); ?> · PZF: <?php echo formatMoney($pzf_stats['sales_total_net']); ?></div>
            </div>
            <div class="stat-card" data-tooltip="Ile wpłynęło na konto ze zleceń pozafakturowych (netto). Dla faktur status płatności pochodzi z ich statusów.">
                <div class="stat-label">Wpłynęło (PZF)</div>
                <div class="stat-value" style="color:#16a34a;"><?php echo formatMoney($pzf_stats['received_net']); ?></div>
            </div>
            <div class="stat-card" data-tooltip="Ile jeszcze brakuje do pełnej zapłaty za zlecenia pozafakturowe.">
                <div class="stat-label">Do wpływu (PZF)</div>
                <div class="stat-value" style="color:#d97706;"><?php echo formatMoney($pzf_stats['outstanding_net']); ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filters card -->
        <div class="card">
            <?php
            $fsMonthNames = [1=>'Styczeń',2=>'Luty',3=>'Marzec',4=>'Kwiecień',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpień',9=>'Wrzesień',10=>'Październik',11=>'Listopad',12=>'Grudzień'];
            $fsYearRange = range((int)date('Y') - 3, (int)date('Y'));
            $fsActiveMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $fsYear, $m);
                if ($date_from === $mS && $date_to === date('Y-m-t', strtotime($mS))) { $fsActiveMonth = $m; break; }
            }
            $fsToday = date('Y-m-d'); $fsWeekAgo = date('Y-m-d', strtotime('-7 days'));
            $fsMonthStart = date('Y-m-01'); $fsMonthEnd = date('Y-m-t');
            $fsYearStart = date('Y') . '-01-01';
            ?>
            <form method="GET" action="" class="spx-filter-bar" id="fsFilterForm">
                <div class="spx-filter-group fg-month">
                    <label>Miesiąc</label>
                    <select name="month" id="fsSelectMonth" onchange="fsOnMonthYearChange()">
                        <option value="0" <?php echo $fsActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($fsMonthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($fsActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="fsSelectYear" onchange="fsOnMonthYearChange()">
                        <?php foreach ($fsYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($fsYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                        <option value="all" <?php echo $fsYear === 0 ? 'selected' : ''; ?>>Wszystkie</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="fsInputDateFrom" value="<?php echo e($date_from); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="fsInputDateTo" value="<?php echo e($date_to); ?>">
                </div>
                <div class="spx-filter-group fg-client">
                    <label>Rodzaj faktury</label>
                    <select name="kind" onchange="this.form.submit()">
                        <option value="all"        <?php echo $kind_filter === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="vat"        <?php echo $kind_filter === 'vat' ? 'selected' : ''; ?>>Faktura VAT</option>
                        <option value="proforma"   <?php echo $kind_filter === 'proforma' ? 'selected' : ''; ?>>Proforma</option>
                        <option value="advance"    <?php echo $kind_filter === 'advance' ? 'selected' : ''; ?>>Zaliczkowa</option>
                        <option value="final"      <?php echo $kind_filter === 'final' ? 'selected' : ''; ?>>Końcowa</option>
                        <option value="correction" <?php echo $kind_filter === 'correction' ? 'selected' : ''; ?>>Korekta</option>
                        <option value="bill"       <?php echo $kind_filter === 'bill' ? 'selected' : ''; ?>>Rachunek</option>
                        <option value="receipt"    <?php echo $kind_filter === 'receipt' ? 'selected' : ''; ?>>Paragon</option>
                        <option value="vat_mp"     <?php echo $kind_filter === 'vat_mp' ? 'selected' : ''; ?>>VAT MP</option>
                        <option value="vat_margin" <?php echo $kind_filter === 'vat_margin' ? 'selected' : ''; ?>>VAT marża</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-status">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all"            <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="draft"          <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Szkic</option>
                        <option value="issued"         <?php echo $status_filter === 'issued' ? 'selected' : ''; ?>>Wystawiona</option>
                        <option value="paid"           <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Opłacona</option>
                        <option value="partially_paid" <?php echo $status_filter === 'partially_paid' ? 'selected' : ''; ?>>Częśc. opłacona</option>
                        <option value="cancelled"      <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Anulowana</option>
                    </select>
                </div>
                <div class="spx-filter-group" style="flex:1.1 1 0;">
                    <label>Retencja</label>
                    <select name="retention" onchange="this.form.submit()">
                        <option value="all"     <?php echo $retention_filter === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="active"  <?php echo $retention_filter === 'active' ? 'selected' : ''; ?>>Z retencją</option>
                        <option value="due_soon"<?php echo $retention_filter === 'due_soon' ? 'selected' : ''; ?>>Do 30 dni</option>
                        <option value="overdue" <?php echo $retention_filter === 'overdue' ? 'selected' : ''; ?>>Po terminie</option>
                        <option value="settled" <?php echo $retention_filter === 'settled' ? 'selected' : ''; ?>>Rozliczone</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-search">
                    <label>Szukaj</label>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Numer, kontrahent, notatki...">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                <?php if ($status_filter !== 'all' || $kind_filter !== 'all' || $date_from || $date_to || $search || $client_filter !== '' || $client_name_filter !== '' || $retention_filter !== 'all'): ?>
                    <?php
                    $clearParams = [];
                    if ($source !== 'invoice') {
                        $clearParams['source'] = $source;
                    }
                    $clearUrl = url('finanse.faktury-sprzedazowe') . ($clearParams ? ('?' . http_build_query($clearParams)) : '');
                    ?>
                    <a href="<?php echo $clearUrl; ?>" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center; flex-shrink: 0; white-space: nowrap;">Wyczyść</a>
                <?php endif; ?>
                <?php if ($sort !== 'issue_date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
                <?php if ($client_name_filter !== ''): ?><input type="hidden" name="client_name" value="<?php echo e($client_name_filter); ?>"><?php endif; ?>
                <?php if ($source !== 'invoice'): ?><input type="hidden" name="source" value="<?php echo e($source); ?>"><?php endif; ?>
            </form>

            <!-- Quick date + controls -->
            <?php
            $qbBase = [];
            if ($status_filter !== 'all')    $qbBase['status'] = $status_filter;
            if ($kind_filter !== 'all')      $qbBase['kind'] = $kind_filter;
            if ($client_filter !== '')       $qbBase['client_id'] = $client_filter;
            if ($retention_filter !== 'all') $qbBase['retention'] = $retention_filter;
            if ($search !== '')             $qbBase['search'] = $search;
            if ($client_name_filter !== '') $qbBase['client_name'] = $client_name_filter;
            if ($sort !== 'issue_date')     $qbBase['sort'] = $sort;
            if ($order !== 'DESC')          $qbBase['order'] = $order;
            if ($source !== 'invoice')      $qbBase['source'] = $source;

            $qbToday = '?' . http_build_query(array_merge($qbBase, ['date_from' => $fsToday, 'date_to' => $fsToday]));
            $qb7days = '?' . http_build_query(array_merge($qbBase, ['date_from' => $fsWeekAgo, 'date_to' => $fsToday]));
            $qbMonth = '?' . http_build_query(array_merge($qbBase, ['date_from' => $fsMonthStart, 'date_to' => $fsMonthEnd, 'year' => date('Y')]));
            $qbYear  = '?' . http_build_query(array_merge($qbBase, ['date_from' => $fsYearStart, 'date_to' => $fsToday, 'year' => date('Y')]));
            $qbAll   = '?' . http_build_query(array_merge($qbBase, ['year' => 'all']));
            $isYearAll = ($fsYear === 0);
            ?>
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <a href="<?php echo $qbAll; ?>" class="spx-quick-btn <?php echo $isYearAll ? 'active' : ''; ?>">Wszystkie</a>
                    <a href="<?php echo $qbToday; ?>" class="spx-quick-btn <?php echo ($date_from === $fsToday && $date_to === $fsToday) ? 'active' : ''; ?>">Dziś</a>
                    <a href="<?php echo $qb7days; ?>" class="spx-quick-btn <?php echo ($date_from === $fsWeekAgo && $date_to === $fsToday) ? 'active' : ''; ?>">7 dni</a>
                    <a href="<?php echo $qbMonth; ?>" class="spx-quick-btn <?php echo ($date_from === $fsMonthStart && $date_to === $fsMonthEnd) ? 'active' : ''; ?>">Ten miesiąc</a>
                    <a href="<?php echo $qbYear; ?>" class="spx-quick-btn <?php echo ($date_from === $fsYearStart && $date_to === $fsToday) ? 'active' : ''; ?>">Ten rok</a>
                </div>
                <div class="spx-controls-right">
                    <button class="btn-color-mode active" onclick="toggleColors()" title="Kolory wierszy">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 1 0 20"/><circle cx="12" cy="12" r="4"/>
                        </svg>
                    </button>
                    <div class="spx-separator"></div>
                    <button class="btn-group-mode active" id="btnDayGroup" onclick="toggleDayGrouping()" title="Grupuj po dniach">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                    </button>
                    <div class="spx-toggle-group">
                        <button class="spx-btn-toggle" onclick="expandAllDays()">Rozwiń</button>
                        <button class="spx-btn-toggle" onclick="collapseAllDays()">Zwiń</button>
                    </div>
                </div>
            </div>

            <!-- Client autocomplete filter -->
            <?php
            $clientNamesStmt = $pdo->query("
                SELECT DISTINCT x.name
                FROM (
                    SELECT i.name
                    FROM investors i
                    JOIN invoices_sale inv ON inv.client_id = i.id
                    WHERE i.name IS NOT NULL AND i.name != ''
                    UNION
                    SELECT i2.name
                    FROM investors i2
                    JOIN sales_noninvoice_entries sne ON sne.client_id = i2.id
                    WHERE i2.name IS NOT NULL AND i2.name != ''
                    UNION
                    SELECT sne2.counterparty_name_manual
                    FROM sales_noninvoice_entries sne2
                    WHERE sne2.counterparty_name_manual IS NOT NULL AND sne2.counterparty_name_manual != ''
                ) x
                ORDER BY x.name
            ");
            $allClientNames = $clientNamesStmt->fetchAll(PDO::FETCH_COLUMN);
            $clientNameParams = $_GET;
            unset($clientNameParams['client_name'], $clientNameParams['page']);
            ?>
            <div class="client-filter-bar">
                <label for="clientNameInput">Kontrahent:</label>
                <div class="client-ac-wrap">
                    <input type="text" id="clientNameInput"
                           placeholder="Zacznij wpisywać nazwę kontrahenta..."
                           value="<?php echo e($client_name_filter); ?>"
                           autocomplete="off">
                    <div class="client-ac-list" id="clientAcList"></div>
                </div>
                <?php if ($client_name_filter !== ''): ?>
                    <a href="?<?php echo http_build_query($clientNameParams); ?>" class="btn-clear-client">✕ Wyczyść</a>
                <?php endif; ?>
            </div>
            <script>var _clientData = <?php echo json_encode(array_values($allClientNames), JSON_UNESCAPED_UNICODE); ?>;</script>

            <?php
            $numberHeaderLabel = $source === 'invoice' ? 'Nr faktury' : 'Numer';
            $dayCountLabel = $source === 'invoice' ? 'fakt.' : ($source === 'noninvoice' ? 'wpis.' : 'poz.');
            $infoHeaderLabel = $source === 'invoice' ? 'Retencja' : ($source === 'noninvoice' ? 'Alokacja' : 'Retencja / alokacja');
            ?>
            <?php if (count($listRows) > 0): ?>

            <!-- ============ NORMAL TABLE VIEW ============ -->
            <div class="normal-view" style="display:none;">
              <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo sortLinkSale('invoice_number', $sort, $order, $numberHeaderLabel); ?></th>
                            <th><?php echo sortLinkSale('client_name',    $sort, $order, 'Kontrahent'); ?></th>
                            <th><?php echo sortLinkSale('issue_date',     $sort, $order, 'Data wyst.'); ?></th>
                            <th><?php echo sortLinkSale('due_date',       $sort, $order, 'Termin pł.'); ?></th>
                            <th><?php echo sortLinkSale('amount_net',     $sort, $order, 'Netto'); ?></th>
                            <th><?php echo sortLinkSale('amount_gross',   $sort, $order, 'Brutto'); ?></th>
                            <th><?php echo sortLinkSale('status',         $sort, $order, 'Status'); ?></th>
                            <th><?php echo $infoHeaderLabel; ?></th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rowIndex = 0; foreach ($listRows as $inv): $colors = getRowColorSale($rowIndex++); ?>
                        <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>" <?php echo !empty($inv['exclude_from_analytics']) ? 'class="row-excluded"' : ''; ?>>
                            <?php
                            if (($inv['source_type'] ?? 'invoice') === 'invoice') {
                                include __DIR__ . '/_sale-row.php';
                            } else {
                                include __DIR__ . '/_sale-pzf-row.php';
                            }
                            ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
              </div>
              <?php if ($totalPages > 1): ?>
              <div class="pagination">
                <?php if ($page > 1): ?><a href="<?php echo pageLinkSale($page - 1); ?>">Poprzednia</a><?php else: ?><span class="disabled">Poprzednia</span><?php endif; ?>
                <?php $startPage = max(1, $page - 3); $endPage = min($totalPages, $page + 3);
                if ($startPage > 1): ?><a href="<?php echo pageLinkSale(1); ?>">1</a><?php if ($startPage > 2): ?><span class="page-info">...</span><?php endif; endif;
                for ($i = $startPage; $i <= $endPage; $i++): if ($i === $page): ?><span class="current"><?php echo $i; ?></span><?php else: ?><a href="<?php echo pageLinkSale($i); ?>"><?php echo $i; ?></a><?php endif; endfor;
                if ($endPage < $totalPages): ?><?php if ($endPage < $totalPages - 1): ?><span class="page-info">...</span><?php endif; ?><a href="<?php echo pageLinkSale($totalPages); ?>"><?php echo $totalPages; ?></a><?php endif; ?>
                <?php if ($page < $totalPages): ?><a href="<?php echo pageLinkSale($page + 1); ?>">Następna</a><?php else: ?><span class="disabled">Następna</span><?php endif; ?>
                <span class="page-info">(<?php echo $totalRows; ?> rekordów)</span>
              </div>
              <?php endif; ?>
            </div>

            <!-- ============ GROUPED BY DAY VIEW ============ -->
            <div class="grouped-view">
                <?php foreach ($rowsByDay as $dayDate => $dayData):
                    $dayName = ($dayDate !== '0000-00-00') ? polishDayShort($dayDate) : '—';
                    $dayDateFmt = ($dayDate !== '0000-00-00') ? formatDate($dayDate) : 'Brak daty';
                ?>
                <div class="day-group" data-date="<?php echo e($dayDate); ?>">
                    <div class="day-header" onclick="toggleDay(this)">
                        <div class="dh-left">
                            <span class="dh-dayname"><?php echo $dayName; ?></span>
                            <span class="dh-date"><?php echo $dayDateFmt; ?></span>
                        </div>
                        <div class="dh-right">
                            <div class="dh-stats">
                                <div class="dh-stat"><span class="dh-stat-value"><?php echo $dayData['count']; ?></span> <span><?php echo $dayCountLabel; ?></span></div>
                                <div class="dh-stat"><span class="dh-stat-value" style="color:var(--primary);"><?php echo formatMoney($dayData['total_net']); ?></span> <span>netto</span></div>
                            </div>
                            <span class="dh-arrow">&#9660;</span>
                        </div>
                    </div>
                    <div class="day-content">
                        <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo $numberHeaderLabel; ?></th>
                                    <th>Kontrahent</th>
                                    <th>Termin pł.</th>
                                    <th>Netto</th>
                                    <th>Brutto</th>
                                    <th>Status</th>
                                    <th><?php echo $infoHeaderLabel; ?></th>
                                    <th>Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowIndex = 0; $__dayGroupMode = true; foreach ($dayData['items'] as $inv): $colors = getRowColorSale($rowIndex++); ?>
                                <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>" <?php echo !empty($inv['exclude_from_analytics']) ? 'class="row-excluded"' : ''; ?>>
                                    <?php
                                    if (($inv['source_type'] ?? 'invoice') === 'invoice') {
                                        include __DIR__ . '/_sale-row.php';
                                    } else {
                                        include __DIR__ . '/_sale-pzf-row.php';
                                    }
                                    ?>
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
                    <?php if ($page > 1): ?><a href="<?php echo pageLinkSale($page - 1); ?>">Poprzednia</a><?php else: ?><span class="disabled">Poprzednia</span><?php endif; ?>
                    <?php $startPage = max(1, $page - 3); $endPage = min($totalPages, $page + 3);
                    if ($startPage > 1): ?><a href="<?php echo pageLinkSale(1); ?>">1</a><?php if ($startPage > 2): ?><span class="page-info">...</span><?php endif; endif;
                    for ($i = $startPage; $i <= $endPage; $i++): if ($i === $page): ?><span class="current"><?php echo $i; ?></span><?php else: ?><a href="<?php echo pageLinkSale($i); ?>"><?php echo $i; ?></a><?php endif; endfor;
                    if ($endPage < $totalPages): ?><?php if ($endPage < $totalPages - 1): ?><span class="page-info">...</span><?php endif; ?><a href="<?php echo pageLinkSale($totalPages); ?>"><?php echo $totalPages; ?></a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a href="<?php echo pageLinkSale($page + 1); ?>">Następna</a><?php else: ?><span class="disabled">Następna</span><?php endif; ?>
                    <span class="page-info">(<?php echo $totalRows; ?> rekordów)</span>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
                <div class="no-data">Brak pozycji sprzedażowych do wyświetlenia.</div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>

    <script>
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
            localStorage.setItem('saleInboxColorsEnabled', isColored ? '1' : '0');
            applyColors(isColored);
        }

        /* --- Day grouping toggle --- */
        function setViewMode(isGrouped) {
            var nv = document.querySelector('.normal-view');
            var gv = document.querySelector('.grouped-view');
            var btn = document.getElementById('btnDayGroup');
            if (isGrouped) {
                if (nv) nv.style.display = 'none';
                if (gv) gv.style.display = 'block';
                if (btn) btn.classList.add('active');
            } else {
                if (nv) nv.style.display = 'block';
                if (gv) gv.style.display = 'none';
                if (btn) btn.classList.remove('active');
            }
            localStorage.setItem('saleInboxDayGrouping', isGrouped ? '1' : '0');
            applyColors(!document.body.classList.contains('no-colors'));
        }
        function toggleDayGrouping() {
            var isGrouped = localStorage.getItem('saleInboxDayGrouping') !== '0';
            setViewMode(!isGrouped);
        }
        function toggleDay(header) { header.parentElement.classList.toggle('collapsed'); }
        function expandAllDays() { document.querySelectorAll('.day-group').forEach(function(g) { g.classList.remove('collapsed'); }); }
        function collapseAllDays() { document.querySelectorAll('.day-group').forEach(function(g) { g.classList.add('collapsed'); }); }

        /* --- Status panel — portal pattern (appended to <body>) --- */
        var activeStatusPortal = null;
        var activeStatusBtn = null;

        function openStatusPortal(e, btn, invId) {
            e.preventDefault();
            e.stopPropagation();
            if (activeStatusPortal && activeStatusBtn === btn) { closeStatusPortal(); return; }
            closeStatusPortal();
            var tpl = document.querySelector('.status-panel-tpl[data-inv-id="' + invId + '"]');
            if (!tpl) return;
            var clone = tpl.content.cloneNode(true);
            var portal = clone.firstElementChild;
            document.body.appendChild(portal);
            var rect = btn.getBoundingClientRect();
            portal.style.top = (rect.bottom + 4) + 'px';
            portal.style.left = Math.max(8, rect.left) + 'px';
            requestAnimationFrame(function() {
                var pr = portal.getBoundingClientRect();
                if (pr.bottom > window.innerHeight - 8) portal.style.top = Math.max(8, rect.top - pr.height - 4) + 'px';
                if (pr.right > window.innerWidth - 8) portal.style.left = Math.max(8, window.innerWidth - pr.width - 8) + 'px';
            });
            activeStatusPortal = portal;
            activeStatusBtn = btn;
        }

        function openStatusPortalById(invId) {
            var btn = document.querySelector('.status-panel-tpl[data-inv-id="' + invId + '"]');
            if (!btn) return;
            var badgeBtn = btn.parentElement.querySelector('.status-badge-btn');
            if (badgeBtn) openStatusPortal({preventDefault:function(){},stopPropagation:function(){}}, badgeBtn, invId);
        }

        function closeStatusPortal() {
            if (activeStatusPortal) { activeStatusPortal.remove(); activeStatusPortal = null; activeStatusBtn = null; }
        }

        document.addEventListener('click', function(e) {
            if (activeStatusPortal && !activeStatusPortal.contains(e.target) && !e.target.closest('.status-badge-btn')) {
                closeStatusPortal();
            }
        });
        document.addEventListener('scroll', function() { closeStatusPortal(); }, true);
        window.addEventListener('resize', function() { closeStatusPortal(); });

        /* --- Retention form toggle --- */
        function toggleRetForm(id) {
            var forms = document.querySelectorAll('.js-ret-form[data-ret-id="' + id + '"]');
            forms.forEach(function(el) {
                el.classList.toggle('show');
            });
        }

        function fsOnMonthYearChange() {
            var month   = parseInt(document.getElementById('fsSelectMonth').value);
            var yearVal = document.getElementById('fsSelectYear').value;
            var dfEl = document.getElementById('fsInputDateFrom');
            var dtEl = document.getElementById('fsInputDateTo');
            if (yearVal === 'all') {
                dfEl.value = '';
                dtEl.value = '';
                document.getElementById('fsFilterForm').submit();
                return;
            }
            var year = parseInt(yearVal);
            if (month >= 1 && month <= 12) {
                var lastDay = new Date(year, month, 0).getDate();
                var pad = function(n) { return String(n).padStart(2, '0'); };
                dfEl.value = year + '-' + pad(month) + '-01';
                dtEl.value = year + '-' + pad(month) + '-' + pad(lastDay);
            } else {
                dfEl.value = year + '-01-01';
                dtEl.value = year + '-12-31';
            }
            document.getElementById('fsFilterForm').submit();
        }

        /* --- Client autocomplete --- */
        (function() {
            var input = document.getElementById('clientNameInput');
            var list = document.getElementById('clientAcList');
            if (!input || !list) return;
            var data = window._clientData || [];
            var activeIdx = -1;

            function applyFilter(val) {
                var params = new URLSearchParams(window.location.search);
                if (val) { params.set('client_name', val); } else { params.delete('client_name'); }
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
                if (item) { e.preventDefault(); applyFilter(item.getAttribute('data-val')); }
            });

            input.addEventListener('keydown', function(e) {
                var items = list.querySelectorAll('.ac-item');
                if (e.key === 'ArrowDown') {
                    e.preventDefault(); activeIdx = Math.min(activeIdx + 1, items.length - 1); updateActive(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); updateActive(items);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIdx >= 0 && items[activeIdx]) { applyFilter(items[activeIdx].getAttribute('data-val')); }
                    else { applyFilter(this.value.trim()); }
                } else if (e.key === 'Escape') { list.style.display = 'none'; }
            });

            function updateActive(items) {
                for (var i = 0; i < items.length; i++) { items[i].classList.toggle('ac-active', i === activeIdx); }
                if (items[activeIdx]) items[activeIdx].scrollIntoView({block: 'nearest'});
            }

            document.addEventListener('click', function(e) {
                if (!input.contains(e.target) && !list.contains(e.target)) { list.style.display = 'none'; }
            });
        })();

        /* --- Init --- */
        document.addEventListener('DOMContentLoaded', function () {
            var colorsEnabled = localStorage.getItem('saleInboxColorsEnabled');
            if (colorsEnabled === '0') {
                document.body.classList.add('no-colors');
                var btn = document.querySelector('.btn-color-mode');
                if (btn) btn.classList.remove('active');
            }
            var dayGrouping = localStorage.getItem('saleInboxDayGrouping');
            setViewMode(dayGrouping !== '0');
            applyColors(!document.body.classList.contains('no-colors'));
        });

        /* Row dropdown — portal pattern: menu is appended to <body> */
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

            var tpl = btn.closest('.action-buttons').querySelector('.row-dd-tpl');
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
        document.addEventListener('scroll', function() { closeRowDropdown(); }, true);
        window.addEventListener('resize', function() { closeRowDropdown(); });

        function sendInvoiceEmail(fakturowniaId, invoiceNumber) {
            if (!confirm('Wysłać fakturę ' + invoiceNumber + ' emailem do klienta?')) return;
            fetch('/api/invoices-sale/send-email.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'fakturownia_id=' + fakturowniaId
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    alert(data.message || 'Wysłano!');
                } else {
                    alert('Błąd: ' + (data.error || 'Nieznany błąd'));
                }
            })
            .catch(function() { alert('Błąd połączenia z API'); });
        }

        function syncFromFakturownia() {
            const btn = document.getElementById('sync-btn');
            const origText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Synchronizuję...';

            const fd = new FormData();
            fd.append('period', 'last_30_days');

            fetch('/api/invoices-sale/sync-from-fakturownia.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message || 'Synchronizacja zakończona.');
                        location.reload();
                    } else {
                        alert('Błąd: ' + (data.error || 'Nieznany błąd'));
                        btn.disabled = false;
                        btn.textContent = origText;
                    }
                })
                .catch(() => { alert('Błąd połączenia z API'); btn.disabled = false; btn.textContent = origText; });
        }

        /* --- Alokacja do projektów modal --- */
        var _allocInvId = 0, _allocInvNet = 0, _allocInvIsCorrection = false;
        function openAssignProjectModal(invoiceId, invoiceNumber) {
            _allocInvId = invoiceId;
            document.getElementById('alloc-modal-title').textContent = invoiceNumber;
            document.getElementById('alloc-modal').style.display = 'flex';
            document.getElementById('alloc-add-project').value = '';
            document.getElementById('alloc-add-amount').value = '';
            document.getElementById('alloc-add-node').innerHTML = '<option value="">— Bez etapu —</option>';
            document.getElementById('alloc-node-wrap').style.display = 'none';
            fetch('/api/invoices-sale/assign-project.php?action=invoice&invoice_id=' + invoiceId)
                .then(function(r){ return r.json(); })
                .then(function(inv){
                    _allocInvNet = parseFloat(inv.amount_net) || 0;
                    _allocInvIsCorrection = !!inv.is_correction;
                    document.getElementById('alloc-add-amount').min = _allocInvIsCorrection ? '' : '0.01';
                    document.getElementById('alloc-inv-net').textContent = _allocInvNet.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' zł';
                    loadAllocations();
                });
        }
        function closeAssignModal() { document.getElementById('alloc-modal').style.display = 'none'; }
        function loadAllocations() {
            fetch('/api/invoices-sale/assign-project.php?action=allocations&invoice_id=' + _allocInvId)
                .then(function(r){ return r.json(); })
                .then(function(rows){
                    var tbody = document.getElementById('alloc-tbody');
                    var sum = 0;
                    if (!rows.length) {
                        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#9ca3af;padding:12px;">Brak alokacji</td></tr>';
                    } else {
                        var html = '';
                        rows.forEach(function(a){
                            sum += parseFloat(a.amount_net);
                            html += '<tr>'
                                + '<td style="padding:6px 8px;">' + a.project_name + (a.node_name ? ' <span style="color:#9ca3af;">/ '+a.node_name+'</span>' : '') + '</td>'
                                + '<td style="padding:6px 8px;text-align:right;font-weight:600;">' + parseFloat(a.amount_net).toFixed(2) + '</td>'
                                + '<td style="padding:6px 8px;color:#9ca3af;font-size:12px;">' + (a.description || '') + '</td>'
                                + '<td style="padding:6px 8px;text-align:center;"><button type="button" onclick="deleteAllocation('+a.id+')" style="color:#dc2626;background:none;border:none;cursor:pointer;font-size:13px;" title="Usuń">✕</button></td>'
                                + '</tr>';
                        });
                        tbody.innerHTML = html;
                    }
                    var base = Math.abs(_allocInvNet);
                    var pct = base > 0 ? Math.min(100, (Math.abs(sum) / base * 100)) : 0;
                    document.getElementById('alloc-bar-fill').style.width = pct + '%';
                    document.getElementById('alloc-bar-fill').style.background = pct > 100 ? '#dc2626' : '#10b981';
                    document.getElementById('alloc-sum-label').textContent = sum.toFixed(2) + ' / ' + _allocInvNet.toFixed(2) + ' netto (' + pct.toFixed(0) + '%)';
                    var remain = _allocInvNet - sum;
                    document.getElementById('alloc-add-amount').placeholder = (_allocInvIsCorrection || remain > 0) ? remain.toFixed(2) : '0.00';
                });
        }
        function onAllocProjectChange() {
            var pid = document.getElementById('alloc-add-project').value;
            var nw = document.getElementById('alloc-node-wrap');
            var ns = document.getElementById('alloc-add-node');
            if (!pid) { nw.style.display = 'none'; return; }
            nw.style.display = 'block';
            ns.innerHTML = '<option value="">Ładuję…</option>';
            fetch('/api/invoices-sale/assign-project.php?action=nodes&project_id=' + pid)
                .then(function(r){ return r.json(); })
                .then(function(nodes){
                    var html = '<option value="">— Bez etapu —</option>';
                    nodes.filter(function(n){return !n.parent_id;}).forEach(function(root){
                        html += '<option value="'+root.id+'">'+root.name+'</option>';
                        nodes.filter(function(n){return n.parent_id == root.id;}).forEach(function(ch){
                            html += '<option value="'+ch.id+'">&nbsp;&nbsp;↳ '+ch.name+'</option>';
                        });
                    });
                    ns.innerHTML = html;
                });
        }
        function addAllocation() {
            var pid = document.getElementById('alloc-add-project').value;
            var amt = document.getElementById('alloc-add-amount').value || document.getElementById('alloc-add-amount').placeholder;
            var nid = document.getElementById('alloc-add-node').value;
            if (!pid) { alert('Wybierz projekt'); return; }
            var parsedAmt = parseFloat(amt);
            if (!amt || isNaN(parsedAmt) || (!_allocInvIsCorrection && parsedAmt <= 0)) {
                alert(_allocInvIsCorrection ? 'Podaj kwotę korekty' : 'Podaj kwotę > 0');
                return;
            }
            var body = 'action=add&invoice_id=' + _allocInvId + '&project_id=' + pid + '&amount_net=' + amt;
            if (nid) body += '&cost_node_id=' + nid;
            fetch('/api/invoices-sale/assign-project.php', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body
            }).then(function(r){ return r.json(); }).then(function(d){
                if (d.success) {
                    document.getElementById('alloc-add-project').value = '';
                    document.getElementById('alloc-add-amount').value = '';
                    document.getElementById('alloc-node-wrap').style.display = 'none';
                    loadAllocations();
                } else { alert(d.error || 'Błąd'); }
            });
        }
        function deleteAllocation(id) {
            if (!confirm('Usunąć alokację?')) return;
            fetch('/api/invoices-sale/assign-project.php', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=delete&allocation_id=' + id
            }).then(function(r){ return r.json(); }).then(function(){ loadAllocations(); });
        }
        function allocSetFull() {
            var pid = document.getElementById('alloc-add-project').value;
            if (!pid) { alert('Wybierz najpierw projekt'); return; }
            var nid = document.getElementById('alloc-add-node').value || '';
            if (!confirm('Przypisać całą kwotę FV do tego projektu? Istniejące alokacje zostaną usunięte.')) return;
            fetch('/api/invoices-sale/assign-project.php', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=set&invoice_id=' + _allocInvId + '&project_id=' + pid + '&cost_node_id=' + nid
            }).then(function(r){ return r.json(); }).then(function(d){
                if (d.success) loadAllocations(); else alert(d.error || 'Błąd');
            });
        }
        function allocUnassignAll() {
            if (!confirm('Odpiąć fakturę od wszystkich projektów?')) return;
            fetch('/api/invoices-sale/assign-project.php', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=set&invoice_id=' + _allocInvId + '&project_id=0'
            }).then(function(r){ return r.json(); }).then(function(){ loadAllocations(); });
        }

        /* --- Alokacja PZF modal (wizualnie identyczny jak FV) --- */
        var _allocPzfId = 0, _allocPzfNet = 0, _allocPzfCurrency = 'PLN';
        function openAssignProjectModalPzf(entryId, entryNumber) {
            _allocPzfId = entryId;
            document.getElementById('pzf-alloc-modal-title').textContent = entryNumber;
            document.getElementById('pzf-alloc-modal').style.display = 'flex';
            document.getElementById('pzf-alloc-add-project').value = '';
            document.getElementById('pzf-alloc-add-amount').value = '';
            document.getElementById('pzf-alloc-add-node').innerHTML = '<option value="">— Bez etapu —</option>';
            document.getElementById('pzf-alloc-node-wrap').style.display = 'none';
            fetch('/api/sales-noninvoice/assign-project.php?action=entry&entry_id=' + entryId)
                .then(function(r){ return r.json(); })
                .then(function(entry){
                    if (entry.entry_number) {
                        document.getElementById('pzf-alloc-modal-title').textContent = entry.entry_number;
                    }
                    _allocPzfNet = parseFloat(entry.amount_net) || 0;
                    _allocPzfCurrency = entry.currency || 'PLN';
                    document.getElementById('pzf-alloc-entry-net').textContent = _allocPzfNet.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ' + _allocPzfCurrency;
                    loadPzfAllocations();
                });
        }
        function closeAssignPzfModal() { document.getElementById('pzf-alloc-modal').style.display = 'none'; }
        function loadPzfAllocations() {
            fetch('/api/sales-noninvoice/assign-project.php?action=allocations&entry_id=' + _allocPzfId)
                .then(function(r){ return r.json(); })
                .then(function(rows){
                    var tbody = document.getElementById('pzf-alloc-tbody');
                    var sum = 0;
                    if (!rows.length) {
                        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#9ca3af;padding:12px;">Brak alokacji</td></tr>';
                    } else {
                        var html = '';
                        rows.forEach(function(a){
                            sum += parseFloat(a.amount_net);
                            html += '<tr>'
                                + '<td style="padding:6px 8px;">' + a.project_name + (a.node_name ? ' <span style="color:#9ca3af;">/ ' + a.node_name + '</span>' : '') + '</td>'
                                + '<td style="padding:6px 8px;text-align:right;font-weight:600;">' + parseFloat(a.amount_net).toFixed(2) + '</td>'
                                + '<td style="padding:6px 8px;color:#9ca3af;font-size:12px;">' + (a.description || '') + '</td>'
                                + '<td style="padding:6px 8px;text-align:center;"><button type="button" onclick="deletePzfAllocation(' + a.id + ')" style="color:#dc2626;background:none;border:none;cursor:pointer;font-size:13px;" title="Usuń">✕</button></td>'
                                + '</tr>';
                        });
                        tbody.innerHTML = html;
                    }
                    var pct = _allocPzfNet > 0 ? Math.min(100, (sum / _allocPzfNet * 100)) : 0;
                    document.getElementById('pzf-alloc-bar-fill').style.width = pct + '%';
                    document.getElementById('pzf-alloc-bar-fill').style.background = pct > 100 ? '#dc2626' : '#10b981';
                    document.getElementById('pzf-alloc-sum-label').textContent = sum.toFixed(2) + ' / ' + _allocPzfNet.toFixed(2) + ' netto (' + pct.toFixed(0) + '%)';
                    var remain = _allocPzfNet - sum;
                    document.getElementById('pzf-alloc-add-amount').placeholder = remain > 0 ? remain.toFixed(2) : '0.00';
                });
        }
        function onPzfAllocProjectChange() {
            var pid = document.getElementById('pzf-alloc-add-project').value;
            var nw = document.getElementById('pzf-alloc-node-wrap');
            var ns = document.getElementById('pzf-alloc-add-node');
            if (!pid) { nw.style.display = 'none'; return; }
            nw.style.display = 'block';
            ns.innerHTML = '<option value="">Ładuję…</option>';
            fetch('/api/sales-noninvoice/assign-project.php?action=nodes&project_id=' + pid)
                .then(function(r){ return r.json(); })
                .then(function(nodes){
                    var html = '<option value="">— Bez etapu —</option>';
                    nodes.filter(function(n){ return !n.parent_id; }).forEach(function(root){
                        html += '<option value="' + root.id + '">' + root.name + '</option>';
                        nodes.filter(function(n){ return n.parent_id == root.id; }).forEach(function(ch){
                            html += '<option value="' + ch.id + '">&nbsp;&nbsp;↳ ' + ch.name + '</option>';
                        });
                    });
                    ns.innerHTML = html;
                });
        }
        function addPzfAllocation() {
            var pid = document.getElementById('pzf-alloc-add-project').value;
            var amt = document.getElementById('pzf-alloc-add-amount').value || document.getElementById('pzf-alloc-add-amount').placeholder;
            var nid = document.getElementById('pzf-alloc-add-node').value;
            if (!pid) { alert('Wybierz projekt'); return; }
            if (!amt || parseFloat(amt) <= 0) { alert('Podaj kwotę > 0'); return; }
            var body = 'action=add&entry_id=' + _allocPzfId + '&project_id=' + pid + '&amount_net=' + amt;
            if (nid) body += '&cost_node_id=' + nid;
            fetch('/api/sales-noninvoice/assign-project.php', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'}, body: body
            }).then(function(r){ return r.json(); }).then(function(d){
                if (d.success) {
                    document.getElementById('pzf-alloc-add-project').value = '';
                    document.getElementById('pzf-alloc-add-amount').value = '';
                    document.getElementById('pzf-alloc-node-wrap').style.display = 'none';
                    loadPzfAllocations();
                } else { alert(d.error || 'Błąd'); }
            });
        }
        function deletePzfAllocation(id) {
            if (!confirm('Usunąć alokację?')) return;
            fetch('/api/sales-noninvoice/assign-project.php', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=delete&allocation_id=' + id
            }).then(function(r){ return r.json(); }).then(function(){ loadPzfAllocations(); });
        }
        function allocSetFullPzf() {
            var pid = document.getElementById('pzf-alloc-add-project').value;
            if (!pid) { alert('Wybierz najpierw projekt'); return; }
            var nid = document.getElementById('pzf-alloc-add-node').value || '';
            if (!confirm('Przypisać całą kwotę wpisu do tego projektu? Istniejące alokacje zostaną usunięte.')) return;
            fetch('/api/sales-noninvoice/assign-project.php', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=set&entry_id=' + _allocPzfId + '&project_id=' + pid + '&cost_node_id=' + nid
            }).then(function(r){ return r.json(); }).then(function(d){
                if (d.success) loadPzfAllocations(); else alert(d.error || 'Błąd');
            });
        }
        function allocUnassignAllPzf() {
            if (!confirm('Odpiąć wpis od wszystkich projektów?')) return;
            fetch('/api/sales-noninvoice/assign-project.php', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: 'action=set&entry_id=' + _allocPzfId + '&project_id=0'
            }).then(function(r){ return r.json(); }).then(function(){ loadPzfAllocations(); });
        }

        <?php if ($openPzfAllocId > 0): ?>
        window.addEventListener('load', function() {
            openAssignProjectModalPzf(<?php echo $openPzfAllocId; ?>, 'PZF');
        });
        <?php endif; ?>
    </script>

    <!-- Alokacja do projektów modal -->
    <div id="alloc-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);align-items:center;justify-content:center;" onclick="if(event.target===this)closeAssignModal()">
        <div style="background:#fff;border-radius:14px;padding:28px 32px;max-width:560px;width:95%;box-shadow:0 20px 60px rgba(0,0,0,.25);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <div>
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">Alokacja do projektów</div>
                    <h3 id="alloc-modal-title" style="margin:2px 0 0;font-size:16px;font-weight:700;"></h3>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px;color:#6b7280;">Kwota netto FV</div>
                    <div id="alloc-inv-net" style="font-size:16px;font-weight:700;color:#2563eb;">—</div>
                </div>
            </div>

            <!-- Progress -->
            <div style="margin-bottom:14px;">
                <div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                    <div id="alloc-bar-fill" style="height:100%;width:0%;background:#10b981;border-radius:3px;transition:width .3s;"></div>
                </div>
                <div id="alloc-sum-label" style="font-size:11px;color:#6b7280;margin-top:3px;">0.00 / 0.00 netto (0%)</div>
            </div>

            <!-- Existing allocations -->
            <table style="width:100%;border-collapse:collapse;margin-bottom:14px;font-size:13px;">
                <thead><tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="padding:6px 8px;text-align:left;font-weight:600;color:#374151;">Projekt</th>
                    <th style="padding:6px 8px;text-align:right;font-weight:600;color:#374151;">Kwota netto</th>
                    <th style="padding:6px 8px;text-align:left;font-weight:600;color:#374151;">Opis</th>
                    <th style="padding:6px 8px;width:32px;"></th>
                </tr></thead>
                <tbody id="alloc-tbody"><tr><td colspan="4" style="text-align:center;color:#9ca3af;padding:12px;">Ładuję…</td></tr></tbody>
            </table>

            <!-- Add form -->
            <div style="background:#f8fafc;border-radius:10px;padding:14px;margin-bottom:14px;">
                <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:8px;">Dodaj alokację</div>
                <div style="display:grid;grid-template-columns:1fr 100px;gap:8px;margin-bottom:8px;">
                    <select id="alloc-add-project" onchange="onAllocProjectChange()" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                        <?php echo $projectAssignOptionsHtml; ?>
                    </select>
                    <input id="alloc-add-amount" type="number" step="0.01" placeholder="0.00" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;text-align:right;">
                </div>
                <div id="alloc-node-wrap" style="display:none;margin-bottom:8px;">
                    <select id="alloc-add-node" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                        <option value="">— Bez etapu —</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="button" onclick="addAllocation()" style="flex:1;padding:8px;border:none;border-radius:8px;background:#2563eb;color:#fff;cursor:pointer;font-weight:600;font-size:13px;">+ Dodaj</button>
                    <button type="button" onclick="allocSetFull()" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-size:12px;color:#374151;" title="Cała kwota na wybrany projekt">Cała kwota →</button>
                </div>
            </div>

            <!-- Footer -->
            <div style="display:flex;gap:10px;justify-content:space-between;">
                <button type="button" onclick="allocUnassignAll()" style="padding:8px 14px;border:1px solid #fecaca;border-radius:8px;background:#fff;cursor:pointer;font-size:12px;color:#dc2626;">Odepnij wszystko</button>
                <button type="button" onclick="closeAssignModal()" style="padding:8px 18px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-size:13px;">Zamknij</button>
            </div>
        </div>
    </div>

    <!-- Alokacja PZF do projektów modal -->
    <div id="pzf-alloc-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.45);align-items:center;justify-content:center;" onclick="if(event.target===this)closeAssignPzfModal()">
        <div style="background:#fff;border-radius:14px;padding:28px 32px;max-width:560px;width:95%;box-shadow:0 20px 60px rgba(0,0,0,.25);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <div>
                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;">Alokacja do projektów</div>
                    <h3 id="pzf-alloc-modal-title" style="margin:2px 0 0;font-size:16px;font-weight:700;"></h3>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px;color:#6b7280;">Kwota netto PZF</div>
                    <div id="pzf-alloc-entry-net" style="font-size:16px;font-weight:700;color:#2563eb;">—</div>
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                    <div id="pzf-alloc-bar-fill" style="height:100%;width:0%;background:#10b981;border-radius:3px;transition:width .3s;"></div>
                </div>
                <div id="pzf-alloc-sum-label" style="font-size:11px;color:#6b7280;margin-top:3px;">0.00 / 0.00 netto (0%)</div>
            </div>

            <table style="width:100%;border-collapse:collapse;margin-bottom:14px;font-size:13px;">
                <thead><tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                    <th style="padding:6px 8px;text-align:left;font-weight:600;color:#374151;">Projekt</th>
                    <th style="padding:6px 8px;text-align:right;font-weight:600;color:#374151;">Kwota netto</th>
                    <th style="padding:6px 8px;text-align:left;font-weight:600;color:#374151;">Opis</th>
                    <th style="padding:6px 8px;width:32px;"></th>
                </tr></thead>
                <tbody id="pzf-alloc-tbody"><tr><td colspan="4" style="text-align:center;color:#9ca3af;padding:12px;">Ładuję…</td></tr></tbody>
            </table>

            <div style="background:#f8fafc;border-radius:10px;padding:14px;margin-bottom:14px;">
                <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:8px;">Dodaj alokację</div>
                <div style="display:grid;grid-template-columns:1fr 100px;gap:8px;margin-bottom:8px;">
                    <select id="pzf-alloc-add-project" onchange="onPzfAllocProjectChange()" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                        <?php echo $projectAssignOptionsHtml; ?>
                    </select>
                    <input id="pzf-alloc-add-amount" type="number" step="0.01" min="0.01" placeholder="0.00" style="padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;text-align:right;">
                </div>
                <div id="pzf-alloc-node-wrap" style="display:none;margin-bottom:8px;">
                    <select id="pzf-alloc-add-node" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">
                        <option value="">— Bez etapu —</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="button" onclick="addPzfAllocation()" style="flex:1;padding:8px;border:none;border-radius:8px;background:#2563eb;color:#fff;cursor:pointer;font-weight:600;font-size:13px;">+ Dodaj</button>
                    <button type="button" onclick="allocSetFullPzf()" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-size:12px;color:#374151;" title="Cała kwota na wybrany projekt">Cała kwota →</button>
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:space-between;">
                <button type="button" onclick="allocUnassignAllPzf()" style="padding:8px 14px;border:1px solid #fecaca;border-radius:8px;background:#fff;cursor:pointer;font-size:12px;color:#dc2626;">Odepnij wszystko</button>
                <button type="button" onclick="closeAssignPzfModal()" style="padding:8px 18px;border:1px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;font-size:13px;">Zamknij</button>
            </div>
        </div>
    </div>
</body>
</html>
