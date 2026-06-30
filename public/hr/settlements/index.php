<?php
/**
 * BRYGAD ERP — Centrum rozliczeń
 * Zakładki: Salda | Historia operacji | Nowa operacja
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
require_once dirname(__DIR__, 2) . '/includes/payroll_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$isAdminUser = isAdmin();

// Aktywna zakładka
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'balances';
if (!in_array($tab, ['balances', 'history', 'new'])) {
    $tab = 'balances';
}

// ========================================
// FUNKCJE POMOCNICZE
// ========================================

function workerColor(int $id): array
{
    $h = fmod($id * 0.618033988749895, 1.0) * 360;
    return [
        'bg'     => "hsla({$h}, 65%, 55%, 0.07)",
        'border' => "hsla({$h}, 65%, 55%, 0.55)",
    ];
}

function settlementTypeLabel(string $type, string $advanceKind = ''): string
{
    if ($type === 'advance') {
        return $advanceKind === 'company' ? 'Zaliczka firmowa' : 'Zaliczka prywatna';
    }
    return [
        'payout'        => 'Wypłata',
        'reimbursement' => 'Zwrot kosztów',
        'bonus'         => 'Premia',
        'correction'    => 'Korekta',
    ][$type] ?? $type;
}

function operationTypeLabel(string $opType): string
{
    return [
        'payout'           => 'Wypłata',
        'advance_private'  => 'Zaliczka prywatna',
        'advance_company'  => 'Zasilenie portfela',
        'reimbursement'    => 'Zwrot kosztów',
        'bonus'            => 'Premia',
        'correction'       => 'Korekta',
        'transfer'         => 'Transfer firmowy A→B',
        'transfer_to_priv' => 'Przekazanie gotówki (firmowa→prywatna)',
    ][$opType] ?? $opType;
}

// ========================================
// DANE: SUMA SALD (hero bar — zawsze)
// ========================================
$totalBalance = 0;
try {
    $stmtSum = $pdo->query("
        SELECT SUM(bal) AS total FROM (
            SELECT
                (
                    COALESCE((SELECT SUM(final_cost) FROM work_logs wl WHERE wl.worker_id = w.id AND wl.status = 'approved' AND wl.final_cost IS NOT NULL), 0)
                    + COALESCE((SELECT SUM(we.amount) FROM worker_expenses we WHERE we.worker_id = w.id AND we.status IN ('approved','reimbursed') AND (we.paid_by_employee = 1 OR we.description LIKE '%[PAID_BY_EMPLOYEE]%')), 0)
                    + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'bonus'), 0)
                    + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'correction'), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'payout'), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'advance' AND (s.advance_kind = 'private' OR s.advance_kind IS NULL) AND NOT EXISTS (SELECT 1 FROM worker_advances wa_dup WHERE wa_dup.worker_id = s.worker_id AND wa_dup.type = 'PRIVATE' AND wa_dup.issue_date = s.date AND wa_dup.amount = s.amount)), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'reimbursement'), 0)
                    - COALESCE((SELECT SUM(wa.amount) FROM worker_advances wa WHERE wa.worker_id = w.id AND wa.type = 'PRIVATE'), 0)
                ) AS bal
            FROM workers w
            WHERE w.is_active = 1
        ) sub
    ");
    $totalBalance = (float)($stmtSum->fetchColumn() ?: 0);
} catch (PDOException $e) {
    logEvent('Centrum rozliczen - suma sald error: ' . $e->getMessage(), 'ERROR');
}

// ========================================
// DANE: SALDA
// ========================================
$balances = [];
if ($tab === 'balances') {
    try {
        $stmt = $pdo->query("
            SELECT
                w.id,
                w.first_name,
                w.last_name,
                w.is_active,
                (
                    COALESCE((SELECT SUM(final_cost) FROM work_logs wl WHERE wl.worker_id = w.id AND wl.status = 'approved' AND wl.final_cost IS NOT NULL), 0)
                    + COALESCE((SELECT SUM(we.amount) FROM worker_expenses we WHERE we.worker_id = w.id AND we.status IN ('approved','reimbursed') AND (we.paid_by_employee = 1 OR we.description LIKE '%[PAID_BY_EMPLOYEE]%')), 0)
                    + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'bonus'), 0)
                    + COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'correction'), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'payout'), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'advance' AND (s.advance_kind = 'private' OR s.advance_kind IS NULL) AND NOT EXISTS (SELECT 1 FROM worker_advances wa_dup WHERE wa_dup.worker_id = s.worker_id AND wa_dup.type = 'PRIVATE' AND wa_dup.issue_date = s.date AND wa_dup.amount = s.amount)), 0)
                    - COALESCE((SELECT SUM(s.amount) FROM settlements s WHERE s.worker_id = w.id AND s.type = 'reimbursement'), 0)
                    - COALESCE((SELECT SUM(wa.amount) FROM worker_advances wa WHERE wa.worker_id = w.id AND wa.type = 'PRIVATE'), 0)
                ) AS balance,
                (SELECT MAX(s2.date) FROM settlements s2 WHERE s2.worker_id = w.id) AS last_settlement_date
            FROM workers w
            WHERE w.is_active = 1
            ORDER BY w.last_name, w.first_name
        ");
        $balances = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent('Centrum rozliczen - salda error: ' . $e->getMessage(), 'ERROR');
        $balances = [];
    }
}

// ========================================
// DANE: HISTORIA OPERACJI
// ========================================
$history = [];
$historyTotal = 0;
$histWorkers = [];
$historyReturnUrl = url('hr.settlements') . '?tab=history';
$historyFlash = isset($_GET['hist_flash']) ? trim((string)$_GET['hist_flash']) : '';

// Zmienne dat dla szybkich filtrów (dostępne zawsze)
$todayDate  = date('Y-m-d');
$weekAgo    = date('Y-m-d', strtotime('-7 days'));
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');
$yearStart  = date('Y-01-01');

if ($tab === 'history') {
    // Lista pracowników do filtra
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers ORDER BY last_name, first_name");
        $histWorkers = $stmt->fetchAll();
    } catch (PDOException $e) {
        $histWorkers = [];
    }

    $fWorker  = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
    $fType    = isset($_GET['type']) ? trim($_GET['type']) : '';
    $fFrom    = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : '';
    $fTo      = isset($_GET['date_to'])   && !empty($_GET['date_to'])  ? $_GET['date_to']   : '';

    $historyReturnParams = ['tab' => 'history'];
    if ($fWorker > 0)  { $historyReturnParams['worker_id'] = $fWorker; }
    if ($fType !== '') { $historyReturnParams['type'] = $fType; }
    if ($fFrom !== '') { $historyReturnParams['date_from'] = $fFrom; }
    if ($fTo !== '')   { $historyReturnParams['date_to'] = $fTo; }
    $historyReturnUrl = url('hr.settlements') . '?' . http_build_query($historyReturnParams);

    // Zbieramy z dwóch źródeł: settlements + worker_advances (PRIVATE)
    // Settlements
    $sWhere  = ['1=1'];
    $sParams = [];
    if ($fWorker > 0)   { $sWhere[] = 's.worker_id = ?'; $sParams[] = $fWorker; }
    if (!empty($fFrom)) { $sWhere[] = 's.date >= ?';     $sParams[] = $fFrom; }
    if (!empty($fTo))   { $sWhere[] = 's.date <= ?';     $sParams[] = $fTo; }

    // Filtr typu: mapowanie UI → DB
    $typeToSettlementType = [
        'payout'          => ['s.type = ?', 'payout'],
        'advance_private' => ['s.type = ? AND (s.advance_kind = ? OR s.advance_kind IS NULL)', 'advance', 'private'],
        'reimbursement'   => ['s.type = ?', 'reimbursement'],
        'bonus'           => ['s.type = ?', 'bonus'],
        'correction'      => ['s.type = ?', 'correction'],
    ];

    $settlementsQuery = null;
    $settlementsParams = $sParams;

    if (!empty($fType) && isset($typeToSettlementType[$fType])) {
        $filterDef = $typeToSettlementType[$fType];
        $sWhere[] = $filterDef[0];
        for ($i = 1; $i < count($filterDef); $i++) {
            $settlementsParams[] = $filterDef[$i];
        }
        $settlementsQuery = "
            SELECT
                s.id,
                s.worker_id,
                w.first_name,
                w.last_name,
                s.date,
                s.type AS raw_type,
                s.advance_kind,
                s.amount,
                s.description,
                'settlement' AS source
            FROM settlements s
            INNER JOIN workers w ON w.id = s.worker_id
            WHERE " . implode(' AND ', $sWhere) . "
            ORDER BY s.date DESC, s.id DESC
        ";
    } elseif (empty($fType) || $fType !== 'advance_company' && $fType !== 'transfer') {
        // Wszystkie typy settlements
        $settlementsQuery = "
            SELECT
                s.id,
                s.worker_id,
                w.first_name,
                w.last_name,
                s.date,
                s.type AS raw_type,
                s.advance_kind,
                s.amount,
                s.description,
                'settlement' AS source
            FROM settlements s
            INNER JOIN workers w ON w.id = s.worker_id
            WHERE " . implode(' AND ', $sWhere) . "
            ORDER BY s.date DESC, s.id DESC
        ";
        $settlementsParams = $sParams;
    }

    $historySettlements = [];
    if ($settlementsQuery) {
        try {
            $stmt = $pdo->prepare($settlementsQuery);
            $stmt->execute($settlementsParams);
            $historySettlements = $stmt->fetchAll();
        } catch (PDOException $e) {
            logEvent('Centrum rozliczen - history settlements error: ' . $e->getMessage(), 'ERROR');
        }
    }

    // Worker advances: PRIVATE (zaliczka prywatna) + COMPANY (zasilenie)
    $waWhere  = ['1=1'];
    $waParams = [];
    if ($fWorker > 0)   { $waWhere[] = 'wa.worker_id = ?'; $waParams[] = $fWorker; }
    if (!empty($fFrom)) { $waWhere[] = 'wa.issue_date >= ?'; $waParams[] = $fFrom; }
    if (!empty($fTo))   { $waWhere[] = 'wa.issue_date <= ?'; $waParams[] = $fTo; }

    if ($fType === 'advance_private') {
        $waWhere[] = "wa.type = 'PRIVATE'";
    } elseif ($fType === 'advance_company') {
        $waWhere[] = "wa.type = 'COMPANY'";
    } elseif (in_array($fType, ['transfer', 'transfer_to_priv'])) {
        $waWhere[] = "1=0"; // transfery nie są w worker_advances bezpośrednio
    } elseif (!empty($fType) && !in_array($fType, ['advance_private', 'advance_company'])) {
        $waWhere[] = "1=0"; // inne settlement types — nie pokazuj advances
    }

    $historyAdvances = [];
    try {
        $salaryPeriodSelect = payrollSalaryPeriodSelectSql($pdo, 'wa');
        $stmt = $pdo->prepare("
            SELECT
                wa.id,
                wa.worker_id,
                w.first_name,
                w.last_name,
                wa.issue_date AS date,
                {$salaryPeriodSelect} AS salary_period,
                wa.type       AS raw_type,
                NULL          AS advance_kind,
                wa.amount,
                wa.description,
                'advance'     AS source
            FROM worker_advances wa
            INNER JOIN workers w ON w.id = wa.worker_id
            WHERE " . implode(' AND ', $waWhere) . "
            ORDER BY wa.issue_date DESC, wa.id DESC
        ");
        $stmt->execute($waParams);
        $historyAdvances = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent('Centrum rozliczen - history advances error: ' . $e->getMessage(), 'ERROR');
    }

    // Scal i posortuj
    $rawHistory = array_merge($historySettlements, $historyAdvances);
    usort($rawHistory, fn($a, $b) => strcmp($b['date'], $a['date']));
    $history = $rawHistory;
    $historyTotal = count($history);

    // Grupowanie po datach
    $historyByDate = [];
    foreach ($history as $h) {
        $d = $h['date'];
        if (!isset($historyByDate[$d])) {
            $historyByDate[$d] = ['rows' => [], 'total_amount' => 0, 'count' => 0];
        }
        $historyByDate[$d]['rows'][] = $h;
        $historyByDate[$d]['total_amount'] += (float)$h['amount'];
        $historyByDate[$d]['count']++;
    }
}

// ========================================
// DANE: Formularz — lista pracowników
// ========================================
$formWorkers = [];
if ($tab === 'new') {
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $formWorkers = $stmt->fetchAll();
    } catch (PDOException $e) {
        $formWorkers = [];
    }
}

// Preselect worker z URL (z przycisku "Rozlicz" w liście pracowników)
$preWorker = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
$preSourceWorker = isset($_GET['from_worker_id']) ? (int)$_GET['from_worker_id'] : 0;
$preSourceAdvance = isset($_GET['from_advance_id']) ? (int)$_GET['from_advance_id'] : 0;
if ($preSourceWorker <= 0 && $preSourceAdvance > 0) {
    $preSourceWorker = walletResolveCompanyAdvanceWorkerId($pdo, $preSourceAdvance);
}

// ========================================
// OBSŁUGA POST — Nowa operacja
// ========================================
$formErrors  = [];
$formSuccess = false;
$formSuccessMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'new_operation') {
    $tab = 'new';

    // Pobierz pracowników do formularza
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $formWorkers = $stmt->fetchAll();
    } catch (PDOException $e) {
        $formWorkers = [];
    }
    try {
        $postedSourceWorker = (int)($_POST['from_worker_id'] ?? $preSourceWorker);
        $walletSourceWorkers = walletGetCompanySourceWorkers($pdo, $postedSourceWorker > 0 ? [$postedSourceWorker] : []);
    } catch (PDOException $e) {
        $walletSourceWorkers = [];
    }

    $opType        = trim($_POST['op_type'] ?? '');
    $workerId      = (int)($_POST['worker_id'] ?? 0);
    $toWorkerId    = (int)($_POST['to_worker_id'] ?? 0);
    $amount        = (float)str_replace(',', '.', trim($_POST['amount'] ?? '0'));
    $opDate        = trim($_POST['op_date'] ?? date('Y-m-d'));
    $periodRaw     = trim($_POST['period'] ?? '');
    $period        = payrollNormalizeMonth($periodRaw, $opDate);
    $description   = trim($_POST['description'] ?? '');
    $sourceKind    = trim($_POST['source_kind'] ?? '');
    $sourceRef     = trim($_POST['source_ref'] ?? '');
    $fromWorkerId = (int)($_POST['from_worker_id'] ?? $preSourceWorker);
    // transfer_to_priv: portfel firmowy A → zaliczka prywatna B
    $ttpFromWorkerId = (int)($_POST['ttp_from_worker_id'] ?? $fromWorkerId);
    $ttpToWorkerId    = (int)($_POST['ttp_to_worker_id'] ?? 0);

    // Walidacja wspólna
    $validOpTypes = ['payout', 'advance_private', 'advance_company', 'reimbursement', 'bonus', 'correction', 'transfer', 'transfer_to_priv'];
    if (!in_array($opType, $validOpTypes)) {
        $formErrors[] = 'Wybierz typ operacji.';
    }
    if ($amount <= 0) {
        $formErrors[] = 'Kwota musi być większa od 0.';
    }
    if (empty($opDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $opDate)) {
        $formErrors[] = 'Data operacji jest nieprawidłowa.';
    }
    if (in_array($opType, ['payout', 'reimbursement', 'bonus', 'correction', 'advance_private', 'transfer_to_priv'], true) && !payrollIsMonthInputValid($periodRaw)) {
        $formErrors[] = 'Okres rozliczeniowy jest nieprawidłowy.';
    }
    if ($opType !== 'transfer' && $opType !== 'transfer_to_priv' && $workerId <= 0) {
        $formErrors[] = 'Wybierz pracownika.';
    }
    if ($opType === 'transfer' && ($fromWorkerId <= 0 || $toWorkerId <= 0)) {
        $formErrors[] = 'Dla transferu: wybierz pracownika źródłowego i pracownika docelowego.';
    }
    if ($opType === 'transfer_to_priv' && ($ttpFromWorkerId <= 0 || $ttpToWorkerId <= 0)) {
        $formErrors[] = 'Dla przekazania gotówki: wybierz portfel firmowy źródłowy i pracownika otrzymującego.';
    }
    if ($opType === 'advance_company' && empty($sourceKind)) {
        $formErrors[] = 'Dla zasilenia portfela wybierz źródło finansowania.';
    }

    if (empty($formErrors)) {
        try {
            $pdo->beginTransaction();
            $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

            if (in_array($opType, ['payout', 'reimbursement', 'bonus', 'correction'])) {
                // → settlements
                $settlementType = $opType;
                $advanceKind = null;
                $stmt = $pdo->prepare("
                    INSERT INTO settlements (worker_id, type, advance_kind, amount, date, period, description, created_by_user_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$workerId, $settlementType, $advanceKind, $amount, $opDate, $period, $description, $createdBy]);
                $formSuccessMsg = operationTypeLabel($opType) . ' została zapisana.';

            } elseif ($opType === 'advance_private') {
                // → worker_advances (PRIVATE) + worker_ledger
                if (payrollWorkerAdvancesHasSalaryPeriod($pdo)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO worker_advances (worker_id, type, amount, issue_date, salary_period, description, status, created_by, created_at)
                        VALUES (?, 'PRIVATE', ?, ?, ?, ?, 'open', ?, NOW())
                    ");
                    $stmt->execute([$workerId, $amount, $opDate, $period, $description, $createdBy]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO worker_advances (worker_id, type, amount, issue_date, description, status, created_by, created_at)
                        VALUES (?, 'PRIVATE', ?, ?, ?, 'open', ?, NOW())
                    ");
                    $stmt->execute([$workerId, $amount, $opDate, $description, $createdBy]);
                }
                $advanceId = (int)$pdo->lastInsertId();

                $ledgerText = 'Zaliczka prywatna' . (!empty($description) ? ': ' . $description : '');
                $stmt = $pdo->prepare("
                    INSERT INTO worker_ledger (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at)
                    VALUES (?, 'ADVANCE', ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$workerId, -1 * $amount, $opDate, $advanceId, $ledgerText, $createdBy]);
                $formSuccessMsg = 'Zaliczka prywatna została zapisana.';

            } elseif ($opType === 'advance_company') {
                // → worker_advances (COMPANY) + worker_ledger + worker_wallet_funding
                $stmt = $pdo->prepare("
                    INSERT INTO worker_advances (worker_id, type, amount, issue_date, description, status, created_by, created_at)
                    VALUES (?, 'COMPANY', ?, ?, ?, 'open', ?, NOW())
                ");
                $stmt->execute([$workerId, $amount, $opDate, $description, $createdBy]);
                $advanceId = (int)$pdo->lastInsertId();

                $ledgerText = 'Zasilenie portfela firmowego' . (!empty($description) ? ': ' . $description : '');
                $stmt = $pdo->prepare("
                    INSERT INTO worker_ledger (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at)
                    VALUES (?, 'ADVANCE', ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$workerId, -1 * $amount, $opDate, $advanceId, $ledgerText, $createdBy]);

                $fundingNote = 'Zasilenie portfela' . (!empty($description) ? ': ' . $description : '');
                $stmt = $pdo->prepare("
                    INSERT INTO worker_wallet_funding (worker_id, advance_id, direction, amount, source_kind, source_ref, note, movement_date, created_by, created_at)
                    VALUES (?, ?, 'OUT_TOPUP', ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $workerId, $advanceId, $amount,
                    $sourceKind,
                    !empty($sourceRef) ? $sourceRef : null,
                    $fundingNote, $opDate, $createdBy
                ]);
                $formSuccessMsg = 'Zasilenie portfela firmowego zostało zapisane.';

            } elseif ($opType === 'advance_company_private') {
                // → settlements (type=advance, advance_kind=company_private)
                // Pracownik wyłożył gotówkę firmową — kwota doliczana do wypłaty (obciąża saldo jak advance_private)
                $stmt = $pdo->prepare("
                    INSERT INTO settlements (worker_id, type, advance_kind, amount, date, period, description, created_by_user_id)
                    VALUES (?, 'advance', 'company_private', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$workerId, $amount, $opDate, $period, $description, $createdBy]);
                $formSuccessMsg = 'Zaliczka firmowa (do wypłaty) została zapisana.';

            } elseif ($opType === 'transfer_to_priv') {
                if ($ttpFromWorkerId === $ttpToWorkerId) {
                    throw new RuntimeException('Pracownik źródłowy i docelowy muszą być różni.');
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

                $formSuccessMsg = 'Przekazanie gotówki zostało zapisane.';

            } elseif ($opType === 'transfer') {
                if ($fromWorkerId === $toWorkerId) {
                    throw new RuntimeException('Pracownik źródłowy i docelowy muszą być różni.');
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

                $formSuccessMsg = 'Transfer A→B został zapisany.';
            }

            $pdo->commit();
            $formSuccess = true;
            logEvent("Centrum rozliczen: nowa operacja '{$opType}' worker_id={$workerId} kwota={$amount}", 'INFO');

        } catch (Exception $e) {
            $pdo->rollBack();
            $formErrors[] = 'Błąd zapisu: ' . $e->getMessage();
            logEvent('Centrum rozliczen - POST error: ' . $e->getMessage(), 'ERROR');
        }
    }

    // Po sukcesie przekieruj z powrotem (PRG pattern)
    if ($formSuccess) {
        $redir = url('hr.settlements') . '?tab=new&saved=1';
        if ($preWorker > 0) $redir .= '&worker_id=' . $preWorker;
        header('Location: ' . $redir);
        exit;
    }
}

// Źródłowe zaliczki firmowe (do transferu) — wszystkie otwarte
if (!isset($walletSourceWorkers)) {
    try {
        $walletSourceWorkers = walletGetCompanySourceWorkers($pdo);
    } catch (PDOException $e) {
        $walletSourceWorkers = [];
    }
}

$preWorker = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
$savedMsg  = isset($_GET['saved']) && $_GET['saved'] === '1';

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Centrum rozliczeń</title>
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

        /* Layout */
        .dashboard-layout { display: flex; align-items: flex-start; gap: 0; }
        .dashboard-content { flex: 1; min-width: 0; padding-left: 16px; }
        .sidebar-wrapper.collapsed + .dashboard-content { padding-left: 0; }
        .sidebar-wrapper { position: relative; flex-shrink: 0; z-index: 10; }
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
            position: absolute; top: 20px; right: -14px;
            width: 28px; height: 28px;
            background: white; border: 1px solid #e5e7eb; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 20; color: var(--primary-blue); transition: all 0.3s ease;
        }
        .toggle-sidebar-btn:hover { background: #f8fafc; transform: scale(1.1); }
        .sidebar-wrapper.collapsed .toggle-sidebar-btn { right: -32px; transform: rotate(180deg); }
        .sidebar-content-inner { width: 240px; }
        .sidebar-search { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-search input {
            width: 100%; padding: 8px 12px;
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
            color: white; border-radius: 7px; font-size: 12px; transition: all 0.2s;
        }
        .sidebar-search input::placeholder { color: #93c5fd; }
        .sidebar-search input:focus { outline: none; background: rgba(255,255,255,0.15); border-color: #60a5fa; }
        .sidebar-actions-body { padding: 10px; }
        .sidebar-section { margin-bottom: 4px; border: 1px solid rgba(255,255,255,0.1); border-radius: 7px; overflow: hidden; }
        .sidebar-section-title {
            font-size: 11px; color: #93c5fd; text-transform: uppercase;
            letter-spacing: 0.8px; font-weight: 700;
            list-style: none; margin: 0; padding: 8px 10px;
            cursor: pointer; user-select: none;
            display: flex; align-items: center; justify-content: space-between;
            background: rgba(255,255,255,0.04);
        }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after { content: '›'; font-size: 14px; color: rgba(255,255,255,0.4); transition: transform 0.2s ease; line-height: 1; }
        .sidebar-section[open] .sidebar-section-title::after { transform: rotate(90deg); }
        .sidebar-section-links { padding: 4px 6px 6px; }
        .sidebar-actions a {
            display: block; padding: 7px 10px; margin-bottom: 2px;
            color: #e2e8f0; text-decoration: none; border-radius: 5px;
            transition: all 0.15s ease; font-size: 12px; font-weight: 500;
        }
        .sidebar-actions a:hover { background: rgba(255,255,255,0.12); color: white; padding-left: 14px; }

        /* ── Hero ── */
        .hero-settlements {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .hero-settlements h1 { margin: 0 0 6px; font-size: 30px; letter-spacing: -0.4px; }
        .hero-settlements .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-settlements .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-settlements .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero-settlements p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-right { display: flex; flex-direction: column; gap: 10px; align-items: flex-end; }
        .hero-nav { display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
        .hero-nav a {
            padding: 7px 12px; border-radius: 8px; text-decoration: none;
            font-size: 12px; font-weight: 600; color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.06);
            transition: all 0.2s ease;
        }
        .hero-nav a:hover { background: rgba(255,255,255,0.14); color: #ffffff; }
        .hero-nav a.active { background: #ffffff; color: #1e3a8a; border-color: #ffffff; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .btn-hero-primary { background: #ffffff; color: #1e3a8a; border: 1px solid #ffffff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; transform: translateY(-1px); }

        /* ── Hero balance ── */
        .hero-balance {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 2px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 10px;
            padding: 10px 20px;
        }
        .hero-balance-label {
            font-size: 11px;
            font-weight: 600;
            color: #93c5fd;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .hero-balance-value {
            font-size: 24px;
            font-weight: 800;
            color: #ffffff;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.5px;
        }
        .hero-balance-value.hbv-pos { color: #86efac; }
        .hero-balance-value.hbv-neg { color: #fca5a5; }

        /* ── Zakładki Centrum rozliczeń ── */
        .tabs-bar {
            display: flex;
            gap: 2px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px 8px 0 0;
            padding: 6px 6px 0;
            border-bottom: none;
        }
        .tabs-bar a {
            padding: 10px 20px;
            border-radius: 6px 6px 0 0;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            border: 1px solid transparent;
            border-bottom: none;
            transition: all 0.2s;
        }
        .tabs-bar a:hover { background: #f9fafb; color: #374151; }
        .tabs-bar a.active {
            background: #f5f7fa;
            color: #667eea;
            border-color: #e5e7eb;
            border-bottom-color: #f5f7fa;
            position: relative;
            bottom: -1px;
        }

        .tab-panel {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0 8px 8px 8px;
            overflow: hidden;
        }

        /* ── SPX Filter System ── */
        .spx-filter-bar {
            padding: 14px 16px; background: white;
            border-bottom: 1px solid #eef2f7;
            display: flex; gap: 10px; align-items: flex-end; flex-wrap: nowrap;
        }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .spx-filter-group.fg-worker  { flex: 1.5 1 0; }
        .spx-filter-group.fg-month   { flex: 1.2 1 0; }
        .spx-filter-group.fg-year    { flex: 0.7 1 0; }
        .spx-filter-group.fg-date    { flex: 1 1 0; }
        .spx-filter-group.fg-status  { flex: 1 1 0; }
        .spx-filter-group label { font-size: 10px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px; white-space: nowrap; }
        .spx-filter-group select,
        .spx-filter-group input[type="date"],
        .spx-filter-group input[type="text"] {
            padding: 0 10px; height: 38px; border: 1px solid #dbe3ef; border-radius: 8px;
            font-size: 13px; background: #f8fafc; color: var(--text-main);
            font-family: inherit; transition: all 0.15s; width: 100%;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input:focus { outline: none; background: #ffffff; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59,130,246,0.12); }
        .spx-filter-bar .btn-primary {
            background: #2563eb; color: #ffffff; border: 1px solid #2563eb; box-shadow: none;
            padding: 0 16px; height: 38px; border-radius: 8px; font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            white-space: nowrap; flex-shrink: 0;
        }
        .spx-filter-bar .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; transform: none; }
        .spx-filter-bar .btn-secondary {
            background: #f8fafc; color: #334155; border: 1px solid #dbe3ef;
            padding: 0 14px; height: 38px; border-radius: 8px; font-size: 13px; font-weight: 500;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; white-space: nowrap; flex-shrink: 0;
        }
        .spx-filter-bar .btn-secondary:hover { background: #eef2f7; border-color: #cbd5e1; }

        /* ── Przyciski ── */
        .btn {
            padding: 5px 12px; height: 32px; border-radius: 6px; border: 1px solid transparent;
            font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; font-family: inherit;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: transparent; }
        .btn-primary:hover { opacity: 0.9; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: #e5e7eb; }
        .btn-secondary:hover { background: #f9fafb; border-color: #d1d5db; }
        .btn-settle {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 4px 10px; height: 28px; font-size: 12px;
        }
        .btn-settle:hover { opacity: 0.9; color: white; }

        /* ── Tabele ── */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead { background: #f9fafb; }
        th {
            padding: 10px 18px;
            text-align: left;
            font-weight: 600;
            color: #6b7280;
            border: 1px solid #000000;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        td {
            padding: 14px 18px;
            border: 1px solid #000000;
            font-size: 13px;
        }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { filter: brightness(0.97); }

        /* Saldo */
        .bal-pos { color: #059669; font-weight: 600; }
        .bal-neg { color: #dc2626; font-weight: 600; }
        .bal-zero { color: #9ca3af; font-weight: 500; }

        /* Typ operacji badge */
        .op-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .op-payout          { background: #fee2e2; color: #dc2626; }
        .op-advance_private { background: #fff7ed; color: #c2410c; }
        .op-advance_company { background: #eff6ff; color: #1d4ed8; }
        .op-reimbursement   { background: #eff6ff; color: #0369a1; }
        .op-bonus           { background: #dcfce7; color: #15803d; }
        .op-correction      { background: #f5f3ff; color: #6d28d9; }
        .op-transfer        { background: #fdf4ff; color: #7c3aed; }
        .op-transfer_to_priv{ background: #fef9c3; color: #92400e; }
        .op-private         { background: #fff7ed; color: #c2410c; }
        .op-company         { background: #eff6ff; color: #1d4ed8; }

        /* ── Alert ── */
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin: 16px 20px;
            font-size: 13px;
            border-left: 3px solid;
        }
        .alert-success { background: #d1fae5; border-color: #059669; color: #065f46; }
        .alert-error   { background: #fee2e2; border-color: #dc2626; color: #991b1b; }
        .alert ul { margin: 6px 0 0 18px; }

        /* ── Formularz Nowej operacji ── */
        .form-wrap { padding: 28px 32px; max-width: 680px; }
        .form-section-title {
            font-size: 12px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin: 24px 0 14px;
            padding-bottom: 6px;
            border-bottom: 1px solid #f3f4f6;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
        }
        .form-group .required { color: #dc2626; margin-left: 2px; }
        .form-group input,
        .form-group input[type="month"],
        .form-group input[type="date"],
        .form-group select,
        .form-group textarea {
            padding: 9px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            color: #1f2937;
            background: white;
            transition: border-color 0.2s;
            width: 100%;
            box-sizing: border-box;
            height: 40px;
            -webkit-appearance: none;
            appearance: none;
            line-height: normal;
        }
        .form-group textarea {
            height: auto;
            min-height: 90px;
            resize: vertical;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102,126,234,0.1);
        }
        .form-group .help-text { font-size: 12px; color: #9ca3af; }
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #f3f4f6;
        }

        /* Sekcje dynamiczne formularza */
        .op-fields { display: none; }
        .op-fields.visible { display: contents; }

        /* Tabela sald — dodatkowe style */
        .worker-link {
            font-weight: 600;
            color: #1f2937;
            text-decoration: none;
        }
        .worker-link:hover { color: #667eea; text-decoration: underline; }
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #9ca3af;
            font-size: 14px;
        }
        .count-info {
            padding: 10px 20px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
        }

        /* ── Day groups (historia operacji) ── */
        .day-group {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 10px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .day-group.collapsed .day-content { display: none; }
        .day-header {
            background: #f8fafc;
            padding: 10px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
            border-bottom: 1px solid #e5e7eb;
            transition: background 0.15s;
        }
        .day-group.collapsed .day-header { border-bottom: none; border-radius: 8px; }
        .day-header:hover { background: #f1f5f9; }
        .dh-left { display: flex; align-items: center; gap: 10px; }
        .dh-dayname {
            font-size: 11px; font-weight: 700; color: #6b7280;
            background: #e2e8f0; padding: 2px 7px; border-radius: 4px;
            text-transform: uppercase; min-width: 26px; text-align: center;
        }
        .dh-date { font-weight: 700; font-size: 14px; color: #334155; }
        .dh-right { display: flex; align-items: center; gap: 14px; font-size: 12px; color: #6b7280; }
        .dh-count { font-weight: 600; color: #374151; }
        .dh-amount { font-weight: 700; color: #667eea; }
        .dh-arrow { font-size: 10px; color: #9ca3af; transition: transform 0.2s; width: 14px; text-align: center; }
        .day-group.collapsed .dh-arrow { transform: rotate(-90deg); }
        .day-content { background: white; }

        .history-table {
            table-layout: fixed;
        }
        .history-table .col-worker { width: 220px; }
        .history-table .col-type { width: 180px; }
        .history-table .col-amount { width: 130px; text-align: right; }
        .history-table .col-actions { width: 120px; text-align: center; }
        .history-table td.col-amount {
            text-align: right;
            font-weight: 600;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }
        .history-table td.col-description {
            color: #6b7280;
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
            justify-content: center;
            align-items: center;
        }
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            padding: 4px 9px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
            border: 1.5px solid;
            background: white;
            white-space: nowrap;
        }
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
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

        /* ── Szybkie filtry w historii ── */
        .hist-controls-bar {
            padding: 10px 20px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .hist-quick-btns { display: flex; gap: 6px; flex-wrap: wrap; }
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
        .spx-quick-btn:hover { background: #f9fafb; border-color: #667eea; color: #667eea; }
        .spx-quick-btn.active { background: #667eea; border-color: #667eea; color: white; font-weight: 600; }
        .hist-toggle-group {
            display: flex; gap: 2px; background: #e5e7eb; padding: 2px; border-radius: 6px;
        }
        .hist-btn-toggle {
            background: transparent; border: none; color: #6b7280;
            padding: 4px 10px; height: 24px; border-radius: 4px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.15s;
            display: inline-flex; align-items: center;
        }
        .hist-btn-toggle:hover { background: white; color: #374151; }

        /* ── Przycisk Raport w tabeli sald ── */
        .btn-report {
            background: white;
            color: #0369a1;
            border: 1px solid #0369a1;
            padding: 5px 10px;
            height: 28px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.15s;
        }
        .btn-report:hover { background: #0369a1; color: white; }

    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="dashboard-layout">

            <!-- SIDEBAR LEWY — domyślnie zwinięty -->
            <div class="sidebar-wrapper collapsed" id="sidebarWrapper">
                <button class="toggle-sidebar-btn" onclick="toggleSidebar()" title="Zwiń/rozwiń panel">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M8 2L4 6L8 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="sidebar-actions">
                    <div class="sidebar-content-inner">
                        <div class="sidebar-search">
                            <input type="text" id="actionSearch" placeholder="Szukaj akcji..." autocomplete="off">
                        </div>
                        <div class="sidebar-actions-body">
                            <details class="sidebar-section" open>
                                <summary class="sidebar-section-title">Akcje HR</summary>
                                <div class="sidebar-section-links">
                                    <a href="<?php echo url('hr.workers.create'); ?>" data-keywords="nowy pracownik dodaj">+ Dodaj pracownika</a>
                                    <a href="<?php echo url('hr.worklog.create'); ?>" data-keywords="wpis czas pracy dodaj">+ Dodaj wpis czasu</a>
                                    <a href="<?php echo url('finanse.wydatki.create'); ?>" data-keywords="wydatek dodaj">+ Dodaj wydatek</a>
                                    <a href="<?php echo url('hr.workers.documents'); ?>" data-keywords="dokument dodaj">+ Dodaj dokument</a>
                                </div>
                            </details>
                            <details class="sidebar-section">
                                <summary class="sidebar-section-title">Raporty</summary>
                                <div class="sidebar-section-links">
                                    <a href="<?php echo url('hr.workers.report'); ?>" data-keywords="raport okresowy">Raport okresowy</a>
                                    <a href="<?php echo url('hr.workers.rates_table'); ?>" data-keywords="stawki tabela">Stawki — tabela</a>
                                    <a href="<?php echo url('hr.workers.bulk_rates'); ?>" data-keywords="stawki masowe">Stawki ogólne</a>
                                </div>
                            </details>
                            <details class="sidebar-section">
                                <summary class="sidebar-section-title">Nawigacja</summary>
                                <div class="sidebar-section-links">
                                    <a href="<?php echo url('hr'); ?>" data-keywords="pracownicy lista">Lista pracowników</a>
                                    <a href="<?php echo url('hr.worklog.index'); ?>" data-keywords="dziennik pracy worklog">Dziennik pracy</a>
                                </div>
                            </details>
                        </div>
                    </div>
                </div>
            </div>
            <!-- END SIDEBAR -->

            <div class="dashboard-content">

                <!-- HERO -->
                <div class="hero-settlements">
                    <div>
                        <div class="hero-breadcrumb"><a href="<?php echo url('hr'); ?>">Pracownicy</a> › Centrum rozliczeń</div>
                        <h1>Centrum rozliczeń</h1>
                        <p>Salda, historia operacji i nowe rozliczenia pracowników</p>
                    </div>
                    <div class="hero-right">
                        <div class="hero-nav">
                            <a href="<?php echo url('hr'); ?>">Lista pracowników</a>
                            <a href="<?php echo url('hr.worklog.index'); ?>">Dziennik pracy</a>
                            <a href="<?php echo url('hr.settlements'); ?>" class="active">Centrum rozliczeń</a>
                        </div>
                        <div class="hero-balance">
                            <span class="hero-balance-label">Suma sald</span>
                            <span class="hero-balance-value <?php echo $totalBalance > 0 ? 'hbv-pos' : ($totalBalance < 0 ? 'hbv-neg' : ''); ?>">
                                <?php echo ($totalBalance > 0 ? '+' : '') . number_format($totalBalance, 2, ',', ' '); ?> zł
                            </span>
                        </div>
                    </div>
                </div>
                <!-- END HERO -->

            <!-- Prawa część: zakładki -->
            <div id="settlements-content">
                <?php
                $workerSuffix = $preWorker > 0 ? '&worker_id=' . $preWorker : '';
                $tabUrl = fn(string $t) => url('hr.settlements') . '?tab=' . $t . $workerSuffix;
                ?>
                <div class="tabs-bar">
                    <a href="<?php echo $tabUrl('balances'); ?>" class="<?php echo $tab === 'balances' ? 'active' : ''; ?>">Salda</a>
                    <a href="<?php echo $tabUrl('history'); ?>" class="<?php echo $tab === 'history' ? 'active' : ''; ?>">Historia operacji</a>
                    <a href="<?php echo $tabUrl('new'); ?>" class="<?php echo $tab === 'new' ? 'active' : ''; ?>">Nowa operacja</a>
                </div>

                <div class="tab-panel">

                    <?php /* ─────────────────── ZAKŁADKA: SALDA ─────────────────── */ ?>
                    <?php if ($tab === 'balances'): ?>
                        <div class="count-info">
                            Aktywni pracownicy: <?php echo count($balances); ?>
                        </div>
                        <?php if (empty($balances)): ?>
                            <div class="no-data">Brak aktywnych pracowników.</div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Pracownik</th>
                                        <th>Saldo</th>
                                        <th>Ostatnia operacja</th>
                                        <th style="text-align:right;">Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($balances as $b):
                                        $c   = workerColor($b['id']);
                                        $bal = (float)$b['balance'];
                                        $cls = $bal > 0 ? 'bal-pos' : ($bal < 0 ? 'bal-neg' : 'bal-zero');
                                        $sgn = $bal > 0 ? '+' : '';
                                    ?>
                                        <tr style="background:<?php echo $c['bg']; ?>; border-left:4px solid <?php echo $c['border']; ?>;">
                                            <td>
                                                <a href="<?php echo url('hr.workers.report'); ?>?worker_id=<?php echo $b['id']; ?>" class="worker-link">
                                                    <?php echo e($b['last_name'] . ' ' . $b['first_name']); ?>
                                                </a>
                                            </td>
                                            <td class="<?php echo $cls; ?>">
                                                <?php echo $sgn . number_format($bal, 2, ',', ' '); ?> zł
                                            </td>
                                            <td style="color:#6b7280; font-size:12px;">
                                                <?php echo $b['last_settlement_date']
                                                    ? date('d.m.Y', strtotime($b['last_settlement_date']))
                                                    : '—'; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <div style="display:flex; gap:6px; justify-content:flex-end; align-items:center;">
                                                    <a href="<?php echo url('hr.workers.report'); ?>?worker_id=<?php echo $b['id']; ?>"
                                                       class="btn-report">Raport</a>
                                                    <a href="<?php echo url('hr.settlements'); ?>?tab=new&worker_id=<?php echo $b['id']; ?>"
                                                       class="btn btn-settle">Rozlicz</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                    <?php /* ─────────────────── ZAKŁADKA: HISTORIA ─────────────────── */ ?>
                    <?php elseif ($tab === 'history'): ?>
                        <?php
                        // Buduj base query string (bez dat) dla szybkich przycisków
                        $histBaseQs = http_build_query(array_filter([
                            'tab'       => 'history',
                            'worker_id' => $fWorker ?: null,
                            'type'      => $fType   ?: null,
                        ]));
                        $isHistAllActive  = (empty($fFrom) && empty($fTo));
                        $isHistYearActive = ($fFrom === $yearStart && $fTo === $todayDate);
                        $polishDays = ['Nd','Pn','Wt','Sr','Cz','Pt','So'];
                        ?>

                        <!-- SPX Filtry Historia -->
                        <form method="GET" action="" id="histFilterForm">
                            <input type="hidden" name="tab" value="history">
                            <div class="spx-filter-bar">
                                <div class="spx-filter-group fg-worker">
                                    <label>Pracownik</label>
                                    <select name="worker_id">
                                        <option value="">Wszyscy</option>
                                        <?php foreach ($histWorkers as $hw): ?>
                                            <option value="<?php echo $hw['id']; ?>" <?php echo $fWorker === (int)$hw['id'] ? 'selected' : ''; ?>>
                                                <?php echo e($hw['last_name'] . ' ' . $hw['first_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="spx-filter-group fg-status">
                                    <label>Typ operacji</label>
                                    <select name="type">
                                        <option value="">Wszystkie</option>
                                        <option value="payout"          <?php echo $fType === 'payout'          ? 'selected' : ''; ?>>Wypłata</option>
                                        <option value="advance_private" <?php echo $fType === 'advance_private' ? 'selected' : ''; ?>>Zaliczka prywatna</option>
                                        <option value="advance_company" <?php echo $fType === 'advance_company' ? 'selected' : ''; ?>>Zasilenie portfela</option>
                                        <option value="reimbursement"   <?php echo $fType === 'reimbursement'   ? 'selected' : ''; ?>>Zwrot kosztów</option>
                                        <option value="bonus"           <?php echo $fType === 'bonus'           ? 'selected' : ''; ?>>Premia</option>
                                        <option value="correction"      <?php echo $fType === 'correction'      ? 'selected' : ''; ?>>Korekta</option>
                                        <optgroup label="Transfery">
                                            <option value="transfer"         <?php echo $fType === 'transfer'         ? 'selected' : ''; ?>>Firmowy → Firmowy</option>
                                            <option value="transfer_to_priv" <?php echo $fType === 'transfer_to_priv' ? 'selected' : ''; ?>>Firmowy → Prywatna</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="spx-filter-group fg-date">
                                    <label>Od</label>
                                    <input type="date" name="date_from" id="histDateFrom" value="<?php echo e($fFrom); ?>">
                                </div>
                                <div class="spx-filter-group fg-date">
                                    <label>Do</label>
                                    <input type="date" name="date_to" id="histDateTo" value="<?php echo e($fTo); ?>">
                                </div>
                                <button type="submit" class="btn-primary">Filtruj</button>
                                <?php if ($fWorker || $fType || $fFrom || $fTo): ?>
                                    <a href="?tab=history" class="btn-secondary">Resetuj</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <!-- Szybkie filtry + zwijanie -->
                        <div class="hist-controls-bar">
                            <div class="hist-quick-btns">
                                <a href="?<?php echo $histBaseQs; ?>&date_from=<?php echo $todayDate; ?>&date_to=<?php echo $todayDate; ?>"
                                   class="spx-quick-btn <?php echo ($fFrom === $todayDate && $fTo === $todayDate) ? 'active' : ''; ?>">Dziś</a>
                                <a href="?<?php echo $histBaseQs; ?>&date_from=<?php echo $weekAgo; ?>&date_to=<?php echo $todayDate; ?>"
                                   class="spx-quick-btn <?php echo ($fFrom === $weekAgo && $fTo === $todayDate) ? 'active' : ''; ?>">7 dni</a>
                                <a href="?<?php echo $histBaseQs; ?>&date_from=<?php echo $monthStart; ?>&date_to=<?php echo $monthEnd; ?>"
                                   class="spx-quick-btn <?php echo ($fFrom === $monthStart && $fTo === $monthEnd) ? 'active' : ''; ?>">Ten miesiąc</a>
                                <a href="?<?php echo $histBaseQs; ?>&date_from=<?php echo $yearStart; ?>&date_to=<?php echo $todayDate; ?>"
                                   class="spx-quick-btn <?php echo $isHistYearActive ? 'active' : ''; ?>">Ten rok</a>
                                <a href="?tab=history<?php echo $fWorker ? '&worker_id='.$fWorker : ''; ?><?php echo $fType ? '&type='.$fType : ''; ?>"
                                   class="spx-quick-btn <?php echo $isHistAllActive ? 'active' : ''; ?>">Wszystko</a>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span style="font-size:12px; color:#6b7280;">Wyników: <?php echo $historyTotal; ?></span>
                                <?php if (!empty($historyByDate)): ?>
                                <div class="hist-toggle-group">
                                    <button type="button" class="hist-btn-toggle" onclick="histExpandAll()">Rozwiń</button>
                                    <button type="button" class="hist-btn-toggle" onclick="histCollapseAll()">Zwiń</button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($historyFlash === 'updated'): ?>
                            <div class="alert alert-success">Operacja została zaktualizowana.</div>
                        <?php elseif ($historyFlash === 'deleted'): ?>
                            <div class="alert alert-success">Operacja została usunięta wraz z powiązaniami.</div>
                        <?php elseif ($historyFlash === 'error'): ?>
                            <div class="alert alert-error">Nie udało się zapisać zmian operacji.</div>
                        <?php endif; ?>

                        <?php if (empty($historyByDate)): ?>
                            <div class="no-data">Brak operacji dla podanych kryteriów.</div>
                        <?php else: ?>
                            <div style="padding: 16px 20px;">
                            <?php foreach ($historyByDate as $hDate => $dayData): ?>
                                <?php
                                $dayNum  = date('w', strtotime($hDate));
                                $dayName = $polishDays[$dayNum];
                                ?>
                                <div class="day-group" data-hdate="<?php echo $hDate; ?>">
                                    <div class="day-header" onclick="histToggleDay(this)">
                                        <div class="dh-left">
                                            <span class="dh-dayname"><?php echo $dayName; ?></span>
                                            <span class="dh-date"><?php echo date('d.m.Y', strtotime($hDate)); ?></span>
                                        </div>
                                        <div class="dh-right">
                                            <span class="dh-count"><?php echo $dayData['count']; ?> operacji</span>
                                            <span class="dh-amount"><?php echo number_format($dayData['total_amount'], 2, ',', ' '); ?> zł</span>
                                            <span class="dh-arrow">&#9660;</span>
                                        </div>
                                    </div>
                                    <div class="day-content">
                                        <table class="history-table">
                                            <colgroup>
                                                <col class="col-worker">
                                                <col class="col-type">
                                                <col class="col-amount">
                                                <col class="col-description">
                                                <col class="col-actions">
                                            </colgroup>
                                            <thead>
                                                <tr>
                                                    <th class="col-worker" style="padding-left:18px;">Pracownik</th>
                                                    <th class="col-type">Typ</th>
                                                    <th class="col-amount">Kwota</th>
                                                    <th class="col-description">Opis</th>
                                                    <th class="col-actions">Akcje</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($dayData['rows'] as $h):
                                                    $c = workerColor($h['worker_id']);
                                                    if ($h['source'] === 'advance') {
                                                        $uiType = $h['raw_type'] === 'PRIVATE' ? 'advance_private' : 'advance_company';
                                                    } else {
                                                        $ak = strtolower((string)($h['advance_kind'] ?? ''));
                                                        if ($h['raw_type'] === 'advance') {
                                                            $uiType = ($ak === 'company') ? 'advance_company' : 'advance_private';
                                                        } else {
                                                            $uiType = $h['raw_type'];
                                                        }
                                                    }
                                                    $typeLabel = operationTypeLabel($uiType);
                                                    $sourceType = $h['source'] === 'settlement' ? 'settlement' : 'advance';
                                                    $editUrl = url('hr.settlements.edit', [
                                                        'source'    => $sourceType,
                                                        'id'        => (int)$h['id'],
                                                        'return_to' => $historyReturnUrl,
                                                    ]);
                                                    $deleteUrl = url('hr.settlements.delete', [
                                                        'source'    => $sourceType,
                                                        'id'        => (int)$h['id'],
                                                        'return_to' => $historyReturnUrl,
                                                    ]);
                                                ?>
                                                    <tr style="background:<?php echo $c['bg']; ?>; border-left:4px solid <?php echo $c['border']; ?>;">
                                                        <td class="col-worker" style="padding-left:18px;">
                                                            <a href="<?php echo url('hr.workers.report'); ?>?worker_id=<?php echo $h['worker_id']; ?>" class="worker-link">
                                                                <?php echo e($h['last_name'] . ' ' . $h['first_name']); ?>
                                                            </a>
                                                        </td>
                                                        <td class="col-type">
                                                            <span class="op-badge op-<?php echo $uiType; ?>">
                                                                <?php echo $typeLabel; ?>
                                                            </span>
                                                        </td>
                                                        <td class="col-amount">
                                                            <?php echo number_format((float)$h['amount'], 2, ',', ' '); ?> zł
                                                        </td>
                                                        <td class="col-description">
                                                           <?php echo e($h['description'] ?? ''); ?>
                                                            <?php if (($h['source'] ?? '') === 'advance' && ($h['raw_type'] ?? '') === 'PRIVATE' && !empty($h['salary_period'])): ?>
                                                                <div style="font-size:10px;color:#9ca3af;">Za miesiąc: <?php echo e(payrollMonthLabel((string)$h['salary_period'])); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="col-actions">
                                                            <div class="action-buttons">
                                                                <a href="<?php echo $editUrl; ?>" class="action-btn action-btn-edit">Edytuj</a>
                                                                <a href="<?php echo $deleteUrl; ?>" class="action-btn action-btn-delete">Usuń</a>
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

                    <?php /* ─────────────────── ZAKŁADKA: NOWA OPERACJA ─────────────────── */ ?>
                    <?php elseif ($tab === 'new'): ?>

                        <?php if ($savedMsg): ?>
                            <div class="alert alert-success">Operacja została zapisana.</div>
                        <?php endif; ?>

                        <?php if (!empty($formErrors)): ?>
                            <div class="alert alert-error">
                                <strong>Błąd:</strong>
                                <ul>
                                    <?php foreach ($formErrors as $err): ?>
                                        <li><?php echo e($err); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="form-wrap">
                            <form method="POST" action="" id="newOpForm">
                                <input type="hidden" name="_action" value="new_operation">

                                <div class="form-section-title">Typ operacji</div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Typ operacji<span class="required">*</span></label>
                                        <select name="op_type" id="op_type" required onchange="updateOpFields()">
                                            <option value="">Wybierz typ</option>
                                            <?php
                                            $preOpType = $_POST['op_type'] ?? $_GET['op_type'] ?? '';
                                            $validOpTypes = ['payout','advance_private','advance_company','reimbursement','bonus','correction','transfer','transfer_to_priv'];
                                            if (!in_array($preOpType, $validOpTypes)) $preOpType = '';
                                            ?>
                                            <option value="payout"          <?php echo $preOpType === 'payout'          ? 'selected' : ''; ?>>Wypłata</option>
                                            <option value="advance_private" <?php echo $preOpType === 'advance_private' ? 'selected' : ''; ?>>Zaliczka prywatna</option>
                                            <option value="advance_company" <?php echo $preOpType === 'advance_company' ? 'selected' : ''; ?>>Zasilenie portfela firmowego</option>
                                            <option value="reimbursement"   <?php echo $preOpType === 'reimbursement'   ? 'selected' : ''; ?>>Zwrot kosztów</option>
                                            <option value="bonus"           <?php echo $preOpType === 'bonus'           ? 'selected' : ''; ?>>Premia</option>
                                            <option value="correction"      <?php echo $preOpType === 'correction'      ? 'selected' : ''; ?>>Korekta</option>
                                            <optgroup label="Transfery między pracownikami">
                                                <option value="transfer"        <?php echo $preOpType === 'transfer'        ? 'selected' : ''; ?>>Firmowy → Firmowy (portfel → portfel)</option>
                                                <option value="transfer_to_priv"<?php echo $preOpType === 'transfer_to_priv'? 'selected' : ''; ?>>Firmowy → Prywatna (gotówka z portfela)</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                </div>

                                <!-- Pola: pojedynczy pracownik (payout, advance_private, advance_company, reimbursement, bonus, correction) -->
                                <div id="fields-single" class="op-fields">
                                    <div class="form-section-title">Dane operacji</div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Pracownik<span class="required">*</span></label>
                                            <select name="worker_id" id="worker_id">
                                                <option value="">Wybierz pracownika</option>
                                                <?php foreach ($formWorkers as $fw): ?>
                                                    <option value="<?php echo $fw['id']; ?>"
                                                        <?php
                                                        $selWorker = (int)($_POST['worker_id'] ?? $preWorker);
                                                        echo $selWorker === (int)$fw['id'] ? 'selected' : '';
                                                        ?>>
                                                        <?php echo e($fw['last_name'] . ' ' . $fw['first_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Tylko dla Zasilenie portfela -->
                                    <div id="source-kind-group" style="display:none;">
                                        <div class="form-section-title">Źródło finansowania</div>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label>Skad pochodzi kwota<span class="required">*</span></label>
                                                <select name="source_kind" id="source_kind">
                                                    <option value="">Wybierz</option>
                                                    <option value="cash"  <?php echo ($_POST['source_kind'] ?? '') === 'cash'  ? 'selected' : ''; ?>>Gotówka</option>
                                                    <option value="bank"  <?php echo ($_POST['source_kind'] ?? '') === 'bank'  ? 'selected' : ''; ?>>Przelew bankowy</option>
                                                    <option value="other" <?php echo ($_POST['source_kind'] ?? '') === 'other' ? 'selected' : ''; ?>>Inne</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>Numer referencyjny (opcjonalnie)</label>
                                                <input type="text" name="source_ref"
                                                       value="<?php echo e($_POST['source_ref'] ?? ''); ?>"
                                                       placeholder="np. numer przelewu">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Pola: Transfer A→B (amount/op_date współdzielone z fields-single, tylko unikalne pola tutaj) -->
                                <div id="fields-transfer" class="op-fields">
                                    <div class="form-section-title">Transfer portfela firmowego</div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Pracownik źródłowy (portfel firmowy)<span class="required">*</span></label>
                                            <select name="from_worker_id" id="from_worker_id">
                                                <option value="">Wybierz portfel źródłowy</option>
                                                <?php foreach ($walletSourceWorkers as $walletWorker): ?>
                                                    <option value="<?php echo (int)$walletWorker['worker_id']; ?>"
                                                        <?php echo ((int)($_POST['from_worker_id'] ?? $preSourceWorker)) === (int)$walletWorker['worker_id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($walletWorker['worker_name']); ?> —
                                                        saldo: <?php echo number_format((float)$walletWorker['wallet_balance'], 2, ',', ' '); ?> zł —
                                                        otwarte pozycje: <?php echo (int)$walletWorker['open_count']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (empty($walletSourceWorkers)): ?>
                                                <span class="help-text" style="color:#dc2626;">Brak otwartych portfeli firmowych.</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-group">
                                            <label>Do pracownika<span class="required">*</span></label>
                                            <select name="to_worker_id">
                                                <option value="">Wybierz pracownika</option>
                                                <?php foreach ($formWorkers as $fw): ?>
                                                    <option value="<?php echo $fw['id']; ?>"
                                                        <?php echo ((int)($_POST['to_worker_id'] ?? 0)) === (int)$fw['id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($fw['last_name'] . ' ' . $fw['first_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="help-text" style="margin-bottom:12px; color:#6b7280;">
                                        Kwotę i datę transferu ustaw w polach poniżej (wspólne dla wszystkich typów operacji).
                                    </div>
                                </div>

                                <!-- Pola: Transfer firmowy→prywatna -->
                                <div id="fields-transfer-to-priv" class="op-fields">
                                    <div class="form-section-title">Przekazanie gotówki z portfela firmowego</div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Pracownik źródłowy — portfel firmowy (od kogo)<span class="required">*</span></label>
                                            <select name="ttp_from_worker_id" id="ttp_from_worker_id">
                                                <option value="">Wybierz portfel źródłowy</option>
                                                <?php foreach ($walletSourceWorkers as $walletWorker): ?>
                                                    <option value="<?php echo (int)$walletWorker['worker_id']; ?>"
                                                        <?php echo ((int)($_POST['ttp_from_worker_id'] ?? $_POST['from_worker_id'] ?? $preSourceWorker)) === (int)$walletWorker['worker_id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($walletWorker['worker_name']); ?> —
                                                        saldo: <?php echo number_format((float)$walletWorker['wallet_balance'], 2, ',', ' '); ?> zł —
                                                        otwarte pozycje: <?php echo (int)$walletWorker['open_count']; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if (empty($walletSourceWorkers)): ?>
                                                <span class="help-text" style="color:#dc2626;">Brak otwartych portfeli firmowych.</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="form-group">
                                            <label>Pracownik otrzymujący (zaliczka prywatna)<span class="required">*</span></label>
                                            <select name="ttp_to_worker_id" id="ttp_to_worker_id">
                                                <option value="">Wybierz pracownika</option>
                                                <?php foreach ($formWorkers as $fw): ?>
                                                    <option value="<?php echo $fw['id']; ?>"
                                                        <?php echo ((int)($_POST['ttp_to_worker_id'] ?? 0)) === (int)$fw['id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($fw['last_name'] . ' ' . $fw['first_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="help-text" style="margin-bottom:12px; color:#6b7280;">
                                        Gotówka schodzi z portfela firmowego pracownika A i zapisuje się jako zaliczka prywatna pracownika B (obciąży jego wypłatę).
                                    </div>
                                </div>

                                <!-- Wspólne pola: kwota, data, opis — zawsze widoczne po wyborze typu -->
                                <div id="fields-common" style="display:none;">
                                    <div class="form-section-title" id="common-section-title">Kwota i data</div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label id="amount-label">Kwota (PLN)<span class="required">*</span></label>
                                            <input type="number" name="amount" id="amount" step="0.01" min="0.01"
                                                   value="<?php echo e($_POST['amount'] ?? ''); ?>"
                                                   placeholder="0.00">
                                        </div>
                                        <div class="form-group">
                                            <label>Data operacji<span class="required">*</span></label>
                                            <input type="date" name="op_date"
                                                   value="<?php echo e($_POST['op_date'] ?? date('Y-m-d')); ?>">
                                        </div>
                                    </div>
                                    <div id="period-group-common">
                                        <div class="form-group" style="margin-bottom:16px;">
                                            <label>Okres rozliczeniowy<span class="required">*</span></label>
                                            <input type="month" name="period"
                                                   value="<?php echo e($_POST['period'] ?? date('Y-m')); ?>">
                                            <span class="help-text">Miesiąc, którego dotyczy operacja</span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Opis (opcjonalnie)</label>
                                        <textarea name="description" placeholder="Dodatkowe informacje..."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn btn-primary" id="submitBtn">Zapisz operację</button>
                                    <a href="<?php echo url('hr.settlements'); ?>?tab=balances" class="btn btn-secondary">Anuluj</a>
                                </div>
                            </form>
                        </div>

                    <?php endif; ?>
                </div><!-- /tab-panel -->
            </div><!-- /settlements-content -->

            </div><!-- /dashboard-content -->
        </div><!-- /dashboard-layout -->
    </div><!-- /container -->

    <script>
        function updateOpFields() {
            const opType = document.getElementById('op_type').value;
            const single         = document.getElementById('fields-single');
            const transfer       = document.getElementById('fields-transfer');
            const transferToPriv = document.getElementById('fields-transfer-to-priv');
            const common         = document.getElementById('fields-common');
            const srcKindGroup   = document.getElementById('source-kind-group');
            const periodGroup    = document.getElementById('period-group-common');
            const submitBtn      = document.getElementById('submitBtn');
            const sectionTitle   = document.getElementById('common-section-title');
            const amountLabel    = document.getElementById('amount-label');

            // Ukryj wszystko
            single.classList.remove('visible');
            transfer.classList.remove('visible');
            transferToPriv.classList.remove('visible');
            common.style.display = 'none';
            srcKindGroup.style.display = 'none';

            if (!opType) {
                submitBtn.disabled = true;
                return;
            }

            submitBtn.disabled = false;
            common.style.display = '';

            const labels = {
                'payout':          'Kwota wypłaty (PLN)',
                'advance_private': 'Kwota zaliczki (PLN)',
                'advance_company': 'Kwota zasilenia (PLN)',
                'reimbursement':   'Kwota zwrotu (PLN)',
                'bonus':           'Kwota premii (PLN)',
                'correction':      'Kwota korekty (PLN)',
                'transfer':        'Kwota transferu (PLN)',
                'transfer_to_priv':'Kwota przekazanej gotówki (PLN)',
            };
            if (amountLabel) amountLabel.innerHTML = (labels[opType] || 'Kwota (PLN)') + '<span class="required">*</span>';

            // Ukryj period dla operacji bez okresu rozliczeniowego
            const noPeriod = ['advance_company', 'transfer'];
            periodGroup.style.display = noPeriod.includes(opType) ? 'none' : '';

            if (opType === 'transfer') {
                transfer.classList.add('visible');
                if (sectionTitle) sectionTitle.textContent = 'Kwota i data transferu';
            } else if (opType === 'transfer_to_priv') {
                transferToPriv.classList.add('visible');
                if (sectionTitle) sectionTitle.textContent = 'Kwota i data';
            } else {
                single.classList.add('visible');
                if (sectionTitle) sectionTitle.textContent = 'Kwota i data';
                if (opType === 'advance_company') {
                    srcKindGroup.style.display = '';
                }
            }
        }

        // Uruchom przy ładowaniu (obsługa walidacji POST i preselect URL)
        document.addEventListener('DOMContentLoaded', function() {
            updateOpFields();

            <?php if ($preWorker > 0): ?>
            const workerSel = document.getElementById('worker_id');
            if (workerSel && !workerSel.value) {
                workerSel.value = '<?php echo $preWorker; ?>';
            }
            <?php endif; ?>

            // Domyślnie zwiń wszystkie grupy dni w historii
            histCollapseAll();
        });

        // ── Historia: zwijanie/rozwijanie dni ──
        function histToggleDay(header) {
            header.parentElement.classList.toggle('collapsed');
        }
        function histExpandAll() {
            document.querySelectorAll('.day-group').forEach(g => g.classList.remove('collapsed'));
        }
        function histCollapseAll() {
            document.querySelectorAll('.day-group').forEach(g => g.classList.add('collapsed'));
        }

        // Sidebar toggle
        function toggleSidebar() {
            const wrapper = document.getElementById('sidebarWrapper');
            if (!wrapper) return;
            wrapper.classList.toggle('collapsed');
            const isCollapsed = wrapper.classList.contains('collapsed');
            localStorage.setItem('brygad_settlements_sidebar_collapsed', isCollapsed ? 'true' : 'false');
        }

        // Sidebar restore + search
        (function() {
            const wrapper = document.getElementById('sidebarWrapper');
            if (wrapper) {
                const stored = localStorage.getItem('brygad_settlements_sidebar_collapsed');
                const isCollapsed = stored === null ? true : stored === 'true';
                wrapper.classList.toggle('collapsed', isCollapsed);
            }
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
                        if (section) { if (!section.open) { section.open = true; section._autoOpened = true; } visibleSections.add(section); }
                    } else { link.style.display = 'none'; }
                });
                sections.forEach(s => { s.style.display = visibleSections.has(s) ? '' : 'none'; });
            });
        })();
    </script>
</body>
</html>
