<?php
/**
 * BRYGAD ERP - CRON: Synchronizacja katalogu produktów z Fakturowni
 *
 * Cel:
 * - pobierać produkty/usługi z Fakturowni do lokalnej tabeli fakturownia_products,
 * - zapewnić idempotentny upsert (po fakturownia_product_id i/lub code),
 * - udostępnić dane do formularzy faktur sprzedażowych w ERP.
 *
 * Crontab (np. raz na noc):
 *   10 2 * * * /usr/bin/php /var/www/sprutex/public/cron/fakturownia_products_sync.php >> /var/log/sprutex/cron_products_sync.log 2>&1
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/modules/fakturownia/FakturowniaClient.php';

$pdo = getDbConnection();
$perPage = 100;

try {
    $client = new FakturowniaClient($pdo);

    $page = 1;
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $conflicts = 0;
    $errors = 0;

    logEvent('CRON Products Sync: start', 'INFO');

    do {
        $endpoint = '/products.json?page=' . $page . '&per_page=' . $perPage;
        $response = $client->get($endpoint);
        $products = extractProductsList($response['data'] ?? []);

        if (count($products) === 0) {
            break;
        }

        foreach ($products as $raw) {
            try {
                $result = upsertFakturowniaProduct($pdo, $raw);
                if ($result === 'inserted') {
                    $inserted++;
                } elseif ($result === 'updated') {
                    $updated++;
                } elseif ($result === 'conflict') {
                    $conflicts++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors++;
                $fid = isset($raw['id']) ? (int)$raw['id'] : 0;
                logEvent('Products Sync upsert error (fakturownia_product_id=' . $fid . '): ' . $e->getMessage(), 'ERROR');
            }
        }

        $fetchedCount = count($products);
        $page++;
    } while ($fetchedCount === $perPage);

    $summary = 'Products Sync done: inserted=' . $inserted
        . ', updated=' . $updated
        . ', skipped=' . $skipped
        . ', conflicts=' . $conflicts
        . ', errors=' . $errors;

    echo '[' . date('Y-m-d H:i:s') . '] ' . $summary . "\n";
    logEvent('CRON ' . $summary, 'INFO');
} catch (FakturowniaAuthException $e) {
    $msg = 'FAKTUROWNIA CRITICAL: Błąd autoryzacji w products_sync — sprawdź token. ' . $e->getMessage();
    logEvent($msg, 'CRITICAL');
    echo '[' . date('Y-m-d H:i:s') . '] CRITICAL AUTH: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
} catch (Throwable $e) {
    logEvent('CRON Products Sync ERROR: ' . $e->getMessage(), 'ERROR');
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
}

/**
 * API może zwracać:
 * - tablicę produktów,
 * - albo obiekt z kluczem "products".
 */
function extractProductsList($data): array
{
    if (!is_array($data)) {
        return [];
    }

    if (isset($data[0]) && is_array($data[0])) {
        return $data;
    }

    if (isset($data['products']) && is_array($data['products'])) {
        return $data['products'];
    }

    return [];
}

/**
 * Upsert produktu do tabeli fakturownia_products.
 *
 * Zwraca: inserted | updated | skipped | conflict
 */
