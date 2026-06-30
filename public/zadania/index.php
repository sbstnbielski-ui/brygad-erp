<?php
/**
 * BRYGAD ERP v3.0 - Zadania: Moje zadania
 * Nowoczesny widok w stylu komunikatora wewnętrznego
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$isAdmin = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

// Pobierz zadania z przypisaniami
try {
    if ($isAdmin) {
        // Admin widzi wszystkie z informacją o przypisanych pracownikach
        $stmt = $pdo->query("
            SELECT t.*, u.login as creator_name,
                   COUNT(DISTINCT ta.worker_id) as assigned_count,
                   SUM(CASE WHEN ta.status = 'done' THEN 1 ELSE 0 END) as done_count
            FROM tasks t
            LEFT JOIN users u ON t.created_by = u.id
            LEFT JOIN task_assignments ta ON t.id = ta.task_id
            WHERE t.is_active = 1
            GROUP BY t.id
            ORDER BY t.created_at DESC
            LIMIT 50
        ");
    } else {
        // Pracownik widzi tylko swoje z możliwością odpowiedzi
        $stmt = $pdo->prepare("
            SELECT t.*, u.login as creator_name, ta.status as my_status
            FROM tasks t
            LEFT JOIN users u ON t.created_by = u.id
            JOIN task_assignments ta ON t.id = ta.task_id
            WHERE t.is_active = 1 AND ta.worker_id = ?
            ORDER BY t.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$currentWorkerId]);
    }
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) {
    $tasks = [];
    error_log("Tasks error: " . $e->getMessage());
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo $isAdmin ? 'Wszystkie zadania' : 'Moje zadania'; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }
        
        /* Page header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header h2 {
            font-size: 32px;
            color: #333;
        }
        
        .actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            padding: 10px 20px;
            height: 42px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        /* Statystyki */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-label {
            font-size: 13px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #667eea;
        }
        
        /* Lista zadań - styl komunikatora */
        .tasks-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .task-item {
            padding: 20px 25px;
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.2s;
            cursor: pointer;
            text-decoration: none;
            display: block;
            color: inherit;
        }
        
        .task-item:hover {
            background: #f9fafb;
        }
        
        .task-item:last-child {
            border-bottom: none;
        }
        
        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
            gap: 15px;
        }
        
        .task-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 5px;
        }
        
        .task-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            color: #6b7280;
            flex-wrap: wrap;
        }
        
        .task-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .priority-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .priority-high {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .priority-medium {
            background: #fef3c7;
            color: #92400e;
        }
        
        .priority-low {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-todo {
            background: #f3f4f6;
            color: #4b5563;
        }
        
        .status-doing {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-done {
            background: #d1fae5;
            color: #065f46;
        }
        
        .task-description {
            color: #6b7280;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 10px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .task-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }
        
        .task-assignees {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        
        .assignee-count {
            font-size: 12px;
            color: #6b7280;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #9ca3af;
            font-size: 13px;
            margin-top: 30px;
        }
        
        /* MOBILE OPTIMIZATION - Wielkie elementy dla budowlańców */
        @media (max-width: 768px) {
            body {
                font-size: 16px;
            }
            
            .container {
                padding: 15px;
            }
            
            .page-header {
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .page-header h2 {
                font-size: 26px;
            }
            
            /* Większe przyciski */
            .btn {
                padding: 14px 24px;
                height: 52px;
                font-size: 16px;
                min-width: 140px;
            }
            
            /* Statystyki - jedna kolumna */
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
                margin-bottom: 20px;
            }
            
            .stat-card {
                padding: 20px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .stat-label {
                font-size: 15px;
                margin-bottom: 0;
            }
            
            .stat-value {
                font-size: 32px;
            }
            
            /* Lista zadań - większe elementy dotykowe */
            .task-item {
                padding: 20px;
                min-height: 90px;
            }
            
            .task-title {
                font-size: 18px;
                line-height: 1.4;
                margin-bottom: 10px;
            }
            
            .task-meta {
                gap: 10px;
                font-size: 14px;
            }
            
            .task-description {
                font-size: 15px;
                margin-top: 8px;
            }
            
            /* Większe badges */
            .priority-badge,
            .status-badge {
                padding: 6px 12px;
                font-size: 13px;
                font-weight: 600;
            }
            
            .task-footer {
                margin-top: 12px;
            }
            
            .assignee-count {
                font-size: 14px;
            }
            
            /* Empty state */
            .empty-state {
                padding: 50px 20px;
            }
            
            .empty-state-icon {
                font-size: 64px;
            }
            
            .empty-state p {
                font-size: 16px;
            }
        }
        
        /* EXTRA SMALL - telefony do 375px */
        @media (max-width: 375px) {
            .page-header h2 {
                font-size: 22px;
            }
            
            .btn {
                padding: 12px 20px;
                height: 48px;
                font-size: 15px;
                min-width: 120px;
            }
            
            .task-title {
                font-size: 17px;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h2><?php echo $isAdmin ? 'Wszystkie zadania' : 'Moje zadania'; ?></h2>
            <div class="actions">
                <?php if ($isAdmin): ?>
                    <a href="<?php echo url('zadania.admin'); ?>" class="btn btn-secondary">Widok admina</a>
                    <a href="<?php echo url('zadania.create'); ?>" class="btn btn-primary">+ Dodaj zadanie</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!$isAdmin): ?>
        <!-- Statystyki dla pracownika -->
        <?php
        $myStats = ['todo' => 0, 'doing' => 0, 'done' => 0];
        foreach ($tasks as $task) {
            if (isset($task['my_status'])) {
                $status = $task['my_status'];
                if (isset($myStats[$status])) {
                    $myStats[$status]++;
                }
            }
        }
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Do zrobienia</div>
                <div class="stat-value"><?php echo $myStats['todo']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">W trakcie</div>
                <div class="stat-value"><?php echo $myStats['doing']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Ukończone</div>
                <div class="stat-value" style="color: #10b981;"><?php echo $myStats['done']; ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="tasks-container">
            <?php if (empty($tasks)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <p style="font-size: 16px; margin-bottom: 5px;">Brak zadań do wyświetlenia</p>
                    <p style="font-size: 14px;">
                        <?php if ($isAdmin): ?>
                            Utwórz nowe zadanie, aby rozpocząć
                        <?php else: ?>
                            Nie masz obecnie przypisanych żadnych zadań
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($tasks as $task): ?>
                    <?php
                    $priorityLabels = [
                        'high' => 'Wysoki',
                        'medium' => 'Średni',
                        'low' => 'Niski'
                    ];
                    $priority = $task['priority'] ?? 'medium';
                    $priorityLabel = $priorityLabels[$priority] ?? 'Średni';
                    
                    $statusLabels = [
                        'todo' => 'Do zrobienia',
                        'doing' => 'W trakcie',
                        'done' => 'Zrobione'
                    ];
                    
                    if ($isAdmin) {
                        $displayStatus = 'todo'; // Domyślny dla admina
                        $statusLabel = $task['assigned_count'] . ' przypisanych';
                        if ($task['done_count'] > 0) {
                            $statusLabel .= ' (' . $task['done_count'] . ' ukończono)';
                        }
                    } else {
                        $displayStatus = $task['my_status'] ?? 'todo';
                        $statusLabel = $statusLabels[$displayStatus] ?? 'Do zrobienia';
                    }
                    ?>
                    <a href="/zadania/show_mobile.php?id=<?php echo $task['id']; ?>" class="task-item" style="cursor: pointer;">
                        <div class="task-header">
                            <div style="flex: 1;">
                                <div class="task-title"><?php echo e($task['title'] ?? 'Bez tytułu'); ?></div>
                                <div class="task-meta">
                                    <span class="task-meta-item">
                                        <span class="priority-badge priority-<?php echo $priority; ?>">
                                            <?php echo $priorityLabel; ?>
                                        </span>
                                    </span>
                                    <?php if (!$isAdmin): ?>
                                    <span class="task-meta-item">
                                        <span class="status-badge status-<?php echo $displayStatus; ?>">
                                            <?php echo $statusLabel; ?>
                                        </span>
                                    </span>
                                    <?php endif; ?>
                                    <span class="task-meta-item">
                                        Utworzono: <?php echo date('d.m.Y', strtotime($task['created_at'])); ?>
                                    </span>
                                    <?php if ($task['due_date']): ?>
                                    <span class="task-meta-item">
                                        Termin: <?php echo formatDate($task['due_date']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($task['description'])): ?>
                        <div class="task-description">
                            <?php echo e($task['description']); ?>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($isAdmin): ?>
                        <div class="task-footer">
                            <div class="assignee-count">
                                <?php echo $statusLabel; ?>
                            </div>
                            <div style="font-size: 12px; color: #9ca3af;">
                                Utworzono przez: <?php echo e($task['creator_name'] ?? 'System'); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
