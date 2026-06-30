<?php
/**
 * BRYGAD ERP - Archiwum dokumentów Fakturowni (PDF)
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

$sourceType = trim((string)($_GET['source_type'] ?? 'all'));
$tier = trim((string)($_GET['storage_tier'] ?? 'all'));
$year = (int)($_GET['year'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));

$where = ' WHERE 1=1';
$params = [];

if (in_array($sourceType, ['sale', 'cost'], true)) {
    $where .= ' AND a.source_type = :source_type';
    $params[':source_type'] = $sourceType;
} else {
    $sourceType = 'all';
}

if (in_array($tier, ['hot', 'cold'], true)) {
    $where .= ' AND a.storage_tier = :storage_tier';
    $params[':storage_tier'] = $tier;
} else {
    $tier = 'all';
}

if ($year > 0) {
    $where .= ' AND a.storage_year = :storage_year';
    $params[':storage_year'] = $year;
}

if ($search !== '') {
    $where .= ' AND (
        a.file_name LIKE :search
        OR a.fakturownia_id LIKE :search_exact
        OR c.invoice_number LIKE :search
        OR c.supplier_name LIKE :search
        OR s.fakturownia_number LIKE :search
    )';
    $params[':search'] = '%' . $search . '%';
    $params[':search_exact'] = $search;
}

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

$countSql = "SELECT COUNT(*)
    FROM fakturownia_archive_files a
    LEFT JOIN fakturownia_cost_invoices c
      ON a.source_type = 'cost' AND c.id = a.source_local_id
    LEFT JOIN fakturownia_invoices s
      ON a.source_type = 'sale' AND s.id = a.source_local_id"
    . $where;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT
        a.*,
        c.invoice_number AS cost_invoice_number,
        c.supplier_name AS cost_supplier_name,
        s.fakturownia_number AS sale_invoice_number
    FROM fakturownia_archive_files a
    LEFT JOIN fakturownia_cost_invoices c
      ON a.source_type = 'cost' AND c.id = a.source_local_id
    LEFT JOIN fakturownia_invoices s
      ON a.source_type = 'sale' AND s.id = a.source_local_id"
    . $where
    . " ORDER BY a.storage_year DESC, a.storage_month DESC, a.id DESC
       LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN storage_tier = 'hot' THEN 1 ELSE 0 END) AS hot_count,
        SUM(CASE WHEN storage_tier = 'cold' THEN 1 ELSE 0 END) AS cold_count,
        COALESCE(SUM(file_size), 0) AS total_size
     FROM fakturownia_archive_files"
)->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'hot_count' => 0, 'cold_count' => 0, 'total_size' => 0];

$yearsStmt = $pdo->query("SELECT DISTINCT storage_year FROM fakturownia_archive_files ORDER BY storage_year DESC");
$years = $yearsStmt ? ($yearsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];

function archivePageLink(int $targetPage): string
{
    $p = $_GET;
    $p['page'] = $targetPage;
    return '?' . http_build_query($p);
}

function archiveSourceLabel(string $sourceType): string
{
    return $sourceType === 'cost' ? 'Kosztowa' : 'Sprzedażowa';
}

function formatBytesSimple(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1, ',', ' ') . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / (1024 * 1024), 2, ',', ' ') . ' MB';
    return number_format($bytes / (1024 * 1024 * 1024), 2, ',', ' ') . ' GB';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Archiwum Fakturowni</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #1f2937;
        }
        .container { max-width: 1500px; margin: 0 auto; padding: 28px; }
        .breadcrumb { margin-bottom: 16px; font-size: 14px; color: #6b7280; }
        .breadcrumb a { color: #2563eb; text-decoration: none; }
        .header {
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; flex-wrap: wrap; margin-bottom: 18px;
        }
        .header h1 { font-size: 30px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 10px 14px; border-radius: 8px; border: none;
            text-decoration: none; font-size: 14px; font-weight: 600; cursor: pointer;
        }
        .btn-secondary { background: #6b7280; color: #fff; }
        .btn-secondary:hover { background: #4b5563; }
        .stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px; margin-bottom: 16px;
        }
        .stat {
            background: #fff; border-radius: 10px; padding: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-label { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
        .stat-value { font-size: 24px; font-weight: 700; }
        .card {
            background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .filters {
            padding: 12px; border-bottom: 1px solid #e5e7eb;
            display: grid; gap: 10px;
            grid-template-columns: 160px 160px 140px 1fr auto auto;
            align-items: end;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 11px; text-transform: uppercase; color: #6b7280; font-weight: 700; }
        .form-group input, .form-group select {
            border: 1px solid #d1d5db; border-radius: 8px; padding: 9px 10px; font-size: 14px;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; font-size: 13px; text-align: left; vertical-align: middle; }
        th { font-size: 11px; text-transform: uppercase; color: #6b7280; background: #f9fafb; }
        .tag {
            display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 700;
        }
        .tag-hot { background: #dcfce7; color: #166534; }
        .tag-cold { background: #dbeafe; color: #1e3a8a; }
        .tag-source { background: #f3f4f6; color: #374151; }
        .pagination {
            display: flex; justify-content: center; align-items: center; gap: 6px;
            padding: 14px;
        }
        .pagination a, .pagination span {
            min-width: 34px; height: 34px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; text-decoration: none;
        }
        .pagination a { border: 1px solid #d1d5db; color: #374151; }
        .pagination .current { background: #2563eb; color: #fff; border: 1px solid #2563eb; }
        .pagination .disabled { border: 1px solid #e5e7eb; color: #cbd5e1; }
        .no-data { padding: 30px; text-align: center; color: #6b7280; }
        @media (max-width: 980px) {
            .filters { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>
<div class="container">
    <div class="breadcrumb">
        <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
        <a href="<?php echo url('finanse'); ?>">Finanse</a> /
        Archiwum Fakturowni
    </div>

    <div class="header">
        <h1>Archiwum Fakturowni</h1>
        <a href="<?php echo url('finanse'); ?>" class="btn btn-secondary">Powrót</a>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="stat-label">Wszystkie pliki</div>
            <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">HOT (&lt;24m)</div>
            <div class="stat-value"><?php echo (int)$stats['hot_count']; ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">COLD (>=24m)</div>
            <div class="stat-value"><?php echo (int)$stats['cold_count']; ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Rozmiar</div>
            <div class="stat-value"><?php echo e(formatBytesSimple((int)$stats['total_size'])); ?></div>
        </div>
    </div>

    <div class="card">
        <form method="GET" action="" class="filters">
            <div class="form-group">
                <label>Źródło</label>
                <select name="source_type">
                    <option value="all" <?php echo $sourceType === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                    <option value="sale" <?php echo $sourceType === 'sale' ? 'selected' : ''; ?>>Sprzedażowe</option>
                    <option value="cost" <?php echo $sourceType === 'cost' ? 'selected' : ''; ?>>Kosztowe</option>
                </select>
            </div>
            <div class="form-group">
                <label>Tier</label>
                <select name="storage_tier">
                    <option value="all" <?php echo $tier === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                    <option value="hot" <?php echo $tier === 'hot' ? 'selected' : ''; ?>>HOT</option>
                    <option value="cold" <?php echo $tier === 'cold' ? 'selected' : ''; ?>>COLD</option>
                </select>
            </div>
            <div class="form-group">
                <label>Rok</label>
                <select name="year">
                    <option value="0">Wszystkie</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo (int)$y; ?>" <?php echo ((int)$year === (int)$y) ? 'selected' : ''; ?>><?php echo (int)$y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Szukaj</label>
                <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Numer, dostawca, nazwa pliku...">
            </div>
            <button type="submit" class="btn btn-primary">Filtruj</button>
            <a href="<?php echo url('finanse.fakturownia-archive'); ?>" class="btn btn-secondary">Wyczyść</a>
        </form>

        <?php if (count($rows) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Źródło</th>
                        <th>Dokument</th>
                        <th>Tier</th>
                        <th>Data dok.</th>
                        <th>Rok/Mies.</th>
                        <th>Plik</th>
                        <th>Rozmiar</th>
                        <th>Zarchiwizowano</th>
                        <th>Akcja</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo (int)$r['id']; ?></td>
                            <td><span class="tag tag-source"><?php echo e(archiveSourceLabel((string)$r['source_type'])); ?></span></td>
                            <td>
                                <?php if ((string)$r['source_type'] === 'cost'): ?>
                                    <?php echo e($r['cost_invoice_number'] ?: ('F#' . $r['fakturownia_id'])); ?><br>
                                    <span style="color:#6b7280;font-size:12px;"><?php echo e($r['cost_supplier_name'] ?: ''); ?></span>
                                <?php else: ?>
                                    <?php echo e($r['sale_invoice_number'] ?: ('F#' . $r['fakturownia_id'])); ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="tag tag-<?php echo e($r['storage_tier']); ?>"><?php echo strtoupper(e($r['storage_tier'])); ?></span></td>
                            <td><?php echo $r['document_date'] ? formatDate($r['document_date']) : '-'; ?></td>
                            <td><?php echo (int)$r['storage_year']; ?>/<?php echo str_pad((string)(int)$r['storage_month'], 2, '0', STR_PAD_LEFT); ?></td>
                            <td>
                                <?php echo e($r['file_name']); ?><br>
                                <span style="color:#6b7280;font-size:11px;"><?php echo e($r['file_path']); ?></span>
                            </td>
                            <td><?php echo e(formatBytesSimple((int)$r['file_size'])); ?></td>
                            <td><?php echo $r['archived_at'] ? e(date('d.m.Y H:i', strtotime($r['archived_at']))) : '-'; ?></td>
                            <td>
                                <a class="btn btn-primary" style="padding:6px 10px;font-size:12px;" href="<?php echo url('finanse.fakturownia-archive.download', ['id' => (int)$r['id']]); ?>">Pobierz</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo archivePageLink($page - 1); ?>">‹</a>
                    <?php else: ?>
                        <span class="disabled">‹</span>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo archivePageLink($i); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo archivePageLink($page + 1); ?>">›</a>
                    <?php else: ?>
                        <span class="disabled">›</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-data">Brak plików archiwalnych dla wybranych filtrów.</div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
