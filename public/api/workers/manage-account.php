<?php
/**
 * BRYGAD ERP v3.0 - Zarządzanie Kontem Użytkownika Pracownika
 * Akcje: create, edit (zmiana hasła), delete
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może zarządzać kontami

$pdo = getDbConnection();

$workerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

if ($workerId <= 0 || !in_array($action, ['create', 'edit', 'delete'])) {
    header('Location: ' . url('hr.workers'));
    exit;
}

// Pobierz dane pracownika i jego konta (z rolą dla walidacji)
try {
    $stmt = $pdo->prepare("
        SELECT 
            w.*,
            u.id as user_id,
            u.login as user_login,
            u.role as user_role
        FROM workers w
        LEFT JOIN users u ON u.worker_id = w.id AND u.is_active = 1
        WHERE w.id = ?
    ");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
    
    if (!$worker) {
        header('Location: ' . url('hr.workers'));
        exit;
    }
} catch (PDOException $e) {
    logEvent("Błąd pobierania pracownika ID $workerId: " . $e->getMessage(), 'ERROR');
    header('Location: ' . url('hr.workers'));
    exit;
}

$errors = [];
$success = false;

// =============================================================================
// AKCJA: DELETE - Usuń konto użytkownika (soft delete)
// =============================================================================
if ($action === 'delete') {
    if (!$worker['user_id']) {
        $_SESSION['flash_error'] = 'Pracownik nie ma konta użytkownika.';
        header('Location: ' . url('hr.workers.edit', ['id' => $workerId]));
        exit;
    }
    
    // BLOKADA: Nie można usunąć konta admina
    if ($worker['user_role'] === 'admin') {
        logEvent("Próba usunięcia konta admina: {$worker['user_login']} przez user ID " . ($_SESSION['user_id'] ?? 'unknown'), 'WARNING');
        $_SESSION['flash_error'] = 'Nie można usunąć konta administratora.';
        header('Location: ' . url('hr.workers.edit', ['id' => $workerId]));
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND role != 'admin'");
        $stmt->execute([$worker['user_id']]);
        
        logEvent("Usunięto konto użytkownika: {$worker['user_login']} (worker_id: $workerId)", 'INFO');
        
        $_SESSION['flash_success'] = 'Konto użytkownika zostało usunięte. Pracownik nie ma już dostępu do systemu.';
        header('Location: ' . url('hr.workers.edit', ['id' => $workerId]));
        exit;
    } catch (PDOException $e) {
        logEvent("Błąd usuwania konta użytkownika ID {$worker['user_id']}: " . $e->getMessage(), 'ERROR');
        $_SESSION['flash_error'] = 'Błąd podczas usuwania konta.';
        header('Location: ' . url('hr.workers.edit', ['id' => $workerId]));
        exit;
    }
}

// =============================================================================
// AKCJA: CREATE lub EDIT (formularz)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Walidacja
    if ($action === 'create') {
        if (empty($login)) {
            $errors[] = 'Login jest wymagany.';
        } elseif (strlen($login) < 3) {
            $errors[] = 'Login musi mieć minimum 3 znaki.';
        }
        
        // Sprawdź czy login już istnieje
        if (!empty($login) && empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
                $stmt->execute([$login]);
                if ($stmt->fetch()) {
                    $errors[] = 'Login jest już zajęty.';
                }
            } catch (PDOException $e) {
                $errors[] = 'Błąd sprawdzania loginu.';
                logEvent("Błąd sprawdzania loginu: " . $e->getMessage(), 'ERROR');
            }
        }
    }
    
    if (empty($password)) {
        $errors[] = 'Hasło jest wymagane.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Hasło musi mieć minimum 6 znaków.';
    }
    
    // Zapisz
    if (empty($errors)) {
        try {
            if ($action === 'create') {
                // Utwórz nowe konto
                if ($worker['user_id']) {
                    $errors[] = 'Pracownik już ma konto użytkownika.';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO users (login, password_hash, role, worker_id, is_active, created_at)
                        VALUES (?, ?, 'worker', ?, 1, NOW())
                    ");
                    $stmt->execute([$login, $passwordHash, $workerId]);
                    
                    logEvent("Utworzono konto użytkownika: $login dla pracownika ID $workerId", 'INFO');
                    
                    $_SESSION['flash_success'] = 'Konto użytkownika zostało utworzone.';
                    header('Location: ' . url('hr.workers.edit', ['id' => $workerId]));
                    exit;
                }
            } elseif ($action === 'edit') {
                // Zmień hasło
                if (!$worker['user_id']) {
                    $errors[] = 'Pracownik nie ma konta użytkownika.';
                } else {
                    // BLOKADA: Nie można zmienić hasła admina przez ten mechanizm
                    if ($worker['user_role'] === 'admin') {
                        logEvent("Próba zmiany hasła admina: {$worker['user_login']} przez user ID " . ($_SESSION['user_id'] ?? 'unknown'), 'WARNING');
                        $_SESSION['flash_error'] = 'Nie można zmienić hasła administratora tym mechanizmem.';
                        header('Location: ' . url('hr.workers.edit', ['id' => $workerId]));
                        exit;
                    }
                    
                    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                    
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role != 'admin'");
                    $stmt->execute([$passwordHash, $worker['user_id']]);
                    
                    logEvent("Zmieniono hasło dla użytkownika: {$worker['user_login']} (worker_id: $workerId)", 'INFO');
                    
                    $_SESSION['flash_success'] = 'Hasło zostało zmienione.';
                    header('Location: ' . url('hr.workers.edit', ['id' => $workerId]));
                    exit;
                }
            }
        } catch (PDOException $e) {
            $errors[] = 'Błąd zapisu. Spróbuj ponownie.';
            logEvent("Błąd zarządzania kontem: " . $e->getMessage(), 'ERROR');
        }
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
    <title><?php echo e(APP_NAME); ?> - Zarządzanie Kontem</title>
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
        .breadcrumb a:hover {
            text-decoration: underline;
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
        .alert ul {
            margin: 10px 0 0 20px;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 8px;
        }
        .form-group {
            margin-bottom: 25px;
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
            font-family: inherit;
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
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
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
            <a href="<?php echo url('hr.workers'); ?>">Pracownicy</a> / 
            <a href="<?php echo url('hr.workers.edit', ['id' => $workerId]); ?>">
                <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
            </a> / 
            Zarządzanie Kontem
        </div>
        
        <div class="page-header">
            <h2>
                <?php if ($action === 'create'): ?>
                    Utwórz Konto Użytkownika
                <?php else: ?>
                    Zmień Hasło
                <?php endif; ?>
            </h2>
        </div>
        
        <div class="card">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Błąd!</strong> Popraw następujące problemy:
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <strong>Pracownik:</strong> 
                <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                <?php if ($worker['user_login'] && $action === 'edit'): ?>
                    <br>
                    <strong>Obecny login:</strong> <?php echo e($worker['user_login']); ?>
                <?php endif; ?>
            </div>
            
            <form method="POST" action="">
                <?php if ($action === 'create'): ?>
                    <div class="form-group">
                        <label>
                            Login <span class="required">*</span>
                        </label>
                        <input type="text" 
                               name="login" 
                               value="<?php echo e($_POST['login'] ?? ''); ?>"
                               required
                               autofocus>
                        <div class="help-text">Minimum 3 znaki. Login musi być unikalny.</div>
                    </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>
                        <?php echo $action === 'edit' ? 'Nowe hasło' : 'Hasło'; ?> 
                        <span class="required">*</span>
                    </label>
                    <input type="password" 
                           name="password" 
                           required
                           <?php echo $action === 'create' ? '' : 'autofocus'; ?>>
                    <div class="help-text">Minimum 6 znaków.</div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $action === 'create' ? 'Utwórz Konto' : 'Zmień Hasło'; ?>
                    </button>
                    <a href="<?php echo url('hr.workers.edit', ['id' => $workerId]); ?>" class="btn btn-secondary">
                        Anuluj
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>

