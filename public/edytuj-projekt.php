<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireAdmin(); // Tylko admin może edytować projekty

$pdo = getDbConnection();
$errors = [];
$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    header("Location: projekty.php");
    exit;
}

// Pobierz dane projektu
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
$stmt->execute(['id' => $project_id]);
$project = $stmt->fetch();

if (!$project) {
    header("Location: projekty.php");
    exit;
}

// Nie pozwól edytować projektu wewnętrznego
if ($project['is_internal']) {
    header("Location: widok-projektu.php?id={$project_id}&error=internal");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $status = $_POST['status'] ?? 'planned';
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    
    // Walidacja
    if (empty($name)) {
        $errors[] = "Nazwa projektu jest wymagana";
    }
    
    if (!in_array($status, ['planned', 'active', 'finished'])) {
        $errors[] = "Nieprawidłowy status";
    }
    
    // Nie pozwól zakończyć projektu wewnętrznego
    if ($status === 'finished' && $project['is_internal']) {
        $errors[] = "Nie można zakończyć projektu wewnętrznego";
    }
    
    // Nie pozwól zamknąć projektu z aktywnymi kosztami w statusie pending
    if ($status === 'finished') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as pending_count
            FROM (
                SELECT id FROM work_logs WHERE project_id = :id AND status = 'pending'
                UNION ALL
                SELECT id FROM worker_expenses WHERE project_id = :id AND status = 'pending'
            ) as pending_items
        ");
        $stmt->execute(['id' => $project_id]);
        $pending = $stmt->fetch();
        
        if ($pending['pending_count'] > 0) {
            $errors[] = "Nie można zakończyć projektu - są niezatwierdzone koszty ({$pending['pending_count']} pozycji)";
        }
    }
    
    if ($start_date && $end_date) {
        if (strtotime($end_date) < strtotime($start_date)) {
            $errors[] = "Data zakończenia nie może być wcześniejsza niż data rozpoczęcia";
        }
    }
    
    if (empty($errors)) {
        try {
            $sql = "UPDATE projects 
                    SET name = :name, 
                        status = :status, 
                        start_date = :start_date, 
                        end_date = :end_date
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'name' => $name,
                'status' => $status,
                'start_date' => $start_date ?: null,
                'end_date' => $end_date ?: null,
                'id' => $project_id
            ]);
            
            logEvent("Zaktualizowano projekt ID: {$project_id} ({$name})", 'info');
            
            header("Location: widok-projektu.php?id={$project_id}&success=updated");
            exit;
            
        } catch (PDOException $e) {
            logEvent("Błąd aktualizacji projektu: " . $e->getMessage(), 'error');
            $errors[] = "Błąd podczas aktualizacji projektu";
        }
    }
} else {
    // Wypełnij formularz danymi z bazy
    $_POST = [
        'name' => $project['name'],
        'status' => $project['status'],
        'start_date' => $project['start_date'],
        'end_date' => $project['end_date']
    ];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Edytuj projekt</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
        }
        
        .header-links a {
            color: #667eea;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            transition: background 0.3s;
        }
        
        .header-links a:hover {
            background: #f0f0f0;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group label .required {
            color: #dc3545;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group .hint {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Edytuj projekt</h1>
        <div class="header-links">
            <a href="widok-projektu.php?id=<?php echo $project_id; ?>">← Powrót do projektu</a>
        </div>
    </div>
    
    <div class="container">
        <div class="form-container">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($_POST['status'] ?? '' === 'finished'): ?>
                <div class="warning-box">
                    <strong>Uwaga:</strong> Projekt zakończony nie będzie przyjmował nowych kosztów.
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>
                        Nazwa projektu <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="name" 
                        value="<?php echo e($_POST['name'] ?? ''); ?>" 
                        required
                    >
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="planned" <?php echo ($_POST['status'] ?? '') === 'planned' ? 'selected' : ''; ?>>
                                Planowany
                            </option>
                            <option value="active" <?php echo ($_POST['status'] ?? '') === 'active' ? 'selected' : ''; ?>>
                                Aktywny
                            </option>
                            <?php if (!$project['is_internal']): ?>
                                <option value="finished" <?php echo ($_POST['status'] ?? '') === 'finished' ? 'selected' : ''; ?>>
                                    Zakończony
                                </option>
                            <?php endif; ?>
                        </select>
                        <?php if ($project['is_internal']): ?>
                            <div class="hint">
                                Projekt wewnętrzny nie może być zakończony
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Data rozpoczęcia</label>
                        <input 
                            type="date" 
                            name="start_date" 
                            value="<?php echo e($_POST['start_date'] ?? ''); ?>"
                        >
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Data zakończenia</label>
                    <input 
                        type="date" 
                        name="end_date" 
                        value="<?php echo e($_POST['end_date'] ?? ''); ?>"
                    >
                    <div class="hint">Opcjonalnie - może być uzupełniona później</div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                    <a href="widok-projektu.php?id=<?php echo $project_id; ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

