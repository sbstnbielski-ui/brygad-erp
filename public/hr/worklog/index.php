<?php
/**
 * BRYGAD ERP v3.0 - Dziennik Pracy
 * Lista wpisów pracy z filtrowaniem
 * UX v3: Grupowanie po dniach, kolory pracowników, zwijanie/rozwijanie
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/rate_helper.php';
require_once dirname(__DIR__, 2) . '/includes/absence_helper.php';
require_once dirname(__DIR__, 2) . '/includes/payroll_helper.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$flashSuccess = $_SESSION['success'] ?? '';
$flashError = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// TWARDY GATE UPRAWNIEŃ
$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

// Suma sald (hero — spójne z hr/index.php)
$totalBalance = 0;
if ($isAdminUser) {
    try {
        $stmtSum = $pdo->query("
            SELECT SUM(bal) AS total FROM (
                SELECT (
                    COALESCE((SELECT SUM(final_cost) FROM work_logs wl WHERE wl.worker_id = w.id AND wl.status = 'approved' AND wl.final_cost IS NOT NULL), 0)
                    + COALESCE((SELECT SUM(we.amount) FROM worker_expenses we WHERE we.worker_id = w.id AND we.status IN ('approved','reimbursed') AND (we.paid_by_employee = 1 OR we.description LIKE '%[PAID_BY_EMPLOYEE]%')), 0)
                    + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'bonus'), 0)
                    + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'correction'), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'payout'), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'advance' AND (s.advance_kind = 'private' OR s.advance_kind IS NULL) AND NOT EXISTS (SELECT 1 FROM worker_advances wa_dup WHERE wa_dup.worker_id = s.worker_id AND wa_dup.type = 'PRIVATE' AND wa_dup.issue_date = s.date AND wa_dup.amount = s.amount)), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'reimbursement'), 0)
                    - COALESCE((SELECT SUM(wa.amount) FROM worker_advances wa WHERE wa.worker_id = w.id AND wa.type = 'PRIVATE'), 0)
                ) AS bal FROM workers w WHERE w.is_active = 1
            ) sub
        ");
        $totalBalance = (float)($stmtSum->fetchColumn() ?: 0);
    } catch (PDOException $e) {}
}

// KPI: dane za bieżący i poprzedni miesiąc (spójne z hr/index.php)
$monthNames = ['','styczeń','luty','marzec','kwiecień','maj','czerwiec','lipiec','sierpień','wrzesień','październik','listopad','grudzień'];
$curMonth = (int)date('n'); $curYear = (int)date('Y');
$curFrom = sprintf('%04d-%02d-01', $curYear, $curMonth);
$curTo = date('Y-m-t', strtotime($curFrom));
$curLabel = $monthNames[$curMonth] . ' ' . $curYear;

$prevMonth = $curMonth - 1; $prevYear = $curYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$prevFrom = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
$prevTo = date('Y-m-t', strtotime($prevFrom));
$prevLabel = $monthNames[$prevMonth] . ' ' . $prevYear;

$kpiCur = ['labor'=>0,'paid'=>0,'advances'=>0,'bonuses'=>0];
$kpiPrev = ['labor'=>0,'paid'=>0,'advances'=>0,'bonuses'=>0];

if ($isAdminUser) {
    $kpiSettlementsSql = "SELECT COALESCE(SUM(CASE WHEN s.type IN ('payout','reimbursement') THEN s.amount ELSE 0 END),0) AS paid_out, COALESCE(SUM(CASE WHEN s.type='bonus' THEN s.amount ELSE 0 END),0) AS bonuses, COALESCE(SUM(CASE WHEN s.type='advance' AND (s.advance_kind='private' OR s.advance_kind IS NULL) THEN s.amount ELSE 0 END),0) AS advances_priv FROM settlements s JOIN workers w ON w.id=s.worker_id AND w.is_active=1 WHERE COALESCE(NULLIF(s.period,'0000-00-00'),s.date)>=? AND COALESCE(NULLIF(s.period,'0000-00-00'),s.date)<=? AND NOT (s.type='advance' AND (s.advance_kind='private' OR s.advance_kind IS NULL) AND EXISTS (SELECT 1 FROM worker_advances wa_dup WHERE wa_dup.worker_id=s.worker_id AND wa_dup.type='PRIVATE' AND wa_dup.issue_date=s.date AND wa_dup.amount=s.amount))";
    $kpiLaborSql = "SELECT COALESCE(SUM(wl.final_cost),0) FROM work_logs wl JOIN workers w ON w.id=wl.worker_id AND w.is_active=1 WHERE wl.status='approved' AND wl.final_cost IS NOT NULL AND wl.date>=? AND wl.date<=?";
    $kpiAdvWaPeriodExpr = payrollAdvancePeriodSql($pdo, 'wa');
    $kpiAdvWaSql = "SELECT COALESCE(SUM(wa.amount),0) FROM worker_advances wa JOIN workers w ON w.id=wa.worker_id AND w.is_active=1 WHERE wa.type='PRIVATE' AND {$kpiAdvWaPeriodExpr}>=? AND {$kpiAdvWaPeriodExpr}<=?";

    try {
        foreach ([['cur',$curFrom,$curTo],['prev',$prevFrom,$prevTo]] as [$key,$from,$to]) {
            $st=$pdo->prepare($kpiLaborSql); $st->execute([$from,$to]);
            ${"kpi".ucfirst($key)}['labor']=(float)$st->fetchColumn();
            $st=$pdo->prepare($kpiSettlementsSql); $st->execute([$from,$to]);
            $r=$st->fetch();
            ${"kpi".ucfirst($key)}['paid']=(float)($r['paid_out']??0);
            ${"kpi".ucfirst($key)}['bonuses']=(float)($r['bonuses']??0);
            ${"kpi".ucfirst($key)}['advances']=(float)($r['advances_priv']??0);
            $st=$pdo->prepare($kpiAdvWaSql); $st->execute([$from,$to]);
            ${"kpi".ucfirst($key)}['advances']+=(float)$st->fetchColumn();
        }
    } catch (PDOException $e) {}
}
foreach (['kpiCur','kpiPrev'] as $k) {
    ${$k}['total_out']=${$k}['paid']+${$k}['advances'];
    ${$k}['balance']=${$k}['labor']-${$k}['total_out'];
    ${$k}['cash_needed']=${$k}['balance']+${$k}['bonuses'];
}

// Filtry
$filterProject = isset($_GET['project']) ? (int)$_GET['project'] : 0;
$filterCostNode = isset($_GET['cost_node']) ? (int)$_GET['cost_node'] : 0;
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$currentWorklogReturnUrl = $_SERVER['REQUEST_URI'] ?? url('hr.worklog');

// Domyślne daty: brak ograniczenia (puste = wszystkie rekordy)
$defaultDateFrom = '';
$defaultDateTo = '';

$filterDateFrom = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : $defaultDateFrom;
$filterDateTo = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : $defaultDateTo;

// Worker NIE może używać filtra worker - widzi tylko siebie
$filterWorker = 0;
if ($isAdminUser) {
    $filterWorker = isset($_GET['worker']) ? (int)$_GET['worker'] : 0;
}

// Pomocnicze daty dla szybkich filtrów
$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-7 days'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

// Pobierz pracowników dla filtra (tylko dla admina)
$workers = [];
if ($isAdminUser) {
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $workers = $stmt->fetchAll();
    } catch (PDOException $e) {
        $workers = [];
    }
}

// Pobierz projekty dla filtra
try {
    $stmt = $pdo->query("SELECT id, name FROM projects WHERE status IN ('active', 'planned') ORDER BY name");
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    $projects = [];
}

// Pobierz etapy (cost nodes) dla wybranego projektu
$costNodes = [];
if ($filterProject > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM project_cost_nodes WHERE project_id = ? AND is_active = 1 ORDER BY sort_order, name");
        $stmt->execute([$filterProject]);
        $costNodes = $stmt->fetchAll();
    } catch (PDOException $e) {
        $costNodes = [];
    }
}

$retroFlash = isset($_GET['retro_flash']) ? trim((string)$_GET['retro_flash']) : '';
$retroUpdated = isset($_GET['retro_updated']) ? (int)$_GET['retro_updated'] : 0;
$retroMatched = isset($_GET['retro_matched']) ? (int)$_GET['retro_matched'] : 0;
$retroWorkerName = isset($_GET['retro_worker']) ? trim((string)$_GET['retro_worker']) : '';
$retroErrors = [];
$retroOld = [];
if ($isAdminUser && !empty($_SESSION['worklog_retro_errors']) && is_array($_SESSION['worklog_retro_errors'])) {
    $retroErrors = $_SESSION['worklog_retro_errors'];
    unset($_SESSION['worklog_retro_errors']);
}
if ($isAdminUser && !empty($_SESSION['worklog_retro_old']) && is_array($_SESSION['worklog_retro_old'])) {
    $retroOld = $_SESSION['worklog_retro_old'];
    unset($_SESSION['worklog_retro_old']);
}

if ($isAdminUser && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['_action'] ?? '') === 'retro_rate_update')) {
    $retroWorkerId = (int)($_POST['retro_worker_id'] ?? 0);
    $retroProjectId = (int)($_POST['retro_project_id'] ?? 0);
    $retroFrom = trim((string)($_POST['retro_date_from'] ?? ''));
    $retroTo = trim((string)($_POST['retro_date_to'] ?? ''));
    $returnQsRaw = trim((string)($_POST['return_qs'] ?? ''));

    $retroRawRates = [
        'retro_base_rate' => trim((string)($_POST['retro_base_rate'] ?? '')),
        'retro_overtime_rate' => trim((string)($_POST['retro_overtime_rate'] ?? '')),
        'retro_saturday_rate' => trim((string)($_POST['retro_saturday_rate'] ?? '')),
        'retro_saturday_overtime_rate' => trim((string)($_POST['retro_saturday_overtime_rate'] ?? '')),
        'retro_sunday_rate' => trim((string)($_POST['retro_sunday_rate'] ?? '')),
        'retro_sunday_overtime_rate' => trim((string)($_POST['retro_sunday_overtime_rate'] ?? '')),
        'retro_night_rate' => trim((string)($_POST['retro_night_rate'] ?? '')),
        'retro_night_overtime_rate' => trim((string)($_POST['retro_night_overtime_rate'] ?? '')),
        'retro_delegation_rate' => trim((string)($_POST['retro_delegation_rate'] ?? '')),
        'retro_delegation_overtime_rate' => trim((string)($_POST['retro_delegation_overtime_rate'] ?? '')),
        'retro_vacation_rate' => trim((string)($_POST['retro_vacation_rate'] ?? '')),
        'retro_sick_rate' => trim((string)($_POST['retro_sick_rate'] ?? '')),
    ];

    $retroOldPayload = [
        'retro_worker_id' => $retroWorkerId,
        'retro_project_id' => $retroProjectId,
        'retro_date_from' => $retroFrom,
        'retro_date_to' => $retroTo,
    ] + $retroRawRates;

    $redirectParams = [];
    if ($returnQsRaw !== '') {
        parse_str(ltrim($returnQsRaw, '?'), $returnQueryParams);
        if (is_array($returnQueryParams)) {
            $allowedIntParams = ['worker', 'project', 'cost_node', 'month', 'year'];
            foreach ($allowedIntParams as $key) {
                if (isset($returnQueryParams[$key]) && is_numeric($returnQueryParams[$key])) {
                    $redirectParams[$key] = (int)$returnQueryParams[$key];
                }
            }
            if (!empty($returnQueryParams['status']) && in_array($returnQueryParams['status'], ['pending', 'approved'], true)) {
                $redirectParams['status'] = $returnQueryParams['status'];
            }
            foreach (['date_from', 'date_to'] as $key) {
                $val = trim((string)($returnQueryParams[$key] ?? ''));
                if ($val !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                    $redirectParams[$key] = $val;
                }
            }
        }
    }

    $retroErrors = [];
    if ($retroWorkerId <= 0) {
        $retroErrors[] = 'Wybierz pracownika.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $retroFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $retroTo)) {
        $retroErrors[] = 'Zakres dat jest nieprawidlowy.';
    } elseif ($retroFrom > $retroTo) {
        $retroErrors[] = 'Data "od" nie moze byc pozniej niz data "do".';
    }
    if ($retroRawRates['retro_base_rate'] === '' || !is_numeric($retroRawRates['retro_base_rate']) || (float)$retroRawRates['retro_base_rate'] < 0) {
        $retroErrors[] = 'Podaj poprawna stawke bazowa (>= 0).';
    }

    $retroRateLabels = [
        'retro_overtime_rate' => 'Stawka nadgodzinowa',
        'retro_saturday_rate' => 'Stawka sobota (podst.)',
        'retro_saturday_overtime_rate' => 'Stawka sobota (nadgodz.)',
        'retro_sunday_rate' => 'Stawka niedziela (podst.)',
        'retro_sunday_overtime_rate' => 'Stawka niedziela (nadgodz.)',
        'retro_night_rate' => 'Stawka nocka (podst.)',
        'retro_night_overtime_rate' => 'Stawka nocka (nadgodz.)',
        'retro_delegation_rate' => 'Stawka delegacja (podst.)',
        'retro_delegation_overtime_rate' => 'Stawka delegacja (nadgodz.)',
        'retro_vacation_rate' => 'Stawka urlop',
        'retro_sick_rate' => 'Stawka L4',
    ];
    foreach ($retroRateLabels as $rawKey => $label) {
        $rawVal = $retroRawRates[$rawKey];
        if ($rawVal === '') {
            continue;
        }
        if (!is_numeric($rawVal) || (float)$rawVal < 0) {
            $retroErrors[] = $label . ' musi byc liczba >= 0.';
        }
    }

    if (empty($retroErrors)) {
        $retroBaseRate = (float)$retroRawRates['retro_base_rate'];
        $retroOverrides = [];
        foreach ($retroRawRates as $rawKey => $rawVal) {
            if ($rawVal === '') {
                continue;
            }
            $retroOverrides[$rawKey] = (float)$rawVal;
        }

        $workerNameMap = [];
        foreach ($workers as $w) {
            $workerNameMap[(int)$w['id']] = trim((string)$w['first_name'] . ' ' . (string)$w['last_name']);
        }
        $retroWorkerLabel = $workerNameMap[$retroWorkerId] ?? ('ID ' . $retroWorkerId);

        $fetchWorkerRate = function(int $workerId, ?int $projectId, ?int $costNodeId, string $date) use ($pdo): ?array {
            $projectId = ($projectId && $projectId > 0) ? (int)$projectId : null;
            $costNodeId = ($costNodeId && $costNodeId > 0) ? (int)$costNodeId : null;

            $rateColumns = "
                base_rate, overtime_rate,
                saturday_rate, saturday_overtime_rate,
                sunday_rate, sunday_overtime_rate,
                night_rate, night_overtime_rate,
                delegation_rate, delegation_overtime_rate,
                vacation_rate, sick_rate
            ";

            $combos = [];
            if ($projectId !== null && $costNodeId !== null) {
                $combos[] = ["project_id = ? AND cost_node_id = ?", [$projectId, $costNodeId]];
            }
            if ($projectId !== null) {
                $combos[] = ["project_id = ? AND cost_node_id IS NULL", [$projectId]];
            }
            if ($costNodeId !== null) {
                $combos[] = ["project_id IS NULL AND cost_node_id = ?", [$costNodeId]];
            }
            $combos[] = ["project_id IS NULL AND cost_node_id IS NULL", []];

            foreach ([true, false] as $withValidTo) {
                foreach ($combos as [$comboWhere, $comboParams]) {
                    $sql = "
                        SELECT {$rateColumns}
                        FROM worker_rates
                        WHERE worker_id = ?
                          AND valid_from <= ?
                    ";
                    $params = [$workerId, $date];
                    if ($withValidTo) {
                        $sql .= " AND (valid_to IS NULL OR valid_to >= ?)";
                        $params[] = $date;
                    }
                    $sql .= " AND {$comboWhere}
                        ORDER BY valid_from DESC
                        LIMIT 1
                    ";
                    $params = array_merge($params, $comboParams);
                    $stmtRate = $pdo->prepare($sql);
                    $stmtRate->execute($params);
                    $row = $stmtRate->fetch();
                    if ($row) {
                        return $row;
                    }
                }
            }

            $stmtAny = $pdo->prepare("
                SELECT {$rateColumns}
                FROM worker_rates
                WHERE worker_id = ?
                ORDER BY valid_from DESC
                LIMIT 1
            ");
            $stmtAny->execute([$workerId]);
            $rowAny = $stmtAny->fetch();
            return $rowAny ?: null;
        };

        try {
            $pdo->beginTransaction();

            $sqlLogs = "
                SELECT
                    id, worker_id, project_id, cost_node_id, date, work_type, is_paid,
                    hours, overtime_hours,
                    workday_hours, workday_overtime,
                    saturday_hours, saturday_overtime,
                    sunday_hours, sunday_overtime,
                    night_hours, night_overtime,
                    delegation_hours, delegation_overtime,
                    absence_days,
                    vacation_hours, sickleave_hours
                FROM work_logs
                WHERE worker_id = ?
                  AND status = 'approved'
                  AND date >= ?
                  AND date <= ?
            ";
            $paramsLogs = [$retroWorkerId, $retroFrom, $retroTo];
            if ($retroProjectId > 0) {
                $sqlLogs .= " AND project_id = ?";
                $paramsLogs[] = $retroProjectId;
            }

            $stmtLogs = $pdo->prepare($sqlLogs);
            $stmtLogs->execute($paramsLogs);
            $logsToRecalc = $stmtLogs->fetchAll();

            $matched = count($logsToRecalc);
            $updated = 0;
            if ($matched > 0) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE work_logs
                    SET system_rate_snapshot = ?,
                        system_cost = ?,
                        final_cost = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");

                foreach ($logsToRecalc as $logRow) {
                    $logRate = $fetchWorkerRate(
                        (int)$logRow['worker_id'],
                        isset($logRow['project_id']) ? (int)$logRow['project_id'] : null,
                        isset($logRow['cost_node_id']) ? (int)$logRow['cost_node_id'] : null,
                        (string)$logRow['date']
                    ) ?: [];

                    $rateFromSource = static function(array $source, string $key): ?float {
                        if (!isset($source[$key]) || $source[$key] === '' || $source[$key] === null) {
                            return null;
                        }
                        return is_numeric($source[$key]) ? (float)$source[$key] : null;
                    };

                    $baseRate = $retroBaseRate;
                    $overtimeRate = $retroOverrides['retro_overtime_rate']
                        ?? $rateFromSource($logRate, 'overtime_rate')
                        ?? $baseRate;
                    $saturdayRate = $retroOverrides['retro_saturday_rate']
                        ?? $rateFromSource($logRate, 'saturday_rate')
                        ?? $baseRate;
                    $saturdayOtRate = $retroOverrides['retro_saturday_overtime_rate']
                        ?? $rateFromSource($logRate, 'saturday_overtime_rate')
                        ?? $overtimeRate;
                    $sundayRate = $retroOverrides['retro_sunday_rate']
                        ?? $rateFromSource($logRate, 'sunday_rate')
                        ?? $baseRate;
                    $sundayOtRate = $retroOverrides['retro_sunday_overtime_rate']
                        ?? $rateFromSource($logRate, 'sunday_overtime_rate')
                        ?? $overtimeRate;
                    $nightRate = $retroOverrides['retro_night_rate']
                        ?? $rateFromSource($logRate, 'night_rate')
                        ?? $baseRate;
                    $nightOtRate = $retroOverrides['retro_night_overtime_rate']
                        ?? $rateFromSource($logRate, 'night_overtime_rate')
                        ?? $overtimeRate;
                    $delegRate = $retroOverrides['retro_delegation_rate']
                        ?? $rateFromSource($logRate, 'delegation_rate')
                        ?? $baseRate;
                    $delegOtRate = $retroOverrides['retro_delegation_overtime_rate']
                        ?? $rateFromSource($logRate, 'delegation_overtime_rate')
                        ?? $overtimeRate;
                    $vacationRate = $retroOverrides['retro_vacation_rate']
                        ?? $rateFromSource($logRate, 'vacation_rate')
                        ?? $baseRate;
                    $sickRate = $retroOverrides['retro_sick_rate']
                        ?? $rateFromSource($logRate, 'sick_rate')
                        ?? $baseRate;

                    $isPaid = (int)($logRow['is_paid'] ?? 1);
                    $workType = (string)($logRow['work_type'] ?? 'work');
                    $snapshotRate = $baseRate;
                    $recalcCost = 0.0;

                    if (($workType === 'vacation' || $workType === 'sick') && $isPaid === 0) {
                        $snapshotRate = 0.0;
                        $recalcCost = 0.0;
                    } elseif ($workType === 'vacation') {
                        $snapshotRate = $vacationRate;
                        $recalcCost = normalizeAbsenceHours($logRow) * $vacationRate;
                    } elseif ($workType === 'sick') {
                        $snapshotRate = $sickRate;
                        $recalcCost = normalizeAbsenceHours($logRow) * $sickRate;
                    } else {
                        $wdHours = max(0.0, (float)($logRow['workday_hours'] ?? 0));
                        $wdOt = max(0.0, (float)($logRow['workday_overtime'] ?? 0));
                        $saHours = max(0.0, (float)($logRow['saturday_hours'] ?? 0));
                        $saOt = max(0.0, (float)($logRow['saturday_overtime'] ?? 0));
                        $suHours = max(0.0, (float)($logRow['sunday_hours'] ?? 0));
                        $suOt = max(0.0, (float)($logRow['sunday_overtime'] ?? 0));
                        $niHours = max(0.0, (float)($logRow['night_hours'] ?? 0));
                        $niOt = max(0.0, (float)($logRow['night_overtime'] ?? 0));
                        $deHours = max(0.0, (float)($logRow['delegation_hours'] ?? 0));
                        $deOt = max(0.0, (float)($logRow['delegation_overtime'] ?? 0));

                        if (
                            ($wdHours + $wdOt + $saHours + $saOt + $suHours + $suOt + $niHours + $niOt + $deHours + $deOt)
                            <= 0.0001
                        ) {
                            $wdHours = max(0.0, (float)($logRow['hours'] ?? 0));
                            $wdOt = max(0.0, (float)($logRow['overtime_hours'] ?? 0));
                        }

                        $snapshotRate = $baseRate;
                        $recalcCost = ($wdHours * $baseRate)
                            + ($wdOt * $overtimeRate)
                            + ($saHours * $saturdayRate)
                            + ($saOt * $saturdayOtRate)
                            + ($suHours * $sundayRate)
                            + ($suOt * $sundayOtRate)
                            + ($niHours * $nightRate)
                            + ($niOt * $nightOtRate)
                            + ($deHours * $delegRate)
                            + ($deOt * $delegOtRate);
                    }

                    $stmtUpdate->execute([$snapshotRate, $recalcCost, $recalcCost, (int)$logRow['id']]);
                    $updated++;
                }
            }

            $pdo->commit();
            logEvent(
                "Worklog retro rate update: worker_id={$retroWorkerId}, project_id={$retroProjectId}, from={$retroFrom}, to={$retroTo}, updated={$updated}, base={$retroBaseRate}",
                'WARNING'
            );

            $redirectParams['retro_flash'] = 'updated';
            $redirectParams['retro_updated'] = $updated;
            $redirectParams['retro_matched'] = $matched;
            $redirectParams['retro_worker'] = $retroWorkerLabel;
            unset($_SESSION['worklog_retro_old']);
            header('Location: ' . url('hr.worklog.index') . '?' . http_build_query($redirectParams));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $retroErrors[] = 'Nie udalo sie przeliczyc stawek.';
            logEvent('Worklog retro rate update error: ' . $e->getMessage(), 'ERROR');
        }
    }

    $_SESSION['worklog_retro_errors'] = $retroErrors;
    $_SESSION['worklog_retro_old'] = $retroOldPayload;
    $redirectParams['retro_flash'] = 'error';
    header('Location: ' . url('hr.worklog.index') . '?' . http_build_query($redirectParams));
    exit;
}

// Buduj query
$where = ["1=1"];
$params = [];

// Sortowanie - zawsze po dacie DESC dla grupowania
$sortBy = 'wl.date';
$sortOrder = 'DESC';

// ZABEZPIECZENIE: Worker widzi tylko swoje wpisy
if (!$isAdminUser && $currentWorkerId) {
    $where[] = "wl.worker_id = ?";
    $params[] = $currentWorkerId;
} elseif ($isAdminUser && $filterWorker > 0) {
    $where[] = "wl.worker_id = ?";
    $params[] = $filterWorker;
}

if ($filterProject > 0) {
    $where[] = "wl.project_id = ?";
    $params[] = $filterProject;
}

if ($filterCostNode > 0) {
    $where[] = "wl.cost_node_id = ?";
    $params[] = $filterCostNode;
}

if (!empty($filterStatus)) {
    $where[] = "wl.status = ?";
    $params[] = $filterStatus;
}

// Filtr zakresu dat
if (!empty($filterDateFrom)) {
    $where[] = "wl.date >= ?";
    $params[] = $filterDateFrom;
}
if (!empty($filterDateTo)) {
    $where[] = "wl.date <= ?";
    $params[] = $filterDateTo;
}

// Pobierz wpisy
try {
    $sql = "SELECT 
                wl.*,
                w.first_name,
                w.last_name,
                p.name as project_name,
                pcn.name as cost_node_name,
                creator.login as created_by_login,
                approver.login as approved_by_login
            FROM work_logs wl
            INNER JOIN workers w ON wl.worker_id = w.id
            LEFT JOIN projects p ON wl.project_id = p.id
            LEFT JOIN project_cost_nodes pcn ON wl.cost_node_id = pcn.id
            LEFT JOIN users creator ON wl.created_by_user_id = creator.id
            LEFT JOIN users approver ON wl.approved_by_user_id = approver.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$sortBy} {$sortOrder}, w.last_name, w.first_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("Błąd pobierania dziennika pracy: " . $e->getMessage(), 'ERROR');
    $logs = [];
}

// ========================================
// GRUPOWANIE WPISÓW PO DACIE
// ========================================
$logsByDate = [];
foreach ($logs as $log) {
    $date = $log['date'];
    if (!isset($logsByDate[$date])) {
        $logsByDate[$date] = [
            'logs' => [],
            'total_hours' => 0,
            'total_cost' => 0,
            'pending_count' => 0,
            'approved_count' => 0
        ];
    }
    $logsByDate[$date]['logs'][] = $log;
    
    $workType = $log['work_type'] ?? 'work';
    if ($workType === 'work') {
        $logsByDate[$date]['total_hours'] += (float)($log['hours'] ?? 0);
    }
    $logsByDate[$date]['total_cost'] += (float)($log['final_cost'] ?: $log['system_cost']);
    
    if ($log['status'] === 'pending') $logsByDate[$date]['pending_count']++;
    if ($log['status'] === 'approved') $logsByDate[$date]['approved_count']++;
}

// ========================================
// FUNKCJA: Generowanie koloru pracownika na podstawie ID
// ========================================
function getWorkerColor($workerId) {
    // Deterministyczny kolor na podstawie ID - używamy złotego podziału dla równomiernego rozkładu
    $goldenRatio = 0.618033988749895;
    $hue = fmod($workerId * $goldenRatio, 1.0) * 360;
    
    // Saturation i Lightness dla czytelnych, przyjemnych kolorów
    $saturation = 65;
    $lightness = 55;
    
    return [
        'hue' => round($hue),
        'sat' => $saturation,
        'light' => $lightness,
        'hsl' => "hsl({$hue}, {$saturation}%, {$lightness}%)",
        'hslLight' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.08)",
        'hslBorder' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.6)"
    ];
}

// ========================================
// FUNKCJA: Nazwa dnia tygodnia po polsku
// ========================================
function getPolishDayName($date) {
    $days = ['Nd', 'Pn', 'Wt', 'Sr', 'Cz', 'Pt', 'So'];
    $dayNum = date('w', strtotime($date));
    return $days[$dayNum];
}

// Statystyki dla wybranego okresu
$stats = [
    'total_hours' => 0,
    'total_overtime' => 0,
    'vacation_days' => 0,
    'sick_days' => 0,
    'pending_count' => 0,
    'approved_count' => 0,
    'total_cost' => 0
];

foreach ($logs as $log) {
    $workType = $log['work_type'] ?? 'work';
    
    if ($workType === 'work') {
        $stats['total_hours'] += $log['hours'];
        $stats['total_overtime'] += $log['overtime_hours'];
    } else if ($workType === 'vacation') {
        $stats['vacation_days'] += normalizeVacationDays($log);
    } else if ($workType === 'sick') {
        $stats['sick_days'] += normalizeSickDays($log);
    }
    
    if ($log['status'] === 'pending') $stats['pending_count']++;
    if ($log['status'] === 'approved') $stats['approved_count']++;
    $stats['total_cost'] += $log['final_cost'] ?: $log['system_cost'];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$retroDefaultWorkerId = $filterWorker > 0 ? $filterWorker : (isset($workers[0]['id']) ? (int)$workers[0]['id'] : 0);
$retroDefaultProjectId = $filterProject > 0 ? $filterProject : 0;
$retroDefaultFrom = $filterDateFrom !== '' ? $filterDateFrom : $monthStart;
$retroDefaultTo = $filterDateTo !== '' ? $filterDateTo : $monthEnd;
$retroDefaultBaseRate = '';
$retroDefaultOvertimeRate = '';
$retroDefaultSaturdayRate = '';
$retroDefaultSaturdayOvertimeRate = '';
$retroDefaultSundayRate = '';
$retroDefaultSundayOvertimeRate = '';
$retroDefaultNightRate = '';
$retroDefaultNightOvertimeRate = '';
$retroDefaultDelegationRate = '';
$retroDefaultDelegationOvertimeRate = '';
$retroDefaultVacationRate = '';
$retroDefaultSickRate = '';
if ($isAdminUser && !empty($retroOld)) {
    if (isset($retroOld['retro_worker_id']) && is_numeric($retroOld['retro_worker_id'])) {
        $retroDefaultWorkerId = max(0, (int)$retroOld['retro_worker_id']);
    }
    if (isset($retroOld['retro_project_id']) && is_numeric($retroOld['retro_project_id'])) {
        $retroDefaultProjectId = max(0, (int)$retroOld['retro_project_id']);
    }
    $tmpFrom = trim((string)($retroOld['retro_date_from'] ?? ''));
    if ($tmpFrom !== '') {
        $retroDefaultFrom = $tmpFrom;
    }
    $tmpTo = trim((string)($retroOld['retro_date_to'] ?? ''));
    if ($tmpTo !== '') {
        $retroDefaultTo = $tmpTo;
    }
    $retroDefaultBaseRate = trim((string)($retroOld['retro_base_rate'] ?? ''));
    $retroDefaultOvertimeRate = trim((string)($retroOld['retro_overtime_rate'] ?? ''));
    $retroDefaultSaturdayRate = trim((string)($retroOld['retro_saturday_rate'] ?? ''));
    $retroDefaultSaturdayOvertimeRate = trim((string)($retroOld['retro_saturday_overtime_rate'] ?? ''));
    $retroDefaultSundayRate = trim((string)($retroOld['retro_sunday_rate'] ?? ''));
    $retroDefaultSundayOvertimeRate = trim((string)($retroOld['retro_sunday_overtime_rate'] ?? ''));
    $retroDefaultNightRate = trim((string)($retroOld['retro_night_rate'] ?? ''));
    $retroDefaultNightOvertimeRate = trim((string)($retroOld['retro_night_overtime_rate'] ?? ''));
    $retroDefaultDelegationRate = trim((string)($retroOld['retro_delegation_rate'] ?? ''));
    $retroDefaultDelegationOvertimeRate = trim((string)($retroOld['retro_delegation_overtime_rate'] ?? ''));
    $retroDefaultVacationRate = trim((string)($retroOld['retro_vacation_rate'] ?? ''));
    $retroDefaultSickRate = trim((string)($retroOld['retro_sick_rate'] ?? ''));
}
$retroOpenRequested = $isAdminUser && (($_GET['retro_open'] ?? '') === '1');
$retroAutoOpen = ($retroFlash === 'error' || !empty($retroErrors) || $retroOpenRequested);
$returnParams = [];
parse_str($_SERVER['QUERY_STRING'] ?? '', $returnParams);
unset($returnParams['retro_flash'], $returnParams['retro_updated'], $returnParams['retro_matched'], $returnParams['retro_worker']);
$returnParams['retro_open'] = null;
$returnParams = array_filter(
    $returnParams,
    static function($value): bool {
        if (is_array($value)) {
            return !empty($value);
        }
        return $value !== null && $value !== '';
    }
);
$returnQs = http_build_query($returnParams);

$sidebarQuickActions = [
    'Nawigacja' => [
        ['label' => 'Dashboard', 'url' => url('dashboard'), 'keywords' => 'dashboard panel glowny'],
        ['label' => 'Dashboard HR', 'url' => url('hr'), 'keywords' => 'hr dashboard pracownicy'],
        ['label' => 'Dziennik pracy', 'url' => url('hr.worklog.index'), 'keywords' => 'dziennik pracy logi'],
        ['label' => 'Historia operacji', 'url' => url('hr') . '?tab=history', 'keywords' => 'historia operacje rozliczenia'],
        ['label' => 'Nowa operacja', 'url' => url('hr') . '?tab=new', 'keywords' => 'nowa operacja rozlicz'],
    ],
    'Akcje' => [
        ['label' => 'Dodaj wpis', 'url' => url('hr.worklog.create'), 'keywords' => 'dodaj wpis pracy'],
    ],
];

if ($isAdminUser) {
    $sidebarQuickActions['Nawigacja'][] = ['label' => 'Lista pracownikow', 'url' => url('hr.workers'), 'keywords' => 'lista pracownikow'];
    $sidebarQuickActions['Nawigacja'][] = ['label' => 'Alerty HR', 'url' => url('hr.alerts'), 'keywords' => 'alerty hr'];
} elseif (!empty($currentWorkerId)) {
    $sidebarQuickActions['Nawigacja'][] = ['label' => 'Moj profil', 'url' => url('hr.workers.profile', ['id' => (int)$currentWorkerId]), 'keywords' => 'moj profil pracownika'];
}

/**
 * Buduje dane o stawce dla wiersza w dzienniku pracy.
 * Wzorzec identyczny jak buildRateInfo() w report.php.
 * Zwraca tablicę: show_meta, meta_text, is_mixed, segments
 */
