<?php
/**
 * BRYGAD ERP v3.0 - Serwis Web Push
 * 
 * Funkcje pomocnicze do wysyłania powiadomień push
 */

if (!defined('SPRUTEX_BOOTSTRAP_LOADED')) {
    die('Direct access not allowed');
}

/**
 * Wysyła powiadomienie push do użytkownika
 * 
 * @param int $userId ID użytkownika
 * @param string $title Tytuł powiadomienia
 * @param string $body Treść powiadomienia
 * @param array $data Dodatkowe dane (url, task_id, itp.)
 * @return array ['success' => bool, 'sent' => int, 'errors' => int]
 */
function sendPushToUser($userId, $title, $body, $data = []) {
    $pdo = getDbConnection();
    
    try {
        // Pobierz aktywne subskrypcje użytkownika
        $stmt = $pdo->prepare("
            SELECT * FROM push_subscriptions 
            WHERE user_id = ? AND is_active = 1
        ");
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll();
        
        if (empty($subscriptions)) {
            return ['success' => false, 'sent' => 0, 'errors' => 0, 'reason' => 'no_subscriptions'];
        }
        
        require_once dirname(__DIR__) . '/config/push.php';

        $vapidPublic = getVapidPublicKey();
        $vapidPrivate = getVapidPrivateKey();

        if ($vapidPublic === '' || $vapidPrivate === '') {
            return ['success' => false, 'sent' => 0, 'errors' => 0, 'reason' => 'no_vapid_keys'];
        }
        
        // Przygotuj payload
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/assets/logo-brygad-erp.png',
            'badge' => '/assets/logo-brygad-erp.png',
            'tag' => 'brygad-' . ($data['type'] ?? 'notification'),
            'data' => $data,
            'actions' => [
                ['action' => 'view', 'title' => 'Zobacz'],
                ['action' => 'dismiss', 'title' => 'Zamknij']
            ]
        ]);
        
        $sentCount = 0;
        $errorCount = 0;
        
        foreach ($subscriptions as $sub) {
            $result = sendPushNotificationInternal(
                $sub['endpoint'],
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
                if ($result['status'] === 410 || $result['status'] === 404) {
                    $updateStmt = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE id = ?");
                    $updateStmt->execute([$sub['id']]);
                }
            }
        }
        
        // Zapisz log
        $logStmt = $pdo->prepare("
            INSERT INTO push_notifications_log (user_id, notification_type, payload, status)
            VALUES (?, ?, ?, ?)
        ");
        $logStmt->execute([
            $userId,
            $data['type'] ?? 'general',
            $payload,
            $sentCount > 0 ? 'sent' : 'failed'
        ]);
        
        return [
            'success' => $sentCount > 0,
            'sent' => $sentCount,
            'errors' => $errorCount
        ];
        
    } catch (PDOException $e) {
        logEvent("Błąd wysyłania push: " . $e->getMessage(), 'ERROR');
        return ['success' => false, 'sent' => 0, 'errors' => 1, 'reason' => 'db_error'];
    }
}

/**
 * Wysyła powiadomienie o nowym zadaniu do przypisanych pracowników
 * 
 * @param int $taskId ID zadania
 * @param array $workerIds Tablica ID pracowników
 * @return array Wyniki wysyłki
 */
function sendTaskNotification($taskId, $workerIds, $type = 'task_new') {
    $pdo = getDbConnection();
    
    // Pobierz dane zadania
    $stmt = $pdo->prepare("SELECT title, description, priority FROM tasks WHERE id = ?");
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    
    if (!$task) {
        return ['success' => false, 'reason' => 'task_not_found'];
    }
    
    $priorityLabels = [
        'high' => '🔴 PILNE',
        'medium' => '🟡 Średni',
        'low' => '🔵 Niski'
    ];
    
    $typeLabels = [
        'task_new' => 'Nowe zadanie',
        'task_updated' => 'Zadanie zaktualizowane',
        'task_overdue' => 'Zadanie przeterminowane'
    ];
    
    $title = ($typeLabels[$type] ?? 'Zadanie') . ' - ' . $task['title'];
    $body = ($priorityLabels[$task['priority']] ?? '') . "\n" . ($task['description'] ?? '');
    
    $results = [];
    
    foreach ($workerIds as $workerId) {
        // Znajdź user_id dla worker_id
        $stmt = $pdo->prepare("SELECT user_id FROM workers WHERE id = ?");
        $stmt->execute([$workerId]);
        $userId = $stmt->fetchColumn();
        
        if ($userId) {
            $result = sendPushToUser($userId, $title, $body, [
                'type' => $type,
                'task_id' => $taskId,
                'url' => '/zadania/show_mobile.php?id=' . $taskId
            ]);
            $results[$workerId] = $result;
        }
    }
    
    return $results;
}

/**
 * Wewnętrzna funkcja wysyłająca push
 * Uproszczona wersja - w produkcji użyj minishlink/web-push
 */
function sendPushNotificationInternal($endpoint, $payload) {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL nie jest dostępny', 'status' => 0];
    }
    
    try {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
                'TTL: 86400' // 24h
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
            'status' => $httpCode
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage(), 'status' => 0];
    }
}

/**
 * Sprawdza czy użytkownik ma aktywną subskrypcję push
 */
function userHasPushSubscription($userId) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() > 0;
}


