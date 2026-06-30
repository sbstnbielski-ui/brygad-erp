<?php
/**
 * BRYGAD ERP v3.0 - API: Wysyłanie testowego powiadomienia push
 * 
 * POST /api/push/send-test.php
 */

require_once dirname(dirname(__DIR__)) . '/config/autoload.php';
require_once dirname(dirname(__DIR__)) . '/config/push.php';
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

// Sprawdź autoryzację
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Wymagane zalogowanie']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda niedozwolona']);
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = getDbConnection();

try {
    // Pobierz subskrypcje użytkownika
    $stmt = $pdo->prepare("
        SELECT * FROM push_subscriptions 
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$user_id]);
    $subscriptions = $stmt->fetchAll();
    
    if (empty($subscriptions)) {
        echo json_encode([
            'success' => false,
            'error' => 'Brak aktywnych subskrypcji push. Najpierw włącz powiadomienia.'
        ]);
        exit;
    }
    
    $vapidPublic = getVapidPublicKey();
    $vapidPrivate = getVapidPrivateKey();

    if ($vapidPublic === '' || $vapidPrivate === '') {
        echo json_encode([
            'success' => false,
            'error' => 'Klucze VAPID nie są skonfigurowane (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY w .env)',
        ]);
        exit;
    }
    
    // Payload powiadomienia
    $payload = json_encode([
        'title' => '🔔 BRYGAD ERP - Test',
        'body' => 'Powiadomienia działają poprawnie! ' . date('H:i:s'),
        'icon' => '/assets/logo-brygad-erp.png',
        'tag' => 'test-notification',
        'data' => [
            'url' => '/zadania/index.php',
            'test' => true
        ]
    ]);
    
    $sentCount = 0;
    $errorCount = 0;
    
    foreach ($subscriptions as $sub) {
        $result = sendPushNotification(
            $sub['endpoint'],
            $sub['p256dh'],
            $sub['auth'],
            $vapidPublic,
            $vapidPrivate,
            $payload
        );
        
        if ($result['success']) {
            $sentCount++;
            
            // Zaktualizuj last_used_at
            $updateStmt = $pdo->prepare("UPDATE push_subscriptions SET last_used_at = NOW() WHERE id = ?");
            $updateStmt->execute([$sub['id']]);
        } else {
            $errorCount++;
            
            // Jeśli subskrypcja wygasła (410 Gone), dezaktywuj
            if ($result['status'] === 410) {
                $updateStmt = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE id = ?");
                $updateStmt->execute([$sub['id']]);
            }
            
            // Loguj błąd
            logEvent("Błąd wysyłania push do subskrypcji {$sub['id']}: " . ($result['error'] ?? 'unknown'), 'WARNING');
        }
    }
    
    // Zapisz log
    $logStmt = $pdo->prepare("
        INSERT INTO push_notifications_log (user_id, notification_type, payload, status)
        VALUES (?, 'test', ?, ?)
    ");
    $logStmt->execute([
        $user_id,
        $payload,
        $sentCount > 0 ? 'sent' : 'failed'
    ]);
    
    echo json_encode([
        'success' => $sentCount > 0,
        'message' => "Wysłano: {$sentCount}, Błędy: {$errorCount}",
        'sent' => $sentCount,
        'errors' => $errorCount
    ]);
    
} catch (PDOException $e) {
    logEvent("Błąd wysyłania testowego push: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Błąd bazy danych']);
}

/**
 * Wysyła powiadomienie push używając Web Push Protocol
 * Uproszczona wersja - w produkcji użyj biblioteki web-push-php
 */
function sendPushNotification($endpoint, $p256dh, $auth, $vapidPublicKey, $vapidPrivateKey, $payload) {
    // Sprawdź czy mamy cURL
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL nie jest dostępny'];
    }
    
    // UWAGA: To jest uproszczona implementacja
    // W produkcji użyj: composer require minishlink/web-push
    // Poniższa wersja działa dla podstawowych testów
    
    try {
        // Dla pełnej implementacji VAPID potrzebna jest biblioteka web-push
        // Ta wersja wysyła prosty request bez szyfrowania (dla testów lokalnych)
        
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
                'TTL: 60'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error, 'status' => 0];
        }
        
        // 201 Created lub 200 OK = sukces
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'status' => $httpCode];
        }
        
        return [
            'success' => false,
            'error' => "HTTP {$httpCode}",
            'status' => $httpCode,
            'response' => $response
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


