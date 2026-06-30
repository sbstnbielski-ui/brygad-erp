<?php
/**
 * BRYGAD ERP - Autoloader (LH-compatible)
 * 
 * WAŻNE: Ten plik zakłada, że config/ jest w ROOT domeny.
 * Nie wychodzi poza domenę (kompatybilne z open_basedir na LH).
 */

// Zapobiegnij wielokrotnemu ładowaniu
if (defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    return;
}

// Prosty loader - zakładamy że autoload.php jest w /config/
// więc bootstrap.php jest TUTAJ OBOK
require_once __DIR__ . '/bootstrap.php';
