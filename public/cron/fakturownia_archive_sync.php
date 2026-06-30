<?php
/**
 * BRYGAD ERP - CRON: Archiwizacja dokumentów faktur z Fakturowni (PDF)
 *
 * Założenia architektury:
 * - dane biznesowe zostają w istniejących tabelach (faktury/alokacje),
 * - archiwum to warstwa plików + indeks metadanych w fakturownia_archive_files,
 * - pliki zapisywane rocznie/miesięcznie z tierem hot/cold.
 *
 * Tier:
 * - hot: dokument <= 24 miesiące
 * - cold: dokument > 24 miesiące
 *
 * Crontab (np. codziennie):
 *   25 2 * * * /usr/bin/php /var/www/sprutex/public/cron/fakturownia_archive_sync.php >> /var/log/sprutex/cron_archive_sync.log 2>&1
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/modules/fakturownia/FakturowniaClient.php';

$pdo = getDbConnection();
$maxPerSource = 300;

try {
    $client = new FakturowniaClient($pdo);
    ensureArchiveRootReady();

    $summary = [
        'sale_total' => 0,
        'cost_total' => 0,
        'archived' => 0,
        'verified' => 0,
        'updated' => 0,
        'errors' => 0,
    ];

    logEvent('CRON Archive Sync: start', 'INFO');

    $saleRows = fetchSalesSourceRows($pdo, $maxPerSource);
    $costRows = fetchCostSourceRows($pdo, $maxPerSource);
    $summary['sale_total'] = count($saleRows);
    $summary['cost_total'] = count($costRows);

    foreach ($saleRows as $row) {
        try {
            $result = archiveSingleDocument($pdo, $client, 'sale', $row);
            $summary[$result]++;
        } catch (Throwable $e) {
            $summary['errors']++;
            logEvent('Archive Sync sale error (local_id=' . (int)$row['source_local_id'] . '): ' . $e->getMessage(), 'ERROR');
        }
    }

    foreach ($costRows as $row) {
        try {
            $result = archiveSingleDocument($pdo, $client, 'cost', $row);
            $summary[$result]++;
        } catch (Throwable $e) {
            $summary['errors']++;
            logEvent('Archive Sync cost error (local_id=' . (int)$row['source_local_id'] . '): ' . $e->getMessage(), 'ERROR');
        }
    }

    $line = 'Archive Sync done: sales=' . $summary['sale_total']
        . ', costs=' . $summary['cost_total']
        . ', archived=' . $summary['archived']
        . ', verified=' . $summary['verified']
        . ', updated=' . $summary['updated']
        . ', errors=' . $summary['errors'];

    echo '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n";
    logEvent('CRON ' . $line, 'INFO');
} catch (FakturowniaAuthException $e) {
    $msg = 'FAKTUROWNIA CRITICAL: Błąd autoryzacji w archive_sync — sprawdź token. ' . $e->getMessage();
    logEvent($msg, 'CRITICAL');
    echo '[' . date('Y-m-d H:i:s') . '] CRITICAL AUTH: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
} catch (Throwable $e) {
    logEvent('CRON Archive Sync ERROR: ' . $e->getMessage(), 'ERROR');
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetchSalesSourceRows(PDO $pdo, int $limit): array
{
    $sql = "SELECT
                fi.id AS source_local_id,
                fi.fakturownia_id,
                fi.created_at AS document_date
            FROM fakturownia_invoices fi
            LEFT JOIN fakturownia_archive_files a
              ON a.source_type = 'sale'
             AND a.source_local_id = fi.id
             AND a.file_kind = 'pdf'
            WHERE fi.fakturownia_id IS NOT NULL
              AND a.id IS NULL
            ORDER BY fi.id ASC
            LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetchCostSourceRows(PDO $pdo, int $limit): array
{
    $sql = "SELECT
                ci.id AS source_local_id,
                ci.fakturownia_id,
                COALESCE(ci.issue_date, ci.imported_at, ci.created_at) AS document_date
            FROM fakturownia_cost_invoices ci
            LEFT JOIN fakturownia_archive_files a
              ON a.source_type = 'cost'
             AND a.source_local_id = ci.id
             AND a.file_kind = 'pdf'
            WHERE ci.fakturownia_id IS NOT NULL
              AND a.id IS NULL
            ORDER BY ci.id ASC
            LIMIT :lim";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string,mixed> $row
 * @return 'archived'|'verified'|'updated'
 */
