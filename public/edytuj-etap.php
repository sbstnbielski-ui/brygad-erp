<?php
// TYMCZASOWE DEBUGOWANIE - usuń po naprawie
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';
startSecureSession();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$node_id = $_GET['id'] ?? null;

if (!$node_id) {
    header("Location: projekty.php");
    exit;
}

// Pobierz etap
$stmt = $pdo->prepare("SELECT * FROM project_cost_nodes WHERE id = :id");
$stmt->execute(['id' => $node_id]);
$node = $stmt->fetch();

if (!$node) {
    header("Location: projekty.php");
    exit;
}

$project_id = $node['project_id'];

// Pobierz projekt
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
$stmt->execute(['id' => $project_id]);
$project = $stmt->fetch();

// Sprawdź czy można dezaktywować
$stmt = $pdo->prepare("
    SELECT COUNT(*) as usage_count
    FROM (
        SELECT id FROM work_logs WHERE cost_node_id = :node_id
        UNION ALL
        SELECT id FROM worker_expenses WHERE cost_node_id = :node_id
        UNION ALL
        SELECT id FROM document_allocations WHERE cost_node_id = :node_id
    ) as usages
");
$stmt->execute(['node_id' => $node_id]);
$usage = $stmt->fetch();

// Sprawdź czy ma dzieci
$stmt = $pdo->prepare("SELECT COUNT(*) as children_count FROM project_cost_nodes WHERE parent_id = :id");
$stmt->execute(['id' => $node_id]);
$children = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';
    
    if ($action === 'delete') {
        // Prawdziwe usuwanie
        if ($children['children_count'] > 0) {
            $errors[] = "Nie można usunąć etapu, który ma elementy podrzędne";
        } elseif ($usage['usage_count'] > 0) {
            $errors[] = "Nie można usunąć etapu, który ma przypisane koszty. Możesz go dezaktywować.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM project_cost_nodes WHERE id = :id");
                $stmt->execute(['id' => $node_id]);
                
                logEvent("Usunięto etap ID: {$node_id} ({$node['name']})", 'WARNING');
                
                header("Location: etapy-kosztow.php?project_id={$project_id}&success=deleted");
                exit;
                
            } catch (PDOException $e) {
                logEvent("Błąd usuwania etapu: " . $e->getMessage(), 'error');
                $errors[] = "Błąd podczas usuwania etapu";
            }
        }
    } elseif ($action === 'deactivate') {
        // Dezaktywacja
        if ($children['children_count'] > 0) {
            $errors[] = "Nie można dezaktywować etapu, który ma elementy podrzędne";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE project_cost_nodes SET is_active = 0 WHERE id = :id");
                $stmt->execute(['id' => $node_id]);
                
                logEvent("Dezaktywowano etap ID: {$node_id} ({$node['name']})", 'info');
                
                header("Location: etapy-kosztow.php?project_id={$project_id}&success=deactivated");
                exit;
                
            } catch (PDOException $e) {
                logEvent("Błąd dezaktywacji etapu: " . $e->getMessage(), 'error');
                $errors[] = "Błąd podczas dezaktywacji etapu";
            }
        }
    } else {
        // Aktualizacja
        $name = trim($_POST['name'] ?? '');
        $sort_order = intval($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Walidacja
        if (empty($name)) {
            $errors[] = "Nazwa etapu jest wymagana";
        }
        
        // Sprawdź duplikaty (tylko wśród aktywnych etapów)
        if (empty($errors)) {
            $sql = "SELECT COUNT(*) FROM project_cost_nodes 
                    WHERE project_id = :project_id 
                    AND name = :name 
                    AND id != :id
                    AND is_active = 1
                    AND " . ($node['parent_id'] ? "parent_id = :parent_id" : "parent_id IS NULL");
            
            $stmt = $pdo->prepare($sql);
            $params = ['project_id' => $project_id, 'name' => $name, 'id' => $node_id];
            if ($node['parent_id']) {
                $params['parent_id'] = $node['parent_id'];
            }
            $stmt->execute($params);
            
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Aktywny etap o tej nazwie już istnieje na tym poziomie";
            }
        }
        
        // Nie pozwól reaktywować jeśli rodzic jest nieaktywny
        if ($is_active && $node['parent_id']) {
            $stmt = $pdo->prepare("SELECT is_active FROM project_cost_nodes WHERE id = :id");
            $stmt->execute(['id' => $node['parent_id']]);
            $parent = $stmt->fetch();
            
            if ($parent && !$parent['is_active']) {
                $errors[] = "Nie można aktywować etapu - rodzic jest nieaktywny";
            }
        }
        
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE project_cost_nodes 
                    SET name = :name, 
                        sort_order = :sort_order,
                        is_active = :is_active
                    WHERE id = :id
                ");
                $stmt->execute([
                    'name' => $name,
                    'sort_order' => $sort_order,
                    'is_active' => $is_active,
                    'id' => $node_id
                ]);
                
                logEvent("Zaktualizowano etap ID: {$node_id} ({$name})", 'info');
                
                header("Location: etapy-kosztow.php?project_id={$project_id}&success=updated");
                exit;
                
            } catch (PDOException $e) {
                logEvent("Błąd aktualizacji etapu: " . $e->getMessage(), 'error');
                $errors[] = "Błąd podczas aktualizacji etapu";
            }
        }
    }
} else {
    // Wypełnij formularz
    $_POST = [
        'name' => $node['name'],
        'sort_order' => $node['sort_order'],
        'is_active' => $node['is_active']
    ];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Edytuj etap</title>
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
        
        .header-subtitle {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
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
        
        .form-group input[type="text"],
        .form-group input[type="number"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-group .hint {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
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
        
        .info-box {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            color: #004085;
        }
        
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            color: #856404;
        }
        
        .danger-box {
            background: #f8d7da;
            border: 1px solid #dc3545;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            color: #721c24;
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
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Edytuj etap</h1>
            <div class="header-subtitle"><?php echo e($project['name']); ?></div>
        </div>
        <div class="header-links">
            <a href="etapy-kosztow.php?project_id=<?php echo $project_id; ?>">← Powrót</a>
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
            
            <?php if ($usage['usage_count'] > 0): ?>
                <div class="info-box">
                    <strong>Ten etap ma przypisane koszty:</strong>
                    <?php echo $usage['usage_count']; ?> pozycji (wpisy pracy, wydatki, faktury)
                </div>
            <?php endif; ?>
            
            <?php if ($children['children_count'] > 0): ?>
                <div class="warning-box">
                    <strong>Ten etap ma <?php echo $children['children_count']; ?> etapów podrzędnych</strong>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="action" value="update">
                
                <div class="form-group">
                    <label>
                        Nazwa etapu <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="name" 
                        value="<?php echo e($_POST['name'] ?? ''); ?>" 
                        required
                    >
                </div>
                
                <div class="form-group">
                    <label>Kolejność sortowania</label>
                    <input 
                        type="number" 
                        name="sort_order" 
                        value="<?php echo e($_POST['sort_order'] ?? 0); ?>"
                        min="0"
                    >
                    <div class="hint">
                        Niższy numer = wyższa pozycja na liście
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input 
                            type="checkbox" 
                            name="is_active" 
                            id="is_active"
                            <?php echo ($_POST['is_active'] ?? 1) ? 'checked' : ''; ?>
                        >
                        <label for="is_active">Etap aktywny</label>
                    </div>
                    <div class="hint">
                        Etapy nieaktywne nie są widoczne przy tworzeniu nowych kosztów
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
                    <a href="etapy-kosztow.php?project_id=<?php echo $project_id; ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
            
            <?php if ($children['children_count'] === 0): ?>
                <div style="margin-top: 40px; padding-top: 30px; border-top: 2px solid #dee2e6;">
                    <h3 style="color: #721c24; margin-bottom: 15px;">Strefa niebezpieczna</h3>
                    
                    <?php if ($usage['usage_count'] > 0): ?>
                        <div class="warning-box" style="margin-bottom: 20px;">
                            <strong>Ten etap ma przypisane koszty (<?php echo $usage['usage_count']; ?> pozycji)</strong><br>
                            Nie można go usunąć, ale możesz go dezaktywować - zachowa dane historyczne.
                        </div>
                        <form method="POST" onsubmit="return confirm('Czy na pewno chcesz dezaktywować ten etap? Będzie ukryty, ale dane historyczne zostaną zachowane.');">
                            <input type="hidden" name="action" value="deactivate">
                            <button type="submit" class="btn btn-danger" style="background: #fd7e14;">Dezaktywuj etap</button>
                        </form>
                    <?php else: ?>
                        <p style="color: #666; margin-bottom: 15px;">
                            Ten etap nie ma przypisanych kosztów. Możesz go:
                        </p>
                        <div style="display: flex; gap: 15px;">
                            <form method="POST" onsubmit="return confirm('Czy na pewno chcesz USUNAC ten etap? Ta operacja jest nieodwracalna!');" style="margin: 0;">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-danger">Usuń etap</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Czy na pewno chcesz dezaktywować ten etap? Będzie ukryty, ale będziesz mógł go reaktywować.');" style="margin: 0;">
                                <input type="hidden" name="action" value="deactivate">
                                <button type="submit" class="btn btn-danger" style="background: #fd7e14;">Dezaktywuj etap</button>
                            </form>
                        </div>
                        <div style="margin-top: 10px; font-size: 13px; color: #666;">
                            <strong>Usuń:</strong> całkowite usunięcie (pozwoli dodać nowy etap o tej samej nazwie)<br>
                            <strong>Dezaktywuj:</strong> ukryj etap (można reaktywować później)
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

