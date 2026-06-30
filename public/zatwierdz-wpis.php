<?php
/**
 * BRYGAD ERP v3.0 - Zatwierdzanie Wpisu Pracy
 * Snapshot stawki + obliczanie kosztu
 */

require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może zatwierdzać

$pdo = getDbConnection();
$errors = [];
$success = false;

$logId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($logId <= 0) {
    header('Location: dziennik-pracy.php');
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
        header('Location: dziennik-pracy.php');
        exit;
    }
    
    if ($log['status'] !== 'pending') {
        $_SESSION['error'] = 'Ten wpis został już zatwierdzony lub zablokowany.';
        header('Location: dziennik-pracy.php');
        exit;
    }
} catch (PDOException $e) {
    logEvent("Błąd pobierania wpisu ID $logId: " . $e->getMessage(), 'ERROR');
    header('Location: dziennik-pracy.php');
    exit;
}

// Pobierz aktualną stawkę pracownika
try {
    // Najpierw sprawdź stawkę projektową
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
    
    // Jeśli nie ma projektowej, weź bazową
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
    
    if (!$rate) {
        $errors[] = 'Brak stawki dla tego pracownika w dniu wykonania pracy.';
    }
} catch (PDOException $e) {
    $errors[] = 'Błąd pobierania stawki.';
    logEvent("Błąd pobierania stawki dla wpisu ID $logId: " . $e->getMessage(), 'ERROR');
}