function archiveSingleDocument(PDO $pdo, FakturowniaClient $client, string $sourceType, array $row): string
{
    $sourceLocalId = (int)($row['source_local_id'] ?? 0);
    $fakturowniaId = (int)($row['fakturownia_id'] ?? 0);
    if ($sourceLocalId <= 0 || $fakturowniaId <= 0) {
        throw new RuntimeException('Nieprawidłowe ID źródłowe/fakturownia.');
    }

    $documentDate = normalizeDocumentDate($row['document_date'] ?? null);
    $tier = resolveStorageTier($documentDate);
    [$year, $month] = resolveYearMonth($documentDate);

    $binaryPdf = $client->downloadPdf((string)$fakturowniaId);
    if ($binaryPdf === '') {
        throw new RuntimeException('Pobrany PDF jest pusty.');
    }

    $checksum = hash('sha256', $binaryPdf);
    $size = strlen($binaryPdf);

    $existing = findExistingArchiveRow($pdo, $sourceType, $sourceLocalId, 'pdf');
    if ($existing && !empty($existing['checksum_sha256']) && hash_equals((string)$existing['checksum_sha256'], $checksum)) {
        $path = (string)($existing['file_path'] ?? '');
        if ($path !== '' && file_exists(ROOT_PATH . '/' . ltrim($path, '/'))) {
            touchArchiveVerification($pdo, (int)$existing['id']);
            return 'verified';
        }
    }

    $relativeDir = 'storage/fakturownia-archive/' . $tier . '/' . $year . '/' . sprintf('%02d', $month) . '/' . $sourceType;
    $absoluteDir = ROOT_PATH . '/' . $relativeDir;
    ensureDirectory($absoluteDir);

    $fileName = buildArchiveFileName($sourceType, $sourceLocalId, $fakturowniaId, $year, $month);
    $relativePath = $relativeDir . '/' . $fileName;
    $absolutePath = ROOT_PATH . '/' . $relativePath;

    if (file_put_contents($absolutePath, $binaryPdf) === false) {
        throw new RuntimeException('Nie udało się zapisać pliku archiwum.');
    }

    $now = date('Y-m-d H:i:s');

    if (!$existing) {
        $stmt = $pdo->prepare(
            "INSERT INTO fakturownia_archive_files
            (source_type, source_local_id, fakturownia_id, file_kind, storage_tier,
             document_date, storage_year, storage_month, file_path, file_name,
             mime_type, file_size, checksum_sha256, archived_at, last_verified_at, created_at, updated_at)
            VALUES
            (:source_type, :source_local_id, :fakturownia_id, :file_kind, :storage_tier,
             :document_date, :storage_year, :storage_month, :file_path, :file_name,
             'application/pdf', :file_size, :checksum_sha256, NOW(), NOW(), NOW(), NOW())"
        );
        $stmt->execute([
            ':source_type' => $sourceType,
            ':source_local_id' => $sourceLocalId,
            ':fakturownia_id' => $fakturowniaId,
            ':file_kind' => 'pdf',
            ':storage_tier' => $tier,
            ':document_date' => $documentDate,
            ':storage_year' => $year,
            ':storage_month' => $month,
            ':file_path' => $relativePath,
            ':file_name' => $fileName,
            ':file_size' => $size,
            ':checksum_sha256' => $checksum,
        ]);
        return 'archived';
    }

    $oldPath = (string)($existing['file_path'] ?? '');
    if ($oldPath !== '' && $oldPath !== $relativePath) {
        $oldAbs = ROOT_PATH . '/' . ltrim($oldPath, '/');
        if (file_exists($oldAbs)) {
            @unlink($oldAbs);
        }
    }

    $upd = $pdo->prepare(
        "UPDATE fakturownia_archive_files
         SET
            fakturownia_id = :fakturownia_id,
            storage_tier = :storage_tier,
            document_date = :document_date,
            storage_year = :storage_year,
            storage_month = :storage_month,
            file_path = :file_path,
            file_name = :file_name,
            mime_type = 'application/pdf',
            file_size = :file_size,
            checksum_sha256 = :checksum_sha256,
            last_verified_at = :last_verified_at,
            updated_at = NOW()
         WHERE id = :id"
    );
    $upd->execute([
        ':fakturownia_id' => $fakturowniaId,
        ':storage_tier' => $tier,
        ':document_date' => $documentDate,
        ':storage_year' => $year,
        ':storage_month' => $month,
        ':file_path' => $relativePath,
        ':file_name' => $fileName,
        ':file_size' => $size,
        ':checksum_sha256' => $checksum,
        ':last_verified_at' => $now,
        ':id' => (int)$existing['id'],
    ]);

    return 'updated';
}

