<?php
/**
 * BRYGAD ERP - Stawki pracownika z historia i przeliczeniem wstecz.
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/rate_helper.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();
$errors = [];
$workerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($workerId <= 0) {
    header('Location: ' . url('hr.workers'));
    exit;
}

$rateFields = [
    'base_rate' => 'Robocza podst. (zl/h)',
    'overtime_rate' => 'Robocza nadgodz. (zl/h)',
    'saturday_rate' => 'Sobota podst. (zl/h)',
    'saturday_overtime_rate' => 'Sobota nadgodz. (zl/h)',
    'sunday_rate' => 'Niedziela podst. (zl/h)',
    'sunday_overtime_rate' => 'Niedziela nadgodz. (zl/h)',
    'night_rate' => 'Nocka podst. (zl/h)',
    'night_overtime_rate' => 'Nocka nadgodz. (zl/h)',
    'delegation_rate' => 'Delegacja podst. (zl/h)',
    'delegation_overtime_rate' => 'Delegacja nadgodz. (zl/h)',
    'vacation_rate' => 'Urlop (zl/h)',
    'sick_rate' => 'L4 (zl/h)',
];

$rateGroups = [
    'Zakres' => ['valid_from', 'valid_to', 'project_id'],
    'Robocze' => ['base_rate', 'overtime_rate'],
    'Weekend' => ['saturday_rate', 'saturday_overtime_rate', 'sunday_rate', 'sunday_overtime_rate'],
    'Specjalne' => ['night_rate', 'night_overtime_rate', 'delegation_rate', 'delegation_overtime_rate', 'vacation_rate', 'sick_rate'],
];

$today = date('Y-m-d');
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

try {
    $stmt = $pdo->prepare('SELECT * FROM workers WHERE id = ? LIMIT 1');
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
    if (!$worker) {
        header('Location: ' . url('hr.workers'));
        exit;
    }
} catch (PDOException $e) {
    logEvent('Worker rates: blad pobierania pracownika ID ' . $workerId . ': ' . $e->getMessage(), 'ERROR');
    header('Location: ' . url('hr.workers'));
    exit;
}

try {
    $stmt = $pdo->query("SELECT id, name FROM projects WHERE status IN ('active', 'planned') ORDER BY name ASC");
    $projects = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent('Worker rates: blad pobierania projektow: ' . $e->getMessage(), 'ERROR');
    $projects = [];
}

$currentBaseRate = getWorkerRate($pdo, $workerId, null, $today, null);
$formData = [
    'valid_from' => $today,
    'valid_to' => '',
    'project_id' => '',
];
foreach (array_keys($rateFields) as $field) {
    $default = $currentBaseRate[$field] ?? null;
    $formData[$field] = ($default !== null && $default !== '') ? number_format((float)$default, 2, '.', '') : '';
}

$flash = $_SESSION['worker_rates_flash'] ?? null;
unset($_SESSION['worker_rates_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = ($_POST['action'] ?? '') === 'save_rate_recalc' ? 'save_rate_recalc' : 'save_rate';
    $projectId = !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null;
    $validFrom = trim((string)($_POST['valid_from'] ?? ''));
    $validTo = trim((string)($_POST['valid_to'] ?? ''));

    $formData['valid_from'] = $validFrom;
    $formData['valid_to'] = $validTo;
    $formData['project_id'] = $projectId ?: '';

    $payload = [
        'worker_id' => $workerId,
        'project_id' => $projectId,
        'cost_node_id' => null,
        'valid_from' => $validFrom,
        'valid_to' => $validTo !== '' ? $validTo : null,
    ];

    foreach (array_keys($rateFields) as $field) {
        $rawValue = trim((string)($_POST[$field] ?? ''));
        $formData[$field] = $rawValue;
        if ($field === 'base_rate') {
            if ($rawValue === '' || !is_numeric($rawValue) || (float)$rawValue < 0) {
                $errors[] = 'Stawka robocza podstawowa musi byc liczba >= 0.';
            } else {
                $payload[$field] = (float)$rawValue;
            }
            continue;
        }

        if ($rawValue === '') {
            $payload[$field] = null;
            continue;
        }

        if (!is_numeric($rawValue) || (float)$rawValue < 0) {
            $errors[] = $rateFields[$field] . ' musi byc liczba >= 0.';
            continue;
        }
        $payload[$field] = (float)$rawValue;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom)) {
        $errors[] = 'Pole "Obowiazuje od" jest wymagane.';
    }
    if ($validTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validTo)) {
        $errors[] = 'Pole "Obowiazuje do" ma nieprawidlowy format.';
    }
    if ($validTo !== '' && $validFrom !== '' && $validTo < $validFrom) {
        $errors[] = 'Data konca nie moze byc wczesniejsza niz data startu.';
    }
    if ($action === 'save_rate_recalc' && $validTo === '') {
        $errors[] = 'Do przeliczenia wpisow podaj takze date "Obowiazuje do".';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            upsertWorkerRateInterval($pdo, $payload);
            $recalcSummary = null;
            if ($action === 'save_rate_recalc') {
                $recalcSummary = recalculateApprovedWorkLogsForWorker(
                    $pdo,
                    $workerId,
                    $validFrom,
                    $validTo,
                    $projectId
                );
            }

            $pdo->commit();

            $scopeLabel = $projectId ? 'projektowa' : 'bazowa';
            logEvent(
                'Worker rates: zapisano stawke ' . $scopeLabel . ' dla worker_id=' . $workerId
                . ', from=' . $validFrom . ', to=' . ($validTo !== '' ? $validTo : 'NULL')
                . ($recalcSummary ? ', recalculated=' . (int)$recalcSummary['updated'] : ''),
                'INFO'
            );

            $_SESSION['worker_rates_flash'] = [
                'type' => 'success',
                'title' => $action === 'save_rate_recalc' ? 'Zapisano stawke i przeliczono wpisy.' : 'Zapisano nowa stawke.',
                'message' => $action === 'save_rate_recalc'
                    ? 'Przeliczono ' . (int)$recalcSummary['updated'] . ' z ' . (int)$recalcSummary['matched'] . ' zatwierdzonych wpisow z tego zakresu.'
                    : 'Historia stawek pracownika zostala zaktualizowana.',
            ];

            header('Location: ' . url('hr.workers.rates', ['id' => $workerId]));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Nie udalo sie zapisac stawki. Sprobuj ponownie.';
            logEvent('Worker rates: blad zapisu dla worker_id=' . $workerId . ': ' . $e->getMessage(), 'ERROR');
        }
    }
}

try {
    $stmt = $pdo->prepare("\n        SELECT\n            wr.*,\n            p.name AS project_name,\n            pcn.name AS cost_node_name\n        FROM worker_rates wr\n        LEFT JOIN projects p ON p.id = wr.project_id\n        LEFT JOIN project_cost_nodes pcn ON pcn.id = wr.cost_node_id\n        WHERE wr.worker_id = ?\n        ORDER BY COALESCE(wr.valid_to, '9999-12-31') DESC, wr.valid_from DESC, wr.project_id IS NULL DESC, wr.cost_node_id IS NULL DESC\n    ");
    $stmt->execute([$workerId]);
    $rates = $stmt->fetchAll();
} catch (PDOException $e) {
    logEvent('Worker rates: blad pobierania historii dla worker_id=' . $workerId . ': ' . $e->getMessage(), 'ERROR');
    $rates = [];
}

try {
    $stmt = $pdo->prepare("\n        SELECT\n            COUNT(*) AS total_count,\n            SUM(CASE WHEN valid_from <= CURDATE() AND (valid_to IS NULL OR valid_to >= CURDATE()) THEN 1 ELSE 0 END) AS active_count,\n            SUM(CASE WHEN project_id IS NOT NULL THEN 1 ELSE 0 END) AS project_count,\n            SUM(CASE WHEN cost_node_id IS NOT NULL THEN 1 ELSE 0 END) AS stage_count\n        FROM worker_rates\n        WHERE worker_id = ?\n    ");
    $stmt->execute([$workerId]);
    $rateStats = $stmt->fetch() ?: ['total_count' => 0, 'active_count' => 0, 'project_count' => 0, 'stage_count' => 0];
} catch (PDOException $e) {
    $rateStats = ['total_count' => 0, 'active_count' => 0, 'project_count' => 0, 'stage_count' => 0];
}

function rateValueText($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format((float)$value, 2, ',', ' ') . ' zl';
}

function ratePairHtml($base, $overtime): string
{
    return '<div class="pair-main">' . e(rateValueText($base)) . '</div><div class="pair-sub">nadg. ' . e(rateValueText($overtime)) . '</div>';
}

function rateScopeMeta(array $rate, string $today): array
{
    $validTo = $rate['valid_to'] ?? null;
    $isFuture = $rate['valid_from'] > $today;
    $isActive = !$isFuture && ($validTo === null || $validTo === '' || $validTo >= $today);

    if ($isFuture) {
        $status = ['label' => 'Planowana', 'class' => 'badge-future'];
    } elseif ($isActive) {
        $status = ['label' => 'Aktywna', 'class' => 'badge-active'];
    } else {
        $status = ['label' => 'Archiwalna', 'class' => 'badge-archived'];
    }

    if (!empty($rate['cost_node_name'])) {
        $scope = ['label' => 'Etap: ' . $rate['cost_node_name'], 'class' => 'badge-scope-stage'];
    } elseif (!empty($rate['project_name'])) {
        $scope = ['label' => 'Projekt: ' . $rate['project_name'], 'class' => 'badge-scope-project'];
    } else {
        $scope = ['label' => 'Stawka bazowa', 'class' => 'badge-scope-base'];
    }

    return ['status' => $status, 'scope' => $scope];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Stawki pracownika</title>
    <style>
        :root {
            --bg-body: #f3f6fb;
            --bg-card: #ffffff;
            --bg-soft: #f8fafc;
            --bg-hero-a: #10213f;
            --bg-hero-b: #23406c;
            --border: #d9e2ec;
            --border-strong: #c6d4e1;
            --text-main: #152238;
            --text-muted: #5c6b80;
            --text-soft: #7a8798;
            --primary: #1d4ed8;
            --primary-dark: #163ea5;
            --success: #0f9f6e;
            --danger: #c0392b;
            --warning: #c47f00;
            --shadow-card: 0 18px 44px rgba(15, 23, 42, 0.08);
            --radius-lg: 22px;
            --radius-md: 16px;
            --radius-sm: 10px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: radial-gradient(circle at top, #eef4ff 0%, var(--bg-body) 48%, #eef2f7 100%);
            color: var(--text-main);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            line-height: 1.5;
            padding-bottom: 40px;
        }
        .header { box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08) !important; }
        .header-content { max-width: 1560px !important; padding: 15px 30px !important; }
        .container {
            max-width: 1560px;
            margin: 0 auto;
            padding: 26px;
        }
        .hero {
            background: linear-gradient(135deg, var(--bg-hero-a) 0%, var(--bg-hero-b) 58%, #31537d 100%);
            color: #fff;
            border-radius: 26px;
            padding: 28px 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            box-shadow: 0 28px 70px rgba(16, 33, 63, 0.24);
            margin-bottom: 22px;
        }
        .hero-breadcrumb {
            font-size: 12px;
            color: rgba(219, 234, 254, 0.92);
            margin-bottom: 8px;
        }
        .hero-breadcrumb a {
            color: #eff6ff;
            text-decoration: none;
        }
        .hero h1 {
            margin: 0;
            font-size: 30px;
            letter-spacing: -0.04em;
        }
        .hero p {
            margin: 8px 0 0;
            max-width: 720px;
            color: rgba(226, 232, 240, 0.94);
            font-size: 14px;
        }
        .hero-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease, border-color 0.18s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary {
            background: linear-gradient(135deg, #fff 0%, #dbeafe 100%);
            color: #11306d;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.16);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.10);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.18);
        }
        .btn-neutral {
            background: #fff;
            color: var(--text-main);
            border-color: var(--border);
        }
        .btn-submit {
            background: linear-gradient(135deg, #1749be 0%, #113694 100%);
            color: #fff;
            box-shadow: 0 18px 32px rgba(29, 78, 216, 0.24);
        }
        .btn-submit-alt {
            background: #e7f0ff;
            color: #123b99;
            border-color: #c7d7ff;
        }
        .layout-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
            gap: 22px;
            align-items: start;
            margin-bottom: 22px;
        }
        .card {
            background: var(--bg-card);
            border: 1px solid rgba(201, 211, 224, 0.88);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
        }
        .card-head {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: flex-start;
            padding: 24px 26px 18px;
            border-bottom: 1px solid #edf2f7;
        }
        .card-head h2,
        .card-head h3 {
            margin: 0;
            font-size: 22px;
            letter-spacing: -0.03em;
        }
        .card-head p {
            margin: 8px 0 0;
            color: var(--text-muted);
            font-size: 13px;
            max-width: 720px;
        }
        .card-body {
            padding: 24px 26px 26px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .summary-tile {
            background: linear-gradient(180deg, #fbfdff 0%, #f3f7fc 100%);
            border: 1px solid #e3ebf5;
            border-radius: 18px;
            padding: 16px 18px;
            min-height: 108px;
        }
        .summary-label {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #5f7187;
            margin-bottom: 10px;
        }
        .pair-main {
            font-size: 20px;
            font-weight: 800;
            color: #12243f;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
        }
        .pair-sub {
            margin-top: 4px;
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
        }
        .meta-list {
            display: grid;
            gap: 12px;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 16px;
            background: linear-gradient(180deg, #fbfdff 0%, #f5f8fc 100%);
            border: 1px solid #e5edf6;
        }
        .meta-row span:first-child {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
        }
        .meta-row strong {
            font-size: 18px;
            color: var(--text-main);
            font-variant-numeric: tabular-nums;
        }
        .meta-note {
            margin-top: 14px;
            padding: 14px 16px;
            border-radius: 16px;
            background: #f7fbff;
            border: 1px solid #dbe8ff;
            color: #36527b;
            font-size: 13px;
        }
        .alert {
            margin-bottom: 18px;
            padding: 16px 18px;
            border-radius: 16px;
            border: 1px solid;
        }
        .alert strong { display: block; margin-bottom: 4px; }
        .alert-success {
            background: #edfdf6;
            border-color: #b9efd7;
            color: #116b49;
        }
        .alert-error {
            background: #fff4f2;
            border-color: #f1c1bb;
            color: #9d2c21;
        }
        .alert ul {
            margin: 8px 0 0 18px;
            padding: 0;
        }
        .form-section + .form-section {
            margin-top: 22px;
        }
        .section-title {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #607287;
            margin-bottom: 12px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px 16px;
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }
        .field.span-2 {
            grid-column: span 2;
        }
        .field label {
            min-height: 34px;
            display: flex;
            align-items: flex-end;
            font-size: 12px;
            line-height: 1.35;
            font-weight: 700;
            color: #2a3b53;
        }
        .field input,
        .field select {
            width: 100%;
            min-height: 46px;
            padding: 0 14px;
            border-radius: 14px;
            border: 1px solid #d2dce8;
            background: #fff;
            color: var(--text-main);
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        .field input:focus,
        .field select:focus {
            outline: none;
            border-color: #7fa8ff;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.11);
            background: #fbfdff;
        }
        .field small {
            color: var(--text-soft);
            font-size: 12px;
        }
        .form-actions {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #edf2f7;
        }
        .form-actions-note {
            max-width: 620px;
            color: var(--text-muted);
            font-size: 13px;
        }
        .form-actions-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .table-wrap {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 1180px;
        }
        thead th {
            padding: 14px 14px;
            background: #f7fafc;
            border-bottom: 1px solid #dde7f2;
            color: #5c6b80;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-align: left;
            white-space: nowrap;
        }
        tbody td {
            padding: 16px 14px;
            border-bottom: 1px solid #edf2f7;
            vertical-align: top;
            font-size: 13px;
            color: var(--text-main);
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.04em;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .badge-active { background: #ebfbf4; border-color: #b6ecd2; color: #0f7c55; }
        .badge-future { background: #fff7e6; border-color: #f5d597; color: #9b6700; }
        .badge-archived { background: #f3f6fa; border-color: #d9e2ec; color: #607287; }
        .badge-scope-base { background: #eef4ff; border-color: #cfe0ff; color: #21488f; }
        .badge-scope-project { background: #eefbf7; border-color: #caefdf; color: #0f7857; }
        .badge-scope-stage { background: #fff5f0; border-color: #f5d4c0; color: #a0522d; }
        .range-cell strong {
            display: block;
            font-size: 14px;
            margin-bottom: 5px;
        }
        .range-cell span {
            color: var(--text-muted);
            font-size: 12px;
        }
        .mono {
            font-variant-numeric: tabular-nums;
        }
        .empty-state {
            padding: 40px 22px;
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
        }
        .footer {
            text-align: center;
            padding: 18px 12px 0;
            color: #8795a6;
            font-size: 12px;
        }
        @media (max-width: 1180px) {
            .layout-grid {
                grid-template-columns: 1fr;
            }
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 820px) {
            .container {
                padding: 14px;
            }
            .hero {
                padding: 22px 18px;
                border-radius: 22px;
                flex-direction: column;
            }
            .hero-actions {
                width: 100%;
                justify-content: stretch;
            }
            .hero-actions .btn,
            .form-actions-buttons .btn {
                flex: 1 1 100%;
            }
            .card-head,
            .card-body {
                padding-left: 18px;
                padding-right: 18px;
            }
            .summary-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }
            .field.span-2 {
                grid-column: span 1;
            }
            .field label {
                min-height: auto;
            }
            .form-actions {
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <?php include dirname(__DIR__, 2) . '/includes/header_modules.php'; ?>

    <div class="container">
        <section class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('hr'); ?>">HR</a> ›
                    <a href="<?php echo url('hr.workers.edit', ['id' => $workerId]); ?>"><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?></a> ›
                    Stawki pracownika
                </div>
                <h1><?php echo e($worker['first_name'] . ' ' . $worker['last_name']); ?> · stawki i historia</h1>
                <p>Tu zapisujesz aktualna lub historyczna stawke pracownika, widzisz poprzednie zakresy i w razie potrzeby od razu przeliczasz zatwierdzone wpisy z podanego okresu.</p>
            </div>
            <div class="hero-actions">
                <a href="<?php echo url('hr.workers.edit', ['id' => $workerId]); ?>" class="btn btn-secondary">Powrot do pracownika</a>
                <a href="<?php echo url('hr.workers.rates_table'); ?>" class="btn btn-primary">Stawki - tabela</a>
            </div>
        </section>

        <?php if ($flash && is_array($flash)): ?>
            <div class="alert <?php echo ($flash['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <strong><?php echo e((string)($flash['title'] ?? 'Komunikat')); ?></strong>
                <span><?php echo e((string)($flash['message'] ?? '')); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Nie zapisano zmian.</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e((string)$error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="layout-grid">
            <section class="card">
                <div class="card-head">
                    <div>
                        <h2>Aktualny podglad stawek bazowych</h2>
                        <p>Formularz ponizej jest domyslnie wypelniony aktualnymi wartosciami bazowymi, zeby korekta historyczna byla szybka i bez przepisywania wszystkiego od zera.</p>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($currentBaseRate): ?>
                        <div class="summary-grid">
                            <div class="summary-tile">
                                <div class="summary-label">Robocze</div>
                                <?php echo ratePairHtml($currentBaseRate['base_rate'] ?? null, $currentBaseRate['overtime_rate'] ?? null); ?>
                            </div>
                            <div class="summary-tile">
                                <div class="summary-label">Sobota</div>
                                <?php echo ratePairHtml($currentBaseRate['saturday_rate'] ?? null, $currentBaseRate['saturday_overtime_rate'] ?? null); ?>
                            </div>
                            <div class="summary-tile">
                                <div class="summary-label">Niedziela</div>
                                <?php echo ratePairHtml($currentBaseRate['sunday_rate'] ?? null, $currentBaseRate['sunday_overtime_rate'] ?? null); ?>
                            </div>
                            <div class="summary-tile">
                                <div class="summary-label">Nocka</div>
                                <?php echo ratePairHtml($currentBaseRate['night_rate'] ?? null, $currentBaseRate['night_overtime_rate'] ?? null); ?>
                            </div>
                            <div class="summary-tile">
                                <div class="summary-label">Delegacja</div>
                                <?php echo ratePairHtml($currentBaseRate['delegation_rate'] ?? null, $currentBaseRate['delegation_overtime_rate'] ?? null); ?>
                            </div>
                            <div class="summary-tile">
                                <div class="summary-label">Absencje</div>
                                <div class="pair-main"><?php echo e(rateValueText($currentBaseRate['vacation_rate'] ?? null)); ?></div>
                                <div class="pair-sub">L4 <?php echo e(rateValueText($currentBaseRate['sick_rate'] ?? null)); ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Brak aktualnej stawki bazowej. Dodaj pierwszy zakres, a historia i podglad zaczna sie wypelniac.</div>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="card">
                <div class="card-head">
                    <div>
                        <h3>Stan historii</h3>
                        <p>Szybki podglad, ile zakresow juz istnieje dla tego pracownika i ile z nich jest aktywnych na dzis.</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="meta-list">
                        <div class="meta-row">
                            <span>Wszystkie zakresy</span>
                            <strong><?php echo (int)$rateStats['total_count']; ?></strong>
                        </div>
                        <div class="meta-row">
                            <span>Aktywne dzis</span>
                            <strong><?php echo (int)$rateStats['active_count']; ?></strong>
                        </div>
                        <div class="meta-row">
                            <span>Zakresy projektowe</span>
                            <strong><?php echo (int)$rateStats['project_count']; ?></strong>
                        </div>
                        <div class="meta-row">
                            <span>Zakresy etapowe</span>
                            <strong><?php echo (int)$rateStats['stage_count']; ?></strong>
                        </div>
                    </div>
                    <div class="meta-note">
                        Stawki - tabela i Stawki ogolne zostaja bez zmian jako narzedzia do pracy masowej. Ten ekran jest miejscem do precyzyjnej korekty historii jednego pracownika.
                    </div>
                </div>
            </aside>
        </div>

        <section class="card" style="margin-bottom: 22px;">
            <div class="card-head">
                <div>
                    <h2>Dodaj lub skoryguj zakres stawki</h2>
                    <p>Nowy wpis nie nadpisuje slepo historii. System domknie lub podzieli tylko te zakresy, ktore nachodza na nowy przedzial dla tej samej stawki bazowej lub projektowej.</p>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <?php foreach ($rateGroups as $groupName => $fields): ?>
                        <div class="form-section">
                            <div class="section-title"><?php echo e($groupName); ?></div>
                            <div class="form-grid">
                                <?php foreach ($fields as $field): ?>
                                    <?php if ($field === 'valid_from'): ?>
                                        <div class="field">
                                            <label for="valid_from">Obowiazuje od</label>
                                            <input type="date" id="valid_from" name="valid_from" value="<?php echo e($formData['valid_from']); ?>" required>
                                            <small>Poczatek zakresu tej stawki.</small>
                                        </div>
                                    <?php elseif ($field === 'valid_to'): ?>
                                        <div class="field">
                                            <label for="valid_to">Obowiazuje do</label>
                                            <input type="date" id="valid_to" name="valid_to" value="<?php echo e($formData['valid_to']); ?>">
                                            <small>Zostaw puste dla stawki otwartej. Do przeliczenia wstecz zakres koncowy jest wymagany.</small>
                                        </div>
                                    <?php elseif ($field === 'project_id'): ?>
                                        <div class="field">
                                            <label for="project_id">Zakres projektu</label>
                                            <select id="project_id" name="project_id">
                                                <option value="">Stawka bazowa (wszystkie projekty)</option>
                                                <?php foreach ($projects as $project): ?>
                                                    <option value="<?php echo (int)$project['id']; ?>" <?php echo (string)$formData['project_id'] === (string)$project['id'] ? 'selected' : ''; ?>>
                                                        <?php echo e($project['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small>Opcjonalnie mozesz zapisac osobny zakres tylko dla wybranego projektu.</small>
                                        </div>
                                    <?php else: ?>
                                        <div class="field">
                                            <label for="<?php echo e($field); ?>"><?php echo e($rateFields[$field]); ?></label>
                                            <input type="number" id="<?php echo e($field); ?>" name="<?php echo e($field); ?>" step="0.01" min="0" value="<?php echo e($formData[$field]); ?>" <?php echo $field === 'base_rate' ? 'required' : ''; ?>>
                                            <small><?php echo $field === 'base_rate' ? 'Pole wymagane.' : 'Puste pole skorzysta z fallbacku bazowego lub nadgodzinowego przy liczeniu kosztu.'; ?></small>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="form-actions">
                        <div class="form-actions-note">
                            Zapisz i przelicz przelicza tylko zatwierdzone wpisy tego pracownika z zakresu nowej stawki. Dla stawki projektowej ograniczenie idzie dodatkowo po projekcie.
                        </div>
                        <div class="form-actions-buttons">
                            <button type="submit" name="action" value="save_rate" class="btn btn-submit-alt">Zapisz bez przeliczania</button>
                            <button type="submit" name="action" value="save_rate_recalc" class="btn btn-submit">Zapisz i przelicz</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Historia stawek</h2>
                    <p>Tu widac wszystkie zakresy tego pracownika: bazowe, projektowe i etapowe. Dzieki temu przed wpisaniem nowej korekty od razu masz podglad, co obowiazywalo wczesniej.</p>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($rates)): ?>
                    <div class="empty-state">Brak zapisanej historii stawek dla tego pracownika.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Status / scope</th>
                                    <th>Zakres</th>
                                    <th>Robocze</th>
                                    <th>Sobota</th>
                                    <th>Niedziela</th>
                                    <th>Nocka</th>
                                    <th>Delegacja</th>
                                    <th>Urlop</th>
                                    <th>L4</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rates as $rate): ?>
                                    <?php $meta = rateScopeMeta($rate, $today); ?>
                                    <tr>
                                        <td>
                                            <div class="badges">
                                                <span class="badge <?php echo e($meta['status']['class']); ?>"><?php echo e($meta['status']['label']); ?></span>
                                                <span class="badge <?php echo e($meta['scope']['class']); ?>"><?php echo e($meta['scope']['label']); ?></span>
                                            </div>
                                        </td>
                                        <td class="range-cell mono">
                                            <strong><?php echo e(formatDate($rate['valid_from'])); ?></strong>
                                            <span>do <?php echo $rate['valid_to'] ? e(formatDate($rate['valid_to'])) : 'teraz'; ?></span>
                                        </td>
                                        <td class="mono"><?php echo ratePairHtml($rate['base_rate'] ?? null, $rate['overtime_rate'] ?? null); ?></td>
                                        <td class="mono"><?php echo ratePairHtml($rate['saturday_rate'] ?? null, $rate['saturday_overtime_rate'] ?? null); ?></td>
                                        <td class="mono"><?php echo ratePairHtml($rate['sunday_rate'] ?? null, $rate['sunday_overtime_rate'] ?? null); ?></td>
                                        <td class="mono"><?php echo ratePairHtml($rate['night_rate'] ?? null, $rate['night_overtime_rate'] ?? null); ?></td>
                                        <td class="mono"><?php echo ratePairHtml($rate['delegation_rate'] ?? null, $rate['delegation_overtime_rate'] ?? null); ?></td>
                                        <td class="mono">
                                            <div class="pair-main"><?php echo e(rateValueText($rate['vacation_rate'] ?? null)); ?></div>
                                            <div class="pair-sub">urlop</div>
                                        </td>
                                        <td class="mono">
                                            <div class="pair-main"><?php echo e(rateValueText($rate['sick_rate'] ?? null)); ?></div>
                                            <div class="pair-sub">L4</div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <footer class="footer">
        &copy; <?php echo date('Y'); ?> <?php echo e(APP_NAME); ?> v<?php echo e(APP_VERSION); ?>
    </footer>
</body>
</html>
