<?php
/**
 * BRYGAD ERP v3.0 - Dodawanie Wpisu Pracy V4
 * Nowosci:
 *  - Multi-worker: admin moze zaznaczyc kilku pracownikow naraz
 *  - Multi-entry: mozna dodac kilka projektow/zestawow godzin na jeden dzien
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$errors  = [];
$success = false;

$isAdminUser     = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

// Pobierz pracownikow
if ($isAdminUser) {
    $stmt    = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
    $workers = $stmt->fetchAll();
} elseif ($currentWorkerId) {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM workers WHERE id = ? AND is_active = 1");
    $stmt->execute([$currentWorkerId]);
    $workers = $stmt->fetchAll();
} else {
    $workers = [];
}

// Pobierz projekty
try {
    $stmt     = $pdo->query("SELECT id, name, is_internal FROM projects WHERE status IN ('active', 'planned') ORDER BY is_internal DESC, name");
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    $projects = [];
}

// ─── POST ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Ustal liste worker_id
    if (!$isAdminUser && $currentWorkerId) {
        $workerIds = [(int)$currentWorkerId];
    } else {
        // tryb multi lub single
        if (!empty($_POST['worker_ids']) && is_array($_POST['worker_ids'])) {
            $workerIds = array_unique(array_map('intval', $_POST['worker_ids']));
            $workerIds = array_filter($workerIds, fn($id) => $id > 0);
        } else {
            $single = (int)($_POST['worker_id'] ?? 0);
            $workerIds = $single > 0 ? [$single] : [];
        }
    }

    $date        = trim($_POST['date'] ?? '');
    $isVacation  = isset($_POST['is_vacation'])  && $_POST['is_vacation']  == '1';
    $isSickLeave = isset($_POST['is_sickleave']) && $_POST['is_sickleave'] == '1';
    $absenceDaysRaw = trim((string)($_POST['absence_days'] ?? '1'));
    $absenceDays = 0.0;

    // 2. Walidacja wspolna
    if (empty($workerIds)) {
        $errors[] = 'Wybierz co najmniej jednego pracownika.';
    }
    if (empty($date)) {
        $errors[] = 'Data pracy jest wymagana.';
    }
    if ($isVacation || $isSickLeave) {
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
    }

    // 3. Wpisy (entries) — tablica bloków projekt+godziny
    //    Dla urlopu/L4: ignorujemy entries, robimy 1 wpis per pracownik
    $entries = [];
    if (!$isVacation && !$isSickLeave) {
        $rawEntries = $_POST['entries'] ?? [];
        if (!is_array($rawEntries) || empty($rawEntries)) {
            // fallback: stary format (pojedynczy wpis)
            $rawEntries = [[
                'project_id'    => $_POST['project_id']    ?? '',
                'cost_node_id'  => $_POST['cost_node_id']  ?? '',
                'workday_hours'    => $_POST['workday_hours']    ?? 0,
                'workday_overtime' => $_POST['workday_overtime'] ?? 0,
                'saturday_hours'   => $_POST['saturday_hours']   ?? 0,
                'saturday_overtime'=> $_POST['saturday_overtime']?? 0,
                'sunday_hours'     => $_POST['sunday_hours']     ?? 0,
                'sunday_overtime'  => $_POST['sunday_overtime']  ?? 0,
                'night_hours'      => $_POST['night_hours']      ?? 0,
                'night_overtime'   => $_POST['night_overtime']   ?? 0,
                'delegation_hours' => $_POST['delegation_hours'] ?? 0,
                'delegation_overtime' => $_POST['delegation_overtime'] ?? 0,
                'description'   => $_POST['description'] ?? '',
            ]];
        }

        foreach ($rawEntries as $idx => $e) {
            $wdH  = (float)($e['workday_hours']      ?? 0);
            $wdOt = (float)($e['workday_overtime']    ?? 0);
            $saH  = (float)($e['saturday_hours']      ?? 0);
            $saOt = (float)($e['saturday_overtime']   ?? 0);
            $suH  = (float)($e['sunday_hours']        ?? 0);
            $suOt = (float)($e['sunday_overtime']     ?? 0);
            $niH  = (float)($e['night_hours']         ?? 0);
            $niOt = (float)($e['night_overtime']      ?? 0);
            $deH  = (float)($e['delegation_hours']    ?? 0);
            $deOt = (float)($e['delegation_overtime'] ?? 0);
            $total = $wdH + $wdOt + $saH + $saOt + $suH + $suOt + $niH + $niOt + $deH + $deOt;

            if ($total <= 0) {
                if (count($rawEntries) > 1) {
                    $errors[] = 'Wpis ' . ($idx + 1) . ': wpisz co najmniej jedna godzine.';
                } else {
                    $errors[] = 'Wpisz co najmniej jedna godzine.';
                }
                continue;
            }
            if ($total > 24) {
                $errors[] = 'Wpis ' . ($idx + 1) . ': laczna liczba godzin nie moze przekroczyc 24h.';
                continue;
            }
            foreach ([$wdH,$wdOt,$saH,$saOt,$suH,$suOt,$niH,$niOt,$deH,$deOt] as $v) {
                if ($v < 0) { $errors[] = 'Godziny nie moga byc ujemne (wpis ' . ($idx+1) . ').'; break; }
            }

            $entries[] = [
                'project_id'           => !empty($e['project_id'])   ? (int)$e['project_id']   : null,
                'cost_node_id'         => !empty($e['cost_node_id']) ? (int)$e['cost_node_id'] : null,
                'workday_hours'        => $wdH,  'workday_overtime'    => $wdOt,
                'saturday_hours'       => $saH,  'saturday_overtime'   => $saOt,
                'sunday_hours'         => $suH,  'sunday_overtime'     => $suOt,
                'night_hours'          => $niH,  'night_overtime'      => $niOt,
                'delegation_hours'     => $deH,  'delegation_overtime' => $deOt,
                'description'          => trim($e['description'] ?? ''),
                'hours'                => $wdH + $saH + $suH + $niH + $deH,
                'overtime_hours'       => $wdOt + $saOt + $suOt + $niOt + $deOt,
                'is_saturday'          => ($saH > 0 || $saOt > 0) ? 1 : 0,
                'is_sunday'            => ($suH > 0 || $suOt > 0) ? 1 : 0,
                'is_night'             => ($niH > 0 || $niOt > 0) ? 1 : 0,
                'is_delegation'        => ($deH > 0 || $deOt > 0) ? 1 : 0,
            ];
        }

        if (empty($entries) && empty($errors)) {
            $errors[] = 'Wpisz co najmniej jedna godzine.';
        }
    }

    // 4. Zapis do bazy
    if (empty($errors)) {
        try {
            $period    = date('Y-m-01', strtotime($date));
            $createdBy = $_SESSION['user_id'] ?? null;
            $insertedCount = 0;

            $stmtInsert = $pdo->prepare("
                INSERT INTO work_logs
                (worker_id, project_id, cost_node_id, work_type, date, period,
                 hours, overtime_hours, absence_days, is_paid, description,
                 is_weekend, is_saturday, is_sunday, is_delegation, is_night,
                 workday_hours, workday_overtime,
                 saturday_hours, saturday_overtime,
                 sunday_hours, sunday_overtime,
                 night_hours, night_overtime,
                 delegation_hours, delegation_overtime,
                 vacation_hours, sickleave_hours,
                 status, created_by_user_id, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,NOW())
            ");

            foreach ($workerIds as $wid) {
                if ($isVacation || $isSickLeave) {
                    $workType   = $isVacation ? 'vacation' : 'sick';
                    $vacHours   = $isVacation   ? round($absenceDays * 8, 2) : 0.0;
                    $sickHours  = $isSickLeave  ? round($absenceDays * 8, 2) : 0.0;
                    $stmtInsert->execute([
                        $wid, null, null, $workType, $date, $period,
                        0, 0, $absenceDays, 1, null,
                        0, 0, 0, 0, 0,
                        0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
                        $vacHours, $sickHours,
                        $createdBy,
                    ]);
                    $insertedCount++;
                } else {
                    foreach ($entries as $entry) {
                        $isWeekend = ($entry['is_saturday'] || $entry['is_sunday']) ? 1 : 0;
                        $stmtInsert->execute([
                            $wid,
                            $entry['project_id'],
                            $entry['cost_node_id'],
                            'work',
                            $date,
                            $period,
                            $entry['hours'],
                            $entry['overtime_hours'],
                            null, 1,
                            $entry['description'] ?: null,
                            $isWeekend,
                            $entry['is_saturday'],
                            $entry['is_sunday'],
                            $entry['is_delegation'],
                            $entry['is_night'],
                            $entry['workday_hours'],    $entry['workday_overtime'],
                            $entry['saturday_hours'],   $entry['saturday_overtime'],
                            $entry['sunday_hours'],     $entry['sunday_overtime'],
                            $entry['night_hours'],      $entry['night_overtime'],
                            $entry['delegation_hours'], $entry['delegation_overtime'],
                            0.0, 0.0,
                            $createdBy,
                        ]);
                        $insertedCount++;
                    }
                }
            }

            logEvent("Dodano $insertedCount wpisow pracy V4: data $date", 'INFO');
            $success = true;
            header('Location: ' . url('hr.worklog'));
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Blad dodawania wpisu: ' . $e->getMessage();
            logEvent('Blad dodawania wpisu pracy V4: ' . $e->getMessage(), 'ERROR');
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
    <title><?php echo e(APP_NAME); ?> - Dodaj Raport Dnia</title>
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
            background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 50px;
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

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 32px;
        }

        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .alert-error   { background: #fef2f2; border-left: 4px solid var(--danger); color: #991b1b; }
        .alert-success { background: #f0fdf4; border-left: 4px solid var(--success); color: #166534; }
        .alert ul { margin: 8px 0 0 18px; }

        /* Form basics */
        .form-row    { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; margin-bottom: 18px; }
        .form-group  { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: var(--text-main); }
        .form-group .required { color: var(--danger); }
        .form-group input[type="date"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
            color: var(--text-main);
            transition: border-color 0.15s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(102,126,234,0.1);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .help-text { font-size: 12px; color: var(--text-muted); margin-top: 5px; }

        /* ── SEKCJA PRACOWNIKA ── */
        .worker-section {
            margin-bottom: 20px;
        }
        .worker-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .worker-section-header label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-main);
        }
        .btn-toggle-multi {
            padding: 5px 14px;
            height: 30px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-toggle-multi:hover,
        .btn-toggle-multi.active {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(102,126,234,0.05);
        }

        /* Multi-worker lista */
        .worker-multi-list {
            border: 1px solid var(--border);
            border-radius: 6px;
            overflow: hidden;
        }
        .worker-multi-header {
            padding: 8px 14px;
            background: #f9fafb;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .worker-multi-header label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            user-select: none;
        }
        .worker-multi-body {
            max-height: 220px;
            overflow-y: auto;
        }
        .worker-multi-item {
            display: flex;
            align-items: center;
            padding: 9px 14px;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            transition: background 0.1s;
        }
        .worker-multi-item:last-child { border-bottom: none; }
        .worker-multi-item:hover { background: #fafbff; }
        .worker-multi-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
            flex-shrink: 0;
            margin-right: 10px;
        }
        .worker-multi-item.checked { background: rgba(102,126,234,0.05); }
        .worker-multi-name { font-size: 13px; font-weight: 500; color: var(--text-main); }

        /* ── SEKCJA ABSENCJI ── */
        .absence-section {
            margin: 20px 0;
            padding: 18px;
            background: #f0f9ff;
            border-radius: 10px;
            border: 1px solid #bae6fd;
        }
        .absence-section h3 { font-size: 14px; font-weight: 700; color: #0369a1; margin-bottom: 12px; }
        .checkbox-row { display: flex; gap: 14px; flex-wrap: wrap; }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 10px 18px;
            background: white;
            border-radius: 7px;
            border: 1px solid var(--border);
            transition: all 0.15s;
        }
        .checkbox-item:hover { border-color: var(--primary); }
        .checkbox-item input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; accent-color: var(--primary); }
        .checkbox-item .checkbox-label { font-weight: 600; font-size: 14px; }
        .checkbox-item.vacation-checked { background: #dcfce7; border-color: #22c55e; }
        .checkbox-item.sick-checked     { background: #fee2e2; border-color: #ef4444; }
        .absence-active-notice {
            margin-top: 12px;
            padding: 10px 16px;
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #92400e;
        }
        .future-absence-note {
            margin-top: 10px;
            font-size: 12px;
            color: #075985;
        }

        /* ── WPISY (ENTRIES) ── */
        .entries-wrapper { margin-top: 22px; }
        .entries-wrapper.disabled { opacity: 0.45; pointer-events: none; }

        .entry-card {
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 14px;
        }
        .entry-card-header {
            background: #f9fafb;
            padding: 10px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }
        .entry-card-title { font-size: 13px; font-weight: 700; color: var(--text-main); }
        .btn-remove-entry {
            background: none;
            border: none;
            color: var(--danger);
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            transition: background 0.1s;
            font-weight: 700;
        }
        .btn-remove-entry:hover { background: #fef2f2; }
        .entry-card-body { padding: 18px; }

        /* Tabela godzin wewnątrz entry */
        .hours-table-wrapper { margin-top: 14px; }
        .hours-table-wrapper h4 { font-size: 13px; font-weight: 700; color: var(--text-main); margin-bottom: 10px; }
        .hours-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 7px;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .hours-table thead { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .hours-table th { padding: 9px 14px; text-align: left; font-size: 12px; font-weight: 600; }
        .hours-table td { padding: 9px 14px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        .hours-table tbody tr:last-child td { border-bottom: none; }
        .hours-table tbody tr:hover { background: #fafbff; }
        .hours-table input[type="number"] {
            width: 100%;
            padding: 7px 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 13px;
            text-align: right;
            font-family: inherit;
            transition: border-color 0.15s;
        }
        .hours-table input[type="number"]:focus {
            outline: none;
            border-color: var(--primary);
        }
        .type-label { font-weight: 600; font-size: 13px; }

        /* Przycisk dodaj wpis */
        .btn-add-entry {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            background: white;
            border: 1px dashed #a5b4fc;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            color: var(--primary);
            cursor: pointer;
            transition: all 0.15s;
            margin-top: 4px;
        }
        .btn-add-entry:hover {
            background: rgba(102,126,234,0.06);
            border-color: var(--primary);
        }

        /* Akcje formularza */
        .btn {
            padding: 0 24px;
            height: 42px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .form-actions { display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap; }

        .footer { text-align: center; padding: 20px; color: var(--text-muted); font-size: 12px; }

        @media (max-width: 600px) {
            .container { padding: 14px; }
            .card { padding: 18px; }
            .form-row { grid-template-columns: 1fr; }
            .checkbox-row { flex-direction: column; }
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
                    <a href="<?php echo url('hr.worklog'); ?>">Dziennik Pracy</a> /
                    Dodaj Raport Dnia
                </div>
                <h1>Dodaj Raport Dnia</h1>
                <p>Wpisz godziny pracy lub zaznacz urlop / L4</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('hr.worklog'); ?>" class="btn-hero-secondary">← Wróć do dziennika</a>
            </div>
        </div>

        <div class="card">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Blad!</strong>
                    <ul><?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="worklogForm">

                <!-- ── PRACOWNIK ── -->
                <?php if ($isAdminUser): ?>
                    <div class="worker-section">
                        <div class="worker-section-header">
                            <label>Pracownik <span class="required">*</span></label>
                            <button type="button" class="btn-toggle-multi" id="btnToggleMulti" onclick="toggleMultiMode()">
                                Wielu pracownikow
                            </button>
                        </div>

                        <!-- Tryb: jeden pracownik -->
                        <div id="singleWorkerWrap" class="form-group" style="margin-bottom:0;">
                            <select name="worker_id" id="singleWorkerSelect">
                                <option value="">Wybierz pracownika</option>
                                <?php foreach ($workers as $w): ?>
                                    <option value="<?php echo $w['id']; ?>">
                                        <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Tryb: wielu pracownikow -->
                        <div id="multiWorkerWrap" style="display:none;">
                            <div class="worker-multi-list">
                                <div class="worker-multi-header">
                                    <label>
                                        <input type="checkbox" id="checkAllWorkers" onchange="toggleAllWorkers(this)">
                                        Zaznacz wszystkich
                                    </label>
                                    <span id="workerSelectedCount" style="font-size:12px; color:var(--text-muted); margin-left:auto;">
                                        Zaznaczono: 0
                                    </span>
                                </div>
                                <div class="worker-multi-body">
                                    <?php foreach ($workers as $w): ?>
                                        <label class="worker-multi-item" id="witem_<?php echo $w['id']; ?>">
                                            <input type="checkbox"
                                                   name="worker_ids[]"
                                                   value="<?php echo $w['id']; ?>"
                                                   class="worker-cb"
                                                   onchange="onWorkerCbChange()">
                                            <span class="worker-multi-name">
                                                <?php echo e($w['last_name'] . ' ' . $w['first_name']); ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="help-text" style="margin-top:6px;">
                                Wpis zostanie dodany dla kazdego zaznaczonego pracownika z identycznymi godzinami i projektem.
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="worker_id" value="<?php echo $currentWorkerId; ?>">
                    <div class="form-group">
                        <label>Pracownik</label>
                        <input type="text" value="<?php echo e($_SESSION['worker_name'] ?? 'Ty'); ?>"
                               disabled style="background:#f0f0f0; color:#666;">
                        <div class="help-text">Dodajesz raport dla siebie.</div>
                    </div>
                <?php endif; ?>

                <!-- ── DATA ── -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Data Pracy <span class="required">*</span></label>
                        <input type="date" name="date" id="worklogDateInput"
                               value="<?php echo e($_POST['date'] ?? date('Y-m-d')); ?>"
                               max="<?php echo date('Y-m-d'); ?>"
                               required>
                        <div id="futureAbsenceNote" class="future-absence-note" style="display:none;">
                            Dla urlopu i L4 mozesz wybrac date w przyszlosci.
                        </div>
                    </div>
                </div>

                <!-- ── ABSENCJA ── -->
                <div class="absence-section">
                    <h3>Urlop lub L4</h3>
                    <p style="font-size:12px; color:#0369a1; margin-bottom:12px;">
                        Zaznacz jesli to dzien wolny. Pola godzin beda niedostepne.
                    </p>
                    <div class="checkbox-row">
                        <label class="checkbox-item" id="vacationCheckbox">
                            <input type="checkbox" name="is_vacation" value="1"
                                   <?php echo isset($_POST['is_vacation']) ? 'checked' : ''; ?>>
                            <span class="checkbox-label">Urlop</span>
                        </label>
                        <label class="checkbox-item" id="sickleaveCheckbox">
                            <input type="checkbox" name="is_sickleave" value="1"
                                   <?php echo isset($_POST['is_sickleave']) ? 'checked' : ''; ?>>
                            <span class="checkbox-label">L4 (Chorobowe)</span>
                        </label>
                    </div>
                    <div id="absenceNotice" class="absence-active-notice" style="display:none;">
                        Zaznaczono absencje - pola godzin sa zablokowane
                    </div>
                    <div id="absenceDaysGroup" class="form-group" style="display:none; margin-top:14px; margin-bottom:0;">
                        <label>Liczba dni absencji <span class="required">*</span></label>
                        <input type="number"
                               name="absence_days"
                               id="absenceDaysInput"
                               step="0.5"
                               min="0.5"
                               max="31"
                               value="<?php echo e($_POST['absence_days'] ?? '1'); ?>">
                        <div class="help-text">Dla pol dnia wpisz `0.5`. Dni sa przeliczane technicznie na 8h do kosztu.</div>
                    </div>
                </div>

                <!-- ── WPISY (projekt + godziny) ── -->
                <div class="entries-wrapper" id="entriesWrapper">
                    <div id="entriesList">
                        <!-- Wpisy będą tu renderowane przez JS / PHP fallback -->
                    </div>
                    <button type="button" class="btn-add-entry" id="btnAddEntry" onclick="addEntry()">
                        + Dodaj kolejny projekt
                    </button>
                </div>

                <!-- ── AKCJE ── -->
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Dodaj Raport</button>
                    <a href="<?php echo url('hr.worklog'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>

    <footer class="footer">
        &copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?> v<?php echo e(APP_VERSION); ?>
    </footer>

    <!-- Template wpisu (projekt + godziny) -->
    <template id="entryTemplate">
        <div class="entry-card" data-entry-idx="__IDX__">
            <div class="entry-card-header">
                <span class="entry-card-title">Wpis __NUM__</span>
                <button type="button" class="btn-remove-entry" onclick="removeEntry(this)" title="Usun wpis">&times;</button>
            </div>
            <div class="entry-card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Projekt</label>
                        <select name="entries[__IDX__][project_id]" class="entry-project-select" onchange="loadCostNodes(this, '__IDX__')">
                            <option value="">Bez projektu</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?php echo $proj['id']; ?>">
                                    <?php echo e($proj['name']); ?><?php echo $proj['is_internal'] ? ' (Wewn.)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Etap</label>
                        <select name="entries[__IDX__][cost_node_id]" class="entry-costnode-select" disabled>
                            <option value="">-- Wybierz projekt --</option>
                        </select>
                    </div>
                </div>

                <div class="hours-table-wrapper">
                    <h4>Godziny</h4>
                    <table class="hours-table">
                        <thead>
                            <tr>
                                <th>Typ pracy</th>
                                <th style="text-align:center; width:110px;">Godziny</th>
                                <th style="text-align:center; width:110px;">Nadgodziny</th>
                            </tr>
                        </thead>
                            <tbody>
                            <tr>
                                <td><div class="type-label">Robocze</div></td>
                                <td><input type="number" name="entries[__IDX__][workday_hours]"    step="0.25" min="0" max="24" placeholder="0.00"></td>
                                <td><input type="number" name="entries[__IDX__][workday_overtime]" step="0.25" min="0" max="24" placeholder="0.00"></td>
                            </tr>
                            <tr>
                                <td><div class="type-label">Sobota</div></td>
                                <td><input type="number" name="entries[__IDX__][saturday_hours]"    step="0.25" min="0" max="24" placeholder="0.00"></td>
                                <td><input type="number" name="entries[__IDX__][saturday_overtime]" step="0.25" min="0" max="24" placeholder="0.00"></td>
                            </tr>
                            <tr>
                                <td><div class="type-label">Niedziela</div></td>
                                <td><input type="number" name="entries[__IDX__][sunday_hours]"    step="0.25" min="0" max="24" placeholder="0.00"></td>
                                <td><input type="number" name="entries[__IDX__][sunday_overtime]" step="0.25" min="0" max="24" placeholder="0.00"></td>
                            </tr>
                            <tr>
                                <td><div class="type-label">Nocne</div></td>
                                <td><input type="number" name="entries[__IDX__][night_hours]"    step="0.25" min="0" max="24" placeholder="0.00"></td>
                                <td><input type="number" name="entries[__IDX__][night_overtime]" step="0.25" min="0" max="24" placeholder="0.00"></td>
                            </tr>
                            <tr>
                                <td><div class="type-label">Delegacja</div></td>
                                <td><input type="number" name="entries[__IDX__][delegation_hours]"    step="0.25" min="0" max="24" placeholder="0.00"></td>
                                <td><input type="number" name="entries[__IDX__][delegation_overtime]" step="0.25" min="0" max="24" placeholder="0.00"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="form-group" style="margin-top:14px; margin-bottom:0;">
                    <label>Opis pracy</label>
                    <textarea name="entries[__IDX__][description]" placeholder="Opcjonalny opis..."></textarea>
                </div>
            </div>
        </div>
    </template>

    <script>
        // ── KONFIGURACJA ────────────────────────────────────────────────────
        const MAX_ENTRIES = 5;
        let entryCount = 0;

        // ── INICJALIZACJA ───────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function () {
            // Zawsze startuj z jednym wpisem
            addEntry();
            updateEntryVisibility();

            // Absencja checkboxy
            document.querySelector('input[name="is_vacation"]').addEventListener('change', updateAbsenceState);
            document.querySelector('input[name="is_sickleave"]').addEventListener('change', updateAbsenceState);
            updateAbsenceState();
        });

        // ── MULTI/SINGLE WORKER ─────────────────────────────────────────────
        let multiMode = false;
        function toggleMultiMode() {
            multiMode = !multiMode;
            document.getElementById('singleWorkerWrap').style.display = multiMode ? 'none' : '';
            document.getElementById('multiWorkerWrap').style.display  = multiMode ? ''     : 'none';
            document.getElementById('btnToggleMulti').classList.toggle('active', multiMode);
            document.getElementById('btnToggleMulti').textContent = multiMode ? 'Jeden pracownik' : 'Wielu pracownikow';

            // Wlacz/wylacz required na single select
            document.getElementById('singleWorkerSelect').required = !multiMode;
        }

        function toggleAllWorkers(cb) {
            document.querySelectorAll('.worker-cb').forEach(w => {
                w.checked = cb.checked;
                w.closest('.worker-multi-item').classList.toggle('checked', cb.checked);
            });
            updateWorkerCount();
        }

        function onWorkerCbChange() {
            document.querySelectorAll('.worker-cb').forEach(w => {
                w.closest('.worker-multi-item').classList.toggle('checked', w.checked);
            });
            updateWorkerCount();
            const all     = document.querySelectorAll('.worker-cb').length;
            const checked = document.querySelectorAll('.worker-cb:checked').length;
            const master  = document.getElementById('checkAllWorkers');
            master.checked       = (checked === all && all > 0);
            master.indeterminate = (checked > 0 && checked < all);
        }

        function updateWorkerCount() {
            const n = document.querySelectorAll('.worker-cb:checked').length;
            document.getElementById('workerSelectedCount').textContent = 'Zaznaczono: ' + n;
        }

        // ── ABSENCJA ────────────────────────────────────────────────────────
        function updateAbsenceState() {
            const vacation  = document.querySelector('input[name="is_vacation"]');
            const sickleave = document.querySelector('input[name="is_sickleave"]');
            const isAbsence = vacation.checked || sickleave.checked;
            const dateInput = document.getElementById('worklogDateInput');
            const futureAbsenceNote = document.getElementById('futureAbsenceNote');
            const absenceDaysGroup = document.getElementById('absenceDaysGroup');
            const absenceDaysInput = document.getElementById('absenceDaysInput');

            document.getElementById('entriesWrapper').classList.toggle('disabled', isAbsence);
            document.getElementById('absenceNotice').style.display = isAbsence ? 'block' : 'none';
            futureAbsenceNote.style.display = isAbsence ? 'block' : 'none';
            absenceDaysGroup.style.display = isAbsence ? 'block' : 'none';

            document.getElementById('vacationCheckbox').classList.toggle('vacation-checked', vacation.checked);
            document.getElementById('sickleaveCheckbox').classList.toggle('sick-checked', sickleave.checked);

            // Wzajemne wykluczenie
            vacation.disabled  = sickleave.checked;
            sickleave.disabled = vacation.checked;

            if (isAbsence) {
                dateInput.removeAttribute('max');
                absenceDaysInput.required = true;
            } else {
                dateInput.max = '<?php echo date('Y-m-d'); ?>';
                if (dateInput.value > dateInput.max) {
                    dateInput.value = dateInput.max;
                }
                absenceDaysInput.required = false;
            }

            // Przycisk "dodaj wpis" — ukryj przy absencji
            document.getElementById('btnAddEntry').style.display = isAbsence ? 'none' : '';
        }

        // ── ENTRIES: DODAJ / USUN ───────────────────────────────────────────
        function addEntry() {
            if (entryCount >= MAX_ENTRIES) return;

            const tmpl = document.getElementById('entryTemplate');
            const idx  = entryCount;
            const num  = idx + 1;

            const clone = tmpl.content.cloneNode(true);
            const div   = clone.querySelector('.entry-card');

            // Zamien __IDX__ i __NUM__
            div.innerHTML = div.innerHTML
                .replaceAll('__IDX__', idx)
                .replaceAll('__NUM__', num);

            div.dataset.entryIdx = idx;
            document.getElementById('entriesList').appendChild(div);
            entryCount++;
            updateEntryVisibility();
        }

        function removeEntry(btn) {
            const card = btn.closest('.entry-card');
            card.remove();
            rebuildEntryNumbers();
            updateEntryVisibility();
        }

        function rebuildEntryNumbers() {
            const cards = document.querySelectorAll('.entry-card');
            entryCount  = cards.length;
            cards.forEach((card, i) => {
                card.dataset.entryIdx = i;
                const title = card.querySelector('.entry-card-title');
                if (title) title.textContent = 'Wpis ' + (i + 1);

                // Przeindeksuj names
                card.querySelectorAll('[name]').forEach(el => {
                    el.name = el.name.replace(/entries\[\d+\]/, 'entries[' + i + ']');
                });
            });
        }

        function updateEntryVisibility() {
            const cards     = document.querySelectorAll('.entry-card');
            const addBtn    = document.getElementById('btnAddEntry');
            const showRemove = cards.length > 1;

            cards.forEach(card => {
                const btn = card.querySelector('.btn-remove-entry');
                if (btn) btn.style.display = showRemove ? '' : 'none';
            });

            if (addBtn) {
                addBtn.style.display = (entryCount >= MAX_ENTRIES) ? 'none' : '';
            }
        }

        // ── ETAPY KOSZTOWE (AJAX) ──────────────────────────────────────────
        function loadCostNodes(projectSelect, idx) {
            const projectId    = projectSelect.value;
            const entryCard    = projectSelect.closest('.entry-card');
            const costNodeSel  = entryCard.querySelector('.entry-costnode-select');

            if (!projectId) {
                costNodeSel.innerHTML = '<option value="">-- Wybierz projekt --</option>';
                costNodeSel.disabled  = true;
                return;
            }

            costNodeSel.disabled  = false;
            costNodeSel.innerHTML = '<option value="">Ladowanie...</option>';

            fetch('../../api/get-cost-nodes.php?project_id=' + projectId)
                .then(r => r.json())
                .then(data => {
                    costNodeSel.innerHTML = '<option value="">-- Bez etapu --</option>';
                    if (data.success && Array.isArray(data.nodes)) {
                        data.nodes.forEach(node => {
                            const opt = document.createElement('option');
                            opt.value       = node.id;
                            opt.textContent = (node.parent_id ? '  \u21b3 ' : '') + node.name;
                            costNodeSel.appendChild(opt);
                        });
                    }
                })
                .catch(() => {
                    costNodeSel.innerHTML = '<option value="">-- Blad ladowania --</option>';
                });
        }
    </script>
</body>
</html>
