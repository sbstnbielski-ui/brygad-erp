<?php
/**
 * BRYGAD ERP - Bootstrap (LH-compatible)
 * 
 * Ten plik jest ładowany przez autoload.php.
 * Definiuje ROOT_PATH i ładuje database.php.
 */

// Zapobiegnij wielokrotnemu ładowaniu
if (defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    return;
}
define('SPRUTEX_BOOTSTRAP_LOADED', true);

// =====================================================
// ŚCIEŻKI SYSTEMU
// =====================================================

// ROOT_PATH = katalog główny domeny (np. /public_html/)
// __DIR__ to /public_html/config, więc dirname(__DIR__) to /example.com
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

// Dla kompatybilności wstecznej
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', ROOT_PATH);
}

// =====================================================
// ZAŁADUJ KONFIGURACJĘ BAZY I FUNKCJE
// =====================================================

require_once ROOT_PATH . '/config/database.php';

// =====================================================
// CENTRALNA MAPA TRAS
// =====================================================

require_once ROOT_PATH . '/includes/routes.php';

// =====================================================
// HELPERY STANU LIST (filtry, sort, strona)
// =====================================================

require_once ROOT_PATH . '/includes/list_state.php';

// =====================================================
// GLOBALNE FUNKCJE POMOCNICZE
// =====================================================

/**
 * Bezpieczne echo (escape HTML)
 */
if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Debug dump (tylko w trybie DEBUG)
 */
if (!function_exists('dd')) {
    function dd(...$vars) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            echo '<pre>';
            foreach ($vars as $var) {
                var_dump($var);
            }
            echo '</pre>';
            die();
        }
    }
}

// =====================================================
// OCHRONA CSRF — minimalny token sesyjny
// =====================================================

if (!function_exists('csrfToken')) {
    /**
     * Zwraca aktualny token CSRF (generuje nowy jeśli brak w sesji).
     */
    function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrfField')) {
    /**
     * Zwraca gotowy hidden input z tokenem CSRF.
     */
    function csrfField(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . e(csrfToken()) . '">';
    }
}

if (!function_exists('csrfVerify')) {
    /**
     * Weryfikuje token CSRF z POST. Zwraca true jeśli poprawny.
     */
    function csrfVerify(): bool
    {
        $submitted = $_POST['_csrf_token'] ?? '';
        $stored    = $_SESSION['_csrf_token'] ?? '';
        if ($submitted === '' || $stored === '') {
            return false;
        }
        return hash_equals($stored, $submitted);
    }
}

// =====================================================
// BOOTSTRAP ZAŁADOWANY POMYŚLNIE
// =====================================================

// Opcjonalnie: log do debugowania (tylko w DEBUG_MODE)
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    error_log("Bootstrap loaded successfully. ROOT_PATH: " . ROOT_PATH);
}
