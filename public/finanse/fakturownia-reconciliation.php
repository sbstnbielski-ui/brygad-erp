<?php
/**
 * BRYGAD ERP - Raport spójności integracji Fakturownia
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

$salesStats = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN fakturownia_id IS NOT NULL THEN 1 ELSE 0 END) AS with_fid,
        SUM(CASE WHEN gov_status = 'pending' THEN 1 ELSE 0 END) AS gov_pending,
        SUM(CASE WHEN gov_status = 'error' THEN 1 ELSE 0 END) AS gov_error,
        SUM(CASE WHEN gov_status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 ELSE 0 END) AS pending_old_48h,
        SUM(CASE WHEN synced_at IS NULL THEN 1 ELSE 0 END) AS never_synced
     FROM fakturownia_invoices"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$costStats = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN workflow_status = 'new' THEN 1 ELSE 0 END) AS wf_new,
        SUM(CASE WHEN workflow_status = 'assigned' THEN 1 ELSE 0 END) AS wf_assigned,
        SUM(CASE WHEN workflow_status = 'accepted' THEN 1 ELSE 0 END) AS wf_accepted,
        SUM(CASE WHEN workflow_status = 'rejected' THEN 1 ELSE 0 END) AS wf_rejected,
        SUM(CASE WHEN workflow_status = 'archived' THEN 1 ELSE 0 END) AS wf_archived,
        SUM(CASE WHEN workflow_status = 'new' AND imported_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS new_old_7d,
        SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count
     FROM fakturownia_cost_invoices"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$allocationStats = $pdo->query(
    "SELECT
        SUM(CASE WHEN ci.workflow_status IN ('accepted', 'assigned')
                  AND COALESCE(alloc.allocated_gross, 0) < 0.01 THEN 1 ELSE 0 END) AS accepted_or_assigned_without_alloc,
        SUM(CASE WHEN COALESCE(alloc.allocated_gross, 0) > ci.amount_gross + 0.01 THEN 1 ELSE 0 END) AS over_allocated,
        SUM(CASE WHEN COALESCE(alloc.allocated_gross, 0) < -0.01 THEN 1 ELSE 0 END) AS negative_allocated
     FROM fakturownia_cost_invoices ci
     LEFT JOIN (
        SELECT cost_invoice_id, COALESCE(SUM(amount_gross), 0) AS allocated_gross
        FROM fakturownia_cost_allocations
        GROUP BY cost_invoice_id
     ) alloc ON alloc.cost_invoice_id = ci.id"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$archiveStats = $pdo->query(
    "SELECT
        (SELECT COUNT(*)
         FROM fakturownia_invoices fi
         WHERE fi.fakturownia_id IS NOT NULL) AS sale_total_syncable,

        (SELECT COUNT(*)
         FROM fakturownia_invoices fi
         WHERE fi.fakturownia_id IS NOT NULL
           AND NOT EXISTS (
                SELECT 1 FROM fakturownia_archive_files a
                WHERE a.source_type = 'sale'
                  AND a.source_local_id = fi.id
                  AND a.file_kind = 'pdf'
           )) AS sale_missing_archive,

        (SELECT COUNT(*)
         FROM fakturownia_cost_invoices ci
         WHERE ci.fakturownia_id IS NOT NULL) AS cost_total_syncable,

        (SELECT COUNT(*)
         FROM fakturownia_cost_invoices ci
         WHERE ci.fakturownia_id IS NOT NULL
           AND NOT EXISTS (
                SELECT 1 FROM fakturownia_archive_files a
                WHERE a.source_type = 'cost'
                  AND a.source_local_id = ci.id
                  AND a.file_kind = 'pdf'
           )) AS cost_missing_archive"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$apiStats24h = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN http_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS s2xx,
        SUM(CASE WHEN http_status = 401 THEN 1 ELSE 0 END) AS s401,
        SUM(CASE WHEN http_status = 429 THEN 1 ELSE 0 END) AS s429,
        SUM(CASE WHEN http_status = 503 THEN 1 ELSE 0 END) AS s503,
        SUM(CASE WHEN http_status IS NULL OR http_status >= 400 THEN 1 ELSE 0 END) AS errors
     FROM fakturownia_api_log
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$pendingRowsStmt = $pdo->query(
    "SELECT id, fakturownia_id, fakturownia_number, gov_status, status, created_at, synced_at
     FROM fakturownia_invoices
     WHERE gov_status = 'pending'
     ORDER BY created_at ASC
     LIMIT 20"
);
$pendingRows = $pendingRowsStmt ? ($pendingRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$costNoAllocStmt = $pdo->query(
    "SELECT
        ci.id,
        ci.invoice_number,
        ci.supplier_name,
        ci.workflow_status,
        ci.amount_gross,
        COALESCE(alloc.allocated_gross, 0) AS allocated_gross,
        ci.updated_at
     FROM fakturownia_cost_invoices ci
     LEFT JOIN (
        SELECT cost_invoice_id, COALESCE(SUM(amount_gross), 0) AS allocated_gross
        FROM fakturownia_cost_allocations
        GROUP BY cost_invoice_id
     ) alloc ON alloc.cost_invoice_id = ci.id
     WHERE ci.workflow_status IN ('accepted', 'assigned')
       AND COALESCE(alloc.allocated_gross, 0) < 0.01
     ORDER BY ci.updated_at DESC
     LIMIT 20"
);
$costNoAllocRows = $costNoAllocStmt ? ($costNoAllocStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$missingArchiveStmt = $pdo->query(
    "(SELECT
        'sale' AS source_type,
        fi.id AS local_id,
        fi.fakturownia_id,
        fi.fakturownia_number AS number_label,
        fi.created_at AS document_date
      FROM fakturownia_invoices fi
      WHERE fi.fakturownia_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM fakturownia_archive_files a
            WHERE a.source_type = 'sale'
              AND a.source_local_id = fi.id
              AND a.file_kind = 'pdf'
        )
      ORDER BY fi.created_at DESC
      LIMIT 15)

     UNION ALL

     (SELECT
        'cost' AS source_type,
        ci.id AS local_id,
        ci.fakturownia_id,
        ci.invoice_number AS number_label,
        COALESCE(ci.issue_date, ci.imported_at, ci.created_at) AS document_date
      FROM fakturownia_cost_invoices ci
      WHERE ci.fakturownia_id IS NOT NULL
        AND NOT EXISTS (
            SELECT 1 FROM fakturownia_archive_files a
            WHERE a.source_type = 'cost'
              AND a.source_local_id = ci.id
              AND a.file_kind = 'pdf'
        )
      ORDER BY COALESCE(ci.issue_date, ci.imported_at, ci.created_at) DESC
      LIMIT 15)

     ORDER BY document_date DESC"
);
$missingArchiveRows = $missingArchiveStmt ? ($missingArchiveStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$withFidClass = 'ok';
if ((int)($salesStats['total'] ?? 0) > 0 && (int)($salesStats['with_fid'] ?? 0) === 0) {
    $withFidClass = 'warning';
}

function riskClass(int $value, int $warningThreshold = 1, int $criticalThreshold = 5): string
{
    if ($value >= $criticalThreshold) {
        return 'critical';
    }
    if ($value >= $warningThreshold) {
        return 'warning';
    }
    return 'ok';
}

function riskLabel(string $class): string
{
    if ($class === 'critical') {
        return 'KRYTYCZNE';
    }
    if ($class === 'warning') {
        return 'UWAGA';
    }
    return 'OK';
}

function fmtDateTime(?string $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return '-';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }

    return date('d.m.Y H:i', $ts);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Rekonsyliacja Fakturownia</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #1f2937;
        }
        .container { max-width: 1520px; margin: 0 auto; padding: 28px; }
        .breadcrumb { margin-bottom: 16px; font-size: 14px; color: #6b7280; }
        .breadcrumb a { color: #2563eb; text-decoration: none; }
        .header {
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; flex-wrap: wrap; margin-bottom: 18px;
        }
        .header h1 { font-size: 30px; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 10px 14px; border-radius: 8px; border: none;
            text-decoration: none; font-size: 14px; font-weight: 600; cursor: pointer;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #6b7280; color: #fff; }
        .btn-secondary:hover { background: #4b5563; }

        .section { margin-bottom: 16px; }
        .section-title {
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #334155;
            margin-bottom: 10px;
            font-weight: 800;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }
        .stat {
            background: #fff;
            border-radius: 10px;
            padding: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #cbd5e1;
        }
        .stat.ok { border-left-color: #16a34a; }
        .stat.warning { border-left-color: #d97706; }
        .stat.critical { border-left-color: #dc2626; }
        .stat-label { font-size: 11px; color: #6b7280; text-transform: uppercase; }
        .stat-value { font-size: 24px; font-weight: 700; margin-top: 4px; }
        .stat-risk {
            margin-top: 6px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .stat-risk.ok { color: #166534; }
        .stat-risk.warning { color: #92400e; }
        .stat-risk.critical { color: #991b1b; }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .card + .card { margin-top: 12px; }
        .card-header {
            padding: 11px 12px;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            border-bottom: 1px solid #e5e7eb;
            padding: 10px;
            font-size: 12px;
            text-align: left;
            vertical-align: top;
        }
        th {
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            background: #f9fafb;
        }
        .no-data { padding: 20px; color: #6b7280; }
        .tag {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }
        .tag-sale { background: #dbeafe; color: #1e3a8a; }
        .tag-cost { background: #fef3c7; color: #92400e; }
        .checklist {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 14px;
        }
        .checklist ul { margin: 8px 0 0 16px; }
        .checklist li { margin-bottom: 8px; color: #334155; font-size: 13px; }
    </style>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

<div class="container">
    <div class="breadcrumb">
        <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
        <a href="<?php echo url('finanse'); ?>">Finanse</a> /
        Rekonsyliacja Fakturownia
    </div>

    <div class="header">
        <h1>Rekonsyliacja Fakturownia</h1>
        <div class="actions">
            <a href="<?php echo url('finanse.fakturownia-logs'); ?>" class="btn btn-primary">Logi API</a>
            <a href="<?php echo url('finanse.fakturownia-archive'); ?>" class="btn btn-primary">Archiwum</a>
            <a href="<?php echo url('finanse'); ?>" class="btn btn-secondary">Powrót</a>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Sprzedaż i KSeF</div>
        <div class="stats">
            <div class="stat ok"><div class="stat-label">Mapowania łącznie</div><div class="stat-value"><?php echo (int)$salesStats['total']; ?></div><div class="stat-risk ok">OK</div></div>
            <div class="stat <?php echo e($withFidClass); ?>"><div class="stat-label">Z `fakturownia_id`</div><div class="stat-value"><?php echo (int)$salesStats['with_fid']; ?></div><div class="stat-risk <?php echo e($withFidClass); ?>"><?php echo e(riskLabel($withFidClass)); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$salesStats['gov_pending'], 1, 10)); ?>"><div class="stat-label">KSeF pending</div><div class="stat-value"><?php echo (int)$salesStats['gov_pending']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$salesStats['gov_pending'], 1, 10)); ?>"><?php echo e(riskLabel(riskClass((int)$salesStats['gov_pending'], 1, 10))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$salesStats['pending_old_48h'], 1, 3)); ?>"><div class="stat-label">Pending >48h</div><div class="stat-value"><?php echo (int)$salesStats['pending_old_48h']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$salesStats['pending_old_48h'], 1, 3)); ?>"><?php echo e(riskLabel(riskClass((int)$salesStats['pending_old_48h'], 1, 3))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$salesStats['gov_error'], 1, 2)); ?>"><div class="stat-label">KSeF error</div><div class="stat-value"><?php echo (int)$salesStats['gov_error']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$salesStats['gov_error'], 1, 2)); ?>"><?php echo e(riskLabel(riskClass((int)$salesStats['gov_error'], 1, 2))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$salesStats['never_synced'], 1, 5)); ?>"><div class="stat-label">Nigdy nie sync</div><div class="stat-value"><?php echo (int)$salesStats['never_synced']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$salesStats['never_synced'], 1, 5)); ?>"><?php echo e(riskLabel(riskClass((int)$salesStats['never_synced'], 1, 5))); ?></div></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Koszty i alokacje</div>
        <div class="stats">
            <div class="stat ok"><div class="stat-label">Inbox kosztów</div><div class="stat-value"><?php echo (int)$costStats['total']; ?></div><div class="stat-risk ok">OK</div></div>
            <div class="stat <?php echo e(riskClass((int)$costStats['new_old_7d'], 1, 10)); ?>"><div class="stat-label">`new` >7 dni</div><div class="stat-value"><?php echo (int)$costStats['new_old_7d']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$costStats['new_old_7d'], 1, 10)); ?>"><?php echo e(riskLabel(riskClass((int)$costStats['new_old_7d'], 1, 10))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$allocationStats['accepted_or_assigned_without_alloc'], 1, 3)); ?>"><div class="stat-label">Accepted/Assigned bez alokacji</div><div class="stat-value"><?php echo (int)$allocationStats['accepted_or_assigned_without_alloc']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$allocationStats['accepted_or_assigned_without_alloc'], 1, 3)); ?>"><?php echo e(riskLabel(riskClass((int)$allocationStats['accepted_or_assigned_without_alloc'], 1, 3))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$allocationStats['over_allocated'], 1, 2)); ?>"><div class="stat-label">Nad- alokowane</div><div class="stat-value"><?php echo (int)$allocationStats['over_allocated']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$allocationStats['over_allocated'], 1, 2)); ?>"><?php echo e(riskLabel(riskClass((int)$allocationStats['over_allocated'], 1, 2))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$allocationStats['negative_allocated'], 1, 2)); ?>"><div class="stat-label">Ujemne alokacje</div><div class="stat-value"><?php echo (int)$allocationStats['negative_allocated']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$allocationStats['negative_allocated'], 1, 2)); ?>"><?php echo e(riskLabel(riskClass((int)$allocationStats['negative_allocated'], 1, 2))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$costStats['unpaid_count'], 50, 120)); ?>"><div class="stat-label">Nieopłacone</div><div class="stat-value"><?php echo (int)$costStats['unpaid_count']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$costStats['unpaid_count'], 50, 120)); ?>"><?php echo e(riskLabel(riskClass((int)$costStats['unpaid_count'], 50, 120))); ?></div></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Archiwum i API (24h)</div>
        <div class="stats">
            <div class="stat <?php echo e(riskClass((int)$archiveStats['sale_missing_archive'], 1, 10)); ?>"><div class="stat-label">Sprzedaż bez PDF</div><div class="stat-value"><?php echo (int)$archiveStats['sale_missing_archive']; ?>/<?php echo (int)$archiveStats['sale_total_syncable']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$archiveStats['sale_missing_archive'], 1, 10)); ?>"><?php echo e(riskLabel(riskClass((int)$archiveStats['sale_missing_archive'], 1, 10))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$archiveStats['cost_missing_archive'], 1, 10)); ?>"><div class="stat-label">Koszty bez PDF</div><div class="stat-value"><?php echo (int)$archiveStats['cost_missing_archive']; ?>/<?php echo (int)$archiveStats['cost_total_syncable']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$archiveStats['cost_missing_archive'], 1, 10)); ?>"><?php echo e(riskLabel(riskClass((int)$archiveStats['cost_missing_archive'], 1, 10))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$apiStats24h['s401'], 1, 1)); ?>"><div class="stat-label">API 401 (24h)</div><div class="stat-value"><?php echo (int)$apiStats24h['s401']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$apiStats24h['s401'], 1, 1)); ?>"><?php echo e(riskLabel(riskClass((int)$apiStats24h['s401'], 1, 1))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$apiStats24h['s429'], 3, 10)); ?>"><div class="stat-label">API 429 (24h)</div><div class="stat-value"><?php echo (int)$apiStats24h['s429']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$apiStats24h['s429'], 3, 10)); ?>"><?php echo e(riskLabel(riskClass((int)$apiStats24h['s429'], 3, 10))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$apiStats24h['s503'], 2, 6)); ?>"><div class="stat-label">API 503 (24h)</div><div class="stat-value"><?php echo (int)$apiStats24h['s503']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$apiStats24h['s503'], 2, 6)); ?>"><?php echo e(riskLabel(riskClass((int)$apiStats24h['s503'], 2, 6))); ?></div></div>
            <div class="stat <?php echo e(riskClass((int)$apiStats24h['errors'], 5, 25)); ?>"><div class="stat-label">API błędy (24h)</div><div class="stat-value"><?php echo (int)$apiStats24h['errors']; ?>/<?php echo (int)$apiStats24h['total']; ?></div><div class="stat-risk <?php echo e(riskClass((int)$apiStats24h['errors'], 5, 25)); ?>"><?php echo e(riskLabel(riskClass((int)$apiStats24h['errors'], 5, 25))); ?></div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">KSeF pending (najstarsze 20)</div>
        <?php if (!empty($pendingRows)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID lokalne</th>
                        <th>Fakturownia ID</th>
                        <th>Numer</th>
                        <th>gov_status</th>
                        <th>status</th>
                        <th>Utworzono</th>
                        <th>Sync</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingRows as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['id']; ?></td>
                            <td><?php echo (int)$row['fakturownia_id']; ?></td>
                            <td><?php echo e((string)($row['fakturownia_number'] ?: '-')); ?></td>
                            <td><?php echo e((string)$row['gov_status']); ?></td>
                            <td><?php echo e((string)$row['status']); ?></td>
                            <td><?php echo e(fmtDateTime($row['created_at'])); ?></td>
                            <td><?php echo e(fmtDateTime($row['synced_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">Brak rekordów pending.</div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">Koszty accepted/assigned bez alokacji (top 20)</div>
        <?php if (!empty($costNoAllocRows)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Numer</th>
                        <th>Dostawca</th>
                        <th>Workflow</th>
                        <th>Kwota brutto</th>
                        <th>Alokowane brutto</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($costNoAllocRows as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['id']; ?></td>
                            <td><?php echo e((string)($row['invoice_number'] ?: '-')); ?></td>
                            <td><?php echo e((string)($row['supplier_name'] ?: '-')); ?></td>
                            <td><?php echo e((string)$row['workflow_status']); ?></td>
                            <td><?php echo formatMoney((float)$row['amount_gross']); ?></td>
                            <td><?php echo formatMoney((float)$row['allocated_gross']); ?></td>
                            <td><?php echo e(fmtDateTime($row['updated_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">Brak rekordów wymagających interwencji.</div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">Braki w archiwum PDF (top 30)</div>
        <?php if (!empty($missingArchiveRows)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Źródło</th>
                        <th>ID lokalne</th>
                        <th>Fakturownia ID</th>
                        <th>Numer</th>
                        <th>Data dokumentu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($missingArchiveRows as $row): ?>
                        <tr>
                            <td>
                                <span class="tag tag-<?php echo e((string)$row['source_type']); ?>">
                                    <?php echo e((string)$row['source_type'] === 'sale' ? 'Sprzedaż' : 'Koszt'); ?>
                                </span>
                            </td>
                            <td><?php echo (int)$row['local_id']; ?></td>
                            <td><?php echo (int)$row['fakturownia_id']; ?></td>
                            <td><?php echo e((string)($row['number_label'] ?: '-')); ?></td>
                            <td><?php echo e(fmtDateTime($row['document_date'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">Brak braków archiwum PDF.</div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
