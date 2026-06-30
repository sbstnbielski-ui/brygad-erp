<?php
/**
 * BRYGAD ERP - Centralna mapa tras
 * 
 * JEDYNE ŹRÓDŁO PRAWDY dla wszystkich linków w systemie.
 * Każda akcja (dodaj, edytuj, lista, anuluj) korzysta z tej mapy.
 * 
 * Użycie:
 *   url('dashboard')             // /dashboard.php
 *   url('hr.workers')            // /hr/workers/index.php
 *   url('hr.workers.create')     // /hr/workers/create.php
 *   url('hr.workers.edit', ['id' => 5])  // /hr/workers/edit.php?id=5
 *   back('hr.workers')           // href dla przycisku Anuluj/Wróć
 */

if (!defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    die('Direct access not allowed');
}

// =====================================================
// CENTRALNA MAPA TRAS
// =====================================================

define('ROUTES', [
    // -------------------------
    // GŁÓWNE
    // -------------------------
    'dashboard'         => '/dashboard.php',
    'login'             => '/login.php',
    'logout'            => '/logout.php',
    'index'             => '/index.php',
    
    // -------------------------
    // HR - Moduł HR
    // -------------------------
    'hr'                    => '/hr/index.php',
    'hr.index'              => '/hr/index.php',
    
    // HR - Pracownicy
    'hr.workers'            => '/hr/index.php',
    'hr.workers.index'      => '/hr/index.php',
    'hr.workers.create'     => '/hr/workers/create.php',
    'hr.workers.edit'       => '/hr/workers/edit.php',
    'hr.workers.profile'    => '/hr/workers/profile.php',
    'hr.workers.rates'      => '/hr/workers/rates.php',
    'hr.workers.bulk_rates' => '/hr/workers/bulk_rates_form.php',
    'hr.workers.rates_table' => '/hr/workers/rates_table.php',
    'hr.workers.balance'    => '/hr/workers/balance.php',
    'hr.workers.report'     => '/hr/workers/report.php',
    'hr.workers.documents'  => '/hr/workers/documents.php',
    'hr.workers.my-advances' => '/hr/workers/my-advances.php',
    'hr.workers.create-account' => '/hr/workers/create-account.php',
    'hr.workers.wallet'     => '/hr/workers/wallet.php',
    'hr.workers.wallet-ledger-edit' => '/hr/workers/wallet-ledger-edit.php',
    'hr.workers.wallet-ledger-delete' => '/hr/workers/wallet-ledger-delete.php',
    
    // HR - Centrum rozliczeń
    'hr.settlements'        => '/hr/settlements/index.php',
    'hr.settlements.index'  => '/hr/settlements/index.php',
    'hr.settlements.edit'   => '/hr/settlements/edit.php',
    'hr.settlements.delete' => '/hr/settlements/delete.php',

    // HR - Dziennik pracy
    'hr.worklog'            => '/hr/worklog/index.php',
    'hr.worklog.index'      => '/hr/worklog/index.php',
    'hr.worklog.create'     => '/hr/worklog/create.php',
    'hr.worklog.edit'       => '/hr/worklog/edit.php',
    'hr.worklog.approve'        => '/hr/worklog/approve.php',
    'hr.worklog.approve_batch'  => '/hr/worklog/approve_batch.php',
    'hr.worklog.delete'     => '/hr/worklog/delete.php',
    
    // HR - Alerty
    'hr.alerts'             => '/hr/alerts/index.php',
    'hr.alerts.index'       => '/hr/alerts/index.php',
    'hr.alerts.acknowledge' => '/hr/alerts/acknowledge-alert.php',
    'hr.alerts.close'       => '/hr/alerts/close-alert.php',
    
    // HR - Dokumenty pracowników
    'dodaj-dokument'        => '/dodaj-dokument.php',
    'edytuj-dokument'       => '/edytuj-dokument.php',
    'archiwizuj-dokument'   => '/archiwizuj-dokument.php',
    'usun-plik-dokumentu'   => '/usun-plik-dokumentu.php',
    
    // -------------------------
    // FINANSE - Moduł finansów
    // -------------------------
    'finanse'                   => '/finanse/index.php',
    'finanse.index'             => '/finanse/index.php',
    
    // Finanse - Wydatki
    'finanse.wydatki'           => '/finanse/wydatki/index.php',
    'finanse.wydatki.index'     => '/finanse/wydatki/index.php',
    'finanse.wydatki.create'    => '/finanse/wydatki/create.php',
    'finanse.wydatki.edit'      => '/finanse/wydatki/edit.php',
    'finanse.wydatki.delete'    => '/finanse/wydatki/delete.php',
    'finanse.wydatki.approve'   => '/finanse/wydatki/approve.php',
    'finanse.wydatki.create-document' => '/finanse/wydatki/create-document.php',
    'finanse.koszty-projektowe.create' => '/finanse/koszty-projektowe/create.php',
    
    // Finanse - Rozliczenia
    'finanse.rozliczenia'           => '/finanse/rozliczenia/index.php',
    'finanse.rozliczenia.index'     => '/finanse/rozliczenia/index.php',
    'finanse.rozliczenia.create'    => '/finanse/rozliczenia/create.php',
    'finanse.rozliczenia.edit'      => '/finanse/rozliczenia/edit.php',
    'finanse.rozliczenia.delete'    => '/finanse/rozliczenia/delete.php',
    
    // Finanse - Zaliczki pracownicze
    'finanse.zaliczki'              => '/finanse/zaliczki/index.php',
    'finanse.zaliczki.index'        => '/finanse/zaliczki/index.php',
    'finanse.zaliczki.create'       => '/finanse/zaliczki/create.php',
    'finanse.zaliczki.transfer'     => '/finanse/zaliczki/transfer.php',
    'finanse.zaliczki.view'         => '/finanse/zaliczki/view.php',
    'finanse.zaliczki.edit'         => '/finanse/zaliczki/edit.php',
    'finanse.zaliczki.close'        => '/finanse/zaliczki/close.php',
    'finanse.zaliczki.mark_paid'    => '/finanse/zaliczki/mark-paid.php',
    'finanse.zaliczki.delete'       => '/finanse/zaliczki/delete.php',
    
    // Finanse - Przychody (umowy/aneksy do projektów)
    'finanse.przychody.create'      => '/finanse/przychody/create.php',
    
    // Finanse - Faktury sprzedażowe
    'finanse.faktury-sprzedazowe'            => '/finanse/faktury-sprzedazowe/index.php',
    'finanse.faktury-sprzedazowe.index'      => '/finanse/faktury-sprzedazowe/index.php',
    'finanse.faktury-sprzedazowe.create'     => '/finanse/faktury-sprzedazowe/create.php',
    'finanse.faktury-sprzedazowe.create-jst' => '/finanse/faktury-sprzedazowe/create_jst.php',
    'finanse.faktury-sprzedazowe.edit'       => '/finanse/faktury-sprzedazowe/edit.php',
    'finanse.faktury-sprzedazowe.delete'     => '/finanse/faktury-sprzedazowe/usun-fakture.php',
    'finanse.faktury-sprzedazowe.usun'       => '/finanse/faktury-sprzedazowe/usun-fakture.php',
    
    // Finanse - Przychody pozafakturowe (PZF)
    'finanse.sprzedaz-pozafakturowa'            => '/finanse/sprzedaz-pozafakturowa/create.php',
    'finanse.sprzedaz-pozafakturowa.create'     => '/finanse/sprzedaz-pozafakturowa/create.php',
    'finanse.sprzedaz-pozafakturowa.edit'       => '/finanse/sprzedaz-pozafakturowa/edit.php',
    'finanse.sprzedaz-pozafakturowa.delete'     => '/finanse/sprzedaz-pozafakturowa/delete.php',
    'finanse.sprzedaz-pozafakturowa.allocations' => '/finanse/sprzedaz-pozafakturowa/allocations.php',

    // Finanse - Katalog towarów/usług
    'finanse.towary'                 => '/finanse/towary/index.php',
    'finanse.towary.index'           => '/finanse/towary/index.php',

    // Finanse - Finance Items (paragony, koszty stałe)
    'finanse.items'                 => '/finanse/items/index.php',
    'finanse.items.create'          => '/finanse/items/create.php',
    'finanse.items.view'            => '/finanse/items/view.php',
    'finanse.receipt.create'        => '/finanse/items/create-receipt.php',
    'finanse.fixed_cost.create'     => '/finanse/items/create-fixed-cost.php',
    
    // Finanse - Faktury
    'finanse.faktury'           => '/finanse/faktury.php',
    'finanse.faktury.index'     => '/finanse/faktury.php',
    'finanse.faktury.create'    => '/finanse/dodaj-fakture.php',
    'finanse.faktury.edit'      => '/finanse/edytuj-fakture.php',
    'finanse.faktury.allocate'  => '/finanse/alokuj-fakture.php',
    'finanse.faktury.delete'    => '/finanse/usun-fakture.php',
    
    // Finanse - Koszty stale
    'finanse.koszty-stale'          => '/finanse/koszty-stale/index.php',
    'finanse.koszty-stale.index'    => '/finanse/koszty-stale/index.php',
    'finanse.koszty-stale.create'   => '/finanse/wydatki/create.php',
    'finanse.koszty-stale.edit'     => '/finanse/koszty-stale/edit.php',
    'finanse.koszty-stale.delete'   => '/finanse/koszty-stale/delete.php',
    
    // Finanse - Koszty pracownicze
    'finanse.koszty-pracownicze'        => '/finanse/koszty-pracownicze/index.php',
    'finanse.koszty-pracownicze.index'  => '/finanse/koszty-pracownicze/index.php',
    
    // Finanse - Wszystkie koszty (widok zbiorczy)
    'finanse.koszty-wszystkie'          => '/finanse/koszty-wszystkie/index.php',
    'finanse.koszty-wszystkie.index'    => '/finanse/koszty-wszystkie/index.php',
    
    // Finanse - Controlling (Przegląd okresu)
    'finanse.overview'          => '/finanse/overview.php',
    'finanse.export'            => '/finanse/export.php',
    
    // Finanse - Finanse właściciela
    'finanse.wlasciciel'                => '/finanse/wlasciciel.php',

    // Finanse - Inbox faktur kosztowych z Fakturowni
    'finanse.fakturownia-cost-inbox'        => '/finanse/fakturownia-cost-inbox.php',
    'finanse.fakturownia-cost-inbox.index'  => '/finanse/fakturownia-cost-inbox.php',
    'finanse.fakturownia-cost-inbox.view'   => '/finanse/fakturownia-cost-inbox-view.php',
    'finanse.fakturownia-cost-inbox.document-view' => '/finanse/fakturownia-cost-inbox-document-view.php',
    'finanse.fakturownia-archive'           => '/finanse/fakturownia-archive.php',
    'finanse.fakturownia-archive.index'     => '/finanse/fakturownia-archive.php',
    'finanse.fakturownia-archive.download'  => '/finanse/fakturownia-archive-download.php',
    'finanse.fakturownia-logs'              => '/finanse/fakturownia-logs.php',
    'finanse.fakturownia-logs.index'        => '/finanse/fakturownia-logs.php',
    'finanse.fakturownia-reconciliation'    => '/finanse/fakturownia-reconciliation.php',
    'finanse.fakturownia-reconciliation.index' => '/finanse/fakturownia-reconciliation.php',
    'finanse.fakturownia-reconcile-sales'    => '/finanse/tools/reconcile-fakturownia.php',
    'finanse.fakturownia-settings'          => '/finanse/fakturownia-settings.php',
    'finanse.fakturownia-settings.index'    => '/finanse/fakturownia-settings.php',
    'finanse.company-settings'              => '/finanse/company-settings.php',
    'finanse.company-settings.index'        => '/finanse/company-settings.php',
    'finanse.system-map'                    => '/finanse/system-map.php',
    
    // -------------------------
    // PROJEKTY - Moduł projektów
    // -------------------------
    'projekty'                  => '/projekty/index.php',
    'projekty.index'            => '/projekty/index.php',
    'projekty.create'           => '/projekty/create.php',
    'projekty.edit'             => '/projekty/edit.php',
    'projekty.view'             => '/projekty/view.php',
    'projekty.delete'           => '/projekty/delete.php',
    'projekty.history'          => '/projekty/history.php',
    'projekty.archive'          => '/api/projekty/archive.php',
    'projekty.raporty'          => '/projekty/raporty-kosztow.php',
    'projekty.etapy'            => '/projekty/etapy-kosztow.php',
    'projekty.etapy.create'     => '/projekty/dodaj-etap.php',
    'projekty.etapy.edit'       => '/projekty/edytuj-etap.php',
    'projekty.etap.view'        => '/projekty/etap-view.php',
    
    // -------------------------
    // INWESTORZY - Moduł inwestorów
    // -------------------------
    'investors'                 => '/investors/index.php',
    'investors.index'           => '/investors/index.php',
    'investors.create'          => '/investors/create.php',
    'investors.edit'            => '/investors/edit.php',
    'investors.delete'          => '/investors/delete.php',
    'investors.show'            => '/investors/show.php',
    'investors.supplier-show'   => '/investors/supplier-show.php',
    'investors.notes'           => '/investors/notes-action.php',
    'investors.reminders'       => '/investors/reminders-action.php',
    
    // -------------------------
    // DOKUMENTY - Moduł dokumentów
    // -------------------------
    'dokumenty'                 => '/dokumenty/index.php',
    'dokumenty.index'           => '/dokumenty/index.php',
    'dokumenty.create'          => '/dokumenty/create.php',
    'dokumenty.edit'            => '/dokumenty/edit.php',
    'dokumenty.archive'         => '/dokumenty/archive.php',
    'dokumenty.delete'          => '/dokumenty/usun-dokument.php',
    
    // -------------------------
    // ZADANIA - Moduł zadań
    // -------------------------
    'zadania'                   => '/zadania/index.php',
    'zadania.index'             => '/zadania/index.php',
    'zadania.create'            => '/zadania/create.php',
    'zadania.edit'              => '/zadania/edit.php',
    'zadania.show'              => '/zadania/show.php',
    'zadania.admin'             => '/zadania/admin.php',
    'zadania.archive'           => '/zadania/archive.php',
    
    // -------------------------
    // BUDŻET DOMOWY - Moduł budżetu domowego
    // -------------------------
    'budzet'                    => '/budzet/index.php',
    'budzet.index'              => '/budzet/index.php',
    'budzet.rachunki'           => '/budzet/rachunki.php',
    'budzet.rachunki.definicje' => '/budzet/rachunki-definicje.php',
    'budzet.rachunki.dodaj'     => '/budzet/dodaj-rachunek.php',
    'budzet.rachunki.edytuj'    => '/budzet/edytuj-rachunek.php',
    'budzet.rachunki.ustaw_kwote' => '/budzet/ustaw-kwote-rachunku.php',
    'budzet.oplac'              => '/budzet/oplac-rachunek.php',
    'budzet.transakcje'         => '/budzet/transakcje.php',
    'budzet.transakcje.create'  => '/budzet/dodaj-transakcje.php',
    'budzet.transakcje.edit'    => '/budzet/edytuj-transakcje.php',
    'budzet.transakcje.delete'  => '/budzet/usun-transakcje.php',
    'budzet.budzety'            => '/budzet/budzety.php',
    'budzet.konta'              => '/budzet/konta.php',
    'budzet.kategorie'          => '/budzet/kategorie.php',
    'budzet.domownicy'          => '/budzet/domownicy.php',
    
    // -------------------------
    // ASSETS - Moduł Maszyny i Auta
    // -------------------------
    'assets'                    => '/assets/index.php',
    'assets.index'              => '/assets/index.php',
    'assets.create'             => '/assets/create.php',
    'assets.edit'               => '/assets/edit.php',
    'assets.view'               => '/assets/view.php',
    'assets.calendar'           => '/assets/calendar.php',
    
    // -------------------------
    // LEGACY ALIASY (dla kompatybilności)
    // -------------------------
    'workers'           => '/hr/index.php',
    'dziennik-pracy'    => '/hr/worklog/index.php',
    'wydatki'           => '/finanse/wydatki/index.php',
    'rozliczenia'       => '/finanse/rozliczenia/index.php',
    'faktury'           => '/finanse/faktury.php',
    'raporty-kosztow'   => '/projekty/raporty-kosztow.php',
    
    // Wewnętrzne linki modułów
    'projekty.view'             => '/projekty/view.php',
    'hr.workers.create'         => '/hr/workers/create.php',
    'hr.workers.edit'           => '/hr/workers/edit.php',
    
    // Worklog approve
    'hr.worklog.approve'        => '/hr/worklog/approve.php',
    
    // Faktury dodatkowe trasy
    'finanse.faktury.edit'      => '/finanse/edytuj-fakture.php',
    'finanse.faktury.allocate'  => '/finanse/alokuj-fakture.php',
    
    // Projekty etapy
    'projekty.etapy'            => '/projekty/etapy-kosztow.php',
    'projekty.etapy.create'     => '/projekty/dodaj-etap.php',
    'projekty.etapy.edit'       => '/projekty/edytuj-etap.php',
    
    // Dokumenty pracownika
    'dokumenty.worker'          => '/hr/workers/documents.php',
    
    // -------------------------
    // API - Endpointy API
    // -------------------------
    'api.push.key'              => '/api/push/vapid-key.php',
    'api.push.subscribe'        => '/api/push/subscribe.php',
    'api.push.unsubscribe'      => '/api/push/unsubscribe.php',
    'api.finanse.wlasciciel-dane' => '/api/finanse/wlasciciel-dane.php',
    'api.document-items.allocate' => '/api/document-items/allocate.php',
    'api.document-items.delete-allocation' => '/api/document-items/delete-allocation.php',
]);

