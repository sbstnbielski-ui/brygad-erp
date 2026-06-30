<?php
/**
 * BRYGAD ERP v3.0 - Karta partnera zakupowego
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

$supplierName = trim((string)($_GET['supplier_name'] ?? ''));
$supplierNip = trim((string)($_GET['supplier_nip'] ?? ''));

if ($supplierName === '') {
    header('Location: ' . url('investors', ['view' => 'suppliers']));
    exit;
}

$where = 'ci.supplier_name = :supplier_name';
$params = [':supplier_name' => $supplierName];

if ($supplierNip !== '') {
    $where .= ' AND ci.supplier_nip = :supplier_nip';
    $params[':supplier_nip'] = $supplierNip;
}

$statsStmt = $pdo->prepare("SELECT
        COUNT(*) AS invoices_count,
        COALESCE(SUM(ci.amount_net), 0) AS total_net,
        COALESCE(SUM(ci.amount_gross), 0) AS total_gross,
        SUM(CASE WHEN ci.payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_invoices,
        SUM(CASE WHEN ci.workflow_status = 'accepted' THEN 1 ELSE 0 END) AS accepted_invoices,
        MIN(ci.issue_date) AS first_issue_date,
        MAX(ci.issue_date) AS last_issue_date
    FROM fakturownia_cost_invoices ci
    WHERE {$where}");
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$stats || (int)$stats['invoices_count'] === 0) {
    header('Location: ' . url('investors', ['view' => 'suppliers', 'error' => 'not_found']));
    exit;
}

$invoicesStmt = $pdo->prepare("SELECT
        ci.id,
        ci.invoice_number,
        ci.ksef_number,
        ci.issue_date,
        ci.due_date,
        ci.amount_net,
        ci.amount_gross,
        ci.payment_status,
        ci.workflow_status
    FROM fakturownia_cost_invoices ci
    WHERE {$where}
    ORDER BY COALESCE(ci.issue_date, DATE(ci.imported_at)) DESC, ci.id DESC
    LIMIT 60");
$invoicesStmt->execute($params);
$invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$projectsStmt = $pdo->prepare("SELECT
        COALESCE(p.name, '(Bez projektu)') AS project_name,
        COALESCE(SUM(a.amount_net), 0) AS allocated_net,
        COUNT(DISTINCT a.cost_invoice_id) AS docs_count
    FROM fakturownia_cost_allocations a
    INNER JOIN fakturownia_cost_invoices ci ON ci.id = a.cost_invoice_id
    LEFT JOIN projects p ON p.id = a.project_id
    WHERE {$where}
    GROUP BY a.project_id, p.name
    ORDER BY allocated_net DESC
    LIMIT 12");
$projectsStmt->execute($params);
$projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$paymentLabels = [
    'paid' => 'Opłacona',
    'partial' => 'Częściowo',
    'unpaid' => 'Nieopłacona',
    'unknown' => 'Brak danych',
];

$workflowLabels = [
    'new' => 'Nowa',
    'assigned' => 'Przypisana',
    'accepted' => 'Zatwierdzona',
    'rejected' => 'Odrzucona',
    'archived' => 'Archiwum',
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Karta partnera zakupowego</title>
    <style>
        :root {
            --primary: #667eea;
            --primary-blue: #1e3a8a;
            --bg-body: #f5f7fa;
            --border: #e5e7eb;
            --border-light: #d1d5db;
            --text-main: #1f2937;
            --text-muted: #6b7280;
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
        .btn-hero-primary,
        .btn-hero-secondary {
            font-size: 13px;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .btn-hero-primary { background: #fff; color: #1e3a8a; border-color: #fff; font-weight: 700; }
        .btn-hero-primary:hover { background: #e0e7ff; }
        .btn-hero-secondary { background: rgba(255,255,255,0.1); color: #e2e8f0; border-color: rgba(255,255,255,0.2); font-weight: 600; }
        .btn-hero-secondary:hover { background: rgba(255,255,255,0.18); color: #fff; }

        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
        @media (max-width: 1000px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        .stat-card {
            background: #fff;
            padding: 18px 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            border: 2px solid transparent;
        }
        .stat-label { font-size: 12px; color: var(--text-muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .stat-value { font-size: 24px; font-weight: 700; color: #667eea; }

        .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.07); margin-bottom: 22px; overflow: hidden; }
        .card-header {
            padding: 12px 20px;
            border-bottom: 1px solid var(--border-light);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #374151;
            background: #f9fafb;
        }

        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #f1f3f5; }
        th { padding: 10px 14px; text-align: left; font-weight: 600; color: var(--text-muted); border: 1px solid var(--border); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 10px 14px; border: 1px solid var(--border); font-size: 13px; vertical-align: middle; }
        tbody tr:nth-child(odd) { background: #ffffff; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        tbody tr:hover { background: #e0f2fe; }

        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge-payment-paid { background: #d4edda; color: #155724; }
        .badge-payment-partial { background: #fff3cd; color: #856404; }
        .badge-payment-unpaid { background: #fee2e2; color: #991b1b; }
        .badge-payment-unknown { background: #e2e8f0; color: #475569; }
        .badge-workflow-new { background: #e0f2fe; color: #075985; }
        .badge-workflow-assigned { background: #e0e7ff; color: #3730a3; }
        .badge-workflow-accepted { background: #dcfce7; color: #166534; }
        .badge-workflow-rejected { background: #fee2e2; color: #991b1b; }
        .badge-workflow-archived { background: #e2e8f0; color: #475569; }

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
                <a href="<?php echo url('investors', ['view' => 'suppliers']); ?>">Partnerzy zakupowi</a> /
                Karta partnera
            </div>
            <h1><?php echo e($supplierName); ?></h1>
            <p><?php echo $supplierNip !== '' ? 'NIP: ' . e($supplierNip) : 'Brak NIP'; ?></p>
        </div>
        <div class="hero-actions">
            <a href="<?php echo url('finanse.fakturownia-cost-inbox', ['supplier' => $supplierName]); ?>" class="btn-hero-primary">Edytuj w inboxie</a>
            <a href="<?php echo url('investors', ['view' => 'suppliers']); ?>" class="btn-hero-secondary">← Wróć do listy</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-label">Faktury</div><div class="stat-value"><?php echo (int)$stats['invoices_count']; ?></div></div>
        <div class="stat-card"><div class="stat-label">Suma netto</div><div class="stat-value"><?php echo formatMoney($stats['total_net']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Suma brutto</div><div class="stat-value"><?php echo formatMoney($stats['total_gross']); ?></div></div>
        <div class="stat-card"><div class="stat-label">Zatwierdzone</div><div class="stat-value"><?php echo (int)$stats['accepted_invoices']; ?> / <?php echo (int)$stats['invoices_count']; ?></div></div>
    </div>

    <div class="card">
        <div class="card-header">Ostatnie faktury partnera</div>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>Numer</th>
                    <th>KSeF</th>
                    <th>Data wystawienia</th>
                    <th>Termin</th>
                    <th style="text-align:right;">Netto</th>
                    <th style="text-align:right;">Brutto</th>
                    <th>Status płatności</th>
                    <th>Status workflow</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <?php
                    $paymentStatus = (string)($invoice['payment_status'] ?? 'unknown');
                    $workflowStatus = (string)($invoice['workflow_status'] ?? 'new');
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo url('finanse.fakturownia-cost-inbox.view', ['id' => (int)$invoice['id']]); ?>" style="font-weight:600;color:#667eea;text-decoration:none;">
                                <?php echo e($invoice['invoice_number'] ?: ('#' . (int)$invoice['id'])); ?>
                            </a>
                        </td>
                        <td><?php echo e($invoice['ksef_number'] ?: '—'); ?></td>
                        <td><?php echo !empty($invoice['issue_date']) ? formatDate($invoice['issue_date']) : '—'; ?></td>
                        <td><?php echo !empty($invoice['due_date']) ? formatDate($invoice['due_date']) : '—'; ?></td>
                        <td style="text-align:right;"><?php echo formatMoney($invoice['amount_net']); ?></td>
                        <td style="text-align:right;font-weight:700;"><?php echo formatMoney($invoice['amount_gross']); ?></td>
                        <td><span class="badge badge-payment-<?php echo e($paymentStatus); ?>"><?php echo e($paymentLabels[$paymentStatus] ?? 'Brak danych'); ?></span></td>
                        <td><span class="badge badge-workflow-<?php echo e($workflowStatus); ?>"><?php echo e($workflowLabels[$workflowStatus] ?? 'Nowa'); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Top projekty wg alokowanego kosztu netto</div>
        <div class="table-scroll">
            <?php if (!empty($projects)): ?>
                <table>
                    <thead>
                    <tr>
                        <th>Projekt</th>
                        <th style="text-align:center;">Dokumenty</th>
                        <th style="text-align:right;">Alokowane netto</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($projects as $project): ?>
                        <tr>
                            <td><?php echo e($project['project_name']); ?></td>
                            <td style="text-align:center;"><?php echo (int)$project['docs_count']; ?></td>
                            <td style="text-align:right;font-weight:700;"><?php echo formatMoney($project['allocated_net']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="padding: 24px 20px; color: #6b7280;">Brak alokacji kosztowych dla tego partnera.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
</footer>
</body>
</html>
