<?php
/**
 * BRYGAD ERP - Konfiguracja integracji Fakturownia.pl
 *
 * Ładuje dane z pliku .env (root projektu) lub ze zmiennych środowiskowych.
 * Na LH shared hosting plik .env jest jedynym bezpiecznym sposobem.
 *
 * Wymagane klucze w .env:
 *   FAKTUROWNIA_API_TOKEN   – token API z panelu Fakturowni (Ustawienia > API)
 *   FAKTUROWNIA_SUBDOMAIN   – subdomena konta (np. "sprutex")
 */

// Prosty loader .env — bez zewnętrznych bibliotek.
// Wspiera oba układy:
// 1) .env w katalogu public/
// 2) .env poziom wyżej (starszy układ lokalny)
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
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
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

    // Pierwszy znaleziony .env wystarczy.
    break;
}

return [
    'api_token'  => $_ENV['FAKTUROWNIA_API_TOKEN'] ?? '',
    'subdomain'  => $_ENV['FAKTUROWNIA_SUBDOMAIN'] ?? '',
    'base_url'   => 'https://{subdomain}.fakturownia.pl',
    'rate_limit' => (int)($_ENV['FAKTUROWNIA_RATE_LIMIT'] ?? 5000),
    'timeout'    => 30,
    'retry_max'  => 3,
];
