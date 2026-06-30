<?php
/**
 * SPRUTEX - Edycja Dokumentu HR
 */
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$docId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($docId <= 0) {
    header('Location: workers.php');
    exit;
}

// Pobierz dokument + pliki
$stmt = $pdo->prepare("
    SELECT wd.*, dt.name as type_name 
    FROM worker_documents wd
    JOIN document_types dt ON wd.document_type_id = dt.id
    WHERE wd.id = ? AND wd.status = 'active'
");
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    header('Location: workers.php');
    exit;
}

$workerId = $doc['worker_id'];

// Pobierz pracownika
$stmt = $pdo->prepare("SELECT * FROM workers WHERE id = ?");
$stmt->execute([$workerId]);
$worker = $stmt->fetch();

// Pobierz pliki
$stmt = $pdo->prepare("SELECT * FROM worker_document_files WHERE document_id = ? ORDER BY uploaded_at DESC");
$stmt->execute([$docId]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $documentNumber = trim($_POST['document_number'] ?? '');
    $issuer = trim($_POST['issuer'] ?? '');
    $validFrom = trim($_POST['valid_from'] ?? '');
    $validTo = trim($_POST['valid_to'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $reminderDays = !empty($_POST['reminder_days']) ? (int)$_POST['reminder_days'] : null;
    
    if (empty($title)) {
        $errors[] = 'Tytuł jest wymagany.';
    }
    
    // Upload nowych plików
    $uploadedFiles = [];
    if (!empty($_FILES['files']['name'][0])) {
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
        $maxFileSize = 10 * 1024 * 1024;
        
        foreach ($_FILES['files']['name'] as $key => $fileName) {
            if (empty($fileName)) continue;
            
            $fileTmpName = $_FILES['files']['tmp_name'][$key];
            $fileSize = $_FILES['files']['size'][$key];
            $fileType = $_FILES['files']['type'][$key];
            $fileError = $_FILES['files']['error'][$key];
            
            if ($fileError !== UPLOAD_ERR_OK) continue;
            if (!in_array($fileType, $allowedTypes)) continue;
            if ($fileSize > $maxFileSize) continue;
            
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $newFileName = 'doc_' . $workerId . '_' . time() . '_' . uniqid() . '.' . $ext;
            $uploadDir = UPLOADS_PATH . '/documents/';
            
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            
            $uploadPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($fileTmpName, $uploadPath)) {
                $uploadedFiles[] = [
                    'path' => 'uploads/documents/' . $newFileName,
                    'original' => $fileName,
                    'mime' => $fileType,
                    'size' => $fileSize
                ];
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                UPDATE worker_documents 
                SET title = ?, document_number = ?, issuer = ?, 
                    valid_from = ?, valid_to = ?, notes = ?, reminder_days = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $title,
                $documentNumber ?: null,
                $issuer ?: null,
                $validFrom ?: null,
                $validTo ?: null,
                $notes ?: null,
                $reminderDays,
                $docId
            ]);
            
            // Zapisz nowe pliki
            if (!empty($uploadedFiles)) {
                $stmt = $pdo->prepare("
                    INSERT INTO worker_document_files 
                    (document_id, file_path, original_name, mime_type, file_size, uploaded_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                foreach ($uploadedFiles as $file) {
                    $stmt->execute([
                        $docId,
                        $file['path'],
                        $file['original'],
                        $file['mime'],
                        $file['size']
                    ]);
                }
            }
            
            $pdo->commit();
            
            logEvent("Zaktualizowano dokument HR ID $docId", 'INFO');
            
            $success = true;
            header("Refresh: 2; url=dokumenty-pracownika.php?worker_id=$workerId");
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Błąd zapisu.';
            logEvent("Błąd edycji dokumentu: " . $e->getMessage(), 'ERROR');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?php echo e(APP_NAME); ?> - Edytuj Dokument</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #333; }
        .container { max-width: 900px; margin: 0 auto; padding: 30px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 40px; margin-bottom: 20px; }
        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; }
        .required { color: #dc3545; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 12px 16px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 14px; font-family: inherit;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .btn { padding: 12px 32px; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .file-item { display: flex; justify-content: space-between; align-items: center; padding: 10px; background: #f8f9fa; border-radius: 6px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Edytuj Dokument</h2>
        <p style="color: #666; margin-bottom: 30px;">
            Pracownik: <strong><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?></strong> | 
            Typ: <strong><?php echo e($doc['type_name']); ?></strong>
        </p>
        
        <?php if (!empty($files)): ?>
            <div class="card">
                <h3 style="margin-bottom: 15px;">Załączone pliki (<?php echo count($files); ?>)</h3>
                <?php foreach ($files as $file): ?>
                    <div class="file-item">
                        <div>
                            <?php echo e($file['original_name']); ?>
                            <span style="color: #999; font-size: 13px;">
                                (<?php echo round($file['file_size']/1024, 1); ?> KB)
                            </span>
                        </div>
                        <a href="usun-plik-dokumentu.php?id=<?php echo $file['id']; ?>&doc_id=<?php echo $docId; ?>" 
                           class="btn btn-danger"
                           onclick="return confirm('Usunąć ten plik?');">
                            Usuń
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <?php if ($success): ?>
                <div style="padding: 15px; background: #d4edda; color: #155724; border-radius: 8px; margin-bottom: 20px;">
                    Dokument zaktualizowany! Przekierowanie...
                </div>
            <?php endif; ?>
            
            <?php if (!$success): ?>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Tytuł <span class="required">*</span></label>
                        <input type="text" name="title" value="<?php echo e($_POST['title'] ?? $doc['title']); ?>" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Numer dokumentu</label>
                            <input type="text" name="document_number" value="<?php echo e($_POST['document_number'] ?? $doc['document_number']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Wydawca</label>
                            <input type="text" name="issuer" value="<?php echo e($_POST['issuer'] ?? $doc['issuer']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Ważny od</label>
                            <input type="date" name="valid_from" value="<?php echo e($_POST['valid_from'] ?? $doc['valid_from']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Ważny do</label>
                            <input type="date" name="valid_to" value="<?php echo e($_POST['valid_to'] ?? $doc['valid_to']); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Przypomnienie (dni)</label>
                        <input type="number" name="reminder_days" value="<?php echo e($_POST['reminder_days'] ?? $doc['reminder_days'] ?? 30); ?>" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label>Notatki</label>
                        <textarea name="notes" rows="4"><?php echo e($_POST['notes'] ?? $doc['notes']); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Dodaj nowe pliki</label>
                        <input type="file" name="files[]" multiple accept=".jpg,.jpeg,.png,.pdf">
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary">Zapisz Zmiany</button>
                        <a href="dokumenty-pracownika.php?worker_id=<?php echo $workerId; ?>" class="btn btn-secondary">Anuluj</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

