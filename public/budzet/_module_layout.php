<?php
/**
 * BRYGAD ERP - Budżet Domowy - Wspólny layout modułu
 */

if (!defined('HB_HOUSEHOLD_ID')) {
    die('Direct access not allowed');
}

if (!function_exists('hb_module_layout_styles')) {
    function hb_module_layout_styles(): string
    {
        return <<<'CSS'
/* ===== Shared module layout: Budżet Domowy ===== */
.hb-module-topbar {
    background: #ffffff;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    position: sticky;
    top: 0;
    z-index: 100;
}
.hb-module-topbar-content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 18px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}
.hb-module-brand {
    display: flex;
    align-items: center;
    gap: 16px;
}
.hb-module-brand-link {
    text-decoration: none;
    color: inherit;
    transition: opacity 0.2s ease;
    cursor: pointer;
}
.hb-module-brand-link:hover {
    opacity: 0.85;
}
.hb-module-brand img {
    height: 46px;
    border-radius: 6px;
}
.hb-module-brand-title {
    font-size: 24px;
    color: #333;
    margin: 0;
}
.hb-module-brand-subtitle {
    font-size: 13px;
    color: #666;
    margin: 0;
}
.hb-module-user {
    display: flex;
    align-items: center;
    gap: 12px;
}
.hb-module-user-name {
    font-weight: 600;
    color: #333;
    font-size: 14px;
}
.hb-module-user-btn {
    padding: 8px 14px;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: #fff;
    border-radius: 6px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}

.hb-module-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 30px;
}
.hb-module-layout {
    display: grid;
    grid-template-columns: 250px 1fr;
    gap: 30px;
    align-items: start;
}
.hb-module-main {
    min-width: 0;
}

.hb-module-nav {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.hb-module-nav a {
    padding: 9px 16px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    text-decoration: none;
    color: #333;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s ease;
}
.hb-module-nav a:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}
.hb-module-nav a.active {
    background: #667eea;
    color: #fff;
    border-color: #667eea;
}

.hb-module-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: #fff;
    padding: 34px 40px;
    border-radius: 12px;
    margin-bottom: 24px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
}
.hb-module-hero h2 {
    margin: 0 0 8px;
    font-size: 28px;
}
.hb-module-hero p {
    margin: 0;
    font-size: 14px;
    color: #94a3b8;
}

@media (max-width: 1024px) {
    .hb-module-layout {
        grid-template-columns: 1fr;
    }
    .hb-module-topbar-content {
        padding: 16px 20px;
    }
    .hb-module-container {
        padding: 20px;
    }
}
CSS;
    }

    function hb_module_nav_items(string $periodMonth): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => url('budzet.transakcje', ['period' => $periodMonth])],
            ['key' => 'analiza', 'label' => 'Analiza', 'url' => url('budzet.analiza')],
            ['key' => 'rachunki', 'label' => 'Rachunki', 'url' => url('budzet.rachunki', ['period' => $periodMonth])],
            ['key' => 'budzety', 'label' => 'Budżety', 'url' => url('budzet.budzety')],
            ['key' => 'konta', 'label' => 'Konta', 'url' => url('budzet.konta')],
            ['key' => 'kategorie', 'label' => 'Kategorie', 'url' => url('budzet.kategorie')],
            ['key' => 'domownicy', 'label' => 'Domownicy', 'url' => url('budzet.domownicy')],
        ];
    }

    function hb_module_shell_start(array $options): void
    {
        $active = (string)($options['active'] ?? '');
        $title = (string)($options['title'] ?? 'Budżet Domowy');
        $subtitle = (string)($options['subtitle'] ?? '');
        $userName = (string)($options['user_name'] ?? '');
        $periodMonth = (string)($options['period_month'] ?? date('Y-m'));

        if (!preg_match('/^\d{4}-\d{2}$/', $periodMonth)) {
            $periodMonth = date('Y-m');
        }

        $navItems = hb_module_nav_items($periodMonth);
        ?>
        <header class="hb-module-topbar">
            <div class="hb-module-topbar-content">
                <a href="<?php echo HB_HOME_URL; ?>" class="hb-module-brand hb-module-brand-link" aria-label="Powrót na stronę główną">
                    <img src="<?php echo asset('logo-brygad-erp.png'); ?>" alt="BRYGAD ERP">
                    <div>
                        <h1 class="hb-module-brand-title"><?php echo e(APP_NAME); ?></h1>
                        <p class="hb-module-brand-subtitle">Budżet Domowy</p>
                    </div>
                </a>
                <div class="hb-module-user">
                    <span class="hb-module-user-name"><?php echo e($userName); ?></span>
                    <a href="<?php echo HB_HOME_URL; ?>" class="hb-module-user-btn"><?php echo HB_HOME_LABEL; ?></a>
                    <a href="<?php echo url('logout'); ?>" class="hb-module-user-btn">Wyloguj</a>
                </div>
            </div>
        </header>

        <main class="hb-module-container">
            <div class="hb-module-layout">
                <?php require __DIR__ . '/_sidebar.php'; ?>
                <section class="hb-module-main">
                    <nav class="hb-module-nav">
                        <?php foreach ($navItems as $item): ?>
                            <a href="<?php echo $item['url']; ?>" class="<?php echo $active === $item['key'] ? 'active' : ''; ?>">
                                <?php echo e($item['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <div class="hb-module-hero">
                        <h2><?php echo e($title); ?></h2>
                        <?php if ($subtitle !== ''): ?>
                            <p><?php echo e($subtitle); ?></p>
                        <?php endif; ?>
                    </div>
        <?php
    }

    function hb_module_shell_end(): void
    {
        ?>
                </section>
            </div>
        </main>
        <?php
    }
}
