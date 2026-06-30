<?php
/**
 * Backward-compat redirect:
 * stary adres HTML alokacji w /api przekierowuje do właściwego ekranu w /finanse.
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

$entryId = (int)($_GET['entry_id'] ?? 0);
$source = (string)($_GET['source'] ?? 'noninvoice');
if (!in_array($source, ['invoice', 'noninvoice', 'all'], true)) {
    $source = 'noninvoice';
}

header('Location: ' . url('finanse.sprzedaz-pozafakturowa.allocations', [
    'entry_id' => $entryId,
    'source' => $source,
]));
exit;
