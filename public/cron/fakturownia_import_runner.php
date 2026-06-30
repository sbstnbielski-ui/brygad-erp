<?php
/**
 * BRYGAD ERP - Sprint 5: Import historyczny — sekwencyjny runner
 *
 * Uruchamia pełen backfill wszystkich danych z Fakturowni w poprawnej kolejności:
 *   1. fakturownia_clients_sync.php
 *   2. fakturownia_sales_backfill_sync.php all
 *   3. fakturownia_cost_inbox_sync.php all
 *   4. fakturownia_products_sync.php
 *   5. fakturownia_archive_sync.php
 *   6. fakturownia_health_check.php 24
 *
 * Na końcu generuje raport SQL rekonsyliacyjny do stdout.
 *
 * Użycie (CLI only):
 *   php public/cron/fakturownia_import_runner.php
 *   php public/cron/fakturownia_import_runner.php --skip-archive
 *   php public/cron/fakturownia_import_runner.php --dry-run
 *
 * Exit codes:
 *   0 = wszystko OK
 *   1 = co najmniej jeden krok zakończony błędem krytycznym (401 lub crash)
 *   2 = ostrzeżenia (health_check WARNING)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "403 Forbidden — CLI only\n";
    exit(1);
}

$startTime = microtime(true);
$skipArchive = in_array('--skip-archive', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

$steps = [
    [
        'label'   => 'Clients Sync',
        'script'  => __DIR__ . '/fakturownia_clients_sync.php',
        'args'    => '',
    ],
    [
        'label'   => 'Sales Backfill (all)',
        'script'  => __DIR__ . '/fakturownia_sales_backfill_sync.php',
        'args'    => 'all',
    ],
    [
        'label'   => 'Cost Inbox Sync (all)',
        'script'  => __DIR__ . '/fakturownia_cost_inbox_sync.php',
        'args'    => 'all',
    ],
    [
        'label'   => 'Products Sync',
        'script'  => __DIR__ . '/fakturownia_products_sync.php',
        'args'    => '',
    ],
    [
        'label'   => 'Archive Sync (PDFs)',
        'script'  => __DIR__ . '/fakturownia_archive_sync.php',
        'args'    => '',
        'skip_if' => 'skipArchive',
    ],
    [
        'label'   => 'Health Check (24h window)',
        'script'  => __DIR__ . '/fakturownia_health_check.php',
        'args'    => '24',
    ],
];

$divider = str_repeat('=', 72);
$thinDivider = str_repeat('-', 72);

echo $divider . "\n";
echo "  BRYGAD ERP — Fakturownia Import Historyczny (Sprint 5)\n";
echo "  Start: " . date('Y-m-d H:i:s') . "\n";
echo "  Mode:  " . ($dryRun ? 'DRY-RUN (bez uruchamiania)' : 'LIVE') . "\n";
if ($skipArchive) {
    echo "  Flags: --skip-archive\n";
}
echo $divider . "\n\n";

$results = [];
$worstExit = 0;
$totalSteps = count($steps);

foreach ($steps as $i => $step) {
    $num = $i + 1;
    $label = $step['label'];

    if (isset($step['skip_if']) && $$step['skip_if']) {
        echo "[{$num}/{$totalSteps}] {$label} — SKIPPED (--skip-archive)\n\n";
        $results[] = ['step' => $label, 'exit_code' => -1, 'duration' => 0, 'status' => 'SKIPPED'];
        continue;
    }

    if (!file_exists($step['script'])) {
        echo "[{$num}/{$totalSteps}] {$label} — ERROR: plik nie istnieje: {$step['script']}\n\n";
        $results[] = ['step' => $label, 'exit_code' => 127, 'duration' => 0, 'status' => 'MISSING'];
        $worstExit = max($worstExit, 1);
        continue;
    }

    echo $thinDivider . "\n";
    echo "[{$num}/{$totalSteps}] {$label}\n";
    echo "     Script: {$step['script']}\n";
    echo "     Start:  " . date('Y-m-d H:i:s') . "\n";
    echo $thinDivider . "\n";

    if ($dryRun) {
        echo "  (dry-run — skipping execution)\n\n";
        $results[] = ['step' => $label, 'exit_code' => 0, 'duration' => 0, 'status' => 'DRY-RUN'];
        continue;
    }

    $stepStart = microtime(true);
    $phpBin = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($step['script']);
    if ($step['args'] !== '') {
        $cmd .= ' ' . escapeshellarg($step['args']);
    }
    $cmd .= ' 2>&1';

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $stepDuration = round(microtime(true) - $stepStart, 1);

    foreach ($output as $line) {
        echo "  " . $line . "\n";
    }

    $statusLabel = 'OK';
    if ($exitCode === 1) {
        $statusLabel = 'CRITICAL';
    } elseif ($exitCode === 2) {
        $statusLabel = 'WARNING';
    } elseif ($exitCode !== 0) {
        $statusLabel = 'ERROR(' . $exitCode . ')';
    }

    echo "\n  Exit: {$exitCode} ({$statusLabel}), Duration: {$stepDuration}s\n\n";

    $results[] = ['step' => $label, 'exit_code' => $exitCode, 'duration' => $stepDuration, 'status' => $statusLabel];
    $worstExit = max($worstExit, $exitCode);

    if ($exitCode === 1) {
        echo "  *** CRITICAL: krok zakończony z exit=1. Przerwanie dalszych kroków. ***\n\n";
        break;
    }
}

$totalDuration = round(microtime(true) - $startTime, 1);

echo $divider . "\n";
echo "  PODSUMOWANIE IMPORTU\n";
echo $divider . "\n";
printf("  %-35s %-12s %-10s %s\n", 'Krok', 'Status', 'Exit', 'Czas');
echo "  " . str_repeat('-', 68) . "\n";
foreach ($results as $r) {
    printf("  %-35s %-12s %-10s %s\n",
        $r['step'],
        $r['status'],
        $r['exit_code'] >= 0 ? $r['exit_code'] : '-',
        $r['duration'] > 0 ? $r['duration'] . 's' : '-'
    );
}
echo "  " . str_repeat('-', 68) . "\n";
echo "  Łącznie: {$totalDuration}s | Najgorszy exit: {$worstExit}\n";
echo "  Zakończono: " . date('Y-m-d H:i:s') . "\n";
echo $divider . "\n\n";

// --- Raport SQL rekonsyliacyjny ---
if (!$dryRun) {
    echo $divider . "\n";
    echo "  RAPORT SQL REKONSYLIACYJNY\n";
    echo $divider . "\n\n";

    try {
        require_once dirname(__DIR__) . '/config/autoload.php';
        $pdo = getDbConnection();

        $queries = [
            'Liczba faktur sprzedażowych w invoices_sale' =>
                "SELECT COUNT(*) AS cnt FROM invoices_sale",

            'Liczba z source_system=fakturownia' =>
                "SELECT COUNT(*) AS cnt FROM invoices_sale WHERE source_system = 'fakturownia'",

            'Mapowania w fakturownia_invoices (łącznie)' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_invoices",

            'Mapowania z fakturownia_id' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_invoices WHERE fakturownia_id IS NOT NULL",

            'Mapowania gov_status=pending' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_invoices WHERE gov_status = 'pending'",

            'Mapowania gov_status=error' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_invoices WHERE gov_status = 'error'",

            'Faktury kosztowe (fakturownia_cost_invoices)' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_cost_invoices",

            'Koszty workflow_status=new' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_cost_invoices WHERE workflow_status = 'new'",

            'Koszty workflow_status=accepted' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_cost_invoices WHERE workflow_status = 'accepted'",

            'Produkty (fakturownia_products)' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_products",

            'Produkty aktywne' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_products WHERE is_active = 1",

            'Archiwum (fakturownia_archive_files)' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_archive_files",

            'Archiwum — sale PDF' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_archive_files WHERE source_type = 'sale' AND file_kind = 'pdf'",

            'Archiwum — cost PDF' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_archive_files WHERE source_type = 'cost' AND file_kind = 'pdf'",

            'API log (łącznie)' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_api_log",

            'API log — 401 (ostatnie 24h)' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_api_log WHERE http_status = 401 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",

            'API log — 429/503 (ostatnie 24h)' =>
                "SELECT COUNT(*) AS cnt FROM fakturownia_api_log WHERE http_status IN (429, 503) AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        ];

        printf("  %-55s %s\n", 'Metryka', 'Wartość');
        echo "  " . str_repeat('-', 68) . "\n";

        foreach ($queries as $label => $sql) {
            try {
                $val = (int)$pdo->query($sql)->fetchColumn();
                printf("  %-55s %s\n", $label, number_format($val, 0, '', ' '));
            } catch (Throwable $e) {
                printf("  %-55s %s\n", $label, 'ERROR: ' . $e->getMessage());
            }
        }

        echo "\n";

        // Braki / anomalie
        echo "  BRAKI I ANOMALIE:\n";
        echo "  " . str_repeat('-', 68) . "\n";

        $gaps = [
            'Sprzedaż bez archiwum PDF' =>
                "SELECT COUNT(*) AS cnt
                 FROM fakturownia_invoices fi
                 WHERE fi.fakturownia_id IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM fakturownia_archive_files a
                       WHERE a.source_type = 'sale' AND a.source_local_id = fi.id AND a.file_kind = 'pdf'
                   )",

            'Koszty bez archiwum PDF' =>
                "SELECT COUNT(*) AS cnt
                 FROM fakturownia_cost_invoices ci
                 WHERE ci.fakturownia_id IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM fakturownia_archive_files a
                       WHERE a.source_type = 'cost' AND a.source_local_id = ci.id AND a.file_kind = 'pdf'
                   )",

            'Accepted/assigned koszty bez alokacji' =>
                "SELECT COUNT(*) AS cnt
                 FROM fakturownia_cost_invoices ci
                 LEFT JOIN (
                    SELECT cost_invoice_id, COALESCE(SUM(amount_gross), 0) AS ag
                    FROM fakturownia_cost_allocations GROUP BY cost_invoice_id
                 ) a ON a.cost_invoice_id = ci.id
                 WHERE ci.workflow_status IN ('accepted', 'assigned')
                   AND COALESCE(a.ag, 0) < 0.01",

            'Koszty new > 7 dni' =>
                "SELECT COUNT(*) AS cnt
                 FROM fakturownia_cost_invoices
                 WHERE workflow_status = 'new'
                   AND imported_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",

            'KSeF pending > 48h' =>
                "SELECT COUNT(*) AS cnt
                 FROM fakturownia_invoices
                 WHERE gov_status = 'pending'
                   AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)",

            'invoices_sale bez dopasowania do fakturownia_invoices' =>
                "SELECT COUNT(*) AS cnt
                 FROM invoices_sale s
                 WHERE s.source_system = 'fakturownia'
                   AND NOT EXISTS (
                       SELECT 1 FROM fakturownia_invoices fi
                       WHERE fi.request_hash = CONCAT('sales_import_', s.source_external_id)
                   )",

            'Duplikaty fakturownia_id w fakturownia_invoices' =>
                "SELECT COUNT(*) AS cnt
                 FROM (
                    SELECT fakturownia_id FROM fakturownia_invoices
                    WHERE fakturownia_id IS NOT NULL
                    GROUP BY fakturownia_id HAVING COUNT(*) > 1
                 ) dup",
        ];

        $hasGaps = false;
        foreach ($gaps as $label => $sql) {
            try {
                $val = (int)$pdo->query($sql)->fetchColumn();
                $flag = $val > 0 ? '!!!' : '   ';
                printf("  %s %-52s %s\n", $flag, $label, number_format($val, 0, '', ' '));
                if ($val > 0) {
                    $hasGaps = true;
                }
            } catch (Throwable $e) {
                printf("  !!! %-52s %s\n", $label, 'ERROR: ' . $e->getMessage());
                $hasGaps = true;
            }
        }

        if (!$hasGaps) {
            echo "  Brak anomalii — dane wyglądają spójnie.\n";
        }

        echo "\n" . $divider . "\n";

    } catch (Throwable $e) {
        echo "  ERROR: Nie udało się połączyć z bazą danych: " . $e->getMessage() . "\n";
        echo $divider . "\n";
        $worstExit = max($worstExit, 1);
    }
}

exit($worstExit);
