<?php
/**
 * BRYGAD ERP v3.0 - Zatwierdzanie Wpisu Pracy
 * Snapshot stawki + obliczanie kosztu
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
require_once dirname(__DIR__, 2) . '/includes/absence_helper.php';
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może zatwierdzać

$pdo = getDbConnection();
$errors = [];
$success = false;
$noRateWarning = false; // Flaga: brak stawki, ale można wpisać ręcznie

$logId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($logId <= 0) {
    header('Location: ' . url('hr.worklog'));
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
        header('Location: ' . url('hr.worklog'));
        exit;
    }
    
    if ($log['status'] !== 'pending') {
        $_SESSION['error'] = 'Ten wpis został już zatwierdzony lub zablokowany.';
        header('Location: ' . url('hr.worklog'));
        exit;
    }
} catch (PDOException $e) {
    logEvent("Błąd pobierania wpisu ID $logId: " . $e->getMessage(), 'ERROR');
    header('Location: ' . url('hr.worklog'));
    exit;
}

// Pobierz aktualną stawkę pracownika
try {
    // Najpierw sprawdź stawkę projektową (dla dokładnej daty)
    $rate = null;
    
    if ($log['project_id']) {
        $stmt = $pdo->prepare("
            SELECT base_rate, overtime_rate, saturday_rate, sunday_rate, night_rate, sick_rate, vacation_rate
            FROM worker_rates
            WHERE worker_id = ?
              AND project_id = ?
              AND valid_from <= ?
              AND (valid_to IS NULL OR valid_to >= ?)
            ORDER BY valid_from DESC
            LIMIT 1
        ");
        $stmt->execute([$log['worker_id'], $log['project_id'], $log['date'], $log['date']]);
        $rate = $stmt->fetch();
    }
    
    // Jeśli nie ma projektowej dla dokładnej daty, weź bazową (dla dokładnej daty)
    if (!$rate) {
        $stmt = $pdo->prepare("
            SELECT base_rate, overtime_rate, saturday_rate, sunday_rate, night_rate, sick_rate, vacation_rate
            FROM worker_rates
            WHERE worker_id = ?
              AND project_id IS NULL
              AND valid_from <= ?
              AND (valid_to IS NULL OR valid_to >= ?)
            ORDER BY valid_from DESC
            LIMIT 1
        ");
        $stmt->execute([$log['worker_id'], $log['date'], $log['date']]);
        $rate = $stmt->fetch();
    }
    
    // Jeśli nadal nie ma, weź najnowszą stawkę bazową (bez sprawdzania daty końca)
    if (!$rate) {
        $stmt = $pdo->prepare("
            SELECT base_rate, overtime_rate, saturday_rate, sunday_rate, night_rate, sick_rate, vacation_rate
            FROM worker_rates
            WHERE worker_id = ?
              AND project_id IS NULL
              AND valid_from <= ?
            ORDER BY valid_from DESC
            LIMIT 1
        ");
        $stmt->execute([$log['worker_id'], $log['date']]);
        $rate = $stmt->fetch();
    }
    
    // POZIOM 4: Ostateczny fallback - weź JAKĄKOLWIEK stawkę bazową (nawet jeśli valid_from > data wpisu)
    if (!$rate) {
        $stmt = $pdo->prepare("
            SELECT base_rate, overtime_rate, saturday_rate, sunday_rate, night_rate, sick_rate, vacation_rate
            FROM worker_rates
            WHERE worker_id = ?
              AND project_id IS NULL
              AND base_rate IS NOT NULL
            ORDER BY valid_from DESC
            LIMIT 1
        ");
        $stmt->execute([$log['worker_id']]);
        $rate = $stmt->fetch();
    }
    
    // POZIOM 5: Ostatnia deska ratunku - weź JAKĄKOLWIEK stawkę (nawet projektową)
    if (!$rate) {
        $stmt = $pdo->prepare("
            SELECT base_rate, overtime_rate, saturday_rate, sunday_rate, night_rate, sick_rate, vacation_rate
            FROM worker_rates
            WHERE worker_id = ?
              AND base_rate IS NOT NULL
            ORDER BY valid_from DESC
            LIMIT 1
        ");
        $stmt->execute([$log['worker_id']]);
        $rate = $stmt->fetch();
    }
    
    // Jeśli brak stawki - ustaw ostrzeżenie, ale pozwól na ręczne wpisanie
    if (!$rate || empty($rate['base_rate'])) {
        $noRateWarning = true;
        $rate = null; // Wyzeruj żeby nie próbować liczyć
    }
} catch (PDOException $e) {
    $errors[] = 'Błąd pobierania stawki.';
    logEvent("Błąd pobierania stawki dla wpisu ID $logId: " . $e->getMessage(), 'ERROR');
}

/**
 * Wybiera odpowiednią stawkę wg priorytetu:
 * 1) L4 (sick) → sick_rate (lub base)
 * 2) Urlop (vacation) → vacation_rate (lub base)
 * 3) Niedziela → sunday_rate (lub base)
 * 4) Sobota → saturday_rate (lub base)
 * 5) Nocka → night_rate (lub base)
 * 6) Base rate
 */
