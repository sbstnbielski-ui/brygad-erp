<?php
/**
 * BRYGAD ERP — List state helpers
 *
 * Trzy funkcje pomocnicze do zachowywania stanu list (filtry, sort, strona)
 * przy nawigacji lista → detal → powrót oraz przy akcjach POST na liście.
 *
 * Pattern użycia:
 *   1) Na stronie-liście (po requireLogin):
 *          rememberListUrl('cost-inbox');
 *   2) W POST-handlerach na liście, zamiast twardego URL-a:
 *          header('Location: ' . listSelfRedirectUrl(['success' => 'status_updated']));
 *   3) Na stronach detalu — linki "Wróć do listy" i failure-redirecty:
 *          <a href="<?= returnToListUrl('cost-inbox', url('finanse.fakturownia-cost-inbox')) ?>">← Wróć</a>
 *
 * Uwaga: funkcje są no-op gdy sesja nie jest aktywna — bezpieczne dla publicznych
 * stron bez logowania (ale ERP i tak wymaga zalogowania przed renderem listy).
 */

if (!function_exists('rememberListUrl')) {
    /**
     * Zapamiętuje bieżący URL listy (ścieżka + query string) w sesji pod kluczem.
     */
    function rememberListUrl(string $key): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        // Pomijamy POST-y — zapamiętujemy tylko GET-owe wejścia na listę.
        // Dzięki temu stan "ostatnio widzianej listy" nie nadpisze się po akcji POST,
        // która z reguły trafia na ten sam URL ale z query stringiem $_GET (działaniem obok).
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        $qs   = $_SERVER['QUERY_STRING'] ?? '';
        $base = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        if ($base === false || $base === '') {
            return;
        }
        if (!isset($_SESSION['list_state']) || !is_array($_SESSION['list_state'])) {
            $_SESSION['list_state'] = [];
        }
        $_SESSION['list_state'][$key] = $base . ($qs !== '' ? '?' . $qs : '');
    }
}

if (!function_exists('returnToListUrl')) {
    /**
     * Zwraca zapamiętany URL listy albo fallback.
     * Fallback to najczęściej goły url('finanse.xxx') — żeby było gdzie wrócić
     * nawet gdy sesja wygasła albo to pierwsza wizyta.
     */
    function returnToListUrl(string $key, string $fallback): string
    {
        if (session_status() === PHP_SESSION_ACTIVE
            && !empty($_SESSION['list_state'][$key])
            && is_string($_SESSION['list_state'][$key])
        ) {
            return $_SESSION['list_state'][$key];
        }
        return $fallback;
    }
}

if (!function_exists('listSelfRedirectUrl')) {
    /**
     * Buduje URL na BIEŻĄCĄ stronę listy z zachowanymi filtrami z $_GET
     * oraz nakładką na parametry (np. ['success' => 'status_updated']).
     *
     * Użycie wewnątrz POST-handlera na liście, gdzie formularz ma action="" —
     * wtedy $_GET zawiera filtry z URL-a na który POSTowano.
     *
     * Nakładanie: przekaż wartość `null` żeby usunąć parametr, inaczej zostanie nadpisany.
     */
    function listSelfRedirectUrl(array $overlay = []): string
    {
        $base   = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        if ($base === false || $base === '') {
            $base = '/';
        }
        $params = is_array($_GET ?? null) ? $_GET : [];
        // Nie przenosimy CSRF tokenu do query stringa, nawet gdyby ktoś go tam wstawił.
        unset($params['_csrf_token']);
        foreach ($overlay as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }
        return $params ? $base . '?' . http_build_query($params) : $base;
    }
}

if (!function_exists('returnToListUrlWithParams')) {
    /**
     * Wariant returnToListUrl który nakłada dodatkowe parametry
     * (np. `?error=not_found`) na zapamiętany URL — bezpiecznie
     * niezależnie od tego czy URL już miał query string, czy nie.
     */
    function returnToListUrlWithParams(string $key, string $fallback, array $extra = []): string
    {
        $url = returnToListUrl($key, $fallback);
        if (empty($extra)) {
            return $url;
        }
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $sep . http_build_query($extra);
    }
}
