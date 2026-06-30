<?php
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$errors = [];
$invoice_id = $_GET['id'] ?? null;

if (!$invoice_id) {
    header("Location: faktury.php");
    exit;
}

// Pobierz fakturę
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = :id");
$stmt->execute(['id' => $invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    header("Location: faktury.php");
    exit;
}

// Pobierz istniejące alokacje
$stmt = $pdo->prepare("
    SELECT 
        ca.*,
        p.name as project_name,
        pcn.name as node_name
    FROM cost_allocations ca
    JOIN projects p ON p.id = ca.project_id
    LEFT JOIN project_cost_nodes pcn ON pcn.id = ca.cost_node_id
    WHERE ca.invoice_id = :invoice_id
    ORDER BY ca.id
");
$stmt->execute(['invoice_id' => $invoice_id]);
$allocations = $stmt->fetchAll();

// Oblicz całkowitą przypisaną kwotę
$total_allocated = array_sum(array_column($allocations, 'amount'));
$remaining = $invoice['amount_gross'] - $total_allocated;

// Pobierz wszystkie projekty
$stmt = $pdo->query("SELECT id, name, status FROM projects WHERE status IN ('planned', 'active') ORDER BY name");
$projects = $stmt->fetchAll();

// Obsługa formularza dodawania alokacji
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $cost_node_id = !empty($_POST['cost_node_id']) ? intval($_POST['cost_node_id']) : null;
        $amount = floatval($_POST['amount'] ?? 0);
        
        // Walidacja
        if ($project_id <= 0) {
            $errors[] = "Wybierz projekt";
        }
        
        if ($amount <= 0) {
            $errors[] = "Kwota musi być większa od 0";
        }
        
        if ($amount > $remaining) {
            $errors[] = "Kwota przekracza pozostałą do przypisania (" . formatMoney($remaining) . ")";
        }
        
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO cost_allocations (invoice_id, project_id, cost_node_id, amount)
                    VALUES (:invoice_id, :project_id, :cost_node_id, :amount)
                ");
                $stmt->execute([
                    'invoice_id' => $invoice_id,
                    'project_id' => $project_id,
                    'cost_node_id' => $cost_node_id,
                    'amount' => $amount
                ]);
                
                logEvent("Przypisano {$amount} PLN z faktury {$invoice['number']} do projektu ID: {$project_id}", 'info');
                
                header("Location: alokuj-fakture.php?id={$invoice_id}&success=allocated");
                exit;
                
            } catch (PDOException $e) {
                logEvent("Błąd alokacji faktury: " . $e->getMessage(), 'error');
                $errors[] = "Błąd podczas przypisywania faktury";
            }
        }
    } elseif ($action === 'delete') {
        $allocation_id = intval($_POST['allocation_id'] ?? 0);
        
        if (isAdmin()) {
            try {
                $stmt = $pdo->prepare("DELETE FROM cost_allocations WHERE id = :id AND invoice_id = :invoice_id");
                $stmt->execute(['id' => $allocation_id, 'invoice_id' => $invoice_id]);
                
                logEvent("Usunięto przypisanie faktury {$invoice['number']}, alokacja ID: {$allocation_id}", 'info');
                
                header("Location: alokuj-fakture.php?id={$invoice_id}&success=deleted");
                exit;
                
            } catch (PDOException $e) {
                logEvent("Błąd usuwania alokacji: " . $e->getMessage(), 'error');
                $errors[] = "Błąd podczas usuwania przypisania";
            }
        }
    }
}