function buildRateInfo(array $log): array
{
    $result = ['show_meta' => false, 'meta_text' => '', 'is_mixed' => false, 'segments' => []];

    if (($log['status'] ?? '') !== 'approved') return $result;
    if (($log['work_type'] ?? 'work') !== 'work') return $result;

    // Obsługujemy zarówno alias 'cost' (report.php) jak i bezpośrednie kolumny (index.php)
    $costRaw = $log['cost'] ?? $log['final_cost'] ?? $log['system_cost'] ?? null;
    if ($costRaw === null || $costRaw === '') return $result;

    $regularHours  = max(0, (float)($log['hours'] ?? 0));
    $overtimeHours = max(0, (float)($log['overtime_hours'] ?? 0));
    $totalHours    = $regularHours + $overtimeHours;
    if ($totalHours <= 0.0001) return $result;

    $cost         = (float)$costRaw;
    if ($cost <= 0.0001) return $result;
    $effectiveRate = $cost / $totalHours;
    $snapshotRate  = (isset($log['system_rate_snapshot']) && is_numeric($log['system_rate_snapshot']))
        ? (float)$log['system_rate_snapshot'] : 0.0;

    $result['show_meta'] = true;

    // Stawki mieszane (nadgodziny z inną stawką)
    if ($overtimeHours > 0.0001 && $snapshotRate > 0.0001) {
        $regularAmount  = $regularHours * $snapshotRate;
        $overtimeAmount = $cost - $regularAmount;
        if ($overtimeAmount < 0 && $overtimeAmount > -0.05) $overtimeAmount = 0.0;
        $overtimeRate = $overtimeHours > 0 ? ($overtimeAmount / $overtimeHours) : 0.0;

        if ($overtimeRate > 0.0001 && abs($overtimeRate - $snapshotRate) > 0.01) {
            $result['is_mixed']  = true;
            $result['meta_text'] = number_format($totalHours, 2, ',', ' ') . ' h, stawki mieszane';

            if ($regularHours > 0.0001) {
                $result['segments'][] = [
                    'label'  => 'Godziny podstawowe',
                    'hours'  => $regularHours,
                    'rate'   => $snapshotRate,
                    'amount' => $regularAmount,
                ];
            }
            $result['segments'][] = [
                'label'  => 'Nadgodziny',
                'hours'  => $overtimeHours,
                'rate'   => $overtimeRate,
                'amount' => $overtimeAmount,
            ];
            return $result;
        }
    }

    $result['meta_text'] = number_format($totalHours, 2, ',', ' ')
        . ' h &times; '
        . number_format($effectiveRate, 2, ',', ' ')
        . ' zl/h';
    return $result;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dziennik Pracy</title>
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
        .container {
            max-width: 1500px;
            margin: 0 auto;
            padding: 25px;
        }
        .flash-message {
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 16px;
            border-left: 4px solid;
            font-size: 14px;
            font-weight: 600;
        }
        .flash-success {
            background: #f0fdf4;
            border-color: var(--success);
            color: #166534;
        }
        .flash-error {
            background: #fef2f2;
            border-color: var(--danger);
            color: #991b1b;
        }

        .dashboard-layout {
            display: flex;
            align-items: flex-start;
            gap: 0;
        }
        .dashboard-content {
            flex: 1;
            min-width: 0;
            padding-left: 16px;
        }
        .sidebar-wrapper.collapsed + .dashboard-content {
            padding-left: 0;
        }

        .sidebar-wrapper {
            position: relative;
            flex-shrink: 0;
            z-index: 10;
        }
        .sidebar-actions {
            background: linear-gradient(180deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
            border-radius: 12px 0 12px 12px;
            width: 240px;
            height: calc(100vh - 90px);
            position: sticky;
            top: 74px;
            overflow-y: auto;
            overflow-x: hidden;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(30, 58, 138, 0.2);
            color: white;
        }
        .sidebar-wrapper.collapsed .sidebar-actions { width: 0; }
        .toggle-sidebar-btn {
            position: absolute;
            top: 20px;
            right: -14px;
            width: 28px;
            height: 28px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 20;
            color: var(--primary-blue);
            transition: all 0.3s ease;
        }
        .toggle-sidebar-btn:hover { background: #f8fafc; transform: scale(1.1); }
        .sidebar-wrapper.collapsed .toggle-sidebar-btn { right: -32px; transform: rotate(180deg); }
        .sidebar-content-inner { width: 240px; }
        .sidebar-search { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-search input {
            width: 100%;
            padding: 8px 12px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            border-radius: 7px;
            font-size: 12px;
            transition: all 0.2s;
        }
        .sidebar-search input::placeholder { color: #93c5fd; }
        .sidebar-search input:focus { outline: none; background: rgba(255,255,255,0.15); border-color: #60a5fa; }
        .sidebar-actions-body { padding: 10px; }
        .sidebar-section {
            margin-bottom: 4px;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 7px;
            overflow: hidden;
        }
        .sidebar-section-title {
            font-size: 11px;
            color: #93c5fd;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            list-style: none;
            margin: 0;
            padding: 8px 10px;
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.04);
        }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after {
            content: '›';
            font-size: 14px;
            color: rgba(255,255,255,0.4);
            transition: transform 0.2s ease;
            line-height: 1;
        }
        .sidebar-section[open] .sidebar-section-title::after { transform: rotate(90deg); }
        .sidebar-section-links { padding: 4px 6px 6px; }
        .sidebar-actions a {
            display: block;
            padding: 7px 10px;
            margin-bottom: 2px;
            color: #e2e8f0;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.15s ease;
            font-size: 12px;
            font-weight: 500;
        }
        .sidebar-actions a:hover {
            background: rgba(255,255,255,0.12);
            color: white;
            padding-left: 14px;
        }

        /* Lokalny override: header ma byc jak na nowym dashboardzie */
        .header {
            box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
        }
        .header-content {
            max-width: 1600px !important;
            padding: 15px 30px !important;
            justify-content: space-between !important;
            align-items: center !important;
            flex-wrap: nowrap !important;
        }
        .logo-section,
        .logo-link {
            gap: 15px !important;
            align-items: center !important;
        }
        .logo-section img { height: 40px !important; }
        .logo-text h1 {
            font-size: 20px !important;
            letter-spacing: -0.5px !important;
            margin: 0 !important;
            color: #1f2937 !important;
        }
        .logo-text p {
            font-size: 12px !important;
            margin: 0 !important;
            color: #6b7280 !important;
        }
        .user-section {
            display: flex !important;
            align-items: center !important;
            gap: 20px !important;
            flex-wrap: nowrap !important;
        }
        .user-name {
            font-weight: 600 !important;
            font-size: 14px !important;
            color: #333 !important;
        }
        .alerts-dropdown { display: none !important; }
        
        /* Hero HR — identyczny jak hr/index.php */
        .hero-hr {
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
        .hero-hr h1 { margin: 0 0 6px; font-size: 30px; letter-spacing: -0.4px; }
        .hero-hr .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-hr .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-hr .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero-hr p { margin: 0; color: #cbd5e1; font-size: 14px; }
        /* KPI kafelki pod hero */
        .hr-kpi-section { margin-bottom:18px; }
        .hr-kpi-section-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.7px; color:var(--text-muted); margin-bottom:8px; display:flex; align-items:center; gap:8px; }
        .hr-kpi-section-label span { background:var(--border-light); padding:2px 8px; border-radius:4px; font-weight:600; letter-spacing:0; text-transform:none; }
        .hr-kpi-strip { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; }
        .hr-kpi-card { background:white; border-radius:10px; padding:14px 16px; border:1px solid var(--border); border-top:3px solid transparent; transition:box-shadow 0.15s, transform 0.15s; }
        .hr-kpi-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); transform:translateY(-2px); }
        .hr-kpi-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-muted); margin-bottom:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .hr-kpi-value { font-size:18px; font-weight:800; color:var(--text-main); line-height:1.2; font-variant-numeric:tabular-nums; }
        .hr-kpi-sub { font-size:11px; color:var(--text-muted); margin-top:3px; }
        .hr-kpi-card.kpi-balance { border-top-color:#2563eb; }
        .hr-kpi-card.kpi-balance .hr-kpi-value { color:#2563eb; }
        .hr-kpi-card.kpi-labor { border-top-color:#7c3aed; }
        .hr-kpi-card.kpi-labor .hr-kpi-value { color:#7c3aed; }
        .hr-kpi-card.kpi-paid { border-top-color:#059669; }
        .hr-kpi-card.kpi-paid .hr-kpi-value { color:#059669; }
        .hr-kpi-card.kpi-bonus { border-top-color:#d97706; }
        .hr-kpi-card.kpi-bonus .hr-kpi-value { color:#d97706; }
        .hr-kpi-card.kpi-cash { border-top-color:#dc2626; }
        .hr-kpi-val-pos { color:#16a34a !important; }
        .hr-kpi-val-neg { color:#dc2626 !important; }
        @media (max-width:1024px) { .hr-kpi-strip { grid-template-columns:repeat(2, 1fr); } }
        @media (max-width:640px) { .hr-kpi-value { font-size:15px; } }
        .hero-right {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-end;
        }
        .hero-nav {
            display: inline-flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .hero-nav a {
            padding: 7px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.06);
            transition: all 0.2s ease;
        }
        .hero-nav a:hover {
            background: rgba(255,255,255,0.14);
            color: #ffffff;
        }
        .hero-nav a.active {
            background: #ffffff;
            color: #1e3a8a;
            border-color: #ffffff;
        }
        .hero-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        
        /* Akcje */
        .actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            padding: 10px 18px;
            height: 40px;
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
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .btn-hero-light {
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.25);
            color: #ffffff;
            backdrop-filter: blur(2px);
        }
        .btn-hero-light:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-1px);
        }
        .btn-hero-primary {
            background: #ffffff;
            color: #1e3a8a;
            border: 1px solid #ffffff;
            font-weight: 700;
        }
        .btn-hero-primary:hover {
            background: #e0e7ff;
            transform: translateY(-1px);
        }
        .btn-ghost {
            background: white;
            color: var(--text-main);
            border: 1px solid var(--border);
        }
        .btn-ghost:hover {
            background: #f9fafb;
            border-color: var(--primary);
        }
        .btn-ghost.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-retro-submit[disabled] {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        /* Statystyki */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            padding: 18px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }
        
        /* Card - główny kontener */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
            border: 1px solid #e5e7eb;
            overflow: hidden;
            margin-bottom: 25px;
        }
        
        /* ========================================
           SPX FILTER SYSTEM - wzorzec v1
           ======================================== */

        /* Główny pasek filtrów */
        .spx-filter-bar {
            padding: 14px 16px;
            background: white;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: nowrap;
        }
        .spx-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .spx-filter-group.fg-worker   { flex: 1.5 1 0; }
        .spx-filter-group.fg-project  { flex: 2   1 0; }
        .spx-filter-group.fg-month    { flex: 1.2 1 0; }
        .spx-filter-group.fg-year     { flex: 0.7 1 0; }
        .spx-filter-group.fg-date     { flex: 1   1 0; }
        .spx-filter-group.fg-status   { flex: 1   1 0; }
        .spx-filter-group label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            white-space: nowrap;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"] {
            padding: 0 10px;
            height: 38px;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            font-size: 13px;
            background: #f8fafc;
            color: var(--text-main);
            font-family: inherit;
            transition: all 0.15s;
            width: 100%;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input[type="date"]:focus {
            outline: none;
            background: #ffffff;
            border-color: #93c5fd;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }
        .spx-filter-bar .btn-primary {
            background: #2563eb;
            color: #ffffff;
            border: 1px solid #2563eb;
            box-shadow: none;
        }
        .spx-filter-bar .btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            box-shadow: none;
            transform: none;
        }
        .spx-filter-bar .btn-secondary {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #dbe3ef;
        }
        .spx-filter-bar .btn-secondary:hover {
            background: #eef2f7;
            border-color: #cbd5e1;
        }

        /* Pasek kontrolek (quick filters + toggle widoku) */
        .spx-controls-bar {
            padding: 10px 16px;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .spx-controls-left {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-wrap: wrap;
        }
        .spx-controls-right {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* Quick filtry (Dzis, 7 dni, Miesiac) */
        .spx-quick-btn {
            padding: 0 12px;
            height: 28px;
            background: white;
            border: 1px solid #dbe3ef;
            border-radius: 999px;
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
        .spx-quick-btn:hover {
            background: #eff6ff;
            border-color: #93c5fd;
            color: #1d4ed8;
        }
        .spx-quick-btn.active {
            background: #2563eb;
            border-color: #2563eb;
            color: white;
            font-weight: 600;
        }

        /* Toggle zwiń/rozwiń */
        .spx-toggle-group {
            display: flex;
            gap: 2px;
            background: #eaf0f7;
            padding: 2px;
            border-radius: 6px;
        }
        .spx-btn-toggle {
            background: transparent;
            border: none;
            color: var(--text-muted);
            padding: 5px 11px;
            height: 26px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
        }
        .spx-btn-toggle:hover {
            background: white;
            color: var(--text-main);
        }

        /* Color mode button */
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
            padding: 0;
        }
        .btn-color-mode:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }
        .btn-color-mode.active {
            background: linear-gradient(135deg, #fce7f3, #e0e7ff);
            border-color: #a78bfa;
        }
        .btn-color-mode svg {
            width: 16px;
            height: 16px;
        }
        
        /* Rate info pod kosztem */
        .rate-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 3px;
            font-weight: 400;
            line-height: 1.3;
        }
        .rate-toggle {
            margin-top: 4px;
            padding: 2px 8px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
        }
        .rate-toggle:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }
        .rate-details-row td {
            background: #f8fafc !important;
            border-top: none !important;
        }
        .rate-details-panel { padding: 8px 2px 4px; }
        .rate-details-title {
            font-size: 11px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .rate-details-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .rate-details-table th,
        .rate-details-table td {
            padding: 5px 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
        }
        .rate-details-table th {
            text-align: left;
            font-size: 10px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .rate-details-table td.rate-num {
            text-align: right;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        /* ========================================
           DAY GROUP - Sekcja dnia
           ======================================== */
        .day-group {
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 16px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            transition: all 0.3s ease;
        }
        .day-group.collapsed {
            margin-bottom: 8px;
            box-shadow: none;
        }
        .day-group.collapsed .day-content {
            display: none;
        }
        
        /* Day header */
        .day-header {
            background: #f8fafc;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
            transition: background 0.2s;
        }
        .day-header:hover {
            background: #f1f5f9;
        }
        .day-group.collapsed .day-header {
            border-bottom: none;
            border-radius: 10px;
        }
        
        .dh-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .dh-dayname {
            color: var(--text-muted);
            text-transform: uppercase;
            font-size: 11px;
            font-weight: 700;
            background: #e2e8f0;
            padding: 3px 8px;
            border-radius: 4px;
            min-width: 28px;
            text-align: center;
        }
        .dh-date {
            font-weight: 700;
            font-size: 15px;
            color: #334155;
        }
        
        .dh-right {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .dh-stats {
            display: flex;
            gap: 16px;
        }
        .dh-stat {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .dh-stat-value {
            font-weight: 600;
            color: var(--text-main);
        }
        
        .btn-approve-day {
            background: white;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-approve-day:hover {
            background: #dcfce7;
            border-color: #86efac;
        }
        
        .dh-arrow {
            font-size: 10px;
            color: var(--text-muted);
            transition: transform 0.2s;
            width: 16px;
            text-align: center;
        }
        .day-group.collapsed .dh-arrow {
            transform: rotate(-90deg);
        }
        
        /* Day content */
        .day-content {
            padding: 0;
        }
        
        /* ========================================
           TABELA WPISÓW
           ======================================== */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: white;
        }
        th {
            padding: 10px 14px !important;
            text-align: left;
            font-weight: 600;
            color: var(--text-muted) !important;
            border: 1px solid #000000 !important;
            font-size: 11px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px;
            background: #f9fafb !important;
        }
        td {
            padding: 10px 14px !important;
            border: 1px solid #000000 !important;
            font-size: 13px !important;
            vertical-align: middle;
            transition: background 0.2s;
        }
        
        /* Kolory pracowników - tryb włączony */
        body:not(.no-colors) tbody tr {
            background: var(--worker-bg, transparent);
            border-left: 4px solid var(--worker-color, #667eea);
        }
        body:not(.no-colors) tbody tr:hover {
            filter: brightness(0.97);
        }
        
        /* Kolory pracowników - tryb wyłączony (zebra) */
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
        
        /* Specjalne wiersze - L4 */
        tr.row-sick {
            --worker-color: #ef4444 !important;
            --worker-bg: #fef2f2 !important;
        }
        tr.row-sick td {
            color: #991b1b;
        }
        body.no-colors tr.row-sick {
            background: #fef2f2 !important;
            border-left: 4px solid #ef4444 !important;
        }
        
        /* Specjalne wiersze - Urlop */
        tr.row-vacation {
            --worker-color: #22c55e !important;
            --worker-bg: #f0fdf4 !important;
        }
        tr.row-vacation td {
            color: #166534;
        }
        body.no-colors tr.row-vacation {
            background: #f0fdf4 !important;
            border-left: 4px solid #22c55e !important;
        }
        
        /* Kolumny */
        .col-worker {
            font-weight: 600;
            color: #334155;
            min-width: 160px;
        }
        .col-project {
            color: var(--text-muted);
            max-width: 200px;
        }
        .col-project-name {
            font-weight: 500;
            color: var(--text-main);
        }
        .col-project-stage {
            font-size: 11px;
            color: var(--text-muted);
        }
        .col-hours {
            text-align: right;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        .col-cost {
            text-align: right;
            font-weight: 600;
            color: var(--text-main);
            font-variant-numeric: tabular-nums;
        }
        .col-status {
            text-align: center;
            width: 70px;
        }
        .col-actions {
            text-align: center;
            width: 120px;
        }
        
        
        /* Worker link */
        .worker-link {
            color: inherit;
            text-decoration: none;
            transition: color 0.2s;
        }
        .worker-link:hover {
            color: var(--primary);
            text-decoration: underline;
        }
        
        /* Status dot */
        .status-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .status-dot.pending {
            background: #eab308;
            box-shadow: 0 0 0 2px rgba(234, 179, 8, 0.2);
            animation: pulse-pending 2s ease-in-out infinite;
        }
        .status-dot.approved {
            background: #22c55e;
            box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.2);
        }
        .status-dot.locked {
            background: #9ca3af;
            box-shadow: 0 0 0 2px rgba(156, 163, 175, 0.2);
        }
        
        @keyframes pulse-pending {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Action buttons */
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
        
        /* No data */
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            padding: 20px;
            color: var(--text-muted);
            font-size: 12px;
        }
        
        /* ========================================
           MOBILE
           ======================================== */
        @media (max-width: 1024px) {
            .container { padding: 15px; }
            .hero-hr h1 { font-size: 24px; }
            .stats-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .stat-card { padding: 14px; }
            .stat-value { font-size: 20px; }
            .spx-filter-bar { flex-wrap: wrap; }
            .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; }
            
            /* Ukryj niektóre kolumny */
            th:nth-child(5), td:nth-child(5),
            th:nth-child(6), td:nth-child(6),
            th:nth-child(7), td:nth-child(7),
            th:nth-child(8), td:nth-child(8) {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .container { padding: 12px; }
            .dashboard-layout { flex-direction: column; }
            .sidebar-wrapper { width: 100%; margin-bottom: 14px; }
            .sidebar-actions { width: 100%; height: auto; position: static; max-height: 260px; border-radius: 12px; }
            .toggle-sidebar-btn { display: none; }
            .sidebar-wrapper.collapsed .sidebar-actions { width: 100%; height: 0; padding: 0; }
            .dashboard-content { padding-left: 0 !important; }
            .hero-hr { border-radius: 10px; padding: 16px; }
            .hero-right { width: 100%; align-items: stretch; }
            .hero-nav { width: 100%; justify-content: flex-start; }
            .hero-actions { width: 100%; justify-content: flex-start; }
            .hero-actions .btn { width: auto; height: 40px; font-size: 13px; }
            
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card {
                padding: 12px; 
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .stat-label { margin-bottom: 0; font-size: 11px; }
            .stat-value { font-size: 18px; }


            .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; }
            .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; }
            .spx-filter-group select,
            .spx-filter-group input[type="date"] {
                width: 100%;
                height: 44px;
                font-size: 14px;
            }
            
            .spx-controls-bar { flex-direction: column; gap: 10px; }
            .spx-controls-left, .spx-controls-right { width: 100%; justify-content: space-between; }
            
            .day-header { padding: 10px 14px; }
            .dh-stats { gap: 10px; font-size: 12px; }
            
            th, td { padding: 8px 10px; font-size: 12px; }
            th:nth-child(3), td:nth-child(3),
            th:nth-child(4), td:nth-child(4),
            th:nth-child(9), td:nth-child(9) {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            
            th:nth-child(2), td:nth-child(2) {
                max-width: 100px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
        }
        
        /* Print */
        @media print {
            .header, .sidebar-wrapper, .hero-hr, .actions, .spx-filter-bar, .spx-controls-bar, .footer, .btn, button { 
                display: none !important; 
            }
            body { background: white; }
            .card, .day-group { box-shadow: none; border: 1px solid #ccc; }
            .day-group.collapsed .day-content { display: block !important; }
            table { font-size: 10px; }
            th, td { padding: 6px 8px; }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="dashboard-layout">
            <div class="sidebar-wrapper collapsed" id="sidebarWrapper">
                <div class="toggle-sidebar-btn" onclick="toggleSidebar()" title="Zwin/Rozwin panel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </div>
                <aside class="sidebar-actions">
                    <div class="sidebar-content-inner">
                        <div class="sidebar-search">
                            <input type="text" id="actionSearch" placeholder="Szukaj akcji..." autocomplete="off">
                        </div>
                        <div class="sidebar-actions-body">
                            <?php foreach ($sidebarQuickActions as $sectionName => $sectionLinks): ?>
                                <details class="sidebar-section">
                                    <summary class="sidebar-section-title"><?php echo e($sectionName); ?></summary>
                                    <div class="sidebar-section-links">
                                        <?php foreach ($sectionLinks as $link): ?>
                                            <a href="<?php echo e($link['url']); ?>" data-keywords="<?php echo e($link['keywords']); ?>">
                                                <?php echo e($link['label']); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                            <details class="sidebar-section">
                                <summary class="sidebar-section-title">Narzedzia</summary>
                                <div class="sidebar-section-links">
                                    <a href="#" onclick="window.print(); return false;" data-keywords="drukuj wydruk">
                                        Drukuj widok
                                    </a>
                                </div>
                            </details>
                        </div>
                    </div>
                </aside>
            </div>
            <div class="dashboard-content">
        <div class="hero-hr">
            <div>
                <div class="hero-breadcrumb"><a href="<?php echo url('dashboard'); ?>">Panel główny</a> › HR › Dziennik pracy</div>
                <h1>Dziennik Pracy</h1>
                <p>Rejestr czasu pracy, nadgodzin i kosztów zespołu</p>
            </div>
            <div class="hero-right">
                <div class="hero-nav">
                    <a href="<?php echo url('hr'); ?>">Lista pracowników</a>
                    <a href="<?php echo url('hr.worklog.index'); ?>" class="active">Dziennik pracy</a>
                    <a href="<?php echo url('hr'); ?>?tab=history">Historia operacji</a>
                    <a href="<?php echo url('hr'); ?>?tab=new">Nowa operacja</a>
                </div>
                <div class="hero-actions">
                    <button onclick="window.print()" class="btn btn-hero-light">Drukuj</button>
                    <a href="<?php echo url('hr.worklog.create'); ?>" class="btn btn-hero-primary">+ Dodaj wpis</a>
                </div>
            </div>
        </div>

        <?php if ($flashSuccess !== ''): ?>
            <div class="flash-message flash-success"><?php echo e($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="flash-message flash-error"><?php echo e($flashError); ?></div>
        <?php endif; ?>
        
        <?php if ($isAdminUser): ?>
        <div class="hr-kpi-section">
            <div class="hr-kpi-section-label">Bieżący miesiąc <span><?php echo $curLabel; ?></span></div>
            <div class="hr-kpi-strip">
                <div class="hr-kpi-card kpi-balance">
                    <div class="hr-kpi-label">Suma sald ogółem</div>
                    <div class="hr-kpi-value <?php echo $totalBalance > 0 ? 'hr-kpi-val-pos' : ($totalBalance < 0 ? 'hr-kpi-val-neg' : ''); ?>">
                        <?php echo ($totalBalance > 0 ? '+' : '') . number_format($totalBalance, 2, ',', ' '); ?> zł
                    </div>
                    <div class="hr-kpi-sub">Cała historia: zarobek − wypłaty − zaliczki</div>
                </div>
                <div class="hr-kpi-card kpi-labor">
                    <div class="hr-kpi-label">Koszt pracy</div>
                    <div class="hr-kpi-value"><?php echo number_format($kpiCur['labor'], 2, ',', ' '); ?> zł</div>
                    <div class="hr-kpi-sub">Zatwierdzone godziny × stawki</div>
                </div>
                <div class="hr-kpi-card kpi-paid">
                    <div class="hr-kpi-label">Wydano gotówki</div>
                    <div class="hr-kpi-value"><?php echo number_format($kpiCur['total_out'], 2, ',', ' '); ?> zł</div>
                    <div class="hr-kpi-sub">Wypłaty/zwroty wg okresu rozliczeniowego</div>
                </div>
                <div class="hr-kpi-card kpi-bonus">
                    <div class="hr-kpi-label">Premie</div>
                    <div class="hr-kpi-value"><?php echo number_format($kpiCur['bonuses'], 2, ',', ' '); ?> zł</div>
                    <div class="hr-kpi-sub">Premie wg okresu rozliczeniowego</div>
                </div>
            </div>
        </div>
        <div class="hr-kpi-section">
            <div class="hr-kpi-section-label">Poprzedni miesiąc <span><?php echo $prevLabel; ?></span></div>
            <div class="hr-kpi-strip">
                <div class="hr-kpi-card kpi-labor">
                    <div class="hr-kpi-label">Koszt pracy</div>
                    <div class="hr-kpi-value"><?php echo number_format($kpiPrev['labor'], 2, ',', ' '); ?> zł</div>
                    <div class="hr-kpi-sub">Zatwierdzone godziny × stawki</div>
                </div>
                <div class="hr-kpi-card kpi-paid">
                    <div class="hr-kpi-label">Wydano gotówki</div>
                    <div class="hr-kpi-value"><?php echo number_format($kpiPrev['total_out'], 2, ',', ' '); ?> zł</div>
                    <div class="hr-kpi-sub">Wypłaty/zwroty wg okresu rozliczeniowego</div>
                </div>
                <div class="hr-kpi-card kpi-bonus">
                    <div class="hr-kpi-label">Premie</div>
                    <div class="hr-kpi-value"><?php echo number_format($kpiPrev['bonuses'], 2, ',', ' '); ?> zł</div>
                    <div class="hr-kpi-sub">Zaksięgowane premie za okres</div>
                </div>
                <div class="hr-kpi-card kpi-cash">
                    <div class="hr-kpi-label">Do wypłaty</div>
                    <div class="hr-kpi-value <?php echo $kpiPrev['cash_needed'] > 0 ? 'hr-kpi-val-neg' : 'hr-kpi-val-pos'; ?>">
                        <?php echo number_format($kpiPrev['cash_needed'], 2, ',', ' '); ?> zł
                    </div>
                    <div class="hr-kpi-sub">Saldo okresu + premie = gotówka do ręki</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Statystyki -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Godziny Pracy</div>
                <div class="stat-value"><?php echo number_format($stats['total_hours'], 1, ',', ' '); ?> h</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Nadgodziny</div>
                <div class="stat-value"><?php echo number_format($stats['total_overtime'], 1, ',', ' '); ?> h</div>
            </div>
            <?php if ($stats['vacation_days'] > 0): ?>
            <div class="stat-card" style="border-left: 3px solid var(--success);">
                <div class="stat-label">Urlop</div>
                <div class="stat-value" style="color: var(--success);"><?php echo number_format($stats['vacation_days'], 1, ',', ' '); ?> dni</div>
            </div>
            <?php endif; ?>
            <?php if ($stats['sick_days'] > 0): ?>
            <div class="stat-card" style="border-left: 3px solid var(--danger);">
                <div class="stat-label">L4</div>
                <div class="stat-value" style="color: var(--danger);"><?php echo number_format($stats['sick_days'], 1, ',', ' '); ?> dni</div>
            </div>
            <?php endif; ?>
            <div class="stat-card">
                <div class="stat-label">Do zatwierdzenia</div>
                <div class="stat-value"><?php echo $stats['pending_count']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Koszt</div>
                <div class="stat-value"><?php echo formatMoney($stats['total_cost']); ?></div>
            </div>
        </div>
        
        <div class="card">
            <?php
            // Aktywny miesiąc i rok
            $filterYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            if ($filterYear < 2020 || $filterYear > 2030) $filterYear = (int)date('Y');
            $filterMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
            // Jeśli wybrano miesiąc+rok z selecta, ustaw date_from/date_to
            if ($filterMonth >= 1 && $filterMonth <= 12 && !isset($_GET['date_from'])) {
                $filterDateFrom = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
                $filterDateTo   = date('Y-m-t', strtotime($filterDateFrom));
            }
            $monthFullNames = [
                1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',
                7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'
            ];
            $yearRange = range((int)date('Y') - 2, (int)date('Y'));
            // Ustal aktywny miesiąc na podstawie zakresu dat
            $activeMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $filterYear, $m);
                $mE = date('Y-m-t', strtotime($mS));
                if ($filterDateFrom === $mS && $filterDateTo === $mE) { $activeMonth = $m; break; }
            }
            // Base query string (inne filtry bez dat)
            $baseQs = http_build_query(array_filter([
                'worker'  => $filterWorker  ?: null,
                'project' => $filterProject ?: null,
                'status'  => $filterStatus  ?: null,
            ]));
            $baseQs = $baseQs ? '&' . $baseQs : '';
            ?>

            <?php
            $yearStart = date('Y-01-01');
            $isAllActive = (empty($filterDateFrom) && empty($filterDateTo));
            $isYearActive = ($filterDateFrom === $yearStart && $filterDateTo === $today);
            ?>
            <!-- GŁÓWNY PASEK FILTRÓW -->
            <form method="GET" action="" class="spx-filter-bar" id="filterForm">
                <?php if ($isAdminUser): ?>
                <div class="spx-filter-group fg-worker">
                    <label>Pracownik</label>
                    <select name="worker">
                        <option value="0">Wszyscy</option>
                        <?php foreach ($workers as $w): ?>
                            <option value="<?php echo $w['id']; ?>" <?php echo ($filterWorker == $w['id']) ? 'selected' : ''; ?>>
                                <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="spx-filter-group fg-project">
                    <label>Projekt</label>
                    <select name="project">
                        <option value="0">Wszystkie</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" <?php echo ($filterProject == $proj['id']) ? 'selected' : ''; ?>>
                                <?php echo e($proj['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-month">
                    <label>Miesiac</label>
                    <select name="month" id="selectMonth" onchange="onMonthYearChange()">
                        <option value="0" <?php echo $activeMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($monthFullNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($activeMonth === $mn) ? 'selected' : ''; ?>>
                                <?php echo $mName; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-year">
                    <label>Rok</label>
                    <select name="year" id="selectYear" onchange="onMonthYearChange()">
                        <?php foreach ($yearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($filterYear == $yr) ? 'selected' : ''; ?>>
                                <?php echo $yr; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Od</label>
                    <input type="date" name="date_from" id="inputDateFrom" value="<?php echo e($filterDateFrom); ?>">
                </div>
                <div class="spx-filter-group fg-date">
                    <label>Do</label>
                    <input type="date" name="date_to" id="inputDateTo" value="<?php echo e($filterDateTo); ?>">
                </div>
                <div class="spx-filter-group fg-status">
                    <label>Status</label>
                    <select name="status">
                        <option value="">Wszystkie</option>
                        <option value="pending" <?php echo ($filterStatus === 'pending') ? 'selected' : ''; ?>>Oczekujace</option>
                        <option value="approved" <?php echo ($filterStatus === 'approved') ? 'selected' : ''; ?>>Zatwierdzone</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                <?php if ($filterDateFrom !== '' || $filterDateTo !== '' || $filterWorker > 0 || $filterProject > 0 || $filterStatus !== ''): ?>
                    <a href="?" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center; flex-shrink: 0; white-space: nowrap;">Resetuj</a>
                <?php endif; ?>
            </form>

            <!-- PASEK KONTROLEK -->
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <a href="?date_from=<?php echo $today; ?>&date_to=<?php echo $today; ?><?php echo $baseQs; ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $today && $filterDateTo === $today) ? 'active' : ''; ?>">Dzis</a>
                    <a href="?date_from=<?php echo $weekAgo; ?>&date_to=<?php echo $today; ?><?php echo $baseQs; ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $weekAgo && $filterDateTo === $today) ? 'active' : ''; ?>">7 dni</a>
                    <a href="?date_from=<?php echo $monthStart; ?>&date_to=<?php echo $monthEnd; ?>&year=<?php echo date('Y'); ?><?php echo $baseQs; ?>"
                       class="spx-quick-btn <?php echo ($filterDateFrom === $monthStart && $filterDateTo === $monthEnd) ? 'active' : ''; ?>">Ten miesiac</a>
                    <a href="?date_from=<?php echo $yearStart; ?>&date_to=<?php echo $today; ?><?php echo $baseQs; ?>"
                       class="spx-quick-btn <?php echo $isYearActive ? 'active' : ''; ?>">Ten rok</a>
                    <a href="?<?php echo ltrim($baseQs, '&'); ?>"
                       class="spx-quick-btn <?php echo $isAllActive ? 'active' : ''; ?>">Wszystko</a>
                </div>
                <div class="spx-controls-right">
                    <button class="btn-color-mode active" onclick="toggleColors()" title="Kolory pracownikow">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 2a10 10 0 0 1 0 20"/>
                            <circle cx="12" cy="12" r="4"/>
                        </svg>
                    </button>
                    <div style="width: 1px; height: 22px; background: var(--border);"></div>
                    <div class="spx-toggle-group">
                        <button class="spx-btn-toggle" onclick="expandAll()">Rozwin</button>
                        <button class="spx-btn-toggle" onclick="collapseAll()">Zwin</button>
                    </div>
                </div>
            </div>
            </div>
            
            <?php if (empty($logs)): ?>
            <div class="card">
                <div class="no-data">
                    Brak wpisow dla wybranych filtrow.
                    <br><br>
                    <a href="<?php echo url('hr.worklog.create'); ?>" class="btn btn-primary">Dodaj pierwszy wpis</a>
                </div>
                </div>
            <?php else: ?>
            <!-- Sekcje dni -->
            <?php foreach ($logsByDate as $date => $dayData): ?>
                <?php 
                    $dayName = getPolishDayName($date);
                    $logsCount = count($dayData['logs']);
                    $totalHours = $dayData['total_hours'];
                    $totalCost  = $dayData['total_cost'];
                    $pendingCount = $dayData['pending_count'];
                    $allApproved = ($pendingCount === 0);
                ?>
                <div class="day-group" data-date="<?php echo $date; ?>">
                    <div class="day-header" onclick="toggleDay(this)">
                        <div class="dh-left">
                            <span class="dh-dayname"><?php echo $dayName; ?></span>
                            <span class="dh-date"><?php echo formatDate($date); ?></span>
                        </div>
                        <div class="dh-right">
                            <div class="dh-stats">
                                <div class="dh-stat">
                                    <span class="dh-stat-value"><?php echo $logsCount; ?></span>
                                    <span>wpis<?php echo ($logsCount == 1) ? '' : (($logsCount > 1 && $logsCount < 5) ? 'y' : 'ow'); ?></span>
                                </div>
                                <div class="dh-stat">
                                    <span class="dh-stat-value"><?php echo number_format($totalHours, 1, ',', ''); ?></span>
                                    <span>h</span>
                                </div>
                                <div class="dh-stat">
                                    <span class="dh-stat-value" style="color: var(--primary);"><?php echo formatMoney($totalCost); ?></span>
                                </div>
                            </div>
                            <?php if ($isAdminUser && $pendingCount > 0): ?>
                                <a href="<?php echo url('hr.worklog.approve_batch', ['date' => $date]); ?>"
                                   class="btn-approve-day"
                                   onclick="event.stopPropagation();">
                                    Zatwierdz (<?php echo $pendingCount; ?>)
                                </a>
                            <?php elseif ($allApproved && $logsCount > 0): ?>
                                <span style="color: var(--success); font-weight: 600; font-size: 12px;">OK</span>
                            <?php endif; ?>
                            <span class="dh-arrow">&#9660;</span>
                        </div>
                    </div>
                    <div class="day-content">
                <table>
                    <thead>
                        <tr>
                                    <th style="padding-left: 20px;">Pracownik</th>
                                    <th>Projekt / Etap</th>
                                    <th style="text-align: right;">Czas</th>
                                    <th style="text-align: right;">Nadg.</th>
                                    <th style="text-align: center;">Sob</th>
                                    <th style="text-align: center;">Ndz</th>
                                    <th style="text-align: center;">Noc</th>
                                    <th style="text-align: center;">Del</th>
                                    <th style="text-align: right;">Koszt</th>
                                    <th class="col-status">Status</th>
                                    <th class="col-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                                <?php foreach ($dayData['logs'] as $log): ?>
                            <?php 
                                $workType = $log['work_type'] ?? 'work';
                                $isVacation = ($workType === 'vacation');
                                $isSick = ($workType === 'sick');
                                $isWork = ($workType === 'work');
                                
                                        // Kolor pracownika
                                        $workerColor = getWorkerColor($log['worker_id']);
                                        
                                        // Klasa wiersza
                                        $rowClass = '';
                                        if ($isSick) $rowClass = 'row-sick';
                                        elseif ($isVacation) $rowClass = 'row-vacation';
                                        
                                        // Styl wiersza (kolor pracownika)
                                        $rowStyle = '';
                                        if (!$isSick && !$isVacation) {
                                            $rowStyle = "--worker-color: {$workerColor['hslBorder']}; --worker-bg: {$workerColor['hslLight']};";
                                        }
                                        
                                        // Uprawnienia
                                $canEdit = false;
                                $canDelete = false;
                                if ($isAdminUser) {
                                    $canEdit = ($log['status'] !== 'locked');
                                    $canDelete = ($log['status'] !== 'locked');
                                } elseif ($currentWorkerId && $log['worker_id'] == $currentWorkerId) {
                                    $canEdit = ($log['status'] === 'pending');
                                    $canDelete = ($log['status'] === 'pending');
                                }
                                        $absenceDays = normalizeAbsenceDays($log);
                                        
                                        // Godziny
                                        $hours = $isWork ? (float)($log['workday_hours'] ?? $log['hours']) : 0;
                                        $overtime = $isWork ? (float)($log['workday_overtime'] ?? $log['overtime_hours'] ?? 0) : 0;
                                        $satHours = $isWork ? (float)(($log['saturday_hours'] ?? 0) + ($log['saturday_overtime'] ?? 0)) : 0;
                                        $sunHours = $isWork ? (float)(($log['sunday_hours'] ?? 0) + ($log['sunday_overtime'] ?? 0)) : 0;
                                        $nightHours = $isWork ? (float)(($log['night_hours'] ?? 0) + ($log['night_overtime'] ?? 0)) : 0;
                                        $delegHours = $isWork ? (float)(($log['delegation_hours'] ?? 0) + ($log['delegation_overtime'] ?? 0)) : 0;
                                        $cost = (float)($log['final_cost'] ?: $log['system_cost']);
                                    ?>
                                    <tr class="<?php echo $rowClass; ?>" style="<?php echo $rowStyle; ?>" data-worker-id="<?php echo $log['worker_id']; ?>">
                                        <td class="col-worker" style="padding-left: 20px;">
                                            <a href="<?php echo url('hr.workers.profile', ['id' => $log['worker_id']]); ?>" class="worker-link">
                                        <?php echo e($log['first_name'] . ' ' . $log['last_name']); ?>
                                    </a>
                                </td>
                                        <td class="col-project">
                                    <?php if ($isSick): ?>
                                                <span style="font-weight: 500;">Zwolnienie lekarskie</span>
                                            <?php elseif ($isVacation): ?>
                                                <span style="font-weight: 500;">Urlop</span>
                                    <?php else: ?>
                                                <div class="col-project-name"><?php echo $log['project_name'] ? e($log['project_name']) : '-'; ?></div>
                                                <?php if ($log['cost_node_name']): ?>
                                                    <div class="col-project-stage"><?php echo e($log['cost_node_name']); ?></div>
                                                <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                        <td class="col-hours">
                                            <?php echo $isWork ? number_format($hours, 2, ',', '') : number_format($absenceDays, 1, ',', '') . ' dni'; ?>
                                </td>
                                        <td style="text-align: right;"><?php echo ($isWork && $overtime > 0) ? number_format($overtime, 2, ',', '') : '-'; ?></td>
                                        <td style="text-align: center;"><?php echo ($satHours > 0) ? number_format($satHours, 1, ',', '') : '-'; ?></td>
                                        <td style="text-align: center;"><?php echo ($sunHours > 0) ? number_format($sunHours, 1, ',', '') : '-'; ?></td>
                                        <td style="text-align: center;"><?php echo ($nightHours > 0) ? number_format($nightHours, 1, ',', '') : '-'; ?></td>
                                        <td style="text-align: center;"><?php echo ($delegHours > 0) ? number_format($delegHours, 1, ',', '') : '-'; ?></td>
                                        <?php
                                        $rateInfo = buildRateInfo($log);
                                        $rateRowId = 'rate-det-' . $log['id'];
                                        ?>
                                        <td class="col-cost">
                                            <?php echo formatMoney($cost); ?>
                                            <?php if ($rateInfo['show_meta']): ?>
                                                <div class="rate-meta"><?php echo $rateInfo['meta_text']; ?></div>
                                                <?php if ($rateInfo['is_mixed']): ?>
                                                    <button type="button"
                                                            class="rate-toggle js-rate-toggle"
                                                            data-target="<?php echo $rateRowId; ?>"
                                                            aria-expanded="false">
                                                        Szczegoly stawek
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                <td class="col-status">
                                    <?php if ($log['status'] === 'pending'): ?>
                                                <?php if ($isAdminUser): ?>
                                                    <a href="<?php echo url('hr.worklog.approve_batch', ['date' => $log['date']]); ?>" title="Zatwierdz wpisy z tego dnia">
                                                        <span class="status-dot pending"></span>
                                        </a>
                                                <?php else: ?>
                                                    <span class="status-dot pending" title="Oczekuje"></span>
                                                <?php endif; ?>
                                    <?php elseif ($log['status'] === 'approved'): ?>
                                        <span class="status-dot approved" title="Zatwierdzony"></span>
                                    <?php else: ?>
                                        <span class="status-dot locked" title="Zablokowany"></span>
                                    <?php endif; ?>
                                </td>
                                        <td class="col-actions">
                                    <div class="action-buttons">
                                        <?php if ($canEdit): ?>
                                                    <a href="<?php echo url('hr.worklog.edit', ['id' => $log['id'], 'return_url' => $currentWorklogReturnUrl]); ?>" class="action-btn action-btn-edit">Edytuj</a>
                                        <?php endif; ?>
                                        <?php if ($canDelete): ?>
                                                    <a href="<?php echo url('hr.worklog.delete', ['id' => $log['id'], 'return_url' => $currentWorklogReturnUrl]); ?>" class="action-btn action-btn-delete">Usun</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                                        <?php if ($rateInfo['is_mixed']): ?>
                                            <tr id="<?php echo $rateRowId; ?>" class="rate-details-row" hidden>
                                                <td colspan="11">
                                                    <div class="rate-details-panel">
                                                        <div class="rate-details-title">Rozbicie stawek</div>
                                                        <table class="rate-details-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>Skladnik</th>
                                                                    <th class="rate-num">Godziny</th>
                                                                    <th class="rate-num">Stawka</th>
                                                                    <th class="rate-num">Kwota</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($rateInfo['segments'] as $seg): ?>
                                                                    <tr>
                                                                        <td><?php echo e($seg['label']); ?></td>
                                                                        <td class="rate-num"><?php echo number_format((float)$seg['hours'], 2, ',', ' '); ?> h</td>
                                                                        <td class="rate-num"><?php echo number_format((float)$seg['rate'], 2, ',', ' '); ?> zl/h</td>
                                                                        <td class="rate-num"><?php echo formatMoney((float)$seg['amount']); ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                                </div>
                            </div>
            <?php endforeach; ?>
                                <?php endif; ?>
                                </div><!-- /dashboard-content -->
                            </div><!-- /dashboard-layout -->
                        </div><!-- /container -->
                                
    <footer class="footer">
        &copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?> v<?php echo e(APP_VERSION); ?>
    </footer>
    
    <script>
        function toggleSidebar() {
            const wrapper = document.getElementById('sidebarWrapper');
            if (!wrapper) return;
            wrapper.classList.toggle('collapsed');
            const isCollapsed = wrapper.classList.contains('collapsed');
            localStorage.setItem('brygad_hr_worklog_sidebar_collapsed', isCollapsed ? 'true' : 'false');
        }

        // ========================================
        // MIESIĄC + ROK -> data_from / date_to
        // ========================================
        function onMonthYearChange() {
            const month = parseInt(document.getElementById('selectMonth').value);
            const year  = parseInt(document.getElementById('selectYear').value);
            if (!month) return; // wybrano "-- Wybierz --" — nie nadpisuj dat

            // Oblicz ostatni dzień miesiąca
            const lastDay = new Date(year, month, 0).getDate();
            const pad = n => String(n).padStart(2, '0');

            document.getElementById('inputDateFrom').value = year + '-' + pad(month) + '-01';
            document.getElementById('inputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);

            // Wyślij formularz
            document.getElementById('filterForm').submit();
        }

        // ========================================
        // ZWIJANIE/ROZWIJANIE DNI
        // ========================================
        function toggleDay(header) {
            header.parentElement.classList.toggle('collapsed');
        }
        
        function expandAll() {
            document.querySelectorAll('.day-group').forEach(g => g.classList.remove('collapsed'));
        }
        
        function collapseAll() {
            document.querySelectorAll('.day-group').forEach(g => g.classList.add('collapsed'));
        }
        
        // ========================================
        // TOGGLE KOLORÓW PRACOWNIKÓW
        // ========================================
        function toggleColors() {
            document.body.classList.toggle('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            btn.classList.toggle('active');
            
            // Zapisz stan w localStorage
            const colorsEnabled = !document.body.classList.contains('no-colors');
            localStorage.setItem('worklog_colors', colorsEnabled ? '1' : '0');
        }
        
        // Przywróć stan kolorów z localStorage
        document.addEventListener('DOMContentLoaded', function() {
            const wrapper = document.getElementById('sidebarWrapper');
            if (wrapper) {
                const stored = localStorage.getItem('brygad_hr_worklog_sidebar_collapsed');
                const isCollapsed = stored === null ? true : stored === 'true';
                wrapper.classList.toggle('collapsed', isCollapsed);
            }

            const colorsEnabled = localStorage.getItem('worklog_colors');
            if (colorsEnabled === '0') {
                document.body.classList.add('no-colors');
                document.querySelector('.btn-color-mode').classList.remove('active');
            }
            
            // Domyślnie zwiń wszystkie dni
            collapseAll();

        });

        (function() {
            const searchInput = document.getElementById('actionSearch');
            if (!searchInput) return;
            const sections = document.querySelectorAll('.sidebar-section');
            const links = document.querySelectorAll('.sidebar-actions a[data-keywords]');

            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                if (query === '') {
                    sections.forEach(s => { s.style.display = ''; if (s._autoOpened) { s.open = false; s._autoOpened = false; } });
                    links.forEach(l => l.style.display = '');
                    return;
                }
                const visibleSections = new Set();
                links.forEach(link => {
                    const keywords = link.getAttribute('data-keywords') || '';
                    const text = link.textContent.toLowerCase();
                    if (text.includes(query) || keywords.includes(query)) {
                        link.style.display = '';
                        const section = link.closest('.sidebar-section');
                        if (section) {
                            if (!section.open) {
                                section.open = true;
                                section._autoOpened = true;
                            }
                            visibleSections.add(section);
                        }
                    } else {
                        link.style.display = 'none';
                    }
                });
                sections.forEach(s => { s.style.display = visibleSections.has(s) ? '' : 'none'; });
            });
        })();
        
        // Zatwierdzanie obsługuje approve_batch.php (linki w nagłówkach dni)

        // ========================================
        // SZCZEGÓŁY STAWEK - toggle
        // ========================================
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.js-rate-toggle');
            if (!btn) return;

            const targetId  = btn.getAttribute('data-target');
            const targetRow = targetId ? document.getElementById(targetId) : null;
            if (!targetRow) return;

            const isExpanded = btn.getAttribute('aria-expanded') === 'true';

            // Zamknij wszystkie inne
            document.querySelectorAll('.rate-details-row').forEach(r => { r.hidden = true; });
            document.querySelectorAll('.js-rate-toggle').forEach(b => {
                b.setAttribute('aria-expanded', 'false');
                b.textContent = 'Szczegoly stawek';
            });

            if (!isExpanded) {
                targetRow.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = 'Ukryj szczegoly';
            }
        });
    </script>
</body>
</html>
