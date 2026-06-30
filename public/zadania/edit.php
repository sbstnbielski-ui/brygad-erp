<?php
/**
 * SPRUTEX - Edytuj Zadanie
 */
require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Ścieżka do uploadu załączników zadań
define('TASK_UPLOADS_PATH', UPLOADS_PATH . '/tasks');

// ID zadania
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$taskId) {
    header('Location: ' . url('zadania.admin'));
    exit;
}

// Pobierz zadanie
$stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND is_active = 1");
$stmt->execute([$taskId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    header('Location: ' . url('zadania.admin'));
    exit;
}

// Pobierz aktywnych pracowników
$stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
$workers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz przypisanych pracowników
$stmt = $pdo->prepare("SELECT worker_id FROM task_assignments WHERE task_id = ?");
$stmt->execute([$taskId]);
$assignedWorkers = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Pobierz istniejące załączniki
$stmt = $pdo->prepare("SELECT * FROM task_attachments WHERE task_id = ? ORDER BY created_at DESC");
$stmt->execute([$taskId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = false;

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
            continue;
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

// Obsługa usuwania załącznika
if (isset($_GET['delete_attachment'])) {
    $attachmentId = (int)$_GET['delete_attachment'];
    
    // Pobierz ścieżkę pliku
    $stmt = $pdo->prepare("SELECT file_path FROM task_attachments WHERE id = ? AND task_id = ?");
    $stmt->execute([$attachmentId, $taskId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($attachment) {
        // Usuń plik z dysku
        $filePath = ROOT_PATH . '/' . $attachment['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Usuń z bazy
        $stmt = $pdo->prepare("DELETE FROM task_attachments WHERE id = ? AND task_id = ?");
        $stmt->execute([$attachmentId, $taskId]);
        
        logEvent("Usunięto załącznik #$attachmentId z zadania #$taskId", 'INFO');
    }
    
    header('Location: ' . url('zadania.edit', ['id' => $taskId]));
    exit;
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
            
            // Aktualizuj zadanie
            $stmt = $pdo->prepare("
                UPDATE tasks 
                SET title = ?, description = ?, priority = ?, due_date = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $title,
                $description ?: null,
                $priority,
                $dueDate ?: null,
                $taskId
            ]);
            
            // Usuń stare przypisania
            $stmt = $pdo->prepare("DELETE FROM task_assignments WHERE task_id = ?");
            $stmt->execute([$taskId]);
            
            // Dodaj nowe przypisania
            $stmt = $pdo->prepare("
                INSERT INTO task_assignments (task_id, worker_id, status, created_at)
                VALUES (?, ?, 'todo', NOW())
            ");
            
            foreach ($selectedWorkers as $workerId) {
                $stmt->execute([$taskId, (int)$workerId]);
            }
            
            // Obsługa nowych załączników
            $uploadedFiles = handleTaskAttachments($pdo, $taskId, $_SESSION['user_id']);
            
            $pdo->commit();
            
            $attachmentCount = count($uploadedFiles);
            logEvent("Zaktualizowano zadanie #$taskId: $title, nowe załączniki: $attachmentCount", 'INFO');
            
            $success = true;
            header("Refresh: 2; url=" . url('zadania.admin'));
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Błąd zapisu zadania.';
            logEvent("Błąd aktualizacji zadania: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Odśwież listę załączników
$stmt = $pdo->prepare("SELECT * FROM task_attachments WHERE task_id = ? ORDER BY created_at DESC");
$stmt->execute([$taskId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?php echo e(APP_NAME); ?> - Edytuj Zadanie</title>
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
        .btn-danger { background: #dc3545; color: white; padding: 6px 12px; font-size: 12px; }
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
        .existing-attachments { margin-top: 20px; }
        .existing-attachments h4 { margin-bottom: 15px; color: #555; }
        .attachment-item { display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8f9fa; border-radius: 6px; margin-bottom: 10px; }
        .attachment-item a { color: #667eea; text-decoration: none; flex: 1; }
        .attachment-item a:hover { text-decoration: underline; }
        .attachment-meta { font-size: 12px; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Edytuj Zadanie</h2>
        <p style="color: #666; margin-bottom: 30px;">Modyfikacja zadania operacyjnego</p>
        
        <div class="card">
            <?php if ($success): ?>
                <div class="alert-success">Zadanie zostało zaktualizowane! Przekierowanie...</div>
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
                        <input type="text" name="title" value="<?php echo e($_POST['title'] ?? $task['title']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Opis</label>
                        <textarea name="description"><?php echo e($_POST['description'] ?? $task['description']); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Priorytet</label>
                        <?php $currentPriority = $_POST['priority'] ?? $task['priority']; ?>
                        <select name="priority">
                            <option value="low" <?php echo $currentPriority === 'low' ? 'selected' : ''; ?>>Niski</option>
                            <option value="medium" <?php echo $currentPriority === 'medium' ? 'selected' : ''; ?>>Średni</option>
                            <option value="high" <?php echo $currentPriority === 'high' ? 'selected' : ''; ?>>Wysoki</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Termin (opcjonalnie)</label>
                        <input type="date" name="due_date" value="<?php echo e($_POST['due_date'] ?? $task['due_date']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Przypisz do pracowników <span class="required">*</span></label>
                        <?php $currentWorkers = $_POST['workers'] ?? $assignedWorkers; ?>
                        <div class="workers-list">
                            <?php foreach ($workers as $worker): ?>
                                <label>
                                    <input type="checkbox" name="workers[]" value="<?php echo $worker['id']; ?>"
                                           <?php echo in_array($worker['id'], $currentWorkers) ? 'checked' : ''; ?>>
                                    <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($attachments)): ?>
                    <div class="form-group">
                        <label>Istniejące załączniki</label>
                        <div class="existing-attachments">
                            <?php foreach ($attachments as $att): ?>
                                <div class="attachment-item">
                                    <span class="file-icon">📎</span>
                                    <a href="/<?php echo e($att['file_path']); ?>" target="_blank">
                                        <?php echo e($att['original_name']); ?>
                                    </a>
                                    <a href="<?php echo url('zadania.edit', ['id' => $taskId, 'delete_attachment' => $att['id']]); ?>" 
                                       class="btn btn-danger"
                                       onclick="return confirm('Usunąć ten załącznik?');">
                                        Usuń
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Dodaj nowe załączniki</label>
                        <div class="file-upload-area">
                            <input type="file" name="attachments[]" id="attachments" multiple 
                                   accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
                            <p class="file-hint">Dozwolone formaty: JPG, PNG, GIF, PDF, DOC, DOCX, XLS, XLSX, TXT, CSV. Maks. 10 MB/plik.</p>
                        </div>
                        <div id="file-list" class="file-list"></div>
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary">Zapisz zmiany</button>
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

