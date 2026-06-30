<?php
/**
 * SPRUTEX - Moduł HR: Dokumenty Pracownika
 */
require_once dirname(__DIR__, 2) . '/config/autoload.php'; // 2 poziomy w dół
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

// ZABEZPIECZENIE: Admin może wybrać pracownika, worker widzi tylko siebie
if ($isAdminUser) {
    $workerId = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
} else {
    // Worker może przeglądać tylko swoje dokumenty
    $workerId = $currentWorkerId;
    
    if (!$workerId) {
        die('Brak przypisanego pracownika do konta.');
    }
    
    // Jeśli próbuje podejrzeć cudze dokumenty
    if (isset($_GET['worker_id']) && (int)$_GET['worker_id'] != $currentWorkerId) {
        logEvent("SECURITY: Worker ID $currentWorkerId próbował podejrzeć dokumenty worker ID " . $_GET['worker_id'], 'WARNING');
        header('Location: ' . url('hr.workers.documents', ['worker_id' => $currentWorkerId]));
        exit;
    }
}

// Pobierz listę pracowników dla admina (do wyboru)
$workersList = [];
if ($isAdminUser) {
    try {
        $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
        $workersList = $stmt->fetchAll();
    } catch (PDOException $e) {
        logEvent("Błąd pobierania listy pracowników: " . $e->getMessage(), 'ERROR');
    }
}

// Pobierz pracownika (jeśli wybrano)
$worker = null;
$documents = [];
$alerts = [];

