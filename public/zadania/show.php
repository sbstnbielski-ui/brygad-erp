<?php
/**
 * BRYGAD ERP v3.0 - Podgląd Zadania
 * Styl komunikatora z możliwością odpowiedzi
 */

// Ścieżka bezwzględna do autoload
$autoloadPath = __DIR__ . '/../config/autoload.php';

if (!file_exists($autoloadPath)) {
    die('BŁĄD: Nie można znaleźć autoload.php. Ścieżka: ' . $autoloadPath . '<br>Katalog: ' . __DIR__);
}

require_once $autoloadPath;

try {
    startSecureSession();
    requireLogin();

    $pdo = getDbConnection();
    $isAdmin = isAdmin();
    $currentWorkerId = $_SESSION['worker_id'] ?? null;

    // ID zadania
    $taskId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (!$taskId) {
        die('Błąd: Brak ID zadania. <a href="' . url('zadania') . '">Wróć do listy</a>');
    }
} catch (Exception $e) {
    die('<h1>Błąd inicjalizacji</h1><p>' . $e->getMessage() . '</p><pre>' . $e->getTraceAsString() . '</pre>');
}

// Obsługa dodawania komentarza
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $comment = trim($_POST['comment'] ?? '');
    
    if (!empty($comment)) {
        try {
            // Utwórz tabelę jeśli nie istnieje
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS task_comments (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    task_id BIGINT UNSIGNED NOT NULL,
                    worker_id BIGINT UNSIGNED NULL,
                    user_id BIGINT UNSIGNED NULL,
                    comment TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                    FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                    INDEX idx_task_id (task_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $stmt = $pdo->prepare("
                INSERT INTO task_comments (task_id, worker_id, user_id, comment)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$taskId, $currentWorkerId, $_SESSION['user_id'] ?? null, $comment]);
            
            header('Location: ' . url('zadania.show', ['id' => $taskId]));
            exit;
        } catch (PDOException $e) {
            error_log("Error adding comment: " . $e->getMessage());
        }
    }
}

// Obsługa zmiany statusu (dla pracownika)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && !$isAdmin && $currentWorkerId) {
    $newStatus = $_POST['status'] ?? '';
    
    if (in_array($newStatus, ['todo', 'doing', 'done'])) {
        try {
            $stmt = $pdo->prepare("
                UPDATE task_assignments 
                SET status = ?
                WHERE task_id = ? AND worker_id = ?
            ");
            $stmt->execute([$newStatus, $taskId, $currentWorkerId]);
            
            // Dodaj automatyczny komentarz
            $statusLabels = [
                'todo' => 'do zrobienia',
                'doing' => 'w trakcie realizacji',
                'done' => 'ukończone'
            ];
            $comment = "Status zmieniony na: " . $statusLabels[$newStatus];
            
            $stmt = $pdo->prepare("
                INSERT INTO task_comments (task_id, worker_id, user_id, comment)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$taskId, $currentWorkerId, $_SESSION['user_id'] ?? null, $comment]);
            
            header('Location: ' . url('zadania.show', ['id' => $taskId]));
    exit;
        } catch (PDOException $e) {
            error_log("Error updating status: " . $e->getMessage());
        }
    }
}

// Pobierz zadanie
try {
$stmt = $pdo->prepare("
    SELECT t.*, u.login as creator_name
    FROM tasks t
    LEFT JOIN users u ON t.created_by = u.id
    WHERE t.id = ?
");
$stmt->execute([$taskId]);
$task = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
        die('Błąd: Zadanie o ID ' . $taskId . ' nie istnieje. <a href="' . url('zadania') . '">Wróć do listy</a>');
}

    // Sprawdź czy użytkownik ma dostęp
    if (!$isAdmin && $currentWorkerId) {
    $stmt = $pdo->prepare("SELECT 1 FROM task_assignments WHERE task_id = ? AND worker_id = ?");
    $stmt->execute([$taskId, $currentWorkerId]);
    if (!$stmt->fetch()) {
            die('Błąd: Nie masz dostępu do tego zadania. <a href="' . url('zadania') . '">Wróć do listy</a>');
        }
    }
} catch (PDOException $e) {
    die('<h1>Błąd bazy danych</h1><p>' . $e->getMessage() . '</p><p><a href="' . url('zadania') . '">Wróć do listy</a></p>');
}

