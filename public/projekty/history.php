
<?php
/**
 * BRYGAD ERP – Historia projektów (archiwum)
 * Widok zarchiwizowanych projektów z możliwością przywrócenia.
 * Styl spójny z raporty-kosztow.php / finanse/overview.php.
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

/* ---------------------------------------------------------------
 * Akcja: przywrócenie projektu
 * -------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $projectId = (int)($_POST['project_id'] ?? 0);
    if ($projectId > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE projects SET archived_at = NULL, status = 'active' WHERE id = ?");
            $stmt->execute([$projectId]);
            logEvent("Przywrócono projekt ID: {$projectId} z historii", 'INFO');
            header("Location: " . url('projekty.history', ['success' => 'restored']));
            exit;
        } catch (PDOException $e) {
            logEvent("Błąd przywracania projektu: " . $e->getMessage(), 'ERROR');
            $error = "Błąd podczas przywracania projektu";
        }
    }
}

/* ---------------------------------------------------------------
 * Filtry
 * -------------------------------------------------------------- */
$search          = trim($_GET['search']      ?? '');
$investorFilter  = $_GET['investor_id']      ?? '';
$yearFilter      = $_GET['year']             ?? '';

/* Listy do dropdownów */
$investorsList = $pdo->query("SELECT id, name FROM investors WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$availableYears = $pdo->query("
    SELECT DISTINCT YEAR(archived_at) AS year
    FROM projects
    WHERE archived_at IS NOT NULL
    ORDER BY year DESC
")->fetchAll(PDO::FETCH_COLUMN);

/* ---------------------------------------------------------------
 * Główne zapytanie – projekty zarchiwizowane
 * -------------------------------------------------------------- */
$sql = "SELECT
    p.id, p.name, p.investor_id, p.status,
    p.start_date, p.end_date, p.archived_at, p.created_at,
    i.name AS investor_name, i.type AS investor_type,
    COALESCE(vpf.total_revenue, 0)      AS total_revenue,
    COALESCE(vpf.total_labor_cost, 0)   AS labor_cost,
    COALESCE(vpf.total_material_cost,0) AS material_cost,
    COALESCE(vpf.current_profit, 0)     AS current_profit
FROM projects p
LEFT JOIN view_project_finances vpf ON vpf.project_id = p.id
LEFT JOIN investors i ON i.id = p.investor_id
WHERE p.archived_at IS NOT NULL";
$params = [];

if ($search !== '') {
    $sql .= " AND p.name LIKE :search";
    $params['search'] = '%' . $search . '%';
}
if ($investorFilter !== '') {
    $sql .= " AND p.investor_id = :investor_id";
    $params['investor_id'] = $investorFilter;
}
if ($yearFilter !== '') {
    $sql .= " AND YEAR(p.archived_at) = :year";
    $params['year'] = $yearFilter;
}
$sql .= " ORDER BY p.archived_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

/* Statystyki łączne – zawsze na pełnym archiwum */
$statsRow = $pdo->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(vpf.total_revenue), 0)   AS total_revenue,
        COALESCE(SUM(vpf.current_profit), 0)  AS total_profit
    FROM projects p
    LEFT JOIN view_project_finances vpf ON vpf.project_id = p.id
    WHERE p.archived_at IS NOT NULL
")->fetch();

