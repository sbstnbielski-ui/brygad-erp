<?php
/**
 * BRYGAD ERP v3.0 - Raport Pracownika od daty do daty
 * Widok: admin widzi wszystkich, pracownik widzi tylko siebie
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
require_once dirname(__DIR__, 2) . '/includes/payroll_helper.php';
require_once dirname(__DIR__, 2) . '/includes/absence_helper.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

// TWARDY GATE UPRAWNIEŃ
if ($isAdminUser) {
    // Admin może wybrać pracownika z URL
    $workerId = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
} else {
    // Worker widzi TYLKO siebie - ignoruj GET['worker_id']
    if (!$currentWorkerId) {
        header('Location: balance.php');
        exit;
    }
    $workerId = $currentWorkerId;
    
    // Jeśli ktoś próbuje manipulować URL
    if (isset($_GET['worker_id']) && (int)$_GET['worker_id'] != $currentWorkerId) {
        logEvent("SECURITY: Worker ID $currentWorkerId próbował podejrzeć raport worker ID " . $_GET['worker_id'], 'WARNING');
        header('Location: balance.php');
        exit;
    }
}

// Pobierz listę pracowników dla admina (do wyboru)
$workersList = [];
if ($isAdminUser) {
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $workersList = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent("Błąd pobierania listy pracowników: " . $e->getMessage(), 'ERROR');
    }
}

// Pobierz dane pracownika (jeśli wybrano)
$worker = null;
if ($workerId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM workers WHERE id = ?");
        $stmt->execute([$workerId]);
        $worker = $stmt->fetch();
        
        if (!$worker) {
            die("Pracownik nie został znaleziony.");
        }
    } catch (PDOException $e) {
        die("Błąd: " . $e->getMessage());
    }
}

// Pobierz dane raportu tylko jeśli wybrano pracownika
$workLogs = [];
$expenses = [];
$settlements = [];
$newAdvances = [];
$workSummary = [
    'hours' => 0,
    'overtime' => 0,
    'normal' => 0,
    'saturday' => 0,
    'sunday' => 0,
    'night' => 0,
    'delegation' => 0,
    'sick_days' => 0,
    'vacation_days' => 0,
    'cost' => 0
];
$expensesTotal = 0;
$settlementsSummary = ['payouts' => 0, 'advances_private' => 0, 'advances_company' => 0, 'reimbursements' => 0, 'bonuses' => 0, 'corrections' => 0];
$newAdvancesTotal = 0;
$bonusTotal = 0;
$correctionTotal = 0;
$paidOutTotal = 0;
$privateAdvancesTotal = 0;
$companyAdvancesTotal = 0;
$dueTotal = 0;

// Filtr projektu
$filterProject = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

// Walidacja dat — domyślnie BRAK ograniczenia (pusty zakres = wszystko)
$dateFrom = $_GET['date_from'] ?? $_POST['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? $_POST['date_to']   ?? '';

// Walidacja dat tylko jeśli podane
if ($dateFrom !== '') {
    $dateFrom = date('Y-m-d', strtotime($dateFrom));
    if ($dateFrom > date('Y-m-d')) $dateFrom = '';
}
if ($dateTo !== '') {
    $dateTo = date('Y-m-d', strtotime($dateTo));
    if ($dateTo > date('Y-m-d')) $dateTo = date('Y-m-d');
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    $dateFrom = $dateTo;
}

// Pobierz listę projektów tego pracownika (do dropdown)
$workerProjects = [];
if ($workerId > 0 && $worker) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT p.id, p.name
            FROM work_logs wl
            JOIN projects p ON p.id = wl.project_id
            WHERE wl.worker_id = ? AND wl.status = 'approved'
            ORDER BY p.name
        ");
        $stmt->execute([$workerId]);
        $workerProjects = $stmt->fetchAll();
    } catch (PDOException $e) {
        $workerProjects = [];
    }
}

if ($workerId > 0 && $worker) {

    // ── 1. Czas pracy (tylko zatwierdzone) ──────────────────────────
    try {
        $where1 = ["wl.worker_id = ?", "wl.status = 'approved'"];
        $params1 = [$workerId];
        if ($dateFrom !== '') { $where1[] = "wl.date >= ?"; $params1[] = $dateFrom; }
        if ($dateTo   !== '') { $where1[] = "wl.date <= ?"; $params1[] = $dateTo; }
        if ($filterProject > 0) { $where1[] = "wl.project_id = ?"; $params1[] = $filterProject; }

        $stmt = $pdo->prepare("
            SELECT 
                wl.date,
                wl.hours,
                wl.overtime_hours,
                wl.absence_days,
                wl.is_paid,
                wl.work_type,
                wl.is_saturday,
                wl.is_sunday,
                wl.is_night,
                wl.is_delegation,
                wl.description,
                COALESCE(wl.final_cost, wl.system_cost) as cost,
                wl.final_cost,
                wl.system_cost,
                wl.system_rate_snapshot,
                p.name as project_name,
                pcn.name as cost_node_name
            FROM work_logs wl
            LEFT JOIN projects p ON p.id = wl.project_id
            LEFT JOIN project_cost_nodes pcn ON pcn.id = wl.cost_node_id
            WHERE " . implode(' AND ', $where1) . "
            ORDER BY wl.date DESC, wl.id DESC
        ");
        $stmt->execute($params1);
        $workLogs = $stmt->fetchAll();
        
        // Oblicz podsumowanie czasu pracy z wyszczególnieniem
        foreach ($workLogs as $log) {
            $hours = (float)$log['hours'];
            $overtime = (float)$log['overtime_hours'];
            $workType = $log['work_type'] ?? 'work';
            $absenceDays = normalizeAbsenceDays($log);
            
            // Zlicz według typu pracy
            if ($workType === 'sick') {
                $workSummary['sick_days'] += $absenceDays;
            } elseif ($workType === 'vacation') {
                $workSummary['vacation_days'] += $absenceDays;
            } else {
                $workSummary['hours'] += $hours;
                $workSummary['overtime'] += $overtime;
                
                if (!empty($log['is_sunday'])) {
                    $workSummary['sunday'] += $hours;
                } elseif (!empty($log['is_saturday'])) {
                    $workSummary['saturday'] += $hours;
                } elseif (!empty($log['is_night'])) {
                    $workSummary['night'] += $hours;
                } else {
                    $workSummary['normal'] += $hours;
                }
                
                if (!empty($log['is_delegation'])) {
                    $workSummary['delegation'] += $hours;
                }
            }
            
            $cost = $log['cost'];
            if ($cost !== null) {
                $workSummary['cost'] += (float)$cost;
            }
        }
    } catch (PDOException $e) {
        logEvent("REPORT: work_logs query error for worker $workerId: " . $e->getMessage(), 'ERROR');
    }
    
    // ── 2. Wydatki pracownika (zatwierdzone lub rozliczone) ─────────
    try {
        // Sprawdź czy kolumna paid_by_employee istnieje
        $hasPaidByEmployee = false;
        try {
            $colCheck = $pdo->query("SELECT paid_by_employee FROM worker_expenses LIMIT 0");
            $hasPaidByEmployee = true;
        } catch (PDOException $e) {
            // Kolumna nie istnieje - pomijamy ją
        }
        
        $where2 = ["we.worker_id = ?", "we.status IN ('approved', 'reimbursed')"];
        $params2 = [$workerId];
        if ($dateFrom !== '') { $where2[] = "we.date >= ?"; $params2[] = $dateFrom; }
        if ($dateTo   !== '') { $where2[] = "we.date <= ?"; $params2[] = $dateTo; }
        $where2[] = $hasPaidByEmployee
            ? "(we.paid_by_employee = 1 OR we.description LIKE '%[PAID_BY_EMPLOYEE]%')"
            : "we.description LIKE '%[PAID_BY_EMPLOYEE]%'";

        $expensesSql = "
            SELECT 
                we.date,
                we.amount,
                we.description,
                we.status,
                " . ($hasPaidByEmployee ? "we.paid_by_employee," : "0 as paid_by_employee,") . "
                p.name as project_name,
                pcn.name as cost_node_name
            FROM worker_expenses we
            LEFT JOIN projects p ON p.id = we.project_id
            LEFT JOIN project_cost_nodes pcn ON pcn.id = we.cost_node_id
            WHERE " . implode(' AND ', $where2) . "
            ORDER BY we.date DESC, we.id DESC
        ";
        $stmt = $pdo->prepare($expensesSql);
        $stmt->execute($params2);
        $expenses = $stmt->fetchAll();
        
        foreach ($expenses as $expIndex => $exp) {
            $isPaidByEmployee = !empty($exp['paid_by_employee']) || strpos((string)($exp['description'] ?? ''), '[PAID_BY_EMPLOYEE]') !== false;
            $expenses[$expIndex]['is_paid_by_employee'] = $isPaidByEmployee ? 1 : 0;
            if ($isPaidByEmployee) {
                $expensesTotal += (float)$exp['amount'];
            }
        }
    } catch (PDOException $e) {
        logEvent("REPORT: expenses query error for worker $workerId: " . $e->getMessage(), 'ERROR');
    }
    
    // ── 3. Rozliczenia (zaksięgowane w okresie) ─────────────────────
    try {
        $settlementDateExpr = "COALESCE(NULLIF(s.period, '0000-00-00'), s.date)";
        $where3 = ["s.worker_id = ?"];
        $params3 = [$workerId];
        if ($dateFrom !== '') { $where3[] = $settlementDateExpr . " >= ?"; $params3[] = $dateFrom; }
        if ($dateTo   !== '') { $where3[] = $settlementDateExpr . " <= ?"; $params3[] = $dateTo; }
        $where3[] = "NOT (
            s.type = 'advance'
            AND (s.advance_kind = 'private' OR s.advance_kind IS NULL)
            AND EXISTS (
                SELECT 1
                FROM worker_advances wa_dup
                WHERE wa_dup.worker_id = s.worker_id
                  AND wa_dup.type = 'PRIVATE'
                  AND wa_dup.issue_date = s.date
                  AND wa_dup.amount = s.amount
            )
        )";
        $stmt = $pdo->prepare("
            SELECT 
                s.date AS operation_date,
                " . $settlementDateExpr . " AS report_date,
                s.period,
                s.type,
                s.amount,
                s.description,
                s.advance_kind
            FROM settlements s
            WHERE " . implode(' AND ', $where3) . "
            ORDER BY report_date DESC, s.id DESC
        ");
        $stmt->execute($params3);
        $settlements = $stmt->fetchAll();
        
        // Oblicz podsumowanie rozliczeń
        foreach ($settlements as $settlement) {
            $type = $settlement['type'];
            $amount = (float)$settlement['amount'];
            
            if ($type === 'payout') {
                $settlementsSummary['payouts'] += $amount;
            } elseif ($type === 'advance') {
                $advanceKindValue = normalizeAdvanceKindValue((string)($settlement['advance_kind'] ?? ''), 'private');
                if (in_array($advanceKindValue, ['private', 'company_private'], true)) {
                    $settlementsSummary['advances_private'] += $amount;
                } else {
                    $settlementsSummary['advances_company'] += $amount;
                }
            } elseif ($type === 'reimbursement') {
                $settlementsSummary['reimbursements'] += $amount;
            } elseif ($type === 'bonus') {
                $settlementsSummary['bonuses'] += $amount;
            } elseif ($type === 'correction') {
                $settlementsSummary['corrections'] += $amount;
            }
        }
    } catch (PDOException $e) {
        logEvent("REPORT: settlements query error for worker $workerId: " . $e->getMessage(), 'ERROR');
    }
    
    // ── 4. Nowe zaliczki (worker_advances) - przypisane do okresu wypłaty ───────
    try {
        $advancePeriodExpr = payrollAdvancePeriodSql($pdo, 'wa');
        $salaryPeriodSelect = payrollSalaryPeriodSelectSql($pdo, 'wa');
        $where4 = ["wa.worker_id = ?"];
        $params4 = [$workerId];
        if ($dateFrom !== '') { $where4[] = $advancePeriodExpr . " >= ?"; $params4[] = $dateFrom; }
        if ($dateTo   !== '') { $where4[] = $advancePeriodExpr . " <= ?"; $params4[] = $dateTo; }
        $stmt = $pdo->prepare("
            SELECT 
                wa.id,
                wa.issue_date as operation_date,
                {$advancePeriodExpr} as report_date,
                {$salaryPeriodSelect} as salary_period,
                wa.amount,
                wa.type as advance_type,
                wa.description,
                wa.status
            FROM worker_advances wa
            WHERE " . implode(' AND ', $where4) . "
            ORDER BY report_date DESC, wa.issue_date DESC, wa.id DESC
        ");
        $stmt->execute($params4);
        $newAdvances = $stmt->fetchAll();
        
        // Zsumuj nowe zaliczki i dodaj do odpowiednich kategorii w settlementsSummary
        $newAdvancesTotal = 0;
        foreach ($newAdvances as $adv) {
            $advAmount = (float)$adv['amount'];
            $newAdvancesTotal += $advAmount;
            
            // INTEGRACJA: Dodaj do odpowiedniej kategorii zaliczek
            if ($adv['advance_type'] === 'PRIVATE') {
                $settlementsSummary['advances_private'] += $advAmount;
            } elseif ($adv['advance_type'] === 'COMPANY') {
                $settlementsSummary['advances_company'] += $advAmount;
            }
        }
    } catch (PDOException $e) {
        logEvent("REPORT: worker_advances query error for worker $workerId: " . $e->getMessage(), 'ERROR');
    }

}

// Premia powiększa należne — szef musi ją pokryć wypłatą.
// Saldo = (zarobek + wydatki + korekty + premie) - wypłaty - zaliczki
$bonusTotal = $settlementsSummary['bonuses'];
$correctionTotal = $settlementsSummary['corrections'];
$payoutsAndReimbursements = $settlementsSummary['payouts'] + $settlementsSummary['reimbursements'];
$paidOutTotal = $payoutsAndReimbursements;
$privateAdvancesTotal = $settlementsSummary['advances_private'];
$companyAdvancesTotal = $settlementsSummary['advances_company'];
$totalPaidToWorker = $paidOutTotal + $privateAdvancesTotal;
$dueTotal = $workSummary['cost'] + $expensesTotal + $correctionTotal + $bonusTotal;
$periodBalance = $dueTotal - $paidOutTotal - $privateAdvancesTotal;

$workerName = $worker ? trim(($worker['first_name'] ?? '') . ' ' . ($worker['last_name'] ?? '')) : '';
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$workerHistoryUrl = url('hr') . '?tab=history&worker_id=' . (int)$workerId;

// ── Stan portfela firmowego pracownika (do karty w sekcji C) ──────────
$walletBalance   = 0.0;
$walletOpenCount = 0;
if ($workerId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(
                    wa.amount - COALESCE((
                        SELECT SUM(wl.amount) FROM worker_ledger wl
                        WHERE wl.advance_id = wa.id AND wl.amount > 0
                    ), 0)
                ), 0) AS balance,
                COUNT(*) AS open_count
            FROM worker_advances wa
            WHERE wa.worker_id = ? AND wa.type = 'COMPANY' AND wa.status = 'open'
        ");
        $stmt->execute([$workerId]);
        $walletRow = $stmt->fetch();
        $walletBalance   = max(0.0, (float)($walletRow['balance'] ?? 0));
        $walletOpenCount = (int)($walletRow['open_count'] ?? 0);
    } catch (PDOException $e) {
        logEvent("REPORT: wallet balance error for worker $workerId: " . $e->getMessage(), 'ERROR');
    }
}

// ── Lista pracowników do formularza inline ────────────────────────────
$formWorkers = [];
if ($isAdminUser) {
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $formWorkers = $stmt->fetchAll();
    } catch (PDOException $e) { /* ignoruj */ }
}