$success_message = '';
if (isset($_GET['success'])) {
    $success_messages = [
        'created' => 'Faktura została utworzona pomyślnie',
        'updated' => 'Faktura została zaktualizowana pomyślnie',
        'allocated' => 'Kwota została przypisana do projektu',
        'deleted' => 'Przypisanie zostało usunięte'
    ];
    $success_message = $success_messages[$_GET['success']] ?? '';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Przypisanie faktury</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
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
        }
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .header h1 { color: #333; font-size: 28px; }
        .header-links { display: flex; gap: 15px; }
        .header-links a {
            color: #667eea;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            transition: background 0.3s;
        }
        .header-links a:hover { background: #f0f0f0; }
        .invoice-info {
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
        }
        .invoice-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .invoice-info-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .invoice-info-label {
            font-size: 12px;
            color: #666;
        }
        .invoice-info-value {
            font-size: 16px;
            color: #333;
            font-weight: 600;
        }
        .container { max-width: 1200px; margin: 0 auto; }
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
        .alert ul { margin: 0; padding-left: 20px; }
        .summary {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .summary h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        .summary-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .summary-item.remaining {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .summary-label {
            font-size: 13px;
            margin-bottom: 8px;
            opacity: 0.9;
        }
        .summary-value {
            font-size: 24px;
            font-weight: bold;
        }
        .section {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .section h2 {
            color: #333;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
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
        tr:hover { background: #f8f9fa; }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #333;
            font-size: 14px;
            font-weight: 500;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 2fr 2fr 1.5fr auto;
            gap: 15px;
            align-items: end;
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
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-sm { padding: 6px 12px; font-size: 13px; }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-business { background: #667eea; color: white; }
        .badge-private { background: #17a2b8; color: white; }
        .badge-draft { background: #ffc107; color: #000; }
        .badge-approved { background: #28a745; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-top">
            <h1>Przypisanie faktury do projektów</h1>
            <div class="header-links">
                <a href="faktury.php">← Powrót do listy</a>
                <?php if (isAdmin()): ?>
                    <a href="edytuj-fakture.php?id=<?php echo $invoice_id; ?>">Edytuj fakturę</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="invoice-info">
            <div class="invoice-info-grid">
                <div class="invoice-info-item">
                    <div class="invoice-info-label">Numer faktury</div>
                    <div class="invoice-info-value"><?php echo e($invoice['number']); ?></div>
                </div>
                <div class="invoice-info-item">
                    <div class="invoice-info-label">Kontrahent</div>
                    <div class="invoice-info-value"><?php echo e($invoice['contractor']); ?></div>
                </div>
                <div class="invoice-info-item">
                    <div class="invoice-info-label">Data</div>
                    <div class="invoice-info-value"><?php echo formatDate($invoice['date']); ?></div>
                </div>
                <div class="invoice-info-item">
                    <div class="invoice-info-label">Kwota brutto</div>
                    <div class="invoice-info-value"><?php echo formatMoney($invoice['amount_gross']); ?></div>
                </div>
                <div class="invoice-info-item">
                    <div class="invoice-info-label">Typ</div>
                    <div class="invoice-info-value">
                        <span class="badge badge-<?php echo $invoice['scope']; ?>">
                            <?php echo $invoice['scope'] === 'business' ? 'Firmowa' : 'Prywatna'; ?>
                        </span>
                    </div>
                </div>
                <div class="invoice-info-item">
                    <div class="invoice-info-label">Status</div>
                    <div class="invoice-info-value">
                        <span class="badge badge-<?php echo $invoice['status']; ?>">
                            <?php echo $invoice['status'] === 'draft' ? 'Szkic' : 'Zatwierdzona'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="container">
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo e($success_message); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="summary">
            <h2>Podsumowanie przypisania</h2>
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Kwota faktury</div>
                    <div class="summary-value"><?php echo formatMoney($invoice['amount_gross']); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Przypisano</div>
                    <div class="summary-value"><?php echo formatMoney($total_allocated); ?></div>
                </div>
                <div class="summary-item remaining">
                    <div class="summary-label">Pozostało do przypisania</div>
                    <div class="summary-value"><?php echo formatMoney($remaining); ?></div>
                </div>
            </div>
        </div>
        
        <div class="section">
            <h2>Dodaj przypisanie do projektu</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label>Projekt</label>
                        <select name="project_id" id="project_id" required onchange="loadCostNodes(this.value)">
                            <option value="">-- Wybierz projekt --</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>">
                                    <?php echo e($project['name']); ?> (<?php echo $project['status']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Etap (opcjonalnie)</label>
                        <select name="cost_node_id" id="cost_node_id">
                            <option value="">-- Brak struktury kosztów --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Kwota</label>
                        <input type="number" name="amount" step="0.01" min="0.01" 
                               value="<?php echo $remaining > 0 ? number_format($remaining, 2, '.', '') : ''; ?>" 
                               required placeholder="0.00">
                    </div>
                    <button type="submit" class="btn btn-primary">Przypisz</button>
                </div>
            </form>
        </div>
        
        <div class="section">
            <h2>Przypisania (<?php echo count($allocations); ?>)</h2>
            <?php if (!empty($allocations)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Projekt</th>
                            <th>Etap</th>
                            <th>Kwota</th>
                            <th>% faktury</th>
                            <?php if (isAdmin()): ?>
                                <th>Akcje</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allocations as $alloc): ?>
                            <?php
                            $percent = ($alloc['amount'] / $invoice['amount_gross']) * 100;
                            ?>
                            <tr>
                                <td><strong><?php echo e($alloc['project_name']); ?></strong></td>
                                <td><?php echo e($alloc['node_name'] ?? '-'); ?></td>
                                <td><strong><?php echo formatMoney($alloc['amount']); ?></strong></td>
                                <td><?php echo number_format($percent, 1); ?>%</td>
                                <?php if (isAdmin()): ?>
                                    <td>
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Czy na pewno chcesz usunąć to przypisanie?');">
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
                <div class="empty-state">
                    Ta faktura nie została jeszcze przypisana do żadnego projektu
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function loadCostNodes(projectId) {
            const select = document.getElementById('cost_node_id');
            select.innerHTML = '<option value="">Ładowanie...</option>';
            
            if (!projectId) {
                select.innerHTML = '<option value="">-- Wybierz najpierw projekt --</option>';
                return;
            }
            
            fetch(`api/get-cost-nodes.php?project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    select.innerHTML = '<option value="">-- Bez etapu --</option>';
                    if (data.success && Array.isArray(data.nodes)) {
                        data.nodes.forEach(node => {
                            const option = document.createElement('option');
                            option.value = node.id;
                            option.textContent = (node.parent_id ? '  ↳ ' : '') + node.name;
                            select.appendChild(option);
                        });
                    }
                })
                .catch(() => {
                    select.innerHTML = '<option value="">-- Błąd ładowania --</option>';
                });
        }
    </script>
</body>
</html>


