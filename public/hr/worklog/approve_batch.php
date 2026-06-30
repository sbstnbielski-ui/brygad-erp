<?php
/**
 * BRYGAD ERP v3.0 - Masowe zatwierdzanie wpisów pracy
 * Admin zaznacza wpisy, edytuje kwoty (premia, korekta) i zatwierdza wszystkie naraz.
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/rate_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo    = getDbConnection();
$errors = [];
$successCount = 0;

// Opcjonalny filtr dnia (z przycisku w dzienniku pracy)
$filterDate   = isset($_GET['date'])        ? trim($_GET['date'])        : '';
$filterWorker = isset($_GET['worker'])      ? (int)$_GET['worker']       : 0;
$filterProject = isset($_GET['project'])    ? (int)$_GET['project']      : 0;

// ─── POST: zatwierdzenie zaznaczonych ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedIds = $_POST['selected_ids'] ?? [];
    $finalCosts  = $_POST['final_cost']   ?? [];

    if (empty($selectedIds)) {
        $errors[] = 'Nie zaznaczono zadnych wpisow do zatwierdzenia.';
    } else {
        $pdo->beginTransaction();
        try {
            foreach ($selectedIds as $rawId) {
                $logId = (int)$rawId;
                if ($logId <= 0) continue;

                // Pobierz wpis (bezpieczenstwo - sprawdź status)
                $stmt = $pdo->prepare("
                    SELECT wl.*, w.first_name, w.last_name
                    FROM work_logs wl
                    INNER JOIN workers w ON wl.worker_id = w.id
                    WHERE wl.id = ? AND wl.status = 'pending'
                ");
                $stmt->execute([$logId]);
                $log = $stmt->fetch();
                if (!$log) continue; // pominięty (nie pending lub nie istnieje)

                // Pobierz kwotę końcową z formularza
                $finalCostRaw = $finalCosts[$logId] ?? '';
                if ($finalCostRaw === '' || !is_numeric($finalCostRaw) || (float)$finalCostRaw < 0) {
                    $errors[] = 'Nieprawidlowa kwota dla: ' . e($log['first_name'] . ' ' . $log['last_name']);
                    continue;
                }
                $finalCost = (float)$finalCostRaw;

                // Oblicz system_cost i rate_snapshot
                $rate       = getWorkerRate(
                    $pdo,
                    (int)$log['worker_id'],
                    $log['project_id'] ? (int)$log['project_id'] : null,
                    $log['date'],
                    $log['cost_node_id'] ? (int)$log['cost_node_id'] : null
                );
                $costData   = calculateSuggestedCost($log, $rate);

                $stmt = $pdo->prepare("
                    UPDATE work_logs
                    SET system_rate_snapshot  = ?,
                        system_cost           = ?,
                        final_cost            = ?,
                        status                = 'approved',
                        approved_by_user_id   = ?,
                        approved_at           = NOW()
                    WHERE id = ? AND status = 'pending'
                ");
                $stmt->execute([
                    $costData['system_rate_snapshot'],
                    $costData['suggested'],
                    $finalCost,
                    $_SESSION['user_id'],
                    $logId,
                ]);

                if ($stmt->rowCount() > 0) {
                    $successCount++;
                    logEvent("Batch approve: wpis ID $logId, pracownik {$log['first_name']} {$log['last_name']}, koszt: $finalCost PLN", 'INFO');
                }
            }

            if (empty($errors)) {
                $pdo->commit();
                $_SESSION['success'] = "Zatwierdzono $successCount " . ($successCount == 1 ? 'wpis' : ($successCount < 5 ? 'wpisy' : 'wpisow')) . '.';
                header('Location: ' . url('hr.worklog'));
                exit;
            } else {
                $pdo->rollBack();
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Blad bazy danych: ' . $e->getMessage();
            logEvent('Batch approve error: ' . $e->getMessage(), 'ERROR');
        }
    }
}

// ─── GET: pobierz wszystkie pending wpisy ─────────────────────────────────
$where  = ["wl.status = 'pending'"];
$params = [];

if (!empty($filterDate)) {
    $where[]  = "wl.date = ?";
    $params[] = $filterDate;
}
if ($filterWorker > 0) {
    $where[]  = "wl.worker_id = ?";
    $params[] = $filterWorker;
}
if ($filterProject > 0) {
    $where[]  = "wl.project_id = ?";
    $params[] = $filterProject;
}

try {
    $sql = "
        SELECT
            wl.*,
            w.first_name,
            w.last_name,
            p.name  AS project_name,
            pcn.name AS cost_node_name
        FROM work_logs wl
        INNER JOIN workers w  ON wl.worker_id  = w.id
        LEFT  JOIN projects p ON wl.project_id = p.id
        LEFT  JOIN project_cost_nodes pcn ON wl.cost_node_id = pcn.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY wl.date DESC, w.last_name, w.first_name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent('Batch approve fetch error: ' . $e->getMessage(), 'ERROR');
    $logs = [];
}

// Oblicz sugerowane koszty dla każdego wpisu
$logData = [];
foreach ($logs as $log) {
    $rate     = getWorkerRate(
        $pdo,
        (int)$log['worker_id'],
        $log['project_id'] ? (int)$log['project_id'] : null,
        $log['date'],
        $log['cost_node_id'] ? (int)$log['cost_node_id'] : null
    );
    $costData = calculateSuggestedCost($log, $rate);
    $logData[] = [
        'log'      => $log,
        'rate'     => $rate,
        'costData' => $costData,
    ];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$totalPending = count($logs);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Zatwierdz wpisy pracy</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #764ba2;
            --bg-body: #f5f7fa;
            --bg-card: #ffffff;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #d97706;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            line-height: 1.5;
            padding-bottom: 40px;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 25px; }

        .breadcrumb { margin-bottom: 20px; color: var(--text-muted); font-size: 13px; }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb a:hover { text-decoration: underline; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-header h2 { font-size: 24px; font-weight: 700; color: var(--text-main); }
        .page-header .header-meta { font-size: 13px; color: var(--text-muted); margin-top: 4px; }

        .btn {
            padding: 0 20px;
            height: 38px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 13px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }

        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error { background: #fef2f2; border-left: 4px solid var(--danger); color: #991b1b; }
        .alert-error ul { margin: 8px 0 0 18px; }
        .alert-warning { background: #fffbeb; border-left: 4px solid var(--warning); color: #92400e; }

        /* Toolbar zaznaczania */
        .select-toolbar {
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px 10px 0 0;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .select-toolbar-left { display: flex; gap: 10px; align-items: center; }
        .select-toolbar-right { display: flex; gap: 10px; align-items: center; }

        .select-count {
            font-size: 13px;
            color: var(--text-muted);
        }
        .select-count strong { color: var(--text-main); }

        .btn-select-all, .btn-deselect-all {
            padding: 0 14px;
            height: 32px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-select-all:hover  { border-color: var(--primary); color: var(--primary); }
        .btn-deselect-all:hover { border-color: var(--danger); color: var(--danger); }

        /* Tabela */
        .table-wrapper {
            background: white;
            border: 1px solid var(--border);
            border-top: none;
            border-radius: 0 0 10px 10px;
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f9fafb; }
        th {
            padding: 10px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            white-space: nowrap;
        }
        th:last-child { border-right: none; }
        td {
            padding: 11px 14px;
            font-size: 13px;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            vertical-align: middle;
        }
        td:last-child { border-right: none; }
        tbody tr:last-child td { border-bottom: none; }

        /* Zaznaczony wiersz */
        tbody tr.row-selected { background: rgba(102, 126, 234, 0.05); }
        tbody tr:hover { background: #fafbff; }
        tbody tr.row-selected:hover { background: rgba(102, 126, 234, 0.08); }

        /* Kolumna checkbox */
        .col-check { width: 46px; text-align: center; }
        .col-check input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        /* Kolumna pracownik */
        .worker-name { font-weight: 600; color: var(--text-main); }
        .worker-date { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

        /* Kolumna projekt */
        .project-name { font-weight: 500; }
        .cost-node-name { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

        /* Kolumna godziny */
        .col-hours { text-align: right; font-variant-numeric: tabular-nums; }
        .hours-detail { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

        /* Kolumna koszt systemowy */
        .col-sys-cost { text-align: right; font-variant-numeric: tabular-nums; }
        .rate-info { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
        .no-rate-badge {
            display: inline-block;
            padding: 2px 8px;
            background: #fef3c7;
            color: #92400e;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Kolumna koszt końcowy — edytowalne pole */
        .col-final-cost { width: 140px; }
        .final-cost-input {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 5px;
            font-size: 13px;
            font-family: inherit;
            text-align: right;
            color: var(--text-main);
            transition: border-color 0.15s;
        }
        .final-cost-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        .final-cost-input.modified {
            border-color: #d97706;
            background: #fffbeb;
        }

        /* Badge typ pracy */
        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-work     { background: #f3f4f6; color: #4b5563; }
        .badge-sick     { background: #fee2e2; color: #991b1b; }
        .badge-vacation { background: #dcfce7; color: #166534; }

        /* Stan pusty */
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
        }
        .no-data .no-data-icon {
            font-size: 40px;
            margin-bottom: 12px;
            color: #d1d5db;
        }

        /* Pasek dolny (zatwierdzenie) */
        .approve-footer {
            position: sticky;
            bottom: 0;
            background: white;
            border-top: 2px solid var(--border);
            padding: 16px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.06);
            z-index: 100;
        }
        .approve-footer-info { font-size: 13px; color: var(--text-muted); }
        .approve-footer-info strong { color: var(--text-main); font-size: 15px; }

        .footer { text-align: center; padding: 20px; color: var(--text-muted); font-size: 12px; }

        @media (max-width: 900px) {
            .container { padding: 15px; }
            .page-header h2 { font-size: 20px; }
            th:nth-child(5), td:nth-child(5),
            th:nth-child(6), td:nth-child(6) { display: none; }
        }
        @media (max-width: 600px) {
            th:nth-child(4), td:nth-child(4) { display: none; }
            .approve-footer { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Glowny</a> /
            <a href="<?php echo url('hr.worklog'); ?>">Dziennik Pracy</a> /
            Zatwierdz wpisy
        </div>

        <div class="page-header">
            <div>
                <h2>Zatwierdzanie wpisow pracy</h2>
                <div class="header-meta">
                    <?php if ($totalPending > 0): ?>
                        <?php echo $totalPending; ?> <?php echo $totalPending == 1 ? 'wpis' : ($totalPending < 5 ? 'wpisy' : 'wpisow'); ?> oczekuje na zatwierdzenie
                        <?php if (!empty($filterDate)): ?>
                            &mdash; dzien: <strong><?php echo formatDate($filterDate); ?></strong>
                        <?php endif; ?>
                    <?php else: ?>
                        Brak wpisow oczekujacych na zatwierdzenie
                    <?php endif; ?>
                </div>
            </div>
            <a href="<?php echo url('hr.worklog'); ?>" class="btn btn-secondary">Wróc do dziennika</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Wystapil blad:</strong>
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo e($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($logs)): ?>
            <div class="table-wrapper" style="border-radius: 10px;">
                <div class="no-data">
                    <div class="no-data-icon">&#10003;</div>
                    <strong>Wszystkie wpisy sa zatwierdzone</strong><br>
                    Brak wpisow ze statusem "Oczekujacy".
                </div>
            </div>
        <?php else: ?>

        <form method="POST" action="" id="batchForm">
            <!-- Toolbar -->
            <div class="select-toolbar">
                <div class="select-toolbar-left">
                    <button type="button" class="btn-select-all" onclick="selectAll()">Zaznacz wszystkie</button>
                    <button type="button" class="btn-deselect-all" onclick="deselectAll()">Odznacz wszystkie</button>
                    <span class="select-count">Zaznaczono: <strong id="selectedCount">0</strong> z <?php echo $totalPending; ?></span>
                </div>
                <div class="select-toolbar-right">
                    <span style="font-size: 12px; color: var(--text-muted);">
                        Mozesz zmienic kwote przed zatwierdzeniem
                    </span>
                </div>
            </div>

            <!-- Tabela -->
            <div class="table-wrapper">
                <?php if (empty($logData)): ?>
                    <div class="no-data">Brak wpisow.</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th class="col-check">
                                <input type="checkbox" id="checkAll" onchange="toggleAll(this)"
                                       title="Zaznacz / odznacz wszystkie">
                            </th>
                            <th>Pracownik</th>
                            <th>Projekt / Etap</th>
                            <th>Typ</th>
                            <th style="text-align:right;">Czas</th>
                            <th style="text-align:right;">Koszt systemowy</th>
                            <th style="text-align:right;">Koszt koncowy</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logData as $entry):
                            $log      = $entry['log'];
                            $costData = $entry['costData'];
                            $hasRate  = ($entry['rate'] !== null && !empty($entry['rate']['base_rate']));
                            $workType = $log['work_type'] ?? 'work';
                            $isSick   = $workType === 'sick';
                            $isVac    = $workType === 'vacation';
                            $isWork   = $workType === 'work';

                            // Badge
                            if ($isSick)     { $badgeClass = 'badge-sick';     $badgeLabel = 'L4'; }
                            elseif ($isVac)  { $badgeClass = 'badge-vacation'; $badgeLabel = 'Urlop'; }
                            else             { $badgeClass = 'badge-work';     $badgeLabel = 'Praca'; }

                            // Sugerowana kwota końcowa
                            $suggestedFinal = $hasRate ? number_format($costData['suggested'], 2, '.', '') : '0.00';

                            // Godziny do wyświetlenia
                            $hours = (float)($log['hours'] ?? 0);
                            $overtime = (float)($log['overtime_hours'] ?? 0);
                            $absenceDays = normalizeAbsenceDays($log);
                        ?>
                        <tr data-log-id="<?php echo $log['id']; ?>">
                            <td class="col-check">
                                <input type="checkbox"
                                       name="selected_ids[]"
                                       value="<?php echo $log['id']; ?>"
                                       class="row-checkbox"
                                       onchange="onCheckboxChange(this)">
                            </td>
                            <td>
                                <div class="worker-name"><?php echo e($log['first_name'] . ' ' . $log['last_name']); ?></div>
                                <div class="worker-date"><?php echo formatDate($log['date']); ?></div>
                            </td>
                            <td>
                                <?php if ($isSick || $isVac): ?>
                                    <span style="color: var(--text-muted); font-style: italic;">
                                        <?php echo $isSick ? 'Zwolnienie lekarskie' : 'Urlop wypoczynkowy'; ?>
                                    </span>
                                <?php elseif ($log['project_name']): ?>
                                    <div class="project-name"><?php echo e($log['project_name']); ?></div>
                                    <?php if ($log['cost_node_name']): ?>
                                        <div class="cost-node-name"><?php echo e($log['cost_node_name']); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">Brak projektu</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
                            </td>
                            <td class="col-hours">
                                <?php if ($isWork): ?>
                                    <?php echo number_format($hours, 2, ',', ''); ?>
                                    <?php if ($overtime > 0): ?>
                                        <div class="hours-detail">+<?php echo number_format($overtime, 2, ',', ''); ?> nadg.</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php echo number_format($absenceDays, 1, ',', ''); ?> dni
                                <?php endif; ?>
                            </td>
                            <td class="col-sys-cost">
                                <?php if (!$hasRate): ?>
                                    <span class="no-rate-badge">Brak stawki</span>
                                <?php else: ?>
                                    <?php echo formatMoney($costData['suggested']); ?>
                                    <div class="rate-info">
                                        <?php if ($isWork && $hours > 0 && isset($costData['system_rate_snapshot'])): ?>
                                            <?php echo number_format($hours, 2, ',', ''); ?> h
                                            &times; <?php echo formatMoney($costData['system_rate_snapshot']); ?>/h
                                        <?php elseif (!$isWork): ?>
                                            <?php echo $costData['rate_label'] ?? ''; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="col-final-cost">
                                <input type="number"
                                       name="final_cost[<?php echo $log['id']; ?>]"
                                       class="final-cost-input"
                                       step="0.01"
                                       min="0"
                                       value="<?php echo $suggestedFinal; ?>"
                                       data-original="<?php echo $suggestedFinal; ?>"
                                       onchange="onCostChange(this)"
                                       oninput="onCostChange(this)">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Sticky footer -->
            <div class="approve-footer">
                <div class="approve-footer-info">
                    Zaznaczono: <strong id="footerCount">0</strong> wpisow
                </div>
                <div style="display:flex; gap:10px;">
                    <a href="<?php echo url('hr.worklog'); ?>" class="btn btn-secondary">Anuluj</a>
                    <button type="submit" class="btn btn-primary" id="approveBtn" disabled>
                        Zaakceptuj zaznaczone
                    </button>
                </div>
            </div>
        </form>

        <?php endif; ?>
    </div>

    <footer class="footer">
        &copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?> v<?php echo e(APP_VERSION); ?>
    </footer>

    <script>
        function updateCounts() {
            const checked = document.querySelectorAll('.row-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = checked;
            document.getElementById('footerCount').textContent   = checked;
            document.getElementById('approveBtn').disabled = (checked === 0);

            // Synchronizuj master checkbox
            const total = document.querySelectorAll('.row-checkbox').length;
            const master = document.getElementById('checkAll');
            if (master) {
                master.checked       = (checked === total && total > 0);
                master.indeterminate = (checked > 0 && checked < total);
            }
        }

        function onCheckboxChange(cb) {
            const row = cb.closest('tr');
            row.classList.toggle('row-selected', cb.checked);
            updateCounts();
        }

        function toggleAll(master) {
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = master.checked;
                cb.closest('tr').classList.toggle('row-selected', master.checked);
            });
            updateCounts();
        }

        function selectAll() {
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = true;
                cb.closest('tr').classList.add('row-selected');
            });
            updateCounts();
        }

        function deselectAll() {
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = false;
                cb.closest('tr').classList.remove('row-selected');
            });
            updateCounts();
        }

        function onCostChange(input) {
            const original = parseFloat(input.dataset.original || 0);
            const current  = parseFloat(input.value || 0);
            input.classList.toggle('modified', Math.abs(current - original) > 0.001);
        }

        document.addEventListener('DOMContentLoaded', function () {
            updateCounts();
        });
    </script>
</body>
</html>
