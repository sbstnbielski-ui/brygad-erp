<?php
/**
 * BRYGAD ERP - Alerty HR
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Pobierz aktywne alerty
try {
    $stmt = $pdo->query("
        SELECT * FROM hr_alerts 
        WHERE is_active = 1 
        ORDER BY created_at DESC
    ");
    $alerts = $stmt->fetchAll();
} catch (PDOException $e) {
    $alerts = [];
    error_log("Alerts error: " . $e->getMessage());
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Alerty HR</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px; }
        /* Override header */
        .header { box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important; }
        .header-content { max-width: 1600px !important; padding: 15px 30px !important; justify-content: space-between !important; align-items: center !important; flex-wrap: nowrap !important; }
        .logo-section, .logo-link { gap: 15px !important; align-items: center !important; }
        .logo-section img { height: 40px !important; }
        .logo-text h1 { font-size: 20px !important; letter-spacing: -0.5px !important; margin: 0 !important; color: #1f2937 !important; }
        .logo-text p { font-size: 12px !important; margin: 0 !important; color: #6b7280 !important; }
        .user-section { display: flex !important; align-items: center !important; gap: 20px !important; flex-wrap: nowrap !important; }
        .user-name { font-weight: 600 !important; font-size: 14px !important; color: #333 !important; }
        .alerts-dropdown { display: none !important; }
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;
        }
        .hero h1 { margin: 0 0 6px; font-size: 28px; letter-spacing: -0.4px; }
        .hero .breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero .breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 2px rgba(15,23,42,0.05); border: 1px solid var(--border); overflow: hidden; margin-bottom: 16px; }
        .alert-item {
            background: white;
            padding: 16px 20px;
            margin-bottom: 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            border-left: 4px solid #ef4444;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .alert-item.ok { border-left-color: #22c55e; }
        .alert-item strong { font-size: 14px; color: var(--text-main); }
        .alert-item p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .empty-state { padding: 60px 20px; text-align: center; color: var(--text-muted); }
        .empty-state p { font-size: 15px; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="breadcrumb"><a href="<?php echo url('hr'); ?>">Pracownicy</a> › Alerty HR</div>
                <h1>Alerty HR</h1>
                <p>Aktywne powiadomienia dotyczące pracowników</p>
            </div>
        </div>

        <?php if (empty($alerts)): ?>
            <div class="empty-state">
                <p>Brak aktywnych alertów. Wszystko w porządku!</p>
            </div>
        <?php else: ?>
            <?php foreach ($alerts as $alert): ?>
                <div class="alert-item">
                    <strong><?php echo e($alert['title'] ?? 'Alert'); ?></strong>
                    <?php if (!empty($alert['message'])): ?>
                        <p><?php echo e($alert['message']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>

