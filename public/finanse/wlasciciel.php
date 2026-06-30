<?php
/**
 * BRYGAD ERP - Finanse Właściciela
 * Dashboard łączący KPI firmy i budżetu domowego
 */

require_once dirname(__DIR__) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$pdo = getDbConnection();

// Pobierz zakres dat z rozszerzonej filtracji (jak w budżecie domowym)
$filterType = $_GET['filter_type'] ?? 'month';
$dateFrom = null;
$dateTo = null;
$label = '';

/**
 * Zwraca nazwę miesiąca i rok po polsku, np. "Luty 2026".
 */
function formatMonthYearPl(string $date): string {
    static $months = [
        1 => 'Styczeń',
        2 => 'Luty',
        3 => 'Marzec',
        4 => 'Kwiecień',
        5 => 'Maj',
        6 => 'Czerwiec',
        7 => 'Lipiec',
        8 => 'Sierpień',
        9 => 'Wrzesień',
        10 => 'Październik',
        11 => 'Listopad',
        12 => 'Grudzień'
    ];

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return '';
    }

    $month = (int)date('n', $timestamp);
    $year = date('Y', $timestamp);

    return ($months[$month] ?? date('F', $timestamp)) . ' ' . $year;
}

switch ($filterType) {
    case 'day':
        $day = $_GET['day'] ?? date('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $dateFrom = $day;
            $dateTo = $day;
            $label = date('d.m.Y', strtotime($day));
        } else {
            $dateFrom = date('Y-m-d');
            $dateTo = date('Y-m-d');
            $label = date('d.m.Y');
        }
        break;
        
    case 'week':
        $week = $_GET['week'] ?? date('Y') . '-W' . date('W');
        if (preg_match('/^\d{4}-W\d{2}$/', $week)) {
            $dateFrom = date('Y-m-d', strtotime($week));
            $dateTo = date('Y-m-d', strtotime($week . ' +6 days'));
            $label = 'Tydzień ' . date('W', strtotime($dateFrom)) . ', ' . date('Y', strtotime($dateFrom));
        } else {
            $dateFrom = date('Y-m-d', strtotime('monday this week'));
            $dateTo = date('Y-m-d', strtotime('sunday this week'));
            $label = 'Tydzień ' . date('W') . ', ' . date('Y');
        }
        break;
        
    case 'month':
        $month = $_GET['month'] ?? date('Y-m');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $dateFrom = $month . '-01';
            $dateTo = date('Y-m-t', strtotime($dateFrom));
            $label = formatMonthYearPl($dateFrom);
        } else {
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
            $label = formatMonthYearPl($dateFrom);
        }
        break;
        
    case 'range':
        $from = $_GET['date_from'] ?? date('Y-m-01');
        $to = $_GET['date_to'] ?? date('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $dateFrom = $from;
            $dateTo = $to;
            $label = date('d.m.Y', strtotime($from)) . ' - ' . date('d.m.Y', strtotime($to));
        } else {
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-d');
            $label = date('d.m.Y', strtotime($dateFrom)) . ' - ' . date('d.m.Y', strtotime($dateTo));
        }
        break;
        
    default:
        $dateFrom = date('Y-m-01');
        $dateTo = date('Y-m-t');
        $label = formatMonthYearPl($dateFrom);
        $filterType = 'month';
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Finanse Właściciela</title>
    <style>
        :root {
            --primary:           #667eea;
            --primary-dark:      #5a67d8;
            --primary-blue:      #1e3a8a;
            --primary-blue-dark: #172554;
            --bg-body:           #f5f7fa;
            --border:            #e5e7eb;
            --text-main:         #1f2937;
            --text-muted:        #6b7280;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body); color: var(--text-main); padding-bottom: 40px;
        }
        .container { max-width: 1500px; margin: 0 auto; padding: 25px; }

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
        .hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 700; letter-spacing: -0.4px; }
        .hero-breadcrumb { font-size: 12px; color: #bfdbfe; margin-bottom: 6px; }
        .hero-breadcrumb a { color: #dbeafe; text-decoration: none; }
        .hero p { margin: 0; color: #cbd5e1; font-size: 14px; }
        
        /* SPX FILTER SYSTEM */
        .spx-filter-box {
            margin-bottom: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .spx-filter-tabs {
            display: flex;
            gap: 6px;
            padding: 12px 20px;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        .spx-filter-tab {
            padding: 0 16px;
            height: 28px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            color: #374151;
            transition: all 0.15s;
            display: inline-flex;
            align-items: center;
        }
        .spx-filter-tab:hover {
            background: #f9fafb;
            border-color: #667eea;
            color: #667eea;
        }
        .spx-filter-tab.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
            font-weight: 600;
        }
        .spx-filter-content {
            display: none;
        }
        .spx-filter-content.active {
            display: block;
        }
        .spx-filter-bar {
            padding: 12px 20px;
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-wrap: nowrap;
        }
        .spx-filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
            flex: 1 1 0;
        }
        .spx-filter-group label {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .spx-filter-group input,
        .spx-filter-group select {
            padding: 0 8px;
            height: 38px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            font-family: inherit;
            transition: border-color 0.15s;
            width: 100%;
        }
        .spx-filter-group input:focus,
        .spx-filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        .spx-filter-submit {
            padding: 0 20px;
            height: 38px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            font-family: inherit;
            transition: background 0.15s;
        }
        .spx-filter-submit:hover { background: #5568d3; }
        .current-filter {
            background: #f0fdf4;
            border-top: 1px solid #bbf7d0;
            padding: 10px 20px;
            font-size: 13px;
            color: #166534;
        }
        .current-filter strong { font-weight: 600; }
        
        /* Loading state */
        .loading {
            text-align: center;
            padding: 60px;
            color: #999;
        }
        .loading::after {
            content: '...';
            animation: dots 1.5s steps(4, end) infinite;
        }
        @keyframes dots {
            0%, 20% { content: '.'; }
            40% { content: '..'; }
            60%, 100% { content: '...'; }
        }
        
        /* Karty KPI */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .kpi-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 6px solid;
        }
        .kpi-card.company { border-left-color: #2563eb; }
        .kpi-card.home { border-left-color: #16a34a; }
        .kpi-card.owner { border-left-color: #9333ea; }
        
        .kpi-card h3 {
            font-size: 16px;
            color: #666;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .kpi-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .kpi-row:last-child {
            border-bottom: none;
            padding-top: 20px;
            margin-top: 10px;
            border-top: 2px solid #f3f4f6;
        }
        .kpi-label {
            font-size: 14px;
            color: #666;
        }
        .kpi-value {
            font-size: 18px;
            font-weight: 700;
            color: #333;
        }
        .kpi-value.positive { color: #16a34a; }
        .kpi-value.negative { color: #dc2626; }
        .kpi-value.large {
            font-size: 32px;
        }
        
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
    </style>
</head>
<body>
    <?php include dirname(__DIR__) . '/includes/header_modules.php'; ?>

    <div class="container">
        <div class="hero">
            <div>
                <div class="hero-breadcrumb">
                    <a href="<?php echo url('dashboard'); ?>">Panel Główny</a> /
                    <a href="<?php echo url('finanse'); ?>">Finanse</a> /
                    Finanse Właściciela
                </div>
                <h1>Finanse Właściciela</h1>
                <p>Dashboard łączący KPI firmy i budżetu domowego</p>
            </div>
        </div>
        
        <!-- ROZSZERZONY FILTR -->
        <div class="spx-filter-box">
            <div class="spx-filter-tabs">
                <div class="spx-filter-tab <?php echo $filterType === 'day' ? 'active' : ''; ?>" onclick="switchFilterTab('day')">Dzien</div>
                <div class="spx-filter-tab <?php echo $filterType === 'week' ? 'active' : ''; ?>" onclick="switchFilterTab('week')">Tydzien</div>
                <div class="spx-filter-tab <?php echo $filterType === 'month' ? 'active' : ''; ?>" onclick="switchFilterTab('month')">Miesiac</div>
                <div class="spx-filter-tab <?php echo $filterType === 'range' ? 'active' : ''; ?>" onclick="switchFilterTab('range')">Zakres</div>
            </div>
            
            <!-- Dzien -->
            <div id="filter-day" class="spx-filter-content <?php echo $filterType === 'day' ? 'active' : ''; ?>">
                <form method="GET" class="spx-filter-bar">
                    <input type="hidden" name="filter_type" value="day">
                    <div class="spx-filter-group">
                        <label>Dzien</label>
                        <input type="date" name="day" value="<?php echo $filterType === 'day' ? $dateFrom : date('Y-m-d'); ?>" required>
                    </div>
                    <button type="submit" class="spx-filter-submit">Pokaz</button>
                </form>
            </div>
            
            <!-- Tydzien -->
            <div id="filter-week" class="spx-filter-content <?php echo $filterType === 'week' ? 'active' : ''; ?>">
                <form method="GET" class="spx-filter-bar">
                    <input type="hidden" name="filter_type" value="week">
                    <div class="spx-filter-group">
                        <label>Tydzien</label>
                        <input type="week" name="week" value="<?php echo $filterType === 'week' ? date('Y', strtotime($dateFrom)) . '-W' . date('W', strtotime($dateFrom)) : date('Y') . '-W' . date('W'); ?>" required>
                    </div>
                    <button type="submit" class="spx-filter-submit">Pokaz</button>
                </form>
            </div>
            
            <!-- Miesiac -->
            <div id="filter-month" class="spx-filter-content <?php echo $filterType === 'month' ? 'active' : ''; ?>">
                <form method="GET" class="spx-filter-bar">
                    <input type="hidden" name="filter_type" value="month">
                    <div class="spx-filter-group">
                        <label>Miesiac</label>
                        <input type="month" name="month" value="<?php echo $filterType === 'month' ? substr($dateFrom, 0, 7) : date('Y-m'); ?>" required>
                    </div>
                    <button type="submit" class="spx-filter-submit">Pokaz</button>
                </form>
            </div>
            
            <!-- Zakres -->
            <div id="filter-range" class="spx-filter-content <?php echo $filterType === 'range' ? 'active' : ''; ?>">
                <form method="GET" class="spx-filter-bar">
                    <input type="hidden" name="filter_type" value="range">
                    <div class="spx-filter-group">
                        <label>Od</label>
                        <input type="date" name="date_from" value="<?php echo $filterType === 'range' ? $dateFrom : date('Y-m-01'); ?>" required>
                    </div>
                    <div class="spx-filter-group">
                        <label>Do</label>
                        <input type="date" name="date_to" value="<?php echo $filterType === 'range' ? $dateTo : date('Y-m-d'); ?>" required>
                    </div>
                    <button type="submit" class="spx-filter-submit">Pokaz</button>
                </form>
            </div>
            
            <div class="current-filter">
                <strong>Aktualny okres:</strong> <?php echo e($label); ?>
            </div>
        </div>
        
        <!-- Loading state -->
        <div id="loading" class="loading">Ładowanie danych</div>
        
        <!-- Karty KPI (wypełniane przez JS) -->
        <div id="kpi-container" style="display: none;">
            <div class="kpi-grid">
                <!-- Karta: Wynik Firmy -->
                <div class="kpi-card company">
                    <h3>Wynik Firmy</h3>
                    <div class="kpi-row">
                        <span class="kpi-label">Przychody</span>
                        <span class="kpi-value" id="company-income">-</span>
                    </div>
                    <div class="kpi-row">
                        <span class="kpi-label">Koszty</span>
                        <span class="kpi-value" id="company-costs">-</span>
                    </div>
                    <div class="kpi-row">
                        <span class="kpi-label">Wynik netto</span>
                        <span class="kpi-value large" id="company-net">-</span>
                    </div>
                </div>
                
                <!-- Karta: Wynik Domu -->
                <div class="kpi-card home">
                    <h3>Wynik Domu</h3>
                    <div class="kpi-row">
                        <span class="kpi-label">Przychody</span>
                        <span class="kpi-value" id="home-income">-</span>
                    </div>
                    <div class="kpi-row">
                        <span class="kpi-label">Wydatki</span>
                        <span class="kpi-value" id="home-expenses">-</span>
                    </div>
                    <div class="kpi-row">
                        <span class="kpi-label">Wynik netto</span>
                        <span class="kpi-value large" id="home-net">-</span>
                    </div>
                </div>
                
                <!-- Karta: Wynik Łączny -->
                <div class="kpi-card owner">
                    <h3>Wynik Łączny Właściciela</h3>
                    <div class="kpi-row">
                        <span class="kpi-label">Suma wyników</span>
                        <span class="kpi-value large" id="owner-net">-</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Formatowanie kwoty
        function formatMoney(value) {
            return new Intl.NumberFormat('pl-PL', {
                style: 'currency',
                currency: 'PLN',
                minimumFractionDigits: 2
            }).format(value);
        }
        
        // Pobierz dane z API
        async function loadData() {
            const dateFrom = '<?php echo $dateFrom; ?>';
            const dateTo = '<?php echo $dateTo; ?>';
            const apiUrl = '<?php echo url("api.finanse.wlasciciel-dane"); ?>?date_from=' + dateFrom + '&date_to=' + dateTo;
            
            try {
                const response = await fetch(apiUrl);
                if (!response.ok) {
                    throw new Error('Błąd API: ' + response.status);
                }
                
                const data = await response.json();
                
                // Ukryj loading, pokaż dane
                document.getElementById('loading').style.display = 'none';
                document.getElementById('kpi-container').style.display = 'block';
                
                // Wypełnij karty KPI
                document.getElementById('company-income').textContent = formatMoney(data.company.income);
                document.getElementById('company-costs').textContent = formatMoney(data.company.costs);
                document.getElementById('company-net').textContent = formatMoney(data.company.net);
                document.getElementById('company-net').className = 'kpi-value large ' + (data.company.net >= 0 ? 'positive' : 'negative');
                
                document.getElementById('home-income').textContent = formatMoney(data.home.income);
                document.getElementById('home-expenses').textContent = formatMoney(data.home.expenses);
                document.getElementById('home-net').textContent = formatMoney(data.home.net);
                document.getElementById('home-net').className = 'kpi-value large ' + (data.home.net >= 0 ? 'positive' : 'negative');
                
                document.getElementById('owner-net').textContent = formatMoney(data.owner.net);
                document.getElementById('owner-net').className = 'kpi-value large ' + (data.owner.net >= 0 ? 'positive' : 'negative');
                
            } catch (error) {
                console.error('Błąd ładowania danych:', error);
                document.getElementById('loading').innerHTML = '<div style="color: #dc2626;">❌ Błąd ładowania danych: ' + error.message + '</div>';
            }
        }
        
        // Załaduj dane po załadowaniu strony
        document.addEventListener('DOMContentLoaded', loadData);
        
        function switchFilterTab(type) {
            document.querySelectorAll('.spx-filter-tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.spx-filter-content').forEach(content => content.classList.remove('active'));
            const tabs = ['day', 'week', 'month', 'range'];
            document.querySelector(`.spx-filter-tab:nth-child(${tabs.indexOf(type) + 1})`).classList.add('active');
            document.getElementById('filter-' + type).classList.add('active');
        }
    </script>
</body>
</html>
