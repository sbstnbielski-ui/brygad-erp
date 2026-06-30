<?php
/**
 * BRYGAD ERP - Budżet Domowy - Ustaw kwotę dla rachunku variable
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';

$pdo = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit = HB_CAN_EDIT;

if (!$canEdit) {
    die('Brak uprawnień do edycji.');
}

$billItemId = $_GET['id'] ?? null;

if (!$billItemId) {
    die('Brak ID pozycji rachunku.');
}

// Pobierz bill_item wraz z bill
try {
    $stmt = $pdo->prepare("
        SELECT 
            bi.*,
            b.name as bill_name,
            b.amount_type
        FROM hb_bill_items bi
        JOIN hb_bills b ON b.id = bi.bill_id
        WHERE bi.id = ? AND bi.household_id = ?
    ");
    $stmt->execute([$billItemId, $householdId]);
    $billItem = $stmt->fetch();
    
    if (!$billItem) {
        die('Pozycja rachunku nie znaleziona.');
    }
    
    if ($billItem['amount_type'] !== 'variable') {
        die('Można ustawić kwotę tylko dla rachunków zmiennych.');
    }
    
} catch (PDOException $e) {
    error_log("Bill item fetch error: " . $e->getMessage());
    die('Błąd pobierania danych.');
}

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amountDue = (float)($_POST['amount_due'] ?? 0);
    
    if ($amountDue <= 0) {
        $error = 'Kwota musi być większa od 0.';
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE hb_bill_items 
                SET amount_due = ?
                WHERE id = ? AND household_id = ?
            ");
            $stmt->execute([$amountDue, $billItemId, $householdId]);
            
            // Redirect z parametrami okresu
            $year = date('Y', strtotime($billItem['period']));
            $month = date('m', strtotime($billItem['period']));
            header("Location: " . url('budzet.rachunki') . "?year={$year}&month={$month}");
            exit;
            
        } catch (PDOException $e) {
            error_log("Bill item update error: " . $e->getMessage());
            $error = 'Błąd podczas zapisywania kwoty.';
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$error = $error ?? null;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Ustaw kwotę rachunku</title>
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
            max-width: 700px;
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
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        .form-group input:focus {
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
        .info-box {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .info-box strong {
            display: block;
            margin-bottom: 5px;
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
            <h2>Ustaw kwotę rachunku</h2>
            <div class="subtitle"><?php echo e($billItem['bill_name']); ?></div>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        
        <div class="form-card">
            <div class="info-box">
                <strong>Rachunek:</strong> <?php echo e($billItem['bill_name']); ?><br>
                <strong>Okres:</strong> <?php echo date('Y-m', strtotime($billItem['period'])); ?><br>
                <strong>Termin płatności:</strong> <?php echo date('Y-m-d', strtotime($billItem['due_date'])); ?><br>
                <strong>Obecna kwota:</strong> <?php echo hb_format_money($billItem['amount_due']); ?>
            </div>
            
            <form method="POST">
                <div class="form-group">
                    <label>Nowa kwota do zapłaty <span style="color: #dc2626;">*</span></label>
                    <input 
                        type="number" 
                        name="amount_due" 
                        value="<?php echo $billItem['amount_due']; ?>"
                        step="0.01" 
                        min="0.01"
                        required
                        autofocus
                    >
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz kwotę</button>
                    <a href="<?php echo url('budzet.rachunki'); ?>" class="btn btn-secondary">
                        Anuluj
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