// ── Obsługa formularza inline (POST nowej operacji) ───────────────────
$inlineFormErrors  = [];
$inlineFormSuccess = '';
$inlineFormToken   = '';

function rptOperationTypeLabel(string $t): string {
    return [
        'payout'           => 'Wypłata',
        'advance_private'  => 'Zaliczka prywatna',
        'advance_company'  => 'Zasilenie portfela firmowego',
        'reimbursement'    => 'Zwrot kosztów',
        'bonus'            => 'Premia',
        'correction'       => 'Korekta',
        'transfer'         => 'Transfer portfela A→B',
        'transfer_to_priv' => 'Transfer firmowy→prywatna',
    ][$t] ?? $t;
}

if ($isAdminUser && ($_GET['inline_saved'] ?? '') === '1') {
    $savedType = trim((string)($_GET['inline_type'] ?? ''));
    $allowedSavedTypes = ['payout','advance_private','advance_company','reimbursement','bonus','correction','transfer','transfer_to_priv'];
    if (in_array($savedType, $allowedSavedTypes, true)) {
        $inlineFormSuccess = rptOperationTypeLabel($savedType) . ' została zapisana.';
    }
}

if ($isAdminUser) {
    $sessionToken = $_SESSION['report_inline_submit_token'] ?? '';
    if (!is_string($sessionToken) || strlen($sessionToken) < 20) {
        $sessionToken = bin2hex(random_bytes(32));
        $_SESSION['report_inline_submit_token'] = $sessionToken;
    }
    $inlineFormToken = $sessionToken;
}

if ($isAdminUser && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'inline_new_operation') {
    $submittedToken = trim((string)($_POST['_submit_token'] ?? ''));
    $sessionToken = (string)($_SESSION['report_inline_submit_token'] ?? '');
    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $inlineFormErrors[] = 'Formularz został już wysłany. Odśwież stronę i spróbuj ponownie.';
        $_SESSION['report_inline_submit_token'] = bin2hex(random_bytes(32));
        $inlineFormToken = $_SESSION['report_inline_submit_token'];
    }

    $opType        = trim($_POST['op_type'] ?? '');
    $postWorkerId  = (int)($_POST['worker_id'] ?? 0);
    $toWorkerId    = (int)($_POST['to_worker_id'] ?? 0);
    $amount        = (float)str_replace(',', '.', trim($_POST['amount'] ?? '0'));
    $opDate        = trim($_POST['op_date'] ?? date('Y-m-d'));
    $periodRaw     = trim($_POST['period'] ?? '');
    $period        = payrollNormalizeMonth($periodRaw, $opDate);
    $description   = trim($_POST['description'] ?? '');
    $sourceKind    = trim($_POST['source_kind'] ?? '');
    $sourceRef     = trim($_POST['source_ref'] ?? '');
    $fromWorkerId     = (int)($_POST['from_worker_id'] ?? $workerId);
    $ttpFromWorkerId  = (int)($_POST['ttp_from_worker_id'] ?? $workerId);
    $ttpToWorkerId    = (int)($_POST['ttp_to_worker_id'] ?? 0);

    $validOpTypes = ['payout','advance_private','advance_company','reimbursement','bonus','correction','transfer','transfer_to_priv'];
    if (!in_array($opType, $validOpTypes))              $inlineFormErrors[] = 'Wybierz typ operacji.';
    if ($amount <= 0)                                   $inlineFormErrors[] = 'Kwota musi być większa od 0.';
    if (empty($opDate))                                 $inlineFormErrors[] = 'Data operacji jest wymagana.';
    if (in_array($opType, ['payout','reimbursement','bonus','correction','advance_private','transfer_to_priv'], true) && !payrollIsMonthInputValid($periodRaw)) $inlineFormErrors[] = 'Okres rozliczeniowy jest nieprawidłowy.';
    if ($opType !== 'transfer' && $opType !== 'transfer_to_priv' && $postWorkerId <= 0) $inlineFormErrors[] = 'Wybierz pracownika.';
    if ($opType === 'transfer' && ($fromWorkerId <= 0 || $toWorkerId <= 0))                 $inlineFormErrors[] = 'Dla transferu: wybierz pracownika docelowego.';
    if ($opType === 'transfer_to_priv' && ($ttpFromWorkerId <= 0 || $ttpToWorkerId <= 0))   $inlineFormErrors[] = 'Dla przekazania gotówki: wybierz pracownika.';
    if ($opType === 'advance_company' && empty($sourceKind)) $inlineFormErrors[] = 'Dla zasilenia portfela wybierz źródło finansowania.';

    if (empty($inlineFormErrors)) {
        $_SESSION['report_inline_submit_token'] = bin2hex(random_bytes(32));
        $inlineFormToken = $_SESSION['report_inline_submit_token'];
        try {
            $pdo->beginTransaction();
            $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

            if (in_array($opType, ['payout','reimbursement','bonus','correction'])) {
                $stmt = $pdo->prepare("INSERT INTO settlements (worker_id, type, advance_kind, amount, date, period, description, created_by_user_id) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)");
                $stmt->execute([$postWorkerId, $opType, $amount, $opDate, $period, $description, $createdBy]);

            } elseif ($opType === 'advance_private') {
                if (payrollWorkerAdvancesHasSalaryPeriod($pdo)) {
                    $stmt = $pdo->prepare("INSERT INTO worker_advances (worker_id, type, amount, issue_date, salary_period, description, status, created_by, created_at) VALUES (?, 'PRIVATE', ?, ?, ?, ?, 'open', ?, NOW())");
                    $stmt->execute([$postWorkerId, $amount, $opDate, $period, $description, $createdBy]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO worker_advances (worker_id, type, amount, issue_date, description, status, created_by, created_at) VALUES (?, 'PRIVATE', ?, ?, ?, 'open', ?, NOW())");
                    $stmt->execute([$postWorkerId, $amount, $opDate, $description, $createdBy]);
                }
                $advId = (int)$pdo->lastInsertId();
                $stmt2 = $pdo->prepare("INSERT INTO worker_ledger (worker_id, advance_id, entry_type, amount, entry_date, description, created_by, created_at) VALUES (?, ?, 'ADVANCE', ?, ?, ?, ?, NOW())");
                $stmt2->execute([$postWorkerId, $advId, -$amount, $opDate, 'Zaliczka prywatna' . ($description ? ': ' . $description : ''), $createdBy]);

            } elseif ($opType === 'advance_company') {
                $stmt = $pdo->prepare("INSERT INTO worker_advances (worker_id, type, amount, issue_date, description, status, created_by, created_at) VALUES (?, 'COMPANY', ?, ?, ?, 'open', ?, NOW())");
                $stmt->execute([$postWorkerId, $amount, $opDate, $description, $createdBy]);
                $advId = (int)$pdo->lastInsertId();
                $stmt2 = $pdo->prepare("INSERT INTO worker_ledger (worker_id, advance_id, entry_type, amount, entry_date, description, created_by, created_at) VALUES (?, ?, 'ADVANCE', ?, ?, ?, ?, NOW())");
                $stmt2->execute([$postWorkerId, $advId, -$amount, $opDate, 'Zasilenie portfela firmowego' . ($description ? ': ' . $description : ''), $createdBy]);
                $stmt3 = $pdo->prepare("INSERT INTO worker_wallet_funding (worker_id, advance_id, direction, amount, source_kind, source_ref, note, movement_date, created_by, created_at) VALUES (?, ?, 'IN', ?, ?, ?, ?, ?, ?, NOW())");
                $stmt3->execute([$postWorkerId, $advId, $amount, $sourceKind, $sourceRef, 'Zasilenie portfela' . ($description ? ': ' . $description : ''), $opDate, $createdBy]);

            } elseif ($opType === 'transfer') {
                if ($fromWorkerId === $toWorkerId) {
                    throw new Exception('Pracownik źródłowy i docelowy muszą być różni.');
                }
                $stmt = $pdo->prepare("SELECT id, first_name, last_name, is_active FROM workers WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$toWorkerId]);
                $targetWorker = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$targetWorker || (int)$targetWorker['is_active'] !== 1) {
                    throw new Exception('Pracownik docelowy jest nieaktywny lub nie istnieje.');
                }
                walletCreateTransfer(
                    $pdo,
                    $fromWorkerId,
                    $toWorkerId,
                    $amount,
                    $opDate,
                    $description,
                    $createdBy,
                    'COMPANY'
                );

            } elseif ($opType === 'transfer_to_priv') {
                if ($ttpFromWorkerId === $ttpToWorkerId) {
                    throw new Exception('Pracownik źródłowy i docelowy muszą być różni.');
                }
                $stmt = $pdo->prepare("SELECT id, first_name, last_name, is_active FROM workers WHERE id = ? LIMIT 1 FOR UPDATE");
                $stmt->execute([$ttpToWorkerId]);
                $targetWorker = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$targetWorker || (int)$targetWorker['is_active'] !== 1) {
                    throw new Exception('Pracownik docelowy jest nieaktywny lub nie istnieje.');
                }
                walletCreateTransfer(
                    $pdo,
                    $ttpFromWorkerId,
                    $ttpToWorkerId,
                    $amount,
                    $opDate,
                    $description,
                    $createdBy,
                    'PRIVATE',
                    $period
                );
            }

            $pdo->commit();
            logEvent("REPORT INLINE: operacja $opType zapisana przez admina dla pracownika $postWorkerId", 'INFO');

            $redirectParams = $_GET;
            unset($redirectParams['export'], $redirectParams['inline_saved'], $redirectParams['inline_type']);
            $redirectParams['worker_id'] = (int)$workerId;
            $redirectParams['inline_saved'] = '1';
            $redirectParams['inline_type'] = $opType;
            $redirectUrl = url('hr.workers.report') . '?' . http_build_query($redirectParams);
            header('Location: ' . $redirectUrl);
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $inlineFormErrors[] = 'Błąd zapisu: ' . $e->getMessage();
        }
    }
}

