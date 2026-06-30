<?php
/**
 * BRYGAD ERP v3.0 - Masowe Przypisanie Stawek - Formularz
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Pobierz projekty (dla bulk rates)
try {
    $stmt = $pdo->query("SELECT id, name FROM projects WHERE status IN ('active', 'planned') ORDER BY name ASC");
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("Błąd pobierania projektów: " . $e->getMessage(), 'ERROR');
    $projects = [];
}

// Pobierz aktywnych pracowników
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name ASC, first_name ASC");
    $workers = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent("Błąd pobierania pracowników: " . $e->getMessage(), 'ERROR');
    $workers = [];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Masowe Przypisanie Stawek</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --danger: #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px; }
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
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; align-self: center; }
        .btn-hero-secondary {
            background: rgba(255,255,255,0.1); color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2); font-weight: 600;
            padding: 8px 16px; border-radius: 8px; text-decoration: none;
            font-size: 13px; display: inline-flex; align-items: center; transition: all 0.2s;
        }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        /* Card */
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); overflow: hidden; }
        .card-body { padding: 28px; }

        /* Alerts */
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; border-left: 4px solid; }
        .alert-error { background: #fef2f2; border-color: #dc2626; color: #991b1b; }
        .alert ul { margin: 8px 0 0 18px; }

        /* Info box */
        .info-box { background: #f0f9ff; border-left: 3px solid #0284c7; padding: 14px 18px; border-radius: 0 8px 8px 0; margin-bottom: 22px; font-size: 13px; }
        .info-box strong { display: block; margin-bottom: 4px; color: #0369a1; font-size: 12px; text-transform: uppercase; letter-spacing: 0.4px; }
        .info-box p { color: var(--text-main); line-height: 1.6; }

        /* Form */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: var(--text-main); font-size: 13px; }
        .required { color: var(--danger); margin-left: 2px; }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select,
        .form-group textarea {
            width: 100%; padding: 9px 12px;
            border: 1px solid var(--border); border-radius: 6px;
            font-size: 13px; font-family: inherit; color: var(--text-main);
            background: white; transition: border-color 0.15s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 2px rgba(102,126,234,0.1); }
        .form-group textarea { min-height: 90px; resize: vertical; }
        .form-group small { display: block; margin-top: 5px; color: var(--text-muted); font-size: 12px; }

        /* Checkbox group */
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
        .checkbox-group label { margin: 0; cursor: pointer; font-weight: 500; font-size: 13px; }

        /* Worker selection */
        .worker-selection { margin-top: 14px; padding: 16px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; }
        .worker-selection h4 { font-size: 13px; font-weight: 700; margin-bottom: 12px; color: var(--text-main); text-transform: uppercase; letter-spacing: 0.4px; }
        .worker-list { max-height: 280px; overflow-y: auto; margin-bottom: 12px; padding: 8px; background: white; border-radius: 6px; border: 1px solid var(--border); }
        .worker-item { display: flex; align-items: center; gap: 10px; padding: 7px 8px; border-bottom: 1px solid #f3f4f6; }
        .worker-item:last-child { border-bottom: none; }
        .worker-item input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
        .worker-item label { margin: 0; cursor: pointer; font-weight: 400; font-size: 13px; flex: 1; }
        .worker-selection-buttons { display: flex; gap: 8px; margin-top: 8px; }

        /* Buttons */
        .form-actions { display: flex; gap: 10px; padding-top: 18px; border-top: 1px solid #f3f4f6; margin-top: 24px; }
        .btn {
            padding: 9px 22px; border-radius: 7px; font-weight: 600; font-size: 13px;
            cursor: pointer; border: 1px solid transparent; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; font-family: inherit;
        }
        .btn-primary   { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; color: white; }
        .btn-secondary { background: white; color: #374151; border-color: var(--border); }
        .btn-secondary:hover { background: #f9fafb; }
        .btn-sm {
            padding: 6px 14px; font-size: 12px; border-radius: 6px;
            background: #f3f4f6; color: #374151; border: 1px solid var(--border);
            cursor: pointer; font-family: inherit; transition: all 0.15s;
        }
        .btn-sm:hover { background: #e5e7eb; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('hr.workers.rates_table'); ?>">Stawki — tabela</a> /
                    Masowe przypisanie
                </div>
                <h1>Masowe Przypisanie Stawek</h1>
                <p>Przypisz stawki dla aktywnych pracowników w wybranym projekcie</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('hr.workers.rates_table'); ?>" class="btn-hero-secondary">← Wróć do stawek</a>
            </div>
        </div>
        
        <?php if (isset($_GET['error']) && $_GET['error'] === 'bulk_rates' && isset($_SESSION['bulk_rates_errors'])): ?>
            <div class="alert alert-error">
                <strong>Błąd!</strong>
                <ul>
                    <?php foreach ($_SESSION['bulk_rates_errors'] as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php unset($_SESSION['bulk_rates_errors']); ?>
        <?php endif; ?>
        
        <div class="info-box">
            <strong>Informacja</strong>
            <p>
                Ten formularz pozwala na jednorazowe przypisanie stawek dla aktywnych pracowników w wybranym projekcie. 
                Możesz wybrać wszystkich pracowników lub tylko wybrane osoby. 
                Możesz też zdecydować, czy nadpisać istniejące stawki, czy tylko dodać brakujące.
            </p>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="POST" action="bulk_rates.php">
                    <div class="form-group">
                        <label>
                            Projekt <span class="required">*</span>
                        </label>
                        <select name="project_id" required>
                            <option value="">-- Wybierz projekt --</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>">
                                    <?php echo e($project['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Projekt, dla którego zostaną przypisane stawki</small>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            Stawka Podstawowa <span class="required">*</span>
                        </label>
                        <input type="number" 
                               name="rate_regular" 
                               step="0.01" 
                               min="0"
                               placeholder="np. 35.00"
                               required>
                        <small>Stawka za normalną godzinę pracy (poniedziałek-piątek, dzień)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Sobota</label>
                        <input type="number" 
                               name="rate_saturday" 
                               step="0.01" 
                               min="0"
                               placeholder="np. 40.00">
                        <small>Stawka za godzinę pracy w sobotę (opcjonalne)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Niedziela</label>
                        <input type="number" 
                               name="rate_sunday" 
                               step="0.01" 
                               min="0"
                               placeholder="np. 50.00">
                        <small>Stawka za godzinę pracy w niedzielę (opcjonalne)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Praca Nocna</label>
                        <input type="number" 
                               name="rate_night" 
                               step="0.01" 
                               min="0"
                               placeholder="np. 45.00">
                        <small>Stawka za godzinę pracy nocnej (opcjonalne)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Nadgodziny</label>
                        <input type="number" 
                               name="rate_overtime" 
                               step="0.01" 
                               min="0"
                               placeholder="np. 50.00">
                        <small>Stawka za nadgodziny (opcjonalne)</small>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="overwrite" id="overwrite" checked>
                            <label for="overwrite">Nadpisz istniejące stawki</label>
                        </div>
                        <small>Jeśli zaznaczone: zaktualizuje istniejące stawki. Jeśli odznaczone: doda stawki tylko dla pracowników, którzy ich nie mają.</small>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="apply_to_all" id="apply_to_all" checked>
                            <label for="apply_to_all">Zastosuj do wszystkich pracowników</label>
                        </div>
                        <small>Jeśli zaznaczone: stawki zostaną przypisane wszystkim aktywnym pracownikom. Jeśli odznaczone: możesz wybrać konkretnych pracowników.</small>
                        
                        <div id="worker-selection" class="worker-selection hidden">
                            <h4>Wybierz pracowników</h4>
                            <div class="worker-list">
                                <?php if (empty($workers)): ?>
                                    <p style="padding: 10px; color: #666;">Brak aktywnych pracowników.</p>
                                <?php else: ?>
                                    <?php foreach ($workers as $worker): ?>
                                        <div class="worker-item">
                                            <input type="checkbox" 
                                                   name="selected_worker_ids[]" 
                                                   value="<?php echo $worker['id']; ?>"
                                                   id="worker_<?php echo $worker['id']; ?>">
                                            <label for="worker_<?php echo $worker['id']; ?>">
                                                <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($workers)): ?>
                            <div class="worker-selection-buttons">
                                <button type="button" class="btn-sm" onclick="selectAllWorkers()">Zaznacz wszystkich</button>
                                <button type="button" class="btn-sm" onclick="deselectAllWorkers()">Odznacz wszystkich</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Zastosuj Stawki</button>
                        <a href="<?php echo url('hr.workers'); ?>" class="btn btn-secondary">Anuluj</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle widoczności sekcji wyboru pracowników
        document.getElementById('apply_to_all').addEventListener('change', function() {
            const workerSelection = document.getElementById('worker-selection');
            if (this.checked) {
                workerSelection.classList.add('hidden');
            } else {
                workerSelection.classList.remove('hidden');
            }
        });
        
        // Zaznacz wszystkich pracowników
        function selectAllWorkers() {
            const checkboxes = document.querySelectorAll('input[name="selected_worker_ids[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = true);
        }
        
        // Odznacz wszystkich pracowników
        function deselectAllWorkers() {
            const checkboxes = document.querySelectorAll('input[name="selected_worker_ids[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = false);
        }
    </script>
</body>
</html>

