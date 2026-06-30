<?php
/**
 * BRYGAD ERP - Wypłata dla Pracownika
 * Wrapper na formularz rozliczenia z preselektowanym pracownikiem
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success = false;

// Pobierz ID pracownika z GET
$workerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($workerId <= 0) {
    header('Location: ' . url('hr.workers'));
    exit;
}

// Pobierz dane pracownika
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM workers WHERE id = ?");
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
    
    if (!$worker) {
        header('Location: ' . url('hr.workers'));
        exit;
    }
} catch (PDOException $e) {
    die("Błąd: " . $e->getMessage());
}

// Pobierz dane do panelu bocznego - domyślnie bieżący miesiąc
$current_period = date('Y-m-01');
$summary_period = $_GET['summary_period'] ?? $current_period;

// Pobierz sumy dla wybranego okresu
$summary_data = [
    'expenses' => 0,
    'work_logs' => 0,
    'settlements' => []
];

try {
    // Suma wydatków pracownika (status=approved)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM worker_expenses
        WHERE worker_id = :worker_id
            AND status = 'approved'
            AND DATE_FORMAT(date, '%Y-%m-01') = :period
    ");
    $stmt->execute(['worker_id' => $workerId, 'period' => $summary_period]);
    $summary_data['expenses'] = (float)$stmt->fetchColumn();
    
    // Suma work_logs (COALESCE(final_cost, system_cost))
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(final_cost, system_cost)), 0) as total
        FROM work_logs
        WHERE worker_id = :worker_id
            AND period = :period
            AND status IN ('approved', 'pending')
    ");
    $stmt->execute(['worker_id' => $workerId, 'period' => $summary_period]);
    $summary_data['work_logs'] = (float)$stmt->fetchColumn();
    
    // Ostatnie rozliczenia (settlements) - ostatnie 5
    $stmt = $pdo->prepare("
        SELECT 
            type,
            advance_kind,
            amount,
            date,
            period,
            description
        FROM settlements
        WHERE worker_id = :worker_id
        ORDER BY date DESC, id DESC
        LIMIT 5
    ");
    $stmt->execute(['worker_id' => $workerId]);
    $summary_data['settlements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logEvent("Błąd pobierania podsumowania dla payout: " . $e->getMessage(), 'ERROR');
}

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = trim($_POST['type'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $period = trim($_POST['period'] ?? '');
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
            
            logEvent("Dodano rozliczenie: typ $type, pracownik ID $workerId, kwota $amount PLN", 'INFO');
            
            $success = true;
            header("Location: " . url('hr.workers.profile', ['id' => $workerId]) . "&success=payout");
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Błąd dodawania rozliczenia. Spróbuj ponownie.';
            logEvent("Błąd dodawania rozliczenia: " . $e->getMessage(), 'ERROR');
        }
    }
}

$workerName = $worker['first_name'] . ' ' . $worker['last_name'];
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Wypłata dla <?php echo e($workerName); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }
        .two-column-layout {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
            align-items: start;
        }
        @media (max-width: 968px) {
            .two-column-layout {
                grid-template-columns: 1fr;
            }
        }
        .page-header {
            margin-bottom: 30px;
        }
        .page-header h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 8px;
        }
        .page-header p {
            font-size: 14px;
            color: #6b7280;
        }
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
        
        /* Panel boczny podsumowania */
        .summary-panel {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: sticky;
            top: 20px;
        }
        .summary-panel h3 {
            font-size: 16px;
            color: #111827;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
        .summary-section {
            margin-bottom: 20px;
        }
        .summary-section h4 {
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .summary-value {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }
        .summary-value.positive {
            color: #10b981;
        }
        .summary-value.negative {
            color: #ef4444;
        }
        .summary-item {
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
        }
        .summary-item:last-child {
            border-bottom: none;
        }
        .summary-item-label {
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
        }
        .summary-item-value {
            font-weight: 600;
            color: #111827;
            margin-top: 2px;
        }
        .summary-item-date {
            font-size: 11px;
            color: #9ca3af;
        }
        .period-selector {
            margin-bottom: 15px;
        }
        .period-selector input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1>Wypłata dla: <?php echo e($workerName); ?></h1>
            <p>Dodaj rozliczenie, wypłatę lub zaliczkę dla pracownika</p>
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
        
        <div class="two-column-layout">
            <div class="card">
                <form method="POST">
                <div class="form-group">
                    <label>Typ rozliczenia *</label>
                    <select name="type" required>
                        <option value="">-- Wybierz --</option>
                        <option value="payout">Wypłata</option>
                        <option value="advance">Zaliczka</option>
                        <option value="reimbursement">Zwrot kosztów</option>
                        <option value="bonus">Premia</option>
                        <option value="correction">Korekta</option>
                    </select>
                    <div class="hint">Typ transakcji finansowej</div>
                </div>
                
                <div class="form-group" id="advanceKindGroup" style="display: none;">
                    <label>Rodzaj zaliczki *</label>
                    <select name="advance_kind">
                        <option value="">-- Wybierz --</option>
                        <option value="private" <?php echo (($_POST['advance_kind'] ?? '') === 'private') ? 'selected' : ''; ?>>Prywatna (do odliczenia od wypłaty)</option>
                        <option value="company" <?php echo (($_POST['advance_kind'] ?? '') === 'company' || ($_POST['advance_kind'] ?? '') === 'business') ? 'selected' : ''; ?>>Firmowa (koszty przedsiębiorstwa)</option>
                    </select>
                    <div class="hint">Określ czy zaliczka jest prywatna czy firmowa</div>
                </div>
                
                <div class="form-group">
                    <label>Kwota (PLN) *</label>
                    <input type="number" name="amount" step="0.01" min="0" required 
                           placeholder="np. 5000.00">
                    <div class="hint">Kwota brutto rozliczenia</div>
                </div>
                
                <div class="form-group">
                    <label>Data wypłaty *</label>
                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Okres rozliczeniowy *</label>
                    <input type="month" name="period" value="<?php echo date('Y-m'); ?>" required>
                    <div class="hint">Za jaki miesiąc dokonywane jest rozliczenie</div>
                </div>
                
                <div class="form-group">
                    <label>Opis</label>
                    <textarea name="description" placeholder="Opcjonalny opis rozliczenia..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Dodaj rozliczenie</button>
                    <a href="<?php echo url('hr.workers.profile', ['id' => $workerId]); ?>" 
                       class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
        
        <!-- Panel boczny: Podsumowanie miesiąca -->
        <div class="summary-panel">
            <h3>📊 Podsumowanie Miesiąca</h3>
            
            <div class="period-selector">
                <label style="font-size: 11px; color: #6b7280; display: block; margin-bottom: 4px;">OKRES</label>
                <input type="month" 
                       value="<?php echo date('Y-m', strtotime($summary_period)); ?>" 
                       onchange="window.location.href='?id=<?php echo $workerId; ?>&summary_period=' + this.value + '-01';">
            </div>
            
            <div class="summary-section">
                <h4>Koszty Pracy</h4>
                <div class="summary-value positive">
                    <?php echo number_format($summary_data['work_logs'], 2, ',', ' '); ?> zł
                </div>
                <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">
                    Suma kosztów z wpisów pracy
                </div>
            </div>
            
            <div class="summary-section">
                <h4>Wydatki Pracownika</h4>
                <div class="summary-value negative">
                    <?php echo number_format($summary_data['expenses'], 2, ',', ' '); ?> zł
                </div>
                <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">
                    Zatwierdzone wydatki zgłoszone przez pracownika
                </div>
            </div>
            
            <?php if (!empty($summary_data['settlements'])): ?>
                <div class="summary-section">
                    <h4>Ostatnie Rozliczenia</h4>
                    <?php foreach ($summary_data['settlements'] as $settlement): ?>
                        <?php
                        $typeLabels = [
                            'payout' => 'Wypłata',
                            'advance' => 'Zaliczka',
                            'reimbursement' => 'Zwrot',
                            'bonus' => 'Premia',
                            'correction' => 'Korekta'
                        ];
                        $typeLabel = $typeLabels[$settlement['type']] ?? $settlement['type'];
                        if ($settlement['type'] === 'advance' && $settlement['advance_kind']) {
                            $typeLabel .= ' (' . ($settlement['advance_kind'] === 'private' ? 'prywatna' : 'firmowa') . ')';
                        }
                        ?>
                        <div class="summary-item">
                            <div class="summary-item-label"><?php echo e($typeLabel); ?></div>
                            <div class="summary-item-value"><?php echo number_format($settlement['amount'], 2, ',', ' '); ?> zł</div>
                            <div class="summary-item-date">
                                <?php echo date('d.m.Y', strtotime($settlement['date'])); ?>
                                <?php if ($settlement['description']): ?>
                                    <br><span style="color: #6b7280;"><?php echo e(mb_substr($settlement['description'], 0, 50)); ?><?php echo mb_strlen($settlement['description']) > 50 ? '...' : ''; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="summary-section">
                    <h4>Ostatnie Rozliczenia</h4>
                    <div style="font-size: 13px; color: #9ca3af; padding: 10px 0;">
                        Brak rozliczeń
                    </div>
                </div>
            <?php endif; ?>
        </div>
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
