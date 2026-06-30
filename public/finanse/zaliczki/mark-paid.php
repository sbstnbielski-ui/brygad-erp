<?php
/**
 * BRYGAD ERP v3.0 - Szybkie oznaczenie zaliczki jako spłaconej
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$advanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($advanceId <= 0) {
    header("Location: " . url('finanse.zaliczki'));
    exit;
}

// Pobierz zaliczkę
try {
    $stmt = $pdo->prepare("
        SELECT wa.*, 
               CONCAT(w.first_name, ' ', w.last_name) AS worker_name,
               COALESCE((SELECT SUM(amount) FROM worker_ledger WHERE advance_id = wa.id AND amount > 0), 0) AS amount_settled
        FROM worker_advances wa
        INNER JOIN workers w ON w.id = wa.worker_id
        WHERE wa.id = ?
    ");
    $stmt->execute([$advanceId]);
    $advance = $stmt->fetch();
    
    if (!$advance) {
        header("Location: " . url('finanse.zaliczki'));
        exit;
    }
    
    if ($advance['status'] === 'closed') {
        header("Location: " . url('finanse.zaliczki.view', ['id' => $advanceId]) . "&error=already_closed");
        exit;
    }
    
} catch (PDOException $e) {
    logEvent("Błąd pobierania zaliczki ID $advanceId: " . $e->getMessage(), 'ERROR');
    header("Location: " . url('finanse.zaliczki'));
    exit;
}

$amountRemaining = $advance['amount'] - $advance['amount_settled'];
$createdBy = $_SESSION['user_id'] ?? null;
$today = date('Y-m-d');

// Obsługa POST - oznacz jako spłaconą
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $note = trim($_POST['note'] ?? '');
    
    try {
        $pdo->beginTransaction();
        
        // Dodaj wpis do ledger rozliczający pozostałą kwotę
        if ($advance['type'] === 'PRIVATE') {
            // Zaliczka prywatna = potrącenie
            $entryType = 'SETTLEMENT_DEDUCTION';
            $description = "Potrącenie z wynagrodzenia: " . ($note ?: 'Oznaczono jako spłaconą');
        } else {
            // Zaliczka firmowa = koszt ręczny
            $entryType = 'MANUAL_COST';
            $description = "Rozliczenie ręczne: " . ($note ?: 'Oznaczono jako spłaconą');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO worker_ledger 
            (worker_id, entry_type, amount, entry_date, advance_id, description, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $advance['worker_id'],
            $entryType,
            $amountRemaining, // dodatnie - rozlicza dług
            $today,
            $advanceId,
            $description,
            $createdBy
        ]);
        
        // Zamknij zaliczkę
        $stmt = $pdo->prepare("
            UPDATE worker_advances
            SET status = 'closed'
            WHERE id = ?
        ");
        $stmt->execute([$advanceId]);
        
        $pdo->commit();
        
        logEvent("Oznaczono zaliczkę ID $advanceId jako spłaconą przez user ID $createdBy", 'INFO');
        
        header("Location: " . url('finanse.zaliczki') . "?success=paid");
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        logEvent("Błąd oznaczania zaliczki jako spłaconej ID $advanceId: " . $e->getMessage(), 'ERROR');
        header("Location: " . url('finanse.zaliczki.view', ['id' => $advanceId]) . "&error=1");
        exit;
    }
}

// Zmienne dla headera
$pageTitle = 'Oznacz jako spłaconą';
$breadcrumbs = [
    ['url' => url('dashboard'), 'label' => 'Start'],
    ['url' => url('finanse'), 'label' => 'Finanse'],
    ['url' => url('finanse.zaliczki'), 'label' => 'Zaliczki'],
    ['url' => '', 'label' => 'Spłać']
];
$moduleColor = 'blue';

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<?php include ROOT_PATH . '/includes/header.php'; ?>

<div class="container" style="max-width: 700px; margin: 0 auto; padding: 30px;">
    <div class="card">
        <h2 style="font-size: 20px; margin-bottom: 20px;">Oznacz zaliczkę jako spłaconą</h2>
        
        <div class="info-box">
            <strong>Zaliczka #<?php echo $advanceId; ?></strong>
            <div class="info-row">
                <span class="label">Pracownik:</span>
                <span class="value"><?php echo e($advance['worker_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Typ:</span>
                <span class="value"><?php echo $advance['type'] === 'PRIVATE' ? 'Prywatna' : 'Firmowa'; ?></span>
            </div>
            <div class="info-row">
                <span class="label">Kwota zaliczki:</span>
                <span class="value"><?php echo number_format($advance['amount'], 2, ',', ' '); ?> PLN</span>
            </div>
            <div class="info-row">
                <span class="label">Już rozliczona:</span>
                <span class="value"><?php echo number_format($advance['amount_settled'], 2, ',', ' '); ?> PLN</span>
            </div>
            <div class="info-row">
                <span class="label">Do rozliczenia:</span>
                <span class="value" style="color: #dc2626;">
                    <?php echo number_format($amountRemaining, 2, ',', ' '); ?> PLN
                </span>
            </div>
        </div>

        <div class="warning">
            <strong>Szybkie rozliczenie:</strong>
            <?php if ($advance['type'] === 'PRIVATE'): ?>
                Oznaczysz zaliczkę jako potrąconą z wynagrodzenia (wpis w ledger: SETTLEMENT_DEDUCTION).
            <?php else: ?>
                Oznaczysz zaliczkę jako rozliczoną ręcznie (wpis w ledger: MANUAL_COST).
            <?php endif; ?>
        </div>

        <form method="POST">
            <div class="form-group">
                <label>Notatka (opcjonalnie)</label>
                <textarea name="note" class="form-control" placeholder="Np. Rozliczone gotówką, Potrącone z wypłaty lutego..."></textarea>
            </div>

            <div class="btn-group">
                <button type="submit" class="btn btn-success">
                    Oznacz jako spłaconą
                </button>
                <a href="<?php echo url('finanse.zaliczki'); ?>" class="btn btn-secondary">
                    Anuluj
                </a>
            </div>
        </form>

        <p style="margin-top: 20px; font-size: 13px; color: #6b7280;">
            <strong>Potrzebujesz szczegółowego rozliczenia?</strong><br>
            Użyj opcji 
            <a href="<?php echo url('finanse.zaliczki.close', ['id' => $advanceId]); ?>" style="color: #2563eb;">
                "Rozlicz Zaliczkę"
            </a> 
            aby powiązać z fakturą lub dodać zwrot gotówki.
        </p>
    </div>
</div>

<style>
.card {
    background: white;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.info-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 20px;
}
.info-box strong {
    display: block;
    color: #1e40af;
    margin-bottom: 8px;
}
.info-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e5e7eb;
}
.info-row:last-child {
    border-bottom: none;
}
.label {
    color: #6b7280;
}
.value {
    font-weight: 600;
    font-family: 'Courier New', monospace;
}
.form-group {
    margin-bottom: 20px;
}
.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #374151;
}
.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
}
textarea.form-control {
    min-height: 80px;
    resize: vertical;
}
.btn-group {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}
.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}
.btn-success {
    background: #16a34a;
    color: white;
    flex: 1;
}
.btn-secondary {
    background: #6b7280;
    color: white;
}
.warning {
    background: #fef3c7;
    border: 1px solid #fde047;
    border-radius: 6px;
    padding: 15px;
    margin-bottom: 20px;
    color: #92400e;
}
</style>

<?php include ROOT_PATH . '/includes/footer.php'; ?>
