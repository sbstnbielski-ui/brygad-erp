<?php
/**
 * BRYGAD ERP v3.0 - Baza Kontrahentów / Partnerów Zakupowych
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

$view = $_GET['view'] ?? 'clients';
if (!in_array($view, ['clients', 'suppliers'], true)) {
    $view = 'clients';
}

$search = trim((string)($_GET['search'] ?? ''));
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$workflow_filter = $_GET['workflow'] ?? 'all';
$year_filter = (int)($_GET['year'] ?? 0);

if (!isset($_SESSION['investors_suppliers_hidden']) || !is_array($_SESSION['investors_suppliers_hidden'])) {
    $_SESSION['investors_suppliers_hidden'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['view'] ?? '') === 'suppliers') {
    if (!csrfVerify()) {
        header('Location: ' . url('investors', [
            'view' => 'suppliers',
            'workflow' => $_POST['workflow'] ?? 'all',
            'year' => (int)($_POST['year'] ?? 0),
            'search' => trim((string)($_POST['search'] ?? '')),
            'error' => 'csrf',
        ]));
        exit;
    }

    $supplierAction = trim((string)($_POST['supplier_action'] ?? ''));

    if ($supplierAction === 'hide_supplier') {
        $supplierName = trim((string)($_POST['supplier_name'] ?? ''));
        $supplierNip = trim((string)($_POST['supplier_nip'] ?? ''));

        if ($supplierName !== '') {
            $supplierKey = mb_strtolower($supplierName . '|' . $supplierNip);
            $_SESSION['investors_suppliers_hidden'][$supplierKey] = [
                'name' => $supplierName,
                'nip' => $supplierNip,
                'at' => date('Y-m-d H:i:s'),
            ];
        }

        header('Location: ' . url('investors', [
            'view' => 'suppliers',
            'workflow' => $_POST['workflow'] ?? 'all',
            'year' => (int)($_POST['year'] ?? 0),
            'search' => trim((string)($_POST['search'] ?? '')),
        ]));
        exit;
    }

    if ($supplierAction === 'unhide_all') {
        $_SESSION['investors_suppliers_hidden'] = [];

        header('Location: ' . url('investors', [
            'view' => 'suppliers',
            'workflow' => $_POST['workflow'] ?? 'all',
            'year' => (int)($_POST['year'] ?? 0),
            'search' => trim((string)($_POST['search'] ?? '')),
        ]));
        exit;
    }
}

$hiddenSuppliers = $_SESSION['investors_suppliers_hidden'];

if ($view === 'suppliers' && isset($_GET['unhide_all']) && $_GET['unhide_all'] === '1') {
    $_SESSION['investors_suppliers_hidden'] = [];
    header('Location: ' . url('investors', [
        'view' => 'suppliers',
        'workflow' => $workflow_filter,
        'year' => $year_filter,
        'search' => $search,
    ]));
    exit;
}

$clients = [];
$clientStats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'b2b' => 0,
    'b2c' => 0,
    'total_projects' => 0,
    'total_revenue' => 0,
];

$partners = [];
$partnerStats = [
    'partners_count' => 0,
    'invoices_count' => 0,
    'total_net' => 0,
    'total_gross' => 0,
    'paid_invoices' => 0,
    'accepted_invoices' => 0,
    'top_partner_name' => '',
];
$supplierYears = [];

if ($view === 'clients') {
    $sql = "
        SELECT
            i.*,
            COUNT(DISTINCT p.id) AS projects_count,
            COUNT(DISTINCT inv.id) AS invoices_count,
            COALESCE(SUM(CASE WHEN inv.status IN ('issued','paid') THEN inv.amount_gross ELSE 0 END), 0) AS total_revenue,
            MAX(inv.issue_date) AS last_invoice_date,
            (
                SELECT inv2.amount_gross
                FROM invoices_sale inv2
                WHERE inv2.client_id = i.id AND inv2.status IN ('issued','paid')
                ORDER BY inv2.issue_date DESC
                LIMIT 1
            ) AS last_invoice_amount
        FROM investors i
        LEFT JOIN projects p ON p.investor_id = i.id
        LEFT JOIN invoices_sale inv ON inv.client_id = i.id
        WHERE 1=1
    ";
    $params = [];

    if ($status_filter === 'active') {
        $sql .= " AND i.is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $sql .= " AND i.is_active = 0";
    }

    if ($type_filter === 'business') {
        $sql .= " AND i.type = 'business'";
    } elseif ($type_filter === 'private') {
        $sql .= " AND i.type = 'private'";
    }

    if ($search !== '') {
        $sql .= " AND (i.name LIKE :search OR i.nip LIKE :search OR i.contact_person LIKE :search OR i.email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " GROUP BY i.id ORDER BY i.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $clientStatsRow = $pdo->query("SELECT
            COUNT(*) as total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
            SUM(CASE WHEN type = 'business' THEN 1 ELSE 0 END) as b2b,
            SUM(CASE WHEN type = 'private' THEN 1 ELSE 0 END) as b2c
        FROM investors")->fetch(PDO::FETCH_ASSOC);

    $projectsStatsRow = $pdo->query("SELECT COUNT(DISTINCT p.id) as total_projects
        FROM projects p
        INNER JOIN investors i ON p.investor_id = i.id
        WHERE i.is_active = 1")->fetch(PDO::FETCH_ASSOC);

    $invoicesStatsRow = $pdo->query("SELECT
            COALESCE(SUM(amount_gross), 0) as total_revenue
        FROM invoices_sale
        WHERE status IN ('issued', 'paid', 'partially_paid')")->fetch(PDO::FETCH_ASSOC);

    $clientStats = [
        'total' => (int)($clientStatsRow['total'] ?? 0),
        'active' => (int)($clientStatsRow['active'] ?? 0),
        'inactive' => (int)($clientStatsRow['inactive'] ?? 0),
        'b2b' => (int)($clientStatsRow['b2b'] ?? 0),
        'b2c' => (int)($clientStatsRow['b2c'] ?? 0),
        'total_projects' => (int)($projectsStatsRow['total_projects'] ?? 0),
        'total_revenue' => (float)($invoicesStatsRow['total_revenue'] ?? 0),
    ];
} else {
    $allowedWorkflowFilters = ['all', 'new', 'assigned', 'accepted', 'rejected', 'archived'];
    if (!in_array($workflow_filter, $allowedWorkflowFilters, true)) {
        $workflow_filter = 'all';
    }

    $yearsStmt = $pdo->query("SELECT DISTINCT YEAR(COALESCE(issue_date, imported_at)) AS y
        FROM fakturownia_cost_invoices
        WHERE supplier_name IS NOT NULL AND supplier_name != ''
          AND COALESCE(issue_date, imported_at) IS NOT NULL
        ORDER BY y DESC");
    $supplierYears = $yearsStmt ? ($yearsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];

    $partnerSql = "
        SELECT
            ci.supplier_name,
            ci.supplier_nip,
            COUNT(*) AS invoices_count,
            COALESCE(SUM(ci.amount_net), 0) AS total_net,
            COALESCE(SUM(ci.amount_gross), 0) AS total_gross,
            MAX(ci.issue_date) AS last_issue_date,
            SUM(CASE WHEN ci.payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_invoices,
            SUM(CASE WHEN ci.workflow_status = 'accepted' THEN 1 ELSE 0 END) AS accepted_invoices
        FROM fakturownia_cost_invoices ci
        WHERE ci.supplier_name IS NOT NULL
          AND ci.supplier_name != ''
    ";
    $partnerParams = [];

    if ($workflow_filter !== 'all') {
        $partnerSql .= " AND ci.workflow_status = :workflow_status";
        $partnerParams[':workflow_status'] = $workflow_filter;
    }

    if ($year_filter > 0) {
        $partnerSql .= " AND YEAR(COALESCE(ci.issue_date, ci.imported_at)) = :issue_year";
        $partnerParams[':issue_year'] = $year_filter;
    }

    if ($search !== '') {
        $partnerSql .= " AND (
            ci.supplier_name LIKE :search
            OR ci.supplier_nip LIKE :search
            OR ci.invoice_number LIKE :search
            OR ci.ksef_number LIKE :search
        )";
        $partnerParams[':search'] = '%' . $search . '%';
    }

    $partnerSql .= "
        GROUP BY ci.supplier_name, ci.supplier_nip
        ORDER BY total_gross DESC, ci.supplier_name ASC
    ";

    $partnerStmt = $pdo->prepare($partnerSql);
    $partnerStmt->execute($partnerParams);
    $partnersRaw = $partnerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($partnersRaw as $partner) {
        $supplierKey = mb_strtolower(trim((string)$partner['supplier_name']) . '|' . trim((string)($partner['supplier_nip'] ?? '')));
        if (isset($hiddenSuppliers[$supplierKey])) {
            continue;
        }
        $partners[] = $partner;
    }

    $partnerStats['partners_count'] = count($partners);
    foreach ($partners as $index => $partner) {
        $partnerStats['invoices_count'] += (int)$partner['invoices_count'];
        $partnerStats['total_net'] += (float)$partner['total_net'];
        $partnerStats['total_gross'] += (float)$partner['total_gross'];
        $partnerStats['paid_invoices'] += (int)$partner['paid_invoices'];
        $partnerStats['accepted_invoices'] += (int)$partner['accepted_invoices'];

        if ($index === 0) {
            $partnerStats['top_partner_name'] = (string)$partner['supplier_name'];
        }
    }
}

$paidShare = $partnerStats['invoices_count'] > 0
    ? round(($partnerStats['paid_invoices'] / $partnerStats['invoices_count']) * 100, 1)
    : 0;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - <?php echo $view === 'clients' ? 'Baza Kontrahentów' : 'Partnerzy Zakupowi'; ?></title>
    <style>
        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --bg-body:           #f5f7fa;
            --border:            #e5e7eb;
            --border-light:      #d1d5db;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); padding-bottom: 40px; }
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }

        .hero {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0f172a 100%);
            color: #fff;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
        }
        .hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero-breadcrumb a:hover { text-decoration: underline; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        .hero-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; align-self: center; }
        .btn-hero-primary {
            background: #fff;
            color: #1e3a8a;
            border: 1px solid #fff;
            font-weight: 700;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-hero-primary:hover { background: #e0e7ff; }

        .view-switch {
            display: inline-flex;
            gap: 4px;
            padding: 4px;
            border-radius: 9px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .view-switch-link {
            padding: 7px 12px;
            border-radius: 7px;
            text-decoration: none;
            color: #dbeafe;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.2px;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .view-switch-link:hover { background: rgba(255,255,255,0.12); color: #fff; }
        .view-switch-link.active { background: #fff; color: #1e3a8a; }

        .stats-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 16px; margin-bottom: 22px; }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 600px)  { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        .stat-card {
            background: white;
            padding: 18px 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            transition: all 0.2s;
            border: 2px solid transparent;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .stat-card:hover { border-color: var(--primary); transform: translateY(-2px); }
        .stat-card.active { border-color: var(--primary); background: #f0f4ff; }
        .stat-label { font-size: 12px; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .stat-value { font-size: 26px; font-weight: 700; color: #667eea; }
        .stat-value.small { font-size: 18px; }

        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 22px; overflow: hidden; }
        .spx-filter-bar { padding: 12px 20px; background: white; border-bottom: 1px solid var(--border-light); display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 140px; }
        .spx-filter-group label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .spx-filter-group select,
        .spx-filter-group input[type="text"] { padding: 0 8px; height: 38px; border: 1px solid var(--border-light); border-radius: 6px; font-size: 13px; background: white; width: 100%; }
        .spx-filter-group select:focus,
        .spx-filter-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(102,126,234,0.1); }
        .spx-filter-group.fg-search { flex: 2; }

        .btn { padding: 0 20px; height: 38px; border-radius: 6px; text-decoration: none; font-weight: 600; cursor: pointer; border: none; font-size: 13px; transition: all 0.2s; display: inline-flex; align-items: center; white-space: nowrap; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102,126,234,0.4); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }

        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f1f3f5; }
        th { padding: 10px 14px; text-align: left; font-weight: 600; color: var(--text-muted); border: 1px solid var(--border); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; user-select: none; }
        th.sortable:hover { color: var(--primary); }
        th .sort-icon { opacity: 0.3; margin-left: 4px; }
        th.asc .sort-icon::after { content: ' ↑'; opacity: 1; }
        th.desc .sort-icon::after { content: ' ↓'; opacity: 1; }
        td { padding: 10px 14px; border: 1px solid var(--border); font-size: 13px; vertical-align: middle; }
        tbody tr:nth-child(odd)  { background: #ffffff; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody tr:hover { background: #e0f2fe; }

        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge-active   { background: #d4edda; color: #155724; }
        .badge-inactive { background: #e2e3e5; color: #383d41; }
        .type-badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; }
        .type-business { background: #dbeafe; color: #1e40af; }
        .type-private  { background: #fce7f3; color: #9d174d; }

        .action-buttons { display: flex; gap: 6px; justify-content: center; flex-wrap: nowrap; align-items: center; }
        .action-btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 4px 10px; height: 26px; border-radius: 4px; text-decoration: none;
            font-size: 11px; font-weight: 600; transition: all 0.2s; border: 1px solid;
            background: white; white-space: nowrap; cursor: pointer;
        }
        .action-btn:hover { transform: translateY(-1px); }
        .action-btn-view   { color: #2563eb; border-color: #2563eb; }
        .action-btn-view:hover   { background: #2563eb; color: white; }
        .action-btn-edit   { color: #059669; border-color: #059669; }
        .action-btn-edit:hover   { background: #059669; color: white; }
        .action-btn-delete { color: #dc2626; border-color: #dc2626; }
        .action-btn-delete:hover { background: #dc2626; color: white; }
        .delete-form { display: inline; margin: 0; }

        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; border-left: 4px solid #28a745; color: #155724; }
        .alert-error   { background: #f8d7da; border-left: 4px solid #dc3545; color: #721c24; }
        .no-data { padding: 60px 20px; text-align: center; color: var(--text-muted); font-size: 15px; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 13px; }
    </style>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

<div class="container">
    <div class="hero">
        <div>
            <div class="hero-breadcrumb">
                <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                <?php echo $view === 'clients' ? 'Baza Kontrahentów' : 'Partnerzy Zakupowi'; ?>
            </div>
            <h1><?php echo $view === 'clients' ? 'Baza Kontrahentów' : 'Partnerzy Zakupowi'; ?></h1>
            <p><?php echo $view === 'clients' ? 'Kontrahenci, faktury sprzedażowe i projekty' : 'Dostawcy i analiza zakupów z faktur kosztowych'; ?></p>
        </div>
        <div class="hero-actions">
            <div class="view-switch">
                <a href="<?php echo url('investors', ['view' => 'clients']); ?>" class="view-switch-link <?php echo $view === 'clients' ? 'active' : ''; ?>">Klienci</a>
                <a href="<?php echo url('investors', ['view' => 'suppliers']); ?>" class="view-switch-link <?php echo $view === 'suppliers' ? 'active' : ''; ?>">Partnerzy zakupowi</a>
            </div>
            <?php if ($view === 'clients'): ?>
                <a href="<?php echo url('investors.create'); ?>" class="btn-hero-primary">+ Dodaj kontrahenta</a>
            <?php else: ?>
                <a href="<?php echo url('finanse.fakturownia-cost-inbox'); ?>" class="btn-hero-primary">Inbox kosztów</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success === 'created'): ?>
        <div class="alert alert-success">Kontrahent został dodany.</div>
    <?php elseif ($success === 'updated'): ?>
        <div class="alert alert-success">Dane kontrahenta zostały zaktualizowane.</div>
    <?php elseif ($success === 'deleted'): ?>
        <div class="alert alert-success">Kontrahent został usunięty.</div>
    <?php elseif ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($view === 'clients'): ?>
        <div class="stats-grid">
            <a href="<?php echo url('investors', ['view' => 'clients', 'status' => 'all', 'type' => $type_filter, 'search' => $search]); ?>" class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                <div class="stat-label">Wszyscy</div>
                <div class="stat-value"><?php echo (int)$clientStats['total']; ?></div>
            </a>
            <a href="<?php echo url('investors', ['view' => 'clients', 'status' => 'active', 'type' => $type_filter, 'search' => $search]); ?>" class="stat-card <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                <div class="stat-label">Aktywni</div>
                <div class="stat-value"><?php echo (int)$clientStats['active']; ?></div>
            </a>
            <a href="<?php echo url('investors', ['view' => 'clients', 'status' => $status_filter, 'type' => 'business', 'search' => $search]); ?>" class="stat-card <?php echo $type_filter === 'business' ? 'active' : ''; ?>">
                <div class="stat-label">B2B (Firmy)</div>
                <div class="stat-value"><?php echo (int)$clientStats['b2b']; ?></div>
            </a>
            <a href="<?php echo url('investors', ['view' => 'clients', 'status' => $status_filter, 'type' => 'private', 'search' => $search]); ?>" class="stat-card <?php echo $type_filter === 'private' ? 'active' : ''; ?>">
                <div class="stat-label">B2C (Osoby)</div>
                <div class="stat-value"><?php echo (int)$clientStats['b2c']; ?></div>
            </a>
            <a href="<?php echo url('projekty'); ?>" class="stat-card">
                <div class="stat-label">Projekty aktywne</div>
                <div class="stat-value"><?php echo (int)$clientStats['total_projects']; ?></div>
            </a>
            <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>" class="stat-card">
                <div class="stat-label">Łączny przychód</div>
                <div class="stat-value small"><?php echo formatMoney($clientStats['total_revenue']); ?></div>
            </a>
        </div>

        <div class="card">
            <form method="GET" action="" class="spx-filter-bar">
                <input type="hidden" name="view" value="clients">
                <div class="spx-filter-group">
                    <label>Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Wszyscy</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Aktywni</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Nieaktywni</option>
                    </select>
                </div>
                <div class="spx-filter-group">
                    <label>Typ</label>
                    <select name="type" onchange="this.form.submit()">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="business" <?php echo $type_filter === 'business' ? 'selected' : ''; ?>>B2B (Firmy)</option>
                        <option value="private" <?php echo $type_filter === 'private' ? 'selected' : ''; ?>>B2C (Osoby)</option>
                    </select>
                </div>
                <div class="spx-filter-group fg-search">
                    <label>Szukaj</label>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Nazwa, NIP, osoba kontaktowa, email...">
                </div>
                <button type="submit" class="btn btn-primary">Filtruj</button>
                <?php if ($status_filter !== 'all' || $search !== '' || $type_filter !== 'all'): ?>
                    <a href="<?php echo url('investors', ['view' => 'clients']); ?>" class="btn btn-secondary">Wyczyść</a>
                <?php endif; ?>
            </form>

            <div class="table-scroll">
                <?php if (!empty($clients)): ?>
                    <table class="js-sort-table">
                        <thead>
                        <tr>
                            <th class="sortable" data-sort="string">Typ <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="string">Nazwa <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="string">NIP <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="string">Osoba kontaktowa <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="string">Telefon <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="number" style="text-align:center;">Projekty <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="number" style="text-align:center;">Faktury <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="number" style="text-align:right;">Łączny przychód <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="string">Ostatnia faktura <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="string">Status <span class="sort-icon"></span></th>
                            <th>Akcje</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clients as $client): ?>
                            <?php
                            $clientType = $client['type'] ?? 'business';
                            $typeLabel = $clientType === 'private' ? 'B2C' : 'B2B';
                            $typeClass = $clientType === 'private' ? 'type-private' : 'type-business';
                            ?>
                            <tr>
                                <td><span class="type-badge <?php echo $typeClass; ?>"><?php echo $typeLabel; ?></span></td>
                                <td><a href="<?php echo url('investors.show', ['id' => $client['id']]); ?>" style="font-weight:600;color:var(--primary);text-decoration:none;"><?php echo e($client['name']); ?></a></td>
                                <td><?php echo e($client['nip'] ?: '—'); ?></td>
                                <td><?php echo e($client['contact_person'] ?: '—'); ?></td>
                                <td><?php echo e($client['phone'] ?: '—'); ?></td>
                                <td style="text-align:center;"><?php echo (int)$client['projects_count'] ?: '—'; ?></td>
                                <td style="text-align:center;"><?php echo (int)$client['invoices_count'] ?: '—'; ?></td>
                                <td style="text-align:right;font-weight:600;"><?php echo (float)$client['total_revenue'] > 0 ? formatMoney($client['total_revenue']) : '<span style="color:var(--text-muted)">—</span>'; ?></td>
                                <td style="font-size:12px;color:var(--text-muted);">
                                    <?php if (!empty($client['last_invoice_date'])): ?>
                                        <span style="color:var(--text-main);"><?php echo formatDate($client['last_invoice_date']); ?></span>
                                        <?php if (!empty($client['last_invoice_amount'])): ?>
                                            <br><?php echo formatMoney($client['last_invoice_amount']); ?>
                                        <?php endif; ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><span class="badge badge-<?php echo (int)$client['is_active'] === 1 ? 'active' : 'inactive'; ?>"><?php echo (int)$client['is_active'] === 1 ? 'Aktywny' : 'Nieaktywny'; ?></span></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="<?php echo url('investors.show', ['id' => $client['id']]); ?>" class="action-btn action-btn-view">Karta</a>
                                        <a href="<?php echo url('investors.edit', ['id' => $client['id']]); ?>" class="action-btn action-btn-edit">Edytuj</a>
                                        <form method="POST" action="<?php echo url('investors.delete'); ?>" class="delete-form" onsubmit="return confirm('Usunąć kontrahenta \'<?php echo e($client['name']); ?>\'?\n\nUWAGA: Usunięcie może wpłynąć na powiązane projekty i faktury.');">
                                            <input type="hidden" name="id" value="<?php echo (int)$client['id']; ?>">
                                            <button type="submit" class="action-btn action-btn-delete">Usuń</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">Brak kontrahentów spełniających kryteria filtrowania.</div>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-label">Partnerzy</div><div class="stat-value"><?php echo (int)$partnerStats['partners_count']; ?></div></div>
            <div class="stat-card"><div class="stat-label">Faktury kosztowe</div><div class="stat-value"><?php echo (int)$partnerStats['invoices_count']; ?></div></div>
            <div class="stat-card"><div class="stat-label">Suma netto</div><div class="stat-value small"><?php echo formatMoney($partnerStats['total_net']); ?></div></div>
            <div class="stat-card"><div class="stat-label">Suma brutto</div><div class="stat-value small"><?php echo formatMoney($partnerStats['total_gross']); ?></div></div>
            <div class="stat-card"><div class="stat-label">Opłacone</div><div class="stat-value"><?php echo number_format($paidShare, 1, ',', ''); ?>%</div></div>
            <div class="stat-card"><div class="stat-label">Top partner</div><div class="stat-value small"><?php echo $partnerStats['top_partner_name'] !== '' ? e($partnerStats['top_partner_name']) : '—'; ?></div></div>
        </div>

        <div class="card">
            <form method="GET" action="" class="spx-filter-bar">
                <input type="hidden" name="view" value="suppliers">
                <div class="spx-filter-group">
                    <label>Status workflow</label>
                    <select name="workflow" onchange="this.form.submit()">
                        <option value="all" <?php echo $workflow_filter === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                        <option value="new" <?php echo $workflow_filter === 'new' ? 'selected' : ''; ?>>Nowe</option>
                        <option value="assigned" <?php echo $workflow_filter === 'assigned' ? 'selected' : ''; ?>>Przypisane</option>
                        <option value="accepted" <?php echo $workflow_filter === 'accepted' ? 'selected' : ''; ?>>Zatwierdzone</option>
                        <option value="rejected" <?php echo $workflow_filter === 'rejected' ? 'selected' : ''; ?>>Odrzucone</option>
                        <option value="archived" <?php echo $workflow_filter === 'archived' ? 'selected' : ''; ?>>Archiwum</option>
                    </select>
                </div>
                <div class="spx-filter-group">
                    <label>Rok</label>
                    <select name="year" onchange="this.form.submit()">
                        <option value="0" <?php echo $year_filter === 0 ? 'selected' : ''; ?>>Wszystkie lata</option>
                        <?php foreach ($supplierYears as $year): ?>
                            <?php $yearInt = (int)$year; ?>
                            <option value="<?php echo $yearInt; ?>" <?php echo $year_filter === $yearInt ? 'selected' : ''; ?>><?php echo $yearInt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group fg-search">
                    <label>Szukaj</label>
                    <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Dostawca, NIP, numer faktury, KSeF...">
                </div>
                <button type="submit" class="btn btn-primary">Filtruj</button>
                <?php if (!empty($hiddenSuppliers)): ?>
                    <a href="<?php echo url('investors', [
                        'view' => 'suppliers',
                        'workflow' => $workflow_filter,
                        'year' => $year_filter,
                        'search' => $search,
                        'unhide_all' => 1,
                    ]); ?>" class="btn btn-secondary">Przywróć ukryte (<?php echo count($hiddenSuppliers); ?>)</a>
                <?php endif; ?>
                <?php if ($workflow_filter !== 'all' || $search !== '' || $year_filter > 0): ?>
                    <a href="<?php echo url('investors', ['view' => 'suppliers']); ?>" class="btn btn-secondary">Wyczyść</a>
                <?php endif; ?>
            </form>

            <div class="table-scroll">
                <?php if (!empty($partners)): ?>
                    <table class="js-sort-table">
                        <thead>
                        <tr>
                            <th class="sortable" data-sort="string">Partner <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="string">NIP <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="number" style="text-align:center;">Faktury <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="number" style="text-align:right;">Suma netto <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="number" style="text-align:right;">Suma brutto <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="string">Ostatnia faktura <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="number" style="text-align:center;">Opłacone <span class="sort-icon"></span></th>
                            <th class="sortable" data-sort="number" style="text-align:center;">Zatwierdzone <span class="sort-icon"></span></th>
                            <th>Akcje</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($partners as $partner): ?>
                            <?php
                            $supplierName = (string)$partner['supplier_name'];
                            $supplierNip = (string)($partner['supplier_nip'] ?? '');
                            $invoicesCount = (int)$partner['invoices_count'];
                            $paidCount = (int)$partner['paid_invoices'];
                            $acceptedCount = (int)$partner['accepted_invoices'];
                            ?>
                            <tr>
                                <td style="font-weight:600;"><?php echo e($supplierName); ?></td>
                                <td><?php echo e($supplierNip !== '' ? $supplierNip : '—'); ?></td>
                                <td style="text-align:center;"><?php echo $invoicesCount; ?></td>
                                <td style="text-align:right;font-weight:600;"><?php echo formatMoney($partner['total_net']); ?></td>
                                <td style="text-align:right;font-weight:700;"><?php echo formatMoney($partner['total_gross']); ?></td>
                                <td><?php echo !empty($partner['last_issue_date']) ? formatDate($partner['last_issue_date']) : '—'; ?></td>
                                <td style="text-align:center;"><?php echo $paidCount; ?> / <?php echo $invoicesCount; ?></td>
                                <td style="text-align:center;"><?php echo $acceptedCount; ?> / <?php echo $invoicesCount; ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="<?php echo url('investors.supplier-show', ['supplier_name' => $supplierName, 'supplier_nip' => $supplierNip]); ?>" class="action-btn action-btn-view">Karta</a>
                                        <a href="<?php echo url('finanse.fakturownia-cost-inbox', ['supplier' => $supplierName]); ?>" class="action-btn action-btn-edit">Edytuj</a>
                                        <form method="POST" action="" class="delete-form" onsubmit="return confirm('Ukryć partnera z listy? Nie usuwa to faktur kosztowych.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                            <input type="hidden" name="view" value="suppliers">
                                            <input type="hidden" name="workflow" value="<?php echo e($workflow_filter); ?>">
                                            <input type="hidden" name="year" value="<?php echo (int)$year_filter; ?>">
                                            <input type="hidden" name="search" value="<?php echo e($search); ?>">
                                            <input type="hidden" name="supplier_action" value="hide_supplier">
                                            <input type="hidden" name="supplier_name" value="<?php echo e($supplierName); ?>">
                                            <input type="hidden" name="supplier_nip" value="<?php echo e($supplierNip); ?>">
                                            <button type="submit" class="action-btn action-btn-delete">Usuń</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">Brak partnerów zakupowych spełniających kryteria filtrowania.</div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>
</div>

<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.js-sort-table').forEach(function(table) {
        const headers = table.querySelectorAll('th.sortable');
        const tbody = table.querySelector('tbody');
        if (!tbody || headers.length === 0) return;

        headers.forEach(function(header, columnIndex) {
            header.addEventListener('click', function() {
                const sortType = this.dataset.sort;
                const isAsc = this.classList.contains('asc');

                headers.forEach(function(h) { h.classList.remove('asc', 'desc'); });
                this.classList.add(isAsc ? 'desc' : 'asc');
                const direction = isAsc ? -1 : 1;

                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort(function(a, b) {
                    let aVal = (a.cells[columnIndex] ? a.cells[columnIndex].textContent : '').trim();
                    let bVal = (b.cells[columnIndex] ? b.cells[columnIndex].textContent : '').trim();

                    if (sortType === 'number') {
                        const aNum = parseFloat(aVal.replace(/[^\d,.-]/g, '').replace(',', '.')) || 0;
                        const bNum = parseFloat(bVal.replace(/[^\d,.-]/g, '').replace(',', '.')) || 0;
                        return (aNum - bNum) * direction;
                    }

                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                    if (aVal < bVal) return -1 * direction;
                    if (aVal > bVal) return 1 * direction;
                    return 0;
                });

                rows.forEach(function(row) { tbody.appendChild(row); });
            });
        });
    });
});
</script>
</body>
</html>
