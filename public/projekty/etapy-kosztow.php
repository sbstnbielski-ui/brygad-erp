<?php
/**
 * BRYGAD ERP v3.0 - Struktura Kosztów Projektu
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$project_id = $_GET['project_id'] ?? null;

if (!$project_id) {
    header("Location: " . url('projekty'));
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
$stmt->execute(['id' => $project_id]);
$project = $stmt->fetch();

if (!$project) {
    header("Location: " . url('projekty'));
    exit;
}

// Pobierz wszystkie etapy
$stmt = $pdo->prepare("
    SELECT 
        pcn.*,
        (SELECT COUNT(*) FROM project_cost_nodes WHERE parent_id = pcn.id) as children_count,
        (SELECT COUNT(*) FROM work_logs WHERE cost_node_id = pcn.id) as logs_count,
        (
            (SELECT COUNT(*) FROM worker_expenses WHERE cost_node_id = pcn.id)
            +
            (SELECT COUNT(*) FROM finance_items WHERE etap_id = pcn.id AND item_type = 'FIXED_COST')
        ) as expenses_count
    FROM project_cost_nodes pcn
    WHERE pcn.project_id = :project_id
    ORDER BY sort_order ASC
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

$success_message = '';
if (isset($_GET['success'])) {
    $messages = [
        'created' => 'Etap został utworzony pomyślnie',
        'updated' => 'Etap został zaktualizowany pomyślnie',
        'deactivated' => 'Etap został dezaktywowany'
    ];
    $success_message = $messages[$_GET['success']] ?? '';
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Struktura Kosztów</title>
    <link rel="stylesheet" href="/projekty/assets/projekty.css?v=20260205b">
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('projekty'); ?>">Projekty</a> /
                    <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>"><?php echo e($project['name']); ?></a> /
                    Struktura Kosztów
                </div>
                <h1>Struktura Kosztów</h1>
                <p><?php echo e($project['name']); ?></p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('projekty.etapy.create', ['project_id' => $project_id]); ?>" class="btn-hero-primary">+ Dodaj Etap</a>
                <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>" class="btn-hero-secondary">← Powrót</a>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo e($success_message); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h3>O strukturze kosztów</h3>
            <p>Struktura kosztów to drzewo etapów, do których przypisywane są koszty projektu. Każdy koszt (robocizna, wydatek, faktura) musi trafić do konkretnego etapu.</p>
        </div>
        
        <?php if (!empty($nodes)): ?>
            <div class="tree-container">
            <?php
            $root_nodes = $nodes_by_parent['root'] ?? [];
            foreach ($root_nodes as $node):
            ?>
                <div class="tree-node">
                    <div class="node-header <?php echo !$node['is_active'] ? 'inactive' : ''; ?>">
                        <div class="node-info">
                            <div class="node-name">
                                <a href="<?php echo url('projekty.etap.view', ['id' => $node['id']]); ?>" style="color: inherit; text-decoration: none;">
                                    <?php echo e($node['name']); ?>
                                </a>
                                <span class="badge <?php echo $node['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo $node['is_active'] ? 'Aktywny' : 'Nieaktywny'; ?>
                                </span>
                                <?php if (!empty($node['description'])): ?>
                                    <br><span style="font-size: 13px; color: #6b7280; font-weight: normal; margin-top: 4px; display: block;">
                                        <?php 
                                        $desc = $node['description'];
                                        echo e(mb_strlen($desc) > 120 ? mb_substr($desc, 0, 120) . '…' : $desc); 
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="node-meta">
                                <span>Podrzędne: <?php echo $node['children_count']; ?></span>
                                <span>Wpisy: <?php echo $node['logs_count']; ?></span>
                                <span>Wydatki: <?php echo $node['expenses_count']; ?></span>
                            </div>
                        </div>
                        <div class="node-actions">
                            <a href="<?php echo url('projekty.etap.view', ['id' => $node['id']]); ?>">Szczegóły</a>
                            <a href="<?php echo url('projekty.etapy.create', ['project_id' => $project_id, 'parent_id' => $node['id']]); ?>">+ Podrzędny</a>
                            <?php if ($isAdminUser): ?>
                                <a href="<?php echo url('projekty.etapy.edit', ['id' => $node['id']]); ?>">Edytuj</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php
                    $children = $nodes_by_parent[$node['id']] ?? [];
                    foreach ($children as $child):
                    ?>
                        <div class="tree-node">
                            <div class="node-header child <?php echo !$child['is_active'] ? 'inactive' : ''; ?>">
                                <div class="node-info">
                                    <div class="node-name">
                                        <a href="<?php echo url('projekty.etap.view', ['id' => $child['id']]); ?>" style="color: inherit; text-decoration: none;">
                                            <?php echo e($child['name']); ?>
                                        </a>
                                        <span class="badge <?php echo $child['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                            <?php echo $child['is_active'] ? 'Aktywny' : 'Nieaktywny'; ?>
                                        </span>
                                        <?php if (!empty($child['description'])): ?>
                                            <br><span style="font-size: 13px; color: #6b7280; font-weight: normal; margin-top: 4px; display: block;">
                                                <?php 
                                                $desc = $child['description'];
                                                echo e(mb_strlen($desc) > 120 ? mb_substr($desc, 0, 120) . '…' : $desc); 
                                                ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="node-meta">
                                        <span>Wpisy: <?php echo $child['logs_count']; ?></span>
                                        <span>Wydatki: <?php echo $child['expenses_count']; ?></span>
                                    </div>
                                </div>
                                <div class="node-actions">
                                    <a href="<?php echo url('projekty.etap.view', ['id' => $child['id']]); ?>">Szczegóły</a>
                                    <?php if ($isAdminUser): ?>
                                        <a href="<?php echo url('projekty.etapy.edit', ['id' => $child['id']]); ?>">Edytuj</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="no-data">
                    <p>Nie zdefiniowano jeszcze struktury kosztów dla tego projektu</p>
                    <p style="margin-top: 20px;">
                        <a href="<?php echo url('projekty.etapy.create', ['project_id' => $project_id]); ?>" class="btn btn-primary">Dodaj pierwszy etap</a>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
