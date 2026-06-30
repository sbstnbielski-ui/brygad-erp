<?php
/**
 * BRYGAD ERP - Web trigger do ręcznego uruchomienia synca faktur kosztowych.
 *
 * Użycie (przeglądarka lub curl):
 *   https://public_html/cron/fakturownia_sync_trigger.php?token=SYNC_SECRET&period=last_30_days
 *
 * Zabezpieczenie:
 *   - Wymaga tokenu w query param (sekret z ENV, nigdy hardcoded).
 *   - Opcjonalna allowlista IP przez ENV FAKTUROWNIA_SYNC_TRIGGER_IPS (comma-separated).
 *   - Bez poprawnego tokenu lub brak sekretu w ENV = 403.
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

// --- Sekret z ENV (wymagany) ---
$secret = $_ENV['FAKTUROWNIA_SYNC_TRIGGER_SECRET']
    ?? getenv('FAKTUROWNIA_SYNC_TRIGGER_SECRET')
    ?: '';

if ($secret === '') {
    http_response_code(403);
    echo "403 Forbidden – trigger secret not configured\n";
    exit;
}

// --- Opcjonalna allowlista IP ---
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

// --- Weryfikacja tokenu ---
$token = $_GET['token'] ?? '';
if (!hash_equals($secret, $token)) {
    http_response_code(403);
    echo "403 Forbidden\n";
    exit;
}

$allowedPeriods = ['last_30_days', 'this_month', 'last_month', 'this_year', 'last_year', 'all'];
$period = isset($_GET['period']) && in_array($_GET['period'], $allowedPeriods, true)
    ? $_GET['period']
    : 'last_30_days';

// Symuluj $argv dla skryptu crona (normalnie ustawiany przez CLI)
$argv = ['fakturownia_cost_inbox_sync.php', $period];

echo "=== Fakturownia Cost Inbox Sync ===\n";
echo "Period: {$period}\n";
echo "Start:  " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 40) . "\n";

ob_start();

try {
    include __DIR__ . '/fakturownia_cost_inbox_sync.php';
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

$output = ob_get_clean();
echo $output;

echo str_repeat('-', 40) . "\n";
echo "Done:   " . date('Y-m-d H:i:s') . "\n";
