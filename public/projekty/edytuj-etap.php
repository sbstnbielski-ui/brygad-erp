<?php
// TYMCZASOWE DEBUGOWANIE - usuń po naprawie
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/config/autoload.php'; // 1 poziom w dół
startSecureSession();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$node_id = $_GET['id'] ?? null;

if (!$node_id) {
    header("Location: " . url('projekty'));
    exit;
}

// Pobierz etap
$stmt = $pdo->prepare("SELECT * FROM project_cost_nodes WHERE id = :id");
$stmt->execute(['id' => $node_id]);
$node = $stmt->fetch();

if (!$node) {
    header("Location: " . url('projekty'));
    exit;
}

$project_id = $node['project_id'];

// Pobierz projekt
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
$stmt->execute(['id' => $project_id]);
$project = $stmt->fetch();

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// Sprawdź czy można dezaktywować
$stmt = $pdo->prepare("
    SELECT COUNT(*) as usage_count
    FROM (
        SELECT id FROM work_logs WHERE cost_node_id = :node_id1
        UNION ALL
        SELECT id FROM worker_expenses WHERE cost_node_id = :node_id2
        UNION ALL
        SELECT id FROM document_allocations WHERE cost_node_id = :node_id3
    ) as usages
");
$stmt->execute(['node_id1' => $node_id, 'node_id2' => $node_id, 'node_id3' => $node_id]);
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
                
                header("Location: " . url('projekty.etapy', ['project_id' => $project_id, 'success' => 'deleted']));
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
                
                header("Location: " . url('projekty.etapy', ['project_id' => $project_id, 'success' => 'deactivated']));
                exit;
                
            } catch (PDOException $e) {
                logEvent("Błąd dezaktywacji etapu: " . $e->getMessage(), 'error');
                $errors[] = "Błąd podczas dezaktywacji etapu";
            }
        }
    } else {
        // Aktualizacja
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
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
                        description = :description,
                        sort_order = :sort_order,
                        is_active = :is_active
                    WHERE id = :id
                ");
                $stmt->execute([
                    'name' => $name,
                    'description' => $description,
                    'sort_order' => $sort_order,
                    'is_active' => $is_active,
                    'id' => $node_id
                ]);
                
                logEvent("Zaktualizowano etap ID: {$node_id} ({$name})", 'info');
                
                header("Location: " . url('projekty.etapy', ['project_id' => $project_id, 'success' => 'updated']));
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
        'description' => $node['description'] ?? '',
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
    <link rel="stylesheet" href="/projekty/assets/projekty.css">
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo url('projekty'); ?>">Projekty</a> / 
            <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>"><?php echo e($project['name']); ?></a> / 
            <a href="<?php echo url('projekty.etapy', ['project_id' => $project_id]); ?>">Etapy</a> / 
            Edycja
        </div>
        
        <div class="page-header">
            <h2>Edytuj etap: <?php echo e($node['name']); ?></h2>
            <div class="actions">
                <a href="<?php echo url('projekty.etapy', ['project_id' => $project_id]); ?>" class="btn btn-secondary">← Powrót</a>
            </div>
        </div>
        <div class="card">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($usage['usage_count'] > 0): ?>
                <div class="alert alert-info">
                    <strong>Ten etap ma przypisane koszty:</strong>
                    <?php echo $usage['usage_count']; ?> pozycji (wpisy pracy, wydatki, faktury)
                </div>
            <?php endif; ?>
            
            <?php if ($children['children_count'] > 0): ?>
                <div class="alert alert-warning">
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
                    <label>Opis etapu</label>
                    <textarea 
                        name="description" 
                        rows="3" 
                        placeholder="Opcjonalny opis etapu, cel, zakres prac..."
                    ><?php echo e($_POST['description'] ?? ''); ?></textarea>
                    <div class="hint">
                        Krótki opis ułatwi identyfikację etapu
                    </div>
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
                    <a href="<?php echo url('projekty.etapy', ['project_id' => $project_id]); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
            
            <?php if ($children['children_count'] === 0): ?>
                <div style="margin-top: 40px; padding-top: 30px; border-top: 2px solid #dee2e6;">
                    <h3 style="color: #721c24; margin-bottom: 15px;">Strefa niebezpieczna</h3>
                    
                    <?php if ($usage['usage_count'] > 0): ?>
                        <div class="alert alert-warning" style="margin-bottom: 20px;">
                            <strong>Ten etap ma przypisane koszty (<?php echo $usage['usage_count']; ?> pozycji)</strong><br>
                            Nie można go usunąć, ale możesz go dezaktywować - zachowa dane historyczne.
                        </div>
                        <form method="POST" onsubmit="return confirm('Czy na pewno chcesz dezaktywować ten etap? Będzie ukryty, ale dane historyczne zostaną zachowane.');">
                            <input type="hidden" name="action" value="deactivate">
                            <button type="submit" class="btn btn-warning">Dezaktywuj etap</button>
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
                                <button type="submit" class="btn btn-warning">Dezaktywuj etap</button>
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
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>

