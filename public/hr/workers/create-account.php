<?php
/**
 * BRYGAD ERP - Tworzenie konta użytkownika dla istniejącego pracownika
 *
 * Obsługuje case: pracownik istnieje w tabeli `workers`, ale nie ma konta w `users`.
 * Np. MARCIN NAPIERAŁA (worker_id=17).
 *
 * Błędy biznesowe:
 *  - pracownik nie istnieje w `workers`
 *  - pracownik już ma konto
 *  - login zajęty
 *  - hasło za krótkie
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors  = [];
$success = false;

// -------------------------------------------------------
// Identyfikacja pracownika
// -------------------------------------------------------
$workerId = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
if ($workerId <= 0) {
    redirect(url('hr.workers'));
}

// Pobierz dane pracownika + sprawdź czy ma już konto
try {
    $stmt = $pdo->prepare("
        SELECT w.id, w.first_name, w.last_name, w.is_active,
               u.id AS user_id, u.login AS existing_login
        FROM workers w
        LEFT JOIN users u ON u.worker_id = w.id
        WHERE w.id = ?
        LIMIT 1
    ");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
} catch (PDOException $e) {
    error_log("create-account.php: " . $e->getMessage());
    die('Błąd bazy danych.');
}

if (!$worker) {
    // Pracownik nie istnieje w tabeli workers
    $errors[] = "Pracownik o ID $workerId nie istnieje w systemie.";
}

$workerName = $worker
    ? trim($worker['first_name'] . ' ' . $worker['last_name'])
    : "Nieznany";

// -------------------------------------------------------
// Obsługa POST
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {

    $login    = trim($_POST['login']    ?? '');
    $password = trim($_POST['password'] ?? '');

    // --- Walidacja ---

    // 1. Pracownik już ma konto?
    if ($worker['user_id']) {
        $errors[] = "Pracownik $workerName już posiada konto (login: {$worker['existing_login']}).";
    }

    // 2. Login
    if (empty($errors)) {
        if (empty($login)) {
            $errors[] = 'Login jest wymagany.';
        } elseif (strlen($login) < 3) {
            $errors[] = 'Login musi mieć minimum 3 znaki.';
        } elseif (!preg_match('/^[a-zA-Z0-9._\-]+$/', $login)) {
            $errors[] = 'Login może zawierać tylko litery, cyfry, kropki, myślniki i podkreślniki.';
        }
    }

    // 3. Hasło
    if (empty($errors)) {
        if (empty($password)) {
            $errors[] = 'Hasło jest wymagane.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Hasło musi mieć minimum 6 znaków.';
        }
    }

    // 4. Unikalność loginu
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
        $stmt->execute([$login]);
        if ($stmt->fetch()) {
            $errors[] = "Login \"$login\" jest już zajęty. Wybierz inny.";
        }
    }

    // --- Zapis ---
    if (empty($errors)) {
        try {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare("
                INSERT INTO users (login, password_hash, role, worker_id, is_active, created_at)
                VALUES (?, ?, 'worker', ?, 1, NOW())
            ");
            $stmt->execute([$login, $passwordHash, $workerId]);

            logEvent(
                "Utworzono konto '$login' dla istniejącego pracownika: $workerName (worker_id=$workerId)",
                'INFO'
            );

            $success = true;

            // Przekieruj do profilu pracownika z komunikatem sukcesu
            header("Location: " . url('hr.workers.profile', ['id' => $workerId]) . "&account_created=1");
            exit;

        } catch (PDOException $e) {
            error_log("create-account.php PDO: " . $e->getMessage());
            $errors[] = 'Błąd bazy danych podczas tworzenia konta. Spróbuj ponownie.';
            logEvent(
                "BŁĄD tworzenia konta dla worker_id=$workerId: " . $e->getMessage(),
                'ERROR'
            );
        }
    }
}

$userName    = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Nadaj dostęp do systemu</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --danger: #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px; }
        .container { max-width: 800px; margin: 0 auto; padding: 25px; }

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
        .hero h1 { margin: 0 0 4px; font-size: 24px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; align-self: center; }
        .btn-hero-secondary {
            background: rgba(255,255,255,0.1); color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2); font-weight: 600;
            padding: 8px 16px; border-radius: 8px; text-decoration: none;
            font-size: 13px; display: inline-flex; align-items: center; transition: all 0.2s;
        }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        /* Worker badge */
        .worker-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: #eff6ff; border: 1px solid #bfdbfe;
            border-radius: 8px; padding: 10px 16px; margin-bottom: 20px;
            font-size: 13px; color: #1d4ed8; font-weight: 600;
        }

        /* Card */
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); padding: 28px; }

        /* Alerts */
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
        .alert-error   { background: #fef2f2; border-color: #dc2626; color: #991b1b; }
        .alert-warning { background: #fffbeb; border-color: #f59e0b; color: #92400e; }
        .alert ul { margin: 8px 0 0 18px; }

        /* Form */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; color: var(--text-main); margin-bottom: 6px; font-size: 13px; }
        .form-group .required { color: var(--danger); }
        .form-group input {
            width: 100%; padding: 9px 12px;
            border: 1px solid var(--border); border-radius: 6px;
            font-size: 13px; font-family: inherit; color: var(--text-main);
            background: white; transition: border-color 0.15s;
        }
        .form-group input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 2px rgba(102,126,234,0.1); }
        .form-group .help-text { font-size: 12px; color: var(--text-muted); margin-top: 5px; }
        .divider { border-top: 1px solid #f3f4f6; margin: 20px 0; }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #f3f4f6; }
        .btn {
            padding: 9px 22px; border-radius: 7px; font-weight: 600; font-size: 13px;
            cursor: pointer; border: 1px solid transparent; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; font-family: inherit;
        }
        .btn-primary   { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: var(--border); }
        .btn-secondary:hover { background: #f9fafb; }
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
                    <a href="<?php echo url('hr.workers.profile', ['id' => $workerId]); ?>"><?php echo e($workerName); ?></a> /
                    Nadaj dostęp
                </div>
                <h1>Nadaj dostęp do systemu</h1>
                <p>Utwórz konto logowania dla pracownika, który jeszcze go nie posiada.</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('hr.workers.profile', ['id' => $workerId]); ?>" class="btn-hero-secondary">← Wróć do profilu</a>
            </div>
        </div>

        <?php if ($worker && $worker['user_id']): ?>
            <!-- Pracownik już ma konto - ostrzeżenie -->
            <div class="alert alert-warning">
                <strong>Ten pracownik posiada już konto!</strong><br>
                Login: <strong><?php echo e($worker['existing_login']); ?></strong><br>
                Jeśli chcesz zmienić hasło lub login, użyj panelu edycji użytkownika.
            </div>
            <a href="<?php echo url('hr.workers.profile', ['id' => $workerId]); ?>" class="btn btn-secondary">
                ← Powrót do profilu
            </a>

        <?php elseif (!$worker): ?>
            <!-- Pracownik nie istnieje -->
            <div class="alert alert-error">
                <?php foreach ($errors as $e_msg): ?>
                    <p><?php echo e($e_msg); ?></p>
                <?php endforeach; ?>
            </div>
            <a href="<?php echo url('hr.workers'); ?>" class="btn btn-secondary">
                ← Powrót do listy pracowników
            </a>

        <?php else: ?>
            <!-- Formularz tworzenia konta -->
            <div class="worker-badge">
                👷 <?php echo e($workerName); ?> (ID: <?php echo $workerId; ?>)
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Popraw błędy:</strong>
                    <ul>
                        <?php foreach ($errors as $e_msg): ?>
                            <li><?php echo e($e_msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card">
                <form method="POST" action="<?php echo url('hr.workers.create-account', ['worker_id' => $workerId]); ?>">

                    <div class="form-group">
                        <label>Login <span class="required">*</span></label>
                        <input
                            type="text"
                            name="login"
                            value="<?php echo e($_POST['login'] ?? ''); ?>"
                            placeholder="np. marcin.napierala"
                            autofocus
                            required>
                        <div class="help-text">Minimum 3 znaki. Dozwolone: litery, cyfry, kropki, myślniki, podkreślniki. Musi być unikalny.</div>
                    </div>

                    <div class="divider"></div>

                    <div class="form-group">
                        <label>Hasło <span class="required">*</span></label>
                        <input
                            type="password"
                            name="password"
                            placeholder="Minimum 6 znaków"
                            required>
                        <div class="help-text">Minimum 6 znaków. Pracownik będzie mógł je zmienić po zalogowaniu.</div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            Utwórz konto
                        </button>
                        <a href="<?php echo url('hr.workers.profile', ['id' => $workerId]); ?>" class="btn btn-secondary">
                            Anuluj
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>