/* Statystyki aktualnie widocznego wyniku filtra */
$filteredRevenue = 0.0;
$filteredProfit  = 0.0;
foreach ($projects as $pr) {
    $filteredRevenue += (float)$pr['total_revenue'];
    $filteredProfit  += (float)$pr['current_profit'];
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
$success  = $_GET['success'] ?? '';
$hasAnyFilter = ($search !== '' || $investorFilter !== '' || $yearFilter !== '');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> – Historia projektów</title>
    <link rel="stylesheet" href="/projekty/assets/projekty.css">
    <style>
        :root {
            --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb;
            --blue-2: #2563eb; --accent: #667eea;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }

        /* Alert sukcesu */
        .alert-success {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; background: #f0fdf4;
            border: 1px solid #bbf7d0; border-left: 3px solid #16a34a;
            color: #166534; border-radius: 8px; margin-bottom: 16px;
            font-size: 13.5px;
        }
        .alert-success strong { font-weight: 700; }

        /* Filter card – spójne z raporty-kosztow.php */
        .filter-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid var(--line); overflow: hidden; margin-bottom: 16px; }
        .spx-filter-bar { padding: 12px 20px; display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
        .spx-filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1 1 0; }
        .spx-filter-group label { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .spx-filter-group select,
        .spx-filter-group input[type="text"] { padding: 0 8px; height: 38px; border: 1px solid var(--line); border-radius: 6px; font-size: 13px; background: #fff; font-family: inherit; width: 100%; box-sizing: border-box; }
        .spx-filter-group select:focus,
        .spx-filter-group input[type="text"]:focus { outline: none; border-color: var(--blue-2); box-shadow: 0 0 0 2px rgba(37,99,235,0.1); }
        .spx-controls-bar { padding: 10px 20px; background: #f9fafb; border-top: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; font-size: 12px; color: var(--muted); }
        .btn { height: 38px; border-radius: 6px; border: 1px solid var(--line); background: #fff; color: var(--ink); padding: 0 14px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; transition: all 0.15s; }
        .btn:hover { background: #f9fafb; border-color: #cbd5e1; }
        .btn-primary { background: var(--blue-2); color: #fff; border-color: var(--blue-2); }
        .btn-primary:hover { background: #1d4ed8; border-color: #1d4ed8; }
        .btn-secondary { background: #fff; color: var(--muted); border-color: var(--line); }
        .btn-secondary:hover { background: #f9fafb; }

        /* KPI */
        .kpi-grid {
            display: grid; gap: 14px; margin-bottom: 20px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .kpi-card {
            background: #fff; border-radius: 12px; padding: 18px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 2px solid transparent; transition: border-color 0.15s;
        }
        .kpi-card:hover { border-color: var(--accent); }
        .kpi-card .kpi-label { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 8px; }
        .kpi-card .kpi-value { font-size: 24px; font-weight: 700; color: var(--accent); line-height: 1.2; }
        .kpi-card .kpi-sub   { font-size: 11px; color: #999; margin-top: 6px; }
        .kpi-card.archive .kpi-value { color: #475569; }
        .kpi-card.revenue .kpi-value { color: #16a34a; }
        .kpi-card.profit.pos .kpi-value { color: #16a34a; }
        .kpi-card.profit.neg .kpi-value { color: #dc2626; }

        /* Tabela */
        .table-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px; }
        .table-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
        .table-head h2 { margin: 0; font-size: 16px; font-weight: 700; color: var(--ink); letter-spacing: -0.2px; }
        .table-head .hint { font-size: 12px; color: var(--muted); }

        .table-scroll { overflow-x: auto; border: 1px solid var(--line); border-radius: 8px; }
        table.hist-table {
            width: 100%; border-collapse: collapse;
            font-size: 13px; font-variant-numeric: tabular-nums;
            min-width: 900px;
        }
        table.hist-table th, table.hist-table td {
            padding: 11px 12px; border-bottom: 1px solid #f1f5f9;
            white-space: nowrap;
        }
        table.hist-table th {
            background: #f8fafc;
            font-size: 10.5px; font-weight: 700;
            color: var(--muted); text-transform: uppercase; letter-spacing: 0.4px;
            text-align: left; border-bottom: 2px solid var(--line);
        }
        table.hist-table th.num, table.hist-table td.num { text-align: right; }
        table.hist-table tbody tr:hover { background: #f9fafb; }
        table.hist-table td .proj-link {
            color: var(--ink); font-weight: 600; text-decoration: none;
        }
        table.hist-table td .proj-link:hover { color: var(--blue-2); }
        table.hist-table td .proj-client { display: block; font-size: 11.5px; color: var(--muted); margin-top: 2px; font-weight: 400; }

        table.hist-table .pos { color: #16a34a; font-weight: 700; }
        table.hist-table .neg { color: #dc2626; font-weight: 700; }

        .badge-archive {
            display: inline-block; margin-left: 8px;
            padding: 2px 8px; background: #f1f5f9; color: #475569;
            border-radius: 999px; font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.4px;
            vertical-align: middle;
        }

        .btn-restore {
            background: #16a34a; color: #fff;
            height: 32px; padding: 0 12px; border-radius: 6px; border: 0;
            font-size: 12px; font-weight: 600; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px;
            transition: background 0.15s;
        }
        .btn-restore:hover { background: #15803d; }
        .btn-preview {
            background: #fff; color: var(--ink);
            height: 32px; padding: 0 12px; border: 1px solid var(--line); border-radius: 6px;
            font-size: 12px; font-weight: 600; text-decoration: none;
            display: inline-flex; align-items: center; transition: all 0.15s;
        }
        .btn-preview:hover { border-color: var(--blue-2); color: var(--blue-2); }

        .no-data {
            padding: 50px 20px; text-align: center;
            color: var(--muted); font-size: 14px;
        }
        .no-data small { display: block; margin-top: 8px; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

<div class="container">

    <div class="hero">
        <div>
            <div class="hero-breadcrumb">
                <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                <a href="<?php echo url('projekty'); ?>">Projekty</a> /
                Historia
            </div>
            <h1>Historia projektów</h1>
            <p>Archiwum zamkniętych projektów · możesz je przywrócić lub podejrzeć</p>
        </div>
        <div class="hero-actions">
            <a href="<?php echo url('projekty.raporty'); ?>" class="btn-hero-secondary">Raport kosztów</a>
            <a href="<?php echo url('projekty'); ?>" class="btn-hero-secondary">← Aktywne projekty</a>
        </div>
    </div>

    <?php if ($success === 'restored'): ?>
        <div class="alert-success">
            <strong>✓ Gotowe.</strong> Projekt został przywrócony i znów jest aktywny.
        </div>
    <?php endif; ?>

    <?php /* KPI – spójne ze stylem kpi-card */ ?>
    <div class="kpi-grid">
        <div class="kpi-card archive">
            <div class="kpi-label">Projekty w archiwum</div>
            <div class="kpi-value"><?php echo (int)$statsRow['total']; ?></div>
            <div class="kpi-sub">Zarchiwizowane łącznie</div>
        </div>
        <div class="kpi-card revenue">
            <div class="kpi-label">Łączny przychód (historia)</div>
            <div class="kpi-value"><?php echo formatMoney($statsRow['total_revenue']); ?></div>
            <div class="kpi-sub">Wszystko co firma kiedykolwiek zarobiła na tych projektach</div>
        </div>
        <div class="kpi-card profit <?php echo $statsRow['total_profit'] >= 0 ? 'pos' : 'neg'; ?>">
            <div class="kpi-label">Łączny zysk (historia)</div>
            <div class="kpi-value"><?php echo formatMoney($statsRow['total_profit']); ?></div>
            <div class="kpi-sub">Przychód minus koszty z widoku projektu</div>
        </div>
        <?php if ($hasAnyFilter): ?>
        <div class="kpi-card">
            <div class="kpi-label">Wynik filtra</div>
            <div class="kpi-value" style="color: var(--accent);"><?php echo count($projects); ?></div>
            <div class="kpi-sub">
                Przychód: <strong><?php echo formatMoney($filteredRevenue); ?></strong>
                · Zysk:
                <strong style="color: <?php echo $filteredProfit >= 0 ? '#16a34a' : '#dc2626'; ?>;">
                    <?php echo formatMoney($filteredProfit); ?>
                </strong>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="filter-card">
        <form method="GET" action="" class="spx-filter-bar">
            <div class="spx-filter-group" style="flex: 0.7 1 0;">
                <label>Rok archiwizacji</label>
                <select name="year" onchange="this.form.submit()">
                    <option value="">Wszystkie lata</option>
                    <?php foreach ($availableYears as $year): ?>
                        <option value="<?php echo $year; ?>" <?php echo (string)$yearFilter === (string)$year ? 'selected' : ''; ?>>
                            <?php echo $year; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spx-filter-group" style="flex: 1.2 1 0;">
                <label>Klient (inwestor)</label>
                <select name="investor_id" onchange="this.form.submit()">
                    <option value="">Wszyscy</option>
                    <?php foreach ($investorsList as $inv): ?>
                        <option value="<?php echo (int)$inv['id']; ?>" <?php echo (string)$investorFilter === (string)$inv['id'] ? 'selected' : ''; ?>>
                            <?php echo e($inv['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="spx-filter-group" style="flex: 1.5 1 0;">
                <label>Szukaj po nazwie</label>
                <input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Np. Osiedle Słoneczne...">
            </div>
            <button type="submit" class="btn btn-primary">Filtruj</button>
            <?php if ($hasAnyFilter): ?>
                <a href="<?php echo url('projekty.history'); ?>" class="btn btn-secondary">Wyczyść</a>
            <?php endif; ?>
        </form>

        <div class="spx-controls-bar">
            <div>
                <strong style="color: var(--ink);"><?php echo count($projects); ?></strong>
                <?php echo count($projects) === 1 ? 'projekt' : 'projektów'; ?>
                na liście
                <?php if ($hasAnyFilter): ?>
                    · filtr aktywny
                <?php endif; ?>
            </div>
            <div>Sortowanie: od najnowszej archiwizacji</div>
        </div>
    </div>

    <div class="table-card">
        <div class="table-head">
            <h2>Zarchiwizowane projekty</h2>
            <div class="hint">Kliknij nazwę żeby podejrzeć, „Przywróć" żeby wróciło do aktywnych</div>
        </div>

        <?php if (empty($projects)): ?>
            <div class="no-data">
                <?php if ($hasAnyFilter): ?>
                    Brak projektów pasujących do filtra.
                    <small>Zmień rok, klienta albo frazę wyszukiwania.</small>
                <?php else: ?>
                    Archiwum jest puste.
                    <small>Projekty pojawią się tu po zamknięciu i archiwizacji w widoku projektu.</small>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="hist-table">
                    <thead>
                        <tr>
                            <th>Projekt / klient</th>
                            <th>Okres realizacji</th>
                            <th class="num">Przychód</th>
                            <th class="num">Zysk</th>
                            <th>Zarchiwizowano</th>
                            <th style="text-align:right;">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projects as $project):
                            $totalRevenue  = (float)$project['total_revenue'];
                            $currentProfit = (float)$project['current_profit'];

                            $period = '';
                            if ($project['start_date']) {
                                $period = formatDate($project['start_date']);
                                if ($project['end_date']) {
                                    $period .= ' – ' . formatDate($project['end_date']);
                                }
                            }
                            $profitCls = $currentProfit > 0 ? 'pos' : ($currentProfit < 0 ? 'neg' : '');
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo url('projekty.view', ['id' => $project['id']]); ?>" class="proj-link">
                                        <?php echo e($project['name']); ?>
                                    </a>
                                    <span class="badge-archive">Archiwum</span>
                                    <?php if ($project['investor_name']): ?>
                                        <span class="proj-client"><?php echo e($project['investor_name']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: var(--muted);"><?php echo $period ?: '—'; ?></td>
                                <td class="num"><?php echo formatMoney($totalRevenue); ?></td>
                                <td class="num <?php echo $profitCls; ?>"><?php echo formatMoney($currentProfit); ?></td>
                                <td style="color: var(--muted); font-size: 12px;">
                                    <?php echo date('d.m.Y', strtotime($project['archived_at'])); ?>
                                </td>
                                <td style="text-align: right;">
                                    <a href="<?php echo url('projekty.view', ['id' => $project['id']]); ?>" class="btn-preview">Podgląd</a>
                                    <form method="POST" action="" style="display: inline;"
                                          onsubmit="return confirm('Przywrócić projekt do aktywnych?');">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="project_id" value="<?php echo (int)$project['id']; ?>">
                                        <button type="submit" class="btn-restore" title="Odarchiwizuj projekt">
                                            ↺ Przywróć
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<footer class="footer">
    <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?></p>
</footer>
</body>
</html>