// =====================================================
// MAPOWANIE MODUŁÓW DO BAZOWYCH TRAS (dla przycisku Wróć)
// =====================================================

define('MODULE_BASE_ROUTES', [
    'hr'            => 'hr',
    'hr.workers'    => 'hr.workers',
    'hr.worklog'    => 'hr.worklog',
    'hr.alerts'     => 'hr.alerts',
    'finanse'       => 'finanse',
    'finanse.wydatki'       => 'finanse.wydatki',
    'finanse.rozliczenia'   => 'finanse.rozliczenia',
    'finanse.faktury'       => 'finanse.faktury',
    'projekty'      => 'projekty',
    'projekty.etapy'=> 'projekty',
    'investors'     => 'investors',
    'dokumenty'     => 'dokumenty',
    'zadania'       => 'zadania',
    'budzet'        => 'budzet',
    'assets'        => 'assets',
]);

// =====================================================
// FUNKCJE POMOCNICZE
// =====================================================

/**
 * Generuje URL na podstawie nazwy trasy
 * 
 * @param string $routeName Nazwa trasy (np. 'hr.workers.edit')
 * @param array $params Parametry GET (np. ['id' => 5])
 * @return string Pełny URL
 */
function url(string $routeName, array $params = []): string {
    $routes = ROUTES;
    
    if (!isset($routes[$routeName])) {
        // Jeśli ścieżka zawiera kropkę z rozszerzeniem (np. .css, .js, .png), zwróć ją jako bezpośredni zasób
        if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|webp|ico|map|woff|woff2|ttf)$/i', $routeName)) {
            return '/' . ltrim($routeName, '/');
        }
        // Fallback: zwróć jako ścieżkę do pliku PHP
        error_log("ROUTES: Unknown route '$routeName'");
        return '/' . str_replace('.', '/', $routeName) . '.php';
    }
    
    $path = $routes[$routeName];
    
    // Dodaj parametry GET
    if (!empty($params)) {
        $path .= '?' . http_build_query($params);
    }
    
    return $path;
}

