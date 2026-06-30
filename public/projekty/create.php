<?php
/**
 * BRYGAD ERP v3.0 - Dodawanie Projektu
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];

// Pobierz typ projektu z URL (standard = duży, micro = mały)
$project_type = $_GET['type'] ?? 'standard';
if (!in_array($project_type, ['standard', 'micro'])) {
    $project_type = 'standard';
}

// Pobierz investor_id z URL (jeśli wróciliśmy z pełnego formularza)
$preselected_investor_id = $_GET['investor_id'] ?? null;

// Pobierz listę aktywnych inwestorów
$stmt = $pdo->query("SELECT id, name FROM investors WHERE is_active = 1 ORDER BY name ASC");
$investors = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $project_type = $_POST['project_type'] ?? 'standard'; // z formularza
    $investor_id = $_POST['investor_id'] ?? null;
    $contract_amount = $_POST['contract_amount'] ?? null;
    $status = $_POST['status'] ?? 'planned';
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    
    if (empty($name)) {
        $errors[] = "Nazwa projektu jest wymagana";
    }
    
    if (!in_array($status, ['planned', 'active', 'finished'])) {
        $errors[] = "Nieprawidłowy status";
    }
    
    if ($contract_amount !== null && $contract_amount !== '' && (!is_numeric($contract_amount) || $contract_amount < 0)) {
        $errors[] = "Kwota umowy musi być liczbą większą lub równą 0";
    }
    
    if ($start_date && $end_date && strtotime($end_date) < strtotime($start_date)) {
        $errors[] = "Data zakończenia nie może być wcześniejsza niż data rozpoczęcia";
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $contractAmountValue = ($contract_amount !== null && $contract_amount !== '') ? (float)$contract_amount : null;

            $stmt = $pdo->prepare("INSERT INTO projects (name, project_type, investor_id, contract_amount, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name,
                $project_type,
                $investor_id ?: null, 
                $contractAmountValue,
                $status, 
                $start_date ?: null, 
                $end_date ?: null
            ]);
            
            $project_id = $pdo->lastInsertId();

            if ($contractAmountValue !== null && $contractAmountValue > 0) {
                $signedDate = $start_date ?: date('Y-m-d');
                $stmt = $pdo->prepare("
                    INSERT INTO project_revenues (project_id, cost_node_id, type, name, amount_net, signed_date, description, created_at)
                    VALUES (?, NULL, 'contract', 'Umowa bazowa', ?, ?, 'Dodano automatycznie przy tworzeniu projektu', NOW())
                ");
                $stmt->execute([$project_id, $contractAmountValue, $signedDate]);
            }

            $pdo->commit();
            logEvent("Utworzono projekt: {$name} (ID: {$project_id})", 'INFO');
            
            header("Location: " . url('projekty.view', ['id' => $project_id, 'success' => 'created']));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            logEvent("Błąd tworzenia projektu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas tworzenia projektu";
        }
    }
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dodaj <?php echo $project_type === 'micro' ? 'Mikroprojekt' : 'Projekt'; ?></title>
    <link rel="stylesheet" href="/projekty/assets/projekty.css">
    <style>
        /* Styles specyficzne dla formularza dodawania projektu */
        .container-narrow {
            max-width: 800px;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container-narrow">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> <span class="separator">›</span> 
            <a href="<?php echo url('projekty', ['project_type' => $project_type]); ?>">Projekty</a> / 
            Dodaj <?php echo $project_type === 'micro' ? 'Mikroprojekt' : 'Projekt'; ?>
        </div>
        
        <div class="page-header">
            <h2>Dodaj <?php echo $project_type === 'micro' ? 'Mikroprojekt' : 'Projekt'; ?></h2>
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
            
            <form method="POST" action="">
                <input type="hidden" name="project_type" value="<?php echo e($project_type); ?>">
                
                <div class="form-group">
                    <label>Nazwa <?php echo $project_type === 'micro' ? 'mikroprojektu' : 'projektu'; ?> <span class="required">*</span></label>
                    <input type="text" name="name" value="<?php echo e($_POST['name'] ?? ''); ?>" 
                           placeholder="<?php echo $project_type === 'micro' ? 'np. Wynajem koparki - Firma ABC' : 'np. Budowa hali przemysłowej'; ?>" required>
                    <?php if ($project_type === 'micro'): ?>
                        <div class="help-text">
                            Krótkie zlecenia usługowe: wynajem maszyny z operatorem, transport, rozbiórka, itp.
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Inwestor / Klient</label>
                    <select name="investor_id" id="investor_id">
                        <option value="">-- Wybierz inwestora --</option>
                        <?php foreach ($investors as $inv): ?>
                            <option value="<?php echo $inv['id']; ?>" 
                                    <?php echo ($_POST['investor_id'] ?? $preselected_investor_id ?? '') == $inv['id'] ? 'selected' : ''; ?>>
                                <?php echo e($inv['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help-text">Opcjonalnie - można przypisać później. <a href="<?php echo url('investors.create'); ?>?return_to=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" style="color: #667eea; font-weight: 600;">+ Dodaj nowego inwestora</a></div>
                </div>
                
                <div class="form-group">
                    <label>Kwota Umowy Bazowej (Netto)</label>
                    <input type="number" name="contract_amount" step="0.01" min="0" 
                           value="<?php echo e($_POST['contract_amount'] ?? ''); ?>" 
                           placeholder="0.00">
                    <div class="help-text">Kwota kontraktu bazowego w PLN (bez aneksów). Opcjonalnie.</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Status początkowy</label>
                        <select name="status">
                            <option value="planned" <?php echo ($_POST['status'] ?? 'planned') === 'planned' ? 'selected' : ''; ?>>Planowany</option>
                            <option value="active" <?php echo ($_POST['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Aktywny</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Data rozpoczęcia</label>
                        <input type="date" name="start_date" value="<?php echo e($_POST['start_date'] ?? ''); ?>">
                        <div class="help-text">Opcjonalnie</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Planowana data zakończenia</label>
                    <input type="date" name="end_date" value="<?php echo e($_POST['end_date'] ?? ''); ?>">
                    <div class="help-text">Opcjonalnie - może być uzupełniona później</div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Utwórz Projekt</button>
                    <a href="<?php echo url('projekty'); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
