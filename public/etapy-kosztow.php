<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$project_id = $_GET['project_id'] ?? null;

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

// Pobierz wszystkie etapy (nodes) dla projektu
$stmt = $pdo->prepare("
    SELECT 
        pcn.*,
        (SELECT COUNT(*) FROM project_cost_nodes WHERE parent_id = pcn.id) as children_count,
        (SELECT COUNT(*) FROM work_logs WHERE cost_node_id = pcn.id) as logs_count,
        (SELECT COUNT(*) FROM worker_expenses WHERE cost_node_id = pcn.id) as expenses_count
    FROM project_cost_nodes pcn
    WHERE pcn.project_id = :project_id
    ORDER BY parent_id IS NULL DESC, sort_order, name
");
$stmt->execute(['project_id' => $project_id]);
$nodes = $stmt->fetchAll();

// Grupuj węzły według parent_id
$nodes_by_parent = [];
foreach ($nodes as $node) {
    $parent_id = $node['parent_id'] ?? 'root';
    if (!isset($nodes_by_parent[$parent_id])) {
        $nodes_by_parent[$parent_id] = [];
    }
    $nodes_by_parent[$parent_id][] = $node;
}

// Komunikaty
$success_message = '';
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'created') {
        $success_message = 'Etap został utworzony pomyślnie';
    } elseif ($_GET['success'] === 'updated') {
        $success_message = 'Etap został zaktualizowany pomyślnie';
    } elseif ($_GET['success'] === 'deactivated') {
        $success_message = 'Etap został dezaktywowany';
    }
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Struktura kosztów</title>
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
        
        .header-links {
            display: flex;
            gap: 15px;
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
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 5px;
            margin-bottom: 20px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
        }
        
        .tree-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        
        .tree-intro {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .tree-intro h2 {
            color: #333;
            font-size: 20px;
            margin-bottom: 10px;
        }
        
        .tree-intro p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .tree-node {
            margin-bottom: 10px;
        }
        
        .node-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .node-header.inactive {
            opacity: 0.6;
            border-left-color: #dee2e6;
        }
        
        .node-header.child {
            margin-left: 40px;
            border-left-color: #28a745;
        }
        
        .node-info {
            flex: 1;
        }
        
        .node-name {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }
        
        .node-meta {
            display: flex;
            gap: 20px;
            margin-top: 8px;
            font-size: 13px;
            color: #666;
        }
        
        .node-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .node-actions {
            display: flex;
            gap: 10px;
        }
        
        .node-actions a {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
            padding: 5px 10px;
            border-radius: 4px;
            transition: background 0.3s;
        }
        
        .node-actions a:hover {
            background: white;
        }
        
        .badge {
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            background: #6c757d;
            color: white;
        }
        
        .badge-active {
            background: #28a745;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .example-tree {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .example-tree h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 15px;
        }
        
        .example-tree ul {
            list-style: none;
            padding-left: 20px;
        }
        
        .example-tree li {
            color: #666;
            font-size: 14px;
            margin: 8px 0;
            position: relative;
        }
        
        .example-tree li:before {
            content: "→";
            position: absolute;
            left: -15px;
            color: #667eea;
        }
        
        .example-tree ul ul {
            padding-left: 30px;
        }
        
        .example-tree ul ul li:before {
            content: "↳";
            color: #28a745;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Struktura kosztów projektu</h1>
            <div class="header-subtitle"><?php echo e($project['name']); ?></div>
        </div>
        <div class="header-links">
            <a href="widok-projektu.php?id=<?php echo $project_id; ?>">← Powrót do projektu</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($success_message): ?>
            <div class="alert">
                <?php echo e($success_message); ?>
            </div>
        <?php endif; ?>
        
        <div class="actions">
            <a href="dodaj-etap.php?project_id=<?php echo $project_id; ?>" class="btn btn-success">
                + Dodaj etap główny
            </a>
        </div>
        
        <div class="tree-container">
            <div class="tree-intro">
                <h2>O strukturze kosztów</h2>
                <p>
                    Struktura kosztów to drzewo etapów, do których przypisywane są koszty projektu.
                    Każdy koszt (robocizna, wydatek, faktura) musi trafić do konkretnego etapu.
                    Możesz tworzyć etapy główne oraz podrzędne (np. "Materiały" → "Zakup stali").
                </p>
                
                <div class="example-tree">
                    <h3>Przykładowa struktura:</h3>
                    <ul>
                        <li>Robocizna
                            <ul>
                                <li>Montaż</li>
                                <li>Spawanie</li>
                            </ul>
                        </li>
                        <li>Materiały
                            <ul>
                                <li>Zakup towarów</li>
                                <li>Malowanie</li>
                            </ul>
                        </li>
                        <li>Sprzęt</li>
                        <li>Transport</li>
                    </ul>
                </div>
            </div>
            
            <?php if (!empty($nodes)): ?>
                <?php
                // Wyświetl etapy główne (parent_id = NULL)
                $root_nodes = $nodes_by_parent['root'] ?? [];
                foreach ($root_nodes as $node):
                ?>
                    <div class="tree-node">
                        <div class="node-header <?php echo !$node['is_active'] ? 'inactive' : ''; ?>">
                            <div class="node-info">
                                <div class="node-name">
                                    <?php echo e($node['name']); ?>
                                    <?php if (!$node['is_active']): ?>
                                        <span class="badge">Nieaktywny</span>
                                    <?php else: ?>
                                        <span class="badge badge-active">Aktywny</span>
                                    <?php endif; ?>
                                </div>
                                <div class="node-meta">
                                    <div class="node-meta-item">
                                        Podrzędne: <?php echo $node['children_count']; ?>
                                    </div>
                                    <div class="node-meta-item">
                                        Wpisy: <?php echo $node['logs_count']; ?>
                                    </div>
                                    <div class="node-meta-item">
                                        Wydatki: <?php echo $node['expenses_count']; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="node-actions">
                                <a href="dodaj-etap.php?project_id=<?php echo $project_id; ?>&parent_id=<?php echo $node['id']; ?>">
                                    + Dodaj podrzędny
                                </a>
                                <?php if (isAdmin()): ?>
                                    <a href="edytuj-etap.php?id=<?php echo $node['id']; ?>">Edytuj</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php
                        // Wyświetl etapy podrzędne
                        $children = $nodes_by_parent[$node['id']] ?? [];
                        foreach ($children as $child):
                        ?>
                            <div class="tree-node">
                                <div class="node-header child <?php echo !$child['is_active'] ? 'inactive' : ''; ?>">
                                    <div class="node-info">
                                        <div class="node-name">
                                            <?php echo e($child['name']); ?>
                                            <?php if (!$child['is_active']): ?>
                                                <span class="badge">Nieaktywny</span>
                                            <?php else: ?>
                                                <span class="badge badge-active">Aktywny</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="node-meta">
                                            <div class="node-meta-item">
                                                Wpisy: <?php echo $child['logs_count']; ?>
                                            </div>
                                            <div class="node-meta-item">
                                                Wydatki: <?php echo $child['expenses_count']; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="node-actions">
                                        <?php if (isAdmin()): ?>
                                            <a href="edytuj-etap.php?id=<?php echo $child['id']; ?>">Edytuj</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>Nie zdefiniowano jeszcze struktury kosztów dla tego projektu</p>
                    <p style="margin-top: 20px;">
                        <a href="dodaj-etap.php?project_id=<?php echo $project_id; ?>" class="btn btn-success">
                            Dodaj pierwszy etap
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>


