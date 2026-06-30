<?php
/**
 * BRYGAD ERP v3.0 - API: Zapisywanie subskrypcji Web Push
 * 
 * POST /api/push/subscribe.php
 * Body JSON: { endpoint, keys: { p256dh, auth } }
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

// Wspieramy dwa formaty:
// 1. Bezpośredni: { endpoint, keys: { p256dh, auth } }
// 2. Zagnieżdżony: { subscription: { endpoint, keys: { p256dh, auth } } }
$subscription = $data['subscription'] ?? $data;

$endpoint = $subscription['endpoint'] ?? '';
$p256dh = $subscription['keys']['p256dh'] ?? '';
$auth = $subscription['keys']['auth'] ?? '';

if (empty($endpoint) || empty($p256dh) || empty($auth)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak wymaganych pól (endpoint, keys.p256dh, keys.auth)']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$pdo = getDbConnection();

try {
    // Sprawdź czy subskrypcja już istnieje
    $stmt = $pdo->prepare("
        SELECT id FROM push_subscriptions 
        WHERE user_id = ? AND endpoint = ?
    ");
    $stmt->execute([$user_id, $endpoint]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Aktualizuj istniejącą
        $stmt = $pdo->prepare("
            UPDATE push_subscriptions 
            SET p256dh = ?, auth = ?, user_agent = ?, is_active = 1, last_used_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$p256dh, $auth, $user_agent, $existing['id']]);
        
        logEvent("Zaktualizowano subskrypcję push dla użytkownika ID: {$user_id}", 'INFO');
    } else {
        // Utwórz nową
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at, is_active)
            VALUES (?, ?, ?, ?, ?, NOW(), 1)
        ");
        $stmt->execute([$user_id, $endpoint, $p256dh, $auth, $user_agent]);
        
        logEvent("Utworzono nową subskrypcję push dla użytkownika ID: {$user_id}", 'INFO');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Subskrypcja zapisana'
    ]);
    
} catch (PDOException $e) {
    logEvent("Błąd zapisywania subskrypcji push: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}