function upsertFakturowniaProduct(PDO $pdo, array $raw): string
{
    $fakturowniaProductId = isset($raw['id']) ? (int)$raw['id'] : 0;
    if ($fakturowniaProductId <= 0) {
        $fakturowniaProductId = null;
    }

    $code = normalizeText($raw['code'] ?? null);
    $name = normalizeText($raw['name'] ?? null);
    $description = normalizeText($raw['description'] ?? null);
    $unit = normalizeText($raw['unit'] ?? null);
    $tax = normalizeTax($raw['tax'] ?? ($raw['vat'] ?? null));
    $priceNet = normalizeMoney($raw['price_net'] ?? ($raw['net_price'] ?? ($raw['price'] ?? 0)));
    $priceGross = normalizeMoney($raw['price_gross'] ?? ($raw['gross_price'] ?? 0));
    $currency = normalizeCurrency($raw['currency'] ?? 'PLN');
    $isActive = normalizeActiveFlag($raw);

    if ($priceGross <= 0 && $priceNet > 0) {
        $vatPercent = is_numeric($tax) ? (float)$tax : 0.0;
        $priceGross = round($priceNet * (1 + ($vatPercent / 100)), 2);
    }

    if ($name === null || $name === '') {
        return 'skipped';
    }

    $payload = $raw;
    unset($payload['api_token']);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $resolution = resolveExistingProduct($pdo, $fakturowniaProductId, $code);
    if ($resolution['mode'] === 'conflict') {
        logEvent(
            'Products Sync CONFLICT: ' . ($resolution['message'] ?? 'Niespójne mapowanie produktu.'),
            'ERROR'
        );
        return 'conflict';
    }

    if ($resolution['mode'] === 'none') {
        $stmt = $pdo->prepare("
            INSERT INTO fakturownia_products
                (fakturownia_product_id, code, name, description, unit, tax,
                 price_net, price_gross, currency, is_active, source_payload_json,
                 synced_at, created_at, updated_at)
            VALUES
                (:fakturownia_product_id, :code, :name, :description, :unit, :tax,
                 :price_net, :price_gross, :currency, :is_active, :source_payload_json,
                 NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            ':fakturownia_product_id' => $fakturowniaProductId,
            ':code' => $code,
            ':name' => $name,
            ':description' => $description,
            ':unit' => $unit,
            ':tax' => $tax,
            ':price_net' => $priceNet,
            ':price_gross' => $priceGross,
            ':currency' => $currency,
            ':is_active' => $isActive,
            ':source_payload_json' => $payloadJson,
        ]);
        return 'inserted';
    }

    $existing = $resolution['row'];
    $localId = (int)$existing['id'];

    $effectiveFakturowniaId = $fakturowniaProductId ?: (isset($existing['fakturownia_product_id']) ? (int)$existing['fakturownia_product_id'] : null);
    if ($effectiveFakturowniaId !== null && $effectiveFakturowniaId <= 0) {
        $effectiveFakturowniaId = null;
    }
    $effectiveCode = $code ?: ($existing['code'] ?? null);

    $stmt = $pdo->prepare("
        UPDATE fakturownia_products
        SET
            fakturownia_product_id = :fakturownia_product_id,
            code = :code,
            name = :name,
            description = :description,
            unit = :unit,
            tax = :tax,
            price_net = :price_net,
            price_gross = :price_gross,
            currency = :currency,
            is_active = :is_active,
            source_payload_json = :source_payload_json,
            synced_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':fakturownia_product_id' => $effectiveFakturowniaId,
        ':code' => $effectiveCode,
        ':name' => $name,
        ':description' => $description,
        ':unit' => $unit,
        ':tax' => $tax,
        ':price_net' => $priceNet,
        ':price_gross' => $priceGross,
        ':currency' => $currency,
        ':is_active' => $isActive,
        ':source_payload_json' => $payloadJson,
        ':id' => $localId,
    ]);

    return 'updated';
}

/**
 * Rozwiązuje deduplikację:
 * - priorytet po fakturownia_product_id,
 * - fallback po code.
 * Jeśli oba wskazują różne rekordy -> konflikt.
 */
function resolveExistingProduct(PDO $pdo, ?int $fakturowniaProductId, ?string $code): array
{
    $byId = null;
    $byCodeRows = [];

    if ($fakturowniaProductId !== null) {
        $stmt = $pdo->prepare(
            'SELECT id, fakturownia_product_id, code FROM fakturownia_products WHERE fakturownia_product_id = ? LIMIT 1'
        );
        $stmt->execute([$fakturowniaProductId]);
        $byId = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($code !== null && $code !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, fakturownia_product_id, code FROM fakturownia_products WHERE code = ? ORDER BY id ASC LIMIT 2'
        );
        $stmt->execute([$code]);
        $byCodeRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($byId && count($byCodeRows) > 0 && (int)$byId['id'] !== (int)$byCodeRows[0]['id']) {
        return [
            'mode' => 'conflict',
            'message' => 'fakturownia_product_id=' . $fakturowniaProductId
                . ' wskazuje na local_id=' . (int)$byId['id']
                . ', ale code=' . $code
                . ' wskazuje na local_id=' . (int)$byCodeRows[0]['id'],
        ];
    }

    if ($byId && count($byCodeRows) > 1) {
        return [
            'mode' => 'conflict',
            'message' => 'code=' . $code . ' wskazuje na więcej niż jeden rekord lokalny',
        ];
    }

    if (!$byId && count($byCodeRows) > 1) {
        return [
            'mode' => 'conflict',
            'message' => 'code=' . $code . ' wskazuje na więcej niż jeden rekord lokalny',
        ];
    }

    if ($byId) {
        return ['mode' => 'existing', 'row' => $byId];
    }

    if (count($byCodeRows) === 1) {
        return ['mode' => 'existing', 'row' => $byCodeRows[0]];
    }

    return ['mode' => 'none', 'row' => null];
}

function normalizeText($value): ?string
{
    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

function normalizeTax($value): ?string
{
    $tax = trim((string)$value);
    if ($tax === '') {
        return null;
    }

    if (strtolower($tax) === 'zw') {
        return 'zw';
    }

    if (is_numeric($tax)) {
        return (string)(int)$tax;
    }

    return $tax;
}

function normalizeMoney($value): float
{
    return round((float)$value, 2);
}

function normalizeCurrency($value): string
{
    $currency = strtoupper(trim((string)$value));
    if ($currency === '') {
        return 'PLN';
    }

    return substr($currency, 0, 3);
}

function normalizeActiveFlag(array $raw): int
{
    if (array_key_exists('deleted', $raw)) {
        return ((int)$raw['deleted'] === 1) ? 0 : 1;
    }

    if (array_key_exists('archived', $raw)) {
        return ((int)$raw['archived'] === 1) ? 0 : 1;
    }

    if (array_key_exists('active', $raw)) {
        return ((int)$raw['active'] === 1) ? 1 : 0;
    }

    return 1;
}
