<?php
/**
 * BRYGAD ERP - Web trigger do uruchomienia pełnego importu Fakturownia
 *
 * Użycie (przeglądarka):
 *   https://public_html/cron/fakturownia_import_trigger.php?token=SECRET
 *   https://public_html/cron/fakturownia_import_trigger.php?token=SECRET&skip_archive=1
 *
 * Zabezpieczenie: token z ENV FAKTUROWNIA_SYNC_TRIGGER_SECRET (ten sam co cost sync trigger).
 *
 * Każdy krok uruchamiany jako osobny proces PHP (exec) — eliminuje kolizje nazw funkcji.
 */

header('Content-Type: text/plain; charset=utf-8');

// Ładowanie .env (fallback dla hostingów, które nie przekazują zmiennych ENV do PHP-FPM/Apache).
$envCandidates = [
    dirname(__DIR__) . '/.env',
    dirname(__DIR__, 2) . '/.env',
];

foreach ($envCandidates as $envFile) {
    if (!file_exists($envFile)) {
        continue;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = $val;
            putenv("{$key}={$val}");
        }
    }

    break;
}

$secret = $_ENV['FAKTUROWNIA_SYNC_TRIGGER_SECRET']
    ?? getenv('FAKTUROWNIA_SYNC_TRIGGER_SECRET')
    ?: '';

if ($secret === '') {
    http_response_code(403);
    echo "403 Forbidden – trigger secret not configured\n";
    exit;
}

$allowedIpsRaw = $_ENV['FAKTUROWNIA_SYNC_TRIGGER_IPS']
    ?? getenv('FAKTUROWNIA_SYNC_TRIGGER_IPS')
    ?: '';

if ($allowedIpsRaw !== '') {
    $allowedIps = array_map('trim', explode(',', $allowedIpsRaw));
    $clientIp   = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!in_array($clientIp, $allowedIps, true)) {
        http_response_code(403);
        echo "403 Forbidden – IP not allowed\n";
        exit;
    }
}

$token = $_GET['token'] ?? '';
if (!hash_equals($secret, $token)) {
    http_response_code(403);
    echo "403 Forbidden\n";
    exit;
}

set_time_limit(600);
ignore_user_abort(true);

$skipArchive = !empty($_GET['skip_archive']);

$steps = [
    'clients' => ['label' => 'Clients Sync', 'script' => 'fakturownia_clients_sync.php', 'args' => ''],
    'sales' => ['label' => 'Sales Backfill (all)', 'script' => 'fakturownia_sales_backfill_sync.php', 'args' => 'all'],
    'cost' => ['label' => 'Cost Inbox (all)', 'script' => 'fakturownia_cost_inbox_sync.php', 'args' => 'all'],
    'products' => ['label' => 'Products Sync', 'script' => 'fakturownia_products_sync.php', 'args' => ''],
    'archive' => ['label' => 'Archive Sync', 'script' => 'fakturownia_archive_sync.php', 'args' => '', 'skip_if_archive' => true],
    'health' => ['label' => 'Health Check (24h)', 'script' => 'fakturownia_health_check.php', 'args' => '24'],
];

$divider = str_repeat('=', 60);
$execAllowed = function_exists('exec') && stripos((string)ini_get('disable_functions'), 'exec') === false;
$requestedStep = trim((string)($_GET['step'] ?? ''));
$phpBin = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';

echo $divider . "\n";
echo "  BRYGAD ERP - Fakturownia Import (HTTP trigger)\n";
echo "  Start: " . date('Y-m-d H:i:s') . "\n";
echo "  Skip archive: " . ($skipArchive ? 'TAK' : 'NIE') . "\n";
echo "  Runner mode: " . ($execAllowed ? 'exec' : 'include-step') . "\n";
echo $divider . "\n\n";

if (!$execAllowed && $requestedStep === '') {
    echo "exec() jest niedostepne na tym hostingu.\n";
    echo "Uruchom kroki pojedynczo (dodaj parametr step):\n\n";
    echo "1) .../fakturownia_import_trigger.php?token=TWÓJ_TOKEN&step=clients\n";
    echo "2) .../fakturownia_import_trigger.php?token=TWÓJ_TOKEN&step=sales\n";
    echo "3) .../fakturownia_import_trigger.php?token=TWÓJ_TOKEN&step=cost\n";
    echo "4) .../fakturownia_import_trigger.php?token=TWÓJ_TOKEN&step=products\n";
    if (!$skipArchive) {
        echo "5) .../fakturownia_import_trigger.php?token=TWÓJ_TOKEN&step=archive\n";
    }
    echo "6) .../fakturownia_import_trigger.php?token=TWÓJ_TOKEN&step=health\n\n";
    echo "Opcjonalnie: dodaj &skip_archive=1 (wtedy pomiń krok archive).\n";
    exit;
}

