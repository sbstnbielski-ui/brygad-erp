<?php
/**
 * BRYGAD ERP v3.0 - API: Usuwanie subskrypcji Web Push
 * 
 * POST /api/push/unsubscribe.php
 * Body JSON: { endpoint, worker_id }
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

// Sprawdź autoryzację
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Wymagane zalogowanie']);
    exit;
}

// Tylko POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona']);
    exit;
}

// Pobierz dane JSON z body
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane JSON']);
    exit;
}

$endpoint = $data['endpoint'] ?? '';

if (empty($endpoint)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak wymaganego pola endpoint']);
    exit;
}

$user_id = $_SESSION['user_id'];

$pdo = getDbConnection();

try {
    // Usuń subskrypcję dla danego użytkownika i endpointa
    $stmt = $pdo->prepare("
        DELETE FROM push_subscriptions 
        WHERE user_id = ? AND endpoint = ?
    ");
    $stmt->execute([$user_id, $endpoint]);
    
    if ($stmt->rowCount() > 0) {
        logEvent("Usunięto subskrypcję push dla użytkownika ID: {$user_id}", 'INFO');
        echo json_encode([
            'success' => true,
            'message' => 'Subskrypcja usunięta'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Subskrypcja nie istniała lub już została usunięta'
        ]);
    }
    
} catch (PDOException $e) {
    logEvent("Błąd usuwania subskrypcji push: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}
