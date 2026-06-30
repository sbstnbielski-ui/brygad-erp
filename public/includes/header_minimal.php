<?php
/**
 * BRYGAD ERP v3.0 - Minimalistyczny Header
 * 
 * Użycie w modułach:
 * $pageTitle = 'Nazwa strony';           // wymagane
 * $pageSubtitle = 'Podtytuł (opcja)';    // opcjonalne
 * include __DIR__ . '/../includes/header_minimal.php';
 * 
 * Lub z relatywną ścieżką:
 * include dirname(__DIR__) . '/includes/header_minimal.php';
 */

// Domyślne wartości
$pageTitle = $pageTitle ?? 'BRYGAD ERP';
$pageSubtitle = $pageSubtitle ?? '';
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'] ?? 'Użytkownik';
$isAdminUser = function_exists('isAdmin') ? isAdmin() : (($_SESSION['role'] ?? '') === 'admin');
?>
<header class="header-minimal">
    <div class="header-minimal-content">
        <div class="header-minimal-left">
            <a href="<?php echo url('dashboard'); ?>" class="header-minimal-home">🏠 Panel główny</a>
            <span class="header-minimal-separator">›</span>
            <div class="header-minimal-title">
                <span class="header-minimal-page"><?php echo e($pageTitle); ?></span>
                <?php if ($pageSubtitle): ?>
                    <span class="header-minimal-subtitle"><?php echo e($pageSubtitle); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="header-minimal-right">
            <span class="header-minimal-user">
                <?php echo e($userName); ?>
                <?php if ($isAdminUser): ?>
                    <span class="header-minimal-badge">Admin</span>
                <?php endif; ?>
            </span>
            <a href="<?php echo url('logout'); ?>" class="header-minimal-logout">Wyloguj</a>
        </div>
    </div>
</header>
<style>
.header-minimal {
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    position: sticky;
    top: 0;
    z-index: 100;
}
.header-minimal-content {
    max-width: 1600px;
    margin: 0 auto;
    padding: 12px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}
.header-minimal-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
.header-minimal-home {
    color: #1f2937;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    padding: 6px 12px;
    border-radius: 6px;
    transition: background 0.2s;
}
.header-minimal-home:hover {
    background: #f0f0f0;
}
.header-minimal-separator {
    color: #ccc;
    font-size: 14px;
}
.header-minimal-title {
    display: flex;
    align-items: baseline;
    gap: 10px;
}
.header-minimal-page {
    font-size: 18px;
    font-weight: 700;
    color: #333;
}
.header-minimal-subtitle {
    font-size: 14px;
    color: #666;
}
.header-minimal-right {
    display: flex;
    align-items: center;
    gap: 15px;
}
.header-minimal-user {
    font-size: 14px;
    color: #333;
    font-weight: 500;
}
.header-minimal-badge {
    display: inline-block;
    padding: 2px 8px;
    background: #1f2937;
    color: white;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    margin-left: 6px;
}
.header-minimal-logout {
    color: #dc3545;
    text-decoration: none;
    font-size: 13px;
    padding: 6px 12px;
    border-radius: 6px;
    transition: background 0.2s;
}
.header-minimal-logout:hover {
    background: #fee2e2;
}
@media (max-width: 600px) {
    .header-minimal-content {
        padding: 10px 16px;
    }
    .header-minimal-page {
        font-size: 16px;
    }
    .header-minimal-subtitle {
        display: none;
    }
}
</style>

