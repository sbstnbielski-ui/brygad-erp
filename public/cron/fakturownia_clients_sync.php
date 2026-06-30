<?php
/**
 * BRYGAD ERP - CRON: Synchronizacja klientów z Fakturowni do ERP (investors)
 *
 * Cel:
 * - pobrać klientów z Fakturowni,
 * - utworzyć/uzupełnić rekordy w investors,
 * - zapisać mapowanie w fakturownia_clients.
 *
 * Uruchomienie (CLI):
 *   php public/cron/fakturownia_clients_sync.php
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
    $matched = 0;
    $skipped = 0;
    $conflicts = 0;
    $errors = 0;

    logEvent('CRON Clients Sync: start', 'INFO');

    do {
        $endpoint = '/clients.json?page=' . $page . '&per_page=' . $perPage;
        $response = $client->get($endpoint);
        $rows = extractClientsList($response['data'] ?? []);

        if (count($rows) === 0) {
            break;
        }

        foreach ($rows as $raw) {
            try {
                $result = upsertInvestorFromFakturowniaClient($pdo, $raw);
                if ($result === 'inserted') {
                    $inserted++;
                } elseif ($result === 'updated') {
                    $updated++;
                } elseif ($result === 'matched') {
                    $matched++;
                } elseif ($result === 'conflict') {
                    $conflicts++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors++;
                $fid = isset($raw['id']) ? (int)$raw['id'] : 0;
                logEvent('Clients Sync upsert error (fakturownia_client_id=' . $fid . '): ' . $e->getMessage(), 'ERROR');
            }
        }

        $fetchedCount = count($rows);
        $page++;
    } while ($fetchedCount === $perPage);

    $summary = 'Clients Sync done: inserted=' . $inserted
        . ', updated=' . $updated
        . ', matched=' . $matched
        . ', skipped=' . $skipped
        . ', conflicts=' . $conflicts
        . ', errors=' . $errors;

    echo '[' . date('Y-m-d H:i:s') . '] ' . $summary . "\n";
    logEvent('CRON ' . $summary, 'INFO');
} catch (FakturowniaAuthException $e) {
    $msg = 'FAKTUROWNIA CRITICAL: Błąd autoryzacji w clients_sync — sprawdź token. ' . $e->getMessage();
    logEvent($msg, 'CRITICAL');
    echo '[' . date('Y-m-d H:i:s') . '] CRITICAL AUTH: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
} catch (Throwable $e) {
    logEvent('CRON Clients Sync ERROR: ' . $e->getMessage(), 'ERROR');
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
}

function extractClientsList($data): array
{
    if (!is_array($data)) {
        return [];
    }

    $rows = [];

    if (isset($data[0]) && is_array($data[0])) {
        foreach ($data as $row) {
            if (isset($row['client']) && is_array($row['client'])) {
                $rows[] = $row['client'];
            } elseif (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    if (isset($data['clients']) && is_array($data['clients'])) {
        return extractClientsList($data['clients']);
    }

    if (isset($data['client']) && is_array($data['client'])) {
        return [$data['client']];
    }

    return [];
}

function upsertInvestorFromFakturowniaClient(PDO $pdo, array $raw): string
{
    $fakturowniaClientId = isset($raw['id']) ? (int)$raw['id'] : 0;
    $name = normalizeClientName($raw);
    $nip = normalizeNip((string)($raw['tax_no'] ?? ''));
    $email = normalizeText($raw['email'] ?? null);
    $phone = normalizeText($raw['phone'] ?? null);
    $contactPerson = normalizeText($raw['person'] ?? null);
    $address = buildAddress($raw);

    if ($name === null || $name === '') {
        return 'skipped';
    }

    $resolved = resolveInvestorId($pdo, $fakturowniaClientId, $nip, $name);
    if (!empty($resolved['conflict'])) {
        logEvent(
            'Clients Sync CONFLICT: fakturownia_client_id=' . $fakturowniaClientId
            . ', mapowanie=' . (int)($resolved['mapping_id'] ?? 0)
            . ', dopasowanie=' . (int)($resolved['other_match_id'] ?? 0),
            'ERROR'
        );
    }

    $investorId = (int)($resolved['investor_id'] ?? 0);
    $rowStatus = 'matched';

    if ($investorId <= 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO investors
            (type, first_name, last_name, name, nip, address, email, phone, contact_person, is_active, created_at, updated_at)
            VALUES
            (:type, :first_name, :last_name, :name, :nip, :address, :email, :phone, :contact_person, 1, NOW(), NOW())"
        );

        $type = $nip !== '' ? 'business' : 'private';
        $firstName = normalizeText($raw['first_name'] ?? null);
        $lastName = normalizeText($raw['last_name'] ?? null);

        $stmt->execute([
            ':type' => $type,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':name' => $name,
            ':nip' => $nip !== '' ? $nip : null,
            ':address' => $address,
            ':email' => $email,
            ':phone' => $phone,
            ':contact_person' => $contactPerson,
        ]);

        $investorId = (int)$pdo->lastInsertId();
        $rowStatus = 'inserted';
    } else {
        $changed = updateMissingInvestorFields($pdo, $investorId, [
            'name' => $name,
            'nip' => $nip !== '' ? $nip : null,
            'address' => $address,
            'email' => $email,
            'phone' => $phone,
            'contact_person' => $contactPerson,
            'first_name' => normalizeText($raw['first_name'] ?? null),
            'last_name' => normalizeText($raw['last_name'] ?? null),
        ]);

        if ($changed) {
            $rowStatus = 'updated';
        }
    }

    if ($fakturowniaClientId > 0 && $investorId > 0) {
        upsertClientMapping($pdo, $investorId, $fakturowniaClientId);
    }

    if (!empty($resolved['conflict'])) {
        return 'conflict';
    }

    return $rowStatus;
}

function resolveInvestorId(PDO $pdo, int $fakturowniaClientId, string $nip, string $name): array
{
    $mappingId = 0;
    if ($fakturowniaClientId > 0) {
        $stmt = $pdo->prepare('SELECT erp_client_id FROM fakturownia_clients WHERE fakturownia_id = :fid LIMIT 1');
        $stmt->execute([':fid' => $fakturowniaClientId]);
        $mappingId = (int)$stmt->fetchColumn();
    }

    $nipId = 0;
    if ($nip !== '') {
        $stmt = $pdo->prepare('SELECT id FROM investors WHERE REPLACE(REPLACE(nip, "-", ""), " ", "") = :nip LIMIT 1');
        $stmt->execute([':nip' => $nip]);
        $nipId = (int)$stmt->fetchColumn();
    }

    $nameId = 0;
    if ($name !== '') {
        $stmt = $pdo->prepare('SELECT id FROM investors WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $nameId = (int)$stmt->fetchColumn();
    }

    $fallbackId = $nipId > 0 ? $nipId : $nameId;

    if ($mappingId > 0 && $fallbackId > 0 && $mappingId !== $fallbackId) {
        return [
            'investor_id' => $mappingId,
            'conflict' => true,
            'mapping_id' => $mappingId,
            'other_match_id' => $fallbackId,
        ];
    }

    if ($mappingId > 0) {
        return ['investor_id' => $mappingId, 'conflict' => false];
    }

    if ($nipId > 0) {
        return ['investor_id' => $nipId, 'conflict' => false];
    }

    if ($nameId > 0) {
        return ['investor_id' => $nameId, 'conflict' => false];
    }

    return ['investor_id' => 0, 'conflict' => false];
}

function upsertClientMapping(PDO $pdo, int $erpClientId, int $fakturowniaClientId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO fakturownia_clients (erp_client_id, fakturownia_id, synced_at, created_at)
        VALUES (:erp_client_id, :fakturownia_id, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            fakturownia_id = VALUES(fakturownia_id),
            synced_at = NOW()"
    );
    $stmt->execute([
        ':erp_client_id' => $erpClientId,
        ':fakturownia_id' => $fakturowniaClientId,
    ]);
}

function updateMissingInvestorFields(PDO $pdo, int $investorId, array $incoming): bool
{
    $stmt = $pdo->prepare('SELECT * FROM investors WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $investorId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return false;
    }

    $set = [];
    $bind = [':id' => $investorId];

    $fillable = ['name', 'nip', 'address', 'email', 'phone', 'contact_person', 'first_name', 'last_name'];
    foreach ($fillable as $field) {
        $newVal = isset($incoming[$field]) ? trim((string)$incoming[$field]) : '';
        $oldVal = isset($existing[$field]) ? trim((string)$existing[$field]) : '';

        if ($newVal !== '' && $oldVal === '') {
            $set[] = $field . ' = :' . $field;
            $bind[':' . $field] = $newVal;
        }
    }

    if (empty($set)) {
        return false;
    }

    $set[] = 'updated_at = NOW()';

    $sql = 'UPDATE investors SET ' . implode(', ', $set) . ' WHERE id = :id';
    $upd = $pdo->prepare($sql);
    $upd->execute($bind);

    return true;
}

function normalizeClientName(array $raw): ?string
{
    $name = normalizeText($raw['name'] ?? null);
    if ($name !== null && $name !== '') {
        return $name;
    }

    $first = normalizeText($raw['first_name'] ?? null);
    $last = normalizeText($raw['last_name'] ?? null);
    $full = trim((string)$first . ' ' . (string)$last);
    if ($full !== '') {
        return $full;
    }

    return null;
}

function normalizeText($value): ?string
{
    $v = trim((string)$value);
    return $v !== '' ? $v : null;
}

function normalizeNip(string $nip): string
{
    return preg_replace('/[^0-9]/', '', $nip);
}

function buildAddress(array $raw): ?string
{
    $address = normalizeText($raw['address'] ?? null);
    if ($address !== null) {
        return $address;
    }

    $street = normalizeText($raw['street'] ?? null);
    $postCode = normalizeText($raw['post_code'] ?? null);
    $city = normalizeText($raw['city'] ?? null);
    $country = normalizeText($raw['country'] ?? null);

    $parts = [];
    if ($street !== null) {
        $parts[] = $street;
    }

    $cityLine = trim((string)$postCode . ' ' . (string)$city);
    if ($cityLine !== '') {
        $parts[] = $cityLine;
    }

    if ($country !== null) {
        $parts[] = $country;
    }

    if (empty($parts)) {
        return null;
    }

    return implode(', ', $parts);
}