/**
 * @return array<string,mixed>|null
 */
function findExistingArchiveRow(PDO $pdo, string $sourceType, int $sourceLocalId, string $fileKind): ?array
{
    $stmt = $pdo->prepare(
        "SELECT *
         FROM fakturownia_archive_files
         WHERE source_type = :source_type
           AND source_local_id = :source_local_id
           AND file_kind = :file_kind
         LIMIT 1"
    );
    $stmt->execute([
        ':source_type' => $sourceType,
        ':source_local_id' => $sourceLocalId,
        ':file_kind' => $fileKind,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function touchArchiveVerification(PDO $pdo, int $archiveId): void
{
    $stmt = $pdo->prepare(
        "UPDATE fakturownia_archive_files
         SET last_verified_at = NOW(), updated_at = NOW()
         WHERE id = :id"
    );
    $stmt->execute([':id' => $archiveId]);
}

function normalizeDocumentDate($value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '' || $raw === '0000-00-00') {
        return null;
    }
    try {
        $dt = new DateTime($raw);
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function resolveStorageTier(?string $documentDate): string
{
    if (!$documentDate) {
        return 'hot';
    }

    $cutoff = new DateTime('first day of this month');
    $cutoff->modify('-24 months');

    $doc = new DateTime($documentDate);
    return ($doc < $cutoff) ? 'cold' : 'hot';
}

/**
 * @return array{0:int,1:int}
 */
function resolveYearMonth(?string $documentDate): array
{
    try {
        $dt = $documentDate ? new DateTime($documentDate) : new DateTime();
    } catch (Throwable $e) {
        $dt = new DateTime();
    }
    return [(int)$dt->format('Y'), (int)$dt->format('m')];
}

function ensureDirectory(string $absoluteDir): void
{
    if (is_dir($absoluteDir)) {
        return;
    }
    if (!mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu: ' . $absoluteDir);
    }
}

function ensureArchiveRootReady(): void
{
    $storageRoot = ROOT_PATH . '/storage';
    if (!is_dir($storageRoot)) {
        throw new RuntimeException('Brak katalogu storage: ' . $storageRoot);
    }

    $archiveRoot = $storageRoot . '/fakturownia-archive';
    ensureDirectory($archiveRoot);
}

function buildArchiveFileName(string $sourceType, int $sourceLocalId, int $fakturowniaId, int $year, int $month): string
{
    return $sourceType
        . '_' . $sourceLocalId
        . '_f' . $fakturowniaId
        . '_' . $year . sprintf('%02d', $month)
        . '_' . date('Ymd_His')
        . '.pdf';
}
