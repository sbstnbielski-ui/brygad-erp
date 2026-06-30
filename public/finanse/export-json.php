<?php
/**
 * BRYGAD ERP - Eksport Finansów do JSON
 * Wersja zgodna z aktualną strukturą bazy (view_finance_ledger + project_revenues).
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

/**
 * Walidacja formatu daty YYYY-MM-DD.
 */
function isValidDateYmd(string $date): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

/**
 * Normalizuje źródła z view_finance_ledger do 4 głównych kategorii.
 */
function normalizeLedgerSource(string $source): string
{
    if ($source === 'invoice_cost' || $source === 'invoice_cost_legacy') {
        return 'invoices';
    }
    if ($source === 'labor') {
        return 'labor';
    }
    if ($source === 'cash') {
        return 'expenses';
    }
    if ($source === 'fixed') {
        return 'fixed_costs';
    }

    return 'other';
}

/**
 * Buduje pusty breakdown kosztów.
 */
function emptyCostBreakdown(): array
{
    return [
        'invoices' => 0.0,
        'labor' => 0.0,
        'expenses' => 0.0,
        'fixed_costs' => 0.0,
        'other' => 0.0,
    ];
}

/**
 * Zwraca listę kolumn tabeli/widoku dla bieżącej bazy.
 */
function getTableColumns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([':table_name' => $tableName]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_map('strval', $rows ?: []);
}

/**
 * Wybiera pierwszą istniejącą kolumnę z listy kandydatów.
 */
function pickExistingColumn(array $availableColumns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $availableColumns, true)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Bezpieczne cytowanie identyfikatora SQL.
 */
