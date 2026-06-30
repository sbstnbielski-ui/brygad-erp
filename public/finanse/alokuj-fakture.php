<?php
/**
 * BRYGAD ERP v3.0 - Alokacja Faktury
 */

require_once dirname(__DIR__) . '/config/autoload.php';
require_once __DIR__ . '/_project-select.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$errors = [];
$invoice_id = $_GET['id'] ?? null;

// URL powrotu: priorytet — ostatnia lista cost-inbox, fallback — legacy lista faktur.
$backToListUrl = returnToListUrl('cost-inbox', url('finanse.faktury'));

if (!$invoice_id) {
    header("Location: " . $backToListUrl);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header("Location: " . $backToListUrl);
    exit;
}

// Pobierz alokacje
$stmt = $pdo->prepare("
    SELECT ca.*, p.name as project_name, pcn.name as node_name
    FROM cost_allocations ca
    JOIN projects p ON p.id = ca.project_id
    LEFT JOIN project_cost_nodes pcn ON pcn.id = ca.cost_node_id
    WHERE ca.invoice_id = ?
    ORDER BY ca.id
");
$stmt->execute([$invoice_id]);
$allocations = $stmt->fetchAll();

$total_allocated = array_sum(array_column($allocations, 'amount'));
$remaining = $invoice['amount_gross'] - $total_allocated;

// Pobierz projekty
$projects = spxFetchSelectableProjects($pdo);

// Obsługa POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $cost_node_id = !empty($_POST['cost_node_id']) ? intval($_POST['cost_node_id']) : null;
        $amount = floatval($_POST['amount'] ?? 0);
        
        if ($project_id <= 0) $errors[] = "Wybierz projekt";
        if ($amount <= 0) $errors[] = "Kwota musi być większa od 0";
        if ($amount > $remaining) $errors[] = "Kwota przekracza pozostałą do przypisania (" . formatMoney($remaining) . ")";
        
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO cost_allocations (invoice_id, project_id, cost_node_id, amount) VALUES (?, ?, ?, ?)");
                $stmt->execute([$invoice_id, $project_id, $cost_node_id, $amount]);
                logEvent("Przypisano {$amount} PLN z faktury {$invoice['number']} do projektu ID: {$project_id}", 'INFO');
                header("Location: " . url('finanse.faktury.allocate', ['id' => $invoice_id, 'success' => 'allocated']));
                exit;
            } catch (PDOException $e) {
                logEvent("Błąd alokacji faktury: " . $e->getMessage(), 'ERROR');
                $errors[] = "Błąd podczas przypisywania faktury";
            }
        }
    } elseif ($action === 'delete' && isAdmin()) {
        $allocation_id = intval($_POST['allocation_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM cost_allocations WHERE id = ? AND invoice_id = ?");
            $stmt->execute([$allocation_id, $invoice_id]);
            logEvent("Usunięto przypisanie faktury {$invoice['number']}, alokacja ID: {$allocation_id}", 'INFO');
            header("Location: " . url('finanse.faktury.allocate', ['id' => $invoice_id, 'success' => 'deleted']));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd usuwania alokacji: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas usuwania przypisania";
        }
    }
}

