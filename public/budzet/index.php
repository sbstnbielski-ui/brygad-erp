<?php
/**
 * BRYGAD ERP - Budżet Domowy - Dashboard (przekierowanie do transakcji)
 */

require_once __DIR__ . '/../config/autoload.php';
startSecureSession();
require_once __DIR__ . '/_guard.php';

// Przekieruj do transakcji z bieżącym miesiącem
$currentMonth = date('Y-m');
header("Location: " . url('budzet.transakcje', ['period' => $currentMonth]));
exit;
