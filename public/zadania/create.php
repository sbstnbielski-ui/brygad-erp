<?php
/**
 * SPRUTEX - Dodaj Zadanie
 */
require_once dirname(__DIR__) . '/config/autoload.php'; // 1 poziom w dół
require_once dirname(__DIR__) . '/includes/push_service.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Ścieżka do uploadu załączników zadań
define('TASK_UPLOADS_PATH', UPLOADS_PATH . '/tasks');

// Pobierz aktywnych pracowników
$stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
$workers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = isset($_GET['success']);

/**
 * Obsługa uploadu plików załączników
 */
function handleTaskAttachments($pdo, $taskId, $userId) {
    $uploadedFiles = [];
    
    if (!isset($_FILES['attachments']) || empty($_FILES['attachments']['name'][0])) {
        return $uploadedFiles;
    }
    
    // Utwórz katalog jeśli nie istnieje
    if (!is_dir(TASK_UPLOADS_PATH)) {
        mkdir(TASK_UPLOADS_PATH, 0755, true);
    }
    
    $allowedTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain', 'text/csv'
    ];
    $maxFileSize = 10 * 1024 * 1024; // 10 MB
    
    $files = $_FILES['attachments'];
    $fileCount = count($files['name']);
    
    $stmt = $pdo->prepare("
        INSERT INTO task_attachments (task_id, file_path, original_name)
        VALUES (?, ?, ?)
    ");
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        $originalName = basename($files['name'][$i]);
        $fileSize = $files['size'][$i];
        $tmpPath = $files['tmp_name'][$i];
        $mimeType = mime_content_type($tmpPath);
        
        // Walidacja typu pliku
        if (!in_array($mimeType, $allowedTypes)) {
            continue; // Pomiń niedozwolone typy
        }
        
        // Walidacja rozmiaru
        if ($fileSize > $maxFileSize) {
            continue;
        }
        
        // Generuj unikalną nazwę pliku
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $extension;
        $targetPath = TASK_UPLOADS_PATH . '/' . $uniqueName;
        
        if (move_uploaded_file($tmpPath, $targetPath)) {
            // Zapisz do bazy - ścieżka względna
            $relativePath = 'uploads/tasks/' . $uniqueName;
            $stmt->execute([$taskId, $relativePath, $originalName]);
            
            $uploadedFiles[] = [
                'original_name' => $originalName,
                'path' => $relativePath
            ];
        }
    }
    
    return $uploadedFiles;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $dueDate = trim($_POST['due_date'] ?? '');
    $selectedWorkers = $_POST['workers'] ?? [];
    
    // Walidacja
    if (empty($title)) {
        $errors[] = 'Tytuł zadania jest wymagany.';
    }
    if (empty($selectedWorkers)) {
        $errors[] = 'Wybierz przynajmniej jednego pracownika.';
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Dodaj zadanie
            $stmt = $pdo->prepare("
                INSERT INTO tasks (title, description, priority, due_date, is_active, created_by, created_at)
                VALUES (?, ?, ?, ?, 1, ?, NOW())
            ");
            $stmt->execute([
                $title,
                $description ?: null,
                $priority,
                $dueDate ?: null,
                $_SESSION['user_id']
            ]);
            
            $taskId = $pdo->lastInsertId();
            
            // Przypisz do pracowników
            $stmt = $pdo->prepare("
                INSERT INTO task_assignments (task_id, worker_id, status, assigned_at)
                VALUES (?, ?, 'todo', NOW())
            ");
            
            foreach ($selectedWorkers as $workerId) {
                $stmt->execute([$taskId, (int)$workerId]);
            }
            
            // Obsługa załączników
            $uploadedFiles = handleTaskAttachments($pdo, $taskId, $_SESSION['user_id']);
            
            $pdo->commit();
            
            $attachmentCount = count($uploadedFiles);
            logEvent("Dodano zadanie: $title, przypisano " . count($selectedWorkers) . " pracowników, załączniki: $attachmentCount", 'INFO');
            
            // Wyślij powiadomienia push do przypisanych pracowników
            try {
                sendTaskNotification($taskId, $selectedWorkers, 'task_new');
            } catch (Exception $e) {
                logEvent("Błąd wysyłania push dla zadania {$taskId}: " . $e->getMessage(), 'WARNING');
            }
            
            // Przekieruj natychmiast (POST-redirect-GET pattern)
            header("Location: " . url('zadania.admin') . '?success=1');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Błąd zapisu zadania: ' . $e->getMessage();
            logEvent("Błąd dodawania zadania: " . $e->getMessage(), 'ERROR');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?php echo e(APP_NAME); ?> - Dodaj Zadanie</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #333; }
        .container { max-width: 900px; margin: 0 auto; padding: 30px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 40px; }
        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; }
        .required { color: #dc3545; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 12px 16px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 14px; font-family: inherit;
        }
        .form-group textarea { resize: vertical; min-height: 100px; }
        .workers-list { max-height: 200px; overflow-y: auto; border: 2px solid #e0e0e0; border-radius: 6px; padding: 10px; }
        .workers-list label { display: flex; align-items: center; gap: 10px; padding: 8px; cursor: pointer; }
        .workers-list label:hover { background: #f8f9fa; }
        .workers-list input[type="checkbox"] { width: 20px; height: 20px; }
        .btn { padding: 12px 32px; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .alert-success { padding: 15px; background: #d4edda; color: #155724; border-radius: 8px; margin-bottom: 20px; }
        .alert-error { padding: 15px; background: #f8d7da; color: #721c24; border-radius: 8px; margin-bottom: 20px; }
        .file-upload-area { border: 2px dashed #e0e0e0; border-radius: 6px; padding: 20px; text-align: center; background: #fafafa; transition: border-color 0.3s; }
        .file-upload-area:hover { border-color: #667eea; }
        .file-upload-area input[type="file"] { width: 100%; cursor: pointer; }
        .file-hint { margin-top: 10px; font-size: 12px; color: #888; }
        .file-list { margin-top: 15px; }
        .file-item { padding: 8px 12px; background: #e8f4fd; border-radius: 6px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .file-icon { font-size: 16px; }
        .file-size { color: #666; font-size: 12px; margin-left: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Dodaj Zadanie Operacyjne</h2>
        <p style="color: #666; margin-bottom: 30px;">Zlecenie zadania pracownikom</p>
        
        <div class="card">
            <?php if ($success): ?>
                <div class="alert-success">Zadanie zostało dodane! Przekierowanie...</div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="alert-error">
                    <ul style="margin: 10px 0 0 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if (!$success): ?>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Tytuł zadania <span class="required">*</span></label>
                        <input type="text" name="title" value="<?php echo e($_POST['title'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Opis</label>
                        <textarea name="description"><?php echo e($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Priorytet</label>
                        <select name="priority">
                            <option value="low" <?php echo ($_POST['priority'] ?? '') === 'low' ? 'selected' : ''; ?>>Niski</option>
                            <option value="medium" <?php echo ($_POST['priority'] ?? 'medium') === 'medium' ? 'selected' : ''; ?>>Średni</option>
                            <option value="high" <?php echo ($_POST['priority'] ?? '') === 'high' ? 'selected' : ''; ?>>Wysoki</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Termin (opcjonalnie)</label>
                        <input type="date" name="due_date" value="<?php echo e($_POST['due_date'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Przypisz do pracowników <span class="required">*</span></label>
                        <div class="workers-list">
                            <?php foreach ($workers as $worker): ?>
                                <label>
                                    <input type="checkbox" name="workers[]" value="<?php echo $worker['id']; ?>"
                                           <?php echo in_array($worker['id'], $_POST['workers'] ?? []) ? 'checked' : ''; ?>>
                                    <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Załączniki (opcjonalnie)</label>
                        <div class="file-upload-area">
                            <input type="file" name="attachments[]" id="attachments" multiple 
                                   accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
                            <p class="file-hint">Dozwolone formaty: JPG, PNG, GIF, PDF, DOC, DOCX, XLS, XLSX, TXT, CSV. Maks. 10 MB/plik.</p>
                        </div>
                        <div id="file-list" class="file-list"></div>
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary">Dodaj Zadanie</button>
                        <a href="<?php echo url('zadania.admin'); ?>" class="btn btn-secondary">Anuluj</a>
                    </div>
                </form>
                
                <script>
                document.getElementById('attachments').addEventListener('change', function(e) {
                    const fileList = document.getElementById('file-list');
                    fileList.innerHTML = '';
                    
                    Array.from(this.files).forEach(file => {
                        const div = document.createElement('div');
                        div.className = 'file-item';
                        div.innerHTML = '<span class="file-icon">📎</span> ' + file.name + ' <span class="file-size">(' + (file.size / 1024 / 1024).toFixed(2) + ' MB)</span>';
                        fileList.appendChild(div);
                    });
                });
                </script>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