function selectRate($log, $rate) {
    $workType = $log['work_type'] ?? 'work';
    
    // Priorytet 1: L4 (Chorobowe)
    if ($workType === 'sick') {
        $rateValue = !empty($rate['sick_rate']) ? $rate['sick_rate'] : $rate['base_rate'];
        return [
            'rate' => $rateValue,
            'label' => !empty($rate['sick_rate']) ? 'Stawka L4 (chorobowe)' : 'Stawka podstawowa (brak sick_rate)'
        ];
    }
    
    // Priorytet 2: Urlop
    if ($workType === 'vacation') {
        $rateValue = !empty($rate['vacation_rate']) ? $rate['vacation_rate'] : $rate['base_rate'];
        return [
            'rate' => $rateValue,
            'label' => !empty($rate['vacation_rate']) ? 'Stawka urlopowa' : 'Stawka podstawowa (brak vacation_rate)'
        ];
    }
    
    // Priorytet 3: Niedziela
    if ($log['is_sunday']) {
        $rateValue = !empty($rate['sunday_rate']) ? $rate['sunday_rate'] : $rate['base_rate'];
        return [
            'rate' => $rateValue,
            'label' => !empty($rate['sunday_rate']) ? 'Stawka niedzielna' : 'Stawka podstawowa (brak sunday_rate)'
        ];
    }
    
    // Priorytet 4: Sobota
    if ($log['is_saturday']) {
        $rateValue = !empty($rate['saturday_rate']) ? $rate['saturday_rate'] : $rate['base_rate'];
        return [
            'rate' => $rateValue,
            'label' => !empty($rate['saturday_rate']) ? 'Stawka sobotnia' : 'Stawka podstawowa (brak saturday_rate)'
        ];
    }
    
    // Priorytet 5: Nocka
    if ($log['is_night']) {
        $rateValue = !empty($rate['night_rate']) ? $rate['night_rate'] : $rate['base_rate'];
        return [
            'rate' => $rateValue,
            'label' => !empty($rate['night_rate']) ? 'Stawka nocna' : 'Stawka podstawowa (brak night_rate)'
        ];
    }
    
    // Domyślnie: base_rate
    return [
        'rate' => $rate['base_rate'],
        'label' => 'Stawka podstawowa'
    ];
}

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $finalCost = trim($_POST['final_cost'] ?? '');
    
    // Walidacja
    if ($finalCost === '' || !is_numeric($finalCost) || $finalCost < 0) {
        $errors[] = 'Koszt końcowy musi być liczbą większą lub równą 0.';
    }
    
    // Zatwierdź wpis
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Jeśli mamy stawkę - oblicz koszt systemowy
            $systemCost = null;
            $rateSnapshot = null;
            
            if ($rate) {
                // Check if unpaid absence
                $workType = $log['work_type'] ?? 'work';
                $isPaid = $log['is_paid'] ?? 1;
                
                if (($workType === 'sick' || $workType === 'vacation') && $isPaid == 0) {
                    // Unpaid absence - no cost
                    $systemCost = 0;
                    $rateSnapshot = 0;
                } else {
                    // Wybierz odpowiednią stawkę wg priorytetu
                    $selectedRate = selectRate($log, $rate);
                    $hourlyRate = $selectedRate['rate'];
                    
                    if ($workType === 'vacation' || $workType === 'sick') {
                        $calcHours = normalizeAbsenceHours($log);
                    } else {
                        $calcHours = (float)$log['hours'];
                    }
                    
                    // Oblicz system_cost
                    $systemCost = ($calcHours * $hourlyRate);
                    
                    // Nadgodziny (overtime_rate lub fallback do base_rate)
                    $overtimeRate = !empty($rate['overtime_rate']) ? $rate['overtime_rate'] : $rate['base_rate'];
                    if ($log['overtime_hours'] > 0) {
                        $systemCost += ($log['overtime_hours'] * $overtimeRate);
                    }
                    
                    // Snapshot stawki (dla godzin standardowych)
                    $rateSnapshot = $hourlyRate;
                }
            }
            // Jeśli brak stawki - system_cost i rateSnapshot pozostają NULL
            
            // Zaktualizuj wpis
            $stmt = $pdo->prepare("
                UPDATE work_logs 
                SET system_rate_snapshot = ?,
                    system_cost = ?,
                    final_cost = ?,
                    status = 'approved',
                    approved_by_user_id = ?,
                    approved_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $rateSnapshot,
                $systemCost,
                $finalCost,
                $_SESSION['user_id'],
                $logId
            ]);
            
            $pdo->commit();
            
            $logMsg = $noRateWarning 
                ? "Zatwierdzono wpis pracy ID $logId RĘCZNIE (brak stawki), koszt: $finalCost PLN"
                : "Zatwierdzono wpis pracy ID $logId, koszt: $finalCost PLN";
            logEvent($logMsg, 'INFO');
            
            $success = true;
            header("Location: " . url('hr.worklog'));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Błąd zatwierdzania wpisu. Spróbuj ponownie.';
            logEvent("Błąd zatwierdzania wpisu ID $logId: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Oblicz sugerowany koszt (jeśli mamy stawkę)
$suggestedCost = 0;
$rateUsed = null;
if ($rate) {
    $workType = $log['work_type'] ?? 'work';
    $isPaid = $log['is_paid'] ?? 1;
    
    if (($workType === 'sick' || $workType === 'vacation') && $isPaid == 0) {
        // Unpaid absence - no cost
        $suggestedCost = 0;
        $rateUsed = [
            'hourly' => 0,
            'label' => 'Nieobecność bezpłatna',
            'overtime' => 0
        ];
    } else {
        $selectedRate = selectRate($log, $rate);
        $hourlyRate = $selectedRate['rate'];
        $rateLabel = $selectedRate['label'];
        
        if ($workType === 'vacation' || $workType === 'sick') {
            $calcHours = normalizeAbsenceHours($log);
        } else {
            $calcHours = (float)$log['hours'];
        }
        
        $suggestedCost = ($calcHours * $hourlyRate);
        
        // Nadgodziny
        $overtimeRate = !empty($rate['overtime_rate']) ? $rate['overtime_rate'] : $rate['base_rate'];
        if ($log['overtime_hours'] > 0) {
            $suggestedCost += ($log['overtime_hours'] * $overtimeRate);
        }
        
        $rateUsed = [
            'hourly' => $hourlyRate,
            'label' => $rateLabel,
            'overtime' => $overtimeRate
        ];
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Zatwierdź Wpis</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px;
        }
        .breadcrumb {
            margin-bottom: 20px;
            color: #666;
            font-size: 14px;
        }
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        .page-header {
            margin-bottom: 30px;
        }
        .page-header h2 {
            font-size: 32px;
            color: #333;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 40px;
            margin-bottom: 20px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .alert-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        .alert-warning a {
            color: #533f03;
            font-weight: 600;
        }
        .alert ul {
            margin: 10px 0 0 20px;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 8px;
        }
        .info-box h3 {
            margin-bottom: 15px;
            color: #004080;
        }
        .info-row {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .info-label {
            font-weight: 600;
            color: #555;
        }
        .cost-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
        }
        .cost-box h3 {
            margin-bottom: 15px;
            font-size: 18px;
        }
        .cost-value {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .cost-details {
            font-size: 14px;
            opacity: 0.9;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group .required {
            color: #dc3545;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-group .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }
        .btn {
            padding: 12px 32px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
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
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        /* BADGE - urlop/L4 */
        .absence-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        .absence-vacation {
            background: #dcfce7;
            color: #166534;
        }
        .absence-sick {
            background: #fee2e2;
            color: #991b1b;
        }
        .check-icon {
            font-size: 18px;
        }
        /* Godziny info */
        .hours-detail {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
        .hours-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .hours-row:last-child {
            border-bottom: none;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo url('hr.worklog'); ?>">Dziennik Pracy</a> / 
            Zatwierdź Wpis
        </div>
        
        <div class="page-header">
            <h2>Zatwierdź Wpis Pracy</h2>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Sukces!</strong> Wpis został zatwierdzony.
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
        
        <?php if (!$success && empty($errors)): ?>
            <div class="card">
                <div class="info-box">
                    <h3>Szczegóły Wpisu</h3>
                    <div class="info-row">
                        <span class="info-label">Pracownik:</span>
                        <span><?php echo e($log['first_name'] . ' ' . $log['last_name']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Data:</span>
                        <span><?php echo formatDate($log['date']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Typ wpisu:</span>
                        <span>
                            <?php 
                                $workType = $log['work_type'] ?? 'work';
                                if ($workType === 'vacation'): 
                            ?>
                                <span class="absence-badge absence-vacation">
                                    <span class="check-icon">✓</span> Urlop (TAK)
                                </span>
                            <?php elseif ($workType === 'sick'): ?>
                                <span class="absence-badge absence-sick">
                                    <span class="check-icon">✓</span> L4 (TAK)
                                </span>
                            <?php else: ?>
                                Praca
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <?php if ($workType === 'work'): ?>
                        <div class="hours-detail">
                            <?php if ($log['workday_hours'] > 0): ?>
                            <div class="hours-row">
                                <span>Godziny robocze:</span>
                                <strong><?php echo number_format($log['workday_hours'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($log['workday_overtime'] > 0): ?>
                            <div class="hours-row">
                                <span>Nadgodziny robocze:</span>
                                <strong><?php echo number_format($log['workday_overtime'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($log['saturday_hours'] > 0): ?>
                            <div class="hours-row">
                                <span>Sobota:</span>
                                <strong><?php echo number_format($log['saturday_hours'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($log['saturday_overtime'] > 0): ?>
                            <div class="hours-row">
                                <span>Nadgodziny w sobotę:</span>
                                <strong><?php echo number_format($log['saturday_overtime'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($log['sunday_hours'] > 0): ?>
                            <div class="hours-row">
                                <span>Niedziela:</span>
                                <strong><?php echo number_format($log['sunday_hours'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($log['sunday_overtime'] > 0): ?>
                            <div class="hours-row">
                                <span>Nadgodziny w niedzielę:</span>
                                <strong><?php echo number_format($log['sunday_overtime'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($log['night_hours'] > 0): ?>
                            <div class="hours-row">
                                <span>Nocne:</span>
                                <strong><?php echo number_format($log['night_hours'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($log['night_overtime'] > 0): ?>
                            <div class="hours-row">
                                <span>Nadgodziny nocne:</span>
                                <strong><?php echo number_format($log['night_overtime'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($log['delegation_hours'] > 0): ?>
                            <div class="hours-row">
                                <span>Delegacja:</span>
                                <strong><?php echo number_format($log['delegation_hours'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                            <?php if ($log['delegation_overtime'] > 0): ?>
                            <div class="hours-row">
                                <span>Nadgodziny delegacji:</span>
                                <strong><?php echo number_format($log['delegation_overtime'], 2); ?> h</strong>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($log['description']): ?>
                        <div class="info-row" style="margin-top: 15px;">
                            <span class="info-label">Opis:</span>
                            <span><?php echo e($log['description']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($noRateWarning): ?>
                    <!-- BRAK STAWKI - ostrzeżenie + ręczne wpisanie -->
                    <div class="alert alert-warning">
                        <strong>Uwaga!</strong> Pracownik nie ma ustawionej stawki godzinowej.<br>
                        Wpisz kwotę ręcznie lub najpierw 
                        <a href="<?php echo url('hr.workers.rates', ['id' => $log['worker_id']]); ?>">ustaw stawkę pracownika</a>.
                    </div>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>
                                Koszt Końcowy (ręcznie) <span class="required">*</span>
                            </label>
                            <input type="number" 
                                   name="final_cost" 
                                   step="0.01" 
                                   min="0"
                                   value="<?php echo e($_POST['final_cost'] ?? ''); ?>"
                                   placeholder="Wpisz kwotę PLN"
                                   required
                                   autofocus>
                            <div class="help-text">
                                Brak stawki w systemie - musisz wpisać kwotę ręcznie.
                                <?php if ($workType === 'work'): ?>
                                    Godziny: <?php echo number_format($log['hours'], 2); ?>h
                                    <?php if ($log['overtime_hours'] > 0): ?>
                                        + nadgodziny: <?php echo number_format($log['overtime_hours'], 2); ?>h
                                    <?php endif; ?>
                                <?php else: ?>
                                    Absencja (urlop/L4)
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                Zatwierdź Wpis (ręcznie)
                            </button>
                            <a href="<?php echo url('hr.worklog'); ?>" class="btn btn-secondary">
                                Anuluj
                            </a>
                        </div>
                    </form>
                    
                <?php elseif ($rate && $rateUsed): ?>
                    <!-- JEST STAWKA - normalne wyliczenie -->
                    <div class="cost-box">
                        <h3>Koszt Wyliczony przez System</h3>
                        <div class="cost-value"><?php echo formatMoney($suggestedCost); ?></div>
                        <div class="cost-details">
                            <?php echo $rateUsed['label']; ?>: <?php echo formatMoney($rateUsed['hourly']); ?>/h<br>
                            <?php if ($log['overtime_hours'] > 0): ?>
                                Stawka nadgodziny: <?php echo formatMoney($rateUsed['overtime']); ?>/h<br>
                            <?php endif; ?>
                            <?php if ($workType === 'work'): ?>
                                Obliczenie: <?php echo number_format($log['hours'], 2); ?>h × <?php echo formatMoney($rateUsed['hourly']); ?>
                                <?php if ($log['overtime_hours'] > 0): ?>
                                    + <?php echo number_format($log['overtime_hours'], 2); ?>h × <?php echo formatMoney($rateUsed['overtime']); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php
                                    $displayDays = normalizeAbsenceDays($log);
                                    $displayHours = normalizeAbsenceHours($log);
                                ?>
                                Absencja: <?php echo number_format($displayDays, 1, ',', ' '); ?> dni (<?php echo number_format($displayHours, 0); ?>h) × <?php echo formatMoney($rateUsed['hourly']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label>
                                Koszt Końcowy <span class="required">*</span>
                            </label>
                            <input type="number" 
                                   name="final_cost" 
                                   step="0.01" 
                                   min="0"
                                   value="<?php echo e($_POST['final_cost'] ?? number_format($suggestedCost, 2, '.', '')); ?>"
                                   required
                                   autofocus>
                            <div class="help-text">
                                Możesz zaakceptować wyliczony koszt lub wprowadzić własny.
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                Zatwierdź Wpis
                            </button>
                            <a href="<?php echo url('hr.worklog'); ?>" class="btn btn-secondary">
                                Anuluj
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
