<?php
/**
 * BRYGAD ERP v3.0 - Strona Startowa
 */

require_once __DIR__ . '/config/autoload.php'; // ROOT domeny
startSecureSession();

if (isLoggedIn()) {
    redirect('dashboard.php');
} else {
    redirect('login.php');
}
?>
