<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
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

// Pobierz strukturę kosztów (etapy)
$stmt = $pdo->prepare("
    SELECT * FROM project_cost_nodes 
    WHERE project_id = :project_id 
    ORDER BY parent_id IS NULL DESC, sort_order, name
");
$stmt->execute(['project_id' => $project_id]);
$cost_nodes = $stmt->fetchAll();

// Robocizna - podsumowanie
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as logs_count,
        SUM(hours) as total_hours,
        SUM(overtime_hours) as total_overtime,
        SUM(final_cost) as total_cost
    FROM work_logs
    WHERE project_id = :project_id AND status = 'approved'
");
$stmt->execute(['project_id' => $project_id]);
$labor_summary = $stmt->fetch();

// Robocizna - per pracownik
$stmt = $pdo->prepare("
    SELECT 
        w.id,
        w.first_name,
        w.last_name,
        COUNT(wl.id) as logs_count,
        SUM(wl.hours) as total_hours,
        SUM(wl.overtime_hours) as total_overtime,
        SUM(wl.final_cost) as total_cost
    FROM workers w
    JOIN work_logs wl ON wl.worker_id = w.id
    WHERE wl.project_id = :project_id AND wl.status = 'approved'
    GROUP BY w.id
    ORDER BY total_cost DESC
");
$stmt->execute(['project_id' => $project_id]);
$labor_by_worker = $stmt->fetchAll();

// Wydatki pracowników - podsumowanie
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as expenses_count,
        SUM(amount) as total_amount
    FROM worker_expenses
    WHERE project_id = :project_id AND status = 'approved'
");
$stmt->execute(['project_id' => $project_id]);
$expenses_summary = $stmt->fetch();

// Wydatki - per pracownik
$stmt = $pdo->prepare("
    SELECT 
        w.id,
        w.first_name,
        w.last_name,
        COUNT(we.id) as expenses_count,
        SUM(we.amount) as total_amount
    FROM workers w
    JOIN worker_expenses we ON we.worker_id = w.id
    WHERE we.project_id = :project_id AND we.status = 'approved'
    GROUP BY w.id
    ORDER BY total_amount DESC
");
$stmt->execute(['project_id' => $project_id]);
$expenses_by_worker = $stmt->fetchAll();

// Faktury (przygotowanie na przyszłość)
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT ca.invoice_id) as invoices_count,
        SUM(ca.amount) as total_amount
    FROM cost_allocations ca
    WHERE ca.project_id = :project_id
");
$stmt->execute(['project_id' => $project_id]);
$invoices_summary = $stmt->fetch();

// Koszt całkowity
$total_cost = ($labor_summary['total_cost'] ?? 0) + 
              ($expenses_summary['total_amount'] ?? 0) + 
              ($invoices_summary['total_amount'] ?? 0);

$status_labels = [
    'planned' => 'Planowany',
    'active' => 'Aktywny',
    'finished' => 'Zakończony'
];

$success_message = '';
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'created') {
        $success_message = 'Projekt został utworzony pomyślnie';
    } elseif ($_GET['success'] === 'updated') {
        $success_message = 'Projekt został zaktualizowany pomyślnie';
    }
}

