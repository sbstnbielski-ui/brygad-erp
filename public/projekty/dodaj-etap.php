<?php
/**
 * BRYGAD ERP v3.0 - Dodawanie Etapu Kosztów
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$project_id = $_GET['project_id'] ?? $_POST['project_id'] ?? null;
$parent_id = $_GET['parent_id'] ?? null;

// Pobierz wszystkie aktywne projekty (do selecta)
$stmt = $pdo->query("SELECT id, name, status FROM projects WHERE status IN ('planned', 'active') ORDER BY name");
$all_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Jeśli mamy project_id, pobierz dane projektu
$project = null;
if ($project_id) {
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
    $stmt->execute(['id' => $project_id]);
    $project = $stmt->fetch();
}

// Jeśli parent_id, pobierz dane rodzica
$parent_node = null;
if ($parent_id && $project_id) {
    $stmt = $pdo->prepare("SELECT * FROM project_cost_nodes WHERE id = :id AND project_id = :project_id");
    $stmt->execute(['id' => $parent_id, 'project_id' => $project_id]);
    $parent_node = $stmt->fetch();
    
    if (!$parent_node) {
        header("Location: " . url('projekty.etapy', ['project_id' => $project_id]));
        exit;
    }
}

// Pobierz etapy główne dla select (tylko gdy mamy projekt)
$main_nodes = [];
if ($project_id && !$parent_id) {
    $stmt = $pdo->prepare("SELECT * FROM project_cost_nodes WHERE project_id = :project_id AND parent_id IS NULL AND is_active = 1 ORDER BY sort_order ASC");
    $stmt->execute(['project_id' => $project_id]);
    $main_nodes = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $selected_parent_id = $_POST['parent_id'] ?? null;
    
    // Jeśli projekt nie był w URL, bierzemy z formularza
    if (!$project_id) {
        $project_id = $_POST['project_id'] ?? null;
    }
    
    if (empty($name)) {
        $errors[] = "Nazwa etapu jest wymagana";
    }
    
    if (empty($project_id)) {
        $errors[] = "Wybierz projekt";
    }
    
    if ($parent_id) {
        $selected_parent_id = $parent_id;
    }
    
    // Sprawdź duplikat (tylko wśród aktywnych etapów)
    if (empty($errors)) {
        $sql = "SELECT COUNT(*) FROM project_cost_nodes 
                WHERE project_id = :project_id AND name = :name AND is_active = 1 AND " . 
                ($selected_parent_id ? "parent_id = :parent_id" : "parent_id IS NULL");
        
        $stmt = $pdo->prepare($sql);
        $params = ['project_id' => $project_id, 'name' => $name];
        if ($selected_parent_id) {
            $params['parent_id'] = $selected_parent_id;
        }
        $stmt->execute($params);
        
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Aktywny etap o tej nazwie już istnieje na tym poziomie";
        }
    }
    
    // Automatyczne obliczanie sort_order (nowy etap na górze)
    if (empty($errors)) {
        $sort_sql = "SELECT MIN(sort_order) as min_order FROM project_cost_nodes WHERE project_id = :project_id";
        $sort_stmt = $pdo->prepare($sort_sql);
        $sort_stmt->execute(['project_id' => $project_id]);
        $min_result = $sort_stmt->fetch();
        
        $sort_order = ($min_result && $min_result['min_order'] !== null) ? ($min_result['min_order'] - 1) : 0;
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO project_cost_nodes (project_id, parent_id, name, description, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$project_id, $selected_parent_id ?: null, $name, $description, $sort_order]);
            
            logEvent("Utworzono etap kosztów: {$name} dla projektu ID: {$project_id}", 'INFO');
            
            header("Location: " . url('projekty.etapy', ['project_id' => $project_id, 'success' => 'created']));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd tworzenia etapu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas tworzenia etapu";
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// Tryb domyślny: jeśli przyszedł parent_id z GET → od razu podetap
$default_mode = $parent_id ? 'sub' : 'main';
// Jeśli błąd POST i był parent_id w POST → zachowaj tryb
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $default_mode = !empty($_POST['parent_id']) ? 'sub' : 'main';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj Etap</title>
    <link rel="stylesheet" href="/projekty/assets/projekty.css">
    <style>
        /* ── Przełącznik Etap / Podetap ── */
        .type-toggle {
            display: inline-flex;
            border: 2px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .type-toggle input[type="radio"] {
            display: none;
        }
        .type-toggle label {
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--bg-body);
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
            user-select: none;
            margin: 0;
            display: block;
        }
        .type-toggle label:first-of-type {
            border-right: 2px solid var(--border);
        }
        .type-toggle input[type="radio"]:checked + label {
            background: var(--color-primary);
            color: #fff;
        }
        .type-toggle input[type="radio"]:checked + label:hover {
            background: var(--color-primary-hover);
        }
        .type-toggle label:hover {
            background: var(--color-primary-light);
            color: var(--color-primary);
        }

        /* ── Sekcja podetapu (chowana/pokazywana) ── */
        #parent-section {
            display: none;
            margin-bottom: 20px;
        }
        #parent-section.visible {
            display: block;
        }

        /* ── form-actions standardowo ── */
        .form-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-top: 8px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

    <div class="container-narrow">

        <!-- HERO -->
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('projekty'); ?>">Projekty</a>
                    <?php if ($project): ?>
                        / <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>"><?php echo e($project['name']); ?></a>
                    <?php endif; ?>
                    / Dodaj etap
                </div>
                <h1>Dodaj etap</h1>
                <p><?php echo $project ? e($project['name']) : 'Wybierz projekt i wprowadź dane etapu'; ?></p>
            </div>
            <div class="hero-actions">
                <?php if ($project): ?>
                    <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>" class="btn-hero-secondary">← Powrót</a>
                <?php else: ?>
                    <a href="<?php echo url('projekty'); ?>" class="btn-hero-secondary">← Powrót</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">

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

                <form method="POST" action="" id="stageForm">

                    <?php if (!$project): ?>
                        <!-- Wybór projektu gdy brak kontekstu -->
                        <div class="form-group">
                            <label>Projekt <span class="required">*</span></label>
                            <select name="project_id" required onchange="window.location.href='<?php echo url('projekty.etapy.create'); ?>?project_id=' + this.value;">
                                <option value="">-- Wybierz projekt --</option>
                                <?php foreach ($all_projects as $proj): ?>
                                    <option value="<?php echo $proj['id']; ?>" <?php echo ($project_id ?? '') == $proj['id'] ? 'selected' : ''; ?>>
                                        <?php echo e($proj['name']); ?> (<?php echo $proj['status'] === 'active' ? 'Aktywny' : 'Planowany'; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                    <?php endif; ?>

                    <?php if ($project): ?>
                        <!-- ── Przełącznik typu ── -->
                        <div class="type-toggle">
                            <input type="radio" name="_type" id="type-main" value="main" <?php echo $default_mode === 'main' ? 'checked' : ''; ?>>
                            <label for="type-main">Etap</label>
                            <input type="radio" name="_type" id="type-sub" value="sub" <?php echo $default_mode === 'sub' ? 'checked' : ''; ?>>
                            <label for="type-sub">Podetap</label>
                        </div>

                        <!-- ── Wybór etapu nadrzędnego (tylko przy Podetap) ── -->
                        <div id="parent-section" class="form-group <?php echo $default_mode === 'sub' ? 'visible' : ''; ?>">
                            <label for="parent_id_select">Etap <span class="required">*</span></label>
                            <select name="parent_id" id="parent_id_select">
                                <option value="">-- Wybierz etap --</option>
                                <?php foreach ($main_nodes as $node): ?>
                                    <option value="<?php echo $node['id']; ?>"
                                        <?php echo (($_POST['parent_id'] ?? $parent_id) == $node['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($node['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text">Wybierz etap, do którego należy ten podetap</div>
                        </div>
                    <?php endif; ?>

                    <!-- ── Nazwa ── -->
                    <div class="form-group">
                        <label for="name_input">Nazwa <span class="required">*</span></label>
                        <input type="text" id="name_input" name="name" value="<?php echo e($_POST['name'] ?? ''); ?>"
                               placeholder="np. Robocizna, Materiały, Spawanie..." required autofocus>
                        <div class="help-text">Krótka i opisowa — widoczna na liście etapów projektu</div>
                    </div>

                    <!-- ── Opis ── -->
                    <div class="form-group">
                        <label>Opis <span style="font-weight:400;color:var(--text-muted);">(opcjonalnie)</span></label>
                        <textarea name="description" rows="3" placeholder="Cel, zakres prac, dodatkowe uwagi..."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="submitBtn">Utwórz etap</button>
                        <?php if ($project): ?>
                            <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>" class="btn btn-secondary">Anuluj</a>
                        <?php else: ?>
                            <a href="<?php echo url('projekty'); ?>" class="btn btn-secondary">Anuluj</a>
                        <?php endif; ?>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>

    <script>
    (function () {
        var radioMain = document.getElementById('type-main');
        var radioSub  = document.getElementById('type-sub');
        var parentSection = document.getElementById('parent-section');
        var parentSelect  = document.getElementById('parent_id_select');
        var submitBtn     = document.getElementById('submitBtn');

        if (!radioMain || !radioSub) return;

        function applyMode(mode) {
            if (mode === 'sub') {
                parentSection.classList.add('visible');
                if (parentSelect) parentSelect.setAttribute('required', 'required');
                if (submitBtn) submitBtn.textContent = 'Utwórz podetap';
            } else {
                parentSection.classList.remove('visible');
                if (parentSelect) {
                    parentSelect.removeAttribute('required');
                    parentSelect.value = '';
                }
                if (submitBtn) submitBtn.textContent = 'Utwórz etap';
            }
        }

        radioMain.addEventListener('change', function () { applyMode('main'); });
        radioSub.addEventListener('change',  function () { applyMode('sub');  });

        // Ustaw stan początkowy (może być pre-zaznaczony przez PHP)
        applyMode(radioSub.checked ? 'sub' : 'main');
    })();
    </script>
</body>
</html>