function qi(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

// Parametry wejściowe
$allowedRanges = ['day', 'week', 'month', 'custom'];
$range = $_GET['range'] ?? 'month';
if (!in_array($range, $allowedRanges, true)) {
    $range = 'month';
}

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Ustal zakres dat
switch ($range) {
    case 'day':
        $dateFrom = date('Y-m-d');
        $dateTo = date('Y-m-d');
        break;

    case 'week':
        // 7 dni łącznie (dziś + 6 dni wstecz)
        $dateFrom = date('Y-m-d', strtotime('-6 days'));
        $dateTo = date('Y-m-d');
        break;

    case 'month':
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
        break;

    case 'custom':
        if (!isValidDateYmd((string)$dateFrom)) {
            $dateFrom = date('Y-m-01');
        }
        if (!isValidDateYmd((string)$dateTo)) {
            $dateTo = date('Y-m-d');
        }
        break;
}

// Dodatkowe zabezpieczenie: jeśli daty odwrotnie, zamień miejscami.
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$export = [
    'meta' => [
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => $_SESSION['login'] ?? null,
        'export_version' => '2026-02-27-v2',
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'range' => $range,
        'currency' => 'PLN',
        'source' => 'view_finance_ledger + project_revenues + hb_transactions',
    ],
    'totals' => [],
    'per_project' => [],
    'per_cost_node' => [],
];

try {
    // Sprawdź kolumny widoku na produkcji (mogą się różnić między środowiskami).
    $viewColumns = getTableColumns($pdo, 'view_finance_ledger');
    $amountCol = pickExistingColumn($viewColumns, ['amount', 'total_cost', 'cost', 'value', 'amount_net', 'amount_gross', 'final_cost', 'system_cost']);
    $dateCol = pickExistingColumn($viewColumns, ['date', 'issue_date', 'entry_date', 'created_at', 'period']);
    $sourceCol = pickExistingColumn($viewColumns, ['ledger_source', 'source', 'type', 'category']);
    $projectCol = pickExistingColumn($viewColumns, ['project_id', 'projekt_id']);
    $costNodeCol = pickExistingColumn($viewColumns, ['cost_node_id', 'etap_id', 'node_id']);

    $export['meta']['view_finance_ledger_columns'] = $viewColumns;
    $export['meta']['view_mapping'] = [
        'amount' => $amountCol,
        'date' => $dateCol,
        'source' => $sourceCol,
        'project' => $projectCol,
        'cost_node' => $costNodeCol,
    ];

    if ($amountCol === null || $dateCol === null) {
        throw new RuntimeException(
            'view_finance_ledger nie ma wymaganych kolumn kosztowych. ' .
            'Wymagane min.: amount/date (lub ich odpowiedniki).'
        );
    }

    // ===================================================================
    // 1) KOSZTY FIRMY - SUMY GLOBALNE I STRUKTURA
    // ===================================================================
    $sourceSelect = $sourceCol !== null ? qi($sourceCol) : "'other'";
    $sourceGroupBy = $sourceCol !== null ? 'GROUP BY ' . qi($sourceCol) : '';

    $stmt = $pdo->prepare("
        SELECT {$sourceSelect} AS ledger_source, COALESCE(SUM(" . qi($amountCol) . "), 0) AS total, COUNT(*) AS cnt
        FROM view_finance_ledger
        WHERE " . qi($dateCol) . " BETWEEN :from AND :to
        {$sourceGroupBy}
    ");
    $stmt->execute([
        ':from' => $dateFrom,
        ':to' => $dateTo,
    ]);
    $costRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $costBreakdown = emptyCostBreakdown();
    $costCounts = [
        'invoices' => 0,
        'labor' => 0,
        'expenses' => 0,
        'fixed_costs' => 0,
        'other' => 0,
    ];
    $rawBySource = [];
    $totalCost = 0.0;

    foreach ($costRows as $row) {
        $source = (string)$row['ledger_source'];
        $amount = (float)$row['total'];
        $count = (int)$row['cnt'];

        $normalized = normalizeLedgerSource($source);
        $costBreakdown[$normalized] += $amount;
        $costCounts[$normalized] += $count;
        $rawBySource[$source] = [
            'amount' => round($amount, 2),
            'count' => $count,
        ];
        $totalCost += $amount;
    }

    // ===================================================================
    // 2) PRZYCHODY FIRMY I WYNIK
    // ===================================================================
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_net), 0) AS total_revenue
        FROM project_revenues
        WHERE signed_date BETWEEN :from AND :to
    ");
    $stmt->execute([
        ':from' => $dateFrom,
        ':to' => $dateTo,
    ]);
    $companyIncome = (float)$stmt->fetchColumn();
    $companyNet = $companyIncome - $totalCost;

    // ===================================================================
    // 3) BUDŻET DOMOWY (dla spójności z modułem właściciela)
    // ===================================================================
    $householdId = 1;

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) AS home_income,
            COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) AS home_expenses
        FROM hb_transactions
        WHERE household_id = :household_id
          AND date BETWEEN :from AND :to
    ");
    $stmt->execute([
        ':household_id' => $householdId,
        ':from' => $dateFrom,
        ':to' => $dateTo,
    ]);
    $home = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['home_income' => 0, 'home_expenses' => 0];
    $homeIncome = (float)$home['home_income'];
    $homeExpenses = (float)$home['home_expenses'];
    $homeNet = $homeIncome - $homeExpenses;
    $ownerNet = $companyNet + $homeNet;

    $export['totals'] = [
        'total_cost' => round($totalCost, 2),
        'company' => [
            'income' => round($companyIncome, 2),
            'costs' => round($totalCost, 2),
            'net' => round($companyNet, 2),
        ],
        'home' => [
            'income' => round($homeIncome, 2),
            'expenses' => round($homeExpenses, 2),
            'net' => round($homeNet, 2),
        ],
        'owner' => [
            'net' => round($ownerNet, 2),
        ],
        'by_category' => [
            'invoices' => [
                'label' => 'Faktury kosztowe',
                'amount' => round($costBreakdown['invoices'], 2),
                'count' => $costCounts['invoices'],
            ],
            'fixed_costs' => [
                'label' => 'Koszty stałe',
                'amount' => round($costBreakdown['fixed_costs'], 2),
                'count' => $costCounts['fixed_costs'],
            ],
            'labor' => [
                'label' => 'Koszty pracownicze',
                'amount' => round($costBreakdown['labor'], 2),
                'count' => $costCounts['labor'],
            ],
            'expenses' => [
                'label' => 'Wydatki pracowników',
                'amount' => round($costBreakdown['expenses'], 2),
                'count' => $costCounts['expenses'],
            ],
            'other' => [
                'label' => 'Pozostałe',
                'amount' => round($costBreakdown['other'], 2),
                'count' => $costCounts['other'],
            ],
        ],
        'raw_by_ledger_source' => $rawBySource,
    ];

    // ===================================================================
    // 4) KOSZTY PER PROJEKT
    // ===================================================================
    $projectRows = [];
    if ($projectCol !== null) {
        $sourceProjectSelect = $sourceCol !== null ? 'v.' . qi($sourceCol) : "'other'";
        $sourceProjectGroup = $sourceCol !== null ? ', v.' . qi($sourceCol) : '';

        $stmt = $pdo->prepare("
            SELECT
                v." . qi($projectCol) . " AS project_id,
                p.name AS project_name,
                {$sourceProjectSelect} AS ledger_source,
                COALESCE(SUM(v." . qi($amountCol) . "), 0) AS total
            FROM view_finance_ledger v
            LEFT JOIN projects p ON p.id = v." . qi($projectCol) . "
            WHERE v." . qi($dateCol) . " BETWEEN :from AND :to
            GROUP BY v." . qi($projectCol) . ", p.name{$sourceProjectGroup}
        ");
        $stmt->execute([
            ':from' => $dateFrom,
            ':to' => $dateTo,
        ]);
        $projectRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $projectMap = [];
    foreach ($projectRows as $row) {
        $projectId = (int)($row['project_id'] ?? 0);
        $projectName = trim((string)($row['project_name'] ?? ''));
        $source = (string)$row['ledger_source'];
        $amount = (float)$row['total'];

        if (!isset($projectMap[$projectId])) {
            $projectMap[$projectId] = [
                'project_id' => $projectId,
                'project_name' => $projectName !== '' ? $projectName : 'Projekt #' . $projectId,
                'total_cost' => 0.0,
                'breakdown' => emptyCostBreakdown(),
            ];
        }

        $key = normalizeLedgerSource($source);
        $projectMap[$projectId]['breakdown'][$key] += $amount;
        $projectMap[$projectId]['total_cost'] += $amount;
    }

    foreach ($projectMap as $project) {
        if ($project['total_cost'] <= 0) {
            continue;
        }

        $project['total_cost'] = round($project['total_cost'], 2);
        foreach ($project['breakdown'] as $key => $value) {
            $project['breakdown'][$key] = round($value, 2);
        }

        $export['per_project'][] = $project;
    }

    usort($export['per_project'], static function ($a, $b) {
        return $b['total_cost'] <=> $a['total_cost'];
    });

    // ===================================================================
    // 5) KOSZTY PER ETAP (COST NODE)
    // ===================================================================
    $nodeRows = [];
    if ($costNodeCol !== null && $projectCol !== null) {
        $sourceNodeSelect = $sourceCol !== null ? 'v.' . qi($sourceCol) : "'other'";
        $sourceNodeGroup = $sourceCol !== null ? ', v.' . qi($sourceCol) : '';

        $stmt = $pdo->prepare("
            SELECT
                v." . qi($costNodeCol) . " AS cost_node_id,
                pcn.name AS cost_node_name,
                v." . qi($projectCol) . " AS project_id,
                p.name AS project_name,
                {$sourceNodeSelect} AS ledger_source,
                COALESCE(SUM(v." . qi($amountCol) . "), 0) AS total
            FROM view_finance_ledger v
            LEFT JOIN project_cost_nodes pcn ON pcn.id = v." . qi($costNodeCol) . "
            LEFT JOIN projects p ON p.id = v." . qi($projectCol) . "
            WHERE v." . qi($dateCol) . " BETWEEN :from AND :to
              AND v." . qi($costNodeCol) . " IS NOT NULL
            GROUP BY v." . qi($costNodeCol) . ", pcn.name, v." . qi($projectCol) . ", p.name{$sourceNodeGroup}
        ");
        $stmt->execute([
            ':from' => $dateFrom,
            ':to' => $dateTo,
        ]);
        $nodeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $nodeMap = [];
    foreach ($nodeRows as $row) {
        $nodeId = (int)$row['cost_node_id'];
        $projectId = (int)($row['project_id'] ?? 0);
        $source = (string)$row['ledger_source'];
        $amount = (float)$row['total'];
        $nodeKey = $projectId . ':' . $nodeId;

        if (!isset($nodeMap[$nodeKey])) {
            $nodeMap[$nodeKey] = [
                'cost_node_id' => $nodeId,
                'cost_node_name' => trim((string)($row['cost_node_name'] ?? '')) ?: ('Etap #' . $nodeId),
                'project_id' => $projectId,
                'project_name' => trim((string)($row['project_name'] ?? '')) ?: ('Projekt #' . $projectId),
                'total_cost' => 0.0,
                'breakdown' => emptyCostBreakdown(),
            ];
        }

        $key = normalizeLedgerSource($source);
        $nodeMap[$nodeKey]['breakdown'][$key] += $amount;
        $nodeMap[$nodeKey]['total_cost'] += $amount;
    }

    foreach ($nodeMap as $node) {
        if ($node['total_cost'] <= 0) {
            continue;
        }

        $node['total_cost'] = round($node['total_cost'], 2);
        foreach ($node['breakdown'] as $key => $value) {
            $node['breakdown'][$key] = round($value, 2);
        }

        $export['per_cost_node'][] = $node;
    }

    usort($export['per_cost_node'], static function ($a, $b) {
        return $b['total_cost'] <=> $a['total_cost'];
    });

} catch (Throwable $e) {
    $export['error'] = [
        'message' => 'Błąd podczas generowania eksportu JSON',
        'details' => $e->getMessage(),
    ];
    if (function_exists('logEvent')) {
        logEvent('EXPORT JSON ERROR: ' . $e->getMessage(), 'ERROR');
    } else {
        error_log('EXPORT JSON ERROR: ' . $e->getMessage());
    }
}

$filename = 'finanse_' . $range . '_' . date('Y-m-d_H-i') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
