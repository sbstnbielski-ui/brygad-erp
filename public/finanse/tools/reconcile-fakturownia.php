<?php
/**
 * BRYGAD ERP - Rekonsyliacja faktur sprzedażowych z Fakturownią/KSeF.
 * Dry-run domyślny. Zapis tylko przez pojedynczą akcję admina.
 */

$sprutexReconcileDebugEnabled = (string)($_GET['debug'] ?? '') === '1'
    || (string)getenv('SPRUTEX_RECONCILE_DEBUG') === '1';

error_reporting(E_ALL);
ini_set('display_errors', $sprutexReconcileDebugEnabled ? '1' : '0');
ini_set('display_startup_errors', $sprutexReconcileDebugEnabled ? '1' : '0');

function sprutexReconcileDebugEscape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sprutexReconcileDebugRender($title, array $details)
{
    global $sprutexReconcileDebugEnabled;

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Rekonsyliacja - debug</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;background:#f8fafc;color:#111827;margin:0;padding:24px}.box{max-width:1100px;margin:0 auto;background:#fff;border:1px solid #fecaca;border-radius:10px;padding:20px}.badge{display:inline-block;background:#fee2e2;color:#991b1b;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:800}pre{white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:14px;border-radius:8px;overflow:auto}.muted{color:#64748b}</style>';
    echo '</head><body><div class="box">';
    echo '<span class="badge">DEBUG REKONSYLIACJI</span>';
    echo '<h1>' . sprutexReconcileDebugEscape($title) . '</h1>';
    if (!$sprutexReconcileDebugEnabled) {
        echo '<p class="muted">Wystąpił błąd krytyczny, ale szczegóły są ukryte. Otwórz ten adres z parametrem <code>?debug=1</code> albo ustaw <code>SPRUTEX_RECONCILE_DEBUG=1</code>, żeby zobaczyć diagnostykę.</p>';
    } else {
        echo '<p class="muted">Ten panel pokazuje ograniczone szczegóły błędu tej strony. Nie pokazuje tokenów ani konfiguracji API.</p>';
        foreach ($details as $label => $value) {
            echo '<h3>' . sprutexReconcileDebugEscape($label) . '</h3>';
            echo '<pre>' . sprutexReconcileDebugEscape($value) . '</pre>';
        }
    }
    echo '</div></body></html>';
}

function sprutexReconcileDebugThrowableDetails($e)
{
    return [
        'typ' => get_class($e),
        'komunikat' => $e->getMessage(),
        'plik' => $e->getFile(),
        'linia' => $e->getLine(),
    ];
}

set_exception_handler(function ($e) {
    error_log('[reconcile-fakturownia] Uncaught: ' . $e->getMessage());
    sprutexReconcileDebugRender('Nieobsłużony wyjątek w narzędziu rekonsyliacji', sprutexReconcileDebugThrowableDetails($e));
    exit;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }
    sprutexReconcileDebugRender('Błąd krytyczny w narzędziu rekonsyliacji', [
        'typ' => $error['type'],
        'komunikat' => $error['message'] ?? '',
        'plik' => $error['file'] ?? '',
        'linia' => $error['line'] ?? '',
    ]);
});

$sprutexPublicRoot = dirname(dirname(__DIR__));

require_once $sprutexPublicRoot . '/config/autoload.php';
$sprutexInvoiceAuditHelper = $sprutexPublicRoot . '/includes/invoice_audit_helper.php';
if (is_file($sprutexInvoiceAuditHelper)) {
    require_once $sprutexInvoiceAuditHelper;
}

if (!function_exists('invoiceAuditLog')) {
    function invoiceAuditLog(
        PDO $pdo,
        ?int $invoiceSaleId,
        string $action,
        array $oldValues = [],
        array $newValues = [],
        ?int $userId = null,
        ?string $reason = null,
        string $source = 'erp',
        ?int $externalFakturowniaId = null,
        ?string $externalGovId = null
    ): void {
        $stmt = $pdo->prepare("
            INSERT INTO invoice_audit_log
                (invoice_sale_id, action, old_values, new_values, user_id, reason, source, external_fakturownia_id, external_gov_id)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoiceSaleId ?: null,
            mb_substr($action, 0, 80),
            $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $userId ?: null,
            $reason ? mb_substr($reason, 0, 500) : null,
            mb_substr($source ?: 'erp', 0, 80),
            $externalFakturowniaId ?: null,
            $externalGovId ?: null,
        ]);
    }
}

