<?php
/**
 * BRYGAD ERP v3.0 - API: Zapisz Stawkę Pracownika
 * Endpoint AJAX do zapisu/aktualizacji stawek
 */

require_once dirname(__DIR__, 2) . '/config/autoload.php';
startSecureSession();
requireLogin();
requireAdmin();

header('Content-Type: application/json');

$pdo = getDbConnection();

// Pobierz dane JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowe dane wejściowe']);
    exit;
}

$workerId = (int)($data['worker_id'] ?? 0);
$rateId = isset($data['rate_id']) && $data['rate_id'] !== 'null' ? (int)$data['rate_id'] : null;
$scopeType = $data['scope_type'] ?? 'GLOBAL';
$scopeId = isset($data['scope_id']) && $data['scope_id'] !== 'null' ? (int)$data['scope_id'] : null;

$rateBase = (float)($data['rate_base'] ?? 0);
$rateOvertime = (float)($data['rate_overtime'] ?? 0);
$rateSaturday = (float)($data['rate_saturday'] ?? 0);
$rateSaturdayOt = (float)($data['rate_saturday_overtime'] ?? 0);
$rateSunday = (float)($data['rate_sunday'] ?? 0);
$rateSundayOt = (float)($data['rate_sunday_overtime'] ?? 0);
$rateNight = (float)($data['rate_night'] ?? 0);
$rateNightOt = (float)($data['rate_night_overtime'] ?? 0);
$rateDelegation = (float)($data['rate_delegation'] ?? 0);
$rateDelegationOt = (float)($data['rate_delegation_overtime'] ?? 0);

// Walidacja
if ($workerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowy pracownik']);
    exit;
}

if (!in_array($scopeType, ['GLOBAL', 'PROJECT', 'STAGE'])) {
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowy zakres']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $userId = $_SESSION['user_id'] ?? null;
    
    if ($rateId) {
        // UPDATE istniejącej stawki
        $stmt = $pdo->prepare("
            UPDATE worker_rates
            SET 
                rate_base = ?,
                rate_overtime = ?,
                rate_saturday = ?,
                rate_saturday_overtime = ?,
                rate_sunday = ?,
                rate_sunday_overtime = ?,
                rate_night = ?,
                rate_night_overtime = ?,
                rate_delegation = ?,
                rate_delegation_overtime = ?,
                updated_at = NOW()
            WHERE id = ? AND worker_id = ?
        ");
        
        $stmt->execute([
            $rateBase,
            $rateOvertime,
            $rateSaturday,
            $rateSaturdayOt,
            $rateSunday,
            $rateSundayOt,
            $rateNight,
            $rateNightOt,
            $rateDelegation,
            $rateDelegationOt,
            $rateId,
            $workerId
        ]);
        
        logEvent("Zaktualizowano stawkę ID $rateId dla pracownika ID $workerId", 'INFO');
        
    } else {
        // INSERT nowej stawki
        // Najpierw sprawdź, czy nie istnieje już stawka dla tego zakresu
        $stmt = $pdo->prepare("
            SELECT id FROM worker_rates
            WHERE worker_id = ? 
              AND scope_type = ?
              AND (scope_id = ? OR (? IS NULL AND scope_id IS NULL))
              AND is_active = 1
        ");
        $stmt->execute([$workerId, $scopeType, $scopeId, $scopeId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Już istnieje - update
            $stmt = $pdo->prepare("
                UPDATE worker_rates
                SET 
                    rate_base = ?,
                    rate_overtime = ?,
                    rate_saturday = ?,
                    rate_saturday_overtime = ?,
                    rate_sunday = ?,
                    rate_sunday_overtime = ?,
                    rate_night = ?,
                    rate_night_overtime = ?,
                    rate_delegation = ?,
                    rate_delegation_overtime = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $rateBase,
                $rateOvertime,
                $rateSaturday,
                $rateSaturdayOt,
                $rateSunday,
                $rateSundayOt,
                $rateNight,
                $rateNightOt,
                $rateDelegation,
                $rateDelegationOt,
                $existing['id']
            ]);
            
            $rateId = $existing['id'];
        } else {
            // Nowa stawka
            $stmt = $pdo->prepare("
                INSERT INTO worker_rates 
                (worker_id, scope_type, scope_id, 
                 rate_base, rate_overtime,
                 rate_saturday, rate_saturday_overtime,
                 rate_sunday, rate_sunday_overtime,
                 rate_night, rate_night_overtime,
                 rate_delegation, rate_delegation_overtime,
                 is_active, created_by_user_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                $workerId,
                $scopeType,
                $scopeId,
                $rateBase,
                $rateOvertime,
                $rateSaturday,
                $rateSaturdayOt,
                $rateSunday,
                $rateSundayOt,
                $rateNight,
                $rateNightOt,
                $rateDelegation,
                $rateDelegationOt,
                $userId
            ]);
            
            $rateId = $pdo->lastInsertId();
        }
        
        logEvent("Dodano nową stawkę ID $rateId dla pracownika ID $workerId (zakres: $scopeType)", 'INFO');
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'rate_id' => $rateId,
        'message' => 'Stawka została zapisana'
    ]);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    logEvent("Błąd zapisu stawki: " . $e->getMessage(), 'ERROR');
    echo json_encode([
        'success' => false,
        'message' => 'Błąd zapisu do bazy danych'
    ]);
}


