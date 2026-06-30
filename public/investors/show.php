<?php
/**
 * BRYGAD ERP v3.0 - Karta Kontrahenta (CRM)
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header("Location: " . url('investors'));
    exit;
}

$investorStmt = $pdo->prepare("SELECT * FROM investors WHERE id = ?");
$investorStmt->execute([$id]);
$investor = $investorStmt->fetch();

if (!$investor) {
    header("Location: " . url('investors', ['error' => 'Kontrahent nie istnieje']));
    exit;
}

// Statystyki finansowe
$finStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS invoices_count,
        COALESCE(SUM(CASE WHEN status IN ('issued','paid') THEN amount_gross ELSE 0 END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_gross ELSE 0 END), 0) AS paid_revenue,
        COALESCE(SUM(CASE WHEN status IN ('draft','issued') THEN amount_gross ELSE 0 END), 0) AS open_revenue,
        COUNT(CASE WHEN status IN ('draft','issued') THEN 1 END) AS open_invoices_count
    FROM invoices_sale
    WHERE client_id = ?
");
$finStmt->execute([$id]);
$fin = $finStmt->fetch();

// Retencje otwarte
$retStmt = $pdo->prepare("
    SELECT r.*, inv.invoice_number, inv.issue_date, inv.amount_gross
    FROM invoice_sale_retentions r
    JOIN invoices_sale inv ON inv.id = r.invoice_id
    WHERE inv.client_id = ? AND r.status != 'settled'
    ORDER BY r.return_date ASC
");
$retStmt->execute([$id]);
$retentions = $retStmt->fetchAll();
$retentionTotal = array_sum(array_column($retentions, 'retention_amount'));

// Projekty
$projStmt = $pdo->prepare("
    SELECT p.*, COALESCE(SUM(pr.amount_net), 0) AS revenue_sum
    FROM projects p
    LEFT JOIN project_revenues pr ON pr.project_id = p.id
    WHERE p.investor_id = ?
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$projStmt->execute([$id]);
$projects = $projStmt->fetchAll();

// Faktury sprzedażowe
$invStmt = $pdo->prepare("
    SELECT inv.*,
        r.status AS ret_status,
        r.retention_amount,
        r.return_date AS ret_return_date
    FROM invoices_sale inv
    LEFT JOIN invoice_sale_retentions r ON r.invoice_id = inv.id
    WHERE inv.client_id = ?
    ORDER BY inv.issue_date DESC
    LIMIT 50
");
$invStmt->execute([$id]);
$invoices = $invStmt->fetchAll();

// Notatki kontaktowe
$notes = [];
try {
    $stmtNotes = $pdo->prepare("
        SELECT n.*, w.name AS author_name
        FROM investor_notes n
        LEFT JOIN workers w ON w.id = n.created_by
        WHERE n.investor_id = ?
        ORDER BY n.created_at DESC
    ");
    $stmtNotes->execute([$id]);
    $notes = $stmtNotes->fetchAll();
} catch (PDOException $e) { $notes = []; }

// Przypomnienia
$reminders = [];
try {
    $stmtRem = $pdo->prepare("
        SELECT r.*, w.name AS author_name
        FROM investor_reminders r
        LEFT JOIN workers w ON w.id = r.created_by
        WHERE r.investor_id = ?
        ORDER BY r.is_done ASC, r.remind_at ASC
    ");
    $stmtRem->execute([$id]);
    $reminders = $stmtRem->fetchAll();
} catch (PDOException $e) { $reminders = []; }

$success = $_GET['success'] ?? '';
$error   = $_GET['error'] ?? '';

$statusLabels = ['planned' => 'Planowany', 'active' => 'Aktywny', 'finished' => 'Zakończony'];
$statusColors = ['planned' => '#6b7280', 'active' => '#059669', 'finished' => '#1e40af'];
$invStatusLabels = ['draft' => 'Szkic', 'issued' => 'Wystawiona', 'paid' => 'Opłacona'];
$retLabels = ['active' => 'Retencja', 'due_soon' => 'Ret. <30d', 'overdue' => 'Ret. po terminie', 'settled' => 'Rozliczona'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo e($investor['name']); ?></title>
    <style>
        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --bg-card:           #ffffff;
            --border:            #e5e7eb;
            --border-light:      #d1d5db;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
            --success:           #22c55e;
            --danger:            #ef4444;
            --warning:           #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); padding-bottom: 40px; }
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }

        /* Hero */
        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff; border-radius: 14px; padding: 22px; margin-bottom: 22px;
            display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border: 1px solid #fff; font-weight: 700; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,0.2); font-weight: 600; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }
        .hero-meta { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 10px; font-size: 13px; color: #bfdbfe; }
        .hero-meta a { color: #93c5fd; text-decoration: none; }
        .hero-meta a:hover { text-decoration: underline; }
        .hero-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: 700; margin-right: 6px; }
        .hero-badge-b2b { background: rgba(219,234,254,0.2); color: #bfdbfe; border: 1px solid rgba(147,197,253,0.3); }
        .hero-badge-b2c { background: rgba(252,231,243,0.2); color: #fbcfe8; border: 1px solid rgba(249,168,212,0.3); }
        .hero-badge-active   { background: rgba(212,237,218,0.2); color: #bbf7d0; border: 1px solid rgba(134,239,172,0.3); }
        .hero-badge-inactive { background: rgba(226,227,229,0.15); color: #d1d5db; border: 1px solid rgba(156,163,175,0.3); }

        /* KPI */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
        @media (max-width: 900px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        .stat-card {
            background: white; padding: 18px 20px; border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07); transition: all 0.2s;
            border: 2px solid transparent; color: inherit;
        }
        .stat-label { font-size: 12px; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .stat-value { font-size: 22px; font-weight: 700; color: #667eea; }
        .stat-sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

        /* Layout */
        .crm-layout { display: grid; grid-template-columns: 310px 1fr; gap: 20px; align-items: start; }
        @media (max-width: 900px) { .crm-layout { grid-template-columns: 1fr; } }

        /* Sidebar cards */
        .sidebar-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); overflow: hidden; margin-bottom: 16px; }
        .sc-header { padding: 12px 16px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); border-bottom: 1px solid var(--border); background: #f9fafb; }
        .sc-body { padding: 16px; }
        .sc-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; gap: 12px; }
        .sc-row:last-child { border-bottom: none; }
        .sc-row-label { color: var(--text-muted); flex-shrink: 0; }
        .sc-row-value { font-weight: 500; text-align: right; word-break: break-word; }
        .sc-row-value a { color: var(--primary); text-decoration: none; }
        .sc-row-value a:hover { text-decoration: underline; }

        /* Section cards (right column) */
        .section-card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); overflow: hidden; margin-bottom: 20px; }
        .section-header { padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); background: #f9fafb; }
        .section-title { font-size: 13px; font-weight: 700; color: #374151; }
        .section-count { font-size: 12px; color: var(--text-muted); background: #e5e7eb; padding: 2px 8px; border-radius: 10px; }

        /* Table */
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f1f3f5; }
        th { padding: 10px 14px; text-align: left; font-weight: 600; color: var(--text-muted); border: 1px solid var(--border); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 10px 14px; border: 1px solid var(--border); font-size: 13px; vertical-align: middle; }
        tbody tr:hover { background: #f8fafc; }

        /* Badges */
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge-paid      { background: #d4edda; color: #155724; }
        .badge-issued    { background: #e7f3ff; color: #004080; }
        .badge-draft     { background: #fff3cd; color: #856404; }
        .badge-overdue   { background: #f8d7da; color: #721c24; }
        .badge-ret-active  { background: #e0f7fa; color: #00695c; }
        .badge-ret-due     { background: #fff3cd; color: #856404; }
        .badge-ret-overdue { background: #f8d7da; color: #721c24; }
        .badge-ret-settled { background: #d4edda; color: #155724; }

        /* Action buttons */
        .action-btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 4px 10px; height: 26px; border-radius: 4px; text-decoration: none;
            font-size: 11px; font-weight: 600; transition: all 0.2s; border: 1px solid;
            background: white; white-space: nowrap; cursor: pointer;
        }
        .action-btn:hover { transform: translateY(-1px); }
        .action-btn-edit { color: #059669; border-color: #059669; }
        .action-btn-edit:hover { background: #059669; color: white; }

        /* Notatki */
        .note-form { padding: 14px 16px; border-bottom: 1px solid var(--border); background: #f9fafb; }
        .note-form textarea { width: 100%; padding: 8px 10px; border: 1px solid var(--border-light); border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; min-height: 64px; transition: border 0.15s; }
        .note-form textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(102,126,234,0.1); }
        .note-form-actions { display: flex; justify-content: flex-end; margin-top: 6px; }
        .btn-sm { padding: 0 14px; height: 30px; font-size: 12px; border-radius: 5px; font-weight: 600; cursor: pointer; border: none; transition: all 0.15s; display: inline-flex; align-items: center; }
        .btn-sm-primary { background: var(--primary); color: white; }
        .btn-sm-primary:hover { background: var(--primary-dark); }
        .btn-sm-danger { background: transparent; color: #ef4444; border: 1px solid #fca5a5; font-size: 11px; padding: 0 8px; height: 24px; }
        .btn-sm-danger:hover { background: #fee2e2; }
        .btn-sm-done { background: transparent; color: #059669; border: 1px solid #6ee7b7; font-size: 11px; padding: 0 8px; height: 24px; }
        .btn-sm-done:hover { background: #d1fae5; }
        .note-item { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; }
        .note-item:last-child { border-bottom: none; }
        .note-text { font-size: 13px; color: #374151; line-height: 1.55; margin-bottom: 6px; white-space: pre-wrap; }
        .note-meta { font-size: 11px; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center; gap: 8px; }

        /* Przypomnienia */
        .reminder-form { padding: 14px 16px; border-bottom: 1px solid var(--border); background: #f9fafb; }
        .reminder-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px; }
        .reminder-form input[type="text"],
        .reminder-form input[type="date"],
        .reminder-form textarea { width: 100%; padding: 6px 10px; height: 34px; border: 1px solid var(--border-light); border-radius: 6px; font-size: 13px; font-family: inherit; transition: border 0.15s; }
        .reminder-form textarea { height: auto; min-height: 52px; resize: vertical; }
        .reminder-form input:focus, .reminder-form textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(102,126,234,0.1); }
        .reminder-item { padding: 11px 16px; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .reminder-item:last-child { border-bottom: none; }
        .reminder-item.done { opacity: 0.5; }
        .reminder-date { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 5px; background: #e0f2fe; color: #0369a1; white-space: nowrap; flex-shrink: 0; }
        .reminder-date.overdue { background: #fee2e2; color: #991b1b; }
        .reminder-date.today   { background: #fef9c3; color: #713f12; }
        .reminder-date.done    { background: #d1fae5; color: #065f46; }
        .reminder-title { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 2px; }
        .reminder-note  { font-size: 12px; color: var(--text-muted); }
        .reminder-actions { display: flex; gap: 5px; flex-shrink: 0; }

        /* Retention sidebar */
        .ret-item { padding: 11px 16px; border-bottom: 1px solid #f3f4f6; }
        .ret-item:last-child { border-bottom: none; }

        .no-data { padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 14px; }
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; border-left: 4px solid #28a745; color: #155724; }
        .alert-error   { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 13px; }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

    <div class="container">

        <!-- HERO -->
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('investors'); ?>">Baza Kontrahentów</a> /
                    <?php echo e($investor['name']); ?>
                </div>
                <h1>
                    <span class="hero-badge hero-badge-<?php echo $investor['type'] === 'private' ? 'b2c' : 'b2b'; ?>">
                        <?php echo $investor['type'] === 'private' ? 'B2C' : 'B2B'; ?>
                    </span>
                    <?php echo e($investor['name']); ?>
                    <span class="hero-badge hero-badge-<?php echo $investor['is_active'] ? 'active' : 'inactive'; ?>">
                        <?php echo $investor['is_active'] ? 'Aktywny' : 'Nieaktywny'; ?>
                    </span>
                </h1>
                <div class="hero-meta">
                    <?php if ($investor['nip']): ?>
                        <span>NIP: <strong><?php echo e($investor['nip']); ?></strong></span>
                    <?php endif; ?>
                    <?php if ($investor['contact_person']): ?>
                        <span>Kontakt: <strong><?php echo e($investor['contact_person']); ?></strong></span>
                    <?php endif; ?>
                    <?php if ($investor['phone']): ?>
                        <span><a href="tel:<?php echo e($investor['phone']); ?>"><?php echo e($investor['phone']); ?></a></span>
                    <?php endif; ?>
                    <?php if ($investor['email']): ?>
                        <span><a href="mailto:<?php echo e($investor['email']); ?>"><?php echo e($investor['email']); ?></a></span>
                    <?php endif; ?>
                    <?php if ($investor['website']): ?>
                        <span><a href="<?php echo e($investor['website']); ?>" target="_blank" rel="noopener"><?php echo e($investor['website']); ?></a></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('investors.edit', ['id' => $investor['id']]); ?>" class="btn-hero-primary">Edytuj dane</a>
                <a href="<?php echo url('investors'); ?>" class="btn-hero-secondary">← Lista</a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo e($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <!-- KPI -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Łączny przychód brutto</div>
                <div class="stat-value"><?php echo formatMoney($fin['total_revenue']); ?></div>
                <div class="stat-sub">wystawione + zapłacone</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Otwarte należności</div>
                <div class="stat-value"><?php echo formatMoney($fin['open_revenue']); ?></div>
                <div class="stat-sub"><?php echo (int)$fin['open_invoices_count']; ?> faktur oczekuje</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Projekty</div>
                <div class="stat-value"><?php echo count($projects); ?></div>
                <div class="stat-sub">powiązanych projektów</div>
            </div>
            <?php if ($retentionTotal > 0): ?>
            <div class="stat-card">
                <div class="stat-label">Otwarte retencje</div>
                <div class="stat-value"><?php echo formatMoney($retentionTotal); ?></div>
                <div class="stat-sub"><?php echo count($retentions); ?> aktywnych retencji</div>
            </div>
            <?php else: ?>
            <div class="stat-card">
                <div class="stat-label">Faktury łącznie</div>
                <div class="stat-value"><?php echo (int)$fin['invoices_count']; ?></div>
                <div class="stat-sub">wystawionych dokumentów</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- GŁÓWNY LAYOUT -->
        <div class="crm-layout">

            <!-- LEWA KOLUMNA -->
            <div>
                <!-- Dane firmy -->
                <div class="sidebar-card">
                    <div class="sc-header">Dane firmy</div>
                    <div class="sc-body">
                        <?php if ($investor['address']): ?>
                        <div class="sc-row">
                            <span class="sc-row-label">Adres</span>
                            <span class="sc-row-value"><?php echo e($investor['address']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="sc-row">
                            <span class="sc-row-label">E-mail</span>
                            <span class="sc-row-value">
                                <?php if ($investor['email']): ?>
                                    <a href="mailto:<?php echo e($investor['email']); ?>"><?php echo e($investor['email']); ?></a>
                                <?php else: ?>
                                    <span style="color:#d1d5db;">— brak —</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="sc-row">
                            <span class="sc-row-label">Telefon</span>
                            <span class="sc-row-value">
                                <?php if ($investor['phone']): ?>
                                    <a href="tel:<?php echo e($investor['phone']); ?>"><?php echo e($investor['phone']); ?></a>
                                <?php else: ?>
                                    <span style="color:#d1d5db;">— brak —</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($investor['contact_person']): ?>
                        <div class="sc-row">
                            <span class="sc-row-label">Kontakt</span>
                            <span class="sc-row-value"><?php echo e($investor['contact_person']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($investor['regon']): ?>
                        <div class="sc-row">
                            <span class="sc-row-label">REGON</span>
                            <span class="sc-row-value"><?php echo e($investor['regon']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($investor['krs']): ?>
                        <div class="sc-row">
                            <span class="sc-row-label">KRS/EDG</span>
                            <span class="sc-row-value"><?php echo e($investor['krs']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($investor['bank_name'] || $investor['bank_account']): ?>
                        <div class="sc-row">
                            <span class="sc-row-label">Bank</span>
                            <span class="sc-row-value">
                                <?php echo e($investor['bank_name']); ?>
                                <?php if ($investor['bank_account']): ?>
                                    <br><span style="font-family:monospace;font-size:11px;"><?php echo e($investor['bank_account']); ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="sc-row">
                            <span class="sc-row-label">Dodano</span>
                            <span class="sc-row-value"><?php echo formatDate($investor['created_at']); ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($investor['notes']): ?>
                <div class="sidebar-card">
                    <div class="sc-header">Notatka ogólna</div>
                    <div class="sc-body">
                        <p style="font-size:13px;line-height:1.6;color:#374151;"><?php echo nl2br(e($investor['notes'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($retentions)): ?>
                <div class="sidebar-card">
                    <div class="sc-header">Otwarte retencje</div>
                    <div style="padding:0;">
                        <?php foreach ($retentions as $ret):
                            $retBadgeMap = ['active' => 'badge-ret-active', 'due_soon' => 'badge-ret-due', 'overdue' => 'badge-ret-overdue'];
                        ?>
                        <div class="ret-item">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
                                <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>?search=<?php echo urlencode($ret['invoice_number']); ?>"
                                   style="font-size:13px;font-weight:600;color:var(--primary);text-decoration:none;">
                                    <?php echo e($ret['invoice_number']); ?>
                                </a>
                                <span class="badge <?php echo $retBadgeMap[$ret['status']] ?? ''; ?>" style="font-size:10px;padding:2px 8px;">
                                    <?php echo $retLabels[$ret['status']] ?? e($ret['status']); ?>
                                </span>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted);">
                                <?php echo formatMoney($ret['retention_amount']); ?>
                                <?php if ($ret['return_date']): ?> · zwrot: <?php echo formatDate($ret['return_date']); ?><?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- NOTATKI KONTAKTOWE -->
                <div class="sidebar-card" id="notatki">
                    <div class="sc-header">Notatki kontaktowe (<?php echo count($notes); ?>)</div>
                    <form method="POST" action="<?php echo url('investors.notes'); ?>" class="note-form">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="investor_id" value="<?php echo $investor['id']; ?>">
                        <textarea name="note" placeholder="Dodaj notatkę kontaktową..." required></textarea>
                        <div class="note-form-actions">
                            <button type="submit" class="btn-sm btn-sm-primary">Dodaj notatkę</button>
                        </div>
                    </form>
                    <?php if (count($notes) > 0): ?>
                        <?php foreach ($notes as $note): ?>
                        <div class="note-item">
                            <div class="note-text"><?php echo e($note['note']); ?></div>
                            <div class="note-meta">
                                <span><?php echo e($note['author_name'] ?? 'System'); ?> · <?php echo date('d.m.Y H:i', strtotime($note['created_at'])); ?></span>
                                <form method="POST" action="<?php echo url('investors.notes'); ?>" style="display:inline;" onsubmit="return confirm('Usunąć tę notatkę?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="investor_id" value="<?php echo $investor['id']; ?>">
                                    <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                                    <button type="submit" class="btn-sm btn-sm-danger">Usuń</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:18px 16px;text-align:center;color:var(--text-muted);font-size:13px;">Brak notatek.</div>
                    <?php endif; ?>
                </div>

                <!-- PRZYPOMNIENIA -->
                <div class="sidebar-card" id="przypomnienia">
                    <?php $activeReminders = count(array_filter($reminders, fn($r) => !$r['is_done'])); ?>
                    <div class="sc-header">Przypomnienia (<?php echo $activeReminders; ?> aktywnych)</div>
                    <form method="POST" action="<?php echo url('investors.reminders'); ?>" class="reminder-form">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="investor_id" value="<?php echo $investor['id']; ?>">
                        <div class="reminder-form-row">
                            <input type="text" name="title" placeholder="Tytuł przypomnienia *" required>
                            <input type="date" name="remind_at" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <textarea name="note" placeholder="Opcjonalna notatka..." rows="2"></textarea>
                        <div style="display:flex;justify-content:flex-end;margin-top:8px;">
                            <button type="submit" class="btn-sm btn-sm-primary">Dodaj przypomnienie</button>
                        </div>
                    </form>
                    <?php if (count($reminders) > 0): ?>
                        <?php foreach ($reminders as $rem):
                            $today   = date('Y-m-d');
                            $isDone  = (bool)$rem['is_done'];
                            $dcls    = $isDone ? 'done' : ($rem['remind_at'] < $today ? 'overdue' : ($rem['remind_at'] === $today ? 'today' : ''));
                        ?>
                        <div class="reminder-item <?php echo $isDone ? 'done' : ''; ?>">
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                                    <span class="reminder-date <?php echo $dcls; ?>">
                                        <?php echo date('d.m.Y', strtotime($rem['remind_at'])); ?>
                                        <?php if (!$isDone && $rem['remind_at'] < $today): ?> ⚠<?php endif; ?>
                                    </span>
                                </div>
                                <div class="reminder-title"><?php echo e($rem['title']); ?></div>
                                <?php if ($rem['note']): ?><div class="reminder-note"><?php echo e($rem['note']); ?></div><?php endif; ?>
                            </div>
                            <div class="reminder-actions">
                                <?php if (!$isDone): ?>
                                <form method="POST" action="<?php echo url('investors.reminders'); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="done">
                                    <input type="hidden" name="investor_id" value="<?php echo $investor['id']; ?>">
                                    <input type="hidden" name="reminder_id" value="<?php echo $rem['id']; ?>">
                                    <button type="submit" class="btn-sm btn-sm-done" title="Oznacz jako wykonane">✓</button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" action="<?php echo url('investors.reminders'); ?>" style="display:inline;" onsubmit="return confirm('Usunąć przypomnienie?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="investor_id" value="<?php echo $investor['id']; ?>">
                                    <input type="hidden" name="reminder_id" value="<?php echo $rem['id']; ?>">
                                    <button type="submit" class="btn-sm btn-sm-danger" title="Usuń">✕</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding:18px 16px;text-align:center;color:var(--text-muted);font-size:13px;">Brak przypomnień.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PRAWA KOLUMNA -->
            <div>

                <!-- PROJEKTY -->
                <div class="section-card">
                    <div class="section-header">
                        <span class="section-title">Projekty</span>
                        <span class="section-count"><?php echo count($projects); ?></span>
                    </div>
                    <?php if (count($projects) > 0): ?>
                    <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Nazwa projektu</th>
                                <th>Status</th>
                                <th style="text-align:right;">Kwota umowy</th>
                                <th style="text-align:right;">Przychody</th>
                                <th>Data startu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $proj):
                                $pStatus = $proj['status'] ?? 'planned';
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo url('projekty.show', ['id' => $proj['id']]); ?>" style="font-weight:600;color:var(--primary);text-decoration:none;">
                                        <?php echo e($proj['name']); ?>
                                    </a>
                                    <?php if ($proj['is_internal']): ?><span style="font-size:11px;color:var(--text-muted);margin-left:4px;">(wewn.)</span><?php endif; ?>
                                    <?php if ($proj['archived_at']): ?><span style="font-size:11px;color:var(--text-muted);margin-left:4px;">(archiw.)</span><?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size:12px;font-weight:600;color:<?php echo $statusColors[$pStatus] ?? '#6b7280'; ?>;">
                                        <?php echo $statusLabels[$pStatus] ?? e($pStatus); ?>
                                    </span>
                                </td>
                                <td style="text-align:right;"><?php echo $proj['contract_amount'] ? formatMoney($proj['contract_amount']) : '<span style="color:var(--text-muted)">—</span>'; ?></td>
                                <td style="text-align:right;font-weight:600;"><?php echo $proj['revenue_sum'] > 0 ? formatMoney($proj['revenue_sum']) : '<span style="color:var(--text-muted)">—</span>'; ?></td>
                                <td style="color:var(--text-muted);font-size:12px;"><?php echo $proj['start_date'] ? formatDate($proj['start_date']) : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php else: ?>
                    <div class="no-data">Brak projektów powiązanych z tym kontrahentem.</div>
                    <?php endif; ?>
                </div>

                <!-- FAKTURY SPRZEDAŻOWE -->
                <div class="section-card">
                    <div class="section-header">
                        <span class="section-title">Faktury sprzedażowe</span>
                        <span class="section-count"><?php echo count($invoices); ?></span>
                    </div>
                    <?php if (count($invoices) > 0): ?>
                    <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Numer</th>
                                <th>Data wystawienia</th>
                                <th>Termin płatności</th>
                                <th style="text-align:right;">Brutto</th>
                                <th>Status</th>
                                <th>Retencja</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $inv):
                                $iStatus = $inv['status'] ?? 'draft';
                                $isOverdue = (!$inv['payment_date'] && $inv['due_date'] && $inv['due_date'] < date('Y-m-d') && $iStatus !== 'paid');
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>?search=<?php echo urlencode($inv['invoice_number']); ?>"
                                       style="font-weight:600;color:var(--primary);text-decoration:none;">
                                        <?php echo e($inv['invoice_number']); ?>
                                    </a>
                                </td>
                                <td style="font-size:12px;"><?php echo formatDate($inv['issue_date']); ?></td>
                                <td style="font-size:12px;<?php echo $isOverdue ? 'color:#ef4444;font-weight:600;' : ''; ?>">
                                    <?php echo $inv['due_date'] ? formatDate($inv['due_date']) : '—'; ?>
                                    <?php if ($isOverdue): ?> <span style="font-size:10px;">⚠</span><?php endif; ?>
                                </td>
                                <td style="text-align:right;font-weight:600;"><?php echo formatMoney($inv['amount_gross']); ?></td>
                                <td><span class="badge badge-<?php echo $iStatus; ?>"><?php echo $invStatusLabels[$iStatus] ?? e($iStatus); ?></span></td>
                                <td>
                                    <?php if ($inv['ret_status'] && $inv['ret_status'] !== 'settled'): ?>
                                        <span class="badge badge-ret-<?php echo $inv['ret_status'] === 'due_soon' ? 'due' : e($inv['ret_status']); ?>" style="font-size:10px;padding:2px 8px;">
                                            <?php echo formatMoney($inv['retention_amount']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php else: ?>
                    <div class="no-data">Brak faktur sprzedażowych dla tego kontrahenta.</div>
                    <?php endif; ?>
                </div>

            </div><!-- /prawa kolumna -->
        </div><!-- /crm-layout -->
    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>
</body>
</html>
