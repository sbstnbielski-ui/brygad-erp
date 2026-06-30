<?php
/**
 * BRYGAD ERP - CRON: Import faktur kosztowych z Fakturowni do lokalnego inboxu
 *
 * Pobiera faktury kosztowe (income=no) z API Fakturowni i zapisuje je
 * w tabeli fakturownia_cost_invoices (upsert).
 *
 * Deduplikacja:
 *   1. po ksef_number (gov_id) - priorytet
 *   2. fallback po fakturownia_id
 *
 * Workflow:
 *   - nowe rekordy => workflow_status='new'
 *   - update => nie nadpisuje workflow_status jeśli decyzja już podjęta (accepted/rejected/archived)
 *
 * Obsługa błędów:
 *   - 401 => CRITICAL + exit(1)
 *   - 429/503 => retry/backoff w FakturowniaClient
 *   - inne => log ERROR i kontynuuj
 *
 * Crontab (co 4 godziny, dopasuj do liczby dokumentów):
 *   0 *\/4 * * * /usr/bin/php /var/www/sprutex/public/cron/fakturownia_cost_inbox_sync.php >> /var/log/sprutex/cron_cost_inbox.log 2>&1
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/modules/fakturownia/FakturowniaClient.php';

// ============================================================
// Funkcje pomocnicze — MUSZĄ być zdefiniowane przed użyciem
// (tryb include wewnątrz funkcji wymaga tego)
// ============================================================

if (!function_exists('upsertCostInvoice')):
/**
 * Upsert faktury kosztowej do fakturownia_cost_invoices.
 *
 * Zwraca: 'inserted' | 'updated' | 'skipped' | 'conflict'
 */
