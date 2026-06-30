<?php
/**
 * BRYGAD ERP - Sprint 6: Monitoring wrapper dla integracji Fakturownia
 *
 * Cel:
 * - uruchomić health_check i zareagować na exit code,
 * - przy CRITICAL (exit 1): zalogować alarm + opcjonalnie wysłać notyfikację,
 * - przy WARNING (exit 2): zalogować ostrzeżenie,
 * - sprawdzić czy crony faktycznie działają (brak ruchu API = alarm).
 *
 * Konfiguracja ENV (opcjonalna):
 *   FAKTUROWNIA_MONITOR_EMAIL   — adres email na alarmy CRITICAL (pusty = bez maila)
 *   FAKTUROWNIA_MONITOR_WINDOW  — okno health_check w godzinach (domyślnie 6)
 *
 * Crontab (co godzinę o :07, po health_check o :05):
 *   7 * * * * /usr/bin/php /var/www/sprutex/public/cron/fakturownia_monitor.php >> /var/log/sprutex/fakturownia_monitor.log 2>&1
 *
 * Exit codes:
 *   0 = OK
 *   1 = CRITICAL detected
 *   2 = WARNING detected
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "403 Forbidden — CLI only\n";
    exit(1);
}

require_once dirname(__DIR__) . '/config/autoload.php';

$pdo = getDbConnection();
$now = date('Y-m-d H:i:s');

$windowHours = (int)($_ENV['FAKTUROWNIA_MONITOR_WINDOW'] ?? getenv('FAKTUROWNIA_MONITOR_WINDOW') ?: 6);
if ($windowHours < 1 || $windowHours > 168) {
    $windowHours = 6;
}

$alertEmail = trim((string)($_ENV['FAKTUROWNIA_MONITOR_EMAIL'] ?? getenv('FAKTUROWNIA_MONITOR_EMAIL') ?: ''));

echo "[{$now}] Fakturownia Monitor: start (window={$windowHours}h)\n";

$severity = 'OK';
$issues = [];

// --- 1. Uruchom health_check i sprawdź exit code ---
$phpBin = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
$healthScript = __DIR__ . '/fakturownia_health_check.php';

if (!file_exists($healthScript)) {
    echo "[{$now}] ERROR: brak pliku health_check: {$healthScript}\n";
    logEvent('MONITOR ERROR: brak pliku fakturownia_health_check.php', 'ERROR');
    exit(1);
}

$healthOutput = [];
$healthExit = 0;
exec(
    escapeshellarg($phpBin) . ' ' . escapeshellarg($healthScript) . ' ' . escapeshellarg((string)$windowHours) . ' 2>&1',
    $healthOutput,
    $healthExit
);

$healthText = implode("\n", $healthOutput);
echo $healthText . "\n";

if ($healthExit === 1) {
    $severity = 'CRITICAL';
    $issues[] = 'Health check zwrócił CRITICAL (exit 1).';
} elseif ($healthExit === 2) {
    $severity = 'WARNING';
    $issues[] = 'Health check zwrócił WARNING (exit 2).';
}

// --- 2. Sprawdź czy crony w ogóle działają ---
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS cnt
         FROM fakturownia_api_log
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL :window_h HOUR)"
    );
    $stmt->bindValue(':window_h', max($windowHours, 12), PDO::PARAM_INT);
    $stmt->execute();
    $apiActivity = (int)$stmt->fetchColumn();

    if ($apiActivity === 0) {
        if ($severity !== 'CRITICAL') {
            $severity = 'WARNING';
        }
        $issues[] = "Brak jakiejkolwiek aktywności API w ostatnich " . max($windowHours, 12) . "h — crony mogą nie działać.";
    }
} catch (Throwable $e) {
    $issues[] = 'Błąd sprawdzania aktywności API: ' . $e->getMessage();
    $severity = 'CRITICAL';
}

// --- 3. Sprawdź ostatni sync dat ---
try {
    $syncChecks = [
        'fakturownia_invoices' => "SELECT MAX(synced_at) FROM fakturownia_invoices",
        'fakturownia_cost_invoices' => "SELECT MAX(synced_at) FROM fakturownia_cost_invoices",
        'fakturownia_products' => "SELECT MAX(synced_at) FROM fakturownia_products",
    ];

    $staleThresholdHours = 48;

    foreach ($syncChecks as $table => $sql) {
        try {
            $lastSync = $pdo->query($sql)->fetchColumn();
            if ($lastSync === null || $lastSync === false) {
                if ($severity !== 'CRITICAL') {
                    $severity = 'WARNING';
                }
                $issues[] = "{$table}: nigdy nie zsynchronizowano.";
                continue;
            }

            $hoursAgo = (time() - strtotime($lastSync)) / 3600;
            if ($hoursAgo > $staleThresholdHours) {
                if ($severity !== 'CRITICAL') {
                    $severity = 'WARNING';
                }
                $issues[] = "{$table}: ostatni sync " . round($hoursAgo, 1) . "h temu (> {$staleThresholdHours}h).";
            }
        } catch (Throwable $e) {
            // Tabela może nie istnieć
        }
    }
} catch (Throwable $e) {
    // Non-critical
}

// --- 4. Sprawdź 401 w ostatnich 2h (autoryzacja) ---
try {
    $auth401 = (int)$pdo->query(
        "SELECT COUNT(*) FROM fakturownia_api_log
         WHERE http_status = 401
           AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)"
    )->fetchColumn();

    if ($auth401 > 0) {
        $severity = 'CRITICAL';
        $issues[] = "Wykryto {$auth401} błędów 401 w ostatnich 2h — SPRAWDŹ TOKEN API!";
    }
} catch (Throwable $e) {
    // Non-critical
}

// --- Raport ---
$endTime = date('Y-m-d H:i:s');

echo "\n[{$endTime}] Monitor result: {$severity}\n";

if (!empty($issues)) {
    foreach ($issues as $issue) {
        echo "[{$endTime}] ISSUE: {$issue}\n";
    }
}

$logSeverity = 'INFO';
if ($severity === 'CRITICAL') {
    $logSeverity = 'CRITICAL';
} elseif ($severity === 'WARNING') {
    $logSeverity = 'WARNING';
}

logEvent("MONITOR Fakturownia [{$severity}]: " . (empty($issues) ? 'Wszystko OK.' : implode(' | ', $issues)), $logSeverity);

// --- Opcjonalny email na CRITICAL ---
if ($severity === 'CRITICAL' && $alertEmail !== '') {
    $subject = '[BRYGAD ERP] CRITICAL: Fakturownia integration alarm';
    $body = "Fakturownia Monitor — CRITICAL\n"
        . "Czas: {$endTime}\n"
        . "Serwer: " . gethostname() . "\n\n"
        . "Problemy:\n";

    foreach ($issues as $issue) {
        $body .= "  - {$issue}\n";
    }

    $body .= "\nHealth check output:\n" . $healthText . "\n";

    $mailSent = @mail($alertEmail, $subject, $body, "From: sprutex-monitor@" . gethostname());
    if ($mailSent) {
        echo "[{$endTime}] Alert email wysłany do: {$alertEmail}\n";
    } else {
        echo "[{$endTime}] WARNING: Nie udało się wysłać emaila do: {$alertEmail}\n";
    }
}

$exitCode = 0;
if ($severity === 'CRITICAL') {
    $exitCode = 1;
} elseif ($severity === 'WARNING') {
    $exitCode = 2;
}

exit($exitCode);
