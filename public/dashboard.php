<?php
/**
 * BRYGAD ERP v3.0 - Panel Główny
 */

require_once __DIR__ . '/config/autoload.php'; // ROOT domeny
startSecureSession();
requireLogin();

$pdo = getDbConnection();
$stats = [];
$isAdminUser = isAdmin();
$currentWorkerId = $_SESSION['worker_id'] ?? null;

if (!function_exists('nowaTablicaQueryScalar')) {
    function nowaTablicaQueryScalar(PDO $pdo, string $sql, array $params = [], $default = 0)
    {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $value = $stmt->fetchColumn();
            return $value !== false ? $value : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

try {
    if ($isAdminUser) {
        // Statystyki dla admina
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM workers WHERE is_active = 1");
        $stats['workers'] = $stmt->fetch()['count'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM projects WHERE status = 'active'");
        $stats['active_projects'] = $stmt->fetch()['count'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM work_logs WHERE status = 'pending'");
        $stats['pending_logs'] = $stmt->fetch()['count'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM worker_expenses WHERE status = 'pending'");
        $stats['pending_expenses'] = $stmt->fetch()['count'];
        
        // Licznik zadań do zrobienia
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT ta.id) as count
            FROM task_assignments ta
            JOIN tasks t ON ta.task_id = t.id
            WHERE t.is_active = 1 AND ta.status != 'done'
        ");
        $stats['pending_tasks'] = $stmt->fetch()['count'];

        // Mikroprojekty (dla osobnego kafelka)
        $stmt = $pdo->query("
            SELECT COUNT(*) as count
            FROM projects
            WHERE project_type = 'micro'
              AND status IN ('planned', 'active')
        ");
        $stats['micro_projects'] = (int)($stmt->fetch()['count'] ?? 0);
        
        // Koszty bieżącego miesiąca (Moduł 2)
        $firstDayOfMonth = date('Y-m-01');
        $today = date('Y-m-d');
        
        // Robocizna
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(final_cost), 0) as total
            FROM work_logs
            WHERE status = 'approved' AND date BETWEEN ? AND ?
        ");
        $stmt->execute([$firstDayOfMonth, $today]);
        $stats['month_labor_cost'] = $stmt->fetch()['total'];
        
        // Wydatki
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM worker_expenses
            WHERE status = 'approved' AND date BETWEEN ? AND ?
        ");
        $stmt->execute([$firstDayOfMonth, $today]);
        $stats['month_expenses_cost'] = $stmt->fetch()['total'];
        
        // Dokumenty kosztowe (faktury) - NETTO
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(da.amount_net), 0) as total
            FROM document_allocations da
            JOIN documents d ON d.id = da.document_id
            WHERE d.status = 'approved' 
              AND d.type = 'invoice_cost' 
              AND d.issue_date BETWEEN ? AND ?
        ");
        $stmt->execute([$firstDayOfMonth, $today]);
        $stats['month_invoices_cost'] = $stmt->fetch()['total'];
        
        $stats['month_total_cost'] = $stats['month_labor_cost'] + $stats['month_expenses_cost'] + $stats['month_invoices_cost'];

        // Przychody miesiąca (netto) do kafelka analiz
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount_net), 0) as total
            FROM project_revenues
            WHERE signed_date BETWEEN ? AND ?
        ");
        $stmt->execute([$firstDayOfMonth, $today]);
        $stats['month_revenue'] = (float)($stmt->fetch()['total'] ?? 0);
        $stats['analysis_result'] = $stats['month_revenue'] - $stats['month_total_cost'];
        
        // Dokumenty kosztowe draft
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM documents WHERE type = 'invoice_cost' AND status = 'draft'");
        $stats['draft_documents'] = $stmt->fetch()['count'];
        
        // Alerty HR
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM hr_alerts WHERE is_active = 1 AND is_acknowledged = 0");
            $stats['active_alerts'] = (int)($stmt->fetch()['count'] ?? 0);
        } catch (PDOException $e) {
            $stats['active_alerts'] = 0;
        }

        // Centrum akceptacji HR: agregat oczekujących decyzji
        $stats['hr_approvals'] = (int)$stats['pending_logs'] + (int)$stats['pending_expenses'] + (int)$stats['active_alerts'];

        // CRM — statystyki kontrahentów
        $stats['investors_active'] = (int)nowaTablicaQueryScalar(
            $pdo,
            "SELECT COUNT(*) FROM investors WHERE is_active = 1",
            [],
            0
        );
        $stats['investors_revenue'] = (float)nowaTablicaQueryScalar(
            $pdo,
            "SELECT COALESCE(SUM(amount_gross), 0) FROM invoices_sale WHERE status IN ('issued','paid')",
            [],
            0
        );
        // CRM — przypomnienia dziś i zaległe
        $stats['crm_reminders_due'] = (int)nowaTablicaQueryScalar(
            $pdo,
            "SELECT COUNT(*) FROM investor_reminders WHERE is_done = 0 AND remind_at <= CURDATE()",
            [],
            0
        );

        // Inbox kosztów Fakturownia (może nie istnieć na każdym środowisku)
        $stats['fakturownia_new'] = (int)nowaTablicaQueryScalar(
            $pdo,
            "SELECT COUNT(*) FROM fakturownia_cost_invoices WHERE workflow_status = 'new'",
            [],
            0
        );

        // Otwarte faktury sprzedażowe
        $stats['sales_open'] = (int)nowaTablicaQueryScalar(
            $pdo,
            "SELECT COUNT(*) FROM invoices_sale WHERE status IN ('draft','sent')",
            [],
            0
        );

        // Ostatnie pending logi HR — dla dynamicznego kafelka
        try {
            $stmtLogs = $pdo->query("
                SELECT wl.id, w.name AS worker_name, wl.date, wl.hours_worked, p.name AS project_name
                FROM work_logs wl
                JOIN workers w ON w.id = wl.worker_id
                LEFT JOIN projects p ON p.id = wl.project_id
                WHERE wl.status = 'pending'
                ORDER BY wl.created_at DESC, wl.date DESC
                LIMIT 4
            ");
            $recentPendingLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $recentPendingLogs = [];
        }
    } else {
        // Statystyki dla pracownika
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM work_logs WHERE worker_id = ? AND status = 'pending'");
        $stmt->execute([$currentWorkerId]);
        $stats['my_pending_logs'] = $stmt->fetch()['count'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM work_logs WHERE worker_id = ? AND status = 'approved'");
        $stmt->execute([$currentWorkerId]);
        $stats['my_approved_logs'] = $stmt->fetch()['count'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM worker_expenses WHERE worker_id = ? AND status = 'pending'");
        $stmt->execute([$currentWorkerId]);
        $stats['my_pending_expenses'] = $stmt->fetch()['count'];
        
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(final_cost), 0) as earned
            FROM work_logs 
            WHERE worker_id = ? AND status = 'approved' AND final_cost IS NOT NULL
        ");
        $stmt->execute([$currentWorkerId]);
        $stats['my_earned'] = $stmt->fetch()['earned'];

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(vad.amount_remaining), 0) AS available,
                COUNT(*) AS open_count
            FROM v_worker_advances_details vad
            WHERE vad.worker_id = ?
              AND vad.type = 'COMPANY'
              AND vad.status = 'open'
              AND vad.amount_remaining > 0.01
        ");
        $stmt->execute([$currentWorkerId]);
        $walletStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['my_wallet_available'] = (float)($walletStats['available'] ?? 0);
        $stats['my_wallet_open_count'] = (int)($walletStats['open_count'] ?? 0);
    }
} catch (PDOException $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}

$userName = $_SESSION['worker_name'] ?? $_SESSION['login'];

// Lewy routing rzeczy rzadziej używanych (właściciel/admin)
$sidebarRouting = [
    'Projekty' => [
        ['label' => 'Projekty', 'url' => url('projekty'), 'keywords' => 'projekty lista aktywne'],
        ['label' => 'Mikroprojekty', 'url' => url('projekty', ['project_type' => 'micro']), 'keywords' => 'mikroprojekty micro'],
        ['label' => 'Dodaj projekt', 'url' => url('projekty.create'), 'keywords' => 'dodaj nowy projekt'],
        ['label' => 'Dodaj etap', 'url' => url('projekty.etapy.create'), 'keywords' => 'dodaj etap projektu'],
        ['label' => 'Dodaj wydatek', 'url' => url('finanse.wydatki.create') . '?worker_id=1', 'keywords' => 'wydatek paragon dodaj'],
        ['label' => 'Dodaj przychód projektowy', 'url' => url('finanse.przychody.create'), 'keywords' => 'przychod projektowy umowa aneks'],
    ],
    'Baza Kontrahentów' => [
        ['label' => 'Wszyscy kontrahenci', 'url' => url('investors'), 'keywords' => 'kontrahenci crm klienci wszyscy lista'],
        ['label' => 'Dodaj kontrahenta', 'url' => url('investors.create'), 'keywords' => 'dodaj kontrahent klient nowy'],
    ],
    'Pracownicy' => [
        ['label' => 'Dodaj pracownika', 'url' => url('hr.workers.create'), 'keywords' => 'pracownik dodaj zatrudnij'],
        ['label' => 'Wpis czasu', 'url' => url('hr.worklog.create'), 'keywords' => 'wpis czasu dziennik pracy'],
        ['label' => 'Dodaj wydatek (pracownika)', 'url' => url('finanse.wydatki.create'), 'keywords' => 'wydatek pracownika paragon'],
        ['label' => 'Dodaj dokument', 'url' => url('hr.workers.documents'), 'keywords' => 'dokument pracownika'],
        ['label' => 'Stawki tabela', 'url' => url('hr.workers.rates_table'), 'keywords' => 'stawki tabela'],
        ['label' => 'Stawki ogólne', 'url' => url('hr.workers.bulk_rates'), 'keywords' => 'stawki ogolne'],
        ['label' => 'Lista pracowników', 'url' => url('hr.workers'), 'keywords' => 'pracownicy lista'],
        ['label' => 'Dziennik pracy', 'url' => url('hr.worklog'), 'keywords' => 'dziennik pracy logi'],
        ['label' => 'Historia operacji', 'url' => url('hr') . '?tab=history', 'keywords' => 'historia operacje rozliczenia'],
        ['label' => 'Wydatki pracownika', 'url' => url('finanse.wydatki'), 'keywords' => 'wydatki pracownika'],
    ],
    'Finanse' => [
        ['label' => 'Koszty Firmy', 'url' => url('finanse.koszty-stale'), 'keywords' => 'koszty firmy wydatki firmowe koszty stale'],
        ['label' => 'Faktury kosztowe', 'url' => url('finanse.fakturownia-cost-inbox'), 'keywords' => 'faktury kosztowe centrum ksef fakturownia'],
        ['label' => 'Centrum faktur sprzedażowych', 'url' => url('finanse.faktury-sprzedazowe'), 'keywords' => 'faktury sprzedazowe centrum'],
    ],
    'Analiza finansowa' => [
        ['label' => 'Finanse właściciela', 'url' => url('finanse.wlasciciel'), 'keywords' => 'wlasciciel wynik firmy'],
        ['label' => 'Analiza finansowa', 'url' => url('finanse.overview'), 'keywords' => 'analiza overview'],
        ['label' => 'Wszystkie koszty', 'url' => url('finanse.koszty-wszystkie'), 'keywords' => 'wszystkie koszty'],
        ['label' => 'Eksport danych', 'url' => url('finanse.export'), 'keywords' => 'eksport dane json'],
    ],
    '_flat_Zadania' => ['label' => 'Zadania', 'url' => url('zadania'), 'keywords' => 'zadania kanban workflow'],
    '_flat_Maszyny i flota' => ['label' => 'Maszyny i flota', 'url' => url('assets'), 'keywords' => 'maszyny flota zasoby'],
    'System i ustawienia' => [
        ['label' => 'Konsola integracji', 'url' => url('finanse.fakturownia-settings'), 'keywords' => 'integracje ustawienia fakturownia'],
        ['label' => 'Mapa podstron (techniczna)', 'url' => url('finanse.system-map'), 'keywords' => 'mapa podstron routing techniczne'],
        ['label' => 'Logi API Fakturowni', 'url' => url('finanse.fakturownia-logs'), 'keywords' => 'logi api'],
        ['label' => 'Rekonsyliacja Fakturowni', 'url' => url('finanse.fakturownia-reconciliation'), 'keywords' => 'rekonsyliacja'],
        ['label' => 'Archiwum Fakturowni', 'url' => url('finanse.fakturownia-archive'), 'keywords' => 'archiwum fakturownia pdf'],
    ],
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(APP_NAME); ?> - Panel Główny</title>
    <style>
        :root {
            --primary-blue: #1e3a8a; /* Ciemny, elegancki niebieski dla panelu */
            --primary-blue-light: #2563eb;
            --primary-blue-dark: #172554;
            --bg-color: #f4f6f9;
            --text-main: #1f2937;
            --text-muted: #6b7280;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg-color);
            color: var(--text-main);
        }
        
        /* HEADER */
        .header {
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .header-content {
            max-width: 1600px;
            margin: 0 auto;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo-section { display: flex; align-items: center; gap: 15px; }
        .logo-section img.brand-logo,
        .logo-section img { height: 44px; width: auto; border-radius: 0; }
        .logo-text h1 { font-size: 20px; color: var(--text-main); letter-spacing: -0.5px;}
        .logo-text p { font-size: 12px; color: var(--text-muted); }
        
        .user-section { display: flex; align-items: center; gap: 20px; }
        .user-name { font-weight: 600; font-size: 14px; color: #333; }
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
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-logout:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 16px 30px 20px;
        }

        /* LAYOUT Z ZWIJANYM PANELEM */
        .dashboard-layout {
            display: flex;
            align-items: flex-start;
            gap: 0;
            transition: all 0.3s ease;
        }

        .dashboard-content { padding-left: 16px; }
        #sidebarWrapper.collapsed + .dashboard-content,
        .dashboard-layout:not(:has(#sidebarWrapper)) .dashboard-content {
            padding-left: 0;
        }

        /* NIEBIESKI PANEL BOCZNY (SIDEBAR) */
        .sidebar-wrapper {
            position: relative;
            flex-shrink: 0;
            z-index: 10;
        }
        .sidebar-actions {
            background: linear-gradient(180deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
            border-radius: 12px 0 12px 12px;
            width: 240px;
            height: calc(100vh - 90px);
            position: sticky;
            top: 74px;
            overflow-y: auto;
            overflow-x: hidden;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 15px rgba(30, 58, 138, 0.2);
            color: white;
        }
        .sidebar-wrapper.collapsed .sidebar-actions { width: 0; }

        /* PRZYCISK ZWIJANIA */
        .toggle-sidebar-btn {
            position: absolute;
            top: 20px;
            right: -14px;
            width: 28px; height: 28px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            z-index: 20;
            color: var(--primary-blue);
            transition: all 0.3s ease;
        }
        .toggle-sidebar-btn:hover { background: #f8fafc; transform: scale(1.1); }
        .sidebar-wrapper.collapsed .toggle-sidebar-btn { right: -32px; transform: rotate(180deg); }

        /* Wnętrze panelu */
        .sidebar-content-inner { width: 240px; }
        .sidebar-search { padding: 12px 14px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-search input {
            width: 100%;
            padding: 8px 12px;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            border-radius: 7px;
            font-size: 12px;
            transition: all 0.2s;
        }
        .sidebar-search input::placeholder { color: #93c5fd; }
        .sidebar-search input:focus { outline: none; background: rgba(255,255,255,0.15); border-color: #60a5fa; }

        /* ACCORDION SEKCJE */
        .sidebar-actions-body { padding: 10px; }
        .sidebar-section {
            margin-bottom: 4px;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 7px;
            overflow: hidden;
        }
        .sidebar-section-title {
            font-size: 11px;
            color: #93c5fd;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            list-style: none;
            margin: 0;
            padding: 8px 10px;
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255,255,255,0.04);
        }
        .sidebar-section-title::-webkit-details-marker { display: none; }
        .sidebar-section-title::after {
            content: '›';
            font-size: 14px;
            color: rgba(255,255,255,0.4);
            transition: transform 0.2s ease;
            line-height: 1;
        }
        .sidebar-section[open] .sidebar-section-title::after { transform: rotate(90deg); }
        .sidebar-section-links { padding: 4px 6px 6px; }

        .sidebar-actions a {
            display: block;
            padding: 7px 10px;
            margin-bottom: 2px;
            color: #e2e8f0;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.15s ease;
            font-size: 12px;
            font-weight: 500;
        }
        .sidebar-actions a:hover {
            background: rgba(255,255,255,0.12);
            color: white;
            padding-left: 14px;
        }
        .sidebar-flat-link {
            display: flex;
            align-items: center;
            padding: 8px 10px;
            margin-bottom: 4px;
            color: #93c5fd !important;
            text-decoration: none;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.1);
            transition: all 0.15s ease;
        }
        .sidebar-flat-link:hover {
            background: rgba(255,255,255,0.1) !important;
            color: #bfdbfe !important;
            padding-left: 14px;
        }

        /* GŁÓWNA ZAWARTOŚĆ */
        .dashboard-content { flex: 1; min-width: 0; }

        /* KAFELEK SZEFA */
        .tile-boss {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--primary-blue-dark) 100%);
            border-radius: 0 10px 10px 0;
            padding: 14px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            color: white;
            margin-bottom: 14px;
            box-shadow: 0 4px 15px rgba(30, 58, 138, 0.25);
            position: relative;
            overflow: hidden;
        }
        .tile-boss.sidebar-open { border-radius: 0 10px 10px 0; }
        .tile-boss.sidebar-closed { border-radius: 10px; }
        .tile-boss::before {
            content: '';
            position: absolute;
            top: -30px; right: -30px;
            width: 130px; height: 130px;
            background: rgba(255,255,255,0.04);
            border-radius: 50%;
        }

        .boss-right {
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .boss-link-plain {
            color: white; text-decoration: none; font-size: 12px;
            font-weight: 600; cursor: pointer; position: relative; z-index: 5;
        }
        .boss-link-plain:hover { opacity: 0.8; }
        .boss-link-arrow {
            color: rgba(255,255,255,0.4); display: flex; align-items: center;
            transition: color 0.2s, transform 0.2s;
        }
        .boss-link-arrow:hover { color: white; transform: translateX(3px); }

        .boss-avatar {
            width: 40px; height: 40px;
            border-radius: 10px;
            background: rgba(255,255,255,0.15);
            border: 2px solid rgba(255,255,255,0.25);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 18px;
        }
        .boss-info { flex: 1; min-width: 0; }
        .boss-name { font-size: 16px; font-weight: 800; letter-spacing: -0.3px; margin-bottom: 2px; }
        .boss-role {
            font-size: 10px; color: #93c5fd;
            text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 5px;
        }
        .boss-stats { display: flex; gap: 12px; flex-wrap: wrap; }
        .boss-stat-item { font-size: 12px; color: rgba(255,255,255,0.75); }
        .boss-stat-item strong { color: white; font-weight: 700; }
        .boss-date { font-size: 10px; color: rgba(255,255,255,0.5); font-weight: 400; }

        /* SIATKA 3x3 KAFELKÓW */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }
        .modern-tile {
            background: white;
            border-radius: 10px;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            text-decoration: none;
            color: var(--text-main);
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
            min-height: 130px;
        }
        .modern-tile:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px -4px rgba(0,0,0,0.1);
            border-color: #cbd5e1;
        }
        .tile-icon {
            width: 38px; height: 38px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 10px;
            background: #f1f5f9;
            color: var(--primary-blue-light);
            transition: all 0.3s;
        }
        .modern-tile:hover .tile-icon { background: var(--primary-blue-light); color: white; transform: scale(1.05); }
        .tile-icon svg { width: 20px; height: 20px; stroke-width: 1.5; }

        .tile-title { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
        .tile-desc { font-size: 12px; color: var(--text-muted); line-height: 1.35; flex-grow: 1; }
        .tile-stat {
            margin-top: 10px;
            display: inline-flex; align-items: center;
            padding: 3px 8px;
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 20px; font-size: 11px; font-weight: 600;
            color: var(--primary-blue); width: fit-content;
        }

        .tile-hr .tile-icon { color: #8b5cf6; background: #f5f3ff; }
        .modern-tile.tile-hr:hover .tile-icon { background: #8b5cf6; color: white; }
        .tile-finance .tile-icon { color: #10b981; background: #ecfdf5; }
        .modern-tile.tile-finance:hover .tile-icon { background: #10b981; color: white; }
        .tile-warning .tile-icon { color: #f59e0b; background: #fffbeb; }
        .modern-tile.tile-warning:hover .tile-icon { background: #f59e0b; color: white; }
        .tile-crm .tile-icon { color: #0ea5e9; background: #e0f2fe; }
        .modern-tile.tile-crm:hover .tile-icon { background: #0ea5e9; color: white; }

        .modern-tile::after {
            content: ''; position: absolute; bottom: 0; left: 0;
            height: 3px; width: 0%;
            background: var(--primary-blue-light); transition: width 0.3s ease;
        }
        .modern-tile:hover::after { width: 100%; }
        .modern-tile.tile-hr::after { background: #8b5cf6; }
        .modern-tile.tile-finance::after { background: #10b981; }
        .modern-tile.tile-warning::after { background: #f59e0b; }
        .modern-tile.tile-crm::after { background: #0ea5e9; }

        /* WERSJA MOBILNA */
        @media (max-width: 1200px) { .modules-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 900px) {
            .dashboard-layout { flex-direction: column; }
            .sidebar-wrapper { width: 100%; margin-bottom: 14px; }
            .sidebar-actions { width: 100%; height: auto; position: static; max-height: 260px; }
            .toggle-sidebar-btn { display: none; }
            .sidebar-wrapper.collapsed .sidebar-actions { width: 100%; height: 0; padding: 0; }
        }
        @media (max-width: 600px) {
            .modules-grid { grid-template-columns: 1fr; }
            .tile-boss { flex-wrap: wrap; }
            .boss-right { align-items: flex-start; }
            .boss-date { display: none; }
        }

        /* DYNAMICZNY KAFELEK HR */
        .tile-hr-dynamic {
            display: flex;
            flex-direction: column;
            min-height: 160px;
        }
        .tile-hr-dynamic .tile-header {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }
        .tile-hr-dynamic .tile-header .tile-icon { margin-bottom: 0; flex-shrink: 0; }
        .tile-hr-dynamic .tile-header-text { flex: 1; }
        .pending-log-list {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 2px;
            flex-grow: 1;
        }
        .pending-log-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 8px;
            background: #f5f3ff;
            border-radius: 6px;
            font-size: 12px;
            color: var(--text-main);
            border: 1px solid #ede9fe;
        }
        .pending-log-item .log-worker {
            font-weight: 600;
            color: #6d28d9;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 90px;
        }
        .pending-log-item .log-hours {
            font-weight: 700;
            color: #1f2937;
            white-space: nowrap;
        }
        .pending-log-item .log-date {
            color: var(--text-muted);
            font-size: 11px;
            white-space: nowrap;
            margin-left: auto;
        }
        .pending-log-more {
            font-size: 11px;
            color: var(--text-muted);
            text-align: center;
            margin-top: 4px;
        }

        /* Inne stare style pomocnicze */
        .footer { text-align: center; padding: 20px; color: #999; font-size: 13px; }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo-section">
                <a href="<?php echo url('dashboard'); ?>" style="display: flex; align-items: center; gap: 15px; text-decoration: none;">
                    <img src="<?php echo asset('logo-brygad-erp.png'); ?>" alt="BRYGAD ERP" class="brand-logo">
                <div class="logo-text">
                    <p>Zintegrowane Środowisko Operacyjne</p>
                </div>
                </a>
            </div>
            <div class="user-section">
                <div>
                    <span class="user-name">
                        <?php echo e($userName); ?>
                        <?php if ($isAdminUser): ?>
                            <span class="role-badge">Administrator</span>
                        <?php endif; ?>
                    </span>
                </div>
                <a href="<?php echo url('logout'); ?>" class="btn-logout">Wyloguj się</a>
            </div>
        </div>
    </header>
    
    <?php 
    @setlocale(LC_TIME, 'pl_PL.UTF-8', 'pl_PL', 'polish', 'pl');
    $polishMonths = ['January' => 'stycznia', 'February' => 'lutego', 'March' => 'marca', 'April' => 'kwietnia', 'May' => 'maja', 'June' => 'czerwca', 'July' => 'lipca', 'August' => 'sierpnia', 'September' => 'września', 'October' => 'października', 'November' => 'listopada', 'December' => 'grudnia'];
    $polishDays = ['Monday' => 'Poniedziałek', 'Tuesday' => 'Wtorek', 'Wednesday' => 'Środa', 'Thursday' => 'Czwartek', 'Friday' => 'Piątek', 'Saturday' => 'Sobota', 'Sunday' => 'Niedziela'];
    $dateStr = strftime('%A, %d %B %Y');
    $formattedDate = strtr($dateStr, array_merge($polishDays, $polishMonths));
    ?>
    <div class="container">
        <?php if (!$isAdminUser): ?>
        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 20px;">
            <a href="<?php echo url('hr.worklog.create'); ?>" style="padding: 10px 20px; background: var(--primary-blue-light); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px;">+ Zgłoś pracę</a>
            <a href="<?php echo url('finanse.wydatki.create'); ?>" style="padding: 10px 20px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px;">+ Dodaj wydatek</a>
        </div>
        <?php endif; ?>
        
        <div class="dashboard-layout">
            <!-- LEWY PANEL ROUTINGU (Niebieski, zwijany) -->
            <?php if ($isAdminUser): ?>
            <div class="sidebar-wrapper" id="sidebarWrapper">
                <div class="toggle-sidebar-btn" onclick="toggleSidebar()" title="Zwiń/Rozwiń panel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                </div>
                <aside class="sidebar-actions">
                    <div class="sidebar-content-inner">
                        <div class="sidebar-search">
                            <input type="text" id="actionSearch" placeholder="Szukaj w nawigacji..." autocomplete="off">
                        </div>
                        
                        <div class="sidebar-actions-body">
                            <?php foreach ($sidebarRouting as $sectionName => $sectionLinks): ?>
                                <?php if (str_starts_with($sectionName, '_flat_')): ?>
                                    <a href="<?php echo e($sectionLinks['url']); ?>" class="sidebar-flat-link" data-keywords="<?php echo e($sectionLinks['keywords']); ?>">
                                        <?php echo e($sectionLinks['label']); ?>
                                    </a>
                                <?php else: ?>
                                <details class="sidebar-section">
                                    <summary class="sidebar-section-title"><?php echo e($sectionName); ?></summary>
                                    <div class="sidebar-section-links">
                                        <?php foreach ($sectionLinks as $link): ?>
                                            <a href="<?php echo e($link['url']); ?>" data-keywords="<?php echo e($link['keywords']); ?>">
                                                <?php echo e($link['label']); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <!-- CRM — przypomnienia na dziś/zaległe -->
                            <?php if (($stats['crm_reminders_due'] ?? 0) > 0): ?>
                            <a href="<?php echo url('investors'); ?>" class="sidebar-flat-link" data-keywords="crm przypomnienia kontrahenci" style="background:rgba(251,191,36,0.15);border-color:rgba(251,191,36,0.4);color:#fbbf24;">
                                ⏰ Przypomnienia CRM
                                <span style="margin-left:auto;background:#fbbf24;color:#1f2937;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:800;"><?php echo (int)$stats['crm_reminders_due']; ?></span>
                            </a>
                            <?php endif; ?>

                            <!-- Sekcja Powiadomienia Push (specjalna — JS interaktywna) -->
                            <details class="sidebar-section" id="pushSection">
                                <summary class="sidebar-section-title">Powiadomienia</summary>
                                <div class="sidebar-section-links">
                                    <a href="#" id="pushToggle" onclick="togglePushNotifications(); return false;" data-keywords="powiadomienia push alert włącz wyłącz">
                                        <span id="pushStatus">Sprawdzam...</span>
                                    </a>
                                    <a href="#" id="pushTest" onclick="sendTestPush(); return false;" data-keywords="test testowe powiadomienie" style="display: none;">
                                        Wyślij powiadomienie testowe
                                    </a>
                                </div>
                            </details>
                        </div>
                    </div>
                </aside>
            </div>
            <?php endif; ?>
            
            <!-- PRAWA STRONA - KAFELKI -->
            <div class="dashboard-content">
                
                <?php if ($isAdminUser): ?>

                <!-- KAFELEK SZEFA — tworzy poziomą część litery L razem z sidebarem -->
                <div class="tile-boss">
                    <div class="boss-avatar">👤</div>
                    <div class="boss-info">
                        <div class="boss-role">Administrator &middot; Właściciel &nbsp;<span class="boss-date"><?php echo $formattedDate; ?></span></div>
                        <div class="boss-name"><?php echo e($userName); ?></div>
                        <div class="boss-stats">
                            <span class="boss-stat-item"><strong><?php echo $stats['workers']; ?></strong> pracowników</span>
                            <span class="boss-stat-item"><strong><?php echo $stats['active_projects']; ?></strong> aktywnych projektów</span>
                            <?php if (($stats['active_alerts'] ?? 0) > 0): ?>
                            <span class="boss-stat-item" style="color:#fbbf24;"><strong><?php echo $stats['active_alerts']; ?></strong> alertów HR</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="boss-right">
                        <a href="budzet/transakcje.php?period=<?php echo date('Y-m'); ?>" class="boss-link-plain">Dom</a>
                        <a href="budzet/transakcje.php?period=<?php echo date('Y-m'); ?>" class="boss-link-plain boss-link-arrow">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
                        </a>
                    </div>
                </div>

                <!-- SIATKA MODUŁÓW 3x3 -->
                <div class="modules-grid">
                    
                    <!-- 1. Pracownicy -->
                    <a href="<?php echo url('hr.workers'); ?>" class="modern-tile tile-hr">
                        <div class="tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                        <div class="tile-title">Pracownicy</div>
                        <div class="tile-desc">Baza personelu, stawki, urlopy, dokumenty i struktura firmy.</div>
                        <span class="tile-stat"><?php echo $stats['workers']; ?> aktywnych</span>
                    </a>

                    <!-- 2. Dziennik pracy -->
                    <a href="<?php echo url('hr.worklog'); ?>" class="modern-tile">
                        <div class="tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><path d="M12 14l-2 2 4 4"></path></svg>
                        </div>
                        <div class="tile-title">Dziennik Pracy</div>
                        <div class="tile-desc">Ewidencja czasu pracy, weryfikacja godzin przypisanych do projektów.</div>
                        <span class="tile-stat"><?php echo $stats['pending_logs']; ?> logów do weryf.</span>
                    </a>

                    <!-- 4. Projekty -->
                    <a href="<?php echo url('projekty'); ?>" class="modern-tile">
                        <div class="tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                        </div>
                        <div class="tile-title">Projekty</div>
                        <div class="tile-desc">Zarządzanie budowami, harmonogramy i rentowność poszczególnych zleceń.</div>
                        <span class="tile-stat"><?php echo $stats['active_projects']; ?> w toku</span>
                    </a>

                    <!-- 5. Mikroprojekty -->
                    <a href="<?php echo url('projekty', ['project_type' => 'micro']); ?>" class="modern-tile">
                        <div class="tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="8" height="8"></rect><rect x="13" y="3" width="8" height="8"></rect><rect x="3" y="13" width="8" height="8"></rect><rect x="13" y="13" width="8" height="8"></rect></svg>
                        </div>
                        <div class="tile-title">Mikroprojekty</div>
                        <div class="tile-desc">Szybki dostęp do drobnych zleceń i ich etapów.</div>
                        <span class="tile-stat"><?php echo (int)($stats['micro_projects'] ?? 0); ?> aktywnych/planned</span>
                    </a>

                    <!-- 6. Koszty Firmy -->
                    <a href="<?php echo url('finanse.koszty-stale'); ?>" class="modern-tile tile-warning">
                        <div class="tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                        </div>
                        <div class="tile-title">Koszty Firmy</div>
                        <div class="tile-desc">Wydatki firmowe, faktury kosztowe i koszty pracownicze.</div>
                        <span class="tile-stat"><?php echo (int)($stats['fakturownia_new'] ?? 0); ?> nowych faktur</span>
                    </a>

                    <!-- 7. Centrum faktur sprzedażowych -->
                    <a href="<?php echo url('finanse.faktury-sprzedazowe'); ?>" class="modern-tile tile-finance">
                        <div class="tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="12" x2="12" y2="18"></line><polyline points="9 15 12 12 15 15"></polyline></svg>
                        </div>
                        <div class="tile-title">Centrum faktur sprzedażowych</div>
                        <div class="tile-desc">Wystawianie faktur dla inwestorów, protokoły odbioru i należności.</div>
                        <span class="tile-stat"><?php echo (int)($stats['sales_open'] ?? 0); ?> roboczych/wysłanych</span>
                    </a>

                    <!-- 8. Centrum analiz finansowych -->
                    <a href="<?php echo url('finanse'); ?>" class="modern-tile tile-finance">
                        <div class="tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        </div>
                        <div class="tile-title">Centrum analiz finansowych</div>
                        <div class="tile-desc">Koszty, przychody, wynik i przegląd kondycji firmy.</div>
                        <span class="tile-stat">Wynik m-ca: <?php echo number_format((float)($stats['analysis_result'] ?? 0), 0, ',', ' '); ?> zł</span>
                    </a>

                    <!-- 9. CRM — Baza Kontrahentów -->
                    <a href="<?php echo url('investors'); ?>" class="modern-tile tile-crm">
                        <div class="tile-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </div>
                        <div class="tile-title">Baza Kontrahentów</div>
                        <div class="tile-desc">Kontrahenci, faktury i projekty.</div>
                        <div style="display:flex;gap:16px;margin-top:auto;">
                            <span class="tile-stat"><?php echo (int)($stats['investors_active'] ?? 0); ?> aktywnych</span>
                            <span class="tile-stat" style="color:#10b981;"><?php echo formatMoney($stats['investors_revenue'] ?? 0); ?></span>
                        </div>
                    </a>

                </div>
                
                <?php else: ?>
                <!-- WIDOK DLA PRACOWNIKA (bez zmian układu, ale korzystający z nowych stylów) -->
                <div class="modules-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <a href="<?php echo url('hr.workers.profile', ['id' => $_SESSION['worker_id']]); ?>" class="modern-tile tile-hr">
                        <div class="tile-title">Mój profil</div>
                        <div class="tile-desc">Twoje dane, dokumenty i saldo rozliczeń z firmą.</div>
                    </a>
                    
                    <a href="<?php echo url('hr.worklog'); ?>" class="modern-tile">
                        <div class="tile-title">Moja praca</div>
                        <div class="tile-desc">Dziennik pracy i raportowanie przepracowanego czasu.</div>
                        <span class="tile-stat"><?php echo $stats['my_pending_logs']; ?> oczekujących logów</span>
                    </a>
                    
                    <a href="<?php echo url('finanse.wydatki'); ?>" class="modern-tile tile-finance">
                        <div class="tile-title">Moje wydatki służbowe</div>
                        <div class="tile-desc">Skanowanie paragonów i faktur płaconych gotówką firmową.</div>
                    </a>

                    <a href="<?php echo url('hr.workers.my-advances'); ?>#wallet-overview" class="modern-tile tile-warning">
                        <div class="tile-title">Mój portfel firmowy</div>
                        <div class="tile-desc">Podgląd przypisanych zaliczek i środków do rozliczenia.</div>
                        <span class="tile-stat"><?php echo formatMoney($stats['my_wallet_available'] ?? 0); ?> dostępne</span>
                    </a>
                </div>
                <?php endif; ?>

            </div><!-- /dashboard-content -->
        </div><!-- /dashboard-layout -->
    </div>
    
    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> BRYGAD ERP v<?php echo e(APP_VERSION); ?>. Panel Operacyjny.</p>
    </footer>
    
    <script>
        // --- LOGIKA ZWIJANIA PANELU (Z zapisem w LocalStorage) ---
        function updateBossTileCorners(isCollapsed) {
            const bossTile = document.querySelector('.tile-boss');
            const content = document.querySelector('.dashboard-content');
            if (bossTile) {
                bossTile.classList.toggle('sidebar-open', !isCollapsed);
                bossTile.classList.toggle('sidebar-closed', isCollapsed);
            }
            if (content) {
                content.style.paddingLeft = isCollapsed ? '0' : '20px';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const wrapper = document.getElementById('sidebarWrapper');
            if (wrapper) {
                const isCollapsed = localStorage.getItem('brygad_sidebar_collapsed') === 'true';
                if (isCollapsed) {
                    wrapper.classList.add('collapsed');
                }
                updateBossTileCorners(isCollapsed);
            }
        });

        function toggleSidebar() {
            const wrapper = document.getElementById('sidebarWrapper');
            if (wrapper) {
                wrapper.classList.toggle('collapsed');
                const isCollapsed = wrapper.classList.contains('collapsed');
                localStorage.setItem('brygad_sidebar_collapsed', isCollapsed);
                updateBossTileCorners(isCollapsed);
            }
        }

        // --- LOGIKA WYSZUKIWARKI NAWIGACJI ---
        (function() {
            const searchInput = document.getElementById('actionSearch');
            if (!searchInput) return;
            const sections = document.querySelectorAll('.sidebar-section');
            const links = document.querySelectorAll('.sidebar-actions a[data-keywords]');

            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase().trim();
                if (query === '') {
                    sections.forEach(s => { s.style.display = ''; if (s._autoOpened) { s.open = false; s._autoOpened = false; } });
                    links.forEach(l => l.style.display = '');
                    return;
                }
                let visibleSections = new Set();
                links.forEach(link => {
                    const keywords = link.getAttribute('data-keywords') || '';
                    const text = link.textContent.toLowerCase();
                    if (text.includes(query) || keywords.includes(query)) {
                        link.style.display = '';
                        const section = link.closest('.sidebar-section');
                        if (section) {
                            if (!section.open) { section.open = true; section._autoOpened = true; }
                            visibleSections.add(section);
                        }
                    } else {
                        link.style.display = 'none';
                    }
                });
                sections.forEach(s => { s.style.display = visibleSections.has(s) ? '' : 'none'; });
            });
        })();

        // ============================================
        // Web Push Notifications
        // ============================================
        let vapidPublicKey = null;

        async function initPushNotifications() {
            const pushStatusEl = document.getElementById('pushStatus');
            const pushTestEl = document.getElementById('pushTest');
            if (!pushStatusEl) return;
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                pushStatusEl.textContent = 'Brak wsparcia';
                return;
            }
            if (Notification.permission === 'denied') {
                pushStatusEl.innerHTML = 'Zablokowane';
                return;
            }
            try {
                const registration = await navigator.serviceWorker.register('/sw.js');
                const subscription = await registration.pushManager.getSubscription();
                if (subscription) {
                    pushStatusEl.innerHTML = '🔔 Włączone';
                    if (pushTestEl) pushTestEl.style.display = '';
                } else {
                    pushStatusEl.innerHTML = '🔔 Włącz powiadomienia';
                    if (pushTestEl) pushTestEl.style.display = 'none';
                }
            } catch (error) {
                pushStatusEl.innerHTML = '⚠️ Błąd';
            }
        }

        async function togglePushNotifications() {
            const pushStatusEl = document.getElementById('pushStatus');
            const pushTestEl = document.getElementById('pushTest');
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                if (subscription) {
                    await subscription.unsubscribe();
                    pushStatusEl.innerHTML = '🔔 Włącz powiadomienia';
                    if (pushTestEl) pushTestEl.style.display = 'none';
                } else {
                    if (Notification.permission === 'default') {
                        const permission = await Notification.requestPermission();
                        if (permission !== 'granted') { alert('Musisz zezwolić na powiadomienia w przeglądarce.'); return; }
                    }
                    if (!vapidPublicKey) {
                        const response = await fetch('/api/push/vapid-key.php');
                        const data = await response.json();
                        if (!data.success) throw new Error('Nie można pobrać klucza VAPID');
                        vapidPublicKey = data.publicKey;
                    }
                    const newSubscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                    });
                    const saveResponse = await fetch('/api/push/subscribe.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(newSubscription.toJSON())
                    });
                    const saveData = await saveResponse.json();
                    if (!saveData.success) throw new Error('Błąd zapisu subskrypcji');
                    pushStatusEl.innerHTML = '🔔 Włączone';
                    if (pushTestEl) pushTestEl.style.display = '';
                    if (/iPhone|iPad|iPod/.test(navigator.userAgent) && !window.navigator.standalone) {
                        alert('💡 Wskazówka dla iOS:\n\nAby powiadomienia działały na iPhonie, dodaj stronę do ekranu głównego:\n\n1. Kliknij ikonę "Udostępnij"\n2. Wybierz "Dodaj do ekranu początkowego"');
                    }
                }
            } catch (error) {
                alert('Błąd: ' + error.message);
            }
        }

        async function sendTestPush() {
            try {
                const response = await fetch('/api/push/send-test.php', { method: 'POST' });
                const data = await response.json();
                if (data.success) alert('Powiadomienie testowe wysłane!');
                else alert('Błąd: ' + (data.error || data.message));
            } catch (error) {
                alert('Błąd wysyłania: ' + error.message);
            }
        }

        function urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - base64String.length % 4) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
            return outputArray;
        }

        document.addEventListener('DOMContentLoaded', initPushNotifications);
    </script>
</body>
</html>
