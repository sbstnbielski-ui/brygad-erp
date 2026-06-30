<?php
/**
 * BRYGAD ERP - Dodawanie Przychodu do Projektu
 * 
 * Formularz pozwala:
 * - Wybrać projekt (jeśli nie przekazano project_id)
 * - Dodać przychód (umowa/aneks/bonus)
 * - Po zapisie wrócić do projektu lub do listy projektów
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success_message = '';

// Parametry
$project_id = $_GET['project_id'] ?? $_POST['project_id'] ?? null;
$return_url = $_GET['return_url'] ?? $_POST['return_url'] ?? null;

// Pobierz dane projektu jeśli przekazano ID
$project = null;
if ($project_id) {
    $stmt = $pdo->prepare("SELECT id, name, is_internal FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
    
    if ($project && $project['is_internal']) {
        // Projekt wewnętrzny nie może mieć przychodów
        header("Location: " . url('projekty.view', ['id' => $project_id, 'error' => 'internal']));
        exit;
    }
}

// Pobierz listę projektów (do selecta)
$stmt = $pdo->query("
    SELECT id, name, status 
    FROM projects 
    WHERE is_internal = 0 
    ORDER BY 
        CASE status WHEN 'active' THEN 1 WHEN 'planned' THEN 2 ELSE 3 END,
        name ASC
");
$projects = $stmt->fetchAll();

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = (int)($_POST['project_id'] ?? 0);
    $rev_type = $_POST['rev_type'] ?? 'contract';
    $rev_name = trim($_POST['rev_name'] ?? '');
    $rev_amount = trim($_POST['rev_amount'] ?? '');
    $rev_signed_date = $_POST['rev_signed_date'] ?? '';
    $rev_description = trim($_POST['rev_description'] ?? '');
    
    // Walidacja
    if (!$project_id) {
        $errors[] = "Musisz wybrać projekt";
    } else {
        // Sprawdź czy projekt istnieje i nie jest wewnętrzny
        $stmt = $pdo->prepare("SELECT id, is_internal FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $proj = $stmt->fetch();
        if (!$proj) {
            $errors[] = "Wybrany projekt nie istnieje";
        } elseif ($proj['is_internal']) {
            $errors[] = "Nie można dodać przychodu do projektu wewnętrznego";
        }
    }
    
    if (empty($rev_name)) {
        $errors[] = "Nazwa dokumentu jest wymagana";
    }
    if (!is_numeric($rev_amount) || $rev_amount < 0) {
        $errors[] = "Kwota netto musi być liczbą większą lub równą 0";
    }
    if (empty($rev_signed_date)) {
        $errors[] = "Data podpisania jest wymagana";
    }
    if (!in_array($rev_type, ['contract', 'annex', 'bonus'])) {
        $errors[] = "Nieprawidłowy typ dokumentu";
    }
    
    if (empty($errors)) {
        try {
            $cost_node_id = !empty($_POST['cost_node_id']) ? $_POST['cost_node_id'] : null;
            
            $stmt = $pdo->prepare("
                INSERT INTO project_revenues (project_id, cost_node_id, type, name, amount_net, signed_date, description, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$project_id, $cost_node_id, $rev_type, $rev_name, $rev_amount, $rev_signed_date, $rev_description ?: null]);
            
            logEvent("Dodano przychód projektu ID: {$project_id}, typ: {$rev_type}, kwota: {$rev_amount} PLN", 'INFO');
            
            // Przekierowanie po sukcesie
            if ($return_url) {
                header("Location: " . $return_url);
            } else {
                header("Location: " . url('projekty.view', ['id' => $project_id, 'success' => 'revenue_added']));
            }
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd dodawania przychodu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas dodawania przychodu";
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// Typy przychodów
$revenue_types = [
    'contract' => 'Umowa',
    'annex' => 'Aneks',
    'bonus' => 'Premia / Bonus'
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj Przychód Projektowy</title>
    <style>

        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --bg-card:           #ffffff;
            --border:            #e5e7eb;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
            --success:           #22c55e;
            --danger:            #ef4444;
            --warning:           #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px;
        }
        .container { max-width: 1000px; margin: 0 auto; padding: 25px; }

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

        /* Hero */
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 26px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 35px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            color: #991b1b;
        }
        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        .alert ul {
            margin: 10px 0 0 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group .required {
            color: #dc2626;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #16a34a;
        }
        .form-group .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 6px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .btn {
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.4);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        .project-badge {
            display: inline-block;
            padding: 8px 16px;
            background: #d1fae5;
            color: #065f46;
            border-radius: 8px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>
    
    <div class="container">
                <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    Dodaj Przychód
                </div>
                <h1>Dodaj Przychód</h1>
                <p>Nowy przychód</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        
        <div class="card">
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
            
            <?php if ($project): ?>
                <div class="project-badge">
                    Projekt: <?php echo e($project['name']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <?php if ($return_url): ?>
                    <input type="hidden" name="return_url" value="<?php echo e($return_url); ?>">
                <?php endif; ?>
                
                <?php if (!$project): ?>
                    <div class="form-group">
                        <label>Projekt <span class="required">*</span></label>
                        <select name="project_id" id="project_id" required>
                            <option value="">-- Wybierz projekt --</option>
                            <?php foreach ($projects as $p): ?>
                                <?php 
                                $statusLabel = ['active' => '🟢', 'planned' => '🟡', 'finished' => '⚪'][($p['status'])] ?? '';
                                ?>
                                <option value="<?php echo $p['id']; ?>" 
                                        <?php echo ($_POST['project_id'] ?? $project_id) == $p['id'] ? 'selected' : ''; ?>>
                                    <?php echo $statusLabel; ?> <?php echo e($p['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Kontrahent przypisany do projektu to inwestor projektu. 🟢 Aktywny, 🟡 Planowany, ⚪ Zakończony</div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="project_id" id="project_id" value="<?php echo $project['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Etap / Pod-etap</label>
                    <select name="cost_node_id" id="cost_node_id">
                        <option value="">-- Bez etapu --</option>
                    </select>
                    <div class="help-text">Opcjonalnie: przypisz przychód do konkretnego etapu projektu</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Typ dokumentu <span class="required">*</span></label>
                        <select name="rev_type" required>
                            <?php foreach ($revenue_types as $key => $label): ?>
                                <option value="<?php echo $key; ?>" 
                                        <?php echo ($_POST['rev_type'] ?? 'contract') === $key ? 'selected' : ''; ?>>
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Data podpisania <span class="required">*</span></label>
                        <input type="date" name="rev_signed_date" 
                               value="<?php echo e($_POST['rev_signed_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Nazwa dokumentu <span class="required">*</span></label>
                    <input type="text" name="rev_name" 
                           value="<?php echo e($_POST['rev_name'] ?? ''); ?>" 
                           placeholder="np. Umowa główna, Aneks nr 1, Bonus za terminowość..." required>
                    <div class="help-text">Pełna nazwa umowy lub aneksu</div>
                </div>
                
                <div class="form-group">
                    <label>Kwota netto (PLN) <span class="required">*</span></label>
                    <input type="number" name="rev_amount" step="0.01" min="0" 
                           value="<?php echo e($_POST['rev_amount'] ?? ''); ?>" 
                           placeholder="0.00" required>
                    <div class="help-text">Wartość netto dokumentu w złotych</div>
                </div>
                
                <div class="form-group">
                    <label>Opis / Notatki (opcjonalnie)</label>
                    <textarea name="rev_description" rows="3" 
                              placeholder="Dodatkowe informacje o tym dokumencie..."><?php echo e($_POST['rev_description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Dodaj Przychód</button>
                    <?php if ($project): ?>
                        <a href="<?php echo url('projekty.view', ['id' => $project['id']]); ?>" class="btn btn-secondary">Anuluj</a>
                    <?php else: ?>
                        <a href="<?php echo url('projekty'); ?>" class="btn btn-secondary">Anuluj</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        // Dynamiczne ładowanie etapów po wyborze projektu
        const projectSelect = document.getElementById('project_id');
        const costNodeSelect = document.getElementById('cost_node_id');
        
        function loadCostNodes(projectId, selectedNodeId = null) {
            if (!projectId) {
                costNodeSelect.innerHTML = '<option value="">-- Bez etapu --</option>';
                return;
            }
            
            fetch(`/api/get-cost-nodes.php?project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    costNodeSelect.innerHTML = '<option value="">-- Bez etapu --</option>';
                    if (data.success && Array.isArray(data.nodes)) {
                        data.nodes.forEach(node => {
                            const option = document.createElement('option');
                            option.value = node.id;
                            option.textContent = (node.parent_id ? '  ↳ ' : '') + node.name;
                            if (selectedNodeId && node.id == selectedNodeId) {
                                option.selected = true;
                            }
                            costNodeSelect.appendChild(option);
                        });
                    }
                })
                .catch(error => {
                    console.error('Błąd ładowania etapów:', error);
                });
        }
        
        // Event listener na zmianę projektu
        if (projectSelect) {
            projectSelect.addEventListener('change', function() {
                loadCostNodes(this.value);
            });
            
            // Załaduj etapy dla wybranego projektu (jeśli jest)
            const initialProjectId = projectSelect.value || '<?php echo $project_id ?? ''; ?>';
            if (initialProjectId) {
                loadCostNodes(initialProjectId, '<?php echo $_POST['cost_node_id'] ?? ''; ?>');
            }
        }
    </script>
</body>
</html>


