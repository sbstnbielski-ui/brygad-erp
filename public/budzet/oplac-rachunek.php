<?php
/**
 * BRYGAD ERP - Budżet Domowy - Opłać rachunek
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_hb.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit = HB_CAN_EDIT;
$userId = $_SESSION['user_id'];

if (!$canEdit) {
    die('Brak uprawnień do edycji.');
}

$billItemId = $_GET['id'] ?? null;
if (!$billItemId) {
    die('Brak ID rachunku.');
}

// Pobierz dane rachunku
try {
    $stmt = $pdo->prepare("
        SELECT 
            bi.*,
            b.name as bill_name,
            b.default_account_id,
            b.category_id
        FROM hb_bill_items bi
        JOIN hb_bills b ON b.id = bi.bill_id
        WHERE bi.id = ? AND bi.household_id = ?
    ");
    $stmt->execute([$billItemId, $householdId]);
    $billItem = $stmt->fetch();
    
    if (!$billItem) {
        die('Rachunek nie znaleziony.');
    }
    
    $remaining = $billItem['amount_due'] - $billItem['amount_paid'];
    
    // Pobierz konta
    $stmtAccounts = $pdo->prepare("
        SELECT id, name, current_balance
        FROM hb_accounts
        WHERE household_id = ? AND is_active = 1
        ORDER BY sort_order, name
    ");
    $stmtAccounts->execute([$householdId]);
    $accounts = $stmtAccounts->fetchAll();
    
} catch (PDOException $e) {
    error_log("Bill item fetch error: " . $e->getMessage());
    die('Błąd pobierania danych.');
}

// Obsługa POST
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float)($_POST['amount'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    
    if ($amount <= 0) {
        $error = 'Kwota musi być większa od 0.';
    } elseif ($amount > $remaining) {
        $error = 'Kwota nie może przekraczać pozostałej kwoty do zapłaty.';
    } elseif (!$accountId) {
        $error = 'Wybierz konto.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Zapisz transakcję
            $stmtTrans = $pdo->prepare("
                INSERT INTO hb_transactions
                (household_id, account_id, direction, amount, date, period, 
                 category_id, bill_item_id, description, created_by)
                VALUES (?, ?, 'expense', ?, CURDATE(), ?, ?, ?, ?, ?)
            ");
            $stmtTrans->execute([
                $householdId,
                $accountId,
                $amount,
                $billItem['period'],
                $billItem['category_id'],
                $billItemId,
                $description ?: 'Opłata rachunku: ' . $billItem['bill_name'],
                $userId
            ]);
            
            // Aktualizuj bill_item
            // UWAGA: Używamy (amount_paid + ?) w CASE, żeby status był liczony poprawnie
            $stmtUpdate = $pdo->prepare("
                UPDATE hb_bill_items
                SET amount_paid = amount_paid + ?,
                    payment_date = CURDATE(),
                    status = CASE
                        WHEN (amount_paid + ?) >= amount_due THEN 'paid'
                        WHEN (amount_paid + ?) > 0 THEN 'partial'
                        ELSE 'unpaid'
                    END
                WHERE id = ? AND household_id = ?
            ");
            $stmtUpdate->execute([$amount, $amount, $amount, $billItemId, $householdId]);
            
            $pdo->commit();
            
            // Redirect do rachunków
            $period = substr($billItem['period'], 0, 7);
            header("Location: " . url('budzet.rachunki', ['period' => $period]));
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Payment error: " . $e->getMessage());
            $error = 'Błąd podczas zapisywania płatności.';
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
    <title><?php echo e(APP_NAME); ?> - Opłać rachunek</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .header {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .logo-section img { height: 50px; border-radius: 6px; }
        .logo-text h1 { font-size: 24px; color: #333; }
        .logo-text p { font-size: 13px; color: #666; }
        .user-section {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .user-name { font-weight: 600; color: #333; }
        .btn-logout, .btn-back {
            padding: 8px 16px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px;
        }
        .page-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            color: white;
            padding: 40px 50px;
            border-radius: 12px;
            margin-bottom: 30px;
        }
        .page-header h2 { font-size: 28px; margin-bottom: 8px; }
        .page-header .subtitle { font-size: 14px; color: #94a3b8; }
        .form-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .info-box {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            margin-bottom: 30px;
        }
        .info-box h3 {
            font-size: 16px;
            margin-bottom: 12px;
            color: #333;
        }
        .info-box .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .info-box .info-row .label {
            color: #666;
        }
        .info-box .info-row .value {
            font-weight: 600;
            color: #333;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #333;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
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
            color: #333;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        .alert-success {
            background: #d1fae5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <img src="<?php echo asset('logo-brygad-erp.png'); ?>" alt="BRYGAD ERP">
                <div class="logo-text">
                    <h1><?php echo e(APP_NAME); ?></h1>
                    <p>Budżet Domowy</p>
                </div>
            </div>
            <div class="user-section">
                <span class="user-name"><?php echo e($userName); ?></span>
                <a href="<?php echo HB_HOME_URL; ?>" class="btn-back"><?php echo HB_HOME_LABEL; ?></a>
                <a href="<?php echo url('logout'); ?>" class="btn-logout">Wyloguj</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="page-header">
            <h2>Opłać rachunek</h2>
            <div class="subtitle"><?php echo e($billItem['bill_name']); ?></div>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>
        
        <div class="form-card">
            <div class="info-box">
                <h3>Informacje o rachunku</h3>
                <div class="info-row">
                    <span class="label">Rachunek:</span>
                    <span class="value"><?php echo e($billItem['bill_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Termin płatności:</span>
                    <span class="value"><?php echo e(date('d.m.Y', strtotime($billItem['due_date']))); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Do zapłaty (razem):</span>
                    <span class="value"><?php echo hb_format_money($billItem['amount_due']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Już zapłacono:</span>
                    <span class="value"><?php echo hb_format_money($billItem['amount_paid']); ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Pozostało do zapłaty:</span>
                    <span class="value" style="font-size: 16px; color: #dc2626;">
                        <?php echo hb_format_money($remaining); ?>
                    </span>
                </div>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label>Kwota płatności <span style="color: #dc2626;">*</span></label>
                    <input 
                        type="number" 
                        name="amount" 
                        step="0.01" 
                        min="0.01"
                        max="<?php echo $remaining; ?>"
                        value="<?php echo $remaining; ?>"
                        required
                    >
                </div>
                
                <div class="form-group">
                    <label>Konto <span style="color: #dc2626;">*</span></label>
                    <select name="account_id" required>
                        <option value="">-- Wybierz konto --</option>
                        <?php foreach ($accounts as $acc): ?>
                        <option 
                            value="<?php echo $acc['id']; ?>"
                            <?php if ($acc['id'] == $billItem['default_account_id']): ?>selected<?php endif; ?>
                        >
                            <?php echo e($acc['name']); ?> 
                            (Saldo: <?php echo hb_format_money($acc['current_balance']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Opis (opcjonalnie)</label>
                    <textarea name="description" rows="3"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz płatność</button>
                    <a href="<?php echo url('budzet.rachunki', ['period' => substr($billItem['period'], 0, 7)]); ?>" class="btn btn-secondary">
                        Anuluj
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

