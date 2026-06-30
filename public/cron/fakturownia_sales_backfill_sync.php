<?php
/**
 * BRYGAD ERP - CRON: Backfill faktur sprzedażowych z Fakturowni do ERP
 *
 * Cel:
 * - pobrać istniejące faktury sprzedażowe (income=yes) z Fakturowni,
 * - utworzyć brakujące rekordy w invoices_sale + invoice_sale_items,
 * - zapisać mapowania w fakturownia_invoices,
 * - nie duplikować danych (idempotencja + deduplikacja).
 *
 * Uruchomienie (CLI):
 *   php public/cron/fakturownia_sales_backfill_sync.php all
 *
 * Parametr okresu (opcjonalny):
 *   last_30_days | this_month | last_month | this_year | last_year | all
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/modules/fakturownia/FakturowniaClient.php';

$pdo = getDbConnection();
$perPage = 100;

$allowedPeriods = [
    'last_30_days', 'this_month', 'last_month', 'this_year', 'last_year', 'all',
];
$period = isset($argv[1]) && in_array($argv[1], $allowedPeriods, true)
    ? $argv[1]
    : 'all';

try {
    $client = new FakturowniaClient($pdo);

    $systemUserId = findSystemUserId($pdo);

    $page = 1;
    $imported = 0;
    $matched = 0;
    $updatedStatus = 0;
    $metadataUpdated = 0;
    $skippedNoClient = 0;
    $errors = 0;

    logEvent("CRON Sales Backfill: start (period={$period})", 'INFO');

    do {
        $endpoint = '/invoices.json?income=yes&period=' . urlencode($period)
            . '&page=' . $page
            . '&per_page=' . $perPage;

        $response = $client->get($endpoint);
        $rows = $response['data'] ?? [];

        if (!is_array($rows) || count($rows) === 0) {
            break;
        }

        foreach ($rows as $rawRow) {
            try {
                $invoice = extractInvoiceData($rawRow);
                $fakturowniaId = (int)($invoice['id'] ?? 0);
                if ($fakturowniaId <= 0) {
                    continue;
                }

                $clientId = findInvestorIdForInvoice($pdo, $invoice);
                if ($clientId <= 0) {
                    $skippedNoClient++;
                    logEvent('Sales Backfill skip: brak dopasowania klienta (fakturownia_id=' . $fakturowniaId . ')', 'ERROR');
                    continue;
                }

                $invoiceNumber = trim((string)($invoice['number'] ?? ''));
                $issueDate = backfillNormalizeDate($invoice['issue_date'] ?? null);

                $existingInvoiceId = findLocalSalesInvoiceIdByImportMarker($pdo, $fakturowniaId);
                if ($existingInvoiceId === null) {
                    $existingInvoiceId = findLocalSalesInvoiceId($pdo, $invoiceNumber, $issueDate, $clientId);
                }
                $isNewLocal = $existingInvoiceId === null;

                if ($isNewLocal) {
                    // Szczegóły pobieramy tylko dla nowych rekordów (potrzebne pozycje).
                    if (empty($invoice['positions']) || !is_array($invoice['positions'])) {
                        $detail = $client->get('/invoices/' . $fakturowniaId . '.json');
                        $invoice = extractInvoiceData($detail['data'] ?? []);
                    }

                    $existingInvoiceId = insertLocalSalesInvoice($pdo, $invoice, $clientId, $systemUserId);
                    if ($existingInvoiceId <= 0) {
                        throw new RuntimeException('Nie udało się utworzyć lokalnej faktury sprzedażowej.');
                    }

                    insertLocalSalesItems($pdo, $existingInvoiceId, $invoice['positions'] ?? []);
                    $imported++;
                } else {
                    $matched++;

                    // Dla istniejących dokumentów aktualizujemy wyłącznie status płatności.
                    $statusUpdated = updateLocalSalesInvoicePaymentStatus($pdo, $existingInvoiceId, $invoice);
                    if ($statusUpdated) {
                        $updatedStatus++;
                    }

                    // Dla rekordów istniejących uzupełniamy metadane importu i typ dokumentu.
                    $metaUpdated = enrichExistingSalesInvoiceMetadata($pdo, $existingInvoiceId, $invoice);
                    if ($metaUpdated) {
                        $metadataUpdated++;
                    }
                }

                upsertFakturowniaInvoiceMapping($pdo, $invoice, $existingInvoiceId);

            } catch (Throwable $e) {
                $errors++;
                $fid = isset($rawRow['id']) ? (int)$rawRow['id'] : 0;
                logEvent('Sales Backfill error (fakturownia_id=' . $fid . '): ' . $e->getMessage(), 'ERROR');
            }
        }

        $fetched = count($rows);
        $page++;
    } while ($fetched === $perPage);

    $summary = "Sales Backfill done: imported={$imported}, matched={$matched}, updated_status={$updatedStatus}, updated_metadata={$metadataUpdated}, skipped_no_client={$skippedNoClient}, errors={$errors}";
    echo '[' . date('Y-m-d H:i:s') . '] ' . $summary . "\n";
    logEvent('CRON ' . $summary, 'INFO');

} catch (FakturowniaAuthException $e) {
    $msg = 'FAKTUROWNIA CRITICAL: Błąd autoryzacji w sales_backfill_sync — sprawdź token. ' . $e->getMessage();
    logEvent($msg, 'CRITICAL');
    echo '[' . date('Y-m-d H:i:s') . '] CRITICAL AUTH: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') { exit(1); }

} catch (Throwable $e) {
    logEvent('CRON Sales Backfill ERROR: ' . $e->getMessage(), 'ERROR');
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') { exit(1); }
}

// ============================================================
// Funkcje pomocnicze
// ============================================================

function extractInvoiceData($payload): array
{
    if (is_array($payload) && isset($payload['invoice']) && is_array($payload['invoice'])) {
        return $payload['invoice'];
    }

    return is_array($payload) ? $payload : [];
}

function backfillNormalizeNip(?string $nip): string
{
    return preg_replace('/[^0-9]/', '', (string)$nip);
}

function backfillNormalizeDate($date): ?string
{
    $v = trim((string)$date);
    if ($v === '') {
        return null;
    }

    $ts = strtotime($v);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d', $ts);
}

function backfillNormalizeMoney($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }

    return round((float)$value, 2);
}

function backfillNormalizeDateTime($value): ?string
{
    $v = trim((string)$value);
    if ($v === '') {
        return null;
    }

    $ts = strtotime($v);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

function mapDocumentKind(array $invoice): string
{
    $kind = strtolower(trim((string)($invoice['kind'] ?? 'vat')));

    if ($kind === 'proforma') {
        return 'sale_proforma';
    }
    if ($kind === 'advance') {
        return 'sale_advance';
    }
    if ($kind === 'final') {
        return 'sale_final';
    }
    if ($kind === 'correction') {
        return 'sale_correction';
    }

    return 'sale_vat';
}

function mapSalesStatus(array $invoice): string
{
    $status = strtolower(trim((string)($invoice['status'] ?? $invoice['state'] ?? 'issued')));

    // FK zwraca "paid" jako kwotę (string "0,00") — nie boolean.
    // !empty("0,00") === true w PHP, stąd fałszywe paid na wszystkim.
    $paidAmount = (float)str_replace(',', '.', (string)($invoice['paid'] ?? '0'));

    if (in_array($status, ['paid', 'zaplacona'], true)) {
        return 'paid';
    }

    if (in_array($status, ['partial'], true) || ($paidAmount > 0 && !in_array($status, ['paid', 'zaplacona'], true))) {
        return 'partially_paid';
    }

    if (in_array($status, ['draft', 'robocza'], true)) {
        return 'draft';
    }

    return 'issued';
}

function mapPaymentMethod(array $invoice): string
{
    $type = strtolower(trim((string)($invoice['payment_type'] ?? 'transfer')));

    if (in_array($type, ['transfer', 'cash', 'card'], true)) {
        return $type;
    }

    return 'other';
}

function mapGovStatus(array $invoice): string
{
    $gov = strtolower(trim((string)($invoice['gov_status'] ?? 'pending')));

    if (in_array($gov, ['ok', 'accepted'], true)) {
        return 'ok';
    }

    if (in_array($gov, ['error', 'rejected'], true)) {
        return 'error';
    }

    return 'pending';
}

function mapFakturowniaStatusForMapping(array $invoice): string
{
    $status = mapSalesStatus($invoice);

    if ($status === 'paid') {
        return 'paid';
    }

    if ($status === 'issued' || $status === 'partially_paid') {
        return 'sent';
    }

    return 'draft';
}

function findSystemUserId(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    $id = (int)$stmt->fetchColumn();

    if ($id > 0) {
        return $id;
    }

    $stmt = $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1');
    $id = (int)$stmt->fetchColumn();

    return $id > 0 ? $id : 1;
}

function findInvestorIdForInvoice(PDO $pdo, array $invoice): int
{
    $nip = backfillNormalizeNip((string)($invoice['buyer_tax_no'] ?? ''));
    $name = trim((string)($invoice['buyer_name'] ?? ''));

    if ($nip !== '') {
        $stmt = $pdo->prepare('SELECT id FROM investors WHERE REPLACE(REPLACE(nip, "-", ""), " ", "") = :nip LIMIT 1');
        $stmt->execute([':nip' => $nip]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }

    if ($name !== '') {
        $stmt = $pdo->prepare('SELECT id FROM investors WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    }

    return 0;
}

function findLocalSalesInvoiceId(PDO $pdo, string $invoiceNumber, ?string $issueDate, int $clientId): ?int
{
    if ($invoiceNumber === '' || $issueDate === null || $clientId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM invoices_sale WHERE invoice_number = :number AND issue_date = :issue_date AND client_id = :client_id LIMIT 1'
    );
    $stmt->execute([
        ':number' => $invoiceNumber,
        ':issue_date' => $issueDate,
        ':client_id' => $clientId,
    ]);

    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : null;
}

function findLocalSalesInvoiceIdByImportMarker(PDO $pdo, int $fakturowniaId): ?int
{
    if ($fakturowniaId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM invoices_sale WHERE notes LIKE :marker ORDER BY id DESC LIMIT 1');
    $stmt->execute([':marker' => '%(ID ' . $fakturowniaId . ')%']);

    $id = (int)$stmt->fetchColumn();
    return $id > 0 ? $id : null;
}

function insertLocalSalesInvoice(PDO $pdo, array $invoice, int $clientId, int $createdBy): int
{
    $status = mapSalesStatus($invoice);
    $issueDate = backfillNormalizeDate($invoice['issue_date'] ?? null) ?: date('Y-m-d');
    $saleDate = backfillNormalizeDate($invoice['sell_date'] ?? null) ?: $issueDate;
    $dueDate = backfillNormalizeDate($invoice['payment_to'] ?? null) ?: $issueDate;

    $paymentDays = (int)($invoice['payment_to_kind'] ?? 14);
    if ($paymentDays <= 0) {
        $paymentDays = max(0, (int)((strtotime($dueDate) - strtotime($issueDate)) / 86400));
    }

    $amountNet = backfillNormalizeMoney($invoice['price_net'] ?? 0);
    $amountVat = backfillNormalizeMoney($invoice['price_tax'] ?? 0);
    $amountGross = backfillNormalizeMoney($invoice['price_gross'] ?? $invoice['total'] ?? 0);
    $documentKind = mapDocumentKind($invoice);
    $sourceExternalId = isset($invoice['id']) ? (int)$invoice['id'] : 0;
    if ($sourceExternalId <= 0) {
        $sourceExternalId = null;
    }
    $sourceRawKind = strtolower(trim((string)($invoice['kind'] ?? '')));
    if ($sourceRawKind === '') {
        $sourceRawKind = null;
    }

    $sourceCreatedAt = backfillNormalizeDateTime($invoice['created_at'] ?? null);
    if ($sourceCreatedAt === null) {
        $sourceCreatedAt = $issueDate . ' 00:00:00';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO invoices_sale
        (invoice_number, document_kind, source_system, source_external_id, source_raw_kind, source_created_at,
         client_id, issue_date, sale_date, due_date, payment_days, payment_method, bank_account, place_of_issue, currency,
         amount_net, amount_vat, amount_gross, status, file_path, notes, created_by, created_at)
        VALUES
        (:invoice_number, :document_kind, 'fakturownia', :source_external_id, :source_raw_kind, :source_created_at,
         :client_id, :issue_date, :sale_date, :due_date, :payment_days, :payment_method, NULL, :place_of_issue, :currency,
         :amount_net, :amount_vat, :amount_gross, :status, NULL, :notes, :created_by, :created_at)"
    );

    $stmt->execute([
        ':invoice_number' => trim((string)($invoice['number'] ?? '')),
        ':document_kind' => $documentKind,
        ':source_external_id' => $sourceExternalId,
        ':source_raw_kind' => $sourceRawKind,
        ':source_created_at' => $sourceCreatedAt,
        ':client_id' => $clientId,
        ':issue_date' => $issueDate,
        ':sale_date' => $saleDate,
        ':due_date' => $dueDate,
        ':payment_days' => $paymentDays,
        ':payment_method' => mapPaymentMethod($invoice),
        ':place_of_issue' => 'Import',
        ':currency' => strtoupper(trim((string)($invoice['currency'] ?? 'PLN'))) ?: 'PLN',
        ':amount_net' => $amountNet,
        ':amount_vat' => $amountVat,
        ':amount_gross' => $amountGross,
        ':status' => $status,
        ':notes' => 'Import z Fakturowni (ID ' . (int)($invoice['id'] ?? 0) . ')',
        ':created_by' => $createdBy,
        ':created_at' => $sourceCreatedAt,
    ]);

    $invoiceId = (int)$pdo->lastInsertId();

    if ($status === 'paid') {
        $paidDate = backfillNormalizeDate($invoice['paid_date'] ?? null) ?: date('Y-m-d');
        $upd = $pdo->prepare('UPDATE invoices_sale SET payment_date = :payment_date WHERE id = :id');
        $upd->execute([':payment_date' => $paidDate, ':id' => $invoiceId]);
    }

    return $invoiceId;
}

function enrichExistingSalesInvoiceMetadata(PDO $pdo, int $invoiceId, array $invoice): bool
{
    $fakturowniaId = isset($invoice['id']) ? (int)$invoice['id'] : 0;
    if ($fakturowniaId <= 0 || $invoiceId <= 0) {
        return false;
    }

    $documentKind = mapDocumentKind($invoice);
    $sourceRawKind = strtolower(trim((string)($invoice['kind'] ?? '')));
    if ($sourceRawKind === '') {
        $sourceRawKind = null;
    }

    $issueDate = backfillNormalizeDate($invoice['issue_date'] ?? null);
    $sourceCreatedAt = backfillNormalizeDateTime($invoice['created_at'] ?? null);
    if ($sourceCreatedAt === null && $issueDate !== null) {
        $sourceCreatedAt = $issueDate . ' 00:00:00';
    }

    $stmt = $pdo->prepare(
        "UPDATE invoices_sale
         SET
            document_kind = :document_kind,
            source_system = 'fakturownia',
            source_external_id = :source_external_id,
            source_raw_kind = COALESCE(:source_raw_kind, source_raw_kind),
            source_created_at = COALESCE(source_created_at, :source_created_at),
            created_at = CASE
                WHEN :source_created_at IS NOT NULL
                     AND (created_at IS NULL OR created_at > :source_created_at)
                THEN :source_created_at
                ELSE created_at
            END,
            updated_at = NOW()
         WHERE id = :id"
    );

    $stmt->execute([
        ':document_kind' => $documentKind,
        ':source_external_id' => $fakturowniaId,
        ':source_raw_kind' => $sourceRawKind,
        ':source_created_at' => $sourceCreatedAt,
        ':id' => $invoiceId,
    ]);

    return $stmt->rowCount() > 0;
}

function insertLocalSalesItems(PDO $pdo, int $invoiceId, array $positions): void
{
    if (empty($positions)) {
        $positions = [[
            'name' => 'Pozycja importowana',
            'quantity' => 1,
            'tax' => 23,
            'price_net' => 0,
        ]];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO invoice_sale_items
        (invoice_id, project_id, cost_node_id, item_name, quantity, unit, unit_price_net, vat_rate, amount_net, amount_vat, amount_gross, sort_order)
        VALUES
        (:invoice_id, NULL, NULL, :item_name, :quantity, :unit, :unit_price_net, :vat_rate, :amount_net, :amount_vat, :amount_gross, :sort_order)"
    );

    $sort = 0;
    foreach ($positions as $pos) {
        $item = is_array($pos) && isset($pos['position']) && is_array($pos['position']) ? $pos['position'] : $pos;

        $quantity = max(1.0, (float)($item['quantity'] ?? 1));
        $unit = trim((string)($item['unit'] ?? 'szt')) ?: 'szt';
        $vatRate = normalizeVatRateForItem($item['tax'] ?? $item['vat'] ?? '23');

        $unitPriceNet = backfillNormalizeMoney($item['price_net'] ?? $item['unit_price_net'] ?? 0);

        $amountNet = backfillNormalizeMoney($item['total_price_net'] ?? null);
        if ($amountNet <= 0) {
            $amountNet = round($quantity * $unitPriceNet, 2);
        }

        $amountVat = backfillNormalizeMoney($item['total_price_tax'] ?? $item['price_tax'] ?? null);
        if ($amountVat <= 0) {
            $rate = is_numeric($vatRate) ? ((float)$vatRate / 100.0) : 0.0;
            $amountVat = round($amountNet * $rate, 2);
        }

        $amountGross = backfillNormalizeMoney($item['total_price_gross'] ?? $item['price_gross'] ?? null);
        if ($amountGross <= 0) {
            $amountGross = round($amountNet + $amountVat, 2);
        }

        $stmt->execute([
            ':invoice_id' => $invoiceId,
            ':item_name' => trim((string)($item['name'] ?? 'Pozycja importowana')),
            ':quantity' => $quantity,
            ':unit' => $unit,
            ':unit_price_net' => $unitPriceNet,
            ':vat_rate' => (string)$vatRate,
            ':amount_net' => $amountNet,
            ':amount_vat' => $amountVat,
            ':amount_gross' => $amountGross,
            ':sort_order' => $sort,
        ]);

        $sort++;
    }
}

function normalizeVatRateForItem($vat)
{
    $v = strtolower(trim((string)$vat));
    if ($v === '' || $v === 'zw' || $v === 'np') {
        return $v === '' ? '23' : $v;
    }

    if (is_numeric($v)) {
        return (string)(int)$v;
    }

    return '23';
}

function updateLocalSalesInvoicePaymentStatus(PDO $pdo, int $invoiceId, array $invoice): bool
{
    $targetStatus = mapSalesStatus($invoice);

    if ($targetStatus === 'paid') {
        $paidDate = backfillNormalizeDate($invoice['paid_date'] ?? null) ?: date('Y-m-d');
        $stmt = $pdo->prepare('UPDATE invoices_sale SET status = :status, payment_date = :payment_date WHERE id = :id AND status <> :status');
        $stmt->execute([':status' => 'paid', ':payment_date' => $paidDate, ':id' => $invoiceId]);
        backfillEnsureFkSyncPayment($pdo, $invoiceId, $paidDate);
        return $stmt->rowCount() > 0;
    }

    if ($targetStatus === 'partially_paid') {
        $stmt = $pdo->prepare("UPDATE invoices_sale SET status = 'partially_paid' WHERE id = :id AND status NOT IN ('paid', 'partially_paid')");
        $stmt->execute([':id' => $invoiceId]);
        return $stmt->rowCount() > 0;
    }

    return false;
}

function backfillEnsureFkSyncPayment(PDO $pdo, int $invoiceId, string $paymentDate): void {
    $existing = $pdo->prepare('SELECT COUNT(*) FROM invoice_sale_payments WHERE invoice_id = ?');
    $existing->execute([$invoiceId]);
    if ((int)$existing->fetchColumn() > 0) return;

    $invStmt = $pdo->prepare('SELECT amount_net FROM invoices_sale WHERE id = ?');
    $invStmt->execute([$invoiceId]);
    $amount = (float)$invStmt->fetchColumn();
    if ($amount <= 0) return;

    $pdo->prepare(
        "INSERT INTO invoice_sale_payments (invoice_id, amount_net, payment_date, source, created_at) VALUES (?, ?, ?, 'fk_sync', NOW())"
    )->execute([$invoiceId, $amount, $paymentDate]);
}

function upsertFakturowniaInvoiceMapping(PDO $pdo, array $invoice, int $localInvoiceId): void
{
    $fakturowniaId = (int)($invoice['id'] ?? 0);
    if ($fakturowniaId <= 0) {
        return;
    }

    $fakturowniaNumber = (string)($invoice['number'] ?? '');
    $govId = (string)($invoice['gov_id'] ?? '');
    $govStatus = mapGovStatus($invoice);
    $mappedStatus = mapFakturowniaStatusForMapping($invoice);
    $requestHash = md5('sales_import_' . $fakturowniaId);

    // Jeśli mapowanie po fakturownia_id już istnieje (np. utworzone wcześniej z ERP),
    // aktualizujemy ten rekord i nie dokładamy duplikatu.
    $existingByFid = $pdo->prepare(
        'SELECT id FROM fakturownia_invoices WHERE fakturownia_id = :fakturownia_id LIMIT 1'
    );
    $existingByFid->execute([':fakturownia_id' => $fakturowniaId]);
    $existingId = (int)$existingByFid->fetchColumn();

    if ($existingId > 0) {
        $upd = $pdo->prepare(
            "UPDATE fakturownia_invoices
             SET fakturownia_number = :fakturownia_number,
                 gov_id = :gov_id,
                 gov_status = :gov_status,
                 status = :status,
                 erp_invoice_sale_id = COALESCE(erp_invoice_sale_id, :erp_invoice_sale_id),
                 synced_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id"
        );
        $upd->execute([
            ':fakturownia_number' => $fakturowniaNumber,
            ':gov_id' => $govId,
            ':gov_status' => $govStatus,
            ':status' => $mappedStatus,
            ':erp_invoice_sale_id' => $localInvoiceId > 0 ? $localInvoiceId : null,
            ':id' => $existingId,
        ]);

        logEvent('Sales Backfill mapping updated by fakturownia_id: local_invoice_id=' . $localInvoiceId . ', fakturownia_id=' . $fakturowniaId, 'INFO');
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO fakturownia_invoices
        (erp_contract_id, erp_milestone_id, erp_invoice_sale_id, fakturownia_id, fakturownia_number, gov_id, gov_status, status, pdf_path, request_hash, created_at, synced_at)
        VALUES
        (NULL, NULL, :erp_invoice_sale_id, :fakturownia_id, :fakturownia_number, :gov_id, :gov_status, :status, NULL, :request_hash, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            fakturownia_id = VALUES(fakturownia_id),
            fakturownia_number = VALUES(fakturownia_number),
            gov_id = VALUES(gov_id),
            gov_status = VALUES(gov_status),
            status = VALUES(status),
            erp_invoice_sale_id = COALESCE(erp_invoice_sale_id, VALUES(erp_invoice_sale_id)),
            synced_at = NOW(),
            updated_at = NOW()"
    );

    $stmt->execute([
        ':erp_invoice_sale_id' => $localInvoiceId > 0 ? $localInvoiceId : null,
        ':fakturownia_id' => $fakturowniaId,
        ':fakturownia_number' => $fakturowniaNumber,
        ':gov_id' => $govId,
        ':gov_status' => $govStatus,
        ':status' => $mappedStatus,
        ':request_hash' => $requestHash,
    ]);

    // Dodatkowy ślad biznesowy w logach aplikacji.
    logEvent('Sales Backfill mapping synced: local_invoice_id=' . $localInvoiceId . ', fakturownia_id=' . $fakturowniaId, 'INFO');
}