// Pobierz przypisanych pracowników
$stmt = $pdo->prepare("
    SELECT w.id, w.first_name, w.last_name, ta.status, ta.created_at
    FROM task_assignments ta
    JOIN workers w ON ta.worker_id = w.id
    WHERE ta.task_id = ?
    ORDER BY w.last_name, w.first_name
");
$stmt->execute([$taskId]);
$assignedWorkers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pobierz status pracownika (jeśli nie admin)
$myStatus = 'todo';
if (!$isAdmin && $currentWorkerId) {
    $stmt = $pdo->prepare("SELECT status FROM task_assignments WHERE task_id = ? AND worker_id = ?");
    $stmt->execute([$taskId, $currentWorkerId]);
    $result = $stmt->fetch();
    if ($result) {
        $myStatus = $result['status'];
    }
}

// Pobierz komentarze
$comments = [];
try {
    $stmt = $pdo->prepare("
        SELECT tc.*, 
               w.first_name, w.last_name,
               u.login
        FROM task_comments tc
        LEFT JOIN workers w ON tc.worker_id = w.id
        LEFT JOIN users u ON tc.user_id = u.id
        WHERE tc.task_id = ?
        ORDER BY tc.created_at ASC
    ");
    $stmt->execute([$taskId]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tabela może nie istnieć jeszcze
    $comments = [];
}

// Pobierz załączniki
$attachments = [];
try {
$stmt = $pdo->prepare("
    SELECT * FROM task_attachments
    WHERE task_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$taskId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching attachments: " . $e->getMessage());
}

$priorityLabels = [
    'high' => ['text' => 'Wysoki', 'class' => 'priority-high'],
    'medium' => ['text' => 'Średni', 'class' => 'priority-medium'],
    'low' => ['text' => 'Niski', 'class' => 'priority-low']
];

$statusLabels = [
    'todo' => ['text' => 'Do zrobienia', 'class' => 'status-todo'],
    'doing' => ['text' => 'W trakcie', 'class' => 'status-progress'],
    'done' => ['text' => 'Ukończone', 'class' => 'status-done']
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo e($task['title']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f5f7fa; 
            color: #333; 
        }
        .container { 
            max-width: 1000px; 
            margin: 0 auto; 
            padding: 30px; 
        }
        
        .back-link { 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            color: #667eea; 
            text-decoration: none; 
            margin-bottom: 20px; 
            font-weight: 500; 
            font-size: 14px;
        }
        .back-link:hover { 
            text-decoration: underline; 
        }
        
        /* Page header */
        .page-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            margin-bottom: 30px; 
        }
        .page-header h2 { 
            font-size: 28px; 
            margin-bottom: 8px; 
        }
        .page-header p { 
            color: #6b7280; 
            font-size: 14px;
        }
        .btn { 
            padding: 10px 20px; 
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
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary { 
            background: #6c757d; 
            color: white; 
        }
        
        /* Cards */
        .card { 
            background: white; 
            border-radius: 12px; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
            padding: 25px; 
            margin-bottom: 20px; 
        }
        .card h3 { 
            font-size: 18px; 
            color: #333; 
            margin-bottom: 20px; 
            padding-bottom: 15px; 
            border-bottom: 1px solid #f0f0f0; 
        }
        
        /* Info grid */
        .info-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
            gap: 20px; 
        }
        .info-item label { 
            display: block; 
            font-size: 12px; 
            color: #6b7280; 
            text-transform: uppercase; 
            margin-bottom: 5px; 
            font-weight: 600;
        }
        .info-item span { 
            font-size: 16px; 
            font-weight: 500; 
        }
        
        /* Badges */
        .priority-badge, .status-badge { 
            display: inline-block; 
            padding: 4px 12px; 
            border-radius: 12px; 
            font-size: 13px; 
            font-weight: 600; 
        }
        .priority-high { background: #fee2e2; color: #991b1b; }
        .priority-medium { background: #fef3c7; color: #92400e; }
        .priority-low { background: #dbeafe; color: #1e40af; }
        .status-todo { background: #f3f4f6; color: #4b5563; }
        .status-progress { background: #dbeafe; color: #1e40af; }
        .status-doing { background: #dbeafe; color: #1e40af; }
        .status-done { background: #d1fae5; color: #065f46; }
        
        .description { 
            padding: 20px; 
            background: #f9fafb; 
            border-radius: 8px; 
            margin-top: 20px; 
            line-height: 1.6; 
            border-left: 3px solid #667eea;
        }
        
        /* Workers list */
        .workers-list { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 10px; 
        }
        .worker-item { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            padding: 12px 16px; 
            background: #f9fafb; 
            border-radius: 8px; 
        }
        .worker-avatar { 
            width: 36px; 
            height: 36px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: white; 
            font-weight: 600; 
            font-size: 14px; 
        }
        .worker-info { 
            flex: 1; 
        }
        .worker-name { 
            font-weight: 600; 
            font-size: 14px;
        }
        .worker-status { 
            font-size: 12px; 
            margin-top: 2px; 
        }
        
        /* Comments section - styl komunikatora */
        .comments-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .comments-header {
            padding: 20px 25px;
            border-bottom: 1px solid #f3f4f6;
            background: #f9fafb;
        }
        
        .comments-header h3 {
            font-size: 18px;
            color: #333;
            margin: 0;
        }
        
        .comments-list {
            max-height: 500px;
            overflow-y: auto;
            padding: 20px 25px;
        }
        
        .comment-item {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .comment-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
            flex-shrink: 0;
        }
        
        .comment-content {
            flex: 1;
            background: #f9fafb;
            padding: 12px 16px;
            border-radius: 12px;
            border-radius: 12px 12px 12px 4px;
        }
        
        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .comment-author {
            font-weight: 600;
            font-size: 14px;
            color: #111827;
        }
        
        .comment-time {
            font-size: 12px;
            color: #9ca3af;
        }
        
        .comment-text {
            color: #4b5563;
            font-size: 14px;
            line-height: 1.5;
            white-space: pre-wrap;
        }
        
        .comment-form {
            padding: 20px 25px;
            border-top: 1px solid #f3f4f6;
            background: white;
        }
        
        .comment-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 80px;
            transition: border 0.2s;
        }
        
        .comment-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .comment-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
        }
        
        .status-selector {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .status-selector label {
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .status-selector select {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #9ca3af;
        }
        
        .attachments-list {
            display: grid;
            gap: 12px;
        }
        
        .attachment-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
            transition: background 0.2s;
        }
        
        .attachment-item:hover {
            background: #e8f4fd;
        }
        
        .attachment-info {
            flex: 1;
        }
        
        .attachment-name {
            font-weight: 600;
            color: #333;
            text-decoration: none;
            font-size: 14px;
        }
        
        .attachment-name:hover {
            color: #667eea;
        }
        
        .attachment-meta {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .attachment-download {
            padding: 8px 16px;
            background: #667eea;
            color: white;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }
        
        .attachment-download:hover {
            background: #5a6fd6;
        }
        
        /* MOBILE OPTIMIZATION - Duże elementy dla budowlańców */
        @media (max-width: 768px) {
            body {
                font-size: 16px;
            }
            
            .container {
                padding: 15px;
            }
            
            .back-link {
                font-size: 15px;
                padding: 10px 0;
                margin-bottom: 15px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                margin-bottom: 20px;
                gap: 15px;
            }
            
            .page-header h2 {
                font-size: 24px;
                line-height: 1.3;
            }
            
            .page-header p {
                font-size: 14px;
            }
            
            /* Większe przyciski */
            .btn {
                padding: 14px 24px;
                height: 52px;
                font-size: 16px;
                width: 100%;
                justify-content: center;
            }
            
            /* Cards */
            .card {
                padding: 20px;
                margin-bottom: 15px;
            }
            
            .card h3 {
                font-size: 18px;
                margin-bottom: 18px;
                padding-bottom: 12px;
            }
            
            /* Info grid - jedna kolumna */
            .info-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .info-item label {
                font-size: 13px;
            }
            
            .info-item span {
                font-size: 17px;
            }
            
            /* Większe badges */
            .priority-badge,
            .status-badge {
                padding: 6px 14px;
                font-size: 14px;
            }
            
            .description {
                padding: 18px;
                font-size: 15px;
                line-height: 1.7;
            }
            
            /* Workers list - pełna szerokość */
            .workers-list {
                flex-direction: column;
                gap: 12px;
            }
            
            .worker-item {
                padding: 15px;
                width: 100%;
            }
            
            .worker-avatar {
                width: 44px;
                height: 44px;
                font-size: 16px;
            }
            
            .worker-name {
                font-size: 16px;
            }
            
            .worker-status {
                font-size: 13px;
            }
            
            /* Komentarze - większe na mobile */
            .comments-section {
                margin-bottom: 20px;
            }
            
            .comments-header {
                padding: 18px 20px;
            }
            
            .comments-header h3 {
                font-size: 18px;
            }
            
            .comments-list {
                padding: 15px;
                max-height: 400px;
            }
            
            .comment-item {
                margin-bottom: 18px;
            }
            
            .comment-avatar {
                width: 44px;
                height: 44px;
                font-size: 16px;
            }
            
            .comment-content {
                padding: 14px 16px;
            }
            
            .comment-author {
                font-size: 15px;
            }
            
            .comment-text {
                font-size: 15px;
                line-height: 1.6;
            }
            
            .comment-time {
                font-size: 13px;
            }
            
            /* Formularz komentarza - WIĘKSZY */
            .comment-form {
                padding: 20px;
            }
            
            .comment-input {
                padding: 16px;
                font-size: 16px;
                min-height: 100px;
                border-width: 2px;
            }
            
            .comment-actions {
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
            }
            
            .status-selector {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                width: 100%;
            }
            
            .status-selector label {
                font-size: 14px;
            }
            
            .status-selector select {
                padding: 14px 16px;
                font-size: 16px;
                height: 52px;
                width: 100%;
            }
            
            .status-selector .btn {
                margin-top: 5px;
            }
            
            /* Załączniki */
            .attachments-list {
                gap: 10px;
            }
            
            .attachment-item {
                padding: 16px;
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .attachment-name {
                font-size: 15px;
            }
            
            .attachment-meta {
                font-size: 13px;
            }
            
            .attachment-download {
                width: 100%;
                text-align: center;
                padding: 12px 16px;
                font-size: 15px;
            }
        }
        
        /* EXTRA SMALL - telefony do 375px */
        @media (max-width: 375px) {
            .page-header h2 {
                font-size: 20px;
            }
            
            .btn {
                padding: 12px 20px;
                height: 48px;
                font-size: 15px;
            }
            
            .comment-avatar {
                width: 40px;
                height: 40px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <a href="<?php echo $isAdmin ? url('zadania.admin') : url('zadania'); ?>" class="back-link">
            ← Powrót do listy zadań
        </a>
        
        <div class="page-header">
            <div>
                <h2><?php echo e($task['title']); ?></h2>
                <p>Zadanie utworzone <?php echo formatDate($task['created_at']); ?> przez <?php echo e($task['creator_name'] ?? 'System'); ?></p>
            </div>
            <?php if ($isAdmin): ?>
                <a href="<?php echo url('zadania.edit', ['id' => $taskId]); ?>" class="btn btn-primary">Edytuj</a>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h3>Informacje o zadaniu</h3>
            <div class="info-grid">
                <div class="info-item">
                    <label>Priorytet</label>
                    <?php $priority = $priorityLabels[$task['priority']] ?? $priorityLabels['medium']; ?>
                    <span class="priority-badge <?php echo $priority['class']; ?>"><?php echo $priority['text']; ?></span>
                </div>
                <?php if (!$isAdmin): ?>
                <div class="info-item">
                    <label>Mój status</label>
                    <?php $status = $statusLabels[$myStatus] ?? $statusLabels['todo']; ?>
                    <span class="status-badge <?php echo $status['class']; ?>"><?php echo $status['text']; ?></span>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <label>Status zadania</label>
                    <span><?php echo $task['is_active'] ? 'Aktywne' : 'Zarchiwizowane'; ?></span>
                </div>
                <div class="info-item">
                    <label>Termin</label>
                    <span><?php echo $task['due_date'] ? formatDate($task['due_date']) : 'Brak terminu'; ?></span>
                </div>
                <div class="info-item">
                    <label>Utworzono</label>
                    <span><?php echo formatDate($task['created_at']); ?></span>
                </div>
            </div>
            
            <?php if (!empty($task['description'])): ?>
                <div class="description">
                    <?php echo nl2br(e($task['description'])); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h3>Przypisani pracownicy (<?php echo count($assignedWorkers); ?>)</h3>
            <?php if (empty($assignedWorkers)): ?>
                <div class="empty-state">Brak przypisanych pracowników</div>
            <?php else: ?>
                <div class="workers-list">
                    <?php foreach ($assignedWorkers as $worker): ?>
                        <?php 
                        $initials = strtoupper(substr($worker['first_name'], 0, 1) . substr($worker['last_name'], 0, 1));
                        $status = $statusLabels[$worker['status']] ?? $statusLabels['todo'];
                        ?>
                        <div class="worker-item">
                            <div class="worker-avatar"><?php echo $initials; ?></div>
                            <div class="worker-info">
                                <div class="worker-name"><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?></div>
                                <div class="worker-status">
                                    <span class="status-badge <?php echo $status['class']; ?>"><?php echo $status['text']; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($attachments)): ?>
        <div class="card">
            <h3>Załączniki (<?php echo count($attachments); ?>)</h3>
                <div class="attachments-list">
                    <?php foreach ($attachments as $att): ?>
                        <div class="attachment-item">
                            <div class="attachment-info">
                                <a href="<?php echo '/' . ltrim($att['file_path'], '/'); ?>" target="_blank" class="attachment-name">
                                    <?php echo e($att['original_name']); ?>
                                </a>
                                <div class="attachment-meta">
                                    Dodano <?php echo formatDate($att['created_at']); ?>
                                </div>
                            </div>
                            <a href="<?php echo '/' . ltrim($att['file_path'], '/'); ?>" download class="attachment-download">
                                Pobierz
                            </a>
                        </div>
                    <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Sekcja komentarzy - komunikator -->
        <div class="comments-section">
            <div class="comments-header">
                <h3>Komentarze i postępy (<?php echo count($comments); ?>)</h3>
            </div>
            
            <div class="comments-list">
                <?php if (empty($comments)): ?>
                    <div class="empty-state">
                        <p>Brak komentarzy</p>
                        <p style="font-size: 13px; margin-top: 5px;">Dodaj pierwszy komentarz poniżej</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <?php
                        $authorName = 'System';
                        $authorInitials = 'S';
                        if ($comment['first_name'] && $comment['last_name']) {
                            $authorName = $comment['first_name'] . ' ' . $comment['last_name'];
                            $authorInitials = strtoupper(substr($comment['first_name'], 0, 1) . substr($comment['last_name'], 0, 1));
                        } elseif ($comment['login']) {
                            $authorName = $comment['login'];
                            $authorInitials = strtoupper(substr($comment['login'], 0, 2));
                        }
                        ?>
                        <div class="comment-item">
                            <div class="comment-avatar"><?php echo $authorInitials; ?></div>
                            <div class="comment-content">
                                <div class="comment-header">
                                    <span class="comment-author"><?php echo e($authorName); ?></span>
                                    <span class="comment-time"><?php echo date('d.m.Y H:i', strtotime($comment['created_at'])); ?></span>
                                </div>
                                <div class="comment-text"><?php echo nl2br(e($comment['comment'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <form method="POST" class="comment-form">
                <textarea name="comment" class="comment-input" placeholder="Napisz komentarz..." required></textarea>
                <div class="comment-actions">
                    <?php if (!$isAdmin): ?>
                    <div class="status-selector">
                        <label>Zmień status:</label>
                        <select name="status">
                            <option value="todo" <?php echo $myStatus === 'todo' ? 'selected' : ''; ?>>Do zrobienia</option>
                            <option value="doing" <?php echo $myStatus === 'doing' ? 'selected' : ''; ?>>W trakcie</option>
                            <option value="done" <?php echo $myStatus === 'done' ? 'selected' : ''; ?>>Ukończone</option>
                        </select>
                        <button type="submit" name="update_status" class="btn btn-secondary">Zmień status</button>
                </div>
            <?php endif; ?>
                    <button type="submit" name="add_comment" class="btn btn-primary">Dodaj komentarz</button>
                </div>
            </form>
        </div>
    </div>
    
    <footer style="text-align: center; padding: 20px; color: #9ca3af; font-size: 13px; margin-top: 30px;">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
