<?php
/**
 * BRYGAD ERP - API: Lista towarów/usług z katalogu ERP
 * GET ?q=szukaj&limit=50&include_inactive=1
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit;
}

$pdo = getDbConnection();
$q = trim($_GET['q'] ?? '');
$limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$includeInactive = ($_GET['include_inactive'] ?? '') === '1';

$where = $includeInactive ? '1=1' : 'is_active = 1';
$params = [];

if ($q !== '') {
    $where .= ' AND (name LIKE ? OR code LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$stmt = $pdo->prepare("
    SELECT id, code, name, unit, vat_rate, price_net, description, is_active, fakturownia_product_id
    FROM erp_products
    WHERE {$where}
    ORDER BY name ASC
    LIMIT {$limit}
");
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'count' => count($items), 'items' => $items], JSON_UNESCAPED_UNICODE);
