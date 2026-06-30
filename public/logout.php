<?php
/**
 * BRYGAD ERP v3.0 - Wylogowanie
 */

require_once __DIR__ . '/config/autoload.php'; // ROOT domeny
startSecureSession();

if (isLoggedIn()) {
    $login = $_SESSION['login'] ?? 'unknown';
    logEvent("Użytkownik wylogowany: " . $login);
}

$_SESSION = array();

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}

session_destroy();
redirect('login.php');
?>