/**
 * Generuje URL dla przycisku "Wróć" / "Anuluj"
 * Wraca do listy bazowej modułu
 * 
 * @param string $currentModule Aktualny moduł (np. 'hr.workers')
 * @param string|null $fallback Fallback jeśli nie znaleziono
 * @return string URL do powrotu
 */
function back(string $currentModule, ?string $fallback = null): string {
    $baseRoutes = MODULE_BASE_ROUTES;
    
    // Szukaj najbliższego dopasowania
    $parts = explode('.', $currentModule);
    while (count($parts) > 0) {
        $key = implode('.', $parts);
        if (isset($baseRoutes[$key])) {
            return url($baseRoutes[$key]);
        }
        array_pop($parts);
    }
    
    // Fallback
    if ($fallback) {
        return url($fallback);
    }
    
    return url('dashboard');
}

/**
 * Generuje link HTML
 * 
 * @param string $routeName Nazwa trasy
 * @param string $label Tekst linku
 * @param array $params Parametry GET
 * @param array $attrs Atrybuty HTML (class, style, etc.)
 * @return string HTML link
 */
function link_to(string $routeName, string $label, array $params = [], array $attrs = []): string {
    $href = url($routeName, $params);
    
    $attrString = '';
    foreach ($attrs as $key => $value) {
        $attrString .= ' ' . e($key) . '="' . e($value) . '"';
    }
    
    return '<a href="' . e($href) . '"' . $attrString . '>' . $label . '</a>';
}

