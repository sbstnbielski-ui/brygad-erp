<?php
/**
 * BRYGAD ERP - Dodawanie Paragonu
 * 
 * Dostępne dla: admin i pracownik
 * Paragon = koszt bez faktury, z możliwością uploadu zdjęcia/PDF
 * Może być przypisany do projektu i etapu (opcjonalnie)
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$errors = [];
$success_message = '';
$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;
$currentUserId = $_SESSION['user_id'] ?? null;

// Parametry
$project_id = $_GET['project_id'] ?? $_POST['project_id'] ?? null;
$return_url = $_GET['return_url'] ?? $_POST['return_url'] ?? null;

// Pobierz dane projektu jeśli przekazano ID
$project = null;
if ($project_id) {
    $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch();
}

// Pobierz listę projektów aktywnych
$stmt = $pdo->query("
    SELECT id, name, status 
    FROM projects 
    WHERE status IN ('active', 'planned')
    ORDER BY 
        CASE status WHEN 'active' THEN 1 ELSE 2 END,
        name ASC
");
$projects = $stmt->fetchAll();

// Pobierz etapy kosztów dla wybranego projektu (lub wszystkie)
$cost_nodes = [];
if ($project_id) {
    $stmt = $pdo->prepare("
        SELECT id, name, parent_id 
        FROM project_cost_nodes 
        WHERE project_id = ? AND is_active = 1 
        ORDER BY sort_order ASC
    ");
    $stmt->execute([$project_id]);
    $cost_nodes = $stmt->fetchAll();
}

// Kategorie kosztowe dla paragonów
$categories = [
    'material' => 'Materiały budowlane',
    'tools' => 'Narzędzia',
    'fuel' => 'Paliwo',
    'transport' => 'Transport',
    'food' => 'Wyżywienie (delegacja)',
    'office' => 'Materiały biurowe',
    'other' => 'Inne'
];

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = $_POST['project_id'] ?: null;
    $etap_id = $_POST['etap_id'] ?: null;
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount_gross = trim($_POST['amount_gross'] ?? '0');
    $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
    $cost_category = $_POST['cost_category'] ?? 'other';
    $company_name = trim($_POST['company_name'] ?? '');
    
    // Walidacja
    if (empty($title)) {
        $errors[] = "Tytuł/opis paragonu jest wymagany";
    }
    if (!is_numeric($amount_gross) || $amount_gross <= 0) {
        $errors[] = "Kwota musi być liczbą większą od 0";
    }
    if (empty($issue_date)) {
        $errors[] = "Data zakupu jest wymagana";
    }
    
    // Obsługa uploadu pliku
    $file_path = null;
    if (!empty($_FILES['file']['name'])) {
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'webp'];
        $file_extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Dozwolone są tylko pliki: PDF, JPG, PNG, HEIC, WEBP";
        } elseif ($_FILES['file']['size'] > 15 * 1024 * 1024) {
            $errors[] = "Plik nie może być większy niż 15MB";
        } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Błąd podczas przesyłania pliku (kod: {$_FILES['file']['error']})";
        } else {
            $upload_dir = dirname(dirname(__DIR__)) . '/uploads/receipts';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = 'receipt_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $file_extension;
            $destination = $upload_dir . '/' . $file_name;
            
            if (move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
                $file_path = 'uploads/receipts/' . $file_name;
            } else {
                $errors[] = "Błąd podczas zapisywania pliku";
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO finance_items (
                    item_type, project_id, etap_id,
                    company_name, title, description,
                    issue_date, amount_net, amount_vat, amount_gross,
                    currency, file_path, cost_category,
                    status, created_by, created_at
                ) VALUES (
                    'RECEIPT', ?, ?,
                    ?, ?, ?,
                    ?, ?, 0, ?,
                    'PLN', ?, ?,
                    ?, ?, NOW()
                )
            ");
            
            // Status: pending dla pracowników, approved dla adminów
            $status = $isAdminUser ? 'approved' : 'pending';
            
            $stmt->execute([
                $project_id ?: null,
                $etap_id ?: null,
                $company_name ?: null,
                $title,
                $description ?: null,
                $issue_date,
                $amount_gross, // amount_net = amount_gross dla paragonów
                $amount_gross,
                $file_path,
                $cost_category,
                $status,
                $currentUserId
            ]);
            
            $item_id = $pdo->lastInsertId();
            logEvent("Dodano paragon ID: {$item_id}, kwota: {$amount_gross} PLN, projekt: " . ($project_id ?: 'brak'), 'INFO');
            
            // Przekierowanie po sukcesie
            if ($return_url) {
                header("Location: " . $return_url . (strpos($return_url, '?') !== false ? '&' : '?') . "success=receipt_added");
            } elseif ($project_id) {
                header("Location: " . url('projekty.view', ['id' => $project_id, 'success' => 'receipt_added']));
            } else {
                header("Location: " . url('finanse.items', ['success' => 'receipt_added']));
            }
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd dodawania paragonu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas zapisywania paragonu";
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj Paragon</title>
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
        .alert-info {
            background: #e0f2fe;
            border-left: 4px solid #0284c7;
            color: #075985;
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
            border-color: #ea580c;
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
            background: linear-gradient(135deg, #ea580c 0%, #dc2626 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.4);
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
            background: #fef3c7;
            color: #92400e;
            border-radius: 8px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .file-upload {
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background: #f9fafb;
            cursor: pointer;
            transition: all 0.2s;
        }
        .file-upload:hover {
            border-color: #ea580c;
            background: #fff7ed;
        }
        .file-upload input[type="file"] {
            display: none;
        }
        .file-upload-label {
            display: block;
            cursor: pointer;
        }
        .file-upload-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        .file-upload-text {
            font-size: 14px;
            color: #666;
        }
        .file-name {
            margin-top: 10px;
            font-weight: 600;
            color: #ea580c;
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
                    Dodaj Pozycję Kosztu
                </div>
                <h1>Dodaj Pozycję Kosztu</h1>
                <p>Nowa pozycja kosztu</p>
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
            
            <?php if (!$isAdminUser): ?>
                <div class="alert alert-info">
                    <strong>Uwaga:</strong> Paragon zostanie wysłany do zatwierdzenia przez administratora.
                </div>
            <?php endif; ?>
            
            <?php if ($project): ?>
                <div class="project-badge">
                    📁 Projekt: <?php echo e($project['name']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <?php if ($return_url): ?>
                    <input type="hidden" name="return_url" value="<?php echo e($return_url); ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Tytuł / Co kupiono <span class="required">*</span></label>
                    <input type="text" name="title" 
                           value="<?php echo e($_POST['title'] ?? ''); ?>" 
                           placeholder="np. Materiały budowlane - kołki, śruby" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Kwota brutto (PLN) <span class="required">*</span></label>
                        <input type="number" name="amount_gross" step="0.01" min="0.01" 
                               value="<?php echo e($_POST['amount_gross'] ?? ''); ?>" 
                               placeholder="0.00" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Data zakupu <span class="required">*</span></label>
                        <input type="date" name="issue_date" 
                               value="<?php echo e($_POST['issue_date'] ?? date('Y-m-d')); ?>" 
                               max="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Kategoria</label>
                        <select name="cost_category">
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?php echo $key; ?>" 
                                        <?php echo ($_POST['cost_category'] ?? 'other') === $key ? 'selected' : ''; ?>>
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Sklep / Źródło zakupu</label>
                        <input type="text" name="company_name" 
                               value="<?php echo e($_POST['company_name'] ?? ''); ?>" 
                               placeholder="np. Castorama, Leroy Merlin...">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Projekt (opcjonalnie)</label>
                        <select name="project_id" id="project_select">
                            <option value="">-- Bez przypisania --</option>
                            <?php foreach ($projects as $p): ?>
                                <?php $statusLabel = ($p['status'] === 'active') ? '🟢' : '🟡'; ?>
                                <option value="<?php echo $p['id']; ?>" 
                                        <?php echo ($_POST['project_id'] ?? $project_id) == $p['id'] ? 'selected' : ''; ?>>
                                    <?php echo $statusLabel; ?> <?php echo e($p['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Etap kosztowy (opcjonalnie)</label>
                        <select name="etap_id" id="etap_select">
                            <option value="">-- Bez etapu --</option>
                            <?php foreach ($cost_nodes as $node): ?>
                                <option value="<?php echo $node['id']; ?>" 
                                        <?php echo ($_POST['etap_id'] ?? '') == $node['id'] ? 'selected' : ''; ?>>
                                    <?php echo $node['parent_id'] ? '└ ' : ''; ?><?php echo e($node['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help-text">Etapy ładują się po wybraniu projektu</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Zdjęcie / Skan paragonu</label>
                    <div class="file-upload" onclick="document.getElementById('file-input').click();">
                        <label class="file-upload-label">
                            <div class="file-upload-icon">📸</div>
                            <div class="file-upload-text">Kliknij aby wybrać plik lub przeciągnij tutaj</div>
                            <div class="file-upload-text" style="font-size: 12px; color: #999; margin-top: 5px;">
                                PDF, JPG, PNG, HEIC (max 15MB)
                            </div>
                            <input type="file" name="file" id="file-input" accept=".pdf,.jpg,.jpeg,.png,.heic,.webp">
                        </label>
                        <div class="file-name" id="file-name"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Dodatkowy opis (opcjonalnie)</label>
                    <textarea name="description" rows="2" 
                              placeholder="Dodatkowe informacje..."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Dodaj Paragon</button>
                    <?php if ($project): ?>
                        <a href="<?php echo url('projekty.view', ['id' => $project['id']]); ?>" class="btn btn-secondary">Anuluj</a>
                    <?php else: ?>
                        <a href="<?php echo url('finanse'); ?>" class="btn btn-secondary">Anuluj</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
    
    <script>
        // Wyświetl nazwę wybranego pliku
        document.getElementById('file-input').addEventListener('change', function() {
            var fileName = this.files[0] ? this.files[0].name : '';
            document.getElementById('file-name').textContent = fileName;
        });
        
        // Ładuj etapy po wybraniu projektu
        document.getElementById('project_select').addEventListener('change', function() {
            var projectId = this.value;
            var etapSelect = document.getElementById('etap_select');
            
            if (!projectId) {
                etapSelect.innerHTML = '<option value="">-- Bez etapu --</option>';
                return;
            }
            
            // Pobierz etapy dla projektu
            fetch('/api/get-cost-nodes.php?project_id=' + projectId)
                .then(response => response.json())
                .then(data => {
                    var html = '<option value="">-- Bez etapu --</option>';
                    if (data.success && data.nodes) {
                        data.nodes.forEach(function(node) {
                            var prefix = node.parent_id ? '└ ' : '';
                            html += '<option value="' + node.id + '">' + prefix + node.name + '</option>';
                        });
                    }
                    etapSelect.innerHTML = html;
                })
                .catch(function() {
                    etapSelect.innerHTML = '<option value="">-- Błąd ładowania --</option>';
                });
        });
    </script>
</body>
</html>