$error_message = '';
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'internal') {
        $error_message = 'Nie można edytować projektu wewnętrznego';
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo e($project['name']); ?></title>
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
            padding: 15px 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .header-left {
            display: flex;
            align-items: center;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
        }
        
        .header-links {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .header-links a {
            color: #667eea;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 5px;
            transition: background 0.3s;
            font-size: 14px;
        }
        
        .header-links a:hover {
            background: #f0f0f0;
        }
        
        .header-links a.active {
            background: #667eea;
            color: white;
            font-weight: 600;
        }
        
        .project-meta {
            display: flex;
            gap: 20px;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }
        
        .project-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
            font-size: 14px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-planned {
            background: #ffc107;
            color: #000;
        }
        
        .badge-active {
            background: #28a745;
            color: white;
        }
        
        .badge-finished {
            background: #6c757d;
            color: white;
        }
        
        .badge-internal {
            background: #17a2b8;
            color: white;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .cost-summary {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .cost-summary h2 {
            color: #333;
            font-size: 20px;
            margin-bottom: 20px;
        }
        
        .cost-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .cost-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .cost-item-label {
            color: #666;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .cost-item-value {
            color: #333;
            font-size: 24px;
            font-weight: bold;
        }
        
        .cost-item-total {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .cost-item-total .cost-item-label {
            color: rgba(255,255,255,0.9);
        }
        
        .cost-item-total .cost-item-value {
            color: white;
        }
        
        .section {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .section h2 {
            color: #333;
            font-size: 20px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            color: #666;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
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
        
        .actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .tab {
            padding: 12px 20px;
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .tab:hover {
            color: #667eea;
        }
        
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
            <div class="header-left">
                <img src="assets/logo-brygad-erp.png" alt="BRYGAD ERP" style="height: 40px; border-radius: 5px;">
                <div style="margin-left: 15px;">
                    <h1 style="font-size: 20px; margin: 0;">BRYGAD ERP</h1>
                    <p style="font-size: 12px; color: #666; margin: 0;">System Zarządzania Operacyjnego</p>
                </div>
            </div>
            <div class="header-links">
                <a href="dashboard.php">Panel Główny</a>
                <a href="workers.php">Pracownicy</a>
                <a href="projekty.php" class="active">Projekty</a>
                <a href="faktury.php">Finanse</a>
                <a href="raporty-kosztow.php">Raporty</a>
                <span style="color: #666; margin: 0 10px;">|</span>
                <span style="color: #333; font-weight: 600;"><?php echo e($_SESSION['login'] ?? 'Użytkownik'); ?></span>
                <?php if (isAdmin()): ?>
                    <span style="font-size: 11px; color: #667eea;">(Administrator)</span>
                <?php endif; ?>
                <a href="logout.php" style="color: #dc3545;">Wyloguj</a>
            </div>
        </div>
        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="font-size: 24px; color: #333; margin: 0;"><?php echo e($project['name']); ?></h2>
            <div>
                <a href="projekty.php" style="color: #667eea; text-decoration: none; padding: 8px 12px;">← Powrót</a>
                <?php if (!$project['is_internal'] && isAdmin()): ?>
                    <a href="edytuj-projekt.php?id=<?php echo $project_id; ?>" style="color: #667eea; text-decoration: none; padding: 8px 12px;">Edytuj</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="project-meta">
            <div class="project-meta-item">
                <span class="badge badge-<?php echo $project['status']; ?>">
                    <?php echo $status_labels[$project['status']]; ?>
                </span>
            </div>
            <?php if ($project['is_internal']): ?>
                <div class="project-meta-item">
                    <span class="badge badge-internal">Projekt Wewnętrzny</span>
                </div>
            <?php endif; ?>
            <?php if ($project['start_date']): ?>
                <div class="project-meta-item">
                    <strong>Start:</strong> <?php echo formatDate($project['start_date']); ?>
                </div>
            <?php endif; ?>
            <?php if ($project['end_date']): ?>
                <div class="project-meta-item">
                    <strong>Koniec:</strong> <?php echo formatDate($project['end_date']); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="container">
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?php echo e($success_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <?php echo e($error_message); ?>
            </div>
        <?php endif; ?>
        
        <div class="cost-summary">
            <h2>Podsumowanie kosztów</h2>
            <div class="cost-grid">
                <div class="cost-item">
                    <div class="cost-item-label">Robocizna</div>
                    <div class="cost-item-value"><?php echo formatMoney($labor_summary['total_cost'] ?? 0); ?></div>
                </div>
                <div class="cost-item">
                    <div class="cost-item-label">Wydatki pracowników</div>
                    <div class="cost-item-value"><?php echo formatMoney($expenses_summary['total_amount'] ?? 0); ?></div>
                </div>
                <div class="cost-item">
                    <div class="cost-item-label">Faktury zewnętrzne</div>
                    <div class="cost-item-value"><?php echo formatMoney($invoices_summary['total_amount'] ?? 0); ?></div>
                </div>
                <div class="cost-item cost-item-total">
                    <div class="cost-item-label">Koszt całkowity</div>
                    <div class="cost-item-value"><?php echo formatMoney($total_cost); ?></div>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2>Szczegóły kosztów</h2>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('labor')">
                    Robocizna (<?php echo $labor_summary['logs_count'] ?? 0; ?> wpisów)
                </button>
                <button class="tab" onclick="switchTab('expenses')">
                    Wydatki (<?php echo $expenses_summary['expenses_count'] ?? 0; ?> pozycji)
                </button>
                <button class="tab" onclick="switchTab('nodes')">
                    Etapy (<?php echo count($cost_nodes); ?>)
                </button>
            </div>
            
            <div id="tab-labor" class="tab-content active">
                <?php if (!empty($labor_by_worker)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Pracownik</th>
                                    <th>Liczba wpisów</th>
                                    <th>Godziny normalne</th>
                                    <th>Nadgodziny</th>
                                    <th>Koszt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($labor_by_worker as $worker): ?>
                                    <tr>
                                        <td><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?></td>
                                        <td><?php echo $worker['logs_count']; ?></td>
                                        <td><?php echo number_format($worker['total_hours'], 2); ?> h</td>
                                        <td><?php echo number_format($worker['total_overtime'], 2); ?> h</td>
                                        <td><strong><?php echo formatMoney($worker['total_cost']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        Brak wpisów robocizny dla tego projektu
                    </div>
                <?php endif; ?>
            </div>
            
            <div id="tab-expenses" class="tab-content">
                <?php if (!empty($expenses_by_worker)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Pracownik</th>
                                    <th>Liczba wydatków</th>
                                    <th>Łączna kwota</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses_by_worker as $worker): ?>
                                    <tr>
                                        <td><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?></td>
                                        <td><?php echo $worker['expenses_count']; ?></td>
                                        <td><strong><?php echo formatMoney($worker['total_amount']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        Brak wydatków dla tego projektu
                    </div>
                <?php endif; ?>
            </div>
            
            <div id="tab-nodes" class="tab-content">
                <?php if (!empty($cost_nodes)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nazwa etapu</th>
                                    <th>Poziom</th>
                                    <th>Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cost_nodes as $node): ?>
                                    <tr>
                                        <td><?php echo e($node['name']); ?></td>
                                        <td><?php echo $node['parent_id'] ? 'Podrzędny' : 'Główny'; ?></td>
                                        <td>
                                            <a href="etapy-kosztow.php?project_id=<?php echo $project_id; ?>&node_id=<?php echo $node['id']; ?>">
                                                Szczegóły
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="actions" style="margin-top: 20px;">
                        <a href="etapy-kosztow.php?project_id=<?php echo $project_id; ?>" class="btn btn-primary">
                            Zarządzaj etapami
                        </a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p>Nie zdefiniowano jeszcze struktury kosztów</p>
                        <p style="margin-top: 10px;">
                            <a href="etapy-kosztow.php?project_id=<?php echo $project_id; ?>" class="btn btn-primary">
                                Dodaj pierwszy etap
                            </a>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            // Ukryj wszystkie taby
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Pokaż wybrany tab
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>

