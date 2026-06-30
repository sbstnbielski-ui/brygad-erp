<?php
/**
 * BRYGAD ERP - Budżet Domowy - Transakcje
 * Redesign: grupowanie po dniach, kolory, accordion, usuwanie
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_hb.php';
require_once __DIR__ . '/_module_layout.php';

$pdo         = getDbConnection();
$householdId = HB_HOUSEHOLD_ID;
$canEdit     = HB_CAN_EDIT;
$isOwner     = defined('HB_IS_OWNER') && HB_IS_OWNER;
$currentUserId = (int)$_SESSION['user_id'];

// -------------------------------------------------------
// Filtry / sortowanie z URL
// -------------------------------------------------------
$period          = hb_period_from_request();
$filterDirection = $_GET['direction'] ?? '';
$filterAccount   = $_GET['account']   ?? '';
$filterCategory  = $_GET['category']  ?? '';
$filterUser      = isset($_GET['user']) ? (int)$_GET['user'] : 0;

$allowedSortCols = ['date', 'direction', 'account_name', 'category_name', 'amount', 'created_by'];
$sortCol  = in_array($_GET['sort'] ?? '', $allowedSortCols) ? $_GET['sort'] : 'date';
$sortDir  = strtolower($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$sortNext = $sortDir === 'asc' ? 'desc' : 'asc';

function buildUrlParams(array $overrides = []): string {
    global $period, $filterDirection, $filterAccount, $filterCategory, $filterUser, $sortCol, $sortDir;
    $base = [
        'period'    => substr($period, 0, 7),
        'direction' => $filterDirection,
        'account'   => $filterAccount,
        'category'  => $filterCategory,
        'user'      => $filterUser ?: '',
        'sort'      => $sortCol,
        'dir'       => $sortDir,
    ];
    $params = array_merge($base, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
}

// -------------------------------------------------------
// Pobierz dane
// -------------------------------------------------------
try {
    $sortMap = [
        'date'          => 't.date',
        'direction'     => 't.direction',
        'account_name'  => 'a.name',
        'category_name' => 'c.name',
        'amount'        => 't.amount',
        'created_by'    => 'u.login',
    ];
    $orderBy = ($sortMap[$sortCol] ?? 't.date') . ' ' . strtoupper($sortDir) . ', t.id DESC';

    $privacyClause = $isOwner
        ? ''
        : " AND (t.visibility = 'shared' OR (t.visibility = 'private' AND t.owner_user_id = $currentUserId))";

    $sql = "
        SELECT 
            t.*,
            a.name  AS account_name,
            c.name  AS category_name,
            ta.name AS transfer_account_name,
            u.login AS creator_login,
            COALESCE(u.display_name, NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),''), u.login) AS creator_display
        FROM hb_transactions t
        LEFT JOIN hb_accounts a  ON a.id  = t.account_id
        LEFT JOIN hb_categories c ON c.id  = t.category_id
        LEFT JOIN hb_accounts ta  ON ta.id = t.transfer_account_id
        LEFT JOIN users u         ON u.id  = t.created_by
        WHERE t.household_id = ? AND t.period = ?
        $privacyClause
    ";
    $params = [$householdId, $period];

    if ($filterDirection) { $sql .= " AND t.direction = ?";    $params[] = $filterDirection; }
    if ($filterAccount)   { $sql .= " AND t.account_id = ?";   $params[] = (int)$filterAccount; }
    if ($filterCategory)  { $sql .= " AND t.category_id = ?";  $params[] = (int)$filterCategory; }
    if ($filterUser)      { $sql .= " AND t.created_by = ?";   $params[] = $filterUser; }

    $sql .= " ORDER BY $orderBy";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    // Konta i kategorie do filtrów
    $stmtAccounts = $pdo->prepare("SELECT id, name FROM hb_accounts WHERE household_id = ? AND is_active = 1 ORDER BY name");
    $stmtAccounts->execute([$householdId]);
    $accounts = $stmtAccounts->fetchAll();

    $stmtCategories = $pdo->prepare("SELECT id, name FROM hb_categories WHERE household_id = ? AND is_active = 1 ORDER BY name");
    $stmtCategories->execute([$householdId]);
    $categories = $stmtCategories->fetchAll();

    $stmtUsers = $pdo->prepare("
        SELECT DISTINCT u.id, 
               COALESCE(u.display_name, NULLIF(TRIM(CONCAT(u.first_name,' ',u.last_name)),''), u.login) AS name
        FROM hb_transactions t
        JOIN users u ON u.id = t.created_by
        WHERE t.household_id = ?
        ORDER BY name
    ");
    $stmtUsers->execute([$householdId]);
    $creators = $stmtUsers->fetchAll();

} catch (PDOException $e) {
    error_log("Transactions fetch error: " . $e->getMessage());
    $transactions = $accounts = $categories = $creators = [];
}

// Sumy
$totalIncome   = array_sum(array_column(array_filter($transactions, fn($t) => $t['direction'] === 'income'),  'amount'));
$totalExpenses = array_sum(array_column(array_filter($transactions, fn($t) => $t['direction'] === 'expense'), 'amount'));

// Grupowanie po dniach
$byDate = [];
foreach ($transactions as $t) {
    $d = $t['date'];
    if (!isset($byDate[$d])) {
        $byDate[$d] = ['rows' => [], 'income' => 0, 'expense' => 0, 'count' => 0];
    }
    $byDate[$d]['rows'][] = $t;
    $byDate[$d]['count']++;
    if ($t['direction'] === 'income')  $byDate[$d]['income']  += (float)$t['amount'];
    if ($t['direction'] === 'expense') $byDate[$d]['expense'] += (float)$t['amount'];
}
// Sortuj daty malejąco
krsort($byDate);

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

// Kolory wierszy (deterministyczne wg indeksu)
function hb_row_color(int $idx): array {
    $hue = ($idx * 137) % 360;
    return [
        'bg'     => "hsl($hue,60%,96%)",
        'border' => "hsl($hue,50%,70%)",
    ];
}

// Komunikaty
$successMsg = $_GET['success'] ?? '';
$errMsg     = $_GET['err']     ?? '';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Transakcje</title>
    <style>
        <?php echo hb_module_layout_styles(); ?>
        :root {
            --primary: #667eea;
            --bg-body: #f5f7fa;
            --bg-card: #ffffff;
            --border: #e5e7eb;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --success: #16a34a;
            --danger: #dc2626;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg-body); color: var(--text-main); }

        /* TOOLBAR */
        .dashboard-toolbar { display: flex; justify-content: flex-end; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
        .page-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }

        /* ALERTS */
        .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
        .alert-success { background: #dcfce7; color: #15803d; border-left: 4px solid #16a34a; }
        .alert-error   { background: #fee2e2; color: #dc2626; border-left: 4px solid #dc2626; }

        /* STATS */
        .stats-grid { display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
        .stat-card { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 14px 20px; flex: 1; min-width: 130px; }
        .stat-card .label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px; font-weight: 700; margin-bottom: 4px; }
        .stat-card .value { font-size: 20px; font-weight: 700; }
        .val-income  { color: #16a34a; }
        .val-expense { color: #dc2626; }
        .val-balance { color: #1d4ed8; }

        /* FILTRY */
        .filters-card { background: white; border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; }
        .filters-grid { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px; }
        .filter-group select,
        .filter-group input[type="month"] { padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; background: white; min-width: 130px; }
        .filter-group select:focus, .filter-group input:focus { outline: none; border-color: var(--primary); }
        .filter-actions { display: flex; gap: 8px; align-items: flex-end; }
        .btn-filter { padding: 7px 16px; background: var(--primary); color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-reset  { padding: 7px 14px; background: #f3f4f6; color: #374151; border: 1px solid var(--border); border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; }

        /* ACTIONS BAR */
        .actions-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .actions-bar-info { font-size: 13px; color: var(--text-muted); }
        .actions-bar-info strong { color: var(--text-main); }
        .btn-add { padding: 8px 16px; background: #16a34a; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 13px; }
        .btn-add:hover { background: #15803d; }

        /* TOGGLE BUTTONS */
        .btn-toggle { width: 34px; height: 34px; border-radius: 6px; border: 1px solid var(--border); background: white; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .2s; padding: 0; }
        .btn-toggle:hover { background: #f9fafb; border-color: var(--primary); }
        .btn-toggle.active { background: linear-gradient(135deg,#667eea,#764ba2); border-color: var(--primary); }
        .btn-toggle.active svg { stroke: white; }
        .btn-toggle svg { width: 16px; height: 16px; }
        
        /* TOGGLE GROUP - dla przycisków z tekstem */
        .toggle-group { display: flex; gap: 2px; background: #e5e7eb; padding: 2px; border-radius: 6px; }
        .btn-toggle-text { background: transparent; border: none; color: var(--text-muted); padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-toggle-text:hover { background: white; color: var(--text-main); }

        /* DAY GROUPS (accordion) */
        .day-group { margin-bottom: 12px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border); background: white; }
        .day-header { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; background: #f9fafb; cursor: pointer; transition: background .2s; user-select: none; }
        .day-header:hover { background: #f0f2f5; }
        .day-info { display: flex; align-items: center; gap: 12px; }
        .day-date { font-weight: 700; font-size: 14px; }
        .day-count { font-size: 12px; color: var(--text-muted); background: white; padding: 2px 8px; border-radius: 10px; border: 1px solid var(--border); }
        .day-totals { display: flex; gap: 10px; font-size: 13px; font-weight: 700; }
        .day-arrow { transition: transform .25s; color: var(--text-muted); }
        .day-group.collapsed .day-arrow { transform: rotate(-90deg); }
        .day-content { overflow: hidden; transition: max-height .3s ease-out; max-height: 3000px; }
        .day-group.collapsed .day-content { max-height: 0; }

        /* TABLE (stable ledger layout) */
        .tx-table-wrap {
            width: 100%;
            overflow-x: auto;
            border-top: 1px solid var(--border);
        }
        .tx-table {
            width: 100%;
            min-width: 980px;
            border-collapse: collapse;
            table-layout: fixed;
            background: #fff;
        }
        .tx-table th {
            padding: 10px 12px;
            background: #f8fafc;
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .45px;
            border: 1px solid #000000;
            white-space: nowrap;
            text-align: left;
            font-weight: 700;
        }
        .tx-table th:last-child {
            border-right: 0;
        }
        .tx-table th a {
            text-decoration: none;
            color: inherit;
        }
        .tx-table th a:hover {
            color: var(--primary);
        }
        .tx-table td {
            padding: 9px 12px;
            border: 1px solid #000000;
            font-size: 13px;
            vertical-align: middle;
        }
        .tx-table td:last-child {
            border-right: 0;
        }
        .tx-table .col-date { width: 112px; }
        .tx-table .col-type { width: 112px; }
        .tx-table .col-account { width: 160px; }
        .tx-table .col-category { width: 180px; }
        .tx-table .col-description { width: auto; }
        .tx-table .col-amount { width: 140px; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .tx-table .col-creator { width: 150px; }
        .tx-table .col-actions { width: 130px; white-space: nowrap; }
        .tx-table .cell-truncate {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* KOLORY WIERSZY */
        body:not(.no-colors) .tx-table tbody tr[data-bg] { background: var(--row-bg, white) !important; border-left: 3px solid var(--row-border, transparent) !important; }
        body:not(.no-colors) .tx-table tbody tr[data-bg]:hover { filter: brightness(0.97) !important; }
        body.no-colors .tx-table tbody tr:nth-child(odd)  { background: #fff !important; border-left: 3px solid transparent !important; }
        body.no-colors .tx-table tbody tr:nth-child(even) { background: #f8fafc !important; border-left: 3px solid transparent !important; }
        body.no-colors .tx-table tbody tr:hover { background: #e0f2fe !important; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
        .badge-success   { background: #dcfce7; color: #15803d; }
        .badge-danger    { background: #fee2e2; color: #dc2626; }
        .badge-secondary { background: #e5e7eb; color: #6b7280; }
        .amount-income   { color: #16a34a; font-weight: 700; }
        .amount-expense  { color: #dc2626; font-weight: 700; }
        .amount-transfer { color: #6b7280; font-weight: 700; }
        .creator-name { font-size: 11px; color: var(--text-muted); }

        .btn-sm { padding: 3px 9px; border-radius: 5px; font-size: 11px; font-weight: 600; text-decoration: none; cursor: pointer; border: 1px solid; transition: all .2s; }
        .btn-edit   { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
        .btn-edit:hover { background: #dbeafe; }
        .btn-delete { background: #fff1f2; color: #dc2626; border-color: #fecaca; }
        .btn-delete:hover { background: #fee2e2; }

        .empty-state { text-align: center; padding: 48px; color: #9ca3af; }
        .normal-view { display: none; }
        .grouped-view { display: block; }

        /* SIDEBAR */
        .sidebar-actions { background: white; border: 1px solid var(--border); border-radius: 8px; padding: 0; position: sticky; top: 92px; }
        .sidebar-actions-header { padding: 12px 16px; border-bottom: 1px solid var(--border); }
        .sidebar-actions-header h3 { font-size: 10px; color: #9ca3af; text-transform: uppercase; letter-spacing: 1px; margin: 0; font-weight: 700; }
        .sidebar-actions-body { padding: 8px; }
        .sidebar-section { margin-bottom: 14px; }
        .sidebar-section:last-child { margin-bottom: 6px; }
        .sidebar-section-title { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin: 8px 8px 4px; font-weight: 700; }
        .sidebar-actions a { display: block; padding: 8px 10px; margin-bottom: 3px; color: #374151; text-decoration: none; border: 1px solid var(--border); border-radius: 6px; transition: all .2s; font-size: 12px; font-weight: 500; }
        .sidebar-actions a:hover { background: #f9fafb; border-color: #d1d5db; }
    </style>
</head>
<body>
<?php hb_module_shell_start([
    'active' => 'dashboard',
    'title' => 'Budżet Domowy - Dashboard',
    'subtitle' => 'Rejestr transakcji, filtry i bieżący bilans',
    'user_name' => $userName,
    'period_month' => substr($period, 0, 7),
]); ?>

            <?php if ($successMsg === 'deleted'): ?>
                <div class="alert alert-success">Transakcja została usunięta.</div>
            <?php elseif ($errMsg === 'forbidden'): ?>
                <div class="alert alert-error">Brak uprawnień do usunięcia tej transakcji.</div>
            <?php elseif ($errMsg === 'not_found'): ?>
                <div class="alert alert-error">Nie znaleziono transakcji.</div>
            <?php elseif ($errMsg === 'delete_failed'): ?>
                <div class="alert alert-error">Błąd podczas usuwania — sprawdź logi.</div>
            <?php endif; ?>

            <!-- Toolbar widoku -->
            <div class="dashboard-toolbar">
                <div class="page-actions">
                    <!-- Toggle kolorów -->
                    <button type="button" class="btn-toggle active" id="btnColors" onclick="toggleColors()" title="Kolory wierszy">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M12 2a10 10 0 0 1 0 20"/>
                            <circle cx="12" cy="12" r="4"/>
                        </svg>
                    </button>
                    <!-- Toggle grupowania -->
                    <button type="button" class="btn-toggle active" id="btnGroup" onclick="toggleGrouping()" title="Grupuj po dniach">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8"  y1="2" x2="8"  y2="6"/>
                            <line x1="3"  y1="10" x2="21" y2="10"/>
                        </svg>
                    </button>
                    <div style="width: 1px; height: 24px; background: var(--border); margin: 0 4px;"></div>
                    <div class="toggle-group">
                        <button class="btn-toggle-text" onclick="expandAll()" title="Rozwiń wszystkie">Rozwiń</button>
                        <button class="btn-toggle-text" onclick="collapseAll()" title="Zwiń wszystkie">Zwiń</button>
                    </div>
                    <div style="width: 1px; height: 24px; background: var(--border); margin: 0 4px;"></div>
                    <?php if ($canEdit): ?>
                    <a href="<?php echo url('budzet.transakcje.create', ['period' => substr($period, 0, 7)]); ?>" class="btn-add">
                        + Dodaj transakcję
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filtry -->
            <div class="filters-card">
                <form method="GET" action="/budzet/transakcje.php">
                    <input type="hidden" name="sort" value="<?php echo e($sortCol); ?>">
                    <input type="hidden" name="dir"  value="<?php echo e($sortDir); ?>">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>Okres</label>
                            <input type="month" name="period" value="<?php echo e(substr($period, 0, 7)); ?>">
                        </div>
                        <div class="filter-group">
                            <label>Typ</label>
                            <select name="direction">
                                <option value="">Wszystkie</option>
                                <option value="income"   <?php if ($filterDirection === 'income')   echo 'selected'; ?>>Przychód</option>
                                <option value="expense"  <?php if ($filterDirection === 'expense')  echo 'selected'; ?>>Wydatek</option>
                                <option value="transfer" <?php if ($filterDirection === 'transfer') echo 'selected'; ?>>Transfer</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Konto</label>
                            <select name="account">
                                <option value="">Wszystkie konta</option>
                                <?php foreach ($accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>" <?php if ($filterAccount == $acc['id']) echo 'selected'; ?>>
                                    <?php echo e($acc['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Kategoria</label>
                            <select name="category">
                                <option value="">Wszystkie</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php if ($filterCategory == $cat['id']) echo 'selected'; ?>>
                                    <?php echo e($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (!empty($creators)): ?>
                        <div class="filter-group">
                            <label>Domownik</label>
                            <select name="user">
                                <option value="">Wszyscy</option>
                                <?php foreach ($creators as $cr): ?>
                                <option value="<?php echo $cr['id']; ?>" <?php if ($filterUser == $cr['id']) echo 'selected'; ?>>
                                    <?php echo e($cr['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="filter-actions">
                            <button type="submit" class="btn-filter">Filtruj</button>
                            <a href="/budzet/transakcje.php?period=<?php echo e(substr($period, 0, 7)); ?>" class="btn-reset">Resetuj</a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Sumy -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label">Przychody</div>
                    <div class="value val-income">+<?php echo hb_format_money($totalIncome); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Wydatki</div>
                    <div class="value val-expense">-<?php echo hb_format_money($totalExpenses); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Bilans</div>
                    <div class="value val-balance"><?php echo hb_format_money($totalIncome - $totalExpenses); ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Wpisów</div>
                    <div class="value" style="color:var(--text-main)"><?php echo count($transactions); ?></div>
                </div>
            </div>

            <!-- Info bar -->
            <div class="actions-bar">
                <div class="actions-bar-info">
                    Wyświetlam <strong><?php echo count($transactions); ?></strong> transakcji
                    <?php if ($filterUser || $filterDirection || $filterAccount || $filterCategory): ?>
                        <span style="color:#f59e0b;font-weight:600;"> · filtr aktywny</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($transactions)): ?>
                <div class="day-group">
                    <div class="empty-state">Brak transakcji spełniających kryteria filtru</div>
                </div>
            <?php else: ?>

            <!-- ========== WIDOK ZGRUPOWANY (domyślny) ========== -->
            <div class="grouped-view">
                <?php foreach ($byDate as $date => $dayData): ?>
                <?php
                    $dayBalance = $dayData['income'] - $dayData['expense'];
                    $formattedDate = date('d.m.Y (l)', strtotime($date));
                    // Lokalizacja dnia tygodnia
                    $days = ['Sunday'=>'niedziela','Monday'=>'poniedziałek','Tuesday'=>'wtorek',
                             'Wednesday'=>'środa','Thursday'=>'czwartek','Friday'=>'piątek','Saturday'=>'sobota'];
                    $dayName = $days[date('l', strtotime($date))] ?? date('l', strtotime($date));
                    $formattedDate = date('d.m.Y', strtotime($date)) . ' — ' . $dayName;
                ?>
                <div class="day-group">
                    <div class="day-header" onclick="toggleDay(this)">
                        <div class="day-info">
                            <span class="day-date"><?php echo $formattedDate; ?></span>
                            <span class="day-count"><?php echo $dayData['count']; ?> wpis<?php
                                $c = $dayData['count'];
                                echo $c === 1 ? '' : ($c < 5 ? 'y' : 'ów');
                            ?></span>
                        </div>
                        <div class="day-totals">
                            <?php if ($dayData['income'] > 0): ?>
                                <span class="val-income">+<?php echo hb_format_money($dayData['income']); ?></span>
                            <?php endif; ?>
                            <?php if ($dayData['expense'] > 0): ?>
                                <span class="val-expense">-<?php echo hb_format_money($dayData['expense']); ?></span>
                            <?php endif; ?>
                        </div>
                        <svg class="day-arrow" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </div>
                    <div class="day-content">
                        <div class="tx-table-wrap">
                        <table class="tx-table tx-table-grouped">
                            <thead>
                                <tr>
                                    <th class="col-type">
                                        <?php
                                        $dir = $sortCol === 'direction' ? $sortNext : 'asc';
                                        $arr = $sortCol === 'direction' ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                                        echo '<a href="/budzet/transakcje.php' . buildUrlParams(['sort'=>'direction','dir'=>$dir]) . '">Typ' . $arr . '</a>';
                                        ?>
                                    </th>
                                    <th class="col-account">
                                        <?php
                                        $dir = $sortCol === 'account_name' ? $sortNext : 'asc';
                                        $arr = $sortCol === 'account_name' ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                                        echo '<a href="/budzet/transakcje.php' . buildUrlParams(['sort'=>'account_name','dir'=>$dir]) . '">Konto' . $arr . '</a>';
                                        ?>
                                    </th>
                                    <th class="col-category">
                                        <?php
                                        $dir = $sortCol === 'category_name' ? $sortNext : 'asc';
                                        $arr = $sortCol === 'category_name' ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                                        echo '<a href="/budzet/transakcje.php' . buildUrlParams(['sort'=>'category_name','dir'=>$dir]) . '">Kategoria' . $arr . '</a>';
                                        ?>
                                    </th>
                                    <th class="col-description">Opis</th>
                                    <th class="col-amount">
                                        <?php
                                        $dir = $sortCol === 'amount' ? $sortNext : 'asc';
                                        $arr = $sortCol === 'amount' ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                                        echo '<a href="/budzet/transakcje.php' . buildUrlParams(['sort'=>'amount','dir'=>$dir]) . '">Kwota' . $arr . '</a>';
                                        ?>
                                    </th>
                                    <th class="col-creator">
                                        <?php
                                        $dir = $sortCol === 'created_by' ? $sortNext : 'asc';
                                        $arr = $sortCol === 'created_by' ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                                        echo '<a href="/budzet/transakcje.php' . buildUrlParams(['sort'=>'created_by','dir'=>$dir]) . '">Dodał' . $arr . '</a>';
                                        ?>
                                    </th>
                                    <?php if ($canEdit): ?><th class="col-actions">Akcje</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rowIdx = 0;
                                foreach ($dayData['rows'] as $t):
                                    $col = hb_row_color($rowIdx++);
                                ?>
                                <tr data-bg="1"
                                    style="--row-bg:<?php echo $col['bg']; ?>;--row-border:<?php echo $col['border']; ?>">
                                    <td class="col-type">
                                        <span class="badge badge-<?php echo hb_direction_class($t['direction']); ?>">
                                            <?php echo hb_format_direction($t['direction']); ?>
                                        </span>
                                    </td>
                                    <td class="col-account"><span class="cell-truncate" title="<?php echo e($t['account_name'] ?? '—'); ?>"><?php echo e($t['account_name'] ?? '—'); ?></span></td>
                                    <td class="col-category">
                                        <?php if ($t['direction'] === 'transfer'): ?>
                                            <span class="cell-truncate" title="<?php echo e('→ ' . ($t['transfer_account_name'] ?? '—')); ?>">→ <?php echo e($t['transfer_account_name'] ?? '—'); ?></span>
                                        <?php else: ?>
                                            <span class="cell-truncate" title="<?php echo e($t['category_name'] ?? '—'); ?>"><?php echo e($t['category_name'] ?? '—'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="col-description"><span class="cell-truncate" title="<?php echo e($t['description'] ?? ''); ?>"><?php echo e($t['description'] ?? ''); ?></span></td>
                                    <td class="col-amount">
                                        <span class="amount-<?php echo $t['direction']; ?>">
                                            <?php if ($t['direction'] === 'income'):  ?>+<?php endif; ?>
                                            <?php if ($t['direction'] === 'expense'): ?>-<?php endif; ?>
                                            <?php echo hb_format_money((float)$t['amount']); ?>
                                        </span>
                                    </td>
                                    <td class="col-creator">
                                        <span class="creator-name cell-truncate" title="<?php echo e($t['creator_display'] ?? $t['creator_login'] ?? '—'); ?>"><?php echo e($t['creator_display'] ?? $t['creator_login'] ?? '—'); ?></span>
                                    </td>
                                    <?php if ($canEdit): ?>
                                    <td class="col-actions">
                                        <a href="<?php echo url('budzet.transakcje.edit', ['id' => $t['id'], 'back_period' => substr($period, 0, 7)]); ?>"
                                           class="btn-sm btn-edit">Edytuj</a>
                                        <form method="POST" action="<?php echo url('budzet.transakcje.delete'); ?>" style="display:inline"
                                              onsubmit="return confirm('Usunąć tę transakcję?')">
                                            <input type="hidden" name="id"          value="<?php echo $t['id']; ?>">
                                            <input type="hidden" name="back_period" value="<?php echo e(substr($period, 0, 7)); ?>">
                                            <button type="submit" class="btn-sm btn-delete">Usuń</button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ========== WIDOK TABELA FLAT (toggle) ========== -->
            <div class="normal-view" style="background:white;border-radius:10px;overflow:hidden;border:1px solid var(--border);">
                <div class="tx-table-wrap">
                <table class="tx-table tx-table-flat">
                    <thead>
                        <tr>
                            <?php
                            $cols = [
                                'date'          => 'Data',
                                'direction'     => 'Typ',
                                'account_name'  => 'Konto',
                                'category_name' => 'Kategoria',
                            ];
                            foreach ($cols as $col => $label):
                                $dir = $sortCol === $col ? $sortNext : 'asc';
                                $arr = $sortCol === $col ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                                $href = buildUrlParams(['sort' => $col, 'dir' => $dir]);
                            ?>
                            <th class="col-<?php echo $col === 'date' ? 'date' : ($col === 'direction' ? 'type' : ($col === 'account_name' ? 'account' : 'category')); ?>">
                                <a href="/budzet/transakcje.php<?php echo $href; ?>"><?php echo $label . $arr; ?></a>
                            </th>
                            <?php endforeach; ?>
                            <th class="col-description">Opis</th>
                            <?php
                            $dir = $sortCol === 'amount' ? $sortNext : 'asc';
                            $arr = $sortCol === 'amount' ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                            $href = buildUrlParams(['sort' => 'amount', 'dir' => $dir]);
                            ?>
                            <th class="col-amount"><a href="/budzet/transakcje.php<?php echo $href; ?>">Kwota<?php echo $arr; ?></a></th>
                            <?php
                            $dir = $sortCol === 'created_by' ? $sortNext : 'asc';
                            $arr = $sortCol === 'created_by' ? ($sortDir === 'asc' ? ' ↑' : ' ↓') : '';
                            $href = buildUrlParams(['sort' => 'created_by', 'dir' => $dir]);
                            ?>
                            <th class="col-creator"><a href="/budzet/transakcje.php<?php echo $href; ?>">Dodał<?php echo $arr; ?></a></th>
                            <?php if ($canEdit): ?><th class="col-actions">Akcje</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rowIdx = 0;
                        foreach ($transactions as $t):
                            $col = hb_row_color($rowIdx++);
                        ?>
                        <tr data-bg="1"
                            style="--row-bg:<?php echo $col['bg']; ?>;--row-border:<?php echo $col['border']; ?>">
                            <td class="col-date"><?php echo e(date('d.m.Y', strtotime($t['date']))); ?></td>
                            <td class="col-type">
                                <span class="badge badge-<?php echo hb_direction_class($t['direction']); ?>">
                                    <?php echo hb_format_direction($t['direction']); ?>
                                </span>
                            </td>
                            <td class="col-account"><span class="cell-truncate" title="<?php echo e($t['account_name'] ?? '—'); ?>"><?php echo e($t['account_name'] ?? '—'); ?></span></td>
                            <td class="col-category">
                                <?php if ($t['direction'] === 'transfer'): ?>
                                    <span class="cell-truncate" title="<?php echo e('→ ' . ($t['transfer_account_name'] ?? '—')); ?>">→ <?php echo e($t['transfer_account_name'] ?? '—'); ?></span>
                                <?php else: ?>
                                    <span class="cell-truncate" title="<?php echo e($t['category_name'] ?? '—'); ?>"><?php echo e($t['category_name'] ?? '—'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-description"><span class="cell-truncate" title="<?php echo e($t['description'] ?? ''); ?>"><?php echo e($t['description'] ?? ''); ?></span></td>
                            <td class="col-amount">
                                <span class="amount-<?php echo $t['direction']; ?>">
                                    <?php if ($t['direction'] === 'income'):  ?>+<?php endif; ?>
                                    <?php if ($t['direction'] === 'expense'): ?>-<?php endif; ?>
                                    <?php echo hb_format_money((float)$t['amount']); ?>
                                </span>
                            </td>
                            <td class="col-creator"><span class="creator-name cell-truncate" title="<?php echo e($t['creator_display'] ?? $t['creator_login'] ?? '—'); ?>"><?php echo e($t['creator_display'] ?? $t['creator_login'] ?? '—'); ?></span></td>
                            <?php if ($canEdit): ?>
                            <td class="col-actions">
                                <a href="<?php echo url('budzet.transakcje.edit', ['id' => $t['id'], 'back_period' => substr($period, 0, 7)]); ?>"
                                   class="btn-sm btn-edit">Edytuj</a>
                                    <form method="POST" action="<?php echo url('budzet.transakcje.delete'); ?>" style="display:inline"
                                      onsubmit="return confirm('Usunąć tę transakcję?')">
                                    <input type="hidden" name="id"          value="<?php echo $t['id']; ?>">
                                    <input type="hidden" name="back_period" value="<?php echo e(substr($period, 0, 7)); ?>">
                                    <button type="submit" class="btn-sm btn-delete">Usuń</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <?php endif; // empty transactions ?>

<?php hb_module_shell_end(); ?>

<script>
    // ============ TOGGLE KOLORÓW ============
    function toggleColors() {
        document.body.classList.toggle('no-colors');
        const btn = document.getElementById('btnColors');
        btn.classList.toggle('active');
        const on = !document.body.classList.contains('no-colors');
        localStorage.setItem('hbTxColorsEnabled', on ? '1' : '0');
    }

    // ============ TOGGLE GRUPOWANIA ============
    function toggleGrouping() {
        const grouped  = document.querySelector('.grouped-view');
        const flat     = document.querySelector('.normal-view');
        const btn      = document.getElementById('btnGroup');
        const isGrouped = grouped.style.display !== 'none';
        if (isGrouped) {
            grouped.style.display = 'none';
            flat.style.display = 'block';
            btn.classList.remove('active');
            localStorage.setItem('hbTxGroupingEnabled', '0');
        } else {
            grouped.style.display = 'block';
            flat.style.display = 'none';
            btn.classList.add('active');
            localStorage.setItem('hbTxGroupingEnabled', '1');
            // Zwiń wszystkie dni po włączeniu grupowania
            collapseAll();
        }
    }

    // ============ TOGGLE DZIEŃ (accordion) ============
    function toggleDay(header) {
        header.closest('.day-group').classList.toggle('collapsed');
    }
    
    function expandAll() {
        document.querySelectorAll('.day-group').forEach(g => g.classList.remove('collapsed'));
    }
    
    function collapseAll() {
        document.querySelectorAll('.day-group').forEach(g => g.classList.add('collapsed'));
    }

    // ============ INIT ============
    document.addEventListener('DOMContentLoaded', function () {
        // Kolory
        const colorsEnabled = localStorage.getItem('hbTxColorsEnabled');
        if (colorsEnabled === '0') {
            document.body.classList.add('no-colors');
            const btn = document.getElementById('btnColors');
            if (btn) btn.classList.remove('active');
        }

        // Grupowanie (domyślnie włączone)
        const groupingEnabled = localStorage.getItem('hbTxGroupingEnabled');
        if (groupingEnabled === '0') {
            // Użytkownik wyłączył grupowanie - pokaż widok normalny
            const grouped = document.querySelector('.grouped-view');
            const flat    = document.querySelector('.normal-view');
            const btn     = document.getElementById('btnGroup');
            if (grouped) grouped.style.display = 'none';
            if (flat)    flat.style.display = 'block';
            if (btn)     btn.classList.remove('active');
        } else {
            // Domyślnie lub '1' - grupowanie włączone
            const grouped = document.querySelector('.grouped-view');
            const flat    = document.querySelector('.normal-view');
            const btn     = document.getElementById('btnGroup');
            if (grouped) grouped.style.display = 'block';
            if (flat)    flat.style.display = 'none';
            if (btn)     btn.classList.add('active');
            // Domyślnie zwinięte wszystkie dni
            collapseAll();
        }
    });
</script>
</body>
</html>
