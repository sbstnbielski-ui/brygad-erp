<?php
/**
 * BRYGAD ERP v3.0 - Ekran Logowania
 */

require_once __DIR__ . '/config/autoload.php'; // ROOT domeny
require_once __DIR__ . '/includes/UserRouter.php';
startSecureSession();

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = $_POST['login'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($login) || empty($password)) {
        $error = 'Podaj login i hasło';
    } else {
        try {
            $pdo = getDbConnection();
            
            $stmt = $pdo->prepare("
                SELECT u.*, w.first_name, w.last_name 
                FROM users u
                LEFT JOIN workers w ON u.worker_id = w.id
                WHERE u.login = ? AND u.is_active = 1
            ");
            $stmt->execute([$login]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['login'] = $user['login'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['worker_id'] = $user['worker_id'];
                
                if ($user['worker_id']) {
                    $_SESSION['worker_name'] = $user['first_name'] . ' ' . $user['last_name'];
                }
                
                $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                logEvent("Użytkownik zalogowany: " . $login);
                
                // Centralny routing wg reguł biznesowych
                $redirectUrl = UserRouter::resolvePostLoginRedirect(
                    $pdo,
                    (int)$user['id'],
                    $user['role']
                );
                redirect($redirectUrl);
            } else {
                $error = 'Nieprawidłowy login lub hasło';
                logEvent("Nieudana próba logowania: " . $login, 'WARNING');
            }
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                $error = 'Błąd bazy danych: ' . $e->getMessage();
            } else {
                $error = 'Wystąpił błąd systemu';
                error_log("Login error: " . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Logowanie</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 800px;
            height: 800px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%);
            pointer-events: none;
        }
        
        body::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -20%;
            width: 800px;
            height: 800px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3), 0 0 1px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
            position: relative;
            z-index: 1;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .login-header {
            padding: 48px 40px 32px;
            text-align: center;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.02) 0%, transparent 100%);
        }
        
        .logo-container { 
            margin-bottom: 24px;
            display: flex;
            justify-content: center;
        }
        
        .logo-container img {
            max-width: 240px;
            height: auto;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        
        .login-header h1 {
            color: #0f172a;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }
        
        .login-header p {
            color: #64748b;
            font-size: 14px;
            font-weight: 400;
        }
        
        .login-body { 
            padding: 32px 40px 40px; 
        }
        
        .form-group { 
            margin-bottom: 20px; 
        }
        
        .form-group label {
            display: block;
            color: #1e293b;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 13px;
            letter-spacing: 0.2px;
        }
        
        .form-group input {
            width: 100%;
            padding: 13px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.2s ease;
            font-family: inherit;
            background: white;
            color: #0f172a;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: #ffffff;
        }
        
        .form-group input::placeholder {
            color: #94a3b8;
        }
        
        .error-message {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #991b1b;
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 13px;
            border-left: 3px solid #dc2626;
            font-weight: 500;
        }
        
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 8px;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.25);
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.35);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .login-footer {
            padding: 20px 40px 24px;
            text-align: center;
            color: #94a3b8;
            font-size: 12px;
            font-weight: 500;
            border-top: 1px solid #f1f5f9;
        }
        
        .login-footer strong {
            color: #475569;
        }
        
        @media (max-width: 480px) {
            .login-container {
                border-radius: 16px;
            }
            .login-header {
                padding: 32px 24px 24px;
            }
            .login-body {
                padding: 24px;
            }
            .login-footer {
                padding: 16px 24px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo-container">
                <img src="<?php echo asset('logo-brygad-erp.png'); ?>" alt="BRYGAD ERP">
            </div>
            <h1>System zarządzania firmą budowlaną</h1>
            <p>Zaloguj się do panelu</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
                <div class="error-message"><?php echo e($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="login">Nazwa użytkownika</label>
                    <input type="text" id="login" name="login" placeholder="Wpisz swoją nazwę użytkownika" value="<?php echo e($_POST['login'] ?? ''); ?>" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Hasło</label>
                    <input type="password" id="password" name="password" placeholder="Wpisz swoje hasło" required>
                </div>
                
                <button type="submit" class="btn-login">Zaloguj się</button>
            </form>
        </div>
        
        <div class="login-footer">
            <strong><?php echo e(APP_NAME); ?></strong> v<?php echo e(APP_VERSION); ?>
        </div>
    </div>
</body>
</html>
