<?php
/**
 * WERSJA MOBILE - zoptymalizowana pod Android
 */
require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$isAdmin = isAdmin();
$taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$currentWorkerId = $_SESSION['worker_id'] ?? null;

if (!$taskId) {
    die('Błąd: Brak ID zadania');
}

// Pobierz zadanie
$stmt = $pdo->prepare("SELECT t.*, u.login as creator_name FROM tasks t LEFT JOIN users u ON t.created_by = u.id WHERE t.id = ?");
$stmt->execute([$taskId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    die('Zadanie nie istnieje');
}

// Sprawdź czy użytkownik ma dostęp
if (!$isAdmin && $currentWorkerId) {
    $stmt = $pdo->prepare("SELECT 1 FROM task_assignments WHERE task_id = ? AND worker_id = ?");
    $stmt->execute([$taskId, $currentWorkerId]);
    if (!$stmt->fetch()) {
        die('Nie masz dostępu do tego zadania. <a href="/zadania/" style="color:#667eea">Wróć</a>');
    }
}

// Pobierz przypisanych
$stmt = $pdo->prepare("SELECT w.first_name, w.last_name, ta.status FROM task_assignments ta JOIN workers w ON ta.worker_id = w.id WHERE ta.task_id = ?");
$stmt->execute([$taskId]);
$workers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz status pracownika
$myStatus = 'todo';
if (!$isAdmin && $currentWorkerId) {
    $stmt = $pdo->prepare("SELECT status FROM task_assignments WHERE task_id = ? AND worker_id = ?");
    $stmt->execute([$taskId, $currentWorkerId]);
    $result = $stmt->fetch();
    if ($result) $myStatus = $result['status'];
}

// Pobierz załączniki
$stmt = $pdo->prepare("SELECT * FROM task_attachments WHERE task_id = ? ORDER BY created_at DESC");
$stmt->execute([$taskId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz komentarze
try {
    $stmt = $pdo->prepare("SELECT tc.*, w.first_name, w.last_name FROM task_comments tc LEFT JOIN workers w ON tc.worker_id = w.id WHERE tc.task_id = ? ORDER BY tc.created_at ASC");
    $stmt->execute([$taskId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $comments = [];
}

// Obsługa zmiany statusu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && !$isAdmin && $currentWorkerId) {
    $newStatus = $_POST['status'] ?? '';
    if (in_array($newStatus, ['todo', 'doing', 'done'])) {
        try {
            $stmt = $pdo->prepare("UPDATE task_assignments SET status = ? WHERE task_id = ? AND worker_id = ?");
            $stmt->execute([$newStatus, $taskId, $currentWorkerId]);
            
            // Dodaj automatyczny komentarz
            $statusLabels = ['todo' => 'do zrobienia', 'doing' => 'w trakcie realizacji', 'done' => 'ukończone'];
            $comment = "Status zmieniony na: " . $statusLabels[$newStatus];
            $stmt = $pdo->prepare("INSERT INTO task_comments (task_id, worker_id, user_id, comment) VALUES (?, ?, ?, ?)");
            $stmt->execute([$taskId, $currentWorkerId, $_SESSION['user_id'] ?? null, $comment]);
            
            header('Location: /zadania/show_mobile.php?id=' . $taskId);
            exit;
        } catch (PDOException $e) {
            error_log("Status update error: " . $e->getMessage());
        }
    }
}

// Obsługa dodawania komentarza
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $comment = trim($_POST['comment'] ?? '');
    if (!empty($comment)) {
        try {
            // Upewnij się że tabela istnieje
            $pdo->exec("CREATE TABLE IF NOT EXISTS task_comments (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                task_id BIGINT UNSIGNED NOT NULL,
                worker_id BIGINT UNSIGNED NULL,
                user_id BIGINT UNSIGNED NULL,
                comment TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            $stmt = $pdo->prepare("INSERT INTO task_comments (task_id, worker_id, user_id, comment) VALUES (?, ?, ?, ?)");
            $stmt->execute([$taskId, $currentWorkerId, $_SESSION['user_id'] ?? null, $comment]);
            header('Location: /zadania/show_mobile.php?id=' . $taskId);
            exit;
        } catch (PDOException $e) {
            error_log("Comment error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?php echo htmlspecialchars($task['title']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f7fa;padding:15px;font-size:16px}
        .back{display:inline-block;padding:15px 20px;background:#667eea;color:#fff;text-decoration:none;border-radius:8px;margin-bottom:20px;font-size:16px;min-height:50px;line-height:20px}
        .card{background:#fff;border-radius:12px;padding:20px;margin-bottom:15px;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
        h1{font-size:22px;margin-bottom:15px;line-height:1.3}
        .info{margin:15px 0;padding:15px;background:#f9f9f9;border-radius:8px}
        .info-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #eee}
        .info-row:last-child{border:none}
        .badge{display:inline-block;padding:6px 12px;border-radius:20px;font-size:13px;font-weight:600}
        .priority-high{background:#fee;color:#c00}
        .priority-medium{background:#ffc;color:#840}
        .priority-low{background:#def;color:#05a}
        .status-todo{background:#f3f4f6;color:#4b5563}
        .status-doing{background:#dbeafe;color:#1e40af}
        .status-done{background:#d1fae5;color:#065f46}
        .desc{margin:15px 0;padding:15px;background:#f9fafb;border-radius:8px;line-height:1.6;border-left:3px solid #667eea}
        .worker{padding:15px;background:#f9f9f9;border-radius:8px;margin:10px 0}
        .worker-name{font-weight:600;font-size:16px}
        .comment{display:flex;gap:10px;margin:15px 0;padding:15px;background:#f9f9f9;border-radius:8px}
        .comment-avatar{width:40px;height:40px;background:#667eea;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:600;flex-shrink:0}
        .comment-content{flex:1}
        .comment-author{font-weight:600;margin-bottom:5px}
        .comment-text{color:#666;line-height:1.5}
        .comment-time{font-size:12px;color:#999;margin-top:5px}
        textarea{width:100%;padding:15px;border:2px solid #e5e7eb;border-radius:8px;font-size:16px;font-family:inherit;min-height:100px}
        .btn{display:block;width:100%;padding:18px;background:#667eea;color:#fff;border:none;border-radius:8px;font-size:18px;font-weight:600;cursor:pointer;margin-top:15px}
        .att-item{padding:15px;background:#f9f9f9;border-radius:8px;margin:10px 0}
        .att-name{font-weight:600;color:#667eea;text-decoration:none;font-size:16px;display:block;margin-bottom:10px}
        .att-btn{display:inline-block;padding:12px 20px;background:#667eea;color:#fff;text-decoration:none;border-radius:6px;font-size:14px}
    </style>
</head>
<body>
    <a href="<?php echo $isAdmin ? '/zadania/admin.php' : '/zadania/'; ?>" class="back">
        <i class="fas fa-arrow-left"></i> Powrót
    </a>
    
    <div class="card">
        <h1><?php echo htmlspecialchars($task['title']); ?></h1>
        
        <div class="info">
            <div class="info-row">
                <span>Priorytet:</span>
                <span class="badge priority-<?php echo $task['priority']; ?>">
                    <?php echo ['high'=>'Wysoki','medium'=>'Średni','low'=>'Niski'][$task['priority']] ?? 'Średni'; ?>
                </span>
            </div>
            <?php if (!$isAdmin): ?>
            <div class="info-row">
                <span>Mój status:</span>
                <span class="badge status-<?php echo $myStatus; ?>">
                    <?php echo ['todo'=>'Do zrobienia','doing'=>'W trakcie','done'=>'Ukończone'][$myStatus] ?? 'Do zrobienia'; ?>
                </span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span>Zadanie:</span>
                <span><?php echo $task['is_active'] ? 'Aktywne' : 'Nieaktywne'; ?></span>
            </div>
            <?php if ($task['due_date']): ?>
            <div class="info-row">
                <span>Termin:</span>
                <span><?php echo date('d.m.Y', strtotime($task['due_date'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($task['description']): ?>
        <div class="desc"><?php echo nl2br(htmlspecialchars($task['description'])); ?></div>
        <?php endif; ?>
    </div>
    
    <?php if (!empty($workers)): ?>
    <div class="card">
        <h2 style="font-size:18px;margin-bottom:15px">Przypisani (<?php echo count($workers); ?>)</h2>
        <?php foreach ($workers as $w): ?>
        <div class="worker">
            <div class="worker-name"><?php echo htmlspecialchars($w['first_name'] . ' ' . $w['last_name']); ?></div>
            <span class="badge status-<?php echo $w['status']; ?>">
                <?php echo ['todo'=>'Do zrobienia','doing'=>'W trakcie','done'=>'Ukończone'][$w['status']] ?? 'Do zrobienia'; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($attachments)): ?>
    <div class="card">
        <h2 style="font-size:18px;margin-bottom:15px">Załączniki (<?php echo count($attachments); ?>)</h2>
        <?php foreach ($attachments as $att): ?>
        <div class="att-item">
            <a href="<?php echo '/' . ltrim($att['file_path'], '/'); ?>" target="_blank" class="att-name">
                <i class="fas fa-file"></i> <?php echo htmlspecialchars($att['original_name']); ?>
            </a>
            <a href="<?php echo '/' . ltrim($att['file_path'], '/'); ?>" download class="att-btn">
                <i class="fas fa-download"></i> Pobierz
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($comments)): ?>
    <div class="card">
        <h2 style="font-size:18px;margin-bottom:15px">Komentarze (<?php echo count($comments); ?>)</h2>
        <?php foreach ($comments as $c): ?>
        <div class="comment">
            <div class="comment-avatar">
                <?php echo strtoupper(substr($c['first_name'] ?? 'S', 0, 1) . substr($c['last_name'] ?? 'S', 0, 1)); ?>
            </div>
            <div class="comment-content">
                <div class="comment-author"><?php echo htmlspecialchars(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? 'System')); ?></div>
                <div class="comment-text"><?php echo nl2br(htmlspecialchars($c['comment'])); ?></div>
                <div class="comment-time"><?php echo date('d.m.Y H:i', strtotime($c['created_at'])); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if (!$isAdmin): ?>
    <div class="card">
        <h2 style="font-size:18px;margin-bottom:15px">Zmień status</h2>
        <form method="POST" style="margin-bottom:20px">
            <select name="status" style="width:100%;padding:15px;border:2px solid #e5e7eb;border-radius:8px;font-size:16px;margin-bottom:10px">
                <option value="todo" <?php echo $myStatus === 'todo' ? 'selected' : ''; ?>>Do zrobienia</option>
                <option value="doing" <?php echo $myStatus === 'doing' ? 'selected' : ''; ?>>W trakcie</option>
                <option value="done" <?php echo $myStatus === 'done' ? 'selected' : ''; ?>>Ukończone</option>
            </select>
            <button type="submit" name="update_status" class="btn">
                <i class="fas fa-check"></i> Zmień status
            </button>
        </form>
        
        <h2 style="font-size:18px;margin-bottom:15px">Dodaj komentarz</h2>
        <form method="POST">
            <textarea name="comment" placeholder="Napisz komentarz..." required></textarea>
            <button type="submit" name="add_comment" class="btn">
                <i class="fas fa-paper-plane"></i> Wyślij komentarz
            </button>
        </form>
    </div>
    <?php endif; ?>
</body>
</html>

