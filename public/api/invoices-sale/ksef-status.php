<?php
/**
 * BRYGAD ERP - API: Sprawdź/odśwież status KSeF faktury z Fakturowni
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/modules/fakturownia/FakturowniaService.php';

header('Content-Type: application/json; charset=utf-8');

startSecureSession();
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit;
}

$fakturowniaId = (int)($_GET['fakturownia_id'] ?? 0);
if ($fakturowniaId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Brak fakturownia_id']);
    exit;
}

$pdo = getDbConnection();
$service = new FakturowniaService($pdo);
$result = $service->getKsefStatus($fakturowniaId);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