$success_message = '';
$messages = ['created' => 'Faktura utworzona', 'updated' => 'Faktura zaktualizowana', 'allocated' => 'Kwota przypisana', 'deleted' => 'Przypisanie usunięte'];
if (isset($_GET['success'])) $success_message = $messages[$_GET['success']] ?? '';

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Alokacja Faktury</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        /* Header - z include */
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }
        .breadcrumb { margin-bottom: 20px; color: #666; font-size: 14px; }
        .breadcrumb a { color: #667eea; text-decoration: none; }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header h2 { font-size: 32px; color: #333; }
        .btn {
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 8px 16px; font-size: 13px; }
        .delete-invoice-form { display: inline; margin: 0 0 0 10px; }
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
        .alert ul { margin: 10px 0 0 20px; }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }
        .card-header {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        .card-header h3 { font-size: 18px; color: #333; }
        .card-body { padding: 20px; }
        .invoice-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .info-label { font-size: 12px; color: #666; }
        .info-value { font-size: 16px; font-weight: 600; color: #333; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .stat-card {
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .stat-card.highlight {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .stat-label { font-size: 13px; margin-bottom: 8px; opacity: 0.9; }
        .stat-value { font-size: 24px; font-weight: 700; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-draft { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-business { background: #e7f3ff; color: #004080; }
        .badge-private { background: #d1ecf1; color: #0c5460; }
        .form-row {
            display: grid;
            grid-template-columns: 2fr 2fr 1fr auto;
            gap: 15px;
            align-items: end;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8f9fa; }
        th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
            font-size: 12px;
            text-transform: uppercase;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        tr:hover { background: #f8f9fa; }
        .no-data {
            padding: 40px 20px;
            text-align: center;
            color: #999;
            font-size: 14px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo url('finanse.faktury'); ?>">Faktury</a> / 
            Alokacja
        </div>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo e($success_message); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Błąd!</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="page-header">
            <h2>Alokacja Faktury</h2>
            <div>
                <?php if ($isAdminUser): ?>
                    <a href="<?php echo url('finanse.faktury.edit', ['id' => $invoice_id]); ?>" class="btn btn-secondary">Edytuj</a>
                    <form method="POST" action="<?php echo url('finanse.faktury.delete'); ?>" class="delete-invoice-form" 
                          onsubmit="return confirm('Czy na pewno chcesz usunąć fakturę <?php echo e($invoice['number']); ?>? Zostaną usunięte wszystkie przypisania do projektów!');">
                        <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                        <button type="submit" class="btn btn-danger">Usuń Fakturę</button>
                    </form>
                <?php endif; ?>
                <a href="<?php echo e($backToListUrl); ?>" class="btn btn-secondary">← Powrót</a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3>Dane Faktury</h3>
            </div>
            <div class="card-body">
                <div class="invoice-info">
                    <div class="info-item">
                        <span class="info-label">Numer</span>
                        <span class="info-value"><?php echo e($invoice['number']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Kontrahent</span>
                        <span class="info-value"><?php echo e($invoice['contractor']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Data</span>
                        <span class="info-value"><?php echo formatDate($invoice['date']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Kwota brutto</span>
                        <span class="info-value"><?php echo formatMoney($invoice['amount_gross']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Typ</span>
                        <span class="info-value">
                            <span class="badge badge-<?php echo $invoice['scope']; ?>">
                                <?php echo $invoice['scope'] === 'business' ? 'Firmowa' : 'Prywatna'; ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="info-value">
                            <span class="badge badge-<?php echo $invoice['status']; ?>">
                                <?php echo $invoice['status'] === 'draft' ? 'Szkic' : 'Zatwierdzona'; ?>
                            </span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Kwota faktury</div>
                        <div class="stat-value"><?php echo formatMoney($invoice['amount_gross']); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Przypisano</div>
                        <div class="stat-value"><?php echo formatMoney($total_allocated); ?></div>
                    </div>
                    <div class="stat-card highlight">
                        <div class="stat-label">Pozostało</div>
                        <div class="stat-value"><?php echo formatMoney($remaining); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($remaining > 0): ?>
        <div class="card">
            <div class="card-header">
                <h3>Przypisz do Projektu</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add">
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Projekt</label>
                            <select name="project_id" id="project_id" required onchange="loadCostNodes(this.value)">
                                <?php echo spxRenderProjectOptions($projects, null, '-- Wybierz --'); ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Etap (opcja)</label>
                            <select name="cost_node_id" id="cost_node_id">
                                <option value="">-- Bez etapu --</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Kwota</label>
                            <input type="number" name="amount" step="0.01" min="0.01" 
                                   value="<?php echo number_format($remaining, 2, '.', ''); ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Przypisz</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h3>Przypisania (<?php echo count($allocations); ?>)</h3>
            </div>
            <?php if (!empty($allocations)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Projekt</th>
                            <th>Etap</th>
                            <th>Kwota</th>
                            <th>%</th>
                            <?php if ($isAdminUser): ?>
                                <th>Akcje</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allocations as $alloc): ?>
                            <tr>
                                <td><strong><?php echo e($alloc['project_name']); ?></strong></td>
                                <td><?php echo e($alloc['node_name'] ?? '-'); ?></td>
                                <td><strong><?php echo formatMoney($alloc['amount']); ?></strong></td>
                                <td><?php echo number_format(($alloc['amount'] / $invoice['amount_gross']) * 100, 1); ?>%</td>
                                <?php if ($isAdminUser): ?>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Usunąć przypisanie?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="allocation_id" value="<?php echo $alloc['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Usuń</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">Brak przypisań</div>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        function loadCostNodes(projectId) {
            const select = document.getElementById('cost_node_id');
            select.innerHTML = '<option value="">Ładowanie...</option>';
            if (!projectId) {
                select.innerHTML = '<option value="">-- Wybierz projekt --</option>';
                return;
            }
            fetch(`api/get-cost-nodes.php?project_id=${projectId}`)
                .then(r => r.json())
                .then(data => {
                    select.innerHTML = '<option value="">-- Bez etapu --</option>';
                    if (data.success && Array.isArray(data.nodes)) {
                        data.nodes.forEach(n => {
                            const opt = document.createElement('option');
                            opt.value = n.id;
                            opt.textContent = (n.parent_id ? '  ↳ ' : '') + n.name;
                            select.appendChild(opt);
                        });
                    }
                })
                .catch(() => select.innerHTML = '<option value="">-- Błąd --</option>');
        }
    </script>
</body>
</html>