/**
 * Generuje przycisk jako link
 * 
 * @param string $routeName Nazwa trasy
 * @param string $label Tekst przycisku
 * @param string $class Klasy CSS
 * @param array $params Parametry GET
 * @return string HTML
 */
function btn(string $routeName, string $label, string $class = 'btn btn-primary', array $params = []): string {
    return link_to($routeName, $label, $params, ['class' => $class]);
}

/**
 * Generuje przycisk "Anuluj" / "Wróć"
 * 
 * @param string $currentModule Aktualny moduł
 * @param string $label Tekst przycisku
 * @param string $class Klasy CSS
 * @return string HTML
 */
function btn_back(string $currentModule, string $label = 'Anuluj', string $class = 'btn btn-secondary'): string {
    $href = back($currentModule);
    return '<a href="' . e($href) . '" class="' . e($class) . '">' . e($label) . '</a>';
}

/**
 * Sprawdza czy dana trasa istnieje
 */
function route_exists(string $routeName): bool {
    return isset(ROUTES[$routeName]);
}

/**
 * Generuje ścieżkę do assetu (obrazki, CSS, JS)
 * Zawsze zwraca ścieżkę od roota domeny
 */
function asset(string $path): string {
    return '/assets/' . ltrim($path, '/');
}

/**
 * Breadcrumbs builder pomocniczy
 */
function breadcrumbs(array $items): array {
    $result = [];
    foreach ($items as $routeName => $label) {
        if (is_int($routeName)) {
            // Ostatni element (bez linku)
            $result[] = ['label' => $label];
        } else {
            $result[] = ['url' => url($routeName), 'label' => $label];
        }
    }
    return $result;
}
