<?php
/**
 * BRYGAD ERP v3.0 - Header dla modułów (PIXEL PERFECT copy z dashboard.php)
 * 
 * UWAGA: To tylko HTML snippet header - nie zawiera <!DOCTYPE> ani <head>
 * Używaj WEWNĄTRZ <body>, każdy plik ma swój własny <head>
 * 
 * Użycie w modułach:
 * include dirname(__DIR__) . '/includes/header_modules.php';
 */

// Domyślne wartości
$userName = $_SESSION['worker_name'] ?? $_SESSION['login'] ?? 'Użytkownik';
$isAdminUser = function_exists('isAdmin') ? isAdmin() : (($_SESSION['role'] ?? '') === 'admin');

// Pobierz aktywne alerty HR (tylko dla admina)
$hrAlerts = [];
$alertsCount = 0;
if ($isAdminUser && isset($pdo)) {
    try {
        $stmt = $pdo->query("
            SELECT 
                ha.id,
                ha.alert_type,
                ha.due_date,
                wd.title as document_title,
                dt.name as document_type,
                w.first_name,
                w.last_name,
                wd.worker_id
            FROM hr_alerts ha
            JOIN worker_documents wd ON ha.document_id = wd.id
            JOIN document_types dt ON wd.document_type_id = dt.id
            JOIN workers w ON ha.worker_id = w.id
            WHERE ha.status = 'open'
            ORDER BY ha.due_date ASC
            LIMIT 10
        ");
        $hrAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $alertsCount = count($hrAlerts);
    } catch (PDOException $e) {
        // Cichо ignoruj błędy - header musi się załadować
    }
}
?>
<header class="header">
    <div class="header-content">
        <div class="logo-section">
            <a href="<?php echo url('dashboard'); ?>" class="logo-link">
                <img src="<?php echo asset('logo-brygad-erp.png'); ?>" alt="BRYGAD ERP" class="brand-logo">
                <div class="logo-text">
                    <p>System Zarządzania Operacyjnego</p>
                </div>
            </a>
        </div>
        <div class="user-section">
            <?php if (!$isAdminUser): ?>
            <a href="<?php echo url('hr.workers.my-advances'); ?>#wallet-overview" class="btn-wallet-shortcut">Portfel</a>
            <?php endif; ?>
            <?php if ($isAdminUser && $alertsCount > 0): ?>
            <div class="alerts-dropdown">
                <button class="alerts-button" onclick="toggleAlertsDropdown(event)">
                    🔔
                    <span class="alerts-badge"><?php echo $alertsCount; ?></span>
                </button>
                <div class="alerts-dropdown-content" id="alertsDropdown">
                    <div class="alerts-dropdown-header">
                        Alerty HR (<?php echo $alertsCount; ?>)
                    </div>
                    <?php foreach ($hrAlerts as $alert): ?>
                        <?php
                        $isExpired = strtotime($alert['due_date']) < time();
                        $daysLeft = (int)((strtotime($alert['due_date']) - time()) / 86400);
                        ?>
                        <div class="alert-dropdown-item">
                            <div class="alert-dropdown-content">
                                <strong><?php echo e($alert['first_name'] . ' ' . $alert['last_name']); ?></strong><br>
                                <span><?php echo e($alert['document_type']); ?>: <?php echo e($alert['document_title']); ?></span><br>
                                <small style="color: <?php echo $isExpired ? '#dc2626' : '#f59e0b'; ?>;">
                                    <?php if ($isExpired): ?>
                                        Wygaslo <?php echo formatDate($alert['due_date']); ?>
                                    <?php else: ?>
                                        ⏰ Wygasa za <?php echo $daysLeft; ?> dni
                                    <?php endif; ?>
                                </small>
                            </div>
                            <a href="<?php echo url('hr.alerts.close', ['id' => $alert['id']]); ?>" 
                               class="alert-close-btn"
                               onclick="return confirm('Zamknąć ten alert?');">
                                ✓
                            </a>
                        </div>
                    <?php endforeach; ?>
                    <div class="alerts-dropdown-footer">
                        <a href="<?php echo url('hr.alerts'); ?>">Zobacz wszystkie →</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div>
                <span class="user-name">
                    <?php echo e($userName); ?>
                    <?php if ($isAdminUser): ?>
                        <span class="role-badge">Administrator</span>
                    <?php endif; ?>
                </span>
            </div>
            <a href="<?php echo url('logout'); ?>" class="btn-logout">Wyloguj</a>
        </div>
    </div>
</header>

<style>
/* =========================================
   HEADER - PIXEL PERFECT COPY Z dashboard.php
   ========================================= */
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
    padding: 20px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}