if ($isAdminUser) {
    $inlineFormToken = (string)($_SESSION['report_inline_submit_token'] ?? $inlineFormToken);
}

// ========================================
// EKSPORT JSON DLA ASYSTENTA WŁAŚCICIELA
// ========================================
if (isset($_GET['export']) && $_GET['export'] === 'json' && $workerId > 0 && $worker) {
    // Określ typ okresu
    $periodType = 'custom';
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $yearStart = date('Y-01-01');
    
    if ($dateFrom === $today && $dateTo === $today) {
        $periodType = 'day';
    } elseif ($dateFrom === $yesterday && $dateTo === $yesterday) {
        $periodType = 'day';
    } elseif ($dateFrom === $weekStart && $dateTo === $weekEnd) {
        $periodType = 'week';
    } elseif ($dateFrom === $monthStart && $dateTo === $monthEnd) {
        $periodType = 'month';
    } elseif ($dateFrom === $yearStart && $dateTo === $today) {
        $periodType = 'year';
    }
    
    // Przygotuj pełne dane JSON
    $jsonData = [
        'meta' => [
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_by' => $userName,
            'period_type' => $periodType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'export_version' => '1.3'
        ],
        'worker' => [
            'id' => (int)$worker['id'],
            'first_name' => $worker['first_name'],
            'last_name' => $worker['last_name'],
            'full_name' => $workerName
        ],
        'summary' => [
            'period_balance' => (float)$periodBalance,
            'due_total' => (float)$dueTotal,
            'paid_out_total' => (float)$paidOutTotal,
            'total_paid_to_worker' => (float)$totalPaidToWorker,
            'private_advances_total' => (float)$privateAdvancesTotal,
            'company_advances_total' => (float)$companyAdvancesTotal,
            'work_summary' => [
                'total_hours' => (float)$workSummary['hours'],
                'overtime_hours' => (float)$workSummary['overtime'],
                'normal_hours' => (float)$workSummary['normal'],
                'saturday_hours' => (float)$workSummary['saturday'],
                'sunday_hours' => (float)$workSummary['sunday'],
                'night_hours' => (float)$workSummary['night'],
                'delegation_hours' => (float)$workSummary['delegation'],
                'sick_days' => (float)$workSummary['sick_days'],
                'vacation_days' => (float)$workSummary['vacation_days'],
                'total_cost' => (float)$workSummary['cost']
            ],
            'expenses_total' => (float)$expensesTotal,
            'settlements_summary' => [
                'payouts' => (float)$settlementsSummary['payouts'],
                'advances_private' => (float)$settlementsSummary['advances_private'],
                'advances_company' => (float)$settlementsSummary['advances_company'],
                'reimbursements' => (float)$settlementsSummary['reimbursements'],
                'bonuses' => (float)$settlementsSummary['bonuses'],
                'corrections' => (float)$settlementsSummary['corrections']
            ],
            'payout_axis' => [
                'due' => [
                    'work_cost' => (float)$workSummary['cost'],
                    'employee_expenses' => (float)$expensesTotal,
                    'corrections' => (float)$correctionTotal,
                    'bonuses' => (float)$bonusTotal,
                    'total' => (float)$dueTotal
                ],
                'paid_out' => [
                    'payouts' => (float)$settlementsSummary['payouts'],
                    'reimbursements' => (float)$settlementsSummary['reimbursements'],
                    'total' => (float)$paidOutTotal
                ],
                'private_advances' => (float)$privateAdvancesTotal,
                'total_paid_to_worker' => (float)$totalPaidToWorker,
                'company_advances_info' => (float)$companyAdvancesTotal,
                'balance' => (float)$periodBalance
            ]
        ],
        'work_logs' => [],
        'expenses' => [],
        'settlements' => [],
        'new_advances' => []
    ];
    
    // Dodaj szczegółowe wpisy czasu pracy
    foreach ($workLogs as $log) {
        $jsonData['work_logs'][] = [
            'date' => $log['date'],
            'hours' => (float)$log['hours'],
            'overtime_hours' => (float)($log['overtime_hours'] ?? 0),
            'absence_days' => normalizeAbsenceDays($log),
            'work_type' => $log['work_type'] ?? 'work',
            'is_paid' => (int)($log['is_paid'] ?? 1),
            'is_saturday' => (bool)$log['is_saturday'],
            'is_sunday' => (bool)$log['is_sunday'],
            'is_night' => (bool)$log['is_night'],
            'is_delegation' => (bool)$log['is_delegation'],
            'project_name' => $log['project_name'],
            'cost_node_name' => $log['cost_node_name'],
            'description' => $log['description'],
            'cost' => $log['cost'] !== null ? (float)$log['cost'] : null,
            'final_cost' => $log['final_cost'] !== null ? (float)$log['final_cost'] : null,
            'system_cost' => $log['system_cost'] !== null ? (float)$log['system_cost'] : null
        ];
    }
    
    // Dodaj szczegółowe wydatki
    foreach ($expenses as $exp) {
        $isPaidByEmployee = isExpensePaidByEmployeeRow($exp);
        $cleanDesc = cleanEmployeeExpenseDescription($exp['description'] ?? '');
        
        $jsonData['expenses'][] = [
            'date' => $exp['date'],
            'amount' => (float)$exp['amount'],
            'description' => $cleanDesc,
            'status' => $exp['status'],
            'paid_by_employee' => $isPaidByEmployee,
            'project_name' => $exp['project_name'],
            'cost_node_name' => $exp['cost_node_name']
        ];
    }
    
    // Dodaj szczegółowe rozliczenia (stare)
    foreach ($settlements as $settlement) {
        $advanceKind = normalizeAdvanceKindValue((string)($settlement['advance_kind'] ?? ''), 'private');
        $jsonData['settlements'][] = [
            'date' => $settlement['report_date'] ?? $settlement['operation_date'],
            'report_date' => $settlement['report_date'] ?? $settlement['operation_date'],
            'operation_date' => $settlement['operation_date'],
            'period' => $settlement['period'] ?? null,
            'type' => $settlement['type'],
            'amount' => (float)$settlement['amount'],
            'description' => $settlement['description'] ?? '',
            'advance_kind' => $advanceKind,
            'balance_effect' => settlementBalanceEffect((string)$settlement['type'], $advanceKind)
        ];
    }
    
    // Dodaj szczegółowe nowe zaliczki
    foreach ($newAdvances as $adv) {
        $jsonData['new_advances'][] = [
            'id' => (int)$adv['id'],
            'date' => $adv['report_date'],
            'report_date' => $adv['report_date'],
            'operation_date' => $adv['operation_date'],
            'salary_period' => $adv['salary_period'] ?? null,
            'amount' => (float)$adv['amount'],
            'type' => $adv['advance_type'],
            'description' => $adv['description'] ?? '',
            'status' => $adv['status'],
            'balance_effect' => $adv['advance_type'] === 'PRIVATE' ? 'minus' : 'neutral'
        ];
    }
    
    // Wyślij jako plik JSON do pobrania
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="raport_pracownika_' . $workerId . '_' . $dateFrom . '_' . $dateTo . '.json"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    
    echo json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Helper function for formatting cell values
function getWorkerColor($workerId) {
    $goldenRatio = 0.618033988749895;
    $hue = fmod($workerId * $goldenRatio, 1.0) * 360;
    $saturation = 65;
    $lightness = 55;
    return [
        'hslLight'  => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.08)",
        'hslLight2' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.17)",
        'hslBorder' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.6)"
    ];
}

function fmtCell($val, $suffix = '') {
    return ((float)$val > 0) ? 
        '<span style="color:#1f2937; font-weight:600;">'.number_format((float)$val, 2, ',', ' ').$suffix.'</span>' : 
        '<span class="col-dim">–</span>';
}

function normalizeAdvanceKindValue(string $advanceKind, string $default = 'private'): string
{
    $kind = strtolower(trim($advanceKind));
    if ($kind === 'business') {
        $kind = 'company';
    }
    if ($kind === '') {
        $kind = $default;
    }
    return $kind;
}

function isPrivateAdvanceKind(string $advanceKind): bool
{
    return in_array($advanceKind, ['private', 'company_private'], true);
}

function settlementBalanceEffect(string $type, string $advanceKind = ''): string
{
    if (in_array($type, ['payout', 'reimbursement', 'bonus'], true)) {
        return 'minus';
    }
    if ($type === 'correction') {
        return 'plus';
    }
    if ($type === 'advance') {
        return isPrivateAdvanceKind(normalizeAdvanceKindValue($advanceKind, 'private')) ? 'minus' : 'neutral';
    }
    return 'neutral';
}

function isExpensePaidByEmployeeRow(array $expense): bool
{
    return !empty($expense['is_paid_by_employee'])
        || !empty($expense['paid_by_employee'])
        || strpos((string)($expense['description'] ?? ''), '[PAID_BY_EMPLOYEE]') !== false;
}