if (!function_exists('invoiceFakturowniaMappingBySaleId')) {
    function invoiceFakturowniaMappingBySaleId(PDO $pdo, int $invoiceSaleId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT *
            FROM fakturownia_invoices
            WHERE erp_invoice_sale_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$invoiceSaleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

$dateFrom = $_GET['date_from'] ?? $_POST['date_from'] ?? '2026-03-01';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateFrom)) {
    $dateFrom = '2026-03-01';
}
$fetchRemote = (string)($_GET['fetch_remote'] ?? $_POST['fetch_remote'] ?? '') === '1';
$messages = [];
$errors = [];
$debugDetails = [];

function sprutexNormalizeMoney($value)
{
    return round((float)str_replace(',', '.', (string)$value), 2);
}

function sprutexArchivedInvoiceNumber($invoiceId, $invoiceNumber)
{
    $base = 'ANUL-' . (int)$invoiceId . '-' . trim((string)$invoiceNumber);
    return mb_substr($base, 0, 100);
}

function sprutexRemoteInvoiceList(PDO $pdo, string $dateFrom, array &$errors, array &$debugDetails): array
{
    try {
        require_once dirname(dirname(__DIR__)) . '/modules/fakturownia/FakturowniaClient.php';
        $client = new FakturowniaClient($pdo);
        $all = [];
        for ($page = 1; $page <= 5; $page++) {
            $response = $client->get('/invoices.json?period=all&income=yes&page=' . $page . '&per_page=100');
            $data = $response['data'] ?? [];
            $rows = isset($data['invoices']) && is_array($data['invoices']) ? $data['invoices'] : $data;
            if (!is_array($rows) || empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                $inv = isset($row['invoice']) && is_array($row['invoice']) ? $row['invoice'] : $row;
                $issueDate = (string)($inv['issue_date'] ?? $inv['sell_date'] ?? $inv['created_at'] ?? '');
                if ($issueDate !== '' && substr($issueDate, 0, 10) < $dateFrom) {
                    continue;
                }
                $id = (int)($inv['id'] ?? 0);
                if ($id > 0) {
                    $all[$id] = $inv;
                }
            }
            if (count($rows) < 100) {
                break;
            }
        }
        return $all;
    } catch (Throwable $e) {
        error_log('[reconcile-fakturownia] Fakturownia API: ' . $e->getMessage());
        $debugDetails[] = ['Fakturownia API', sprutexReconcileDebugThrowableDetails($e)];
        $errors[] = 'Nie udało się pobrać danych z Fakturowni (sprawdź token i subdomenę w konfiguracji). Szczegóły diagnostyczne są dostępne po uruchomieniu tej strony z parametrem debug=1.';
        return [];
    }
}

function sprutexRemoteGross(array $remote)
{
    foreach (['price_gross', 'total_price_gross', 'gross_price', 'price'] as $key) {
        if (isset($remote[$key])) {
            return sprutexNormalizeMoney($remote[$key]);
        }
    }
    return 0.0;
}

function sprutexRemoteBuyerName(array $remote): string
{
    return trim((string)($remote['buyer_name'] ?? $remote['client_name'] ?? $remote['seller_name'] ?? ''));
}

function sprutexRemoteBuyerTaxNo(array $remote): string
{
    return preg_replace('/\D+/', '', (string)($remote['buyer_tax_no'] ?? $remote['client_tax_no'] ?? ''));
}

/**
 * @param array|null $mapping
 * @param array|null $remote
 */
