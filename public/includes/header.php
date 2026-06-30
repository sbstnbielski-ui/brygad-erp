<?php
/**
 * BRYGAD ERP - Uniwersalny nagłówek
 * 
 * Wymagane zmienne:
 * - $breadcrumbs (array): [['url' => '...', 'label' => '...'], ...]
 * - $pageTitle (string): Tytuł strony
 * - $moduleName (string): Nazwa modułu (opcjonalne)
 * - $moduleColor (string): Kolor modułu: purple, green, blue, orange, cyan (opcjonalne)
 */

if (!defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    die('Direct access not allowed');
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'] ?? 'Użytkownik';
$isAdmin = isAdmin();

// Domyślne wartości
$pageTitle = $pageTitle ?? 'BRYGAD ERP';
$moduleColor = $moduleColor ?? 'purple';

// Kolory modułów
$colors = [
    'purple' => '#7c3aed',
    'green' => '#16a34a',
    'blue' => '#2563eb',
    'orange' => '#ea580c',
    'cyan' => '#0891b2',
    'gray' => '#6b7280'
];
$themeColor = $colors[$moduleColor] ?? $colors['purple'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - <?php echo e(APP_NAME); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #333;
            min-height: 100vh;
        }
        
        .header {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .breadcrumbs {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #666;
        }
        .breadcrumbs a {
            color: <?php echo $themeColor; ?>;
            text-decoration: none;
            transition: color 0.2s;
        }
        .breadcrumbs a:hover {
            text-decoration: underline;
        }
        .breadcrumbs .separator {
            color: #ccc;
        }
        .breadcrumbs .current {
            color: #333;
            font-weight: 600;
        }
        
        .user-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-name {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            background: <?php echo $themeColor; ?>;
            color: white;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .btn-logout {
            padding: 8px 16px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-logout:hover {
            background: #c82333;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
    <script src="<?php echo asset('js/month-range-picker.js'); ?>" defer></script>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="breadcrumbs">
                <?php if (!empty($breadcrumbs)): ?>
                    <?php foreach ($breadcrumbs as $index => $crumb): ?>
                        <?php if ($index > 0): ?>
                            <span class="separator">›</span>
                        <?php endif; ?>
                        
                        <?php if (isset($crumb['url'])): ?>
                            <a href="<?php echo e($crumb['url']); ?>"><?php echo $crumb['label']; ?></a>
                        <?php else: ?>
                            <span class="current"><?php echo $crumb['label']; ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="user-section">
                <span class="user-name"><?php echo e($userName); ?></span>
                <?php if ($isAdmin): ?>
                <span class="role-badge">Admin</span>
                <?php endif; ?>
                <a href="<?php echo url('logout'); ?>" class="btn-logout">Wyloguj</a>
            </div>
        </div>
    </header>

    <div class="container">
