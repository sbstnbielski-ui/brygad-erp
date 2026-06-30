<?php
/**
 * BRYGAD ERP - Zaliczki pracownicze - Szczegóły
 * 
 * Widok szczegółów konkretnej zaliczki, historia ruchów, pliki
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/wallet_helper.php';
require_once dirname(__DIR__, 2) . '/includes/payroll_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$advanceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($advanceId <= 0) {
    header("Location: " . url('finanse.zaliczki'));
    exit;
}

// Pobierz szczegóły zaliczki
try {
    $stmt = $pdo->prepare("SELECT * FROM v_worker_advances_details WHERE advance_id = ?");
    $stmt->execute([$advanceId]);
    $advance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$advance) {
        die("Nie znaleziono zaliczki.");
    }
    
    // Pobierz historię ruchów z ledger
    $stmt = $pdo->prepare("
        SELECT 
            wl.*,
            u.login as created_by_name
        FROM worker_ledger wl
        LEFT JOIN users u ON wl.created_by = u.id
        WHERE wl.advance_id = ?
        ORDER BY wl.entry_date DESC, wl.id DESC
    ");
    $stmt->execute([$advanceId]);
    $ledgerEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $walletWorkerNames = walletGetWorkerDisplayNames(
        $pdo,
        walletExtractWorkerIdsFromDescriptions(array_merge(
            [(string)($advance['description'] ?? '')],
            array_map(static fn(array $entry): string => (string)($entry['description'] ?? ''), $ledgerEntries)
        ))
    );
    
    // Pobierz pliki
    $stmt = $pdo->prepare("
        SELECT 
            waf.*,
            u.login as uploaded_by_name
        FROM worker_advance_files waf
        LEFT JOIN users u ON waf.uploaded_by_user_id = u.id
        WHERE waf.advance_id = ?
        ORDER BY waf.uploaded_at DESC
    ");
    $stmt->execute([$advanceId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching advance details: " . $e->getMessage());
    die("Błąd pobierania danych zaliczki: " . $e->getMessage());
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Szczegóły zaliczki #<?php echo $advanceId; ?></title>
    <style>

        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --bg-card:           #ffffff;
            --border:            #e5e7eb;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
            --success:           #22c55e;
            --danger:            #ef4444;
            --warning:           #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body); color: var(--text-main); line-height: 1.5; padding-bottom: 40px;
        }
        .container { max-width: 1000px; margin: 0 auto; padding: 25px; }

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

        /* Hero */
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 26px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        
        /* Breadcrumbs */
        .breadcrumbs {
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }
        .breadcrumbs a {
            color: #667eea;
            text-decoration: none;
        }
        .breadcrumbs a:hover {
            text-decoration: underline;
        }
        .breadcrumbs span {
            margin: 0 5px;
        }
        
        .page-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header h1 {
            font-size: 32px;
            color: #333;
            margin-bottom: 8px;
        }
        .page-header p {
            font-size: 16px;
            color: #666;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 6px rgba(102, 126, 234, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-secondary:hover {
            background: #d1d5db;
        }
        .btn-warning {
            background: #fef3c7;
            color: #92400e;
        }
        .btn-warning:hover {
            background: #fde68a;
        }
        
        /* Info card */
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .info-card h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f3f4f6;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
        }
        .info-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .info-value {
            font-size: 16px;
            color: #1f2937;
            font-weight: 600;
        }
        .info-value.large {
            font-size: 24px;
            color: #667eea;
        }
        
        /* Badges */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge.type-company {
            background: #dbeafe;
            color: #1e40af;
        }
        .badge.type-private {
            background: #fef3c7;
            color: #92400e;
        }
        .badge.status-open {
            background: #fef3c7;
            color: #92400e;
        }
        .badge.status-closed {
            background: #d1fae5;
            color: #065f46;
        }
        
        /* Ledger history table */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }
        th {
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
        }
        td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
        }
        tbody tr:hover {
            background: #f9fafb;
        }
        
        .amount-positive {
            color: #16a34a;
            font-weight: 600;
        }
        .amount-negative {
            color: #dc2626;
            font-weight: 600;
        }
        
        /* Files */
        .files-list {
            display: grid;
            gap: 10px;
        }
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .file-icon {
            font-size: 24px;
        }
        .file-details {
            font-size: 13px;
        }
        .file-name {
            font-weight: 600;
            color: #1f2937;
        }
        .file-meta {
            color: #6b7280;
            font-size: 11px;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
            .header-actions {
                flex-direction: column;
                width: 100%;
            }
            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>

    <div class="container">
        <!-- Success Messages -->
        <?php if (isset($_GET['success']) && $_GET['success'] === 'edited'): ?>
            <div style="padding: 15px 20px; background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                ✓ Zaliczka została zaktualizowana.
            </div>
        <?php endif; ?>
        
        <!-- Page Header -->
                <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    <a href="<?php echo url('finanse.zaliczki'); ?>">Zaliczki</a> /
                    Szczegóły Zaliczki
                </div>
                <h1>Szczegóły Zaliczki</h1>
                <p>Podgląd zaliczki</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse.zaliczki'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
            <div class="header-actions">
                <a href="<?php echo url('finanse.zaliczki'); ?>" class="btn btn-secondary">← Powrót</a>
                <a href="<?php echo url('finanse.zaliczki.edit', ['id' => $advanceId]); ?>" class="btn" style="background: #dbeafe; color: #1e40af;">Edytuj</a>
                <?php if ($advance['status'] === 'open' && $advance['amount_remaining'] > 0): ?>
                    <a href="<?php echo url('finanse.zaliczki.close', ['id' => $advanceId]); ?>" class="btn btn-primary">Rozlicz szczegółowo</a>
                    <form method="POST" action="<?php echo url('finanse.zaliczki.mark_paid'); ?>" style="display: inline;" onsubmit="return confirm('Czy na pewno chcesz oznaczyć tę zaliczkę jako spłaconą?');">
                        <input type="hidden" name="advance_id" value="<?php echo $advanceId; ?>">
                                        <button type="submit" class="btn btn-warning">Spłać</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" action="<?php echo url('finanse.zaliczki.delete'); ?>" style="display: inline;" onsubmit="return confirm('Czy na pewno chcesz USUNĄĆ tę zaliczkę? Zostaną usunięte także WSZYSTKIE rozliczenia!');">
                                    <input type="hidden" name="advance_id" value="<?php echo $advanceId; ?>">
                                    <button type="submit" class="btn" style="background: #fee2e2; color: #991b1b;">Usuń</button>
                                </form>
            </div>
        </div>
        
        <!-- Basic Info -->
        <div class="info-card">
            <h2>Podstawowe informacje</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Pracownik</div>
                    <div class="info-value"><?php echo e($advance['last_name'] . ' ' . $advance['first_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Typ zaliczki</div>
                    <div class="info-value">
                        <span class="badge type-<?php echo strtolower($advance['type']); ?>">
                            <?php echo $advance['type'] === 'COMPANY' ? 'Firmowa' : 'Prywatna'; ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="badge status-<?php echo $advance['status']; ?>">
                            <?php echo $advance['status'] === 'open' ? 'Otwarta' : 'Zamknięta'; ?>
                        </span>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Data wydania</div>
                    <div class="info-value"><?php echo formatDate($advance['issue_date']); ?></div>
                </div>
                <?php if (($advance['type'] ?? '') === 'PRIVATE' && !empty($advance['salary_period'])): ?>
                <div class="info-item">
                    <div class="info-label">Okres rozliczeniowy</div>
                    <div class="info-value"><?php echo e(payrollMonthLabel((string)$advance['salary_period'])); ?></div>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <div class="info-label">Kwota wydana</div>
                    <div class="info-value large"><?php echo formatMoney($advance['amount']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Rozliczono</div>
                    <div class="info-value"><?php echo formatMoney($advance['amount_settled']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Pozostało</div>
                    <div class="info-value" style="color: <?php echo $advance['amount_remaining'] > 0 ? '#dc2626' : '#16a34a'; ?>;">
                        <?php echo formatMoney($advance['amount_remaining']); ?>
                    </div>
                </div>
                <?php if ($advance['description']): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <div class="info-label">Opis</div>
                    <div class="info-value" style="font-size: 14px; font-weight: normal; color: #6b7280;">
                        <?php echo nl2br(e(walletHumanizeDescription((string)$advance['description'], $walletWorkerNames ?? []))); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Ledger History -->
        <div class="info-card">
            <h2>Historia rozliczenia (ledger)</h2>
            <?php if (empty($ledgerEntries)): ?>
                <div class="no-data">
                    <p>Brak wpisów w historii</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Typ operacji</th>
                            <th>Kwota</th>
                            <th>Opis</th>
                            <th>Utworzył</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ledgerEntries as $entry): ?>
                            <tr>
                                <td><?php echo formatDate($entry['entry_date']); ?></td>
                                <td><?php echo e(walletResolveEntryLabel($entry)); ?></td>
                                <td>
                                    <span class="<?php echo $entry['amount'] > 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                        <?php echo formatMoney($entry['amount']); ?>
                                    </span>
                                </td>
                                <td><?php echo e(walletHumanizeDescription((string)($entry['description'] ?? ''), $walletWorkerNames ?? [])) ?: '—'; ?></td>
                                <td><?php echo e($entry['created_by_name'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Files -->
        <div class="info-card">
            <h2>Załączone pliki (<?php echo count($files); ?>)</h2>
            <?php if (empty($files)): ?>
                <div class="no-data">
                    <p>Brak załączonych plików</p>
                </div>
            <?php else: ?>
                <div class="files-list">
                    <?php foreach ($files as $file): ?>
                        <div class="file-item">
                            <div class="file-info">
                                <div class="file-icon">📎</div>
                                <div class="file-details">
                                    <div class="file-name"><?php echo e($file['original_name'] ?? basename($file['file_path'])); ?></div>
                                    <div class="file-meta">
                                        Dodano: <?php echo formatDate($file['uploaded_at']); ?>
                                        <?php if ($file['uploaded_by_name']): ?>
                                            przez <?php echo e($file['uploaded_by_name']); ?>
                                        <?php endif; ?>
                                        <?php if ($file['file_size']): ?>
                                            • <?php echo formatFileSize($file['file_size']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <a href="<?php echo asset($file['file_path']); ?>" target="_blank" class="btn btn-secondary" style="padding: 6px 12px; font-size: 11px;">Pobierz</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
