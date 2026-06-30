<?php
/**
 * BRYGAD ERP - Dodaj zasób (Maszynę/Auto)
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $type = $_POST['type'] ?? 'other';
        $name = trim($_POST['name'] ?? '');
        $reg_number = trim($_POST['reg_number'] ?? '');
        $serial_number = trim($_POST['serial_number'] ?? '');
        $usage_unit = $_POST['usage_unit'] ?? 'km';
        $current_usage = !empty($_POST['current_usage']) ? (float)$_POST['current_usage'] : 0.0;
        $production_year = !empty($_POST['production_year']) ? (int)$_POST['production_year'] : null;
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $notes = trim($_POST['notes'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($name)) {
            throw new Exception('Podaj nazwę zasobu');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO assets (
                type, name, reg_number, serial_number, usage_unit, current_usage,
                production_year, purchase_date, notes, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $type,
            $name,
            $reg_number ?: null,
            $serial_number ?: null,
            $usage_unit,
            $current_usage,
            $production_year,
            $purchase_date,
            $notes ?: null,
            $is_active
        ]);
        
        $asset_id = $pdo->lastInsertId();
        
        logEvent("Dodano zasób #$asset_id: $name", 'INFO');
        
        header('Location: ' . url('assets.view', ['id' => $asset_id]));
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        logEvent("Błąd dodawania zasobu: " . $error, 'ERROR');
    }
}

$assetTypes = [
    'car_passenger' => 'Auto osobowe',
    'car_delivery' => 'Auto dostawcze',
    'truck' => 'Ciężarówka',
    'excavator' => 'Koparka',
    'lift' => 'Podnośnik',
    'tool' => 'Narzędzie',
    'other' => 'Inne'
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dodaj zasób - <?php echo e(APP_NAME); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            font-size: 32px;
            color: #333;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .card-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            background: white;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border: 1px solid;
            transition: all 0.2s;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }
        
        .btn-secondary {
            background: white;
            color: #6b7280;
            border-color: #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
        }
        
        .actions-bar {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            margin-top: 20px;
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
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1>Dodaj zasób</h1>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <strong>Błąd:</strong> <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="form-group">
                        <label>Typ *</label>
                        <select name="type" required>
                            <?php foreach ($assetTypes as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Nazwa *</label>
                        <input type="text" name="name" required placeholder="np. Skoda Octavia, CAT 320">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Numer rejestracyjny</label>
                            <input type="text" name="reg_number" placeholder="np. WX12345">
                        </div>
                        
                        <div class="form-group">
                            <label>Numer seryjny</label>
                            <input type="text" name="serial_number" placeholder="np. SN123456">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Jednostka licznika</label>
                            <select name="usage_unit">
                                <option value="km">Kilometry (km)</option>
                                <option value="mth">Motogodziny (mth)</option>
                                <option value="none">Brak</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Stan licznika</label>
                            <input type="number" name="current_usage" step="0.1" value="0" min="0">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Rok produkcji</label>
                            <input type="number" name="production_year" min="1900" max="<?php echo date('Y') + 1; ?>" placeholder="np. 2020">
                        </div>
                        
                        <div class="form-group">
                            <label>Data zakupu</label>
                            <input type="date" name="purchase_date">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notatki</label>
                        <textarea name="notes" placeholder="Dodatkowe informacje o zasobie..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="is_active" checked>
                            <label for="is_active" style="margin: 0;">Aktywny</label>
                        </div>
                    </div>
                    
                    <div class="actions-bar">
                        <a href="<?php echo url('assets'); ?>" class="btn btn-secondary">Anuluj</a>
                        <button type="submit" class="btn btn-primary">Dodaj zasób</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>

