<?php
/**
 * SPRUTEX - Dodawanie Dokumentu HR
 */
require_once __DIR__ . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$workerId = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;

if ($workerId <= 0) {
    header('Location: ' . url('hr.workers'));
    exit;
}

// Pobierz pracownika
$stmt = $pdo->prepare("SELECT * FROM workers WHERE id = ?");
$stmt->execute([$workerId]);
$worker = $stmt->fetch();

if (!$worker) {
    header('Location: ' . url('hr.workers'));
    exit;
}

// Pobierz typy dokumentów
$stmt = $pdo->query("SELECT * FROM document_types WHERE is_active = 1 ORDER BY sort_order");
$docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $documentTypeId = !empty($_POST['document_type_id']) ? (int)$_POST['document_type_id'] : 0;
    $title = trim($_POST['title'] ?? '');
    $documentNumber = trim($_POST['document_number'] ?? '');
    $issuer = trim($_POST['issuer'] ?? '');
    $validFrom = trim($_POST['valid_from'] ?? '');
    $validTo = trim($_POST['valid_to'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $reminderDays = !empty($_POST['reminder_days']) ? (int)$_POST['reminder_days'] : null;
    
    if ($documentTypeId <= 0) {
        $errors[] = 'Wybierz typ dokumentu.';
    }
    if (empty($title)) {
        $errors[] = 'Tytuł jest wymagany.';
    }
    
    // Upload plików
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
            
            if ($fileError !== UPLOAD_ERR_OK) {
                $errors[] = "Błąd uploadu pliku: $fileName";
                continue;
            }
            if (!in_array($fileType, $allowedTypes)) {
                $errors[] = "Nieprawidłowy typ pliku: $fileName (dozwolone: JPG, PNG, PDF)";
                continue;
            }
            if ($fileSize > $maxFileSize) {
                $errors[] = "Plik za duży: $fileName (max 10MB)";
                continue;
            }
            
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $newFileName = 'doc_' . $workerId . '_' . time() . '_' . uniqid() . '.' . $ext;
            
            // Ścieżka względem public/
            $uploadDir = __DIR__ . '/uploads/documents/';
            
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
            } else {
                $errors[] = "Nie udało się zapisać pliku: $fileName";
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Wstaw dokument
            $stmt = $pdo->prepare("
                INSERT INTO worker_documents 
                (worker_id, document_type_id, title, document_number, issuer, valid_from, valid_to, notes, reminder_days, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
            ");
            $stmt->execute([
                $workerId,
                $documentTypeId,
                $title,
                $documentNumber ?: null,
                $issuer ?: null,
                $validFrom ?: null,
                $validTo ?: null,
                $notes ?: null,
                $reminderDays
            ]);
            
            $documentId = $pdo->lastInsertId();
            
            // Zapisz pliki
            if (!empty($uploadedFiles)) {
                $stmt = $pdo->prepare("
                    INSERT INTO worker_document_files 
                    (document_id, file_path, original_name, mime_type, file_size, uploaded_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                foreach ($uploadedFiles as $file) {
                    $stmt->execute([
                        $documentId,
                        $file['path'],
                        $file['original'],
                        $file['mime'],
                        $file['size']
                    ]);
                }
            }
            
            $pdo->commit();
            
            logEvent("Dodano dokument HR dla pracownika ID $workerId: $title", 'INFO');
            
            $success = true;
            header("Location: " . url('hr.workers.documents', ['worker_id' => $workerId]));
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Błąd zapisu do bazy danych.';
            logEvent("Błąd dodawania dokumentu HR: " . $e->getMessage(), 'ERROR');
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
    <title><?php echo e(APP_NAME); ?> - Dodaj Dokument HR</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .header {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .logo-section { display: flex; align-items: center; gap: 20px; }
        .logo-section img { height: 50px; border-radius: 6px; }
        .logo-text h1 { font-size: 24px; color: #333; }
        .logo-text p { font-size: 13px; color: #666; }
        .nav-section { display: flex; align-items: center; gap: 20px; }
        .nav-section a {
            padding: 10px 20px;
            color: #333;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .nav-section a:hover { background: #f0f0f0; }
        .nav-section a.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .user-section { display: flex; align-items: center; gap: 20px; }
        .user-name { font-weight: 600; color: #333; }
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }
        .btn-logout {
            padding: 10px 20px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 30px;
        }
        .breadcrumb {
            margin-bottom: 20px;
            color: #666;
            font-size: 14px;
        }
        .breadcrumb a {
            color: #667eea;
            text-decoration: none;
        }
        .page-header {
            margin-bottom: 30px;
        }
        .page-header h2 { font-size: 32px; color: #333; margin-bottom: 10px; }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 40px;
            margin-bottom: 20px;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        .form-group {
            margin-bottom: 25px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .required { color: #dc3545; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .help-text {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }
        .btn {
            padding: 12px 32px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .worker-info {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            <a href="<?php echo url('hr.workers'); ?>">Pracownicy</a> / 
            <a href="<?php echo url('hr.workers.profile', ['id' => $workerId]); ?>">
                <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
            </a> / 
            <a href="<?php echo url('hr.workers.documents', ['worker_id' => $workerId]); ?>">Dokumenty HR</a> / 
            Dodaj Dokument
        </div>
        
        <div class="page-header">
            <h2>Dodaj Dokument HR</h2>
        </div>
        
        <div class="worker-info">
            <strong>Pracownik:</strong> <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
            | <strong>Typ:</strong> <?php 
                $types = ['permanent' => 'Stały', 'temporary' => 'Tymczasowy', 'contractor' => 'Podwykonawca'];
                echo $types[$worker['worker_type']] ?? 'Stały';
            ?>
        </div>
        
        <div class="card">
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <strong>Sukces!</strong> Dokument został dodany! Przekierowanie...
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Błędy:</strong>
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
                        <label>Typ dokumentu <span class="required">*</span></label>
                        <select name="document_type_id" required id="docType">
                            <option value="">-- Wybierz typ --</option>
                            <?php foreach ($docTypes as $type): ?>
                                <option value="<?php echo $type['id']; ?>" 
                                        data-has-validity="<?php echo $type['has_validity']; ?>"
                                        data-reminder-days="<?php echo $type['reminder_days']; ?>"
                                        <?php echo (isset($_POST['document_type_id']) && $_POST['document_type_id'] == $type['id']) ? 'selected' : ''; ?>>
                                    <?php echo e($type['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Tytuł dokumentu <span class="required">*</span></label>
                        <input type="text" name="title" value="<?php echo e($_POST['title'] ?? ''); ?>" required 
                               placeholder="np. Umowa o pracę 2026">
                        <div class="help-text">Krótka nazwa identyfikująca dokument</div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Numer dokumentu</label>
                            <input type="text" name="document_number" value="<?php echo e($_POST['document_number'] ?? ''); ?>" 
                                   placeholder="np. UM/2026/01">
                        </div>
                        <div class="form-group">
                            <label>Wydawca</label>
                            <input type="text" name="issuer" value="<?php echo e($_POST['issuer'] ?? ''); ?>" 
                                   placeholder="np. Sprutex Sp. z o.o.">
                        </div>
                    </div>
                    
                    <div class="form-row" id="validityFields">
                        <div class="form-group">
                            <label>Ważny od</label>
                            <input type="date" name="valid_from" value="<?php echo e($_POST['valid_from'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Ważny do</label>
                            <input type="date" name="valid_to" value="<?php echo e($_POST['valid_to'] ?? ''); ?>">
                            <div class="help-text">Data wygaśnięcia dokumentu</div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Przypomnienie (dni przed wygaśnięciem)</label>
                        <input type="number" name="reminder_days" id="reminderDays" value="<?php echo e($_POST['reminder_days'] ?? 30); ?>" min="0" max="365">
                        <div class="help-text">System wygeneruje alert X dni przed wygaśnięciem</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notatki</label>
                        <textarea name="notes" rows="4" placeholder="Dodatkowe informacje..."><?php echo e($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Pliki (JPG, PNG, PDF)</label>
                        <input type="file" name="files[]" multiple accept=".jpg,.jpeg,.png,.pdf">
                        <div class="help-text">Możesz dodać wiele plików (max 10MB każdy)</div>
                    </div>
                    
                    <div style="display: flex; gap: 15px; margin-top: 30px;">
                        <button type="submit" class="btn btn-primary">Dodaj Dokument</button>
                        <a href="<?php echo url('hr.workers.documents', ['worker_id' => $workerId]); ?>" class="btn btn-secondary">Anuluj</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-uzupełnianie reminder_days przy wyborze typu dokumentu
        document.getElementById('docType')?.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const reminderDays = selected.dataset.reminderDays;
            if (reminderDays) {
                document.getElementById('reminderDays').value = reminderDays;
            }
        });
    </script>
</body>
</html>