if ($workerId > 0) {
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
    $docTypesById = array_column($docTypes, null, 'id');

    // Pobierz dokumenty pracownika (aktywne) z najnowszym plikiem
    $stmt = $pdo->prepare("
        SELECT 
            wd.*,
            dt.name as type_name,
            dt.code as type_code,
            dt.has_validity,
            (SELECT COUNT(*) FROM worker_document_files WHERE document_id = wd.id) as files_count,
            (SELECT file_path FROM worker_document_files WHERE document_id = wd.id ORDER BY uploaded_at DESC LIMIT 1) as latest_file_path,
            (SELECT original_name FROM worker_document_files WHERE document_id = wd.id ORDER BY uploaded_at DESC LIMIT 1) as latest_file_name
        FROM worker_documents wd
        JOIN document_types dt ON wd.document_type_id = dt.id
        WHERE wd.worker_id = ? AND wd.status = 'active'
        ORDER BY dt.sort_order, wd.valid_to ASC
    ");
    $stmt->execute([$workerId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pobierz aktywne alerty
    $stmt = $pdo->prepare("
        SELECT 
            ha.*,
            wd.title as document_title,
            dt.name as document_type
        FROM hr_alerts ha
        JOIN worker_documents wd ON ha.document_id = wd.id
        JOIN document_types dt ON wd.document_type_id = dt.id
        WHERE ha.worker_id = ? AND ha.status = 'open'
        ORDER BY ha.due_date ASC
    ");
    $stmt->execute([$workerId]);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dokumenty HR</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #eab308;
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
        /* Hero */
        .page-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;
        }
        .page-header h2 { font-size: 28px; font-weight: 700; color: #fff; margin-bottom: 4px; letter-spacing: -0.3px; }
        .page-header .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .page-header .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .page-header p { color: #cbd5e1; font-size: 14px; margin-top: 2px; }
        .page-header-actions { display: flex; gap: 8px; align-items: flex-start; }
        .btn-hero-primary { background: #ffffff; color: #1e3a8a; border: 1px solid #ffffff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        /* Worker info box — neutralny, nie niebieski jak stary */
        .worker-info {
            background: #eff6ff; border-left: 4px solid #3b82f6;
            padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;
            font-size: 13px; color: #1e40af;
        }
        .alerts-section { margin-bottom: 20px; }
        .alert-item {
            background: white; border-left: 4px solid #eab308;
            padding: 12px 16px; margin-bottom: 8px; border-radius: 8px;
            border: 1px solid #e5e7eb; border-left-width: 4px;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 13px;
        }
        .alert-item.expired { border-left-color: #ef4444; }
        .alert-item.expiring { border-left-color: #eab308; }
        .card { background: white; border-radius: 12px; box-shadow: 0 1px 2px rgba(15,23,42,0.05); border: 1px solid var(--border); overflow: hidden; margin-bottom: 20px; }
        .card-header { padding: 14px 18px; background: #f9fafb; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 13px; color: var(--text-main); }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 10px 14px !important; text-align: left; font-weight: 600; color: var(--text-muted) !important; border: 1px solid #000 !important; font-size: 11px !important; text-transform: uppercase !important; letter-spacing: 0.5px; background: #f9fafb !important; }
        td { padding: 10px 14px !important; border: 1px solid #000 !important; font-size: 13px !important; vertical-align: middle; }
        tbody tr:hover { background: #f8fafc; }
        .btn { padding: 5px 10px; border-radius: 6px; text-decoration: none; font-weight: 600; cursor: pointer; border: 1px solid transparent; font-size: 12px; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-success { background: #22c55e; color: white; border-color: #22c55e; }
        .btn-success:hover { background: #16a34a; }
        .btn-warning { background: #fbbf24; color: #1f2937; border-color: #fbbf24; }
        .btn-danger { background: #ef4444; color: white; border-color: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
        .btn-info { background: #0ea5e9; color: white; border-color: #0ea5e9; }
        .btn-file { background: #6b7280; color: white; border-color: #6b7280; padding: 4px 8px; font-size: 11px; }
        .badge { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-danger  { background: #fee2e2; color: #991b1b; }
        .no-data { padding: 60px 20px; text-align: center; color: var(--text-muted); font-size: 15px; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <?php if ($isAdminUser && $workerId <= 0): ?>
            <!-- Wybór pracownika -->
            <div class="page-header">
                <h2>Dokumenty HR pracownika</h2>
            </div>
            
            <div class="card">
                <div class="card-header">Wybierz pracownika</div>
                <div style="padding: 30px;">
                    <form method="get">
                        <label style="display: block; margin-bottom: 10px; font-weight: 600;">Pracownik:</label>
                        <select name="worker_id" required style="padding: 10px; width: 100%; max-width: 400px; border: 2px solid #e0e0e0; border-radius: 6px; font-size: 14px;">
                            <option value="">-- Wybierz pracownika --</option>
                            <?php foreach ($workersList as $w): ?>
                                <option value="<?php echo $w['id']; ?>">
                                    <?php echo e($w['first_name'] . ' ' . $w['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" style="margin-top: 15px; padding: 10px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600;">
                            Pokaż dokumenty
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="page-header">
                <div>
                    <div class="hero-breadcrumb">
                        <a href="<?php echo url('hr.workers'); ?>">Pracownicy</a> ›
                        <a href="<?php echo url('hr.workers.profile', ['id' => $workerId]); ?>"><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?></a> ›
                        Dokumenty HR
                    </div>
                    <h2>Dokumenty HR</h2>
                    <p><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?></p>
                </div>
                <div class="page-header-actions">
                    <a href="<?php echo url('dodaj-dokument') . '?worker_id=' . $workerId; ?>" class="btn-hero-primary">+ Dodaj dokument</a>
                </div>
            </div>
            
            <div class="worker-info">
                <strong>Pracownik:</strong> <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
                | <strong>Typ:</strong> <?php 
                    $types = ['permanent' => 'Stały', 'temporary' => 'Tymczasowy', 'contractor' => 'Podwykonawca'];
                    echo $types[$worker['worker_type']] ?? 'Stały';
                ?>
            </div>
        
        <?php if (!empty($alerts)): ?>
            <div class="alerts-section">
                <h3 style="margin-bottom: 15px;">Aktywne Alerty (<?php echo count($alerts); ?>)</h3>
                <?php foreach ($alerts as $alert): ?>
                    <?php
                    $isExpired = strtotime($alert['due_date']) < time();
                    $daysLeft = (int)((strtotime($alert['due_date']) - time()) / 86400);
                    ?>
                    <div class="alert-item <?php echo $isExpired ? 'expired' : 'expiring'; ?>">
                        <div>
                            <strong><?php echo e($alert['document_type']); ?>:</strong> 
                            <?php echo e($alert['document_title']); ?>
                            <br>
                            <span style="font-size: 13px; color: #666;">
                                <?php if ($isExpired): ?>
                                    Wygaslo <?php echo formatDate($alert['due_date']); ?>
                                <?php else: ?>
                                    ⏰ Wygasa za <?php echo $daysLeft; ?> dni (<?php echo formatDate($alert['due_date']); ?>)
                                <?php endif; ?>
                            </span>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <a href="<?php echo url('hr.alerts.acknowledge', ['id' => $alert['id']]); ?>" 
                               class="btn btn-warning">
                                Potwierdź
                            </a>
                            <a href="<?php echo url('hr.alerts.close', ['id' => $alert['id']]); ?>" 
                               class="btn btn-success">
                                Zamknij
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">Dokumenty (<?php echo count($documents); ?>)</div>
            
            <?php if (empty($documents)): ?>
                <div class="no-data">
                    Brak dokumentów dla tego pracownika.<br><br>
                    <a href="<?php echo url('dodaj-dokument') . '?worker_id=' . $workerId; ?>" class="btn btn-primary">
                        Dodaj pierwszy dokument
                    </a>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Typ</th>
                            <th>Tytuł</th>
                            <th>Ważność</th>
                            <th>Plik</th>
                            <th>Status</th>
                            <th style="text-align: right;">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <?php
                            $hasValidity = $doc['has_validity'] && $doc['valid_to'];
                            $isExpired = $hasValidity && strtotime($doc['valid_to']) < time();
                            $daysLeft = $hasValidity ? (int)((strtotime($doc['valid_to']) - time()) / 86400) : 0;
                            $isExpiring = $hasValidity && $daysLeft <= ($doc['reminder_days'] ?? 30) && $daysLeft >= 0;
                            ?>
                            <tr>
                                <td><?php echo e($doc['type_name']); ?></td>
                                <td>
                                    <strong><?php echo e($doc['title']); ?></strong>
                                    <?php if ($doc['document_number']): ?>
                                        <br><span style="font-size: 13px; color: #666;">Nr: <?php echo e($doc['document_number']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($hasValidity): ?>
                                        <?php echo formatDate($doc['valid_from']); ?> – <?php echo formatDate($doc['valid_to']); ?>
                                    <?php else: ?>
                                        <span style="color: #999;">Brak daty ważności</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($doc['latest_file_path']): ?>
                                        <a href="/<?php echo e($doc['latest_file_path']); ?>" 
                                           target="_blank" 
                                           class="btn btn-file"
                                           title="<?php echo e($doc['latest_file_name'] ?? 'Otworz plik'); ?>">
                                            Otworz
                                        </a>
                                        <?php if ($doc['files_count'] > 1): ?>
                                            <span style="font-size: 11px; color: #666;">(+<?php echo $doc['files_count'] - 1; ?>)</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #999;">Brak</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isExpired): ?>
                                        <span class="badge badge-danger">Wygasły</span>
                                    <?php elseif ($isExpiring): ?>
                                        <span class="badge badge-warning">Wygasa wkrótce</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">Aktualny</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                        <a href="<?php echo url('edytuj-dokument') . '?id=' . $doc['id']; ?>" 
                                           class="btn btn-info">
                                            Edytuj
                                        </a>
                                        <a href="<?php echo url('archiwizuj-dokument') . '?id=' . $doc['id']; ?>" 
                                           class="btn btn-warning"
                                           onclick="return confirm('Czy na pewno archiwizować ten dokument?');">
                                            Archiwizuj
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

