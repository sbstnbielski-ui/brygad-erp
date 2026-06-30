<?php
/**
 * BRYGAD ERP - Przykładowa konfiguracja bazy danych
 *
 * Skopiuj ten plik jako database.php i uzupełnij wartości lokalne.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'brygad_erp');
define('DB_USER', 'brygad');
define('DB_PASS', 'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

define('SESSION_SECRET', 'wygeneruj-losowy-ciag-min-32-znaki');
define('SESSION_LIFETIME', 3600 * 8);
define('TIMEZONE', 'Europe/Warsaw');

if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', PUBLIC_PATH . '/uploads');
}
if (!defined('RECEIPTS_PATH')) {
    define('RECEIPTS_PATH', UPLOADS_PATH . '/receipts');
}
if (!defined('INVOICE_SCANS_PATH')) {
    define('INVOICE_SCANS_PATH', UPLOADS_PATH . '/invoices');
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', PUBLIC_PATH . '/storage');
}
if (!defined('LOGS_PATH')) {
    define('LOGS_PATH', STORAGE_PATH . '/logs');
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'BRYGAD ERP');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0');
}

if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', LOGS_PATH . '/error.log');
}

date_default_timezone_set(TIMEZONE);

function getDbConnection() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true,
            ];

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                die('Błąd połączenia z bazą danych: ' . $e->getMessage());
            }

            error_log('Database connection error: ' . $e->getMessage());
            die('Wystąpił błąd systemu. Skontaktuj się z administratorem.');
        }
    }

    return $pdo;
}

function startSecureSession() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_secure', 0);
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);

        session_name('BRYGAD_SESSION');
        session_start();

        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['login']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        redirect('dashboard.php');
    }
}

function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function formatDate($date) {
    if (empty($date)) {
        return '';
    }

    return date('d.m.Y', strtotime($date));
}

function formatMoney($amount) {
    return number_format($amount, 2, ',', ' ') . ' zł';
}

function formatFileSize($bytes) {
    if ($bytes === null || $bytes === 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log(1024));

    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

function logEvent($message, $level = 'INFO') {
    if (!is_dir(LOGS_PATH)) {
        @mkdir(LOGS_PATH, 0755, true);
    }

    $logFile = LOGS_PATH . '/app.log';
    $timestamp = date('Y-m-d H:i:s');
    $userId = $_SESSION['user_id'] ?? 'GUEST';
    $login = $_SESSION['login'] ?? 'GUEST';

    $logMessage = '[' . $timestamp . '] [' . $level . '] [User: ' . $login . ' (' . $userId . ')] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $logMessage, FILE_APPEND);
}
