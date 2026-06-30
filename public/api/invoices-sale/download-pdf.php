<?php
/**
 * BRYGAD ERP - API: Pobierz PDF faktury z Fakturowni (z kodem QR KSeF)
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/modules/fakturownia/FakturowniaService.php';

startSecureSession();
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo 'Brak uprawnień';
    exit;
}

$fakturowniaId = (int)($_GET['fakturownia_id'] ?? 0);
if ($fakturowniaId <= 0) {
    http_response_code(400);
    echo 'Brak fakturownia_id';
    exit;
}

$pdo = getDbConnection();
$service = new FakturowniaService($pdo);
$result = $service->downloadInvoicePdf($fakturowniaId);

if ($result['success'] && !empty($result['data'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="faktura_' . $fakturowniaId . '.pdf"');
    header('Content-Length: ' . strlen($result['data']));
    echo $result['data'];
} else {
    http_response_code(500);
    echo 'Błąd pobierania PDF: ' . ($result['error'] ?? 'nieznany');
}
