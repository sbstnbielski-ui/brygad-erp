<?php
/**
 * BRYGAD ERP - Widok szczegółów dokumentu finansowego (Finance Item)
 * 
 * Wyświetla szczegóły paragonu lub kosztu stałego
 * Obsługuje: RECEIPT, FIXED_COST
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$isAdminUser = isAdmin();
$currentUserId = $_SESSION['user_id'] ?? null;
$currentWorkerId = $_SESSION['worker_id'] ?? null;

$item_id = $_GET['id'] ?? null;

if (!$item_id) {
    header("Location: " . url('finanse'));
    exit;
}

// Pobierz dane dokumentu
$stmt = $pdo->prepare("
    SELECT fi.*, 
           p.name AS project_name,
           pcn.name AS etap_name,
           inv.name AS company_full_name,
           u.login AS created_by_login
    FROM finance_items fi
    LEFT JOIN projects p ON p.id = fi.project_id
    LEFT JOIN project_cost_nodes pcn ON pcn.id = fi.etap_id
    LEFT JOIN investors inv ON inv.id = fi.company_id
    LEFT JOIN users u ON u.id = fi.created_by
    WHERE fi.id = ?
");
$stmt->execute([$item_id]);
$item = $stmt->fetch();

if (!$item) {
    header("Location: " . url('finanse') . "?error=not_found");
    exit;
}

// Sprawdź uprawnienia - pracownik widzi tylko swoje
if (!$isAdminUser && $item['created_by'] != $currentUserId) {
    header("Location: " . url('dashboard') . "?error=access_denied");
    exit;
}

// Obsługa zatwierdzania (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdminUser) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE finance_items SET status = 'approved', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$item_id]);
        logEvent("Zatwierdzono finance_item ID: {$item_id}", 'INFO');
        header("Location: " . url('finanse.items.view', ['id' => $item_id, 'success' => 'approved']));
        exit;
    }
    
    if ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE finance_items SET status = 'rejected', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$item_id]);
        logEvent("Odrzucono finance_item ID: {$item_id}", 'INFO');
        header("Location: " . url('finanse.items.view', ['id' => $item_id, 'success' => 'rejected']));
        exit;
    }
}

// Typ dokumentu
$typeLabels = [
    'RECEIPT' => 'Paragon',
    'FIXED_COST' => 'Koszt staly',
    'INVOICE_COST' => 'Faktura kosztowa',
    'INVOICE_REVENUE' => 'Przychod'
];

$statusLabels = [
    'draft' => ['label' => 'Wersja robocza', 'class' => 'badge-secondary'],
    'pending' => ['label' => 'Oczekuje', 'class' => 'badge-warning'],
    'approved' => ['label' => 'Zatwierdzony', 'class' => 'badge-success'],
    'rejected' => ['label' => 'Odrzucony', 'class' => 'badge-danger'],
    'cancelled' => ['label' => 'Anulowany', 'class' => 'badge-secondary']
];

$categoryLabels = [
    'material' => 'Materiały budowlane',
    'tools' => 'Narzędzia',
    'fuel' => 'Paliwo',
    'transport' => 'Transport',
    'food' => 'Wyżywienie',
    'office' => 'Materiały biurowe',
    'zus' => 'ZUS / Składki',
    'pit' => 'PIT',
    'vat' => 'VAT',
    'cit' => 'CIT',
    'insurance' => 'Ubezpieczenia',
    'rent' => 'Wynajem',
    'leasing' => 'Leasing',
    'subscription' => 'Abonamenty',
    'utilities' => 'Media',
    'phone' => 'Telefony / Internet',
    'accounting' => 'Księgowość',
    'bank' => 'Opłaty bankowe',
    'salary' => 'Wynagrodzenia',
    'other' => 'Inne',
    'other_fixed' => 'Inne koszty stałe'
];

$success_message = '';
if (isset($_GET['success'])) {
    $messages = [
        'approved' => 'Dokument został zatwierdzony',
        'rejected' => 'Dokument został odrzucony'
    ];
    $success_message = $messages[$_GET['success']] ?? '';
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo $typeLabels[$item['item_type']] ?? 'Dokument'; ?></title>
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

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .card-header {
            padding: 20px 25px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            font-size: 16px;
        }
        .card-body {
            padding: 25px;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .detail-item {
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }
        .detail-label {
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .detail-value {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
        }
        .detail-value.amount {
            font-size: 24px;
            color: #dc2626;
        }
        .detail-value.amount.positive {
            color: #16a34a;
        }
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-secondary { background: #e5e7eb; color: #374151; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-block;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-success {
            background: #10b981;
            color: white;
        }
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .file-preview {
            margin-top: 20px;
            padding: 20px;
            background: #f3f4f6;
            border-radius: 8px;
            text-align: center;
        }
        .file-preview img {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .file-preview a {
            display: inline-block;
            margin-top: 15px;
            color: #3b82f6;
            text-decoration: none;
            font-weight: 600;
        }
        .file-preview a:hover {
            text-decoration: underline;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
            }
            .actions {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(dirname(__DIR__)) . '/includes/header_modules.php'; ?>
    
    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    Szczegóły Pozycji
                </div>
                <h1>Szczegóły Pozycji</h1>
                <p>Szczegóły pozycji</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
        <div class="actions">
                <?php if ($isAdminUser && $item['status'] === 'pending'): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success" onclick="return confirm('Zatwierdzić ten dokument?')">
                            ✓ Zatwierdź
                        </button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="reject">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Odrzucić ten dokument?')">
                            ✗ Odrzuć
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($item['project_id']): ?>
                    <a href="<?php echo url('projekty.view', ['id' => $item['project_id']]); ?>#dokumenty-finansowe" class="btn btn-secondary">← Wróć do projektu</a>
                <?php else: ?>
                    <a href="<?php echo url('finanse'); ?>" class="btn btn-secondary">← Wróć</a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">Szczegóły dokumentu</div>
            <div class="card-body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Tytuł / Opis</div>
                        <div class="detail-value"><?php echo e($item['title']); ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Kwota brutto</div>
                        <div class="detail-value amount"><?php echo formatMoney($item['amount_gross']); ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Data</div>
                        <div class="detail-value"><?php echo $item['issue_date'] ? formatDate($item['issue_date']) : '-'; ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Kategoria</div>
                        <div class="detail-value"><?php echo e($categoryLabels[$item['cost_category']] ?? $item['cost_category'] ?? '-'); ?></div>
                    </div>
                    
                    <?php if ($item['amount_vat'] > 0): ?>
                    <div class="detail-item">
                        <div class="detail-label">Kwota netto</div>
                        <div class="detail-value"><?php echo formatMoney($item['amount_net']); ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">VAT</div>
                        <div class="detail-value"><?php echo formatMoney($item['amount_vat']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($item['company_name'] || $item['company_full_name']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Kontrahent / Źródło</div>
                        <div class="detail-value"><?php echo e($item['company_full_name'] ?? $item['company_name']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($item['doc_number']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Numer dokumentu</div>
                        <div class="detail-value"><?php echo e($item['doc_number']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($item['payment_date']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Data zapłaty</div>
                        <div class="detail-value"><?php echo formatDate($item['payment_date']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($item['etap_name']): ?>
                    <div class="detail-item">
                        <div class="detail-label">Etap kosztowy</div>
                        <div class="detail-value"><?php echo e($item['etap_name']); ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <div class="detail-label">Utworzono</div>
                        <div class="detail-value">
                            <?php echo $item['created_at'] ? date('Y-m-d H:i', strtotime($item['created_at'])) : '-'; ?>
                            <?php if ($item['created_by_login']): ?>
                                <br><small style="color: #666;">przez <?php echo e($item['created_by_login']); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php if ($item['description']): ?>
                <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px;">
                    <div class="detail-label">Dodatkowy opis</div>
                    <div style="margin-top: 8px; color: #374151; line-height: 1.6;">
                        <?php echo nl2br(e($item['description'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($item['file_path']): ?>
                <div class="file-preview">
                    <div class="detail-label">Załączony plik</div>
                    <?php 
                    $file_ext = strtolower(pathinfo($item['file_path'], PATHINFO_EXTENSION));
                    $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                    ?>
                    <?php if ($is_image): ?>
                        <img src="/<?php echo e($item['file_path']); ?>" alt="Podgląd dokumentu">
                        <br>
                    <?php else: ?>
                        <div style="font-size: 48px; margin: 20px 0;">📄</div>
                    <?php endif; ?>
                    <a href="/<?php echo e($item['file_path']); ?>" target="_blank">
                        📥 Pobierz / Otwórz plik (<?php echo strtoupper($file_ext); ?>)
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>

