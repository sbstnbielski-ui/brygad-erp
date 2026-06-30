<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

$project_id = $_GET['project_id'] ?? null;
$parent_id = $_GET['parent_id'] ?? null;

if (!$project_id) {
    header("Location: projekty.php");
    exit;
}

// Pobierz projekt
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
$stmt->execute(['id' => $project_id]);
$project = $stmt->fetch();

if (!$project) {
    header("Location: projekty.php");
    exit;
}

// Jeśli parent_id, pobierz dane rodzica
$parent_node = null;
if ($parent_id) {
    $stmt = $pdo->prepare("SELECT * FROM project_cost_nodes WHERE id = :id AND project_id = :project_id");
    $stmt->execute(['id' => $parent_id, 'project_id' => $project_id]);
    $parent_node = $stmt->fetch();
    
    if (!$parent_node) {
        header("Location: etapy-kosztow.php?project_id={$project_id}");
        exit;
    }
}

// Pobierz wszystkie etapy główne dla select (jeśli nie ma parent_id z URL)
$main_nodes = [];
if (!$parent_id) {
    $stmt = $pdo->prepare("SELECT * FROM project_cost_nodes WHERE project_id = :project_id AND parent_id IS NULL AND is_active = 1 ORDER BY name");
    $stmt->execute(['project_id' => $project_id]);
    $main_nodes = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $selected_parent_id = $_POST['parent_id'] ?? null;
    $sort_order = intval($_POST['sort_order'] ?? 0);
    
    // Walidacja
    if (empty($name)) {
        $errors[] = "Nazwa etapu jest wymagana";
    }
    
    // Jeśli parent_id z URL, użyj go
    if ($parent_id) {
        $selected_parent_id = $parent_id;
    }
    
    // Sprawdź czy nazwa nie jest duplikatem na tym samym poziomie
    if (empty($errors)) {
        $sql = "SELECT COUNT(*) FROM project_cost_nodes 
                WHERE project_id = :project_id 
                AND name = :name 
                AND " . ($selected_parent_id ? "parent_id = :parent_id" : "parent_id IS NULL");
        
        $stmt = $pdo->prepare($sql);
        $params = ['project_id' => $project_id, 'name' => $name];
        if ($selected_parent_id) {
            $params['parent_id'] = $selected_parent_id;
        }
        $stmt->execute($params);
        
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Etap o tej nazwie już istnieje na tym poziomie";
        }
    }
    
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO project_cost_nodes (project_id, parent_id, name, sort_order) 
                    VALUES (:project_id, :parent_id, :name, :sort_order)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'project_id' => $project_id,
                'parent_id' => $selected_parent_id ?: null,
                'name' => $name,
                'sort_order' => $sort_order
            ]);
            
            $node_id = $pdo->lastInsertId();
            
            logEvent("Utworzono etap kosztów: {$name} dla projektu ID: {$project_id}", 'info');
            
            header("Location: etapy-kosztow.php?project_id={$project_id}&success=created");
            exit;
            
        } catch (PDOException $e) {
            logEvent("Błąd tworzenia etapu: " . $e->getMessage(), 'error');
            $errors[] = "Błąd podczas tworzenia etapu";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj etap</title>
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
        
        .info-box strong {
            display: block;
            margin-bottom: 5px;
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
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1><?php echo $parent_node ? 'Dodaj etap podrzędny' : 'Dodaj etap główny'; ?></h1>
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
            
            <?php if ($parent_node): ?>
                <div class="info-box">
                    <strong>Tworzysz etap podrzędny dla:</strong>
                    <?php echo e($parent_node['name']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label>
                        Nazwa etapu <span class="required">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="name" 
                        value="<?php echo e($_POST['name'] ?? ''); ?>" 
                        required
                        placeholder="np. Robocizna, Materiały, Spawanie..."
                    >
                    <div class="hint">
                        Nazwa powinna być krótka i opisowa (np. "Robocizna", "Materiały", "Transport")
                    </div>
                </div>
                
                <?php if (!$parent_id && !empty($main_nodes)): ?>
                    <div class="form-group">
                        <label>Rodzic (opcjonalnie)</label>
                        <select name="parent_id">
                            <option value="">-- Etap główny --</option>
                            <?php foreach ($main_nodes as $node): ?>
                                <option value="<?php echo $node['id']; ?>" <?php echo ($_POST['parent_id'] ?? '') == $node['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($node['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint">
                            Zostaw puste jeśli tworzysz etap główny, wybierz rodzica jeśli etap podrzędny
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Kolejność sortowania</label>
                    <input 
                        type="number" 
                        name="sort_order" 
                        value="<?php echo e($_POST['sort_order'] ?? 0); ?>"
                        min="0"
                    >
                    <div class="hint">
                        Niższy numer = wyższa pozycja na liście. Domyślnie: 0
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Utwórz etap</button>
                    <a href="etapy-kosztow.php?project_id=<?php echo $project_id; ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>


