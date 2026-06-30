<?php
/**
 * BRYGAD ERP - Portfel firmowy pracownika
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$isAdmin = isAdmin();

$monthNames = ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
$polishDays = ['Nd', 'Pn', 'Wt', 'Sr', 'Cz', 'Pt', 'So'];
$todayDate = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-7 days'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$yearStart = date('Y-01-01');

function walletEntryLabel(array $entry): string
{
    return walletResolveEntryLabel($entry);
}

function walletEntryUiType(array $entry): string
{
    return walletResolveEntryFilterType($entry);
}

function walletEntryBadgeClass(array $entry): string
{
    return match (walletEntryUiType($entry)) {
        'advance', 'transfer_in' => 'eb-advance',
        'expense' => 'eb-expense',
        'transfer_out' => 'eb-return',
        default => 'eb-other',
    };
}

function walletVisualAmount(array $entry): float
{
    return -1 * (float)($entry['amount'] ?? 0);
}

function walletFormatSignedMoney(float $amount): string
{
    return ($amount >= 0 ? '+' : '') . formatMoney($amount);
}

function walletFilterOptionLabel(string $type): string
{
    return [
        'advance' => 'Zasilenie',
        'expense' => 'Wydatek',
        'transfer_in' => 'Transfer przychodzący',
        'transfer_out' => 'Transfer wychodzący',
    ][$type] ?? $type;
}

function walletSafeReturnUrl(int $workerId, array $extra = []): string
{
    $params = array_merge(['worker_id' => $workerId], $extra);
    return url('hr.workers.wallet') . '?' . http_build_query($params);
}

function walletRowColor(int $workerId): array
{
    if (function_exists('getWorkerColor')) {
        return getWorkerColor($workerId);
    }

    return [
        'hslLight' => '#eef2ff',
        'hslBorder' => '#667eea',
    ];
}

function walletSourceLabel(?string $sourceKind): string
{
    return [
        'cash' => 'Gotówka',
        'bank' => 'Przelew',
        'other' => 'Inne',
    ][$sourceKind ?? ''] ?? '';
}

$workersList = [];
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY first_name, last_name");
    $workersList = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("WALLET: blad pobierania listy pracownikow: " . $e->getMessage(), 'ERROR');
}

$workerId = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
$worker = null;

$walletSummary = [
    'total_topups' => 0.0,
    'available' => 0.0,
    'spent' => 0.0,
    'open_count' => 0,
];
$walletEntries = [];
$walletEntriesByDate = [];
$walletTotalRows = 0;
$walletWorkerNames = [];

$filterType = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$validFilterTypes = ['', 'advance', 'expense', 'transfer_in', 'transfer_out'];
if (!in_array($filterType, $validFilterTypes, true)) {
    $filterType = '';
}

$filterFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? trim((string)$_GET['date_from']) : '';
$filterTo = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? trim((string)$_GET['date_to']) : '';
if ($filterFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = '';
}
if ($filterTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = '';
}

$walletMonthRaw = isset($_GET['month']) ? (int)$_GET['month'] : 0;
if ($walletMonthRaw < 0 || $walletMonthRaw > 12) {
    $walletMonthRaw = 0;
}
$walletYearRaw = isset($_GET['year']) ? (int)$_GET['year'] : 0;
if ($walletYearRaw < 2020 || $walletYearRaw > 2035) {
    $walletYearRaw = 0;
}
$walletYearOptions = range((int)date('Y') - 3, (int)date('Y') + 1);
if ($walletYearRaw > 0 && !in_array($walletYearRaw, $walletYearOptions, true)) {
    $walletYearOptions[] = $walletYearRaw;
    sort($walletYearOptions);
}
if ($walletMonthRaw >= 1 && !isset($_GET['date_from'])) {
    $filterFrom = sprintf('%04d-%02d-01', $walletYearRaw > 0 ? $walletYearRaw : (int)date('Y'), $walletMonthRaw);
    $filterTo = date('Y-m-t', strtotime($filterFrom));
}

$walletActiveYear = $walletYearRaw > 0 ? $walletYearRaw : (int)date('Y');
$walletActiveMonth = 0;
if ($filterFrom !== '' && $filterTo !== '') {
    for ($m = 1; $m <= 12; $m++) {
        $mStart = sprintf('%04d-%02d-01', $walletActiveYear, $m);
        $mEnd = date('Y-m-t', strtotime($mStart));
        if ($filterFrom === $mStart && $filterTo === $mEnd) {
            $walletActiveMonth = $m;
            break;
        }
    }
}

$returnParams = ['worker_id' => $workerId];
if ($filterType !== '') {
    $returnParams['type'] = $filterType;
}
if ($filterFrom !== '') {
    $returnParams['date_from'] = $filterFrom;
}
if ($filterTo !== '') {
    $returnParams['date_to'] = $filterTo;
}
$walletReturnUrl = walletSafeReturnUrl($workerId, array_diff_key($returnParams, ['worker_id' => true]));
$walletExpenseCreateUrl = url('finanse.wydatki.create', [
    'worker_id' => $workerId,
    'return_url' => $walletReturnUrl,
]);
$quickBaseParams = ['worker_id' => $workerId];
if ($filterType !== '') {
    $quickBaseParams['type'] = $filterType;
}
$quickBaseQs = http_build_query($quickBaseParams);

if ($workerId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM workers WHERE id = ?");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
    } catch (PDOException $e) {
        logEvent("WALLET: blad pobierania pracownika ID $workerId: " . $e->getMessage(), 'ERROR');
    }

    if ($worker) {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    wa.id AS advance_id,
                    wa.amount,
                    wa.status,
                    COALESCE(SUM(CASE WHEN wl.amount > 0 THEN wl.amount ELSE 0 END), 0) AS amount_settled
                FROM worker_advances wa
                LEFT JOIN worker_ledger wl ON wl.advance_id = wa.id
                WHERE wa.worker_id = ?
                  AND wa.type = 'COMPANY'
                GROUP BY wa.id, wa.amount, wa.status
            ");
            $stmt->execute([$workerId]);
            foreach ($stmt->fetchAll() as $row) {
                $amount = (float)$row['amount'];
                $settled = (float)$row['amount_settled'];
                $walletSummary['total_topups'] += $amount;
                $walletSummary['spent'] += $settled;
                if ((string)$row['status'] === 'open') {
                    $walletSummary['available'] += max(0, $amount - $settled);
                    $walletSummary['open_count']++;
                }
            }

            $where = [
                'wl.worker_id = ?',
                "wa.type = 'COMPANY'",
            ];
            $params = [$workerId];
            if ($filterFrom !== '') {
                $where[] = 'wl.entry_date >= ?';
                $params[] = $filterFrom;
            }
            if ($filterTo !== '') {
                $where[] = 'wl.entry_date <= ?';
                $params[] = $filterTo;
            }

            $stmt = $pdo->prepare("
                SELECT
                    wl.id,
                    wl.entry_type,
                    wl.amount,
                    wl.entry_date,
                    wl.description,
                    wl.advance_id,
                    wl.expense_id,
                    wl.document_id,
                    wl.settlement_id,
                    wa.status AS advance_status,
                    wwf.source_kind,
                    wwf.source_ref,
                    we.description AS expense_description
                FROM worker_ledger wl
                INNER JOIN worker_advances wa ON wa.id = wl.advance_id
                LEFT JOIN worker_wallet_funding wwf
                    ON wwf.advance_id = wa.id
                   AND wwf.direction = 'OUT_TOPUP'
                LEFT JOIN worker_expenses we ON we.id = wl.expense_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY wl.entry_date DESC, wl.id DESC
            ");
            $stmt->execute($params);
            $rawEntries = $stmt->fetchAll();

            $workerNameIds = walletExtractWorkerIdsFromDescriptions(array_merge(
                array_map(static fn(array $entry): string => (string)($entry['description'] ?? ''), $rawEntries),
                array_map(static fn(array $advance): string => (string)($advance['description'] ?? ''), $walletEntries)
            ));
            $walletWorkerNames = walletGetWorkerDisplayNames($pdo, $workerNameIds);

            foreach ($rawEntries as $entry) {
                if ($filterType !== '' && walletEntryUiType($entry) !== $filterType) {
                    continue;
                }
                $walletEntries[] = $entry;
                $date = (string)$entry['entry_date'];
                if (!isset($walletEntriesByDate[$date])) {
                    $walletEntriesByDate[$date] = [
                        'rows' => [],
                        'count' => 0,
                        'total_amount' => 0.0,
                    ];
                }
                $walletEntriesByDate[$date]['rows'][] = $entry;
                $walletEntriesByDate[$date]['count']++;
                $walletEntriesByDate[$date]['total_amount'] += walletVisualAmount($entry);
            }
            $walletTotalRows = count($walletEntries);
        } catch (PDOException $e) {
            logEvent("WALLET: blad pobierania portfela pracownika ID $workerId: " . $e->getMessage(), 'ERROR');
        }
    }
}

$workerName = $worker ? $worker['first_name'] . ' ' . $worker['last_name'] : '';
$pageTitle = $worker ? 'Portfel: ' . $workerName : 'Portfel firmowy';
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo e($pageTitle); ?></title>
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --bg-card: #ffffff;
            --border: #e5e7eb;
            --border-light: #f3f4f6;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            line-height: 1.5;
            padding-bottom: 40px;
        }
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }

        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }

        .hero-wallet {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }
        .hero-wallet h1 { margin: 0 0 6px; font-size: 28px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-wallet .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-wallet .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-wallet .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero-wallet p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-right { display: flex; flex-direction: column; gap: 10px; align-items: flex-end; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; transform: translateY(-1px); }
        .btn-hero-light { background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.22); color: #e2e8f0; padding: 8px 14px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-light:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .filter-card, .wallet-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }
        .filter-card-header {
            padding: 12px 20px;
            border-bottom: 1px solid #d1d5db;
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .worker-picker-bar {
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .worker-picker-bar label,
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            white-space: nowrap;
        }
        .worker-picker-bar select {
            flex: 1;
            min-width: 200px;
            padding: 0 12px;
            height: 38px;
            border: 1px solid #dbe3ef;
            border-radius: 6px;
            font-size: 13px;
            background: #f8fafc;
            color: var(--text-main);
            font-family: inherit;
        }
        .worker-picker-bar select:focus,
        .spx-filter-group select:focus,
        .spx-filter-group input:focus {
            outline: none;
            background: #fff;
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.12);
        }
        .btn-filter-apply,
        .spx-filter-bar .btn-primary {
            background: #2563eb;
            color: #fff;
            border: 1px solid #2563eb;
            padding: 0 18px;
            height: 38px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-filter-apply:hover,
        .spx-filter-bar .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .spx-filter-bar .btn-secondary {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #dbe3ef;
            padding: 0 14px;
            height: 38px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border: 2px solid transparent;
            border-radius: 12px;
            padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .stat-card:hover { border-color: #667eea; }
        .stat-card .sc-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-card .sc-value { font-size: 26px; font-weight: 700; color: #667eea; font-variant-numeric: tabular-nums; }
        .stat-card .sc-sub { font-size: 11px; color: #9ca3af; margin-top: 4px; }
        .stat-card.kpi-available .sc-value { color: #1d4ed8; }
        .stat-card.kpi-spent .sc-value { color: #dc2626; }

        .spx-filter-bar {
            padding: 14px 16px;
            background: white;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: nowrap;
        }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .spx-filter-group.fg-type { flex: 1.4 1 0; }
        .spx-filter-group.fg-month { flex: 1 1 0; }
        .spx-filter-group.fg-year { flex: 0.8 1 0; }
        .spx-filter-group.fg-date { flex: 1 1 0; }
        .spx-filter-group select,
        .spx-filter-group input[type="date"] {
            padding: 0 10px;
            height: 38px;
            border: 1px solid #dbe3ef;
            border-radius: 6px;
            font-size: 13px;
            background: #f8fafc;
            color: var(--text-main);
            font-family: inherit;
            transition: all 0.15s;
            width: 100%;
        }
        .quick-bar {
            padding: 8px 20px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .quick-left,
        .quick-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn {
            padding: 0 12px;
            height: 28px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
            color: #374151;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }
        .spx-quick-btn:hover { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
        .spx-quick-btn.active { background: #667eea; border-color: #667eea; color: white; }
        .filter-count { color: var(--text-muted); font-size: 12px; padding: 0 4px; white-space: nowrap; }
        .small-toggle {
            background: transparent;
            border: none;
            color: #6b7280;
            padding: 4px 10px;
            height: 24px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .toggle-group { display: flex; gap: 2px; background: #e5e7eb; padding: 2px; border-radius: 6px; }
        .btn-color-mode {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid #dbe3ef;
            background: #ffffff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
            flex-shrink: 0;
        }
        .btn-color-mode:hover { background: #eff6ff; border-color: #93c5fd; }
        .btn-color-mode.active { background: linear-gradient(135deg, #fce7f3, #e0e7ff); border-color: #a78bfa; }

        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { background: #f1f3f5; }
        th {
            padding: 10px 14px !important;
            text-align: left;
            font-weight: 600;
            color: #4b5563 !important;
            border: 1px solid #2d3748 !important;
            font-size: 11px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px;
            background: #f1f3f5 !important;
        }
        td {
            padding: 10px 14px !important;
            border: 1px solid #2d3748 !important;
            font-size: 13px !important;
            vertical-align: middle;
            transition: background 0.2s;
        }
        tbody tr { transition: background 0.15s ease; }
        tbody tr:hover { background: #f8fafc; }
        body:not(.no-colors) tbody tr { background: var(--worker-bg, transparent); border-left: 4px solid var(--worker-color, #667eea); }
        body:not(.no-colors) tbody tr:hover { filter: brightness(0.97); }
        body.no-colors tbody tr:nth-child(odd) { background: #ffffff; border-left: 4px solid transparent; }
        body.no-colors tbody tr:nth-child(even) { background: #f8fafc; border-left: 4px solid transparent; }
        body.no-colors tbody tr:hover { background: #e0f2fe !important; }
        .col-date { width: 115px; }
        .col-type { width: 190px; }
        .col-amount { width: 130px; text-align: right; }
        .col-source { width: 170px; }
        .col-actions { width: 145px; text-align: center; }
        .amount-cell { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .bal-pos { color: #059669; }
        .bal-neg { color: #dc2626; }
        .description-muted { color: var(--text-muted); font-size: 13px; line-height: 1.4; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .source-meta { color: #64748b; font-size: 12px; line-height: 1.35; }
        .entry-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
        .eb-advance { background: #eff6ff; color: #1d4ed8; }
        .eb-expense { background: #fff7ed; color: #c2410c; }
        .eb-return { background: #fef9c3; color: #92400e; }
        .eb-deduction { background: #f5f3ff; color: #6d28d9; }
        .eb-other { background: #f3f4f6; color: #374151; }
        .action-row { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; }
        .action-btn {
            min-height: 26px;
            padding: 4px 10px;
            border-radius: 4px;
            border: 1px solid;
            font-size: 11px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            transition: all 0.2s;
            font-family: inherit;
            cursor: pointer;
        }
        .action-edit { color: #059669; border-color: #059669; }
        .action-edit:hover { background: #059669; color: white; }
        .action-delete { color: #dc2626; border-color: #dc2626; }
        .action-delete:hover { background: #dc2626; color: white; }

        .day-group { border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .day-group.collapsed .day-content { display: none; }
        .day-header { background: #f8fafc; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none; border-bottom: 1px solid #e5e7eb; transition: background 0.15s; }
        .day-group.collapsed .day-header { border-bottom: none; }
        .day-header:hover { background: #f1f5f9; }
        .dh-arrow { font-size: 10px; color: #9ca3af; transition: transform 0.2s; width: 14px; text-align: center; }
        .day-group.collapsed .dh-arrow { transform: rotate(-90deg); }
        .day-content { background: white; }
        .day-body-wrap { padding: 16px; }
        .panel-empty,
        .pick-state {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 60px 30px;
            text-align: center;
            color: #9ca3af;
        }
        .panel-empty { box-shadow: none; border-radius: 0; padding: 42px 20px; font-size: 14px; }
        .pick-state p { font-size: 15px; color: #6b7280; }
        .alert { padding: 12px 16px; border-radius: 8px; margin: 0 16px 16px; font-size: 13px; border-left: 3px solid; }
        .alert-success { background: #d1fae5; border-color: #059669; color: #065f46; }
        .alert-error { background: #fee2e2; border-color: #dc2626; color: #991b1b; }

        @media (max-width: 1200px) {
            .stats-row { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .spx-filter-bar { flex-wrap: wrap; }
            .spx-filter-group.fg-type,
            .spx-filter-group.fg-month,
            .spx-filter-group.fg-year,
            .spx-filter-group.fg-date { flex: 1 1 170px; }
        }
        @media (max-width: 720px) {
            .stats-row { grid-template-columns: 1fr; }
            .hero-actions { justify-content: flex-start; }
            .spx-filter-bar { align-items: stretch; }
            .spx-filter-bar .btn-primary,
            .spx-filter-bar .btn-secondary { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero-wallet">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('hr'); ?>">Pracownicy</a> - Portfel firmowy
                </div>
                <h1>Portfel firmowy</h1>
                <p><?php echo $worker ? e($workerName) : 'Wybierz pracownika, aby zobaczyć saldo portfela, historię operacji i kontrolę ruchów.'; ?></p>
            </div>
            <?php if ($worker): ?>
            <div class="hero-right">
                <div class="hero-actions">
                    <a href="<?php echo url('hr'); ?>?tab=new&worker_id=<?php echo $workerId; ?>&op_type=advance_company" class="btn-hero-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Zasil portfel
                    </a>
                    <a href="<?php echo url('finanse.zaliczki.transfer', ['from_worker_id' => $workerId]); ?>" class="btn-hero-light">Przekaż środki</a>
                    <a href="<?php echo url('hr'); ?>?tab=new&worker_id=<?php echo $workerId; ?>&op_type=advance_private" class="btn-hero-light">Zaliczka prywatna</a>
                    <a href="<?php echo $walletExpenseCreateUrl; ?>" class="btn-hero-light">Dodaj wydatek</a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="filter-card">
            <div class="filter-card-header">Wybór portfela</div>
            <form method="get" class="worker-picker-bar">
                <label for="worker_id">Pracownik</label>
                <select name="worker_id" id="worker_id" onchange="this.form.submit()">
                    <option value="">-- wybierz pracownika --</option>
                    <?php foreach ($workersList as $w): ?>
                        <option value="<?php echo (int)$w['id']; ?>" <?php echo ($workerId === (int)$w['id']) ? 'selected' : ''; ?>>
                            <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-filter-apply">Pokaż portfel</button>
            </form>
        </div>

        <?php if (!$worker && $workerId > 0): ?>
            <div class="pick-state">
                <p>Nie znaleziono pracownika o wskazanym identyfikatorze.</p>
            </div>
        <?php elseif (!$worker): ?>
            <div class="pick-state">
                <svg viewBox="0 0 48 48" fill="none" stroke="#9ca3af" stroke-width="1.5" width="56" height="56">
                    <rect x="4" y="14" width="40" height="28" rx="4"/>
                    <path d="M32 14v-4a8 8 0 0 0-16 0v4"/>
                    <circle cx="24" cy="28" r="3"/>
                </svg>
                <p>Wybierz pracownika z listy powyżej.</p>
            </div>
        <?php else: ?>
            <div class="stats-row">
                <div class="stat-card">
                    <div class="sc-label">Łączne wpłaty</div>
                    <div class="sc-value"><?php echo formatMoney($walletSummary['total_topups']); ?></div>
                    <div class="sc-sub">Suma zasileń portfela firmowego</div>
                </div>
                <div class="stat-card kpi-available">
                    <div class="sc-label">Dostępne</div>
                    <div class="sc-value"><?php echo formatMoney($walletSummary['available']); ?></div>
                    <div class="sc-sub"><?php echo (int)$walletSummary['open_count']; ?> otwartych pozycji portfela</div>
                </div>
                <div class="stat-card kpi-spent">
                    <div class="sc-label">Wydane</div>
                    <div class="sc-value"><?php echo formatMoney($walletSummary['spent']); ?></div>
                    <div class="sc-sub">Suma rozliczeń i transferów wychodzących</div>
                </div>
            </div>

            <div class="wallet-card">
                <form method="GET" action="" id="walletFilterForm">
                    <input type="hidden" name="worker_id" value="<?php echo (int)$workerId; ?>">
                    <div class="spx-filter-bar">
                        <div class="spx-filter-group fg-type">
                            <label for="walletType">Typ ruchu</label>
                            <select name="type" id="walletType">
                                <option value="">Wszystkie</option>
                                <?php foreach (array_filter($validFilterTypes) as $typeOption): ?>
                                    <option value="<?php echo e($typeOption); ?>" <?php echo $filterType === $typeOption ? 'selected' : ''; ?>>
                                        <?php echo e(walletFilterOptionLabel($typeOption)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="spx-filter-group fg-month">
                            <label for="walletSelectMonth">Miesiąc</label>
                            <select name="month" id="walletSelectMonth" onchange="walletOnMonthYearChange()">
                                <option value="0" <?php echo $walletActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                                <?php for ($mn = 1; $mn <= 12; $mn++): ?>
                                    <option value="<?php echo $mn; ?>" <?php echo $walletActiveMonth === $mn ? 'selected' : ''; ?>>
                                        <?php echo e($monthNames[$mn]); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="spx-filter-group fg-year">
                            <label for="walletSelectYear">Rok</label>
                            <select name="year" id="walletSelectYear" onchange="walletOnMonthYearChange()">
                                <?php foreach ($walletYearOptions as $yearOpt): ?>
                                    <option value="<?php echo (int)$yearOpt; ?>" <?php echo $walletActiveYear === (int)$yearOpt ? 'selected' : ''; ?>>
                                        <?php echo (int)$yearOpt; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="spx-filter-group fg-date">
                            <label for="walletDateFrom">Od</label>
                            <input type="date" name="date_from" id="walletDateFrom" value="<?php echo e($filterFrom); ?>">
                        </div>
                        <div class="spx-filter-group fg-date">
                            <label for="walletDateTo">Do</label>
                            <input type="date" name="date_to" id="walletDateTo" value="<?php echo e($filterTo); ?>">
                        </div>
                        <button type="submit" class="btn-primary">Filtruj</button>
                        <?php if ($filterType || $filterFrom || $filterTo): ?>
                            <a href="<?php echo walletSafeReturnUrl($workerId); ?>" class="btn-secondary">Resetuj</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php
                $isAllActive = ($filterFrom === '' && $filterTo === '');
                $isYearActive = ($filterFrom === $yearStart && $filterTo === $todayDate);
                ?>
                <div class="quick-bar">
                    <div class="quick-left">
                        <a href="?<?php echo $quickBaseQs; ?>&date_from=<?php echo $todayDate; ?>&date_to=<?php echo $todayDate; ?>" class="spx-quick-btn <?php echo ($filterFrom === $todayDate && $filterTo === $todayDate) ? 'active' : ''; ?>">Dziś</a>
                        <a href="?<?php echo $quickBaseQs; ?>&date_from=<?php echo $weekAgo; ?>&date_to=<?php echo $todayDate; ?>" class="spx-quick-btn <?php echo ($filterFrom === $weekAgo && $filterTo === $todayDate) ? 'active' : ''; ?>">7 dni</a>
                        <a href="?<?php echo $quickBaseQs; ?>&date_from=<?php echo $monthStart; ?>&date_to=<?php echo $monthEnd; ?>" class="spx-quick-btn <?php echo ($filterFrom === $monthStart && $filterTo === $monthEnd) ? 'active' : ''; ?>">Ten miesiąc</a>
                        <a href="?<?php echo $quickBaseQs; ?>&date_from=<?php echo $yearStart; ?>&date_to=<?php echo $todayDate; ?>" class="spx-quick-btn <?php echo $isYearActive ? 'active' : ''; ?>">Ten rok</a>
                        <a href="?<?php echo $quickBaseQs; ?>" class="spx-quick-btn <?php echo $isAllActive ? 'active' : ''; ?>">Wszystko</a>
                    </div>
                    <div class="quick-right">
                        <span class="filter-count">Wyników: <?php echo (int)$walletTotalRows; ?></span>
                        <button type="button" class="btn-color-mode active" onclick="walletToggleColors()" title="Kolory wierszy">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 2a10 10 0 0 1 0 20"/>
                            </svg>
                        </button>
                        <?php if (!empty($walletEntriesByDate)): ?>
                            <div class="toggle-group">
                                <button type="button" class="small-toggle" onclick="walletExpandAll()">Rozwiń</button>
                                <button type="button" class="small-toggle" onclick="walletCollapseAll()">Zwiń</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($_GET['wallet_flash']) && $_GET['wallet_flash'] === 'updated'): ?>
                    <div class="alert alert-success">Ruch portfela został zaktualizowany.</div>
                <?php elseif (isset($_GET['wallet_flash']) && $_GET['wallet_flash'] === 'deleted'): ?>
                    <div class="alert alert-success">Ruch portfela został usunięty.</div>
                <?php elseif (isset($_GET['wallet_flash']) && $_GET['wallet_flash'] === 'error'): ?>
                    <div class="alert alert-error">Nie udało się wykonać operacji na ruchu portfela.</div>
                <?php endif; ?>

                <?php if (empty($walletEntriesByDate)): ?>
                    <div class="panel-empty">Brak ruchów portfela dla podanych kryteriów.</div>
                <?php else: ?>
                    <div class="day-body-wrap">
                        <?php foreach ($walletEntriesByDate as $entryDate => $dayData): ?>
                            <?php
                            $dayNum = date('w', strtotime($entryDate));
                            $dayName = $polishDays[$dayNum];
                            ?>
                            <div class="day-group" data-wallet-date="<?php echo e($entryDate); ?>">
                                <div class="day-header" onclick="this.parentElement.classList.toggle('collapsed')">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <span style="font-size:11px;font-weight:700;color:#6b7280;background:#e2e8f0;padding:2px 7px;border-radius:4px;text-transform:uppercase;"><?php echo e($dayName); ?></span>
                                        <span style="font-weight:700;font-size:14px;color:#334155;"><?php echo date('d.m.Y', strtotime($entryDate)); ?></span>
                                    </div>
                                    <div style="display:flex;align-items:center;gap:14px;font-size:12px;color:#6b7280;">
                                        <span style="font-weight:600;color:#374151;"><?php echo (int)$dayData['count']; ?> operacji</span>
                                        <span style="font-weight:700;color:#667eea;"><?php echo walletFormatSignedMoney((float)$dayData['total_amount']); ?></span>
                                        <span class="dh-arrow">&#9660;</span>
                                    </div>
                                </div>
                                <div class="day-content">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="col-date">Data</th>
                                                <th class="col-type">Typ ruchu</th>
                                                <th class="col-amount">Kwota</th>
                                                <th>Opis</th>
                                                <th class="col-source">Źródło</th>
                                                <th class="col-actions">Akcje</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($dayData['rows'] as $entry): ?>
                                                <?php
                                                $visualAmount = walletVisualAmount($entry);
                                                $rowColor = walletRowColor($workerId);
                                                $sourceKindLabel = walletSourceLabel($entry['source_kind'] ?? null);
                                                $sourceText = '';
                                                if ((string)$entry['entry_type'] === 'ADVANCE' && $sourceKindLabel !== '') {
                                                    $sourceText = $sourceKindLabel;
                                                    if (!empty($entry['source_ref'])) {
                                                        $sourceText .= ' - ' . (string)$entry['source_ref'];
                                                    }
                                                } elseif (!empty($entry['expense_id'])) {
                                                    $sourceText = 'Wydatek pracownika';
                                                } elseif (!empty($entry['document_id'])) {
                                                    $sourceText = 'Dokument kosztowy';
                                                } elseif (!empty($entry['settlement_id'])) {
                                                    $sourceText = 'Rozliczenie wynagrodzenia';
                                                }
                                                $entryEditUrl = '';
                                                $entryDeleteUrl = '';
                                                if ((string)$entry['entry_type'] === 'ADVANCE') {
                                                    $entryEditUrl = url('hr.settlements.edit', ['source' => 'advance', 'id' => (int)$entry['advance_id'], 'return_to' => $walletReturnUrl]);
                                                    $entryDeleteUrl = url('hr.settlements.delete', ['source' => 'advance', 'id' => (int)$entry['advance_id'], 'return_to' => $walletReturnUrl]);
                                                } else {
                                                    $entryEditUrl = url('hr.workers.wallet-ledger-edit', ['id' => (int)$entry['id'], 'return_url' => $walletReturnUrl]);
                                                    $entryDeleteUrl = url('hr.workers.wallet-ledger-delete', ['id' => (int)$entry['id'], 'return_url' => $walletReturnUrl]);
                                                }
                                                ?>
                                                <tr style="--worker-bg:<?php echo e($rowColor['hslLight']); ?>;--worker-color:<?php echo e($rowColor['hslBorder']); ?>;">
                                                    <td><?php echo formatDate($entry['entry_date']); ?></td>
                                                    <td><span class="entry-badge <?php echo walletEntryBadgeClass($entry); ?>"><?php echo e(walletEntryLabel($entry)); ?></span></td>
                                                    <td class="amount-cell <?php echo $visualAmount >= 0 ? 'bal-pos' : 'bal-neg'; ?>">
                                                        <?php echo walletFormatSignedMoney($visualAmount); ?>
                                                    </td>
                                                    <?php $entryVisibleDescription = walletHumanizeDescription((string)($entry['description'] ?? ''), $walletWorkerNames); ?>
                                                    <td class="description-muted" title="<?php echo e($entryVisibleDescription); ?>">
                                                        <?php echo $entryVisibleDescription !== '' ? e($entryVisibleDescription) : '--'; ?>
                                                    </td>
                                                    <td class="source-meta"><?php echo $sourceText !== '' ? e($sourceText) : '--'; ?></td>
                                                    <td class="col-actions">
                                                        <div class="action-row">
                                                            <a href="<?php echo $entryEditUrl; ?>" class="action-btn action-edit">Edytuj</a>
                                                            <?php if (!empty($entry['expense_id'])): ?>
                                                                <form method="POST" action="<?php echo url('finanse.wydatki.delete'); ?>" onsubmit="return confirm('Usunąć ten wydatek i powiązany ruch portfela?');">
                                                                    <input type="hidden" name="id" value="<?php echo (int)$entry['expense_id']; ?>">
                                                                    <input type="hidden" name="return_url" value="<?php echo e($walletReturnUrl . '&wallet_flash=deleted'); ?>">
                                                                    <button type="submit" class="action-btn action-delete">Usuń</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <a href="<?php echo $entryDeleteUrl; ?>" class="action-btn action-delete">Usuń</a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <script>
        function walletToggleColors() {
            document.body.classList.toggle('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) btn.classList.toggle('active');
            const colorsEnabled = !document.body.classList.contains('no-colors');
            localStorage.setItem('brygad_wallet_colors', colorsEnabled ? '1' : '0');
        }

        function walletExpandAll(remember = true) {
            document.querySelectorAll('.day-group').forEach(function(group) {
                group.classList.remove('collapsed');
            });
            if (remember) localStorage.setItem('brygad_wallet_table_state', 'expanded');
        }

        function walletCollapseAll(remember = true) {
            document.querySelectorAll('.day-group').forEach(function(group) {
                group.classList.add('collapsed');
            });
            if (remember) localStorage.setItem('brygad_wallet_table_state', 'collapsed');
        }

        function walletOnMonthYearChange() {
            var monthEl = document.getElementById('walletSelectMonth');
            var yearEl = document.getElementById('walletSelectYear');
            var dfEl = document.getElementById('walletDateFrom');
            var dtEl = document.getElementById('walletDateTo');
            var form = document.getElementById('walletFilterForm');
            if (!monthEl || !yearEl || !dfEl || !dtEl || !form) return;
            var month = parseInt(monthEl.value, 10);
            var year = parseInt(yearEl.value, 10);
            if (!month) return;
            var lastDay = new Date(year, month, 0).getDate();
            var pad = function(value) { return String(value).padStart(2, '0'); };
            dfEl.value = year + '-' + pad(month) + '-01';
            dtEl.value = year + '-' + pad(month) + '-' + pad(lastDay);
            form.submit();
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('brygad_wallet_colors') === '0') {
                document.body.classList.add('no-colors');
                const btn = document.querySelector('.btn-color-mode');
                if (btn) btn.classList.remove('active');
            }

            if (localStorage.getItem('brygad_wallet_table_state') === 'collapsed') {
                walletCollapseAll(false);
            } else {
                walletExpandAll(false);
            }
        });
    </script>
</body>
</html>
