<?php
/**
 * BRYGAD ERP v3.0 - Stawki Tabela
 * Szybka edycja stawek w formie tabeli (pracownicy x typy stawek)
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$success = false;
$errors = [];

// Filtry
$filterProject = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$filterCostNode = isset($_GET['cost_node_id']) ? (int)$_GET['cost_node_id'] : 0;

// Pobierz projekty dla filtra
try {
    $stmt = $pdo->query("SELECT id, name FROM projects WHERE status IN ('active', 'planned') ORDER BY name");
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    $projects = [];
}

// Pobierz etapy (cost nodes) dla wybranego projektu
$costNodes = [];
if ($filterProject > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM project_cost_nodes WHERE project_id = ? AND is_active = 1 ORDER BY sort_order, name");
        $stmt->execute([$filterProject]);
        $costNodes = $stmt->fetchAll();
    } catch (PDOException $e) {
        $costNodes = [];
    }
}

// Obsługa zapisu (AJAX lub POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_rate') {
        // AJAX - zapis pojedynczej stawki
        header('Content-Type: application/json');
        
        $workerId = (int)($_POST['worker_id'] ?? 0);
        $rateType = $_POST['rate_type'] ?? '';
        $rateValue = trim($_POST['rate_value'] ?? '');
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $costNodeId = !empty($_POST['cost_node_id']) ? (int)$_POST['cost_node_id'] : null;
        
        if ($workerId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Nieprawidłowy ID pracownika']);
            exit;
        }
        
        $allowedTypes = ['base_rate', 'overtime_rate', 'saturday_rate', 'saturday_overtime_rate', 
                         'sunday_rate', 'sunday_overtime_rate', 'night_rate', 'night_overtime_rate',
                         'delegation_rate', 'delegation_overtime_rate', 'vacation_rate', 'sick_rate'];
        
        if (!in_array($rateType, $allowedTypes)) {
            echo json_encode(['success' => false, 'error' => 'Nieprawidłowy typ stawki']);
            exit;
        }
        
        $rateValue = $rateValue === '' ? null : (float)$rateValue;
        
        try {
            $pdo->beginTransaction();
            
            // Sprawdź czy istnieje stawka
            $sql = "SELECT id FROM worker_rates WHERE worker_id = ? AND valid_to IS NULL";
            $params = [$workerId];
            
            if ($projectId) {
                $sql .= " AND project_id = ?";
                $params[] = $projectId;
            } else {
                $sql .= " AND project_id IS NULL";
            }
            
            if ($costNodeId) {
                $sql .= " AND cost_node_id = ?";
                $params[] = $costNodeId;
            } else {
                $sql .= " AND cost_node_id IS NULL";
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Aktualizuj
                $updateSql = "UPDATE worker_rates SET {$rateType} = ? WHERE id = ?";
                $stmt = $pdo->prepare($updateSql);
                $stmt->execute([$rateValue, $existing['id']]);
            } else {
                // Utwórz nowy wpis
                $columns = ['worker_id', 'project_id', 'cost_node_id', 'base_rate', 'valid_from'];
                $values = ['?', '?', '?', '0', 'CURDATE()'];
                $insertParams = [$workerId, $projectId, $costNodeId];
                
                // Dodaj kolumnę która jest edytowana
                if ($rateType !== 'base_rate') {
                    $columns[] = $rateType;
                    $values[] = '?';
                    $insertParams[] = $rateValue;
                }
                
                $insertSql = "INSERT INTO worker_rates (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
                $stmt = $pdo->prepare($insertSql);
                $stmt->execute($insertParams);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true]);
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
    
    if ($action === 'copy_rates') {
        // Kopiowanie stawek z jednego pracownika do innych
        $sourceWorkerId = (int)($_POST['source_worker_id'] ?? 0);
        $targetWorkerIds = $_POST['target_worker_ids'] ?? [];
        $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
        $costNodeId = !empty($_POST['cost_node_id']) ? (int)$_POST['cost_node_id'] : null;
        
        if ($sourceWorkerId <= 0 || empty($targetWorkerIds)) {
            $errors[] = 'Wybierz źródłowego i docelowych pracowników.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Pobierz stawki źródłowe
                $sql = "SELECT * FROM worker_rates WHERE worker_id = ? AND valid_to IS NULL";
                $params = [$sourceWorkerId];
                
                if ($projectId) {
                    $sql .= " AND project_id = ?";
                    $params[] = $projectId;
                } else {
                    $sql .= " AND project_id IS NULL";
                }
                
                if ($costNodeId) {
                    $sql .= " AND cost_node_id = ?";
                    $params[] = $costNodeId;
                } else {
                    $sql .= " AND cost_node_id IS NULL";
                }
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $sourceRate = $stmt->fetch();
                
                if ($sourceRate) {
                    foreach ($targetWorkerIds as $targetWorkerId) {
                        if ($targetWorkerId == $sourceWorkerId) continue;
                        
                        // Sprawdź czy istnieje
                        $checkSql = "SELECT id FROM worker_rates WHERE worker_id = ? AND valid_to IS NULL";
                        $checkParams = [$targetWorkerId];
                        
                        if ($projectId) {
                            $checkSql .= " AND project_id = ?";
                            $checkParams[] = $projectId;
                        } else {
                            $checkSql .= " AND project_id IS NULL";
                        }
                        
                        if ($costNodeId) {
                            $checkSql .= " AND cost_node_id = ?";
                            $checkParams[] = $costNodeId;
                        } else {
                            $checkSql .= " AND cost_node_id IS NULL";
                        }
                        
                        $stmt = $pdo->prepare($checkSql);
                        $stmt->execute($checkParams);
                        $existing = $stmt->fetch();
                        
                        if ($existing) {
                            // Aktualizuj
                            $updateSql = "UPDATE worker_rates SET 
                                base_rate = ?, overtime_rate = ?, saturday_rate = ?, saturday_overtime_rate = ?,
                                sunday_rate = ?, sunday_overtime_rate = ?, night_rate = ?, night_overtime_rate = ?,
                                delegation_rate = ?, delegation_overtime_rate = ?, vacation_rate = ?, sick_rate = ?
                                WHERE id = ?";
                            $stmt = $pdo->prepare($updateSql);
                            $stmt->execute([
                                $sourceRate['base_rate'], $sourceRate['overtime_rate'],
                                $sourceRate['saturday_rate'], $sourceRate['saturday_overtime_rate'],
                                $sourceRate['sunday_rate'], $sourceRate['sunday_overtime_rate'],
                                $sourceRate['night_rate'], $sourceRate['night_overtime_rate'],
                                $sourceRate['delegation_rate'], $sourceRate['delegation_overtime_rate'],
                                $sourceRate['vacation_rate'], $sourceRate['sick_rate'],
                                $existing['id']
                            ]);
                        } else {
                            // Wstaw nowy
                            $insertSql = "INSERT INTO worker_rates 
                                (worker_id, project_id, cost_node_id, base_rate, overtime_rate,
                                 saturday_rate, saturday_overtime_rate, sunday_rate, sunday_overtime_rate,
                                 night_rate, night_overtime_rate, delegation_rate, delegation_overtime_rate,
                                 vacation_rate, sick_rate, valid_from)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())";
                            $stmt = $pdo->prepare($insertSql);
                            $stmt->execute([
                                $targetWorkerId, $projectId, $costNodeId,
                                $sourceRate['base_rate'], $sourceRate['overtime_rate'],
                                $sourceRate['saturday_rate'], $sourceRate['saturday_overtime_rate'],
                                $sourceRate['sunday_rate'], $sourceRate['sunday_overtime_rate'],
                                $sourceRate['night_rate'], $sourceRate['night_overtime_rate'],
                                $sourceRate['delegation_rate'], $sourceRate['delegation_overtime_rate'],
                                $sourceRate['vacation_rate'], $sourceRate['sick_rate']
                            ]);
                        }
                    }
                }
                
                $pdo->commit();
                $success = true;
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Błąd kopiowania stawek.';
            }
        }
    }
}

// Pobierz pracowników z aktualnymi stawkami
try {
    $sql = "SELECT 
                w.id,
                w.first_name,
                w.last_name,
                w.is_active,
                wr.base_rate,
                wr.overtime_rate,
                wr.saturday_rate,
                wr.saturday_overtime_rate,
                wr.sunday_rate,
                wr.sunday_overtime_rate,
                wr.night_rate,
                wr.night_overtime_rate,
                wr.delegation_rate,
                wr.delegation_overtime_rate,
                wr.vacation_rate,
                wr.sick_rate
            FROM workers w
            LEFT JOIN worker_rates wr ON wr.worker_id = w.id 
                AND wr.valid_to IS NULL
                AND ((? = 0 AND wr.project_id IS NULL) OR (? > 0 AND wr.project_id = ?))
                AND ((? = 0 AND wr.cost_node_id IS NULL) OR (? > 0 AND wr.cost_node_id = ?))
            WHERE w.is_active = 1
            ORDER BY w.last_name, w.first_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $filterProject, $filterProject, $filterProject,
        $filterCostNode, $filterCostNode, $filterCostNode
    ]);
    $workers = $stmt->fetchAll();
} catch (PDOException $e) {
    $workers = [];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Stawki Tabela</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px; }
        /* Override header */
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }
        .container { max-width: 1800px; margin: 0 auto; padding: 25px; }
        /* Hero */
        .page-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;
        }
        .page-header h2 { font-size: 26px; font-weight: 700; color: #fff; margin-bottom: 4px; letter-spacing: -0.3px; }
        .page-header p { color: #cbd5e1; font-size: 14px; margin-top: 2px; }
        .page-header .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .page-header .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .page-header-actions { display: flex; gap: 8px; align-items: flex-start; }
        .btn-hero-light { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25); color: #ffffff; padding: 8px 14px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-light:hover { background: rgba(255,255,255,0.2); }
        
        /* SPX FILTER SYSTEM */
        .spx-filter-bar {
            background: white;
            padding: 14px 16px;
            border-radius: 0;
            border-bottom: 1px solid #eef2f7;
            margin-bottom: 0;
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: nowrap;
        }
        .card .spx-filter-bar { border-radius: 0; }
        .spx-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .spx-filter-group.fg-project  { flex: 2 1 0; }
        .spx-filter-group.fg-stage    { flex: 2 1 0; }
        .spx-filter-group label {
            font-size: 10px; font-weight: 600; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.6px; white-space: nowrap;
        }
        .spx-filter-group select {
            padding: 0 10px;
            height: 38px;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            font-size: 13px;
            background: #f8fafc;
            width: 100%;
            background: white;
            font-family: inherit;
            transition: border-color 0.15s;
        }
        .spx-filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        @media (max-width: 768px) {
            .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; }
            .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; }
            .spx-filter-group select { height: 44px; font-size: 14px; }
        }
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-success {
            background: #22c55e;
            color: white;
        }
        
        /* Tabela */
        .table-wrapper {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        th {
            padding: 12px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-right: 1px solid rgba(255,255,255,0.2);
        }
        th:first-child {
            text-align: left;
            min-width: 180px;
        }
        th.rate-group {
            background: rgba(255,255,255,0.1);
        }
        th.subheader {
            background: rgba(0,0,0,0.1);
            font-size: 10px;
            padding: 6px 4px;
        }
        tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        tbody tr:hover {
            background: #f9fafb;
        }
        td {
            padding: 8px;
            border-right: 1px solid #e5e7eb;
        }
        td:first-child {
            font-weight: 600;
            color: #333;
        }
        td.worker-name {
            position: sticky;
            left: 0;
            background: white;
            z-index: 10;
            border-right: 2px solid #e5e7eb;
        }
        tbody tr:hover td.worker-name {
            background: #f9fafb;
        }
        
        /* Inputy w tabeli */
        .rate-input {
            width: 70px;
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 13px;
            text-align: right;
            transition: all 0.2s;
        }
        .rate-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }
        .rate-input.changed {
            border-color: #22c55e;
            background: #f0fdf4;
        }
        .rate-input.saving {
            border-color: #f59e0b;
            background: #fffbeb;
        }
        
        /* Grupy kolumn */
        .col-group-workday { background: #f0f9ff; }
        .col-group-saturday { background: #fefce8; }
        .col-group-sunday { background: #fef2f2; }
        .col-group-night { background: #f3f4f6; }
        .col-group-delegation { background: #f5f3ff; }
        .col-group-absence { background: #ecfdf5; }
        
        /* Kopiowanie */
        .copy-section {
            background: #eff6ff;
            padding: 15px 20px;
            border-bottom: 1px solid #dbeafe;
        }
        .copy-section h4 {
            font-size: 14px;
            margin-bottom: 10px;
            color: #1e40af;
        }
        .copy-row {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        /* Status */
        .save-status {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
        }
        .save-status.saved {
            background: #dcfce7;
            color: #166534;
        }
        .save-status.error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Mobile */
        @media (max-width: 1200px) {
            .container {
                padding: 15px;
            }
            .rate-input {
                width: 60px;
                padding: 4px 6px;
                font-size: 12px;
            }
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        .legend {
            margin-top: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            font-size: 12px;
            color: #666;
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-right: 20px;
            margin-bottom: 5px;
        }
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <div>
                <div class="hero-breadcrumb"><a href="<?php echo url('hr'); ?>">Pracownicy</a> › Stawki tabela</div>
                <h2>Stawki Tabela</h2>
                <p>Szybka edycja stawek godzinowych — wpisz wartość i naciśnij Enter</p>
            </div>
            <div class="page-header-actions">
                <a href="<?php echo url('hr.workers'); ?>" class="btn-hero-light">← Powrót do listy</a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Sukces!</strong> Operacja zakończona pomyślnie.
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Błąd!</strong> <?php echo implode(', ', $errors); ?>
            </div>
        <?php endif; ?>
        
        <!-- Filtry -->
        <form method="GET" action="" class="spx-filter-bar">
            <div class="spx-filter-group fg-project">
                <label>Projekt</label>
                <select name="project_id" id="projectSelect" onchange="this.form.submit()">
                    <option value="0">-- Stawki ogólne --</option>
                    <?php foreach ($projects as $proj): ?>
                        <option value="<?php echo $proj['id']; ?>" <?php echo ($filterProject == $proj['id']) ? 'selected' : ''; ?>>
                            <?php echo e($proj['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="spx-filter-group fg-stage">
                <label>Etap (opcjonalnie)</label>
                <select name="cost_node_id" id="costNodeSelect" onchange="this.form.submit()">
                    <option value="0">-- Wszystkie etapy --</option>
                    <?php foreach ($costNodes as $node): ?>
                        <option value="<?php echo $node['id']; ?>" <?php echo ($filterCostNode == $node['id']) ? 'selected' : ''; ?>>
                            <?php echo e($node['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <a href="<?php echo url('hr.workers.bulk_rates_form'); ?>" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center; flex-shrink: 0; white-space: nowrap;">
                Stawki ogólne (masowe)
            </a>
        </form>
        
        <!-- Tabela stawek -->
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">Pracownik</th>
                        <th colspan="2" class="rate-group col-group-workday">Robocze</th>
                        <th colspan="2" class="rate-group col-group-saturday">Sobota</th>
                        <th colspan="2" class="rate-group col-group-sunday">Niedziela</th>
                        <th colspan="2" class="rate-group col-group-night">Nocka</th>
                        <th colspan="2" class="rate-group col-group-delegation">Delegacja</th>
                        <th colspan="2" class="rate-group col-group-absence">Absencja</th>
                    </tr>
                    <tr>
                        <!-- Robocze -->
                        <th class="subheader col-group-workday">Podst.</th>
                        <th class="subheader col-group-workday">Nadgodz.</th>
                        <!-- Sobota -->
                        <th class="subheader col-group-saturday">Podst.</th>
                        <th class="subheader col-group-saturday">Nadgodz.</th>
                        <!-- Niedziela -->
                        <th class="subheader col-group-sunday">Podst.</th>
                        <th class="subheader col-group-sunday">Nadgodz.</th>
                        <!-- Nocka -->
                        <th class="subheader col-group-night">Podst.</th>
                        <th class="subheader col-group-night">Nadgodz.</th>
                        <!-- Delegacja -->
                        <th class="subheader col-group-delegation">Podst.</th>
                        <th class="subheader col-group-delegation">Nadgodz.</th>
                        <!-- Absencja -->
                        <th class="subheader col-group-absence">Urlop</th>
                        <th class="subheader col-group-absence">L4</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workers as $worker): ?>
                        <tr data-worker-id="<?php echo $worker['id']; ?>">
                            <td class="worker-name">
                                <?php echo e($worker['last_name'] . ' ' . $worker['first_name']); ?>
                                <span class="save-status" id="status-<?php echo $worker['id']; ?>" style="display: none;"></span>
                            </td>
                            
                            <!-- Robocze -->
                            <td class="col-group-workday">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="base_rate"
                                       value="<?php echo $worker['base_rate'] ? number_format($worker['base_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            <td class="col-group-workday">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="overtime_rate"
                                       value="<?php echo $worker['overtime_rate'] ? number_format($worker['overtime_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            
                            <!-- Sobota -->
                            <td class="col-group-saturday">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="saturday_rate"
                                       value="<?php echo $worker['saturday_rate'] ? number_format($worker['saturday_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            <td class="col-group-saturday">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="saturday_overtime_rate"
                                       value="<?php echo $worker['saturday_overtime_rate'] ? number_format($worker['saturday_overtime_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            
                            <!-- Niedziela -->
                            <td class="col-group-sunday">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="sunday_rate"
                                       value="<?php echo $worker['sunday_rate'] ? number_format($worker['sunday_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            <td class="col-group-sunday">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="sunday_overtime_rate"
                                       value="<?php echo $worker['sunday_overtime_rate'] ? number_format($worker['sunday_overtime_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            
                            <!-- Nocka -->
                            <td class="col-group-night">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="night_rate"
                                       value="<?php echo $worker['night_rate'] ? number_format($worker['night_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            <td class="col-group-night">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="night_overtime_rate"
                                       value="<?php echo $worker['night_overtime_rate'] ? number_format($worker['night_overtime_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            
                            <!-- Delegacja -->
                            <td class="col-group-delegation">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="delegation_rate"
                                       value="<?php echo $worker['delegation_rate'] ? number_format($worker['delegation_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            <td class="col-group-delegation">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="delegation_overtime_rate"
                                       value="<?php echo $worker['delegation_overtime_rate'] ? number_format($worker['delegation_overtime_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            
                            <!-- Absencja (bez nadgodzin) -->
                            <td class="col-group-absence">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="vacation_rate"
                                       value="<?php echo $worker['vacation_rate'] ? number_format($worker['vacation_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                            <td class="col-group-absence">
                                <input type="number" class="rate-input" 
                                       data-worker="<?php echo $worker['id']; ?>" data-type="sick_rate"
                                       value="<?php echo $worker['sick_rate'] ? number_format($worker['sick_rate'], 2) : ''; ?>"
                                       step="0.01" placeholder="-">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Legenda -->
        <div class="legend">
            <div class="legend-item">
                <div class="legend-color" style="background: #f0f9ff;"></div>
                <span>Robocze</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #fefce8;"></div>
                <span>Sobota</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #fef2f2;"></div>
                <span>Niedziela</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #f3f4f6;"></div>
                <span>Nocka</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #f5f3ff;"></div>
                <span>Delegacja</span>
            </div>
            <div class="legend-item">
                <div class="legend-color" style="background: #ecfdf5;"></div>
                <span>Urlop/L4</span>
            </div>
        </div>
    </div>
    
    <script>
        // Zapisywanie stawki
        async function saveRate(input) {
            const workerId = input.dataset.worker;
            const rateType = input.dataset.type;
            const rateValue = input.value;
            const statusEl = document.getElementById('status-' + workerId);
            
            input.classList.add('saving');
            input.classList.remove('changed');
            
            const formData = new FormData();
            formData.append('action', 'save_rate');
            formData.append('worker_id', workerId);
            formData.append('rate_type', rateType);
            formData.append('rate_value', rateValue);
            formData.append('project_id', '<?php echo $filterProject; ?>');
            formData.append('cost_node_id', '<?php echo $filterCostNode; ?>');
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                input.classList.remove('saving');
                
                if (data.success) {
                    statusEl.textContent = '✓';
                    statusEl.className = 'save-status saved';
                    statusEl.style.display = 'inline';
                    setTimeout(() => { statusEl.style.display = 'none'; }, 2000);
                } else {
                    statusEl.textContent = '✗';
                    statusEl.className = 'save-status error';
                    statusEl.style.display = 'inline';
                    input.classList.add('changed');
                }
            } catch (error) {
                input.classList.remove('saving');
                statusEl.textContent = '✗';
                statusEl.className = 'save-status error';
                statusEl.style.display = 'inline';
                input.classList.add('changed');
            }
        }
        
        // Nasłuchiwanie na inputy
        document.querySelectorAll('.rate-input').forEach(input => {
            let timeout;
            
            input.addEventListener('input', () => {
                input.classList.add('changed');
            });
            
            input.addEventListener('blur', () => {
                if (input.classList.contains('changed')) {
                    saveRate(input);
                }
            });
            
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveRate(input);
                    // Przejdź do następnego inputa
                    const inputs = Array.from(document.querySelectorAll('.rate-input'));
                    const index = inputs.indexOf(input);
                    if (inputs[index + 1]) {
                        inputs[index + 1].focus();
                    }
                }
            });
        });
        
        // Ładowanie etapów po zmianie projektu
        document.getElementById('projectSelect')?.addEventListener('change', function() {
            const projectId = this.value;
            const costNodeSelect = document.getElementById('costNodeSelect');
            
            if (!projectId || projectId === '0') {
                costNodeSelect.innerHTML = '<option value="0">-- Wszystkie etapy --</option>';
                return;
            }
            
            fetch(`../../api/get-cost-nodes.php?project_id=${projectId}`)
                .then(r => r.json())
                .then(data => {
                    let html = '<option value="0">-- Wszystkie etapy --</option>';
                    if (data.success && Array.isArray(data.nodes)) {
                        data.nodes.forEach(node => {
                            html += `<option value="${node.id}">${node.name}</option>`;
                        });
                    }
                    costNodeSelect.innerHTML = html;
                })
                .catch(() => {
                    costNodeSelect.innerHTML = '<option value="0">-- Błąd ładowania --</option>';
                });
        });
    </script>
</body>
</html>
