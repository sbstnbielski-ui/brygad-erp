<?php
/**
 * BRYGAD ERP - API: Wyślij fakturę emailem przez Fakturownia API
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/modules/fakturownia/FakturowniaService.php';

startSecureSession();
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit;
}

$fakturowniaId = (int)($_POST['fakturownia_id'] ?? $_GET['fakturownia_id'] ?? 0);
if ($fakturowniaId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak fakturownia_id']);
    exit;
}

$emailTo = trim((string)($_POST['email_to'] ?? $_GET['email_to'] ?? ''));
if ($emailTo !== '' && !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowy adres e-mail']);
    exit;
}

$pdo = getDbConnection();
$service = new FakturowniaService($pdo);
$result = $service->sendInvoiceByEmail($fakturowniaId, $emailTo ?: null);

if ($result['success'] && $emailTo) {
    try {
        $stmtClient = $pdo->prepare("
            SELECT inv.client_id, i.email AS current_email
            FROM fakturownia_invoices fi
            JOIN invoices_sale inv ON fi.erp_invoice_sale_id = inv.id
            JOIN investors i ON i.id = inv.client_id
            WHERE fi.fakturownia_id = ?
            LIMIT 1
        ");
        $stmtClient->execute([$fakturowniaId]);
        $clientRow = $stmtClient->fetch(PDO::FETCH_ASSOC);
        if ($clientRow && empty($clientRow['current_email'])) {
            $stmtUpdate = $pdo->prepare("UPDATE investors SET email = ? WHERE id = ?");
            $stmtUpdate->execute([$emailTo, $clientRow['client_id']]);
        }
    } catch (Throwable $e) {}
}

if ($result['success']) {
    $logMsg = "Wysłano fakturę emailem (Fakturownia ID: {$fakturowniaId}";
    if ($emailTo) $logMsg .= ", do: {$emailTo}";
    logEvent($logMsg . ")", 'INFO');
    echo json_encode(['success' => true, 'message' => 'Faktura została wysłana emailem' . ($emailTo ? ' na adres: ' . $emailTo : '') . '.']);
} else {
    $error = $result['error'] ?? 'Nieznany błąd';
    logEvent("Błąd wysyłki emaila (Fakturownia ID: {$fakturowniaId}): {$error}", 'ERROR');
    echo json_encode(['success' => false, 'error' => $error]);
}
