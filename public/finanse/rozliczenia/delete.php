<?php
/**
 * BRYGAD ERP v3.0 - Usuwanie Rozliczenia
 * Umożliwia usunięcie wypłat, zaliczek, zwrotów kosztów itp.
 * TYLKO DLA ADMINA
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin może usuwać rozliczenia

$pdo = getDbConnection();
$errors = [];

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

// Typ rozliczenia (label)
$typeLabels = [
    'payout' => 'Wypłata',
    'advance' => 'Zaliczka',
    'reimbursement' => 'Zwrot kosztów',
    'bonus' => 'Premia',
    'correction' => 'Korekta'
];
$typeLabel = $typeLabels[$settlement['type']] ?? $settlement['type'];
if ($settlement['type'] === 'advance' && $settlement['advance_kind']) {
    $typeLabel .= ' (' . ($settlement['advance_kind'] === 'private' ? 'prywatna' : 'firmowa') . ')';
}

// Obsługa POST - usuwanie rozliczenia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // Usuń rozliczenie
        $stmt = $pdo->prepare("DELETE FROM settlements WHERE id = ?");
        $stmt->execute([$settlementId]);
        
        logEvent("Usunięto rozliczenie ID $settlementId: typ {$settlement['type']}, pracownik {$workerName}, kwota {$settlement['amount']} PLN", 'WARNING');
        
        header("Location: " . url('finanse.rozliczenia') . "?success=delete");
        exit;
    } catch (PDOException $e) {
        $errors[] = 'Błąd usuwania rozliczenia. Spróbuj ponownie.';
        logEvent("Błąd usuwania rozliczenia ID $settlementId: " . $e->getMessage(), 'ERROR');
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Usuwanie Rozliczenia</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
            padding: 30px;
        }
        .page-header {
            margin-bottom: 30px;
        }
        .page-header h1 {
            font-size: 28px;
            color: #dc2626;
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
            border-left: 4px solid #dc2626;
        }
        .warning-banner {
            background: #fef2f2;
            border: 2px solid #dc2626;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            color: #991b1b;
        }
        .warning-banner h3 {
            font-size: 18px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .warning-banner p {
            font-size: 14px;
            line-height: 1.6;
        }
        .settlement-details {
            background: #f9fafb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .settlement-details h3 {
            font-size: 16px;
            color: #111827;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 600;
            color: #6b7280;
            font-size: 13px;
        }
        .detail-value {
            font-weight: 700;
            color: #111827;
            font-size: 14px;
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
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        .btn-danger:hover {
            background: #b91c1c;
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
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1>⚠️ Usuwanie Rozliczenia</h1>
            <p>Potwierdź usunięcie rozliczenia</p>
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
        
        <div class="warning-banner">
            <h3>
                <span style="font-size: 24px;">⚠️</span>
                Nieodwracalna operacja
            </h3>
            <p>
                <strong>Uwaga!</strong> Usunięcie rozliczenia jest operacją nieodwracalną. 
                Po usunięciu dane nie będą mogły zostać przywrócone. 
                Ta operacja zostanie zapisana w logach systemu.
            </p>
        </div>
        
        <div class="card">
            <div class="settlement-details">
                <h3>Szczegóły rozliczenia do usunięcia:</h3>
                
                <div class="detail-row">
                    <span class="detail-label">Pracownik:</span>
                    <span class="detail-value"><?php echo e($workerName); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Typ rozliczenia:</span>
                    <span class="detail-value"><?php echo e($typeLabel); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Kwota:</span>
                    <span class="detail-value" style="color: #dc2626; font-size: 16px;">
                        <?php echo number_format($settlement['amount'], 2, ',', ' '); ?> zł
                    </span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Data wypłaty:</span>
                    <span class="detail-value"><?php echo date('d.m.Y', strtotime($settlement['date'])); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="detail-label">Okres rozliczeniowy:</span>
                    <span class="detail-value"><?php echo date('m/Y', strtotime($settlement['period'])); ?></span>
                </div>
                
                <?php if ($settlement['description']): ?>
                <div class="detail-row">
                    <span class="detail-label">Opis:</span>
                    <span class="detail-value" style="font-weight: 400; color: #6b7280;">
                        <?php echo e($settlement['description']); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="POST">
                <div class="form-actions">
                    <button type="submit" name="confirm_delete" value="1" class="btn btn-danger" 
                            onclick="return confirm('Czy na pewno chcesz usunąć to rozliczenie? Ta operacja jest nieodwracalna!');">
                        🗑️ Tak, usuń rozliczenie
                    </button>
                    <a href="<?php echo url('finanse.rozliczenia'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

