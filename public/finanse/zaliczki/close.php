<?php
/**
 * BRYGAD ERP - Zaliczki pracownicze - Rozliczanie szczegółowe
 * 
 * SCENARIUSZE:
 * - S1: Rozliczenie fakturą (pełne)
 * - S2: Rozliczenie fakturą + zwrot gotówki
 * - S3: Koszt ręczny bez faktury (MANUAL_COST)
 * - S4: Potrącenie z wynagrodzenia (SETTLEMENT_DEDUCTION)
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success = false;
$advanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($advanceId <= 0) {
    header("Location: " . url('finanse.zaliczki'));
    exit;
}

// Pobierz szczegóły zaliczki
try {
    $stmt = $pdo->prepare("SELECT * FROM v_worker_advances_details WHERE advance_id = ?");
    $stmt->execute([$advanceId]);
    $advance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$advance) {
        die("Nie znaleziono zaliczki.");
    }
    if ($advance['status'] === 'closed' || $advance['amount_remaining'] <= 0) {
        header("Location: " . url('finanse.zaliczki.view', ['id' => $advanceId]));
        exit;
    }
    
    // Pobierz dokumenty (faktury)
    $stmt = $pdo->query("
        SELECT id, number, issue_date, amount_net, description
        FROM documents
        WHERE status = 'approved'
        ORDER BY issue_date DESC
        LIMIT 100
    ");
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pobierz projekty
    $stmt = $pdo->query("
        SELECT id, name 
        FROM projects 
        WHERE status = 'active'
        ORDER BY name
    ");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pobierz rozliczenia (dla prywatnej zaliczki)
    $settlements = [];
    if ($advance['type'] === 'PRIVATE') {
        $stmt = $pdo->query("
            SELECT id, period, description
            FROM settlements
            ORDER BY period DESC
            LIMIT 12
        ");
        $settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Error fetching advance: " . $e->getMessage());
    die("Błąd pobierania danych: " . $e->getMessage());
}

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $closureType = trim($_POST['closure_type'] ?? '');
    $closureDate = trim($_POST['closure_date'] ?? date('Y-m-d'));
    $closureNote = trim($_POST['closure_note'] ?? '');
    $amountRemaining = $advance['amount_remaining'];
    
    $createdBy = $_SESSION['user_id'] ?? null;
    
    try {
        $pdo->beginTransaction();
        
        // SCENARIUSZ 1 i 2: Rozliczenie fakturą
        if ($closureType === 'EXPENSE_DOC') {
            $documentId = (int)($_POST['document_id'] ?? 0);
            $documentAmount = floatval(str_replace(',', '.', str_replace(' ', '', trim($_POST['document_amount'] ?? '0'))));
            
            if ($documentId <= 0) {
                throw new Exception('Wybierz dokument.');
            }
            if ($documentAmount <= 0 || $documentAmount > $amountRemaining) {
                throw new Exception('Nieprawidłowa kwota dokumentu.');
            }
            
            // Dodaj wpis do ledger
            $stmt = $pdo->prepare("
                INSERT INTO worker_ledger 
                (worker_id, entry_type, amount, entry_date, advance_id, document_id, description, created_by, created_at)
                VALUES (?, 'EXPENSE_DOC', ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $advance['worker_id'],
                $documentAmount,
                $closureDate,
                $advanceId,
                $documentId,
                "Rozliczenie fakturą" . ($closureNote ? ": " . $closureNote : ''),
                $createdBy
            ]);
            
            $amountRemaining -= $documentAmount;
            
            // Zwrot gotówki (S2)
            $cashReturn = floatval(str_replace(',', '.', str_replace(' ', '', trim($_POST['cash_return'] ?? '0'))));
            if ($cashReturn > 0 && $cashReturn <= $amountRemaining) {
                $stmt = $pdo->prepare("
                    INSERT INTO worker_ledger 
                    (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at)
                    VALUES (?, 'CASH_RETURN', ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $advance['worker_id'],
                    $cashReturn,
                    $closureDate,
                    $advanceId,
                    "Zwrot gotówki" . ($closureNote ? ": " . $closureNote : ''),
                    $createdBy
                ]);

                // Ruch finansowy: środki wracają do firmy
                $stmt = $pdo->prepare("
                    INSERT INTO worker_wallet_funding
                    (worker_id, advance_id, direction, amount, source_kind, source_ref, note, movement_date, created_by, created_at)
                    VALUES (?, ?, 'IN_RETURN', ?, 'cash', NULL, ?, ?, ?, NOW())
                ");

                $stmt->execute([
                    $advance['worker_id'],
                    $advanceId,
                    abs($cashReturn),
                    $closureNote !== '' ? 'Zwrot gotówki: ' . $closureNote : 'Zwrot gotówki z portfela',
                    $closureDate,
                    $createdBy
                ]);
                
                $amountRemaining -= $cashReturn;
            }
        }
        
        // SCENARIUSZ 3: Koszt ręczny
        elseif ($closureType === 'MANUAL_COST') {
            $costAmount = floatval(str_replace(',', '.', str_replace(' ', '', trim($_POST['cost_amount'] ?? '0'))));
            $projectId = (int)($_POST['project_id'] ?? 0);
            
            if ($costAmount <= 0 || $costAmount > $amountRemaining) {
                throw new Exception('Nieprawidłowa kwota kosztu.');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO worker_ledger 
                (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at)
                VALUES (?, 'MANUAL_COST', ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $advance['worker_id'],
                $costAmount,
                $closureDate,
                $advanceId,
                "Koszt ręczny" . ($closureNote ? ": " . $closureNote : ''),
                $createdBy
            ]);
            
            $amountRemaining -= $costAmount;
        }
        
        // SCENARIUSZ 4: Potrącenie z wynagrodzenia
        elseif ($closureType === 'SETTLEMENT_DEDUCTION') {
            $deductionAmount = floatval(str_replace(',', '.', str_replace(' ', '', trim($_POST['deduction_amount'] ?? '0'))));
            $settlementId = (int)($_POST['settlement_id'] ?? 0);
            
            if ($deductionAmount <= 0 || $deductionAmount > $amountRemaining) {
                throw new Exception('Nieprawidłowa kwota potrącenia.');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO worker_ledger 
                (worker_id, entry_type, amount, entry_date, advance_id, settlement_id, description, created_by, created_at)
                VALUES (?, 'SETTLEMENT_DEDUCTION', ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $advance['worker_id'],
                $deductionAmount,
                $closureDate,
                $advanceId,
                $settlementId > 0 ? $settlementId : null,
                "Potrącenie z wynagrodzenia" . ($closureNote ? ": " . $closureNote : ''),
                $createdBy
            ]);
            
            $amountRemaining -= $deductionAmount;
        }
        
        else {
            throw new Exception('Nieprawidłowy typ rozliczenia.');
        }
        
        // Jeśli zaliczka jest w pełni rozliczona, zmień status
        if ($amountRemaining <= 0.01) {
            $stmt = $pdo->prepare("UPDATE worker_advances SET status = 'closed' WHERE id = ?");
            $stmt->execute([$advanceId]);
        }
        
        $pdo->commit();
        
        // Przekieruj do widoku szczegółów
        header("Location: " . url('finanse.zaliczki.view', ['id' => $advanceId]) . '?success=1');
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = $e->getMessage();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error closing advance: " . $e->getMessage());
        $errors[] = 'Błąd podczas rozliczania: ' . $e->getMessage();
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Rozlicz zaliczkę #<?php echo $advanceId; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px;
        }
        
        /* Breadcrumbs */
        .breadcrumbs {
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }
        .breadcrumbs a {
            color: #667eea;
            text-decoration: none;
        }
        .breadcrumbs a:hover {
            text-decoration: underline;
        }
        .breadcrumbs span {
            margin: 0 5px;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        .page-header h1 {
            font-size: 32px;
            color: #333;
            margin-bottom: 8px;
        }
        .page-header p {
            font-size: 16px;
            color: #666;
        }
        
        /* Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }
        .alert ul {
            margin: 10px 0 0 20px;
        }
        
        /* Summary card */
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .summary-card h2 {
            font-size: 16px;
            margin-bottom: 15px;
            opacity: 0.9;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .summary-item {
            display: flex;
            flex-direction: column;
        }
        .summary-label {
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 5px;
        }
        .summary-value {
            font-size: 24px;
            font-weight: 700;
        }
        
        /* Form */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .form-card h3 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-label .required {
            color: #dc2626;
        }
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }
        .form-hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .scenario-section {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: #f9fafb;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .scenario-section.active {
            display: block;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(102, 126, 234, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            .summary-grid {
                grid-template-columns: 1fr;
            }
            .form-actions {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
    <script>
        function showScenario(type) {
            // Ukryj wszystkie sekcje
            document.querySelectorAll('.scenario-section').forEach(el => {
                el.classList.remove('active');
            });
            
            // Pokaż wybraną sekcję
            const section = document.getElementById('scenario-' + type);
            if (section) {
                section.classList.add('active');
            }
        }
    </script>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>

    <div class="container">
        <!-- Breadcrumbs -->
        <div class="breadcrumbs">
            <a href="<?php echo url('dashboard'); ?>">Dashboard</a>
            <span>›</span>
            <a href="<?php echo url('finanse'); ?>">Finanse</a>
            <span>›</span>
            <a href="<?php echo url('finanse.zaliczki'); ?>">Zaliczki pracownicze</a>
            <span>›</span>
            <a href="<?php echo url('finanse.zaliczki.view', ['id' => $advanceId]); ?>">Szczegóły #<?php echo $advanceId; ?></a>
            <span>›</span>
            <span>Rozlicz</span>
        </div>
        
        <!-- Page Header -->
        <div class="page-header">
            <h1>Rozliczanie zaliczki #<?php echo $advanceId; ?></h1>
            <p>Wybierz sposób rozliczenia i wprowadź szczegóły</p>
        </div>
        
        <!-- Errors -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Wystąpiły błędy:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- Summary -->
        <div class="summary-card">
            <h2><?php echo e($advance['last_name'] . ' ' . $advance['first_name']); ?></h2>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Kwota wydana</div>
                    <div class="summary-value"><?php echo formatMoney($advance['amount']); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Rozliczono</div>
                    <div class="summary-value"><?php echo formatMoney($advance['amount_settled']); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Pozostało do rozliczenia</div>
                    <div class="summary-value"><?php echo formatMoney($advance['amount_remaining']); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Form -->
        <form method="POST">
            <div class="form-card">
                <h3>Sposób rozliczenia</h3>
                
                <div class="form-group">
                    <label class="form-label">
                        Typ rozliczenia <span class="required">*</span>
                    </label>
                    <select name="closure_type" class="form-select" required onchange="showScenario(this.value)">
                        <option value="">-- Wybierz sposób rozliczenia --</option>
                        <?php if ($advance['type'] === 'COMPANY'): ?>
                            <option value="EXPENSE_DOC">Faktura / Dokument (S1/S2)</option>
                            <option value="MANUAL_COST">Koszt ręczny bez faktury (S3)</option>
                        <?php endif; ?>
                        <?php if ($advance['type'] === 'PRIVATE'): ?>
                            <option value="SETTLEMENT_DEDUCTION">Potrącenie z wynagrodzenia (S4)</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        Data rozliczenia <span class="required">*</span>
                    </label>
                    <input type="date" name="closure_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <!-- SCENARIUSZ 1/2: Faktura -->
                <div id="scenario-EXPENSE_DOC" class="scenario-section">
                    <h4 style="margin-bottom: 15px; color: #667eea;">Rozliczenie fakturą</h4>
                    
                    <div class="form-group">
                        <label class="form-label">Dokument / Faktura</label>
                        <select name="document_id" class="form-select">
                            <option value="">-- Wybierz dokument --</option>
                            <?php foreach ($documents as $doc): ?>
                                <option value="<?php echo $doc['id']; ?>">
                                    <?php echo e($doc['number'] . ' - ' . formatDate($doc['issue_date']) . ' - ' . formatMoney($doc['amount_net'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Kwota faktury (PLN)</label>
                        <input type="number" name="document_amount" step="0.01" min="0" max="<?php echo $advance['amount_remaining']; ?>" class="form-input">
                        <div class="form-hint">Max: <?php echo formatMoney($advance['amount_remaining']); ?></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Zwrot gotówki (PLN)</label>
                        <input type="number" name="cash_return" step="0.01" min="0" class="form-input" placeholder="0.00">
                        <div class="form-hint">Jeśli pracownik zwraca resztę gotówki (S2)</div>
                    </div>
                </div>
                
                <!-- SCENARIUSZ 3: Koszt ręczny -->
                <div id="scenario-MANUAL_COST" class="scenario-section">
                    <h4 style="margin-bottom: 15px; color: #667eea;">Koszt ręczny (bez faktury)</h4>
                    
                    <div class="form-group">
                        <label class="form-label">Kwota kosztu (PLN)</label>
                        <input type="number" name="cost_amount" step="0.01" min="0" max="<?php echo $advance['amount_remaining']; ?>" class="form-input">
                        <div class="form-hint">Max: <?php echo formatMoney($advance['amount_remaining']); ?></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Projekt (opcjonalnie)</label>
                        <select name="project_id" class="form-select">
                            <option value="">-- Bez przypisania --</option>
                            <?php foreach ($projects as $proj): ?>
                                <option value="<?php echo $proj['id']; ?>"><?php echo e($proj['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- SCENARIUSZ 4: Potrącenie -->
                <div id="scenario-SETTLEMENT_DEDUCTION" class="scenario-section">
                    <h4 style="margin-bottom: 15px; color: #667eea;">Potrącenie z wynagrodzenia</h4>
                    
                    <div class="form-group">
                        <label class="form-label">Kwota potrącenia (PLN)</label>
                        <input type="number" name="deduction_amount" step="0.01" min="0" max="<?php echo $advance['amount_remaining']; ?>" class="form-input">
                        <div class="form-hint">Max: <?php echo formatMoney($advance['amount_remaining']); ?></div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Rozliczenie (opcjonalnie)</label>
                        <select name="settlement_id" class="form-select">
                            <option value="">-- Bez przypisania --</option>
                            <?php foreach ($settlements as $settl): ?>
                                <option value="<?php echo $settl['id']; ?>">
                                    <?php echo e(formatDate($settl['period']) . ' - ' . ($settl['description'] ?: 'Rozliczenie')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notatka</label>
                    <textarea name="closure_note" class="form-textarea" placeholder="Dodatkowe informacje o rozliczeniu"></textarea>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Rozlicz zaliczkę</button>
                <a href="<?php echo url('finanse.zaliczki.view', ['id' => $advanceId]); ?>" class="btn btn-secondary">Anuluj</a>
            </div>
        </form>
    </div>
</body>
</html>
