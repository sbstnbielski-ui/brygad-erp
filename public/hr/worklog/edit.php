<?php
/**
 * BRYGAD ERP v3.0 - Edycja Wpisu Pracy
 * Pełne zabezpieczenia + walidacja
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/absence_helper.php';
require_once dirname(__DIR__, 2) . '/includes/rate_helper.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$errors = [];
$success = false;

$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

$logId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$resolveWorklogReturnUrl = static function ($candidate): string {
    $fallback = url('hr.worklog');
    if (!is_string($candidate) || $candidate === '') {
        return $fallback;
    }
    $parts = parse_url($candidate);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }
    $path = $parts['path'] ?? '';
    if ($path !== '/hr/worklog/index.php' && $path !== '/hr/worklog') {
        return $fallback;
    }
    return $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
};

$worklogReturnUrlWithStatus = static function (string $returnUrl, string $status): string {
    $parts = parse_url($returnUrl);
    if ($parts === false) {
        return url('hr.worklog', ['status' => $status]);
    }
    $path = $parts['path'] ?? url('hr.worklog');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $query['status'] = $status;
    return $path . '?' . http_build_query($query);
};

$returnUrl = $resolveWorklogReturnUrl($_GET['return_url'] ?? $_POST['return_url'] ?? '');

if ($logId <= 0) {
    $_SESSION['error'] = 'Nieprawidłowy ID wpisu.';
    header('Location: ' . $returnUrl);
    exit;
}

// Pobierz wpis
try {
    $stmt = $pdo->prepare("
        SELECT 
            wl.*,
            w.first_name,
            w.last_name
        FROM work_logs wl
        INNER JOIN workers w ON wl.worker_id = w.id
        WHERE wl.id = ?
    ");
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    
    if (!$log) {
        $_SESSION['error'] = 'Wpis nie istnieje.';
        header('Location: ' . $returnUrl);
        exit;
    }
    
    // ZABEZPIECZENIE 1: Worker może edytować tylko swoje wpisy i tylko pending
    if (!$isAdminUser) {
        if ($log['worker_id'] != $currentWorkerId) {
            $_SESSION['error'] = 'Nie możesz edytować wpisów innych pracowników.';
            header('Location: ' . $returnUrl);
            exit;
        }
        
        if ($log['status'] !== 'pending') {
            $_SESSION['error'] = 'Możesz edytować tylko wpisy oczekujące na zatwierdzenie.';
            header('Location: ' . $returnUrl);
            exit;
        }
    }
    
    // ZABEZPIECZENIE 2: Admin nie może edytować zablokowanych wpisów
    if ($log['status'] === 'locked') {
        $_SESSION['error'] = 'Nie można edytować zablokowanego wpisu. Odblokuj go najpierw.';
        header('Location: ' . $returnUrl);
        exit;
    }
    
    // ZABEZPIECZENIE 3: Admin edytujący zatwierdzony wpis - ostrzeżenie
    $editingApproved = ($log['status'] === 'approved' && $isAdminUser);
    
} catch (PDOException $e) {
    logEvent("Błąd pobierania wpisu ID $logId do edycji: " . $e->getMessage(), 'ERROR');
    $_SESSION['error'] = 'Błąd pobierania wpisu.';
    header('Location: ' . $returnUrl);
    exit;
}

// Pobierz projekty dla filtra
try {
    $stmt = $pdo->query("SELECT id, name, is_internal FROM projects WHERE status IN ('active', 'planned') ORDER BY is_internal DESC, name");
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    $projects = [];
}

// Obsługa POST - aktualizacja
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    $costNodeId = !empty($_POST['cost_node_id']) ? (int)$_POST['cost_node_id'] : null;
    $date = trim($_POST['date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $absenceDaysRaw = trim((string)($_POST['absence_days'] ?? ''));
    $absenceDays = 0.0;
    
    // CHECKBOXY: Urlop i L4
    $isVacation = isset($_POST['is_vacation']) && $_POST['is_vacation'] == '1';
    $isSickLeave = isset($_POST['is_sickleave']) && $_POST['is_sickleave'] == '1';
    
    // Godziny - tylko jeśli NIE ma urlopu/L4
    if ($isVacation || $isSickLeave) {
        $workdayHours = 0;
        $workdayOt = 0;
        $saturdayHours = 0;
        $saturdayOt = 0;
        $sundayHours = 0;
        $sundayOt = 0;
        $nightHours = 0;
        $nightOt = 0;
        $delegationHours = 0;
        $delegationOt = 0;
        if ($absenceDaysRaw === '' || !is_numeric($absenceDaysRaw)) {
            $errors[] = 'Podaj poprawna liczbe dni absencji.';
        } else {
            $absenceDays = round((float)$absenceDaysRaw, 2);
            if ($absenceDays <= 0) {
                $errors[] = 'Liczba dni absencji musi byc wieksza od 0.';
            }
            if ($absenceDays > 31) {
                $errors[] = 'Liczba dni absencji jest zbyt duza.';
            }
        }
        $vacationHours = $isVacation ? round($absenceDays * 8, 2) : 0;
        $sickleaveHours = $isSickLeave ? round($absenceDays * 8, 2) : 0;
    } else {
        $workdayHours = (float)($_POST['workday_hours'] ?? 0);
        $workdayOt = (float)($_POST['workday_overtime'] ?? 0);
        $saturdayHours = (float)($_POST['saturday_hours'] ?? 0);
        $saturdayOt = (float)($_POST['saturday_overtime'] ?? 0);
        $sundayHours = (float)($_POST['sunday_hours'] ?? 0);
        $sundayOt = (float)($_POST['sunday_overtime'] ?? 0);
        $nightHours = (float)($_POST['night_hours'] ?? 0);
        $nightOt = (float)($_POST['night_overtime'] ?? 0);
        $delegationHours = (float)($_POST['delegation_hours'] ?? 0);
        $delegationOt = (float)($_POST['delegation_overtime'] ?? 0);
        $vacationHours = 0;
        $sickleaveHours = 0;
    }
    
    // Walidacja
    if (empty($date)) {
        $errors[] = 'Data pracy jest wymagana.';
    }
    
    // Sprawdź, czy JAKIEKOLWIEK godziny lub urlop/L4
    $totalWorkHours = $workdayHours + $workdayOt + $saturdayHours + $saturdayOt + 
                      $sundayHours + $sundayOt + $nightHours + $nightOt + 
                      $delegationHours + $delegationOt;
    
    if ($totalWorkHours <= 0 && !$isVacation && !$isSickLeave) {
        $errors[] = 'Musisz wpisać co najmniej jedną godzinę lub zaznaczyć urlop/L4.';
    }
    
    // Walidacja: wartości >= 0
    $allValues = [
        $workdayHours, $workdayOt, $saturdayHours, $saturdayOt, 
        $sundayHours, $sundayOt, $nightHours, $nightOt,
        $delegationHours, $delegationOt
    ];
    
    foreach ($allValues as $val) {
        if ($val < 0) {
            $errors[] = 'Godziny nie mogą być ujemne.';
            break;
        }
    }
    
    // Rozsądne maksimum
    $totalHours = $totalWorkHours + $vacationHours + $sickleaveHours;
    if (!$isVacation && !$isSickLeave && $totalHours > 24) {
        $errors[] = 'Łączna liczba godzin w dniu nie może przekroczyć 24h.';
    }
    
    // Aktualizuj wpis
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Oblicz period (1. dzień miesiąca)
            $period = date('Y-m-01', strtotime($date));
            
            // Określ work_type
            $workType = 'work';
            if ($isVacation) {
                $workType = 'vacation';
            } elseif ($isSickLeave) {
                $workType = 'sick';
            }
            
            // Dla backward compatibility: oblicz stare pola
            $hours = $workType === 'work'
                ? $workdayHours + $saturdayHours + $sundayHours + $nightHours + $delegationHours
                : 0;
            $overtimeHours = $workdayOt + $saturdayOt + $sundayOt + $nightOt + $delegationOt;
            
            // Flagi
            $isSaturday = ($saturdayHours > 0 || $saturdayOt > 0) ? 1 : 0;
            $isSunday = ($sundayHours > 0 || $sundayOt > 0) ? 1 : 0;
            $isNight = ($nightHours > 0 || $nightOt > 0) ? 1 : 0;
            $isDelegation = ($delegationHours > 0 || $delegationOt > 0) ? 1 : 0;
            $isWeekend = ($isSaturday || $isSunday) ? 1 : 0;
            
            // Absence days
            $normalizedAbsenceDays = null;
            $isPaid = 1;
            if ($isVacation) {
                $normalizedAbsenceDays = $absenceDays;
            } elseif ($isSickLeave) {
                $normalizedAbsenceDays = $absenceDays;
            }
            
            // Admin edytujacy pojedynczy wpis zatwierdza go od razu po zapisie.
            // Jesli nie da sie policzyc kosztu ze stawek, wpis wraca do pending do recznej akceptacji.
            $approveOnSave = $isAdminUser;
            $approveInline = false;
            $approvalCostData = null;

            if ($approveOnSave) {
                $updatedLogForCost = $log;
                $updatedLogForCost['worker_id'] = $log['worker_id'];
                $updatedLogForCost['project_id'] = $projectId;
                $updatedLogForCost['cost_node_id'] = $costNodeId;
                $updatedLogForCost['work_type'] = $workType;
                $updatedLogForCost['date'] = $date;
                $updatedLogForCost['hours'] = $hours;
                $updatedLogForCost['overtime_hours'] = $overtimeHours;
                $updatedLogForCost['absence_days'] = $normalizedAbsenceDays;
                $updatedLogForCost['is_paid'] = $isPaid;
                $updatedLogForCost['is_weekend'] = $isWeekend;
                $updatedLogForCost['is_saturday'] = $isSaturday;
                $updatedLogForCost['is_sunday'] = $isSunday;
                $updatedLogForCost['is_delegation'] = $isDelegation;
                $updatedLogForCost['is_night'] = $isNight;
                $updatedLogForCost['workday_hours'] = $workdayHours;
                $updatedLogForCost['workday_overtime'] = $workdayOt;
                $updatedLogForCost['saturday_hours'] = $saturdayHours;
                $updatedLogForCost['saturday_overtime'] = $saturdayOt;
                $updatedLogForCost['sunday_hours'] = $sundayHours;
                $updatedLogForCost['sunday_overtime'] = $sundayOt;
                $updatedLogForCost['night_hours'] = $nightHours;
                $updatedLogForCost['night_overtime'] = $nightOt;
                $updatedLogForCost['delegation_hours'] = $delegationHours;
                $updatedLogForCost['delegation_overtime'] = $delegationOt;
                $updatedLogForCost['vacation_hours'] = $vacationHours;
                $updatedLogForCost['sickleave_hours'] = $sickleaveHours;

                $rate = getWorkerRate($pdo, (int)$log['worker_id'], $projectId, $date, $costNodeId);
                if ($rate && normalizeRateValue($rate['base_rate'] ?? null) !== null) {
                    $approvalCostData = calculateSuggestedCost($updatedLogForCost, $rate);
                    $approveInline = true;
                }
            }
            
            if ($approveOnSave) {
                // Edycja pojedynczego wpisu przez admina: zatwierdz od razu, jesli mozna policzyc koszt.
                $stmt = $pdo->prepare("
                    UPDATE work_logs 
                    SET project_id = ?,
                        cost_node_id = ?,
                        work_type = ?,
                        date = ?,
                        period = ?,
                        hours = ?,
                        overtime_hours = ?,
                        absence_days = ?,
                        is_paid = ?,
                        description = ?,
                        is_weekend = ?,
                        is_saturday = ?,
                        is_sunday = ?,
                        is_delegation = ?,
                        is_night = ?,
                        workday_hours = ?,
                        workday_overtime = ?,
                        saturday_hours = ?,
                        saturday_overtime = ?,
                        sunday_hours = ?,
                        sunday_overtime = ?,
                        night_hours = ?,
                        night_overtime = ?,
                        delegation_hours = ?,
                        delegation_overtime = ?,
                        vacation_hours = ?,
                        sickleave_hours = ?,
                        status = ?,
                        system_rate_snapshot = ?,
                        system_cost = ?,
                        final_cost = ?,
                        approved_by_user_id = ?,
                        approved_at = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $projectId,
                    $costNodeId,
                    $workType,
                    $date,
                    $period,
                    $hours,
                    $overtimeHours,
                    $normalizedAbsenceDays,
                    $isPaid,
                    $description ?: null,
                    $isWeekend,
                    $isSaturday,
                    $isSunday,
                    $isDelegation,
                    $isNight,
                    $workdayHours,
                    $workdayOt,
                    $saturdayHours,
                    $saturdayOt,
                    $sundayHours,
                    $sundayOt,
                    $nightHours,
                    $nightOt,
                    $delegationHours,
                    $delegationOt,
                    $vacationHours,
                    $sickleaveHours,
                    $approveInline ? 'approved' : 'pending',
                    $approveInline ? $approvalCostData['system_rate_snapshot'] : null,
                    $approveInline ? $approvalCostData['suggested'] : 0,
                    $approveInline ? $approvalCostData['suggested'] : null,
                    $approveInline ? $_SESSION['user_id'] : null,
                    $approveInline ? date('Y-m-d H:i:s') : null,
                    $logId
                ]);
            } else {
                // Zwykła aktualizacja (wpis pending)
                $stmt = $pdo->prepare("
                    UPDATE work_logs 
                    SET project_id = ?,
                        cost_node_id = ?,
                        work_type = ?,
                        date = ?,
                        period = ?,
                        hours = ?,
                        overtime_hours = ?,
                        absence_days = ?,
                        is_paid = ?,
                        description = ?,
                        is_weekend = ?,
                        is_saturday = ?,
                        is_sunday = ?,
                        is_delegation = ?,
                        is_night = ?,
                        workday_hours = ?,
                        workday_overtime = ?,
                        saturday_hours = ?,
                        saturday_overtime = ?,
                        sunday_hours = ?,
                        sunday_overtime = ?,
                        night_hours = ?,
                        night_overtime = ?,
                        delegation_hours = ?,
                        delegation_overtime = ?,
                        vacation_hours = ?,
                        sickleave_hours = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $projectId,
                    $costNodeId,
                    $workType,
                    $date,
                    $period,
                    $hours,
                    $overtimeHours,
                    $normalizedAbsenceDays,
                    $isPaid,
                    $description ?: null,
                    $isWeekend,
                    $isSaturday,
                    $isSunday,
                    $isDelegation,
                    $isNight,
                    $workdayHours,
                    $workdayOt,
                    $saturdayHours,
                    $saturdayOt,
                    $sundayHours,
                    $sundayOt,
                    $nightHours,
                    $nightOt,
                    $delegationHours,
                    $delegationOt,
                    $vacationHours,
                    $sickleaveHours,
                    $logId
                ]);
            }
            
            $pdo->commit();
            
            $logMsg = $approveOnSave
                ? ($approveInline
                    ? "Edytowano i zatwierdzono wpis pracy ID $logId (przez user_id={$_SESSION['user_id']})"
                    : "Edytowano wpis pracy ID $logId - pozostawiono pending, brak stawki do automatycznego zatwierdzenia (przez user_id={$_SESSION['user_id']})")
                : "Edytowano wpis pracy ID $logId (przez user_id={$_SESSION['user_id']})";
            logEvent($logMsg, 'INFO');
            
            $_SESSION['success'] = $approveOnSave
                ? ($approveInline
                    ? 'Wpis został zapisany i zatwierdzony.'
                    : 'Wpis został zaktualizowany. Brak stawki do automatycznego przeliczenia, więc wymaga ręcznego zatwierdzenia.')
                : 'Wpis został zaktualizowany pomyślnie.';
            
            $redirectAfterSave = ($approveOnSave && !$approveInline) ? $worklogReturnUrlWithStatus($returnUrl, 'pending') : $returnUrl;
            header("Location: " . $redirectAfterSave);
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Błąd aktualizacji wpisu. Spróbuj ponownie.';
            logEvent("Błąd edycji wpisu ID $logId: " . $e->getMessage(), 'ERROR');
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Edytuj Raport Dnia</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --bg-card: #ffffff;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #22c55e;
            --danger: #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body); color: var(--text-main); line-height: 1.5;
        }
        .container { max-width: 1000px; margin: 0 auto; padding: 25px; }

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

        /* Hero */
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 26px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-secondary {
            background: rgba(255,255,255,0.1); color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2); font-weight: 600;
            padding: 8px 16px; border-radius: 8px; text-decoration: none;
            font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
        }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); padding: 32px; }
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
        .alert-error   { background: #fef2f2; border-color: #dc2626; color: #991b1b; }
        .alert-warning { background: #fffbeb; border-color: #f59e0b; color: #92400e; }
        .alert ul { margin: 8px 0 0 18px; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: var(--text-main); font-size: 13px; }
        .form-group .required { color: var(--danger); }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%; padding: 9px 12px;
            border: 1px solid var(--border); border-radius: 6px;
            font-size: 13px; font-family: inherit; color: var(--text-main);
            background: white; transition: border-color 0.15s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 2px rgba(102,126,234,0.1); }
        .form-group textarea { resize: vertical; min-height: 90px; }
        .form-group .help-text { font-size: 12px; color: var(--text-muted); margin-top: 5px; }
        
        /* CHECKBOXY - Urlop/L4 */
        .absence-section {
            margin: 25px 0;
            padding: 20px;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-radius: 12px;
            border: 2px solid #bae6fd;
        }
        .absence-section h3 {
            margin-bottom: 15px;
            color: #0369a1;
            font-size: 16px;
        }
        .checkbox-row {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 12px 20px;
            background: white;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            transition: all 0.2s;
        }
        .checkbox-item:hover {
            border-color: #667eea;
        }
        .checkbox-item input[type="checkbox"] {
            width: 24px;
            height: 24px;
            cursor: pointer;
            accent-color: #667eea;
        }
        .checkbox-item .checkbox-label {
            font-weight: 600;
            font-size: 15px;
            color: #333;
        }
        .checkbox-item.vacation-checked {
            background: #dcfce7;
            border-color: #22c55e;
        }
        .checkbox-item.sick-checked {
            background: #fee2e2;
            border-color: #ef4444;
        }
        .future-absence-note {
            margin-top: 8px;
            font-size: 12px;
            color: #075985;
        }
        
        /* TABELA GODZIN */
        .hours-table-wrapper {
            margin: 30px 0;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            transition: opacity 0.3s;
        }
        .hours-table-wrapper.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        .hours-table-wrapper h3 {
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        .hours-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        .hours-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .hours-table th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
        }
        .hours-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
        }
        .hours-table tbody tr:hover {
            background: #f8f9fa;
        }
        .hours-table input[type="number"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
            text-align: right;
        }
        .hours-table input[type="number"]:focus {
            outline: none;
            border-color: #667eea;
        }
        .type-label {
            font-weight: 600;
            color: #333;
        }
        .type-description {
            font-size: 12px;
            color: #6b7280;
            margin-top: 2px;
        }
        
        .btn {
            padding: 9px 22px; border-radius: 7px; font-weight: 600; font-size: 13px;
            cursor: pointer; border: 1px solid transparent; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; font-family: inherit;
        }
        .btn-primary   { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: var(--border); }
        .btn-secondary:hover { background: #f9fafb; }
        .form-actions { display: flex; gap: 10px; margin-top: 22px; padding-top: 18px; border-top: 1px solid #f3f4f6; }
        .absence-active-notice {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 8px;
            padding: 15px 20px;
            margin: 15px 0;
            color: #92400e;
            font-weight: 600;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .hours-table {
                font-size: 13px;
            }
            .hours-table th,
            .hours-table td {
                padding: 8px 10px;
            }
            .checkbox-row {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo e($returnUrl); ?>">Dziennik Pracy</a> /
                    Edytuj Raport
                </div>
                <h1>Edytuj Raport Dnia</h1>
                <p><?php echo e($log['first_name'] . ' ' . $log['last_name']); ?> — <?php echo formatDate($log['date']); ?></p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo e($returnUrl); ?>" class="btn-hero-secondary">← Wróć do dziennika</a>
            </div>
        </div>
        
        <div class="card">
            <?php if ($editingApproved): ?>
                <div class="alert alert-warning">
                    <strong>Uwaga!</strong> Edytujesz zatwierdzony wpis. 
                    Po zapisaniu zmian wpis zostanie ponownie przeliczony i zatwierdzony bez przechodzenia przez ekran zbiorczy.
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Błąd!</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="worklogForm">
                <input type="hidden" name="return_url" value="<?php echo e($returnUrl); ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>
                            Data Pracy <span class="required">*</span>
                        </label>
                        <input type="date" 
                               id="worklogDateInput"
                               name="date" 
                               value="<?php echo e($_POST['date'] ?? $log['date']); ?>"
                               max="<?php echo date('Y-m-d'); ?>"
                               required>
                        <div id="futureAbsenceNote" class="future-absence-note" style="display:none;">
                            Dla urlopu i L4 mozesz ustawic date w przyszlosci.
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Projekt</label>
                        <select name="project_id" id="project_id">
                            <option value="">Wybierz projekt (opcjonalnie)</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>" 
                                        <?php echo (($_POST['project_id'] ?? $log['project_id']) == $project['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($project['name']); ?>
                                    <?php if ($project['is_internal']): ?> (Wewnętrzny)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Etap</label>
                        <select name="cost_node_id" id="cost_node_select" 
                                data-selected-value="<?php echo e($_POST['cost_node_id'] ?? $log['cost_node_id'] ?? ''); ?>">
                            <option value="">-- Wybierz najpierw projekt --</option>
                        </select>
                    </div>
                </div>
                
                <!-- SEKCJA ABSENCJI -->
                <div class="absence-section">
                    <h3>Urlop lub L4 (Chorobowe)</h3>
                    <p style="font-size: 13px; color: #666; margin-bottom: 15px;">
                        Zaznacz jeśli to dzień wolny. Godziny pracy będą niedostępne.
                    </p>
                    <div class="checkbox-row">
                        <label class="checkbox-item" id="vacationCheckbox">
                            <input type="checkbox" name="is_vacation" value="1" 
                                   <?php echo (isset($_POST['is_vacation']) ? ($_POST['is_vacation'] == '1') : ($log['work_type'] === 'vacation')) ? 'checked' : ''; ?>>
                            <span class="checkbox-label">Urlop</span>
                        </label>
                        <label class="checkbox-item" id="sickleaveCheckbox">
                            <input type="checkbox" name="is_sickleave" value="1"
                                   <?php echo (isset($_POST['is_sickleave']) ? ($_POST['is_sickleave'] == '1') : ($log['work_type'] === 'sick')) ? 'checked' : ''; ?>>
                            <span class="checkbox-label">L4 (Chorobowe)</span>
                        </label>
                    </div>
                    <div id="absenceNotice" class="absence-active-notice" style="display: none;">
                        Zaznaczono absencję - pola godzin są zablokowane
                    </div>
                    <div id="absenceDaysGroup" class="form-group" style="display:none; margin-top:16px; margin-bottom:0;">
                        <label>Liczba dni absencji <span class="required">*</span></label>
                        <input type="number"
                               name="absence_days"
                               id="absenceDaysInput"
                               step="0.5"
                               min="0.5"
                               max="31"
                               value="<?php echo e($_POST['absence_days'] ?? number_format(normalizeAbsenceDays($log), 1, '.', '')); ?>"
                               <?php echo (($log['work_type'] ?? 'work') === 'vacation' || ($log['work_type'] ?? 'work') === 'sick' || isset($_POST['is_vacation']) || isset($_POST['is_sickleave'])) ? '' : 'disabled'; ?>>
                        <div class="help-text">Dla pol dnia wpisz `0.5`. Dni sa przeliczane technicznie na 8h do kosztu.</div>
                    </div>
                </div>
                
                <!-- TABELA GODZIN -->
                <div class="hours-table-wrapper" id="hoursTableWrapper">
                    <h3>Przepracowane Godziny</h3>
                    <table class="hours-table">
                        <thead>
                            <tr>
                                <th>Typ Pracy</th>
                                <th style="text-align: center;">Godziny</th>
                                <th style="text-align: center;">Nadgodziny</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <div class="type-label">Godziny Robocze</div>
                                    <div class="type-description">Standardowy dzień pracy</div>
                                </td>
                                <td>
                                    <input type="number" name="workday_hours" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['workday_hours'] ?? $log['workday_hours']); ?>" 
                                           placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" name="workday_overtime" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['workday_overtime'] ?? $log['workday_overtime']); ?>" 
                                           placeholder="0.00">
                                </td>
                            </tr>
                            
                            <tr>
                                <td>
                                    <div class="type-label">Sobota</div>
                                    <div class="type-description">Praca w sobotę</div>
                                </td>
                                <td>
                                    <input type="number" name="saturday_hours" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['saturday_hours'] ?? $log['saturday_hours']); ?>" 
                                           placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" name="saturday_overtime" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['saturday_overtime'] ?? $log['saturday_overtime']); ?>" 
                                           placeholder="0.00">
                                </td>
                            </tr>
                            
                            <tr>
                                <td>
                                    <div class="type-label">Niedziela</div>
                                    <div class="type-description">Praca w niedzielę</div>
                                </td>
                                <td>
                                    <input type="number" name="sunday_hours" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['sunday_hours'] ?? $log['sunday_hours']); ?>" 
                                           placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" name="sunday_overtime" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['sunday_overtime'] ?? $log['sunday_overtime']); ?>" 
                                           placeholder="0.00">
                                </td>
                            </tr>
                            
                            <tr>
                                <td>
                                    <div class="type-label">Godziny Nocne</div>
                                    <div class="type-description">Praca w nocy (22:00-6:00)</div>
                                </td>
                                <td>
                                    <input type="number" name="night_hours" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['night_hours'] ?? $log['night_hours']); ?>" 
                                           placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" name="night_overtime" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['night_overtime'] ?? $log['night_overtime']); ?>" 
                                           placeholder="0.00">
                                </td>
                            </tr>
                            
                            <tr>
                                <td>
                                    <div class="type-label">Delegacja</div>
                                    <div class="type-description">Praca poza firmą</div>
                                </td>
                                <td>
                                    <input type="number" name="delegation_hours" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['delegation_hours'] ?? $log['delegation_hours']); ?>" 
                                           placeholder="0.00">
                                </td>
                                <td>
                                    <input type="number" name="delegation_overtime" step="0.25" min="0" max="24" 
                                           value="<?php echo e($_POST['delegation_overtime'] ?? $log['delegation_overtime']); ?>" 
                                           placeholder="0.00">
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <script>
                    // Ładowanie etapów kosztowych
                    function loadCostNodes(projectId, selectedCostNodeId = null) {
                        const select = document.getElementById('cost_node_select');
                        
                        if (!projectId) {
                            select.innerHTML = '<option value="">-- Wybierz najpierw projekt --</option>';
                            select.disabled = true;
                            return;
                        }
                        
                        select.disabled = false;
                        select.innerHTML = '<option value="">Ładowanie...</option>';
                        
                        fetch(`../../api/get-cost-nodes.php?project_id=${projectId}`)
                            .then(response => response.json())
                            .then(data => {
                                select.innerHTML = '<option value="">-- Bez etapu --</option>';
                                if (data.success && Array.isArray(data.nodes)) {
                                    data.nodes.forEach(node => {
                                        const option = document.createElement('option');
                                        option.value = node.id;
                                        option.textContent = (node.parent_id ? '  ↳ ' : '') + node.name;
                                        if (selectedCostNodeId && node.id == selectedCostNodeId) {
                                            option.selected = true;
                                        }
                                        select.appendChild(option);
                                    });
                                }
                            })
                            .catch(() => {
                                select.innerHTML = '<option value="">-- Błąd ładowania --</option>';
                            });
                    }
                    
                    // Obsługa checkboxów absencji
                    function updateAbsenceState() {
                        const vacationCb = document.querySelector('input[name="is_vacation"]');
                        const sickleaveCb = document.querySelector('input[name="is_sickleave"]');
                        const hoursWrapper = document.getElementById('hoursTableWrapper');
                        const absenceNotice = document.getElementById('absenceNotice');
                        const vacationLabel = document.getElementById('vacationCheckbox');
                        const sickleaveLabel = document.getElementById('sickleaveCheckbox');
                        const dateInput = document.getElementById('worklogDateInput');
                        const futureAbsenceNote = document.getElementById('futureAbsenceNote');
                        const absenceDaysGroup = document.getElementById('absenceDaysGroup');
                        const absenceDaysInput = document.getElementById('absenceDaysInput');
                        
                        const isVacation = vacationCb.checked;
                        const isSickLeave = sickleaveCb.checked;
                        const isAbsence = isVacation || isSickLeave;
                        
                        if (isAbsence) {
                            hoursWrapper.classList.add('disabled');
                            absenceNotice.style.display = 'block';
                            futureAbsenceNote.style.display = 'block';
                            absenceDaysGroup.style.display = 'block';
                            dateInput.removeAttribute('max');
                            absenceDaysInput.disabled = false;
                            absenceDaysInput.required = true;
                        } else {
                            hoursWrapper.classList.remove('disabled');
                            absenceNotice.style.display = 'none';
                            futureAbsenceNote.style.display = 'none';
                            absenceDaysGroup.style.display = 'none';
                            dateInput.max = '<?php echo date('Y-m-d'); ?>';
                            if (dateInput.value > dateInput.max) {
                                dateInput.value = dateInput.max;
                            }
                            absenceDaysInput.required = false;
                            absenceDaysInput.disabled = true;
                        }
                        
                        vacationLabel.classList.toggle('vacation-checked', isVacation);
                        sickleaveLabel.classList.toggle('sick-checked', isSickLeave);
                        
                        if (isVacation) {
                            sickleaveCb.disabled = true;
                        } else if (isSickLeave) {
                            vacationCb.disabled = true;
                        } else {
                            vacationCb.disabled = false;
                            sickleaveCb.disabled = false;
                        }
                    }
                    
                    document.addEventListener('DOMContentLoaded', function() {
                        const projectSelect = document.getElementById('project_id');
                        const costNodeSelect = document.getElementById('cost_node_select');
                        
                        if (projectSelect && projectSelect.value) {
                            const selectedCostNodeId = costNodeSelect ? costNodeSelect.dataset.selectedValue : null;
                            loadCostNodes(projectSelect.value, selectedCostNodeId);
                        }
                        
                        if (projectSelect) {
                            projectSelect.addEventListener('change', function() {
                                loadCostNodes(this.value);
                            });
                        }
                        
                        document.querySelector('input[name="is_vacation"]').addEventListener('change', updateAbsenceState);
                        document.querySelector('input[name="is_sickleave"]').addEventListener('change', updateAbsenceState);
                        
                        updateAbsenceState();
                    });
                </script>
                
                <div class="form-group">
                    <label>Opis Pracy</label>
                    <textarea name="description" placeholder="Opcjonalny opis wykonanej pracy..."><?php echo e($_POST['description'] ?? $log['description']); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $isAdminUser ? 'Zapisz i zatwierdź' : 'Zapisz Zmiany'; ?>
                    </button>
                    <a href="<?php echo e($returnUrl); ?>" class="btn btn-secondary">
                        Anuluj
                    </a>
                </div>
            </form>
        </div>
    </div>
    
</body>
</html>
