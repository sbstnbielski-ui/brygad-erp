<?php
/**
 * BRYGAD ERP v3.0 - Usuwanie Wpisu Pracy
 * Pełne zabezpieczenia + konsekwencje dla projektów i finansów
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
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
            w.last_name,
            p.name as project_name,
            pcn.name as cost_node_name
        FROM work_logs wl
        INNER JOIN workers w ON wl.worker_id = w.id
        LEFT JOIN projects p ON wl.project_id = p.id
        LEFT JOIN project_cost_nodes pcn ON wl.cost_node_id = pcn.id
        WHERE wl.id = ?
    ");
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    
    if (!$log) {
        $_SESSION['error'] = 'Wpis nie istnieje.';
        header('Location: ' . $returnUrl);
        exit;
    }
    
    // ZABEZPIECZENIE 1: Worker może usuwać tylko swoje wpisy i tylko pending
    if (!$isAdminUser) {
        if ($log['worker_id'] != $currentWorkerId) {
            $_SESSION['error'] = 'Nie możesz usuwać wpisów innych pracowników.';
            header('Location: ' . $returnUrl);
            exit;
        }
        
        if ($log['status'] !== 'pending') {
            $_SESSION['error'] = 'Możesz usuwać tylko wpisy oczekujące na zatwierdzenie.';
            header('Location: ' . $returnUrl);
            exit;
        }
    }
    
    // ZABEZPIECZENIE 2: Admin nie może usuwać zablokowanych wpisów
    if ($log['status'] === 'locked' && $isAdminUser) {
        $_SESSION['error'] = 'Nie można usunąć zablokowanego wpisu. Odblokuj go najpierw.';
        header('Location: ' . $returnUrl);
        exit;
    }
    
} catch (PDOException $e) {
    logEvent("Błąd pobierania wpisu ID $logId do usunięcia: " . $e->getMessage(), 'ERROR');
    $_SESSION['error'] = 'Błąd pobierania wpisu.';
    header('Location: ' . $returnUrl);
    exit;
}

// Obsługa POST - właściwe usunięcie
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';
    
    if (!$confirm) {
        $errors[] = 'Musisz potwierdzić usunięcie.';
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Zapisz dane do loga przed usunięciem
            $logData = [
                'worker' => $log['first_name'] . ' ' . $log['last_name'],
                'date' => $log['date'],
                'project' => $log['project_name'] ?? 'Brak',
                'hours' => $log['hours'],
                'cost' => $log['final_cost'] ?: $log['system_cost'],
                'status' => $log['status']
            ];
            
            // KROK 1: Usuń wpis z work_logs
            $stmt = $pdo->prepare("DELETE FROM work_logs WHERE id = ?");
            $stmt->execute([$logId]);
            
            // KROK 2: Zaktualizuj statystyki projektu (jeśli był przypisany)
            // Uwaga: Finanse są liczone przez view_finance_ledger, który automatycznie 
            // się zaktualizuje po usunięciu wpisu z work_logs
            // Nie trzeba ręcznie aktualizować finansów - system jest zbudowany na VIEW
            
            // KROK 3: Jeśli wpis był zatwierdzony (approved), odśwież cache'e/raporty
            if ($log['status'] === 'approved' && $log['project_id']) {
                // View finance_ledger automatycznie obsługuje to przez JOINy
                // Nie wymaga ręcznej aktualizacji - to jest zaleta architektury VIEW
            }
            
            $pdo->commit();
            
            // Logowanie
            $logMsg = sprintf(
                "USUNIĘTO wpis pracy ID %d: pracownik=%s, data=%s, projekt=%s, godziny=%.2f, koszt=%.2f PLN, status=%s (przez user_id=%d)",
                $logId,
                $logData['worker'],
                $logData['date'],
                $logData['project'],
                $logData['hours'],
                $logData['cost'],
                $logData['status'],
                $_SESSION['user_id']
            );
            logEvent($logMsg, 'WARNING'); // WARNING bo to ważna operacja
            
            $_SESSION['success'] = 'Wpis został usunięty pomyślnie.';
            header('Location: ' . $returnUrl);
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Błąd usuwania wpisu. Spróbuj ponownie.';
            logEvent("Błąd usuwania wpisu ID $logId: " . $e->getMessage(), 'ERROR');
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
    <title><?php echo e(APP_NAME); ?> - Usuń Wpis Pracy</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 800px;
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
            color: #dc3545;
        }
        .page-header p {
            color: #666;
            margin-top: 8px;
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
        .alert-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        .alert ul {
            margin: 10px 0 0 20px;
        }
        .warning-box {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        .warning-box h3 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        .warning-box p {
            font-size: 15px;
            opacity: 0.95;
        }
        .info-box {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
        }
        .info-box h3 {
            margin-bottom: 15px;
            color: #333;
            font-size: 18px;
        }
        .info-row {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 15px;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e9ecef;
        }
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .info-label {
            font-weight: 600;
            color: #666;
        }
        .info-value {
            color: #333;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        .status-locked {
            background: #d1ecf1;
            color: #0c5460;
        }
        .consequences-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .consequences-box h4 {
            color: #856404;
            margin-bottom: 12px;
            font-size: 16px;
        }
        .consequences-box ul {
            margin-left: 20px;
            color: #856404;
        }
        .consequences-box ul li {
            margin-bottom: 8px;
        }
        .confirm-box {
            background: #f8d7da;
            border: 3px solid #dc3545;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
        }
        .confirm-box label {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #721c24;
        }
        .confirm-box input[type="checkbox"] {
            width: 24px;
            height: 24px;
            cursor: pointer;
            accent-color: #dc3545;
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
            display: inline-block;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover:not(:disabled) {
            background: #c82333;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
        }
        .btn-danger:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
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
            <a href="<?php echo e($returnUrl); ?>">Dziennik Pracy</a> / 
            Usuń Wpis
        </div>
        
        <div class="page-header">
            <h2>UWAGA: Usuń Wpis Pracy</h2>
            <p>Ta operacja jest nieodwracalna</p>
        </div>
        
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
        
        <div class="card">
            <div class="warning-box">
                <h3>UWAGA</h3>
                <p>Zamierzasz usunąć wpis pracy. Tej operacji nie można cofnąć!</p>
            </div>
            
            <div class="info-box">
                <h3>Szczegóły Usuwanego Wpisu</h3>
                <div class="info-row">
                    <span class="info-label">Pracownik:</span>
                    <span class="info-value"><?php echo e($log['first_name'] . ' ' . $log['last_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Data:</span>
                    <span class="info-value"><?php echo formatDate($log['date']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Typ:</span>
                    <span class="info-value">
                        <?php 
                            $workType = $log['work_type'] ?? 'work';
                            if ($workType === 'vacation') {
                                echo 'Urlop';
                            } elseif ($workType === 'sick') {
                                echo 'L4 (Chorobowe)';
                            } else {
                                echo 'Praca';
                            }
                        ?>
                    </span>
                </div>
                <?php if ($log['project_name']): ?>
                <div class="info-row">
                    <span class="info-label">Projekt:</span>
                    <span class="info-value"><?php echo e($log['project_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($log['cost_node_name']): ?>
                <div class="info-row">
                    <span class="info-label">Etap:</span>
                    <span class="info-value"><?php echo e($log['cost_node_name']); ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Godziny:</span>
                    <span class="info-value">
                        <?php echo number_format($log['hours'], 2); ?> h
                        <?php if ($log['overtime_hours'] > 0): ?>
                            (+ <?php echo number_format($log['overtime_hours'], 2); ?> h nadgodzin)
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($log['status'] === 'approved'): ?>
                <div class="info-row">
                    <span class="info-label">Koszt:</span>
                    <span class="info-value" style="font-weight: 700; color: #dc3545;">
                        <?php echo formatMoney($log['final_cost'] ?: $log['system_cost']); ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="info-value">
                        <?php if ($log['status'] === 'pending'): ?>
                            <span class="status-badge status-pending">Oczekujący</span>
                        <?php elseif ($log['status'] === 'approved'): ?>
                            <span class="status-badge status-approved">Zatwierdzony</span>
                        <?php else: ?>
                            <span class="status-badge status-locked">Zablokowany</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($log['description']): ?>
                <div class="info-row">
                    <span class="info-label">Opis:</span>
                    <span class="info-value"><?php echo e($log['description']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($log['status'] === 'approved' && ($log['project_id'] || $log['final_cost'] > 0)): ?>
            <div class="consequences-box">
                <h4>Konsekwencje usunięcia:</h4>
                <ul>
                    <?php if ($log['project_id']): ?>
                    <li>Godziny i koszty zostaną odjęte od projektu: <?php echo e($log['project_name']); ?></li>
                    <?php endif; ?>
                    <?php if ($log['final_cost'] > 0): ?>
                    <li>Koszt <?php echo formatMoney($log['final_cost'] ?: $log['system_cost']); ?> zostanie usunięty z raportów finansowych</li>
                    <?php endif; ?>
                    <li>Wpis zniknie z dziennika pracy pracownika</li>
                    <li>Statystyki godzin pracownika zostaną zaktualizowane</li>
                    <li>Ta operacja zostanie zapisana w logach systemu</li>
                </ul>
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="deleteForm">
                <input type="hidden" name="return_url" value="<?php echo e($returnUrl); ?>">
                <div class="confirm-box">
                    <label>
                        <input type="checkbox" name="confirm" value="yes" id="confirmCheckbox" required>
                        <span>Potwierdzam, że chcę usunąć ten wpis pracy. Rozumiem, że tej operacji nie można cofnąć.</span>
                    </label>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-danger" id="deleteButton">
                        Usuń Wpis
                    </button>
                    <a href="<?php echo e($returnUrl); ?>" class="btn btn-secondary">
                        Anuluj
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        // Włącz/wyłącz przycisk w zależności od checkboxa
        const checkbox = document.getElementById('confirmCheckbox');
        const deleteBtn = document.getElementById('deleteButton');
        
        checkbox.addEventListener('change', function() {
            deleteBtn.disabled = !this.checked;
        });
        
        // Początkowy stan
        deleteBtn.disabled = !checkbox.checked;
        
        // Dodatkowe potwierdzenie przy submit
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            if (!confirm('Czy NA PEWNO chcesz usunąć ten wpis pracy? Ta operacja jest NIEODWRACALNA!')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