function upsertCostInvoice(PDO $pdo, array $raw): string
{
    $fakturowniaId = isset($raw['id']) ? (int)$raw['id'] : null;
    $ksefNumber    = normalizeKsefNumber($raw['gov_id'] ?? null);
    $invoiceNumber = trim((string)($raw['number'] ?? ''));
    // Dla faktur kosztowych (income=0) Fakturownia odwraca role:
    // buyer_* = faktyczny sprzedawca/dostawca na dokumencie
    $supplierName  = trim((string)($raw['buyer_name'] ?? $raw['seller_name'] ?? ''));
    $supplierNip   = normalizeNip((string)($raw['buyer_tax_no'] ?? $raw['seller_tax_no'] ?? ''));
    $issueDate     = normalizeDate($raw['issue_date'] ?? null);
    $saleDate      = normalizeDate($raw['sell_date'] ?? $raw['transaction_date'] ?? null);
    $dueDate       = normalizeDate($raw['payment_to'] ?? null);
    $currency      = strtoupper(trim((string)($raw['currency'] ?? 'PLN'))) ?: 'PLN';
    $amountNet     = normalizeMoney($raw['price_net'] ?? 0);
    $amountVat     = normalizeMoney($raw['price_tax'] ?? 0);
    $amountGross   = normalizeMoney($raw['price_gross'] ?? $raw['total'] ?? 0);
    $paymentStatus = mapPaymentStatus($raw);
    $hasCorrectionColumns = costCorrectionColumnsAvailable($pdo);
    $correctionMeta = $hasCorrectionColumns
        ? detectCostCorrectionMeta($pdo, $raw, $amountNet, $amountVat, $amountGross)
        : null;

    $payload = $raw;
    unset($payload['api_token']);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (empty($supplierName)) {
        $supplierName = 'Nieznany dostawca';
    }

    $conflict = checkDeduplicationConflict($pdo, $ksefNumber, $fakturowniaId);
    if ($conflict !== null) {
        logEvent(
            "Cost Inbox CONFLICT: ksef_number={$ksefNumber} wskazuje na local_id={$conflict['ksef_local_id']}, "
            . "ale fakturownia_id={$fakturowniaId} wskazuje na local_id={$conflict['fid_local_id']}. "
            . "Rekord pominięty — wymaga ręcznej weryfikacji.",
            'ERROR'
        );
        return 'conflict';
    }

    $existing = findExistingRecord($pdo, $ksefNumber, $fakturowniaId);

    if ($existing === null) {
        $correctionInsertColumns = '';
        $correctionInsertValues = '';
        $correctionParams = [];
        if ($hasCorrectionColumns && $correctionMeta !== null) {
            $correctionInsertColumns = ',
                 document_kind, correction_of_fakturownia_id, correction_of_cost_invoice_id,
                 correction_effect_net, correction_effect_vat, correction_effect_gross,
                 correction_reason_text';
            $correctionInsertValues = ',
                 :document_kind, :correction_of_fakturownia_id, :correction_of_cost_invoice_id,
                 :correction_effect_net, :correction_effect_vat, :correction_effect_gross,
                 :correction_reason_text';
            $correctionParams = [
                ':document_kind' => $correctionMeta['document_kind'],
                ':correction_of_fakturownia_id' => $correctionMeta['correction_of_fakturownia_id'],
                ':correction_of_cost_invoice_id' => $correctionMeta['correction_of_cost_invoice_id'],
                ':correction_effect_net' => $correctionMeta['correction_effect_net'],
                ':correction_effect_vat' => $correctionMeta['correction_effect_vat'],
                ':correction_effect_gross' => $correctionMeta['correction_effect_gross'],
                ':correction_reason_text' => $correctionMeta['correction_reason_text'],
            ];
        }
        $stmt = $pdo->prepare("
            INSERT INTO fakturownia_cost_invoices
                (fakturownia_id, ksef_number, invoice_number,
                 supplier_name, supplier_nip,
                 issue_date, sale_date, due_date,
                 currency, amount_net, amount_vat, amount_gross,
                 payment_status, workflow_status,
                 source_payload_json{$correctionInsertColumns}, imported_at, synced_at,
                 created_at, updated_at)
            VALUES
                (:fakturownia_id, :ksef_number, :invoice_number,
                 :supplier_name, :supplier_nip,
                 :issue_date, :sale_date, :due_date,
                 :currency, :amount_net, :amount_vat, :amount_gross,
                 :payment_status, 'new',
                 :source_payload_json{$correctionInsertValues}, NOW(), NOW(),
                 NOW(), NOW())
        ");
        $stmt->execute(array_merge([
            ':fakturownia_id'     => $fakturowniaId,
            ':ksef_number'        => $ksefNumber ?: null,
            ':invoice_number'     => $invoiceNumber ?: null,
            ':supplier_name'      => $supplierName,
            ':supplier_nip'       => $supplierNip ?: null,
            ':issue_date'         => $issueDate,
            ':sale_date'          => $saleDate,
            ':due_date'           => $dueDate,
            ':currency'           => $currency,
            ':amount_net'         => $amountNet,
            ':amount_vat'         => $amountVat,
            ':amount_gross'       => $amountGross,
            ':payment_status'     => $paymentStatus,
            ':source_payload_json'=> $payloadJson,
        ], $correctionParams));
        return 'inserted';
    }

    // UPDATE — rekord istnieje
    $localId = (int)$existing['id'];
    $setClauses = [
        'fakturownia_id      = :fakturownia_id',
        'invoice_number      = :invoice_number',
        'supplier_name       = :supplier_name',
        'supplier_nip        = :supplier_nip',
        'issue_date          = :issue_date',
        'sale_date           = :sale_date',
        'due_date            = :due_date',
        'currency            = :currency',
        'amount_net          = :amount_net',
        'amount_vat          = :amount_vat',
        'amount_gross        = :amount_gross',
        'payment_status      = :payment_status',
        'source_payload_json = :source_payload_json',
        'synced_at           = NOW()',
        'updated_at          = NOW()',
    ];
    if ($hasCorrectionColumns && $correctionMeta !== null) {
        $setClauses[] = 'document_kind = :document_kind';
        $setClauses[] = 'correction_of_fakturownia_id = :correction_of_fakturownia_id';
        $setClauses[] = 'correction_of_cost_invoice_id = :correction_of_cost_invoice_id';
        $setClauses[] = 'correction_effect_net = :correction_effect_net';
        $setClauses[] = 'correction_effect_vat = :correction_effect_vat';
        $setClauses[] = 'correction_effect_gross = :correction_effect_gross';
        $setClauses[] = 'correction_reason_text = :correction_reason_text';
    }

    if (empty($existing['ksef_number']) && !empty($ksefNumber)) {
        $setClauses[] = 'ksef_number = :ksef_number';
        $bindKsef = true;
    } else {
        $bindKsef = false;
    }

    $sql = 'UPDATE fakturownia_cost_invoices SET '
        . implode(', ', $setClauses)
        . ' WHERE id = :id';

    $stmt = $pdo->prepare($sql);
    $params = [
        ':fakturownia_id'      => $fakturowniaId,
        ':invoice_number'      => $invoiceNumber ?: null,
        ':supplier_name'       => $supplierName,
        ':supplier_nip'        => $supplierNip ?: null,
        ':issue_date'          => $issueDate,
        ':sale_date'           => $saleDate,
        ':due_date'            => $dueDate,
        ':currency'            => $currency,
        ':amount_net'          => $amountNet,
        ':amount_vat'          => $amountVat,
        ':amount_gross'        => $amountGross,
        ':payment_status'      => $paymentStatus,
        ':source_payload_json' => $payloadJson,
        ':id'                  => $localId,
    ];
    if ($hasCorrectionColumns && $correctionMeta !== null) {
        $params[':document_kind'] = $correctionMeta['document_kind'];
        $params[':correction_of_fakturownia_id'] = $correctionMeta['correction_of_fakturownia_id'];
        $params[':correction_of_cost_invoice_id'] = $correctionMeta['correction_of_cost_invoice_id'];
        $params[':correction_effect_net'] = $correctionMeta['correction_effect_net'];
        $params[':correction_effect_vat'] = $correctionMeta['correction_effect_vat'];
        $params[':correction_effect_gross'] = $correctionMeta['correction_effect_gross'];
        $params[':correction_reason_text'] = $correctionMeta['correction_reason_text'];
    }
    if ($bindKsef) {
        $params[':ksef_number'] = $ksefNumber;
    }

    $stmt->execute($params);
    return 'updated';
}
endif;

if (!function_exists('checkDeduplicationConflict')):
function checkDeduplicationConflict(PDO $pdo, ?string $ksefNumber, ?int $fakturowniaId): ?array
{
    if (empty($ksefNumber) || $fakturowniaId === null) {
        return null;
    }

    $stmtKsef = $pdo->prepare(
        'SELECT id FROM fakturownia_cost_invoices WHERE ksef_number = ? LIMIT 1'
    );
    $stmtKsef->execute([$ksefNumber]);
    $ksefRow = $stmtKsef->fetch(PDO::FETCH_ASSOC);

    $stmtFid = $pdo->prepare(
        'SELECT id FROM fakturownia_cost_invoices WHERE fakturownia_id = ? LIMIT 1'
    );
    $stmtFid->execute([$fakturowniaId]);
    $fidRow = $stmtFid->fetch(PDO::FETCH_ASSOC);

    if ($ksefRow && $fidRow && (int)$ksefRow['id'] !== (int)$fidRow['id']) {
        return [
            'ksef_local_id' => (int)$ksefRow['id'],
            'fid_local_id'  => (int)$fidRow['id'],
        ];
    }

    return null;
}
endif;

if (!function_exists('findExistingRecord')):
function findExistingRecord(PDO $pdo, ?string $ksefNumber, ?int $fakturowniaId): ?array
{
    if (!empty($ksefNumber)) {
        $stmt = $pdo->prepare(
            'SELECT id, workflow_status, ksef_number FROM fakturownia_cost_invoices WHERE ksef_number = ? LIMIT 1'
        );
        $stmt->execute([$ksefNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    if ($fakturowniaId !== null) {
        $stmt = $pdo->prepare(
            'SELECT id, workflow_status, ksef_number FROM fakturownia_cost_invoices WHERE fakturownia_id = ? LIMIT 1'
        );
        $stmt->execute([$fakturowniaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    return null;
}
endif;

if (!function_exists('costCorrectionColumnsAvailable')):
function costCorrectionColumnsAvailable(PDO $pdo): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $required = [
        'document_kind',
        'correction_of_fakturownia_id',
        'correction_of_cost_invoice_id',
        'correction_effect_net',
        'correction_effect_vat',
        'correction_effect_gross',
        'correction_reason_text',
    ];

    try {
        foreach ($required as $column) {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `fakturownia_cost_invoices` LIKE ?");
            $stmt->execute([$column]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $available = false;
                return false;
            }
        }
    } catch (Throwable $e) {
        $available = false;
        return false;
    }

    $available = true;
    return true;
}
endif;

if (!function_exists('findLocalCostInvoiceByFakturowniaId')):
function findLocalCostInvoiceByFakturowniaId(PDO $pdo, ?int $fakturowniaId): ?int
{
    if ($fakturowniaId === null || $fakturowniaId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM fakturownia_cost_invoices WHERE fakturownia_id = ? LIMIT 1");
        $stmt->execute([$fakturowniaId]);
        $id = (int)$stmt->fetchColumn();
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}
endif;

if (!function_exists('detectCostCorrectionMeta')):
function detectCostCorrectionMeta(PDO $pdo, array $raw, float $amountNet, float $amountVat, float $amountGross): array
{
    $kind = strtolower(trim((string)($raw['kind'] ?? $raw['invoice_kind'] ?? $raw['type'] ?? '')));
    $fromFakturowniaId = (int)(
        $raw['from_invoice_id']
        ?? $raw['corrected_invoice_id']
        ?? $raw['correction_of_invoice_id']
        ?? 0
    );
    if ($fromFakturowniaId <= 0 && $kind === 'correction') {
        $fromFakturowniaId = (int)($raw['invoice_id'] ?? 0);
    }

    $hasCorrectionFields = isset($raw['correction_reason'])
        || isset($raw['corrected_content_before'])
        || isset($raw['corrected_content_after'])
        || isset($raw['correction_before_attributes'])
        || isset($raw['correction_after_attributes']);

    $isCorrection = $kind === 'correction' || $fromFakturowniaId > 0 || $hasCorrectionFields;
    $reason = trim((string)($raw['correction_reason'] ?? $raw['reason'] ?? ''));

    return [
        'document_kind' => $isCorrection ? 'correction' : 'invoice',
        'correction_of_fakturownia_id' => $isCorrection && $fromFakturowniaId > 0 ? $fromFakturowniaId : null,
        'correction_of_cost_invoice_id' => $isCorrection ? findLocalCostInvoiceByFakturowniaId($pdo, $fromFakturowniaId) : null,
        'correction_effect_net' => $isCorrection ? $amountNet : null,
        'correction_effect_vat' => $isCorrection ? $amountVat : null,
        'correction_effect_gross' => $isCorrection ? $amountGross : null,
        'correction_reason_text' => $isCorrection && $reason !== '' ? mb_substr($reason, 0, 500) : null,
    ];
}
endif;

if (!function_exists('mapPaymentStatus')):
function mapPaymentStatus(array $raw): string
{
    $paymentStatus = strtolower(trim((string)($raw['payment_status'] ?? '')));
    $status = strtolower(trim((string)($raw['status'] ?? $raw['state'] ?? '')));
    $paidAmount = normalizeMoney($raw['paid'] ?? 0);
    $grossAmount = normalizeMoney($raw['price_gross'] ?? $raw['total'] ?? 0);
    $paidDate = normalizeDate($raw['paid_date'] ?? null);

    if (in_array($paymentStatus, ['paid', 'zaplacona'], true)
        || in_array($status, ['paid', 'zaplacona'], true)
        || $paidDate !== null
        || ($grossAmount > 0 && $paidAmount >= $grossAmount)) {
        return 'paid';
    }

    if (in_array($paymentStatus, ['partial', 'partially_paid', 'czesciowo_zaplacona'], true)
        || in_array($status, ['partial', 'partially_paid'], true)
        || $paidAmount > 0) {
        return 'partial';
    }

    if (in_array($paymentStatus, ['unpaid', 'nieoplacona'], true)
        || in_array($status, ['issued', 'sent', 'received', 'wystawiona'], true)) {
        return 'unpaid';
    }

    return 'unknown';
}
endif;

if (!function_exists('normalizeKsefNumber')):
function normalizeKsefNumber($value): ?string
{
    $v = trim((string)($value ?? ''));
    return $v !== '' ? $v : null;
}
endif;

if (!function_exists('normalizeNip')):
function normalizeNip(string $nip): string
{
    return preg_replace('/[^0-9]/', '', $nip);
}
endif;

if (!function_exists('normalizeDate')):
function normalizeDate($value): ?string
{
    $v = trim((string)($value ?? ''));
    if ($v === '') {
        return null;
    }
    $ts = strtotime($v);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}
endif;

if (!function_exists('normalizeMoney')):
function normalizeMoney($value): float
{
    $v = str_replace(',', '.', trim((string)($value ?? '0')));
    return round((float)$v, 2);
}
endif;

// ============================================================
// Główna logika synchronizacji
// ============================================================

$pdo = getDbConnection();

$perPage = 100;

$allowedPeriods = [
    'last_30_days', 'this_month', 'last_month', 'this_year', 'last_year', 'all',
];
$_syncArgv = $GLOBALS['argv'] ?? (isset($argv) ? $argv : []);
$period = isset($_syncArgv[1]) && in_array($_syncArgv[1], $allowedPeriods, true)
    ? $_syncArgv[1]
    : 'last_30_days';
unset($_syncArgv);

try {
    $client = new FakturowniaClient($pdo);

    $page      = 1;
    $imported  = 0;
    $updated   = 0;
    $skipped   = 0;
    $conflicts = 0;
    $errors    = 0;

    logEvent("CRON Cost Inbox: start (period={$period}, mode=" . ($period === 'all' ? 'BACKFILL' : 'incremental') . ")", 'INFO');

    do {
        $endpoint = '/invoices.json?income=no&period=' . urlencode($period)
            . '&page=' . $page
            . '&per_page=' . $perPage;

        $response = $client->get($endpoint);
        $invoices = $response['data'] ?? [];

        if (!is_array($invoices) || count($invoices) === 0) {
            echo '[' . date('Y-m-d H:i:s') . "] Page {$page}: 0 invoices from API (end of data)\n";
            break;
        }

        $kindsOnPage = [];
        foreach ($invoices as $_inv) {
            $k = $_inv['kind'] ?? 'unknown';
            $kindsOnPage[$k] = ($kindsOnPage[$k] ?? 0) + 1;
        }
        $kindsSummary = [];
        foreach ($kindsOnPage as $k => $cnt) {
            $kindsSummary[] = "{$k}={$cnt}";
        }
        echo '[' . date('Y-m-d H:i:s') . "] Page {$page}: " . count($invoices) . " invoices fetched (kinds: " . implode(', ', $kindsSummary) . ")\n";

        foreach ($invoices as $raw) {
            try {
                $fid = isset($raw['id']) ? (int)$raw['id'] : 0;
                $fKind = $raw['kind'] ?? 'unknown';
                $fNumber = $raw['number'] ?? '?';
                $result = upsertCostInvoice($pdo, $raw);
                if ($result === 'inserted') {
                    $imported++;
                    echo "  + INSERT fakturownia_id={$fid} kind={$fKind} number={$fNumber}\n";
                } elseif ($result === 'updated') {
                    $updated++;
                } elseif ($result === 'conflict') {
                    $conflicts++;
                    echo "  ! CONFLICT fakturownia_id={$fid} kind={$fKind} number={$fNumber}\n";
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors++;
                $fid = isset($raw['id']) ? (int)$raw['id'] : 0;
                echo "  X ERROR fakturownia_id={$fid}: " . $e->getMessage() . "\n";
                logEvent("Cost Inbox upsert error (fakturownia_id={$fid}): " . $e->getMessage(), 'ERROR');
            }
        }

        $fetchedCount = count($invoices);
        $page++;

    } while ($fetchedCount === $perPage);

    $totalPages = $page - 1;
    $summary = "Cost Inbox Sync done: imported={$imported}, updated={$updated}, skipped={$skipped}, conflicts={$conflicts}, errors={$errors}, pages_fetched={$totalPages}";
    echo '[' . date('Y-m-d H:i:s') . '] ' . $summary . "\n";
    logEvent('CRON ' . $summary, 'INFO');

} catch (FakturowniaAuthException $e) {
    $msg = 'FAKTUROWNIA CRITICAL: Błąd autoryzacji w cost_inbox_sync — sprawdź token. ' . $e->getMessage();
    logEvent($msg, 'CRITICAL');
    echo '[' . date('Y-m-d H:i:s') . '] CRITICAL AUTH: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') { exit(1); }

} catch (Throwable $e) {
    logEvent('CRON Cost Inbox ERROR: ' . $e->getMessage(), 'ERROR');
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') { exit(1); }
}