function sprutexCompareStatus(array $local, $mapping, $remote)
{
    if (!$mapping) {
        return ['BRAK_MAPOWANIA', ['Brak rekordu fakturownia_invoices po erp_invoice_sale_id']];
    }
    if (!empty($mapping['fakturownia_id']) && !$remote) {
        return ['BRAK_W_FAKTUROWNI', ['Mapowanie istnieje, ale dry-run nie znalazł faktury po fakturownia_id']];
    }
    if (!$remote) {
        return ['DO_RĘCZNEGO_SPRAWDZENIA', ['Brak danych zewnętrznych do porównania']];
    }

    $issues = [];
    $status = 'OK';
    $remoteNumber = trim((string)($remote['number'] ?? ''));
    if ($remoteNumber !== '' && $remoteNumber !== (string)$local['invoice_number']) {
        $status = 'RÓŻNY_NUMER';
        $issues[] = 'Lokalnie ' . $local['invoice_number'] . ', Fakturownia ' . $remoteNumber;
    }

    $localGross = sprutexNormalizeMoney($local['amount_gross'] ?? 0);
    $remoteGross = sprutexRemoteGross($remote);
    if ($remoteGross > 0 && abs($localGross - $remoteGross) > 0.01) {
        $status = 'KONFLIKT_KWOTY';
        $issues[] = 'Kwota lokalna ' . number_format($localGross, 2, ',', ' ') . ', Fakturownia ' . number_format($remoteGross, 2, ',', ' ');
    }

    $remoteDate = substr((string)($remote['issue_date'] ?? ''), 0, 10);
    if ($remoteDate !== '' && $remoteDate !== (string)$local['issue_date']) {
        $status = $status === 'OK' ? 'KONFLIKT_DATY' : $status;
        $issues[] = 'Data lokalna ' . $local['issue_date'] . ', Fakturownia ' . $remoteDate;
    }

    $localNip = preg_replace('/\D+/', '', (string)($local['client_nip'] ?? ''));
    $remoteNip = sprutexRemoteBuyerTaxNo($remote);
    if ($localNip !== '' && $remoteNip !== '' && $localNip !== $remoteNip) {
        $status = 'KONFLIKT_KONTRAHENTA';
        $issues[] = 'NIP lokalny ' . $localNip . ', Fakturownia ' . $remoteNip;
    }

    return [$status, $issues];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $errors[] = 'Nieprawidłowy token CSRF.';
    } else {
        $action = $_POST['action'] ?? '';
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        try {
            if ($invoiceId <= 0) {
                throw new RuntimeException('Brak ID faktury lokalnej.');
            }
            $pdo->beginTransaction();
            $invoiceStmt = $pdo->prepare("SELECT * FROM invoices_sale WHERE id = ? FOR UPDATE");
            $invoiceStmt->execute([$invoiceId]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new RuntimeException('Nie znaleziono faktury lokalnej.');
            }
            $mapping = invoiceFakturowniaMappingBySaleId($pdo, $invoiceId);

            if ($action === 'sync_number') {
                if (!$mapping || trim((string)($mapping['fakturownia_number'] ?? '')) === '') {
                    throw new RuntimeException('Brak numeru Fakturowni do uzgodnienia.');
                }
                $officialNumber = trim((string)$mapping['fakturownia_number']);
                $dup = $pdo->prepare("SELECT * FROM invoices_sale WHERE invoice_number = ? AND id <> ? LIMIT 1");
                $dup->execute([$officialNumber, $invoiceId]);
                $duplicate = $dup->fetch(PDO::FETCH_ASSOC);
                $duplicateId = (int)($duplicate['id'] ?? 0);
                if ($duplicateId > 0) {
                    $duplicateMapping = invoiceFakturowniaMappingBySaleId($pdo, $duplicateId);
                    $duplicateClosed = (string)($duplicate['status'] ?? '') === 'cancelled'
                        || (!empty($duplicate['deleted_at']) && $duplicate['deleted_at'] !== '0000-00-00 00:00:00');
                    $duplicateHasExternal = $duplicateMapping && (
                        !empty($duplicateMapping['fakturownia_id'])
                        || !empty($duplicateMapping['gov_id'])
                        || !empty($duplicateMapping['gov_status'])
                    );
                    if (!$duplicateClosed || $duplicateHasExternal) {
                        throw new RuntimeException('Nie można uzgodnić numeru: numer jest już użyty przez aktywną albo zewnętrznie powiązaną invoices_sale.id=' . $duplicateId . '. Najpierw zamknij lokalną fakturę bez mapowania.');
                    }
                    $archivedNumber = sprutexArchivedInvoiceNumber($duplicateId, $duplicate['invoice_number'] ?? $officialNumber);
                    $pdo->prepare("
                        UPDATE invoices_sale
                        SET invoice_number = ?,
                            sync_attention_required = 1,
                            sync_attention_note = COALESCE(sync_attention_note, 'Numer technicznie zarchiwizowany podczas rekonsyliacji')
                        WHERE id = ?
                    ")->execute([$archivedNumber, $duplicateId]);
                    invoiceAuditLog($pdo, $duplicateId, 'manual_archive_cancelled_invoice_number', ['invoice_number' => $duplicate['invoice_number'] ?? null], ['invoice_number' => $archivedNumber, 'released_for_invoice_id' => $invoiceId], $_SESSION['user_id'] ?? null, 'Zwolnienie numeru dla faktury poprawnie zsynchronizowanej z Fakturownią/KSeF', 'reconcile_tool', $duplicateMapping ? (int)($duplicateMapping['fakturownia_id'] ?? 0) : null, $duplicateMapping['gov_id'] ?? null);
                }
                $pdo->prepare("UPDATE invoices_sale SET invoice_number = ?, sync_attention_required = 0, sync_attention_note = NULL WHERE id = ?")
                    ->execute([$officialNumber, $invoiceId]);
                invoiceAuditLog($pdo, $invoiceId, 'manual_invoice_number_reconcile', ['invoice_number' => $invoice['invoice_number']], ['invoice_number' => $officialNumber], $_SESSION['user_id'] ?? null, 'Ręczne uzgodnienie numeru z Fakturownią', 'reconcile_tool', (int)($mapping['fakturownia_id'] ?? 0), $mapping['gov_id'] ?? null);
                $messages[] = 'Uzgodniono numer lokalny faktury #' . $invoiceId . ' z Fakturownią.';
            } elseif ($action === 'mark_attention') {
                $note = trim((string)($_POST['note'] ?? 'Wymaga ręcznej weryfikacji rekonsyliacji'));
                $pdo->prepare("UPDATE invoices_sale SET sync_attention_required = 1, sync_attention_note = ? WHERE id = ?")
                    ->execute([mb_substr($note, 0, 500), $invoiceId]);
                invoiceAuditLog($pdo, $invoiceId, 'manual_mark_sync_attention', [], ['sync_attention_required' => 1, 'note' => $note], $_SESSION['user_id'] ?? null, $note, 'reconcile_tool', $mapping ? (int)($mapping['fakturownia_id'] ?? 0) : null, $mapping['gov_id'] ?? null);
                $messages[] = 'Oznaczono fakturę #' . $invoiceId . ' do ręcznej weryfikacji.';
            } elseif ($action === 'mark_unsynced_cancelled') {
                $note = trim((string)($_POST['note'] ?? 'Oznaczono jako niewysłaną/anulowaną po rekonsyliacji'));
                $archivedNumber = sprutexArchivedInvoiceNumber($invoiceId, $invoice['invoice_number'] ?? '');
                $pdo->prepare("
                    UPDATE invoices_sale
                    SET invoice_number = ?,
                        status = 'cancelled',
                        deleted_at = COALESCE(deleted_at, NOW()),
                        deleted_by = ?,
                        delete_reason = ?,
                        sync_attention_required = 1,
                        sync_attention_note = ?
                    WHERE id = ?
                ")->execute([
                    $archivedNumber,
                    $_SESSION['user_id'] ?? null,
                    mb_substr($note, 0, 500),
                    mb_substr($note, 0, 500),
                    $invoiceId,
                ]);
                invoiceAuditLog($pdo, $invoiceId, 'manual_mark_unsynced_cancelled', ['status' => $invoice['status'], 'deleted_at' => $invoice['deleted_at'] ?? null, 'invoice_number' => $invoice['invoice_number'] ?? null], ['status' => 'cancelled', 'deleted_at' => date('Y-m-d H:i:s'), 'invoice_number' => $archivedNumber, 'note' => $note], $_SESSION['user_id'] ?? null, $note, 'reconcile_tool', $mapping ? (int)($mapping['fakturownia_id'] ?? 0) : null, $mapping['gov_id'] ?? null);
                $messages[] = 'Oznaczono fakturę #' . $invoiceId . ' jako anulowaną/niewysłaną do decyzji księgowej.';
            } elseif ($action === 'link_mapping') {
                $fakturowniaId = (int)($_POST['fakturownia_id'] ?? 0);
                if ($fakturowniaId <= 0 || empty($_POST['confirm_manual'])) {
                    throw new RuntimeException('Podaj fakturownia_id i zaznacz ręczne potwierdzenie.');
                }
                $number = trim((string)($_POST['fakturownia_number'] ?? ''));
                $govId = trim((string)($_POST['gov_id'] ?? ''));
                $govStatus = trim((string)($_POST['gov_status'] ?? 'pending'));
                $oldMapping = $mapping ?: [];
                $stmt = $pdo->prepare("
                    INSERT INTO fakturownia_invoices
                        (erp_contract_id, erp_milestone_id, erp_invoice_sale_id, fakturownia_id, fakturownia_number, gov_id, gov_status, status, request_hash, created_at, synced_at)
                    VALUES
                        (NULL, NULL, ?, ?, ?, ?, ?, 'manual_reconciled', ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        erp_invoice_sale_id = VALUES(erp_invoice_sale_id),
                        fakturownia_number = VALUES(fakturownia_number),
                        gov_id = VALUES(gov_id),
                        gov_status = VALUES(gov_status),
                        status = VALUES(status),
                        synced_at = NOW(),
                        updated_at = NOW()
                ");
                $stmt->execute([$invoiceId, $fakturowniaId, $number ?: null, $govId ?: null, $govStatus ?: 'pending', 'manual_reconcile_' . $invoiceId . '_' . $fakturowniaId]);
                invoiceAuditLog($pdo, $invoiceId, 'manual_fakturownia_mapping_linked', $oldMapping, ['fakturownia_id' => $fakturowniaId, 'fakturownia_number' => $number, 'gov_id' => $govId, 'gov_status' => $govStatus], $_SESSION['user_id'] ?? null, 'Ręczne połączenie mapowania w narzędziu rekonsyliacji', 'reconcile_tool', $fakturowniaId, $govId ?: null);
                $messages[] = 'Ręcznie połączono fakturę #' . $invoiceId . ' z Fakturownia ID ' . $fakturowniaId . '.';
            } else {
                throw new RuntimeException('Nieznana akcja.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $debugDetails[] = ['POST / akcja ręczna', sprutexReconcileDebugThrowableDetails($e)];
            if ($e instanceof PDOException) {
                error_log('[reconcile-fakturownia] POST DB: ' . $e->getMessage());
                $errors[] = 'Błąd zapisu w bazie. Szczegóły diagnostyczne są dostępne po uruchomieniu tej strony z parametrem debug=1.';
            } else {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$reconcileLoadFailed = false;
$remoteInvoices = [];
$remoteById = [];
$remoteUsed = [];
$localRows = [];
$rows = [];

try {
    $remoteInvoices = $fetchRemote ? sprutexRemoteInvoiceList($pdo, $dateFrom, $errors, $debugDetails) : [];
    $remoteById = $remoteInvoices;

    $localStmt = $pdo->prepare("
        SELECT inv.*, i.name AS client_name, i.nip AS client_nip,
               fi.id AS mapping_id, fi.fakturownia_id, fi.fakturownia_number, fi.gov_id, fi.gov_status, fi.status AS fakturownia_status, fi.synced_at
        FROM invoices_sale inv
        LEFT JOIN investors i ON i.id = inv.client_id
        LEFT JOIN fakturownia_invoices fi ON fi.erp_invoice_sale_id = inv.id
        WHERE inv.issue_date >= ?
          AND (inv.deleted_at IS NULL OR inv.deleted_at = '0000-00-00 00:00:00')
        ORDER BY inv.issue_date ASC, inv.id ASC
    ");
    $localStmt->execute([$dateFrom]);
    $localRows = $localStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($localRows as $local) {
        $mapping = !empty($local['mapping_id']) ? $local : null;
        $remote = null;
        if (!empty($local['fakturownia_id']) && isset($remoteById[(int)$local['fakturownia_id']])) {
            $remote = $remoteById[(int)$local['fakturownia_id']];
            $remoteUsed[(int)$local['fakturownia_id']] = true;
        }
        if (!$fetchRemote) {
            if (!$mapping) {
                $status = 'BRAK_MAPOWANIA';
                $issues = ['Brak rekordu fakturownia_invoices po erp_invoice_sale_id'];
            } else {
                $localNumber = (string)($local['invoice_number'] ?? '');
                $externalNumber = (string)($local['fakturownia_number'] ?? '');
                $status = ($externalNumber !== '' && $externalNumber !== $localNumber) ? 'RÓŻNY_NUMER' : 'OK';
                $issues = ['Porównanie lokalne bez połączenia z Fakturownią'];
                if ($status === 'RÓŻNY_NUMER') {
                    $issues[] = 'Lokalnie ' . $localNumber . ', mapowanie Fakturowni ' . $externalNumber;
                }
            }
        } else {
            list($status, $issues) = sprutexCompareStatus($local, $mapping, $remote);
        }
        if (!$mapping && $remoteInvoices) {
            $candidates = [];
            foreach ($remoteInvoices as $rid => $r) {
                $score = 0;
                if (sprutexRemoteGross($r) > 0 && abs(sprutexRemoteGross($r) - sprutexNormalizeMoney($local['amount_gross'])) <= 0.01) {
                    $score += 3;
                }
                if (substr((string)($r['issue_date'] ?? ''), 0, 10) === (string)$local['issue_date']) {
                    $score += 2;
                }
                if (sprutexRemoteBuyerTaxNo($r) !== '' && sprutexRemoteBuyerTaxNo($r) === preg_replace('/\D+/', '', (string)$local['client_nip'])) {
                    $score += 3;
                }
                if (trim((string)($r['number'] ?? '')) === (string)$local['invoice_number']) {
                    $score += 1;
                }
                if ($score >= 4) {
                    $candidates[] = '#' . $rid . ' / ' . ($r['number'] ?? '-') . ' / score ' . $score;
                }
            }
            if ($candidates) {
                $status = $status === 'BRAK_MAPOWANIA' ? 'DO_RĘCZNEGO_SPRAWDZENIA' : $status;
                $issues[] = 'Kandydaci: ' . implode('; ', array_slice($candidates, 0, 3));
            }
        }
        $rows[] = ['local' => $local, 'remote' => $remote, 'status' => $status, 'issues' => $issues];
    }

    foreach ($remoteInvoices as $remoteId => $remote) {
        if (!isset($remoteUsed[$remoteId])) {
            $mappedStmt = $pdo->prepare("SELECT erp_invoice_sale_id FROM fakturownia_invoices WHERE fakturownia_id = ? LIMIT 1");
            $mappedStmt->execute([$remoteId]);
            if (!$mappedStmt->fetchColumn()) {
                $rows[] = ['local' => null, 'remote' => $remote, 'status' => 'BRAK_LOKALNIE', 'issues' => ['Faktura istnieje w Fakturowni bez lokalnego mapowania']];
            }
        }
    }
    if (!$fetchRemote) {
        $messages[] = 'Tryb lokalny: nie wykonano żadnego połączenia z Fakturownią. Zaznacz pobranie danych z Fakturowni, aby porównać kwoty/kontrahentów/daty z API.';
    }
} catch (Throwable $e) {
    error_log('[reconcile-fakturownia] ' . $e->getMessage());
    $debugDetails[] = ['Wczytywanie danych rekonsyliacji', sprutexReconcileDebugThrowableDetails($e)];
    $reconcileLoadFailed = true;
    $remoteInvoices = [];
    $remoteById = [];
    $remoteUsed = [];
    $localRows = [];
    $rows = [];
    $errors[] = 'Nie udało się wczytać listy (często brak kolumny deleted_at albo migracji z 2026-05-17). Szczegóły diagnostyczne są dostępne po uruchomieniu tej strony z parametrem debug=1.';
}

$statusColors = [
    'OK' => '#065f46',
    'RÓŻNY_NUMER' => '#92400e',
    'BRAK_MAPOWANIA' => '#991b1b',
    'BRAK_W_FAKTUROWNI' => '#991b1b',
    'BRAK_LOKALNIE' => '#7c2d12',
    'KONFLIKT_KWOTY' => '#991b1b',
    'KONFLIKT_KONTRAHENTA' => '#991b1b',
    'KONFLIKT_DATY' => '#92400e',
    'PODEJRZANY_DUPLIKAT' => '#92400e',
    'DO_RĘCZNEGO_SPRAWDZENIA' => '#92400e',
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Rekonsyliacja Fakturownia</title>
    <style>
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f5f7fa; color:#1f2937; margin:0; }
        .container { max-width:1500px; margin:0 auto; padding:24px; }
        .hero { background:#0f172a; color:white; border-radius:10px; padding:20px; margin-bottom:18px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .hero a { color:#dbeafe; }
        .card { background:white; border:1px solid #e5e7eb; border-radius:8px; padding:18px; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th, td { border-bottom:1px solid #e5e7eb; padding:9px; text-align:left; vertical-align:top; }
        th { background:#f8fafc; font-size:12px; color:#475569; }
        .badge { display:inline-block; padding:3px 8px; border-radius:999px; background:#fef3c7; font-weight:800; font-size:11px; }
        .btn { padding:7px 10px; border-radius:6px; border:1px solid #d1d5db; background:#fff; cursor:pointer; text-decoration:none; color:#1f2937; font-weight:700; font-size:12px; }
        .btn-primary { background:#1e3a8a; color:#fff; border-color:#1e3a8a; }
        .alert { padding:11px 14px; border-radius:7px; margin-bottom:12px; }
        .alert-error { background:#fee2e2; color:#991b1b; }
        .alert-ok { background:#dcfce7; color:#166534; }
        input[type=text], input[type=date] { padding:7px 9px; border:1px solid #d1d5db; border-radius:6px; }
        form.inline { display:inline-flex; gap:6px; align-items:center; flex-wrap:wrap; margin:2px 0; }
    </style>
</head>
<body>
<nav style="background:#1e293b;color:#fff;padding:10px 24px;font-size:14px;">
    <a style="color:#93c5fd;" href="/dashboard.php">Panel</a>
    · <a style="color:#93c5fd;" href="/finanse/faktury-sprzedazowe/index.php">Faktury sprzedażowe</a>
    · <a style="color:#93c5fd;" href="/logout.php">Wyloguj</a>
</nav>
<div class="container">
    <?php if ($reconcileLoadFailed): ?>
        <div class="alert alert-error">
            <p style="margin:0;"><strong>Nie udało się wczytać danych rekonsyliacji.</strong> Zwykle trzeba uruchomić migrację <code>migrations/2026-05-17_invoice_audit_soft_delete_wallet_allocations.sql</code> na tej bazie.</p>
        </div>
    <?php endif; ?>
    <div class="hero">
        <div>
            <a href="/finanse/faktury-sprzedazowe/index.php">Faktury sprzedażowe</a>
            <h1 style="margin:5px 0;">Rekonsyliacja Fakturownia/KSeF</h1>
            <p style="margin:0;color:#cbd5e1;">Dry-run porównuje po fakturownia_id i erp_invoice_sale_id. Numer faktury jest tylko sygnałem pomocniczym.</p>
        </div>
        <form method="GET" class="inline">
            <label>Od daty <input type="date" name="date_from" value="<?php echo e($dateFrom); ?>"></label>
            <label style="font-size:13px;color:#dbeafe;"><input type="checkbox" name="fetch_remote" value="1" <?php echo $fetchRemote ? 'checked' : ''; ?>> Pobierz z Fakturowni</label>
            <button class="btn btn-primary" type="submit">Uruchom dry-run</button>
        </form>
    </div>

    <?php foreach ($messages as $message): ?><div class="alert alert-ok"><?php echo e($message); ?></div><?php endforeach; ?>
    <?php foreach ($errors as $error): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endforeach; ?>
    <?php if (!empty($debugDetails) && !$sprutexReconcileDebugEnabled): ?>
        <div class="alert alert-error">
            Zebrano szczegóły błędu, ale są ukryte. Otwórz tę stronę z parametrem <code>debug=1</code>, np. <code>/finanse/tools/reconcile-fakturownia.php?date_from=<?php echo e($dateFrom); ?>&debug=1</code>.
        </div>
    <?php endif; ?>
    <?php if (!empty($debugDetails) && $sprutexReconcileDebugEnabled): ?>
        <div class="card" style="border-color:#fecaca;background:#fff7f7;">
            <h2 style="margin-top:0;color:#991b1b;">Debug błędu rekonsyliacji</h2>
            <p style="color:#64748b;margin-top:0;">Ograniczone dane diagnostyczne tej strony. Nie pokazujemy tokenów ani konfiguracji API.</p>
            <?php foreach ($debugDetails as $debugItem): ?>
                <?php $debugLabel = $debugItem[0] ?? 'Błąd'; $debugData = $debugItem[1] ?? []; ?>
                <h3 style="margin-bottom:6px;"><?php echo e($debugLabel); ?></h3>
                <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto;"><?php echo e(print_r($debugData, true)); ?></pre>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if ($reconcileLoadFailed): ?>
            <p>Lista pusta — po migracji odśwież stronę.</p>
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Lokalnie</th>
                    <th>Mapowanie</th>
                    <th>Fakturownia</th>
                    <th>Uwagi</th>
                    <th>Akcje ręczne</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $local = $row['local']; $remote = $row['remote']; $status = $row['status']; ?>
                <tr>
                    <td><span class="badge" style="color:<?php echo e($statusColors[$status] ?? '#374151'); ?>"><?php echo e($status); ?></span></td>
                    <td>
                        <?php if ($local): ?>
                            ID <?php echo (int)$local['id']; ?><br>
                            nr <?php echo e($local['invoice_number']); ?><br>
                            data <?php echo e($local['issue_date']); ?><br>
                            <?php echo e($local['client_name'] ?? '-'); ?><br>
                            <?php echo e(number_format((float)$local['amount_gross'], 2, ',', ' ')); ?> PLN
                        <?php else: ?>
                            Brak lokalnie
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($local && !empty($local['mapping_id'])): ?>
                            map #<?php echo (int)$local['mapping_id']; ?><br>
                            FK ID <?php echo e($local['fakturownia_id']); ?><br>
                            FK nr <?php echo e($local['fakturownia_number']); ?><br>
                            KSeF <?php echo e($local['gov_status'] ?: '-'); ?><br>
                            <?php echo e($local['gov_id'] ?: 'Brak gov_id'); ?>
                        <?php elseif ($local): ?>
                            Brak mapowania po erp_invoice_sale_id
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($remote): ?>
                            FK ID <?php echo (int)($remote['id'] ?? 0); ?><br>
                            nr <?php echo e($remote['number'] ?? '-'); ?><br>
                            data <?php echo e(substr((string)($remote['issue_date'] ?? ''), 0, 10)); ?><br>
                            <?php echo e(sprutexRemoteBuyerName($remote) ?: '-'); ?><br>
                            <?php echo e(number_format(sprutexRemoteGross($remote), 2, ',', ' ')); ?> PLN
                        <?php else: ?>
                            Brak w dry-run / niepobrane
                        <?php endif; ?>
                    </td>
                    <td><?php echo e(implode(' | ', $row['issues'])); ?></td>
                    <td>
                        <?php if ($local): ?>
                            <?php if (!empty($local['mapping_id']) && $status === 'RÓŻNY_NUMER'): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Uzgodnić lokalny numer z Fakturownią?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="date_from" value="<?php echo e($dateFrom); ?>">
                                    <input type="hidden" name="fetch_remote" value="<?php echo $fetchRemote ? '1' : '0'; ?>">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$local['id']; ?>">
                                    <input type="hidden" name="action" value="sync_number">
                                    <button class="btn btn-primary" type="submit">Uzgodnij numer</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" class="inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="date_from" value="<?php echo e($dateFrom); ?>">
                                <input type="hidden" name="fetch_remote" value="<?php echo $fetchRemote ? '1' : '0'; ?>">
                                <input type="hidden" name="invoice_id" value="<?php echo (int)$local['id']; ?>">
                                <input type="hidden" name="action" value="mark_attention">
                                <input type="text" name="note" value="Wymaga ręcznej weryfikacji rekonsyliacji" style="width:220px;">
                                <button class="btn" type="submit">Oznacz do weryfikacji</button>
                            </form>
                            <?php if (empty($local['mapping_id'])): ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Oznaczyć fakturę jako anulowaną/niewysłaną?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="date_from" value="<?php echo e($dateFrom); ?>">
                                    <input type="hidden" name="fetch_remote" value="<?php echo $fetchRemote ? '1' : '0'; ?>">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$local['id']; ?>">
                                    <input type="hidden" name="action" value="mark_unsynced_cancelled">
                                    <button class="btn" type="submit">Anulowana/niewysłana</button>
                                </form>
                                <form method="POST" class="inline" onsubmit="return confirm('Ręcznie połączyć mapowanie? Upewnij się, że kwota, kontrahent i data są zgodne.');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="date_from" value="<?php echo e($dateFrom); ?>">
                                    <input type="hidden" name="fetch_remote" value="<?php echo $fetchRemote ? '1' : '0'; ?>">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$local['id']; ?>">
                                    <input type="hidden" name="action" value="link_mapping">
                                    <input type="text" name="fakturownia_id" placeholder="fakturownia_id" style="width:110px;">
                                    <input type="text" name="fakturownia_number" placeholder="numer FK" style="width:100px;">
                                    <label style="font-size:12px;"><input type="checkbox" name="confirm_manual" value="1"> potwierdzam</label>
                                    <button class="btn" type="submit">Połącz</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            Tylko do ręcznej analizy.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
