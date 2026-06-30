<?php
/**
 * BRYGAD ERP - CRON: Health check integracji Fakturownia
 *
 * Cel:
 * - szybkie wykrycie problemów operacyjnych (401/429/503, wzrost błędów),
 * - sygnalizacja zaległości w kolejkach pending/new,
 * - jasny kod wyjścia dla monitoringu (0=OK/WARN, 1=CRITICAL).
 *
 * Użycie:
 *   php public/cron/fakturownia_health_check.php [window_hours]
 *
 * Przykład crontab (co godzinę):
 *   5 * * * * /usr/bin/php /var/www/sprutex/public/cron/fakturownia_health_check.php 6 >> /var/log/sprutex/cron_fakturownia_health.log 2>&1
 */

require_once dirname(__DIR__) . '/config/autoload.php';

$pdo = getDbConnection();

$windowHours = isset($argv[1]) ? (int)$argv[1] : 6;
if ($windowHours < 1 || $windowHours > 168) {
    $windowHours = 6;
}

$thresholds = [
    'auth_critical' => 1,      // pojedynczy 401 = krytyczny
    'retry_warn' => 5,         // 429/503
    'retry_critical' => 25,
    'errors_warn' => 10,       // suma non-2xx
    'errors_critical' => 40,
    'error_rate_warn' => 0.20, // 20%
    'error_rate_critical' => 0.45,
    'pending_ksef_warn' => 5,
    'pending_ksef_critical' => 20,
    'pending_ksef_old_warn' => 1,
    'pending_ksef_old_critical' => 5,
    'cost_new_old_warn' => 10,
    'cost_new_old_critical' => 40,
];

