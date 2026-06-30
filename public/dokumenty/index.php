<?php
/**
 * BRYGAD ERP - Moduł Dokumenty Kosztowe (Lista)
 * 
 * Wyświetla listę faktur kosztowych z możliwością filtrowania i zarządzania.
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin(); // Tylko admin w v1

$pdo = getDbConnection();

// Filtrowanie
$vendor_id = $_GET['vendor_id'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Sortowanie
$sort = $_GET['sort'] ?? 'issue_date';
$order = $_GET['order'] ?? 'DESC';

// Walidacja sortowania
$allowed_sorts = ['issue_date', 'amount_net', 'amount_gross', 'status', 'number'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'issue_date';
}
$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Komunikaty sukcesu i błędów
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Budowanie zapytania
$sql = "SELECT d.*, 
        COALESCE(i.name, d.source_name) as vendor_display,
        i.name as vendor_name,
        d.source_name
        FROM documents d
        LEFT JOIN investors i ON d.vendor_id = i.id
        WHERE d.type = 'invoice_cost'";
$params = [];

if ($vendor_id !== '') {
    $sql .= " AND d.vendor_id = :vendor_id";
    $params['vendor_id'] = $vendor_id;
}

if ($status !== '') {
    $sql .= " AND d.status = :status";
    $params['status'] = $status;
}

if ($date_from !== '') {
    $sql .= " AND d.issue_date >= :date_from";
    $params['date_from'] = $date_from;
}

if ($date_to !== '') {
    $sql .= " AND d.issue_date <= :date_to";
    $params['date_to'] = $date_to;
}

// Wyszukiwarka globalna (po numerze, kontrahenci, opisie)
if ($search !== '') {
    $sql .= " AND (d.number LIKE :search 
                   OR d.source_name LIKE :search 
                   OR i.name LIKE :search 
                   OR d.description LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

$sql .= " ORDER BY d.$sort $order, d.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Funkcja do generowania kolorów dla wierszy
function getRowColor($index) {
    $hue = ($index * 137.508) % 360;
    return [
        'hsl' => "hsl($hue, 70%, 95%)",
        'border' => "hsl($hue, 60%, 65%)"
    ];
}

// Pobierz alokacje dla wszystkich dokumentów (bez N+1 query)
$allocations_by_doc = [];
if (count($documents) > 0) {
    $document_ids = array_column($documents, 'id');
    $placeholders = implode(',', array_fill(0, count($document_ids), '?'));
    
    $alloc_sql = "
        SELECT da.document_id, da.amount_net, da.category,
               p.name as project_name,
               pcn.name as cost_node_name
        FROM document_allocations da
        JOIN projects p ON da.project_id = p.id
        LEFT JOIN project_cost_nodes pcn ON da.cost_node_id = pcn.id
        WHERE da.document_id IN ($placeholders)
        ORDER BY da.created_at ASC
    ";
    
    $alloc_stmt = $pdo->prepare($alloc_sql);
    $alloc_stmt->execute($document_ids);
    
    foreach ($alloc_stmt->fetchAll() as $alloc) {
        $allocations_by_doc[$alloc['document_id']][] = $alloc;
    }
}

// Pobierz listę kontrahentów do filtra
$vendors = $pdo->query("SELECT id, name FROM investors WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Statystyki
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived
FROM documents WHERE type = 'invoice_cost'";
$stats = $pdo->query($stats_sql)->fetch();

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$isAdminUser = isAdmin();

// Grupowanie po dniach dla widoku accordion
$documentsByDate = [];
foreach ($documents as $doc) {
    $date = $doc['issue_date'];
    if (!isset($documentsByDate[$date])) {
        $documentsByDate[$date] = [
            'documents' => [],
            'total_net' => 0,
            'total_gross' => 0,
            'count' => 0
        ];
    }
    $documentsByDate[$date]['documents'][] = $doc;
    $documentsByDate[$date]['total_net'] += $doc['amount_net'];
    $documentsByDate[$date]['total_gross'] += $doc['amount_gross'];
    $documentsByDate[$date]['count']++;
}
krsort($documentsByDate); // Sortuj daty malejąco (najnowsze najpierw)
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo e(APP_NAME); ?> - Dokumenty Kosztowe</title>
    <style>
        /* MODERNIZACJA 2026-02-15 - Nowe style */
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --bg-body: #f5f7fa;
            --bg-card: #ffffff;
            --border: #e5e7eb;
            --border-light: #f3f4f6;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #eab308;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
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
            color: #ea580c;
            text-decoration: none;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header h2 {
            font-size: 32px;
            color: #333;
        }
        .actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .btn-color-mode {
            width: 38px;
            height: 38px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            padding: 0;
        }
        .btn-color-mode:hover {
            background: #f9fafb;
            border-color: var(--primary);
        }
        .btn-color-mode.active {
            background: linear-gradient(135deg, #fce7f3, #e0e7ff);
            border-color: #a78bfa;
        }
        .btn-color-mode svg {
            width: 18px;
            height: 18px;
        }
        .btn-group-mode {
            width: 38px;
            height: 38px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            padding: 0;
        }
        .btn-group-mode:hover {
            background: #f9fafb;
            border-color: var(--primary);
        }
        .btn-group-mode svg {
            width: 18px;
            height: 18px;
            transition: transform 0.2s;
        }
        body.grouped-mode .btn-group-mode {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-color: var(--primary);
        }
        body.grouped-mode .btn-group-mode svg {
            stroke: white;
        }
        
        /* Accordion dla grupowania po dniach */
        .day-group {
            margin-bottom: 15px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border);
            background: white;
        }
        .day-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #f9fafb;
            cursor: pointer;
            transition: background 0.2s;
        }
        .day-header:hover {
            background: #f3f4f6;
        }
        .day-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .day-date {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-main);
        }
        .day-count {
            font-size: 12px;
            color: var(--text-muted);
            background: white;
            padding: 2px 8px;
            border-radius: 10px;
        }
        .day-total {
            font-weight: 700;
            font-size: 15px;
            color: var(--primary);
        }
        .day-arrow {
            transition: transform 0.3s;
            color: var(--text-muted);
        }
        .day-group.collapsed .day-arrow {
            transform: rotate(-90deg);
        }
        .day-content {
            max-height: 2000px;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        .day-group.collapsed .day-content {
            max-height: 0;
        }
        .day-content table {
            margin: 0;
            border-radius: 0;
            box-shadow: none;
        }
        .day-content thead th {
            background: white !important;
        }
        .btn {
            padding: 12px 24px;
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
            background: linear-gradient(135deg, #ea580c 0%, #dc2626 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(234, 88, 12, 0.4);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-decoration: none;
            display: block;
            transition: all 0.2s;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .stat-card.active {
            background: linear-gradient(135deg, #ea580c 0%, #dc2626 100%);
            box-shadow: 0 4px 16px rgba(234, 88, 12, 0.4);
        }
        .stat-card.active .stat-label,
        .stat-card.active .stat-value {
            color: white;
        }
        .stat-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #ea580c;
        }
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .search-bar {
            padding: 20px;
            background: white;
            border-bottom: 2px solid #e0e0e0;
        }
        .search-wrapper {
            position: relative;
            max-width: 500px;
        }
        .search-input {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.2s;
        }
        .search-input:focus {
            outline: none;
            border-color: #ea580c;
            box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.1);
        }
        .search-input::placeholder {
            color: #999;
        }
        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            pointer-events: none;
        }
        /* SPX FILTER SYSTEM */
        .spx-filter-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .spx-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"] {
            padding: 0 12px;
            height: 38px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            font-family: inherit;
            transition: border-color 0.15s;
            min-width: 150px;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input[type="date"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: white; border: 1px solid #e5e7eb; border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; white-space: nowrap; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: #667eea; color: #667eea; }
        .spx-quick-btn.active { background: #667eea; border-color: #667eea; color: white; font-weight: 600; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: #f8f9fa;
        }
        th {
            padding: 10px 14px !important;
            text-align: left;
            font-weight: 600;
            color: var(--text-muted) !important;
            border-bottom: 1px solid #000000 !important;
            font-size: 11px !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px;
            background: #f9fafb !important;
        }
        th.sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            transition: all 0.2s;
        }
        th.sortable:hover {
            background: #e9ecef !important;
            color: #ea580c !important;
        }
        .sort-icon {
            opacity: 0.3;
            margin-left: 5px;
        }
        th.sortable:hover .sort-icon {
            opacity: 0.6;
        }
        th.sortable.asc .sort-icon::after,
        th.sortable.desc .sort-icon::after {
            opacity: 1;
            font-weight: bold;
        }
        th.sortable.asc .sort-icon::after {
            content: ' ↑';
        }
        th.sortable.desc .sort-icon::after {
            content: ' ↓';
        }
        td {
            padding: 10px 14px !important;
            border: 1px solid #000000 !important;
            font-size: 13px !important;
            vertical-align: middle;
        }
        th {
            border: 1px solid #000000 !important;
        }
        /* Tryb WYŁĄCZONY - Zebra-striping */
        body.no-colors tbody tr:nth-child(odd) {
            background: #ffffff !important;
            border-left: 4px solid transparent !important;
        }
        body.no-colors tbody tr:nth-child(even) {
            background: #f8fafc !important;
            border-left: 4px solid transparent !important;
        }
        body.no-colors tbody tr:hover {
            background: #e0f2fe !important;
        }
        
        /* Tryb WŁĄCZONY - Kolorowe wiersze (domyślnie) */
        body:not(.no-colors) tbody tr {
            background: var(--row-bg, #ffffff) !important;
            border-left: 4px solid var(--row-border, transparent) !important;
        }
        body:not(.no-colors) tbody tr:hover {
            filter: brightness(0.95) !important;
        }
        .doc-number {
            font-weight: 600;
            color: #333;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-draft {
            background: #fff3cd;
            color: #856404;
        }
        .badge-approved {
            background: #d4edda;
            color: #155724;
        }
        .badge-archived {
            background: #e2e3e5;
            color: #383d41;
        }
        .action-links {
            display: flex;
            gap: 12px;
        }
        .action-links a {
            color: #ea580c;
            text-decoration: none;
            font-size: 14px;
        }
        .action-links a:hover {
            text-decoration: underline;
        }
        .no-data {
            padding: 60px 20px;
            text-align: center;
            color: #999;
            font-size: 16px;
        }
        details {
            margin-top: 8px;
        }
        summary {
            cursor: pointer;
            font-size: 13px;
            color: #666;
            font-weight: 600;
            user-select: none;
        }
        summary:hover {
            color: #ea580c;
        }
        .alloc-mini-table {
            margin-top: 8px;
            font-size: 12px;
            border-left: 3px solid #ea580c;
            padding-left: 10px;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
        }
        .alloc-mini-table .alloc-row {
            padding: 4px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .alloc-mini-table .alloc-row:last-child {
            border-bottom: none;
        }
        .alloc-mini-table .alloc-project {
            font-weight: 600;
            color: #333;
        }
        .alloc-mini-table .alloc-amount {
            color: #ea580c;
            font-weight: 600;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        .btn-danger-small {
            background: #dc3545;
            color: white;
            padding: 4px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
            margin-left: 8px;
        }
        .btn-danger-small:hover {
            background: #c82333;
        }
        .delete-doc-form {
            display: inline;
            margin: 0;
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="breadcrumb">
            <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> / 
            Dokumenty Kosztowe
        </div>
        
        <div class="page-header">
            <h2>Dokumenty Kosztowe (Faktury)</h2>
            <div class="actions">
                <button type="button" class="btn-color-mode active" onclick="toggleColors()" title="Kolory wierszy">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 2a10 10 0 0 1 0 20"/>
                        <circle cx="12" cy="12" r="4"/>
                    </svg>
                </button>
                <button type="button" class="btn-group-mode" onclick="toggleGrouping()" title="Grupuj po dniach">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </button>
                <a href="<?php echo url('dokumenty.create'); ?>" class="btn btn-primary">+ Dodaj Dokument</a>
            </div>
        </div>
        
        <?php if ($success === 'created'): ?>
            <div class="alert alert-success">
                Dokument został pomyślnie utworzony.
            </div>
        <?php elseif ($success === 'updated'): ?>
            <div class="alert alert-success">
                Dokument został pomyślnie zaktualizowany.
            </div>
        <?php elseif ($success === 'approved'): ?>
            <div class="alert alert-success">
                Dokument został zatwierdzony.
            </div>
        <?php elseif ($success === 'archived'): ?>
            <div class="alert alert-success">
                Dokument został zarchiwizowany.
            </div>
        <?php elseif ($success === 'deleted'): ?>
            <div class="alert alert-success">
                Dokument został pomyślnie usunięty.
            </div>
        <?php endif; ?>
        
        <?php if ($error === 'delete_failed'): ?>
            <div class="alert alert-error">
                Wystąpił błąd podczas usuwania dokumentu. Sprawdź logi.
            </div>
        <?php elseif ($error === 'not_found'): ?>
            <div class="alert alert-error">
                Nie znaleziono dokumentu do usunięcia.
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <a href="<?php echo url('dokumenty'); ?>" class="stat-card<?php echo $status === '' ? ' active' : ''; ?>">
                <div class="stat-label">Wszystkie</div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
            </a>
            <a href="<?php echo url('dokumenty', ['status' => 'draft']); ?>" class="stat-card<?php echo $status === 'draft' ? ' active' : ''; ?>">
                <div class="stat-label">Wersje robocze</div>
                <div class="stat-value"><?php echo $stats['draft']; ?></div>
            </a>
            <a href="<?php echo url('dokumenty', ['status' => 'approved']); ?>" class="stat-card<?php echo $status === 'approved' ? ' active' : ''; ?>">
                <div class="stat-label">Zatwierdzone</div>
                <div class="stat-value"><?php echo $stats['approved']; ?></div>
            </a>
            <a href="<?php echo url('dokumenty', ['status' => 'archived']); ?>" class="stat-card<?php echo $status === 'archived' ? ' active' : ''; ?>">
                <div class="stat-label">Zarchiwizowane</div>
                <div class="stat-value"><?php echo $stats['archived']; ?></div>
            </a>
        </div>
        
        <div class="card">
            <!-- Wyszukiwarka globalna -->
            <form method="GET" action="" class="search-bar" style="display: flex; gap: 15px; align-items: center;">
                <div class="search-wrapper" style="flex: 1;">
                    <input type="text" 
                           name="search" 
                           class="search-input" 
                           placeholder="Szukaj po numerze, kontrahenci, opisie..."
                           value="<?php echo e($search); ?>">
                    <span class="search-icon">🔍</span>
                </div>
                <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Szukaj</button>
                <?php if ($search): ?>
                    <a href="?<?php echo http_build_query(array_diff_key($_GET, ['search' => ''])); ?>" 
                       class="btn btn-secondary" 
                       style="white-space: nowrap;">Wyczyść</a>
                <?php endif; ?>
                <!-- Zachowaj filtry przy wyszukiwaniu -->
                <?php if ($vendor_id): ?><input type="hidden" name="vendor_id" value="<?php echo e($vendor_id); ?>"><?php endif; ?>
                <?php if ($status): ?><input type="hidden" name="status" value="<?php echo e($status); ?>"><?php endif; ?>
                <?php if ($date_from): ?><input type="hidden" name="date_from" value="<?php echo e($date_from); ?>"><?php endif; ?>
                <?php if ($date_to): ?><input type="hidden" name="date_to" value="<?php echo e($date_to); ?>"><?php endif; ?>
                <?php if ($sort !== 'issue_date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
            </form>
            
            <!-- Filtry -->
            <?php
            $dokYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
            if ($dokYear < 2020 || $dokYear > 2030) $dokYear = (int)date('Y');
            $dokMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
            if ($dokMonth >= 1 && $dokMonth <= 12 && !$date_from) {
                $date_from = sprintf('%04d-%02d-01', $dokYear, $dokMonth);
                $date_to   = date('Y-m-t', strtotime($date_from));
            }
            $dokMonthNames = [1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'];
            $dokYearRange = range((int)date('Y') - 3, (int)date('Y'));
            $dokActiveMonth = 0;
            for ($m = 1; $m <= 12; $m++) {
                $mS = sprintf('%04d-%02d-01', $dokYear, $m);
                if ($date_from === $mS && $date_to === date('Y-m-t', strtotime($mS))) { $dokActiveMonth = $m; break; }
            }
            $dokToday = date('Y-m-d'); $dokWeekAgo = date('Y-m-d', strtotime('-7 days'));
            $dokMonthStart = date('Y-m-01'); $dokMonthEnd = date('Y-m-t');
            ?>
            <form method="GET" action="" class="spx-filter-bar" id="dokFilterForm">
                <div class="spx-filter-group">
                    <label>Kontrahent</label>
                    <select name="vendor_id">
                        <option value="">Wszyscy</option>
                        <?php foreach ($vendors as $vendor): ?>
                            <option value="<?php echo $vendor['id']; ?>" <?php echo $vendor_id == $vendor['id'] ? 'selected' : ''; ?>>
                                <?php echo e($vendor['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">Wszystkie</option>
                        <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Wersja robocza</option>
                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Zatwierdzone</option>
                        <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Zarchiwizowane</option>
                    </select>
                </div>
                <div class="spx-filter-group">
                    <label>Miesiac</label>
                    <select name="month" id="dokSelectMonth" onchange="dokOnMonthYearChange()">
                        <option value="0" <?php echo $dokActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                        <?php foreach ($dokMonthNames as $mn => $mName): ?>
                            <option value="<?php echo $mn; ?>" <?php echo ($dokActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group">
                    <label>Rok</label>
                    <select name="year" id="dokSelectYear" onchange="dokOnMonthYearChange()">
                        <?php foreach ($dokYearRange as $yr): ?>
                            <option value="<?php echo $yr; ?>" <?php echo ($dokYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="spx-filter-group">
                    <label>Od</label>
                    <input type="date" name="date_from" id="dokInputDateFrom" value="<?php echo e($date_from); ?>">
                </div>
                <div class="spx-filter-group">
                    <label>Do</label>
                    <input type="date" name="date_to" id="dokInputDateTo" value="<?php echo e($date_to); ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="height: 38px; align-self: flex-end;">Filtruj</button>
                <?php if ($vendor_id || $status || $date_from || $date_to || $search): ?>
                    <a href="<?php echo url('dokumenty'); ?>" class="btn btn-secondary" style="height: 38px; align-self: flex-end; display: inline-flex; align-items: center;">Wyczysc</a>
                <?php endif; ?>
                <?php if ($search): ?><input type="hidden" name="search" value="<?php echo e($search); ?>"><?php endif; ?>
                <?php if ($sort !== 'issue_date'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
                <?php if ($order !== 'DESC'): ?><input type="hidden" name="order" value="<?php echo e($order); ?>"><?php endif; ?>
            </form>
            <div class="spx-controls-bar">
                <div class="spx-controls-left">
                    <a href="?date_from=<?php echo $dokToday; ?>&date_to=<?php echo $dokToday; ?>" class="spx-quick-btn <?php echo ($date_from === $dokToday && $date_to === $dokToday) ? 'active' : ''; ?>">Dzis</a>
                    <a href="?date_from=<?php echo $dokWeekAgo; ?>&date_to=<?php echo $dokToday; ?>" class="spx-quick-btn <?php echo ($date_from === $dokWeekAgo && $date_to === $dokToday) ? 'active' : ''; ?>">7 dni</a>
                    <a href="?date_from=<?php echo $dokMonthStart; ?>&date_to=<?php echo $dokMonthEnd; ?>&year=<?php echo date('Y'); ?>" class="spx-quick-btn <?php echo ($date_from === $dokMonthStart && $date_to === $dokMonthEnd) ? 'active' : ''; ?>">Ten miesiac</a>
                </div>
            </div>
            
            <?php if (count($documents) > 0): ?>
                <!-- Tabela normalna (domyślnie ukryta) -->
                <table class="normal-view" style="display: none;">
                    <thead>
                        <tr>
                            <th data-sort="date" class="sortable">Data wystawienia <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Numer <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Kontrahent <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Kwota netto <span class="sort-icon"></span></th>
                            <th data-sort="number" class="sortable text-right">Kwota brutto <span class="sort-icon"></span></th>
                            <th data-sort="string" class="sortable">Status <span class="sort-icon"></span></th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowIndex = 0;
                        foreach ($documents as $doc): 
                            $colors = getRowColor($rowIndex++);
                        ?>
                            <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>">
                                <td><?php echo formatDate($doc['issue_date']); ?></td>
                                <td class="doc-number">
                                    <?php echo e($doc['number']); ?>
                                    <?php 
                                    $doc_allocations = $allocations_by_doc[$doc['id']] ?? [];
                                    $alloc_count = count($doc_allocations);
                                    if ($alloc_count > 0): 
                                    ?>
                                        <details>
                                            <summary>Alokacje: <?php echo $alloc_count; ?></summary>
                                            <div class="alloc-mini-table">
                                                <?php foreach ($doc_allocations as $alloc): ?>
                                                    <div class="alloc-row">
                                                        <span class="alloc-project"><?php echo e($alloc['project_name']); ?></span>
                                                        <?php if ($alloc['cost_node_name']): ?>
                                                            → <?php echo e($alloc['cost_node_name']); ?>
                                                        <?php endif; ?>
                                                        <br>
                                                        <span class="alloc-amount"><?php echo number_format($alloc['amount_net'], 2, ',', ' '); ?> PLN</span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo e($doc['vendor_display']); ?></td>
                                <td><?php echo number_format($doc['amount_net'], 2, ',', ' '); ?> <?php echo e($doc['currency']); ?></td>
                                <td><?php echo number_format($doc['amount_gross'], 2, ',', ' '); ?> <?php echo e($doc['currency']); ?></td>
                                <td>
                                    <?php
                                    $statusLabels = [
                                        'draft' => 'Wersja robocza',
                                        'approved' => 'Zatwierdzone',
                                        'archived' => 'Zarchiwizowane'
                                    ];
                                    ?>
                                    <span class="badge badge-<?php echo $doc['status']; ?>">
                                        <?php echo $statusLabels[$doc['status']]; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-links">
                                        <?php if ($doc['status'] === 'draft'): ?>
                                            <a href="<?php echo url('dokumenty.edit', ['id' => $doc['id']]); ?>">Edytuj</a>
                                        <?php else: ?>
                                            <a href="<?php echo url('dokumenty.edit', ['id' => $doc['id']]); ?>">Podgląd</a>
                                        <?php endif; ?>
                                        <?php if ($isAdminUser): ?>
                                            <form method="POST" action="<?php echo url('dokumenty.delete'); ?>" class="delete-doc-form" 
                                                  onsubmit="return confirm('Czy na pewno chcesz usunąć dokument <?php echo e($doc['number']); ?>? Zostaną usunięte wszystkie alokacje i pozycje!');">
                                                <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" class="btn-danger-small">Usuń</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Tabela zgrupowana po dniach (domyślnie widoczna) -->
                <div class="grouped-view" style="display: block;">
                    <?php foreach ($documentsByDate as $date => $dayData): ?>
                        <div class="day-group">
                            <div class="day-header" onclick="toggleDay(this)">
                                <div class="day-info">
                                    <span class="day-date"><?php echo formatDate($date); ?></span>
                                    <span class="day-count"><?php echo $dayData['count']; ?> dokument<?php echo $dayData['count'] == 1 ? '' : ($dayData['count'] < 5 ? 'y' : 'ów'); ?></span>
                                </div>
                                <div class="day-total"><?php echo formatMoney($dayData['total_gross']); ?></div>
                                <svg class="day-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </div>
                            <div class="day-content">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Numer</th>
                                            <th>Kontrahent</th>
                                            <th class="text-right">Kwota netto</th>
                                            <th class="text-right">Kwota brutto</th>
                                            <th>Status</th>
                                            <th>Akcje</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $rowIndex = 0;
                                        foreach ($dayData['documents'] as $doc): 
                                            $colors = getRowColor($rowIndex++);
                                            $doc_allocations = $allocations_by_doc[$doc['id']] ?? [];
                                            $alloc_count = count($doc_allocations);
                                        ?>
                                            <tr data-row-color="<?php echo e($colors['hsl']); ?>" data-row-border="<?php echo e($colors['border']); ?>">
                                                <td class="doc-number">
                                                    <?php echo e($doc['number']); ?>
                                                    <?php if ($alloc_count > 0): ?>
                                                        <details>
                                                            <summary>Alokacje: <?php echo $alloc_count; ?></summary>
                                                            <div class="alloc-mini-table">
                                                                <?php foreach ($doc_allocations as $alloc): ?>
                                                                    <div class="alloc-row">
                                                                        <span class="alloc-project"><?php echo e($alloc['project_name']); ?></span>
                                                                        <?php if ($alloc['cost_node_name']): ?>
                                                                            → <?php echo e($alloc['cost_node_name']); ?>
                                                                        <?php endif; ?>
                                                                        <br>
                                                                        <span class="alloc-amount"><?php echo number_format($alloc['amount_net'], 2, ',', ' '); ?> PLN</span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </details>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo e($doc['vendor_display']); ?></td>
                                                <td><?php echo number_format($doc['amount_net'], 2, ',', ' '); ?> <?php echo e($doc['currency']); ?></td>
                                                <td><?php echo number_format($doc['amount_gross'], 2, ',', ' '); ?> <?php echo e($doc['currency']); ?></td>
                                                <td>
                                                    <?php
                                                    $statusLabels = [
                                                        'draft' => 'Wersja robocza',
                                                        'approved' => 'Zatwierdzone',
                                                        'archived' => 'Zarchiwizowane'
                                                    ];
                                                    ?>
                                                    <span class="badge badge-<?php echo $doc['status']; ?>">
                                                        <?php echo $statusLabels[$doc['status']]; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-links">
                                                        <?php if ($doc['status'] === 'draft'): ?>
                                                            <a href="<?php echo url('dokumenty.edit', ['id' => $doc['id']]); ?>">Edytuj</a>
                                                        <?php else: ?>
                                                            <a href="<?php echo url('dokumenty.edit', ['id' => $doc['id']]); ?>">Podgląd</a>
                                                        <?php endif; ?>
                                                        <?php if ($isAdminUser): ?>
                                                            <form method="POST" action="<?php echo url('dokumenty.delete'); ?>" class="delete-doc-form" 
                                                                  onsubmit="return confirm('Czy na pewno chcesz usunąć dokument <?php echo e($doc['number']); ?>? Zostaną usunięte wszystkie alokacje i pozycje!');">
                                                                <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                                                                <button type="submit" class="btn-danger-small">Usuń</button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-data">
                    Brak dokumentów do wyświetlenia.
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
    </footer>

    <script>
        // ========================================
        // TOGGLE KOLORÓW
        // ========================================
        function toggleColors() {
            document.body.classList.toggle('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) btn.classList.toggle('active');
            
            const isColored = !document.body.classList.contains('no-colors');
            localStorage.setItem('dokumentyColorsEnabled', isColored ? '1' : '0');
            
            // Zastosuj/usuń kolory
            document.querySelectorAll('tbody tr[data-row-color]').forEach(tr => {
                if (isColored) {
                    tr.style.setProperty('--row-bg', tr.dataset.rowColor);
                    tr.style.setProperty('--row-border', tr.dataset.rowBorder);
                } else {
                    tr.style.removeProperty('--row-bg');
                    tr.style.removeProperty('--row-border');
                }
            });
        }
        
        // ========================================
        // TOGGLE GRUPOWANIA
        // ========================================
        function toggleGrouping() {
            document.body.classList.toggle('grouped-mode');
            const isGrouped = document.body.classList.contains('grouped-mode');
            localStorage.setItem('dokumentyGroupingEnabled', isGrouped ? '1' : '0');
            
            const normalView = document.querySelector('.normal-view');
            const groupedView = document.querySelector('.grouped-view');
            
            if (isGrouped) {
                normalView.style.display = 'none';
                groupedView.style.display = 'block';
            } else {
                normalView.style.display = 'table';
                groupedView.style.display = 'none';
            }
        }
        
        // ========================================
        // TOGGLE DZIEŃ (accordion)
        // ========================================
        function toggleDay(header) {
            header.closest('.day-group').classList.toggle('collapsed');
        }
        
        // ========================================
        // INICJALIZACJA
        // ========================================
        document.addEventListener('DOMContentLoaded', function() {
            // 1. Kolory
            const colorsEnabled = localStorage.getItem('dokumentyColorsEnabled');
            if (colorsEnabled === '0') {
                document.body.classList.add('no-colors');
                const btn = document.querySelector('.btn-color-mode');
                if (btn) btn.classList.remove('active');
            } else {
                // Zastosuj kolory
                document.querySelectorAll('tbody tr[data-row-color]').forEach(tr => {
                    tr.style.setProperty('--row-bg', tr.dataset.rowColor);
                    tr.style.setProperty('--row-border', tr.dataset.rowBorder);
                });
            }
            
            // 2. Grupowanie - DOMYŚLNIE WŁĄCZONE
            const groupingEnabled = localStorage.getItem('dokumentyGroupingEnabled');
            if (groupingEnabled === '0') {
                // Użytkownik ręcznie wyłączył - przełącz na widok normalny
                document.body.classList.remove('grouped-mode');
                const normalView = document.querySelector('.normal-view');
                const groupedView = document.querySelector('.grouped-view');
                if (normalView && groupedView) {
                    normalView.style.display = 'table';
                    groupedView.style.display = 'none';
                }
            } else {
                // Domyślnie lub '1' - zostaw grupowanie włączone
                document.body.classList.add('grouped-mode');
            }
            
            // 3. SORTOWANIE JS (dla widoku normalnego)
            const table = document.querySelector('.normal-view');
            if (!table) return;
            
            const headers = table.querySelectorAll('th.sortable');
            const tbody = table.querySelector('tbody');
            
            headers.forEach(function(header, columnIndex) {
                header.addEventListener('click', function() {
                    const sortType = this.dataset.sort;
                    const isAsc = this.classList.contains('asc');
                    
                    // Reset wszystkich nagłówków
                    headers.forEach(function(h) { 
                        h.classList.remove('asc', 'desc'); 
                    });
                    
                    // Ustaw nowy kierunek
                    this.classList.add(isAsc ? 'desc' : 'asc');
                    const direction = isAsc ? -1 : 1;
                    
                    // Pobierz wiersze i sortuj
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    
                    rows.sort(function(a, b) {
                        let aVal = a.cells[columnIndex].textContent.trim();
                        let bVal = b.cells[columnIndex].textContent.trim();
                        
                        if (sortType === 'number') {
                            aVal = parseFloat(aVal.replace(/[^\d,.\-]/g, '').replace(',', '.')) || 0;
                            bVal = parseFloat(bVal.replace(/[^\d,.\-]/g, '').replace(',', '.')) || 0;
                            return (aVal - bVal) * direction;
                        } else if (sortType === 'date') {
                            const parseDate = function(str) {
                                if (!str || str === '–' || str === '-') return 0;
                                const parts = str.split('.');
                                if (parts.length === 3) {
                                    return new Date(parts[2], parts[1] - 1, parts[0]).getTime();
                                }
                                return 0;
                            };
                            return (parseDate(aVal) - parseDate(bVal)) * direction;
                        } else {
                            aVal = aVal.toLowerCase();
                            bVal = bVal.toLowerCase();
                            if (aVal < bVal) return -1 * direction;
                            if (aVal > bVal) return 1 * direction;
                            return 0;
                        }
                    });
                    
                    // Odbuduj tabelę
                    rows.forEach(function(row) {
                        tbody.appendChild(row);
                    });
                });
            });
        });

        function dokOnMonthYearChange() {
            const month = parseInt(document.getElementById('dokSelectMonth').value);
            const year  = parseInt(document.getElementById('dokSelectYear').value);
            if (!month) return;
            const lastDay = new Date(year, month, 0).getDate();
            const pad = n => String(n).padStart(2, '0');
            document.getElementById('dokInputDateFrom').value = year + '-' + pad(month) + '-01';
            document.getElementById('dokInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
            document.getElementById('dokFilterForm').submit();
        }
    </script>
</body>
</html>
