<?php
/**
 * BRYGAD ERP - CRON: Synchronizacja statusów KSeF z Fakturownia.pl
 *
 * Odpytuje API Fakturowni o faktury z gov_status='pending',
 * aktualizuje gov_status, gov_id, status i synced_at w lokalnej bazie.
 * Limit 50 rekordów na przebieg (ochrona przed rate-limit).
 */
// Crontab: co 15 min
// */15 * * * * php /path/to/fakturownia_sync.php

require_once dirname(__DIR__) . '/config/autoload.php';
require_once dirname(__DIR__) . '/modules/fakturownia/FakturowniaClient.php';
require_once dirname(__DIR__) . '/modules/fakturownia/FakturowniaMapper.php';

$pdo = getDbConnection();
$maxPerRun = 50;

try {
    $client = new FakturowniaClient($pdo);
    $mapper = new FakturowniaMapper();

    // Pobierz faktury oczekujące na potwierdzenie KSeF (najstarsze najpierw)
    $stmt = $pdo->prepare("
        SELECT id, fakturownia_id, fakturownia_number
        FROM fakturownia_invoices
        WHERE gov_status = 'pending'
          AND fakturownia_id IS NOT NULL
        ORDER BY created_at ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $maxPerRun, PDO::PARAM_INT);
    $stmt->execute();
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pending)) {
        echo "[" . date('Y-m-d H:i:s') . "] Fakturownia Sync: brak faktur pending — pomijam.\n";
        exit(0);
    }

    $updated = 0;
    $errors = 0;

    foreach ($pending as $invoice) {
        $fakturowniaId = (int)$invoice['fakturownia_id'];
        $localId = (int)$invoice['id'];

        try {
            // GET /invoices/{id}.json — retry 429/503 obsługuje FakturowniaClient
            $response = $client->get('/invoices/' . $fakturowniaId . '.json');
            $mapped = $mapper->fakturowniaKsefStatusToErp($response['data'] ?? []);

            $updateStmt = $pdo->prepare("
                UPDATE fakturownia_invoices
                SET gov_status = :gov_status,
                    gov_id     = :gov_id,
                    status     = :status,
                    synced_at  = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':gov_status' => $mapped['gov_status'],
                ':gov_id'     => $mapped['gov_id'],
                ':status'     => $mapped['status'],
                ':id'         => $localId,
            ]);

            $updated++;

            // Jeśli KSeF odrzucił — zaloguj szczegóły błędu
            if ($mapped['gov_status'] === 'error' && !empty($mapped['gov_error_messages'])) {
                $errorDetail = implode('; ', $mapped['gov_error_messages']);
                logEvent("Fakturownia KSeF error FV #{$invoice['fakturownia_number']} (ID {$fakturowniaId}): {$errorDetail}", 'ERROR');
            }

        } catch (FakturowniaAuthException $e) {
            // 401 — token nieprawidłowy/wygasły → przerwij cały przebieg
            logEvent('FAKTUROWNIA CRITICAL: Błąd autoryzacji API w cron sync — sprawdź token. ' . $e->getMessage(), 'CRITICAL');
            echo "[" . date('Y-m-d H:i:s') . "] CRITICAL AUTH: " . $e->getMessage() . "\n";
            echo "Przerwano po {$updated} aktualizacjach, {$errors} błędach.\n";
            exit(1);

        } catch (Throwable $e) {
            $errors++;
            logEvent("Fakturownia Sync error (FV ID {$fakturowniaId}): " . $e->getMessage(), 'ERROR');
        }
    }

    $total = count($pending);
    echo "[" . date('Y-m-d H:i:s') . "] Fakturownia Sync: {$total} pending, {$updated} updated, {$errors} errors\n";
    logEvent("CRON Fakturownia Sync: {$total} pending, {$updated} updated, {$errors} errors", 'INFO');

} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    logEvent("CRON Fakturownia Sync ERROR: " . $e->getMessage(), 'ERROR');
    exit(1);
}