/**
 * Wybiera odpowiednią stawkę wg priorytetu (zgodnie z wymaganiami):
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
    if ($log['is_sunday'] && !empty($rate['sunday_rate'])) {
        return [
            'rate' => $rate['sunday_rate'],
            'label' => 'Stawka niedzielna'
        ];
    }
    
    // Priorytet 4: Sobota
    if ($log['is_saturday'] && !empty($rate['saturday_rate'])) {
        return [
            'rate' => $rate['saturday_rate'],
            'label' => 'Stawka sobotnia'
        ];
    }
    
    // Priorytet 5: Nocka
    if ($log['is_night'] && !empty($rate['night_rate'])) {
        return [
            'rate' => $rate['night_rate'],
            'label' => 'Stawka nocna'
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
    if (empty($finalCost) || !is_numeric($finalCost) || $finalCost < 0) {
        $errors[] = 'Koszt końcowy musi być liczbą większą lub równą 0.';
    }
    
    // Zatwierdź wpis
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Wybierz odpowiednią stawkę wg priorytetu
            $selectedRate = selectRate($log, $rate);
            $hourlyRate = $selectedRate['rate'];
            
            // Oblicz system_cost
            $systemCost = ($log['hours'] * $hourlyRate);
            
            // Nadgodziny (overtime_rate lub fallback do base_rate)
            $overtimeRate = !empty($rate['overtime_rate']) ? $rate['overtime_rate'] : $rate['base_rate'];
            if ($log['overtime_hours'] > 0) {
                $systemCost += ($log['overtime_hours'] * $overtimeRate);
            }
            
            // Snapshot stawki (dla godzin standardowych)
            $rateSnapshot = $hourlyRate;
            
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
            
            logEvent("Zatwierdzono wpis pracy ID $logId, koszt: $finalCost PLN", 'INFO');
            
            $success = true;
            header("Refresh: 2; url=dziennik-pracy.php");
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
    $selectedRate = selectRate($log, $rate);
    $hourlyRate = $selectedRate['rate'];
    $rateLabel = $selectedRate['label'];
    
    $suggestedCost = ($log['hours'] * $hourlyRate);
    
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
        .header {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .logo-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .logo-section img {
            height: 50px;
            border-radius: 6px;
        }
        .logo-text h1 { font-size: 24px; color: #333; }
        .logo-text p { font-size: 13px; color: #666; }
        .nav-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .nav-section a {
            padding: 10px 20px;
            color: #333;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .nav-section a:hover {
            background: #f0f0f0;
        }
        .nav-section a.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .user-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .user-name { font-weight: 600; color: #333; }
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }
        .btn-logout {
            padding: 10px 20px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
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
        .flag-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 6px;
        }
        .flag-weekend { background: #e7f3ff; color: #004080; }
        .flag-delegation { background: #fff3cd; color: #856404; }
        .flag-night { background: #e2e3e5; color: #383d41; }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <img src="assets/logo-brygad-erp.png" alt="BRYGAD ERP">
                <div class="logo-text">
                    <h1><?php echo e(APP_NAME); ?></h1>
                    <p>System Zarządzania Operacyjnego</p>
                </div>
            </div>
            <nav class="nav-section">
                <a href="dashboard.php">Panel Główny</a>
                <a href="workers.php">Pracownicy</a>
                <a href="dziennik-pracy.php" class="active">Dziennik Pracy</a>
            </nav>
            <div class="user-section">
                <div>
                    <span class="user-name">
                        <?php echo e($userName); ?>
                        <?php if ($isAdminUser): ?>
                            <span class="role-badge">Administrator</span>
                        <?php endif; ?>
                    </span>
                </div>
                <a href="logout.php" class="btn-logout">Wyloguj</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="dashboard.php">Panel Główny</a> / 
            <a href="dziennik-pracy.php">Dziennik Pracy</a> / 
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
                        <span class="info-label">Godziny normalne:</span>
                        <span><?php echo number_format($log['hours'], 2); ?> h</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Nadgodziny:</span>
                        <span><?php echo number_format($log['overtime_hours'], 2); ?> h</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Typ wpisu:</span>
                        <span>
                            <?php 
                                $workTypeLabels = [
                                    'work' => 'Praca',
                                    'sick' => 'L4 (Chorobowe)',
                                    'vacation' => 'Urlop'
                                ];
                                echo $workTypeLabels[$log['work_type'] ?? 'work'] ?? 'Praca';
                            ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Flagi:</span>
                        <span>
                            <?php if ($log['is_saturday']): ?>
                                <span class="flag-badge flag-weekend">Sobota</span>
                            <?php endif; ?>
                            <?php if ($log['is_sunday']): ?>
                                <span class="flag-badge flag-weekend">Niedziela</span>
                            <?php endif; ?>
                            <?php if ($log['is_night']): ?>
                                <span class="flag-badge flag-night">Nocna</span>
                            <?php endif; ?>
                            <?php if ($log['is_delegation']): ?>
                                <span class="flag-badge flag-delegation">Delegacja</span>
                            <?php endif; ?>
                            <?php if (!$log['is_saturday'] && !$log['is_sunday'] && !$log['is_delegation'] && !$log['is_night']): ?>
                                Brak
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($log['description']): ?>
                        <div class="info-row">
                            <span class="info-label">Opis:</span>
                            <span><?php echo e($log['description']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($rate && $rateUsed): ?>
                    <div class="cost-box">
                        <h3>Koszt Wyliczony przez System</h3>
                        <div class="cost-value"><?php echo formatMoney($suggestedCost); ?></div>
                        <div class="cost-details">
                            <?php echo $rateUsed['label']; ?>: <?php echo formatMoney($rateUsed['hourly']); ?>/h<br>
                            <?php if ($log['overtime_hours'] > 0): ?>
                                Stawka nadgodziny: <?php echo formatMoney($rateUsed['overtime']); ?>/h<br>
                            <?php endif; ?>
                            Obliczenie: <?php echo number_format($log['hours'], 2); ?>h × <?php echo formatMoney($rateUsed['hourly']); ?>
                            <?php if ($log['overtime_hours'] > 0): ?>
                                + <?php echo number_format($log['overtime_hours'], 2); ?>h × <?php echo formatMoney($rateUsed['overtime']); ?>
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
                            <a href="dziennik-pracy.php" class="btn btn-secondary">
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

