<?php
/**
 * BRYGAD ERP v3.0 - Dodawanie Rozliczenia
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success = false;

// Pobierz pracowników
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
    $workers = $stmt->fetchAll();
} catch (PDOException $e) {
    $workers = [];
}

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $workerId = (int)($_POST['worker_id'] ?? 0);
    $type = trim($_POST['type'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $date = trim($_POST['date'] ?? '');
    // input[type=month] zwraca YYYY-MM; DB wymaga DATE → dodajemy -01
    $periodRaw = trim($_POST['period'] ?? '');
    $period = !empty($periodRaw) ? $periodRaw . '-01' : '';
    $description = trim($_POST['description'] ?? '');
    $advanceKind = strtolower(trim($_POST['advance_kind'] ?? ''));
    if ($advanceKind === 'business') {
        $advanceKind = 'company';
    }
    
    // Walidacja
    if ($workerId <= 0) {
        $errors[] = 'Wybierz pracownika.';
    }
    if (empty($type) || !in_array($type, ['payout', 'advance', 'reimbursement', 'bonus', 'correction'])) {
        $errors[] = 'Wybierz typ rozliczenia.';
    }
    if (empty($amount) || !is_numeric($amount) || $amount <= 0) {
        $errors[] = 'Kwota musi być liczbą większą od 0.';
    }
    if (empty($date)) {
        $errors[] = 'Data jest wymagana.';
    }
    if (empty($period)) {
        $errors[] = 'Okres rozliczeniowy jest wymagany.';
    }
    if ($type === 'advance' && empty($advanceKind)) {
        $errors[] = 'Dla zaliczki musisz wybrać rodzaj (prywatna/firmowa).';
    }
    if ($type === 'advance' && !empty($advanceKind) && !in_array($advanceKind, ['private', 'company'], true)) {
        $errors[] = 'Nieprawidłowy rodzaj zaliczki.';
    }
    
    // Dodaj rozliczenie
    if (empty($errors)) {
        try {
            $createdBy = $_SESSION['user_id'] ?? null;
            
            $stmt = $pdo->prepare("
                INSERT INTO settlements 
                (worker_id, type, advance_kind, amount, date, period, description, created_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $workerId,
                $type,
                $type === 'advance' ? $advanceKind : null,
                $amount,
                $date,
                $period,
                $description,
                $createdBy
            ]);
            
            $settlementId = $pdo->lastInsertId();
            
            logEvent("Dodano rozliczenie: typ $type, pracownik ID $workerId, kwota $amount PLN", 'INFO');
            
            $success = true;
            header("Location: " . url('finanse.rozliczenia'));
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Błąd dodawania rozliczenia. Spróbuj ponownie.';
            logEvent("Błąd dodawania rozliczenia: " . $e->getMessage(), 'ERROR');
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
    <title><?php echo e(APP_NAME); ?> - Dodaj Rozliczenie</title>
    <style>

        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --bg-card:           #ffffff;
            --border:            #e5e7eb;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
            --success:           #22c55e;
            --danger:            #ef4444;
            --warning:           #eab308;
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
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        /* Header - z include */
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
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
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
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border 0.2s;
            font-family: inherit;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
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
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
        #advance_kind_group {
            display: none;
        }
    </style>
    <script>
        function toggleAdvanceKind() {
            const type = document.getElementById('type').value;
            const advanceGroup = document.getElementById('advance_kind_group');
            const advanceSelect = document.getElementById('advance_kind');
            
            if (type === 'advance') {
                advanceGroup.style.display = 'block';
                advanceSelect.required = true;
            } else {
                advanceGroup.style.display = 'none';
                advanceSelect.required = false;
                advanceSelect.value = '';
            }
        }
    </script>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
                <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    <a href="<?php echo url('finanse.rozliczenia'); ?>">Rozliczenia</a> /
                    Dodaj Rozliczenie
                </div>
                <h1>Dodaj Rozliczenie</h1>
                <p>Nowe rozliczenie</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.rozliczenia'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        
        <div class="card">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Sukces!</strong> Rozliczenie zostało dodane.
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
            
            <?php if (!$success): ?>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                Pracownik <span class="required">*</span>
                            </label>
                            <select name="worker_id" required>
                                <option value="">Wybierz pracownika</option>
                                <?php foreach ($workers as $worker): ?>
                                    <option value="<?php echo $worker['id']; ?>" 
                                            <?php echo (($_POST['worker_id'] ?? '') == $worker['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                Typ Rozliczenia <span class="required">*</span>
                            </label>
                            <select name="type" id="type" onchange="toggleAdvanceKind()" required>
                                <option value="">Wybierz typ</option>
                                <option value="payout" <?php echo (($_POST['type'] ?? '') === 'payout') ? 'selected' : ''; ?>>Wypłata</option>
                                <option value="advance" <?php echo (($_POST['type'] ?? '') === 'advance') ? 'selected' : ''; ?>>Zaliczka</option>
                                <option value="reimbursement" <?php echo (($_POST['type'] ?? '') === 'reimbursement') ? 'selected' : ''; ?>>Zwrot kosztów</option>
                                <option value="bonus" <?php echo (($_POST['type'] ?? '') === 'bonus') ? 'selected' : ''; ?>>Premia</option>
                                <option value="correction" <?php echo (($_POST['type'] ?? '') === 'correction') ? 'selected' : ''; ?>>Korekta</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group" id="advance_kind_group">
                        <label>
                            Rodzaj Zaliczki <span class="required">*</span>
                        </label>
                        <select name="advance_kind" id="advance_kind">
                            <option value="">Wybierz rodzaj</option>
                            <option value="private" <?php echo (($_POST['advance_kind'] ?? '') === 'private') ? 'selected' : ''; ?>>Prywatna</option>
                            <option value="company" <?php echo (($_POST['advance_kind'] ?? '') === 'company') ? 'selected' : ''; ?>>Firmowa</option>
                        </select>
                        <div class="help-text">Tylko dla zaliczek</div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                Kwota (PLN) <span class="required">*</span>
                            </label>
                            <input type="number" 
                                   name="amount" 
                                   step="0.01" 
                                   min="0.01"
                                   value="<?php echo e($_POST['amount'] ?? ''); ?>"
                                   placeholder="0.00"
                                   required>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                Data <span class="required">*</span>
                            </label>
                            <input type="date" 
                                   name="date" 
                                   value="<?php echo e($_POST['date'] ?? date('Y-m-d')); ?>"
                                   required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            Okres Rozliczeniowy <span class="required">*</span>
                        </label>
                        <input type="month" 
                               name="period" 
                               value="<?php echo e($_POST['period'] ?? date('Y-m')); ?>"
                               required>
                        <div class="help-text">Miesiąc, którego dotyczy rozliczenie</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Opis</label>
                        <textarea name="description" placeholder="Opcjonalny opis rozliczenia..."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            Dodaj Rozliczenie
                        </button>
                        <a href="<?php echo url('finanse.rozliczenia'); ?>" class="btn btn-secondary">
                            Anuluj
                        </a>
                    </div>
                </form>
                
                <script>
                    // Sprawdź przy ładowaniu strony
                    toggleAdvanceKind();
                </script>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
