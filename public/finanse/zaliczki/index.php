<?php
/**
 * BRYGAD ERP - Zaliczki pracownicze - Lista
 * 
 * Wyświetla listę zaliczek pracowniczych (firmowych i prywatnych)
 * Tylko dla administratorów
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/payroll_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Filtrowanie
$filters = [
    'worker_id' => $_GET['worker_id'] ?? null,
    'type' => $_GET['type'] ?? null,
    'status' => $_GET['status'] ?? null,
    'from_date' => $_GET['from_date'] ?? null,
    'to_date' => $_GET['to_date'] ?? null,
];

// Buduj zapytanie SQL
$sql = "
    SELECT 
        vad.*,
        (SELECT COUNT(*) FROM worker_advance_files WHERE advance_id = vad.advance_id) as files_count
    FROM v_worker_advances_details vad
    WHERE 1=1
";
$params = [];

if ($filters['worker_id']) {
    $sql .= " AND vad.worker_id = :worker_id";
    $params[':worker_id'] = $filters['worker_id'];
}
if ($filters['type']) {
    $sql .= " AND vad.type = :type";
    $params[':type'] = $filters['type'];
}
if ($filters['status']) {
    $sql .= " AND vad.status = :status";
    $params[':status'] = $filters['status'];
}
if ($filters['from_date']) {
    $sql .= " AND vad.issue_date >= :from_date";
    $params[':from_date'] = $filters['from_date'];
}
if ($filters['to_date']) {
    $sql .= " AND vad.issue_date <= :to_date";
    $params[':to_date'] = $filters['to_date'];
}

$sql .= " ORDER BY vad.issue_date DESC, vad.advance_id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $advances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pobierz listę pracowników do filtra
    $stmt = $pdo->query("SELECT id, first_name, last_name FROM workers WHERE is_active = 1 ORDER BY last_name, first_name");
    $workers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Statystyki
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
            SUM(CASE WHEN status = 'open' THEN amount_remaining ELSE 0 END) as open_amount,
            SUM(amount) as total_amount
        FROM v_worker_advances_details
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching advances: " . $e->getMessage());
    die("Błąd pobierania danych zaliczek: " . $e->getMessage());
}

// Grupowanie po dniach dla widoku accordion
$advancesByDate = [];
foreach ($advances as $adv) {
    $date = $adv['issue_date'];
    if (!isset($advancesByDate[$date])) {
        $advancesByDate[$date] = [
            'advances' => [],
            'total' => 0,
            'count' => 0
        ];
    }
    $advancesByDate[$date]['advances'][] = $adv;
    $advancesByDate[$date]['total'] += $adv['amount'];
    $advancesByDate[$date]['count']++;
}
krsort($advancesByDate); // Sortuj daty malejąco (najnowsze najpierw)

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

// ========================================
// FUNKCJA: Generowanie koloru pracownika
// ========================================
function getWorkerColor($workerId) {
    $goldenRatio = 0.618033988749895;
    $hue = fmod($workerId * $goldenRatio, 1.0) * 360;
    $saturation = 65;
    $lightness = 55;
    return [
        'hslLight' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.08)",
        'hslBorder' => "hsla({$hue}, {$saturation}%, {$lightness}%, 0.6)"
    ];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Zaliczki pracownicze</title>
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
            color: #ea580c;
            text-decoration: none;
        }
        .breadcrumbs a:hover {
            text-decoration: underline;
        }
        .breadcrumbs span {
            margin: 0 5px;
        }
        
        /* Stats bar */
        .stats-bar {
            background: white;
            border-radius: 12px;
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .stat-item {
            text-align: center;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #ea580c;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Actions bar */
        .actions-bar {
            background: white;
            border-radius: 12px;
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        /* SPX FILTER SYSTEM */
        .spx-filter-bar {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
            align-items: flex-end;
            flex: 1;
        }
        .spx-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .spx-filter-group.fg-worker  { flex: 1.5 1 0; }
        .spx-filter-group.fg-type    { flex: 1   1 0; }
        .spx-filter-group.fg-status  { flex: 1   1 0; }
        .spx-filter-group.fg-month   { flex: 1.2 1 0; }
        .spx-filter-group.fg-year    { flex: 0.7 1 0; }
        .spx-filter-group.fg-date    { flex: 1   1 0; }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .spx-filter-group select,
        .spx-filter-group input[type="date"] {
            padding: 0 8px;
            height: 38px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            font-family: inherit;
            transition: border-color 0.15s;
            width: 100%;
        }
        .spx-filter-group select:focus,
        .spx-filter-group input[type="date"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        @media (max-width: 1024px) { .spx-filter-bar { flex-wrap: wrap; } .spx-filter-group { flex: 1 1 auto !important; min-width: 120px; } }
        @media (max-width: 768px) { .spx-filter-bar { flex-wrap: wrap !important; gap: 10px; } .spx-filter-group { flex: 1 1 calc(50% - 10px) !important; min-width: 120px !important; } .spx-filter-group select, .spx-filter-group input[type="date"] { height: 44px; font-size: 14px; } }
        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .spx-controls-left, .spx-controls-right { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .spx-quick-btn { padding: 0 12px; height: 28px; background: white; border: 1px solid #e5e7eb; border-radius: 5px; font-size: 12px; font-weight: 500; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; white-space: nowrap; }
        .spx-quick-btn:hover { background: #f9fafb; border-color: #667eea; color: #667eea; }
        .spx-quick-btn.active { background: #667eea; border-color: #667eea; color: white; font-weight: 600; }
        
        .btn-primary {
            padding: 12px 24px;
            background: linear-gradient(135deg, #ea580c 0%, #dc2626 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
            box-shadow: 0 4px 6px rgba(234, 88, 12, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(234, 88, 12, 0.4);
        }
        
        /* Table */
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
        }
        th {
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        
        /* Kolory pracowników - tryb włączony */
        body:not(.no-colors) tbody tr {
            background: var(--worker-bg, transparent);
            border-left: 4px solid var(--worker-color, #667eea);
        }
        body:not(.no-colors) tbody tr:hover {
            filter: brightness(0.97);
        }
        
        /* Tryb wyłączony - zebra striping */
        body.no-colors tbody tr:nth-child(odd) {
            background: #ffffff !important;
            border-left: 4px solid transparent;
        }
        body.no-colors tbody tr:nth-child(even) {
            background: #f8fafc !important;
            border-left: 4px solid transparent;
        }
        body.no-colors tbody tr:hover {
            background: #e0f2fe !important;
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
        
        /* Actions */
        .table-actions {
            display: flex;
            gap: 8px;
        }
        .btn-sm {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-view {
            background: #e0e7ff;
            color: #3730a3;
        }
        .btn-view:hover {
            background: #c7d2fe;
        }
        .btn-settle {
            background: #dcfce7;
            color: #166534;
        }
        .btn-settle:hover {
            background: #bbf7d0;
        }
        .btn-quick-pay {
            background: #fef3c7;
            color: #92400e;
        }
        .btn-quick-pay:hover {
            background: #fde68a;
        }
        .btn-edit {
            background: #dbeafe;
            color: #1e40af;
        }
        .btn-edit:hover {
            background: #bfdbfe;
        }
        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
        }
        .btn-delete:hover {
            background: #fecaca;
        }
        
        .text-muted {
            color: #9ca3af;
            font-size: 11px;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }
        
        @media (max-width: 1024px) {
            .container {
                padding: 20px;
            }
            .stats-bar {
                grid-template-columns: 1fr 1fr;
            }
            .actions-bar {
                flex-direction: column;
                align-items: stretch;
            }
            table {
                font-size: 12px;
            }
            th, td {
                padding: 8px;
            }
        }
        
        /* Toggle kolorów - przycisk */
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
        th.sortable {
            cursor: pointer;
            user-select: none;
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
                    Zaliczki
                </div>
                <h1>Zaliczki</h1>
                <p>Lista zaliczek i rozliczeń</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('finanse'); ?>" class="btn-hero-secondary">← Wróć</a>
            </div>
        </div>
            <div class="header-actions">
                <button type="button" class="btn-color-mode active" onclick="toggleColors()" title="Kolory pracowników">
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
                <div style="width: 1px; height: 24px; background: var(--border); margin: 0 4px;"></div>
                <button class="btn btn-ghost" onclick="expandAll()" style="padding: 10px 16px; background: white; border: 1px solid #ddd;">Rozwiń</button>
                <button class="btn btn-ghost" onclick="collapseAll()" style="padding: 10px 16px; background: white; border: 1px solid #ddd;">Zwiń</button>
                <div style="width: 1px; height: 24px; background: var(--border); margin: 0 4px;"></div>
                <a href="<?php echo url('finanse.zaliczki.create', ['operation' => 'TRANSFER_COMPANY_TO_COMPANY']); ?>" class="btn-primary" style="padding: 12px 18px; background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block; box-shadow: 0 4px 6px rgba(30, 64, 175, 0.25); transition: all 0.2s;">
                    Przekaż środki
                </a>
                <a href="<?php echo url('finanse.zaliczki.create', ['operation' => 'TOPUP_COMPANY']); ?>" class="btn-primary" style="padding: 12px 24px; background: linear-gradient(135deg, #ea580c 0%, #dc2626 100%); color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block; box-shadow: 0 4px 6px rgba(234, 88, 12, 0.3); transition: all 0.2s;">
                    Zasil portfel
                </a>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div style="padding: 15px 20px; background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                <?php if ($_GET['success'] === 'deleted'): ?>
                    ✓ Zaliczka została usunięta.
                <?php elseif ($_GET['success'] === 'paid'): ?>
                    ✓ Zaliczka została oznaczona jako spłacona.
                <?php elseif ($_GET['success'] === 'transfer_created'): ?>
                    ✓ Transfer środków między pracownikami został zapisany.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div style="padding: 15px 20px; background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                <?php if ($_GET['error'] === 'has_settlements'): ?>
                    ✗ Nie można usunąć zaliczki, która ma już rozliczenia.
                <?php elseif ($_GET['error'] === 'delete_failed'): ?>
                    ✗ Błąd podczas usuwania zaliczki.
                <?php else: ?>
                    ✗ Wystąpił błąd.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-value"><?php echo count($advances); ?></div>
                <div class="stat-label">Wszystkie zaliczki</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['open_count'] ?? 0; ?></div>
                <div class="stat-label">Otwarte</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo formatMoney($stats['open_amount'] ?? 0); ?></div>
                <div class="stat-label">Do rozliczenia</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo formatMoney($stats['total_amount'] ?? 0); ?></div>
                <div class="stat-label">Łącznie wydane</div>
            </div>
        </div>
        
        <!-- Actions & Filters -->
        <?php
        $zalYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        if ($zalYear < 2020 || $zalYear > 2030) $zalYear = (int)date('Y');
        $zalMonth = isset($_GET['month']) ? (int)$_GET['month'] : 0;
        if ($zalMonth >= 1 && $zalMonth <= 12 && empty($filters['from_date'])) {
            $filters['from_date'] = sprintf('%04d-%02d-01', $zalYear, $zalMonth);
            $filters['to_date']   = date('Y-m-t', strtotime($filters['from_date']));
        }
        $zalMonthNames = [1=>'Styczen',2=>'Luty',3=>'Marzec',4=>'Kwiecien',5=>'Maj',6=>'Czerwiec',7=>'Lipiec',8=>'Sierpien',9=>'Wrzesien',10=>'Pazdziernik',11=>'Listopad',12=>'Grudzien'];
        $zalYearRange = range((int)date('Y') - 3, (int)date('Y'));
        $zalActiveMonth = 0;
        for ($m = 1; $m <= 12; $m++) {
            $mS = sprintf('%04d-%02d-01', $zalYear, $m);
            if (($filters['from_date'] ?? '') === $mS && ($filters['to_date'] ?? '') === date('Y-m-t', strtotime($mS))) { $zalActiveMonth = $m; break; }
        }
        $zalToday      = date('Y-m-d');
        $zalWeekAgo    = date('Y-m-d', strtotime('-7 days'));
        $zalMonthStart = date('Y-m-01');
        $zalMonthEnd   = date('Y-m-t');
        $zalYearStart  = date('Y-01-01');
        $zalIsDayActive   = (($filters['from_date'] ?? '') === $zalToday   && ($filters['to_date'] ?? '') === $zalToday);
        $zalIsWeekActive  = (($filters['from_date'] ?? '') === $zalWeekAgo  && ($filters['to_date'] ?? '') === $zalToday);
        $zalIsMonthActive = (($filters['from_date'] ?? '') === $zalMonthStart && ($filters['to_date'] ?? '') === $zalMonthEnd);
        $zalIsYearActive  = (($filters['from_date'] ?? '') === $zalYearStart  && ($filters['to_date'] ?? '') === $zalToday);
        $zalIsAllActive   = (empty($filters['from_date']) && empty($filters['to_date']) && empty($filters['worker_id']) && empty($filters['type']) && empty($filters['status']));
        $zalBaseQs = http_build_query(array_filter([
            'worker_id' => $filters['worker_id'] ?? '',
            'type'      => $filters['type'] ?? '',
            'status'    => $filters['status'] ?? '',
        ]));
        ?>
        <form method="GET" class="spx-filter-bar" id="zalFilterForm">
            <div class="spx-filter-group fg-worker">
                <label>Pracownik</label>
                <select name="worker_id">
                    <option value="">Wszyscy</option>
                    <?php foreach ($workers as $worker): ?>
                        <option value="<?php echo $worker['id']; ?>" <?php echo ($filters['worker_id'] == $worker['id']) ? 'selected' : ''; ?>>
                            <?php echo e($worker['last_name'] . ' ' . $worker['first_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spx-filter-group fg-type">
                <label>Typ</label>
                <select name="type">
                    <option value="">Wszystkie</option>
                    <option value="COMPANY" <?php echo ($filters['type'] == 'COMPANY') ? 'selected' : ''; ?>>Firmowa</option>
                    <option value="PRIVATE" <?php echo ($filters['type'] == 'PRIVATE') ? 'selected' : ''; ?>>Prywatna</option>
                </select>
            </div>
            <div class="spx-filter-group fg-status">
                <label>Status</label>
                <select name="status">
                    <option value="">Wszystkie</option>
                    <option value="open" <?php echo ($filters['status'] == 'open') ? 'selected' : ''; ?>>Otwarte</option>
                    <option value="closed" <?php echo ($filters['status'] == 'closed') ? 'selected' : ''; ?>>Zamkniete</option>
                </select>
            </div>
            <div class="spx-filter-group fg-month">
                <label>Miesiac</label>
                <select name="month" id="zalSelectMonth" onchange="zalOnMonthYearChange()">
                    <option value="0" <?php echo $zalActiveMonth === 0 ? 'selected' : ''; ?>>-- Wybierz --</option>
                    <?php foreach ($zalMonthNames as $mn => $mName): ?>
                        <option value="<?php echo $mn; ?>" <?php echo ($zalActiveMonth === $mn) ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spx-filter-group fg-year">
                <label>Rok</label>
                <select name="year" id="zalSelectYear" onchange="zalOnMonthYearChange()">
                    <?php foreach ($zalYearRange as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo ($zalYear == $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spx-filter-group fg-date">
                <label>Od</label>
                <input type="date" name="from_date" id="zalInputDateFrom" value="<?php echo e($filters['from_date'] ?? ''); ?>">
            </div>
            <div class="spx-filter-group fg-date">
                <label>Do</label>
                <input type="date" name="to_date" id="zalInputDateTo" value="<?php echo e($filters['to_date'] ?? ''); ?>">
            </div>
            <button type="submit" style="padding: 0 16px; height: 38px; align-self: flex-end; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; transition: opacity 0.2s; flex-shrink: 0; white-space: nowrap;">Filtruj</button>
            <?php if (array_filter($filters)): ?>
                <a href="<?php echo url('finanse.zaliczki'); ?>" style="padding: 0 14px; height: 38px; align-self: flex-end; display: inline-flex; align-items: center; background: #6c757d; color: white; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 600; flex-shrink: 0; white-space: nowrap;">Wyczysc</a>
            <?php endif; ?>
        </form>
        <div class="spx-controls-bar">
            <div class="spx-controls-left">
                <a href="?<?php echo $zalBaseQs ? $zalBaseQs.'&' : ''; ?>from_date=<?php echo $zalToday; ?>&to_date=<?php echo $zalToday; ?>"
                   class="spx-quick-btn <?php echo $zalIsDayActive ? 'active' : ''; ?>">Dzis</a>
                <a href="?<?php echo $zalBaseQs ? $zalBaseQs.'&' : ''; ?>from_date=<?php echo $zalWeekAgo; ?>&to_date=<?php echo $zalToday; ?>"
                   class="spx-quick-btn <?php echo $zalIsWeekActive ? 'active' : ''; ?>">7 dni</a>
                <a href="?<?php echo $zalBaseQs ? $zalBaseQs.'&' : ''; ?>from_date=<?php echo $zalMonthStart; ?>&to_date=<?php echo $zalMonthEnd; ?>&year=<?php echo date('Y'); ?>"
                   class="spx-quick-btn <?php echo $zalIsMonthActive ? 'active' : ''; ?>">Ten miesiac</a>
                <a href="?<?php echo $zalBaseQs ? $zalBaseQs.'&' : ''; ?>from_date=<?php echo $zalYearStart; ?>&to_date=<?php echo $zalToday; ?>"
                   class="spx-quick-btn <?php echo $zalIsYearActive ? 'active' : ''; ?>">Ten rok</a>
                <a href="<?php echo url('finanse.zaliczki'); ?>"
                   class="spx-quick-btn <?php echo $zalIsAllActive ? 'active' : ''; ?>">Wszystko</a>
            </div>
        </div>
        
        <!-- Table -->
        <div class="table-container">
            <?php if (empty($advances)): ?>
                <div class="no-data">
                    <p>Brak zaliczek do wyświetlenia</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Data</th>
                            <th>Pracownik</th>
                            <th>Typ</th>
                            <th>Kwota</th>
                            <th>Rozliczono</th>
                            <th>Pozostało</th>
                            <th>Status</th>
                            <th>Pliki</th>
                            <th>Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advances as $adv): 
                            $workerColor = getWorkerColor($adv['worker_id']);
                            $rowStyle = "--worker-color: {$workerColor['hslBorder']}; --worker-bg: {$workerColor['hslLight']};";
                        ?>
                            <tr style="<?php echo $rowStyle; ?>" data-worker-id="<?php echo $adv['worker_id']; ?>">
                                <td><?php echo e($adv['advance_id']); ?></td>
                                <td><?php echo formatDate($adv['issue_date']); ?></td>
                                <td>
                                    <strong><?php echo e($adv['last_name'] . ' ' . $adv['first_name']); ?></strong>
                                    <?php if (($adv['type'] ?? '') === 'PRIVATE' && !empty($adv['salary_period'])): ?>
                                        <br><span class="text-muted">Za miesiąc: <?php echo e(payrollMonthLabel((string)$adv['salary_period'])); ?></span>
                                    <?php endif; ?>
                                    <?php if ($adv['description']): ?>
                                        <br><span class="text-muted"><?php echo e($adv['description']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge type-<?php echo strtolower($adv['type']); ?>">
                                        <?php echo $adv['type'] === 'COMPANY' ? 'Firmowa' : 'Prywatna'; ?>
                                    </span>
                                </td>
                                <td><strong><?php echo formatMoney($adv['amount']); ?></strong></td>
                                <td><?php echo formatMoney($adv['amount_settled']); ?></td>
                                <td>
                                    <?php if ($adv['amount_remaining'] > 0): ?>
                                        <strong style="color: #dc2626;"><?php echo formatMoney($adv['amount_remaining']); ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">0,00 zł</span>
                                    <?php endif; ?>
                                </td>
                        <td>
                            <span class="badge status-<?php echo $adv['status']; ?>">
                                <?php echo $adv['status'] === 'open' ? 'Otwarta' : 'Zamknięta'; ?>
                            </span>
                        </td>
                                <td>
                                    <?php if ($adv['files_count'] > 0): ?>
                                        <?php echo (int)$adv['files_count']; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?php echo url('finanse.zaliczki.view', ['id' => $adv['advance_id']]); ?>" class="btn-sm btn-view">Zobacz</a>
                                        <a href="<?php echo url('finanse.zaliczki.edit', ['id' => $adv['advance_id']]); ?>" class="btn-sm btn-edit">Edytuj</a>
                                        <?php if ($adv['status'] === 'open' && $adv['amount_remaining'] > 0): ?>
                                            <a href="<?php echo url('finanse.zaliczki.close', ['id' => $adv['advance_id']]); ?>" class="btn-sm btn-settle">Rozlicz</a>
                                            <form method="POST" action="<?php echo url('finanse.zaliczki.mark_paid'); ?>" style="display: inline;" onsubmit="return confirm('Czy na pewno chcesz oznaczyć tę zaliczkę jako spłaconą?');">
                                                <input type="hidden" name="advance_id" value="<?php echo $adv['advance_id']; ?>">
                                                <button type="submit" class="btn-sm btn-quick-pay">Spłać</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="<?php echo url('finanse.zaliczki.delete'); ?>" style="display: inline;" onsubmit="return confirm('Czy na pewno chcesz USUNĄĆ tę zaliczkę? Zostaną usunięte także WSZYSTKIE rozliczenia!');">
                                            <input type="hidden" name="advance_id" value="<?php echo $adv['advance_id']; ?>">
                                            <button type="submit" class="btn-sm btn-delete">Usuń</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    // ========================================
    // TOGGLE KOLORÓW PRACOWNIKÓW
    // ========================================
    function toggleColors() {
        document.body.classList.toggle('no-colors');
        const btn = document.querySelector('.btn-color-mode');
        btn.classList.toggle('active');
        
        const colorsEnabled = !document.body.classList.contains('no-colors');
        localStorage.setItem('worklog_colors', colorsEnabled ? '1' : '0');
    }
    
    // ========================================
    // TOGGLE GRUPOWANIA PO DNIACH
    // ========================================
    function toggleGrouping() {
        document.body.classList.toggle('grouped-mode');
        const btn = document.querySelector('.btn-group-mode');
        btn.classList.toggle('active');
        
        const groupingEnabled = document.body.classList.contains('grouped-mode');
        localStorage.setItem('zaliczkiGroupingEnabled', groupingEnabled ? '1' : '0');
        
        if (groupingEnabled) {
            collapseAll();
        }
    }
    
    function toggleDay(header) {
        header.closest('.day-group').classList.toggle('collapsed');
    }
    
    function expandAll() {
        document.querySelectorAll('.day-group').forEach(g => g.classList.remove('collapsed'));
    }
    
    function collapseAll() {
        document.querySelectorAll('.day-group').forEach(g => g.classList.add('collapsed'));
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        const colorsEnabled = localStorage.getItem('worklog_colors');
        if (colorsEnabled === '0') {
            document.body.classList.add('no-colors');
            const btn = document.querySelector('.btn-color-mode');
            if (btn) btn.classList.remove('active');
        }
        
        const groupingEnabled = localStorage.getItem('zaliczkiGroupingEnabled');
        if (groupingEnabled === '1') {
            document.body.classList.add('grouped-mode');
            collapseAll();
        }
    });

        function zalOnMonthYearChange() {
            const month = parseInt(document.getElementById('zalSelectMonth').value);
            const year  = parseInt(document.getElementById('zalSelectYear').value);
            if (!month) return;
            const lastDay = new Date(year, month, 0).getDate();
            const pad = n => String(n).padStart(2, '0');
            document.getElementById('zalInputDateFrom').value = year + '-' + pad(month) + '-01';
            document.getElementById('zalInputDateTo').value   = year + '-' + pad(month) + '-' + pad(lastDay);
            document.getElementById('zalFilterForm').submit();
        }
    </script>
</body>
</html>
