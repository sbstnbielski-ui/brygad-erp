<?php
/**
 * BRYGAD ERP v3.0 - Edycja Projektu
 * 
 * Obsługuje:
 * - Edycję podstawowych danych projektu (nazwa, status, daty)
 * - Zarządzanie przychodami projektu (umowy/aneksy) - Task 1
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$success_message = '';
$project_id = $_GET['id'] ?? null;

if (!$project_id) {
    header("Location: " . url('projekty'));
    exit;
}

// Pobierz dane projektu
$stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
$stmt->execute(['id' => $project_id]);
$project = $stmt->fetch();

if (!$project) {
    header("Location: " . url('projekty'));
    exit;
}

// Pobierz listę aktywnych inwestorów
$stmt = $pdo->query("SELECT id, name FROM investors WHERE is_active = 1 ORDER BY name ASC");
$investors = $stmt->fetchAll();

// =====================================================
// Obsługa dodawania przychodu (umowy/aneksu)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_revenue') {
    $rev_type = $_POST['rev_type'] ?? 'contract';
    $rev_name = trim($_POST['rev_name'] ?? '');
    $rev_amount = trim($_POST['rev_amount'] ?? '');
    $rev_signed_date = $_POST['rev_signed_date'] ?? '';
    $rev_description = trim($_POST['rev_description'] ?? '');
    
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
            $stmt = $pdo->prepare("
                INSERT INTO project_revenues (project_id, type, name, amount_net, signed_date, description, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$project_id, $rev_type, $rev_name, $rev_amount, $rev_signed_date, $rev_description ?: null]);
            
            logEvent("Dodano przychód projektu ID: {$project_id}, typ: {$rev_type}, kwota: {$rev_amount} PLN", 'INFO');
            
            header("Location: " . url('projekty.edit', ['id' => $project_id, 'success' => 'revenue_added']));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd dodawania przychodu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas dodawania przychodu";
        }
    }
}

// =====================================================
// Obsługa usuwania przychodu
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_revenue') {
    $rev_id = (int)($_POST['revenue_id'] ?? 0);
    
    if ($rev_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM project_revenues WHERE id = ? AND project_id = ?");
            $stmt->execute([$rev_id, $project_id]);
            
            logEvent("Usunięto przychód ID: {$rev_id} z projektu ID: {$project_id}", 'INFO');
            
            header("Location: " . url('projekty.edit', ['id' => $project_id, 'success' => 'revenue_deleted']));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd usuwania przychodu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas usuwania przychodu";
        }
    }
}

// Pobierz przychody projektu
$stmt = $pdo->prepare("SELECT * FROM project_revenues WHERE project_id = ? ORDER BY signed_date DESC, created_at DESC");
$stmt->execute([$project_id]);
$revenues = $stmt->fetchAll();

// Oblicz sumę przychodów
$total_revenue = array_sum(array_column($revenues, 'amount_net'));

// Obsługa komunikatów sukcesu
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'revenue_added':
            $success_message = 'Przychód został dodany pomyślnie';
            break;
        case 'revenue_deleted':
            $success_message = 'Przychód został usunięty';
            break;
    }
}

// =====================================================
// Obsługa edycji podstawowych danych projektu
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'update_project')) {
    $name = trim($_POST['name'] ?? '');
    $investor_id = $_POST['investor_id'] ?? null;
    $status = $_POST['status'] ?? 'planned';
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    
    if (empty($name)) {
        $errors[] = "Nazwa projektu jest wymagana";
    }
    
    if (!in_array($status, ['planned', 'active', 'finished'])) {
        $errors[] = "Nieprawidłowy status";
    }
    
    if ($status === 'finished' && $project['is_internal']) {
        $errors[] = "Nie można zakończyć projektu wewnętrznego";
    }
    
    // Sprawdź czy nie ma niezatwierdzonych kosztów
    if ($status === 'finished') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as pending_count
            FROM (
                SELECT id FROM work_logs WHERE project_id = :id1 AND status = 'pending'
                UNION ALL
                SELECT id FROM worker_expenses WHERE project_id = :id2 AND status = 'pending'
            ) as pending_items
        ");
        $stmt->execute(['id1' => $project_id, 'id2' => $project_id]);
        $pending = $stmt->fetch();
        
        if ($pending['pending_count'] > 0) {
            $errors[] = "Nie można zakończyć projektu - są niezatwierdzone koszty ({$pending['pending_count']} pozycji)";
        }
    }
    
    if ($start_date && $end_date && strtotime($end_date) < strtotime($start_date)) {
        $errors[] = "Data zakończenia nie może być wcześniejsza niż data rozpoczęcia";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE projects SET name = ?, investor_id = ?, status = ?, start_date = ?, end_date = ? WHERE id = ?");
            $stmt->execute([
                $name, 
                $investor_id ?: null, 
                $status, 
                $start_date ?: null, 
                $end_date ?: null, 
                $project_id
            ]);
            
            logEvent("Zaktualizowano projekt ID: {$project_id} ({$name})", 'INFO');
            
            header("Location: " . url('projekty.view', ['id' => $project_id, 'success' => 'updated']));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd aktualizacji projektu: " . $e->getMessage(), 'ERROR');
            $errors[] = "Błąd podczas aktualizacji projektu: " . $e->getMessage();
        }
    }
}

// Ustaw domyślne wartości formularza
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($errors)) {
    $_POST = array_merge([
        'name' => $project['name'],
        'investor_id' => $project['investor_id'],
        'status' => $project['status'],
        'start_date' => $project['start_date'],
        'end_date' => $project['end_date']
    ], $_POST);
}

// Typy przychodów do wyświetlenia
$revenue_types = [
    'contract' => 'Umowa',
    'annex' => 'Aneks',
    'bonus' => 'Premia'
];

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Edytuj Projekt</title>
    <link rel="stylesheet" href="/projekty/assets/projekty.css">
    <style>
        /* Styles specyficzne dla formularza edycji projektu */
        .revenue-table { width: 100%; margin-top: 20px; }
        .revenue-actions { display: flex; gap: 10px; }
        .btn-delete { background: #dc2626; color: white; padding: 6px 12px; font-size: 12px; }
        .btn-delete:hover { background: #b91c1c; }
        
        /* Modal */
        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-backdrop.active {
            display: flex;
        }
        .modal {
            background: white;
            border-radius: 8px;
            padding: 0;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
            line-height: 1;
        }
        .modal-close:hover {
            color: #000;
        }
        .modal form {
            padding: 20px;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo url('projekty', ['project_type' => $project['project_type'] ?? 'standard']); ?>">Projekty</a> / 
            <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>"><?php echo e($project['name']); ?></a> / 
            Edycja
        </div>
        
        <div class="page-header">
            <h2>Edytuj Projekt</h2>
        </div>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo e($success_message); ?></div>
        <?php endif; ?>

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
            
            <?php if (($_POST['status'] ?? '') === 'finished'): ?>
                <div class="alert alert-warning">
                    <strong>Uwaga:</strong> Projekt zakończony nie będzie przyjmował nowych kosztów.
                </div>
            <?php endif; ?>
            
            <h3 class="section-title">Dane Projektu</h3>
            
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_project">
                
                <div class="form-group">
                    <label>Nazwa projektu <span class="required">*</span></label>
                    <input type="text" name="name" value="<?php echo e($_POST['name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Inwestor / Klient</label>
                    <div class="field-with-action">
                        <select name="investor_id" id="investor_id">
                            <option value="">-- Brak inwestora --</option>
                            <?php foreach ($investors as $inv): ?>
                                <option value="<?php echo $inv['id']; ?>" 
                                        <?php echo ($_POST['investor_id'] ?? '') == $inv['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($inv['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a href="<?php echo url('investors.create'); ?>" class="btn btn-secondary btn-sm" target="_blank">
                            + Nowy
                        </a>
                    </div>
                    <div class="help-text">Opcjonalnie - można dodać nowego inwestora w nowej karcie</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="planned" <?php echo ($_POST['status'] ?? '') === 'planned' ? 'selected' : ''; ?>>Planowany</option>
                            <option value="active" <?php echo ($_POST['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Aktywny</option>
                            <?php if (!$project['is_internal']): ?>
                                <option value="finished" <?php echo ($_POST['status'] ?? '') === 'finished' ? 'selected' : ''; ?>>Zakończony</option>
                            <?php endif; ?>
                        </select>
                        <?php if ($project['is_internal']): ?>
                            <div class="help-text">Projekt wewnętrzny nie może być zakończony</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Data rozpoczęcia</label>
                        <input type="date" name="start_date" value="<?php echo e($_POST['start_date'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Data zakończenia</label>
                    <input type="date" name="end_date" value="<?php echo e($_POST['end_date'] ?? ''); ?>">
                    <div class="help-text">Opcjonalnie - może być uzupełniona później</div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Zapisz Zmiany</button>
                    <a href="<?php echo url('projekty.view', ['id' => $project_id]); ?>" class="btn btn-secondary">Anuluj</a>
                </div>
            </form>
            
            <hr class="separator">
            
            <!-- =====================================================
                 SEKCJA: FINANSOWANIE / ANEKSY (Task 1)
                 ===================================================== -->
            <h3 class="section-title">Finansowanie / Aneksy</h3>
            
            <div class="revenue-total">
                Suma przychodów: <?php echo formatMoney($total_revenue); ?>
            </div>
            
            <?php if (!empty($revenues)): ?>
                <table class="revenues-table">
                    <thead>
                        <tr>
                            <th>Typ</th>
                            <th>Nazwa</th>
                            <th>Kwota netto</th>
                            <th>Data podpisania</th>
                            <th>Opis</th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revenues as $rev): ?>
                            <tr>
                                <td>
                                    <span class="badge-sm badge-<?php echo e($rev['type']); ?>">
                                        <?php echo e($revenue_types[$rev['type']] ?? $rev['type']); ?>
                                    </span>
                                </td>
                                <td><strong><?php echo e($rev['name']); ?></strong></td>
                                <td style="font-weight: 600; color: #28a745;"><?php echo formatMoney($rev['amount_net']); ?></td>
                                <td><?php echo formatDate($rev['signed_date']); ?></td>
                                <td><?php echo e($rev['description'] ?: '-'); ?></td>
                                <td>
                                    <form method="POST" action="" style="display:inline;" 
                                          onsubmit="return confirm('Czy na pewno chcesz usunąć ten przychód?');">
                                        <input type="hidden" name="action" value="delete_revenue">
                                        <input type="hidden" name="revenue_id" value="<?php echo $rev['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Usuń</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">
                    Brak zdefiniowanych przychodów dla tego projektu.<br>
                    Dodaj umowę lub aneks, aby śledzić rentowność.
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 20px;">
                <button type="button" class="btn btn-primary" onclick="openRevenueModal()">
                    + Dodaj Umowę / Aneks
                </button>
            </div>
        </div>
        
        <!-- Modal: Dodaj Przychód -->
        <div id="revenueModal" class="modal-backdrop">
            <div class="modal">
                <div class="modal-header">
                    <h3>Dodaj Przychód</h3>
                    <button type="button" class="modal-close" onclick="closeRevenueModal()">&times;</button>
                </div>
                
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_revenue">
                    
                    <div class="form-group">
                        <label>Typ dokumentu <span class="required">*</span></label>
                        <select name="rev_type" required>
                            <option value="contract">Umowa</option>
                            <option value="annex">Aneks</option>
                            <option value="bonus">Premia / Bonus</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Nazwa <span class="required">*</span></label>
                        <input type="text" name="rev_name" placeholder="np. Umowa główna, Aneks nr 1..." required>
                    </div>
                    
                    <div class="form-group">
                        <label>Kwota netto (PLN) <span class="required">*</span></label>
                        <input type="number" name="rev_amount" step="0.01" min="0" placeholder="0.00" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Data podpisania <span class="required">*</span></label>
                        <input type="date" name="rev_signed_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Opis (opcjonalnie)</label>
                        <textarea name="rev_description" rows="3" placeholder="Dodatkowe informacje..."></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Dodaj Przychód</button>
                        <button type="button" class="btn btn-secondary" onclick="closeRevenueModal()">Anuluj</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            function openRevenueModal() {
                document.getElementById('revenueModal').classList.add('active');
            }
            
            function closeRevenueModal() {
                document.getElementById('revenueModal').classList.remove('active');
            }
            
            // Zamknij modal po kliknięciu poza nim
            document.getElementById('revenueModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeRevenueModal();
                }
            });
            
            // Zamknij modal klawiszem Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeRevenueModal();
                }
            });
        </script>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
