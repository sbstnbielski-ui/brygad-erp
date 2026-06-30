<?php
/**
 * BRYGAD ERP v3.0 - Edycja Rozliczenia
 * Umożliwia edycję wypłat, zaliczek, zwrotów kosztów itp.
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może edytować rozliczenia

$pdo = getDbConnection();
$errors = [];
$success = false;

// Pobierz ID rozliczenia
$settlementId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($settlementId <= 0) {
    header('Location: ' . url('finanse.rozliczenia'));
    exit;
}

// Pobierz dane rozliczenia
try {
    $stmt = $pdo->prepare("
        SELECT s.*, w.first_name, w.last_name
        FROM settlements s
        JOIN workers w ON s.worker_id = w.id
        WHERE s.id = ?
    ");
    $stmt->execute([$settlementId]);
    $settlement = $stmt->fetch();
    
    if (!$settlement) {
        header('Location: ' . url('finanse.rozliczenia'));
        exit;
    }
} catch (PDOException $e) {
    die("Błąd: " . $e->getMessage());
}

$workerName = $settlement['first_name'] . ' ' . $settlement['last_name'];

// Obsługa POST - aktualizacja rozliczenia
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    
    // Aktualizuj rozliczenie
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE settlements 
                SET type = ?, 
                    advance_kind = ?, 
                    amount = ?, 
                    date = ?, 
                    period = ?, 
                    description = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $type,
                $type === 'advance' ? $advanceKind : null,
                $amount,
                $date,
                $period,
                $description,
                $settlementId
            ]);
            
            logEvent("Zaktualizowano rozliczenie ID $settlementId: typ $type, pracownik {$workerName}, kwota $amount PLN", 'INFO');
            
            $success = true;
            header("Location: " . url('finanse.rozliczenia') . "?success=edit");
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Błąd aktualizacji rozliczenia. Spróbuj ponownie.';
            logEvent("Błąd aktualizacji rozliczenia ID $settlementId: " . $e->getMessage(), 'ERROR');
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
    <title><?php echo e(APP_NAME); ?> - Edycja Rozliczenia</title>
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

        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 13px;
            color: #374151;
        }
        input[type="text"],
        input[type="number"],
        input[type="date"],
        input[type="month"],
        select,
        textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border 0.2s;
        }
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            border-left: 3px solid;
        }
        .alert-error {
            background: #fee2e2;
            border-color: #dc2626;
            color: #991b1b;
        }
        .alert ul {
            margin: 8px 0 0 20px;
        }
        .hint {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 4px;
        }
        .info-banner {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #1e40af;
            font-size: 14px;
        }
        .info-banner strong {
            display: block;
            margin-bottom: 5px;
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
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    <a href="<?php echo url('finanse.rozliczenia'); ?>">Rozliczenia</a> /
                    Edytuj Rozliczenie
                </div>
                <h1>Edytuj Rozliczenie</h1>
                <p>Edycja rozliczenia</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.rozliczenia'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Błędy:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="info-banner">
            <strong>⚠️ Uwaga:</strong>
            Edycja rozliczenia zmieni dane w systemie. Upewnij się, że wprowadzane zmiany są poprawne.
        </div>
        
        <div class="card">
            <form method="POST">
                <div class="form-group">
                    <label>Typ rozliczenia *</label>
                    <select name="type" required>
                        <option value="">-- Wybierz --</option>
                        <option value="payout" <?php echo ($settlement['type'] === 'payout') ? 'selected' : ''; ?>>Wypłata</option>
                        <option value="advance" <?php echo ($settlement['type'] === 'advance') ? 'selected' : ''; ?>>Zaliczka</option>
                        <option value="reimbursement" <?php echo ($settlement['type'] === 'reimbursement') ? 'selected' : ''; ?>>Zwrot kosztów</option>
                        <option value="bonus" <?php echo ($settlement['type'] === 'bonus') ? 'selected' : ''; ?>>Premia</option>
                        <option value="correction" <?php echo ($settlement['type'] === 'correction') ? 'selected' : ''; ?>>Korekta</option>
                    </select>
                    <div class="hint">Typ transakcji finansowej</div>
                </div>
                
                <div class="form-group" id="advanceKindGroup" style="display: <?php echo ($settlement['type'] === 'advance') ? 'block' : 'none'; ?>;">
                    <label>Rodzaj zaliczki *</label>
                    <select name="advance_kind">
                        <option value="">-- Wybierz --</option>
                        <option value="private" <?php echo ($settlement['advance_kind'] === 'private') ? 'selected' : ''; ?>>Prywatna (do odliczenia od wypłaty)</option>
                        <option value="company" <?php echo ($settlement['advance_kind'] === 'company' || $settlement['advance_kind'] === 'business') ? 'selected' : ''; ?>>Firmowa (koszty przedsiębiorstwa)</option>
                    </select>
                    <div class="hint">Określ czy zaliczka jest prywatna czy firmowa</div>
                </div>
                
                <div class="form-group">
                    <label>Kwota (PLN) *</label>
                    <input type="number" name="amount" step="0.01" min="0" required 
                           value="<?php echo e($settlement['amount']); ?>"
                           placeholder="np. 5000.00">
                    <div class="hint">Kwota brutto rozliczenia</div>
                </div>
                
                <div class="form-group">
                    <label>Data wypłaty *</label>
                    <input type="date" name="date" value="<?php echo e($settlement['date']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Okres rozliczeniowy *</label>
                    <?php
                        $periodForInput = (!empty($settlement['period']) && $settlement['period'] !== '0000-00-00')
                            ? date('Y-m', strtotime($settlement['period']))
                            : date('Y-m', strtotime($settlement['date']));
                    ?>
                    <input type="month" name="period" value="<?php echo e($periodForInput); ?>" required>
                    <div class="hint">Za jaki miesiąc dokonywane jest rozliczenie</div>
                </div>
                
                <div class="form-group">
                    <label>Opis</label>
                    <textarea name="description" placeholder="Opcjonalny opis rozliczenia..."><?php echo e($settlement['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                    <a href="<?php echo url('finanse.rozliczenia'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Pokaż/ukryj pole "Rodzaj zaliczki" w zależności od typu
        document.querySelector('select[name="type"]').addEventListener('change', function() {
            const advanceKindGroup = document.getElementById('advanceKindGroup');
            if (this.value === 'advance') {
                advanceKindGroup.style.display = 'block';
                advanceKindGroup.querySelector('select').required = true;
            } else {
                advanceKindGroup.style.display = 'none';
                advanceKindGroup.querySelector('select').required = false;
            }
        });
    </script>
</body>
</html>
