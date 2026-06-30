<?php
/**
 * BRYGAD ERP v3.0 - Saldo Pracownika
 * Widok: admin widzi wszystkich, pracownik widzi tylko siebie
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

// TWARDY GATE UPRAWNIEŃ
if ($isAdminUser) {
    // Admin może wybrać pracownika z listy
    $selectedWorkerId = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
    
    // Pobierz listę pracowników
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $workers = $stmt->fetchAll();
    } catch (PDOException $e) {
        $workers = [];
    }
    
    // Jeśli nie wybrano, wybierz pierwszego
    if ($selectedWorkerId == 0 && !empty($workers)) {
        $selectedWorkerId = $workers[0]['id'];
    }
} else {
    // Worker widzi TYLKO siebie - ignoruj GET['worker_id']
    if (!$currentWorkerId) {
        header('Location: index.php');
        exit;
    }
    $selectedWorkerId = $currentWorkerId;
    $workers = [];
    
    // Jeśli ktoś próbuje manipulować URL
    if (isset($_GET['worker_id']) && (int)$_GET['worker_id'] != $currentWorkerId) {
        logEvent("SECURITY: Worker ID $currentWorkerId próbował podejrzeć saldo worker ID " . $_GET['worker_id'], 'WARNING');
        header('Location: balance.php');
        exit;
    }
}

// Pobierz dane wybranego pracownika
$worker = null;
if ($selectedWorkerId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM workers WHERE id = ?");
        $stmt->execute([$selectedWorkerId]);
        $worker = $stmt->fetch();
    } catch (PDOException $e) {
        $worker = null;
        logEvent("BALANCE ERROR: Nie można pobrać pracownika ID $selectedWorkerId: " . $e->getMessage(), 'ERROR');
    }
} else {
    logEvent("BALANCE WARNING: selectedWorkerId = $selectedWorkerId (0 lub ujemne)", 'WARNING');
}

// Pobierz transakcje (chronologicznie)
// FIX v7 2026-02-08: Indywidualne try-catch per query + naprawiony brak kolumny created_at w settlements
$transactions = [];
$saldo = 0;
$workLogs = [];
$expenses = [];
$settlements = [];

if ($worker) {
    // ── 1. Zatwierdzone wpisy pracy (zarobki) ──────────────────────
    try {
        $stmt = $pdo->prepare("
            SELECT
                'work'          AS `type`,
                id              AS work_id,
                `date`,
                final_cost      AS amount,
                description,
                hours,
                overtime_hours,
                created_at      AS transaction_date
            FROM work_logs
            WHERE worker_id = ? AND status = 'approved' AND final_cost IS NOT NULL
        ");
        $stmt->execute([$selectedWorkerId]);
        $workLogs = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent("BALANCE: work_logs query error for worker $selectedWorkerId: " . $e->getMessage(), 'ERROR');
    }

    // ── 2. Zatwierdzone wydatki (do zwrotu) ─────────────────────────
    try {
        $stmt = $pdo->prepare("
            SELECT
                'expense'   AS `type`,
                id          AS expense_id,
                `date`,
                amount,
                description,
                created_at  AS transaction_date
            FROM worker_expenses
            WHERE worker_id = ?
              AND status IN ('approved', 'reimbursed')
              AND (
                    paid_by_employee = 1
                    OR description LIKE '%[PAID_BY_EMPLOYEE]%'
                  )
        ");
        $stmt->execute([$selectedWorkerId]);
        $expenses = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent("BALANCE: expenses query error for worker $selectedWorkerId: " . $e->getMessage(), 'ERROR');
    }

    // ── 3. Rozliczenia (wypłaty, zaliczki, zwroty, premie) ─────────
    //    UWAGA: tabela settlements NIE MA kolumny created_at!
    //    Używamy `date` jako transaction_date.
    try {
        $stmt = $pdo->prepare("
            SELECT
                'settlement'        AS `type`,
                id                  AS settlement_id,
                `date`,
                `type`              AS settlement_type,
                advance_kind,
                amount,
                description,
                `date`              AS transaction_date
            FROM settlements
            WHERE worker_id = ?
              AND (
                    `type` <> 'advance'
                    OR advance_kind = 'private'
                    OR advance_kind IS NULL
                  )
              AND NOT (
                    `type` = 'advance'
                    AND (advance_kind = 'private' OR advance_kind IS NULL)
                    AND EXISTS (
                        SELECT 1
                        FROM worker_advances wa_dup
                        WHERE wa_dup.worker_id = settlements.worker_id
                          AND wa_dup.type = 'PRIVATE'
                          AND wa_dup.issue_date = settlements.date
                          AND wa_dup.amount = settlements.amount
                    )
                  )
        ");
        $stmt->execute([$selectedWorkerId]);
        $settlements = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent("BALANCE: settlements query error for worker $selectedWorkerId: " . $e->getMessage(), 'ERROR');
    }

    // ── 4. Nowe zaliczki (worker_advances) - wydane pracownikowi ─────
    $newAdvances = [];
    try {
        $stmt = $pdo->prepare("
            SELECT
                'new_advance'       AS `type`,
                id                  AS advance_id,
                issue_date          AS `date`,
                `type`              AS advance_type,
                amount,
                description,
                status,
                created_at          AS transaction_date
            FROM worker_advances
            WHERE worker_id = ?
              AND `type` = 'PRIVATE'
        ");
        $stmt->execute([$selectedWorkerId]);
        $newAdvances = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent("BALANCE: worker_advances query error for worker $selectedWorkerId: " . $e->getMessage(), 'ERROR');
    }

    // ── Połącz i posortuj chronologicznie ───────────────────────────
    $transactions = array_merge($workLogs, $expenses, $settlements, $newAdvances);

    usort($transactions, function ($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });

    // ── Oblicz saldo krok po kroku ──────────────────────────────────
    foreach ($transactions as &$transaction) {
        if ($transaction['type'] === 'work') {
            $saldo += $transaction['amount'];
            $transaction['balance_change'] = '+' . formatMoney($transaction['amount']);
            $transaction['description_full'] = 'Praca: ' . ($transaction['description'] ?? 'Brak opisu') .
                ' (' . $transaction['hours'] . 'h' .
                ($transaction['overtime_hours'] > 0 ? ' + ' . $transaction['overtime_hours'] . 'h nadgodziny' : '') . ')';

        } elseif ($transaction['type'] === 'expense') {
            $saldo += $transaction['amount'];
            $transaction['balance_change'] = '+' . formatMoney($transaction['amount']);
            $cleanDescription = trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', $transaction['description'] ?? 'Brak opisu'));
            $transaction['description_full'] = 'Wydatek (do zwrotu): ' . $cleanDescription;

        } elseif ($transaction['type'] === 'settlement') {
            $settlementType = $transaction['settlement_type'];

            if ($settlementType === 'payout') {
                $saldo -= $transaction['amount'];
                $transaction['balance_change'] = '-' . formatMoney($transaction['amount']);
                $transaction['description_full'] = 'Wypłata';
            } elseif ($settlementType === 'advance') {
                $saldo -= $transaction['amount'];
                $transaction['balance_change'] = '-' . formatMoney($transaction['amount']);
                $advanceKindValue = strtolower((string)($transaction['advance_kind'] ?? 'private'));
                $advanceKind = $advanceKindValue === 'private' ? 'prywatna' : 'firmowa';
                $transaction['description_full'] = 'Zaliczka (' . $advanceKind . ')';
            } elseif ($settlementType === 'reimbursement') {
                $saldo -= $transaction['amount'];
                $transaction['balance_change'] = '-' . formatMoney($transaction['amount']);
                $transaction['description_full'] = 'Zwrot kosztów';
            } elseif ($settlementType === 'bonus') {
                $saldo += $transaction['amount'];
                $transaction['balance_change'] = '+' . formatMoney($transaction['amount']);
                $transaction['description_full'] = 'Premia';
            } elseif ($settlementType === 'correction') {
                $amount = $transaction['amount'];
                $saldo += $amount;
                $transaction['balance_change'] = ($amount >= 0 ? '+' : '') . formatMoney($amount);
                $transaction['description_full'] = 'Korekta';
            }

            if (!empty($transaction['description'])) {
                $transaction['description_full'] .= ': ' . $transaction['description'];
            }

        } elseif ($transaction['type'] === 'new_advance') {
            // Nowe zaliczki z worker_advances
            $saldo -= $transaction['amount'];
            $transaction['balance_change'] = '-' . formatMoney($transaction['amount']);
            $statusLabel = $transaction['status'] === 'open' ? ' [OTWARTA]' : ' [ZAMKNIĘTA]';
            $transaction['description_full'] = 'Zaliczka (prywatna)' . $statusLabel;
            
            if (!empty($transaction['description'])) {
                $transaction['description_full'] .= ': ' . $transaction['description'];
            }
        }

        $transaction['running_balance'] = $saldo;
    }
    unset($transaction); // Zwolnij referencję &

    // Odwróć kolejność (najnowsze najpierw)
    $transactions = array_reverse($transactions);
}

// Oblicz podsumowanie
$summary = [
    'earned' => 0,
    'expenses' => 0,
    'payouts' => 0,
    'advances' => 0,
    'bonuses' => 0,
];

if ($worker) {
    try {
        // Zarobki
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(final_cost), 0) AS total FROM work_logs WHERE worker_id = ? AND status = 'approved' AND final_cost IS NOT NULL");
        $stmt->execute([$selectedWorkerId]);
        $summary['earned'] = $stmt->fetchColumn();

        // Wydatki zatwierdzone
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM worker_expenses
            WHERE worker_id = ?
              AND status IN ('approved', 'reimbursed')
              AND (
                    paid_by_employee = 1
                    OR description LIKE '%[PAID_BY_EMPLOYEE]%'
                  )
        ");
        $stmt->execute([$selectedWorkerId]);
        $summary['expenses'] = $stmt->fetchColumn();

        // Wypłaty
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM settlements WHERE worker_id = ? AND `type` = 'payout'");
        $stmt->execute([$selectedWorkerId]);
        $summary['payouts'] = $stmt->fetchColumn();

        // Zaliczki
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM settlements
            WHERE worker_id = ?
              AND `type` = 'advance'
              AND (advance_kind = 'private' OR advance_kind IS NULL)
              AND NOT EXISTS (
                  SELECT 1
                  FROM worker_advances wa_dup
                  WHERE wa_dup.worker_id = settlements.worker_id
                    AND wa_dup.type = 'PRIVATE'
                    AND wa_dup.issue_date = settlements.date
                    AND wa_dup.amount = settlements.amount
              )
        ");
        $stmt->execute([$selectedWorkerId]);
        $summary['advances'] = $stmt->fetchColumn();

        // Nowe zaliczki prywatne (worker_advances)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM worker_advances
            WHERE worker_id = ?
              AND `type` = 'PRIVATE'
        ");
        $stmt->execute([$selectedWorkerId]);
        $summary['advances'] += (float)$stmt->fetchColumn();

        // Premie
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM settlements WHERE worker_id = ? AND `type` = 'bonus'");
        $stmt->execute([$selectedWorkerId]);
        $summary['bonuses'] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        logEvent("Błąd obliczania podsumowania salda: " . $e->getMessage(), 'ERROR');
    }
}

function getBalanceRowStyle(array $transaction)
{
    $bg = '#ffffff';
    $border = '#9ca3af';

    if ($transaction['type'] === 'work') {
        $bg = '#ecfdf5';
        $border = '#22c55e';
    } elseif ($transaction['type'] === 'expense') {
        $bg = '#eff6ff';
        $border = '#3b82f6';
    } elseif ($transaction['type'] === 'new_advance') {
        $bg = '#fff7ed';
        $border = '#f97316';
    } elseif ($transaction['type'] === 'settlement') {
        $settlementType = $transaction['settlement_type'] ?? '';
        if ($settlementType === 'payout') {
            $bg = '#fef2f2';
            $border = '#ef4444';
        } elseif ($settlementType === 'advance') {
            $bg = '#fff7ed';
            $border = '#f59e0b';
        } elseif ($settlementType === 'reimbursement') {
            $bg = '#eff6ff';
            $border = '#3b82f6';
        } elseif ($settlementType === 'bonus') {
            $bg = '#f0fdf4';
            $border = '#22c55e';
        } elseif ($settlementType === 'correction') {
            $bg = '#f5f3ff';
            $border = '#8b5cf6';
        }
    }

    return "--row-bg: {$bg}; --row-border: {$border};";
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Saldo Pracownika</title>
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
        /* Override header */
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }
        /* Hero */
        .page-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;
        }
        .page-header h2 {
            font-size: 26px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 4px;
        }
        .worker-select {
            padding: 9px 14px;
            height: 38px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            background: white;
            font-family: inherit;
            cursor: pointer;
        }
        .worker-select:focus {
            outline: none;
            border-color: var(--primary);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 16px;
            min-height: 38px;
            border-radius: 6px;
            border: 1px solid transparent;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            line-height: 1;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #ffffff; color: #1e3a8a; border-color: #ffffff;
        }
        .btn-primary:hover { background: #e0e7ff; transform: translateY(-1px); }
        .btn-ghost {
            background: rgba(255,255,255,0.12); color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .btn-ghost:hover { background: rgba(255,255,255,0.2); color: #ffffff; }
        .worker-select {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.25);
            color: white;
            border-radius: 8px;
            padding: 0 10px;
            height: 38px;
            font-size: 13px;
            font-family: inherit;
            cursor: pointer;
        }
        .worker-select:focus { outline: none; border-color: #60a5fa; }
        .worker-select option { background: #1e3a8a; color: white; }
        .btn-color-mode {
            width: 32px; height: 32px; border-radius: 6px;
            border: 1px solid rgba(255,255,255,0.25);
            background: rgba(255,255,255,0.1);
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: all 0.15s; padding: 0; color: white;
        }
        .btn-color-mode:hover { background: rgba(255,255,255,0.2);
            border-color: var(--primary);
        }
        .btn-color-mode.active {
            background: linear-gradient(135deg, #fce7f3, #e0e7ff);
            border-color: #a78bfa;
        }
        .btn-color-mode svg {
            width: 18px;
            height: 18px;
        }
        
        /* Statystyki */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .summary-card {
            background: white;
            padding: 18px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .summary-card h3 {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .summary-card .value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-main);
        }
        .summary-card.positive .value {
            color: var(--success);
        }
        .summary-card.negative .value {
            color: var(--danger);
        }
        
        /* Karta salda */
        .balance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 35px;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .balance-card h3 {
            font-size: 14px;
            margin-bottom: 12px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .balance-card .balance {
            font-size: 42px;
            font-weight: 700;
        }
        
        /* Karta */
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        /* Tabela - spójna z worklog */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: #f9fafb;
        }
        th {
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            color: var(--text-muted);
            border: 1px solid #000000;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        td {
            padding: 10px 14px;
            border: 1px solid #000000;
            font-size: 13px;
            vertical-align: middle;
        }
        body:not(.no-colors) tbody tr {
            background: var(--row-bg, #ffffff);
            border-left: 4px solid var(--row-border, transparent);
        }
        body:not(.no-colors) tbody tr:hover {
            filter: brightness(0.97);
        }
        body.no-colors tbody tr:nth-child(odd) {
            background: #ffffff;
            border-left: 4px solid transparent;
        }
        body.no-colors tbody tr:nth-child(even) {
            background: #f8fafc;
            border-left: 4px solid transparent;
        }
        body.no-colors tbody tr:hover {
            background: #e0f2fe !important;
        }
        
        .positive-amount {
            color: var(--success);
            font-weight: 600;
        }
        .negative-amount {
            color: var(--danger);
            font-weight: 600;
        }
        
        /* Przyciski akcji - spójne z worklog */
        .action-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
        }
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            height: 26px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            transition: all 0.2s;
            border: 1px solid;
            background: white;
            white-space: nowrap;
        }
        .action-btn:hover {
            transform: translateY(-1px);
        }
        .action-btn-edit {
            color: #059669;
            border-color: #059669;
        }
        .action-btn-edit:hover {
            background: #059669;
            color: white;
        }
        .action-btn-delete {
            color: #dc2626;
            border-color: #dc2626;
        }
        .action-btn-delete:hover {
            background: #dc2626;
            color: white;
        }
        
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: var(--text-muted);
            font-size: 12px;
        }
        
        /* Responsywność */
        @media (max-width: 768px) {
            .container { padding: 15px; }
            .page-header { flex-direction: column; align-items: stretch; }
            .page-header h2 { font-size: 22px; }
            .summary-grid { grid-template-columns: 1fr 1fr; }
            .balance-card { padding: 25px; }
            .balance-card .balance { font-size: 32px; }
            th, td { padding: 8px 10px; font-size: 12px; }
        }
        
        @media (max-width: 480px) {
            .summary-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <div>
                <div style="font-size:12px; color:#bfdbfe; margin-bottom:6px;">
                    <a href="<?php echo url('hr'); ?>" style="color:#dbeafe; text-decoration:none;">Pracownicy</a> ›
                    Szczegółowe saldo
                </div>
                <h2>
                    <?php if ($isAdminUser): ?>
                        Szczegółowe saldo pracownika
                    <?php else: ?>
                        Moje saldo
                    <?php endif; ?>
                </h2>
            </div>

            <div style="display: flex; gap: 8px; align-items: center; flex-wrap:wrap;">
                <button type="button" class="btn-color-mode active" onclick="toggleColors()" title="Kolory transakcji">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 2a10 10 0 0 1 0 20"/>
                        <circle cx="12" cy="12" r="4"/>
                    </svg>
                </button>
                <?php if ($worker): ?>
                    <a href="<?php echo url('hr'); ?>?tab=new&worker_id=<?php echo $selectedWorkerId; ?>"
                       class="btn btn-primary">Rozlicz</a>
                    <a href="<?php echo url('hr.workers.report', ['worker_id' => $selectedWorkerId]); ?>"
                       class="btn btn-ghost">Raport</a>
                <?php endif; ?>
                <?php if ($isAdminUser && !empty($workers)): ?>
                    <select class="worker-select" onchange="window.location.href='?worker_id=' + this.value">
                        <?php foreach ($workers as $w): ?>
                            <option value="<?php echo $w['id']; ?>"
                                    <?php echo $w['id'] == $selectedWorkerId ? 'selected' : ''; ?>>
                                <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!$worker): ?>
            <div class="card">
                <div class="no-data">
                    Nie znaleziono pracownika.
                </div>
            </div>
        <?php else: ?>
            <!-- Aktualne saldo -->
            <div class="balance-card">
                <h3>Aktualne Saldo</h3>
                <div class="balance"><?php echo formatMoney($saldo); ?></div>
                <div style="margin-top: 10px; font-size: 14px; opacity: 0.9;">
                    <?php if ($saldo > 0): ?>
                        Firma jest winna pracownikowi
                    <?php elseif ($saldo < 0): ?>
                        Pracownik jest winny firmie
                    <?php else: ?>
                        Rozliczone
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Podsumowanie -->
            <div class="summary-grid">
                <div class="summary-card positive">
                    <h3>Zarobione</h3>
                    <div class="value"><?php echo formatMoney($summary['earned']); ?></div>
                </div>
                <div class="summary-card positive">
                    <h3>Wydatki (do zwrotu)</h3>
                    <div class="value"><?php echo formatMoney($summary['expenses']); ?></div>
                </div>
                <div class="summary-card negative">
                    <h3>Wypłaty</h3>
                    <div class="value"><?php echo formatMoney($summary['payouts']); ?></div>
                </div>
                <div class="summary-card negative">
                    <h3>Zaliczki</h3>
                    <div class="value"><?php echo formatMoney($summary['advances']); ?></div>
                </div>
                <div class="summary-card positive">
                    <h3>Premie</h3>
                    <div class="value"><?php echo formatMoney($summary['bonuses']); ?></div>
                </div>
            </div>

            
            <!-- Historia transakcji -->
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Opis</th>
                            <th style="text-align: right;">Zmiana</th>
                            <th style="text-align: right;">Saldo</th>
                            <?php if ($isAdminUser): ?>
                                <th style="width: 120px; text-align: center;">Akcje</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 40px; color: #999;">
                                    Brak transakcji dla tego pracownika.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr style="<?php echo e(getBalanceRowStyle($transaction)); ?>">
                                    <td style="font-size: 13px; color: #666;">
                                        <?php echo formatDate($transaction['date']); ?>
                                    </td>
                                    <td>
                                        <?php if ($transaction['type'] === 'new_advance' && isset($transaction['advance_id'])): ?>
                                            <a href="<?php echo url('finanse.zaliczki.view', ['id' => $transaction['advance_id']]); ?>" 
                                               style="color: #0284c7; text-decoration: none; font-weight: 500;"
                                               onmouseover="this.style.textDecoration='underline'"
                                               onmouseout="this.style.textDecoration='none'">
                                                <?php echo e($transaction['description_full']); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo e($transaction['description_full']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <span class="<?php echo (strpos($transaction['balance_change'], '+') === 0) ? 'positive-amount' : 'negative-amount'; ?>">
                                            <?php echo $transaction['balance_change']; ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right; font-weight: 600;">
                                        <?php echo formatMoney($transaction['running_balance']); ?>
                                    </td>
                                    <?php if ($isAdminUser): ?>
                                        <td style="text-align: center;">
                                            <?php if ($transaction['type'] === 'settlement' && isset($transaction['settlement_id'])): ?>
                                                <!-- ROZLICZENIA -->
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('finanse.rozliczenia.edit', ['id' => $transaction['settlement_id']]); ?>" 
                                                       class="action-btn action-btn-edit">Edytuj</a>
                                                    <a href="<?php echo url('finanse.rozliczenia.delete', ['id' => $transaction['settlement_id']]); ?>" 
                                                       class="action-btn action-btn-delete">Usun</a>
                                                </div>
                                            <?php elseif ($transaction['type'] === 'work' && isset($transaction['work_id'])): ?>
                                                <!-- WPISY PRACY -->
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('hr.worklog.edit', ['id' => $transaction['work_id']]); ?>" 
                                                       class="action-btn action-btn-edit">Edytuj</a>
                                                    <a href="<?php echo url('hr.worklog.delete', ['id' => $transaction['work_id']]); ?>" 
                                                       class="action-btn action-btn-delete">Usun</a>
                                                </div>
                                            <?php elseif ($transaction['type'] === 'expense' && isset($transaction['expense_id'])): ?>
                                                <!-- WYDATKI -->
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('finanse.wydatki.edit', ['id' => $transaction['expense_id']]); ?>" 
                                                       class="action-btn action-btn-edit">Edytuj</a>
                                                    <a href="<?php echo url('finanse.wydatki.delete', ['id' => $transaction['expense_id']]); ?>" 
                                                       class="action-btn action-btn-delete">Usun</a>
                                                </div>
                                            <?php elseif ($transaction['type'] === 'new_advance' && isset($transaction['advance_id'])): ?>
                                                <!-- NOWE ZALICZKI (worker_advances) -->
                                                <div class="action-buttons">
                                                    <a href="<?php echo url('finanse.zaliczki.edit', ['id' => $transaction['advance_id']]); ?>" 
                                                       class="action-btn action-btn-edit">Edytuj</a>
                                                    <a href="<?php echo url('finanse.zaliczki.delete', ['id' => $transaction['advance_id']]); ?>" 
                                                       class="action-btn action-btn-delete"
                                                       onclick="return confirm('Czy na pewno chcesz USUNAC te zaliczke?');">Usun</a>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #d1d5db; font-size: 12px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    <script>
        function toggleColors() {
            document.body.classList.toggle('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) {
                btn.classList.toggle('active');
            }

            const colorsEnabled = !document.body.classList.contains('no-colors');
            localStorage.setItem('worklog_colors', colorsEnabled ? '1' : '0');
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (localStorage.getItem('worklog_colors') === '0') {
                document.body.classList.add('no-colors');
                const btn = document.querySelector('.btn-color-mode');
                if (btn) {
                    btn.classList.remove('active');
                }
            }
        });
    </script>
</body>
</html>
