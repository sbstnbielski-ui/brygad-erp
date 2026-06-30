<?php
/**
 * BRYGAD ERP v3.0 - Edycja Pracownika
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może edytować pracowników

$pdo = getDbConnection();
$errors = [];
$success = false;

// Pobierz ID pracownika
$workerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($workerId <= 0) {
    header('Location: ' . url('hr.workers'));
    exit;
}

// Pobierz dane pracownika
try {
    $stmt = $pdo->prepare("
        SELECT 
            w.*,
            u.id as user_id,
            u.login as user_login
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

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $workerType = $_POST['worker_type'] ?? 'permanent';
    $notes = trim($_POST['notes'] ?? '');
    
    // Walidacja
    if (empty($firstName)) {
        $errors[] = 'Imię jest wymagane.';
    }
    if (empty($lastName)) {
        $errors[] = 'Nazwisko jest wymagane.';
    }
    
    // Sprawdź czy telefon już istnieje (pomijając tego pracownika)
    if (!empty($phone) && empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM workers WHERE phone = ? AND id != ?");
            $stmt->execute([$phone, $workerId]);
            if ($stmt->fetch()) {
                $errors[] = 'Pracownik z tym numerem telefonu już istnieje.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Błąd sprawdzania telefonu.';
            logEvent("Błąd sprawdzania telefonu: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // Zaktualizuj pracownika
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE workers 
                SET first_name = ?,
                    last_name = ?,
                    phone = ?,
                    email = ?,
                    worker_type = ?,
                    notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $firstName,
                $lastName,
                $phone ?: null,
                $email ?: null,
                $workerType,
                $notes ?: null,
                $workerId
            ]);
            
            logEvent("Zaktualizowano pracownika: $firstName $lastName (ID: $workerId)", 'INFO');
            
            $success = true;
            
            // Odśwież dane pracownika
            $worker['first_name'] = $firstName;
            $worker['last_name'] = $lastName;
            $worker['phone'] = $phone;
            $worker['email'] = $email;
            $worker['worker_type'] = $workerType;
            $worker['notes'] = $notes;
            
            // Przekieruj po 2 sekundach
            header("Refresh: 2; url=" . url('hr.workers'));
        } catch (PDOException $e) {
            $errors[] = 'Błąd aktualizacji pracownika. Spróbuj ponownie.';
            logEvent("Błąd aktualizacji pracownika ID $workerId: " . $e->getMessage(), 'ERROR');
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
    <title><?php echo e(APP_NAME); ?> - Edytuj Pracownika</title>
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
            background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px;
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
        .status-badge {
            display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
        }
        .status-active  { background: rgba(34,197,94,0.2); color: #86efac; border: 1px solid rgba(34,197,94,0.3); }
        .status-inactive{ background: rgba(239,68,68,0.2); color: #fca5a5; border: 1px solid rgba(239,68,68,0.3); }

        /* Card */
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); padding: 32px; margin-bottom: 16px; }

        /* Alert */
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
        .alert-error   { background: #fef2f2; border-color: #dc2626; color: #991b1b; }
        .alert-success { background: #f0fdf4; border-color: #16a34a; color: #166534; }
        .alert ul { margin: 8px 0 0 18px; }

        /* Info box */
        .info-box {
            background: #f0f9ff; border-left: 3px solid #0284c7;
            padding: 14px 18px; margin-bottom: 22px; border-radius: 0 8px 8px 0; font-size: 13px;
        }
        .info-box strong { display: block; margin-bottom: 6px; color: #0369a1; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; }
        .info-row { display: flex; gap: 8px; margin-bottom: 4px; }
        .info-label { font-weight: 600; color: var(--text-muted); min-width: 120px; }

        /* Section */
        .section-title {
            font-size: 13px; font-weight: 700; color: var(--text-main);
            margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid #f3f4f6;
        }
        .section-desc { font-size: 13px; color: var(--text-muted); margin-bottom: 14px; }

        /* Account / status boxes */
        .box-info    { background: #eff6ff; border-left: 3px solid #3b82f6; padding: 14px 16px; border-radius: 0 8px 8px 0; margin-bottom: 14px; font-size: 13px; }
        .box-warning { background: #fffbeb; border-left: 3px solid #f59e0b; padding: 14px 16px; border-radius: 0 8px 8px 0; margin-bottom: 14px; font-size: 13px; color: #92400e; }
        .box-danger  { background: #fef2f2; border-left: 3px solid #dc2626; padding: 14px 16px; border-radius: 0 8px 8px 0; margin-bottom: 14px; font-size: 13px; color: #991b1b; }

        /* Form */
        .form-section-title {
            font-size: 11px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.6px;
            margin: 24px 0 14px; padding-bottom: 6px; border-bottom: 1px solid #f3f4f6;
        }
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
        .form-group textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 2px rgba(102,126,234,0.1); }
        .form-group textarea { resize: vertical; min-height: 90px; }
        .form-group .help-text { font-size: 12px; color: var(--text-muted); margin-top: 5px; }

        /* Buttons */
        .form-actions { display: flex; gap: 10px; margin-top: 22px; padding-top: 18px; border-top: 1px solid #f3f4f6; }
        .btn {
            padding: 9px 22px; border-radius: 7px; font-weight: 600; font-size: 13px;
            cursor: pointer; border: 1px solid transparent; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; font-family: inherit;
        }
        .btn-primary   { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: var(--border); }
        .btn-secondary:hover { background: #f9fafb; }
        .btn-info    { background: #0ea5e9; color: white; }
        .btn-info:hover { background: #0284c7; color: white; }
        .btn-warning { background: #f59e0b; color: #1f2937; }
        .btn-warning:hover { background: #d97706; }
        .btn-danger  { background: #dc2626; color: white; }
        .btn-danger:hover  { background: #b91c1c; color: white; }
        .btn-success { background: #16a34a; color: white; }
        .btn-success:hover { background: #15803d; color: white; }
        .btn-actions { display: flex; gap: 8px; flex-wrap: wrap; }
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
                    Edytuj Pracownika
                </div>
                <h1>
                    <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                    <?php if ($worker['is_active']): ?>
                        <span class="status-badge status-active">Aktywny</span>
                    <?php else: ?>
                        <span class="status-badge status-inactive">Nieaktywny</span>
                    <?php endif; ?>
                </h1>
                <p>Edytuj dane pracownika</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('hr.workers'); ?>" class="btn-hero-secondary">← Wróć do listy</a>
            </div>
        </div>
        
        <div class="card">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Sukces!</strong> Dane pracownika zostały zaktualizowane.<br>
                    Za chwilę zostaniesz przekierowany do listy pracowników...
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['flash_success'])): ?>
                <div class="alert alert-success">
                    <?php echo e($_SESSION['flash_success']); ?>
                    <?php unset($_SESSION['flash_success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="alert alert-error">
                    <?php echo e($_SESSION['flash_error']); ?>
                    <?php unset($_SESSION['flash_error']); ?>
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
            
            <div class="info-box">
                <strong>Informacje systemowe</strong>
                <div class="info-row"><span class="info-label">ID:</span><span><?php echo e($worker['id']); ?></span></div>
                <div class="info-row"><span class="info-label">Dodano:</span><span><?php echo formatDate($worker['created_at']); ?></span></div>
                <?php if ($worker['user_login']): ?>
                    <div class="info-row"><span class="info-label">Konto:</span><span style="font-weight:600; color:#0369a1;"><?php echo e($worker['user_login']); ?></span></div>
                <?php endif; ?>
            </div>
            
            <?php if (!$success): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label>
                            Imię <span class="required">*</span>
                        </label>
                        <input type="text" 
                               name="first_name" 
                               value="<?php echo e($_POST['first_name'] ?? $worker['first_name']); ?>"
                               required
                               autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            Nazwisko <span class="required">*</span>
                        </label>
                        <input type="text" 
                               name="last_name" 
                               value="<?php echo e($_POST['last_name'] ?? $worker['last_name']); ?>"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label>Telefon</label>
                        <input type="tel" 
                               name="phone" 
                               value="<?php echo e($_POST['phone'] ?? $worker['phone']); ?>"
                               placeholder="np. 123456789">
                        <div class="help-text">Opcjonalnie. Jeśli podany, musi być unikalny.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" 
                               name="email" 
                               value="<?php echo e($_POST['email'] ?? $worker['email']); ?>"
                               placeholder="np. jan.kowalski@example.com">
                        <div class="help-text">Opcjonalnie.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Typ pracownika <span class="required">*</span></label>
                        <select name="worker_type" required>
                            <?php 
                            $currentType = $_POST['worker_type'] ?? $worker['worker_type'];
                            $types = [
                                'permanent' => 'Stały',
                                'temporary' => 'Tymczasowy',
                                'contractor' => 'Podwykonawca'
                            ];
                            foreach ($types as $value => $label):
                            ?>
                                <option value="<?php echo $value; ?>" 
                                        <?php echo $currentType === $value ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Określa charakter współpracy.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notatki wewnętrzne</label>
                        <textarea name="notes" 
                                  rows="5"
                                  placeholder="Prywatne notatki (ustalenia, charakter pracy, ograniczenia...)"><?php echo e($_POST['notes'] ?? $worker['notes']); ?></textarea>
                        <div class="help-text">Pole prywatne - widoczne tylko dla administratora.</div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            Zapisz Zmiany
                        </button>
                        <a href="<?php echo url('hr.workers'); ?>" class="btn btn-secondary">
                            Anuluj
                        </a>
                    </div>
                </form>
            <?php endif; ?>
                
        </div><!-- /card form -->

        <div class="card">
            <div class="section-title">Konto Użytkownika</div>
            <div class="section-desc">Zarządzaj dostępem pracownika do systemu (login i hasło).</div>

            <?php if ($worker['user_id']): ?>
                <div class="box-info">
                    <strong>Login: <?php echo e($worker['user_login']); ?></strong>
                    Pracownik ma dostęp do systemu i może dodawać swoje wpisy pracy oraz wydatki.
                </div>
                <div class="btn-actions">
                    <a href="/api/workers/manage-account.php?id=<?php echo $workerId; ?>&action=edit"
                       class="btn btn-info">Zmień Hasło</a>
                    <button onclick="confirmDeleteAccount()" class="btn btn-danger">Usuń Konto</button>
                </div>
            <?php else: ?>
                <div class="box-warning">
                    Pracownik nie ma jeszcze konta użytkownika. Możesz utworzyć dla niego dostęp do systemu.
                </div>
                <a href="/api/workers/manage-account.php?id=<?php echo $workerId; ?>&action=create"
                   class="btn btn-success">+ Utwórz Konto Użytkownika</a>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-title">Stawki Pracownika</div>
            <div class="section-desc">Zarządzaj stawkami roboczymi (bazowymi i projektowymi) oraz przeglądaj historię zmian.</div>
            <a href="<?php echo url('hr.workers.rates', ['id' => $workerId]); ?>" class="btn btn-info">Zarządzaj Stawkami</a>
        </div>

        <div class="card">
            <div class="section-title">Dokumenty HR</div>
            <div class="section-desc">Umowy, badania lekarskie, szkolenia BHP, uprawnienia UDT i inne dokumenty pracownika.</div>
            <a href="<?php echo url('hr.workers.documents', ['worker_id' => $workerId]); ?>" class="btn btn-success">Dokumenty i Alerty</a>
        </div>

        <div class="card">
            <div class="section-title" style="color: #dc2626;">Zarządzanie Statusem</div>
            <div class="section-desc">
                <?php if ($worker['is_active']): ?>
                    Pracownik jest obecnie <strong>aktywny</strong>. Możesz go dezaktywować, jeśli już nie pracuje w firmie.
                    <strong>Ważne:</strong> Dezaktywacja NIE usuwa danych historycznych.
                <?php else: ?>
                    Pracownik jest <strong>nieaktywny</strong>. Możesz go ponownie aktywować.
                <?php endif; ?>
            </div>
            <?php if ($worker['is_active']): ?>
                <button onclick="confirmDeactivate()" class="btn btn-warning">Dezaktywuj Pracownika</button>
            <?php else: ?>
                <button onclick="confirmActivate()" class="btn btn-info">Aktywuj Pracownika</button>
            <?php endif; ?>
        </div><!-- /card status -->
    </div><!-- /container -->
    
    <script>
        function confirmDeactivate() {
            if (confirm('Czy na pewno chcesz dezaktywować tego pracownika?\n\n<?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>\n\nPracownik nie zostanie usunięty, tylko oznaczony jako nieaktywny.')) {
                window.location.href = '/api/workers/toggle-status.php?id=<?php echo $workerId; ?>&action=deactivate&return=edit';
            }
        }
        
        function confirmActivate() {
            if (confirm('Czy na pewno chcesz ponownie aktywować tego pracownika?\n\n<?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>')) {
                window.location.href = '/api/workers/toggle-status.php?id=<?php echo $workerId; ?>&action=activate&return=edit';
            }
        }
        
        function confirmDeleteAccount() {
            if (confirm('Czy na pewno chcesz usunąć konto użytkownika?\n\n<?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>\n\nPracownik straci dostęp do systemu, ale jego dane historyczne (wpisy pracy, wydatki) pozostaną.')) {
                window.location.href = '/api/workers/manage-account.php?id=<?php echo $workerId; ?>&action=delete';
            }
        }
    </script>
</body>
</html>

