<?php
/**
 * BRYGAD ERP - API: Zapisz towar/usługę do katalogu
 * POST: name, code, unit, vat_rate, price_net, description
 * Jeśli id podane — aktualizuje, jeśli nie — tworzy (z deduplikacją po nazwie).
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda POST wymagana']);
    exit;
}

$pdo = getDbConnection();

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$code = trim($_POST['code'] ?? '');
$unit = trim($_POST['unit'] ?? 'szt');
$vatRate = trim($_POST['vat_rate'] ?? '23');
$priceNet = round((float)($_POST['price_net'] ?? 0), 2);
$description = trim($_POST['description'] ?? '');
$isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

if ($name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nazwa jest wymagana']);
    exit;
}

$allowedVat = ['23', '8', '5', '0', 'zw'];
if (!in_array($vatRate, $allowedVat, true)) $vatRate = '23';

$allowedUnits = ['szt', 'usł', 'godz', 'm2', 'mb', 'kg', 'kpl'];
if (!in_array($unit, $allowedUnits, true)) $unit = 'szt';

try {
    if ($id > 0) {
        $dupCheck = $pdo->prepare("SELECT id FROM erp_products WHERE name = ? AND id != ?");
        $dupCheck->execute([$name, $id]);
        if ($dupCheck->fetchColumn()) {
            echo json_encode(['success' => false, 'error' => 'Towar/usługa o tej nazwie już istnieje']);
            exit;
        }

        $stmt = $pdo->prepare("
            UPDATE erp_products SET name = ?, code = ?, unit = ?, vat_rate = ?, price_net = ?, description = ?, is_active = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $code ?: null, $unit, $vatRate, $priceNet, $description ?: null, $isActive, $id]);
        $productId = $id;
        $mode = 'updated';
    } else {
        $dupCheck = $pdo->prepare("SELECT id FROM erp_products WHERE name = ?");
        $dupCheck->execute([$name]);
        $existingId = $dupCheck->fetchColumn();

        if ($existingId) {
            echo json_encode(['success' => true, 'id' => (int)$existingId, 'mode' => 'exists', 'message' => 'Towar/usługa już istnieje w katalogu']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO erp_products (name, code, unit, vat_rate, price_net, description, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $code ?: null, $unit, $vatRate, $priceNet, $description ?: null, $isActive, $_SESSION['user_id'] ?? null]);
        $productId = (int)$pdo->lastInsertId();
        $mode = 'created';
    }

    echo json_encode(['success' => true, 'id' => $productId, 'mode' => $mode]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        echo json_encode(['success' => false, 'error' => 'Towar/usługa o tej nazwie już istnieje']);
    } else {
        logEvent("Błąd zapisu produktu: " . $e->getMessage(), 'ERROR');
        echo json_encode(['success' => false, 'error' => 'Błąd zapisu']);
    }
}
