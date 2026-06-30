<?php
/**
 * BRYGAD ERP - Centrum Pracownika (AGREGATOR UX, NIE MODUŁ)
 * Wszystko o pracowniku w jednym miejscu - ZERO nowych danych, tylko agregacja
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
require_once dirname(__DIR__, 2) . '/includes/absence_helper.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$isAdmin = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;
$currentTab = $_GET['tab'] ?? 'podsumowanie';

// TWARDY GATE UPRAWNIEŃ
if ($isAdmin) {
    $workerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($workerId <= 0) {
        header('Location: index.php');
        exit;
    }
} else {
    if (!$currentWorkerId) {
        header('Location: index.php');
        exit;
    }
    $workerId = $currentWorkerId;
    
    if (isset($_GET['id']) && (int)$_GET['id'] != $currentWorkerId) {
        logEvent("SECURITY: Worker ID $currentWorkerId próbował podejrzeć profil worker ID " . $_GET['id'], 'WARNING');
        header('Location: profile.php?id=' . $currentWorkerId);
        exit;
    }
}

// Pobierz dane pracownika
try {
    $stmt = $pdo->prepare("
        SELECT w.*, u.login, u.role
        FROM workers w
        LEFT JOIN users u ON u.worker_id = w.id
        WHERE w.id = ?
    ");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
    
    if (!$worker) {
        die("Pracownik nie został znaleziony.");
    }
} catch (PDOException $e) {
    die("Błąd: " . $e->getMessage());
}

$workerName = trim(($worker['first_name'] ?? '') . ' ' . ($worker['last_name'] ?? ''));
if (empty($workerName)) {
    $workerName = $worker['login'] ?? 'Pracownik';
}

// SEKCJA A) PODSUMOWANIE - dane z istniejących tabel
$summary = [
    'saldo' => 0,
    'last_payout' => null,
    'pending_hours' => 0,
    'approved_hours_month' => 0,
];

try {
    // Saldo (z settlements i work_logs)
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN status = 'approved' THEN final_cost ELSE 0 END), 0) as earned
        FROM work_logs
        WHERE worker_id = ?
    ");
    $stmt->execute([$workerId]);
    $earned = $stmt->fetch()['earned'];
    
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM worker_expenses
        WHERE worker_id = ?
          AND status IN ('approved', 'reimbursed')
          AND (
                paid_by_employee = 1
                OR description LIKE '%[PAID_BY_EMPLOYEE]%'
              )
    ");
    $stmt->execute([$workerId]);
    $expenses = $stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM settlements
        WHERE worker_id = ? AND type = 'payout'
    ");
    $stmt->execute([$workerId]);
    $payouts = $stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM settlements
        WHERE worker_id = ? AND type = 'advance'
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
    $stmt->execute([$workerId]);
    $advances = $stmt->fetch()['total'];

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM worker_advances
        WHERE worker_id = ?
          AND type = 'PRIVATE'
    ");
    $stmt->execute([$workerId]);
    $advances += (float)$stmt->fetch()['total'];
    
    $summary['saldo'] = $earned + $expenses - $payouts - $advances;
    
    // Ostatnia wypłata/zaliczka
    $stmt = $pdo->prepare("
        SELECT type, amount, date, description
        FROM settlements
        WHERE worker_id = ?
          AND (
                type = 'payout'
                OR (type = 'advance' AND (advance_kind = 'private' OR advance_kind IS NULL)
                    AND NOT EXISTS (
                        SELECT 1
                        FROM worker_advances wa_dup
                        WHERE wa_dup.worker_id = settlements.worker_id
                          AND wa_dup.type = 'PRIVATE'
                          AND wa_dup.issue_date = settlements.date
                          AND wa_dup.amount = settlements.amount
                    ))
              )
        ORDER BY date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$workerId]);
    $summary['last_payout'] = $stmt->fetch();
    
    // Godziny oczekujące (tylko rzeczywista praca, bez L4/urlopu)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(hours), 0) as total
        FROM work_logs
        WHERE worker_id = ? AND status = 'pending' AND (work_type = 'work' OR work_type IS NULL)
    ");
    $stmt->execute([$workerId]);
    $summary['pending_hours'] = $stmt->fetch()['total'];
    
    // Godziny zatwierdzone w bieżącym miesiącu (tylko rzeczywista praca, bez L4/urlopu)
    $monthStart = date('Y-m-01');
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(hours), 0) as total
        FROM work_logs
        WHERE worker_id = ? AND status = 'approved' AND date >= ? AND (work_type = 'work' OR work_type IS NULL)
    ");
    $stmt->execute([$workerId, $monthStart]);
    $summary['approved_hours_month'] = $stmt->fetch()['total'];
    
} catch (PDOException $e) {
    logEvent("Błąd pobierania podsumowania pracownika ID $workerId: " . $e->getMessage(), 'ERROR');
}

// SEKCJA B) GODZINY - ostatnie wpisy (10 ostatnich)
$recentWorkLogs = [];
if ($currentTab === 'godziny') {
    try {
        $stmt = $pdo->prepare("
            SELECT wl.*, p.name as project_name, pcn.name as cost_node_name
            FROM work_logs wl
            LEFT JOIN projects p ON wl.project_id = p.id
            LEFT JOIN project_cost_nodes pcn ON wl.cost_node_id = pcn.id
            WHERE wl.worker_id = ?
            ORDER BY wl.date DESC, wl.id DESC
            LIMIT 10
        ");
        $stmt->execute([$workerId]);
        $recentWorkLogs = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent("Błąd pobierania wpisów pracy: " . $e->getMessage(), 'ERROR');
    }
}

// SEKCJA C) ROZLICZENIA - ostatnie rozliczenia (10 ostatnich)
// UWAGA: Łączy settlements (stare) + worker_advances (nowe)
$recentSettlements = [];
if ($currentTab === 'rozliczenia') {
    try {
        // 1. Stare rozliczenia z settlements
        $stmt = $pdo->prepare("
            SELECT 
                'settlement' AS source,
                id,
                date,
                type,
                advance_kind,
                amount,
                description
            FROM settlements
            WHERE worker_id = ?
              AND (
                    type <> 'advance'
                    OR advance_kind = 'private'
                    OR advance_kind IS NULL
                  )
              AND NOT (
                    type = 'advance'
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
        $stmt->execute([$workerId]);
        $oldSettlements = $stmt->fetchAll();
        
        // 2. Nowe zaliczki z worker_advances
        $stmt = $pdo->prepare("
            SELECT 
                'new_advance' AS source,
                id,
                issue_date AS date,
                type AS advance_type,
                amount,
                description,
                status
            FROM worker_advances
            WHERE worker_id = ?
              AND type = 'PRIVATE'
        ");
        $stmt->execute([$workerId]);
        $newAdvances = $stmt->fetchAll();
        
        // 3. Połącz i posortuj (ostatnie 10)
        $recentSettlements = array_merge($oldSettlements, $newAdvances);
        usort($recentSettlements, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        $recentSettlements = array_slice($recentSettlements, 0, 10);
        
    } catch (PDOException $e) {
        logEvent("Błąd pobierania rozliczeń: " . $e->getMessage(), 'ERROR');
    }
}

// SEKCJA E) PORTFEL FIRMOWY - środki firmowe przekazane pracownikowi
$walletSummary = [
    'available' => 0.0,
    'open_count' => 0,
    'open_issued' => 0.0,
    'open_settled' => 0.0,
];
$walletOpenAdvances = [];
$walletRecentEntries = [];
$walletLastMovement = null;
$walletWorkerNames = [];
if ($currentTab === 'portfel') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                wa.id AS advance_id,
                wa.issue_date,
                wa.amount,
                wa.description,
                wa.status,
                COALESCE((
                    SELECT SUM(wl.amount)
                    FROM worker_ledger wl
                    WHERE wl.advance_id = wa.id
                      AND wl.amount > 0
                ), 0) AS amount_settled
            FROM worker_advances wa
            WHERE wa.worker_id = ?
              AND wa.type = 'COMPANY'
              AND wa.status = 'open'
            ORDER BY wa.issue_date DESC, wa.id DESC
        ");
        $stmt->execute([$workerId]);
        $walletOpenAdvances = $stmt->fetchAll();

        foreach ($walletOpenAdvances as &$walletAdvance) {
            $walletAdvance['amount_remaining'] = max(0, (float)$walletAdvance['amount'] - (float)$walletAdvance['amount_settled']);
            $walletSummary['available'] += (float)$walletAdvance['amount_remaining'];
            $walletSummary['open_count']++;
            $walletSummary['open_issued'] += (float)$walletAdvance['amount'];
            $walletSummary['open_settled'] += (float)$walletAdvance['amount_settled'];
        }
        unset($walletAdvance);

        $stmt = $pdo->prepare("
            SELECT
                wl.id,
                wl.entry_type,
                wl.amount,
                wl.entry_date,
                wl.description,
                wl.advance_id
            FROM worker_ledger wl
            INNER JOIN worker_advances wa ON wa.id = wl.advance_id
            WHERE wl.worker_id = ?
              AND wa.type = 'COMPANY'
            ORDER BY wl.entry_date DESC, wl.id DESC
            LIMIT 20
        ");
        $stmt->execute([$workerId]);
        $walletRecentEntries = $stmt->fetchAll();
        if (!empty($walletRecentEntries)) {
            $walletLastMovement = $walletRecentEntries[0];
        }

        $walletNameIds = walletExtractWorkerIdsFromDescriptions(array_merge(
            array_map(static fn(array $advance): string => (string)($advance['description'] ?? ''), $walletOpenAdvances),
            array_map(static fn(array $entry): string => (string)($entry['description'] ?? ''), $walletRecentEntries)
        ));
        $walletWorkerNames = walletGetWorkerDisplayNames($pdo, $walletNameIds);
    } catch (PDOException $e) {
        logEvent("Błąd pobierania portfela firmowego pracownika ID $workerId: " . $e->getMessage(), 'ERROR');
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Centrum: <?php echo e($workerName); ?></title>
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

        .container {
            max-width: 1500px;
            margin: 0 auto;
            padding: 25px;
        }

        /* Hero profilu pracownika */
        .profile-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .profile-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .profile-avatar {
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(255,255,255,0.15);
            border: 2px solid rgba(255,255,255,0.3);
            display: flex; align-items: center; justify-content: center;
            font-size: 30px; color: white; font-weight: 700; flex-shrink: 0;
        }
        .profile-details h1 { font-size: 28px; color: #fff; margin-bottom: 4px; letter-spacing: -0.3px; }
        .profile-details p { font-size: 14px; color: #cbd5e1; }
        .profile-header .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .profile-header .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .profile-header .hero-breadcrumb a:hover { text-decoration: underline; }
        .profile-status {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 600; margin-top: 6px;
        }
        .profile-status.active { background: rgba(34,197,94,0.15); color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
        .profile-status.inactive { background: rgba(239,68,68,0.15); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }

        .profile-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; }
        .btn {
            padding: 7px 14px; border-radius: 8px; text-decoration: none; font-size: 13px;
            font-weight: 600; cursor: pointer; border: 1px solid; transition: all 0.2s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary {
            background: #ffffff; color: #1e3a8a; border-color: #ffffff;
        }
        .btn-primary:hover { background: #e0e7ff; transform: translateY(-1px); }
        
        .btn-secondary {
            background: rgba(255,255,255,0.12);
            color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.25);
        }
        .btn-secondary:hover { background: rgba(255,255,255,0.2); color: #ffffff; transform: translateY(-1px); }

        .btn-danger {
            background: rgba(239,68,68,0.15);
            color: #fca5a5;
            border: 1px solid rgba(239,68,68,0.3);
        }
        .btn-danger:hover { background: rgba(239,68,68,0.25); color: #fecaca; }

        /* Zakładka Portfel — przyciski na białym tle (hero używa tych samych klas pod ciemnym nagłówkiem) */
        .profile-portfel .btn-primary {
            background: #1e3a8a;
            color: #ffffff;
            border-color: #1e3a8a;
        }
        .profile-portfel .btn-primary:hover {
            background: #172554;
            border-color: #172554;
            color: #ffffff;
        }

        .profile-portfel .btn-secondary {
            background: #ffffff;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        .profile-portfel .btn-secondary:hover {
            background: #f3f4f6;
            color: #111827;
            border-color: #9ca3af;
        }

        .profile-portfel .btn-danger {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .profile-portfel .btn-danger:hover {
            background: #fee2e2;
            color: #991b1b;
            border-color: #f87171;
        }
        
        /* Tabs */
        .tabs {
            background: white;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .tabs-nav {
            display: flex;
            border-bottom: 2px solid #f3f4f6;
            overflow-x: auto;
        }
        
        .tab-link {
            padding: 18px 25px;
            text-decoration: none;
            color: #666;
            font-weight: 600;
            font-size: 14px;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .tab-link:hover {
            color: #667eea;
            background: #f9fafb;
        }
        
        .tab-link.active {
            color: #667eea;
            border-bottom-color: #667eea;
            background: #faf5ff;
        }
        
        /* Tab content */
        .tab-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        /* Summary cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s;
        }
        
        .summary-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
        }
        
        .summary-card h3 {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .summary-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
        }
        
        .summary-card .value.positive {
            color: #22c55e;
        }
        
        .summary-card .value.negative {
            color: #ef4444;
        }
        
        .summary-card .sub {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 8px;
        }
        
        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        thead {
            background: #f9fafb;
        }
        
        th {
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 1px solid #000000;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 10px 14px !important;
            border: 1px solid #000000 !important;
            font-size: 13px !important;
            vertical-align: middle;
        }
        th {
            border: 1px solid #000000 !important;
        }
        /* Zebra-striping */
        tbody tr:nth-child(odd) {
            background: #ffffff !important;
        }
        tbody tr:nth-child(even) {
            background: #f8fafc !important;
        }
        tbody tr:hover {
            background: #e0f2fe !important;
        }
        
        .status-box {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
            border-left: 3px solid #f59e0b;
        }
        
        .status-approved {
            background: #d1fae5;
            color: #065f46;
            border-left: 3px solid #10b981;
        }
        
        .status-locked {
            background: #dbeafe;
            color: #1e40af;
            border-left: 3px solid #3b82f6;
        }
        
        .info-box {
            background: #f0f9ff;
            border-left: 4px solid #0284c7;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info-box p {
            color: #0c4a6e;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .link-action {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .link-action:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }

        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: white;
        }
        .table-scroll table {
            margin-top: 0;
            min-width: 860px;
        }
        
        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .tabs-nav {
                flex-wrap: wrap;
            }
            
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <?php if (isset($_GET['account_created']) && $_GET['account_created'] == '1'): ?>
        <div style="background:#d1fae5;border-left:4px solid #10b981;color:#065f46;padding:14px 18px;border-radius:8px;margin-bottom:20px;font-size:14px;font-weight:600;">
            ✅ Konto zostało pomyślnie utworzone. Pracownik może się teraz zalogować.
        </div>
        <?php endif; ?>

        <!-- Profile hero header -->
        <div class="profile-header">
            <div class="profile-info">
                <div class="profile-avatar">
                    <?php 
                    $initials = '';
                    if (!empty($worker['first_name'])) $initials .= mb_substr($worker['first_name'], 0, 1);
                    if (!empty($worker['last_name'])) $initials .= mb_substr($worker['last_name'], 0, 1);
                    echo e($initials ?: '?');
                    ?>
                </div>
                <div class="profile-details">
                    <div class="hero-breadcrumb"><a href="<?php echo url('hr'); ?>">Pracownicy</a> › Profil</div>
                    <h1><?php echo e($workerName); ?></h1>
                    <p>
                        <?php 
                        $typeLabels = [
                            'permanent' => 'Pracownik stały',
                            'temporary' => 'Pracownik tymczasowy',
                            'contractor' => 'Podwykonawca'
                        ];
                        echo e($typeLabels[$worker['worker_type']] ?? 'Pracownik');
                        ?>
                    </p>
                    <span class="profile-status <?php echo $worker['is_active'] ? 'active' : 'inactive'; ?>">
                        <?php echo $worker['is_active'] ? 'Aktywny' : 'Nieaktywny'; ?>
                    </span>
                </div>
            </div>
            <div class="profile-actions">
                <a href="<?php echo url('hr'); ?>?tab=new&worker_id=<?php echo $workerId; ?>" class="btn btn-primary">Rozlicz</a>
                <a href="<?php echo url('hr.workers.wallet', ['worker_id' => $workerId]); ?>" class="btn btn-secondary">Portfel</a>
                <a href="<?php echo url('hr.workers.report', ['worker_id' => $workerId]); ?>" class="btn btn-secondary">Raport</a>
                <?php if ($isAdmin): ?>
                <a href="<?php echo url('hr.workers.edit', ['id' => $workerId]); ?>" class="btn btn-secondary">Edytuj</a>
                <?php if (empty($worker['login'])): ?>
                <a href="<?php echo url('hr.workers.create-account', ['worker_id' => $workerId]); ?>"
                   class="btn btn-secondary"
                   title="Pracownik nie ma konta — kliknij aby nadać dostep do systemu">
                    Nadaj dostep
                </a>
                <?php endif; ?>
                <a href="<?php echo url('hr.workers'); ?>" class="btn btn-secondary">← Lista</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs">
            <div class="tabs-nav">
                <a href="<?php echo url('hr.workers.profile', ['id' => $workerId, 'tab' => 'podsumowanie']); ?>" 
                   class="tab-link <?php echo $currentTab === 'podsumowanie' ? 'active' : ''; ?>">
                    Podsumowanie
                </a>
                <a href="<?php echo url('hr.workers.profile', ['id' => $workerId, 'tab' => 'godziny']); ?>" 
                   class="tab-link <?php echo $currentTab === 'godziny' ? 'active' : ''; ?>">
                    Godziny
                </a>
                <a href="<?php echo url('hr.workers.profile', ['id' => $workerId, 'tab' => 'rozliczenia']); ?>" 
                   class="tab-link <?php echo $currentTab === 'rozliczenia' ? 'active' : ''; ?>">
                    Rozliczenia
                </a>
                <a href="<?php echo url('hr.workers.profile', ['id' => $workerId, 'tab' => 'portfel']); ?>" 
                   class="tab-link <?php echo $currentTab === 'portfel' ? 'active' : ''; ?>">
                    Portfel firmowy
                </a>
                <a href="<?php echo url('hr.workers.profile', ['id' => $workerId, 'tab' => 'powiadomienia']); ?>" 
                   class="tab-link <?php echo $currentTab === 'powiadomienia' ? 'active' : ''; ?>">
                    Powiadomienia
                </a>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="tab-content">
            <?php if ($currentTab === 'podsumowanie'): ?>
                <!-- SEKCJA A) PODSUMOWANIE -->
                <h2 style="margin-bottom: 25px; font-size: 24px;">Podsumowanie</h2>
                
                <div class="summary-grid">
                    <div class="summary-card">
                        <h3>Aktualne saldo</h3>
                        <div class="value <?php echo $summary['saldo'] >= 0 ? 'positive' : 'negative'; ?>">
                            <?php echo formatMoney($summary['saldo']); ?>
                        </div>
                        <div class="sub">
                            <?php if ($summary['saldo'] > 0): ?>
                                Firma jest winna pracownikowi
                            <?php elseif ($summary['saldo'] < 0): ?>
                                Pracownik jest winny firmie
                            <?php else: ?>
                                Rozliczone
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="summary-card">
                        <h3>Godziny oczekujące</h3>
                        <div class="value"><?php echo number_format($summary['pending_hours'], 2); ?>h</div>
                        <div class="sub">Do zatwierdzenia</div>
                    </div>
                    
                    <div class="summary-card">
                        <h3>Godziny zatwierdzone</h3>
                        <div class="value"><?php echo number_format($summary['approved_hours_month'], 2); ?>h</div>
                        <div class="sub">W bieżącym miesiącu</div>
                    </div>
                    
                    <?php if ($summary['last_payout']): ?>
                    <div class="summary-card">
                        <h3>Ostatnia <?php echo $summary['last_payout']['type'] === 'payout' ? 'wypłata' : 'zaliczka'; ?></h3>
                        <div class="value"><?php echo formatMoney($summary['last_payout']['amount']); ?></div>
                        <div class="sub">
                            <?php echo formatDate($summary['last_payout']['date']); ?>
                            <?php if ($summary['last_payout']['description']): ?>
                                - <?php echo e($summary['last_payout']['description']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($currentTab === 'godziny'): ?>
                <!-- SEKCJA B) GODZINY (PODGLĄD) -->
                <h2 style="margin-bottom: 25px; font-size: 24px;">Ostatnie wpisy pracy</h2>
                
                <?php if (empty($recentWorkLogs)): ?>
                    <p style="text-align: center; color: #9ca3af; padding: 40px;">Brak wpisów pracy dla tego pracownika.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Projekt</th>
                                <th>Etap</th>
                                <th>Typ</th>
                                <th>Czas</th>
                                <th>Nadgodziny</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentWorkLogs as $log): ?>
                                <?php 
                                    $workType = $log['work_type'] ?? 'work';
                                    $isWork = ($workType === 'work');
                                    $isVacation = ($workType === 'vacation');
                                    $isSick = ($workType === 'sick');
                                ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo formatDate($log['date']); ?></td>
                                    <td><?php echo $log['project_name'] ? e($log['project_name']) : '-'; ?></td>
                                    <td style="font-size: 13px; color: #666;"><?php echo $log['cost_node_name'] ? e($log['cost_node_name']) : '-'; ?></td>
                                    <td style="font-weight: 600;">
                                        <?php if ($isVacation): ?>
                                            <span style="color: #22c55e;">Urlop</span>
                                        <?php elseif ($isSick): ?>
                                            <span style="color: #ef4444;">L4</span>
                                        <?php else: ?>
                                            <span style="color: #6b7280;">Praca</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $isWork ? number_format($log['hours'], 2) . 'h' : number_format(normalizeAbsenceDays($log), 1, ',', ' ') . ' dni'; ?></td>
                                    <td><?php echo $isWork ? number_format($log['overtime_hours'], 2) . 'h' : '-'; ?></td>
                                    <td>
                                        <?php if ($log['status'] === 'pending'): ?>
                                            <span class="status-box status-pending">Oczekuje</span>
                                        <?php elseif ($log['status'] === 'approved'): ?>
                                            <span class="status-box status-approved">Zatwierdzony</span>
                                        <?php else: ?>
                                            <span class="status-box status-locked">Zablokowany</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <div style="margin-top:16px;">
                    <a href="<?php echo url('hr.worklog.index'); ?>?worker=<?php echo $workerId; ?>" class="btn btn-secondary" style="font-size:13px; padding:8px 16px;">
                        Dziennik pracy tego pracownika
                    </a>
                </div>

            <?php elseif ($currentTab === 'rozliczenia'): ?>
                <!-- SEKCJA C) ROZLICZENIA -->
                <h2 style="margin-bottom: 25px; font-size: 24px;">Ostatnie rozliczenia</h2>
                
                <?php if (empty($recentSettlements)): ?>
                    <p style="text-align: center; color: #9ca3af; padding: 40px;">Brak rozliczeń dla tego pracownika.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Typ</th>
                                <th>Kwota</th>
                                <th>Opis</th>
                                <?php if ($isAdmin): ?>
                                    <th style="width: 280px; text-align: center;">Akcje</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentSettlements as $settlement): ?>
                                <?php
                                // Określ źródło i typ
                                $source = $settlement['source'] ?? 'settlement';
                                
                                if ($source === 'new_advance') {
                                    // Nowa zaliczka z worker_advances
                                    $advanceTypeLabel = ($settlement['advance_type'] === 'PRIVATE') ? 'prywatna' : 'firmowa';
                                    $statusLabel = ($settlement['status'] === 'open') ? ' [OTWARTA]' : ' [ZAMKNIĘTA]';
                                    $typeLabel = 'Zaliczka (' . $advanceTypeLabel . ')' . $statusLabel;
                                    $isNegative = true;
                                    $rowBg = '#fff7ed';
                                    $rowBorder = '#f97316';
                                } else {
                                    // Stare rozliczenie z settlements
                                    $typeLabels = [
                                        'payout' => 'Wypłata',
                                        'advance' => 'Zaliczka',
                                        'reimbursement' => 'Zwrot kosztów',
                                        'bonus' => 'Premia',
                                        'correction' => 'Korekta'
                                    ];
                                    $typeLabel = $typeLabels[$settlement['type']] ?? $settlement['type'];
                                    $isNegative = in_array($settlement['type'], ['payout', 'advance']);

                                    if ($settlement['type'] === 'payout') {
                                        $rowBg = '#fef2f2';
                                        $rowBorder = '#ef4444';
                                    } elseif ($settlement['type'] === 'advance') {
                                        $rowBg = '#fff7ed';
                                        $rowBorder = '#f59e0b';
                                    } elseif ($settlement['type'] === 'reimbursement') {
                                        $rowBg = '#eff6ff';
                                        $rowBorder = '#3b82f6';
                                    } elseif ($settlement['type'] === 'bonus') {
                                        $rowBg = '#f0fdf4';
                                        $rowBorder = '#22c55e';
                                    } elseif ($settlement['type'] === 'correction') {
                                        $rowBg = '#f5f3ff';
                                        $rowBorder = '#8b5cf6';
                                    } else {
                                        $rowBg = '#ffffff';
                                        $rowBorder = '#9ca3af';
                                    }
                                }
                                ?>
                                <tr style="background: <?php echo e($rowBg); ?>; border-left: 4px solid <?php echo e($rowBorder); ?>;">
                                    <td style="font-weight: 600;"><?php echo formatDate($settlement['date']); ?></td>
                                    <td><?php echo e($typeLabel); ?></td>
                                    <td style="font-weight: 600; color: <?php echo $isNegative ? '#ef4444' : '#22c55e'; ?>;">
                                        <?php echo formatMoney($settlement['amount']); ?>
                                    </td>
                                    <td style="color: #666;"><?php echo $settlement['description'] ? e($settlement['description']) : '-'; ?></td>
                                    <?php if ($isAdmin): ?>
                                        <td style="text-align: center;">
                                            <?php if ($source === 'new_advance'): ?>
                                                <!-- NOWA ZALICZKA -->
                                                <div style="display: flex; gap: 6px; justify-content: center; align-items: center; flex-wrap: wrap;">
                                                    <a href="<?php echo url('finanse.zaliczki.view', ['id' => $settlement['id']]); ?>" 
                                                       class="btn btn-secondary"
                                                       style="padding: 6px 10px; font-size: 12px;">
                                                        Zobacz
                                                    </a>
                                                    <a href="<?php echo url('finanse.zaliczki.edit', ['id' => $settlement['id']]); ?>" 
                                                       class="btn btn-secondary"
                                                       style="padding: 6px 10px; font-size: 12px;">
                                                        Edytuj
                                                    </a>
                                                    <form method="POST" action="<?php echo url('finanse.zaliczki.delete'); ?>" style="display: inline;" onsubmit="return confirm('Czy na pewno chcesz usunąć tę zaliczkę?');">
                                                        <input type="hidden" name="advance_id" value="<?php echo (int)$settlement['id']; ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding: 6px 10px; font-size: 12px;">Usuń</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <!-- STARE ROZLICZENIE -->
                                                <div style="display: flex; gap: 6px; justify-content: center; align-items: center; flex-wrap: wrap;">
                                                    <a href="<?php echo url('finanse.rozliczenia.edit', ['id' => $settlement['id']]); ?>" 
                                                       class="btn btn-secondary"
                                                       style="padding: 6px 10px; font-size: 12px;">
                                                        Edytuj
                                                    </a>
                                                    <a href="<?php echo url('finanse.rozliczenia.delete', ['id' => $settlement['id']]); ?>" 
                                                       class="btn btn-danger"
                                                       style="padding: 6px 10px; font-size: 12px;"
                                                       onclick="return confirm('Czy na pewno chcesz usunąć to rozliczenie?');">
                                                        Usuń
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <div style="display:flex; gap:10px; margin-top:16px; flex-wrap:wrap;">
                    <a href="<?php echo url('hr'); ?>?tab=history&worker_id=<?php echo $workerId; ?>" class="btn btn-primary" style="font-size:13px; padding:8px 16px;">
                        Historia operacji
                    </a>
                    <a href="<?php echo url('hr.workers.balance', ['worker_id' => $workerId]); ?>" class="btn btn-secondary" style="font-size:13px; padding:8px 16px;">
                        Szczegółowe saldo
                    </a>
                </div>

            <?php elseif ($currentTab === 'portfel'): ?>
                <div class="profile-portfel">
                <!-- SEKCJA E) PORTFEL FIRMOWY -->
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 25px;">
                    <h2 style="font-size: 24px; margin: 0;">Portfel firmowy</h2>
                    <?php if ($isAdmin): ?>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <a href="<?php echo url('hr'); ?>?tab=new&worker_id=<?php echo $workerId; ?>&op_type=advance_company" class="btn btn-primary">
                                Zasil portfel
                            </a>
                            <a href="<?php echo url('hr'); ?>?tab=new&worker_id=<?php echo $workerId; ?>&op_type=advance_private" class="btn btn-secondary">
                                Zaliczka prywatna
                            </a>
                            <a href="<?php echo url('finanse.zaliczki.transfer', ['from_worker_id' => (int)$workerId]); ?>" class="btn btn-secondary">
                                Przekaż środki
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="summary-grid">
                    <div class="summary-card">
                        <h3>Dostępne środki</h3>
                        <div class="value"><?php echo formatMoney($walletSummary['available']); ?></div>
                        <div class="sub">Suma pozostała do wydania</div>
                    </div>
                    <div class="summary-card">
                        <h3>Otwarte zaliczki firmowe</h3>
                        <div class="value"><?php echo (int)$walletSummary['open_count']; ?></div>
                        <div class="sub">Aktywne pozycje portfela</div>
                    </div>
                    <div class="summary-card">
                        <h3>Ostatni ruch</h3>
                        <?php if ($walletLastMovement): ?>
                            <?php
                                $walletLastDelta = -1 * (float)$walletLastMovement['amount'];
                                $walletLastLabel = walletResolveEntryLabel($walletLastMovement);
                            ?>
                            <div class="value" style="color: <?php echo $walletLastDelta >= 0 ? '#16a34a' : '#dc2626'; ?>;">
                                <?php echo ($walletLastDelta >= 0 ? '+' : '') . formatMoney($walletLastDelta); ?>
                            </div>
                            <div class="sub"><?php echo formatDate($walletLastMovement['entry_date']); ?> • <?php echo e($walletLastLabel); ?></div>
                        <?php else: ?>
                            <div class="value">-</div>
                            <div class="sub">Brak ruchów portfela</div>
                        <?php endif; ?>
                    </div>
                </div>

                <h3 style="margin: 10px 0 15px; font-size: 18px;">Rozbicie księgowe portfela</h3>
                <?php if (empty($walletOpenAdvances)): ?>
                    <p style="text-align: center; color: #9ca3af; padding: 25px 0;">Brak otwartych pozycji księgowych portfela.</p>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Data</th>
                                    <th>Kwota</th>
                                    <th>Rozliczono</th>
                                    <th>Pozostało</th>
                                    <th>Opis</th>
                                    <?php if ($isAdmin): ?>
                                        <th style="width: 360px;">Akcje</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($walletOpenAdvances as $walletAdvance): ?>
                                    <tr>
                                        <td>#<?php echo (int)$walletAdvance['advance_id']; ?></td>
                                        <td><?php echo formatDate($walletAdvance['issue_date']); ?></td>
                                        <td><?php echo formatMoney($walletAdvance['amount']); ?></td>
                                        <td><?php echo formatMoney($walletAdvance['amount_settled']); ?></td>
                                        <td style="font-weight: 700; color: #1e40af;"><?php echo formatMoney($walletAdvance['amount_remaining']); ?></td>
                                        <td><?php echo !empty($walletAdvance['description']) ? e(walletHumanizeDescription((string)$walletAdvance['description'], $walletWorkerNames)) : '-'; ?></td>
                                        <?php if ($isAdmin): ?>
                                            <td>
                                                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                                    <a href="<?php echo url('finanse.zaliczki.view', ['id' => $walletAdvance['advance_id']]); ?>" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;">
                                                        Zobacz
                                                    </a>
                                                    <a href="<?php echo url('finanse.zaliczki.edit', ['id' => $walletAdvance['advance_id']]); ?>" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;">
                                                        Edytuj
                                                    </a>
                                                    <a href="<?php echo url('finanse.zaliczki.transfer', ['from_worker_id' => (int)$workerId]); ?>" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;">
                                                        Przekaż
                                                    </a>
                                                    <form method="POST" action="<?php echo url('finanse.zaliczki.delete'); ?>" style="display: inline;" onsubmit="return confirm('Czy na pewno chcesz usunąć tę zaliczkę?');">
                                                        <input type="hidden" name="advance_id" value="<?php echo (int)$walletAdvance['advance_id']; ?>">
                                                        <button type="submit" class="btn btn-danger" style="padding: 6px 10px; font-size: 12px;">Usuń</button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <h3 style="margin: 30px 0 15px; font-size: 18px;">Ostatnie ruchy portfela</h3>
                <?php if (empty($walletRecentEntries)): ?>
                    <p style="text-align: center; color: #9ca3af; padding: 20px 0;">Brak ruchów portfela firmowego.</p>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Typ ruchu</th>
                                    <th>Kwota ruchu</th>
                                    <th>Opis</th>
                                    <th>Zaliczka</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($walletRecentEntries as $entry): ?>
                                    <?php
                                        $entryLabel = walletResolveEntryLabel($entry);
                                        $walletDelta = -1 * (float)$entry['amount'];
                                    ?>
                                    <tr>
                                        <td><?php echo formatDate($entry['entry_date']); ?></td>
                                        <td><?php echo e($entryLabel); ?></td>
                                        <td style="font-weight: 700; color: <?php echo $walletDelta >= 0 ? '#16a34a' : '#dc2626'; ?>;">
                                            <?php echo ($walletDelta >= 0 ? '+' : '') . formatMoney($walletDelta); ?>
                                        </td>
                                        <td><?php echo !empty($entry['description']) ? e(walletHumanizeDescription((string)$entry['description'], $walletWorkerNames)) : '-'; ?></td>
                                        <td>#<?php echo (int)$entry['advance_id']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                </div><!-- .profile-portfel -->

            <?php elseif ($currentTab === 'powiadomienia'): ?>
                <!-- SEKCJA D) POWIADOMIENIA PUSH -->
                <h2 style="margin-bottom: 25px; font-size: 24px;">Powiadomienia Push</h2>
                
                <div class="summary-grid" style="max-width: 600px;">
                    <div class="summary-card" style="grid-column: 1 / -1;">
                        <h3>Status powiadomień</h3>
                        <div id="push-status" style="margin: 15px 0; padding: 15px; border-radius: 8px; background: #f3f4f6;">
                            <p style="margin: 0; color: #666;">Sprawdzanie dostępności...</p>
                        </div>
                        <button id="btn-enable-push" class="btn btn-primary" style="display: none; margin-top: 10px;" onclick="subscribePush()">
                            Włącz powiadomienia
                        </button>
                        <button id="btn-disable-push" class="btn btn-danger" style="display: none; margin-top: 10px;" onclick="unsubscribePush()">
                            Wyłącz powiadomienia
                        </button>
                    </div>
                </div>
                
                <div class="info-box" style="margin-top: 20px; max-width: 600px;">
                    <p><strong>Co to są powiadomienia Push?</strong></p>
                    <p>Powiadomienia push pozwalają otrzymywać informacje o nowych zadaniach, zmianach w grafiku lub ważnych komunikatach bez konieczności ciągłego sprawdzania aplikacji. Powiadomienia będą wyświetlane nawet gdy aplikacja jest zamknięta.</p>
                </div>

                <script>
                // Rejestruj Service Worker dla powiadomień push
                if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.register('/sw.js')
                        .then(function(registration) {
                            console.log('[Push] Service Worker zarejestrowany');
                        })
                        .catch(function(err) {
                            console.error('[Push] Błąd rejestracji SW:', err);
                        });
                }
                
                // Sprawdź status powiadomień przy załadowaniu strony
                document.addEventListener('DOMContentLoaded', function() {
                    checkPushStatus();
                });

                function checkPushStatus() {
                    const statusEl = document.getElementById('push-status');
                    const btnEnable = document.getElementById('btn-enable-push');
                    const btnDisable = document.getElementById('btn-disable-push');
                    
                    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                        statusEl.innerHTML = '<p style="margin: 0; color: #dc2626;"><strong>Przeglądarka nie obsługuje powiadomień push</strong></p>';
                        statusEl.style.background = '#fee2e2';
                        return;
                    }
                    
                    navigator.serviceWorker.ready.then(function(registration) {
                        return registration.pushManager.getSubscription();
                    }).then(function(subscription) {
                        if (subscription) {
                            statusEl.innerHTML = '<p style="margin: 0; color: #059669;"><strong>Powiadomienia są włączone</strong></p><p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">Otrzymasz powiadomienia o nowych zadaniach i zmianach</p>';
                            statusEl.style.background = '#d1fae5';
                            btnDisable.style.display = 'inline-block';
                            btnEnable.style.display = 'none';
                        } else {
                            statusEl.innerHTML = '<p style="margin: 0; color: #92400e;"><strong>Powiadomienia są wyłączone</strong></p><p style="margin: 5px 0 0 0; font-size: 13px; color: #666;">Włącz powiadomienia, aby być na bieżąco</p>';
                            statusEl.style.background = '#fef3c7';
                            btnEnable.style.display = 'inline-block';
                            btnDisable.style.display = 'none';
                        }
                    }).catch(function(err) {
                        statusEl.innerHTML = '<p style="margin: 0; color: #dc2626;"><strong>Błąd:</strong> ' + err.message + '</p>';
                        statusEl.style.background = '#fee2e2';
                    });
                }

                function urlBase64ToUint8Array(base64String) {
                    const padding = '='.repeat((4 - base64String.length % 4) % 4);
                    const base64 = (base64String + padding)
                        .replace(/\-/g, '+')
                        .replace(/_/g, '/');
                    const rawData = window.atob(base64);
                    const outputArray = new Uint8Array(rawData.length);
                    for (let i = 0; i < rawData.length; ++i) {
                        outputArray[i] = rawData.charCodeAt(i);
                    }
                    return outputArray;
                }

                function subscribePush() {
                    const btnEnable = document.getElementById('btn-enable-push');
                    btnEnable.disabled = true;
                    btnEnable.textContent = 'Włączanie...';
                    
                    // Pobierz klucz publiczny VAPID z serwera
                    fetch('<?php echo url("api.push.key"); ?>')
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            if (!data.publicKey) {
                                throw new Error('Brak klucza publicznego VAPID');
                            }
                            return navigator.serviceWorker.ready.then(function(registration) {
                                return registration.pushManager.subscribe({
                                    userVisibleOnly: true,
                                    applicationServerKey: urlBase64ToUint8Array(data.publicKey)
                                });
                            });
                        })
                        .then(function(subscription) {
                            // Wyślij subskrypcję na serwer
                            return fetch('<?php echo url("api.push.subscribe"); ?>', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    subscription: subscription.toJSON(),
                                    worker_id: <?php echo $workerId; ?>
                                })
                            });
                        })
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            if (data.success) {
                                checkPushStatus();
                            } else {
                                throw new Error(data.error || 'Błąd subskrypcji');
                            }
                        })
                        .catch(function(err) {
                            alert('Błąd: ' + err.message);
                            btnEnable.disabled = false;
                            btnEnable.textContent = 'Włącz powiadomienia';
                        });
                }

                function unsubscribePush() {
                    const btnDisable = document.getElementById('btn-disable-push');
                    btnDisable.disabled = true;
                    btnDisable.textContent = 'Wyłączanie...';
                    
                    navigator.serviceWorker.ready.then(function(registration) {
                        return registration.pushManager.getSubscription();
                    }).then(function(subscription) {
                        if (subscription) {
                            return subscription.unsubscribe().then(function() {
                                return fetch('<?php echo url("api.push.unsubscribe"); ?>', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({
                                        endpoint: subscription.endpoint,
                                        worker_id: <?php echo $workerId; ?>
                                    })
                                });
                            });
                        }
                    })
                    .then(function() {
                        checkPushStatus();
                    })
                    .catch(function(err) {
                        alert('Błąd: ' + err.message);
                        btnDisable.disabled = false;
                        btnDisable.textContent = 'Wyłącz powiadomienia';
                    });
                }
                </script>

            <?php else: ?>
                <p>Nieznana zakładka.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <footer style="text-align: center; padding: 20px; color: #999; font-size: 13px;">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
