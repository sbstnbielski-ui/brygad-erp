<?php
/**
 * BRYGAD ERP - Techniczna mapa podstron
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$routeModuleLabels = [
    'assets' => 'Assets / Flota',
    'budzet' => 'Budzet domowy',
    'dokumenty' => 'Dokumenty',
    'finanse' => 'Finanse',
    'hr' => 'HR',
    'investors' => 'Kontrahenci',
    'projekty' => 'Projekty',
    'zadania' => 'Zadania',
    'root' => 'Root / inne',
];

$routeLabelMap = [
    'hr' => 'Dashboard HR',
    'hr.workers' => 'Lista pracownikow',
    'hr.worklog' => 'Dziennik pracy',
    'hr.settlements' => 'Centrum rozliczen',
    'hr.alerts' => 'Alerty HR',
    'finanse' => 'Dashboard finansow',
    'finanse.faktury-sprzedazowe' => 'Centrum faktur sprzedazowych',
    'finanse.fakturownia-cost-inbox' => 'Centrum faktur kosztowych',
    'finanse.fakturownia-archive' => 'Archiwum Fakturowni',
    'finanse.fakturownia-logs' => 'Logi API Fakturowni',
    'finanse.fakturownia-reconciliation' => 'Rekonsyliacja Fakturowni',
    'finanse.fakturownia-settings' => 'Konsola integracji Fakturownia',
    'finanse.koszty-stale' => 'Koszty stale',
    'finanse.koszty-pracownicze' => 'Koszty pracownicze',
    'finanse.koszty-wszystkie' => 'Wszystkie koszty',
    'finanse.wydatki' => 'Wydatki',
    'finanse.zaliczki' => 'Zaliczki pracownicze',
    'finanse.overview' => 'Overview finansow',
    'finanse.export' => 'Eksport finansow',
    'finanse.wlasciciel' => 'Raport wlasciciela',
    'projekty' => 'Lista projektow',
    'projekty.history' => 'Historia projektow',
    'projekty.raporty' => 'Raporty kosztow projektow',
    'projekty.etapy' => 'Etapy projektowe',
    'investors' => 'Baza kontrahentow',
    'dokumenty' => 'Dokumenty kosztowe (legacy)',
    'zadania' => 'Tablica zadan',
    'zadania.admin' => 'Zadania: panel admina',
    'assets' => 'Maszyny i flota',
    'assets.calendar' => 'Kalendarz zasobow',
    'budzet' => 'Budzet domowy',
];

$routeModules = [];
$routeCount = 0;

foreach (ROUTES as $routeName => $path) {
    if (strpos((string)$routeName, 'api.') === 0 || strpos((string)$path, '/api/') === 0) {
        continue;
    }
    if (in_array($routeName, ['login', 'logout', 'index'], true)) {
        continue;
    }

    $absolute = dirname(__DIR__) . $path;
    if (!is_file($absolute)) {
        continue;
    }

    $trimmed = trim($path, '/');
    $module = $trimmed === '' ? 'root' : explode('/', $trimmed)[0];
    if (!isset($routeModules[$module])) {
        $routeModules[$module] = [];
    }

    $duplicate = false;
    foreach ($routeModules[$module] as $existing) {
        if ($existing['path'] === $path) {
            $duplicate = true;
            break;
        }
    }
    if ($duplicate) {
        continue;
    }

    $label = $routeLabelMap[$routeName] ?? null;
    if ($label === null) {
        $base = basename($path, '.php');
        $base = str_replace(['-', '_'], ' ', $base);
        $label = mb_convert_case(trim($base) !== '' ? $base : str_replace('.', ' ', $routeName), MB_CASE_TITLE, 'UTF-8');
    }

    $routeModules[$module][] = [
        'route' => $routeName,
        'path' => $path,
        'label' => $label,
        'url' => url($routeName),
    ];
    $routeCount++;
}

ksort($routeModules);
foreach ($routeModules as &$moduleItems) {
    usort($moduleItems, static function (array $a, array $b): int {
        return strcmp($a['label'], $b['label']);
    });
}
unset($moduleItems);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Mapa podstron</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #1f2937;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 26px;
        }
        .breadcrumb {
            margin-bottom: 16px;
            color: #6b7280;
            font-size: 14px;
        }
        .breadcrumb a {
            color: #2563eb;
            text-decoration: none;
        }
        .header {
            margin-bottom: 18px;
        }
        .header h1 {
            margin: 0 0 6px;
            font-size: 28px;
        }
        .header p {
            margin: 0;
            color: #6b7280;
            font-size: 14px;
        }
        .meta {
            margin: 14px 0 18px;
            font-size: 13px;
            color: #4b5563;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 14px;
        }
        .module {
            background: #fff;
            border: 1px solid #dbe1ea;
            border-radius: 10px;
            overflow: hidden;
        }
        .module-title {
            margin: 0;
            padding: 10px 12px;
            font-size: 14px;
            font-weight: 700;
            color: #1e3a8a;
            background: #eef3ff;
            border-bottom: 1px solid #dbe1ea;
        }
        .module-list {
            list-style: none;
            padding: 8px;
            margin: 0;
            display: grid;
            gap: 6px;
        }
        .module-item {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 10px;
            background: #fbfdff;
        }
        .module-item a {
            display: block;
            text-decoration: none;
            color: #0f172a;
            font-size: 13px;
            font-weight: 600;
        }
        .module-item small {
            display: block;
            margin-top: 3px;
            color: #6b7280;
            font-size: 11px;
            word-break: break-all;
        }
    </style>
</head>
<body>
<?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

<div class="container">
    <div class="breadcrumb">
        <a href="<?php echo url('dashboard'); ?>">Panel Glowny</a> /
        <a href="<?php echo url('finanse.fakturownia-settings'); ?>">System i ustawienia</a> /
        Mapa podstron
    </div>

    <div class="header">
        <h1>Mapa podstron (techniczna)</h1>
    </div>

    <div class="meta">
        Liczba podstron: <strong><?php echo (int)$routeCount; ?></strong>
        | Moduly: <strong><?php echo count($routeModules); ?></strong>
    </div>

    <div class="grid">
        <?php foreach ($routeModules as $moduleKey => $moduleItems): ?>
            <?php $moduleTitle = $routeModuleLabels[$moduleKey] ?? strtoupper($moduleKey); ?>
            <section class="module">
                <h2 class="module-title"><?php echo e($moduleTitle); ?> (<?php echo count($moduleItems); ?>)</h2>
                <ul class="module-list">
                    <?php foreach ($moduleItems as $routeItem): ?>
                        <li class="module-item">
                            <a href="<?php echo e($routeItem['url']); ?>"><?php echo e($routeItem['label']); ?></a>
                            <small><?php echo e($routeItem['path']); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