try {
    $apiStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN http_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS s2xx,
            SUM(CASE WHEN http_status = 401 THEN 1 ELSE 0 END) AS s401,
            SUM(CASE WHEN http_status = 429 THEN 1 ELSE 0 END) AS s429,
            SUM(CASE WHEN http_status = 503 THEN 1 ELSE 0 END) AS s503,
            SUM(CASE WHEN http_status IS NULL OR http_status >= 400 THEN 1 ELSE 0 END) AS errors,
            SUM(CASE WHEN retry_count > 0 THEN 1 ELSE 0 END) AS retried
         FROM fakturownia_api_log
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL :window_h HOUR)"
    );
    $apiStmt->bindValue(':window_h', $windowHours, PDO::PARAM_INT);
    $apiStmt->execute();
    $api = $apiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $ksefStmt = $pdo->query(
        "SELECT
            SUM(CASE WHEN gov_status = 'pending' THEN 1 ELSE 0 END) AS pending_total,
            SUM(CASE WHEN gov_status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 ELSE 0 END) AS pending_old_48h,
            SUM(CASE WHEN gov_status = 'error' THEN 1 ELSE 0 END) AS gov_error
         FROM fakturownia_invoices"
    );
    $ksef = $ksefStmt ? ($ksefStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    $costStmt = $pdo->query(
        "SELECT
            SUM(CASE WHEN workflow_status = 'new' THEN 1 ELSE 0 END) AS wf_new,
            SUM(CASE WHEN workflow_status = 'new' AND imported_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS wf_new_old_7d,
            SUM(CASE WHEN workflow_status = 'accepted' THEN 1 ELSE 0 END) AS wf_accepted
         FROM fakturownia_cost_invoices"
    );
    $cost = $costStmt ? ($costStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    $topErrStmt = $pdo->prepare(
        "SELECT endpoint, COUNT(*) AS errors
         FROM fakturownia_api_log
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL :window_h HOUR)
           AND (http_status IS NULL OR http_status >= 400)
         GROUP BY endpoint
         ORDER BY errors DESC
         LIMIT 3"
    );
    $topErrStmt->bindValue(':window_h', $windowHours, PDO::PARAM_INT);
    $topErrStmt->execute();
    $topErrors = $topErrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $total = (int)($api['total'] ?? 0);
    $errors = (int)($api['errors'] ?? 0);
    $s401 = (int)($api['s401'] ?? 0);
    $s429 = (int)($api['s429'] ?? 0);
    $s503 = (int)($api['s503'] ?? 0);
    $retryable = $s429 + $s503;
    $errorRate = $total > 0 ? $errors / $total : 0.0;

    $pendingKsef = (int)($ksef['pending_total'] ?? 0);
    $pendingKsefOld = (int)($ksef['pending_old_48h'] ?? 0);
    $ksefError = (int)($ksef['gov_error'] ?? 0);

    $costNewOld = (int)($cost['wf_new_old_7d'] ?? 0);

    $severity = 'INFO';
    $issues = [];

    if ($s401 >= $thresholds['auth_critical']) {
        $severity = 'CRITICAL';
        $issues[] = 'Wykryto HTTP 401 w logach API (token/autoryzacja).';
    }

    if ($retryable >= $thresholds['retry_critical']) {
        $severity = 'CRITICAL';
        $issues[] = 'Wysoki poziom 429/503: ' . $retryable . ' w oknie ' . $windowHours . 'h.';
    } elseif ($retryable >= $thresholds['retry_warn'] && $severity !== 'CRITICAL') {
        $severity = 'WARNING';
        $issues[] = 'Podwyższony poziom 429/503: ' . $retryable . ' w oknie ' . $windowHours . 'h.';
    }

    if ($errors >= $thresholds['errors_critical'] || $errorRate >= $thresholds['error_rate_critical']) {
        $severity = 'CRITICAL';
        $issues[] = 'Wysoki poziom błędów API: ' . $errors . '/' . $total . ' (' . round($errorRate * 100, 1) . '%).';
    } elseif (($errors >= $thresholds['errors_warn'] || $errorRate >= $thresholds['error_rate_warn']) && $severity !== 'CRITICAL') {
        $severity = 'WARNING';
        $issues[] = 'Podwyższony poziom błędów API: ' . $errors . '/' . $total . ' (' . round($errorRate * 100, 1) . '%).';
    }

    if ($pendingKsef >= $thresholds['pending_ksef_critical'] || $pendingKsefOld >= $thresholds['pending_ksef_old_critical']) {
        $severity = 'CRITICAL';
        $issues[] = 'Kolejka KSeF pending za duża: pending=' . $pendingKsef . ', pending>48h=' . $pendingKsefOld . '.';
    } elseif (($pendingKsef >= $thresholds['pending_ksef_warn'] || $pendingKsefOld >= $thresholds['pending_ksef_old_warn']) && $severity !== 'CRITICAL') {
        $severity = 'WARNING';
        $issues[] = 'Kolejka KSeF pending wymaga uwagi: pending=' . $pendingKsef . ', pending>48h=' . $pendingKsefOld . '.';
    }

    if ($ksefError > 0 && $severity !== 'CRITICAL') {
        $severity = 'WARNING';
        $issues[] = 'Są rekordy KSeF w statusie error: ' . $ksefError . '.';
    }

    if ($costNewOld >= $thresholds['cost_new_old_critical']) {
        $severity = 'CRITICAL';
        $issues[] = 'Duża zaległość kosztów w statusie new >7 dni: ' . $costNewOld . '.';
    } elseif ($costNewOld >= $thresholds['cost_new_old_warn'] && $severity !== 'CRITICAL') {
        $severity = 'WARNING';
        $issues[] = 'Zaległe koszty new >7 dni: ' . $costNewOld . '.';
    }

    if ($total === 0 && $severity === 'INFO') {
        $severity = 'WARNING';
        $issues[] = 'Brak ruchu API w oknie ' . $windowHours . 'h (sprawdź harmonogram cronów).';
    }

    $summary = 'Fakturownia Health [' . $severity . '] '
        . 'window=' . $windowHours . 'h, api_total=' . $total
        . ', api_errors=' . $errors
        . ', 401=' . $s401
        . ', 429=' . $s429
        . ', 503=' . $s503
        . ', pending_ksef=' . $pendingKsef
        . ', pending_ksef_old=' . $pendingKsefOld
        . ', ksef_error=' . $ksefError
        . ', cost_new_old_7d=' . $costNewOld;

    echo '[' . date('Y-m-d H:i:s') . '] ' . $summary . "\n";

    if (!empty($topErrors)) {
        $chunks = [];
        foreach ($topErrors as $row) {
            $chunks[] = (string)$row['endpoint'] . '=' . (int)$row['errors'];
        }
        $topLine = 'Top błędne endpointy: ' . implode(', ', $chunks);
        echo '[' . date('Y-m-d H:i:s') . '] ' . $topLine . "\n";
        logEvent('CRON ' . $topLine, $severity === 'CRITICAL' ? 'ERROR' : 'INFO');
    }

    if (!empty($issues)) {
        foreach ($issues as $issue) {
            echo '[' . date('Y-m-d H:i:s') . '] ISSUE: ' . $issue . "\n";
            logEvent('CRON Fakturownia Health: ' . $issue, $severity === 'CRITICAL' ? 'CRITICAL' : 'WARNING');
        }
    }

    logEvent('CRON ' . $summary, $severity === 'CRITICAL' ? 'CRITICAL' : ($severity === 'WARNING' ? 'WARNING' : 'INFO'));

    if (php_sapi_name() === 'cli') {
        if ($severity === 'CRITICAL') {
            exit(1);
        }
        if ($severity === 'WARNING') {
            exit(2);
        }
        exit(0);
    }
} catch (Throwable $e) {
    $msg = 'CRON Fakturownia Health ERROR: ' . $e->getMessage();
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $msg . "\n";
    logEvent($msg, 'ERROR');
    if (php_sapi_name() === 'cli') {
        exit(1);
    }
}
