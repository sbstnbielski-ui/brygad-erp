<?php
/**
 * SPRUTEX - Moduł HR: Dokumenty Pracownika
 */
require_once __DIR__ . '/config/database.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

// ZABEZPIECZENIE: Admin może wybrać pracownika, worker widzi tylko siebie
if ($isAdminUser) {
    $workerId = isset($_GET['worker_id']) ? (int)$_GET['worker_id'] : 0;
    
    if ($workerId <= 0) {
        header('Location: workers.php');
        exit;
    }
} else {
    // Worker może przeglądać tylko swoje dokumenty
    $workerId = $currentWorkerId;
    
    if (!$workerId) {
        die('Brak przypisanego pracownika do konta.');
    }
    
    // Jeśli próbuje podejrzeć cudze dokumenty
    if (isset($_GET['worker_id']) && (int)$_GET['worker_id'] != $currentWorkerId) {
        logEvent("SECURITY: Worker ID $currentWorkerId próbował podejrzeć dokumenty worker ID " . $_GET['worker_id'], 'WARNING');
        header('Location: dokumenty-pracownika.php?worker_id=' . $currentWorkerId);
        exit;
    }
}

// Pobierz pracownika
$stmt = $pdo->prepare("SELECT * FROM workers WHERE id = ?");
$stmt->execute([$workerId]);
$worker = $stmt->fetch();

if (!$worker) {
    header('Location: workers.php');
    exit;
}

// Pobierz typy dokumentów
$stmt = $pdo->query("SELECT * FROM document_types WHERE is_active = 1 ORDER BY sort_order");
$docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$docTypesById = array_column($docTypes, null, 'id');

// Pobierz dokumenty pracownika (aktywne)
$stmt = $pdo->prepare("
    SELECT 
        wd.*,
        dt.name as type_name,
        dt.code as type_code,
        dt.has_validity,
        (SELECT COUNT(*) FROM worker_document_files WHERE document_id = wd.id) as files_count
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

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Dokumenty HR</title>
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
            max-width: 1400px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .page-header h2 { font-size: 32px; color: #333; }
        .worker-info {
            background: #e7f3ff;
            border-left: 4px solid #0066cc;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .alerts-section {
            margin-bottom: 30px;
        }
        .alert-item {
            background: white;
            border-left: 4px solid #ffc107;
            padding: 15px 20px;
            margin-bottom: 10px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .alert-item.expired { border-left-color: #dc3545; }
        .alert-item.expiring { border-left-color: #ffc107; }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: #555;
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
            font-size: 14px;
        }
        td {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
        }
        tr:hover { background: #f8f9fa; }
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 13px;
            transition: all 0.2s;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-info { background: #17a2b8; color: white; }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #999;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <img src="assets/logo-brygad-erp.png" alt="BRYGAD ERP">
                <div class="logo-text">
                    <h1><?php echo e(APP_NAME); ?></h1>
                    <p>System Zarządzania Operacyjnego</p>
                </div>
            </div>
            <nav class="nav-section">
                <a href="dashboard.php">Panel Główny</a>
                <a href="workers.php" class="active">Pracownicy</a>
            </nav>
            <div class="user-section">
                <div>
                    <span class="user-name">
                        <?php echo e($userName); ?>
                        <span class="role-badge">Administrator</span>
                    </span>
                </div>
                <a href="logout.php" class="btn-logout">Wyloguj</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="dashboard.php">Panel Główny</a> / 
            <a href="workers.php">Pracownicy</a> / 
            <a href="edytuj-pracownika.php?id=<?php echo $workerId; ?>">
                <?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?>
            </a> / 
            Dokumenty HR
        </div>
        
        <div class="page-header">
            <h2>Dokumenty HR</h2>
            <a href="dodaj-dokument.php?worker_id=<?php echo $workerId; ?>" class="btn btn-primary">
                + Dodaj Dokument
            </a>
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
                <h3 style="margin-bottom: 15px;">⚠️ Aktywne Alerty (<?php echo count($alerts); ?>)</h3>
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
                            <a href="acknowledge-alert.php?id=<?php echo $alert['id']; ?>" 
                               class="btn btn-warning">
                                Potwierdź
                            </a>
                            <a href="close-alert.php?id=<?php echo $alert['id']; ?>" 
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
                    <a href="dodaj-dokument.php?worker_id=<?php echo $workerId; ?>" class="btn btn-primary">
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
                            <th>Pliki</th>
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
                                    <?php if ($doc['files_count'] > 0): ?>
                                        <?php echo $doc['files_count']; ?> plik(ow)
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
                                        <a href="edytuj-dokument.php?id=<?php echo $doc['id']; ?>" 
                                           class="btn btn-info">
                                            Edytuj
                                        </a>
                                        <a href="archiwizuj-dokument.php?id=<?php echo $doc['id']; ?>" 
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
    </div>
</body>
</html>