.logo-section {
    display: flex;
    align-items: center;
    gap: 20px;
}
.logo-link {
    display: flex;
    align-items: center;
    gap: 20px;
    text-decoration: none;
    color: inherit;
    cursor: pointer;
    transition: opacity 0.2s ease;
}
.logo-link:hover {
    opacity: 0.8;
}
.logo-section img.brand-logo,
.logo-section img {
    height: 44px;
    width: auto;
    border-radius: 0;
}
.logo-text h1 { 
    font-size: 24px; 
    color: #333; 
}
.logo-text p { 
    font-size: 13px; 
    color: #666; 
}
.user-section {
    display: flex;
    align-items: center;
    gap: 20px;
}
.user-name { 
    font-weight: 600; 
    color: #333; 
}
.role-badge {
    display: inline-block;
    padding: 5px 12px;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: white;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    margin-left: 8px;
    letter-spacing: 0.5px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}
.btn-logout {
    padding: 5px 12px;
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}
.btn-logout:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.btn-wallet-shortcut {
    padding: 7px 14px;
    background: linear-gradient(135deg, #0369a1 0%, #0ea5e9 100%);
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-decoration: none;
    text-transform: uppercase;
    transition: all 0.2s ease;
    box-shadow: 0 2px 8px rgba(14, 165, 233, 0.35);
}

.btn-wallet-shortcut:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(14, 165, 233, 0.5);
}

@media (max-width: 768px) {
    .header-content {
        padding: 15px;
        gap: 10px;
    }
    .logo-section {
        gap: 12px;
    }
    .logo-section img {
        height: 42px;
    }
    .logo-text h1 {
        font-size: 18px;
    }
    .logo-text p {
        font-size: 11px;
    }
    .user-section {
        gap: 10px;
        flex-wrap: wrap;
    }
    .user-name {
        font-size: 14px;
    }
    .role-badge {
        padding: 4px 10px;
        font-size: 10px;
    }
    .btn-logout {
        padding: 8px 14px;
        font-size: 11px;
        height: 36px;
    }
    .btn-wallet-shortcut {
        padding: 8px 12px;
        font-size: 11px;
        height: 36px;
        display: inline-flex;
        align-items: center;
    }
}

@media (max-width: 375px) {
    .header-content {
        padding: 12px;
    }
    .logo-section img {
        height: 36px;
    }
    .logo-text h1 {
        font-size: 16px;
    }
    .logo-text p {
        display: none;
    }
}

/* =========================================
   ALERTY DROPDOWN
   ========================================= */
.alerts-dropdown {
    position: relative;
}
.alerts-button {
    position: relative;
    background: transparent;
    border: none;
    font-size: 24px;
    cursor: pointer;
    padding: 8px;
    transition: transform 0.2s;
}
.alerts-button:hover {
    transform: scale(1.1);
}
.alerts-badge {
    position: absolute;
    top: 4px;
    right: 4px;
    background: #dc2626;
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
    border: 2px solid white;
}
.alerts-dropdown-content {
    display: none;
    position: absolute;
    right: 0;
    top: 45px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    min-width: 350px;
    max-width: 400px;
    z-index: 1000;
}
.alerts-dropdown-content.show {
    display: block;
}
.alerts-dropdown-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 700;
    font-size: 14px;
    color: #dc2626;
}
.alert-dropdown-item {
    padding: 12px 20px;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 10px;
}
.alert-dropdown-item:hover {
    background: #f9fafb;
}
.alert-dropdown-item .alert-dropdown-content {
    flex: 1;
    font-size: 13px;
    line-height: 1.5;
}
.alert-dropdown-item .alert-dropdown-content strong {
    color: #111827;
}
.alert-dropdown-item .alert-dropdown-content span {
    color: #6b7280;
}
.alert-close-btn {
    background: #10b981;
    color: white;
    border: none;
    border-radius: 50%;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 16px;
    font-weight: 700;
    text-decoration: none;
    transition: all 0.2s;
    flex-shrink: 0;
}
.alert-close-btn:hover {
    background: #059669;
    transform: scale(1.1);
}
.alerts-dropdown-footer {
    padding: 12px 20px;
    text-align: center;
    border-top: 1px solid #e5e7eb;
}
.alerts-dropdown-footer a {
    color: #667eea;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}
.alerts-dropdown-footer a:hover {
    text-decoration: underline;
}
</style>

<script>
function toggleAlertsDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('alertsDropdown');
    dropdown.classList.toggle('show');
}

// Zamknij dropdown gdy kliknięto poza nim
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('alertsDropdown');
    if (dropdown && !event.target.closest('.alerts-dropdown')) {
        dropdown.classList.remove('show');
    }
});
</script>
<script src="<?php echo asset('js/month-range-picker.js'); ?>"></script>
