<?php
/**
 * BRYGAD ERP - Debug logi API Fakturownia
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

$endpointSearch = trim((string)($_GET['endpoint'] ?? ''));
$method = strtoupper(trim((string)($_GET['method'] ?? 'all')));
$statusGroup = trim((string)($_GET['status_group'] ?? 'all'));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$errorSearch = trim((string)($_GET['error_search'] ?? ''));
$onlyErrors = (int)($_GET['only_errors'] ?? 0) === 1;

$allowedMethods = ['all', 'GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
if (!in_array($method, $allowedMethods, true)) {
    $method = 'all';
}

$allowedStatusGroups = ['all', '2xx', '4xx', '5xx', 'auth', 'retry', 'errors'];
if (!in_array($statusGroup, $allowedStatusGroups, true)) {
    $statusGroup = 'all';
}

$where = ' WHERE 1=1';
$params = [];

if ($endpointSearch !== '') {
    $where .= ' AND endpoint LIKE :endpoint';
    $params[':endpoint'] = '%' . $endpointSearch . '%';
}

if ($method !== 'all') {
    $where .= ' AND http_method = :http_method';
    $params[':http_method'] = $method;
}

if ($dateFrom !== '') {
    $where .= ' AND created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where .= ' AND created_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

if ($errorSearch !== '') {
    $where .= ' AND error_message LIKE :error_search';
    $params[':error_search'] = '%' . $errorSearch . '%';
}

switch ($statusGroup) {
    case '2xx':
        $where .= ' AND http_status BETWEEN 200 AND 299';
        break;
    case '4xx':
        $where .= ' AND http_status BETWEEN 400 AND 499';
        break;
    case '5xx':
        $where .= ' AND http_status BETWEEN 500 AND 599';
        break;
    case 'auth':
        $where .= ' AND http_status = 401';
        break;
    case 'retry':
        $where .= ' AND (http_status IN (429, 503) OR retry_count > 0)';
        break;
    case 'errors':
        $where .= ' AND (http_status IS NULL OR http_status >= 400 OR error_message IS NOT NULL)';
        break;
}

if ($onlyErrors) {
    $where .= ' AND (http_status IS NULL OR http_status >= 400 OR error_message IS NOT NULL)';
}

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM fakturownia_api_log ' . $where);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT id, endpoint, http_method, http_status, request_json, response_json, retry_count, error_message, created_at
        FROM fakturownia_api_log '
    . $where
    . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats24h = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN http_status BETWEEN 200 AND 299 THEN 1 ELSE 0 END) AS s2xx,
        SUM(CASE WHEN http_status BETWEEN 400 AND 499 THEN 1 ELSE 0 END) AS s4xx,
        SUM(CASE WHEN http_status BETWEEN 500 AND 599 THEN 1 ELSE 0 END) AS s5xx,
        SUM(CASE WHEN http_status = 401 THEN 1 ELSE 0 END) AS s401,
        SUM(CASE WHEN http_status IN (429, 503) THEN 1 ELSE 0 END) AS sretry,
        SUM(CASE WHEN retry_count > 0 THEN 1 ELSE 0 END) AS retried
     FROM fakturownia_api_log
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
)->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    's2xx' => 0,
    's4xx' => 0,
    's5xx' => 0,
    's401' => 0,
    'sretry' => 0,
    'retried' => 0,
];

$topEndpointsStmt = $pdo->query(
    "SELECT
        endpoint,
        COUNT(*) AS total,
        SUM(CASE WHEN http_status IS NULL OR http_status >= 400 THEN 1 ELSE 0 END) AS errors
     FROM fakturownia_api_log
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
     GROUP BY endpoint
     ORDER BY total DESC
     LIMIT 5"
);
$topEndpoints = $topEndpointsStmt ? ($topEndpointsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

function logsPageLink(int $targetPage): string
{
    $p = $_GET;
    $p['page'] = $targetPage;
    return '?' . http_build_query($p);
}

function shortText(?string $text, int $maxLen = 220): string
{
    $value = trim((string)$text);
    if ($value === '') {
        return '-';
    }

    if (mb_strlen($value) <= $maxLen) {
        return $value;
    }

    return mb_substr($value, 0, $maxLen - 3) . '...';
}

function statusClass($httpStatus): string
{
    $status = (int)$httpStatus;
    if ($status >= 200 && $status < 300) {
        return 'ok';
    }
    if ($status === 401) {
        return 'auth';
    }
    if (in_array($status, [429, 503], true)) {
        return 'retry';
    }
    if ($status >= 400 && $status < 600) {
        return 'error';
    }
    return 'unknown';
}

function statusLabel($httpStatus): string
{
    if ($httpStatus === null || $httpStatus === '') {
        return 'N/A';
    }
    return (string)((int)$httpStatus);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Logi API Fakturownia</title>
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
            gap: 12px; flex-wrap: wrap; margin-bottom: 16px;
        }
        .header h1 { font-size: 30px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 10px 14px; border-radius: 8px; border: none;
            text-decoration: none; font-size: 14px; font-weight: 600; cursor: pointer;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #6b7280; color: #fff; }
        .btn-secondary:hover { background: #4b5563; }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }
        .stat {
            background: #fff;
            border-radius: 10px;
            padding: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-label { font-size: 11px; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
        .stat-value { font-size: 24px; font-weight: 700; }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 16px;
        }
        .filters {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            display: grid;
            gap: 10px;
            grid-template-columns: 220px 120px 130px 140px 140px 1fr 120px auto auto;
            align-items: end;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label {
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            font-weight: 700;
        }
        .form-group input, .form-group select {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 9px 10px;
            font-size: 14px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            border-bottom: 1px solid #e5e7eb;
            padding: 10px;
            text-align: left;
            vertical-align: top;
            font-size: 12px;
        }
        th {
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            background: #f9fafb;
        }
        .status-tag {
            display: inline-block;
            min-width: 56px;
            text-align: center;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-auth { background: #fee2e2; color: #991b1b; }
        .status-retry { background: #fef3c7; color: #92400e; }
        .status-error { background: #ffedd5; color: #9a3412; }
        .status-unknown { background: #f3f4f6; color: #374151; }
        .error-text { color: #b91c1c; font-weight: 600; }
        details pre {
            white-space: pre-wrap;
            word-break: break-word;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px;
            margin-top: 6px;
            font-size: 11px;
            max-height: 220px;
            overflow: auto;
        }
        .small { color: #6b7280; font-size: 11px; }
        .no-data { padding: 28px; color: #6b7280; text-align: center; }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            padding: 14px;
        }
        .pagination a, .pagination span {
            min-width: 34px;
            height: 34px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            text-decoration: none;
        }
        .pagination a { border: 1px solid #d1d5db; color: #374151; }
        .pagination .current { background: #2563eb; color: #fff; border: 1px solid #2563eb; }
        .pagination .disabled { border: 1px solid #e5e7eb; color: #cbd5e1; }
        .top-endpoints {
            display: grid;
            gap: 8px;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        }
        .endpoint-item {
            background: #fff;
            border-radius: 10px;
            padding: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .endpoint-main { font-size: 12px; font-weight: 700; color: #111827; }
        .endpoint-meta { font-size: 11px; color: #6b7280; margin-top: 3px; }
        @media (max-width: 1100px) {
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
        Logi API Fakturownia
    </div>

    <div class="header">
        <h1>Logi API Fakturownia</h1>
        <div style="display:flex;gap:8px;">
            <a href="<?php echo url('finanse.fakturownia-reconciliation'); ?>" class="btn btn-primary">Raport spójności</a>
            <a href="<?php echo url('finanse'); ?>" class="btn btn-secondary">Powrót</a>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><div class="stat-label">24h wszystkie</div><div class="stat-value"><?php echo (int)$stats24h['total']; ?></div></div>
        <div class="stat"><div class="stat-label">24h 2xx</div><div class="stat-value"><?php echo (int)$stats24h['s2xx']; ?></div></div>
        <div class="stat"><div class="stat-label">24h 4xx</div><div class="stat-value"><?php echo (int)$stats24h['s4xx']; ?></div></div>
        <div class="stat"><div class="stat-label">24h 5xx</div><div class="stat-value"><?php echo (int)$stats24h['s5xx']; ?></div></div>
        <div class="stat"><div class="stat-label">24h 401</div><div class="stat-value"><?php echo (int)$stats24h['s401']; ?></div></div>
        <div class="stat"><div class="stat-label">24h 429/503</div><div class="stat-value"><?php echo (int)$stats24h['sretry']; ?></div></div>
        <div class="stat"><div class="stat-label">24h z retry</div><div class="stat-value"><?php echo (int)$stats24h['retried']; ?></div></div>
    </div>

    <?php if (!empty($topEndpoints)): ?>
        <div class="top-endpoints" style="margin-bottom:16px;">
            <?php foreach ($topEndpoints as $ep): ?>
                <div class="endpoint-item">
                    <div class="endpoint-main"><?php echo e((string)$ep['endpoint']); ?></div>
                    <div class="endpoint-meta">Żądań: <?php echo (int)$ep['total']; ?> | Błędów: <?php echo (int)$ep['errors']; ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="GET" action="" class="filters">
            <div class="form-group">
                <label>Endpoint</label>
                <input type="text" name="endpoint" value="<?php echo e($endpointSearch); ?>" placeholder="np. /invoices.json">
            </div>
            <div class="form-group">
                <label>Metoda</label>
                <select name="method">
                    <option value="all" <?php echo $method === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                    <option value="GET" <?php echo $method === 'GET' ? 'selected' : ''; ?>>GET</option>
                    <option value="POST" <?php echo $method === 'POST' ? 'selected' : ''; ?>>POST</option>
                    <option value="PUT" <?php echo $method === 'PUT' ? 'selected' : ''; ?>>PUT</option>
                    <option value="DELETE" <?php echo $method === 'DELETE' ? 'selected' : ''; ?>>DELETE</option>
                    <option value="PATCH" <?php echo $method === 'PATCH' ? 'selected' : ''; ?>>PATCH</option>
                </select>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status_group">
                    <option value="all" <?php echo $statusGroup === 'all' ? 'selected' : ''; ?>>Wszystkie</option>
                    <option value="2xx" <?php echo $statusGroup === '2xx' ? 'selected' : ''; ?>>2xx</option>
                    <option value="4xx" <?php echo $statusGroup === '4xx' ? 'selected' : ''; ?>>4xx</option>
                    <option value="5xx" <?php echo $statusGroup === '5xx' ? 'selected' : ''; ?>>5xx</option>
                    <option value="auth" <?php echo $statusGroup === 'auth' ? 'selected' : ''; ?>>401 auth</option>
                    <option value="retry" <?php echo $statusGroup === 'retry' ? 'selected' : ''; ?>>429/503+retry</option>
                    <option value="errors" <?php echo $statusGroup === 'errors' ? 'selected' : ''; ?>>Błędy</option>
                </select>
            </div>
            <div class="form-group">
                <label>Od</label>
                <input type="date" name="date_from" value="<?php echo e($dateFrom); ?>">
            </div>
            <div class="form-group">
                <label>Do</label>
                <input type="date" name="date_to" value="<?php echo e($dateTo); ?>">
            </div>
            <div class="form-group">
                <label>Fraza błędu</label>
                <input type="text" name="error_search" value="<?php echo e($errorSearch); ?>" placeholder="fragment error_message">
            </div>
            <div class="form-group">
                <label>Tylko błędy</label>
                <select name="only_errors">
                    <option value="0" <?php echo !$onlyErrors ? 'selected' : ''; ?>>Nie</option>
                    <option value="1" <?php echo $onlyErrors ? 'selected' : ''; ?>>Tak</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filtruj</button>
            <a href="<?php echo url('finanse.fakturownia-logs'); ?>" class="btn btn-secondary">Wyczyść</a>
        </form>

        <?php if (!empty($rows)): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Data</th>
                        <th>Metoda</th>
                        <th>Endpoint</th>
                        <th>Status</th>
                        <th>Retry</th>
                        <th>Błąd</th>
                        <th>Szczegóły</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $stClass = statusClass($row['http_status']); ?>
                        <tr>
                            <td><?php echo (int)$row['id']; ?></td>
                            <td>
                                <?php echo e(date('d.m.Y H:i:s', strtotime((string)$row['created_at']))); ?>
                                <div class="small"><?php echo e(date('D', strtotime((string)$row['created_at']))); ?></div>
                            </td>
                            <td><?php echo e((string)$row['http_method']); ?></td>
                            <td><strong><?php echo e((string)$row['endpoint']); ?></strong></td>
                            <td><span class="status-tag status-<?php echo e($stClass); ?>"><?php echo e(statusLabel($row['http_status'])); ?></span></td>
                            <td><?php echo (int)$row['retry_count']; ?></td>
                            <td class="error-text"><?php echo e(shortText($row['error_message'] ?? '', 120)); ?></td>
                            <td>
                                <details>
                                    <summary>Pokaż</summary>
                                    <pre>request_json:
<?php echo e(shortText((string)($row['request_json'] ?? ''), 1800)); ?>

response_json:
<?php echo e(shortText((string)($row['response_json'] ?? ''), 1800)); ?></pre>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo logsPageLink($page - 1); ?>">‹</a>
                    <?php else: ?>
                        <span class="disabled">‹</span>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo logsPageLink($i); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo logsPageLink($page + 1); ?>">›</a>
                    <?php else: ?>
                        <span class="disabled">›</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="no-data">Brak wpisów logów dla wybranych filtrów.</div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
