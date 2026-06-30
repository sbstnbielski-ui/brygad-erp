<?php
/**
 * BRYGAD ERP v3.0 - Dodawanie Pracownika
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może dodawać pracowników

$pdo = getDbConnection();
$errors = [];
$success = false;

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $workerType = $_POST['worker_type'] ?? 'permanent';
    $notes = trim($_POST['notes'] ?? '');
    $createAccount = isset($_POST['create_account']) && $_POST['create_account'] === '1';
    $login = trim($_POST['login'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Walidacja
    if (empty($firstName)) {
        $errors[] = 'Imię jest wymagane.';
    }
    if (empty($lastName)) {
        $errors[] = 'Nazwisko jest wymagane.';
    }
    
    // Walidacja konta użytkownika
    if ($createAccount) {
        if (empty($login)) {
            $errors[] = 'Login jest wymagany, jeśli tworzysz konto użytkownika.';
        } elseif (strlen($login) < 3) {
            $errors[] = 'Login musi mieć minimum 3 znaki.';
        }
        
        if (empty($password)) {
            $errors[] = 'Hasło jest wymagane, jeśli tworzysz konto użytkownika.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Hasło musi mieć minimum 6 znaków.';
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
    
    // Sprawdź czy telefon już istnieje (jeśli podany)
    if (!empty($phone) && empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM workers WHERE phone = ?");
            $stmt->execute([$phone]);
            if ($stmt->fetch()) {
                $errors[] = 'Pracownik z tym numerem telefonu już istnieje.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Błąd sprawdzania telefonu.';
            logEvent("Błąd sprawdzania telefonu: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // Dodaj pracownika
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // 1. Dodaj pracownika
            $stmt = $pdo->prepare("
                INSERT INTO workers (first_name, last_name, phone, email, worker_type, notes, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
            ");
            $stmt->execute([
                $firstName, 
                $lastName, 
                $phone ?: null, 
                $email ?: null, 
                $workerType,
                $notes ?: null
            ]);
            
            $workerId = $pdo->lastInsertId();
            
            logEvent("Dodano pracownika: $firstName $lastName (ID: $workerId)", 'INFO');
            
            // 2. Utwórz konto użytkownika (jeśli zaznaczone)
            if ($createAccount) {
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (login, password_hash, role, worker_id, is_active, created_at)
                    VALUES (?, ?, 'worker', ?, 1, NOW())
                ");
                $stmt->execute([$login, $passwordHash, $workerId]);
                
                logEvent("Utworzono konto użytkownika: $login dla pracownika ID $workerId", 'INFO');
            }
            
            $pdo->commit();
            $success = true;
            
            // Przekieruj do profilu nowo utworzonego pracownika
            header("Location: " . url('hr.workers.profile', ['id' => $workerId]));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Błąd dodawania pracownika. Spróbuj ponownie.';
            logEvent("Błąd dodawania pracownika: " . $e->getMessage(), 'ERROR');
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
    <title><?php echo e(APP_NAME); ?> - Dodaj Pracownika</title>
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
            background: var(--bg-body);
            color: var(--text-main);
            line-height: 1.5;
            padding-bottom: 40px;
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

        /* Card */
        .card {
            background: white; border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07); padding: 32px;
        }

        /* Alert */
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid; }
        .alert-error   { background: #fef2f2; border-color: #dc2626; color: #991b1b; }
        .alert-success { background: #f0fdf4; border-color: #16a34a; color: #166534; }
        .alert ul { margin: 8px 0 0 18px; }

        /* Form */
        .form-section-title {
            font-size: 11px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.6px;
            margin: 24px 0 14px; padding-bottom: 6px; border-bottom: 1px solid #f3f4f6;
        }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: var(--text-main); }
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
        .form-group textarea:focus {
            outline: none; border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102,126,234,0.1);
        }
        .form-group textarea { resize: vertical; min-height: 90px; }
        .form-group .help-text { font-size: 12px; color: var(--text-muted); margin-top: 5px; }

        /* Sekcja konta */
        .account-section {
            margin-top: 24px; padding: 20px; background: #f8fafc;
            border: 1px solid var(--border); border-radius: 8px;
        }
        .account-section .form-group:last-child { margin-bottom: 0; }

        /* Checkbox */
        .checkbox-label {
            display: flex; align-items: center; gap: 10px; cursor: pointer;
            font-size: 14px; font-weight: 500; color: var(--text-main);
        }
        .checkbox-label input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }

        /* Buttons */
        .form-actions { display: flex; gap: 10px; margin-top: 28px; padding-top: 20px; border-top: 1px solid #f3f4f6; }
        .btn {
            padding: 9px 22px; border-radius: 7px; font-weight: 600; font-size: 13px;
            cursor: pointer; border: 1px solid transparent; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; font-family: inherit;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: var(--border); }
        .btn-secondary:hover { background: #f9fafb; border-color: #d1d5db; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('hr.workers'); ?>">Pracownicy</a> /
                    Dodaj Pracownika
                </div>
                <h1>Dodaj Pracownika</h1>
                <p>Nowy pracownik w systemie HR</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('hr.workers'); ?>" class="btn-hero-secondary">← Wróć do listy</a>
            </div>
        </div>
        
        <div class="card">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Sukces!</strong> Pracownik został dodany do systemu.<br>
                    Za chwilę zostaniesz przekierowany do listy pracowników...
                </div>
            <?php endif; ?>
            
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
            
            <?php if (!$success): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>
                            Imię <span class="required">*</span>
                        </label>
                        <input type="text" 
                               name="first_name" 
                               value="<?php echo e($_POST['first_name'] ?? ''); ?>"
                               required
                               autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            Nazwisko <span class="required">*</span>
                        </label>
                        <input type="text" 
                               name="last_name" 
                               value="<?php echo e($_POST['last_name'] ?? ''); ?>"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label>Telefon</label>
                        <input type="tel" 
                               name="phone" 
                               value="<?php echo e($_POST['phone'] ?? ''); ?>"
                               placeholder="np. 123456789">
                        <div class="help-text">Opcjonalnie. Jeśli podany, musi być unikalny.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" 
                               name="email" 
                               value="<?php echo e($_POST['email'] ?? ''); ?>"
                               placeholder="np. jan.kowalski@example.com">
                        <div class="help-text">Opcjonalnie.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Typ pracownika <span class="required">*</span></label>
                        <select name="worker_type" required>
                            <option value="permanent" <?php echo ($_POST['worker_type'] ?? 'permanent') === 'permanent' ? 'selected' : ''; ?>>
                                Stały
                            </option>
                            <option value="temporary" <?php echo ($_POST['worker_type'] ?? '') === 'temporary' ? 'selected' : ''; ?>>
                                Tymczasowy
                            </option>
                            <option value="contractor" <?php echo ($_POST['worker_type'] ?? '') === 'contractor' ? 'selected' : ''; ?>>
                                Podwykonawca
                            </option>
                        </select>
                        <div class="help-text">Określa charakter współpracy.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notatki wewnętrzne</label>
                        <textarea name="notes" 
                                  rows="4"
                                  placeholder="Prywatne notatki (ustalenia, charakter pracy, ograniczenia...)"><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                        <div class="help-text">Pole prywatne - widoczne tylko dla administratora.</div>
                    </div>
                    
                    <div class="form-section-title">Dostęp do systemu</div>
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox"
                                       name="create_account"
                                       value="1"
                                       id="createAccountCheckbox"
                                       <?php echo isset($_POST['create_account']) ? 'checked' : ''; ?>
                                       onchange="toggleAccountFields(this.checked)">
                                Utwórz konto użytkownika (dostęp do systemu)
                            </label>
                            <div class="help-text">Pracownik będzie mógł logować się do systemu i dodawać swoje wpisy pracy oraz wydatki.</div>
                        </div>

                        <div id="accountFields" style="display: none;" class="account-section">
                            <div class="form-group">
                                <label>
                                    Login <span class="required">*</span>
                                </label>
                                <input type="text" 
                                       name="login" 
                                       value="<?php echo e($_POST['login'] ?? ''); ?>"
                                       placeholder="np. jan.kowalski">
                                <div class="help-text">Minimum 3 znaki. Login musi być unikalny.</div>
                            </div>
                            
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>
                                    Hasło <span class="required">*</span>
                                </label>
                                <input type="password" 
                                       name="password" 
                                       placeholder="Minimum 6 znaków">
                                <div class="help-text">Minimum 6 znaków. Pracownik będzie mógł je później zmienić.</div>
                            </div>
                        </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            Dodaj Pracownika
                        </button>
                        <a href="<?php echo url('hr.workers'); ?>" class="btn btn-secondary">
                            Anuluj
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleAccountFields(show) {
            const accountFields = document.getElementById('accountFields');
            accountFields.style.display = show ? 'block' : 'none';
            
            // Wymagane pola tylko gdy checkbox zaznaczony
            const loginInput = document.querySelector('input[name="login"]');
            const passwordInput = document.querySelector('input[name="password"]');
            
            if (show) {
                loginInput.setAttribute('required', 'required');
                passwordInput.setAttribute('required', 'required');
            } else {
                loginInput.removeAttribute('required');
                passwordInput.removeAttribute('required');
            }
        }
        
        // Pokaż pola jeśli checkbox był zaznaczony (po błędzie walidacji)
        document.addEventListener('DOMContentLoaded', function() {
            const checkbox = document.getElementById('createAccountCheckbox');
            if (checkbox && checkbox.checked) {
                toggleAccountFields(true);
            }
        });
    </script>
</body>
</html>

