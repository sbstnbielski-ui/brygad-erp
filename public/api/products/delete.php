<?php
/**
 * BRYGAD ERP - API: Usuń towar/usługę z katalogu (soft-delete → is_active=0)
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit;
}

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak id']);
    exit;
}

$pdo = getDbConnection();
$stmt = $pdo->prepare("UPDATE erp_products SET is_active = 0 WHERE id = ?");
$stmt->execute([$id]);

echo json_encode(['success' => true]);
