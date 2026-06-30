<?php
/**
 * SPRUTEX - Zadania (Admin)
 */
require_once dirname(__DIR__) . '/config/autoload.php'; // 1 poziom w dół
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Pobierz zadania aktywne + licznik pozostałych + liczba załączników
$stmt = $pdo->query("
    SELECT 
        t.*,
        COUNT(DISTINCT ta.id) as total_assignments,
        COUNT(DISTINCT CASE WHEN ta.status != 'done' THEN ta.id END) as pending_count,
        (SELECT COUNT(*) FROM task_attachments WHERE task_id = t.id) as attachment_count
    FROM tasks t
    LEFT JOIN task_assignments ta ON t.id = ta.task_id
    WHERE t.is_active = 1
    GROUP BY t.id
    ORDER BY t.due_date ASC, t.created_at DESC
");
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?php echo e(APP_NAME); ?> - Zadania</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #333; }
        .container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .page-header h2 { font-size: 32px; }
        .btn { padding: 12px 24px; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-secondary { background: #6c757d; color: white; margin-right: 10px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 15px 20px; text-align: left; font-weight: 600; color: #555; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; font-size: 14px; }
        td { padding: 15px 20px; border-bottom: 1px solid #f0f0f0; }
        tr:hover { background: #f8f9fa; }
        .priority-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .priority-high { background: #f8d7da; color: #721c24; }
        .priority-medium { background: #fff3cd; color: #856404; }
        .priority-low { background: #d1ecf1; color: #0c5460; }
        .counter { display: inline-block; padding: 4px 10px; background: #667eea; color: white; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .no-data { padding: 60px 20px; text-align: center; color: #999; font-size: 16px; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h2>Zadania Operacyjne</h2>
            <div>
                <a href="<?php echo url('dashboard'); ?>" class="btn btn-secondary">← Dashboard</a>
                <a href="<?php echo url('zadania.create'); ?>" class="btn btn-primary">+ Dodaj Zadanie</a>
            </div>
        </div>
        
        <div class="card">
            <?php if (empty($tasks)): ?>
                <div class="no-data">
                    Brak aktywnych zadań.<br><br>
                    <a href="<?php echo url('zadania.create'); ?>" class="btn btn-primary">Dodaj pierwsze zadanie</a>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Zadanie</th>
                            <th>Priorytet</th>
                            <th>Termin</th>
                            <th>Pozostało</th>
                            <th>📎</th>
                            <th style="text-align: right;">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($task['title']); ?></strong>
                                    <?php if ($task['description']): ?>
                                        <br><span style="font-size: 13px; color: #666;"><?php echo e($task['description']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $priorityLabels = [
                                        'high' => ['class' => 'priority-high', 'text' => 'Wysoki'],
                                        'medium' => ['class' => 'priority-medium', 'text' => 'Średni'],
                                        'low' => ['class' => 'priority-low', 'text' => 'Niski']
                                    ];
                                    $priority = $priorityLabels[$task['priority']] ?? $priorityLabels['medium'];
                                    ?>
                                    <span class="priority-badge <?php echo $priority['class']; ?>">
                                        <?php echo $priority['text']; ?>
                                    </span>
                                </td>
                                <td><?php echo $task['due_date'] ? formatDate($task['due_date']) : '-'; ?></td>
                                <td>
                                    <?php if ($task['pending_count'] > 0): ?>
                                        <span class="counter"><?php echo $task['pending_count']; ?> os.</span>
                                    <?php else: ?>
                                        <span style="color: #28a745; font-weight: 600;">✓ Wszystko zrobione</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($task['attachment_count'] > 0): ?>
                                        <span style="color: #667eea; font-weight: 600;"><?php echo $task['attachment_count']; ?></span>
                                    <?php else: ?>
                                        <span style="color: #ccc;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="/zadania/show_mobile.php?id=<?php echo $task['id']; ?>" 
                                       class="btn" 
                                       style="background: #17a2b8; color: white; font-size: 13px; padding: 6px 12px; margin-right: 5px;">
                                        Podgląd
                                    </a>
                                    <a href="<?php echo url('zadania.edit', ['id' => $task['id']]); ?>" 
                                       class="btn" 
                                       style="background: #667eea; color: white; font-size: 13px; padding: 6px 12px; margin-right: 5px;">
                                        Edytuj
                                    </a>
                                    <a href="<?php echo url('zadania.archive', ['id' => $task['id']]); ?>" 
                                       class="btn" 
                                       style="background: #6c757d; color: white; font-size: 13px; padding: 6px 12px;"
                                       onclick="return confirm('Zarchiwizować to zadanie?');">
                                        Archiwizuj
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