if (!$execAllowed && !isset($steps[$requestedStep])) {
    http_response_code(400);
    echo "400 Bad Request — nieznany step. Dozwolone: clients,sales,cost,products,archive,health\n";
    exit;
}

$stepsToRun = $execAllowed
    ? $steps
    : [$requestedStep => $steps[$requestedStep]];

$results = [];
$totalSteps = count($stepsToRun);
$index = 0;

foreach ($stepsToRun as $stepKey => $step) {
    $index++;

    if (!empty($step['skip_if_archive']) && $skipArchive) {
        echo "[{$index}/{$totalSteps}] {$step['label']} — SKIPPED\n\n";
        $results[] = ['step' => $step['label'], 'status' => 'SKIPPED', 'exit' => -1];
        continue;
    }

    $scriptPath = __DIR__ . '/' . $step['script'];
    if (!file_exists($scriptPath)) {
        echo "[{$index}/{$totalSteps}] {$step['label']} — ERROR: brak pliku ({$step['script']})\n\n";
        $results[] = ['step' => $step['label'], 'status' => 'MISSING', 'exit' => 127];
        continue;
    }

    echo str_repeat('-', 60) . "\n";
    echo "[{$index}/{$totalSteps}] {$step['label']}\n";
    echo "Start: " . date('Y-m-d H:i:s') . "\n";
    echo str_repeat('-', 60) . "\n";

    $exitCode = 0;

    if ($execAllowed) {
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath);
        if ($step['args'] !== '') {
            $cmd .= ' ' . escapeshellarg($step['args']);
        }
        $cmd .= ' 2>&1';

        $output = [];
        exec($cmd, $output, $exitCode);
        foreach ($output as $line) {
            echo "  " . $line . "\n";
        }
    } else {
        $oldArgv = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = [basename($scriptPath)];
        if ($step['args'] !== '') {
            $GLOBALS['argv'][] = $step['args'];
        }

        ob_start();
        $runtimeError = null;
        try {
            include $scriptPath;
        } catch (Throwable $e) {
            $runtimeError = $e;
        }
        $rawOutput = (string)ob_get_clean();

        if ($oldArgv !== null) {
            $GLOBALS['argv'] = $oldArgv;
        } else {
            unset($GLOBALS['argv']);
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($rawOutput));
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            echo "  " . $line . "\n";
        }

        if ($runtimeError !== null) {
            $exitCode = 1;
            echo "  ERROR: " . $runtimeError->getMessage() . "\n";
        } elseif ($stepKey === 'health') {
            if (strpos($rawOutput, '[CRITICAL]') !== false) {
                $exitCode = 1;
            } elseif (strpos($rawOutput, '[WARNING]') !== false) {
                $exitCode = 2;
            }
        }
    }

    $statusLabel = 'OK';
    if ($exitCode === 1) {
        $statusLabel = 'CRITICAL';
    } elseif ($exitCode === 2) {
        $statusLabel = 'WARNING';
    } elseif ($exitCode !== 0) {
        $statusLabel = 'ERROR(' . $exitCode . ')';
    }

    echo "\nStatus: {$statusLabel} (exit {$exitCode}) | Done: " . date('Y-m-d H:i:s') . "\n\n";

    $results[] = ['step' => $step['label'], 'status' => $statusLabel, 'exit' => $exitCode];

    if ($exitCode === 1) {
        echo "*** CRITICAL — przerywam dalsze kroki ***\n\n";
        break;
    }

    flush();
}

echo $divider . "\n";
echo "  PODSUMOWANIE\n";
echo $divider . "\n";
foreach ($results as $r) {
    printf("  %-30s %-12s exit=%s\n", $r['step'], $r['status'], $r['exit'] >= 0 ? $r['exit'] : '-');
}
echo $divider . "\n";
echo "  Zakończono: " . date('Y-m-d H:i:s') . "\n";