function cleanEmployeeExpenseDescription(?string $description): string
{
    return trim(str_replace(['[PAID_BY_EMPLOYEE] ', '[PAID_BY_EMPLOYEE]'], '', (string)$description));
}

function getPolishDayName(string $date): string
{
    $days = ['Nd', 'Pn', 'Wt', 'Sr', 'Cz', 'Pt', 'So'];
    $dayNum = (int)date('w', strtotime($date));
    return $days[$dayNum] ?? '';
}

function formatDateWithDay(string $date): string
{
    if (trim($date) === '') {
        return '<span class="col-dim">—</span>';
    }

    $dayName = getPolishDayName($date);
    return '<div class="date-cell-main">' . e(formatDate($date)) . '</div>'
        . '<div class="date-cell-day">' . e($dayName) . '</div>';
}

function buildRateInfo(array $log): array {
    $result = [
        'show_meta' => false,
        'meta_text' => '',
        'is_mixed' => false,
        'segments' => []
    ];

    if (!isset($log['cost']) || $log['cost'] === null) {
        return $result;
    }

    $workType = $log['work_type'] ?? 'work';
    if ($workType !== 'work') {
        return $result;
    }

    $regularHours = max(0, (float)($log['hours'] ?? 0));
    $overtimeHours = max(0, (float)($log['overtime_hours'] ?? 0));
    $totalHours = $regularHours + $overtimeHours;
    if ($totalHours <= 0.0001) {
        return $result;
    }

    $cost = (float)$log['cost'];
    $effectiveRate = $cost / $totalHours;
    $snapshotRate = (isset($log['system_rate_snapshot']) && is_numeric($log['system_rate_snapshot']))
        ? (float)$log['system_rate_snapshot']
        : 0.0;

    $result['show_meta'] = true;

    // Dla nadgodzin próbujemy pokazać realne rozbicie stawek.
    if ($overtimeHours > 0.0001 && $snapshotRate > 0.0001) {
        $regularAmount = $regularHours * $snapshotRate;
        $overtimeAmount = $cost - $regularAmount;
        if ($overtimeAmount < 0 && $overtimeAmount > -0.05) {
            $overtimeAmount = 0.0;
        }
        $overtimeRate = $overtimeHours > 0 ? ($overtimeAmount / $overtimeHours) : 0.0;

        if ($overtimeRate > 0.0001 && abs($overtimeRate - $snapshotRate) > 0.01) {
            $result['is_mixed'] = true;
            $result['meta_text'] = number_format($totalHours, 2, ',', ' ') . ' h, stawki mieszane';

            if ($regularHours > 0.0001) {
                $result['segments'][] = [
                    'label' => 'Godziny podstawowe',
                    'hours' => $regularHours,
                    'rate' => $snapshotRate,
                    'amount' => $regularAmount
                ];
            }
            $result['segments'][] = [
                'label' => 'Nadgodziny',
                'hours' => $overtimeHours,
                'rate' => $overtimeRate,
                'amount' => $overtimeAmount
            ];

            return $result;
        }
    }

    $result['meta_text'] = number_format($totalHours, 2, ',', ' ')
        . ' h × '
        . number_format($effectiveRate, 2, ',', ' ')
        . ' zł/h';

    return $result;
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Raport Pracownika: <?php echo e($workerName); ?></title>
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #764ba2;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --bg-card: #ffffff;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 50px; }
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
        .header-title h1 { font-size: 26px; font-weight: 700; color: #fff; margin: 0 0 4px; letter-spacing: -0.3px; }
        .header-meta { font-size: 12px; color: #cbd5e1; margin-top: 2px; }
        .page-header .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .page-header .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .page-header-actions { display: flex; gap: 8px; align-items: flex-start; flex-wrap: wrap; }
        .btn-hero-primary { background: #ffffff; color: #1e3a8a; border: 1px solid #ffffff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-light { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25); color: #ffffff; padding: 8px 14px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-light:hover { background: rgba(255,255,255,0.2); }
        
        /* ========================================
           SPX FILTER SYSTEM - wzorzec v1
           ======================================== */
        .spx-filter-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 8px;
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
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"] {
            padding: 0 8px;
            height: 38px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            background: white;
            color: var(--text-main);
            font-family: inherit;
            transition: border-color 0.15s;
            width: 100%;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input[type="date"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        @media (max-width: 1024px) {
            .spx-filter-bar { flex-wrap: wrap; }
            .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; }
        }
        @media (max-width: 768px) {
            .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; }
            .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; }
            .spx-filter-group select, .spx-filter-group input[type="date"] { height: 44px; font-size: 14px; }
        }
        .spx-controls-bar {
            padding: 10px 20px;
            background: #f9fafb;
            border-bottom: 1px solid var(--border);
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
        .spx-quick-btn {
            padding: 0 12px;
            height: 28px;
            background: white;
            border: 1px solid var(--border);
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
        .spx-quick-btn:hover {
            background: #f9fafb;
            border-color: var(--primary);
            color: var(--primary);
        }
        .spx-quick-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            font-weight: 600;
        }
        .btn-action { 
            padding: 8px 16px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: 600; 
            font-size: 13px;
            transition: opacity 0.2s;
        }
        .btn-action:hover { opacity: 0.9; }
        .btn-action.secondary { background: #374151; }

        /* HERO STATS GRID */
        .stats-grid { 
            display: grid; 
            grid-template-columns: 280px 1fr; 
            gap: 20px; 
            margin-bottom: 25px; 
        }
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        
        .balance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px; 
            padding: 24px;
            display: flex; 
            flex-direction: column; 
            justify-content: center;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .balance-label { font-size: 12px; opacity: 0.9; text-transform: uppercase; letter-spacing: 1px; }
        .balance-val { font-size: 36px; font-weight: 700; margin: 8px 0; letter-spacing: -1px; }
        .balance-sub { font-size: 12px; opacity: 0.8; margin-top: auto; }

        /* BREAKDOWN CARD */
        .breakdown-card { 
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border); 
            padding: 20px; 
        }
        .breakdown-title { 
            font-size: 11px; 
            text-transform: uppercase; 
            color: var(--text-muted); 
            font-weight: 700; 
            margin-bottom: 15px; 
            border-bottom: 1px solid #f0f0f0; 
            padding-bottom: 8px; 
        }
        
        .hours-grid { 
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); 
            gap: 15px;
        }
        .hg-item { display: flex; flex-direction: column; }
        .hg-label { font-size: 11px; color: var(--text-muted); margin-bottom: 4px; }
        .hg-val { font-size: 16px; font-weight: 600; color: var(--text-main); }
        .hg-val.highlight { color: var(--primary); }
        .hg-val.danger { color: var(--danger); }
        .hg-val.success { color: var(--success); }

        /* SECTION TITLES */
        .section-title { 
            margin: 25px 0 12px 0; 
            font-weight: 700; 
            font-size: 15px; 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            color: var(--text-main); 
        }
        .badge-count { 
            background: #e5e7eb; 
            color: var(--text-main); 
            padding: 2px 10px; 
            border-radius: 10px; 
            font-size: 11px; 
            font-weight: 600;
        }
        
        /* DATA TABLES - spójne z projekty.css */
        .data-table { 
            width: 100%; 
            border-collapse: collapse; 
            background: white; 
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .data-table th { 
            background: #f9fafb; 
            padding: 10px 14px; 
            text-align: left; 
            font-weight: 600; 
            color: var(--text-muted); 
            border-bottom: 1px solid var(--border); 
            font-size: 11px; 
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .data-table td { 
            padding: 10px 14px !important; 
            border: 1px solid #e5e7eb !important; 
            vertical-align: middle;
            font-size: 13px !important;
            color: var(--text-main);
        }
        .data-table th {
            border: 1px solid #e5e7eb !important;
        }

        /* Domyślnie: zebra o wyraźnym kontraście */
        .data-table tbody tr {
            border-left: 4px solid transparent;
        }
        .data-table tbody tr td {
            transition: background 0.15s ease;
        }
        /* Kolory pracownika — tryb włączony (domyślny), naprzemienne odcienie */
        body:not(.no-colors) .data-table tbody tr {
            border-left: 4px solid var(--worker-color, #667eea);
        }
        body:not(.no-colors) .data-table tbody tr.zebra-odd td {
            background: var(--worker-bg, rgba(102,126,234,0.08)) !important;
        }
        body:not(.no-colors) .data-table tbody tr.zebra-even td {
            background: var(--worker-bg2, rgba(102,126,234,0.17)) !important;
        }
        body:not(.no-colors) .data-table tbody tr:hover td {
            filter: brightness(0.94);
        }

        /* Tryb wyłączony — zebra szaro-biała */
        body.no-colors .data-table tbody tr {
            border-left: 4px solid transparent;
        }
        body.no-colors .data-table tbody tr.zebra-odd td {
            background: #ffffff !important;
        }
        body.no-colors .data-table tbody tr.zebra-even td {
            background: #eef2f7 !important;
        }
        body.no-colors .data-table tbody tr.zebra-odd:hover td,
        body.no-colors .data-table tbody tr.zebra-even:hover td {
            background: #e4edfb !important;
        }

        /* Wyraźne oznaczenie typów dni jak w worklogu */
        tr.row-sick {
            background: #fef2f2 !important;
            border-left: 4px solid #ef4444 !important;
        }
        tr.row-sick td {
            background: #fef2f2 !important;
            color: #991b1b !important;
        }
        tr.row-vacation {
            background: #f0fdf4 !important;
            border-left: 4px solid #22c55e !important;
        }
        tr.row-vacation td {
            background: #f0fdf4 !important;
            color: #166534 !important;
        }

        .date-cell-main {
            white-space: nowrap;
            font-weight: 600;
            line-height: 1.1;
        }
        .date-cell-day {
            margin-top: 2px;
            font-size: 10px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            line-height: 1;
        }
        
        .btn-color-mode {
            width: 38px;
            height: 38px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            padding: 0;
        }
        .btn-color-mode:hover {
            background: #f9fafb;
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
        
        .col-money { text-align: right; font-weight: 600; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .col-center { text-align: center; }
        .col-dim { color: #9ca3af; }
        .rate-meta {
            margin-top: 4px;
            font-size: 11px;
            font-weight: 500;
            color: var(--text-muted);
            line-height: 1.2;
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
        }
        .rate-toggle:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }
        .rate-details-row td {
            background: #f8fafc !important;
            border-top: none !important;
        }
        .rate-details-panel {
            padding: 8px 2px 4px;
        }
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
            padding: 6px 8px;
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
        
        .badge { 
            display: inline-block; 
            padding: 4px 10px; 
            border-radius: 12px; 
            font-size: 11px; 
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .badge-work { background: #f3f4f6; color: #4b5563; }
        .badge-sick { background: #fee2e2; color: #991b1b; }
        .badge-vacation { background: #dcfce7; color: #166534; }
        
        /* TWO COLUMN GRID */
        .two-col-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-top: 10px;
        }
        @media (max-width: 900px) {
            .two-col-grid { grid-template-columns: 1fr; }
        }

        /* SUMMARY BOX */
        .summary-box { 
            background: white; 
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 24px; 
            margin-top: 25px; 
        }
        .summary-box-title {
            font-weight: 700; 
            color: #1f2937; 
            margin-bottom: 15px; 
            text-transform: uppercase;
            font-size: 14px;
            letter-spacing: 0.5px;
        }
        .sum-row { 
            display: flex; 
            justify-content: space-between; 
            padding: 10px 0; 
            font-size: 14px; 
            border-bottom: 1px solid #f3f4f6; 
        }
        .sum-row:last-child { 
            border-bottom: none; 
            font-weight: 700; 
            font-size: 18px; 
            padding-top: 16px; 
            margin-top: 8px; 
            border-top: 2px solid #e5e7eb; 
        }
        .sum-label { color: #6b7280; }
        .sum-value { font-weight: 600; color: #1f2937; }
        .sum-value.positive { color: #16a34a; }
        .sum-value.negative { color: #dc2626; }
        .sum-value.neutral { color: #475569; }
        
        /* SECTION CARD */
        .section-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 20px;
            margin-bottom: 20px;
        }
        .section-card h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        /* WORKER SELECT */
        .worker-select-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 30px;
            max-width: 500px;
        }
        .worker-select-card h3 {
            font-size: 18px;
            margin-bottom: 20px;
            color: var(--text-main);
        }
        .worker-select-card select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            margin-bottom: 15px;
        }
        
        /* NO DATA */
        .no-data {
            padding: 30px 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
            background: #f9fafb;
            border-radius: 8px;
        }

        /* FOOTER */
        .footer {
            text-align: center;
            padding: 25px;
            color: var(--text-muted);
            font-size: 12px;
        }

        /* PRINT STYLES */
        @media print {
            .header,
            .filter-bar,
            .quick-filters,
            .btn-action,
            .nav-section,
            .user-section,
            .footer,
            .alerts-dropdown {
                display: none !important;
            }
            body { background: white; font-size: 11px; }
            .container { padding: 0; max-width: 100%; }
            .balance-card { 
                background: white !important; 
                color: black !important; 
                border: 2px solid #000; 
                box-shadow: none;
            }
            .balance-card .balance-label,
            .balance-card .balance-sub { color: #333 !important; }
            .data-table th { background: #eee !important; color: black; border-bottom: 1px solid #000; }
            .data-table td { border-bottom: 1px solid #ccc; }
            .hg-val { color: black !important; }
            .section-card, .breakdown-card, .summary-box { 
                box-shadow: none;
                border: 1px solid #ccc; 
            }
            .stats-grid { grid-template-columns: 1fr 2fr; }
            .page-header { border: 1px solid #ccc; box-shadow: none; }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div style="font-size:13px; color:#9ca3af; margin-bottom:16px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <a href="<?php echo url('hr'); ?>" style="color:#667eea; text-decoration:none;">Pracownicy</a>
            <span>/</span>
            <span>Raport finansowy</span>
        </div>
        <?php if ($isAdminUser && $workerId <= 0): ?>
            <!-- Wybór pracownika -->
            <div class="page-header">
                <div class="header-title">
                    <h1>Raport okresowy pracownika</h1>
                    <div class="header-meta">Wybierz pracownika aby wygenerować raport</div>
                </div>
            </div>
            
            <div class="worker-select-card">
                <h3>Wybierz pracownika</h3>
                <form method="get">
                    <select name="worker_id" required>
                        <option value="">-- Wybierz pracownika --</option>
                        <?php foreach ($workersList as $w): ?>
                            <option value="<?php echo $w['id']; ?>">
                                <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-action" style="width: 100%;">
                        Generuj raport
                    </button>
                </form>
            </div>
        <?php else:
            $wc = getWorkerColor($workerId);
            $workerRowStyle = "--worker-color: {$wc['hslBorder']}; --worker-bg: {$wc['hslLight']}; --worker-bg2: {$wc['hslLight2']};";
        ?>
            <!-- HEADER RAPORTU — hero -->
            <div class="page-header">
                <div class="header-title">
                    <div class="hero-breadcrumb"><a href="<?php echo url('hr'); ?>">Pracownicy</a> › Raport finansowy</div>
                    <h1>Raport Finansowy</h1>
                    <div class="header-meta">
                        <?php echo e($workerName); ?> &nbsp;|&nbsp;
                        <?php echo formatDate($dateFrom); ?> – <?php echo formatDate($dateTo); ?>
                    </div>
                </div>
                <div class="page-header-actions">
                    <button type="button" class="btn-color-mode active" onclick="toggleColors()" title="Kolory pracownika"
                        style="background:rgba(255,255,255,0.12);border-color:rgba(255,255,255,0.25);color:white;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 2a10 10 0 0 1 0 20"/>
                            <circle cx="12" cy="12" r="4"/>
                        </svg>
                    </button>
                    <a href="<?php echo url('hr'); ?>?tab=new&worker_id=<?php echo (int)$workerId; ?>" class="btn-hero-light">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Nowa operacja
                    </a>
                    <a href="<?php echo url('hr.workers.wallet', ['worker_id' => (int)$workerId]); ?>" class="btn-hero-light">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2"/></svg>
                        Portfel
                    </a>
                    <button type="button" class="btn-hero-light" onclick="window.print()">Drukuj</button>
                    <button type="button" class="btn-hero-primary" onclick="downloadJSON()">JSON</button>
                </div>
            </div>

                <!-- GŁÓWNY PASEK FILTRÓW SPX -->
                <?php
                $rToday     = date('Y-m-d');
                $rYesterday = date('Y-m-d', strtotime('-1 day'));
                $rWeekAgo   = date('Y-m-d', strtotime('-7 days'));
                $rMonthStart = date('Y-m-01');
                $rMonthEnd   = date('Y-m-t');
                $rYearStart  = date('Y-01-01');

                // Dropdown miesiąc/rok
                $rFilterYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
                if ($rFilterYear < 2020 || $rFilterYear > 2030) $rFilterYear = (int)date('Y');
                $rFilterMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
                if ($rFilterMonth >= 1 && $rFilterMonth <= 12 && !isset($_GET['date_from'])) {
                    $dateFrom = sprintf('%04d-%02d-01', $rFilterYear, $rFilterMonth);
                    $dateTo   = date('Y-m-t', strtotime($dateFrom));
                }
                $rMonthNames = [
                    1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',
                    7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'
                ];
                $rYearRange = range((int)date('Y') - 3, (int)date('Y'));
                $rActiveMonth = 0;
                for ($m = 1; $m <= 12; $m++) {
                    $mS = sprintf('%04d-%02d-01', $rFilterYear, $m);
                    $mE = date('Y-m-t', strtotime($mS));
                    if ($dateFrom === $mS && $dateTo === $mE) { $rActiveMonth = $m; break; }
                }

                $rIsDayActive   = ($dateFrom !== '' && $dateFrom === $rToday && $dateTo === $rToday);
                $rIsWeekActive  = ($dateFrom !== '' && $dateFrom === $rWeekAgo && $dateTo === $rToday);
                $rIsMonthActive = ($dateFrom !== '' && $dateFrom === $rMonthStart && $dateTo === $rMonthEnd);
                $rIsYearActive  = ($dateFrom !== '' && $dateFrom === $rYearStart && $dateTo === $rToday);
                $rBaseQs = 'worker_id=' . $workerId . ($filterProject > 0 ? '&project_id=' . $filterProject : '');
                ?>
                <form class="spx-filter-bar" method="get" id="reportFilterForm">
                    <?php if ($isAdminUser): ?>
                    <div class="spx-filter-group fg-worker">
                        <label>Pracownik</label>
                        <select name="worker_id" onchange="this.form.submit()">
                            <?php foreach ($workersList as $w): ?>
                                <option value="<?php echo $w['id']; ?>" <?php echo $w['id'] == $workerId ? 'selected' : ''; ?>>
                                    <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="worker_id" value="<?php echo $workerId; ?>">
                    <?php endif; ?>
                    <?php if (!empty($workerProjects)): ?>
                    <div class="spx-filter-group fg-project">
                        <label>Projekt</label>
                        <select name="project_id" onchange="this.form.submit()">
                            <option value="0">Wszystkie projekty</option>
                            <?php foreach ($workerProjects as $proj): ?>
                                <option value="<?php echo $proj['id']; ?>" <?php echo $filterProject == $proj['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($proj['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="spx-filter-group fg-month">
                        <label>Miesiac</label>
                        <select name="month" id="rSelectMonth" onchange="rOnMonthYearChange()">
                            <option value="0" <?php echo $rActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                            <?php foreach ($rMonthNames as $mn => $mName): ?>
                                <option value="<?php echo $mn; ?>" <?php echo ($rActiveMonth === $mn) ? 'selected' : ''; ?>>
                                    <?php echo $mName; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="spx-filter-group fg-year">
                        <label>Rok</label>
                        <select name="year" id="rSelectYear" onchange="rOnMonthYearChange()">
                            <?php foreach ($rYearRange as $yr): ?>
                                <option value="<?php echo $yr; ?>" <?php echo ($rFilterYear == $yr) ? 'selected' : ''; ?>>
                                    <?php echo $yr; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="spx-filter-group fg-date">
                        <label>Od</label>
                        <input type="date" name="date_from" id="rInputDateFrom" value="<?php echo e($dateFrom); ?>">
                    </div>
                    <div class="spx-filter-group fg-date">
                        <label>Do</label>
                        <input type="date" name="date_to" id="rInputDateTo" value="<?php echo e($dateTo); ?>">
                    </div>
                    <button type="submit" class="btn-action" style="height: 38px; align-self: flex-end; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
                    <?php if ($dateFrom !== '' || $dateTo !== '' || $filterProject > 0): ?>
                        <a href="?worker_id=<?php echo $workerId; ?>" class="btn-action" style="height: 38px; align-self: flex-end; background: #6c757d; color: white; display: inline-flex; align-items: center; text-decoration: none; border-radius: 6px; padding: 0 14px; font-size: 13px; font-weight: 600; flex-shrink: 0; white-space: nowrap;">Resetuj</a>
                    <?php endif; ?>
                </form>

                <!-- PASEK SZYBKICH FILTRÓW SPX -->
                <div class="spx-controls-bar">
                    <div class="spx-controls-left">
                        <a href="?<?php echo $rBaseQs; ?>&date_from=<?php echo $rToday; ?>&date_to=<?php echo $rToday; ?>"
                           class="spx-quick-btn <?php echo $rIsDayActive ? 'active' : ''; ?>">Dzis</a>
                        <a href="?<?php echo $rBaseQs; ?>&date_from=<?php echo $rWeekAgo; ?>&date_to=<?php echo $rToday; ?>"
                           class="spx-quick-btn <?php echo $rIsWeekActive ? 'active' : ''; ?>">7 dni</a>
                        <a href="?<?php echo $rBaseQs; ?>&date_from=<?php echo $rMonthStart; ?>&date_to=<?php echo $rMonthEnd; ?>&year=<?php echo date('Y'); ?>"
                           class="spx-quick-btn <?php echo $rIsMonthActive ? 'active' : ''; ?>">Ten miesiac</a>
                        <a href="?<?php echo $rBaseQs; ?>&date_from=<?php echo $rYearStart; ?>&date_to=<?php echo $rToday; ?>"
                           class="spx-quick-btn <?php echo $rIsYearActive ? 'active' : ''; ?>">Ten rok</a>
                        <a href="?<?php echo $rBaseQs; ?>"
                           class="spx-quick-btn <?php echo ($dateFrom === '' && $dateTo === '') ? 'active' : ''; ?>">Wszystko</a>
                    </div>
                </div>
                
            <!-- 1. PODSUMOWANIE GŁÓWNE (HERO + BREAKDOWN) -->
            <div class="stats-grid">
                <!-- Saldo okresu -->
                <div class="balance-card">
                    <div class="balance-label">Saldo Okresu (oś wypłaty)</div>
                    <div class="balance-val">
                        <?php 
                        if ($periodBalance > 0) {
                            echo '+ ' . formatMoney($periodBalance);
                        } elseif ($periodBalance < 0) {
                            echo formatMoney($periodBalance);
                        } else {
                            echo formatMoney(0);
                        }
                        ?>
                    </div>
                    <div class="balance-sub">
                        Zarobek: <?php echo formatMoney($dueTotal); ?>
                        | Wypłacono: <?php echo formatMoney($paidOutTotal); ?>
                        <?php if ($privateAdvancesTotal > 0): ?>| Zaliczki: <?php echo formatMoney($privateAdvancesTotal); ?><?php endif; ?>
                        <?php if ($bonusTotal > 0): ?>| Premie: <?php echo formatMoney($bonusTotal); ?><?php endif; ?>
                </div>
                </div>
                
                <!-- Wyszczególnienie Godzin -->
                <div class="breakdown-card">
                    <div class="breakdown-title">A) Wyszczególnienie Czasu Pracy</div>
                    <div class="hours-grid">
                        <div class="hg-item">
                            <span class="hg-label">Razem</span>
                            <span class="hg-val"><?php echo number_format($workSummary['hours'], 2, ',', ' '); ?> h</span>
                    </div>
                        <div class="hg-item">
                            <span class="hg-label">Normalne</span>
                            <span class="hg-val"><?php echo number_format($workSummary['normal'], 2, ',', ' '); ?> h</span>
                </div>
                        <div class="hg-item">
                            <span class="hg-label">Nadgodziny</span>
                            <span class="hg-val highlight"><?php echo number_format($workSummary['overtime'], 2, ',', ' '); ?> h</span>
            </div>
                        <?php if($workSummary['saturday'] > 0): ?>
                        <div class="hg-item">
                            <span class="hg-label">Soboty</span>
                            <span class="hg-val"><?php echo number_format($workSummary['saturday'], 2, ',', ' '); ?> h</span>
                </div>
                        <?php endif; ?>
                        <?php if($workSummary['sunday'] > 0): ?>
                        <div class="hg-item">
                            <span class="hg-label">Niedziele</span>
                            <span class="hg-val"><?php echo number_format($workSummary['sunday'], 2, ',', ' '); ?> h</span>
                </div>
                <?php endif; ?>
                        <?php if($workSummary['night'] > 0): ?>
                        <div class="hg-item">
                            <span class="hg-label">Nocki</span>
                            <span class="hg-val"><?php echo number_format($workSummary['night'], 2, ',', ' '); ?> h</span>
                </div>
                <?php endif; ?>
                        <?php if($workSummary['delegation'] > 0): ?>
                        <div class="hg-item">
                            <span class="hg-label">Delegacje</span>
                            <span class="hg-val"><?php echo number_format($workSummary['delegation'], 2, ',', ' '); ?> h</span>
                </div>
                <?php endif; ?>
                        <?php if($workSummary['sick_days'] > 0): ?>
                        <div class="hg-item">
                            <span class="hg-label">L4 (Chorobowe)</span>
                            <span class="hg-val danger"><?php echo number_format($workSummary['sick_days'], 1, ',', ' '); ?> dni</span>
                </div>
                <?php endif; ?>
                        <?php if($workSummary['vacation_days'] > 0): ?>
                        <div class="hg-item">
                            <span class="hg-label">Urlop</span>
                            <span class="hg-val success"><?php echo number_format($workSummary['vacation_days'], 1, ',', ' '); ?> dni</span>
                </div>
                <?php endif; ?>
            </div>
                </div>
                </div>
            
            <!-- 2. TABELA SZCZEGÓŁOWA CZASU PRACY -->
            <div class="section-title">
                <span>A) Ewidencja szczegółowa czasu pracy</span>
                <span class="badge-count"><?php echo count($workLogs); ?> wpisów</span>
            </div>
            
            <?php if (empty($workLogs)): ?>
                <div class="no-data">Brak wpisów czasu pracy w wybranym okresie.</div>
            <?php else: ?>
            <table class="data-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Typ</th>
                        <th>Projekt / Etap</th>
                        <th class="col-money">Godz.</th>
                        <th class="col-money">Nadg.</th>
                        <th class="col-center">Sob</th>
                        <th class="col-center">Niedz</th>
                        <th class="col-center">Nocki</th>
                        <th class="col-center">Del.</th>
                        <th class="col-money">Koszt</th>
                            <th>Opis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workLogs as $index => $log): 
                            $workType = $log['work_type'] ?? 'work';
                            $hours = (float)$log['hours'];
                            $absenceDays = normalizeAbsenceDays($log);
                            $isSick = $workType === 'sick';
                            $isVacation = $workType === 'vacation';
                            $rowClass = $isSick ? 'row-sick' : ($isVacation ? 'row-vacation' : '');
                            $zebraClass = ($index % 2 === 0) ? 'zebra-odd' : 'zebra-even';
                            $rowClasses = trim($zebraClass . ' ' . $rowClass);
                            $rateInfo = buildRateInfo($log);
                            $rateDetailsRowId = 'rate-details-' . $index;
                        
                        // Badge class
                        if ($isSick) {
                                $isPaid = ($log['is_paid'] ?? 1) == 1;
                            $badgeClass = 'badge-sick';
                            $badgeLabel = $isPaid ? 'L4' : 'L4 (bezpł.)';
                        } elseif ($isVacation) {
                                $isPaid = ($log['is_paid'] ?? 1) == 1;
                            $badgeClass = 'badge-vacation';
                            $badgeLabel = $isPaid ? 'Urlop' : 'Urlop (bezpł.)';
                            } else {
                            $badgeClass = 'badge-work';
                            $badgeLabel = 'Praca';
                            }
                            ?>
                    <tr class="<?php echo e($rowClasses); ?>" style="<?php echo $isSick ? '' : ($isVacation ? '' : $workerRowStyle); ?>">
                        <td><?php echo formatDateWithDay((string)$log['date']); ?></td>
                        <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span></td>
                                <td>
                            <div style="font-weight:500;"><?php echo e($log['project_name'] ?? '-'); ?></div>
                            <div style="font-size:10px; color:var(--text-muted);"><?php echo e($log['cost_node_name'] ?? ''); ?></div>
                                </td>
                        
                        <?php if($isSick || $isVacation): ?>
                            <!-- Scalone komórki dla L4/Urlopu -->
                            <td colspan="6" style="text-align:center; color:var(--text-muted); font-style:italic;">
                                <?php echo $isSick ? 'Zwolnienie lekarskie' : 'Urlop wypoczynkowy'; ?>
                                (<?php echo number_format($absenceDays, 1, ',', ' '); ?> dni)
                            </td>
                                    <?php else: ?>
                            <!-- Normalne kolumny -->
                            <td class="col-money"><?php echo number_format($hours, 2, ',', ' '); ?></td>
                            <td class="col-money"><?php echo fmtCell($log['overtime_hours']); ?></td>
                            <td class="col-center"><?php echo fmtCell(!empty($log['is_saturday']) ? $hours : 0); ?></td>
                            <td class="col-center"><?php echo fmtCell(!empty($log['is_sunday']) ? $hours : 0); ?></td>
                            <td class="col-center"><?php echo fmtCell(!empty($log['is_night']) ? $hours : 0); ?></td>
                            <td class="col-center"><?php echo fmtCell(!empty($log['is_delegation']) ? $hours : 0); ?></td>
                                    <?php endif; ?>

                        <td class="col-money" style="color:var(--success);">
                            <?php echo $log['cost'] !== null ? formatMoney($log['cost']) : '<span class="col-dim">-</span>'; ?>
                            <?php if ($rateInfo['show_meta']): ?>
                                <div class="rate-meta"><?php echo e($rateInfo['meta_text']); ?></div>
                                <?php if ($rateInfo['is_mixed']): ?>
                                    <button type="button"
                                            class="rate-toggle js-rate-toggle"
                                            data-target="<?php echo e($rateDetailsRowId); ?>"
                                            aria-expanded="false">
                                        Szczegóły stawek
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                                </td>
                        <td style="color:var(--text-muted); font-size:11px; max-width:180px;"><?php echo e($log['description'] ?? ''); ?></td>
                            </tr>
                        <?php if ($rateInfo['is_mixed']): ?>
                            <tr id="<?php echo e($rateDetailsRowId); ?>" class="rate-details-row" hidden>
                                <td colspan="11">
                                    <div class="rate-details-panel">
                                        <div class="rate-details-title">Rozbicie stawek (wyliczone z kwoty końcowej)</div>
                                        <table class="rate-details-table">
                                            <thead>
                                                <tr>
                                                    <th>Składnik</th>
                                                    <th class="rate-num">Godziny</th>
                                                    <th class="rate-num">Stawka</th>
                                                    <th class="rate-num">Kwota</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rateInfo['segments'] as $segment): ?>
                                                    <tr>
                                                        <td><?php echo e($segment['label']); ?></td>
                                                        <td class="rate-num"><?php echo number_format((float)$segment['hours'], 2, ',', ' '); ?> h</td>
                                                        <td class="rate-num"><?php echo number_format((float)$segment['rate'], 2, ',', ' '); ?> zł/h</td>
                                                        <td class="rate-num"><?php echo formatMoney((float)$segment['amount']); ?></td>
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
            <?php endif; ?>
        
            <!-- 3. WYDATKI I ROZLICZENIA (GRID 2 KOLUMNY) -->
            <div class="two-col-grid">
                
                <!-- B) Wydatki -->
                <div>
                    <div class="section-title">
                        <span>B) Wydatki Pracownika (do zwrotu)</span>
                        <span style="font-size:12px; font-weight:400; color:var(--text-muted);">(Suma: <?php echo formatMoney($expensesTotal); ?>)</span>
                    </div>
                    <table class="data-table">
                    <thead>
                            <tr><th>Data</th><th>Opis</th><th class="col-money">Kwota</th></tr>
                    </thead>
                    <tbody>
                            <?php if(empty($expenses)): ?>
                                <tr><td colspan="3" class="col-dim" style="text-align:center; padding:20px;">Brak wydatków pracownika do zwrotu.</td></tr>
                            <?php else: ?>
                                <?php foreach($expenses as $expIndex => $exp): 
                                    $rptDesc = cleanEmployeeExpenseDescription($exp['description'] ?? '-');
                                    $isPaidByEmployee = isExpensePaidByEmployeeRow($exp);
                                    $zebraClass = ($expIndex % 2 === 0) ? 'zebra-odd' : 'zebra-even';
                                ?>
                                <tr class="<?php echo e($zebraClass); ?>" style="<?php echo $workerRowStyle; ?>">
                                    <td><?php echo formatDateWithDay((string)$exp['date']); ?></td>
                                    <td>
                                        <?php echo e($rptDesc); ?>
                                        <?php if($isPaidByEmployee): ?>
                                            <span style="display:inline-block; margin-left:4px; padding:2px 6px; background:#0891b2; color:white; border-radius:8px; font-size:9px;">PRAC.</span>
                                    <?php endif; ?>
                                        <div style="font-size:10px; color:var(--text-muted);"><?php echo e($exp['project_name'] ?? ''); ?></div>
                                </td>
                                    <td class="col-money" style="color:var(--success);">+ <?php echo formatMoney($exp['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <?php endif; ?>
                    </tbody>
                </table>
        </div>
        
        <!-- C) Rozliczenia -->
                <div>
                    <div class="section-title" style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                        <span>C) Rozliczenia (oś wypłaty)</span>
                </div>
                    <table class="data-table">
                    <thead>
                            <tr><th>Data</th><th>Typ</th><th class="col-money">Kwota</th></tr>
                    </thead>
                    <tbody>
                                    <?php 
                            // Łączymy stare i nowe rozliczenia dla widoku
                            // Zaliczki firmowe (advance_company) są pomijane — nie dotyczą osi wypłaty
                            $allS = [];
                            foreach($settlements as $s) {
                                $kind = normalizeAdvanceKindValue((string)($s['advance_kind'] ?? ''), 'private');
                                if ($s['type'] === 'advance' && $kind === 'company') continue;
                                $allS[] = [
                                    'date' => $s['report_date'] ?? $s['operation_date'],
                                    'operation_date' => $s['operation_date'] ?? $s['report_date'],
                                    'type' => $s['type'],
                                    'amount' => $s['amount'],
                                    'desc' => $s['description'] ?? '',
                                    'advance_kind' => $s['advance_kind'] ?? '',
                                    'period' => $s['period'] ?? ''
                                ];
                            }
                            foreach($newAdvances as $a) {
                                if ($a['advance_type'] === 'COMPANY') continue;
                                $allS[] = [
                                    'date' => $a['report_date'],
                                    'operation_date' => $a['operation_date'],
                                    'type' => 'advance',
                                    'amount' => $a['amount'],
                                    'desc' => $a['description'] ?? '',
                                    'advance_kind' => strtolower($a['advance_type']),
                                    'period' => $a['report_date'] ?? ''
                                ];
                            }
                            usort($allS, function($a,$b){ return strtotime($b['date']) - strtotime($a['date']); });
                            
                            if(empty($allS)): ?>
                                <tr><td colspan="3" class="col-dim" style="text-align:center; padding:20px;">Brak rozliczeń.</td></tr>
                            <?php else: ?>
                                <?php foreach($allS as $rowIndex => $row): 
                                    $kind = normalizeAdvanceKindValue((string)($row['advance_kind'] ?? ''), 'private');
                                    $effect = settlementBalanceEffect((string)$row['type'], $kind);
                                    $color = match($effect) {
                                        'plus' => 'var(--success)',
                                        'minus' => 'var(--danger)',
                                        default => 'var(--text-muted)'
                                    };
                                    $sign = match($effect) {
                                        'plus' => '+',
                                        'minus' => '-',
                                        default => ''
                                    };
                                    $zebraClass = ($rowIndex % 2 === 0) ? 'zebra-odd' : 'zebra-even';
                                    $label = match($row['type']) {
                                        'payout' => 'Wypłata',
                                        'advance' => 'Zaliczka',
                                        'reimbursement' => 'Zwrot wydatków',
                                        'bonus' => 'Premia',
                                        'correction' => 'Korekta',
                                        default => $row['type']
                                    };
                                    if ($row['type'] === 'advance') {
                                        if ($kind === 'company') {
                                            $kindLabel = 'firmowa';
                                        } elseif ($kind === 'company_private') {
                                            $kindLabel = 'firmowa do wypłaty';
                                        } else {
                                            $kindLabel = 'prywatna';
                                        }
                                        $label = 'Zaliczka (' . $kindLabel . ')';
                                    }
                                ?>
                                <?php
                                    $hasPeriod = !empty($row['period']) && $row['period'] !== '0000-00-00' && in_array($row['type'], ['payout', 'advance'], true);
                                    if ($hasPeriod) {
                                        $polishMonthNames = [
                                            1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',
                                            5=>'maj',6=>'czerwiec',7=>'lipiec',8=>'sierpień',
                                            9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'
                                        ];
                                        $periodTs = strtotime($row['period']);
                                        $periodMonthNum = (int)date('n', $periodTs);
                                        $periodYear = date('Y', $periodTs);
                                        $periodLabel = ($polishMonthNames[$periodMonthNum] ?? '') . ' ' . $periodYear;
                                    }
                                ?>
                                <tr class="<?php echo e($zebraClass); ?>" style="<?php echo $workerRowStyle; ?>">
                                    <td>
                                        <?php if ($hasPeriod): ?>
                                            <div class="date-cell-main" style="font-size:11px; color:var(--text-muted);">Rozliczenie za</div>
                                            <div class="date-cell-day" style="font-weight:600; color:var(--text);"><?php echo e($periodLabel); ?></div>
                                        <?php else: ?>
                                            <?php echo formatDateWithDay((string)$row['date']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $label; ?> <span style="font-size:10px; color:var(--text-muted);"><?php echo e($row['desc']); ?></span>
                                        <?php if (!empty($row['operation_date'])): ?>
                                            <div style="font-size:10px; color:var(--text-muted);">Data operacji: <?php echo e(formatDate($row['operation_date'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-money" style="color:<?php echo $color; ?>;"><?php echo ($sign !== '' ? $sign . ' ' : '') . formatMoney($row['amount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                            <?php endif; ?>
                    </tbody>
                </table>
                    <?php if ($workerId > 0): ?>
                    <div style="margin-top:10px; text-align:right;">
                        <a href="<?php echo e($workerHistoryUrl); ?>" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#2563eb; text-decoration:none; font-weight:700; padding:6px 10px; border:1px solid #bfdbfe; border-radius:8px; background:#eff6ff;">
                            Otwórz historię i zarządzaj operacjami
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Karta stanu portfela firmowego -->
                <div style="margin-top:14px; padding:14px 18px; background:var(--bg-card); border:1px solid var(--border); border-left:3px solid #2563eb; border-radius:8px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8" width="22" height="22"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2"/></svg>
                        <div>
                            <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Portfel firmowy</div>
                            <div style="font-size:18px; font-weight:700; color:#1e40af; margin-top:2px;"><?php echo formatMoney($walletBalance); ?></div>
                            <div style="font-size:11px; color:var(--text-muted); margin-top:1px;"><?php echo $walletOpenCount; ?> otwart<?php echo $walletOpenCount === 1 ? 'a zaliczka' : ($walletOpenCount >= 2 && $walletOpenCount <= 4 ? 'e zaliczki' : 'ych zaliczek'); ?></div>
                        </div>
                    </div>
                    <a href="<?php echo url('hr.workers.wallet', ['worker_id' => $workerId]); ?>" style="font-size:12px; color:#2563eb; text-decoration:none; font-weight:600; white-space:nowrap;">Szczegóły portfela →</a>
                </div>
        </div>
        
            <!-- D) PODSUMOWANIE OKRESU (SUMMARY BOX) -->
            <div class="summary-box">
                <div class="summary-box-title">D) Podsumowanie wynagrodzenia za okres</div>

                <div class="sum-row" style="padding:8px 0; border-bottom:1px solid var(--border-light);">
                    <span class="sum-label">Należne za pracę (A)</span>
                    <span class="sum-value"><?php echo formatMoney($workSummary['cost']); ?></span>
                </div>
                <?php if ($expensesTotal > 0): ?>
                <div class="sum-row" style="padding:8px 0; border-bottom:1px solid var(--border-light);">
                    <span class="sum-label">Zwrot wydatków pracownika (B)</span>
                    <span class="sum-value"><?php echo formatMoney($expensesTotal); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($correctionTotal != 0): ?>
                <div class="sum-row" style="padding:8px 0; border-bottom:1px solid var(--border-light);">
                    <span class="sum-label">Korekty</span>
                    <span class="sum-value"><?php echo ($correctionTotal > 0 ? '+' : '') . formatMoney($correctionTotal); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($bonusTotal > 0): ?>
                <div class="sum-row" style="padding:8px 0; border-bottom:1px solid var(--border-light);">
                    <span class="sum-label">Premia</span>
                    <span class="sum-value" style="color:#d97706; font-weight:700;"><?php echo formatMoney($bonusTotal); ?></span>
                </div>
                <?php endif; ?>
                <div class="sum-row" style="padding:8px 0; border-bottom:1px solid var(--border-light);">
                    <span class="sum-label" style="font-size:12px; font-weight:800; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Odjęto</span>
                    <span></span>
                </div>
                <div class="sum-row" style="padding:8px 0; border-bottom:1px solid var(--border-light);">
                    <span class="sum-label">Wypłaty końcowe</span>
                    <span class="sum-value"><?php echo formatMoney($paidOutTotal); ?></span>
                </div>
                <?php if ($privateAdvancesTotal > 0): ?>
                <div class="sum-row" style="padding:8px 0; border-bottom:1px solid var(--border-light);">
                    <span class="sum-label">Zaliczki prywatne</span>
                    <span class="sum-value"><?php echo formatMoney($privateAdvancesTotal); ?></span>
                </div>
                <?php endif; ?>
                <div class="sum-row" style="padding:8px 0; border-bottom:1px solid var(--border-light);">
                    <span class="sum-label">Razem już przekazano</span>
                    <span class="sum-value"><?php echo formatMoney($totalPaidToWorker); ?></span>
                </div>

                <div class="sum-row" style="padding:12px 0; margin-top:4px; border-top:2px solid var(--primary);">
                    <span style="font-size:15px; color:var(--primary); font-weight:700;">Pozostało do wypłaty</span>
                    <span style="font-size:22px; color:<?php echo $periodBalance > 0 ? 'var(--success)' : ($periodBalance < 0 ? 'var(--danger)' : 'var(--primary)'); ?>; font-weight:800;">
                        <?php echo formatMoney($periodBalance); ?> 
                    </span>
                </div>

                <?php if ($isAdminUser): ?>
                <?php if ($inlineFormSuccess): ?>
                    <div style="margin-top:12px; margin-bottom:10px; padding:10px 12px; background:#d1fae5; border-left:3px solid #059669; border-radius:6px; font-size:13px; color:#065f46;">
                        ✓ <?php echo e($inlineFormSuccess); ?>
                    </div>
                <?php endif; ?>
                <!-- Przycisk rozwijający formularz nowej operacji -->
                <div style="margin-top:20px; padding-top:16px; border-top:1px solid var(--border);">
                    <button type="button" id="toggleInlineForm" onclick="toggleInlineOpForm()" 
                        style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px; background:var(--primary); color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; transition:all 0.2s;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Rozlicz pracownika
                    </button>
                </div>

                <!-- Formularz inline nowej operacji -->
                <div id="inlineOpForm" style="display:none; margin-top:16px; padding:24px; background:#f8fafc; border:1px solid var(--border); border-radius:10px;">

                    <?php if (!empty($inlineFormErrors)): ?>
                        <div style="margin-bottom:16px; padding:12px 16px; background:#fee2e2; border-left:3px solid #dc2626; border-radius:6px; font-size:13px; color:#991b1b;">
                            <?php foreach ($inlineFormErrors as $err): ?>
                                <div>• <?php echo e($err); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="inlineNewOpForm">
                        <input type="hidden" name="_action" value="inline_new_operation">
                        <input type="hidden" name="_submit_token" value="<?php echo e($inlineFormToken); ?>">

                        <!-- Typ operacji -->
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Typ operacji <span style="color:#dc2626;">*</span></label>
                            <select name="op_type" id="iop_op_type" required onchange="iopUpdateFields()"
                                style="width:100%; max-width:380px; padding:0 12px; height:40px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#fff; font-family:inherit;">
                                <option value="">Wybierz typ</option>
                                <option value="payout">Wypłata</option>
                                <option value="advance_private">Zaliczka prywatna</option>
                                <option value="advance_company">Zasilenie portfela firmowego</option>
                                <option value="reimbursement">Zwrot kosztów</option>
                                <option value="bonus">Premia</option>
                                <option value="correction">Korekta</option>
                                <optgroup label="Transfery między pracownikami">
                                    <option value="transfer">Firmowy → Firmowy (portfel → portfel)</option>
                                    <option value="transfer_to_priv">Firmowy → Prywatna (gotówka z portfela)</option>
                                </optgroup>
                            </select>
                        </div>

                        <!-- Blok: pojedynczy pracownik (domyślnie ukryty dla transfer*) -->
                        <div id="iop-fields-single" style="display:none;">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                                <div>
                                    <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Pracownik <span style="color:#dc2626;">*</span></label>
                                    <select name="worker_id" id="iop_worker_sel"
                                        style="width:100%; padding:0 12px; height:40px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#fff; font-family:inherit;">
                                        <?php foreach ($formWorkers as $fw): ?>
                                            <option value="<?php echo $fw['id']; ?>" <?php echo (int)$fw['id'] === $workerId ? 'selected' : ''; ?>>
                                                <?php echo e($fw['last_name'] . ' ' . $fw['first_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <!-- Źródło finansowania (tylko dla advance_company) -->
                                <div id="iop-source-kind" style="display:none;">
                                    <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Źródło finansowania <span style="color:#dc2626;">*</span></label>
                                    <select name="source_kind" id="iop_source_kind"
                                        style="width:100%; padding:0 12px; height:40px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#fff; font-family:inherit;">
                                        <option value="">Wybierz</option>
                                        <option value="cash">Gotówka</option>
                                        <option value="bank">Przelew bankowy</option>
                                        <option value="other">Inne</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Blok: transfer A→B -->
                        <div id="iop-fields-transfer" style="display:none; margin-bottom:16px;">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                                <div>
                                    <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Portfel źródłowy</label>
                                    <input type="hidden" name="from_worker_id" value="<?php echo (int)$workerId; ?>">
                                    <div style="min-height:40px; display:flex; align-items:center; padding:0 12px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#f8fafc; color:#1f2937;">
                                        <?php echo e($workerName); ?> — saldo portfela: <?php echo formatMoney($walletBalance); ?>
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Do pracownika <span style="color:#dc2626;">*</span></label>
                                    <select name="to_worker_id"
                                        style="width:100%; padding:0 12px; height:40px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#fff; font-family:inherit;">
                                        <option value="">Wybierz pracownika</option>
                                        <?php foreach ($formWorkers as $fw): ?>
                                            <option value="<?php echo $fw['id']; ?>"><?php echo e($fw['last_name'] . ' ' . $fw['first_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Blok: transfer firmowy→prywatna -->
                        <div id="iop-fields-ttp" style="display:none; margin-bottom:16px;">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                                <div>
                                    <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Portfel źródłowy</label>
                                    <input type="hidden" name="ttp_from_worker_id" value="<?php echo (int)$workerId; ?>">
                                    <div style="min-height:40px; display:flex; align-items:center; padding:0 12px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#f8fafc; color:#1f2937;">
                                        <?php echo e($workerName); ?> — saldo portfela: <?php echo formatMoney($walletBalance); ?>
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Pracownik otrzymujący <span style="color:#dc2626;">*</span></label>
                                    <select name="ttp_to_worker_id"
                                        style="width:100%; padding:0 12px; height:40px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#fff; font-family:inherit;">
                                        <option value="">Wybierz pracownika</option>
                                        <?php foreach ($formWorkers as $fw): ?>
                                            <option value="<?php echo $fw['id']; ?>"><?php echo e($fw['last_name'] . ' ' . $fw['first_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Wspólne pola: kwota, data, okres, opis -->
                        <div id="iop-fields-common" style="display:none;">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                                <div>
                                    <label id="iop-amount-label" style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Kwota (PLN) <span style="color:#dc2626;">*</span></label>
                                    <input type="number" name="amount" step="0.01" min="0.01" placeholder="0.00"
                                        style="width:100%; padding:0 12px; height:40px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#fff; font-family:inherit;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Data operacji <span style="color:#dc2626;">*</span></label>
                                    <input type="date" name="op_date" value="<?php echo date('Y-m-d'); ?>"
                                        style="width:100%; padding:0 12px; height:40px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#fff; font-family:inherit;">
                                </div>
                            </div>
                            <div id="iop-period-group" style="margin-bottom:12px;">
                                <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Okres rozliczeniowy <span style="color:#dc2626;">*</span></label>
                                <input type="month" name="period" value="<?php echo date('Y-m'); ?>"
                                    style="width:200px; padding:0 12px; height:40px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#fff; font-family:inherit;">
                                <span style="font-size:11px; color:var(--text-muted); margin-left:8px;">Miesiąc, którego dotyczy operacja</span>
                            </div>
                            <div style="margin-bottom:16px;">
                                <label style="display:block; font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:6px;">Opis (opcjonalnie)</label>
                                <textarea name="description" placeholder="Dodatkowe informacje..."
                                    style="width:100%; padding:10px 12px; border:1px solid #dbe3ef; border-radius:8px; font-size:13px; background:#fff; font-family:inherit; resize:vertical; min-height:70px;"></textarea>
                            </div>
                        </div>

                        <!-- Przyciski -->
                        <div id="iop-actions" style="display:none; display:flex; gap:10px; align-items:center;">
                            <button type="submit" id="iop-submit"
                                style="padding:10px 22px; background:var(--primary); color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer;">
                                Zapisz operację
                            </button>
                            <button type="button" onclick="toggleInlineOpForm()"
                                style="padding:10px 16px; background:transparent; color:var(--text-muted); border:1px solid var(--border); border-radius:8px; font-size:13px; cursor:pointer;">
                                Anuluj
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?> v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
    function rOnMonthYearChange() {
        const month = parseInt(document.getElementById('rSelectMonth').value);
        const year  = parseInt(document.getElementById('rSelectYear').value);
        if (!month) return;
        const lastDay = new Date(year, month, 0).getDate();
        const pad = n => String(n).padStart(2, '0');
        document.getElementById('rInputDateFrom').value = year + '-' + pad(month) + '-01';
        document.getElementById('rInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
        document.getElementById('reportFilterForm').submit();
    }

    function toggleColors() {
        document.body.classList.toggle('no-colors');
        const btn = document.querySelector('.btn-color-mode');
        btn.classList.toggle('active');
        // 1 = kolory włączone (brak klasy no-colors), 0 = wyłączone
        const colorsEnabled = !document.body.classList.contains('no-colors');
        localStorage.setItem('worklog_colors', colorsEnabled ? '1' : '0');
    }
    document.addEventListener('DOMContentLoaded', function() {
        localStorage.removeItem('report_table_tint');
        localStorage.removeItem('report_table_white');
        // Domyślnie kolory; wyłącz tylko jeśli użytkownik wcześniej wyłączył
        if (localStorage.getItem('worklog_colors') === '0') {
            document.body.classList.add('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) btn.classList.remove('active');
        }
    });
    
    function downloadJSON() {
        const params = new URLSearchParams(window.location.search);
        params.set('export', 'json');
        window.location.href = '?' + params.toString();
    }

    document.addEventListener('click', function (event) {
        const toggleButton = event.target.closest('.js-rate-toggle');
        if (!toggleButton) {
            return;
        }

        const targetId = toggleButton.getAttribute('data-target');
        const targetRow = targetId ? document.getElementById(targetId) : null;
        if (!targetRow) {
            return;
        }

        const isExpanded = toggleButton.getAttribute('aria-expanded') === 'true';

        document.querySelectorAll('.rate-details-row').forEach(function (row) {
            row.hidden = true;
        });
        document.querySelectorAll('.js-rate-toggle').forEach(function (btn) {
            btn.setAttribute('aria-expanded', 'false');
            btn.textContent = 'Szczegóły stawek';
        });

        if (!isExpanded) {
            targetRow.hidden = false;
            toggleButton.setAttribute('aria-expanded', 'true');
            toggleButton.textContent = 'Ukryj szczegóły';
        }
    });

    // ── Formularz inline nowej operacji ──────────────────────────────────
    function toggleInlineOpForm() {
        const form = document.getElementById('inlineOpForm');
        const btn  = document.getElementById('toggleInlineForm');
        if (!form) return;
        const isOpen = form.style.display !== 'none';
        form.style.display = isOpen ? 'none' : 'block';
        if (btn) {
            btn.innerHTML = isOpen
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Rozlicz pracownika'
                : '✕ Zamknij formularz';
        }
        if (!isOpen) {
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function iopUpdateFields() {
        const opType     = document.getElementById('iop_op_type').value;
        const single     = document.getElementById('iop-fields-single');
        const transfer   = document.getElementById('iop-fields-transfer');
        const ttp        = document.getElementById('iop-fields-ttp');
        const common     = document.getElementById('iop-fields-common');
        const actions    = document.getElementById('iop-actions');
        const srcKind    = document.getElementById('iop-source-kind');
        const periodGrp  = document.getElementById('iop-period-group');
        const amtLabel   = document.getElementById('iop-amount-label');

        single.style.display   = 'none';
        transfer.style.display = 'none';
        ttp.style.display      = 'none';
        common.style.display   = 'none';
        actions.style.display  = 'none';
        srcKind.style.display  = 'none';

        if (!opType) return;

        common.style.display  = '';
        actions.style.display = 'flex';

        const labels = {
            'payout':           'Kwota wypłaty (PLN)',
            'advance_private':  'Kwota zaliczki (PLN)',
            'advance_company':  'Kwota zasilenia (PLN)',
            'reimbursement':    'Kwota zwrotu (PLN)',
            'bonus':            'Kwota premii (PLN)',
            'correction':       'Kwota korekty (PLN)',
            'transfer':         'Kwota transferu (PLN)',
            'transfer_to_priv': 'Kwota gotówki (PLN)',
        };
        if (amtLabel) amtLabel.innerHTML = (labels[opType] || 'Kwota (PLN)') + ' <span style="color:#dc2626;">*</span>';

        const noPeriod = ['advance_company', 'transfer'];
        periodGrp.style.display = noPeriod.includes(opType) ? 'none' : '';

        if (opType === 'transfer') {
            transfer.style.display = '';
        } else if (opType === 'transfer_to_priv') {
            ttp.style.display = '';
        } else {
            single.style.display = '';
            if (opType === 'advance_company') srcKind.style.display = '';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const inlineForm = document.getElementById('inlineNewOpForm');
        const submitBtn = document.getElementById('iop-submit');
        if (inlineForm && submitBtn) {
            inlineForm.addEventListener('submit', function(event) {
                if (inlineForm.dataset.submitted === '1') {
                    event.preventDefault();
                    return;
                }
                inlineForm.dataset.submitted = '1';
                submitBtn.disabled = true;
                submitBtn.textContent = 'Zapisywanie...';
                submitBtn.style.opacity = '0.75';
                submitBtn.style.cursor = 'not-allowed';
            });
        }
    });

    // Jeśli po POST mamy błędy — automatycznie otwórz formularz
    <?php if (!empty($inlineFormErrors)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('inlineOpForm');
        if (form) {
            form.style.display = 'block';
            const btn = document.getElementById('toggleInlineForm');
            if (btn) btn.innerHTML = '✕ Zamknij formularz';
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            // Przywróć wybrany typ operacji
            const opSel = document.getElementById('iop_op_type');
            <?php if (!empty($_POST['op_type'])): ?>
            if (opSel) { opSel.value = '<?php echo e($_POST['op_type']); ?>'; iopUpdateFields(); }
            <?php endif; ?>
        }
    });
    <?php endif; ?>
    </script>
</body>
</html>
